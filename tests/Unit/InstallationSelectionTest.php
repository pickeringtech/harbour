<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PickeringTech\Harbour\Exceptions\ErrorCode;
use PickeringTech\Harbour\Exceptions\HarbourException;
use PickeringTech\Harbour\Installation\InstallationDiscovery;
use PickeringTech\Harbour\Installation\InstallationFileRenderer;
use PickeringTech\Harbour\Installation\InstallationSelection;
use PickeringTech\Harbour\Installation\InstallationServiceCatalog;
use ReflectionMethod;

final class InstallationSelectionTest extends TestCase
{
    public function test_sail_compatible_services_and_group_options_are_normalized(): void
    {
        $selection = InstallationSelection::fromOptions(
            'postgresql',
            'REDIS',
            'mailpit',
            'pgsql,redis,mailpit,meilisearch,minio,soketi',
        );

        self::assertSame('pgsql', $selection->database);
        self::assertSame('redis', $selection->cache);
        self::assertSame('mailpit', $selection->mail);
        self::assertSame(
            ['pgsql', 'redis', 'mailpit', 'meilisearch', 'minio', 'soketi'],
            $selection->services(),
        );
        self::assertSame('shared', $selection->toArray()['provider']);
    }

    public function test_with_none_and_native_choices_require_no_shared_services(): void
    {
        $selection = InstallationSelection::fromOptions('sqlite', 'file', 'log', 'none');

        self::assertSame([], $selection->services());
    }

    public function test_compose_provider_is_recorded_for_service_backed_components(): void
    {
        $selection = InstallationSelection::fromOptions('pgsql', 'redis', 'mailpit', 'meilisearch,minio', 'compose');

        self::assertSame('compose', $selection->provider);
        self::assertSame('compose', $selection->toArray()['provider']);
        self::assertSame(['pgsql', 'redis', 'mailpit', 'meilisearch', 'minio'], $selection->services());
    }

    public function test_compose_provider_requires_at_least_one_service_backed_component(): void
    {
        $this->expectException(HarbourException::class);
        $this->expectExceptionMessage('requires at least one service-backed component');

        InstallationSelection::fromOptions('sqlite', 'file', 'log', 'none', 'compose');
    }

    public function test_every_sail_service_is_accepted_by_the_compatible_with_option(): void
    {
        foreach ((new InstallationServiceCatalog)->names() as $service) {
            $selection = InstallationSelection::fromOptions(null, null, null, $service);

            self::assertContains($service, $selection->services());
        }
    }

    #[DataProvider('invalidSelections')]
    public function test_invalid_and_conflicting_selections_are_rejected(
        ?string $database,
        ?string $cache,
        ?string $mail,
        ?string $with,
        string $message,
    ): void {
        try {
            InstallationSelection::fromOptions($database, $cache, $mail, $with);
            self::fail('Expected the installation selection to be rejected.');
        } catch (HarbourException $exception) {
            self::assertSame(ErrorCode::InvalidInstallSelection, $exception->errorCode);
            self::assertStringContainsString($message, $exception->getMessage());
        }
    }

    /** @return iterable<string, array{?string, ?string, ?string, ?string, string}> */
    public static function invalidSelections(): iterable
    {
        yield 'unknown database' => ['oracle', null, null, null, 'Unsupported database'];
        yield 'unknown cache' => [null, 'dynamodb', null, null, 'Unsupported cache'];
        yield 'unknown mail' => [null, null, 'smtp', null, 'Unsupported mail'];
        yield 'unknown service' => [null, null, null, 'redis,kafka', 'Unsupported service'];
        yield 'empty with' => [null, null, null, '', 'requires a comma-separated'];
        yield 'none mixed with service' => [null, null, null, 'none,redis', 'cannot be combined'];
        yield 'two databases' => [null, null, null, 'mysql,pgsql', 'only one database'];
        yield 'two cache stores' => [null, null, null, 'redis,valkey', 'only one cache service'];
        yield 'database flag conflict' => ['sqlite', null, null, 'mysql', 'Conflicting database'];
        yield 'cache flag conflict' => [null, 'file', null, 'redis', 'Conflicting cache'];
        yield 'mail flag conflict' => [null, null, 'log', 'mailpit', 'Conflicting mail'];
        yield 'empty item' => [null, null, null, 'redis,', 'cannot be combined'];
    }

    public function test_unknown_provider_is_rejected(): void
    {
        $this->expectException(HarbourException::class);
        $this->expectExceptionMessage('Unsupported infrastructure provider');

        InstallationSelection::fromOptions('pgsql', 'none', 'none', 'none', 'kubernetes');
    }

    public function test_unknown_redis_client_is_rejected(): void
    {
        $this->expectException(HarbourException::class);
        $this->expectExceptionMessage('Unsupported Redis client');

        InstallationSelection::fromOptions('none', 'redis', 'log', 'none', 'shared', 'hiredis');
    }

