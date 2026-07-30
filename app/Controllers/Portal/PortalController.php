<?php

declare(strict_types=1);

namespace App\Controllers\Portal;

use App\Services\BillingService;
use App\Services\PortalAuthService;
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

        $subscription = $db->fetchOne(
            "SELECT s.*, p.name as plan_name, p.price, p.interval
             FROM subscriptions s
             JOIN subscription_plans p ON p.id = s.plan_id
             WHERE s.media_user_id = ? ORDER BY s.created_at DESC LIMIT 1",
            [$user->id]
        );

        $recentPlays = $db->fetchAll(
            'SELECT title, media_type, player, started_at, duration_seconds
             FROM playback_sessions WHERE media_user_id = ? ORDER BY started_at DESC LIMIT 10',
            [$user->id]
        );

        $tickets = $db->fetchAll(
            "SELECT uuid, subject, status, priority, created_at FROM tickets
             WHERE customer_id IN (SELECT id FROM customers WHERE media_user_id = ?)
             ORDER BY created_at DESC LIMIT 5",
            [$user->id]
        );

        return $this->view('portal.dashboard', [
            'title' => 'Mi cuenta',
            'portalUser' => $user,
            'subscription' => $subscription,
            'recentPlays' => $recentPlays,
            'tickets' => $tickets,
        ]);
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
