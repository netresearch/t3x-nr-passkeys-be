<!-- FOR AI AGENTS - Human readability is a side effect, not a goal -->
<!-- Managed by agent: keep sections and order; edit content, not structure -->
<!-- Drift-prone fields (version numbers, test counts, dated timestamps) are intentionally absent. -->
<!-- Verify on demand: `gh release view --json tagName,isLatest` for version; run the relevant suite from the Commands section for current test counts. -->

# AGENTS.md

**Precedence:** The **closest AGENTS.md** to changed files wins. Root holds global defaults only.

## Project Overview

**nr_passkeys_be** -- TYPO3 extension for passwordless backend authentication via WebAuthn/FIDO2 Passkeys.
Supports TouchID, FaceID, YubiKey, Windows Hello for one-click TYPO3 backend login.
Includes per-group enforcement with gradual rollout (Off → Encourage → Required → Enforced),
admin dashboard with adoption stats, and onboarding UX (banner, interstitial, reminders).

| Key | Value |
|-----|-------|
| Vendor | Netresearch DTT GmbH |
| Composer | `netresearch/nr-passkeys-be` |
| Extension key | `nr_passkeys_be` |
| Namespace | `Netresearch\NrPasskeysBe` |
| TYPO3 | ^12.4 \|\| ^13.4 \|\| ^14.3 |
| PHP | ^8.2 |
| WebAuthn lib | `web-auth/webauthn-lib` ^5.3 |

## Global Rules
- Conventional Commits: `type(scope): subject`
- `declare(strict_types=1)` in all PHP files
- PER-CS3.0 code style via php-cs-fixer
- PHPStan level 10 (do not lower)
- Do NOT commit `composer.lock` (in `.gitignore`)
- Do NOT use DDEV for running tests -- DDEV is for local development only
- E2E tests run via `Build/Scripts/runTests.sh e2e` or CI workflow (PHP built-in server + MySQL container)

## Commands (verified)
> Source: `composer.json` scripts, `Makefile`, `Build/Scripts/runTests.sh`

| Task | Command | ~Time |
|------|---------|-------|
| Install | `composer install` | 30s |
| CGL (check) | `composer ci:test:php:cgl` | 5s |
| CGL (fix) | `composer ci:cgl` | 5s |
| Static analysis | `composer ci:test:php:phpstan` | 10s |
| Unit tests | `composer ci:test:php:unit` | 5s |
| Fuzz tests | `composer ci:test:php:fuzz` | 5s |
| Functional tests | `composer ci:test:php:functional` | 30s |
| Unit + functional | `composer ci:test:php:all` | 35s |
| JS tests | `npx vitest run` | 2s |
| E2E tests | `Build/Scripts/runTests.sh e2e` | 60s |
| Mutation testing | `composer ci:mutation` | 60s |
| Local CI (no DB) | `make ci` | 25s |
| Local dev setup | `make up` | 5m |

