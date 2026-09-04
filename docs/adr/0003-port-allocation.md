# ADR 0003: Ports use a locked machine registry plus socket checks

Status: accepted

Probing alone permits two Harbour processes to choose the same port. Harbour
serializes selection and reservation in an XDG-state registry with `flock`.
Within the lock it excludes live reservations, tests a loopback socket bind,
records the allocation atomically, and only then returns it. Setup immediately
copies the allocation into workspace state.

This guarantees uniqueness among concurrent Harbour setups and avoids ports
already held by other processes. Harbour is not a daemon and cannot prevent an
unrelated process taking a reserved-but-unbound port later; launchers should
therefore use strict-port behaviour. A replacement strategy may hold sockets or
integrate with another allocator.

An existing reservation for the same workspace and allocation name is reused
without another bind probe. The registry is Harbour's ownership record, and an
occupied owned port normally means that workspace's application or managed
service is already running. Reallocating it during convergent setup would split
persisted state from the running service and rendered environment. A genuine
external collision is instead reported by strict process or service startup.

Teardown releases exact persisted tuples and then any remaining reservations
carrying the same workspace ID. The second pass recovers the narrow crash
window between the global registry write and the workspace-state write without
touching another workspace.
