<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Installation;

use PickeringTech\Harbour\Contracts\CommandRunner;
use PickeringTech\Harbour\Contracts\InstalledWorkspaceStarter;
use PickeringTech\Harbour\Exceptions\ErrorCode;
use PickeringTech\Harbour\Exceptions\HarbourException;
use PickeringTech\Harbour\Support\WorkspacePath;

final readonly class ArtisanWorkspaceStarter implements InstalledWorkspaceStarter
{
    public function __construct(
        private string $workspacePath,
        private CommandRunner $processes,
    ) {}

    public function start(): string
    {
        $artisan = $this->workspacePath.'/artisan';
        WorkspacePath::assertSafe($this->workspacePath, $artisan);

        if (! is_file($artisan) || is_link($artisan)) {
            throw new HarbourException(ErrorCode::UnsafeOperation, 'Unable to start the workspace because its Artisan entry point is missing or unsafe.');
        }

        $result = $this->processes->run([PHP_BINARY, $artisan, 'workspace:setup'], $this->workspacePath);
        if (! $result->successful()) {
            throw new HarbourException(
                ErrorCode::ProcessFailed,
                'Harbour project files were created, but the workspace could not be started. Run composer workspace:setup to retry.',
                ['exit_code' => $result->exitCode],
            );
        }

        return $result->output;
    }
}
