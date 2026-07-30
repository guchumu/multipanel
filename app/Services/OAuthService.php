<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Repositories\UserRepository;
use Core\Database;
use Core\Logger;
use Core\Session;
use GuzzleHttp\Client;
use Ramsey\Uuid\Uuid;

/**
 * OAuth2/OIDC authentication for panel SSO.
 */
final class OAuthService
{
    private Client $http;

    public function __construct(
        private AuthService $auth = new AuthService(),
        private UserRepository $users = new UserRepository(),
        private PasswordService $passwords = new PasswordService(),
    ) {
        $this->http = new Client(['timeout' => 15, 'http_errors' => false]);
    }

    /** @return array<string, array<string, mixed>> */
    public function getEnabledProviders(): array
    {
        $providers = config('oauth', []);
        return array_filter($providers, fn ($p) => !empty($p['enabled']) && !empty($p['client_id']));
    }

    public function getAuthorizationUrl(string $provider): string
    {
        $config = $this->getProviderConfig($provider);
        $state = bin2hex(random_bytes(16));
        Session::getInstance()->set('oauth_state', $state);
        Session::getInstance()->set('oauth_provider', $provider);

        $params = http_build_query([
            'client_id' => $config['client_id'],
            'redirect_uri' => $config['redirect_uri'],
            'response_type' => 'code',
            'scope' => implode(' ', $config['scopes']),
            'state' => $state,
        ]);

        return $config['authorize_url'] . '?' . $params;
    }

    public function handleCallback(string $provider, string $code, string $state): ?User
    {
        $session = Session::getInstance();
        $expectedState = $session->get('oauth_state');
        $expectedProvider = $session->get('oauth_provider');

        if ($state === '' || $state !== $expectedState || $provider !== $expectedProvider) {
            Logger::warning('OAuth state mismatch', ['provider' => $provider]);
            return null;
        }

        $session->remove('oauth_state');
        $session->remove('oauth_provider');

        $config = $this->getProviderConfig($provider);
        $token = $this->exchangeCode($config, $code);
        if ($token === null) {
            return null;
        }

        $profile = $this->fetchProfile($provider, $config, $token['access_token']);
        if ($profile === null || empty($profile['email'])) {
            return null;
        }

        $user = $this->findOrCreateUser($provider, $profile, $token);
        if ($user === null || $user->status !== 'active') {
            return null;
        }

        $this->auth->login($user);
        \Core\EventDispatcher::dispatch('user.login', $user);

        return $user;
    }

    /** @return array<string, mixed> */
    private function getProviderConfig(string $provider): array
    {
        $config = config("oauth.{$provider}");
        if (!is_array($config) || empty($config['client_id'])) {
            throw new \InvalidArgumentException("OAuth provider not configured: {$provider}");
        }

        return $config;
    }

    /** @return array<string, mixed>|null */
    private function exchangeCode(array $config, string $code): ?array
    {
        $response = $this->http->post($config['token_url'], [
            'headers' => [
                'Accept' => 'application/json',
            ],
            'form_params' => [
                'client_id' => $config['client_id'],
                'client_secret' => $config['client_secret'],
                'code' => $code,
                'redirect_uri' => $config['redirect_uri'],
                'grant_type' => 'authorization_code',
            ],
        ]);

        $body = json_decode((string) $response->getBody(), true);
        if (!is_array($body) || empty($body['access_token'])) {
            Logger::error('OAuth token exchange failed', ['status' => $response->getStatusCode()]);
            return null;
        }

        return $body;
    }

    /** @return array{id: string, email: string, name?: string, avatar?: string}|null */
    private function fetchProfile(string $provider, array $config, string $accessToken): ?array
    {
        $headers = ['Authorization' => 'Bearer ' . $accessToken, 'Accept' => 'application/json'];

        if ($provider === 'github') {
            $headers['User-Agent'] = 'MultiPanel-ERP';
        }

        $response = $this->http->get($config['userinfo_url'], ['headers' => $headers]);
        $data = json_decode((string) $response->getBody(), true);

        if (!is_array($data)) {
            return null;
        }

        return match ($provider) {
            'google' => [
                'id' => (string) ($data['sub'] ?? ''),
                'email' => (string) ($data['email'] ?? ''),
                'name' => (string) ($data['name'] ?? ''),
                'avatar' => (string) ($data['picture'] ?? ''),
            ],
            'github' => $this->normalizeGitHubProfile($data, $accessToken),
            'microsoft' => [
                'id' => (string) ($data['id'] ?? ''),
                'email' => (string) ($data['mail'] ?? $data['userPrincipalName'] ?? ''),
                'name' => (string) ($data['displayName'] ?? ''),
                'avatar' => '',
            ],
            default => null,
        };
    }

