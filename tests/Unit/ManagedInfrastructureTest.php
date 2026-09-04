<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PickeringTech\Harbour\Contracts\CommandRunner;
use PickeringTech\Harbour\Docker\ComposeManager;
use PickeringTech\Harbour\Docker\DockerManager;
use PickeringTech\Harbour\Exceptions\ErrorCode;
use PickeringTech\Harbour\Exceptions\HarbourException;
use PickeringTech\Harbour\Identity\ContextIdentifier;
use PickeringTech\Harbour\Identity\WorkspaceIdentity;
use PickeringTech\Harbour\Process\ProcessResult;
use PickeringTech\Harbour\State\OwnedResource;

final class ManagedInfrastructureTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/harbour-docker-unit-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0700, true);
        file_put_contents($this->directory.'/compose.yml', "services: {}\n");
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->directory);
    }

    public function test_docker_validates_collection_configuration_and_process_failures(): void
    {
        $manager = new DockerManager(new ScenarioCommandRunner, new ContextIdentifier);
        $resource = $manager->prepare($this->identity(), 'search');

        $this->assertHarbourCode(ErrorCode::InvalidConfiguration, fn () => $manager->create($resource, $this->directory, ['image' => 'image', 'ports' => 'bad'], []));
        $this->assertHarbourCode(ErrorCode::InvalidConfiguration, fn () => $manager->create($resource, $this->directory, ['image' => 'image', 'environment' => 'bad'], []));

        $failing = new DockerManager(new ScenarioCommandRunner([new ProcessResult(9, '', 'failed')]), new ContextIdentifier);
        $exception = $this->assertHarbourCode(ErrorCode::ProcessFailed, fn () => $failing->create($resource, $this->directory, ['image' => 'image'], []));
        self::assertSame('failed', $exception->context['stderr']);
    }

    public function test_docker_start_destroy_and_ownership_failures_are_explicit(): void
    {
        $resource = new OwnedResource('docker_'.str_repeat('a', 32), 'ws_test', 'docker_container', 'docker', [
            'service' => 'search',
            'container_id' => 'container-id',
        ]);
        $labels = json_encode([
            DockerManager::MANAGED_LABEL => 'true',
            DockerManager::WORKSPACE_LABEL => 'ws_test',
            DockerManager::RESOURCE_LABEL => $resource->id,
        ], JSON_THROW_ON_ERROR);

        $start = new DockerManager(new ScenarioCommandRunner([
            new ProcessResult(0, $labels),
            new ProcessResult(4, '', 'start failed'),
        ]), new ContextIdentifier);
        $this->assertHarbourCode(ErrorCode::ProcessFailed, fn () => $start->start($resource, $this->directory));

        $missing = new DockerManager(new ScenarioCommandRunner([new ProcessResult(1, '')]), new ContextIdentifier);
        $missing->destroy($resource, $this->directory);
        self::addToAssertionCount(1);

        $destroy = new DockerManager(new ScenarioCommandRunner([
            new ProcessResult(0, '{}'),
            new ProcessResult(0, $labels),
            new ProcessResult(8, '', 'remove failed'),
        ]), new ContextIdentifier);
        $this->assertHarbourCode(ErrorCode::ProcessFailed, fn () => $destroy->destroy($resource, $this->directory));

        $unowned = new OwnedResource('docker_'.str_repeat('b', 32), 'ws_test', 'database', 'docker', ['container_id' => 'container-id']);
        $this->assertHarbourCode(ErrorCode::DockerResourceNotOwned, fn () => $missing->assertOwned($unowned, $this->directory));

        $badLabels = new DockerManager(new ScenarioCommandRunner([new ProcessResult(0, '{}')]), new ContextIdentifier);
        $this->assertHarbourCode(ErrorCode::DockerResourceNotOwned, fn () => $badLabels->assertOwned($resource, $this->directory));

        $unsafe = new OwnedResource('docker_'.str_repeat('c', 32), 'ws_test', 'docker_container', 'docker', ['container_id' => "bad\nvalue"]);
        $this->assertHarbourCode(ErrorCode::DockerResourceNotOwned, fn () => $missing->start($unsafe, $this->directory));
    }

    public function test_compose_validates_sources_evidence_and_command_failures(): void
    {
        $manager = new ComposeManager(new ScenarioCommandRunner, new ContextIdentifier);
        $this->assertHarbourCode(ErrorCode::InvalidConfiguration, fn () => $manager->prepare($this->identity(), $this->directory, 'stack', []));
        $this->assertHarbourCode(ErrorCode::UnsafeOperation, fn () => $manager->prepare($this->identity(), $this->directory, 'stack', ['file' => dirname($this->directory).'/outside.yml']));

        $resource = $manager->prepare($this->identity(), $this->directory, 'stack', ['file' => 'compose.yml']);
        $snapshot = $resource->metadata['file'] ?? null;
        if (! is_string($snapshot)) {
            self::fail('Compose preparation did not persist a snapshot path.');
        }
        self::assertFileExists($snapshot);

        $startRunner = new ScenarioCommandRunner([new ProcessResult(7, '', 'up failed password=compose-secret')]);
        $start = new ComposeManager($startRunner, new ContextIdentifier);
        $exception = $this->assertHarbourCode(ErrorCode::ComposeStartFailed, fn () => $start->start($resource, $this->directory, [
            'APP_PORT' => '8000',
            'DB_PASSWORD' => 'compose-secret',
        ]));
        self::assertSame('up failed password=[REDACTED]', $exception->context['stderr']);
        self::assertSame(
            ['up', '--detach', '--wait', '--wait-timeout', '60'],
            array_slice($startRunner->commands[0] ?? [], -5),
        );

        $inspect = new ComposeManager(new ScenarioCommandRunner([new ProcessResult(2, '')]), new ContextIdentifier);
        $this->assertHarbourCode(ErrorCode::ProcessFailed, fn () => $inspect->destroy($resource, $this->directory));

        $labelMismatch = new ComposeManager(new ScenarioCommandRunner([
            new ProcessResult(0, "container-id\n"),
            new ProcessResult(0, 'another-project'),
        ]), new ContextIdentifier);
        $this->assertHarbourCode(ErrorCode::DockerResourceNotOwned, fn () => $labelMismatch->destroy($resource, $this->directory));

        $down = new ComposeManager(new ScenarioCommandRunner([
            new ProcessResult(0, ''),
            new ProcessResult(5, '', 'down failed'),
        ]), new ContextIdentifier);
        $this->assertHarbourCode(ErrorCode::ProcessFailed, fn () => $down->destroy($resource, $this->directory));

        $successfulRunner = new ScenarioCommandRunner([
            new ProcessResult(0, ''),
            new ProcessResult(0, ''),
        ]);
        $successful = new ComposeManager($successfulRunner, new ContextIdentifier);
        $successful->destroy($resource, $this->directory, ['APP_PORT' => '8123']);
        self::assertFileDoesNotExist($snapshot);
        self::assertSame(['APP_PORT' => '8123'], $successfulRunner->environments[0] ?? null);
        self::assertSame(['APP_PORT' => '8123'], $successfulRunner->environments[1] ?? null);

        $invalid = new OwnedResource('compose_'.str_repeat('d', 32), 'ws_test', 'compose_project', 'compose', []);
        $this->assertHarbourCode(ErrorCode::DockerResourceNotOwned, fn () => $manager->start($invalid, $this->directory, []));
    }

    /** @param callable(): mixed $operation */
    private function assertHarbourCode(ErrorCode $code, callable $operation): HarbourException
    {
        try {
            $operation();
            self::fail("Expected {$code->value}.");
        } catch (HarbourException $exception) {
            self::assertSame($code, $exception->errorCode);

            return $exception;
        }
    }

    private function identity(): WorkspaceIdentity
    {
        return new WorkspaceIdentity('ws_test', 'test-a1b2c3d4', str_repeat('a', 64), 'main');
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path) || is_link($path)) {
            @unlink($path);

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

final class ScenarioCommandRunner implements CommandRunner
{
    /** @var list<list<string>> */
    public array $commands = [];

    /** @var list<array<string, string>> */
    public array $environments = [];

    /** @param list<ProcessResult> $results */
    public function __construct(private array $results = []) {}

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $environment
     */
    public function run(array $command, string $workingDirectory, array $environment = [], ?callable $output = null): ProcessResult
    {
        $this->commands[] = $command;
        $this->environments[] = $environment;

        return array_shift($this->results) ?? new ProcessResult(0, 'ok');
    }
}
