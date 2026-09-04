# Changelog

All notable changes will be documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and releases follow
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- The installer can generate a workspace-managed `docker-compose.harbour.yml`
  for any supported Sail-compatible service selection and optionally start the
  first workspace immediately.
- `workspace:install` now exposes deterministic `--provider`, `--compose`, and
  `--start` options for agents and CI.
- Generated Compose services have real validation, lifecycle, readiness,
  database connectivity, idempotency, and teardown coverage.

### Changed

- Interactive installation now begins with an explicit auto-detect or manual
  choice and uses Laravel Prompts' keyboard TUI throughout.
- Additional components use a true multi-select control instead of repeated or
  numeric choices.
- Managed Docker/Compose infrastructure starts and becomes ready before Harbour
  creates its logical database or runs migrations.

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

[Unreleased]: https://github.com/pickeringtech/harbour/compare/v0.0.2...HEAD
[0.0.2]: https://github.com/pickeringtech/harbour/compare/v0.0.1...v0.0.2
[0.0.1]: https://github.com/pickeringtech/harbour/releases/tag/v0.0.1
