<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthService;
use Core\Controller;
use Core\Request;
use Core\Response;
use Core\Session;
use Core\Exceptions\HttpException;

/**
 * Authentication controller.
 */
class AuthController extends Controller
{
    public function __construct(
        private AuthService $auth = new AuthService(),
    ) {
    }

    public function showLogin(Request $request): Response
    {
        if ($this->auth->check()) {
            return $this->redirect('/dashboard');
        }

        $oauth = new \App\Services\OAuthService();

        return $this->view('auth.login', [
            'title' => 'Iniciar sesión',
            'oauthProviders' => $oauth->getEnabledProviders(),
        ]);
    }

    public function login(Request $request): Response
    {
        $data = $this->validate($request, [
            'email' => 'required|email',
            'password' => 'required|min:6',
        ]);

        $user = $this->auth->attempt($data['email'], $data['password']);

        if ($user === null) {
            Session::getInstance()->flash('error', 'Credenciales incorrectas.');
            Session::getInstance()->flash('old.email', $data['email']);
            return $this->redirect('/login');
        }

        return $this->redirect('/dashboard');
    }

    public function logout(Request $request): Response
    {
        $this->auth->logout();
        return $this->redirect('/login');
    }
}