## File Map
```
Classes/                  -> PHP source (PSR-4: Netresearch\NrPasskeysBe\)
  Authentication/         -> PasskeyAuthenticationService (TYPO3 auth chain, priority 80)
  Configuration/          -> ExtensionConfiguration value object
  Controller/             -> Login, Management, Admin, AdminModule controllers
                             Traits: JsonBodyTrait, TranslationTrait, BackendUserTrait
  Domain/Dto/             -> 10 typed DTOs (RegistrationOptions, EnforcementStatus, AdoptionStats, etc.)
  Domain/Enum/            -> EnforcementLevel enum (Off, Encourage, Required, Enforced)
  Domain/Model/           -> Credential entity (plain PHP, not Extbase)
  EventListener/          -> InjectPasskeyLoginFields, InjectPasskeyBanner (PSR-14)
  Form/Element/           -> PasskeyInfoElement (FormEngine)
  Middleware/             -> PasskeySetupInterstitial, PublicRouteResolver (PSR-15)
  Service/                -> WebAuthn, Challenge, Credential, RateLimiter, Enforcement, AdoptionStats, ExtConfig
  UserSettings/           -> PasskeySettingsPanel (user settings module)
  Utility/                -> TypeCastTrait (shared type coercion helpers)
Build/                    -> Tooling config + Scripts/runTests.sh (NOT .Build/ which is composer output)
Configuration/            -> TYPO3 config (TCA, Backend Routes, AjaxRoutes, Services.yaml)
Documentation/            -> TYPO3 RST documentation (docs.typo3.org format), 18 RST files, 8 screenshots
Resources/Private/        -> Fluid templates (AdminModule, Interstitial, UserSettings), 4 XLIFF files
Resources/Public/         -> 5 JS modules (Login, Management, Banner, Dashboard, AdminInfo), Icons
Tests/Unit/               -> Unit tests (PHPUnit, ~546 tests)
Tests/Functional/         -> Functional tests (require MySQL, CI only, ~69 tests)
Tests/Fuzz/               -> Fuzz tests (~131 tests)
Tests/JavaScript/         -> JS unit tests (Vitest, ~63 tests)
Tests/E2E/                -> E2E tests (Playwright, 9 spec files)
Tests/Architecture/       -> PHPat architecture rules (layer isolation, finality)
Makefile                  -> make up (dev), make ci (checks), make test-e2e, make help
.github/workflows/        -> CI, E2E, TER Publish, PR Quality, CodeQL, OpenSSF Scorecard
```

## Golden Samples
| For | Reference | Key patterns |
|-----|-----------|-------------|
| Service class | `Classes/Service/ChallengeService.php` | DI, audit logging, HMAC security, locking |
| Controller | `Classes/Controller/LoginController.php` | JsonBodyTrait, BackendUserTrait, PSR-7 |
| Admin module | `Classes/Controller/AdminModuleController.php` | Backend module, Fluid views, TranslationTrait |
| DTO | `Classes/Domain/Dto/EnforcementStatus.php` | Readonly, typed, behavioral methods |
| Enum | `Classes/Domain/Enum/EnforcementLevel.php` | Backed enum with severity ordering |
| Middleware | `Classes/Middleware/PasskeySetupInterstitial.php` | PSR-15, enforcement, audit logging |
| Unit test | `Tests/Unit/Service/ChallengeServiceTest.php` | Mocking final classes, data providers |
| JS module | `Resources/Public/JavaScript/PasskeyBanner.js` | Banner injection, v12-v14 compat |
| JS test | `Tests/JavaScript/PasskeyBanner.test.js` | Vitest, DOM testing |
| Auth service | `Classes/Authentication/PasskeyAuthenticationService.php` | GeneralUtility::makeInstance() pattern |
| Shared trait | `Classes/Utility/TypeCastTrait.php` | Type coercion for mixed DB/config values |

## Heuristics
| When | Do |
|------|----|
| Adding a service | Use constructor DI via Services.yaml, inject LoggerInterface |
| Auth service deps | Use `GeneralUtility::makeInstance()` (no DI available) |
| Controller returns JSON | Use `JsonBodyTrait` |
| Controller needs auth user | Use `BackendUserTrait` |
| Need translation with fallback | Use `TranslationTrait` |
| Type coercion from mixed | Use `TypeCastTrait` (intVal/stringVal) |
| Database access | Use QueryBuilder, never raw SQL |
| Testing final classes | Use `dg/bypass-finals` + create test doubles |
| Functional test needs DB | Only run in CI (MySQL required) |
| E2E tests | Use `Build/Scripts/runTests.sh e2e` -- never DDEV |
| Fuzz test flakes | Re-run -- `random_bytes()` can produce edge cases |
| Adding enforcement feature | Use `EnforcementLevel` enum, add DTO in `Domain/Dto/` |
| V14 DOM differences | Use fallback chain for container detection (see PasskeyBanner.js) |
| Releasing a version | Bump `ext_emconf.php` BEFORE tagging; `guides.xml` version too |
| Adding admin API endpoint | Add to `AjaxRoutes.php`, document in Administration/Index.rst |
| Security-sensitive operation | Add audit logging (WARNING for failures, INFO for success) |
| Logging usernames | Hash with `hash('sha256', $username)` -- never log plaintext |

