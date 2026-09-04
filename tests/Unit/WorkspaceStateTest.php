<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PickeringTech\Harbour\Identity\WorkspaceIdentity;
use PickeringTech\Harbour\State\FileWorkspaceStateRepository;
use PickeringTech\Harbour\State\OwnedResource;
use PickeringTech\Harbour\State\WorkspaceState;
use PickeringTech\Harbour\Variables\VariableBag;
use PickeringTech\Harbour\Workspace;

final class WorkspaceStateTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/harbour-state-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->directory.'/.harbour.json');
        @rmdir($this->directory);
    }

    public function test_state_round_trips_atomically_with_owned_resources(): void
    {
        $repository = new FileWorkspaceStateRepository($this->directory.'/.harbour.json');
        $identity = new WorkspaceIdentity('ws_test', 'test-a1b2c3d4', str_repeat('a', 64), 'main');
        $state = WorkspaceState::begin($identity, $this->directory)
            ->withAllocation('APP_PORT', 8123)
            ->withResource(new OwnedResource('resource-1', 'ws_test', 'sqlite_database', 'sqlite', [
                'path' => $this->directory.'/database.sqlite',
            ]))
            ->ready();

        $repository->save($state);

        self::assertEquals($state, $repository->load());
        self::assertSame(0600, fileperms($this->directory.'/.harbour.json') & 0777);
    }

    public function test_diagnostic_resources_redact_ownership_tokens(): void
    {
        $identity = new WorkspaceIdentity('ws_test', 'test-a1b2c3d4', str_repeat('a', 64), 'main');
        $state = WorkspaceState::begin($identity, $this->directory)->withResource(
            new OwnedResource('resource-1', 'ws_test', 'database', 'sqlite', [
                'database' => 'safe.sqlite',
                'ownership_token' => 'must-not-leak',
            ]),
        );

        $diagnostic = $state->resources[0]->diagnosticArray();
        self::assertSame('[REDACTED]', $diagnostic['metadata']['ownership_token']);
        self::assertStringNotContainsString('must-not-leak', (string) json_encode((new Workspace($state, new VariableBag))->toArray()));
    }

    public function test_state_copies_preserve_failures_until_ready_or_replace_them_explicitly(): void
    {
        $identity = new WorkspaceIdentity('ws_test', 'test-a1b2c3d4', str_repeat('a', 64), 'main');
        $failed = WorkspaceState::begin($identity, $this->directory)->failed('HARBOUR_PROCESS_FAILED');

        self::assertSame('HARBOUR_PROCESS_FAILED', $failed->withAllocation('APP_PORT', 8123)->errorCode);
        self::assertSame('HARBOUR_PROCESS_FAILED', $failed->tearingDown()->errorCode);
        self::assertSame('HARBOUR_PROCESS_FAILED', $failed->preparing()->errorCode);
        self::assertNull($failed->ready()->errorCode);
        self::assertSame('HARBOUR_DATABASE_NOT_OWNED', $failed->failed('HARBOUR_DATABASE_NOT_OWNED')->errorCode);
    }
}
