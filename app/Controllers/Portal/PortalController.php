<?php

declare(strict_types=1);

namespace App\Controllers\Portal;

use App\Repositories\PeticionesRepository;
use App\Services\BillingSettingsService;
use App\Services\Peticiones\PeticionesConfig;
use App\Services\PortalAuthService;
use App\Services\StreamingActivityService;
use Core\Controller;
use Core\Database;
use Core\Request;
use Core\Response;
use Core\Session;

/**
 * Client self-service portal controller.
 */
class PortalController extends Controller
{
    public function __construct(
        private PortalAuthService $auth = new PortalAuthService(),
        private StreamingActivityService $streaming = new StreamingActivityService(),
        private BillingSettingsService $billingSettings = new BillingSettingsService(),
    ) {
    }

    public function showLogin(Request $request): Response
    {
        if ($this->auth->check()) {
            return $this->redirect('/portal');
        }
        return $this->view('portal.login', ['title' => 'Portal Cliente']);
    }

    public function login(Request $request): Response
    {
        $data = $this->validate($request, [
            'username' => 'required',
            'password' => 'required',
        ]);

        $user = $this->auth->attempt($data['username'], $data['password']);
        if (!$user) {
            Session::getInstance()->flash('error', 'Credenciales incorrectas o cuenta inactiva.');
            return $this->redirect('/portal/login');
        }

        return $this->redirect('/portal');
    }

    public function logout(Request $request): Response
    {
        $this->auth->logout();
        return $this->redirect('/portal/login');
    }

    public function dashboard(Request $request): Response
    {
        $user = $this->auth->user();
        $db = Database::getInstance();
        $tenantId = (int) ($user->tenant_id ?? 1);

        $subscription = $db->fetchOne(
            "SELECT s.*, p.name as plan_name, p.price, p.interval
             FROM subscriptions s
             JOIN subscription_plans p ON p.id = s.plan_id
             WHERE s.media_user_id = ? ORDER BY s.created_at DESC LIMIT 1",
            [$user->id]
        );

        $recentPlays = [];
        try {
            $recentPlays = $db->fetchAll(
                'SELECT title, media_type, player, started_at, duration_seconds
                 FROM playback_sessions WHERE media_user_id = ? ORDER BY started_at DESC LIMIT 10',
                [$user->id]
            );
        } catch (\Throwable) {
            $recentPlays = [];
        }

        $tickets = [];
        try {
            $tickets = $db->fetchAll(
                "SELECT uuid, subject, status, priority, created_at FROM tickets
                 WHERE customer_id IN (SELECT id FROM customers WHERE media_user_id = ?)
                 ORDER BY created_at DESC LIMIT 5",
                [$user->id]
            );
        } catch (\Throwable) {
            $tickets = [];
        }

        $liveStreams = [];
        try {
            if (!empty($user->server_id)) {
                $liveStreams = $this->streaming->getSessionsForUser(
                    $tenantId,
                    (int) $user->server_id,
                    (string) ($user->username ?? ''),
                    $user->display_name ?? null
                );
            }
        } catch (\Throwable) {
            $liveStreams = [];
        }

        $expiry = $this->expiryInfo($user->expires_at ?? null, (string) ($user->status ?? ''));
        $renewalPresets = $this->billingSettings->getRenewalPresets($tenantId);
        $stripeConfigured = trim($this->billingSettings->getStripeSecretKey($tenantId)) !== '';

        $peticiones = [
            'configured' => false,
            'items' => [],
            'note' => null,
        ];
        try {
            $cfg = PeticionesConfig::forTenant($tenantId);
            if (!empty($cfg['configured'])) {
                $peticiones['configured'] = true;
                $result = (new PeticionesRepository())->listForUsername((string) ($user->username ?? ''));
                $peticiones['items'] = $result['items'] ?? [];
                if (empty($result['ok'])) {
                    $peticiones['note'] = $result['note']
                        ?? 'Las peticiones no están vinculadas a tu cuenta en este momento.';
                }
            } else {
                $peticiones['note'] = 'El módulo de peticiones no está configurado.';
            }
        } catch (\Throwable) {
            $peticiones['note'] = 'No se pudieron cargar tus peticiones.';
        }

        return $this->view('portal.dashboard', [
            'title' => 'Mi cuenta',
            'portalUser' => $user,
            'subscription' => $subscription,
            'recentPlays' => $recentPlays,
            'tickets' => $tickets,
            'liveStreams' => $liveStreams,
            'expiry' => $expiry,
            'renewalPresets' => $renewalPresets,
            'stripeConfigured' => $stripeConfigured,
            'peticiones' => $peticiones,
        ]);
    }

    /**
     * @return array{label: string, class: string, date: string|null, days_left: int|null, expired: bool}
     */
    private function expiryInfo(?string $expiresAt, string $status): array
    {
        if ($expiresAt === null || trim($expiresAt) === '') {
            return [
                'label' => 'Sin fecha de caducidad',
                'class' => 'secondary',
                'date' => null,
                'days_left' => null,
                'expired' => $status !== 'active',
            ];
        }

        $date = substr($expiresAt, 0, 10);
        $ts = strtotime($date . ' 23:59:59');
        if ($ts === false) {
            return [
                'label' => 'Fecha inválida',
                'class' => 'secondary',
                'date' => $date,
                'days_left' => null,
                'expired' => false,
            ];
        }

        $days = (int) floor(($ts - time()) / 86400);
        if ($days < 0) {
            return [
                'label' => 'Caducó hace ' . abs($days) . ' día(s)',
                'class' => 'danger',
                'date' => $date,
                'days_left' => $days,
                'expired' => true,
            ];
        }
        if ($days === 0) {
            return [
                'label' => 'Caduca hoy',
                'class' => 'danger',
                'date' => $date,
                'days_left' => 0,
                'expired' => false,
            ];
        }
        if ($days <= 7) {
            return [
                'label' => "Quedan {$days} días",
                'class' => 'warning',
                'date' => $date,
                'days_left' => $days,
                'expired' => false,
            ];
        }

        return [
            'label' => "Quedan {$days} días",
            'class' => 'success',
            'date' => $date,
            'days_left' => $days,
            'expired' => false,
        ];
    }

    public function subscription(Request $request): Response
    {
        $user = $this->auth->user();
        $plans = Database::getInstance()->fetchAll(
            'SELECT * FROM subscription_plans WHERE tenant_id = ? AND is_active = 1 ORDER BY price',
            [$user->tenant_id ?? 1]
        );

        return $this->view('portal.subscription', [
            'title' => 'Mi suscripción',
            'portalUser' => $user,
            'plans' => $plans,
        ]);
    }

    public function profile(Request $request): Response
    {
        return $this->view('portal.profile', [
            'title' => 'Mi perfil',
            'portalUser' => $this->auth->user(),
        ]);
    }

    public function updateProfile(Request $request): Response
    {
        $user = $this->auth->user();
        $user->display_name = $request->input('display_name') ?: $user->display_name;
        $user->email = $request->input('email') ?: $user->email;
        $user->locale = $request->input('locale') ?: $user->locale;
        $user->timezone = $request->input('timezone') ?: $user->timezone;
        $user->save();

        Session::getInstance()->flash('success', 'Perfil actualizado.');
        return $this->redirect('/portal/profile');
    }
}
