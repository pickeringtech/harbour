<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Environment;

use PickeringTech\Harbour\Exceptions\ErrorCode;
use PickeringTech\Harbour\Exceptions\HarbourException;

final class EnvironmentTemplate
{
    /** @return list<string> */
    public function variables(string $template): array
    {
        preg_match_all('/\\$\\{([A-Za-z_][A-Za-z0-9_]*)\\}/', $template, $matches);

        return array_values(array_unique($matches[1]));
    }

    /** @param array<string, scalar|null> $variables */
    public function render(string $template, array $variables): string
    {
        $rendered = preg_replace_callback('/\$\{([A-Za-z_][A-Za-z0-9_]*)\}/', function (array $match) use ($variables): string {
            $name = $match[1];

            if (! array_key_exists($name, $variables) || $variables[$name] === null) {
                throw new HarbourException(
                    ErrorCode::UnresolvedVariable,
                    "Environment template variable [{$name}] is unresolved.",
                    ['variable' => $name],
                );
            }

            $value = $variables[$name];

            return is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        }, $template);

        return $rendered ?? $template;
    }
}
