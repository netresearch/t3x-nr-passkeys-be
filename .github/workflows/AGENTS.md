<!-- Managed by agent: keep sections and order; edit content, not structure. Last updated: 2026-03-27 -->

# AGENTS.md -- .github/workflows

## Overview
Multiple workflows: CI pipeline, E2E tests, TER publish, PR quality gates, CodeQL, OpenSSF Scorecard.

## Key Files
| File | Purpose |
|------|---------|
| `ci.yml` | Main CI pipeline: lint, stan, unit, fuzz, functional, mutation |
| `e2e.yml` | E2E tests via reusable workflow (PHP server + MySQL, NOT DDEV) |
| `ter-publish.yml` | Publish to TYPO3 TER on release (strips `v` prefix for version) |
| `release.yml` | Release automation with attestations |
| `pr-quality.yml` | Auto-approve for solo maintainer, Copilot review coordination |
| `codeql.yml` | CodeQL security analysis (javascript-typescript, actions -- NOT PHP) |
| `scorecard.yml` | OpenSSF Scorecard security assessment |
| `auto-merge-deps.yml` | Auto-merge dependency PRs (Dependabot/Renovate) |
| `dependency-review.yml` | Dependency review on PRs |

## CI Jobs
| Job | Matrix | Purpose |
|-----|--------|---------|
| `lint` | PHP 8.2-8.5 | php-cs-fixer PER-CS3.0 check |
| `stan` | PHP 8.2-8.4 x TYPO3 12/13, PHP 8.2-8.5 x TYPO3 14 | PHPStan level 10 |
| `unit` | PHP 8.2-8.4 x TYPO3 12, PHP 8.2-8.5 x TYPO3 13/14 | Unit tests with coverage |
| `fuzz` | PHP 8.4 x TYPO3 14 | Fuzz tests (no coverage) |
| `functional` | PHP 8.2-8.4 x TYPO3 12, PHP 8.2-8.5 x TYPO3 13/14 | Functional tests with MySQL |
| `e2e` | PHP 8.4, Chromium | Playwright E2E tests (PHP built-in server + MySQL service) |

## E2E Tests
E2E tests use the reusable workflow `netresearch/typo3-ci-workflows/.github/workflows/e2e.yml@main` which:
1. Starts a MySQL service container
2. Runs `typo3 setup` to configure TYPO3
3. Starts a PHP built-in server on port 8080
4. Runs `npm run test:e2e`

**NEVER use DDEV in CI.** DDEV is for local development only.

## Conventions
- Pin actions to full SHA with version comment: `uses: actions/checkout@SHA # vX.Y.Z`
- Use `shivammathur/setup-php` for PHP setup
- Non-v13 TYPO3 jobs remove `saschaegerer/phpstan-typo3` (v13-only compatibility)
- Functional tests use `mysql:8.0` service container
- Coverage uploads to Codecov with flag separation (unit vs functional)

## Security
- Pin actions to full commit SHA, not mutable tags
- Use minimal permissions
- Never expose secrets in logs

## When modifying CI
- Test changes locally with `act` if possible
- Verify action SHA + version match with `gh api repos/OWNER/REPO/tags`
- Keep matrix balanced -- every PHP version should be tested with every supported TYPO3 version
- Mutation testing thresholds: MSI >= 80%, covered-MSI >= 80% (infection.json5)

## TER Publish Gotchas
- `GITHUB_REF#refs/tags/` gives `v0.6.0` but ext_emconf.php has `0.6.0` (no `v`)
- Workflow uses separate `checkout_ref` (raw tag) and `version` (v-stripped) env vars
- Always bump `ext_emconf.php` version BEFORE creating the tag
- `guides.xml` version should also be updated to match
