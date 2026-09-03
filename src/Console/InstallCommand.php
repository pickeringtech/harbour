<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Console;

use PickeringTech\Harbour\Installation\ProjectInstaller;

final class InstallCommand extends WorkspaceCommand
{
    protected $signature = 'workspace:install {--json : Emit stable JSON}';

    protected $description = 'Prepare this Laravel project for Harbour without overwriting existing choices';

    public function handle(ProjectInstaller $installer): int
    {
        $json = (bool) $this->option('json');

        return $this->executeSafely($json, function () use ($installer, $json): int {
            $result = $installer->install();

            if ($json) {
                $this->line((string) json_encode([
                    'version' => 1,
                    'ok' => true,
                    'installation' => $result->toArray(),
                ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

                return self::SUCCESS;
            }

            $this->components->info('Harbour project files are ready.');
            foreach ($result->created as $path) {
                $this->components->task("Created {$path}");
            }
            foreach ($result->updated as $path) {
                $this->components->task("Updated {$path}");
            }
            foreach ($result->unchanged as $path) {
                $this->components->twoColumnDetail($path, '<fg=gray>already configured</>');
            }
            foreach ($result->conflicts as $path) {
                $this->components->warn("Kept existing {$path}; Harbour never replaces a project-defined script.");
            }
            $this->newLine();
            $this->line('Review <comment>.env.harbour</comment>, commit the project files, then run <comment>composer workspace:setup</comment>.');

            return self::SUCCESS;
        });
    }
}