    /** @param array<string, mixed> $data */
    private function normalizeGitHubProfile(array $data, string $accessToken): array
    {
        $email = (string) ($data['email'] ?? '');

        if ($email === '') {
            $response = $this->http->get('https://api.github.com/user/emails', [
                'headers' => ['Authorization' => 'Bearer ' . $accessToken, 'Accept' => 'application/json', 'User-Agent' => 'MultiPanel-ERP'],
            ]);
            $emails = json_decode((string) $response->getBody(), true);
            if (is_array($emails)) {
                foreach ($emails as $entry) {
                    if (!empty($entry['primary']) && !empty($entry['email'])) {
                        $email = (string) $entry['email'];
                        break;
                    }
                }
            }
        }

        return [
            'id' => (string) ($data['id'] ?? ''),
            'email' => $email,
            'name' => (string) ($data['name'] ?? $data['login'] ?? ''),
            'avatar' => (string) ($data['avatar_url'] ?? ''),
        ];
    }

    /** @param array{id: string, email: string, name?: string, avatar?: string} $profile */
    /** @param array<string, mixed> $token */
    private function findOrCreateUser(string $provider, array $profile, array $token): ?User
    {
        $db = Database::getInstance();

        $linked = $db->fetchOne(
            'SELECT u.* FROM oauth_accounts oa JOIN users u ON u.id = oa.user_id WHERE oa.provider = ? AND oa.provider_user_id = ? AND u.deleted_at IS NULL',
            [$provider, $profile['id']]
        );

        if ($linked) {
            $this->updateOAuthAccount((int) $linked['id'], $provider, $profile, $token);
            return new User($linked);
        }

        $user = $this->users->findByEmail($profile['email']);
        if ($user === null) {
            $username = $this->generateUsername($profile['email']);
            $user = $this->auth->register([
                'tenant_id' => 1,
                'role_id' => 3,
                'email' => $profile['email'],
                'username' => $username,
                'password' => bin2hex(random_bytes(32)),
                'first_name' => $profile['name'] ?? null,
                'status' => 'active',
            ]);
            $user->email_verified_at = date('Y-m-d H:i:s');
            if (!empty($profile['avatar'])) {
                $user->avatar = $profile['avatar'];
            }
            $user->save();
        }

        $db->insert('oauth_accounts', [
            'user_id' => $user->id,
            'provider' => $provider,
            'provider_user_id' => $profile['id'],
            'email' => $profile['email'],
            'avatar' => $profile['avatar'] ?? null,
            'access_token' => $token['access_token'] ?? null,
            'refresh_token' => $token['refresh_token'] ?? null,
            'expires_at' => isset($token['expires_in'])
                ? date('Y-m-d H:i:s', time() + (int) $token['expires_in'])
                : null,
        ]);

        return $user;
    }

    /** @param array{id: string, email: string, name?: string, avatar?: string} $profile */
    /** @param array<string, mixed> $token */
    private function updateOAuthAccount(int $userId, string $provider, array $profile, array $token): void
    {
        Database::getInstance()->query(
            'UPDATE oauth_accounts SET access_token = ?, refresh_token = ?, email = ?, avatar = ?, expires_at = ?, updated_at = NOW()
             WHERE user_id = ? AND provider = ?',
            [
                $token['access_token'] ?? null,
                $token['refresh_token'] ?? null,
                $profile['email'],
                $profile['avatar'] ?? null,
                isset($token['expires_in']) ? date('Y-m-d H:i:s', time() + (int) $token['expires_in']) : null,
                $userId,
                $provider,
            ]
        );
    }

    private function generateUsername(string $email): string
    {
        $base = preg_replace('/[^a-z0-9_]/', '', strtolower(explode('@', $email)[0])) ?: 'user';
        $username = $base;
        $i = 1;

        while ($this->users->findByUsername($username) !== null) {
            $username = $base . $i;
            $i++;
        }

        return $username;
    }
}
