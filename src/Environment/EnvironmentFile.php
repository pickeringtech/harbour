<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Environment;

final class EnvironmentFile
{
    /** @return array<string, string> */
    public function parse(string $contents): array
    {
        $values = [];

        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            if (! preg_match('/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)\s*$/', $line, $matches)) {
                continue;
            }

            $value = trim($matches[2]);
            if (strlen($value) >= 2 && (($value[0] === '"' && str_ends_with($value, '"')) || ($value[0] === "'" && str_ends_with($value, "'")))) {
                $value = substr($value, 1, -1);
            }
            $values[$matches[1]] = $value;
        }

        return $values;
    }
}
