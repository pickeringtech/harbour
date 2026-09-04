# Releasing Harbour

Harbour releases start from a version-only `release-intent.json` and are
reconciled from the append-only `releases.json` ledger. The merge to `main` is
the release approval boundary; the normal path uses one human PR and no local
tag, tag push, signing key, or `gh release` command.

## Normal one-PR release

1. In one release PR, move the relevant `Unreleased` entries in `CHANGELOG.md`
   under a dated `MAJOR.MINOR.PATCH` heading and change the single version in
   `release-intent.json`:

   ```json
   {
       "version": "v0.0.6"
   }
   ```

   Complete the usual dependency, ownership/security, fresh Laravel, and
   acceptance review. Do not edit `releases.json`.
2. Merge that PR with a normal merge commit. The changed intent resolves to
   that exact first-parent `main` commit, including the reviewed changelog.
3. After `CI` succeeds on that exact commit, `Release reconciliation` checks
   the complete required-check set again. The release App appends the resolved
   `version -> SHA` entry to `releases.json` in a one-file control-plane commit
   and pushes it without force. The same run then creates the verified
   annotated tag, drafts and publishes the immutable release, and checks that
   Packagist resolves the version to the same commit.
4. Read the workflow job summary. Every ledger entry is reported as already
   synchronized, tag created/release published, or failed closed.

The App's ledger commit contains `[skip ci]` because the package target is the
preceding human merge commit whose full CI result authorized the append. The
ledger commit is control-plane metadata and is intentionally not in the
package tag. A commit cannot name its own ID, so do not add a SHA or ref to the
human intent.

## Ledger and validation

`release-intent.json` has exactly one `version` string. It must either equal the
latest recorded ledger version (settled state) or be the next strictly greater
version (pending state). Pull-request validation rejects a human edit to
`releases.json`, a reverted/non-increasing intent, or an intent without an exact
non-empty changelog section. A pending intent cannot be replaced by another
version; wait for its ledger commit before opening the next release PR.

`releases.json` has schema version `1` and one ordered `releases` array. Each
generated entry has exactly two strings:

- `version`: unique, strictly increasing `vMAJOR.MINOR.PATCH` SemVer without
  prerelease/build fields or leading zeroes;
- `commit`: a lowercase, full 40-character commit object ID.

The first three entries permanently record v0.0.1-v0.0.3 at their historical
commits. Pull-request validation parses the whole file, compares it with the
base commit, and requires it to be unchanged; only the App path may append.
Before the App writes, validation proves the resolved target is a commit
reachable from `main`, extracts a non-empty exact changelog section, and
requires every release check to be successful on that target. PR validation
runs on `pull_request`, never `pull_request_target`, and receives no release
credential.

`tools/release.php` owns planning, validation, ledger append, and
reconciliation. Values are schema-validated before reaching Git, Markdown
extraction, URLs, or API paths. Git is invoked with argument arrays. Both the
ledger and a new signed tag are pushed with explicit non-force refspecs. The
ledger publisher refuses a dirty checkout, stages only `releases.json`, and
fails on a concurrent `main` update. The privileged job checks out the exact CI
target rather than newer, not-yet-authorizing code. Release writes use fixed
REST operations. There is no force push, tag-ref update/delete, or release-
delete operation; the only release update publishes a newly created or
recovered draft.

## Reconciliation states

Reconciliation is serialized under `release-reconciliation` without cancelling
an active run. It first reads every entry. Any inconsistent entry fails the
whole preflight before a write:

| Existing state | Result |
| --- | --- |
| Exact annotated tag and published release | No-op; verify Packagist. |
| Neither tag nor release | Create a verified tag object, create its ref, draft and publish the release. |
| Exact tag, no release | Draft and publish from that tag; do not recreate it. |
| Exact tag and draft release | Publish the existing draft. |
| Missing, lightweight, unverified, or wrong-commit tag behind a release | Fail closed. |
| Existing tag at another commit | Fail closed; never move, delete, or replace it. |

If ref creation reports that another actor won the race, the reconciler reads
the ref again and proceeds only when the annotated, verified tag is exact. A
failure after ref creation leaves the correct tag in place; rerunning creates
only the missing release. v0.0.1-v0.0.3 are exact compare-only history, so their
older signature/immutability state is reported without attempted rewriting.

