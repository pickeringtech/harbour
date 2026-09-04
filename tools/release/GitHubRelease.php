<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Release;

final readonly class GitHubRelease
{
    public function __construct(
        public int $id,
        public string $tagName,
        public bool $draft,
        public bool $immutable,
    ) {}
}
