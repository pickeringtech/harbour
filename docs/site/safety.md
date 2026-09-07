# Safety

Destructive-operation safety is Harbour's first design priority.

## Persisted ownership

`.harbour.json` records the exact ports and resources acquired by setup. Teardown consumes that evidence instead of reconstructing targets from current configuration, branch names, or `.env`.

State is schema-versioned and atomically replaced. Ownership is persisted immediately after each allocation or resource preparation so a killed setup remains recoverable.

## Environment preservation

Before replacing `.env`, Harbour stores an exact private backup and its checksum. Teardown restores that original file.

If a developer edits the Harbour-rendered `.env`, ordinary teardown stops before removing resources. Forced teardown archives the modified copy before restoring the original.
Setup and `workspace:render` also stop before overwriting that edit. Their
`--force` option authorizes replacement, while leaving all resource ownership
and path checks intact.

## Database guards

Database identifiers are derived through context-specific sanitizers. PostgreSQL and MySQL/MariaDB databases carry random internal ownership markers. Teardown refuses to drop a database whose marker does not match persisted state.

SQLite paths must remain within the workspace, and Harbour removes only the file it created.

## Docker and Compose guards

Docker containers require matching Harbour workspace and resource labels before deletion. Names alone never prove ownership.

Compose receives a collision-resistant project name. Harbour validates the recorded project and Compose file before invoking teardown and does not delete externally declared resources or volumes indiscriminately.

## Hostile local input

Branch names, paths, configuration, and template values are treated as untrusted. SQL identifiers are quoted after validation, processes receive argument arrays, and managed paths are constrained to known roots.

## Reversible project installation

`workspace:uninstall` first performs the same ownership-checked workspace
teardown, then removes only project policy that still carries Harbour's
generated marker, its exact Composer aliases, and its intact `.gitignore`
block. It does not infer ownership from a filename alone. Unmarked replacement
files, custom Composer scripts, symlinks, modified ownership blocks, and
pre-existing `.gitignore` lines are retained and identified for manual review.
Edits deliberately made inside a still-marked generated policy file remain
part of that policy and are removed with it.

Package removal remains a separate, transparent Composer operation because an
installed package cannot reliably remove itself while Composer is changing the
dependency graph.

## External worktree lifecycle

An external tool must run Harbour teardown before deleting a checkout because
the ownership evidence is stored inside that checkout. Worktrunk uses blocking
`pre-remove`, where failure aborts deletion. Orca's current `scripts.archive`
hook does not abort deletion after failure, so Harbour refuses to enable that
adapter. Harbour never schedules destructive post-removal cleanup, never
interpolates a raw path or branch into a shell command, and never creates or
deletes the worktree itself.

## Production protection

Harbour is intended as a development dependency and is enabled by default only
when `APP_ENV` is `local` or `testing`. Staging, prod, and custom environments
fail closed unless they intentionally set `HARBOUR_ENABLED=true`.

`--force` suppresses interaction only. It never bypasses resource ownership, environment checksum, database, Docker, Compose, or path guards.

Report suspected vulnerabilities privately according to the [security policy](https://github.com/pickeringtech/harbour/security/policy).
