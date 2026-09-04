<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Release;

final readonly class ReconciliationState
{
    public function __construct(
        public ReleaseEntry $entry,
        public ?ReleaseTag $tag,
        public ?GitHubRelease $release,
    ) {}

    public function needsWrite(): bool
    {
        return $this->tag === null || $this->release === null || $this->release->draft;
    }
}
