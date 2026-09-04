<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Tests\Integration;

use Illuminate\Contracts\Config\Repository;
use PickeringTech\Harbour\Contracts\CommandRunner;
use PickeringTech\Harbour\Exceptions\HarbourException;
use PickeringTech\Harbour\Process\ProcessResult;
use PickeringTech\Harbour\State\ResourceType;
use PickeringTech\Harbour\Tests\TestCase;
use PickeringTech\Harbour\WorkspaceManager;

final class IncrementalOwnershipTest extends TestCase
{
    public function test_failure_marks_the_latest_incrementally_persisted_resource_state(): void
    {
        file_put_contents($this->workspaceDirectory.'/compose.yml', "services:\n  no-op:\n    image: alpine:3.22\n");
        $config = $this->application()->make(Repository::class);
        $config->set('harbour.compose', ['stack' => ['file' => 'compose.yml']]);
        $runner = new FailingThenSuccessfulRunner;
        $this->application()->instance(CommandRunner::class, $runner);
        $manager = $this->application()->make(WorkspaceManager::class);

        try {
            $manager->setup();
            self::fail('Compose setup should have failed.');
        } catch (HarbourException) {
            // Expected.
        }

        $workspace = $manager->current();
        self::assertNotNull($workspace);
        self::assertSame('failed', $workspace->state()->status);
        self::assertSame(ResourceType::ComposeProject, $workspace->state()->resources[0]->type);
        $snapshot = $workspace->state()->resources[0]->metadata['file'] ?? null;
        self::assertIsString($snapshot);
        self::assertFileExists($snapshot);

        $runner->fail = false;
        $manager->teardown(true);
        self::assertFileDoesNotExist($this->workspaceDirectory.'/.harbour.json');
        self::assertSame("ORIGINAL=yes\n", file_get_contents($this->workspaceDirectory.'/.env'));
    }
}

final class FailingThenSuccessfulRunner implements CommandRunner
{
    public bool $fail = true;

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $environment
     */
    public function run(array $command, string $workingDirectory, array $environment = [], ?callable $output = null): ProcessResult
    {
        return $this->fail ? new ProcessResult(17, '', 'injected failure') : new ProcessResult(0, '');
    }
}
