<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PickeringTech\Harbour\Identity\ContextIdentifier;
use PickeringTech\Harbour\Identity\WorkspaceIdentity;
use PickeringTech\Harbour\Variables\DefaultVariableResolver;
use PickeringTech\Harbour\Variables\VariableResolutionContext;

final class DefaultVariableResolverTest extends TestCase
{
    public function test_it_builds_laravel_workspace_isolation_variables(): void
    {
        $identity = new WorkspaceIdentity('ws_test', 'feature-login-a1b2c3d4', str_repeat('a', 64), 'feature/login');
        $context = new VariableResolutionContext(
            $identity,
            '/project',
            'Acme',
            ['APP_PORT' => 8123, 'VITE_PORT' => 9123, 'REVERB_PORT' => 10123],
            'acme_feature_login_a1b2c3d4',
        );

        $variables = iterator_to_array((new DefaultVariableResolver(new ContextIdentifier))->resolve($context));
        $values = [];
        foreach ($variables as $variable) {
            $values[$variable->name] = $variable->value;
        }

        self::assertSame('http://127.0.0.1:8123', $values['APP_URL']);
        self::assertSame('acme_feature_login_a1b2c3d4', $values['DB_DATABASE']);
        self::assertSame('9123', $values['VITE_PORT']);
        self::assertSame('/project/.harbour/vite/hot', $values['VITE_HOT_FILE']);
        self::assertSame('acme_feature_login_a1b2c3d4_fa73bfb1:queue', $values['QUEUE_NAME']);
        self::assertSame($values['QUEUE_NAME'], $values['REDIS_QUEUE']);
        self::assertSame('acme_feature_login_a1b2c3d4_fa73bfb1:', $values['REDIS_PREFIX']);
        self::assertSame('acme_feature_login_a1b2c3d4_fa73bfb1:cache:', $values['CACHE_PREFIX']);
        self::assertSame('acme_feature_login_a1b2c3d4_fa73bfb1:queue:', $values['QUEUE_PREFIX']);
        self::assertSame('acme_feature_login_a1b2c3d4_fa73bfb1:horizon:', $values['HORIZON_PREFIX']);
        self::assertSame('acme_feature_login_a1b2c3d4_fa73bfb1', $values['SESSION_COOKIE']);
    }
}
