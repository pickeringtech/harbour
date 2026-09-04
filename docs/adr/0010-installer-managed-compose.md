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

Existing `docker-compose.harbour.yml` files are never overwritten by default.
An explicit installer `--reconfigure` may replace only files carrying Harbour's
generated-file marker; unmarked project files remain protected. Teardown
uses the persisted project identity and snapshot, verifies Compose project
labels, and does not remove volumes by default.

Every supported dependency is defined once as a structured installation
service: group/label, aliases, image, ports and `FORWARD_*` variables, Compose
environment, volume, healthcheck, environment keys, and SQL lifecycle
capability. TUI choices, detection, generated policy, and Compose rendering are
projections of that definition. Discovery remains a documented Sail/Herd YAML
subset rather than a general parser or a collection of per-service regexes.

## Consequences

- A fresh project can go from component selection to a running workspace in one
  guided command.
- Shared infrastructure remains the default and lowest-overhead path.
- All generated host ports participate in Harbour's locked, concurrent registry.
- The generated images and service defaults become maintained compatibility
  surface and require Docker Compose integration coverage.
- Persistent volumes are conservative by default and may remain after project
  teardown.
