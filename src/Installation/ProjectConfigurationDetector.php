<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Installation;

use PickeringTech\Harbour\Environment\EnvironmentFile;
use PickeringTech\Harbour\Support\WorkspacePath;

final readonly class ProjectConfigurationDetector
{
    /** @var list<string> */
    private const COMPOSE_FILES = ['compose.yaml', 'compose.yml', 'docker-compose.yaml', 'docker-compose.yml'];

    /**
     * Laravel's normal connection selectors describe how the application behaves;
     * their mere presence is not evidence that every service fragment containing
     * the same key is in use.
     *
     * @var list<string>
     */
    private const NON_SERVICE_SIGNAL_KEYS = ['BROADCAST_CONNECTION', 'QUEUE_CONNECTION'];

    public function __construct(
        private string $workspacePath,
        private EnvironmentFile $environment = new EnvironmentFile,
        private InstallationServiceCatalog $services = new InstallationServiceCatalog,
    ) {}

    public function discover(): InstallationDiscovery
    {
        $environment = $this->environment();
        $services = [];
        $sources = [];
        $ports = $this->environmentPorts($environment);
        $localServices = [];

        foreach (self::COMPOSE_FILES as $filename) {
            $path = $this->path($filename);
            if (! is_file($path) || is_link($path)) {
                continue;
            }
            $composeServices = $this->yamlServices($this->contents($path));
            $recognized = $this->recognizedServices($composeServices);
            if ($recognized === []) {
                continue;
            }
            $services = [...$services, ...$recognized];
            $localServices = [...$localServices, ...$recognized];
            $sources[] = (in_array('laravel.test', $composeServices, true) || $this->usesSail() ? 'sail:' : 'compose:').$filename;
            $ports = [...$ports, ...$this->composePorts($recognized, $environment)];
            break;
        }

        $herd = $this->path('herd.yml');
        if (is_file($herd) && ! is_link($herd)) {
            $herdServices = $this->recognizedServices($this->yamlServices($this->contents($herd)));
            if ($herdServices !== []) {
                $services = [...$services, ...$herdServices];
                $localServices = [...$localServices, ...$herdServices];
                $sources[] = 'herd:herd.yml';
                $ports = [...$ports, ...$this->herdPorts($this->contents($herd), $herdServices, $environment)];
            }
        }

        $environmentServices = $this->environmentServices($environment);
        if ($environmentServices !== [] || $this->hasEnvironmentSignals($environment)) {
            $services = [...$services, ...$environmentServices];
            $sources[] = is_file($this->path('.env')) ? 'environment:.env' : 'environment:.env.example';
        }

        $services = array_values(array_unique($services));
        $detected = $sources !== [];
        $database = $this->database($environment, $services, $detected);
        $cache = $this->cache($environment, $services, $detected);
        $mail = $this->mail($environment, $services, $detected);
        $additional = array_values(array_intersect($services, $this->services->namesFor('additional')));

        $redisClient = strtolower($environment['REDIS_CLIENT'] ?? 'auto');
        if (! in_array($redisClient, InstallationSelection::REDIS_CLIENTS, true)) {
            $redisClient = 'auto';
        }

        return new InstallationDiscovery(
            new InstallationSelection($database, $cache, $mail, $additional, 'shared', $redisClient),
            $detected,
            array_values(array_unique($sources)),
            $ports,
            array_keys($environment),
            array_values(array_unique($localServices)),
        );
    }

    /** @return array<string, string> */
    private function environment(): array
    {
        $values = [];
        foreach (['.env.example', '.env'] as $filename) {
            $path = $this->path($filename);
            if (is_file($path) && ! is_link($path)) {
                $values = [...$values, ...$this->environment->parse($this->contents($path))];
            }
        }

        return $values;
    }

    /** @return list<string> */
    private function yamlServices(string $yaml): array
    {
        $services = [];
        $servicesIndent = null;
        $entryIndent = null;

        foreach (preg_split('/\R/', $yaml) ?: [] as $line) {
            if ($servicesIndent === null) {
                if (preg_match('/^(\s*)services\s*:\s*(?:#.*)?$/', $line, $match) === 1) {
                    $servicesIndent = strlen($match[1]);
                }

                continue;
            }

            if (trim($line) === '' || preg_match('/^\s*#/', $line) === 1) {
                continue;
            }
            preg_match('/^(\s*)/', $line, $indentMatch);
            $indent = strlen($indentMatch[1] ?? '');
            if ($indent <= $servicesIndent) {
                break;
            }
            if (preg_match('/^\s*["\']?([A-Za-z0-9._-]+)["\']?\s*:/', $line, $match) !== 1) {
                continue;
            }
            $entryIndent ??= $indent;
            if ($indent === $entryIndent) {
                $services[] = strtolower($match[1]);
            }
        }

        return array_values(array_unique($services));
    }

    /**
     * @param  list<string>  $services
     * @return list<string>
     */
    private function recognizedServices(array $services): array
    {
        $recognized = [];
        foreach ($services as $service) {
            $normalized = $this->services->normalize($service);
            if (in_array($normalized, $this->services->names(), true)) {
                $recognized[] = $normalized;
            }
        }

        return array_values(array_unique($recognized));
    }

    /**
     * @param  list<string>  $services
     * @param  array<string, string>  $environment
     * @return array<string, int>
     */
    private function composePorts(array $services, array $environment): array
    {
        $ports = [];
        foreach ($services as $service) {
            $definition = $this->services->get($service)->ports;
            $primary = $definition[$this->services->get($service)->primaryPortVariable()];
            $port = $this->portValue($environment[$primary['forward']] ?? null);
            $ports[$service] = $port ?? $primary['container'];
        }
        if (in_array('mailpit', $services, true)) {
            $dashboard = $this->services->get('mailpit')->ports['MAILPIT_DASHBOARD_PORT'];
            $ports['mailpit-dashboard'] = $this->portValue($environment[$dashboard['forward']] ?? null) ?? $dashboard['container'];
        }

        return $ports;
    }

    /**
     * @param  array<string, string>  $environment
     * @return array<string, int>
     */
    private function environmentPorts(array $environment): array
    {
        $ports = [];
        $database = $this->normalizedDatabase($environment['DB_CONNECTION'] ?? null);
        if (in_array($database, ['mysql', 'mariadb', 'pgsql'], true)) {
            $this->putPort($ports, $database, $this->portValue($environment['DB_PORT'] ?? null));
        }
        if ($database === 'mongodb') {
            $this->putPort($ports, 'mongodb', $this->portValue($environment['MONGODB_PORT'] ?? null)
                ?? $this->urlPort($environment['MONGODB_URI'] ?? null));
        }

        foreach (['CACHE_STORE', 'CACHE_DRIVER', 'SESSION_DRIVER', 'QUEUE_CONNECTION'] as $key) {
            $service = strtolower($environment[$key] ?? '');
            if (in_array($service, ['redis', 'valkey'], true)) {
                $this->putPort($ports, $service, $this->portValue($environment['REDIS_PORT'] ?? null));
            }
            if ($service === 'memcached') {
                $this->putPort($ports, 'memcached', $this->portValue($environment['MEMCACHED_PORT'] ?? null));
            }
        }

        $this->putPort($ports, 'mailpit', $this->portValue($environment['MAIL_PORT'] ?? null));
        $this->putPort($ports, 'mailpit-dashboard', $this->urlPort($environment['MAILPIT_URL'] ?? null));
        $this->putPort($ports, 'meilisearch', $this->urlPort($environment['MEILISEARCH_HOST'] ?? null));
        $this->putPort($ports, 'typesense', $this->portValue($environment['TYPESENSE_PORT'] ?? null));
        $this->putPort($ports, 'minio', $this->urlPort($environment['MINIO_ENDPOINT'] ?? null));
        $this->putPort($ports, 'rustfs', $this->urlPort($environment['RUSTFS_ENDPOINT'] ?? null));
        $this->putPort($ports, 'rabbitmq', $this->portValue($environment['RABBITMQ_PORT'] ?? null));
        $this->putPort($ports, 'selenium', $this->urlPort($environment['DUSK_DRIVER_URL'] ?? null));
        $this->putPort($ports, 'soketi', $this->portValue($environment['PUSHER_PORT'] ?? null));

        return $ports;
    }

    /** @param array<string, int> $ports */
    private function putPort(array &$ports, string $service, ?int $port): void
    {
        if ($port !== null) {
            $ports[$service] = $port;
        }
    }

    private function urlPort(?string $value): ?int
    {
        if ($value === null || str_contains($value, '${')) {
            return null;
        }
        $port = parse_url($value, PHP_URL_PORT);

        return is_int($port) ? $port : null;
    }

    /**
     * @param  list<string>  $services
     * @param  array<string, string>  $environment
     * @return array<string, int>
     */
    private function herdPorts(string $yaml, array $services, array $environment): array
    {
        $ports = [];
        foreach ($services as $service) {
            $value = null;
            foreach ($this->serviceAliases($service) as $alias) {
                $pattern = '/^\s{2,}["\']?'.preg_quote($alias, '/').'["\']?\s*:\s*\R(?:(?!^\s{0,3}\S).*\R)*?^\s+port\s*:\s*["\']?([^\s"\']+)/mi';
                if (preg_match($pattern, $yaml, $match) === 1) {
                    $value = $match[1];
                    break;
                }
            }
            if (is_string($value) && preg_match('/^\$\{([A-Za-z_][A-Za-z0-9_]*)\}$/', $value, $variable) === 1) {
                $value = $environment[$variable[1]] ?? null;
            }
            $ports[$service] = $this->portValue($value) ?? $this->services->get($service)->defaultPort();
        }

        return $ports;
    }

    /** @return list<string> */
    private function serviceAliases(string $service): array
    {
        $definition = $this->services->get($service);

        return [$definition->name, ...$definition->aliases];
    }

    /**
     * @param  array<string, string>  $environment
     * @return list<string>
     */
    private function environmentServices(array $environment): array
    {
        $services = [];
        $connection = $this->normalizedDatabase($environment['DB_CONNECTION'] ?? null);
        if (in_array($connection, ['mysql', 'mariadb', 'pgsql', 'mongodb'], true)) {
            $services[] = $connection;
        }

        foreach (['CACHE_STORE', 'CACHE_DRIVER', 'SESSION_DRIVER', 'QUEUE_CONNECTION'] as $key) {
            $value = strtolower($environment[$key] ?? '');
            if (in_array($value, ['redis', 'valkey', 'memcached'], true)) {
                $services[] = $value;
            }
        }
        if (($environment['MAIL_MAILER'] ?? null) === 'smtp' && str_contains(strtolower($environment['MAIL_HOST'] ?? ''), 'mailpit')) {
            $services[] = 'mailpit';
        }

        foreach ($this->services->all() as $service) {
            if ($service->group !== 'additional') {
                continue;
            }
            foreach ($service->environmentKeys as $key) {
                if (in_array($key, self::NON_SERVICE_SIGNAL_KEYS, true)) {
                    continue;
                }
                if (isset($environment[$key]) && $environment[$key] !== '') {
                    $services[] = $service->name;
                    break;
                }
            }
        }

        return array_values(array_unique($services));
    }

    /** @param array<string, string> $environment */
    private function hasEnvironmentSignals(array $environment): bool
    {
        foreach (['DB_CONNECTION', 'CACHE_STORE', 'CACHE_DRIVER', 'SESSION_DRIVER', 'QUEUE_CONNECTION', 'MAIL_MAILER'] as $key) {
            if (isset($environment[$key]) && $environment[$key] !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, string>  $environment
     * @param  list<string>  $services
     */
    private function database(array $environment, array $services, bool $detected): string
    {
        $configured = $this->normalizedDatabase($environment['DB_CONNECTION'] ?? null);
        if (in_array($configured, InstallationSelection::databases(), true)) {
            return $configured;
        }
        foreach (['mysql', 'pgsql', 'mariadb', 'mongodb'] as $service) {
            if (in_array($service, $services, true)) {
                return $service;
            }
        }

        return $detected ? 'none' : 'sqlite';
    }

    /**
     * @param  array<string, string>  $environment
     * @param  list<string>  $services
     */
    private function cache(array $environment, array $services, bool $detected): string
    {
        foreach (['CACHE_STORE', 'CACHE_DRIVER'] as $key) {
            $configured = strtolower($environment[$key] ?? '');
            if ($configured === 'array') {
                return 'none';
            }
            if (in_array($configured, InstallationSelection::caches(), true)) {
                return $configured;
            }
        }
        foreach (['redis', 'valkey', 'memcached'] as $service) {
            if (in_array($service, $services, true)) {
                return $service;
            }
        }

        return $detected ? 'none' : 'file';
    }

    /**
     * @param  array<string, string>  $environment
     * @param  list<string>  $services
     */
    private function mail(array $environment, array $services, bool $detected): string
    {
        $configured = strtolower($environment['MAIL_MAILER'] ?? '');
        if ($configured === 'log') {
            return 'log';
        }
        if ($configured === 'array') {
            return 'none';
        }
        if ($configured === 'smtp' && (in_array('mailpit', $services, true) || str_contains(strtolower($environment['MAIL_HOST'] ?? ''), 'mailpit'))) {
            return 'mailpit';
        }
        if (in_array('mailpit', $services, true)) {
            return 'mailpit';
        }

        return $detected ? 'none' : 'log';
    }

    private function normalizedDatabase(?string $database): ?string
    {
        if ($database === null) {
            return null;
        }

        return $this->services->normalize(strtolower(trim($database)));
    }

    private function usesSail(): bool
    {
        $composer = $this->path('composer.json');
        if (! is_file($composer) || is_link($composer)) {
            return false;
        }
        $decoded = json_decode($this->contents($composer), true);
        if (! is_array($decoded)) {
            return false;
        }
        $require = $decoded['require'] ?? null;
        $requireDev = $decoded['require-dev'] ?? null;

        return (is_array($require) && array_key_exists('laravel/sail', $require))
            || (is_array($requireDev) && array_key_exists('laravel/sail', $requireDev));
    }

    private function portValue(?string $value): ?int
    {
        if ($value === null || preg_match('/^[0-9]{1,5}$/', trim($value)) !== 1) {
            return null;
        }
        $port = (int) $value;

        return $port >= 1 && $port <= 65535 ? $port : null;
    }

    private function contents(string $path): string
    {
        $contents = file_get_contents($path);

        return $contents === false ? '' : $contents;
    }

    private function path(string $relative): string
    {
        $path = rtrim($this->workspacePath, DIRECTORY_SEPARATOR).'/'.$relative;
        WorkspacePath::assertSafe($this->workspacePath, $path);

        return $path;
    }
}
