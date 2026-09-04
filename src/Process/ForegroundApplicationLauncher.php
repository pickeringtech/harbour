<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Process;

use PickeringTech\Harbour\Contracts\ApplicationLauncher;
use PickeringTech\Harbour\Contracts\CommandRunner;
use PickeringTech\Harbour\Exceptions\ErrorCode;
use PickeringTech\Harbour\Exceptions\HarbourException;
use PickeringTech\Harbour\Workspace;
use Symfony\Component\Process\Process;

final readonly class ForegroundApplicationLauncher implements ApplicationLauncher
{
    public function __construct(
        private string $workspacePath,
        private ApplicationProcessPlan $plan,
        private CommandRunner $commands,
    ) {}

    public function launch(Workspace $workspace, bool $vite = true, ?callable $output = null): int
    {
        $processes = $this->plan->processes($workspace, $vite);
        if ($vite && count($processes) > 1 && ! $this->plan->nodeDependenciesReady()) {
            $node = $this->plan->nodeCommands($workspace);
            if ($node !== null) {
                $stream = $output === null
                    ? null
                    : static fn (string $type, string $buffer) => $output('node', $buffer);
                $result = $this->commands->run($node['install'], $this->workspacePath, [], $stream);
                if (! $result->successful()) {
                    throw new HarbourException(
                        ErrorCode::ProcessFailed,
                        'The application could not be launched because its Node dependencies could not be installed.',
                        ProcessFailure::context($result),
                    );
                }
            }
        }

        $environment = $workspace->variables()->values();
        $running = [];
        try {
            foreach ($processes as $definition) {
                $process = new Process($definition->command, $this->workspacePath, $environment);
                $process->setTimeout(null);
                $process->start(function (string $type, string $buffer) use ($definition, $output): void {
                    if ($output !== null) {
                        $output($definition->name, $buffer);
                    }
                });
                $running[] = $process;
            }

            while ($running !== []) {
                foreach ($running as $process) {
                    if (! $process->isRunning()) {
                        return $process->getExitCode() ?? 1;
                    }
                }
                usleep(50_000);
            }
        } finally {
            foreach ($running as $process) {
                if ($process->isRunning()) {
                    $process->stop(5);
                }
            }
        }

        return 0;
    }
}
