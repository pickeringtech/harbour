<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Release;

final readonly class ValidatedManifest
{
    /** @param array<string, string> $notes */
    public function __construct(
        public Manifest $manifest,
        public array $notes,
    ) {}

    public function notesFor(ReleaseEntry $entry): string
    {
        if (! isset($this->notes[$entry->version])) {
            throw new ReleaseException("Validated release notes are missing for {$entry->version}.");
        }

        return $this->notes[$entry->version];
    }
}
