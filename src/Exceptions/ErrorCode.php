<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Exceptions;

enum ErrorCode: string
{
    case StateCorrupted = 'HARBOUR_STATE_CORRUPTED';
    case StateWriteFailed = 'HARBOUR_STATE_WRITE_FAILED';
    case PortAllocationFailed = 'HARBOUR_PORT_ALLOCATION_FAILED';
    case DatabaseCreationFailed = 'HARBOUR_DATABASE_CREATION_FAILED';
    case DatabaseNotOwned = 'HARBOUR_DATABASE_NOT_OWNED';
    case EnvironmentModified = 'HARBOUR_ENVIRONMENT_MODIFIED';
    case DockerResourceNotOwned = 'HARBOUR_DOCKER_RESOURCE_NOT_OWNED';
    case ComposeStartFailed = 'HARBOUR_COMPOSE_START_FAILED';
    case UnresolvedVariable = 'HARBOUR_UNRESOLVED_VARIABLE';
    case UnsafeOperation = 'HARBOUR_UNSAFE_OPERATION';
    case InvalidConfiguration = 'HARBOUR_INVALID_CONFIGURATION';
    case InstallSelectionRequired = 'HARBOUR_INSTALL_SELECTION_REQUIRED';
    case InvalidInstallSelection = 'HARBOUR_INVALID_INSTALL_SELECTION';
    case ProcessFailed = 'HARBOUR_PROCESS_FAILED';
}
