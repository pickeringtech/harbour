<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Installation;

use LogicException;

final class InstallationServiceCatalog
{
    /**
     * @return array<string, array{container: int, range: array{int, int}}>
     */
    public function portsFor(string $service): array
    {
        return match ($service) {
            'mysql', 'mariadb' => ['DB_PORT' => $this->port(3306)],
            'pgsql' => ['DB_PORT' => $this->port(5432)],
            'mongodb' => ['MONGODB_PORT' => $this->port(27017)],
            'redis', 'valkey' => ['REDIS_PORT' => $this->port(6379)],
            'memcached' => ['MEMCACHED_PORT' => $this->port(11211)],
            'meilisearch' => ['MEILISEARCH_PORT' => $this->port(7700)],
            'typesense' => ['TYPESENSE_PORT' => $this->port(8108)],
            'minio' => [
                'MINIO_PORT' => $this->port(9000),
                'MINIO_CONSOLE_PORT' => $this->port(8900),
            ],
            'rustfs' => [
                'RUSTFS_PORT' => $this->port(9000),
                'RUSTFS_CONSOLE_PORT' => $this->port(9001),
            ],
            'mailpit' => [
                'MAIL_PORT' => $this->port(1025),
                'MAILPIT_DASHBOARD_PORT' => $this->port(8025),
            ],
            'rabbitmq' => [
                'RABBITMQ_PORT' => $this->port(5672),
                'RABBITMQ_DASHBOARD_PORT' => $this->port(15672),
            ],
            'selenium' => ['SELENIUM_PORT' => $this->port(4444)],
            'soketi' => [
                'PUSHER_PORT' => $this->port(6001),
                'PUSHER_METRICS_PORT' => $this->port(9601),
            ],
            default => throw new LogicException("Unsupported installation service [{$service}]."),
        };
    }

    /**
     * @param  list<string>  $services
     * @return array<string, array{range: array{int, int}}>
     */
    public function portDefinitions(array $services): array
    {
        $definitions = [];

        foreach ($services as $service) {
            foreach ($this->portsFor($service) as $variable => $definition) {
                $definitions[$variable] = ['range' => $definition['range']];
            }
        }

        return $definitions;
    }

    public function primaryPortVariable(string $service): string
    {
        $variable = array_key_first($this->portsFor($service));

        if (! is_string($variable)) {
            throw new LogicException("Installation service [{$service}] has no port.");
        }

        return $variable;
    }

    /** @return array{container: int, range: array{int, int}} */
    private function port(int $container): array
    {
        return ['container' => $container, 'range' => [11000, 29999]];
    }
}
