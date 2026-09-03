<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Console;

use PickeringTech\Harbour\Exceptions\ErrorCode;
use PickeringTech\Harbour\Exceptions\HarbourException;
use PickeringTech\Harbour\Installation\InstallationDiscovery;
use PickeringTech\Harbour\Installation\InstallationSelection;
use PickeringTech\Harbour\Installation\ProjectConfigurationDetector;
use PickeringTech\Harbour\Installation\ProjectInstaller;

final class InstallCommand extends WorkspaceCommand
{
    protected $signature = 'workspace:install
        {--d|database= : Database: none, sqlite, mysql, mariadb, pgsql, mongodb}
        {--c|cache= : Cache: none, file, database, redis, valkey, memcached}
        {--m|mail= : Mail: none, log, mailpit}
        {--with= : Sail-compatible comma-separated services, or none}
        {--detect : Accept the configuration inferred from Sail, Herd, and Laravel files}
        {--json : Emit stable JSON; use --detect or supply selections as options}';

    protected $description = 'Prepare this Laravel project for Harbour without overwriting existing choices';

    public function handle(ProjectInstaller $installer, ProjectConfigurationDetector $detector): int
    {
        $json = (bool) $this->option('json');

        return $this->executeSafely($json, function () use ($installer, $detector, $json): int {
            $discovery = $this->installation($json, $detector);
            $selection = $discovery->selection;
            $result = $installer->install($discovery);

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
            if ($discovery->sources !== []) {
                $this->components->twoColumnDetail('Detected from', implode(', ', $discovery->sources));
            }
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

    private function installation(bool $json, ProjectConfigurationDetector $detector): InstallationDiscovery
    {
        $database = $this->stringOption('database');
        $cache = $this->stringOption('cache');
        $mail = $this->stringOption('mail');
        $with = $this->stringOption('with');
        $detect = (bool) $this->option('detect');
        $explicit = $database !== null || $cache !== null || $mail !== null || $with !== null;

        if ($detect) {
            $discovery = $detector->discover();
            $selection = InstallationSelection::fromOptions(
                $database ?? $discovery->selection->database,
                $cache ?? $discovery->selection->cache,
                $mail ?? $discovery->selection->mail,
                $with ?? $this->serviceList($discovery->selection->additionalServices),
            );

            return $discovery->withSelection($selection);
        }

        if ($explicit) {
            return InstallationDiscovery::explicit(InstallationSelection::fromOptions($database, $cache, $mail, $with));
        }

        if ($json || ! $this->input->isInteractive()) {
            throw new HarbourException(
                ErrorCode::InstallSelectionRequired,
                'Use --detect or choose services explicitly with --database, --cache, --mail, and/or --with=none in non-interactive mode.',
            );
        }

        $discovery = $detector->discover();
        $this->showProposal($discovery);
        $question = $discovery->detected
            ? 'Create Harbour configuration from these detected settings?'
            : 'Create a zero-dependency Harbour setup with these settings?';

        if ($this->confirm($question, true)) {
            return $discovery;
        }

        $databaseChoice = $this->choice(
            'Which database should Harbour isolate?',
            ['None', 'SQLite', 'MySQL', 'MariaDB', 'PostgreSQL', 'MongoDB'],
            $this->databaseDefault($discovery->selection->database),
        );
        $cacheChoice = $this->choice(
            'Which cache and shared-state store should Laravel use?',
            ['None', 'File', 'Database', 'Redis', 'Valkey', 'Memcached'],
            $this->choiceDefault($discovery->selection->cache, ['none', 'file', 'database', 'redis', 'valkey', 'memcached']),
        );
        $mailChoice = $this->choice(
            'Which mail transport should Laravel use locally?',
            ['None', 'Log', 'Mailpit'],
            $this->choiceDefault($discovery->selection->mail, ['none', 'log', 'mailpit']),
        );
        $additionalChoice = $this->choice(
            'Which additional shared services should Harbour configure?',
            ['Meilisearch', 'Typesense', 'MinIO', 'RustFS', 'RabbitMQ', 'Selenium', 'Soketi'],
            implode(',', array_map(static fn (string $service): string => match ($service) {
                'minio' => 'MinIO',
                'rustfs' => 'RustFS',
                default => ucfirst($service),
            }, $discovery->selection->additionalServices)) ?: null,
            null,
            true,
        );

        $additional = [];
        if (is_array($additionalChoice)) {
            foreach ($additionalChoice as $service) {
                $additional[] = $this->choiceString($service, 'additional service');
            }
        }

        $selection = InstallationSelection::fromOptions(
            $this->databaseChoice($databaseChoice),
            $this->choiceString($cacheChoice, 'cache'),
            $this->choiceString($mailChoice, 'mail'),
            $additional === [] ? 'none' : implode(',', $additional),
        );

        return $discovery->withManualSelection($selection);
    }

    private function showProposal(InstallationDiscovery $discovery): void
    {
        $this->newLine();
        $this->components->info($discovery->detected ? 'Harbour detected this project configuration.' : 'No external infrastructure configuration was detected.');
        $this->components->twoColumnDetail('Database', $discovery->selection->database);
        $this->components->twoColumnDetail('Cache', $discovery->selection->cache);
        $this->components->twoColumnDetail('Mail', $discovery->selection->mail);
        $this->components->twoColumnDetail('Additional services', $discovery->selection->additionalServices === [] ? 'none' : implode(', ', $discovery->selection->additionalServices));
        if ($discovery->sources !== []) {
            $this->components->twoColumnDetail('Sources', implode(', ', $discovery->sources));
        }
        $this->newLine();
    }

    /** @param list<string> $services */
    private function serviceList(array $services): string
    {
        return $services === [] ? 'none' : implode(',', $services);
    }

    private function databaseDefault(string $database): int
    {
        return $this->choiceDefault($database === 'pgsql' ? 'postgresql' : $database, ['none', 'sqlite', 'mysql', 'mariadb', 'postgresql', 'mongodb']);
    }

    /** @param list<string> $values */
    private function choiceDefault(string $selected, array $values): int
    {
        $index = array_search($selected, $values, true);

        return is_int($index) ? $index : 0;
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
