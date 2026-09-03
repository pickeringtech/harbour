# Orca IDE

**Orca owns the worktree, task, agent, and terminal. Harbour owns the Laravel environment inside that checkout.** Harbour does not detect Orca or depend on it.

Commit Harbour's generated project files first. Then use this repository setup policy in Orca:

```bash
composer install --no-interaction && composer workspace:setup
```

The first command restores PHP dependencies in the new worktree. The second creates its isolated environment.

Create a workspace and apply the repository policy:

```bash
orca worktree create --name payment-retry --setup run --json
```

`--setup inherit` uses the repository policy and is Orca's default; `--setup skip` deliberately bypasses it.

Before Orca removes the checkout:

```bash
composer workspace:teardown -- --force
orca worktree rm --worktree active --force --json
```

Ordering matters: Harbour's resource evidence lives inside the checkout, so teardown must complete while it still exists.
