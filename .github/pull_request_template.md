## Summary

## Behavioral tests

## Safety review

- [ ] Destructive targets come from persisted ownership evidence.
- [ ] `--force` does not weaken ownership or path checks.
- [ ] Hostile branch/path/config input cannot enter SQL or shell commands unsafely.
- [ ] Partial failure remains teardown-safe.
- [ ] Docs/changelog are updated.

## Quality gates

- [ ] PHPUnit
- [ ] Line coverage remains at least 95%
- [ ] PHPStan/Larastan max
- [ ] Pint
- [ ] Relevant database/Redis/Docker/Compose tests
- [ ] Property/fuzz tests for new untrusted-input transformations
- [ ] Mutation tests for changed safety logic
