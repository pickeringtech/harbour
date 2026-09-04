<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Database;

use PDO;
use Throwable;

class OwnershipMarker
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
        } catch (Throwable) {
            return false;
        }
    }

    public function reassignIfOwnedByWorkspace(PDO $pdo, OwnedDatabaseEvidence $evidence): bool
    {
        try {
            $pdo->beginTransaction();
            $statement = $pdo->query('SELECT workspace_id, resource_id, ownership_token FROM '.self::TABLE);
            $rows = $statement === false ? false : $statement->fetchAll(PDO::FETCH_ASSOC);

            if (! is_array($rows) || count($rows) !== 1) {
                $this->rollback($pdo);

                return false;
            }

            $row = $rows[0];
            if (! is_array($row)) {
                $this->rollback($pdo);

                return false;
            }
            $workspaceId = $row['workspace_id'] ?? null;
            $resourceId = $row['resource_id'] ?? null;
            $token = $row['ownership_token'] ?? null;
            if ($workspaceId !== $evidence->workspaceId
                || preg_match('/^ws_[a-f0-9]{64}$/D', $evidence->workspaceId) !== 1
                || preg_match('/^db_[a-f0-9]{32}$/D', $evidence->resourceId) !== 1
                || preg_match('/^[a-f0-9]{64}$/D', $evidence->token) !== 1
                || ! is_string($resourceId) || preg_match('/^db_[a-f0-9]{32}$/D', $resourceId) !== 1
                || ! is_string($token) || preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
                $this->rollback($pdo);

                return false;
            }

            $update = $pdo->prepare(
                'UPDATE '.self::TABLE.' SET resource_id = ?, ownership_token = ? WHERE workspace_id = ? AND resource_id = ? AND ownership_token = ?',
            );
            $update->execute([$evidence->resourceId, $evidence->token, $workspaceId, $resourceId, $token]);
            if ($update->rowCount() !== 1) {
                $this->rollback($pdo);

                return false;
            }

            $pdo->commit();

            return true;
        } catch (Throwable) {
            $this->rollback($pdo);

            return false;
        }
    }

    private function rollback(PDO $pdo): void
    {
        try {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        } catch (Throwable) {
            // Recovery failed closed; the caller will refuse the database.
        }
    }
}
