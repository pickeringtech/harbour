# ADR 0013: Releases reconcile from pre-existing commits

Status: accepted

Harbour's desired release state is an append-only `releases.json` ledger. A
release declaration names a strict SemVer tag and the full object ID of an
already-existing commit reachable from `main`.

Release preparation uses one human PR containing the changelog section and a
version-only `release-intent.json`. The exact target is the first-parent `main`
commit that changed that intent. After full CI succeeds on that commit, the
release App appends the resolved version and 40-character SHA in a one-file,
non-force control-plane commit. This avoids an impossible self-reference while
keeping the package target, human approval, and exact successful CI result
identical. A concurrent branch update rejects the push; retry resolution is
idempotent and retains the original intent-change target.

Existing ledger entries and remote tags are compare-only. Validation forbids
editing, reordering, or removing an entry. Reconciliation may create a missing
verified annotated tag, draft a missing release, or publish that draft, but it
has no update or delete operation. Any lightweight, unverifiable, missing, or
wrong-target existing tag fails closed for owner investigation. This preserves
evidence and makes retries converge after partial failure without rewriting
history.

A dedicated GitHub App supplies a short-lived, repository-scoped token and is
the only normal identity allowed to append the ledger directly to `main` or
push a new release-tag ref. Both use explicit non-force refspecs; branch and tag
rules prohibit history rewrites and deletion. GitHub does not
server-sign annotated tags created through the Git-tag REST endpoint for an App
installation token, so authentication and signing credentials are deliberately
separate. `rpickz` is explicitly designated as the account-bound signature
identity and owns a dedicated signing-only SSH public key; its private half
cannot authenticate or push. The App then pushes one explicit non-force
refspec, and the reconciler refetches the object and requires GitHub
verification before it creates a release. No everyday owner signing key is
stored in Actions.

Immutable releases are checked before writes and releases are drafted before
publication. Tag creation protection is separate from update/deletion
protection because GitHub grants bypass per ruleset: the App may bypass only the
creation ruleset, while only the owner has emergency bypass over historical
mutation rules through a direct user bypass.
