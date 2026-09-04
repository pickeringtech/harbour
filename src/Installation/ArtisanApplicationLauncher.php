<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Installation;

use PickeringTech\Harbour\Contracts\CommandRunner;
use PickeringTech\Harbour\Contracts\InstalledApplicationLauncher;
use PickeringTech\Harbour\Exceptions\ErrorCode;
use PickeringTech\Harbour\Exceptions\HarbourException;
use PickeringTech\Harbour\Process\ProcessFailure;
use PickeringTech\Harbour\Support\WorkspacePath;

final readonly class ArtisanApplicationLauncher implements InstalledApplicationLauncher
{
    public function __construct(
        private string $workspacePath,
        private CommandRunner $commands,
    ) {}

    public function launch(bool $vite = true, ?callable $output = null): void
    {
        $artisan = $this->workspacePath.'/artisan';
        WorkspacePath::assertSafe($this->workspacePath, $artisan);
        if (! is_file($artisan) || is_link($artisan)) {
            throw new HarbourException(ErrorCode::UnsafeOperation, 'Unable to launch Laravel because its Artisan entry point is missing or unsafe.');
        }

        $command = [PHP_BINARY, $artisan, 'workspace:dev', '--from-install'];
        if (! $vite) {
            $command[] = '--no-vite';
        }
        $result = $this->commands->run($command, $this->workspacePath, [], $output);
        if (! $result->successful() && $result->exitCode !== 130) {
            throw new HarbourException(
                ErrorCode::ProcessFailed,
                'The Harbour environment is ready, but the application development processes exited with an error. Run composer workspace:dev to retry.',
                ProcessFailure::context($result),
            );
        }
    }
}
