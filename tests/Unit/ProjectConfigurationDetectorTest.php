<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PickeringTech\Harbour\Exceptions\ErrorCode;
use PickeringTech\Harbour\Exceptions\HarbourException;
use PickeringTech\Harbour\Installation\InstallationFileRenderer;
use PickeringTech\Harbour\Installation\ProjectConfigurationDetector;

final class ProjectConfigurationDetectorTest extends TestCase
{
    public function test_it_preserves_an_explicit_redis_client_choice(): void
    {
        file_put_contents($this->workspace.'/.env', "CACHE_STORE=redis\nREDIS_CLIENT=predis\n");

        $discovery = (new ProjectConfigurationDetector($this->workspace))->discover();

        self::assertSame('redis', $discovery->selection->cache);
        self::assertSame('predis', $discovery->selection->redisClient);
    }

    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir().'/harbour-discovery-'.bin2hex(random_bytes(6));
        mkdir($this->workspace, 0700, true);
        file_put_contents($this->workspace.'/composer.json', "{\n    \"name\": \"acme/app\"\n}\n");
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->workspace);
    }

    public function test_it_proposes_a_zero_dependency_stack_when_nothing_is_configured(): void
    {
        $discovery = $this->detector()->discover();

        self::assertFalse($discovery->detected);
        self::assertSame([], $discovery->sources);
        self::assertSame('sqlite', $discovery->selection->database);
        self::assertSame('file', $discovery->selection->cache);
        self::assertSame('log', $discovery->selection->mail);
        self::assertSame([], $discovery->selection->services());
    }

    public function test_it_detects_sail_services_and_forwarded_host_ports(): void
    {
        file_put_contents($this->workspace.'/composer.json', <<<'JSON'
        {
            "name": "acme/app",
            "require-dev": {"laravel/sail": "^1.0"}
        }
        JSON);
        file_put_contents($this->workspace.'/compose.yaml', <<<'YAML'
        services:
          laravel.test:
            image: sail-8.4/app
          pgsql:
            image: postgres:17
          redis:
            image: redis:alpine
          mailpit:
            image: axllent/mailpit
          minio:
            image: minio/minio
        YAML);
        file_put_contents($this->workspace.'/.env', <<<'ENV'
        APP_NAME=Acme
        DB_CONNECTION=pgsql
        DB_HOST=pgsql
        DB_PORT=5432
        DB_USERNAME=sail
        DB_PASSWORD=secret-value
        CACHE_STORE=redis
        REDIS_HOST=redis
        REDIS_PORT=6379
        MAIL_MAILER=smtp
        MAIL_HOST=mailpit
        MAIL_PORT=1025
        FORWARD_DB_PORT=15432
        FORWARD_REDIS_PORT=16379
        FORWARD_MAILPIT_PORT=11025
        FORWARD_MAILPIT_DASHBOARD_PORT=18025
        FORWARD_MINIO_PORT=19000
        MINIO_ENDPOINT=http://minio:9000
        MINIO_ACCESS_KEY_ID=sail
        MINIO_SECRET_ACCESS_KEY=secret-value
        ENV);

        $discovery = $this->detector()->discover();
        $environment = (new InstallationFileRenderer)->environment($discovery);

        self::assertTrue($discovery->detected);
        self::assertSame(['sail:compose.yaml', 'environment:.env'], $discovery->sources);
        self::assertSame(['pgsql', 'redis', 'mailpit', 'minio'], $discovery->selection->services());
        self::assertSame(15432, $discovery->port('pgsql', 1));
        self::assertSame(16379, $discovery->port('redis', 1));
        self::assertSame(11025, $discovery->port('mailpit', 1));
        self::assertSame(18025, $discovery->port('mailpit-dashboard', 1));
        self::assertSame(19000, $discovery->port('minio', 1));
        self::assertStringContainsString('DB_HOST=127.0.0.1', $environment);
        self::assertStringContainsString('DB_PORT=15432', $environment);
        self::assertStringContainsString('DB_PASSWORD=${DB_PASSWORD}', $environment);
        self::assertStringContainsString('MAILPIT_URL=http://127.0.0.1:18025', $environment);
        self::assertStringContainsString('MINIO_ENDPOINT=http://127.0.0.1:19000', $environment);
        self::assertStringContainsString('MINIO_SECRET_ACCESS_KEY=${MINIO_SECRET_ACCESS_KEY}', $environment);
        self::assertSame(['detected' => true, 'sources' => ['sail:compose.yaml', 'environment:.env']], $discovery->metadata());
        self::assertStringNotContainsString('secret-value', json_encode($discovery->metadata(), JSON_THROW_ON_ERROR));
    }

    public function test_it_detects_herd_services_and_resolves_declared_ports(): void
    {
        file_put_contents($this->workspace.'/herd.yml', <<<'YAML'
        services:
          mysql:
            version: 8.4
            port: '${DB_PORT}'
          redis:
            version: 7.4
            port: '${REDIS_PORT}'
          meilisearch:
            version: 1.15
            port: 17700
          postgresql:
            version: 17
            port: 15433
        YAML);
        file_put_contents($this->workspace.'/.env', <<<'ENV'
        DB_CONNECTION=mysql
        DB_PORT=13306
        CACHE_STORE=redis
        REDIS_PORT=16380
        MEILISEARCH_HOST=http://127.0.0.1:17700
        MAIL_MAILER=log
        ENV);

        $discovery = $this->detector()->discover();

        self::assertSame(['herd:herd.yml', 'environment:.env'], $discovery->sources);
        self::assertSame(['mysql', 'redis', 'meilisearch'], $discovery->selection->services());
        self::assertSame(13306, $discovery->port('mysql', 1));
        self::assertSame(16380, $discovery->port('redis', 1));
        self::assertSame(17700, $discovery->port('meilisearch', 1));
        self::assertSame(15433, $discovery->port('pgsql', 1));
    }

    public function test_it_preserves_an_existing_external_service_host_through_placeholders(): void
    {
        file_put_contents($this->workspace.'/.env.example', <<<'ENV'
        APP_NAME=Acme
        DB_CONNECTION=postgresql
        DB_HOST=db.dev.example
        DB_PORT=25432
        DB_USERNAME=developer
        DB_PASSWORD=change-me
        CACHE_STORE=redis
        REDIS_HOST=cache.dev.example
        REDIS_PORT=26379
        MAIL_MAILER=log
        ENV);

        $discovery = $this->detector()->discover();
        $environment = (new InstallationFileRenderer)->environment($discovery);

        self::assertSame(['environment:.env.example'], $discovery->sources);
        self::assertSame('pgsql', $discovery->selection->database);
        self::assertStringContainsString('APP_NAME=${APP_NAME}', $environment);
        self::assertStringContainsString('DB_HOST=${DB_HOST}', $environment);
        self::assertStringContainsString('DB_PORT=25432', $environment);
        self::assertStringContainsString('REDIS_HOST=${REDIS_HOST}', $environment);
        self::assertStringContainsString('REDIS_PORT=26379', $environment);
    }

    public function test_env_overrides_example_and_invalid_or_unresolved_ports_are_ignored(): void
    {
        file_put_contents($this->workspace.'/.env.example', "DB_CONNECTION=mysql\nDB_PORT=3306\nCACHE_STORE=file\nMAIL_MAILER=log\n");
        file_put_contents($this->workspace.'/.env', "DB_CONNECTION=pgsql\nDB_PORT=70000\nCACHE_STORE=redis\nREDIS_PORT=\${REDIS_FORWARD_PORT}\nMAIL_MAILER=array\n");

        $discovery = $this->detector()->discover();

        self::assertSame('pgsql', $discovery->selection->database);
        self::assertSame('redis', $discovery->selection->cache);
        self::assertSame('none', $discovery->selection->mail);
        self::assertSame(5432, $discovery->port('pgsql', 5432));
        self::assertSame(6379, $discovery->port('redis', 6379));
    }

    public function test_standard_laravel_connection_selectors_do_not_imply_optional_services(): void
    {
        file_put_contents($this->workspace.'/.env.example', <<<'ENV'
        DB_CONNECTION=sqlite
        CACHE_STORE=database
        SESSION_DRIVER=database
        QUEUE_CONNECTION=database
        MAIL_MAILER=log
        BROADCAST_CONNECTION=log
        ENV);

        $discovery = $this->detector()->discover();

        self::assertTrue($discovery->detected);
        self::assertSame('sqlite', $discovery->selection->database);
        self::assertSame('database', $discovery->selection->cache);
        self::assertSame('log', $discovery->selection->mail);
        self::assertSame([], $discovery->selection->additionalServices);
        self::assertNotContains('rabbitmq', $discovery->selection->services());
        self::assertNotContains('soketi', $discovery->selection->services());
    }

    public function test_it_skips_irrelevant_compose_files_and_marks_a_generic_compose_source(): void
    {
        file_put_contents($this->workspace.'/compose.yaml', "services:\n  web:\n    image: nginx\n");
        file_put_contents($this->workspace.'/docker-compose.yml', <<<'YAML'
        # project dependencies
        services:
          "mariadb":
            image: mariadb:11
            environment:
              redis: not-a-service
          'memcached':
            image: memcached:alpine
          rabbitmq:
            image: rabbitmq:alpine
        networks:
          redis:
        YAML);

        $discovery = $this->detector()->discover();

        self::assertSame(['compose:docker-compose.yml'], $discovery->sources);
        self::assertSame('mariadb', $discovery->selection->database);
        self::assertSame('memcached', $discovery->selection->cache);
        self::assertSame(['mariadb', 'memcached', 'rabbitmq'], $discovery->selection->services());
    }

    public function test_laravel_test_service_identifies_sail_without_composer_metadata(): void
    {
        file_put_contents($this->workspace.'/compose.yml', "services:\n  laravel.test:\n    image: sail/app\n  redis:\n    image: redis\n");

        $discovery = $this->detector()->discover();

        self::assertSame(['sail:compose.yml'], $discovery->sources);
        self::assertSame('redis', $discovery->selection->cache);
        self::assertSame(6379, $discovery->port('redis', 1));
    }

    public function test_regular_sail_requirement_and_invalid_composer_json_are_handled_safely(): void
    {
        file_put_contents($this->workspace.'/docker-compose.yaml', "services:\n  redis:\n    image: redis\n");
        file_put_contents($this->workspace.'/composer.json', '{invalid');
        self::assertSame(['compose:docker-compose.yaml'], $this->detector()->discover()->sources);

        file_put_contents($this->workspace.'/composer.json', '{"require":{"laravel/sail":"^1.0"}}');
        self::assertSame(['sail:docker-compose.yaml'], $this->detector()->discover()->sources);
    }

    public function test_all_supported_environment_service_signals_and_ports_are_bounded(): void
    {
        file_put_contents($this->workspace.'/.env', <<<'ENV'
        DB_CONNECTION=mongodb
        MONGODB_URI=mongodb://127.0.0.1:27018/app
        CACHE_STORE=valkey
        REDIS_PORT=16379
        SESSION_DRIVER=memcached
        MEMCACHED_PORT=11212
        QUEUE_CONNECTION=redis
        MAIL_MAILER=smtp
        MAIL_HOST=mailpit.local
        MAIL_PORT=11025
        MAILPIT_URL=http://127.0.0.1:18025
        MEILISEARCH_HOST=http://127.0.0.1:17700
        TYPESENSE_HOST=127.0.0.1
        TYPESENSE_PORT=18108
        MINIO_ENDPOINT=http://127.0.0.1:19000
        RUSTFS_ENDPOINT=http://127.0.0.1:19001
        RABBITMQ_HOST=127.0.0.1
        RABBITMQ_PORT=15672
        DUSK_DRIVER_URL=http://127.0.0.1:14444/wd/hub
        PUSHER_HOST=127.0.0.1
        PUSHER_PORT=16001
        ENV);

        $discovery = $this->detector()->discover();

        self::assertSame('mongodb', $discovery->selection->database);
        self::assertSame('valkey', $discovery->selection->cache);
        self::assertSame('mailpit', $discovery->selection->mail);
        self::assertSame(
            ['mongodb', 'valkey', 'mailpit', 'meilisearch', 'typesense', 'minio', 'rustfs', 'rabbitmq', 'selenium', 'soketi'],
            $discovery->selection->services(),
        );
        foreach ([
            'mongodb' => 27018,
            'valkey' => 16379,
            'memcached' => 11212,
            'redis' => 16379,
            'mailpit' => 11025,
            'mailpit-dashboard' => 18025,
            'meilisearch' => 17700,
            'typesense' => 18108,
            'minio' => 19000,
            'rustfs' => 19001,
            'rabbitmq' => 15672,
            'selenium' => 14444,
            'soketi' => 16001,
        ] as $service => $port) {
            self::assertSame($port, $discovery->port($service, 1));
        }
    }

    public function test_compose_discovery_covers_sail_aliases_and_every_optional_host_port(): void
    {
        unlink($this->workspace.'/composer.json');
        file_put_contents($this->workspace.'/compose.yaml', <<<'YAML'
        services:
          - ignored-list-entry
          mongo:
            image: mongo:8
          memcache:
            image: memcached:alpine
          meilisearch:
            image: getmeili/meilisearch:latest
          typesense:
            image: typesense/typesense:latest
          rustfs:
            image: rustfs/rustfs:latest
          selenium:
            image: selenium/standalone-chromium:latest
          soketi:
            image: quay.io/soketi/soketi:latest
        YAML);

        $discovery = $this->detector()->discover();

        self::assertSame(['compose:compose.yaml'], $discovery->sources);
        self::assertSame('mongodb', $discovery->selection->database);
        self::assertSame('memcached', $discovery->selection->cache);
        self::assertSame(
            ['mongodb', 'memcached', 'meilisearch', 'typesense', 'rustfs', 'selenium', 'soketi'],
            $discovery->selection->services(),
        );
        foreach ([
            'mongodb' => 27017,
            'memcached' => 11211,
            'meilisearch' => 7700,
            'typesense' => 8108,
            'rustfs' => 9000,
            'selenium' => 4444,
            'soketi' => 6001,
        ] as $service => $port) {
            self::assertSame($port, $discovery->port($service, 1));
        }
    }

    public function test_herd_aliases_and_native_laravel_choices_are_detected_without_external_services(): void
    {
        file_put_contents($this->workspace.'/herd.yml', <<<'YAML'
        services:
          mongo:
            version: 8
            port: 27019
          memcache:
            version: 1.6
            port: 11213
          mailpit:
            version: latest
        YAML);
        file_put_contents($this->workspace.'/.env', "DB_CONNECTION=sqlite\nCACHE_STORE=array\nMAIL_MAILER=smtp\n");

        $discovery = $this->detector()->discover();

        self::assertSame('sqlite', $discovery->selection->database);
        self::assertSame('none', $discovery->selection->cache);
        self::assertSame('mailpit', $discovery->selection->mail);
        self::assertSame(27019, $discovery->port('mongodb', 1));
        self::assertSame(11213, $discovery->port('memcached', 1));
        self::assertSame(1025, $discovery->port('mailpit', 1));
    }

    public function test_symlinked_configuration_files_are_rejected(): void
    {
        $outside = sys_get_temp_dir().'/harbour-discovery-outside-'.bin2hex(random_bytes(6));
        file_put_contents($outside, "services:\n  redis:\n    image: redis\n");
        symlink($outside, $this->workspace.'/compose.yaml');
        symlink($outside, $this->workspace.'/.env');

        try {
            $this->detector()->discover();
            self::fail('Expected symlinked discovery input to be rejected.');
        } catch (HarbourException $exception) {
            self::assertSame(ErrorCode::UnsafeOperation, $exception->errorCode);
            self::assertStringContainsString('symbolic link', $exception->getMessage());
        } finally {
            unlink($outside);
        }
    }

    private function detector(): ProjectConfigurationDetector
    {
        return new ProjectConfigurationDetector($this->workspace);
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path.'/'.$entry;
            is_dir($child) && ! is_link($child) ? $this->removeDirectory($child) : @unlink($child);
        }
        @rmdir($path);
    }
}
