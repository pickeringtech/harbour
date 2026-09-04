<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Console;

use PickeringTech\Harbour\Exceptions\ErrorCode;
use PickeringTech\Harbour\Exceptions\HarbourException;
use PickeringTech\Harbour\WorkspaceManager;

final class TeardownCommand extends WorkspaceCommand
{
    protected $signature = 'workspace:teardown {--force : Do not prompt; safety guards remain active} {--json : Emit stable JSON}';

    protected $description = 'Remove resources owned by the current Harbour workspace';

    public function handle(WorkspaceManager $manager): int
    {
        $json = (bool) $this->option('json');

        return $this->executeSafely($json, function () use ($manager, $json): int {
            $force = (bool) $this->option('force');
            if (! $force && ! $this->confirm('Tear down resources proven to be owned by this Harbour workspace?')) {
                if (! $this->input->isInteractive()) {
                    throw new HarbourException(
                        ErrorCode::UnsafeOperation,
                        'Non-interactive teardown requires --force.',
                    );
                }
                $this->components->warn('Teardown aborted; no resources were changed.');

                return self::SUCCESS;
            }
            $manager->teardown($force);
            if ($json) {
                $this->line('{"version":1,"ok":true,"workspace":{"status":"absent"}}');
            } else {
                $this->components->info('Workspace cleaned.');
            }

            return self::SUCCESS;
        });
    }
}
