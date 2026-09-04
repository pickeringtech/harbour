<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PickeringTech\Harbour\Contracts\CommandRunner;
use PickeringTech\Harbour\Exceptions\ErrorCode;
use PickeringTech\Harbour\Exceptions\HarbourException;
use PickeringTech\Harbour\Installation\InstallationSelection;
use PickeringTech\Harbour\Installation\SystemInstallationPreflight;
use PickeringTech\Harbour\Process\ProcessResult;
use PickeringTech\Harbour\Tests\TestCase;

final class SystemInstallationPreflightTest extends TestCase
{
    public function test_it_aggregates_requirements_from_the_final_selected_stack(): void
    {
        $this->application()['config']->set('database.redis.client', 'phpredis');
        $preflight = new SystemInstallationPreflight(
            $this->application()['config'],
            new PreflightCommandRunner(dockerAvailable: false),
            $this->workspaceDirectory,
            [],
            [],
        );

        try {
            $preflight->assertReady(new InstallationSelection(
                'pgsql',
                'redis',
                'mailpit',
                ['meilisearch', 'soketi'],
                'compose',
            ));
            self::fail('Expected the selected stack to fail its preflight.');
        } catch (HarbourException $exception) {
            self::assertSame(ErrorCode::InstallRequirementsMissing, $exception->errorCode);
            self::assertStringContainsString('PHP extension pdo_pgsql', $exception->getMessage());
            self::assertStringContainsString('PHP extension redis', $exception->getMessage());
            self::assertStringContainsString('Composer package laravel/scout', $exception->getMessage());
            self::assertStringContainsString('Composer package meilisearch/meilisearch-php', $exception->getMessage());
            self::assertStringContainsString('Composer package pusher/pusher-php-server', $exception->getMessage());
            self::assertStringContainsString('Docker CLI', $exception->getMessage());
            self::assertStringContainsString('No project files were changed.', $exception->getMessage());
            self::assertSame([
                'extension:pdo_pgsql',
                'extension:redis',
                'package:laravel/scout',
                'package:meilisearch/meilisearch-php',
                'package:pusher/pusher-php-server',
                'executable:docker',
            ], $this->missingIds($exception));
        }
    }

    public function test_it_accepts_predis_as_the_configured_redis_client(): void
    {
        $this->application()['config']->set('database.redis.client', 'predis');
        $preflight = new SystemInstallationPreflight(
            $this->application()['config'],
            new PreflightCommandRunner,
            $this->workspaceDirectory,
            ['pdo_sqlite'],
            ['predis/predis'],
        );

        $preflight->assertReady(new InstallationSelection('sqlite', 'redis', 'log'));

        self::addToAssertionCount(1);
    }

    public function test_it_requires_compose_v2_after_finding_docker(): void
    {
        $preflight = new SystemInstallationPreflight(
            $this->application()['config'],
            new PreflightCommandRunner(composeAvailable: false),
            $this->workspaceDirectory,
            ['pdo_pgsql'],
            [],
        );

        try {
            $preflight->assertReady(new InstallationSelection('pgsql', 'file', 'log', [], 'compose'));
            self::fail('Expected Docker Compose v2 to be required.');
        } catch (HarbourException $exception) {
            self::assertSame(ErrorCode::InstallRequirementsMissing, $exception->errorCode);
            self::assertSame(['plugin:docker-compose'], $this->missingIds($exception));
            self::assertStringContainsString('docker compose version', $exception->getMessage());
        }
    }

    public function test_it_reports_shared_requirements_only_once(): void
    {
        $preflight = new SystemInstallationPreflight(
            $this->application()['config'],
            new PreflightCommandRunner,
            $this->workspaceDirectory,
            [],
            [],
        );

        try {
            $preflight->assertReady(new InstallationSelection('none', 'file', 'log', ['meilisearch', 'typesense', 'minio', 'rustfs']));
            self::fail('Expected integration clients to be required.');
        } catch (HarbourException $exception) {
            $ids = $this->missingIds($exception);
            self::assertSame(1, count(array_keys($ids, 'package:laravel/scout', true)));
            self::assertSame(1, count(array_keys($ids, 'package:league/flysystem-aws-s3-v3', true)));
        }
    }

