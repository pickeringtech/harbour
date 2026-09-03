# ADR 0008: Explicit installation selection

## Status

Accepted.

## Context

Copying one fixed `.env.harbour` during `workspace:install` silently selected
SQLite and a general-purpose set of Laravel namespace variables. That was safe,
but it was not an honest representation of projects using PostgreSQL, MySQL,
Redis, Mailpit, or other common Laravel development dependencies.

Laravel Sail established a familiar service-selection vocabulary. Harbour has a
different runtime model, but should not make developers relearn the names of
those services.

## Decision

`workspace:install` asks interactive users to choose a database, cache, mail
transport, and optional services. Automation must provide explicit flags. The
command supports Sail's current service names through `--with`, alongside
category flags such as `--database`, `--cache`, and `--mail`.

Harbour adds SQLite, file/database cache, log/array mail, and `none` choices
because these are useful native Laravel configurations that do not require a
service.

Selected infrastructure uses Harbour's `shared` provider by default. This is a
deliberate difference from Sail: installation configures workspace-safe access
to existing infrastructure rather than creating a complete container stack.
Projects may subsequently change individual service entries to Docker or add a
Compose project using Harbour's existing explicit resource configuration.

Non-interactive installation without choices fails. Conflicting selections fail
before any project file is written. Existing `.env.harbour`, `config/harbour.php`,
`.gitignore`, and Composer scripts retain the package's non-destructive rules.

## Consequences

- Humans see and approve the project's infrastructure policy.
- Agents and CI can reproduce that policy with deterministic switches.
- Sail service names remain familiar while Harbour's shared-infrastructure
  philosophy stays intact.
- Selecting a shared service does not install or supervise that daemon; the
  generated file clearly records its loopback endpoint and prerequisites.
