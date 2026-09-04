<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Installation;

use LogicException;

final class InstallationFileRenderer
{
    public function __construct(private readonly InstallationServiceCatalog $services = new InstallationServiceCatalog) {}

    public function environment(InstallationSelection|InstallationDiscovery $installation): string
    {
        $discovery = $this->discovery($installation);
        $selection = $discovery->selection;
        $sections = [
            $this->baseEnvironment($discovery),
            $this->databaseEnvironment($selection->database, $discovery),
            $this->cacheEnvironment($selection->cache, $discovery),
            $this->mailEnvironment($selection->mail, $discovery),
        ];

        foreach ($selection->additionalServices as $service) {
            $sections[] = $this->serviceEnvironment($service, $discovery);
        }

        $variables = [];
        foreach ($sections as $section) {
            foreach (preg_split('/\R/', $section) ?: [] as $line) {
                if ($line === '') {
                    continue;
                }
                $separator = strpos($line, '=');
                if ($separator !== false) {
                    $variables[substr($line, 0, $separator)] = $line;
                }
            }
        }

        return implode("\n", $variables)."\n";
    }

    public function configuration(InstallationSelection|InstallationDiscovery $installation): string
    {
        $discovery = $this->discovery($installation);
        $selection = $discovery->selection;
        $databaseEnabled = in_array($selection->database, ['sqlite', 'mysql', 'mariadb', 'pgsql'], true);
        $databaseConnection = $databaseEnabled ? $selection->database : null;
        $installation = $this->export([...$selection->toArray(), 'discovery' => $discovery->metadata()], 1);
        $services = [];
        if ($selection->provider === 'shared') {
            foreach ($selection->services() as $service) {
                $services[$service] = ['driver' => 'shared'];
            }
        }
        $exportedServices = $this->export($services, 1);
        $compose = $selection->provider === 'compose'
            ? ['services' => [
                'file' => 'docker-compose.harbour.yml',
                'ports' => $this->services->portDefinitions($selection->services()),
            ]]
            : [];
        $exportedCompose = $this->export($compose, 1);
        $enabled = $databaseEnabled ? 'true' : 'false';
        $connection = $databaseConnection === null ? 'null' : "'{$databaseConnection}'";
        $databaseComment = $selection->database === 'mongodb'
            ? "// MongoDB is connection-only: Harbour never creates, marks,\n            // migrates, or destroys the selected MongoDB database."
            : '// SQL lifecycle is guarded by the persisted Harbour ownership marker.';

        return <<<PHP
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

            // Recorded so the selected installation is reviewable and reproducible.
            'installation' => {$installation},

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

            {$databaseComment}
            'database' => [
                'enabled' => {$enabled},
                'connection' => {$connection},
                'sqlite_path' => 'database/harbour.sqlite',
                'migrate' => {$enabled},
                'seed' => false,
            ],

            'variables' => [],
            'resolvers' => [],

            // Shared services are reused; Compose services are owned per workspace.
            'services' => {$exportedServices},
            'compose' => {$exportedCompose},

            'hooks' => [
                // Prefer argv lists, for example [PHP_BINARY, 'artisan', 'about'].
                // String hooks are supported and intentionally run through a shell.
                'before_setup' => [],
                'after_setup' => [],
                'before_teardown' => [],
                'after_teardown' => [],
            ],
        ];

