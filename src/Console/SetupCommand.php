<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Console;

use Illuminate\Contracts\Config\Repository;
use PickeringTech\Harbour\Exceptions\ErrorCode;
use PickeringTech\Harbour\Exceptions\HarbourException;
use PickeringTech\Harbour\Workspace;
use PickeringTech\Harbour\WorkspaceManager;

final class SetupCommand extends WorkspaceCommand
{
    protected $signature = 'workspace:setup {--fresh : Recreate only Harbour-owned resources} {--force : Do not ask for confirmation} {--json : Emit stable JSON}';

    protected $description = 'Set up an isolated Harbour workspace';

    public function handle(WorkspaceManager $manager, Repository $config): int
    {
        $json = (bool) $this->option('json');

        return $this->executeSafely($json, function () use ($manager, $config, $json): int {
            $fresh = (bool) $this->option('fresh');
            if ($fresh && ! $this->option('force') && ! $this->confirm('Recreate Harbour-owned workspace resources?')) {
                if (! $this->input->isInteractive()) {
                    throw new HarbourException(
                        ErrorCode::UnsafeOperation,
                        'Non-interactive fresh setup requires --force.',
                    );
                }
                $this->components->warn('Fresh setup aborted; no resources were changed.');

                return self::SUCCESS;
            }

            if (! $json && is_array($config->get('harbour.compose')) && $config->get('harbour.compose') !== []) {
                $this->components->info('Starting managed Compose projects; images will be pulled when missing.');
            }

            $workspace = $manager->setup($fresh);

            if ($json) {
                $this->line((string) json_encode(['version' => 1, 'ok' => true, 'workspace' => $workspace->toArray()], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            } else {
                $this->components->info('Harbour is ready.');
                $this->displayWorkspace($workspace);
                foreach ($workspace->warnings() as $warning) {
                    $this->components->warn($warning);
                }
                $appPort = $workspace->ports()['APP_PORT'] ?? null;
                if (is_int($appPort)) {
                    $this->line("Start Laravel with <comment>php artisan serve --host=127.0.0.1 --port={$appPort}</comment>.");
                }
            }

            return self::SUCCESS;
        });
    }

    private function displayWorkspace(Workspace $workspace): void
    {
        $data = $workspace->toArray();
        $rows = [
            ['Workspace', $data['slug']],
            ['Application', $data['application_url'] ?? '—'],
        ];
        foreach ($workspace->ports() as $name => $port) {
            if ($name !== 'APP_PORT') {
                $rows[] = [str_replace('_PORT', '', $name), (string) $port];
            }
        }
        if ($data['database'] !== null) {
            $rows[] = ['Database', $data['database']];
        }
        $resources = $data['resources'] ?? [];
        if (is_array($resources)) {
            foreach ($resources as $resource) {
                $project = is_array($resource) && is_array($resource['metadata'] ?? null)
                    ? ($resource['metadata']['project_name'] ?? null)
                    : null;
                if (is_array($resource) && ($resource['type'] ?? null) === 'compose_project' && is_string($project)) {
                    $rows[] = ['Compose project', $project];
                }
            }
        }
        $this->table([], $rows);
    }
}
