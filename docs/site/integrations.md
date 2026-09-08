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

See the complete [Orca recipe](/orca/).

**Orca owns the worktree. Harbour owns the Laravel environment.**

Harbour has a fail-closed adapter for this repository lifecycle policy:

```yaml
scripts:
  setup: composer install --no-interaction && composer workspace:setup
  archive: composer workspace:teardown -- --force
```

Current Orca releases run archive hooks when CLI removal passes `--run-hooks`,
but still delete the checkout after a hook fails. Harbour therefore rejects
`--worktree-hooks=orca` before writing configuration. Track the upstream fix in
[stablyai/orca#19334](https://github.com/stablyai/orca/issues/19334).

Once a supported runtime exists, CLI removal will also need to request hooks
explicitly until Orca provides a repository-default policy:

```bash
orca worktree rm --worktree active --run-hooks --json
```

Harbour detects Orca's machine-readable CLI/runtime capabilities but does not
depend on Orca at runtime.

## Worktrunk

See the complete [Worktrunk guide](/worktrunk/).

Worktrunk is the first-class headless lifecycle integration. Opt in with:

```bash
php artisan workspace:install --detect --worktree-hooks=worktrunk --no-interaction
```

Its blocking `pre-start` pipeline installs Composer dependencies before setup.
Its blocking `pre-remove` hook tears down the Harbour environment while the
checkout and ownership state still exist. The real pinned `wt` release validates
configuration and exercises create, remove, teardown-failure, concurrency, and
merge paths in CI.

## Herdr

See the complete [Herdr recipe](/herdr/).

Run setup after Herdr creates or opens a worktree:

```bash
composer install --no-interaction
composer workspace:setup
```

Run teardown before `herdr worktree remove`. A post-removal hook is too late because Harbour's ownership state lived inside the checkout.

## Sail and Harbour

Sail is excellent when a Laravel project needs a complete, reproducible Docker
development stack. Harbour solves the narrower high-density case where many
parallel worktrees do not each need a duplicate application stack.

`workspace:install` detects Sail services and published `FORWARD_*` host ports.
Native PHP processes can then share a Sail-provided PostgreSQL or Redis service
while Harbour creates separate databases and prefixes. Harbour respects Sail's
ownership: it does not call Sail internals, rewrite its files, or start and stop
the stack.

## Isolated Docker service

Set a service's driver to `docker` when namespacing is insufficient. Harbour derives a safe container name, attaches ownership labels, maps allocated host ports, and records the container before startup.

## Workspace Compose project

Configure a Compose file when a dependency graph must be isolated. Harbour supplies a workspace-specific Compose project name and resolved variables. Teardown uses the recorded project and does not pass `-v`, preserving conservative volume behavior.
