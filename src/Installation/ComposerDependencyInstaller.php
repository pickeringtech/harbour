<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Installation;

use PickeringTech\Harbour\Contracts\CommandRunner;
use PickeringTech\Harbour\Contracts\InstallationDependencyInstaller;
use PickeringTech\Harbour\Exceptions\ErrorCode;
use PickeringTech\Harbour\Exceptions\HarbourException;
use PickeringTech\Harbour\Process\ProcessFailure;

final readonly class ComposerDependencyInstaller implements InstallationDependencyInstaller
{
    public function __construct(
        private string $workspacePath,
        private CommandRunner $commands,
    ) {}

    public function install(array $requirements, ?callable $output = null): void
    {
        $runtime = [];
        $development = [];

        foreach ($requirements as $requirement) {
            $package = $requirement->composerPackage();
            if ($package === null) {
                continue;
            }

            if ($requirement->isDevelopmentDependency()) {
                $development[] = $package;
            } else {
                $runtime[] = $package;
            }
        }

        $this->requirePackages(array_values(array_unique($runtime)), false, $output);
        $this->requirePackages(array_values(array_unique($development)), true, $output);
    }

    /**
     * @param  list<string>  $packages
     * @param  null|callable(string, string): void  $output
     */
    private function requirePackages(array $packages, bool $development, ?callable $output): void
    {
        if ($packages === []) {
            return;
        }

        $command = ['composer', 'require'];
        if ($development) {
            $command[] = '--dev';
        }
        $command = [...$command, ...$packages, '--no-interaction'];
        $result = $this->commands->run($command, $this->workspacePath, [], $output);

        if (! $result->successful()) {
            throw new HarbourException(
                ErrorCode::ProcessFailed,
                'Composer could not install Harbour\'s selected project integrations. The component selection has not been lost; resolve the Composer error and rerun workspace:install.',
                [...ProcessFailure::context($result), 'command' => $command],
            );
        }
    }
}
