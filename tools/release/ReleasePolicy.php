<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Release;

final class ReleasePolicy
{
    /** @var array<string, string> */
    public const LEGACY_RELEASES = [
        'v0.0.1' => 'b29047d0a593fe52221751af54761009b31b194f',
        'v0.0.2' => '46d62be6198a8f6aafe1564b7995484e0e57c119',
        'v0.0.3' => 'b40c1b5fb0154cc5907564dc218463cab466846f',
    ];

    /** @var list<string> */
    public const REQUIRED_CHECKS = [
        'Coverage',
        'Dependency advisories',
        'Docker / Compose ownership',
        'Fresh Laravel install',
        'Mutation testing',
        'PHP 8.4 / quality',
        'PHP 8.5 / quality',
        'PHP security analysis',
        'PostgreSQL / MySQL / Redis',
        'Property / fuzz testing',
        'README / Mermaid',
        'Two-worktree acceptance',
    ];

    public static function isLegacy(ReleaseEntry $entry): bool
    {
        return (self::LEGACY_RELEASES[$entry->version] ?? null) === $entry->commit;
    }

    /** @param array<string, string> $actual */
    public static function assertRequiredChecks(ReleaseEntry $entry, array $actual): void
    {
        $failures = [];

        foreach (self::REQUIRED_CHECKS as $check) {
            $conclusion = $actual[$check] ?? 'missing';
            if ($conclusion !== 'success') {
                $failures[] = "{$check} ({$conclusion})";
            }
        }

        if ($failures !== []) {
            throw new ReleaseException("Required checks are not successful for {$entry->version}: ".implode(', ', $failures).'.');
        }
    }
}
