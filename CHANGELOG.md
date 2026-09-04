# Changelog

All notable changes will be documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and releases follow
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Releases are now reconciled from a reviewed append-only manifest into
  verified annotated tags, immutable GitHub releases, and checked Packagist
  mappings without a maintainer's local signing or publication ceremony.

## [0.0.3] - 2026-09-04

### Security

- Server databases now count as existing only when their persisted
  `_harbour_ownership` marker matches, and confirmed databases/containers that
  later disappear are never silently recreated.
- Harbour now enables destructive lifecycle commands by default only in
  `local` and `testing`; every other environment must explicitly set
  `HARBOUR_ENABLED=true`.
- All machine error codes now use the `HARBOUR_` prefix. Consumers of
  `error.code` must update for this intentional pre-1.0 contract change.

### Added

- The installer can generate a workspace-managed `docker-compose.harbour.yml`
  for any supported Sail-compatible service selection and optionally start the
  first workspace immediately.
- `workspace:install` now exposes deterministic `--provider`, `--compose`, and
  `--start` options for agents and CI.
- Generated Compose services have real validation, lifecycle, readiness,
  database connectivity, idempotency, and teardown coverage.
- Process failures expose a bounded, redacted stderr tail in human and JSON
  output, and setup reports when managed Compose images may be pulled.
- Successful setup prints the native `php artisan serve` command, while JSON
  installer starts include the resulting workspace payload.

### Changed

- Interactive installation now begins with an explicit auto-detect or manual
  choice and uses Laravel Prompts' keyboard TUI throughout.
- Additional components use a true multi-select control instead of repeated or
  numeric choices.
- Managed Docker/Compose infrastructure starts and becomes ready before Harbour
  creates its logical database or runs migrations.
- Failed SQL/Docker creation retries only from an explicitly persisted pending
  record; confirmed missing resources require teardown before setup can retry.
- Generated Compose images are pinned and every service has a readiness
  healthcheck. MongoDB uses the official lightweight image and remains a
  connection-only database selection.
- Reused ports are bind-checked again, failed state remains attached across
  intermediate state copies, and setup retries re-enter `preparing`.
- Non-interactive fresh setup and teardown fail unless `--force` is passed.
- Installation preserves existing Composer JSON formatting where possible and
  gives exact removal instructions for protected files that prevent
  reconfiguration.
- Setup and teardown orchestration now lives in explicit lifecycle sequences,
  with variable, database, hook, and managed-infrastructure responsibilities
  separated from the `WorkspaceManager` facade.

## [0.0.2] - 2026-09-03

### Changed

- `workspace:install` now detects Sail, Compose, Herd, `.env`, and
  `.env.example`, presents one reviewable proposal, and supports deterministic
  non-interactive discovery through `--detect`.
- Projects without configured infrastructure are offered an explicit
  zero-dependency SQLite, file-cache, and log-mail setup.
- Detected host ports and existing credential placeholders are preserved
  without including secret values in state or diagnostics.
- Standard Laravel Vite projects now use their already workspace-local
  `public/hot` file with no application code changes; explicitly configured
  custom hot files are applied on Laravel's side by Harbour.
- GitHub Pages now separates the concise README from task-oriented guides for
  workspaces, environment templates, ports, databases, Laravel state,
  Vite/Reverb, Docker, Compose, hooks, extension points, and debugging.
- `workspace:install` now interactively selects database, cache, mail, and
  optional shared services instead of assuming SQLite.
- Added deterministic `--database` / `-d`, `--cache` / `-c`, `--mail` / `-m`,
  and Sail-compatible `--with` installation options.
- Non-interactive installation now requires explicit choices and generated
  configuration records their shared-infrastructure provenance.

## [0.0.1] - 2026-09-03

### Added

- Initial Laravel 13 package architecture.
- Workspace identity, target-safe identifiers, and real Git/worktree support.
- Locked port registry and concurrent multi-process allocation.
- Versioned atomic state and persisted resource ownership.
- `.env.harbour` rendering with safe preservation/restoration.
- PostgreSQL, MySQL/MariaDB, and SQLite lifecycle drivers with ownership markers.
- Laravel Redis/cache/session/queue/Horizon, Vite, and Reverb variables.
- Lifecycle events, hooks, machine-readable commands, and programmatic API.
- Optional labelled Docker containers and isolated Compose projects.
- Enforced 95% line coverage with service-backed coverage, property/fuzz tests,
  and mutation-quality gates in CI.
- Generated documentation pipeline that renders Mermaid source from Markdown
  templates into committed SVG diagrams and rejects stale artifacts in CI.
- A non-destructive `workspace:install` command for the one-time project setup.
- An eight-page GitHub Pages documentation site with light and dark themes.
- A full two-worktree Laravel acceptance job covering PostgreSQL, Redis cache,
  locks, queues, Docker, Compose, independent teardown, and failure recovery.

[Unreleased]: https://github.com/pickeringtech/harbour/compare/v0.0.3...HEAD
[0.0.3]: https://github.com/pickeringtech/harbour/compare/v0.0.2...v0.0.3
[0.0.2]: https://github.com/pickeringtech/harbour/compare/v0.0.1...v0.0.2
[0.0.1]: https://github.com/pickeringtech/harbour/releases/tag/v0.0.1
