# Security policy

## Supported versions

Until Harbour 1.0, security fixes are released on the latest minor line. After
1.0, the current major version will receive fixes according to the support
policy published with that release.

## Reporting a vulnerability

Do not open a public issue for a vulnerability involving resource deletion,
command or SQL injection, path traversal, credential disclosure, or ownership
bypass. Use GitHub's **Report a vulnerability** private advisory flow for this
repository:

<https://github.com/pickeringtech/harbour/security/advisories/new>

Include the affected version/commit, platform, reproduction, impact, and any
suggested remediation. Maintainers will acknowledge a complete report within
five business days and coordinate disclosure after a fix is available.

Harbour manages developer resources and should never be installed as a runtime
production dependency. `--force` is not a security bypass; report any case
where it disables an ownership or path guard.
