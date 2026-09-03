# Releasing Harbour

1. Confirm all required CI jobs pass on the release commit.
2. Run the manual two-worktree acceptance scenario in `docs/testing.md`.
3. Review dependency advisories and the ownership/security diff.
4. Move Unreleased changelog entries under the new SemVer version and date.
5. Validate README commands in a fresh Laravel 13 application.
6. Create a signed annotated tag and GitHub release from the changelog.
7. Confirm Packagist receives the tag and install it in another clean app.

Do not release from a dirty worktree or lower mutation thresholds as part of a
release change.
