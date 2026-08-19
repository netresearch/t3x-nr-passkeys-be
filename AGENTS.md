<!-- FOR AI AGENTS - Human readability is a side effect, not a goal -->
<!-- Managed by agent: keep sections and order; edit content, not structure. Last updated: 2026-08-19 -->
<!-- Drift-prone fields (versions, test counts, dates) are intentionally absent -- verify on demand: `gh release view --json tagName,isLatest`; run the relevant suite for counts. -->

# AGENTS.md

**Precedence:** The **closest AGENTS.md** to changed files wins. Root holds global defaults only.

## Project Overview

**nr_passkeys_be** -- TYPO3 extension for passwordless backend login via WebAuthn/FIDO2 Passkeys (TouchID, FaceID, YubiKey, Windows Hello).
Per-group enforcement with gradual rollout (Off → Encourage → Required → Enforced), admin dashboard with adoption stats, onboarding UX (banner, interstitial, reminders).

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
- Signed commits required: `git commit -S --signoff` (`require-signed-commits` ruleset + DCO check)
- `declare(strict_types=1)` in all PHP files
- PER-CS3.0 code style via php-cs-fixer
- PHPStan level 10 (do not lower)
- Do NOT commit `composer.lock` (in `.gitignore`)
- Do NOT use DDEV for running tests -- DDEV is for local development only
- E2E tests run via `Build/Scripts/runTests.sh e2e` (PHP built-in server + MySQL container); local-only, no CI e2e workflow at present

## Commands (verified)
> Source: `composer.json` scripts, `Makefile`, `package.json`, `Build/Scripts/runTests.sh`

| Task | Command | ~Time |
|------|---------|-------|
| Install | `composer install` | 30s |
| CGL check / fix | `composer ci:test:php:cgl` / `composer ci:cgl` | 5s |
| Static analysis | `composer ci:test:php:phpstan` | 10s |
| Rector (dry-run) | `composer ci:test:php:rector` | 10s |
| Unit tests | `composer ci:test:php:unit` | 5s |
| Fuzz tests | `composer ci:test:php:fuzz` | 5s |
| Functional tests | `composer ci:test:php:functional` | 30s |
| All checks except functional | `composer ci:check` | 30s |
| Full PHP suite (cgl+stan+rector+unit+fuzz+functional) | `composer ci:test:php:all` | 60s |
| JS tests | `npm run test:js` | 2s |
| E2E tests | `Build/Scripts/runTests.sh e2e` | 60s |
| Mutation testing | `composer ci:mutation` | 60s |
| Local CI (no DB) | `make ci` | 25s |
| Local dev setup | `make up` | 5m |

## File Map
```
Classes/             -> PHP source (PSR-4: Netresearch\NrPasskeysBe\); see Classes/AGENTS.md.
                        Subdirs: Authentication, Command, Configuration, Controller, Domain
                        (Dto/Enum/Model), EventListener, Form, Middleware, Service,
                        UserSettings, Utility, Widgets
Build/               -> Tooling config (phpstan.neon, rector.php, infection.json5,
                        captainhook.json) + Scripts/runTests.sh (NOT .Build/ = composer output)
Configuration/       -> TYPO3 config (TCA, Backend routes, Services.yaml, Services.Dashboard.php)
Documentation/       -> TYPO3 RST documentation (docs.typo3.org format)
Resources/Private/   -> Fluid templates (AdminModule, Interstitial, UserSettings), XLIFF files
Resources/Public/    -> JS modules (Login, Management, Banner, Dashboard, AdminInfo), CSS, Icons
Tests/               -> Unit, Functional (MySQL, CI only), Fuzz, JavaScript (Vitest),
                        E2E (Playwright), Architecture (PHPat); see Tests/AGENTS.md
docs/                -> ARCHITECTURE.md (component map + glossary), adr/, exec-plans/
Makefile             -> Local dev + CI targets (see `make help`)
.github/workflows/   -> Thin callers of central reusables; see .github/workflows/AGENTS.md
```
Golden samples per area live in the scoped AGENTS.md files (Classes, Tests).

## Heuristics
| When | Do |
|------|----|
| Adding a service | Use constructor DI via Services.yaml, inject LoggerInterface |
| Auth service deps | Use `GeneralUtility::makeInstance()` (no DI available) |
| Database access | Use QueryBuilder, never raw SQL |
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
- `web-auth/webauthn-lib` v5.x classes are `final` -- use `dg/bypass-finals` for mocking
- PHPStan config is inlined in `Build/phpstan.neon` (the shared typo3-ci-workflows config needs phpstan-typo3, which requires TYPO3 ^13.4 -- incompatible with v12 support)
- Discoverable login behind `discoverableLoginEnabled` feature flag
- V14 uses web components (`typo3-backend-module-router`) instead of `.scaffold-content-module`
- RateLimiterService uses atomic locking (LockFactory) with dual counters (per-IP + per-username)
- Encryption key access centralized in ExtensionConfigurationService::getEncryptionKey()
- Dashboard widgets (`Classes/Widgets/`) are registered on TYPO3 v14.3+ only via the guarded `Configuration/Services.Dashboard.php`

## Index of Scoped AGENTS.md
- `./Classes/AGENTS.md` -- PHP source code patterns, traits, and TYPO3 conventions
- `./Tests/AGENTS.md` -- Test structure, commands, and patterns
- `./Resources/AGENTS.md` -- Templates, translations, and static assets
- `./Documentation/AGENTS.md` -- TYPO3 RST documentation standards
- `./.github/workflows/AGENTS.md` -- CI/CD pipeline configuration
- `./.ddev/AGENTS.md` -- DDEV local development environment
