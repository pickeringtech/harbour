# ADR 0015: Lost database state recovers only within the same workspace

Status: accepted

Harbour persists random database resource IDs and ownership tokens in both its
local state and the database itself. A checkout may lose `.harbour.json` while
its database survives in shared infrastructure or a persistent Compose volume.
Creating new random evidence then makes a safe database appear to be an
unowned name collision.

A pending database creation may atomically replace stale marker evidence only
when all of these conditions hold:

- the derived database identity and connection still match the pending state;
- the database contains exactly one Harbour ownership row;
- the row's workspace ID exactly matches the current cryptographic workspace
  identity;
- both the existing and replacement resource IDs and tokens have Harbour's
  strict generated shapes; and
- the current resource is explicitly marked as creation pending.

Recovery updates the resource ID and token transactionally. It does not drop,
recreate, or clear the database, so normal migrations can converge its schema.
Confirmed resources never use this path.

An unmarked database, malformed or ambiguous marker, different workspace ID,
connection mismatch, or non-pending resource remains a hard ownership failure.
Teardown continues to require the complete replacement evidence persisted in
state. Thus state-loss recovery improves usability without allowing `--force`
or a matching database name to become destruction authority.
