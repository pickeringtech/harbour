<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Process;

use JsonException;
use PickeringTech\Harbour\Exceptions\ErrorCode;
use PickeringTech\Harbour\Exceptions\HarbourException;
use PickeringTech\Harbour\Support\WorkspacePath;
use PickeringTech\Harbour\Workspace;

final readonly class ApplicationProcessPlan
{
    public function __construct(private string $workspacePath) {}

    /** @return list<ApplicationProcess> */
    public function processes(Workspace $workspace, bool $vite = true): array
    {
        $artisan = $this->workspacePath.'/artisan';
        WorkspacePath::assertSafe($this->workspacePath, $artisan);
        if (! is_file($artisan) || is_link($artisan)) {
            throw new HarbourException(ErrorCode::UnsafeOperation, 'Unable to launch Laravel because its Artisan entry point is missing or unsafe.');
        }

        $appPort = $workspace->ports()['APP_PORT'] ?? null;
        if (! is_int($appPort)) {
            throw new HarbourException(ErrorCode::InvalidConfiguration, 'The workspace has no APP_PORT allocation.');
        }

        $processes = [new ApplicationProcess('laravel', [
            PHP_BINARY,
            $artisan,
            'serve',
            '--host=127.0.0.1',
            '--port='.$appPort,
            '--tries=1',
        ])];

        if ($vite && ($viteProcess = $this->vite($workspace)) !== null) {
            $processes[] = $viteProcess;
        }

        return $processes;
    }

    /** @return null|array{command: list<string>, install: list<string>} */
    public function nodeCommands(Workspace $workspace): ?array
    {
        $vite = $this->vite($workspace);
        if ($vite === null) {
            return null;
        }

        $manager = $vite->command[0];
        $install = match ($manager) {
            'npm' => ['npm', 'install'],
            'pnpm' => ['pnpm', 'install'],
            'yarn' => ['yarn', 'install'],
            'bun' => ['bun', 'install'],
            default => throw new HarbourException(ErrorCode::InvalidConfiguration, 'Unsupported Node package manager ['.$manager.'].'),
        };

        return ['command' => $vite->command, 'install' => $install];
    }

    public function nodeDependenciesReady(): bool
    {
        return is_file($this->workspacePath.'/node_modules/.bin/vite');
    }

    private function vite(Workspace $workspace): ?ApplicationProcess
    {
        $path = $this->workspacePath.'/package.json';
        WorkspacePath::assertSafe($this->workspacePath, $path);
        if (! is_file($path) || is_link($path)) {
            return null;
        }

        $contents = file_get_contents($path);
        try {
            $package = is_string($contents) ? json_decode($contents, true, flags: JSON_THROW_ON_ERROR) : null;
        } catch (JsonException $exception) {
            throw new HarbourException(ErrorCode::InvalidConfiguration, 'package.json contains invalid JSON.', [], $exception);
        }
        if (! is_array($package) || ! is_array($package['scripts'] ?? null) || ! is_string($package['scripts']['dev'] ?? null)) {
            return null;
        }
        $dependencies = [
            ...(is_array($package['dependencies'] ?? null) ? $package['dependencies'] : []),
            ...(is_array($package['devDependencies'] ?? null) ? $package['devDependencies'] : []),
        ];
        if (! array_key_exists('vite', $dependencies) && ! array_key_exists('laravel-vite-plugin', $dependencies)) {
            return null;
        }

        $vitePort = $workspace->ports()['VITE_PORT'] ?? null;
        if (! is_int($vitePort)) {
            throw new HarbourException(ErrorCode::InvalidConfiguration, 'The workspace has no VITE_PORT allocation.');
        }

        $manager = $this->packageManager();
        $command = match ($manager) {
            'yarn' => ['yarn', 'dev', '--host', '127.0.0.1', '--port', (string) $vitePort, '--strictPort'],
            default => [$manager, 'run', 'dev', '--', '--host', '127.0.0.1', '--port', (string) $vitePort, '--strictPort'],
        };

        return new ApplicationProcess('vite', $command);
    }

    private function packageManager(): string
    {
        return match (true) {
            is_file($this->workspacePath.'/bun.lock'), is_file($this->workspacePath.'/bun.lockb') => 'bun',
            is_file($this->workspacePath.'/pnpm-lock.yaml') => 'pnpm',
            is_file($this->workspacePath.'/yarn.lock') => 'yarn',
            default => 'npm',
        };
    }
}
