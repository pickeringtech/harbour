# Releasing Harbour

1. Confirm all required CI jobs pass on the release commit, including the 95%
   line-coverage floor and Infection thresholds.
2. Run `composer acceptance` and inspect the two-worktree lifecycle result.
3. Review dependency advisories and the ownership/security diff.
4. Move Unreleased changelog entries under the new SemVer version and date.
5. Validate README commands in a fresh Laravel 13 application.
6. Confirm the release signing key is registered as an SSH signing key on the
   releasing maintainer's GitHub account and that the tagger email is verified
   on that account.
7. Create a signed annotated tag and GitHub release from the changelog.
8. Confirm the new tag displays **Verified** on GitHub before announcing it.
9. Confirm the release is immutable, then verify its tag and assets cannot be
   edited or deleted through the ordinary release path.
10. Confirm Packagist resolves the version to the release tag's exact commit,
    then install that version in another clean application.

Do not release from a dirty worktree or lower mutation thresholds as part of a
release change.
