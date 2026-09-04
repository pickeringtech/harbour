<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Lifecycle;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use PickeringTech\Harbour\Exceptions\ErrorCode;
use PickeringTech\Harbour\Exceptions\HarbourException;
use PickeringTech\Harbour\Hooks\LifecycleHookRunner;
use PickeringTech\Harbour\Variables\VariableBag;

final readonly class LifecycleHooks
{
    public function __construct(
        private string $workspacePath,
        private ConfigRepository $config,
        private LifecycleHookRunner $runner,
    ) {}

    public function run(string $stage, VariableBag $variables): void
    {
        $commands = $this->config->get('harbour.hooks.'.$stage, []);
        if (! is_array($commands)) {
            return;
        }

        $normalized = [];
        foreach ($commands as $command) {
            if (is_string($command)) {
                $normalized[] = $command;
            } elseif (is_array($command) && array_is_list($command)) {
                $arguments = [];
                foreach ($command as $argument) {
                    if (! is_string($argument)) {
                        throw new HarbourException(ErrorCode::InvalidConfiguration, "Invalid lifecycle hook in [{$stage}].");
                    }
                    $arguments[] = $argument;
                }
                $normalized[] = $arguments;
            } else {
                throw new HarbourException(ErrorCode::InvalidConfiguration, "Invalid lifecycle hook in [{$stage}].");
            }
        }

        $this->runner->run($stage, $normalized, $this->workspacePath, $variables->values());
    }
}
