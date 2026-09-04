# Releasing Harbour

Harbour releases are reconciled from the append-only `releases.json` ledger.
The merge to `main` is the release approval boundary; the normal path uses no
local tag, tag push, signing key, or `gh release` command.

## Normal two-PR release

1. In a release-content PR, move the relevant `Unreleased` entries in
   `CHANGELOG.md` under a dated `MAJOR.MINOR.PATCH` heading. Complete the usual
   dependency, ownership/security, fresh Laravel, and acceptance review.
2. Merge that PR and wait for every required CI check on its exact `main`
   commit to succeed.
3. In a separate release-declaration PR, append one object to `releases.json`:

   ```json
   {
       "version": "v0.0.4",
       "commit": "0123456789abcdef0123456789abcdef01234567"
   }
   ```

4. Merge the declaration PR. `Release reconciliation` creates the verified
   annotated tag at the declared commit, creates a draft release using that
   commit's changelog section, publishes it as immutable, and checks that
   Packagist resolves the version to the same commit.
5. Read the workflow job summary. Every ledger entry is reported as already
   synchronized, tag created/release published, or failed closed.

The declaration commit is control-plane metadata and is intentionally not in
the package tag. A manifest commit cannot name its own commit ID, so do not
introduce a self-referential placeholder or replace the exact SHA with a ref.

## Ledger and validation

`releases.json` has schema version `1` and one ordered `releases` array. Each
entry has exactly two strings:

- `version`: unique, strictly increasing `vMAJOR.MINOR.PATCH` SemVer without
  prerelease/build fields or leading zeroes;
- `commit`: a lowercase, full 40-character commit object ID.

The first three entries permanently record v0.0.1-v0.0.3 at their historical
commits. Pull-request validation parses the whole file, compares it with the
base commit, and rejects editing, reordering, or removing any existing entry.
It also proves each target is a commit reachable from `main`, extracts a
non-empty exact changelog section, and requires all release checks to have
succeeded for each appended non-historical entry. It runs on `pull_request`,
never `pull_request_target`, and receives no release credential.

`tools/release.php` owns validation and reconciliation. Values are schema-
validated before reaching Git, Markdown extraction, URLs, or API paths. Git is
invoked with argument arrays; GitHub writes use fixed REST operations. There is
no tag-ref update/delete or release-delete operation in the client interface;
the only release update publishes a newly created or recovered draft.

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
is used only to confirm immutable releases before a write.

Create a `release` environment without a manual approval rule. Store:

- environment variable `RELEASE_APP_CLIENT_ID`;
- environment secret `RELEASE_APP_PRIVATE_KEY`.

Only `.github/workflows/release-reconciliation.yml` references them. The App
private key authenticates token minting; it is not a Git signing key. Tag
objects are created through the GitHub API while authenticated as the App,
without custom tagger or signature fields, then must come back with
`verification.verified=true` before the tag ref is created. This follows
GitHub's [bot signature verification requirements](https://docs.github.com/en/authentication/managing-commit-signature-verification/about-commit-signature-verification#signature-verification-for-bots)
and avoids storing an owner's everyday key or silently signing as `rpickz`.

Rotate the App private key by generating a replacement, updating the
environment secret, manually dispatching a no-op reconciliation, and only then
revoking the old key. For compromise, revoke all App keys and uninstall the
App before investigating; immutable releases and update/deletion protection
remain in force. Installation tokens are short-lived and the token action
revokes them after each job.

## Tag rulesets and one-time proof

GitHub bypass applies to an entire ruleset, not one rule within it. To let the
App create but never update/delete `refs/tags/v*`, first create a closed/visible
(not secret) `release-emergency` organization team whose only member is
`rpickz`, then use two active tag rulesets:

1. `Protect release tag history`: target `refs/tags/v*`, restrict update and
   deletion, and grant an always-allow bypass only to `release-emergency`.
2. `Restrict release tag creation`: target `refs/tags/v*`, restrict creation,
   and grant always-allow bypass only to `release-emergency` and the dedicated
   release App. Do not grant repository roles, administrators, writers, or the
   generic GitHub Actions integration.

Repository rulesets owned by an organization accept roles, teams, and Apps as
bypass actors, not an individual member, and secret teams are not eligible.
Keep the emergency team owner-managed and single-member so it implements the
intended `rpickz`-only human exception without granting every organization
owner or repository admin bypass.

Keep immutable releases enabled. GitHub documents both
[creation restrictions and App-specific bypass](https://docs.github.com/en/repositories/configuring-branches-and-merges-in-your-repository/managing-rulesets/creating-rulesets-for-a-repository#granting-bypass-permissions-for-your-branch-or-tag-ruleset).

Before enabling creation restriction in the production repository, reproduce
both rulesets in a disposable repository with the same three actors and prove:

- a normal writer cannot create, update, or delete a matching tag;
- `rpickz` can create and remove a disposable probe tag for emergency recovery;
- the App can create a new matching tag but cannot update or delete it.

Then enable the creation ruleset here and run a declaration through review.
The reconciler's create-ref call is the production confirmation that the App
receives the intended bypass. A denied create leaves only an unreachable Git
tag object and no release, and a rerun converges after the ruleset is fixed.

## Outages and emergency recovery

For a transient GitHub or Packagist outage, rerun `Release reconciliation` with
`workflow_dispatch`; it intentionally has no version or SHA inputs and always
reconciles the complete ledger. Do not edit an existing entry to force a retry.

Owner bypass is for investigation, not normal publication. If state disagrees
with the ledger, preserve the tag/release and workflow evidence, disable the
release App if compromise is possible, and investigate before changing any
remote object. The reconciler will never repair disagreement by mutation. Any
owner-only destructive recovery requires a separately reviewed incident plan;
never weaken branch protection, tag protection, coverage, mutation, or security
gates to make a release pass.
