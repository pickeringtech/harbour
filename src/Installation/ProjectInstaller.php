<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Installation;

use JsonException;
use PickeringTech\Harbour\Exceptions\ErrorCode;
use PickeringTech\Harbour\Exceptions\HarbourException;
use PickeringTech\Harbour\Support\AtomicFile;
use PickeringTech\Harbour\Support\WorkspacePath;

final readonly class ProjectInstaller
{
    /** @var array<string, list<string>> */
    private const COMPOSER_SCRIPTS = [
        'workspace:setup' => ['@php artisan workspace:setup'],
        'workspace:status' => ['@php artisan workspace:status'],
        'workspace:teardown' => ['@php artisan workspace:teardown'],
    ];

    public function __construct(
        private string $workspacePath,
        private AtomicFile $files = new AtomicFile,
        private InstallationFileRenderer $renderer = new InstallationFileRenderer,
        private InstallationComposeRenderer $compose = new InstallationComposeRenderer,
    ) {}

    public function install(InstallationSelection|InstallationDiscovery $installation): InstallationResult
    {
        $discovery = $installation instanceof InstallationSelection
            ? InstallationDiscovery::explicit($installation)
            : $installation;
        $created = [];
        $updated = [];
        $unchanged = [];
        $conflicts = [];

        $this->writeIfMissing('.env.harbour', $this->renderer->environment($discovery), $created, $unchanged);
        $this->writeIfMissing('config/harbour.php', $this->renderer->configuration($discovery), $created, $unchanged);
        if ($discovery->selection->provider === 'compose') {
            $this->writeIfMissing('docker-compose.harbour.yml', $this->compose->render($discovery->selection), $created, $unchanged);
        }
        $this->updateGitignore($updated, $unchanged);
        $this->updateComposer($updated, $unchanged, $conflicts);

        return new InstallationResult($created, $updated, $unchanged, $conflicts, $discovery->selection, $discovery->metadata());
    }

    /**
     * @param  list<string>  $created
     * @param  list<string>  $unchanged
     */
    private function writeIfMissing(string $destination, string $contents, array &$created, array &$unchanged): void
    {
        $target = $this->target($destination);
        $this->assertRegularOrMissing($target);

        if (is_file($target)) {
            $unchanged[] = $destination;

            return;
        }

        $this->files->write($target, $contents, 0644);
        $created[] = $destination;
    }

    /**
     * @param  list<string>  $updated
     * @param  list<string>  $unchanged
     */
    private function updateGitignore(array &$updated, array &$unchanged): void
    {
        $path = $this->target('.gitignore');
        $this->assertRegularOrMissing($path);
        $contents = is_file($path) ? file_get_contents($path) : '';
        if ($contents === false) {
            throw new HarbourException(ErrorCode::UnsafeOperation, 'Unable to read the project .gitignore.');
        }

        $lines = preg_split('/\R/', $contents) ?: [];
        $missing = array_values(array_filter(
            ['/.harbour.json', '/.harbour/'],
            static fn (string $entry): bool => ! in_array($entry, $lines, true),
        ));

        if ($missing === []) {
            $unchanged[] = '.gitignore';

            return;
        }

        $suffix = ($contents !== '' && ! str_ends_with($contents, "\n") ? "\n" : '')
            .($contents === '' ? '' : "\n")
            ."# Harbour workspace state\n"
            .implode("\n", $missing)."\n";
        $this->files->write($path, $contents.$suffix, 0644);
        $updated[] = '.gitignore';
    }

    /**
     * @param  list<string>  $updated
     * @param  list<string>  $unchanged
     * @param  list<string>  $conflicts
     */
    private function updateComposer(array &$updated, array &$unchanged, array &$conflicts): void
    {
        $path = $this->target('composer.json');
        $this->assertRegularOrMissing($path);
        if (! is_file($path)) {
            throw new HarbourException(ErrorCode::InvalidConfiguration, 'Harbour must be installed from a Laravel project containing composer.json.');
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new HarbourException(ErrorCode::UnsafeOperation, 'Unable to read composer.json.');
        }

        try {
            $manifest = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new HarbourException(ErrorCode::InvalidConfiguration, 'composer.json contains invalid JSON.', [], $exception);
        }

        if (! is_array($manifest) || array_is_list($manifest)) {
            throw new HarbourException(ErrorCode::InvalidConfiguration, 'composer.json must contain a JSON object.');
        }

        $scripts = $manifest['scripts'] ?? [];
        if (! is_array($scripts)) {
            throw new HarbourException(ErrorCode::InvalidConfiguration, 'composer.json scripts must be a JSON object.');
        }

        $changed = false;
        foreach (self::COMPOSER_SCRIPTS as $name => $commands) {
            if (! array_key_exists($name, $scripts)) {
                $scripts[$name] = $commands;
                $changed = true;

                continue;
            }

            if ($scripts[$name] !== $commands) {
                $conflicts[] = "composer.json scripts.{$name}";
            }
        }

        if (! $changed) {
            $unchanged[] = 'composer.json';

            return;
        }

        $manifest['scripts'] = $scripts;
        try {
            $encoded = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        } catch (JsonException $exception) {
            throw new HarbourException(ErrorCode::InvalidConfiguration, 'Unable to encode composer.json.', [], $exception);
        }
        $this->files->write($path, $encoded, 0644);
        $updated[] = 'composer.json';
    }

    private function target(string $relative): string
    {
        $path = rtrim($this->workspacePath, DIRECTORY_SEPARATOR).'/'.$relative;
        WorkspacePath::assertSafe($this->workspacePath, $path);

        return $path;
    }

    private function assertRegularOrMissing(string $path): void
    {
        if (is_link($path) || (file_exists($path) && ! is_file($path))) {
            throw new HarbourException(ErrorCode::UnsafeOperation, "Refusing to install through unsafe project path [{$path}].");
        }
    }
}
