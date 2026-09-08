# ADR 0017: Orca lifecycle boundary

## Status

Proposed; blocked on [stablyai/orca#19334](https://github.com/stablyai/orca/issues/19334).

## Context

Orca owns tasks, branches, linked worktrees, agents, terminals, and checkout
removal. Harbour's teardown evidence lives in `.harbour.json` inside each
checkout, so cleanup after deletion cannot be safe. Orca supports repository
`orca.yaml` setup and archive commands, but archive hooks are not yet run by
default for CLI removal. More importantly, Orca `1.4.197` continues deletion
after an archive hook exits non-zero.

## Decision

Harbour reserves explicit `--worktree-hooks=orca` installation for the portable
lifecycle contract:

```yaml
scripts:
  setup: composer install --no-interaction && composer workspace:setup
  archive: composer workspace:teardown -- --force
```

Harbour detects Orca through its headless command schema and runtime status. The
adapter remains unsupported unless the runtime exposes a machine-readable
guarantee that archive failure blocks removal. Consequently, current releases
fail before configuration writes. The prepared parser does not embed machine
paths, branch names, secrets, or worktree paths; it accepts exact equivalents,
preserves unrelated text when safe, and refuses conflicts.

Orca must execute archive before deletion and propagate failure. Merely exposing
`--run-hooks` is not treated as sufficient capability. Until Orca has a
repository archive-run policy, the intended CLI removal includes `--run-hooks`.

## Consequences

- Selecting No or `--worktree-hooks=none` invokes no Orca command and touches no
  Orca configuration.
- Harbour never chains unknown project commands or creates/removes worktrees.
- No Orca release is currently selectable as supported.
- A future teardown failure must retain the checkout and ownership state for
  safe retry.
- A fixed Orca release must be pinned and lifecycle-tested before the adapter is
  advertised as first-class.
