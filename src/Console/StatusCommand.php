<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Console;

use PickeringTech\Harbour\Integrations\Worktrunk\WorktrunkIntegration;
use PickeringTech\Harbour\WorkspaceManager;

final class StatusCommand extends WorkspaceCommand
{
    protected $signature = 'workspace:status {--json : Emit stable JSON}';

    protected $description = 'Show the current Harbour workspace status';

    public function handle(WorkspaceManager $manager, WorktrunkIntegration $worktrunk): int
    {
        $json = (bool) $this->option('json');

        return $this->executeSafely($json, function () use ($manager, $worktrunk, $json): int {
            $status = $manager->status();
            $status['worktree_integrations'] = ['worktrunk' => $worktrunk->status()];
            if ($json) {
                $this->line((string) json_encode($status, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            } else {
                $workspace = $status['workspace'];
                $statusValue = is_scalar($workspace['status'] ?? null) ? (string) $workspace['status'] : 'unknown';
                $pathValue = is_scalar($workspace['path'] ?? null) ? (string) $workspace['path'] : 'unknown';
                $rows = [['State', $statusValue], ['Path', $pathValue]];
                foreach (['slug' => 'Workspace', 'branch' => 'Branch', 'application_url' => 'Application', 'database' => 'Database'] as $key => $label) {
                    if (isset($workspace[$key]) && is_scalar($workspace[$key])) {
                        $rows[] = [$label, (string) $workspace[$key]];
                    }
                }
                $this->components->info('Harbour Workspace');
                $this->table([], $rows);
                $integration = $status['worktree_integrations']['worktrunk'];
                $tool = $integration['tool'];
                $this->components->info('Worktree Integrations');
                $this->table([], [
                    ['Integration', 'Worktrunk'],
                    ['Capability', 'full lifecycle'],
                    ['Configuration', (string) $integration['configuration']],
                    ['Tool version', is_string($tool['version']) ? $tool['version'] : 'unavailable'],
                    ['Supported', $tool['supported'] ? 'yes' : 'no'],
                    ['Conflicts', $integration['conflicts'] === [] ? 'none' : implode('; ', $integration['conflicts'])],
                ]);
            }

            return self::SUCCESS;
        });
    }
}
