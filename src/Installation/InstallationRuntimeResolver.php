<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Installation;

final readonly class InstallationRuntimeResolver
{
    public function resolve(InstallationDiscovery $discovery): InstallationDiscovery
    {
        $selection = $discovery->selection;
        if (! in_array($selection->cache, ['redis', 'valkey'], true) || $selection->redisClient !== 'auto') {
            return $discovery;
        }

        return $discovery->withSelection($selection->withRedisClient('predis'));
    }
}
