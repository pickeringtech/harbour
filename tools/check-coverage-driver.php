<?php

declare(strict_types=1);

$pcovLoaded = extension_loaded('pcov');
$pcovEnabled = $pcovLoaded && filter_var(ini_get('pcov.enabled'), FILTER_VALIDATE_BOOL);
$xdebugLoaded = extension_loaded('xdebug');
$xdebugModes = $xdebugLoaded && function_exists('xdebug_info') ? xdebug_info('mode') : [];
$xdebugCoverage = is_array($xdebugModes) && in_array('coverage', $xdebugModes, true);

if ($pcovEnabled || $xdebugCoverage) {
    exit(0);
}

if ($pcovLoaded) {
    fwrite(STDERR, "Coverage is unavailable: PCOV is loaded but disabled. Enable pcov.enabled=1.\n");
} elseif ($xdebugLoaded) {
    fwrite(STDERR, "Coverage is unavailable: Xdebug coverage mode is disabled. Set XDEBUG_MODE=coverage.\n");
} else {
    fwrite(STDERR, "Coverage is unavailable: install and enable PCOV or Xdebug with coverage mode.\n");
}

exit(2);
