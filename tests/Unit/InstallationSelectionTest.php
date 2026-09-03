<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PickeringTech\Harbour\Exceptions\ErrorCode;
use PickeringTech\Harbour\Exceptions\HarbourException;
use PickeringTech\Harbour\Installation\InstallationFileRenderer;
use PickeringTech\Harbour\Installation\InstallationSelection;
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

    public function test_every_sail_service_is_accepted_by_the_compatible_with_option(): void
    {
        foreach (InstallationSelection::SAIL_SERVICES as $service) {
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

    public function test_every_base_and_optional_service_combination_renders_unique_environment_keys(): void
    {
        $renderer = new InstallationFileRenderer;
        $additional = InstallationSelection::ADDITIONAL_SERVICES;

        foreach (InstallationSelection::DATABASES as $database) {
            foreach (InstallationSelection::CACHES as $cache) {
                foreach (InstallationSelection::MAILERS as $mail) {
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
                        $environment = $renderer->environment($selection);
                        preg_match_all('/^([A-Z][A-Z0-9_]*)=/m', $environment, $matches);

                        self::assertNotEmpty($matches[1]);
                        self::assertSame($matches[1], array_values(array_unique($matches[1])));
                        self::assertStringContainsString("'provider' => 'shared'", $renderer->configuration($selection));
                    }
                }
            }
        }
    }

    public function test_rendered_configuration_records_choices_and_shared_services(): void
    {
        $renderer = new InstallationFileRenderer;
        $selection = new InstallationSelection('pgsql', 'redis', 'mailpit', ['rabbitmq', 'minio']);
        $configuration = $renderer->configuration($selection);
        $environment = $renderer->environment($selection);

        self::assertStringContainsString("'connection' => 'pgsql'", $configuration);
        self::assertStringContainsString("'rabbitmq' => [", $configuration);
        self::assertStringContainsString("'driver' => 'shared'", $configuration);
        self::assertStringContainsString('DB_DATABASE=${DB_DATABASE}', $environment);
        self::assertStringContainsString('CACHE_STORE=redis', $environment);
        self::assertStringContainsString('MAIL_HOST=127.0.0.1', $environment);
        self::assertStringContainsString('QUEUE_CONNECTION=rabbitmq', $environment);
        self::assertStringNotContainsString("QUEUE_CONNECTION=redis\n", $environment);
        self::assertStringContainsString('MINIO_BUCKET=${OBJECT_STORAGE_BUCKET}', $environment);
    }

    public function test_mongodb_is_configured_without_relational_database_lifecycle(): void
    {
        $renderer = new InstallationFileRenderer;
        $selection = new InstallationSelection('mongodb', 'none', 'none');

        self::assertStringContainsString("'enabled' => false", $renderer->configuration($selection));
        self::assertStringContainsString('MONGODB_DATABASE=${MONGODB_DATABASE}', $renderer->environment($selection));
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
        $reflection->invoke($renderer, 'uncatalogued');
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
