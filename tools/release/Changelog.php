<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Release;

final class Changelog
{
    public static function notes(string $contents, ReleaseEntry $entry): string
    {
        $version = preg_quote($entry->changelogVersion(), '/');
        $pattern = '/^## \['.$version.'\] - \d{4}-\d{2}-\d{2}\R(?<notes>.*?)(?=^## \[|\z)/ms';

        if (preg_match($pattern, $contents, $matches) !== 1) {
            throw new ReleaseException("CHANGELOG.md has no dated section for {$entry->version}.");
        }

        $notes = trim($matches['notes']);
        if ($notes === '') {
            throw new ReleaseException("CHANGELOG.md section for {$entry->version} is empty.");
        }

        return $notes;
    }
}
