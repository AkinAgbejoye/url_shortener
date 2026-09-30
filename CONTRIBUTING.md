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
npm run test:coverage
npm run test:e2e
npm audit --audit-level=high
npm run build
```

PCOV or Xdebug is required for backend coverage. Use `composer test` when iterating locally if neither extension is installed, but run the backend and frontend coverage gates before submitting.

## Submit a change

- Keep each commit to one feature or fix and the tests that prove it. Do not defer those tests to a later commit.
- Put formatting-only changes in a separate commit so behavioral reviews stay clear.
- Update the README and `CHANGELOG.md` when public behavior or setup changes.
- Use a short, imperative commit message such as `fix: handle cache timeouts`.
- Keep generated files, credentials, and local `.env` files out of commits.

## Merge protection

The active `Protect main quality gates` repository ruleset protects the `main` branch. Every change must arrive through a pull request whose branch is up to date with `main`, all review conversations must be resolved, and these exact GitHub Actions checks must pass on the latest commit:

- `Lint and build`
- `Test`
- `Dependency audit`
- `Fresh-clone bootstrap`

The ruleset blocks force pushes and deletion of `main`. It does not require an approving review because this is currently a single-maintainer repository, but the pull request and passing checks are mandatory.

The repository owner has a recovery-only bypass that works from a pull request; it does not permit direct pushes to `main`. Use it only when the ruleset or GitHub Actions infrastructure itself prevents an urgent recovery, explain the incident and bypass in that pull request, and restore normal enforcement before routine work resumes. Do not use the bypass for failing application or dependency checks.

Maintainers can verify the effective configuration in [repository rules settings](https://github.com/AkinAgbejoye/url_shortener/rules/24258314) or through the API:

```bash
gh api repos/AkinAgbejoye/url_shortener/rulesets/24258314
gh api repos/AkinAgbejoye/url_shortener/rules/branches/main
```

## Prepare a release

Move completed entries from `Unreleased` into a dated semantic version section in `CHANGELOG.md`. Create the version tag only after every local quality check passes and the corresponding `main` CI run is green.
