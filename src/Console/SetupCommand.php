<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Console;

use PickeringTech\Harbour\HarbourConfig;
use PickeringTech\Harbour\WorkspaceManager;

final class SetupCommand extends WorkspaceCommand
{
    protected $signature = 'workspace:setup {--fresh : Recreate only Harbour-owned resources} {--force : Do not prompt and replace a modified rendered .env; safety guards remain active} {--seed : Run the configured database seeder even when the workspace is already ready} {--stream : Stream managed service process output (used by the human installer)} {--json : Emit stable JSON}';

    protected $description = 'Set up an isolated Harbour workspace';

    public function handle(WorkspaceManager $manager, HarbourConfig $config): int
    {
        $json = (bool) $this->option('json');

        return $this->executeSafely($json, function () use ($manager, $config, $json): int {
            $fresh = (bool) $this->option('fresh');
            if ($fresh && ! $this->confirmForcedOperation(
                (bool) $this->option('force'),
                'Recreate Harbour-owned workspace resources?',
                'Non-interactive fresh setup requires --force.',
                'Fresh setup aborted; no resources were changed.',
            )) {
                return self::SUCCESS;
            }

            if (! $json && $config->compose !== []) {
                $this->components->info('Starting managed Compose projects; images will be pulled when missing.');
            }

            $output = (bool) $this->option('stream')
                ? fn (string $type, string $buffer) => $this->output->write($buffer)
                : null;
            $workspace = $manager->setup($fresh, (bool) $this->option('force'), (bool) $this->option('seed'), $output);

            if ($json) {
                $this->line((string) json_encode(['version' => 1, 'ok' => true, 'workspace' => $workspace->toArray()], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            } else {
                $this->components->info('Harbour is ready.');
                $this->displayWorkspace($workspace->toArray());
                foreach ($workspace->warnings() as $warning) {
                    $this->components->warn($warning);
                }
            }

            return self::SUCCESS;
        });
    }
}
