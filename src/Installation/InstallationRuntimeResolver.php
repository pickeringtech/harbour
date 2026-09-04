<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Installation;

final readonly class InstallationRuntimeResolver
{
    /** @param null|list<string> $extensions */
    public function __construct(private ?array $extensions = null) {}

    public function resolve(InstallationDiscovery $discovery, bool $redisClientExplicit = false): InstallationDiscovery
    {
        $selection = $discovery->selection;
        if (! in_array($selection->cache, ['redis', 'valkey'], true)) {
            return $discovery;
        }
        if ($selection->redisClient === 'phpredis' && ($redisClientExplicit || $this->hasExtension('redis'))) {
            return $discovery;
        }
        if ($selection->redisClient === 'predis') {
            return $discovery;
        }

        return $discovery->withSelection($selection->withRedisClient('predis'));
    }

    private function hasExtension(string $extension): bool
    {
        return $this->extensions === null
            ? extension_loaded($extension)
            : in_array($extension, $this->extensions, true);
    }
}
