<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Console;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\select;

use PickeringTech\Harbour\Contracts\InstallationPreflight;
use PickeringTech\Harbour\Contracts\InstalledWorkspaceStarter;
use PickeringTech\Harbour\Exceptions\ErrorCode;
use PickeringTech\Harbour\Exceptions\HarbourException;
use PickeringTech\Harbour\Installation\InstallationDiscovery;
use PickeringTech\Harbour\Installation\InstallationPlan;
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
        {--provider= : Service provider: shared or compose}
        {--compose : Generate a workspace-managed Docker Compose stack}
        {--start : Set up this workspace and start managed services after installation}
        {--json : Emit stable JSON; use --detect or supply selections as options}';

    protected $description = 'Prepare this Laravel project for Harbour without overwriting existing choices';

    public function handle(
        ProjectInstaller $installer,
        ProjectConfigurationDetector $detector,
        InstalledWorkspaceStarter $starter,
        InstallationPreflight $preflight,
    ): int {
        $json = (bool) $this->option('json');

        return $this->executeSafely($json, function () use ($installer, $detector, $starter, $preflight, $json): int {
            $plan = $this->installation($json, $detector);
            $discovery = $plan->discovery;
            $selection = $discovery->selection;
            if ($json) {
                $preflight->assertReady($selection);
            } else {
                $this->components->task('Checking selected stack requirements', fn () => $preflight->assertReady($selection));
            }
            $result = $installer->install($discovery);
            $startOutput = '';

            if ($plan->start) {
                if ($json) {
                    $startOutput = $starter->start();
                } else {
                    if ($selection->provider === 'compose') {
                        $this->components->info('Starting managed Compose services; images will be pulled when missing.');
                    }
                    $this->components->task('Setting up this workspace', function () use ($starter, &$startOutput): void {
                        $startOutput = $starter->start();
                    });
                }
            }

            if ($json) {
                $payload = [
                    'version' => 1,
                    'ok' => true,
                    'installation' => [...$result->toArray(), 'started' => $plan->start],
                ];
                if ($startOutput !== '') {
                    $payload['workspace'] = $this->startedWorkspace($startOutput);
                }
                $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

                return self::SUCCESS;
            }

            $this->components->info('Harbour project files are ready.');
            $this->components->twoColumnDetail('Database', $selection->database);
            $this->components->twoColumnDetail('Cache', $selection->cache);
            $this->components->twoColumnDetail('Mail', $selection->mail);
            $this->components->twoColumnDetail('Provider', $selection->provider === 'compose' ? 'Docker Compose' : 'shared infrastructure');
            $this->components->twoColumnDetail('Services', $selection->services() === [] ? 'none' : implode(', ', $selection->services()));
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
            foreach ($result->reconfigure as $path) {
                $this->components->warn("To change the installation selection, delete {$path} and rerun workspace:install; Harbour will never overwrite it.");
            }
            foreach ($result->conflicts as $path) {
                $this->components->warn("Kept existing {$path}; Harbour never replaces a project-defined script.");
            }
            $this->newLine();
            if ($plan->start) {
                if ($startOutput !== '') {
                    $this->displayStartedWorkspace($this->startedWorkspace($startOutput));
                }
                $this->line('The project policy is ready and this workspace is running.');
            } else {
                $this->line('Review <comment>.env.harbour</comment>, commit the project files, then run <comment>composer workspace:setup</comment>.');
            }

            return self::SUCCESS;
        });
    }

    private function installation(bool $json, ProjectConfigurationDetector $detector): InstallationPlan
    {
        $database = $this->stringOption('database');
        $cache = $this->stringOption('cache');
        $mail = $this->stringOption('mail');
        $with = $this->stringOption('with');
        $provider = $this->providerOption();
        $detect = (bool) $this->option('detect');
        $start = (bool) $this->option('start');
        $explicit = $database !== null || $cache !== null || $mail !== null || $with !== null;

        if ($detect) {
            $discovery = $detector->discover();
            $selection = InstallationSelection::fromOptions(
                $database ?? $discovery->selection->database,
                $cache ?? $discovery->selection->cache,
                $mail ?? $discovery->selection->mail,
                $with ?? $this->serviceList($discovery->selection->additionalServices),
                $provider ?? $discovery->selection->provider,
            );

            return new InstallationPlan($discovery->withSelection($selection), $start);
        }

        if ($explicit) {
            return new InstallationPlan(
                InstallationDiscovery::explicit(InstallationSelection::fromOptions($database, $cache, $mail, $with, $provider)),
                $start,
            );
        }

        if ($json || ! $this->input->isInteractive()) {
            throw new HarbourException(
                ErrorCode::InstallSelectionRequired,
                'Use --detect or choose services explicitly with --database, --cache, --mail, and/or --with=none in non-interactive mode.',
            );
        }

        intro('Configure Harbour');
        $mode = select(
            label: 'How would you like to configure this project?',
            options: [
                'detect' => 'Auto-detect from this project',
                'manual' => 'Choose components manually',
            ],
            default: 'detect',
            hint: 'Use the arrow keys and press Enter.',
        );

        if ($mode === 'detect') {
            $discovery = $detector->discover();
            $this->showProposal($discovery);
            $question = $discovery->detected
                ? 'Use these detected settings?'
                : 'Use this zero-dependency setup?';

            if (confirm($question, true)) {
                return new InstallationPlan($discovery, $this->confirmStart($discovery->selection, $start));
            }
        } elseif ($mode === 'manual') {
            $discovery = $detector->discover();
        } else {
            throw new HarbourException(ErrorCode::InvalidInstallSelection, 'Choose auto-detection or manual component selection.');
        }

        $databaseChoice = select(
            label: 'Which database connection should Laravel use?',
            options: [
                'none' => 'None',
                'sqlite' => 'SQLite',
                'mysql' => 'MySQL',
                'mariadb' => 'MariaDB',
                'pgsql' => 'PostgreSQL',
                'mongodb' => 'MongoDB (connection only; database is not owned)',
            ],
            default: $discovery->selection->database,
            scroll: count(InstallationSelection::DATABASES),
            hint: 'SQL databases are isolated; MongoDB is connection-only.',
        );
        $cacheChoice = select(
            label: 'Which cache and shared-state store should Laravel use?',
            options: [
                'none' => 'None / array',
                'file' => 'File',
                'database' => 'Database',
                'redis' => 'Redis',
                'valkey' => 'Valkey',
                'memcached' => 'Memcached',
            ],
            default: $discovery->selection->cache,
            scroll: count(InstallationSelection::CACHES),
        );
        $mailChoice = select(
            label: 'Which mail transport should Laravel use locally?',
            options: [
                'none' => 'None / array',
                'log' => 'Log',
                'mailpit' => 'Mailpit',
            ],
            default: $discovery->selection->mail,
            scroll: count(InstallationSelection::MAILERS),
        );
        $additionalChoice = multiselect(
            label: 'Which additional components should Harbour configure?',
            options: [
                'meilisearch' => 'Meilisearch',
                'typesense' => 'Typesense',
                'minio' => 'MinIO',
                'rustfs' => 'RustFS',
                'rabbitmq' => 'RabbitMQ',
                'selenium' => 'Selenium',
                'soketi' => 'Soketi',
            ],
            default: $discovery->selection->additionalServices,
            scroll: count(InstallationSelection::ADDITIONAL_SERVICES),
            hint: 'Use Space to select multiple components, then press Enter.',
        );

        $additional = [];
        foreach ($additionalChoice as $service) {
            $additional[] = $this->choiceString($service, 'additional service');
        }

        $selection = InstallationSelection::fromOptions(
            $this->choiceString($databaseChoice, 'database'),
            $this->choiceString($cacheChoice, 'cache'),
            $this->choiceString($mailChoice, 'mail'),
            $additional === [] ? 'none' : implode(',', $additional),
        );

        if ($provider !== null) {
            $selection = $selection->withProvider($provider);
        } elseif ($selection->services() !== []) {
            $useCompose = confirm(
                label: 'Use Docker Compose for these service-backed components?',
                default: false,
                hint: 'No keeps using existing shared infrastructure.',
            );
            if ($useCompose) {
                $selection = $selection->withProvider('compose');
            }
        }

        return new InstallationPlan($discovery->withManualSelection($selection), $this->confirmStart($selection, $start));
    }

    private function confirmStart(InstallationSelection $selection, bool $start): bool
    {
        if ($start) {
            return true;
        }

        $startLabel = $selection->provider === 'compose'
            ? 'Start these Docker Compose components and set up this workspace now?'
            : 'Set up this workspace now?';

        return confirm(
            label: $startLabel,
            default: $selection->provider === 'compose',
            hint: $selection->provider === 'compose'
                ? 'Harbour will wait until the services are ready.'
                : 'You can always run composer workspace:setup later.',
        );
    }

    /** @return array<string, mixed> */
    private function startedWorkspace(string $output): array
    {
        $payload = json_decode($output, true);
        $workspace = is_array($payload) ? ($payload['workspace'] ?? null) : null;
        if (! is_array($workspace) || ($payload['ok'] ?? null) !== true) {
            throw new HarbourException(
                ErrorCode::ProcessFailed,
                'Harbour setup completed without a valid workspace status payload. Run composer workspace:status to inspect it.',
            );
        }

        /** @var array<string, mixed> $workspace */
        return $workspace;
    }

    /** @param array<string, mixed> $workspace */
    private function displayStartedWorkspace(array $workspace): void
    {
        $slug = $workspace['slug'] ?? null;
        $url = $workspace['application_url'] ?? null;
        if (is_string($slug)) {
            $this->components->twoColumnDetail('Workspace', $slug);
        }
        if (is_string($url)) {
            $this->components->twoColumnDetail('Application', $url);
        }

        $resources = $workspace['resources'] ?? [];
        if (is_array($resources)) {
            foreach ($resources as $resource) {
                $project = is_array($resource) && is_array($resource['metadata'] ?? null)
                    ? ($resource['metadata']['project_name'] ?? null)
                    : null;
                if (is_array($resource) && ($resource['type'] ?? null) === 'compose_project' && is_string($project)) {
                    $this->components->twoColumnDetail('Compose project', $project);
                }
            }
        }

        $ports = $workspace['ports'] ?? [];
        $appPort = is_array($ports) ? ($ports['APP_PORT'] ?? null) : null;
        if (is_int($appPort)) {
            $this->line("Start Laravel with <comment>php artisan serve --host=127.0.0.1 --port={$appPort}</comment>.");
        }
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

    private function providerOption(): ?string
    {
        $provider = $this->stringOption('provider');
        if ((bool) $this->option('compose')) {
            if ($provider !== null && strtolower(trim($provider)) !== 'compose') {
                throw new HarbourException(ErrorCode::InvalidInstallSelection, 'The --compose flag conflicts with --provider='.$provider.'.');
            }

            return 'compose';
        }

        if ($provider === null) {
            return null;
        }

        $normalized = strtolower(trim($provider));
        if (! in_array($normalized, InstallationSelection::PROVIDERS, true)) {
            throw new HarbourException(
                ErrorCode::InvalidInstallSelection,
                'Unsupported infrastructure provider ['.$provider.']. Choose one of: '.implode(', ', InstallationSelection::PROVIDERS).'.',
            );
        }

        return $normalized;
    }

    private function choiceString(mixed $choice, string $group): string
    {
        if (! is_string($choice)) {
            throw new HarbourException(ErrorCode::InvalidInstallSelection, "The interactive {$group} choice must be a string.");
        }

        return strtolower($choice);
    }
}
