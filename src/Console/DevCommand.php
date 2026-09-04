<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Console;

use PickeringTech\Harbour\Contracts\ApplicationLauncher;
use PickeringTech\Harbour\WorkspaceManager;

final class DevCommand extends WorkspaceCommand
{
    protected $signature = 'workspace:dev
        {--no-vite : Launch Laravel without Vite}
        {--from-install : Suppress the duplicate workspace summary when invoked by workspace:install}';

    protected $description = 'Set up and launch this workspace\'s Laravel and Vite development processes';

    public function handle(WorkspaceManager $manager, ApplicationLauncher $launcher): int
    {
        return $this->executeSafely(false, function () use ($manager, $launcher): int {
            $workspace = $manager->setup();
            if (! (bool) $this->option('from-install')) {
                $this->components->info('Harbour is ready. Launching the application.');
                $this->displayWorkspace($workspace->toArray());
            }
            $this->line('Press <comment>Ctrl+C</comment> to stop Laravel and Vite; managed infrastructure remains available.');
            $this->newLine();

            $exitCode = $launcher->launch(
                $workspace,
                ! (bool) $this->option('no-vite'),
                fn (string $name, string $buffer) => $this->output->write($this->prefix($name, $buffer)),
            );

            return $exitCode === 130 ? self::SUCCESS : $exitCode;
        });
    }

    private function prefix(string $name, string $buffer): string
    {
        $lines = preg_split('/(?<=\n)/', $buffer, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return implode('', array_map(static fn (string $line): string => "[{$name}] ".$line, $lines));
    }
}
