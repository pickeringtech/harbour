<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use PickeringTech\Harbour\Variables\ResolvedVariable;
use PickeringTech\Harbour\Variables\VariableBag;

final class VariableBagTest extends TestCase
{
    public function test_last_source_wins_and_provenance_is_retained(): void
    {
        $bag = new VariableBag;
        $bag->put(new ResolvedVariable('APP_PORT', '8000', 'default'));
        $bag->put(new ResolvedVariable('APP_PORT', '8123', 'ports'));

        $variable = $bag->get('APP_PORT');
        self::assertNotNull($variable);
        self::assertSame('8123', $variable->value);
        self::assertSame('ports', $variable->source);
    }

    public function test_secret_values_are_redacted_and_never_persisted(): void
    {
        $bag = new VariableBag([
            new ResolvedVariable('API_KEY', 'super-secret', 'process', true, false),
            new ResolvedVariable('NORMAL', 'visible', 'config', false, true),
            new ResolvedVariable('DB_PASSWORD', 'heuristic', 'process'),
            new ResolvedVariable('APP_KEY', 'laravel-key', 'environment'),
        ]);

        self::assertSame('[REDACTED]', $bag->debug()['API_KEY']['value']);
        self::assertSame('[REDACTED]', $bag->debug()['DB_PASSWORD']['value']);
        self::assertSame('[REDACTED]', $bag->debug()['APP_KEY']['value']);
        self::assertSame(['NORMAL' => 'visible'], $bag->persistable());
        self::assertSame(['API_KEY', 'APP_KEY', 'DB_PASSWORD', 'NORMAL'], array_keys($bag->debug()));
        self::assertFalse($bag->debug()['NORMAL']['secret']);
        self::assertTrue($bag->debug()['NORMAL']['persisted']);
    }

    public function test_variable_names_are_anchored_and_default_flags_are_stable(): void
    {
        $variable = new ResolvedVariable('VALID_NAME', 'value', 'test');
        self::assertFalse($variable->secret);
        self::assertTrue($variable->persist);
        self::assertFalse($variable->isSensitive());

        foreach (["VALID\nINJECTED", '9INVALID', 'INVALID-NAME'] as $name) {
            try {
                new ResolvedVariable($name, 'value', 'test');
                self::fail('Expected an invalid variable name.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
