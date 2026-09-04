<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Release;

use JsonException;

final readonly class Manifest
{
    /** @param list<ReleaseEntry> $entries */
    private function __construct(public array $entries) {}

    public static function fromFile(string $path): self
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw new ReleaseException("Release manifest [{$path}] cannot be read.");
        }

        return self::fromJson($contents);
    }

    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ReleaseException('Release manifest is not valid JSON: '.$exception->getMessage(), previous: $exception);
        }

        if (! is_array($data) || array_is_list($data) || self::keys($data) !== ['releases', 'schema']) {
            throw new ReleaseException('Release manifest must contain exactly schema and releases.');
        }
        if (($data['schema'] ?? null) !== 1) {
            throw new ReleaseException('Release manifest schema must be the integer 1.');
        }
        if (! is_array($data['releases']) || ! array_is_list($data['releases'])) {
            throw new ReleaseException('Release manifest releases must be a JSON array.');
        }

        $entries = [];
        $versions = [];
        $previous = null;

        foreach ($data['releases'] as $index => $release) {
            if (! is_array($release) || array_is_list($release) || self::keys($release) !== ['commit', 'version']) {
                throw new ReleaseException("Release entry {$index} must contain exactly version and commit.");
            }

            $version = $release['version'] ?? null;
            $commit = $release['commit'] ?? null;

            if (! is_string($version) || preg_match('/^v(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)$/D', $version) !== 1) {
                throw new ReleaseException("Release entry {$index} has an invalid version; expected strict vMAJOR.MINOR.PATCH SemVer.");
            }
            if (! is_string($commit) || preg_match('/^[0-9a-f]{40}$/D', $commit) !== 1) {
                throw new ReleaseException("Release entry {$index} commit must be a full lowercase 40-character object ID.");
            }
            if (isset($versions[$version])) {
                throw new ReleaseException("Release version {$version} is duplicated.");
            }
            if ($previous !== null && self::compareVersions($previous, $version) >= 0) {
                throw new ReleaseException("Release versions must be strictly increasing; {$version} follows {$previous}.");
            }

            $entries[] = new ReleaseEntry($version, $commit);
            $versions[$version] = true;
            $previous = $version;
        }

        return new self($entries);
    }

    public function assertAppendOnlyFrom(self $base): void
    {
        if (count($this->entries) < count($base->entries)) {
            throw new ReleaseException('Release manifest is append-only; existing entries may not be removed.');
        }

        foreach ($base->entries as $index => $entry) {
            if (! isset($this->entries[$index]) || ! $this->entries[$index]->equals($entry)) {
                throw new ReleaseException("Release manifest is append-only; entry {$index} was edited or reordered.");
            }
        }
    }

    public function assertSameAs(self $other): void
    {
        $this->assertAppendOnlyFrom($other);
        $other->assertAppendOnlyFrom($this);
    }

    public function latest(): ?ReleaseEntry
    {
        return $this->entries === [] ? null : $this->entries[array_key_last($this->entries)];
    }

    public function hasVersion(string $version): bool
    {
        foreach ($this->entries as $entry) {
            if ($entry->version === $version) {
                return true;
            }
        }

        return false;
    }

    public function withAppended(ReleaseEntry $entry): self
    {
        $releases = array_map(
            static fn (ReleaseEntry $release): array => ['version' => $release->version, 'commit' => $release->commit],
            [...$this->entries, $entry],
        );

        return self::fromJson(json_encode(['schema' => 1, 'releases' => $releases], JSON_THROW_ON_ERROR));
    }

    public function toJson(): string
    {
        $releases = array_map(
            static fn (ReleaseEntry $entry): array => ['version' => $entry->version, 'commit' => $entry->commit],
            $this->entries,
        );

        return json_encode(
            ['schema' => 1, 'releases' => $releases],
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        )."\n";
    }

    /** @return list<ReleaseEntry> */
    public function entriesAddedAfter(?self $base): array
    {
        if ($base === null) {
            return array_values(array_filter($this->entries, static fn (ReleaseEntry $entry): bool => ! ReleasePolicy::isLegacy($entry)));
        }

        return array_slice($this->entries, count($base->entries));
    }

    /**
     * @param  array<mixed>  $value
     * @return list<int|string>
     */
    private static function keys(array $value): array
    {
        $keys = array_keys($value);
        sort($keys);

        return $keys;
    }

    private static function compareVersions(string $left, string $right): int
    {
        $leftParts = explode('.', substr($left, 1));
        $rightParts = explode('.', substr($right, 1));

        foreach ([0, 1, 2] as $index) {
            $length = strlen($leftParts[$index]) <=> strlen($rightParts[$index]);
            if ($length !== 0) {
                return $length;
            }
            $comparison = strcmp($leftParts[$index], $rightParts[$index]);
            if ($comparison !== 0) {
                return $comparison <=> 0;
            }
        }

        return 0;
    }
}
