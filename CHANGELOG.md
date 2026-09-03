# Changelog

All notable changes will be documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and releases follow
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

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

[Unreleased]: https://github.com/pickeringtech/harbour/compare/v0.0.1...HEAD
[0.0.1]: https://github.com/pickeringtech/harbour/releases/tag/v0.0.1
