<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\State;

enum ResourceType: string
{
    case Database = 'database';
    case DockerContainer = 'docker_container';
    case ComposeProject = 'compose_project';
    case Unknown = 'unknown';
}
