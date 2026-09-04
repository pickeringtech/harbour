<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Console;

use Illuminate\Console\Command;
use PickeringTech\Harbour\Exceptions\ErrorCode;
use PickeringTech\Harbour\Exceptions\HarbourException;
use Throwable;

abstract class WorkspaceCommand extends Command
{
    protected function confirmForcedOperation(bool $force, string $question, string $nonInteractive, string $aborted): bool
    {
        if ($force || $this->confirm($question)) {
            return true;
        }
        if (! $this->input->isInteractive()) {
            throw new HarbourException(ErrorCode::UnsafeOperation, $nonInteractive);
        }
        $this->components->warn($aborted);

        return false;
    }

    /** @param array<string, mixed> $workspace */
    protected function displayWorkspace(array $workspace): void
    {
        $slug = $workspace['slug'] ?? null;
        $url = $workspace['application_url'] ?? null;
        if (is_string($slug)) {
            $this->components->twoColumnDetail('Workspace', $slug);
        }
        if (is_string($url)) {
            $this->components->twoColumnDetail('Application', $url);
        }
        $database = $workspace['database'] ?? null;
        if (is_string($database)) {
            $this->components->twoColumnDetail('Database', $database);
        }
        $resources = $workspace['resources'] ?? [];
        if (is_array($resources)) {
            foreach ($resources as $resource) {
                $project = is_array($resource) && is_array($resource['metadata'] ?? null)
                    ? ($resource['metadata']['project_name'] ?? null)
                    : null;
                if (is_array($resource) && ($resource['type'] ?? null) === 'compose_project' && is_string($project)) {
                    $this->components->twoColumnDetail('Compose project', $project);
                }
            }
        }
        $ports = $workspace['ports'] ?? [];
        if (is_array($ports)) {
            foreach ($ports as $name => $port) {
                if (is_string($name) && is_int($port) && $name !== 'APP_PORT') {
                    $this->components->twoColumnDetail(str_replace('_PORT', '', $name), (string) $port);
                }
            }
            $appPort = $ports['APP_PORT'] ?? null;
            if (is_int($appPort)) {
                $this->line("Start Laravel with <comment>php artisan serve --host=127.0.0.1 --port={$appPort}</comment>.");
            }
        }
    }

    /** @param callable(): int $operation */
    protected function executeSafely(bool $json, callable $operation): int
    {
        try {
            return $operation();
        } catch (Throwable $exception) {
            $error = $exception instanceof HarbourException
                ? $exception
                : new HarbourException(ErrorCode::UnsafeOperation, $exception->getMessage(), [], $exception);

            if ($json) {
                $this->line((string) json_encode([
                    'version' => 1,
                    'ok' => false,
                    'error' => $error->toArray(),
                ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            } else {
                $this->components->error($error->getMessage());
                $stderr = $error->context['stderr'] ?? null;
                if (is_string($stderr) && $stderr !== '') {
                    $this->line($stderr);
                }
                if ($this->output->isVerbose()) {
                    $this->line('Error code: '.$error->errorCode->value);
                }
            }

            return self::FAILURE;
        }
    }
}
