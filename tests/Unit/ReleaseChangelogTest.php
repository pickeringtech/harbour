<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PickeringTech\Harbour\Release\Changelog;
use PickeringTech\Harbour\Release\ReleaseEntry;
use PickeringTech\Harbour\Release\ReleaseException;

final class ReleaseChangelogTest extends TestCase
{
    public function test_it_extracts_only_the_exact_dated_section(): void
    {
        $contents = "# Changelog\n\n## [1.2.3] - 2026-09-04\n\n### Added\n\n- Exact notes.\n\n## [1.2.2] - 2026-09-03\n\n- Old notes.\n";

        self::assertSame("### Added\n\n- Exact notes.", Changelog::notes($contents, new ReleaseEntry('v1.2.3', str_repeat('a', 40))));
    }

    #[DataProvider('invalidChangelogProvider')]
    public function test_it_rejects_missing_undated_and_empty_sections(string $contents, string $message): void
    {
        $this->expectException(ReleaseException::class);
        $this->expectExceptionMessage($message);

        Changelog::notes($contents, new ReleaseEntry('v1.2.3', str_repeat('a', 40)));
    }

    /** @return iterable<array{string, string}> */
    public static function invalidChangelogProvider(): iterable
    {
        yield ["## [1.2.2] - 2026-09-04\n\n- Different.\n", 'no dated section'];
        yield ["## [1.2.3]\n\n- Undated.\n", 'no dated section'];
        yield ["## [1.2.3] - 2026-09-04\n\n## [1.2.2] - 2026-09-03\n", 'is empty'];
    }
}
