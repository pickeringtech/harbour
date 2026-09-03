<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Exceptions;

enum ErrorCode: string
{
    case StateCorrupted = 'HARBOUR_STATE_CORRUPTED';
    case StateWriteFailed = 'HARBOUR_STATE_WRITE_FAILED';
    case PortAllocationFailed = 'PORT_ALLOCATION_FAILED';
    case DatabaseCreationFailed = 'DATABASE_CREATION_FAILED';
    case DatabaseNotOwned = 'DATABASE_NOT_OWNED';
    case EnvironmentModified = 'ENVIRONMENT_MODIFIED';
    case DockerResourceNotOwned = 'DOCKER_RESOURCE_NOT_OWNED';
    case ComposeStartFailed = 'COMPOSE_START_FAILED';
    case UnresolvedVariable = 'UNRESOLVED_VARIABLE';
    case UnsafeOperation = 'UNSAFE_OPERATION';
    case InvalidConfiguration = 'INVALID_CONFIGURATION';
    case InstallSelectionRequired = 'INSTALL_SELECTION_REQUIRED';
    case InvalidInstallSelection = 'INVALID_INSTALL_SELECTION';
    case ProcessFailed = 'PROCESS_FAILED';
}
