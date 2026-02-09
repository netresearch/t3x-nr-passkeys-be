<!-- FOR AI AGENTS - Human readability is a side effect, not a goal -->
<!-- Managed by agent: keep sections and order; edit content, not structure -->
<!-- Last updated: 2026-02-09 | Last verified: 2026-02-09 -->

# AGENTS.md

**Precedence:** The **closest AGENTS.md** to changed files wins. Root holds global defaults only.

## Project Overview

**nr_passkeys_be** -- TYPO3 extension for passwordless backend authentication via WebAuthn/FIDO2 Passkeys.
Supports TouchID, FaceID, YubiKey, Windows Hello for one-click TYPO3 backend login.

| Key | Value |
|-----|-------|
| Vendor | Netresearch DTT GmbH |
| Composer | `netresearch/nr-passkeys-be` |
| Extension key | `nr_passkeys_be` |
| Namespace | `Netresearch\NrPasskeysBe` |
| TYPO3 | ^13.4 \|\| ^14.1 |
| PHP | ^8.2 |
| WebAuthn lib | `web-auth/webauthn-lib` ^5.2 |

## Global Rules
- Conventional Commits: `type(scope): subject`
- `declare(strict_types=1)` in all PHP files
- PER-CS2.0 code style via php-cs-fixer
- PHPStan level 8 (do not lower)
- Do NOT commit `composer.lock` (in `.gitignore`)

## Commands (verified)
> Source: `composer.json` scripts

| Task | Command | ~Time |
|------|---------|-------|
| Install | `composer install` | 30s |
| Lint (check) | `composer ci:lint:php` | 5s |
| Lint (fix) | `composer ci:lint:php:fix` | 5s |
| Static analysis | `composer ci:stan` | 10s |
| Unit tests | `composer ci:test:php:unit` | 5s |
| Functional tests | `composer ci:test:php:functional` | 30s |
| All tests | `composer ci:test:php:all` | 35s |
| Mutation testing | `composer ci:mutation` | 60s |

## File Map
```
Classes/                  -> PHP source (PSR-4: Netresearch\NrPasskeysBe\)
  Authentication/         -> PasskeyAuthenticationService (TYPO3 auth chain, priority 80)
  Configuration/          -> ExtensionConfiguration value object
  Controller/             -> Login, Management, Admin controllers + JsonBodyTrait
  Domain/Model/           -> Credential entity (plain PHP, not Extbase)
  LoginProvider/          -> PasskeyLoginProvider (TYPO3 login form integration)
  Service/                -> WebAuthn, Challenge, Credential, RateLimiter, Config services
  UserSettings/           -> User settings module integration
Configuration/            -> TYPO3 config (TCA, Backend Routes, Services.yaml)
Resources/Private/        -> Fluid templates, XLIFF translations
Resources/Public/         -> JavaScript, Icons
Tests/Unit/               -> Unit tests (PHPUnit)
Tests/Functional/         -> Functional tests (require MySQL, CI only)
Tests/Fuzz/               -> Fuzz tests (ChallengeToken, CredentialId, RequestPayload)
Tests/E2E/                -> End-to-end tests
Tests/Fixtures/           -> Shared test fixtures
.github/workflows/ci.yml  -> CI pipeline (lint, stan, unit, fuzz, functional, mutation)
```

## Golden Samples
| For | Reference | Key patterns |
|-----|-----------|-------------|
| Service class | `Classes/Service/ChallengeService.php` | DI, strict types, HMAC security |
| Controller | `Classes/Controller/LoginController.php` | JsonBodyTrait, PSR-7 responses |
| Unit test | `Tests/Unit/Service/ChallengeServiceTest.php` | Mocking final classes, data providers |
| Auth service | `Classes/Authentication/PasskeyAuthenticationService.php` | GeneralUtility::makeInstance() pattern |

## Heuristics
| When | Do |
|------|----|
| Adding a service | Use constructor DI via Services.yaml |
| Auth service deps | Use `GeneralUtility::makeInstance()` (no DI available) |
| Controller returns JSON | Use `JsonBodyTrait` |
| Database access | Use QueryBuilder, never raw SQL |
| Testing final classes | Create test doubles (webauthn-lib classes are `final`) |
| Functional test needs DB | Only run in CI (MySQL required) |
| Fuzz test flakes | Re-run -- `random_bytes()` can produce edge cases |

## Boundaries

### Always Do
- Run `composer ci:lint:php` and `composer ci:stan` before committing
- Add tests for new code paths
- Use conventional commit format
- Validate all user inputs
- Show test output as evidence before claiming work is complete

### Ask First
- Adding new dependencies
- Modifying CI/CD configuration
- Changing public API signatures
- Modifying security-sensitive code (challenge, auth, rate limiting)
- Changing database schema (`ext_tables.sql`)

### Never Do
- Commit secrets, credentials, API keys
- Modify `.Build/vendor/` or generated files
- Push directly to main branch
- Lower PHPStan level below 8
- Disable security features (HMAC, nonce replay, rate limiting)
- Commit `composer.lock`

## Codebase State
- Extension is fully functional with all CI checks passing
- Passkeys are primary credentials (NOT MFA) -- registered at auth priority 80
- `web-auth/webauthn-lib` v5.x classes are `final` -- cannot mock, must use test doubles
- `saschaegerer/phpstan-typo3` v2 only supports TYPO3 v13 (removed for v14 CI jobs)
- Functional tests require MySQL (CI only, not local)
- Discoverable login behind `discoverableLoginEnabled` feature flag

## Terminology
| Term | Means |
|------|-------|
| Passkey | WebAuthn/FIDO2 credential (platform authenticator) |
| Assertion | Authentication ceremony (verifying a passkey) |
| Attestation | Registration ceremony (creating a passkey) |
| Challenge token | HMAC-signed, time-limited, single-use token for WebAuthn ceremonies |
| Lockout | Account lock after N failed auth attempts |
| Discoverable login | Login without entering username first (resident key) |

## Index of Scoped AGENTS.md
- `./Classes/AGENTS.md` -- PHP source code patterns and TYPO3 conventions
- `./Tests/AGENTS.md` -- Test structure, commands, and patterns
- `./Resources/AGENTS.md` -- Templates, translations, and static assets
- `./.github/workflows/AGENTS.md` -- CI/CD pipeline configuration

## When Instructions Conflict
Nearest AGENTS.md wins. User prompts override files.
