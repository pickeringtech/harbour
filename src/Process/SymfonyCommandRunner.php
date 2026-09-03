<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Process;

use PickeringTech\Harbour\Contracts\CommandRunner;
use Symfony\Component\Process\Process;

final class SymfonyCommandRunner implements CommandRunner
{
    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $environment
     */
    public function run(array $command, string $workingDirectory, array $environment = []): ProcessResult
    {
        $process = new Process($command, $workingDirectory, $environment);
        $process->setTimeout(null);
        $process->run();

        return new ProcessResult($process->getExitCode() ?? 1, trim($process->getOutput()), trim($process->getErrorOutput()));
    }
}
