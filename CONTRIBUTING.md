# Contributing

Thanks for improving the URL shortener. Keep each change focused, include tests that prove new behavior, and avoid mixing feature work with unrelated formatting or refactoring.

## Set up the project

Follow the [local setup](README.md#local-setup) instructions in the README. Tests use SQLite in memory and the array cache, so they do not require MySQL or Redis.

## Validate a change

Run the same checks used by CI before opening a pull request:

```bash
composer test:coverage
vendor/bin/pint --test
composer audit
npm run lint
npm run format:check
npm test
npm run test:e2e
npm audit --audit-level=high
npm run build
```

PCOV or Xdebug is required for the coverage command. Use `composer test` when iterating locally if neither extension is installed, but run the 70% coverage gate before submitting.

## Submit a change

- Keep each commit to one feature or fix and the tests that prove it. Do not defer those tests to a later commit.
- Put formatting-only changes in a separate commit so behavioral reviews stay clear.
- Update the README and `CHANGELOG.md` when public behavior or setup changes.
- Use a short, imperative commit message such as `fix: handle cache timeouts`.
- Keep generated files, credentials, and local `.env` files out of commits.

## Prepare a release

Move completed entries from `Unreleased` into a dated semantic version section in `CHANGELOG.md`. Create the version tag only after every local quality check passes and the corresponding `main` CI run is green.
