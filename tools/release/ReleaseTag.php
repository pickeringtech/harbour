<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Release;

final readonly class ReleaseTag
{
    public function __construct(
        public string $version,
        public string $commit,
        public string $objectSha,
        public bool $annotated,
        public bool $verified,
        public string $verificationReason,
    ) {}
}
