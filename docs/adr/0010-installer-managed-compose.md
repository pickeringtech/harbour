# ADR 0010: Installer-managed Compose dependencies

## Status

Accepted.

## Context

Shared services are Harbour's preferred high-density model, but they cannot be
assumed to exist on every development machine. Requiring users to hand-author a
Compose file after choosing service-backed components creates friction at the
exact point where the installer should make a project usable.

Generating a complete application runtime would duplicate Sail and violate
Harbour's native PHP and Node boundary. Generating only selected dependency
services preserves that boundary.

## Decision

The manual installer offers one provider choice for service-backed components:
reuse shared infrastructure or generate a workspace-managed Compose project.
Compose mode writes a readable `docker-compose.harbour.yml` and records a named
Harbour port allocation for every published container port. The generated file
contains only selected dependencies; it does not containerize PHP, Artisan,
Node, Vite, or Reverb.

The user separately decides whether to start the workspace immediately.
Automation receives equivalent `--provider=compose` / `--compose` and `--start`
flags.

Setup persists the Compose ownership record before invoking `up`, passes
arguments as an array, uses a collision-resistant project name, and waits for
declared service health with a bounded timeout. Managed infrastructure starts
before database creation and migrations. Thus a database driver never races a
container that is still starting, and partial Compose failure retains enough
state for safe teardown.

Existing `docker-compose.harbour.yml` files are never overwritten. Teardown
uses the persisted project identity and snapshot, verifies Compose project
labels, and does not remove volumes by default.

## Consequences

- A fresh project can go from component selection to a running workspace in one
  guided command.
- Shared infrastructure remains the default and lowest-overhead path.
- All generated host ports participate in Harbour's locked, concurrent registry.
- The generated images and service defaults become maintained compatibility
  surface and require Docker Compose integration coverage.
- Persistent volumes are conservative by default and may remain after project
  teardown.
