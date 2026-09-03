<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use PickeringTech\Harbour\Exceptions\ErrorCode;
use PickeringTech\Harbour\Exceptions\HarbourException;
use PickeringTech\Harbour\Identity\WorkspaceIdentity;
use PickeringTech\Harbour\Ports\FilePortRegistry;
use PickeringTech\Harbour\Ports\PortRequirement;
use PickeringTech\Harbour\State\FileWorkspaceStateRepository;
use PickeringTech\Harbour\State\OwnedResource;
use PickeringTech\Harbour\State\WorkspaceState;
use PickeringTech\Harbour\Support\AtomicFile;
use PickeringTech\Harbour\Support\LifecycleLock;
use PickeringTech\Harbour\Support\WorkspacePath;

final class StateAndSupportTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/harbour-support-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->directory);
    }

    public function test_state_repository_handles_absence_round_trip_deletion_and_corruption(): void
    {
        $path = $this->directory.'/.harbour.json';
        $repository = new FileWorkspaceStateRepository($path);
        self::assertNull($repository->load());

        $state = WorkspaceState::begin($this->identity(), $this->directory)
            ->withVariables(['NAME' => 'value'])
            ->withEnvironment(['original_exists' => false]);
        $repository->save($state);
        self::assertEquals($state, $repository->load());
        $repository->delete();
        $repository->delete();
        self::assertFileDoesNotExist($path);

        file_put_contents($path, '{not json');
        $this->assertHarbourCode(ErrorCode::StateCorrupted, static fn () => $repository->load());

        unlink($path);
        mkdir($path);
        $this->assertHarbourCode(ErrorCode::UnsafeOperation, static fn () => $repository->load());
    }

    public function test_state_repository_refuses_to_encode_unserializable_metadata(): void
    {
        $resourceHandle = fopen('php://memory', 'rb');
        self::assertIsResource($resourceHandle);
        $state = WorkspaceState::begin($this->identity(), $this->directory)->withResource(
            new OwnedResource('resource', 'ws_test', 'database', 'sqlite', ['handle' => $resourceHandle]),
        );

        try {
            $this->assertHarbourCode(
                ErrorCode::StateWriteFailed,
                fn () => (new FileWorkspaceStateRepository($this->directory.'/.harbour.json'))->save($state),
            );
        } finally {
            fclose($resourceHandle);
        }
    }

    public function test_owned_resources_validate_round_trip_and_redact_sensitive_metadata(): void
    {
        $resource = OwnedResource::fromArray([
            'id' => 'resource',
            'workspace_id' => 'ws_test',
            'type' => 'database',
            'driver' => 'sqlite',
            'metadata' => ['password' => 'secret', 'safe' => 'visible'],
            'created_by_harbour' => true,
        ]);

        self::assertSame('resource', $resource->toArray()['id']);
        self::assertSame('[REDACTED]', $resource->diagnosticArray()['metadata']['password']);
        self::assertSame('visible', $resource->diagnosticArray()['metadata']['safe']);

        foreach ([
            ['', 'ws', 'type', 'driver', []],
            ['id', '', 'type', 'driver', []],
            ['id', 'ws', '', 'driver', []],
            ['id', 'ws', 'type', '', []],
        ] as $arguments) {
            try {
                new OwnedResource(...$arguments);
                self::fail('Empty ownership evidence must be rejected.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function test_workspace_state_rejects_malformed_persisted_shapes(): void
    {
        $valid = WorkspaceState::begin($this->identity(), $this->directory)->toArray();
        $invalidStates = [
            [...$valid, 'version' => 99],
            [...$valid, 'allocations' => 'bad'],
            [...$valid, 'allocations' => ['APP_PORT' => '8000']],
            [...$valid, 'variables' => 'bad'],
            [...$valid, 'variables' => ['NAME' => 123]],
            [...$valid, 'environment' => 'bad'],
            [...$valid, 'environment' => ['bad' => []]],
            [...$valid, 'resources' => ['not' => 'a list']],
            [...$valid, 'resources' => [['id' => 1]]],
            [...$valid, 'resources' => [[
                'id' => 'resource', 'workspace_id' => 'ws_test', 'type' => 'database', 'driver' => 'sqlite',
                'metadata' => [0 => 'bad-key'],
            ]]],
        ];

        foreach ($invalidStates as $data) {
            try {
                WorkspaceState::fromArray($data);
                self::fail('Malformed state must be rejected.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }

        $this->expectException(InvalidArgumentException::class);
        new WorkspaceState(1, 'unknown', $this->identity(), $this->directory);
    }

    public function test_workspace_state_rejects_resources_owned_by_another_workspace(): void
    {
        $state = WorkspaceState::begin($this->identity(), $this->directory);

        $this->expectException(InvalidArgumentException::class);
        $state->withResource(new OwnedResource('resource', 'ws_other', 'database', 'sqlite', []));
    }

    public function test_atomic_file_and_path_guards_cover_success_and_failure_paths(): void
    {
        $atomic = new AtomicFile;
        $path = $this->directory.'/nested/value.txt';
        $atomic->write($path, 'value', 0640);
        self::assertSame('value', file_get_contents($path));
        self::assertSame(0640, fileperms($path) & 0777);

        WorkspacePath::assertSafe($this->directory, $this->directory);
        WorkspacePath::assertSafe($this->directory, $path);
        $this->assertHarbourCode(ErrorCode::UnsafeOperation, fn () => WorkspacePath::assertSafe($this->directory, $this->directory.'/nested/../escape'));
        $this->assertHarbourCode(ErrorCode::UnsafeOperation, fn () => WorkspacePath::assertSafe($this->directory, dirname($this->directory).'/outside'));

        mkdir($this->directory.'/target-directory');
        $this->assertHarbourCode(ErrorCode::StateWriteFailed, fn () => $atomic->write($this->directory.'/target-directory', 'cannot replace directory'));
    }

    public function test_lifecycle_lock_returns_values_and_releases_after_exceptions(): void
    {
        $lock = new LifecycleLock($this->directory.'/.harbour/locks/lifecycle.lock');
        self::assertSame('result', $lock->synchronized(static fn (): string => 'result'));

        try {
            $lock->synchronized(static function (): void {
                throw new InvalidArgumentException('operation failed');
            });
        } catch (InvalidArgumentException $exception) {
            self::assertSame('operation failed', $exception->getMessage());
        }

        self::assertSame(42, $lock->synchronized(static fn (): int => 42));
    }

    public function test_port_requirements_and_registry_failure_reconciliation_are_guarded(): void
    {
        foreach ([
            ['bad-name', 8000, 8001, '127.0.0.1'],
            ['APP_PORT', 1000, 8001, '127.0.0.1'],
            ['APP_PORT', 8000, 7000, '127.0.0.1'],
            ['APP_PORT', 8000, 8001, '0.0.0.0'],
        ] as $arguments) {
            $this->assertHarbourCode(ErrorCode::InvalidConfiguration, static fn () => new PortRequirement(...$arguments));
        }

        $registryDirectory = $this->directory.'/registry';
        $workspace = $this->directory.'/workspace';
        mkdir($workspace);
        $registry = new FilePortRegistry($registryDirectory);
        $errorNumber = 0;
        $errorMessage = '';
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);
        self::assertIsResource($socket, $errorMessage ?? 'Unable to bind a temporary socket.');
        $address = stream_socket_get_name($socket, false);
        if (! is_string($address)) {
            self::fail('Unable to determine the temporary socket address.');
        }
        $separator = strrchr($address, ':');
        if ($separator === false) {
            self::fail('Temporary socket address does not contain a port.');
        }
        $port = (int) substr($separator, 1);

        try {
            $this->assertHarbourCode(
                ErrorCode::PortAllocationFailed,
                fn () => $registry->reserve('ws_test', $workspace, new PortRequirement('APP_PORT', $port, $port)),
            );
        } finally {
            fclose($socket);
        }

        $allocation = $registry->reserve('ws_test', $workspace, new PortRequirement('APP_PORT', $port, $port));
        self::assertSame($port, $allocation->port);
        rmdir($workspace);
        self::assertSame(1, $registry->reconcileDeletedWorkspaces());
        self::assertSame(0, $registry->reconcileDeletedWorkspaces());

        file_put_contents($registryDirectory.'/ports.json', '{broken');
        $this->assertHarbourCode(ErrorCode::StateCorrupted, fn () => $registry->releaseWorkspace('ws_test'));

        file_put_contents($registryDirectory.'/ports.json', '{"version":99,"reservations":[]}');
        $this->assertHarbourCode(ErrorCode::StateCorrupted, fn () => $registry->releaseWorkspace('ws_test'));
    }

    /** @param callable(): mixed $operation */
    private function assertHarbourCode(ErrorCode $code, callable $operation): void
    {
        try {
            $operation();
            self::fail("Expected {$code->value}.");
        } catch (HarbourException $exception) {
            self::assertSame($code, $exception->errorCode);
            self::assertSame($code->value, $exception->toArray()['code']);
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
