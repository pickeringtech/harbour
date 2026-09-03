<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Hooks;

use PickeringTech\Harbour\Exceptions\ErrorCode;
use PickeringTech\Harbour\Exceptions\HarbourException;
use Symfony\Component\Process\Process;

final class LifecycleHookRunner
{
    /**
     * @param  list<string|list<string>>  $commands
     * @param  array<string, string>  $environment
     */
    public function run(string $stage, array $commands, string $workingDirectory, array $environment): void
    {
        foreach ($commands as $command) {
            $process = is_array($command)
                ? new Process($command, $workingDirectory, $environment)
                : Process::fromShellCommandline($command, $workingDirectory, $environment);
            $process->setTimeout(null);
            $process->run();

            if (! $process->isSuccessful()) {
                throw new HarbourException(
                    ErrorCode::ProcessFailed,
                    "Lifecycle hook failed during [{$stage}] with exit code {$process->getExitCode()}.",
                    ['stage' => $stage, 'exit_code' => $process->getExitCode()],
                );
            }
        }
    }
}
