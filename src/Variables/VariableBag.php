<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Variables;

final class VariableBag
{
    /** @var array<string, ResolvedVariable> */
    private array $variables = [];

    /** @param iterable<ResolvedVariable> $variables */
    public function __construct(iterable $variables = [])
    {
        foreach ($variables as $variable) {
            $this->put($variable);
        }
    }

    public function put(ResolvedVariable $variable): void
    {
        $this->variables[$variable->name] = $variable;
    }

    public function get(string $name): ?ResolvedVariable
    {
        return $this->variables[$name] ?? null;
    }

    /** @return array<string, ResolvedVariable> */
    public function all(): array
    {
        return $this->variables;
    }

    /** @return array<string, string> */
    public function values(): array
    {
        return array_map(static fn (ResolvedVariable $variable): string => $variable->value, $this->variables);
    }

    /** @return array<string, string> */
    public function persistable(): array
    {
        $values = [];

        foreach ($this->variables as $variable) {
            if ($variable->persist && ! $variable->isSensitive()) {
                $values[$variable->name] = $variable->value;
            }
        }

        return $values;
    }

    /** @return array<string, array{value: string, source: string, secret: bool, persisted: bool}> */
    public function debug(): array
    {
        $debug = [];

        foreach ($this->variables as $variable) {
            $sensitive = $variable->isSensitive();
            $debug[$variable->name] = [
                'value' => $sensitive ? '[REDACTED]' : $variable->value,
                'source' => $variable->source,
                'secret' => $sensitive,
                'persisted' => $variable->persist && ! $sensitive,
            ];
        }

        ksort($debug);

        return $debug;
    }
}
