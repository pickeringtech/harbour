<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Tests\Integration;

use Illuminate\Contracts\Config\Repository;
use PickeringTech\Harbour\Exceptions\HarbourException;
use PickeringTech\Harbour\Tests\TestCase;
use PickeringTech\Harbour\WorkspaceManager;

final class FailureRecoveryTest extends TestCase
{
    public function test_teardown_recovers_allocations_and_environment_after_a_late_setup_failure(): void
    {
        $this->application()->make(Repository::class)->set('harbour.hooks.after_setup', [[PHP_BINARY, '-r', 'exit(23);']]);
        $manager = $this->application()->make(WorkspaceManager::class);

        try {
            $manager->setup();
            self::fail('Setup should have failed.');
        } catch (HarbourException $exception) {
            self::assertSame('PROCESS_FAILED', $exception->errorCode->value);
        }

        $state = $manager->current();
        self::assertNotNull($state);
        self::assertSame('failed', $state->state()->status);
        self::assertNotEmpty($state->ports());
        self::assertNotSame("ORIGINAL=yes\n", file_get_contents($this->workspaceDirectory.'/.env'));

        $manager->teardown(true);

        self::assertFileDoesNotExist($this->workspaceDirectory.'/.harbour.json');
        self::assertSame("ORIGINAL=yes\n", file_get_contents($this->workspaceDirectory.'/.env'));
    }
}
