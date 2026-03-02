<!-- Managed by agent: keep sections and order; edit content, not structure. Last updated: 2026-03-02 -->

# AGENTS.md -- Tests

## Overview
Five test suites: PHP Unit, Fuzz, Functional (PHPUnit 11.5 + TYPO3 testing-framework v9), JavaScript (Vitest), and E2E (Playwright).

## Test Structure
```
Tests/
  Unit/                         # Fast, isolated PHP unit tests (~491 tests)
    Authentication/             # PasskeyAuthenticationService tests
    Controller/                 # Controller tests (Login, Management, Admin, AdminModule)
    Service/                    # Service tests (WebAuthn, Challenge, Credential, RateLimiter, Enforcement, AdoptionStats)
    Configuration/              # ExtensionConfiguration tests
    EventListener/              # InjectPasskeyLoginFields, InjectPasskeyBanner tests
    Domain/                     # DTO and Enum tests
    Middleware/                  # PasskeySetupInterstitial tests
    Form/                       # PasskeyInfoElement tests
  Functional/                   # Database tests (~24 tests, MySQL required, CI only)
    Service/                    # CredentialRepository functional tests
  Fuzz/                         # Fuzz tests (~131 tests, randomized input)
    Service/                    # ChallengeToken, CredentialId, RequestPayload fuzzing
  JavaScript/                   # JS unit tests (~63 tests, Vitest)
    PasskeyBanner.test.js       # Banner injection, v14 compat, content, dismissal
    PasskeyDashboard.test.js    # Dashboard chart rendering, enforcement controls
    PasskeyLogin.test.js        # Login ceremony, WebAuthn API mocking
  E2E/                          # End-to-end tests (Playwright, 9 spec files)
    passkey-banner.spec.ts      # Banner renders, dismisses, shows content
    admin-dashboard.spec.ts     # Admin module loads, stats display
    passkey-login-flow.spec.ts  # Full login ceremony including interstitial
    user-settings.spec.ts       # User settings passkey management
    (5 more spec files)
  Fixtures/                     # Shared test fixtures (CSV datasets)
  Build/                        # CI configuration files
```

## Running Tests
| Type | Command | Notes |
|------|---------|-------|
| Unit tests | `composer ci:test:php:unit` | Fast, no DB needed |
| Fuzz tests | `composer ci:test:php:fuzz` | May flake due to random data |
| Functional tests | `composer ci:test:php:functional` | MySQL required (CI only) |
| Unit + functional | `composer ci:test:php:all` | No fuzz tests included |
| JS tests | `npx vitest run` | Fast, DOM testing with jsdom |
| E2E tests | `npx playwright test` | Requires DDEV running (targets v13) |
| Mutation testing | `composer ci:mutation` | min-MSI 60%, covered-MSI 75% |
| Single PHP test | `.Build/bin/phpunit -c Build/phpunit.xml Tests/Unit/Path/To/Test.php` | |
| Single JS test | `npx vitest run Tests/JavaScript/PasskeyBanner.test.js` | |

## Key Patterns
- Unit tests extend `\TYPO3\TestingFramework\Core\Unit\UnitTestCase`
- Functional tests extend `\TYPO3\TestingFramework\Core\Functional\FunctionalTestCase`
- Use `$this->importCSVDataSet()` for functional test fixtures (not XML)
- `web-auth/webauthn-lib` classes are `final` -- create test doubles, do not mock
- Use data providers for multiple similar cases
- Test class name matches source: `MyClass` -> `MyClassTest`
- Test methods use `test` prefix (not `@test` annotation)
- Fuzz tests use randomized input; flakes are expected -- re-run to verify

## Golden Samples
| Pattern | Reference |
|---------|-----------|
| Service unit test | `Unit/Service/ChallengeServiceTest.php` |
| Controller unit test | `Unit/Controller/LoginControllerTest.php` |
| Enforcement test | `Unit/Service/EnforcementServiceTest.php` |
| Middleware test | `Unit/Middleware/PasskeySetupInterstitialTest.php` |
| Fuzz test | `Fuzz/Service/ChallengeTokenFuzzTest.php` |
| Functional test | `Functional/Service/CredentialRepositoryTest.php` |
| JS test | `JavaScript/PasskeyBanner.test.js` |
| E2E test | `E2E/passkey-banner.spec.ts` |

## Code Style
- Same PER-CS3.0 rules as production code
- `declare(strict_types=1)` in all test files
- One assertion concept per test
- Mock external services, never real HTTP calls

## PR Checklist
- [ ] `composer ci:test:php:unit` passes
- [ ] New functionality has tests
- [ ] Fixtures are minimal and focused
- [ ] No hardcoded credentials or paths
