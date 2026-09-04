# Maintainer security and moderation runbook

This runbook covers repository access, account recovery, private reports, and
moderation. It deliberately does not contain recovery codes, private keys, or
other credentials.

## Access and recovery

`rpickz` and `rich-picktech` are the designated organization owners and Harbour
repository administrators. Keep administrator access limited to people who
need organization recovery or repository security administration. Grant normal
write or maintain access through a Harbour-specific maintainer team rather
than through the organization base permission.

Before enforcing organization-wide two-factor authentication, each owner must:

1. Enrol at least two non-SMS factors, with a passkey or hardware security key
   preferred for the primary factor.
2. Store GitHub recovery codes offline in the owner's password manager and keep
   an independently accessible encrypted backup.
3. Confirm the other owner can recover the organization and repository without
   the first owner's account or devices.
4. Review members, billing managers, outside collaborators, GitHub Apps, and
   service accounts for 2FA readiness and remove obsolete access.
5. Record completion and the date of the recovery exercise in the private
   maintainer register. Never commit the codes or backup locations here.

Repeat the access and recovery review every six months and whenever an owner,
billing manager, maintainer, or automation identity changes.

## Security and conduct reports

Security vulnerabilities arrive through GitHub private security advisories as
described in `SECURITY.md`. Conduct reports attached to GitHub content arrive
through the repository's private reported-content queue. Both repository
administrators must keep notifications enabled for these routes and verify the
queues during the six-month access review.

For each report:

1. Acknowledge it within five business days and assign an administrator who is
   not named in the report.
2. Preserve links and relevant evidence privately. Do not copy sensitive
   details into public issues or pull requests.
3. Assess immediate risk, stop ongoing exposure or harassment, and tell the
   reporter what will happen next when it is safe to do so.
4. Record the decision and resolve the private report only after the response
   is complete.

## Moderation escalation

Use the least restrictive effective response, while prioritising safety:

- Ask participants to change course for isolated, low-impact incivility.
- Hide or edit content that exposes private information, contains abuse, or
  materially disrupts technical discussion.
- Lock a conversation when conflict is escalating, repeated warnings are being
  ignored, or leaving replies open would amplify harmful content.
- Apply temporary interaction limits during coordinated disruption, spam, or a
  sudden influx that maintainers cannot safely review in real time.
- Block a user for credible threats, doxxing, persistent harassment, malicious
  spam, or repeated serious violations after warnings.
- Escalate credible threats, illegal content, and platform-wide abuse to GitHub
  Support; contact local emergency services where there is imminent danger.

Document the rationale privately. Revisit temporary restrictions after the
stated period and remove them when the risk has passed.

## OpenSSF Scorecard evaluation

The Scorecard workflow is deferred for now. Its strongest repository checks
duplicate required CI, branch protection, Dependabot, and workflow review
already enforced directly, while accurate inspection of the existing classic
branch protection would require a long-lived administrative token. Adding that
token would create more privilege and secret-rotation burden than the current
signal justifies. Re-evaluate after branch governance has moved to repository
rulesets, which Scorecard can inspect with the read-only `GITHUB_TOKEN`.
