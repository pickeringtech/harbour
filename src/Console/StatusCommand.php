<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Console;

use PickeringTech\Harbour\Integrations\Orca\OrcaIntegration;
use PickeringTech\Harbour\Integrations\Worktrunk\WorktrunkIntegration;
use PickeringTech\Harbour\WorkspaceManager;

final class StatusCommand extends WorkspaceCommand
{
    protected $signature = 'workspace:status {--json : Emit stable JSON}';

    protected $description = 'Show the current Harbour workspace status';

    public function handle(WorkspaceManager $manager, OrcaIntegration $orca, WorktrunkIntegration $worktrunk): int
    {
        $json = (bool) $this->option('json');

        return $this->executeSafely($json, function () use ($manager, $orca, $worktrunk, $json): int {
            $status = $manager->status();
            $status['worktree_integrations'] = [
                'worktrunk' => $worktrunk->status(),
                'orca' => $orca->status(),
            ];
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
                $this->components->info('Worktree Integrations');
                $rows = [];
                foreach (['orca' => 'Orca IDE', 'worktrunk' => 'Worktrunk'] as $key => $label) {
                    $integration = $status['worktree_integrations'][$key];
                    $tool = $integration['tool'];
                    $rows[] = [
                        $label,
                        str_replace('_', ' ', (string) ($integration['state'] ?? $integration['configuration'])),
                        is_string($tool['version']) ? $tool['version'] : ($tool['available'] ? 'unknown' : 'unavailable'),
                        $tool['supported'] ? 'yes' : 'no',
                        $integration['conflicts'] === [] ? 'none' : implode('; ', $integration['conflicts']),
                    ];
                }
                $this->table(['Integration', 'State', 'Tool version', 'Supported', 'Conflicts'], $rows);
            }

            return self::SUCCESS;
        });
    }
}