## Boundaries

### Always Do
- Run `composer ci:test:php:cgl` and `composer ci:test:php:phpstan` before committing
- Add tests for new code paths
- Use conventional commit format
- Validate all user inputs
- Show test output as evidence before claiming work is complete
- Add audit logging for security-sensitive operations

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
- Lower PHPStan level below 10
- Disable security features (HMAC, nonce replay, rate limiting)
- Commit `composer.lock`
- Use DDEV for running tests (DDEV is for local dev only)
- Log plaintext usernames (use SHA-256 hash)

## Codebase State
- Passkeys are primary credentials (NOT MFA) -- registered at auth priority 80
- Per-group enforcement with 4 levels, admin dashboard, onboarding UX (banner, interstitial)
- `web-auth/webauthn-lib` v5.x classes are `final` -- use `dg/bypass-finals` for mocking
- `saschaegerer/phpstan-typo3` v2 only supports TYPO3 v13 (removed for v12 and v14 CI jobs)
- Functional tests require MySQL (CI only, not local)
- Discoverable login behind `discoverableLoginEnabled` feature flag
- V14 uses web components (`typo3-backend-module-router`) instead of `.scaffold-content-module`
- TER publish workflow requires `v` prefix stripping for version validation
- Shared traits: JsonBodyTrait, BackendUserTrait (Controller/); TranslationTrait, TypeCastTrait (Utility/)
- RateLimiterService uses atomic locking (LockFactory) with dual counters (per-IP + per-username)
- Encryption key access centralized in ExtensionConfigurationService::getEncryptionKey()
- Mutation testing: MSI >= 80%, covered MSI >= 80% (infection.json5)
- Test suites: unit, fuzz, functional, JS (Vitest), E2E (Playwright), architecture (PHPat). Run the relevant suite command from the Commands section above for current counts; counts are intentionally not pinned here.

## Terminology
| Term | Means |
|------|-------|
| Passkey | WebAuthn/FIDO2 credential (platform authenticator) |
| Assertion | Authentication ceremony (verifying a passkey) |
| Attestation | Registration ceremony (creating a passkey) |
| Challenge token | HMAC-signed, time-limited, single-use token for WebAuthn ceremonies |
| Lockout | Account lock after N failed auth attempts (per-IP and per-username) |
| Discoverable login | Login without entering username first (resident key) |
| Enforcement level | Per-group setting: Off, Encourage, Required, Enforced |
| Grace period | Days before Required enforcement becomes mandatory |
| Interstitial | Full-page setup prompt blocking navigation until passkey registered |
| Nudge | Admin-triggered reminder flag on a user's record |

## Index of Scoped AGENTS.md
- `./Classes/AGENTS.md` -- PHP source code patterns, traits, and TYPO3 conventions
- `./Tests/AGENTS.md` -- Test structure, commands, and patterns
- `./Resources/AGENTS.md` -- Templates, translations, and static assets
- `./Documentation/AGENTS.md` -- TYPO3 RST documentation standards
- `./.github/workflows/AGENTS.md` -- CI/CD pipeline configuration

## When Instructions Conflict
Nearest AGENTS.md wins. User prompts override files.

## Commit Signing

Signed commits are required: `git commit -S --signoff`. The `require-signed-commits` ruleset on the default branch rejects unsigned commits at merge time, and the DCO check additionally requires the `Signed-off-by` trailer. Quickest setup is SSH signing — register your SSH key as a *signing key* on your GitHub account, then `git config gpg.format ssh && git config user.signingkey ~/.ssh/<key>.pub`.
