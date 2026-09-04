<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Tests\Unit;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PickeringTech\Harbour\Database\OwnedDatabaseEvidence;
use PickeringTech\Harbour\Database\OwnershipMarker;

final class OwnershipMarkerTest extends TestCase
{
    public function test_it_atomically_reassigns_valid_evidence_for_the_same_workspace(): void
    {
        $pdo = $this->pdo();
        $marker = new OwnershipMarker;
        $original = $this->evidence();
        $replacement = $this->evidence(resource: 'db_'.str_repeat('d', 32), token: str_repeat('e', 64));
        $marker->create($pdo, $original->workspaceId, $original->resourceId, $original->token);

        self::assertTrue($marker->reassignIfOwnedByWorkspace($pdo, $replacement));
        self::assertFalse($marker->matches($pdo, $original));
        self::assertTrue($marker->matches($pdo, $replacement));
        self::assertFalse($pdo->inTransaction());
    }

    public function test_it_fails_closed_when_the_marker_is_missing_or_ambiguous(): void
    {
        $marker = new OwnershipMarker;
        $missing = $this->pdo();

        self::assertFalse($marker->reassignIfOwnedByWorkspace($missing, $this->evidence()));
        self::assertFalse($missing->inTransaction());

        $ambiguous = $this->pdo();
        $original = $this->evidence();
        $marker->create($ambiguous, $original->workspaceId, $original->resourceId, $original->token);
        $statement = $ambiguous->prepare('INSERT INTO _harbour_ownership (workspace_id, resource_id, ownership_token) VALUES (?, ?, ?)');
        $statement->execute([$original->workspaceId, 'db_'.str_repeat('f', 32), str_repeat('1', 64)]);

        self::assertFalse($marker->reassignIfOwnedByWorkspace($ambiguous, $this->evidence(resource: 'db_'.str_repeat('d', 32))));
        self::assertTrue($marker->matches($ambiguous, $original));
        self::assertFalse($ambiguous->inTransaction());
    }

    /**
     * @param  array{workspace?: string, resource?: string, token?: string}  $stored
     * @param  array{workspace?: string, resource?: string, token?: string}  $replacement
     */
    #[DataProvider('unsafeEvidence')]
    public function test_it_refuses_to_reassign_malformed_or_cross_workspace_evidence(array $stored, array $replacement): void
    {
        $pdo = $this->pdo();
        $marker = new OwnershipMarker;
        $original = $this->evidence(...$stored);
        $candidate = $this->evidence(...$replacement);
        $marker->create($pdo, $original->workspaceId, $original->resourceId, $original->token);

        self::assertFalse($marker->reassignIfOwnedByWorkspace($pdo, $candidate));
        self::assertTrue($marker->matches($pdo, $original));
        self::assertFalse($pdo->inTransaction());
    }

    /** @return iterable<string, array{array{workspace?: string, resource?: string, token?: string}, array{workspace?: string, resource?: string, token?: string}}> */
    public static function unsafeEvidence(): iterable
    {
        yield 'another workspace' => [[], ['workspace' => 'ws_'.str_repeat('f', 64)]];
        yield 'malformed replacement workspace' => [
            ['workspace' => 'ws_invalid'],
            ['workspace' => 'ws_invalid', 'resource' => 'db_'.str_repeat('d', 32)],
        ];
        yield 'malformed replacement resource' => [[], ['resource' => 'db_invalid']];
        yield 'malformed replacement token' => [[], ['token' => 'not-a-token']];
        yield 'malformed stored resource' => [['resource' => 'db_invalid'], ['resource' => 'db_'.str_repeat('d', 32)]];
        yield 'malformed stored token' => [['token' => 'not-a-token'], ['resource' => 'db_'.str_repeat('d', 32)]];
    }

    public function test_it_rolls_back_when_the_guarded_update_does_not_change_the_marker(): void
    {
        $pdo = $this->pdo();
        $marker = new OwnershipMarker;
        $original = $this->evidence();
        $marker->create($pdo, $original->workspaceId, $original->resourceId, $original->token);
        $pdo->exec('CREATE TRIGGER ignore_ownership_update BEFORE UPDATE ON _harbour_ownership BEGIN SELECT RAISE(IGNORE); END');

        self::assertFalse($marker->reassignIfOwnedByWorkspace(
            $pdo,
            $this->evidence(resource: 'db_'.str_repeat('d', 32), token: str_repeat('e', 64)),
        ));
        self::assertTrue($marker->matches($pdo, $original));
        self::assertFalse($pdo->inTransaction());
    }

    private function pdo(): PDO
    {
        return new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    private function evidence(
        string $workspace = 'ws_'.self::VALID_WORKSPACE_HASH,
        string $resource = 'db_'.self::VALID_RESOURCE_HASH,
        string $token = self::VALID_TOKEN,
    ): OwnedDatabaseEvidence {
        return new OwnedDatabaseEvidence($workspace, $resource, $token, 'harbour_test', 'fingerprint');
    }

    private const VALID_WORKSPACE_HASH = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const VALID_RESOURCE_HASH = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private const VALID_TOKEN = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';
}
