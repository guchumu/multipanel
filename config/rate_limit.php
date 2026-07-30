<?php

declare(strict_types=1);

return [
    'max' => (int) env('RATE_LIMIT_MAX', 60),
    'window' => (int) env('RATE_LIMIT_WINDOW', 60),
];
