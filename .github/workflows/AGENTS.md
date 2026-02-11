<!-- Managed by agent: keep sections and order; edit content, not structure. Last updated: 2026-02-09 -->

# AGENTS.md -- .github/workflows

## Overview
Single CI workflow (`ci.yml`) with 6 job types across a PHP/TYPO3 version matrix.

## Key Files
| File | Purpose |
|------|---------|
| `ci.yml` | Main CI pipeline: lint, stan, unit, fuzz, functional, mutation |

## CI Jobs
| Job | Matrix | Purpose |
|-----|--------|---------|
| `lint` | PHP 8.2-8.5 | php-cs-fixer PER-CS2.0 check |
| `stan` | PHP 8.2-8.5 x TYPO3 13/14 | PHPStan level 10 |
| `unit` | PHP 8.2-8.5 x TYPO3 13/14 | Unit tests with coverage |
| `fuzz` | PHP 8.2-8.5 | Fuzz tests (no coverage) |
| `functional` | PHP 8.2-8.4 x TYPO3 13/14 | Functional tests with MySQL |
| `mutation` | PHP 8.4 | Infection mutation testing (MSI >= 60%) |

## Conventions
- Pin actions to full SHA with version comment: `uses: actions/checkout@SHA # vX.Y.Z`
- Use `shivammathur/setup-php` for PHP setup
- TYPO3 v14 jobs remove `saschaegerer/phpstan-typo3` (v13-only compatibility)
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
