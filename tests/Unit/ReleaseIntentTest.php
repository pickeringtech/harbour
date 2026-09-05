<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PickeringTech\Harbour\Release\ReleaseException;
use PickeringTech\Harbour\Release\ReleaseIntent;

final class ReleaseIntentTest extends TestCase
{
    public function test_it_parses_a_version_only_intent(): void
    {
        $intent = ReleaseIntent::fromJson('{"version":"v1.2.3"}');

        self::assertSame('v1.2.3', $intent->version);
        self::assertSame("{\n    \"version\": \"v1.2.3\"\n}\n", $intent->toJson());
    }

    public function test_the_file_form_must_be_canonical(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'harbour-intent-');
        self::assertIsString($path);
        file_put_contents($path, '{"version":"v1.2.3"}');

        try {
            $this->expectException(ReleaseException::class);
            $this->expectExceptionMessage('canonical generated JSON');
            ReleaseIntent::fromFile($path);
        } finally {
            @unlink($path);
        }
    }

    #[DataProvider('invalidIntentProvider')]
    public function test_it_rejects_everything_except_one_strict_version(string $json, string $message): void
    {
        $this->expectException(ReleaseException::class);
        $this->expectExceptionMessage($message);

        ReleaseIntent::fromJson($json);
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidIntentProvider(): iterable
    {
        yield 'invalid json' => ['{', 'not valid JSON'];
        yield 'extra key' => ['{"version":"v1.2.3","commit":"abc"}', 'exactly one version'];
        yield 'array' => ['["v1.2.3"]', 'exactly one version'];
        yield 'without v' => ['{"version":"1.2.3"}', 'strict vMAJOR.MINOR.PATCH'];
        yield 'prerelease' => ['{"version":"v1.2.3-rc.1"}', 'strict vMAJOR.MINOR.PATCH'];
    }
}
