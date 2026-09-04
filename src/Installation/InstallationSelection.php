<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Installation;

use PickeringTech\Harbour\Exceptions\ErrorCode;
use PickeringTech\Harbour\Exceptions\HarbourException;

final readonly class InstallationSelection
{
    /** @var list<string> */
    public const PROVIDERS = ['shared', 'compose'];

    /** @param list<string> $additionalServices */
    public function __construct(
        public string $database,
        public string $cache,
        public string $mail,
        public array $additionalServices = [],
        public string $provider = 'shared',
    ) {
        if (! in_array($database, self::databases(), true)) {
            throw self::invalid('database', $database, self::databases());
        }
        if (! in_array($cache, self::caches(), true)) {
            throw self::invalid('cache', $cache, self::caches());
        }
        if (! in_array($mail, self::mailers(), true)) {
            throw self::invalid('mail', $mail, self::mailers());
        }

        foreach ($additionalServices as $service) {
            if (! in_array($service, self::additionalServices(), true)) {
                throw self::invalid('additional service', $service, self::additionalServices());
            }
        }
        if (! in_array($provider, self::PROVIDERS, true)) {
            throw self::invalid('infrastructure provider', $provider, self::PROVIDERS);
        }
        if ($provider === 'compose' && $this->services() === []) {
            throw new HarbourException(
                ErrorCode::InvalidInstallSelection,
                'The Compose provider requires at least one service-backed component.',
            );
        }
    }

    public static function fromOptions(?string $database, ?string $cache, ?string $mail, ?string $with, ?string $provider = null): self
    {
        $withServices = self::parseWith($with);
        $catalog = new InstallationServiceCatalog;
        $withDatabase = self::onlyOne($withServices, $catalog->namesFor('database'), 'database');
        $withCache = self::onlyOne($withServices, $catalog->namesFor('cache'), 'cache service');
        $withMail = self::onlyOne($withServices, $catalog->namesFor('mail'), 'mail service');

        $selectedDatabase = self::normalize($database, 'database', self::databases(), $catalog)
            ?? $withDatabase
            ?? 'none';
        $selectedCache = self::normalize($cache, 'cache', self::caches(), $catalog)
            ?? $withCache
            ?? 'none';
        $selectedMail = self::normalize($mail, 'mail', self::mailers(), $catalog)
            ?? $withMail
            ?? 'none';

        self::assertCompatible('database', $selectedDatabase, $withDatabase);
        self::assertCompatible('cache', $selectedCache, $withCache);
        self::assertCompatible('mail', $selectedMail, $withMail);

        $additional = array_values(array_intersect($withServices, self::additionalServices()));

        $selectedProvider = self::normalize($provider, 'infrastructure provider', self::PROVIDERS) ?? 'shared';

        return new self($selectedDatabase, $selectedCache, $selectedMail, array_values(array_unique($additional)), $selectedProvider);
    }

    /** @return list<string> */
    public function services(): array
    {
        $services = [];
        foreach ([$this->database, $this->cache, $this->mail, ...$this->additionalServices] as $service) {
            if (in_array($service, (new InstallationServiceCatalog)->names(), true) && ! in_array($service, $services, true)) {
                $services[] = $service;
            }
        }

        return $services;
    }

    public function withProvider(string $provider): self
    {
        return new self($this->database, $this->cache, $this->mail, $this->additionalServices, $provider);
    }

    /** @return array{database: string, cache: string, mail: string, services: list<string>, provider: string} */
    public function toArray(): array
    {
        return [
            'database' => $this->database,
            'cache' => $this->cache,
            'mail' => $this->mail,
            'services' => $this->services(),
            'provider' => $this->provider,
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
        $normalizedServices = [];
        foreach ($services as $service) {
            $service = (new InstallationServiceCatalog)->normalize($service);
            if (! in_array($service, (new InstallationServiceCatalog)->names(), true)) {
                throw self::invalid('service', $service, (new InstallationServiceCatalog)->names());
            }
            $normalizedServices[] = $service;
        }

        return array_values(array_unique($normalizedServices));
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
     */
    private static function normalize(?string $value, string $group, array $allowed, ?InstallationServiceCatalog $catalog = null): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = strtolower(trim($value));
        $normalized = $catalog?->normalize($normalized) ?? $normalized;
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

    /** @return list<string> */
    public static function databases(): array
    {
        return ['none', 'sqlite', ...(new InstallationServiceCatalog)->namesFor('database')];
    }

    /** @return list<string> */
    public static function caches(): array
    {
        return ['none', 'file', 'database', ...(new InstallationServiceCatalog)->namesFor('cache')];
    }

    /** @return list<string> */
    public static function mailers(): array
    {
        return ['none', 'log', ...(new InstallationServiceCatalog)->namesFor('mail')];
    }

    /** @return list<string> */
    public static function additionalServices(): array
    {
        return (new InstallationServiceCatalog)->namesFor('additional');
    }
}
