<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PickeringTech\Harbour\Release\Manifest;
use PickeringTech\Harbour\Release\ReleaseException;
use PickeringTech\Harbour\Release\ReleaseIntent;
use PickeringTech\Harbour\Release\ReleasePlanner;
use PickeringTech\Harbour\Tests\Support\FakeReleaseRepository;

final class ReleasePlannerTest extends TestCase
{
    public function test_it_resolves_a_pending_version_to_the_first_parent_intent_commit(): void
    {
        $repository = new FakeReleaseRepository;
        $repository->latestChange = str_repeat('b', 40);

        $entry = (new ReleasePlanner($repository))->pendingEntry(
            $this->manifest([['version' => 'v1.0.0', 'commit' => str_repeat('a', 40)]]),
            ReleaseIntent::fromJson('{"version":"v1.1.0"}'),
            'origin/main',
        );

        self::assertNotNull($entry);
        self::assertSame('v1.1.0', $entry->version);
        self::assertSame(str_repeat('b', 40), $entry->commit);
    }

    public function test_it_is_idempotent_when_the_latest_version_is_recorded(): void
    {
        $entry = (new ReleasePlanner(new FakeReleaseRepository))->pendingEntry(
            $this->manifest([['version' => 'v1.0.0', 'commit' => str_repeat('a', 40)]]),
            ReleaseIntent::fromJson('{"version":"v1.0.0"}'),
            'origin/main',
        );

        self::assertNull($entry);
    }

    public function test_it_rejects_a_reverted_or_non_increasing_intent(): void
    {
        $repository = new FakeReleaseRepository;
        $repository->latestChange = str_repeat('c', 40);
        $manifest = $this->manifest([
            ['version' => 'v1.0.0', 'commit' => str_repeat('a', 40)],
            ['version' => 'v1.1.0', 'commit' => str_repeat('b', 40)],
        ]);

        $this->expectException(ReleaseException::class);
        $this->expectExceptionMessage('older than the latest');

        (new ReleasePlanner($repository))->pendingEntry(
            $manifest,
            ReleaseIntent::fromJson('{"version":"v1.0.0"}'),
            'origin/main',
        );
    }

    public function test_a_pending_intent_cannot_be_replaced_by_a_later_pr(): void
    {
        $planner = new ReleasePlanner(new FakeReleaseRepository);

        $this->expectException(ReleaseException::class);
        $this->expectExceptionMessage('cannot be replaced before it is recorded');

        $planner->assertIntentTransition(
            $this->manifest([['version' => 'v1.0.0', 'commit' => str_repeat('a', 40)]]),
            ReleaseIntent::fromJson('{"version":"v1.1.0"}'),
            ReleaseIntent::fromJson('{"version":"v1.2.0"}'),
        );
    }

    public function test_the_initial_intent_bootstraps_only_from_the_latest_ledger_version(): void
    {
        $planner = new ReleasePlanner(new FakeReleaseRepository);
        $manifest = $this->manifest([['version' => 'v1.0.0', 'commit' => str_repeat('a', 40)]]);

        $planner->assertIntentTransition($manifest, null, ReleaseIntent::fromJson('{"version":"v1.0.0"}'));
        self::addToAssertionCount(1);

        $this->expectException(ReleaseException::class);
        $this->expectExceptionMessage('initial release intent');
        $planner->assertIntentTransition($manifest, null, ReleaseIntent::fromJson('{"version":"v1.1.0"}'));
    }

    /** @param list<array{version: string, commit: string}> $entries */
    private function manifest(array $entries): Manifest
    {
        return Manifest::fromJson(json_encode(['schema' => 1, 'releases' => $entries], JSON_THROW_ON_ERROR));
    }
}
