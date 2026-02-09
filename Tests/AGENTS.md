<!-- Managed by agent: keep sections and order; edit content, not structure. Last updated: 2026-02-09 -->

# AGENTS.md -- Tests

## Overview
Three test suites: Unit, Functional, and Fuzz. Uses PHPUnit 11.5 + TYPO3 testing-framework v9.

## Test Structure
```
Tests/
  Unit/                         # Fast, isolated unit tests (~194 tests)
    Authentication/             # PasskeyAuthenticationService tests
    Controller/                 # Controller tests (Login, Management, Admin)
    Service/                    # Service tests (WebAuthn, Challenge, Credential, RateLimiter)
    Configuration/              # ExtensionConfiguration tests
    LoginProvider/              # PasskeyLoginProvider tests
  Functional/                   # Database tests (~24 tests, MySQL required, CI only)
    Service/                    # CredentialRepository functional tests
  Fuzz/                         # Fuzz tests (~122 tests, randomized input)
    Service/                    # ChallengeToken, CredentialId, RequestPayload fuzzing
  E2E/                          # End-to-end tests
  Fixtures/                     # Shared test fixtures (CSV datasets)
  Build/                        # CI configuration files
```

## Running Tests
| Type | Command | Notes |
|------|---------|-------|
| Unit tests | `composer ci:test:php:unit` | Fast, no DB needed |
| Functional tests | `composer ci:test:php:functional` | MySQL required (CI only) |
| All tests | `composer ci:test:php:all` | Unit + functional |
| Fuzz tests | `.Build/bin/phpunit -c phpunit.xml --testsuite fuzz --no-coverage` | May flake due to random data |
| Mutation testing | `composer ci:mutation` | MSI >= 60%, covered MSI >= 75% |
| Single test file | `.Build/bin/phpunit -c phpunit.xml Tests/Unit/Path/To/Test.php` | |

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
| Fuzz test | `Fuzz/Service/ChallengeTokenFuzzTest.php` |
| Functional test | `Functional/Service/CredentialRepositoryTest.php` |

## Code Style
- Same PER-CS2.0 rules as production code
- `declare(strict_types=1)` in all test files
- One assertion concept per test
- Mock external services, never real HTTP calls

## PR Checklist
- [ ] `composer ci:test:php:unit` passes
- [ ] New functionality has tests
- [ ] Fixtures are minimal and focused
- [ ] No hardcoded credentials or paths
