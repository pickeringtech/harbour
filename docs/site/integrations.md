# Integrations

The boundary is simple: another tool owns checkout and process lifecycle; Harbour owns the Laravel workspace environment.

## Git worktrees

```bash
git worktree add ../acme-payment-retry feature/payment-retry
cd ../acme-payment-retry
composer install
composer workspace:setup
```

Before removal:

```bash
composer workspace:teardown -- --force
git worktree remove ../acme-payment-retry
```

Harbour also works in the primary checkout and in non-Git directories by falling back safely to path-based identity.

## Orca IDE

**Orca owns the worktree. Harbour owns the Laravel environment.**

Use this repository setup policy:

```bash
composer install --no-interaction && composer workspace:setup
```

Before Orca removes the worktree:

```bash
composer workspace:teardown -- --force
orca worktree rm --worktree active --force --json
```

Harbour does not detect Orca or depend on it.

## Herdr

Run setup after Herdr creates or opens a worktree:

```bash
composer install --no-interaction
composer workspace:setup
```

Run teardown before `herdr worktree remove`. A post-removal hook is too late because Harbour's ownership state lived inside the checkout.

## Shared Sail services

A project may publish a Sail PostgreSQL or Redis port to loopback and let native PHP processes share that service. Harbour creates separate databases and prefixes without calling Sail internals.

## Isolated Docker service

Set a service's driver to `docker` when namespacing is insufficient. Harbour derives a safe container name, attaches ownership labels, maps allocated host ports, and records the container before startup.

## Workspace Compose project

Configure a Compose file when a dependency graph must be isolated. Harbour supplies a workspace-specific Compose project name and resolved variables. Teardown uses the recorded project and does not pass `-v`, preserving conservative volume behavior.
