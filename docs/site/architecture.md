# Architecture

Harbour's Artisan commands adapt input and output around an injectable `WorkspaceManager`. Orchestration remains outside the console layer.

![Harbour component boundaries](/images/architecture/components.svg)

## Lifecycle

![Harbour workspace lifecycle](/images/architecture/lifecycle.svg)

Setup records `preparing` before external mutation and persists every acquired allocation or resource. Failures retain the recorded subset. Teardown walks resources in reverse order and converges safely on `absent`.

## Domain boundaries

- Identity turns Git and path context into an immutable workspace identity.
- Context-specific identifier transformers produce safe database, Redis, cookie, Docker, Compose, and filesystem values.
- The port registry coordinates reservations across processes and workspaces.
- Variable resolution records value provenance and secret metadata.
- Database, Docker, and Compose adapters create and destroy owned resources.
- Environment management preserves and restores the checkout's original `.env`.

## Architectural decisions

The repository contains the complete rationale and invariants:

- [Workspace identity](https://github.com/pickeringtech/harbour/blob/main/docs/adr/0001-workspace-identity.md)
- [Resource ownership](https://github.com/pickeringtech/harbour/blob/main/docs/adr/0002-resource-ownership.md)
- [Port allocation](https://github.com/pickeringtech/harbour/blob/main/docs/adr/0003-port-allocation.md)
- [Environment restoration](https://github.com/pickeringtech/harbour/blob/main/docs/adr/0004-environment-restoration.md)
- [Shared, Docker, and Compose resources](https://github.com/pickeringtech/harbour/blob/main/docs/adr/0005-resource-modes.md)
- [Variable precedence](https://github.com/pickeringtech/harbour/blob/main/docs/adr/0006-variable-precedence.md)

Read the [full architecture document](https://github.com/pickeringtech/harbour/blob/main/docs/architecture.md) for state schemas, lock ordering, identifier rules, and teardown invariants.
