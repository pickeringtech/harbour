<?php

declare(strict_types=1);

use PickeringTech\Harbour\Release\Command;

require dirname(__DIR__).'/vendor/autoload.php';

$arguments = $_SERVER['argv'] ?? [];
if (! is_array($arguments) || ! array_is_list($arguments)) {
    $arguments = [];
}
$arguments = array_values(array_filter($arguments, 'is_string'));

exit(Command::run($arguments, dirname(__DIR__)));
