<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\State;

use InvalidArgumentException;
use PickeringTech\Harbour\Identity\WorkspaceIdentity;

final readonly class WorkspaceState
{
    public const VERSION = 1;

    /**
     * @param  array<string, int>  $allocations
     * @param  list<OwnedResource>  $resources
     * @param  array<string, string>  $variables
     * @param  array<string, bool|int|string|null>  $environment
     */
    public function __construct(
        public int $version,
        public string $status,
        public WorkspaceIdentity $identity,
        public string $path,
        public array $allocations = [],
        public array $resources = [],
        public array $variables = [],
        public array $environment = [],
        public ?string $errorCode = null,
    ) {
        if ($version !== self::VERSION || ! in_array($status, ['preparing', 'ready', 'failed', 'tearing_down'], true)) {
            throw new InvalidArgumentException('Invalid workspace state.');
        }
    }

    public static function begin(WorkspaceIdentity $identity, string $path): self
    {
        return new self(self::VERSION, 'preparing', $identity, $path);
    }

    public function withAllocation(string $name, int $port): self
    {
        return $this->copy(allocations: [...$this->allocations, $name => $port]);
    }

    public function withResource(OwnedResource $resource): self
    {
        if ($resource->workspaceId !== $this->identity->id()) {
            throw new InvalidArgumentException('Resource belongs to a different workspace.');
        }

        $resources = array_values(array_filter($this->resources, static fn (OwnedResource $existing): bool => $existing->id !== $resource->id));
        $resources[] = $resource;

        return $this->copy(resources: $resources);
    }

    /** @param array<string, string> $variables */
    public function withVariables(array $variables): self
    {
        return $this->copy(variables: $variables);
    }

    /** @param array<string, bool|int|string|null> $environment */
    public function withEnvironment(array $environment): self
    {
        return $this->copy(environment: $environment);
    }

    public function ready(): self
    {
        return $this->copy(status: 'ready', errorCode: null);
    }

    public function failed(string $errorCode): self
    {
        return $this->copy(status: 'failed', errorCode: $errorCode);
    }

    public function tearingDown(): self
    {
        return $this->copy(status: 'tearing_down');
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'status' => $this->status,
            'workspace' => [...$this->identity->toArray(), 'path' => $this->path],
            'allocations' => $this->allocations,
            'resources' => array_map(static fn (OwnedResource $resource): array => $resource->toArray(), $this->resources),
            'variables' => $this->variables,
            'environment' => $this->environment,
            'error_code' => $this->errorCode,
        ];
    }

    /** @param array<mixed> $data */
    public static function fromArray(array $data): self
    {
        $workspace = $data['workspace'] ?? null;
        $status = $data['status'] ?? null;
        if (($data['version'] ?? null) !== self::VERSION || ! is_array($workspace) || ! is_string($status)
            || ! is_string($workspace['id'] ?? null) || ! is_string($workspace['slug'] ?? null)
            || ! is_string($workspace['hash'] ?? null) || ! is_string($workspace['path'] ?? null)
            || (isset($workspace['branch']) && ! is_string($workspace['branch']))) {
            throw new InvalidArgumentException('Unsupported or malformed Harbour state.');
        }

        $allocations = self::integerMap($data['allocations'] ?? []);
        $variables = self::stringMap($data['variables'] ?? []);
        $environment = self::environmentMap($data['environment'] ?? []);
        $resources = self::resources($data['resources'] ?? []);

        return new self(
            self::VERSION,
            $status,
            new WorkspaceIdentity($workspace['id'], $workspace['slug'], $workspace['hash'], $workspace['branch'] ?? null),
            $workspace['path'],
            $allocations,
            $resources,
            $variables,
            $environment,
            is_string($data['error_code'] ?? null) ? $data['error_code'] : null,
        );
    }

    /** @return array<string, int> */
    private static function integerMap(mixed $value): array
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException('State allocations must be an object.');
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (! is_string($key) || ! is_int($item)) {
                throw new InvalidArgumentException('State allocation is malformed.');
            }
            $result[$key] = $item;
        }

        return $result;
    }

    /** @return array<string, string> */
    private static function stringMap(mixed $value): array
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException('State variables must be an object.');
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (! is_string($key) || ! is_string($item)) {
                throw new InvalidArgumentException('State variable is malformed.');
            }
            $result[$key] = $item;
        }

        return $result;
    }

    /** @return array<string, bool|int|string|null> */
    private static function environmentMap(mixed $value): array
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException('State environment metadata must be an object.');
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (! is_string($key) || (! is_bool($item) && ! is_int($item) && ! is_string($item) && $item !== null)) {
                throw new InvalidArgumentException('State environment metadata is malformed.');
            }
            $result[$key] = $item;
        }

        return $result;
    }

    /** @return list<OwnedResource> */
    private static function resources(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException('State resources must be a list.');
        }
        $resources = [];
        foreach ($value as $item) {
            if (! is_array($item)
                || ! is_string($item['id'] ?? null) || ! is_string($item['workspace_id'] ?? null)
                || ! is_string($item['type'] ?? null) || ! is_string($item['driver'] ?? null)
                || ! is_array($item['metadata'] ?? null)
                || (isset($item['created_by_harbour']) && ! is_bool($item['created_by_harbour']))) {
                throw new InvalidArgumentException('State resource is malformed.');
            }
            $metadata = [];
            foreach ($item['metadata'] as $key => $metadataValue) {
                if (! is_string($key)) {
                    throw new InvalidArgumentException('State resource metadata key is malformed.');
                }
                $metadata[$key] = $metadataValue;
            }
            $resources[] = new OwnedResource(
                $item['id'], $item['workspace_id'], $item['type'], $item['driver'], $metadata, $item['created_by_harbour'] ?? false,
            );
        }

        return $resources;
    }

    /**
     * @param  array<string, int>|null  $allocations
     * @param  list<OwnedResource>|null  $resources
     * @param  array<string, string>|null  $variables
     * @param  array<string, bool|int|string|null>|null  $environment
     */
    private function copy(
        ?string $status = null,
        ?array $allocations = null,
        ?array $resources = null,
        ?array $variables = null,
        ?array $environment = null,
        ?string $errorCode = null,
    ): self {
        return new self(
            $this->version,
            $status ?? $this->status,
            $this->identity,
            $this->path,
            $allocations ?? $this->allocations,
            $resources ?? $this->resources,
            $variables ?? $this->variables,
            $environment ?? $this->environment,
            $errorCode,
        );
    }
}
