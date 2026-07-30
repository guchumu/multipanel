<?php

declare(strict_types=1);

return [
    'policies' => [
        [
            'action' => 'billing.manage',
            'effect' => 'allow',
            'conditions' => ['role_id' => [1, 2, 3]],
        ],
        [
            'action' => 'servers.delete',
            'effect' => 'allow',
            'conditions' => ['role_id' => [1, 2]],
        ],
        [
            'action' => 'settings.manage',
            'effect' => 'allow',
            'conditions' => ['role_id' => [1, 2]],
        ],
    ],
];
