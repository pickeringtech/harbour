<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PickeringTech\Harbour\Release\Manifest;
use PickeringTech\Harbour\Release\ReleaseException;

final class ReleaseManifestTest extends TestCase
{
    public function test_it_parses_strict_ordered_releases(): void
    {
        $manifest = Manifest::fromJson($this->json([
            ['version' => 'v1.2.3', 'commit' => str_repeat('a', 40)],
            ['version' => 'v1.10.0', 'commit' => str_repeat('b', 40)],
            ['version' => 'v100000000000000000000.0.0', 'commit' => str_repeat('c', 40)],
        ]));

        self::assertSame(['v1.2.3', 'v1.10.0', 'v100000000000000000000.0.0'], array_map(
            static fn ($entry): string => $entry->version,
            $manifest->entries,
        ));
    }

    #[DataProvider('invalidManifestProvider')]
    public function test_it_rejects_invalid_schema_versions_and_commits(string $json, string $message): void
    {
        $this->expectException(ReleaseException::class);
        $this->expectExceptionMessage($message);

        Manifest::fromJson($json);
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidManifestProvider(): iterable
    {
        $sha = str_repeat('a', 40);

        yield 'json' => ['{', 'not valid JSON'];
        yield 'root keys' => [json_encode(['schema' => 1, 'releases' => [], 'current' => 'v1.0.0'], JSON_THROW_ON_ERROR), 'exactly schema and releases'];
        yield 'schema type' => [json_encode(['schema' => '1', 'releases' => []], JSON_THROW_ON_ERROR), 'integer 1'];
        yield 'release object' => [json_encode(['schema' => 1, 'releases' => [['version' => 'v1.0.0', 'commit' => $sha, 'notes' => 'x']]], JSON_THROW_ON_ERROR), 'exactly version and commit'];
        yield 'prerelease' => [self::providedJson([['version' => 'v1.0.0-rc.1', 'commit' => $sha]]), 'strict vMAJOR.MINOR.PATCH'];
        yield 'leading zero' => [self::providedJson([['version' => 'v01.0.0', 'commit' => $sha]]), 'strict vMAJOR.MINOR.PATCH'];
        yield 'short sha' => [self::providedJson([['version' => 'v1.0.0', 'commit' => 'abcdef']]), 'full lowercase 40-character'];
        yield 'uppercase sha' => [self::providedJson([['version' => 'v1.0.0', 'commit' => str_repeat('A', 40)]]), 'full lowercase 40-character'];
        yield 'duplicate' => [self::providedJson([
            ['version' => 'v1.0.0', 'commit' => $sha],
            ['version' => 'v1.0.0', 'commit' => str_repeat('b', 40)],
        ]), 'duplicated'];
        yield 'decreasing' => [self::providedJson([
            ['version' => 'v2.0.0', 'commit' => $sha],
            ['version' => 'v1.9.0', 'commit' => str_repeat('b', 40)],
        ]), 'strictly increasing'];
    }

    public function test_append_only_comparison_allows_only_a_suffix(): void
    {
        $first = ['version' => 'v1.0.0', 'commit' => str_repeat('a', 40)];
        $second = ['version' => 'v1.1.0', 'commit' => str_repeat('b', 40)];
        $base = Manifest::fromJson($this->json([$first]));
        $appended = Manifest::fromJson($this->json([$first, $second]));

        $appended->assertAppendOnlyFrom($base);

        self::assertSame(['v1.1.0'], array_map(
            static fn ($entry): string => $entry->version,
            $appended->entriesAddedAfter($base),
        ));
    }

    public function test_append_only_comparison_rejects_edits_and_removals(): void
    {
        $first = ['version' => 'v1.0.0', 'commit' => str_repeat('a', 40)];
        $base = Manifest::fromJson($this->json([$first]));

        foreach ([
            Manifest::fromJson($this->json([])),
            Manifest::fromJson($this->json([['version' => 'v1.0.0', 'commit' => str_repeat('b', 40)]])),
        ] as $changed) {
            try {
                $changed->assertAppendOnlyFrom($base);
                self::fail('A non-append-only manifest was accepted.');
            } catch (ReleaseException $exception) {
                self::assertStringContainsString('append-only', $exception->getMessage());
            }
        }
    }

    /** @param list<array{version: string, commit: string}> $releases */
    private function json(array $releases): string
    {
        return self::providedJson($releases);
    }

    /** @param list<array{version: string, commit: string}> $releases */
    private static function providedJson(array $releases): string
    {
        return json_encode(['schema' => 1, 'releases' => $releases], JSON_THROW_ON_ERROR);
    }
}
