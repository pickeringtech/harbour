<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Database;

final readonly class DatabaseConfiguration
{
    public function __construct(
        public string $driver,
        public ?string $host = null,
        public ?int $port = null,
        public ?string $database = null,
        public ?string $username = null,
        public ?string $password = null,
        public ?string $unixSocket = null,
        public string $charset = 'utf8mb4',
        public string $adminDatabase = '',
    ) {}

    /** @param array<string, mixed> $config */
    public static function fromLaravel(array $config): self
    {
        return new self(
            driver: self::string($config, 'driver'),
            host: self::nullableString($config, 'host'),
            port: isset($config['port']) && is_numeric($config['port']) ? (int) $config['port'] : null,
            database: self::nullableString($config, 'database'),
            username: self::nullableString($config, 'username'),
            password: self::nullableString($config, 'password'),
            unixSocket: self::nullableString($config, 'unix_socket'),
            charset: self::string($config, 'charset', 'utf8mb4'),
            adminDatabase: self::string($config, 'harbour_admin_database'),
        );
    }

    /** @param array<string, mixed> $config */
    private static function string(array $config, string $key, string $default = ''): string
    {
        $value = $config[$key] ?? null;

        return is_scalar($value) ? (string) $value : $default;
    }

    /** @param array<string, mixed> $config */
    private static function nullableString(array $config, string $key): ?string
    {
        $value = $config[$key] ?? null;

        return is_scalar($value) ? (string) $value : null;
    }

    public function fingerprint(): string
    {
        return hash('sha256', implode("\0", [
            $this->driver,
            $this->host ?? '',
            (string) ($this->port ?? ''),
            $this->unixSocket ?? '',
            $this->username ?? '',
        ]));
    }
}
