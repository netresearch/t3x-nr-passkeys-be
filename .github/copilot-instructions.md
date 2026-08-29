# Copilot Instructions -- nr_passkeys_be

TYPO3 extension for passwordless backend authentication via WebAuthn/FIDO2 Passkeys.

## Key context
- Extension key: `nr_passkeys_be`, Namespace: `Netresearch\NrPasskeysBe`
- PHP ^8.2, TYPO3 ^12.4 || ^13.4 || ^14.3, `web-auth/webauthn-lib` ^5.3
- Passkeys are **primary credentials** (NOT MFA), auth priority 80
- PER-CS3.0 code style, PHPStan level 10, `declare(strict_types=1)` in all files
- Do NOT commit `composer.lock`

## Architecture
- `PasskeyAuthenticationService` uses `GeneralUtility::makeInstance()` for deps (no DI in auth services)
- `ChallengeService`: HMAC-SHA256 challenge tokens with nonce replay protection
- `RateLimiterService`: Per-endpoint rate limiting + account lockout
- `Credential`: Plain PHP entity (not Extbase), soft delete + revocation
- Controllers use `JsonBodyTrait` for JSON request body parsing
- Per-group enforcement: `EnforcementLevel` enum (Off, Encourage, Required, Enforced)
- `EnforcementService` + `AdoptionStatsService` for enforcement logic and dashboard metrics
- `PasskeySetupInterstitial` middleware: blocks navigation until passkey registered
- `InjectPasskeyBanner` event listener: encourage-stage banner with v12-v14 compat
- 10 typed DTOs in `Domain/Dto/`, all readonly; API-facing ones implement `JsonSerializable`

## Commands
- `composer ci:test:php:cgl` -- code style check
- `composer ci:test:php:phpstan` -- PHPStan level 10
- `composer ci:test:php:unit` -- unit tests (~546)
- `composer ci:test:php:functional` -- functional tests (MySQL required)
- `npx vitest run` -- JS tests (~63)
- `./Build/Scripts/runTests.sh -s e2e` -- E2E tests (installs its own TYPO3 in containers)
- `composer ci:mutation` -- mutation testing (MSI >= 80%, covered-MSI >= 80%)

## Conventions
- Use constructor DI via Services.yaml (except auth service and userFunc classes)
- Use QueryBuilder for database access, never raw SQL
- User enumeration prevention: dummy responses with randomized timing
- Test doubles for `web-auth/webauthn-lib` (classes are `final`, use `dg/bypass-finals`)
- Conventional Commits: `type(scope): subject`
- All non-extending classes are `final` (enforced by phpat rules)
- Prefer typed DTOs over untyped arrays for public API responses
