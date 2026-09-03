<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\State;

use InvalidArgumentException;

final readonly class OwnedResource
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public string $id,
        public string $workspaceId,
        public string $type,
        public string $driver,
        public array $metadata,
        public bool $createdByHarbour = true,
    ) {
        if ($id === '' || $workspaceId === '' || $type === '' || $driver === '') {
            throw new InvalidArgumentException('Owned resource fields may not be empty.');
        }
    }

    /** @return array{id: string, workspace_id: string, type: string, driver: string, created_by_harbour: bool, metadata: array<string, mixed>} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'workspace_id' => $this->workspaceId,
            'type' => $this->type,
            'driver' => $this->driver,
            'created_by_harbour' => $this->createdByHarbour,
            'metadata' => $this->metadata,
        ];
    }

    /** @return array{id: string, workspace_id: string, type: string, driver: string, created_by_harbour: bool, metadata: array<string, mixed>} */
    public function diagnosticArray(): array
    {
        $metadata = [];
        foreach ($this->metadata as $key => $value) {
            $metadata[$key] = preg_match('/(?:TOKEN|PASSWORD|PASSWD|SECRET|PRIVATE_KEY|CREDENTIAL)/i', $key)
                ? '[REDACTED]'
                : $value;
        }

        return [
            'id' => $this->id,
            'workspace_id' => $this->workspaceId,
            'type' => $this->type,
            'driver' => $this->driver,
            'created_by_harbour' => $this->createdByHarbour,
            'metadata' => $metadata,
        ];
    }

    /** @param array{id: string, workspace_id: string, type: string, driver: string, created_by_harbour?: bool, metadata?: array<string, mixed>} $data */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['id'],
            $data['workspace_id'],
            $data['type'],
            $data['driver'],
            $data['metadata'] ?? [],
            $data['created_by_harbour'] ?? false,
        );
    }
}
