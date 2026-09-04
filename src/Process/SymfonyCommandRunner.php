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
    public function run(array $command, string $workingDirectory, array $environment = [], ?callable $output = null): ProcessResult
    {
        $process = new Process($command, $workingDirectory, $environment);
        $process->setTimeout(null);
        $process->run($output);

        return new ProcessResult($process->getExitCode() ?? 1, trim($process->getOutput()), trim($process->getErrorOutput()));
    }
}
