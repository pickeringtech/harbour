<?php

declare(strict_types=1);

if ($argc !== 2) {
    fwrite(STDERR, "Usage: php tools/check-coverage.php <clover.xml>\n");

    exit(2);
}

$path = $argv[1];
$configurationPath = dirname(__DIR__).'/composer.json';
$configuration = json_decode((string) @file_get_contents($configurationPath), true);
$minimum = is_array($configuration) ? ($configuration['extra']['harbour']['coverage-minimum'] ?? null) : null;

if (! is_int($minimum) || $minimum < 0 || $minimum > 100) {
    fwrite(STDERR, "Coverage threshold in [composer.json] must be an integer from 0 to 100.\n");

    exit(2);
}

if (! is_file($path)) {
    fwrite(STDERR, "Coverage report [{$path}] does not exist.\n");

    exit(2);
}

$document = new DOMDocument;
$previous = libxml_use_internal_errors(true);
$loaded = $document->load($path, LIBXML_NONET);
libxml_clear_errors();
libxml_use_internal_errors($previous);

if (! $loaded) {
    fwrite(STDERR, "Coverage report [{$path}] is not valid XML.\n");

    exit(2);
}

$statements = 0;
$covered = 0;
$nodes = (new DOMXPath($document))->query('//file/metrics');

if ($nodes === false) {
    fwrite(STDERR, "Coverage report [{$path}] cannot be queried.\n");

    exit(2);
}

foreach ($nodes as $node) {
    if (! $node instanceof DOMElement) {
        continue;
    }
    $nodeStatements = $node->getAttribute('statements');
    $nodeCovered = $node->getAttribute('coveredstatements');
    if (preg_match('/\A\d+\z/', $nodeStatements) !== 1
        || preg_match('/\A\d+\z/', $nodeCovered) !== 1
        || (int) $nodeCovered > (int) $nodeStatements) {
        fwrite(STDERR, "Coverage report [{$path}] contains invalid statement metrics.\n");

        exit(2);
    }
    $statements += (int) $nodeStatements;
    $covered += (int) $nodeCovered;
}

if ($statements === 0) {
    fwrite(STDERR, "Coverage report [{$path}] contains no executable statements.\n");

    exit(2);
}

$percentage = $covered / $statements * 100;
$summary = sprintf(
    'Statement coverage: %.2f%% (%d/%d); required: %.2f%%',
    $percentage,
    $covered,
    $statements,
    $minimum,
);

if ($covered * 100 < $minimum * $statements) {
    fwrite(STDERR, $summary."\nCoverage threshold not met.\n");

    exit(1);
}

fwrite(STDOUT, $summary."\nCoverage threshold met.\n");
