<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Console;

use PickeringTech\Harbour\WorkspaceManager;

final class RenderCommand extends WorkspaceCommand
{
    protected $signature = 'workspace:render {--json : Emit stable JSON}';

    protected $description = 'Render .env again from current Harbour state';

    public function handle(WorkspaceManager $manager): int
    {
        $json = (bool) $this->option('json');

        return $this->executeSafely($json, function () use ($manager, $json): int {
            $workspace = $manager->render();
            if ($json) {
                $this->line((string) json_encode(['version' => 1, 'ok' => true, 'workspace' => $workspace->toArray()], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            } else {
                $this->components->info('Environment rendered.');
            }

            return self::SUCCESS;
        });
    }
}
