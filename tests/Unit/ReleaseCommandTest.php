<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PickeringTech\Harbour\Release\Command;
use PickeringTech\Harbour\Release\ReconciliationResult;
use PickeringTech\Harbour\Release\ReleaseEntry;

final class ReleaseCommandTest extends TestCase
{
    public function test_summary_lists_every_result_and_escapes_markdown(): void
    {
        $entry = new ReleaseEntry('v1.0.0', str_repeat('a', 40));
        $summary = Command::summary([
            new ReconciliationResult($entry, 'failed closed', "safe | detail\nnext", false),
        ]);

        self::assertStringContainsString('| v1.0.0 | `'.str_repeat('a', 40).'` | failed closed | safe \\| detail next |', $summary);
        self::assertStringNotContainsString("detail\nnext", $summary);
    }
}
