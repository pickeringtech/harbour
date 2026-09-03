# Workspaces

A Harbour workspace is the current Laravel checkout plus its persisted identity, allocations, variables, and owned resources. Harbour accepts the checkout as input; it never creates branches or worktrees.

## Git worktree workflow

```bash
git worktree add ../acme-payment-retry feature/payment-retry
cd ../acme-payment-retry
composer install
composer workspace:setup
```

Before Git removes it:

```bash
composer workspace:teardown -- --force
git worktree remove ../acme-payment-retry
```

The same setup command works in the primary checkout, a normal clone, detached HEAD, or a non-Git directory. When Git metadata is unavailable, identity falls back to repository and path evidence.

## Identity

The default strategy combines repository identity, checkout path, and available Git metadata. A readable slug is useful for output, while a collision-resistant hash anchors ownership. Branch text alone is insufficient: two repositories may share a branch name, HEAD may be detached, and checkouts may be moved.

Raw branch names never become SQL, shell, Docker, cookie, hostname, or filesystem identifiers. Each target has its own constrained transformation.

## Lifecycle

Setup moves through identity, port allocation, environment preservation, database preparation, optional services, variable resolution, rendering, migrations, hooks, and ready state. Ownership is persisted after each material acquisition, so partial setup remains recoverable.

Repeated setup converges on the recorded state. `workspace:setup --fresh --force` first removes only proven-owned resources, then recreates them. It never means “drop the currently configured database.”

Teardown reads persisted targets instead of deriving destructive names from current configuration. This is why it should run before an external tool deletes the checkout.

## Concurrent workspaces

Different workspaces coordinate ports through an XDG machine registry and own separate logical resources. Setup of the same checkout and setup-versus-teardown are serialized through a checkout lifecycle lock.

See [Ports](/ports/) for reservation guarantees and [Safety](/safety/) for destructive-operation guards.
