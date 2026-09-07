<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Console;

use PickeringTech\Harbour\Installation\ProjectUninstaller;
use PickeringTech\Harbour\Integrations\Orca\OrcaIntegration;
use PickeringTech\Harbour\Integrations\Worktrunk\WorktrunkIntegration;
use PickeringTech\Harbour\WorkspaceManager;

final class UninstallCommand extends WorkspaceCommand
{
    protected $signature = 'workspace:uninstall {--force : Do not prompt; safety guards remain active} {--json : Emit stable JSON}';

    protected $description = 'Tear down this workspace and remove Harbour-managed project configuration';

    public function handle(WorkspaceManager $manager, ProjectUninstaller $uninstaller, OrcaIntegration $orca, WorktrunkIntegration $worktrunk): int
    {
        $json = (bool) $this->option('json');

        return $this->executeSafely($json, function () use ($manager, $uninstaller, $orca, $worktrunk, $json): int {
            $force = (bool) $this->option('force');
            if (! $this->confirmForcedOperation(
                $force,
                'Tear down this workspace and remove Harbour-managed project configuration?',
                'Non-interactive uninstall requires --force.',
                'Uninstall aborted; no resources or project files were changed.',
            )) {
                return self::SUCCESS;
            }

            $manager->teardown($force);
            $orcaResult = $orca->uninstall();
            $worktrunkResult = $worktrunk->uninstall();
            $result = $uninstaller->uninstall();
            $result = $result->withProjectFile(OrcaIntegration::CONFIGURATION_PATH, $orcaResult->change);
            $result = $result->withProjectFile(WorktrunkIntegration::CONFIGURATION_PATH, $worktrunkResult->change);
            if ($json) {
                $this->line((string) json_encode([
                    'version' => 1,
                    'ok' => true,
                    'workspace' => ['status' => 'absent'],
                    'uninstallation' => $result->toArray(),
                ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

                return self::SUCCESS;
            }

            $this->components->info('Harbour workspace and managed project configuration removed.');
            foreach ($result->removed as $path) {
                $this->components->task("Removed {$path}");
            }
            foreach ($result->retained as $path) {
                $this->components->warn("Retained {$path}; Harbour could not prove it still owns this content.");
            }
            $this->newLine();
            $this->line('To remove the package dependency, run <comment>composer remove --dev pickeringtech/harbour</comment>.');

            return self::SUCCESS;
        });
    }
}
