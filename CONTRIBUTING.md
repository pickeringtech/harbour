# Contributing to Harbour

Thank you for improving Harbour. Safety regressions can delete local resources,
so changes to teardown, ownership, identifiers, paths, SQL, Docker, Compose, or
locking need both positive and adversarial tests.

## Development

`README.md`, `docs/architecture.md`, and their diagrams are generated
artifacts. Edit the corresponding `*.template.md` source, then render and
verify the committed Markdown and SVGs:

```bash
npm ci
npm run readme:render
npm run readme:check
npm run docs:build
```

```bash
git clone git@github.com:pickeringtech/harbour.git
cd harbour
composer install
composer test
composer fuzz
composer acceptance
composer coverage
composer mutate
composer analyse
composer security:analyse
composer format:check
```

PHP 8.4 is the minimum supported runtime. Integration tests use the environment
variables documented in the GitHub Actions workflow. Docker tests are opt-in so
ordinary unit runs never mutate the local Docker daemon.

## Pull requests

- Add a failing behavioral test before or with the implementation.
- Preserve backward compatibility for public contracts and versioned JSON.
- Add an ADR for a lasting safety or architectural decision.
- Never weaken an ownership guard to make `--force` convenient.
- Update README/config examples and `CHANGELOG.md`.
- Run PHPUnit, the 95% coverage gate, mutation testing, PHPStan/Larastan max,
  Psalm taint analysis, Pint, and relevant integration tests.

Keep commits focused. A maintainer may ask for a security review or mutation
test focused on safety-critical branches.

By participating, you agree to the Code of Conduct and license your
contribution under the MIT license.