    public function test_every_base_and_optional_service_combination_renders_unique_environment_keys(): void
    {
        $renderer = new InstallationFileRenderer;
        $additional = InstallationSelection::additionalServices();

        foreach (InstallationSelection::databases() as $database) {
            foreach (InstallationSelection::caches() as $cache) {
                foreach (InstallationSelection::mailers() as $mail) {
                    for ($mask = 0; $mask < (1 << count($additional)); $mask++) {
                        $services = [];
                        foreach ($additional as $index => $service) {
                            if (($mask & (1 << $index)) !== 0) {
                                $services[] = $service;
                            }
                        }

                        $selection = InstallationSelection::fromOptions(
                            $database,
                            $cache,
                            $mail,
                            $services === [] ? 'none' : implode(',', $services),
                        );
                        $discovery = InstallationDiscovery::explicit($selection);
                        $environment = $renderer->environment($discovery);
                        preg_match_all('/^([A-Z][A-Z0-9_]*)=/m', $environment, $matches);

                        self::assertNotEmpty($matches[1]);
                        self::assertSame($matches[1], array_values(array_unique($matches[1])));
                        self::assertStringContainsString("'provider' => 'shared'", $renderer->configuration($discovery));
                    }
                }
            }
        }
    }

    public function test_rendered_configuration_records_choices_and_shared_services(): void
    {
        $renderer = new InstallationFileRenderer;
        $selection = new InstallationSelection('pgsql', 'redis', 'mailpit', ['rabbitmq', 'minio']);
        $discovery = InstallationDiscovery::explicit($selection);
        $configuration = $renderer->configuration($discovery);
        $environment = $renderer->environment($discovery);

        self::assertStringContainsString("'connection' => 'pgsql'", $configuration);
        self::assertStringContainsString("in_array(env('APP_ENV'), ['local', 'testing'], true)", $configuration);
        self::assertStringContainsString("'rabbitmq' => [", $configuration);
        self::assertStringContainsString("'driver' => 'shared'", $configuration);
        self::assertStringContainsString('DB_DATABASE=${DB_DATABASE}', $environment);
        self::assertStringContainsString('CACHE_STORE=redis', $environment);
        self::assertStringContainsString('MAIL_HOST=127.0.0.1', $environment);
        self::assertStringContainsString('QUEUE_CONNECTION=rabbitmq', $environment);
        self::assertStringNotContainsString("QUEUE_CONNECTION=redis\n", $environment);
        self::assertStringContainsString('MINIO_BUCKET=${OBJECT_STORAGE_BUCKET}', $environment);

        self::assertSame(
            $renderer->environment($discovery),
            $renderer->environment($selection),
        );
    }

    public function test_mongodb_is_configured_without_relational_database_lifecycle(): void
    {
        $renderer = new InstallationFileRenderer;
        $selection = new InstallationSelection('mongodb', 'none', 'none');
        $discovery = InstallationDiscovery::explicit($selection);

        self::assertStringContainsString("'enabled' => false", $renderer->configuration($discovery));
        self::assertStringContainsString('Harbour never creates,', $renderer->configuration($discovery));
        self::assertStringContainsString('MONGODB_DATABASE=${MONGODB_DATABASE}', $renderer->environment($discovery));
        self::assertStringContainsString('APP_PORT=${APP_PORT}', $renderer->environment($discovery));
    }

    public function test_discovery_can_preserve_or_clear_provenance_when_a_selection_changes(): void
    {
        $initial = new InstallationDiscovery(
            new InstallationSelection('pgsql', 'redis', 'log'),
            true,
            ['environment:.env'],
            ['pgsql' => 15432],
            ['DB_HOST'],
        );
        $replacement = new InstallationSelection('sqlite', 'file', 'none');

        self::assertSame('${DB_HOST}', $initial->serviceHost('DB_HOST', 'pgsql'));
        self::assertTrue($initial->hasEnvironmentVariable('DB_HOST'));
        self::assertFalse($initial->hasEnvironmentVariable('MISSING'));
        self::assertSame('fallback', $initial->templateValue('MISSING', 'fallback'));

        $unchanged = $initial->withSelection($initial->selection);
        self::assertSame(15432, $unchanged->port('pgsql', 1));
        self::assertSame('${DB_HOST}', $unchanged->serviceHost('DB_HOST', 'pgsql'));

        $detected = $initial->withSelection($replacement);
        self::assertTrue($detected->detected);
        self::assertSame(['environment:.env'], $detected->sources);
        self::assertSame(1, $detected->port('pgsql', 1));
        self::assertSame('127.0.0.1', $detected->serviceHost('DB_HOST', 'sqlite'));

        $manual = $initial->withManualSelection($replacement);
        self::assertFalse($manual->detected);
        self::assertSame([], $manual->sources);
        self::assertSame(1, $manual->port('pgsql', 1));
    }

