<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Release;

use JsonException;

final readonly class HttpPackagistClient implements PackagistClient
{
    public function __construct(
        private string $package = 'pickeringtech/harbour',
        private string $baseUrl = 'https://repo.packagist.org',
    ) {}

    public function resolvesTo(ReleaseEntry $entry): bool
    {
        $url = rtrim($this->baseUrl, '/').'/p2/'.$this->package.'.json';
        $context = stream_context_create(['http' => [
            'header' => "User-Agent: harbour-release-reconciler\r\n",
            'ignore_errors' => true,
            'timeout' => 30,
        ]]);
        $raw = @file_get_contents($url, false, $context);

        if ($raw === false) {
            throw new ReleaseException('Packagist metadata could not be fetched.');
        }

        try {
            $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ReleaseException('Packagist returned invalid JSON.', previous: $exception);
        }

        if (! is_array($data) || ! is_array($data['packages'] ?? null)) {
            throw new ReleaseException('Packagist metadata has an unexpected shape.');
        }
        $packages = $data['packages'];
        $versions = $packages[$this->package] ?? null;
        if (! is_array($versions)) {
            throw new ReleaseException('Packagist metadata has an unexpected shape.');
        }

        foreach ($versions as $package) {
            if (! is_array($package) || ($package['version'] ?? null) !== $entry->version) {
                continue;
            }
            $source = $package['source'] ?? null;

            return is_array($source) && ($source['reference'] ?? null) === $entry->commit;
        }

        return false;
    }
}
