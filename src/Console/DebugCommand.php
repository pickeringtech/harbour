<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Console;

use PickeringTech\Harbour\Exceptions\ErrorCode;
use PickeringTech\Harbour\Exceptions\HarbourException;
use PickeringTech\Harbour\WorkspaceManager;

final class DebugCommand extends WorkspaceCommand
{
    protected $signature = 'workspace:debug {variable? : Inspect one variable} {--json : Emit stable JSON}';

    protected $description = 'Explain Harbour variable values and provenance with secrets redacted';

    public function handle(WorkspaceManager $manager): int
    {
        $json = (bool) $this->option('json');

        return $this->executeSafely($json, function () use ($manager, $json): int {
            $workspace = $manager->current();
            if ($workspace === null) {
                throw new HarbourException(ErrorCode::UnsafeOperation, 'No Harbour workspace state exists.');
            }
            $debug = $workspace->variables()->debug();
            $name = $this->argument('variable');
            if (is_string($name)) {
                $debug = isset($debug[$name]) ? [$name => $debug[$name]] : [];
            }
            if ($json) {
                $this->line((string) json_encode(['version' => 1, 'ok' => true, 'variables' => $debug], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            } else {
                $this->table(['Variable', 'Value', 'Source', 'Persisted'], array_map(
                    static fn (string $variable, array $data): array => [$variable, $data['value'], $data['source'], $data['persisted'] ? 'yes' : 'no'],
                    array_keys($debug),
                    $debug,
                ));
            }

            return self::SUCCESS;
        });
    }
}
