<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Installation;

final readonly class InstallationPlan
{
    public function __construct(
        public InstallationDiscovery $discovery,
        public bool $start,
    ) {}

    public function withDiscovery(InstallationDiscovery $discovery): self
    {
        return new self($discovery, $this->start);
    }
}
