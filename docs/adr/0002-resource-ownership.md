# ADR 0002: Destruction requires persisted ownership evidence

Status: accepted

Current configuration describes intent, not ownership. It can change after a
partial setup and can point at a developer or production resource. Harbour
therefore records each created resource immediately in workspace state and
teardown addresses only those records.

Drivers perform a second external check where possible: Docker labels must
match the resource and workspace IDs; SQLite paths must remain in the checkout;
server database names and connection fingerprints must match the record.
`--force` never weakens these checks. Missing owned resources are harmless;
mismatched resources raise a stable not-owned error.
