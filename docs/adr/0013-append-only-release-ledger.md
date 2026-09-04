# ADR 0013: Releases reconcile from pre-existing commits

Status: accepted

Harbour's desired release state is an append-only `releases.json` ledger. A
release declaration names a strict SemVer tag and the full object ID of an
already-existing commit reachable from `main`. Release content and its
declaration therefore use two pull requests. The declaration commit is control-
plane metadata, not package content, avoiding an impossible self-referential
commit ID and ensuring CI has already completed on the exact package tree.

Existing ledger entries and remote tags are compare-only. Validation forbids
editing, reordering, or removing an entry. Reconciliation may create a missing
verified annotated tag, draft a missing release, or publish that draft, but it
has no update or delete operation. Any lightweight, unverifiable, missing, or
wrong-target existing tag fails closed for owner investigation. This preserves
evidence and makes retries converge after partial failure without rewriting
history.

A dedicated GitHub App supplies a short-lived, repository-scoped token. GitHub
creates and verifies the bot tag object before the reconciler creates its ref;
no human signing key is stored in Actions. Immutable releases are checked before
writes and releases are drafted before publication. Tag creation protection is
separate from update/deletion protection because GitHub grants bypass per
ruleset: the App may bypass only the creation ruleset, while only the owner has
emergency bypass over historical mutation rules through a direct user bypass.
