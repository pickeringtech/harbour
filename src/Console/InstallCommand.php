<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Console;

use PickeringTech\Harbour\Exceptions\ErrorCode;
use PickeringTech\Harbour\Exceptions\HarbourException;
use PickeringTech\Harbour\Installation\InstallationSelection;
use PickeringTech\Harbour\Installation\ProjectInstaller;

final class InstallCommand extends WorkspaceCommand
{
    protected $signature = 'workspace:install
        {--d|database= : Database: none, sqlite, mysql, mariadb, pgsql, mongodb}
        {--c|cache= : Cache: none, file, database, redis, valkey, memcached}
        {--m|mail= : Mail: none, log, mailpit}
        {--with= : Sail-compatible comma-separated services, or none}
        {--json : Emit stable JSON; selections must be supplied as options}';

    protected $description = 'Prepare this Laravel project for Harbour without overwriting existing choices';

    public function handle(ProjectInstaller $installer): int
    {
        $json = (bool) $this->option('json');

        return $this->executeSafely($json, function () use ($installer, $json): int {
            $selection = $this->selection($json);
            $result = $installer->install($selection);

            if ($json) {
                $this->line((string) json_encode([
                    'version' => 1,
                    'ok' => true,
                    'installation' => $result->toArray(),
                ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

                return self::SUCCESS;
            }

            $this->components->info('Harbour project files are ready.');
            $this->components->twoColumnDetail('Database', $selection->database);
            $this->components->twoColumnDetail('Cache', $selection->cache);
            $this->components->twoColumnDetail('Mail', $selection->mail);
            $this->components->twoColumnDetail('Shared services', $selection->services() === [] ? 'none' : implode(', ', $selection->services()));
            $this->newLine();
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

    private function selection(bool $json): InstallationSelection
    {
        $database = $this->stringOption('database');
        $cache = $this->stringOption('cache');
        $mail = $this->stringOption('mail');
        $with = $this->stringOption('with');
        $explicit = $database !== null || $cache !== null || $mail !== null || $with !== null;

        if ($explicit) {
            return InstallationSelection::fromOptions($database, $cache, $mail, $with);
        }

        if ($json || ! $this->input->isInteractive()) {
            throw new HarbourException(
                ErrorCode::InstallSelectionRequired,
                'Choose services explicitly with --database, --cache, --mail, and/or --with=none in non-interactive mode.',
            );
        }

        $databaseChoice = $this->choice(
            'Which database should Harbour isolate?',
            ['None', 'SQLite', 'MySQL', 'MariaDB', 'PostgreSQL', 'MongoDB'],
            1,
        );
        $cacheChoice = $this->choice(
            'Which cache and shared-state store should Laravel use?',
            ['None', 'File', 'Database', 'Redis', 'Valkey', 'Memcached'],
            1,
        );
        $mailChoice = $this->choice(
            'Which mail transport should Laravel use locally?',
            ['None', 'Log', 'Mailpit'],
            1,
        );
        $additionalChoice = $this->choice(
            'Which additional shared services should Harbour configure?',
            ['Meilisearch', 'Typesense', 'MinIO', 'RustFS', 'RabbitMQ', 'Selenium', 'Soketi'],
            null,
            null,
            true,
        );

        $additional = [];
        if (is_array($additionalChoice)) {
            foreach ($additionalChoice as $service) {
                $additional[] = $this->choiceString($service, 'additional service');
            }
        }

        return InstallationSelection::fromOptions(
            $this->databaseChoice($databaseChoice),
            $this->choiceString($cacheChoice, 'cache'),
            $this->choiceString($mailChoice, 'mail'),
            $additional === [] ? 'none' : implode(',', $additional),
        );
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        if ($value === null) {
            return null;
        }
        if (! is_string($value)) {
            throw new HarbourException(ErrorCode::InvalidInstallSelection, "The --{$name} option must be a string.");
        }

        return $value;
    }

    private function databaseChoice(mixed $choice): string
    {
        $value = $this->choiceString($choice, 'database');

        return $value === 'postgresql' ? 'pgsql' : $value;
    }

    private function choiceString(mixed $choice, string $group): string
    {
        if (! is_string($choice)) {
            throw new HarbourException(ErrorCode::InvalidInstallSelection, "The interactive {$group} choice must be a string.");
        }

        return strtolower($choice);
    }
}
