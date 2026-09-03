<?php

declare(strict_types=1);

if ($argc !== 5) {
    fwrite(STDERR, "Usage: php tools/verify-acceptance.php STATUS_A STATUS_B PROBE_A PROBE_B\n");
    exit(2);
}

/** @return array<string, mixed> */
function jsonFile(string $path): array
{
    $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    if (! is_array($decoded)) {
        throw new RuntimeException("Invalid acceptance JSON [{$path}].");
    }

    return $decoded;
}

function requireDifferent(mixed $first, mixed $second, string $name): void
{
    if (! is_scalar($first) || ! is_scalar($second) || $first === $second) {
        throw new RuntimeException("Acceptance values for {$name} are not distinct.");
    }
}

$statusA = jsonFile($argv[1]);
$statusB = jsonFile($argv[2]);
$probeA = jsonFile($argv[3]);
$probeB = jsonFile($argv[4]);
$workspaceA = $statusA['workspace'] ?? null;
$workspaceB = $statusB['workspace'] ?? null;
if (! is_array($workspaceA) || ! is_array($workspaceB)
    || ($workspaceA['status'] ?? null) !== 'ready'
    || ($workspaceB['status'] ?? null) !== 'ready') {
    throw new RuntimeException('Both acceptance workspaces must be ready.');
}

requireDifferent($workspaceA['id'] ?? null, $workspaceB['id'] ?? null, 'workspace identity');
requireDifferent($workspaceA['database'] ?? null, $workspaceB['database'] ?? null, 'database');
foreach (['APP_PORT', 'VITE_PORT', 'REVERB_PORT'] as $name) {
    requireDifferent($workspaceA['ports'][$name] ?? null, $workspaceB['ports'][$name] ?? null, $name);
}
foreach (['redis_prefix', 'cache_prefix', 'session_cookie', 'queue_name'] as $name) {
    requireDifferent($probeA[$name] ?? null, $probeB[$name] ?? null, $name);
}
if (($probeA['cache'] ?? null) !== 'workspace-a' || ($probeB['cache'] ?? null) !== 'workspace-b'
    || ($probeA['lock_acquired'] ?? null) !== true || ($probeB['lock_acquired'] ?? null) !== true
    || ($probeA['queue_size'] ?? null) !== 1 || ($probeB['queue_size'] ?? null) !== 1) {
    throw new RuntimeException('Laravel cache, lock, or queue isolation failed.');
}

echo "Two full Laravel workspaces are independently ready and isolated.\n";
