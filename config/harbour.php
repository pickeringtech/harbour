<?php

declare(strict_types=1);

use PickeringTech\Harbour\Identity\DefaultWorkspaceIdentityStrategy;
use PickeringTech\Harbour\Ports\DefaultPortAllocationStrategy;

return [
    'enabled' => env('HARBOUR_ENABLED', env('APP_ENV') !== 'production'),

    'template' => '.env.harbour',
    'state' => '.harbour.json',
    'project_name' => env('APP_NAME'),
    'workspace_path' => null,

    'identity' => [
        'strategy' => DefaultWorkspaceIdentityStrategy::class,
    ],

    'registry' => [
        'path' => env('HARBOUR_STATE_HOME'),
    ],

    'ports' => [
        'strategy' => DefaultPortAllocationStrategy::class,
        'allocations' => [
            'APP_PORT' => ['range' => [8000, 8999]],
            'VITE_PORT' => ['range' => [9000, 9999]],
            'REVERB_PORT' => ['range' => [10000, 10999]],
        ],
    ],

    'database' => [
        'enabled' => true,
        'connection' => null,
        'sqlite_path' => 'database/harbour.sqlite',
        'migrate' => true,
        'seed' => false,
    ],

    'variables' => [],
    'resolvers' => [],

    'services' => [],
    'compose' => [],

    'hooks' => [
        'before_setup' => [],
        'after_setup' => [],
        'before_teardown' => [],
        'after_teardown' => [],
    ],
];
