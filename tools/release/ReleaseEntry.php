<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Release;

final readonly class ReleaseEntry
{
    public function __construct(
        public string $version,
        public string $commit,
    ) {}

    public function changelogVersion(): string
    {
        return substr($this->version, 1);
    }

    public function equals(self $other): bool
    {
        return $this->version === $other->version && $this->commit === $other->commit;
    }
}
