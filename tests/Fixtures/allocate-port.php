<?php

declare(strict_types=1);

use PickeringTech\Harbour\Ports\FilePortRegistry;
use PickeringTech\Harbour\Ports\PortRequirement;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$arguments = cliArguments($_SERVER['argv'] ?? null);
if (count($arguments) !== 4) {
    fwrite(STDERR, "Invalid allocator arguments.\n");
    exit(2);
}
[$script, $registry, $workspaceId, $workspacePath] = $arguments;
$allocation = (new FilePortRegistry($registry))->reserve(
    $workspaceId,
    $workspacePath,
    new PortRequirement('APP_PORT', 18500, 18599),
);

fwrite(STDOUT, (string) $allocation->port);

/** @return list<string> */
function cliArguments(mixed $value): array
{
    if (! is_array($value) || ! array_is_list($value)) {
        return [];
    }
    $arguments = [];
    foreach ($value as $argument) {
        if (! is_string($argument)) {
            return [];
        }
        $arguments[] = $argument;
    }

    return $arguments;
}
