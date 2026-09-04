<?php

declare(strict_types=1);

use PickeringTech\Harbour\Identity\DefaultWorkspaceIdentityStrategy;
use PickeringTech\Harbour\Ports\DefaultPortAllocationStrategy;

return [
    'enabled' => env('HARBOUR_ENABLED', in_array(env('APP_ENV'), ['local', 'testing'], true)),

    'template' => '.env.harbour',
    'state' => '.harbour.json',
    'project_name' => env('APP_NAME'),
    'workspace_path' => null,

    'installation' => [
        'database' => null,
        'cache' => null,
        'mail' => null,
        'services' => [],
        'provider' => 'shared',
        'discovery' => [
            'detected' => false,
            'sources' => [],
        ],
    ],

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

    'vite' => [
        'hot_file' => env('VITE_HOT_FILE'),
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
        // Prefer argv lists, for example [PHP_BINARY, 'artisan', 'about'].
        // String hooks are supported and intentionally run through a shell.
        'before_setup' => [],
        'after_setup' => [],
        'before_teardown' => [],
        'after_teardown' => [],
    ],
];
