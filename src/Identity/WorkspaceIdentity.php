<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Identity;

use InvalidArgumentException;

final readonly class WorkspaceIdentity
{
    public function __construct(
        private string $id,
        private string $slug,
        private string $hash,
        private ?string $branch,
    ) {
        if ($id === '' || $slug === '' || ! preg_match('/^[a-f0-9]{64}$/', $hash)) {
            throw new InvalidArgumentException('Invalid workspace identity.');
        }
    }

    public function id(): string
    {
        return $this->id;
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function hash(): string
    {
        return $this->hash;
    }

    public function branch(): ?string
    {
        return $this->branch;
    }

    /** @return array{id: string, slug: string, hash: string, branch: string|null} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'hash' => $this->hash,
            'branch' => $this->branch,
        ];
    }

    /** @param array{id: string, slug: string, hash: string, branch?: string|null} $data */
    public static function fromArray(array $data): self
    {
        return new self($data['id'], $data['slug'], $data['hash'], $data['branch'] ?? null);
    }
}
