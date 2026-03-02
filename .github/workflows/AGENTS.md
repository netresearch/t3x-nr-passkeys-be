<!-- Managed by agent: keep sections and order; edit content, not structure. Last updated: 2026-03-02 -->

# AGENTS.md -- .github/workflows

## Overview
Multiple workflows: CI pipeline, TER publish, PR quality gates, CodeQL, OpenSSF Scorecard.

## Key Files
| File | Purpose |
|------|---------|
| `ci.yml` | Main CI pipeline: lint, stan, unit, fuzz, functional, mutation |
| `ter-publish.yml` | Publish to TYPO3 TER on release (strips `v` prefix for version) |
| `pr-quality-gates.yml` | Auto-approve for solo maintainer, Copilot review coordination |
| `codeql.yml` | CodeQL security analysis (javascript-typescript, actions -- NOT PHP) |
| `scorecard.yml` | OpenSSF Scorecard security assessment |
| `auto-merge.yml` | Auto-merge dependency PRs (Dependabot/Renovate) |
| `dependency-review.yml` | Dependency review on PRs |

## CI Jobs
| Job | Matrix | Purpose |
|-----|--------|---------|
| `lint` | PHP 8.2-8.5 | php-cs-fixer PER-CS3.0 check |
| `stan` | PHP 8.2-8.4 x TYPO3 12/13, PHP 8.2-8.5 x TYPO3 14 | PHPStan level 10 |
| `unit` | PHP 8.2-8.4 x TYPO3 12, PHP 8.2-8.5 x TYPO3 13/14 | Unit tests with coverage |
| `fuzz` | PHP 8.4 x TYPO3 14 | Fuzz tests (no coverage) |
| `functional` | PHP 8.2-8.4 x TYPO3 12, PHP 8.2-8.5 x TYPO3 13/14 | Functional tests with MySQL |
| `mutation` | PHP 8.4 | Infection mutation testing (MSI >= 80%) |

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
- Mutation testing runs on single PHP version (8.4) to save CI minutes

## TER Publish Gotchas
- `GITHUB_REF#refs/tags/` gives `v0.6.0` but ext_emconf.php has `0.6.0` (no `v`)
- Workflow uses separate `checkout_ref` (raw tag) and `version` (v-stripped) env vars
- Always bump `ext_emconf.php` version BEFORE creating the tag
- `guides.xml` version should also be updated to match