        PHP;
    }

    private function baseEnvironment(InstallationDiscovery $discovery): string
    {
        $appName = $discovery->templateValue('APP_NAME', 'Laravel');

        $environment = <<<ENV
        APP_NAME={$appName}
        APP_ENV=local
        APP_KEY=\${APP_KEY}
        APP_DEBUG=true
        APP_URL=\${APP_URL}
        APP_PORT=\${APP_PORT}

        REDIS_PREFIX=\${REDIS_PREFIX}
        CACHE_PREFIX=\${CACHE_PREFIX}
        SESSION_COOKIE=\${SESSION_COOKIE}
        QUEUE_PREFIX=\${QUEUE_PREFIX}
        QUEUE_NAME=\${QUEUE_NAME}
        REDIS_QUEUE=\${REDIS_QUEUE}
        HORIZON_PREFIX=\${HORIZON_PREFIX}

        VITE_PORT=\${VITE_PORT}
        REVERB_HOST=127.0.0.1
        REVERB_PORT=\${REVERB_PORT}
        REVERB_SERVER_HOST=127.0.0.1
        REVERB_SERVER_PORT=\${REVERB_PORT}
        ENV;

        foreach (['REVERB_APP_ID', 'REVERB_APP_KEY', 'REVERB_APP_SECRET', 'REVERB_SCHEME'] as $variable) {
            if ($discovery->hasEnvironmentVariable($variable)) {
                $environment .= "\n".$variable.'=${'.$variable.'}';
            }
        }
        if ($discovery->hasEnvironmentVariable('BROADCAST_CONNECTION')) {
            $environment .= "\nBROADCAST_CONNECTION=\${BROADCAST_CONNECTION}";
        }
        if ($discovery->hasEnvironmentVariable('REVERB_APP_KEY')) {
            $scheme = $discovery->templateValue('REVERB_SCHEME', 'http');
            $environment .= "\nVITE_REVERB_APP_KEY=\${REVERB_APP_KEY}"
                ."\nVITE_REVERB_HOST=127.0.0.1"
                ."\nVITE_REVERB_PORT=\${REVERB_PORT}"
                ."\nVITE_REVERB_SCHEME={$scheme}";
        }

        return $environment;
    }

    private function databaseEnvironment(string $database, InstallationDiscovery $discovery): string
    {
        $port = $this->servicePort($database, $discovery, $database === 'pgsql' ? 5432 : ($database === 'mongodb' ? 27017 : 3306));
        $managed = $discovery->selection->provider === 'compose';
        $username = $managed && $database === 'pgsql'
            ? 'harbour'
            : $discovery->templateValue('DB_USERNAME', $database === 'pgsql' ? 'postgres' : 'root');
        $password = $managed && in_array($database, ['mysql', 'mariadb', 'pgsql'], true)
            ? 'harbour'
            : $discovery->templateValue('DB_PASSWORD', '');
        $mongodbUri = $discovery->serviceValue('MONGODB_URI', 'mongodb', "mongodb://127.0.0.1:{$port}");
        $host = $discovery->serviceHost('DB_HOST', $database);

        return match ($database) {
            'none' => "DB_CONNECTION=sqlite\nDB_DATABASE=:memory:",
            'sqlite' => "DB_CONNECTION=sqlite\nDB_DATABASE=\${DB_DATABASE}",
            'mysql' => "DB_CONNECTION=mysql\nDB_HOST={$host}\nDB_PORT={$port}\nDB_DATABASE=\${DB_DATABASE}\nDB_USERNAME={$username}\nDB_PASSWORD={$password}",
            'mariadb' => "DB_CONNECTION=mariadb\nDB_HOST={$host}\nDB_PORT={$port}\nDB_DATABASE=\${DB_DATABASE}\nDB_USERNAME={$username}\nDB_PASSWORD={$password}",
            'pgsql' => "DB_CONNECTION=pgsql\nDB_HOST={$host}\nDB_PORT={$port}\nDB_DATABASE=\${DB_DATABASE}\nDB_USERNAME={$username}\nDB_PASSWORD={$password}",
            'mongodb' => "DB_CONNECTION=mongodb\nMONGODB_URI={$mongodbUri}\nMONGODB_DATABASE=\${MONGODB_DATABASE}",
            default => throw new LogicException("Unsupported rendered database [{$database}]."),
        };
    }

    private function cacheEnvironment(string $cache, InstallationDiscovery $discovery): string
    {
        $port = $this->servicePort($cache, $discovery, $cache === 'memcached' ? 11211 : 6379);
        $password = $discovery->templateValue('REDIS_PASSWORD', 'null');
        $redisHost = $discovery->serviceHost('REDIS_HOST', $cache);
        $memcachedHost = $discovery->serviceHost('MEMCACHED_HOST', 'memcached');

        return match ($cache) {
            'none' => "CACHE_STORE=array\nSESSION_DRIVER=array\nQUEUE_CONNECTION=sync",
            'file' => "CACHE_STORE=file\nSESSION_DRIVER=file\nQUEUE_CONNECTION=sync",
            'database' => "CACHE_STORE=database\nSESSION_DRIVER=database\nQUEUE_CONNECTION=database",
            'redis' => "CACHE_STORE=redis\nSESSION_DRIVER=redis\nQUEUE_CONNECTION=redis\nREDIS_HOST={$redisHost}\nREDIS_PASSWORD={$password}\nREDIS_PORT={$port}",
            'valkey' => "CACHE_STORE=redis\nSESSION_DRIVER=redis\nQUEUE_CONNECTION=redis\nREDIS_HOST={$redisHost}\nREDIS_PASSWORD={$password}\nREDIS_PORT={$port}",
            'memcached' => "CACHE_STORE=memcached\nSESSION_DRIVER=file\nQUEUE_CONNECTION=sync\nMEMCACHED_HOST={$memcachedHost}\nMEMCACHED_PORT={$port}",
            default => throw new LogicException("Unsupported rendered cache [{$cache}]."),
        };
    }

    private function mailEnvironment(string $mail, InstallationDiscovery $discovery): string
    {
        $port = $this->servicePort('mailpit', $discovery, 1025);
        $dashboardPort = $discovery->selection->provider === 'compose'
            ? '\${MAILPIT_DASHBOARD_PORT}'
            : (string) $discovery->port('mailpit-dashboard', 8025);
        $host = $discovery->serviceHost('MAIL_HOST', 'mailpit');
        $username = $discovery->templateValue('MAIL_USERNAME', 'null');
        $password = $discovery->templateValue('MAIL_PASSWORD', 'null');
        $dashboardUrl = $discovery->serviceValue('MAILPIT_URL', 'mailpit', "http://127.0.0.1:{$dashboardPort}");

        return match ($mail) {
            'none' => 'MAIL_MAILER=array',
            'log' => 'MAIL_MAILER=log',
            'mailpit' => "MAIL_MAILER=smtp\nMAIL_HOST={$host}\nMAIL_PORT={$port}\nMAIL_USERNAME={$username}\nMAIL_PASSWORD={$password}\nMAIL_ENCRYPTION=null\nMAILPIT_URL={$dashboardUrl}",
            default => throw new LogicException("Unsupported rendered mail transport [{$mail}]."),
        };
    }

    private function serviceEnvironment(string $service, InstallationDiscovery $discovery): string
    {
        $port = $this->servicePort($service, $discovery, match ($service) {
            'meilisearch' => 7700,
            'typesense' => 8108,
            'minio', 'rustfs' => 9000,
            'rabbitmq' => 5672,
            'selenium' => 4444,
            'soketi' => 6001,
            default => 1,
        });
        $meilisearchHost = $discovery->serviceValue('MEILISEARCH_HOST', 'meilisearch', "http://127.0.0.1:{$port}");
        $typesenseHost = $discovery->serviceHost('TYPESENSE_HOST', 'typesense');
        $typesenseProtocol = $discovery->templateValue('TYPESENSE_PROTOCOL', 'http');
        $typesenseKey = $discovery->templateValue('TYPESENSE_API_KEY', 'xyz');
        $minioEndpoint = $discovery->serviceValue('MINIO_ENDPOINT', 'minio', "http://127.0.0.1:{$port}");
        $minioKey = $discovery->templateValue('MINIO_ACCESS_KEY_ID', 'sail');
        $minioSecret = $discovery->templateValue('MINIO_SECRET_ACCESS_KEY', 'password');
        $rustfsEndpoint = $discovery->serviceValue('RUSTFS_ENDPOINT', 'rustfs', "http://127.0.0.1:{$port}");
        $rustfsKey = $discovery->templateValue('RUSTFS_ACCESS_KEY_ID', 'rustfsadmin');
        $rustfsSecret = $discovery->templateValue('RUSTFS_SECRET_ACCESS_KEY', 'rustfsadmin');
        $rabbitmqHost = $discovery->serviceHost('RABBITMQ_HOST', 'rabbitmq');
        $seleniumUrl = $discovery->serviceValue('DUSK_DRIVER_URL', 'selenium', "http://127.0.0.1:{$port}/wd/hub");
        $pusherId = $discovery->templateValue('PUSHER_APP_ID', 'app-id');
        $pusherKey = $discovery->templateValue('PUSHER_APP_KEY', 'app-key');
        $pusherSecret = $discovery->templateValue('PUSHER_APP_SECRET', 'app-secret');
        $pusherHost = $discovery->serviceHost('PUSHER_HOST', 'soketi');
        $pusherScheme = $discovery->templateValue('PUSHER_SCHEME', 'http');

        return match ($service) {
            'meilisearch' => "MEILISEARCH_HOST={$meilisearchHost}\nMEILISEARCH_INDEX_PREFIX=\${SEARCH_PREFIX}",
            'typesense' => "TYPESENSE_HOST={$typesenseHost}\nTYPESENSE_PORT={$port}\nTYPESENSE_PROTOCOL={$typesenseProtocol}\nTYPESENSE_API_KEY={$typesenseKey}\nTYPESENSE_COLLECTION_PREFIX=\${SEARCH_PREFIX}",
            'minio' => "MINIO_ENDPOINT={$minioEndpoint}\nMINIO_ACCESS_KEY_ID={$minioKey}\nMINIO_SECRET_ACCESS_KEY={$minioSecret}\nMINIO_BUCKET=\${OBJECT_STORAGE_BUCKET}\nMINIO_USE_PATH_STYLE_ENDPOINT=true",
            'rustfs' => "RUSTFS_ENDPOINT={$rustfsEndpoint}\nRUSTFS_ACCESS_KEY_ID={$rustfsKey}\nRUSTFS_SECRET_ACCESS_KEY={$rustfsSecret}\nRUSTFS_BUCKET=\${OBJECT_STORAGE_BUCKET}\nRUSTFS_USE_PATH_STYLE_ENDPOINT=true",
            'rabbitmq' => "QUEUE_CONNECTION=rabbitmq\nRABBITMQ_HOST={$rabbitmqHost}\nRABBITMQ_PORT={$port}\nRABBITMQ_USER=harbour\nRABBITMQ_PASSWORD=harbour\nRABBITMQ_QUEUE=\${QUEUE_NAME}",
            'selenium' => "DUSK_DRIVER_URL={$seleniumUrl}",
            'soketi' => "BROADCAST_CONNECTION=pusher\nPUSHER_APP_ID={$pusherId}\nPUSHER_APP_KEY={$pusherKey}\nPUSHER_APP_SECRET={$pusherSecret}\nPUSHER_HOST={$pusherHost}\nPUSHER_PORT={$port}\nPUSHER_SCHEME={$pusherScheme}\nVITE_PUSHER_APP_KEY={$pusherKey}\nVITE_PUSHER_HOST={$pusherHost}\nVITE_PUSHER_PORT={$port}\nVITE_PUSHER_SCHEME={$pusherScheme}",
            default => throw new LogicException("Unsupported rendered service [{$service}]."),
        };
    }

    private function export(mixed $value, int $depth): string
    {
        if (! is_array($value)) {
            return var_export($value, true);
        }
        if ($value === []) {
            return '[]';
        }

        $list = array_is_list($value);
        $lines = ['['];
        foreach ($value as $key => $item) {
            $prefix = $list ? '' : var_export($key, true).' => ';
            $lines[] = str_repeat('    ', $depth + 1).$prefix.$this->export($item, $depth + 1).',';
        }
        $lines[] = str_repeat('    ', $depth).']';

        return implode("\n", $lines);
    }

    private function discovery(InstallationSelection|InstallationDiscovery $installation): InstallationDiscovery
    {
        return $installation instanceof InstallationDiscovery
            ? $installation
            : InstallationDiscovery::explicit($installation);
    }

    private function servicePort(string $service, InstallationDiscovery $discovery, int $default): string
    {
        if ($discovery->selection->provider === 'compose'
            && in_array($service, InstallationSelection::SAIL_SERVICES, true)) {
            return '${'.$this->services->primaryPortVariable($service).'}';
        }

        return (string) $discovery->port($service, $default);
    }
}