    public function test_switching_a_detected_selection_to_compose_discards_external_endpoints(): void
    {
        $initial = new InstallationDiscovery(
            new InstallationSelection('pgsql', 'redis', 'mailpit'),
            true,
            ['sail:compose.yaml'],
            ['pgsql' => 15432, 'redis' => 16379, 'mailpit' => 11025],
            ['DB_HOST', 'DB_PORT', 'DB_USERNAME', 'DB_PASSWORD', 'REDIS_HOST', 'REDIS_PORT', 'MAIL_HOST', 'MAIL_PORT'],
            ['pgsql', 'redis', 'mailpit'],
        );

        $compose = $initial->withSelection($initial->selection->withProvider('compose'));

        self::assertSame([], $compose->servicePorts);
        self::assertSame([], $compose->localServices);
        self::assertFalse($compose->hasEnvironmentVariable('DB_PASSWORD'));
        self::assertStringContainsString('DB_PORT=${DB_PORT}', (new InstallationFileRenderer)->environment($compose));
    }

    public function test_changing_detected_optional_services_discards_their_stale_endpoints_and_credentials(): void
    {
        $services = InstallationSelection::additionalServices();
        $variables = [
            'MEILISEARCH_HOST',
            'TYPESENSE_HOST',
            'TYPESENSE_PORT',
            'TYPESENSE_PROTOCOL',
            'TYPESENSE_API_KEY',
            'MINIO_ENDPOINT',
            'MINIO_ACCESS_KEY_ID',
            'MINIO_SECRET_ACCESS_KEY',
            'RUSTFS_ENDPOINT',
            'RUSTFS_ACCESS_KEY_ID',
            'RUSTFS_SECRET_ACCESS_KEY',
            'RABBITMQ_HOST',
            'RABBITMQ_PORT',
            'DUSK_DRIVER_URL',
            'PUSHER_APP_ID',
            'PUSHER_APP_KEY',
            'PUSHER_APP_SECRET',
            'PUSHER_HOST',
            'PUSHER_PORT',
            'PUSHER_SCHEME',
        ];
        $discovery = new InstallationDiscovery(
            new InstallationSelection('sqlite', 'file', 'log', $services),
            true,
            ['sail:compose.yaml'],
            array_fill_keys($services, 12345),
            $variables,
            $services,
        );

        $changed = $discovery->withSelection(new InstallationSelection('sqlite', 'file', 'log'));

        foreach ($services as $service) {
            self::assertSame(54321, $changed->port($service, 54321));
        }
        foreach ($variables as $variable) {
            self::assertFalse($changed->hasEnvironmentVariable($variable));
        }
        self::assertSame([], $changed->localServices);
    }

    public function test_detected_reverb_credentials_are_referenced_and_server_port_follows_allocation(): void
    {
        $discovery = new InstallationDiscovery(
            new InstallationSelection('sqlite', 'file', 'log'),
            true,
            ['environment:.env'],
            [],
            ['REVERB_APP_ID', 'REVERB_APP_KEY', 'REVERB_APP_SECRET', 'REVERB_SCHEME', 'BROADCAST_CONNECTION'],
        );
        $environment = (new InstallationFileRenderer)->environment($discovery);

        self::assertStringContainsString('REVERB_SERVER_HOST=127.0.0.1', $environment);
        self::assertStringContainsString('REVERB_SERVER_PORT=${REVERB_PORT}', $environment);
        self::assertStringContainsString('REVERB_APP_ID=${REVERB_APP_ID}', $environment);
        self::assertStringContainsString('REVERB_APP_SECRET=${REVERB_APP_SECRET}', $environment);
        self::assertStringContainsString('BROADCAST_CONNECTION=${BROADCAST_CONNECTION}', $environment);
        self::assertStringContainsString('VITE_REVERB_PORT=${REVERB_PORT}', $environment);
    }

    /** @param list<string> $additional */
    #[DataProvider('invalidDirectSelections')]
    public function test_direct_construction_rejects_values_outside_the_catalog(
        string $database,
        string $cache,
        string $mail,
        array $additional,
    ): void {
        $this->expectException(HarbourException::class);
        $this->expectExceptionMessage('Unsupported');

        new InstallationSelection($database, $cache, $mail, $additional);
    }

    /** @return iterable<string, array{string, string, string, list<string>}> */
    public static function invalidDirectSelections(): iterable
    {
        yield 'database' => ['sqlsrv', 'file', 'log', []];
        yield 'cache' => ['sqlite', 'apcu', 'log', []];
        yield 'mail' => ['sqlite', 'file', 'sendmail', []];
        yield 'additional service' => ['sqlite', 'file', 'log', ['kafka']];
    }

    #[DataProvider('rendererMethods')]
    public function test_renderer_defensively_rejects_an_impossible_uncatalogued_value(string $method): void
    {
        $renderer = new InstallationFileRenderer;
        $reflection = new ReflectionMethod($renderer, $method);

        $this->expectException(\LogicException::class);
        $reflection->invoke($renderer, 'uncatalogued', InstallationDiscovery::explicit(new InstallationSelection('none', 'none', 'none')));
    }

    /** @return iterable<string, array{string}> */
    public static function rendererMethods(): iterable
    {
        yield 'database' => ['databaseEnvironment'];
        yield 'cache' => ['cacheEnvironment'];
        yield 'mail' => ['mailEnvironment'];
        yield 'service' => ['serviceEnvironment'];
    }
}