    /**
     * @param  list<string>  $extensions
     * @param  list<string>  $packages
     */
    #[DataProvider('componentRequirements')]
    public function test_it_validates_each_supported_component(
        InstallationSelection $selection,
        array $extensions,
        array $packages,
        string $missing,
    ): void {
        $preflight = new SystemInstallationPreflight(
            $this->application()['config'],
            new PreflightCommandRunner,
            $this->workspaceDirectory,
            $extensions,
            $packages,
        );

        try {
            $preflight->assertReady($selection);
            self::fail('Expected a missing component requirement.');
        } catch (HarbourException $exception) {
            self::assertContains($missing, $this->missingIds($exception));
        }
    }

    /** @return list<string> */
    private function missingIds(HarbourException $exception): array
    {
        $requirements = $exception->context['missing'] ?? null;
        if (! is_array($requirements)) {
            self::fail('Expected structured missing requirements.');
        }

        $ids = [];
        foreach ($requirements as $requirement) {
            if (! is_array($requirement) || ! is_string($requirement['id'] ?? null)) {
                self::fail('Expected every missing requirement to have an ID.');
            }
            $ids[] = $requirement['id'];
        }

        return $ids;
    }

    /** @return iterable<string, array{InstallationSelection, list<string>, list<string>, string}> */
    public static function componentRequirements(): iterable
    {
        yield 'SQLite' => [new InstallationSelection('sqlite', 'file', 'log'), [], [], 'extension:pdo_sqlite'];
        yield 'MySQL' => [new InstallationSelection('mysql', 'file', 'log'), [], [], 'extension:pdo_mysql'];
        yield 'MariaDB' => [new InstallationSelection('mariadb', 'file', 'log'), [], [], 'extension:pdo_mysql'];
        yield 'MongoDB extension' => [new InstallationSelection('mongodb', 'file', 'log'), [], ['mongodb/laravel-mongodb'], 'extension:mongodb'];
        yield 'MongoDB Laravel driver' => [new InstallationSelection('mongodb', 'file', 'log'), ['mongodb'], [], 'package:mongodb/laravel-mongodb'];
        yield 'Memcached' => [new InstallationSelection('none', 'memcached', 'log'), [], [], 'extension:memcached'];
        yield 'Typesense' => [new InstallationSelection('none', 'file', 'log', ['typesense']), [], ['laravel/scout'], 'package:typesense/typesense-php'];
        yield 'MinIO' => [new InstallationSelection('none', 'file', 'log', ['minio']), [], [], 'package:league/flysystem-aws-s3-v3'];
        yield 'RustFS' => [new InstallationSelection('none', 'file', 'log', ['rustfs']), [], [], 'package:league/flysystem-aws-s3-v3'];
        yield 'RabbitMQ' => [new InstallationSelection('none', 'file', 'log', ['rabbitmq']), [], [], 'package:vladimir-yuldashev/laravel-queue-rabbitmq'];
        yield 'Selenium' => [new InstallationSelection('none', 'file', 'log', ['selenium']), [], [], 'package:laravel/dusk'];
    }
}

final readonly class PreflightCommandRunner implements CommandRunner
{
    public function __construct(
        private bool $dockerAvailable = true,
        private bool $composeAvailable = true,
    ) {}

    public function run(array $command, string $workingDirectory, array $environment = [], ?callable $output = null): ProcessResult
    {
        $available = $command === ['docker', '--version'] ? $this->dockerAvailable : $this->composeAvailable;

        return new ProcessResult($available ? 0 : 1, $available ? 'available' : '', $available ? '' : 'unavailable');
    }
}
