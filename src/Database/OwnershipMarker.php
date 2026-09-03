<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Database;

use PDO;

final class OwnershipMarker
{
    private const TABLE = '_harbour_ownership';

    public function create(PDO $pdo, string $workspaceId, string $resourceId, string $token): void
    {
        $pdo->exec('CREATE TABLE '.self::TABLE.' (workspace_id VARCHAR(80) NOT NULL, resource_id VARCHAR(80) NOT NULL, ownership_token VARCHAR(128) NOT NULL)');
        $statement = $pdo->prepare('INSERT INTO '.self::TABLE.' (workspace_id, resource_id, ownership_token) VALUES (?, ?, ?)');
        $statement->execute([$workspaceId, $resourceId, $token]);
    }

    public function matches(PDO $pdo, OwnedDatabaseEvidence $evidence): bool
    {
        try {
            $statement = $pdo->prepare('SELECT COUNT(*) FROM '.self::TABLE.' WHERE workspace_id = ? AND resource_id = ? AND ownership_token = ?');
            $statement->execute([$evidence->workspaceId, $evidence->resourceId, $evidence->token]);

            return (int) $statement->fetchColumn() === 1;
        } catch (\Throwable) {
            return false;
        }
    }
}
