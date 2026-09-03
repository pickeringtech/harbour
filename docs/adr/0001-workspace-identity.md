# ADR 0001: Workspace identity is not a branch slug

Status: accepted

A branch may be shared by multiple checkouts, absent in detached HEAD, renamed,
hostile to downstream grammars, or too long. Harbour therefore hashes a
repository fingerprint and the canonical checkout path. The identity contains
the branch only as metadata. A persisted identity remains authoritative after a
directory or branch rename so teardown can still find its resources.

The display slug is readable but always ends in a hash fragment. Dedicated
transformers derive database, Redis, cookie, hostname, Docker, Compose, and
filesystem identifiers from the immutable identity; raw branch names never
enter SQL, process arguments, or deletion paths.

Outside Git, the canonical project path supplies both repository and checkout
fingerprints. This preserves the zero-configuration path without making Git a
runtime requirement.
