<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Contracts;

use PickeringTech\Harbour\Process\ProcessResult;

interface CommandRunner
{
    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $environment
     * @param  null|callable(string, string): void  $output
     */
    public function run(array $command, string $workingDirectory, array $environment = [], ?callable $output = null): ProcessResult;
}
