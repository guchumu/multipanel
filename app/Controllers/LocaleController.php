<?php

declare(strict_types=1);

namespace App\Controllers;

use Core\Controller;
use Core\Language;
use Core\Request;
use Core\Response;
use Core\Session;

/**
 * Locale / language switcher controller.
 */
class LocaleController extends Controller
{
    /** @var list<string> */
    private const ALLOWED = ['es', 'en'];

    public function switch(Request $request, string $locale): Response
    {
        if (!in_array($locale, self::ALLOWED, true)) {
            $locale = 'es';
        }

        Language::setLocale($locale);
        Session::getInstance()->set('locale', $locale);

        $user = (new \App\Services\AuthService())->user();
        if ($user) {
            $user->locale = $locale;
            $user->save();
        }

        $redirect = $request->input('redirect', '/dashboard');
        if (!is_string($redirect) || !str_starts_with($redirect, '/')) {
            $redirect = '/dashboard';
        }

        return $this->redirect($redirect);
    }
}
