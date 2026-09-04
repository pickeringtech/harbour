# Architecture

Harbour's Artisan commands adapt input and output around an injectable
`WorkspaceManager`. The manager is a small lifecycle facade; `SetupSequence`
and `TeardownSequence` own orchestration, with dedicated variable, database,
and managed-infrastructure collaborators. Orchestration remains outside the
console layer.
Harbour configuration is validated once into a typed value object, and each
lifecycle collaborator is registered in Laravel's container rather than
constructed inline by the manager.

![Harbour component boundaries](/images/architecture/components.svg)

## Lifecycle

![Harbour workspace lifecycle](/images/architecture/lifecycle.svg)

Setup records `preparing` before external mutation and persists every acquired
allocation or resource. Failures retain the recorded subset. A later setup may
finish only an explicitly pending SQL or Docker create; confirmed resources
whose ownership evidence vanished require teardown. Teardown walks resources
in reverse order and converges safely on `absent`.

## Domain boundaries

- Identity turns Git and path context into an immutable workspace identity.
- Context-specific identifier transformers produce safe database, Redis, cookie, Docker, Compose, and filesystem values.
- The port registry coordinates reservations across processes and workspaces.
- Variable resolution records value provenance and secret metadata.
- Database, Docker, and Compose adapters create and destroy owned resources.
- Environment management preserves and restores the checkout's original `.env`.
- Setup and teardown sequences encode the documented ordering without coupling it to console commands.
- A structured installation-service specification projects into TUI choices,
  detection aliases/ports, environment metadata, and readable Compose output.
- Owned-resource types are exhaustive enums, and lifecycle hooks execute
  through the same injectable command runner as Docker and Compose.

## Architectural decisions

The repository contains the complete rationale and invariants:

- [Workspace identity](https://github.com/pickeringtech/harbour/blob/main/docs/adr/0001-workspace-identity.md)
- [Resource ownership](https://github.com/pickeringtech/harbour/blob/main/docs/adr/0002-resource-ownership.md)
- [Port allocation](https://github.com/pickeringtech/harbour/blob/main/docs/adr/0003-port-allocation.md)
- [Environment restoration](https://github.com/pickeringtech/harbour/blob/main/docs/adr/0004-environment-restoration.md)
- [Shared, Docker, and Compose resources](https://github.com/pickeringtech/harbour/blob/main/docs/adr/0005-resource-modes.md)
- [Variable precedence](https://github.com/pickeringtech/harbour/blob/main/docs/adr/0006-variable-precedence.md)
- [Database lifecycle](https://github.com/pickeringtech/harbour/blob/main/docs/adr/0007-database-lifecycle.md)
- [Installer-managed Compose](https://github.com/pickeringtech/harbour/blob/main/docs/adr/0010-installer-managed-compose.md)
- [Safe enablement and pending creation](https://github.com/pickeringtech/harbour/blob/main/docs/adr/0011-safe-enablement-and-pending-creation.md)

Read the [full architecture document](https://github.com/pickeringtech/harbour/blob/main/docs/architecture.md) for state schemas, lock ordering, identifier rules, and teardown invariants.
