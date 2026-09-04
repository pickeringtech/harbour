<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PickeringTech\Harbour\Process\ProcessFailure;
use PickeringTech\Harbour\Process\ProcessResult;

final class ProcessFailureTest extends TestCase
{
    public function test_stderr_context_is_redacted_and_bounded_to_its_tail(): void
    {
        $stderr = str_repeat('old output ', 600).'password=hunter2 API_KEY=abc123 mysql://root:uri-secret@localhost final detail';
        $context = ProcessFailure::context(new ProcessResult(17, '', $stderr), [
            'DB_PASSWORD' => 'hunter2',
            'SAFE' => 'visible',
        ]);

        self::assertSame(17, $context['exit_code']);
        self::assertIsString($context['stderr']);
        self::assertLessThanOrEqual(4099, strlen($context['stderr']));
        self::assertStringStartsWith('…', $context['stderr']);
        self::assertStringContainsString('final detail', $context['stderr']);
        self::assertStringNotContainsString('hunter2', $context['stderr']);
        self::assertStringNotContainsString('abc123', $context['stderr']);
        self::assertStringNotContainsString('uri-secret', $context['stderr']);
    }

    public function test_empty_stderr_is_not_added_to_context(): void
    {
        self::assertSame(['exit_code' => 2], ProcessFailure::context(new ProcessResult(2, '')));
    }
}
