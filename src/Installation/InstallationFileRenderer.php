<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Installation;

use LogicException;

final class InstallationFileRenderer
{
    public function environment(InstallationSelection $selection): string
    {
        $sections = [
            $this->baseEnvironment(),
            $this->databaseEnvironment($selection->database),
            $this->cacheEnvironment($selection->cache),
            $this->mailEnvironment($selection->mail),
        ];

        foreach ($selection->additionalServices as $service) {
            $sections[] = $this->serviceEnvironment($service);
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

    public function configuration(InstallationSelection $selection): string
    {
        $databaseEnabled = in_array($selection->database, ['sqlite', 'mysql', 'mariadb', 'pgsql'], true);
        $databaseConnection = $databaseEnabled ? $selection->database : null;
        $installation = $this->export($selection->toArray(), 1);
        $services = [];
        foreach ($selection->services() as $service) {
            $services[$service] = ['driver' => 'shared'];
        }
        $exportedServices = $this->export($services, 1);
        $enabled = $databaseEnabled ? 'true' : 'false';
        $connection = $databaseConnection === null ? 'null' : "'{$databaseConnection}'";

        return <<<PHP
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

            'database' => [
                'enabled' => {$enabled},
                'connection' => {$connection},
                'sqlite_path' => 'database/harbour.sqlite',
                'migrate' => {$enabled},
                'seed' => false,
            ],

            'variables' => [],
            'resolvers' => [],

            // Install selections use existing shared infrastructure. Change an entry
            // to the documented Docker configuration when isolation needs a container.
            'services' => {$exportedServices},
            'compose' => [],

            'hooks' => [
                'before_setup' => [],
                'after_setup' => [],
                'before_teardown' => [],
                'after_teardown' => [],
            ],
        ];

        PHP;
    }

    private function baseEnvironment(): string
    {
        return <<<'ENV'
        APP_NAME=Laravel
        APP_ENV=local
        APP_KEY=${APP_KEY}
        APP_DEBUG=true
        APP_URL=${APP_URL}

        REDIS_PREFIX=${REDIS_PREFIX}
        CACHE_PREFIX=${CACHE_PREFIX}
        SESSION_COOKIE=${SESSION_COOKIE}
        QUEUE_PREFIX=${QUEUE_PREFIX}
        QUEUE_NAME=${QUEUE_NAME}
        REDIS_QUEUE=${REDIS_QUEUE}
        HORIZON_PREFIX=${HORIZON_PREFIX}

        VITE_PORT=${VITE_PORT}
        VITE_HOT_FILE=${VITE_HOT_FILE}

        REVERB_HOST=127.0.0.1
        REVERB_PORT=${REVERB_PORT}
        ENV;
    }

    private function databaseEnvironment(string $database): string
    {
        return match ($database) {
            'none' => "DB_CONNECTION=sqlite\nDB_DATABASE=:memory:",
            'sqlite' => "DB_CONNECTION=sqlite\nDB_DATABASE=\${DB_DATABASE}",
            'mysql' => "DB_CONNECTION=mysql\nDB_HOST=127.0.0.1\nDB_PORT=3306\nDB_DATABASE=\${DB_DATABASE}\nDB_USERNAME=root\nDB_PASSWORD=",
            'mariadb' => "DB_CONNECTION=mariadb\nDB_HOST=127.0.0.1\nDB_PORT=3306\nDB_DATABASE=\${DB_DATABASE}\nDB_USERNAME=root\nDB_PASSWORD=",
            'pgsql' => "DB_CONNECTION=pgsql\nDB_HOST=127.0.0.1\nDB_PORT=5432\nDB_DATABASE=\${DB_DATABASE}\nDB_USERNAME=root\nDB_PASSWORD=",
            'mongodb' => "DB_CONNECTION=mongodb\nMONGODB_URI=mongodb://127.0.0.1:27017\nMONGODB_DATABASE=\${MONGODB_DATABASE}",
            default => throw new LogicException("Unsupported rendered database [{$database}]."),
        };
    }

    private function cacheEnvironment(string $cache): string
    {
        return match ($cache) {
            'none' => "CACHE_STORE=array\nSESSION_DRIVER=array\nQUEUE_CONNECTION=sync",
            'file' => "CACHE_STORE=file\nSESSION_DRIVER=file\nQUEUE_CONNECTION=sync",
            'database' => "CACHE_STORE=database\nSESSION_DRIVER=database\nQUEUE_CONNECTION=database",
            'redis' => "CACHE_STORE=redis\nSESSION_DRIVER=redis\nQUEUE_CONNECTION=redis\nREDIS_HOST=127.0.0.1\nREDIS_PORT=6379",
            'valkey' => "CACHE_STORE=redis\nSESSION_DRIVER=redis\nQUEUE_CONNECTION=redis\nREDIS_HOST=127.0.0.1\nREDIS_PORT=6379",
            'memcached' => "CACHE_STORE=memcached\nSESSION_DRIVER=file\nQUEUE_CONNECTION=sync\nMEMCACHED_HOST=127.0.0.1\nMEMCACHED_PORT=11211",
            default => throw new LogicException("Unsupported rendered cache [{$cache}]."),
        };
    }

    private function mailEnvironment(string $mail): string
    {
        return match ($mail) {
            'none' => 'MAIL_MAILER=array',
            'log' => 'MAIL_MAILER=log',
            'mailpit' => "MAIL_MAILER=smtp\nMAIL_HOST=127.0.0.1\nMAIL_PORT=1025\nMAIL_USERNAME=null\nMAIL_PASSWORD=null\nMAIL_ENCRYPTION=null\nMAILPIT_URL=http://127.0.0.1:8025",
            default => throw new LogicException("Unsupported rendered mail transport [{$mail}]."),
        };
    }

    private function serviceEnvironment(string $service): string
    {
        return match ($service) {
            'meilisearch' => "MEILISEARCH_HOST=http://127.0.0.1:7700\nMEILISEARCH_INDEX_PREFIX=\${SEARCH_PREFIX}",
            'typesense' => "TYPESENSE_HOST=127.0.0.1\nTYPESENSE_PORT=8108\nTYPESENSE_PROTOCOL=http\nTYPESENSE_API_KEY=xyz\nTYPESENSE_COLLECTION_PREFIX=\${SEARCH_PREFIX}",
            'minio' => "MINIO_ENDPOINT=http://127.0.0.1:9000\nMINIO_ACCESS_KEY_ID=sail\nMINIO_SECRET_ACCESS_KEY=password\nMINIO_BUCKET=\${OBJECT_STORAGE_BUCKET}\nMINIO_USE_PATH_STYLE_ENDPOINT=true",
            'rustfs' => "RUSTFS_ENDPOINT=http://127.0.0.1:9000\nRUSTFS_ACCESS_KEY_ID=rustfsadmin\nRUSTFS_SECRET_ACCESS_KEY=rustfsadmin\nRUSTFS_BUCKET=\${OBJECT_STORAGE_BUCKET}\nRUSTFS_USE_PATH_STYLE_ENDPOINT=true",
            'rabbitmq' => "QUEUE_CONNECTION=rabbitmq\nRABBITMQ_HOST=127.0.0.1\nRABBITMQ_PORT=5672\nRABBITMQ_QUEUE=\${QUEUE_NAME}",
            'selenium' => 'DUSK_DRIVER_URL=http://127.0.0.1:4444/wd/hub',
            'soketi' => "BROADCAST_CONNECTION=pusher\nPUSHER_APP_ID=app-id\nPUSHER_APP_KEY=app-key\nPUSHER_APP_SECRET=app-secret\nPUSHER_HOST=127.0.0.1\nPUSHER_PORT=6001\nPUSHER_SCHEME=http\nVITE_PUSHER_APP_KEY=app-key\nVITE_PUSHER_HOST=127.0.0.1\nVITE_PUSHER_PORT=6001\nVITE_PUSHER_SCHEME=http",
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
}
