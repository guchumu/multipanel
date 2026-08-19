<?php

declare(strict_types=1);

return [
    'bot_token' => env('TELEGRAM_BOT_TOKEN', ''),
    'chat_id' => env('TELEGRAM_CHAT_ID', ''),
    'bot_username' => env('TELEGRAM_BOT_USERNAME', ''),
    'sandbox_chat_id' => env('TELEGRAM_SANDBOX_CHAT_ID', ''),
    'sandbox_enabled' => filter_var(env('TELEGRAM_SANDBOX', false), FILTER_VALIDATE_BOOLEAN),
    'sandbox_copy_real' => filter_var(env('TELEGRAM_SANDBOX_COPY_REAL', false), FILTER_VALIDATE_BOOLEAN),
];
