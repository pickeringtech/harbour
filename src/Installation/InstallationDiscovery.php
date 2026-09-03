<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Installation;

final readonly class InstallationDiscovery
{
    /**
     * @param  list<string>  $sources
     * @param  array<string, int>  $servicePorts
     * @param  list<string>  $environmentVariables
     * @param  list<string>  $localServices
     */
    public function __construct(
        public InstallationSelection $selection,
        public bool $detected,
        public array $sources = [],
        public array $servicePorts = [],
        public array $environmentVariables = [],
        public array $localServices = [],
    ) {}

    public static function explicit(InstallationSelection $selection): self
    {
        return new self($selection, false);
    }

    /** @return array{detected: bool, sources: list<string>} */
    public function metadata(): array
    {
        return [
            'detected' => $this->detected,
            'sources' => $this->sources,
        ];
    }

    public function port(string $service, int $default): int
    {
        return $this->servicePorts[$service] ?? $default;
    }

    public function withSelection(InstallationSelection $selection): self
    {
        $ports = $this->servicePorts;
        $variables = $this->environmentVariables;

        if ($selection->database !== $this->selection->database) {
            $this->removePorts($ports, ['mysql', 'mariadb', 'pgsql', 'mongodb']);
            $variables = $this->withoutVariables($variables, ['DB_HOST', 'DB_PORT', 'DB_USERNAME', 'DB_PASSWORD', 'MONGODB_URI', 'MONGODB_PORT']);
        }
        if ($selection->cache !== $this->selection->cache) {
            $this->removePorts($ports, ['redis', 'valkey', 'memcached']);
            $variables = $this->withoutVariables($variables, ['REDIS_HOST', 'REDIS_PORT', 'REDIS_PASSWORD', 'MEMCACHED_HOST', 'MEMCACHED_PORT']);
        }
        if ($selection->mail !== $this->selection->mail) {
            $this->removePorts($ports, ['mailpit', 'mailpit-dashboard']);
            $variables = $this->withoutVariables($variables, ['MAIL_HOST', 'MAIL_PORT', 'MAIL_USERNAME', 'MAIL_PASSWORD', 'MAILPIT_URL']);
        }

        $changedAdditional = array_values(array_unique(array_merge(
            array_diff($selection->additionalServices, $this->selection->additionalServices),
            array_diff($this->selection->additionalServices, $selection->additionalServices),
        )));
        foreach ($changedAdditional as $service) {
            unset($ports[$service]);
            $variables = $this->withoutVariables($variables, self::serviceVariables($service));
        }

        $selectedServices = $selection->services();
        $localServices = array_values(array_filter(
            $this->localServices,
            static fn (string $service): bool => in_array($service, $selectedServices, true),
        ));

        return new self($selection, $this->detected, $this->sources, $ports, $variables, $localServices);
    }

    public function withManualSelection(InstallationSelection $selection): self
    {
        return self::explicit($selection);
    }

    public function templateValue(string $variable, string $fallback): string
    {
        return in_array($variable, $this->environmentVariables, true) ? '${'.$variable.'}' : $fallback;
    }

    public function hasEnvironmentVariable(string $variable): bool
    {
        return in_array($variable, $this->environmentVariables, true);
    }

    public function serviceValue(string $variable, string $service, string $fallback): string
    {
        if (in_array($service, $this->localServices, true)) {
            return $fallback;
        }

        return $this->templateValue($variable, $fallback);
    }

    public function serviceHost(string $variable, string $service, string $fallback = '127.0.0.1'): string
    {
        return $this->serviceValue($variable, $service, $fallback);
    }

    /**
     * @param  array<string, int>  $ports
     * @param  list<string>  $services
     */
    private function removePorts(array &$ports, array $services): void
    {
        foreach ($services as $service) {
            unset($ports[$service]);
        }
    }

    /**
     * @param  list<string>  $variables
     * @param  list<string>  $removed
     * @return list<string>
     */
    private function withoutVariables(array $variables, array $removed): array
    {
        return array_values(array_filter(
            $variables,
            static fn (string $variable): bool => ! in_array($variable, $removed, true),
        ));
    }

    /** @return list<string> */
    private static function serviceVariables(string $service): array
    {
        return match ($service) {
            'meilisearch' => ['MEILISEARCH_HOST'],
            'typesense' => ['TYPESENSE_HOST', 'TYPESENSE_PORT', 'TYPESENSE_PROTOCOL', 'TYPESENSE_API_KEY'],
            'minio' => ['MINIO_ENDPOINT', 'MINIO_ACCESS_KEY_ID', 'MINIO_SECRET_ACCESS_KEY'],
            'rustfs' => ['RUSTFS_ENDPOINT', 'RUSTFS_ACCESS_KEY_ID', 'RUSTFS_SECRET_ACCESS_KEY'],
            'rabbitmq' => ['RABBITMQ_HOST', 'RABBITMQ_PORT'],
            'selenium' => ['DUSK_DRIVER_URL'],
            'soketi' => ['PUSHER_APP_ID', 'PUSHER_APP_KEY', 'PUSHER_APP_SECRET', 'PUSHER_HOST', 'PUSHER_PORT', 'PUSHER_SCHEME'],
            default => [],
        };
    }
}
