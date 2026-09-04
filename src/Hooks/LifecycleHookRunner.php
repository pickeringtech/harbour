<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Hooks;

use PickeringTech\Harbour\Contracts\CommandRunner;
use PickeringTech\Harbour\Exceptions\ErrorCode;
use PickeringTech\Harbour\Exceptions\HarbourException;
use PickeringTech\Harbour\HarbourConfig;
use PickeringTech\Harbour\Process\ProcessFailure;
use PickeringTech\Harbour\Variables\VariableBag;

final readonly class LifecycleHookRunner
{
    public function __construct(
        private string $workspacePath,
        private HarbourConfig $config,
        private CommandRunner $processes,
    ) {}

    public function run(string $stage, VariableBag $variables): void
    {
        $commands = $this->config->hooks[$stage] ?? [];

        foreach ($commands as $arguments) {
            $result = $this->processes->run($arguments, $this->workspacePath, $variables->values());
            if (! $result->successful()) {
                throw new HarbourException(
                    ErrorCode::ProcessFailed,
                    "Lifecycle hook failed during [{$stage}] with exit code {$result->exitCode}.",
                    ['stage' => $stage, ...ProcessFailure::context($result, $variables->values())],
                );
            }
        }
    }
}
