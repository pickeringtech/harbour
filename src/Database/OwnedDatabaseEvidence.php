<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Database;

use PickeringTech\Harbour\Exceptions\ErrorCode;
use PickeringTech\Harbour\Exceptions\HarbourException;
use PickeringTech\Harbour\State\OwnedResource;

final readonly class OwnedDatabaseEvidence
{
    public function __construct(
        public string $workspaceId,
        public string $resourceId,
        public string $token,
        public string $database,
        public string $fingerprint,
    ) {}

    public static function fromResource(OwnedResource $resource): self
    {
        $metadata = $resource->metadata;

        if (! $resource->createdByHarbour
            || $resource->type !== 'database'
            || ! is_string($metadata['ownership_token'] ?? null)
            || $metadata['ownership_token'] === ''
            || ! is_string($metadata['database'] ?? null)
            || $metadata['database'] === ''
            || ! is_string($metadata['connection_fingerprint'] ?? null)) {
            throw new HarbourException(ErrorCode::DatabaseNotOwned, 'Database resource lacks Harbour ownership evidence.');
        }

        return new self(
            $resource->workspaceId,
            $resource->id,
            $metadata['ownership_token'],
            $metadata['database'],
            $metadata['connection_fingerprint'],
        );
    }
}
