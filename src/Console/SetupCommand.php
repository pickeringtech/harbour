<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Console;

use PickeringTech\Harbour\Workspace;
use PickeringTech\Harbour\WorkspaceManager;

final class SetupCommand extends WorkspaceCommand
{
    protected $signature = 'workspace:setup {--fresh : Recreate only Harbour-owned resources} {--force : Do not ask for confirmation} {--json : Emit stable JSON}';

    protected $description = 'Set up an isolated Harbour workspace';

    public function handle(WorkspaceManager $manager): int
    {
        $json = (bool) $this->option('json');

        return $this->executeSafely($json, function () use ($manager, $json): int {
            $fresh = (bool) $this->option('fresh');
            if ($fresh && ! $this->option('force') && ! $this->confirm('Recreate Harbour-owned workspace resources?')) {
                return self::SUCCESS;
            }

            $workspace = $manager->setup($fresh);

            if ($json) {
                $this->line((string) json_encode(['version' => 1, 'ok' => true, 'workspace' => $workspace->toArray()], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            } else {
                $this->components->info('Harbour is ready.');
                $this->displayWorkspace($workspace);
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
        $this->table([], $rows);
    }
}
