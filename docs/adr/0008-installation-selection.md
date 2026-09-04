# ADR 0008: Explicit installation selection

## Status

Accepted.

## Context

Copying one fixed `.env.harbour` during `workspace:install` silently selected
SQLite and a general-purpose set of Laravel namespace variables. That was safe,
but it was not an honest representation of projects using PostgreSQL, MySQL,
Redis, Mailpit, or other common Laravel development dependencies.

Laravel Sail established a familiar and effective service-selection vocabulary.
Harbour serves a different runtime model, but should not make developers
relearn the names of those services.

## Decision

`workspace:install` first asks interactive users to choose auto-detection or
manual selection. Manual selection uses Laravel Prompts' keyboard TUI: one
choice each for database, cache, and mail, followed by a true multi-select for
optional services. Automation must provide explicit flags. The command supports
Sail's current service names through `--with`, alongside category flags such as
`--database`, `--cache`, and `--mail`.

Harbour adds SQLite, file/database cache, log/array mail, and `none` choices
because these are useful native Laravel configurations that do not require a
service.

Selected infrastructure uses Harbour's `shared` provider by default. When a
manual selection includes service-backed components, the installer explicitly
offers the `compose` provider and can start the initial workspace. The same
choices are exposed through `--provider`, `--compose`, and `--start`. Sail is
excellent at providing a complete, reproducible Docker stack. Harbour's
deliberately narrower role is to configure workspace-safe access to existing
infrastructure when many native-PHP worktrees need to run concurrently.
Compose is offered when those worktrees need their own dependency services;
Laravel and Node still run natively.

Non-interactive installation without choices fails. Conflicting selections fail
before any project file is written. Existing `.env.harbour`, `config/harbour.php`,
`.gitignore`, and Composer scripts retain the package's non-destructive rules.

## Consequences

- Humans see and approve the project's infrastructure policy.
- Agents and CI can reproduce that policy with deterministic switches.
- Sail service names remain familiar while Harbour's shared-infrastructure
  philosophy stays intact.
- Optional components can be selected together without repeating the prompt.
- A project can acquire a ready-to-run Compose policy without hand-authoring
  infrastructure configuration.
- Selecting a shared service does not install or supervise that daemon; the
  generated file clearly records its loopback endpoint and prerequisites.
