<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\OAuthService;
use Core\Controller;
use Core\Request;
use Core\Response;
use Core\Session;

/**
 * OAuth2/OIDC SSO controller.
 */
class OAuthController extends Controller
{
    public function __construct(
        private OAuthService $oauth = new OAuthService(),
    ) {
    }

    public function redirect(Request $request, string $provider): Response
    {
        $enabled = $this->oauth->getEnabledProviders();
        if (!isset($enabled[$provider])) {
            Session::getInstance()->flash('error', 'Proveedor SSO no disponible.');
            return $this->redirect('/login');
        }

        return $this->redirect($this->oauth->getAuthorizationUrl($provider));
    }

    public function callback(Request $request, string $provider): Response
    {
        $code = (string) $request->query('code', '');
        $state = (string) $request->query('state', '');
        $error = (string) $request->query('error', '');

        if ($error !== '') {
            Session::getInstance()->flash('error', 'Autenticación SSO cancelada.');
            return $this->redirect('/login');
        }

        $user = $this->oauth->handleCallback($provider, $code, $state);

        if ($user === null) {
            Session::getInstance()->flash('error', 'No se pudo completar el inicio de sesión SSO.');
            return $this->redirect('/login');
        }

        return $this->redirect('/dashboard');
    }
}
