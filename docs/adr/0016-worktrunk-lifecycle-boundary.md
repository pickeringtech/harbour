# ADR 0016: Worktrunk lifecycle boundary

## Decision

Harbour supports Worktrunk `0.76.x` through project-scoped `.config/wt.toml`.
An ordered blocking `pre-start` pipeline runs `composer install` and then
`composer workspace:setup`. Blocking `pre-remove` runs
`composer workspace:teardown -- --force` in the checkout being removed.

Harbour validates configuration with the real `wt` machine-readable interface,
preserves unrelated TOML text, recognizes an exact equivalent lifecycle, and
refuses partial or custom lifecycle hooks. Worktrunk remains solely responsible
for branch and worktree creation, switching, merging, and deletion.

## Rationale

Harbour's `.harbour.json` is the authoritative evidence for allocated ports,
database markers, environment restoration, and managed-resource ownership.
After a checkout is deleted that evidence is unavailable. A post-removal hook
therefore cannot perform trustworthy cleanup. `pre-remove` both retains the
evidence and propagates failure so Worktrunk can abort deletion.

Setup must finish before an agent begins using the checkout, so background
`post-start` is also unsuitable. Pipeline stages preserve the dependency between
Composer installation and Harbour setup without embedding machine paths or
branch names in shell commands.

## Consequences

- Integration is explicit; No and `--worktree-hooks=none` touch nothing.
- Conflicting hooks require a human composition decision.
- Worktrunk minor upgrades require contract review and real lifecycle CI before
  Harbour widens or advances its supported range.
- Harbour teardown remains idempotent and ownership-checked even when invoked by
  an external lifecycle manager.
