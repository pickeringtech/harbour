<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Installation;

final readonly class InstallationRequirement
{
    public function __construct(
        public string $id,
        public string $name,
        public string $purpose,
        public string $resolution,
    ) {}

    /** @return array{id: string, name: string, purpose: string, resolution: string} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'purpose' => $this->purpose,
            'resolution' => $this->resolution,
        ];
    }
}
