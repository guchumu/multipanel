<?php

declare(strict_types=1);

namespace App\Controllers\Portal;

use App\Repositories\PeticionesRepository;
use App\Services\BillingSettingsService;
use App\Services\PasswordService;
use App\Services\Peticiones\PeticionesConfig;
use App\Services\Peticiones\PeticionesService;
use App\Services\PortalAuthService;
use App\Services\PortalDefaultPasswordService;
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
        private PasswordService $passwords = new PasswordService(),
        private PortalDefaultPasswordService $portalPasswords = new PortalDefaultPasswordService(),
    ) {
    }

    public function showLogin(Request $request): Response
    {
        if ($this->auth->check()) {
            return $this->redirect('/portal');
        }
        return $this->view('portal.login', ['title' => 'Acceder']);
    }

    public function login(Request $request): Response
    {
        $data = $this->validate($request, [
            'username' => 'required',
            'password' => 'required',
        ]);

        $result = $this->auth->attemptWithReason($data['username'], $data['password']);
        if (empty($result['ok'])) {
            Session::getInstance()->flash('error', $result['error'] ?? 'No se pudo iniciar sesión.');
            return $this->redirect('/portal/login');
        }

        return $this->redirect('/portal');
    }

    /** Envía la contraseña del portal por Telegram tras introducir el email. */
    public function sendPasswordTelegram(Request $request): Response
    {
        if ($this->auth->check()) {
            return $this->redirect('/portal');
        }

        $email = trim((string) $request->input('email', ''));
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $result = $this->portalPasswords->sendPasswordByEmail($email, $ip !== '' ? $ip : null);

        Session::getInstance()->flash(
            !empty($result['success']) ? 'success' : 'error',
            (string) ($result['message'] ?? 'No se pudo completar la solicitud.')
        );

        return $this->redirect('/portal/login');
    }

    public function logout(Request $request): Response
    {
        $this->auth->logout();
        Session::getInstance()->flash('success', 'Has cerrado sesión correctamente.');
        return $this->redirect('/portal/login');
    }

    public function dashboard(Request $request): Response
    {
        $user = $this->auth->user();
        $db = Database::getInstance();
        $tenantId = (int) ($user->tenant_id ?? 1);

        $subscription = null;
        try {
            $subscription = $db->fetchOne(
                "SELECT s.*, p.name as plan_name, p.price, p.interval
                 FROM subscriptions s
                 JOIN subscription_plans p ON p.id = s.plan_id
                 WHERE s.media_user_id = ? ORDER BY s.created_at DESC LIMIT 1",
                [$user->id]
            );
        } catch (\Throwable) {
            $subscription = null;
        }

        $serverInfo = [
            'name' => 'Sin servidor',
            'type' => null,
            'type_label' => '—',
        ];
        if (!empty($user->server_id)) {
            try {
                $srow = $db->fetchOne(
                    'SELECT name, type FROM servers WHERE id = ? AND deleted_at IS NULL LIMIT 1',
                    [(int) $user->server_id]
                );
                if ($srow) {
                    $type = strtolower((string) ($srow['type'] ?? ''));
                    $serverInfo = [
                        'name' => (string) ($srow['name'] ?? 'Servidor'),
                        'type' => $type,
                        'type_label' => match ($type) {
                            'plex' => 'Plex',
                            'jellyfin' => 'Jellyfin',
                            'emby' => 'Emby',
                            default => $type !== '' ? ucfirst($type) : 'Media',
                        },
                    ];
                }
            } catch (\Throwable) {
                // ignore
            }
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
        $accountStatus = $this->accountStatusLabel($user, $expiry);
        $renewalPresets = $this->billingSettings->getRenewalPresets($tenantId);
        $stripeConfigured = trim($this->billingSettings->getStripeSecretKey($tenantId)) !== '';

        $peticiones = $this->loadPeticionesSummary($user, $tenantId, 5);

        return $this->view('portal.dashboard', [
            'title' => 'Mi cuenta',
            'portalUser' => $user,
            'subscription' => $subscription,
            'serverInfo' => $serverInfo,
            'tickets' => $tickets,
            'liveStreams' => $liveStreams,
            'expiry' => $expiry,
            'accountStatus' => $accountStatus,
            'renewalPresets' => $renewalPresets,
            'stripeConfigured' => $stripeConfigured,
            'peticiones' => $peticiones,
            'navActive' => 'home',
        ]);
    }

    public function subscription(Request $request): Response
    {
        $user = $this->auth->user();
        $tenantId = (int) ($user->tenant_id ?? 1);
        $expiry = $this->expiryInfo($user->expires_at ?? null, (string) ($user->status ?? ''));
        $renewalPresets = $this->billingSettings->getRenewalPresets($tenantId);
        $stripeConfigured = trim($this->billingSettings->getStripeSecretKey($tenantId)) !== '';

        $serverInfo = ['name' => 'Sin servidor', 'type_label' => '—'];
        if (!empty($user->server_id)) {
            try {
                $srow = Database::getInstance()->fetchOne(
                    'SELECT name, type FROM servers WHERE id = ? AND deleted_at IS NULL LIMIT 1',
                    [(int) $user->server_id]
                );
                if ($srow) {
                    $type = strtolower((string) ($srow['type'] ?? ''));
                    $serverInfo = [
                        'name' => (string) ($srow['name'] ?? 'Servidor'),
                        'type_label' => match ($type) {
                            'plex' => 'Plex',
                            'jellyfin' => 'Jellyfin',
                            'emby' => 'Emby',
                            default => $type !== '' ? ucfirst($type) : 'Media',
                        },
                    ];
                }
            } catch (\Throwable) {
                // ignore
            }
        }

        $plans = [];
        try {
            $plans = Database::getInstance()->fetchAll(
                'SELECT * FROM subscription_plans WHERE tenant_id = ? AND is_active = 1 ORDER BY price',
                [$tenantId]
            );
        } catch (\Throwable) {
            $plans = [];
        }

        return $this->view('portal.subscription', [
            'title' => 'Renovar / pagar',
            'portalUser' => $user,
            'plans' => $plans,
            'expiry' => $expiry,
            'serverInfo' => $serverInfo,
            'renewalPresets' => $renewalPresets,
            'stripeConfigured' => $stripeConfigured,
            'navActive' => 'pay',
        ]);
    }

    public function profile(Request $request): Response
    {
        return $this->view('portal.profile', [
            'title' => 'Mi perfil',
            'portalUser' => $this->auth->user(),
            'navActive' => 'profile',
        ]);
    }

    public function updateProfile(Request $request): Response
    {
        $user = $this->auth->user();
        $email = trim((string) $request->input('email', ''));
        $displayName = trim((string) $request->input('display_name', ''));

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Session::getInstance()->flash('error', 'El email no es válido.');
            return $this->redirect('/portal/profile');
        }

        if ($displayName !== '') {
            $user->display_name = $displayName;
        }
        $user->email = $email !== '' ? $email : $user->email;
        $user->locale = $request->input('locale') ?: $user->locale;
        $user->timezone = $request->input('timezone') ?: $user->timezone;
        $user->save();

        Session::getInstance()->flash('success', 'Perfil actualizado.');
        return $this->redirect('/portal/profile');
    }

    public function changePassword(Request $request): Response
    {
        $user = $this->auth->user();
        $current = (string) $request->input('current_password', '');
        $new = (string) $request->input('new_password', '');
        $confirm = (string) $request->input('new_password_confirmation', '');

        $hash = (string) ($user->password ?? '');
        if ($hash === '' || !$this->passwords->verify($current, $hash)) {
            Session::getInstance()->flash('error', 'La contraseña actual no es correcta.');
            return $this->redirect('/portal/profile');
        }

        if ($new !== $confirm) {
            Session::getInstance()->flash('error', 'La nueva contraseña y la confirmación no coinciden.');
            return $this->redirect('/portal/profile');
        }

        if (!$this->passwords->validate($new, [
            'min_length' => 8,
            'require_uppercase' => false,
            'require_lowercase' => false,
            'require_number' => false,
            'require_special' => false,
        ])) {
            Session::getInstance()->flash('error', 'La nueva contraseña debe tener al menos 8 caracteres.');
            return $this->redirect('/portal/profile');
        }

        $user->password = $this->passwords->hash($new);
        $user->save();

        Session::getInstance()->flash('success', 'Contraseña actualizada. Úsala en el próximo acceso.');
        return $this->redirect('/portal/profile');
    }

    public function peticiones(Request $request): Response
    {
        $user = $this->auth->user();
        $tenantId = (int) ($user->tenant_id ?? 1);
        $peticiones = $this->loadPeticionesSummary($user, $tenantId, 30);

        return $this->view('portal.peticiones', [
            'title' => 'Mis peticiones',
            'portalUser' => $user,
            'peticiones' => $peticiones,
            'navActive' => 'peticiones',
        ]);
    }

    public function storePeticion(Request $request): Response
    {
        $user = $this->auth->user();
        $tenantId = (int) ($user->tenant_id ?? 1);

        try {
            $cfg = PeticionesConfig::forTenant($tenantId);
            if (empty($cfg['configured'])) {
                Session::getInstance()->flash('error', 'El módulo de peticiones no está configurado.');
                return $this->redirect('/portal/peticiones');
            }
        } catch (\Throwable) {
            Session::getInstance()->flash('error', 'No se pudo conectar con peticiones.');
            return $this->redirect('/portal/peticiones');
        }

        $title = trim((string) $request->input('title', ''));
        $url = trim((string) $request->input('url', ''));

        if ($title === '') {
            Session::getInstance()->flash('error', 'Indica un título para la petición.');
            return $this->redirect('/portal/peticiones');
        }

        if ($url === '') {
            // URL opcional: usar placeholder estable para el esquema legacy.
            $url = 'https://www.themoviedb.org/search?query=' . rawurlencode($title);
        } elseif (!preg_match('#^https?://#i', $url)) {
            Session::getInstance()->flash('error', 'La URL debe empezar por http:// o https://');
            return $this->redirect('/portal/peticiones');
        }

        try {
            $result = (new PeticionesService())->addManual(
                $url,
                $title,
                '',
                $user->telegram_chat_id !== null && trim((string) $user->telegram_chat_id) !== ''
                    ? (string) $user->telegram_chat_id
                    : null,
                (string) ($user->username ?? ''),
            );
        } catch (\Throwable) {
            Session::getInstance()->flash('error', 'No se pudo guardar la petición. Inténtalo más tarde.');
            return $this->redirect('/portal/peticiones');
        }

        if (empty($result['ok'])) {
            Session::getInstance()->flash('error', $result['message'] ?? 'No se pudo crear la petición.');
            return $this->redirect('/portal/peticiones');
        }

        Session::getInstance()->flash('success', 'Petición enviada. La revisaremos pronto.');
        return $this->redirect('/portal/peticiones');
    }

    /**
     * @return array{label: string, class: string, date: string|null, days_left: int|null, expired: bool, urgent: bool}
     */
    private function expiryInfo(?string $expiresAt, string $status): array
    {
        if ($expiresAt === null || trim($expiresAt) === '') {
            $expired = $status === 'expired' || $status === 'suspended';

            return [
                'label' => $expired ? 'Sin acceso activo' : 'Sin fecha de caducidad',
                'class' => $expired ? 'danger' : 'secondary',
                'date' => null,
                'days_left' => null,
                'expired' => $expired,
                'urgent' => $expired,
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
                'urgent' => false,
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
                'urgent' => true,
            ];
        }
        if ($days === 0) {
            return [
                'label' => 'Caduca hoy',
                'class' => 'danger',
                'date' => $date,
                'days_left' => 0,
                'expired' => false,
                'urgent' => true,
            ];
        }
        if ($days <= 7) {
            return [
                'label' => "Quedan {$days} días",
                'class' => 'warning',
                'date' => $date,
                'days_left' => $days,
                'expired' => false,
                'urgent' => true,
            ];
        }

        return [
            'label' => "Quedan {$days} días",
            'class' => 'success',
            'date' => $date,
            'days_left' => $days,
            'expired' => false,
            'urgent' => false,
        ];
    }

    /**
     * Estado legible para el cliente: activo / por vencer / vencido / suspendido…
     *
     * @param object $user
     * @param array{label: string, class: string, expired: bool, urgent: bool, days_left: int|null} $expiry
     * @return array{label: string, class: string, hint: string}
     */
    private function accountStatusLabel(object $user, array $expiry): array
    {
        $status = (string) ($user->status ?? '');

        if ($status === 'suspended') {
            return [
                'label' => 'Suspendida',
                'class' => 'warning',
                'hint' => 'Contacta con soporte para reactivar el acceso.',
            ];
        }
        if ($status === 'blocked') {
            return [
                'label' => 'Bloqueada',
                'class' => 'danger',
                'hint' => 'Contacta con soporte.',
            ];
        }
        if (!empty($expiry['expired']) || $status === 'expired') {
            return [
                'label' => 'Vencido',
                'class' => 'danger',
                'hint' => 'Renueva para recuperar el acceso.',
            ];
        }
        if (!empty($expiry['urgent'])) {
            return [
                'label' => 'Por vencer',
                'class' => 'warning',
                'hint' => 'Renueva pronto para no perder el acceso.',
            ];
        }
        if ($status === 'invited') {
            return [
                'label' => 'Invitada',
                'class' => 'info',
                'hint' => 'Tu cuenta está lista. Disfruta del servicio.',
            ];
        }

        return [
            'label' => 'Activo',
            'class' => 'success',
            'hint' => 'Todo en orden.',
        ];
    }

    /**
     * @return array{configured: bool, items: array<int, array<string, mixed>>, note: ?string, can_submit: bool}
     */
    private function loadPeticionesSummary(object $user, int $tenantId, int $limit = 10): array
    {
        $out = [
            'configured' => false,
            'items' => [],
            'note' => null,
            'can_submit' => false,
        ];

        try {
            $cfg = PeticionesConfig::forTenant($tenantId);
            if (empty($cfg['configured'])) {
                $out['note'] = 'El módulo de peticiones no está configurado.';

                return $out;
            }

            $out['configured'] = true;
            $out['can_submit'] = true;
            $result = (new PeticionesRepository())->listForClient(
                (string) ($user->username ?? ''),
                isset($user->telegram_chat_id) ? (string) $user->telegram_chat_id : null,
                $limit
            );
            $out['items'] = $result['items'] ?? [];
            if (!empty($result['note'])) {
                $out['note'] = $result['note'];
            }
        } catch (\Throwable) {
            $out['note'] = 'No se pudieron cargar tus peticiones.';
        }

        return $out;
    }
}
