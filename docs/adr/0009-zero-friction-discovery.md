# ADR 0009: Zero-friction project discovery

## Status

Accepted.

## Context

An installer that asks every project to restate configuration already present
in Sail, Herd, or Laravel environment files creates avoidable adoption work.
Conversely, silently starting services or rewriting another tool's files would
blur Harbour's ownership boundary and make teardown unsafe.

Vite's default `public/hot` marker is already workspace-local because Git
worktrees have separate working directories. Requiring every application to
customize both its JavaScript and PHP Vite configuration therefore adds setup
tax without improving the normal worktree case.

## Decision

`workspace:install` performs read-only discovery of conventional Compose files,
`herd.yml`, `.env`, and `.env.example`. It presents one inferred Harbour plan
and asks the user to approve it. Declining opens the detailed selectors.
Automation may accept the same deterministic proposal with `--detect`, while
explicit category options remain authoritative.

When no external service is detected, Harbour proposes SQLite, file cache, and
log mail. This gives a fresh Laravel checkout a functioning environment without
requiring Docker or a host service.

Sail and Herd remain owners of their configuration and service processes.
Harbour reads their declared services and published host ports, then creates
only Harbour's `.env.harbour`, `config/harbour.php`, Composer aliases, and ignore
entries. It never invokes, creates, rewrites, or tears down Sail or Herd state.

The generated environment uses Laravel Vite's default `public/hot` marker. The
package service provider applies `VITE_HOT_FILE` automatically when an advanced
project explicitly supplies one, keeping both sides compatible without an
`AppServiceProvider` edit.

## Consequences

- Existing Laravel projects normally accept one accurate proposal instead of
  answering several prompts.
- Agents get equivalent behavior through `workspace:install --detect`.
- Reusing Sail/Herd infrastructure remains transparent and non-destructive.
- A missing daemon is reported during setup rather than silently installed.
- Projects with shared or mapped public directories can retain explicit custom
  Vite hot-file configuration, while ordinary worktrees need no Vite changes.
