<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Release;

use JsonException;

final readonly class ReleaseIntent
{
    private function __construct(public string $version) {}

    public static function fromFile(string $path): self
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw new ReleaseException("Release intent [{$path}] cannot be read.");
        }

        $intent = self::fromJson($contents);
        if ($contents !== $intent->toJson()) {
            throw new ReleaseException('Release intent must use the canonical generated JSON format.');
        }

        return $intent;
    }

    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ReleaseException('Release intent is not valid JSON: '.$exception->getMessage(), previous: $exception);
        }

        if (! is_array($data) || array_is_list($data) || array_keys($data) !== ['version']) {
            throw new ReleaseException('Release intent must contain exactly one version string.');
        }

        $version = $data['version'] ?? null;
        if (! is_string($version) || preg_match('/^v(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)$/D', $version) !== 1) {
            throw new ReleaseException('Release intent version must be strict vMAJOR.MINOR.PATCH SemVer.');
        }

        return new self($version);
    }

    public function toJson(): string
    {
        return json_encode(
            ['version' => $this->version],
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        )."\n";
    }
}
