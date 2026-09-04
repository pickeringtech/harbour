<?php

declare(strict_types=1);

namespace PickeringTech\Harbour;

use PickeringTech\Harbour\Identity\WorkspaceIdentity;
use PickeringTech\Harbour\State\OwnedResource;
use PickeringTech\Harbour\State\ResourceType;
use PickeringTech\Harbour\State\WorkspaceState;
use PickeringTech\Harbour\Variables\VariableBag;

final readonly class Workspace
{
    public function __construct(
        private WorkspaceState $state,
        private VariableBag $variables,
    ) {}

    public function identity(): WorkspaceIdentity
    {
        return $this->state->identity;
    }

    /** @return array<string, int> */
    public function ports(): array
    {
        return $this->state->allocations;
    }

    public function variables(): VariableBag
    {
        return $this->variables;
    }

    public function database(): ?OwnedResource
    {
        return $this->state->resource(ResourceType::Database);
    }

    public function state(): WorkspaceState
    {
        return $this->state;
    }

    /** @return list<string> */
    public function warnings(): array
    {
        $warnings = [];
        foreach ($this->ports() as $name => $port) {
            $variable = $this->variables->get($name);
            if ($variable !== null && $variable->value !== (string) $port) {
                $warnings[] = "Configured variable [{$name}]={$variable->value} overrides Harbour's allocated port {$port}.";
            }
        }

        return $warnings;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $database = $this->database();

        return [
            'id' => $this->identity()->id(),
            'slug' => $this->identity()->slug(),
            'branch' => $this->identity()->branch(),
            'path' => $this->state->path,
            'status' => $this->state->status,
            'ports' => $this->ports(),
            'application_url' => $this->variables->get('APP_URL')?->value,
            'database' => $database?->metadata['database'] ?? null,
            'resources' => array_map(static fn (OwnedResource $resource): array => $resource->diagnosticArray(), $this->state->resources),
            'warnings' => $this->warnings(),
        ];
    }
}
