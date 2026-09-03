<?php

declare(strict_types=1);

use PickeringTech\Harbour\Identity\DefaultWorkspaceIdentityStrategy;
use PickeringTech\Harbour\Ports\DefaultPortAllocationStrategy;

$docker = getenv('HARBOUR_ACCEPTANCE_DOCKER') === '1';
$failAfterSetup = getenv('HARBOUR_ACCEPTANCE_FAIL') === '1';
$stateHome = getenv('HARBOUR_STATE_HOME');
$databaseConnection = getenv('DB_CONNECTION');

return [
    'enabled' => true,
    'template' => '.env.harbour',
    'state' => '.harbour.json',
    'project_name' => 'Harbour Acceptance',
    'workspace_path' => null,
    'identity' => ['strategy' => DefaultWorkspaceIdentityStrategy::class],
    'registry' => ['path' => is_string($stateHome) && $stateHome !== '' ? $stateHome : null],
    'ports' => [
        'strategy' => DefaultPortAllocationStrategy::class,
        'allocations' => [
            'APP_PORT' => ['range' => [21800, 21899]],
            'VITE_PORT' => ['range' => [21900, 21999]],
            'REVERB_PORT' => ['range' => [22000, 22099]],
        ],
    ],
    'database' => [
        'enabled' => true,
        'connection' => is_string($databaseConnection) && $databaseConnection !== '' ? $databaseConnection : 'pgsql',
        'sqlite_path' => 'database/harbour.sqlite',
        'migrate' => true,
        'seed' => false,
    ],
    'variables' => [],
    'resolvers' => [],
    'services' => $docker ? [
        'acceptance-service' => [
            'driver' => 'docker',
            'image' => 'alpine:3.22',
            'command' => ['sleep', '300'],
        ],
    ] : [],
    'compose' => $docker ? [
        'acceptance-stack' => ['file' => 'docker-compose.harbour.yml'],
    ] : [],
    'hooks' => [
        'before_setup' => [],
        'after_setup' => $failAfterSetup ? [[PHP_BINARY, '-r', 'exit(23);']] : [],
        'before_teardown' => [],
        'after_teardown' => [],
    ],
];
