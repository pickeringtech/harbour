# ADR 0004: Preserve and conditionally restore `.env`

Status: accepted

Before the first render, Harbour records whether `.env` existed and copies its
bytes into `.harbour/backups/env.original` with mode `0600`. State stores the
backup path and checksum, never the contents. Every Harbour render is atomic,
mode-preserving where possible, and its checksum is persisted.

Teardown restores the exact backup only while the current `.env` checksum
matches Harbour's last render. A mismatch means a person or tool edited it;
interactive teardown stops, while explicit `--force` archives that modified
file before restoring. If no original existed, the same rule governs deletion.

Setup and `workspace:render` enforce the recorded rendered checksum before
writing as well. They refuse a mismatch with `HARBOUR_ENVIRONMENT_MODIFIED` and
direct durable edits to `.env.harbour`; explicit `--force` authorizes replacing
the modified render. A first render has no recorded rendered checksum and is
therefore allowed.
