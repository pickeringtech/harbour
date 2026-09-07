# Worktrunk

Worktrunk is Harbour's first-class, fully headless worktree lifecycle adapter.
**Worktrunk owns branches, worktrees, switching, merging, and removal. Harbour
owns only the Laravel environment inside each checkout.**

## Configure the project

Install a supported Worktrunk `0.76.x` release, then opt in from the primary
checkout:

```bash
php artisan workspace:install --detect --worktree-hooks=worktrunk --no-interaction
git add .config/wt.toml
git commit -m "Configure Harbour for Worktrunk"
```

Interactive installation first asks `Configure worktree lifecycle hooks?`.
Choosing No is exactly equivalent to `--worktree-hooks=none`: Harbour does not
read or write Worktrunk configuration and does not invoke `wt`.

Harbour writes only the project-scoped `.config/wt.toml` lifecycle commands:

```toml
[[pre-start]]
harbour-composer-install = "composer install"

[[pre-start]]
harbour-setup = "composer workspace:setup"

[pre-remove]
harbour-teardown = "composer workspace:teardown -- --force"
```

The two `pre-start` pipeline stages are blocking and ordered. A new worktree
does not proceed to its agent or execute step until Composer dependencies exist
and Harbour setup succeeds. `pre-remove` runs inside the target checkout while
`.harbour.json` still exists; any non-zero teardown exit aborts removal.
Harbour never places cleanup in background `post-start` or post-removal hooks.

On installation, Harbour asks the real `wt` binary to validate both the current
project configuration and the proposed TOML. Existing comments and unrelated
settings are preserved byte-for-byte. An exact equivalent lifecycle is accepted
without rewriting it. Partial, custom, malformed, or symlinked lifecycle
configuration fails closed for manual review instead of being overwritten or
silently chained.

`composer workspace:uninstall -- --force` removes the Worktrunk block only when
it still exactly matches Harbour's generated text. Custom or edited TOML is
retained for manual review.

## Create and switch

Approve the checked-in project commands at Worktrunk's first prompt, then create
a worktree normally:

```bash
wt switch --create feature/payment-retry
```

`pre-start` runs exactly once during creation. Switching to an existing
worktree does not rerun it. Harbour setup is convergent, so an interrupted setup
can safely be retried directly:

```bash
composer workspace:setup
```

To run the application after switching:

```bash
composer workspace:dev
```

## Merge and remove

Both Worktrunk paths invoke the same blocking `pre-remove` hook:

```bash
wt merge main
wt remove feature/payment-retry
```

If Harbour cannot prove resource ownership, restore `.env` safely, or complete
teardown, the command fails and Worktrunk retains the checkout. Fix the reported
problem and retry; do not delete `.harbour.json` or bypass hooks to force cleanup.
A successful removal deletes only that checkout's recorded Harbour resources.
Other workspaces and shared infrastructure remain operational.

## Status and troubleshooting

```bash
composer workspace:status
php artisan workspace:status --json
wt hook show --format=json
wt hook pre-start --dry-run
wt hook pre-remove --dry-run
```

Harbour status reports Worktrunk's version, supported range, full-lifecycle
capability, the three required hook stages, and any conflict. Worktrunk project
commands require approval on first execution; an approval prompt is Worktrunk's
security boundary, not a failed Harbour setup. Use Worktrunk's normal approval
flow rather than changing the checked-in commands.

Harbour supports `>=0.76.0 <0.77.0`. Each new Worktrunk minor series is reviewed
against its configuration schema and meaningful create/remove/merge lifecycle,
then CI's exact pinned release and this supported range advance together. Patch
updates within `0.76.x` remain compatible; CI keeps `0.76.0` as the minimum
contract target.
