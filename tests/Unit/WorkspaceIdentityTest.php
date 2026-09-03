<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PickeringTech\Harbour\Identity\ContextIdentifier;
use PickeringTech\Harbour\Identity\WorkspaceIdentity;

final class WorkspaceIdentityTest extends TestCase
{
    public function test_it_is_an_immutable_serializable_value_object(): void
    {
        $identity = new WorkspaceIdentity('workspace-id', 'feature-login-a1b2c3d4', str_repeat('a', 64), 'feature/login');

        self::assertSame('workspace-id', $identity->id());
        self::assertSame('feature-login-a1b2c3d4', $identity->slug());
        self::assertSame(str_repeat('a', 64), $identity->hash());
        self::assertSame('feature/login', $identity->branch());
        self::assertEquals($identity, WorkspaceIdentity::fromArray($identity->toArray()));
    }

    public function test_context_identifiers_have_stable_collision_resistant_values(): void
    {
        $identity = new WorkspaceIdentity('ws_test', 'feature-login-a1b2c3d4', str_repeat('a', 64), 'feature/login');
        $identifiers = new ContextIdentifier;

        self::assertSame('project_feature_login_a1b2c3d4_e39daee0', $identifiers->database($identity, 'Project'));
        self::assertSame('service-feature-login-a1b2c3d4-673ac3f4', $identifiers->docker($identity, 'Service'));
        self::assertSame('stack-feature-login-a1b2c3d4-ed5c71cb', $identifiers->compose($identity, 'Stack'));
        self::assertSame('project_feature_login_a1b2c3d4_e39daee0', $identifiers->cookie($identity, 'Project'));
        self::assertSame('project_feature_login_a1b2c3d4_e39daee0:', $identifiers->redis($identity, 'Project'));
        self::assertSame('tmp-feature-login-a1b2c3d4-f147f3c3', $identifiers->filesystem($identity, 'tmp'));
    }

    #[DataProvider('hostileNames')]
    public function test_identifiers_are_safe_for_every_target(string $name): void
    {
        $identity = new WorkspaceIdentity('ws_'.hash('sha256', $name), $name, hash('sha256', $name), $name);
        $identifiers = new ContextIdentifier;

        self::assertMatchesRegularExpression('/^[a-z][a-z0-9_]{0,62}$/', $identifiers->database($identity, 'Project'));
        self::assertMatchesRegularExpression('/^[a-z0-9][a-z0-9_.-]{0,62}$/', $identifiers->docker($identity, 'Service'));
        self::assertMatchesRegularExpression('/^[a-z0-9][a-z0-9_-]{0,62}$/', $identifiers->compose($identity, 'Stack'));
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{1,128}$/', $identifiers->cookie($identity, 'Project'));
        self::assertStringEndsWith(':', $identifiers->redis($identity, 'Project'));
    }

    /** @return iterable<string, array{string}> */
    public static function hostileNames(): iterable
    {
        yield 'slash and quote' => ["feature/foo's-awesome-checkout"];
        yield 'unicode' => ['修正/支払い-🚢'];
        yield 'shell' => ['$(touch /tmp/nope); `id`; $PATH'];
        yield 'newlines' => ["feature/foo\r\nDROP DATABASE prod"];
        yield 'huge' => [str_repeat('feature/very-long-', 200)];
        yield 'empty-like' => ['---___...'];
    }
}
