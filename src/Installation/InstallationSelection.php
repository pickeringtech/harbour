<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Installation;

use PickeringTech\Harbour\Exceptions\ErrorCode;
use PickeringTech\Harbour\Exceptions\HarbourException;

final readonly class InstallationSelection
{
    /** @var list<string> */
    public const SAIL_SERVICES = [
        'mysql',
        'pgsql',
        'mariadb',
        'mongodb',
        'redis',
        'valkey',
        'memcached',
        'meilisearch',
        'typesense',
        'minio',
        'rustfs',
        'mailpit',
        'rabbitmq',
        'selenium',
        'soketi',
    ];

    /** @var list<string> */
    public const DATABASES = ['none', 'sqlite', 'mysql', 'mariadb', 'pgsql', 'mongodb'];

    /** @var list<string> */
    public const CACHES = ['none', 'file', 'database', 'redis', 'valkey', 'memcached'];

    /** @var list<string> */
    public const MAILERS = ['none', 'log', 'mailpit'];

    /** @var list<string> */
    public const ADDITIONAL_SERVICES = ['meilisearch', 'typesense', 'minio', 'rustfs', 'rabbitmq', 'selenium', 'soketi'];

    /** @param list<string> $additionalServices */
    public function __construct(
        public string $database,
        public string $cache,
        public string $mail,
        public array $additionalServices = [],
    ) {
        if (! in_array($database, self::DATABASES, true)) {
            throw self::invalid('database', $database, self::DATABASES);
        }
        if (! in_array($cache, self::CACHES, true)) {
            throw self::invalid('cache', $cache, self::CACHES);
        }
        if (! in_array($mail, self::MAILERS, true)) {
            throw self::invalid('mail', $mail, self::MAILERS);
        }

        foreach ($additionalServices as $service) {
            if (! in_array($service, self::ADDITIONAL_SERVICES, true)) {
                throw self::invalid('additional service', $service, self::ADDITIONAL_SERVICES);
            }
        }
    }

    public static function fromOptions(?string $database, ?string $cache, ?string $mail, ?string $with): self
    {
        $withServices = self::parseWith($with);
        $withDatabase = self::onlyOne($withServices, ['mysql', 'pgsql', 'mariadb', 'mongodb'], 'database');
        $withCache = self::onlyOne($withServices, ['redis', 'valkey', 'memcached'], 'cache service');
        $withMail = self::onlyOne($withServices, ['mailpit'], 'mail service');

        $selectedDatabase = self::normalize($database, 'database', self::DATABASES, ['postgres' => 'pgsql', 'postgresql' => 'pgsql'])
            ?? $withDatabase
            ?? 'none';
        $selectedCache = self::normalize($cache, 'cache', self::CACHES, ['memcache' => 'memcached'])
            ?? $withCache
            ?? 'none';
        $selectedMail = self::normalize($mail, 'mail', self::MAILERS)
            ?? $withMail
            ?? 'none';

        self::assertCompatible('database', $selectedDatabase, $withDatabase);
        self::assertCompatible('cache', $selectedCache, $withCache);
        self::assertCompatible('mail', $selectedMail, $withMail);

        $additional = array_values(array_intersect($withServices, self::ADDITIONAL_SERVICES));

        return new self($selectedDatabase, $selectedCache, $selectedMail, array_values(array_unique($additional)));
    }

    /** @return list<string> */
    public function services(): array
    {
        $services = [];
        foreach ([$this->database, $this->cache, $this->mail, ...$this->additionalServices] as $service) {
            if (in_array($service, self::SAIL_SERVICES, true) && ! in_array($service, $services, true)) {
                $services[] = $service;
            }
        }

        return $services;
    }

    /** @return array{database: string, cache: string, mail: string, services: list<string>, provider: string} */
    public function toArray(): array
    {
        return [
            'database' => $this->database,
            'cache' => $this->cache,
            'mail' => $this->mail,
            'services' => $this->services(),
            'provider' => 'shared',
        ];
    }

    /** @return list<string> */
    private static function parseWith(?string $with): array
    {
        if ($with === null) {
            return [];
        }

        $normalized = strtolower(trim($with));
        if ($normalized === 'none') {
            return [];
        }
        if ($normalized === '') {
            throw new HarbourException(ErrorCode::InvalidInstallSelection, 'The --with option requires a comma-separated service list or "none".');
        }

        $services = array_map('trim', explode(',', $normalized));
        if (in_array('', $services, true) || in_array('none', $services, true)) {
            throw new HarbourException(ErrorCode::InvalidInstallSelection, 'The --with value "none" cannot be combined with services.');
        }
        foreach ($services as $service) {
            if (! in_array($service, self::SAIL_SERVICES, true)) {
                throw self::invalid('service', $service, self::SAIL_SERVICES);
            }
        }

        return array_values(array_unique($services));
    }

    /**
     * @param  list<string>  $selected
     * @param  list<string>  $candidates
     */
    private static function onlyOne(array $selected, array $candidates, string $group): ?string
    {
        $matches = array_values(array_intersect($selected, $candidates));
        if (count($matches) > 1) {
            throw new HarbourException(
                ErrorCode::InvalidInstallSelection,
                'Choose only one '.$group.'; received ['.implode(', ', $matches).'].',
            );
        }

        return $matches[0] ?? null;
    }

    /**
     * @param  list<string>  $allowed
     * @param  array<string, string>  $aliases
     */
    private static function normalize(?string $value, string $group, array $allowed, array $aliases = []): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = strtolower(trim($value));
        $normalized = $aliases[$normalized] ?? $normalized;
        if (! in_array($normalized, $allowed, true)) {
            throw self::invalid($group, $value, $allowed);
        }

        return $normalized;
    }

    private static function assertCompatible(string $group, string $selected, ?string $fromWith): void
    {
        if ($fromWith !== null && $selected !== $fromWith) {
            throw new HarbourException(
                ErrorCode::InvalidInstallSelection,
                "Conflicting {$group} selections [{$selected}] and [{$fromWith}].",
            );
        }
    }

    /** @param list<string> $allowed */
    private static function invalid(string $group, string $value, array $allowed): HarbourException
    {
        return new HarbourException(
            ErrorCode::InvalidInstallSelection,
            "Unsupported {$group} [{$value}]. Choose one of: ".implode(', ', $allowed).'.',
        );
    }
}
