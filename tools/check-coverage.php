<?php

declare(strict_types=1);

if ($argc !== 3 || ! is_numeric($argv[2]) || (float) $argv[2] < 0 || (float) $argv[2] > 100) {
    fwrite(STDERR, "Usage: php tools/check-coverage.php <clover.xml> <minimum-percent>\n");

    exit(2);
}

$path = $argv[1];
$minimum = (float) $argv[2];

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
    $statements += (int) $node->getAttribute('statements');
    $covered += (int) $node->getAttribute('coveredstatements');
}

if ($statements === 0) {
    fwrite(STDERR, "Coverage report [{$path}] contains no executable statements.\n");

    exit(2);
}

$percentage = $covered / $statements * 100;
$summary = sprintf(
    'Line coverage: %.2f%% (%d/%d); required: %.2f%%',
    $percentage,
    $covered,
    $statements,
    $minimum,
);

if ($percentage + PHP_FLOAT_EPSILON < $minimum) {
    fwrite(STDERR, $summary."\nCoverage threshold not met.\n");

    exit(1);
}

fwrite(STDOUT, $summary."\nCoverage threshold met.\n");
