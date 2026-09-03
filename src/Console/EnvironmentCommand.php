<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Console;

use PickeringTech\Harbour\Exceptions\ErrorCode;
use PickeringTech\Harbour\Exceptions\HarbourException;
use PickeringTech\Harbour\Variables\ResolvedVariable;
use PickeringTech\Harbour\WorkspaceManager;

final class EnvironmentCommand extends WorkspaceCommand
{
    protected $signature = 'workspace:env {--format=table : table, json, dotenv, or shell} {--show-secrets : Explicitly include secret values}';

    protected $description = 'Print resolved Harbour workspace variables';

    public function handle(WorkspaceManager $manager): int
    {
        $option = $this->option('format');
        $format = is_string($option) ? $option : 'table';

        return $this->executeSafely($format === 'json', function () use ($manager, $format): int {
            $workspace = $manager->current();
            if ($workspace === null) {
                throw new HarbourException(ErrorCode::UnsafeOperation, 'No Harbour workspace state exists.');
            }
            $includeSecrets = (bool) $this->option('show-secrets');
            $variables = array_filter(
                $workspace->variables()->all(),
                static fn (ResolvedVariable $variable): bool => $includeSecrets || ! $variable->isSensitive(),
            );

            match ($format) {
                'json' => $this->line((string) json_encode(['version' => 1, 'ok' => true, 'variables' => array_map(static fn (ResolvedVariable $variable): string => $variable->value, $variables)], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
                'dotenv' => $this->writeLines(array_map(fn (ResolvedVariable $variable): string => $variable->name.'='.$this->dotenvQuote($variable->value), $variables)),
                'shell' => $this->writeLines(array_map(static fn (ResolvedVariable $variable): string => 'export '.$variable->name.'='.escapeshellarg($variable->value), $variables)),
                'table' => $this->table(['Variable', 'Value', 'Source'], array_map(static fn (ResolvedVariable $variable): array => [$variable->name, $variable->isSensitive() ? '[REDACTED]' : $variable->value, $variable->source], $variables)),
                default => throw new HarbourException(ErrorCode::InvalidConfiguration, "Unknown environment format [{$format}]."),
            };

            return self::SUCCESS;
        });
    }

    private function dotenvQuote(string $value): string
    {
        return '"'.str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', '\\n', '\\r'], $value).'"';
    }

    /** @param array<string, string> $lines */
    private function writeLines(array $lines): void
    {
        foreach ($lines as $line) {
            $this->line($line);
        }
    }
}
