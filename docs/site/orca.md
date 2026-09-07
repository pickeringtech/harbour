# Orca IDE

Orca lifecycle support is implemented but intentionally disabled pending an
upstream deletion-safety fix. **Orca owns the task, branch, worktree, agent,
terminal, and checkout removal. Harbour owns only the isolated Laravel
environment inside that checkout.**

The reserved explicit selection is:

```bash
php artisan workspace:install --detect --worktree-hooks=orca --no-interaction
```

No current Orca release passes Harbour's blocking archive contract, so this
command reports `HARBOUR_INTEGRATION_UNAVAILABLE` before changing project files.
Pinned Orca `1.4.197` logs a non-zero archive hook and then deletes the checkout;
track [stablyai/orca#19334](https://github.com/stablyai/orca/issues/19334).

Interactive installation first asks `Configure worktree lifecycle hooks?`.
Choosing No is exactly equivalent to `--worktree-hooks=none`: Harbour does not
read or write `orca.yaml` and does not invoke Orca. Once a supported release is
available, choosing Orca will authorize one project-scoped lifecycle change.

Harbour will generate or validate this repository policy:

```yaml
scripts:
  setup: composer install --no-interaction && composer workspace:setup
  archive: composer workspace:teardown -- --force
```

The setup command runs from the new checkout. Composer dependencies are restored
before Harbour allocates ports, prepares the database and services, renders the
environment, and runs migrations. Repeating setup is safe and keeps the same
workspace identity and owned resources.

The archive command must be blocking and run while the checkout and
`.harbour.json` ownership evidence still exist. Current Orca releases run it but
do not prevent deletion after a non-zero result, which is why Harbour fails
closed.

## Existing `orca.yaml`

Harbour parses YAML before writing. It preserves unrelated configuration and
comments, recognizes exact equivalent setup/archive commands without mutation,
and can add missing keys to a simple block mapping without rewriting the file
once a supported runtime is available.
Malformed YAML, symlinks, unsafe file types, flow-style partial mappings, and
conflicting commands fail before any project file changes.

Harbour never silently chains project-authored setup or archive commands. A
conflict reports this exact manual merge target:

```yaml
scripts:
  setup: composer install --no-interaction && composer workspace:setup
  archive: composer workspace:teardown -- --force
```

`--reconfigure` cannot replace an unmarked or modified file. An exact
project-authored equivalent remains project-owned and is left byte-for-byte
unchanged. `workspace:uninstall` removes only the exact Harbour-owned block;
equivalent project configuration is retained.

## Create and setup

When the upstream contract is fixed and a release is validated, worktree setup
will use the repository policy:

```bash
orca worktree create --name payment-retry --setup run --json
```

`--setup inherit` uses the repository policy and is Orca's default;
`--setup skip` deliberately bypasses it. Teams can also configure the repository
policy to run setup by default. Harbour does not create the branch or worktree.

## Archive and removal

Current Orca CLI removal only invokes repository archive hooks when it explicitly
opts in:

```bash
orca worktree rm --worktree active --run-hooks --json
```

However, Orca `1.4.197` still deletes the checkout when that hook fails, even
without `--force`. Therefore this command is documentation of the intended
future flow, not a currently supported Harbour removal path. The safe manual
workaround is to run `composer workspace:teardown -- --force` yourself, check
that it succeeded, and only then ask Orca to remove the checkout.

Successful removal of one worktree tears down only its Harbour-owned database,
ports, Compose project, containers, and rendered environment. Sibling
workspaces and shared services remain operational.

## Capability and status contract

Harbour currently supports no Orca release. It calls the local, machine-readable
`agent-context --json` and runtime status schemas to distinguish setup/archive
hook availability from a verified guarantee that archive failure blocks
removal. Orca is not a Composer or Harbour runtime dependency.

```bash
php artisan workspace:status --json
```

The `worktree_integrations.orca` object distinguishes absent, equivalent,
Harbour-managed, conflicting, unsupported, and fully configured states. It also
reports setup policy, archive-hook availability, verified blocking behavior,
detected version, exact hooks, conflict details, and the future removal command.

`composer probe:orca` loads the generated `orca.yaml` through a real runtime and
exercises ordered setup, retry, two simultaneous worktrees, archive failure, and
independent teardown. It currently fails at the required deletion guard on Orca
`1.4.197`. Once upstream fixes the behavior, a pinned passing release and CI job
are required before Harbour advertises first-class support.