Every newly published release must report `immutable: true`. GitHub's
[immutable release guidance](https://docs.github.com/en/code-security/concepts/supply-chain-security/immutable-releases)
also recommends this draft-then-publish sequence. Packagist stays webhook-
driven; the workflow polls only to verify the resulting version-to-SHA mapping.

## Release identity and credentials

Use a dedicated GitHub App installed only on this repository. Give the App
repository permissions `Contents: write`, `Checks: read`, and
`Administration: read`; grant nothing else. The workflow further narrows each
short-lived installation token to those permissions. `Administration: read`
is used only to confirm immutable releases before a write. `Contents: write`
authorizes the one-file ledger commit and new tag ref; the tooling exposes
neither a force push nor a ref update/delete path.

GitHub's Git-tag REST endpoint does not server-sign an annotated tag created by
an App installation token. Harbour therefore separates authorization from
signature identity:

- the dedicated App is the only normal actor allowed to push the new tag ref;
- `rpickz`, explicitly designated as the account-bound signing identity, owns a
  dedicated signing-only SSH public key; that key cannot authenticate or push;
- the matching private key is used only to sign the tag locally in the release
  job. It is not an authentication key and is never the owner's everyday key.

The fixed tagger identity is `rpickz
<31162594+rpickz@users.noreply.github.com>`. The reconciler writes the
private key to a mode-`0600` temporary file only while `git tag --sign` runs,
overwrites and removes that file, then pushes exactly the new tag with the App
token. It refetches the resulting object and requires GitHub to report an exact
annotated tag with `verification.verified=true` before creating a release.

Create a `release` environment without a manual approval rule and restrict its
deployment branches to `main`. Store:

- environment variable `RELEASE_APP_CLIENT_ID`;
- environment secret `RELEASE_APP_PRIVATE_KEY`;
- environment secret `RELEASE_SIGNING_PRIVATE_KEY`, containing the dedicated
  signing-only OpenSSH private key registered to `rpickz`.

Only `.github/workflows/release-reconciliation.yml` references them. Its
unprivileged detection job reads trusted `main`; the `release` environment and
App token are reached only for the matching successful CI commit or an explicit
manual recovery dispatch. The App private key authenticates token minting and
the separate SSH key signs Git tag objects as the explicitly designated
`rpickz` identity. The signing key is not the owner's everyday key and grants
no authentication capability. GitHub
verifies SSH-signed tags against the registered public signing key, as described
in its [SSH signature verification documentation](https://docs.github.com/en/authentication/managing-commit-signature-verification/about-commit-signature-verification).

Rotate the App private key by generating a replacement, updating the
environment secret, manually dispatching a no-op reconciliation, and only then
revoking the old key. For compromise, revoke all App keys and uninstall the
App before investigating; immutable releases and update/deletion protection
remain in force. Installation tokens are short-lived and the token action
revokes them after each job. Rotate the signing key separately: add its public
half to `rpickz`, replace `RELEASE_SIGNING_PRIVATE_KEY`, run a disposable
verified-tag proof, and only then remove the old public key.

## Tag rulesets and one-time proof

GitHub bypass applies to an entire ruleset, not one rule within it. To let the
App create but never update/delete `refs/tags/v*`, use two active repository
tag rulesets:

1. `Protect release tag history`: target `refs/tags/v*`, restrict update and
   deletion, and grant an always-allow bypass only to the GitHub user `rpickz`.
2. `Restrict release tag creation`: target `refs/tags/v*`, restrict creation,
   and grant always-allow bypass only to `rpickz` and the dedicated
   release App. Do not grant repository roles, administrators, writers, or the
   generic GitHub Actions integration.

The repository-ruleset API accepts a specific user as a bypass actor even when
the repository belongs to an organization. Use the `User` actor for `rpickz`;
do not substitute the organization-owner or repository-administrator role,
which would silently broaden emergency access.

Keep immutable releases enabled. GitHub documents both
[creation restrictions and App-specific bypass](https://docs.github.com/en/repositories/configuring-branches-and-merges-in-your-repository/managing-rulesets/creating-rulesets-for-a-repository#granting-bypass-permissions-for-your-branch-or-tag-ruleset).

Before enabling creation restriction in the production repository, reproduce
both rulesets in a disposable repository with the same three actors and prove:

- a normal writer cannot create, update, or delete a matching tag;
- `rpickz` can create and remove a disposable probe tag for emergency recovery;
- the App can create a new matching tag but cannot update or delete it.

Run `composer release:policy-test` against that disposable repository once per
actor. Set `HARBOUR_RELEASE_POLICY_INTEGRATION=1`, the actor name
(`non-bypass`, `owner`, or `release-app`), its short-lived token, two exact
commit IDs, and unique create/history probe tags through the corresponding
`HARBOUR_RELEASE_POLICY_*` variables. The test refuses non-`v*` probe names and
records no token values. Use the owner identity to remove the App-created probe
after the denial assertions have been recorded.

Then enable the creation ruleset here and run a declaration through review.
The reconciler's non-force tag push is the production confirmation that the App
receives the intended bypass. A denied create leaves no remote tag or release,
and a rerun converges after the ruleset is fixed.

## Main branch rule and one-time proof

GitHub bypass applies to the whole ruleset, so keep two active `main` rulesets:

1. `Protect main history`: block force pushes and deletion with no release-App
   bypass. Retain only the separately governed owner emergency access.
2. `Require reviewed main changes`: require a PR and the normal status checks,
   with an always-allow bypass only for the dedicated release App. Do not grant
   the generic GitHub Actions integration a bypass.

Normal maintainers continue to require a reviewed PR and successful checks.
The App bypasses those rules only for the generated control-plane commit; the
tooling independently requires the complete check set on the exact package
target before minting that commit and pushes only `HEAD:refs/heads/main`
without force.

In a disposable repository with the same branch rules, prove that a maintainer
cannot push directly, the App can push a one-file fast-forward commit, and the
App cannot non-fast-forward or delete `main`. Preserve that result with the tag
ruleset proof. Test the exact production rule shape before enabling this
workflow.

## Outages and emergency recovery

For a transient GitHub or Packagist outage, a partial failure after the ledger
push, or a non-fast-forward ledger conflict, rerun `Release reconciliation`
with `workflow_dispatch`. It intentionally has no version or SHA inputs. If the
intent is pending, it resolves the original first-parent intent commit, checks
that commit's required CI again, and retries the append. If the entry is already
present, it skips the append and reconciles the complete ledger. Do not edit an
existing entry or change the intent to force a retry.

Owner bypass is for investigation, not normal publication. If state disagrees
with the ledger, preserve the tag/release and workflow evidence, disable the
release App if compromise is possible, and investigate before changing any
remote object. The reconciler will never repair disagreement by mutation. Any
owner-only destructive recovery requires a separately reviewed incident plan;
never weaken branch protection, tag protection, coverage, mutation, or security
gates to make a release pass.
