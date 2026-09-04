<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Installation;

use Composer\InstalledVersions;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use PickeringTech\Harbour\Contracts\CommandRunner;
use PickeringTech\Harbour\Contracts\InstallationPreflight;
use PickeringTech\Harbour\Exceptions\ErrorCode;
use PickeringTech\Harbour\Exceptions\HarbourException;

final readonly class SystemInstallationPreflight implements InstallationPreflight
{
    /**
     * @param  null|list<string>  $extensions
     * @param  null|list<string>  $packages
     */
    public function __construct(
        private ConfigRepository $config,
        private CommandRunner $commands,
        private string $workspacePath,
        private ?array $extensions = null,
        private ?array $packages = null,
        private PhpExtensionGuide $extensionGuide = new PhpExtensionGuide,
    ) {}

    public function assertReady(InstallationSelection $selection): void
    {
        $missing = $this->requirements($selection);

        if ($missing === []) {
            return;
        }

        $details = [];
        foreach ($missing as $requirement) {
            $details[] = "{$requirement->name}\n  Needed for: {$requirement->purpose}\n  Resolve by: {$requirement->resolution}";
        }

        throw new HarbourException(
            ErrorCode::InstallRequirementsMissing,
            "The selected Harbour stack is missing required runtime capabilities:\n\n"
                .implode("\n\n", $details)
                ."\n\nHarbour configuration and workspace resources were not created.",
            [
                'missing' => array_map(
                    static fn (InstallationRequirement $requirement): array => $requirement->toArray(),
                    $missing,
                ),
                'php_binary' => PHP_BINARY,
                'retry_command' => $this->retryCommand($selection),
            ],
        );
    }

    public function requirements(InstallationSelection $selection): array
    {
        $missing = [];

        $this->databaseRequirements($selection, $missing);
        $this->cacheRequirements($selection, $missing);
        $this->serviceRequirements($selection, $missing);
        $this->composeRequirements($selection, $missing);

        return $this->unique($missing);
    }

    /** @param list<InstallationRequirement> $missing */
    private function databaseRequirements(InstallationSelection $selection, array &$missing): void
    {
        $requirement = match ($selection->database) {
            'sqlite' => ['pdo_sqlite', 'Laravel to use the selected SQLite database'],
            'mysql', 'mariadb' => ['pdo_mysql', 'Laravel to use the selected MySQL-compatible database'],
            'pgsql' => ['pdo_pgsql', 'Laravel to use the selected PostgreSQL database'],
            default => null,
        };

        if ($requirement !== null) {
            [$extension, $purpose] = $requirement;
            $this->requireExtension($extension, $purpose, $missing);
        }

        if ($selection->database === 'mongodb') {
            $this->requireExtension('mongodb', 'Laravel to connect to the selected MongoDB service', $missing);
            $this->requirePackage(
                'mongodb/laravel-mongodb',
                'Laravel to provide its MongoDB connection driver',
                'composer require mongodb/laravel-mongodb',
                $missing,
            );
        }
    }

    /** @param list<InstallationRequirement> $missing */
    private function cacheRequirements(InstallationSelection $selection, array &$missing): void
    {
        if (in_array($selection->cache, ['redis', 'valkey'], true)) {
            $client = $selection->redisClient === 'auto'
                ? $this->config->get('database.redis.client', 'phpredis')
                : $selection->redisClient;
            if ($client === 'predis') {
                $this->requirePackage(
                    'predis/predis',
                    'Laravel to use the configured Predis client',
                    'composer require predis/predis',
                    $missing,
                );
            } else {
                $this->requireExtension('redis', 'Laravel to use the configured PhpRedis client', $missing);
            }
        }

        if ($selection->cache === 'memcached') {
            $this->requireExtension('memcached', 'Laravel to use the selected Memcached cache store', $missing);
        }
    }

    /** @param list<InstallationRequirement> $missing */
    private function serviceRequirements(InstallationSelection $selection, array &$missing): void
    {
        foreach ($selection->additionalServices as $service) {
            match ($service) {
                'meilisearch' => $this->requirePackages([
                    ['laravel/scout', 'Laravel to configure searchable models for Meilisearch', 'composer require laravel/scout'],
                    ['meilisearch/meilisearch-php', 'Laravel Scout to communicate with Meilisearch', 'composer require meilisearch/meilisearch-php'],
                ], $missing),
                'typesense' => $this->requirePackages([
                    ['laravel/scout', 'Laravel to configure searchable models for Typesense', 'composer require laravel/scout'],
                    ['typesense/typesense-php', 'Laravel Scout to communicate with Typesense', 'composer require typesense/typesense-php'],
                ], $missing),
                'minio', 'rustfs' => $this->requirePackage(
                    'league/flysystem-aws-s3-v3',
                    'Laravel Filesystem to use the selected S3-compatible object store',
                    'composer require league/flysystem-aws-s3-v3',
                    $missing,
                ),
                'rabbitmq' => $this->requirePackage(
                    'vladimir-yuldashev/laravel-queue-rabbitmq',
                    'Laravel to provide the selected RabbitMQ queue driver',
                    'composer require vladimir-yuldashev/laravel-queue-rabbitmq',
                    $missing,
                ),
                'selenium' => $this->requirePackage(
                    'laravel/dusk',
                    'Laravel to drive the selected Selenium browser service',
                    'composer require --dev laravel/dusk',
                    $missing,
                ),
                'soketi' => $this->requirePackage(
                    'pusher/pusher-php-server',
                    'Laravel broadcasting to communicate with the selected Soketi service',
                    'composer require pusher/pusher-php-server',
                    $missing,
                ),
                default => null,
            };
        }
    }

    /** @param list<InstallationRequirement> $missing */
    private function composeRequirements(InstallationSelection $selection, array &$missing): void
    {
        if ($selection->provider !== 'compose') {
            return;
        }

        $docker = $this->commands->run(['docker', '--version'], $this->workspacePath);
        if (! $docker->successful()) {
            $missing[] = new InstallationRequirement(
                'executable:docker',
                'Docker CLI',
                'Harbour to provision the selected Docker Compose services',
                'Install Docker and ensure the docker executable is available on PATH.',
            );

            return;
        }

        $compose = $this->commands->run(['docker', 'compose', 'version'], $this->workspacePath);
        if (! $compose->successful()) {
            $missing[] = new InstallationRequirement(
                'plugin:docker-compose',
                'Docker Compose v2 plugin',
                'Harbour to generate and manage the selected Compose project',
                'Install Docker Compose v2 and verify that docker compose version succeeds.',
            );
        }
    }

    /** @param list<InstallationRequirement> $missing */
    private function requireExtension(string $extension, string $purpose, array &$missing): void
    {
        if ($this->hasExtension($extension)) {
            return;
        }

        $missing[] = new InstallationRequirement(
            'extension:'.$extension,
            "PHP extension {$extension}",
            $purpose,
            rtrim($this->extensionGuide->resolution($extension), '.').'.',
        );
    }

    /** @param list<InstallationRequirement> $missing */
    private function requirePackage(string $package, string $purpose, string $resolution, array &$missing): void
    {
        if ($this->hasPackage($package)) {
            return;
        }

        $missing[] = new InstallationRequirement('package:'.$package, "Composer package {$package}", $purpose, $resolution.'.');
    }

    /**
     * @param  list<array{string, string, string}>  $requirements
     * @param  list<InstallationRequirement>  $missing
     */
    private function requirePackages(array $requirements, array &$missing): void
    {
        foreach ($requirements as [$package, $purpose, $resolution]) {
            $this->requirePackage($package, $purpose, $resolution, $missing);
        }
    }

    private function hasExtension(string $extension): bool
    {
        $extensions = $this->extensions ?? get_loaded_extensions();

        return in_array(strtolower($extension), array_map('strtolower', $extensions), true);
    }

    private function hasPackage(string $package): bool
    {
        if ($this->packages !== null) {
            return in_array(strtolower($package), array_map('strtolower', $this->packages), true);
        }

        $installed = $this->workspacePath.'/vendor/composer/installed.json';
        if (is_file($installed) && ! is_link($installed)) {
            $contents = file_get_contents($installed);
            $decoded = is_string($contents) ? json_decode($contents, true) : null;
            $sets = is_array($decoded) && array_is_list($decoded) ? $decoded : [$decoded];
            foreach ($sets as $set) {
                $packages = is_array($set) ? ($set['packages'] ?? null) : null;
                if (! is_array($packages)) {
                    continue;
                }
                foreach ($packages as $installedPackage) {
                    $name = is_array($installedPackage) ? ($installedPackage['name'] ?? null) : null;
                    if (is_string($name) && strtolower($name) === strtolower($package)) {
                        return true;
                    }
                }
            }
        }

        return class_exists(InstalledVersions::class) && InstalledVersions::isInstalled($package);
    }

    /**
     * @param  list<InstallationRequirement>  $requirements
     * @return list<InstallationRequirement>
     */
    private function unique(array $requirements): array
    {
        $unique = [];
        foreach ($requirements as $requirement) {
            $unique[$requirement->id] ??= $requirement;
        }

        return array_values($unique);
    }

    private function retryCommand(InstallationSelection $selection): string
    {
        $services = $selection->additionalServices === [] ? 'none' : implode(',', $selection->additionalServices);

        return 'php artisan workspace:install'
            .' --database='.$selection->database
            .' --cache='.$selection->cache
            .' --mail='.$selection->mail
            .' --with='.$services
            .' --provider='.$selection->provider
            .' --redis-client='.$selection->redisClient;
    }
}
