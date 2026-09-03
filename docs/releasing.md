# Releasing Harbour

1. Confirm all required CI jobs pass on the release commit, including the 95%
   line-coverage floor and Infection thresholds.
2. Run `composer acceptance` and inspect the two-worktree lifecycle result.
3. Review dependency advisories and the ownership/security diff.
4. Move Unreleased changelog entries under the new SemVer version and date.
5. Validate README commands in a fresh Laravel 13 application.
6. Create a signed annotated tag and GitHub release from the changelog.
7. Confirm Packagist receives the tag and install it in another clean app.

Do not release from a dirty worktree or lower mutation thresholds as part of a
release change.
