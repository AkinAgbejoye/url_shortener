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
npm audit --audit-level=high
npm run build
```

PCOV or Xdebug is required for the coverage command. Use `composer test` when iterating locally if neither extension is installed, but run the 70% coverage gate before submitting.

## Submit a change

- Add or update unit and feature tests for behavior changes.
- Update the README and `CHANGELOG.md` when public behavior or setup changes.
- Use a short, imperative commit message such as `fix: handle cache timeouts`.
- Keep generated files, credentials, and local `.env` files out of commits.
