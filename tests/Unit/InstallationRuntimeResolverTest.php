<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PickeringTech\Harbour\Installation\InstallationDiscovery;
use PickeringTech\Harbour\Installation\InstallationFileRenderer;
use PickeringTech\Harbour\Installation\InstallationRuntimeResolver;
use PickeringTech\Harbour\Installation\InstallationSelection;

final class InstallationRuntimeResolverTest extends TestCase
{
    public function test_it_uses_predis_when_phpredis_is_unavailable_and_records_the_portable_choice(): void
    {
        $discovery = InstallationDiscovery::explicit(new InstallationSelection('pgsql', 'redis', 'log'));
        $resolved = (new InstallationRuntimeResolver([]))->resolve($discovery);

        self::assertSame('predis', $resolved->selection->redisClient);
        self::assertStringContainsString('REDIS_CLIENT=predis', (new InstallationFileRenderer)->environment($resolved));
    }

    public function test_it_uses_the_portable_default_and_preserves_explicit_choices(): void
    {
        $resolver = new InstallationRuntimeResolver([]);
        $automatic = $resolver->resolve(InstallationDiscovery::explicit(new InstallationSelection('none', 'redis', 'log')));
        $explicit = $resolver->resolve(InstallationDiscovery::explicit(new InstallationSelection('none', 'redis', 'log', [], 'shared', 'predis')));

        self::assertSame('predis', $automatic->selection->redisClient);
        self::assertSame('predis', $explicit->selection->redisClient);
        self::assertSame('auto', $resolver->resolve(InstallationDiscovery::explicit(new InstallationSelection('none', 'file', 'log')))->selection->redisClient);
    }

    public function test_it_replaces_detected_phpredis_only_when_the_host_extension_is_unavailable(): void
    {
        $discovery = InstallationDiscovery::explicit(new InstallationSelection('none', 'redis', 'log', [], 'shared', 'phpredis'));

        self::assertSame('predis', (new InstallationRuntimeResolver([]))->resolve($discovery)->selection->redisClient);
        self::assertSame('phpredis', (new InstallationRuntimeResolver(['redis']))->resolve($discovery)->selection->redisClient);
        self::assertSame('phpredis', (new InstallationRuntimeResolver([]))->resolve($discovery, true)->selection->redisClient);
    }
}
