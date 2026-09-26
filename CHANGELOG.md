# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-09-26

### Added

- Deterministic Base62 short URL creation.
- Idempotent URL creation with replay and conflict handling.
- Cache-accelerated redirects with database fallback.
- API and framework health-check endpoints.
- Request correlation IDs and structured JSON application logs.
- Browser-local recent link history with copy, open, and clear actions.
- System-aware light and dark themes with a persistent manual preference.
- Playwright coverage for the complete browser shortening and redirect journey.
- Automated tests, coverage enforcement, style checks, dependency audits, and frontend builds in CI.
- Dependabot updates for Composer, npm, and GitHub Actions dependencies.
- Structured exception reporting with privacy-safe request context.
- Pull-request and contribution guidance for test-backed, focused changes.

### Changed

- CI now proves the fresh-clone bootstrap path and retains backend coverage evidence.
- Pint, ESLint, and Prettier checks now enforce PHP and JavaScript code quality.

[Unreleased]: https://github.com/AkinAgbejoye/url_shortener/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/AkinAgbejoye/url_shortener/releases/tag/v0.1.0
