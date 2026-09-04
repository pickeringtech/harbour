<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Release;

interface TagPublisher
{
    public function createTagObject(ReleaseEntry $entry): ReleaseTag;

    /** Returns false when the ref appeared concurrently. */
    public function createTagReference(ReleaseTag $tag): bool;
}
