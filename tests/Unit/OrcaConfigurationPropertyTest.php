<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Tests\Unit;

use Eris\Generators;
use Eris\TestTrait;
use PHPUnit\Framework\TestCase;
use PickeringTech\Harbour\Contracts\CommandRunner;
use PickeringTech\Harbour\Integrations\Orca\OrcaIntegration;
use PickeringTech\Harbour\Process\ProcessResult;
use Throwable;

final class OrcaConfigurationPropertyTest extends TestCase
{
    use TestTrait;

    public function test_arbitrary_existing_yaml_cannot_be_mutated_during_preparation(): void
    {
        $this->forAll(Generators::string())->then(function (string $yaml): void {
            $workspace = sys_get_temp_dir().'/harbour-orca-property-'.bin2hex(random_bytes(6));
            mkdir($workspace, 0700, true);
            $path = $workspace.'/'.OrcaIntegration::CONFIGURATION_PATH;
            file_put_contents($path, $yaml);

            try {
                try {
                    (new OrcaIntegration($workspace, new PropertyOrcaRunner))->prepare();
                } catch (Throwable) {
                    // Malformed, unsafe, and conflicting documents fail closed.
                }

                self::assertSame($yaml, file_get_contents($path));
            } finally {
                unlink($path);
                rmdir($workspace);
            }
        });
    }
}

final class PropertyOrcaRunner implements CommandRunner
{
    public function run(array $command, string $workingDirectory, array $environment = [], ?callable $output = null): ProcessResult
    {
        if (($command[1] ?? null) === 'agent-context') {
            return new ProcessResult(0, '{"commands":[{"command":"worktree create","flags":["setup"]},{"command":"worktree rm","flags":["run-hooks"]}]}');
        }

        return new ProcessResult(0, '{"result":{"runtime":{"appVersion":"1.4.197","capabilities":["worktree.archive-failure-blocking.v1"]}}}');
    }
}
