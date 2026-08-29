<!-- Managed by agent: keep sections and order; edit content, not structure. Last updated: 2026-08-19 -->

# AGENTS.md -- Tests

## Overview
Six test suites: PHP Unit, Fuzz, Functional (PHPUnit ^10.5||^11.5 + TYPO3 testing-framework ^8.2||^9.0), JavaScript (Vitest), E2E (Playwright), and Architecture (PHPat, runs inside PHPStan).

## Test Structure
```
Tests/
  Unit/                         # Fast, isolated PHP unit tests
    Authentication/             # PasskeyAuthenticationService tests
    Controller/                 # Controller + trait tests (TranslationTrait, JsonBodyTrait)
    Service/                    # Service tests (all services including ExtensionConfigurationService)
    Configuration/              # ExtensionConfiguration VO tests
    EventListener/              # InjectPasskeyLoginFields, InjectPasskeyBanner tests
    Domain/                     # DTO and Enum tests
    Middleware/                  # PasskeySetupInterstitial tests
    Form/                       # PasskeyInfoElement tests
    UserSettings/               # PasskeySettingsPanel tests
    Utility/                    # TypeCastTrait tests
    Command/                    # RecoveryCommand tests
    Widgets/                    # Dashboard widget wiring tests (v14.3+ interfaces)
    QueryBuilderMockTrait.php   # Shared QueryBuilder mock builder for unit tests
  Functional/                   # Database tests (MySQL required, CI only)
    Controller/                 # AdminController functional tests
    Repository/                 # CredentialRepository functional tests
    Service/                    # AdoptionStatsService, EnforcementService functional tests
  Fuzz/                         # Fuzz tests (randomized input)
  JavaScript/                   # JS unit tests (Vitest + jsdom)
  E2E/                          # End-to-end tests (Playwright *.spec.ts)
  Architecture/                 # PHPat architecture rules (layer isolation, finality)
  Fixtures/                     # Shared test fixtures (CSV datasets)
  Build/                        # Test bootstrap (FunctionalTestsBootstrap.php)
```

## Setup
- PHP suites: `composer install`, then run phpunit via the composer scripts below (configs in `Build/phpunit.xml` / `Build/phpunit.functional.xml`)
- JS/E2E suites: `npm ci` (Vitest config in `vitest.config.mjs`, Playwright specs in `Tests/E2E/`)

## Running Tests
| Type | Command | Notes |
|------|---------|-------|
| Unit tests | `composer ci:test:php:unit` | Fast, no DB needed |
| Fuzz tests | `composer ci:test:php:fuzz` | May flake due to random data |
| Functional tests | `composer ci:test:php:functional` | MySQL required (CI only) |
| JS tests | `npm run test:js` | Fast, DOM testing with jsdom |
| E2E tests | `Build/Scripts/runTests.sh -s e2e` | Installs its own TYPO3 in containers |
| Mutation testing | `composer ci:mutation` | MSI >= 80%, covered-MSI >= 80% |

**IMPORTANT**: E2E tests do NOT use DDEV. Use `Build/Scripts/runTests.sh -s e2e` (there is currently no CI e2e workflow). It brings up MariaDB, a Composer-installed TYPO3 13 with this extension, PHP-FPM and Apache, and drives them from a Playwright container; `E2E_TYPO3_VERSION=14` selects the other supported major, and `TYPO3_BASE_URL=…` points the same specs at an instance that already runs. The instance lands in `.Build/e2e-typo3` and is kept there when the suite fails.

## Conventions
- Use `#[Test]` attribute and `#[DataProvider('name')]` (not annotations)
- Use `self::assert*()` not `$this->assert*()`
- Services with `LoggerInterface` need logger mock in test setUp
- `web-auth/webauthn-lib` classes are `final` -- use `dg/bypass-finals` + test doubles
- Fuzz tests use randomized input; flakes are expected -- re-run to verify

## Examples (golden samples)
| Pattern | Reference |
|---------|-----------|
| Service unit test | `Unit/Service/ChallengeServiceTest.php` |
| Trait test | `Unit/Utility/TypeCastTraitTest.php` |
| Fuzz test | `Fuzz/ChallengeTokenFuzzTest.php` |
| Functional test | `Functional/Repository/CredentialRepositoryTest.php` |

## PR Checklist
- [ ] `composer ci:test:php:unit` passes
- [ ] `composer ci:test:php:fuzz` passes
- [ ] `npx vitest run` passes
- [ ] New functionality has tests

## Security
- Never put real credentials, keys, or personal data in fixtures -- `Tests/Fixtures/` uses synthetic users only
- Fuzz suite (`Tests/Fuzz/`) guards the challenge-token boundary; extend it when touching ChallengeService
- Expected error paths must be asserted, not left as noise in test output

## When stuck
- Mocking `final` webauthn-lib classes: see `dg/bypass-finals` usage in `Unit/Service/ChallengeServiceTest.php`
- QueryBuilder mocking: use `Tests/Unit/QueryBuilderMockTrait.php`
- Functional DB failures locally: expected -- MySQL is CI-only, do not debug locally
- Root `AGENTS.md` for project-wide commands and rules
