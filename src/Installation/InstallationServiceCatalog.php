<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Installation;

use LogicException;

final class InstallationServiceCatalog
{
    /** @var array<string, InstallationService>|null */
    private ?array $services = null;

    /** @return array<string, InstallationService> */
    public function all(): array
    {
        return $this->services ??= $this->definitions();
    }

    public function get(string $name): InstallationService
    {
        return $this->all()[$name] ?? throw new LogicException("Unsupported installation service [{$name}].");
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->all());
    }

    /** @return list<string> */
    public function namesFor(string $group): array
    {
        return array_keys(array_filter($this->all(), static fn (InstallationService $service): bool => $service->group === $group));
    }

    /** @return array<string, string> */
    public function optionsFor(string $group): array
    {
        $options = [];
        foreach ($this->all() as $service) {
            if ($service->group === $group) {
                $options[$service->name] = $service->label;
            }
        }

        return $options;
    }

    public function normalize(string $name): string
    {
        $name = strtolower($name);
        foreach ($this->all() as $service) {
            if ($name === $service->name || in_array($name, $service->aliases, true)) {
                return $service->name;
            }
        }

        return $name;
    }

    /** @return array<string, array{container: int, range: array{int, int}, forward: string, discovery: string}> */
    public function portsFor(string $service): array
    {
        return $this->get($service)->ports;
    }

    /**
     * @param  list<string>  $services
     * @return array<string, array{range: array{int, int}}>
     */
    public function portDefinitions(array $services): array
    {
        $definitions = [];
        foreach ($services as $service) {
            foreach ($this->portsFor($service) as $variable => $definition) {
                $definitions[$variable] = ['range' => $definition['range']];
            }
        }

        return $definitions;
    }

    public function primaryPortVariable(string $service): string
    {
        return $this->get($service)->primaryPortVariable();
    }

    /** @return array<string, InstallationService> */
    private function definitions(): array
    {
        return [
            'mysql' => $this->service('mysql', 'MySQL', 'database', [], ['DB_PORT' => $this->port(3306, 'FORWARD_DB_PORT', 'mysql')], 'mysql:8.4', ['MYSQL_ROOT_PASSWORD' => 'harbour', 'MYSQL_ROOT_HOST' => '%', 'MYSQL_ALLOW_EMPTY_PASSWORD' => '0'], ['CMD', 'mysqladmin', 'ping', '-pharbour'], '/var/lib/mysql', true, <<<'ENV'
            DB_CONNECTION=mysql
            DB_HOST={{host:DB_HOST}}
            DB_PORT={{port}}
            DB_DATABASE=${DB_DATABASE}
            DB_USERNAME={{template:DB_USERNAME|root}}
            DB_PASSWORD={{managed-template:DB_PASSWORD|harbour|}}
            ENV),
            'pgsql' => $this->service('pgsql', 'PostgreSQL', 'database', ['postgres', 'postgresql'], ['DB_PORT' => $this->port(5432, 'FORWARD_DB_PORT', 'pgsql')], 'postgres:18.0-alpine', ['POSTGRES_DB' => 'postgres', 'POSTGRES_USER' => 'harbour', 'POSTGRES_PASSWORD' => 'harbour'], ['CMD', 'pg_isready', '-q', '-d', 'postgres', '-U', 'harbour'], '/var/lib/postgresql', true, <<<'ENV'
            DB_CONNECTION=pgsql
            DB_HOST={{host:DB_HOST}}
            DB_PORT={{port}}
            DB_DATABASE=${DB_DATABASE}
            DB_USERNAME={{managed-template:DB_USERNAME|harbour|postgres}}
            DB_PASSWORD={{managed-template:DB_PASSWORD|harbour|}}
            ENV),
            'mariadb' => $this->service('mariadb', 'MariaDB', 'database', [], ['DB_PORT' => $this->port(3306, 'FORWARD_DB_PORT', 'mariadb')], 'mariadb:11.8', ['MARIADB_ROOT_PASSWORD' => 'harbour'], ['CMD', 'healthcheck.sh', '--connect', '--innodb_initialized'], '/var/lib/mysql', true, <<<'ENV'
            DB_CONNECTION=mariadb
            DB_HOST={{host:DB_HOST}}
            DB_PORT={{port}}
            DB_DATABASE=${DB_DATABASE}
            DB_USERNAME={{template:DB_USERNAME|root}}
            DB_PASSWORD={{managed-template:DB_PASSWORD|harbour|}}
            ENV),
            'mongodb' => $this->service('mongodb', 'MongoDB (connection only; database is not owned)', 'database', ['mongo'], ['MONGODB_PORT' => $this->port(27017, 'FORWARD_MONGODB_PORT', 'mongodb')], 'mongo:8.0', [], ['CMD', 'mongosh', 'mongodb://localhost:27017/admin', '--quiet', '--eval=db.runCommand({ping:1})'], '/data/db', false, <<<'ENV'
            DB_CONNECTION=mongodb
            MONGODB_URI={{value:MONGODB_URI|mongodb://127.0.0.1:{{port}}}}
            MONGODB_DATABASE=${MONGODB_DATABASE}
            ENV),
            'redis' => $this->service('redis', 'Redis', 'cache', [], ['REDIS_PORT' => $this->port(6379, 'FORWARD_REDIS_PORT', 'redis')], 'redis:8.2-alpine', [], ['CMD', 'redis-cli', 'ping'], '/data', false, <<<'ENV'
            CACHE_STORE=redis
            SESSION_DRIVER=redis
            QUEUE_CONNECTION=redis
            REDIS_HOST={{host:REDIS_HOST}}
            REDIS_PASSWORD={{template:REDIS_PASSWORD|null}}
            REDIS_PORT={{port}}
            ENV),
            'valkey' => $this->service('valkey', 'Valkey', 'cache', [], ['REDIS_PORT' => $this->port(6379, 'FORWARD_REDIS_PORT', 'valkey')], 'valkey/valkey:8.1-alpine', [], ['CMD', 'valkey-cli', 'ping'], '/data', false, <<<'ENV'
            CACHE_STORE=redis
            SESSION_DRIVER=redis
            QUEUE_CONNECTION=redis
            REDIS_HOST={{host:REDIS_HOST}}
            REDIS_PASSWORD={{template:REDIS_PASSWORD|null}}
            REDIS_PORT={{port}}
            ENV),
            'memcached' => $this->service('memcached', 'Memcached', 'cache', ['memcache'], ['MEMCACHED_PORT' => $this->port(11211, 'FORWARD_MEMCACHED_PORT', 'memcached')], 'memcached:1.6-alpine', [], ['CMD-SHELL', 'echo version | nc -w 1 127.0.0.1 11211 | grep -q VERSION'], null, false, <<<'ENV'
            CACHE_STORE=memcached
            SESSION_DRIVER=file
            QUEUE_CONNECTION=sync
            MEMCACHED_HOST={{host:MEMCACHED_HOST}}
            MEMCACHED_PORT={{port}}
            ENV),
            'meilisearch' => $this->service('meilisearch', 'Meilisearch', 'additional', [], ['MEILISEARCH_PORT' => $this->port(7700, 'FORWARD_MEILISEARCH_PORT', 'meilisearch')], 'getmeili/meilisearch:v1.15', ['MEILI_NO_ANALYTICS' => 'true'], ['CMD', 'wget', '--no-verbose', '--spider', 'http://127.0.0.1:7700/health'], '/meili_data', false, <<<'ENV'
            MEILISEARCH_HOST={{value:MEILISEARCH_HOST|http://127.0.0.1:{{port}}}}
            MEILISEARCH_INDEX_PREFIX=${SEARCH_PREFIX}
            ENV),
            'typesense' => $this->service('typesense', 'Typesense', 'additional', [], ['TYPESENSE_PORT' => $this->port(8108, 'FORWARD_TYPESENSE_PORT', 'typesense')], 'typesense/typesense:27.1', ['TYPESENSE_DATA_DIR' => '/typesense-data', 'TYPESENSE_API_KEY' => 'xyz', 'TYPESENSE_ENABLE_CORS' => 'true'], ['CMD', 'wget', '--no-verbose', '--spider', 'http://127.0.0.1:8108/health'], '/typesense-data', false, <<<'ENV'
            TYPESENSE_HOST={{host:TYPESENSE_HOST}}
            TYPESENSE_PORT={{port}}
            TYPESENSE_PROTOCOL={{template:TYPESENSE_PROTOCOL|http}}
            TYPESENSE_API_KEY={{template:TYPESENSE_API_KEY|xyz}}
            TYPESENSE_COLLECTION_PREFIX=${SEARCH_PREFIX}
            ENV),
            'minio' => $this->service('minio', 'MinIO', 'additional', [], ['MINIO_PORT' => $this->port(9000, 'FORWARD_MINIO_PORT', 'minio'), 'MINIO_CONSOLE_PORT' => $this->port(8900, 'FORWARD_MINIO_CONSOLE_PORT', 'minio-console')], 'minio/minio:RELEASE.2025-07-18T21-56-31Z', ['MINIO_ROOT_USER' => 'sail', 'MINIO_ROOT_PASSWORD' => 'password'], ['CMD', 'curl', '--fail', 'http://127.0.0.1:9000/minio/health/live'], '/data', false, <<<'ENV'
            MINIO_ENDPOINT={{value:MINIO_ENDPOINT|http://127.0.0.1:{{port}}}}
            MINIO_ACCESS_KEY_ID={{template:MINIO_ACCESS_KEY_ID|sail}}
            MINIO_SECRET_ACCESS_KEY={{template:MINIO_SECRET_ACCESS_KEY|password}}
            MINIO_BUCKET=${OBJECT_STORAGE_BUCKET}
            MINIO_USE_PATH_STYLE_ENDPOINT=true
            ENV, 'server /data --console-address ":8900"'),
            'rustfs' => $this->service('rustfs', 'RustFS', 'additional', [], ['RUSTFS_PORT' => $this->port(9000, 'FORWARD_RUSTFS_PORT', 'rustfs'), 'RUSTFS_CONSOLE_PORT' => $this->port(9001, 'FORWARD_RUSTFS_CONSOLE_PORT', 'rustfs-console')], 'rustfs/rustfs:1.0.0-beta.11', ['RUSTFS_VOLUMES' => '/data', 'RUSTFS_ADDRESS' => '0.0.0.0:9000', 'RUSTFS_CONSOLE_ADDRESS' => '0.0.0.0:9001', 'RUSTFS_CONSOLE_ENABLE' => 'true', 'RUSTFS_ACCESS_KEY' => 'rustfsadmin', 'RUSTFS_SECRET_KEY' => 'rustfsadmin'], ['CMD', 'curl', '--fail', 'http://127.0.0.1:9000/health'], '/data', false, <<<'ENV'
            RUSTFS_ENDPOINT={{value:RUSTFS_ENDPOINT|http://127.0.0.1:{{port}}}}
            RUSTFS_ACCESS_KEY_ID={{template:RUSTFS_ACCESS_KEY_ID|rustfsadmin}}
            RUSTFS_SECRET_ACCESS_KEY={{template:RUSTFS_SECRET_ACCESS_KEY|rustfsadmin}}
            RUSTFS_BUCKET=${OBJECT_STORAGE_BUCKET}
            RUSTFS_USE_PATH_STYLE_ENDPOINT=true
            ENV),
            'mailpit' => $this->service('mailpit', 'Mailpit', 'mail', [], ['MAIL_PORT' => $this->port(1025, 'FORWARD_MAILPIT_PORT', 'mailpit'), 'MAILPIT_DASHBOARD_PORT' => $this->port(8025, 'FORWARD_MAILPIT_DASHBOARD_PORT', 'mailpit-dashboard')], 'axllent/mailpit:v1.27', [], ['CMD', 'wget', '--quiet', '--spider', 'http://127.0.0.1:8025/readyz'], null, false, <<<'ENV'
            MAIL_MAILER=smtp
            MAIL_HOST={{host:MAIL_HOST}}
            MAIL_PORT={{port}}
            MAIL_USERNAME={{template:MAIL_USERNAME|null}}
            MAIL_PASSWORD={{template:MAIL_PASSWORD|null}}
            MAIL_ENCRYPTION=null
            MAILPIT_URL={{value:MAILPIT_URL|http://127.0.0.1:{{port:MAILPIT_DASHBOARD_PORT}}}}
            ENV),
            'rabbitmq' => $this->service('rabbitmq', 'RabbitMQ', 'additional', [], ['RABBITMQ_PORT' => $this->port(5672, 'FORWARD_RABBITMQ_PORT', 'rabbitmq'), 'RABBITMQ_DASHBOARD_PORT' => $this->port(15672, 'FORWARD_RABBITMQ_DASHBOARD_PORT', 'rabbitmq-dashboard')], 'rabbitmq:4.1-management-alpine', ['RABBITMQ_DEFAULT_USER' => 'harbour', 'RABBITMQ_DEFAULT_PASS' => 'harbour'], ['CMD', 'rabbitmq-diagnostics', '-q', 'ping'], '/var/lib/rabbitmq', false, <<<'ENV'
            QUEUE_CONNECTION=rabbitmq
            RABBITMQ_HOST={{host:RABBITMQ_HOST}}
            RABBITMQ_PORT={{port}}
            RABBITMQ_USER=harbour
            RABBITMQ_PASSWORD=harbour
            RABBITMQ_QUEUE=${QUEUE_NAME}
            ENV),
            'selenium' => $this->service('selenium', 'Selenium', 'additional', [], ['SELENIUM_PORT' => $this->port(4444, 'FORWARD_SELENIUM_PORT', 'selenium')], 'selenium/standalone-chromium:4.35.0', [], ['CMD-SHELL', 'curl --fail --silent http://127.0.0.1:4444/wd/hub/status | grep -q \'"ready": true\''], null, false, <<<'ENV'
            DUSK_DRIVER_URL={{value:DUSK_DRIVER_URL|http://127.0.0.1:{{port}}/wd/hub}}
            ENV, null, ['shm_size' => '2gb']),
            'soketi' => $this->service('soketi', 'Soketi', 'additional', [], ['PUSHER_PORT' => $this->port(6001, 'FORWARD_SOKETI_PORT', 'soketi'), 'PUSHER_METRICS_PORT' => $this->port(9601, 'FORWARD_SOKETI_METRICS_PORT', 'soketi-metrics')], 'quay.io/soketi/soketi:1.6-16-alpine', ['SOKETI_DEFAULT_APP_ID' => 'app-id', 'SOKETI_DEFAULT_APP_KEY' => 'app-key', 'SOKETI_DEFAULT_APP_SECRET' => 'app-secret', 'SOKETI_METRICS_SERVER_PORT' => '9601'], ['CMD', 'wget', '--quiet', '--spider', 'http://127.0.0.1:6001/ready'], null, false, <<<'ENV'
            BROADCAST_CONNECTION=pusher
            PUSHER_APP_ID={{template:PUSHER_APP_ID|app-id}}
            PUSHER_APP_KEY={{template:PUSHER_APP_KEY|app-key}}
            PUSHER_APP_SECRET={{template:PUSHER_APP_SECRET|app-secret}}
            PUSHER_HOST={{host:PUSHER_HOST}}
            PUSHER_PORT={{port}}
            PUSHER_SCHEME={{template:PUSHER_SCHEME|http}}
            VITE_PUSHER_APP_KEY={{template:PUSHER_APP_KEY|app-key}}
            VITE_PUSHER_HOST={{host:PUSHER_HOST}}
            VITE_PUSHER_PORT={{port}}
            VITE_PUSHER_SCHEME={{template:PUSHER_SCHEME|http}}
            ENV),
        ];
    }

    /** @return array{container: int, range: array{int, int}, forward: string, discovery: string} */
    private function port(int $container, string $forward, ?string $discovery = null): array
    {
        return ['container' => $container, 'range' => [11000, 29999], 'forward' => $forward, 'discovery' => $discovery ?? ''];
    }

    /**
     * @param  list<string>  $aliases
     * @param  array<string, array{container: int, range: array{int, int}, forward: string, discovery: string}>  $ports
     * @param  array<string, string>  $environment
     * @param  list<string>  $healthcheck
     * @param  array<string, string>  $properties
     */
    private function service(
        string $name,
        string $label,
        string $group,
        array $aliases,
        array $ports,
        string $image,
        array $environment,
        array $healthcheck,
        ?string $volume,
        bool $ownsSqlLifecycle,
        string $environmentFragment,
        ?string $command = null,
        array $properties = [],
    ): InstallationService {
        return new InstallationService(
            $name, $label, $group, $aliases, $ports, $image, $environment, $healthcheck,
            $volume, $ownsSqlLifecycle, $environmentFragment, $command, $properties,
        );
    }
}
