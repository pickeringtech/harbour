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

    public function composerPackage(): ?string
    {
        return str_starts_with($this->id, 'package:') ? substr($this->id, 8) : null;
    }

    public function isDevelopmentDependency(): bool
    {
        return $this->composerPackage() === 'laravel/dusk';
    }

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
