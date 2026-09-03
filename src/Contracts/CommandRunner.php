<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Contracts;

use PickeringTech\Harbour\Process\ProcessResult;

interface CommandRunner
{
    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $environment
     */
    public function run(array $command, string $workingDirectory, array $environment = []): ProcessResult;
}
