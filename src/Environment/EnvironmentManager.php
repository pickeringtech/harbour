<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Environment;

use PickeringTech\Harbour\Exceptions\ErrorCode;
use PickeringTech\Harbour\Exceptions\HarbourException;
use PickeringTech\Harbour\State\WorkspaceState;
use PickeringTech\Harbour\Support\AtomicFile;
use PickeringTech\Harbour\Support\WorkspacePath;

final readonly class EnvironmentManager
{
    private string $environmentPath;

    private string $backupPath;

    private string $modifiedPath;

    public function __construct(
        private string $workspacePath,
        private AtomicFile $files = new AtomicFile,
    ) {
        $root = rtrim($workspacePath, DIRECTORY_SEPARATOR);
        $this->environmentPath = $root.'/.env';
        $this->backupPath = $root.'/.harbour/backups/env.original';
        $this->modifiedPath = $root.'/.harbour/backups/env.modified';
    }

    public function prepare(WorkspaceState $state): WorkspaceState
    {
        if (array_key_exists('original_exists', $state->environment)) {
            return $state;
        }

        $this->assertManagedPaths();
        $exists = is_file($this->environmentPath);
        $contents = $exists ? file_get_contents($this->environmentPath) : '';

        if ($contents === false) {
            throw new HarbourException(ErrorCode::UnsafeOperation, 'Unable to read the existing .env file.');
        }

        if ($exists) {
            $this->files->write($this->backupPath, $contents);
        }

        return $state->withEnvironment([
            'original_exists' => $exists,
            'original_checksum' => $exists ? hash('sha256', $contents) : null,
            'backup_path' => $exists ? $this->backupPath : null,
            'rendered_checksum' => null,
        ]);
    }

    public function render(WorkspaceState $state, string $contents): WorkspaceState
    {
        if (! array_key_exists('original_exists', $state->environment)) {
            throw new HarbourException(ErrorCode::UnsafeOperation, 'The environment must be preserved before rendering.');
        }

        $this->assertManagedPaths();
        $this->files->write($this->environmentPath, $contents);

        return $state->withEnvironment([
            ...$state->environment,
            'rendered_checksum' => hash('sha256', $contents),
        ]);
    }

    public function assertRenderable(WorkspaceState $state, bool $force): void
    {
        $renderedChecksum = $state->environment['rendered_checksum'] ?? null;
        if (! is_string($renderedChecksum)) {
            return;
        }

        $this->assertManagedPaths();
        $current = is_file($this->environmentPath) ? file_get_contents($this->environmentPath) : null;
        $currentChecksum = is_string($current) ? hash('sha256', $current) : null;

        if ($currentChecksum !== $renderedChecksum && ! $force) {
            throw new HarbourException(
                ErrorCode::EnvironmentModified,
                'The Harbour-rendered .env was modified. Put durable values in .env.harbour, or pass --force to replace the modified .env.',
            );
        }
    }

    public function restore(WorkspaceState $state, bool $force): void
    {
        $this->assertRestorable($state, $force);

        if (! array_key_exists('original_exists', $state->environment)) {
            return;
        }

        $current = is_file($this->environmentPath) ? file_get_contents($this->environmentPath) : null;
        $currentChecksum = is_string($current) ? hash('sha256', $current) : null;
        $renderedChecksum = $state->environment['rendered_checksum'] ?? null;

        if ($currentChecksum !== $renderedChecksum) {
            if (is_string($current)) {
                $this->files->write($this->modifiedPath, $current);
            }
        }

        if ($state->environment['original_exists'] === true) {
            $backup = (string) file_get_contents($this->backupPath);

            $this->files->write($this->environmentPath, $backup);
        } elseif (is_file($this->environmentPath) && ! @unlink($this->environmentPath)) {
            throw new HarbourException(ErrorCode::UnsafeOperation, 'Unable to remove Harbour-generated .env.');
        }
    }

    public function assertRestorable(WorkspaceState $state, bool $force): void
    {
        if (! array_key_exists('original_exists', $state->environment)) {
            return;
        }

        $this->assertManagedPaths();
        $current = is_file($this->environmentPath) ? file_get_contents($this->environmentPath) : null;
        $currentChecksum = is_string($current) ? hash('sha256', $current) : null;
        $renderedChecksum = $state->environment['rendered_checksum'] ?? null;

        if ($currentChecksum !== $renderedChecksum && ! $force) {
            throw new HarbourException(
                ErrorCode::EnvironmentModified,
                'The Harbour-rendered .env was modified; rerun teardown with --force to archive it and restore the original.',
            );
        }

        if ($state->environment['original_exists'] === true) {
            $backup = file_get_contents($this->backupPath);

            if ($backup === false || hash('sha256', $backup) !== $state->environment['original_checksum']) {
                throw new HarbourException(ErrorCode::StateCorrupted, 'The original .env backup is missing or corrupted.');
            }
        }
    }

    public function cleanupBackup(): void
    {
        if (is_file($this->backupPath)) {
            @unlink($this->backupPath);
        }

        $backups = dirname($this->backupPath);
        $internal = dirname($backups);

        if (is_dir($backups) && (scandir($backups) ?: []) === ['.', '..']) {
            @rmdir($backups);
        }

        if (is_dir($internal) && (scandir($internal) ?: []) === ['.', '..']) {
            @rmdir($internal);
        }
    }

    private function assertRegularOrMissing(string $path): void
    {
        if (is_link($path) || (file_exists($path) && ! is_file($path))) {
            throw new HarbourException(ErrorCode::UnsafeOperation, "Refusing to manage unsafe environment path [{$path}].");
        }
    }

    private function assertManagedPaths(): void
    {
        foreach ([$this->environmentPath, $this->backupPath, $this->modifiedPath] as $path) {
            WorkspacePath::assertSafe($this->workspacePath, $path);
        }
        $this->assertRegularOrMissing($this->environmentPath);
    }
}
