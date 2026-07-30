<?php

declare(strict_types=1);

$baseUrl = rtrim(env('APP_URL', 'http://localhost'), '/');

return [
    'google' => [
        'enabled' => env('OAUTH_GOOGLE_ENABLED', false),
        'label' => 'Google',
        'icon' => 'bi-google',
        'client_id' => env('OAUTH_GOOGLE_CLIENT_ID', ''),
        'client_secret' => env('OAUTH_GOOGLE_CLIENT_SECRET', ''),
        'redirect_uri' => $baseUrl . '/auth/oauth/google/callback',
        'authorize_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
        'token_url' => 'https://oauth2.googleapis.com/token',
        'userinfo_url' => 'https://openidconnect.googleapis.com/v1/userinfo',
        'scopes' => ['openid', 'email', 'profile'],
    ],
    'github' => [
        'enabled' => env('OAUTH_GITHUB_ENABLED', false),
        'label' => 'GitHub',
        'icon' => 'bi-github',
        'client_id' => env('OAUTH_GITHUB_CLIENT_ID', ''),
        'client_secret' => env('OAUTH_GITHUB_CLIENT_SECRET', ''),
        'redirect_uri' => $baseUrl . '/auth/oauth/github/callback',
        'authorize_url' => 'https://github.com/login/oauth/authorize',
        'token_url' => 'https://github.com/login/oauth/access_token',
        'userinfo_url' => 'https://api.github.com/user',
        'email_url' => 'https://api.github.com/user/emails',
        'scopes' => ['read:user', 'user:email'],
    ],
    'microsoft' => [
        'enabled' => env('OAUTH_MICROSOFT_ENABLED', false),
        'label' => 'Microsoft',
        'icon' => 'bi-microsoft',
        'client_id' => env('OAUTH_MICROSOFT_CLIENT_ID', ''),
        'client_secret' => env('OAUTH_MICROSOFT_CLIENT_SECRET', ''),
        'redirect_uri' => $baseUrl . '/auth/oauth/microsoft/callback',
        'authorize_url' => 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize',
        'token_url' => 'https://login.microsoftonline.com/common/oauth2/v2.0/token',
        'userinfo_url' => 'https://graph.microsoft.com/v1.0/me',
        'scopes' => ['openid', 'email', 'profile', 'User.Read'],
    ],
];
