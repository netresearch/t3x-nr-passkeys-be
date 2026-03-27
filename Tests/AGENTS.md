<!-- Managed by agent: keep sections and order; edit content, not structure. Last updated: 2026-03-27 -->

# AGENTS.md -- Tests

## Overview
Six test suites: PHP Unit, Fuzz, Functional (PHPUnit 11.5 + TYPO3 testing-framework v9), JavaScript (Vitest), E2E (Playwright), and Architecture (PHPat).

## Test Structure
```
Tests/
  Unit/                         # Fast, isolated PHP unit tests (~546 tests)
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
  Functional/                   # Database tests (~69 tests, MySQL required, CI only)
    Controller/                 # AdminController functional tests
    Repository/                 # CredentialRepository functional tests
    Service/                    # AdoptionStatsService, EnforcementService functional tests
  Fuzz/                         # Fuzz tests (~131 tests, randomized input)
  JavaScript/                   # JS unit tests (~63 tests, Vitest)
  E2E/                          # End-to-end tests (Playwright, 9 spec files)
  Architecture/                 # PHPat architecture rules (layer isolation, finality)
  Fixtures/                     # Shared test fixtures (CSV datasets)
  Build/                        # CI configuration files
```

## Running Tests
| Type | Command | Notes |
|------|---------|-------|
| Unit tests | `composer ci:test:php:unit` | Fast, no DB needed |
| Fuzz tests | `composer ci:test:php:fuzz` | May flake due to random data |
| Functional tests | `composer ci:test:php:functional` | MySQL required (CI only) |
| JS tests | `npx vitest run` | Fast, DOM testing with jsdom |
| E2E tests | `Build/Scripts/runTests.sh e2e` | PHP built-in server + MySQL |
| Mutation testing | `composer ci:mutation` | MSI >= 80%, covered-MSI >= 80% |

**IMPORTANT**: E2E tests do NOT use DDEV. Use `Build/Scripts/runTests.sh e2e` or the CI e2e workflow.

## Key Patterns
- Use `#[Test]` attribute and `#[DataProvider('name')]` (not annotations)
- Use `self::assert*()` not `$this->assert*()`
- Services with `LoggerInterface` need logger mock in test setUp
- `web-auth/webauthn-lib` classes are `final` -- use `dg/bypass-finals` + test doubles
- Fuzz tests use randomized input; flakes are expected -- re-run to verify

## Golden Samples
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
