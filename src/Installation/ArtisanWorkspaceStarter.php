<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Installation;

use PickeringTech\Harbour\Contracts\CommandRunner;
use PickeringTech\Harbour\Contracts\InstalledWorkspaceStarter;
use PickeringTech\Harbour\Exceptions\ErrorCode;
use PickeringTech\Harbour\Exceptions\HarbourException;
use PickeringTech\Harbour\Process\ProcessFailure;
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

        $cachedConfiguration = $this->workspacePath.'/bootstrap/cache/config.php';
        WorkspacePath::assertSafe($this->workspacePath, $cachedConfiguration);
        if (is_link($cachedConfiguration)) {
            throw new HarbourException(ErrorCode::UnsafeOperation, 'Refusing to clear a symlinked Laravel configuration cache.');
        }
        if (is_file($cachedConfiguration)) {
            $clear = $this->processes->run([PHP_BINARY, $artisan, 'config:clear'], $this->workspacePath);
            if (! $clear->successful()) {
                throw new HarbourException(
                    ErrorCode::ProcessFailed,
                    'Harbour project files were created, but Laravel configuration cache could not be cleared.',
                    ProcessFailure::context($clear),
                );
            }
        }

        $result = $this->processes->run([PHP_BINARY, $artisan, 'workspace:setup', '--json'], $this->workspacePath);
        if (! $result->successful()) {
            throw new HarbourException(
                ErrorCode::ProcessFailed,
                'Harbour project files were created, but the workspace could not be started. Run composer workspace:setup to retry.',
                ProcessFailure::context($result),
            );
        }

        $payload = json_decode($result->output, true);
        $workspace = is_array($payload) ? ($payload['workspace'] ?? null) : null;
        if (! is_array($workspace) || ($payload['ok'] ?? null) !== true) {
            throw new HarbourException(
                ErrorCode::ProcessFailed,
                'Harbour setup completed without a valid workspace status payload. Run composer workspace:status to inspect it.',
                ['exit_code' => $result->exitCode],
            );
        }

        return $result->output;
    }
}
