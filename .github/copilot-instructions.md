# Copilot Instructions -- nr_passkeys_be

TYPO3 extension for passwordless backend authentication via WebAuthn/FIDO2 Passkeys.

## Key context
- Extension key: `nr_passkeys_be`, Namespace: `Netresearch\NrPasskeysBe`
- PHP ^8.2, TYPO3 ^13.4 || ^14.1, `web-auth/webauthn-lib` ^5.2
- Passkeys are **primary credentials** (NOT MFA), auth priority 80
- PER-CS2.0 code style, PHPStan level 8, `declare(strict_types=1)` in all files
- Do NOT commit `composer.lock`

## Architecture
- `PasskeyAuthenticationService` uses `GeneralUtility::makeInstance()` for deps (no DI in auth services)
- `ChallengeService`: HMAC-SHA256 challenge tokens with nonce replay protection
- `RateLimiterService`: Per-endpoint rate limiting + account lockout
- `Credential`: Plain PHP entity (not Extbase), soft delete + revocation
- Controllers use `JsonBodyTrait` for JSON request body parsing

## Commands
- `composer ci:lint:php` -- code style check
- `composer ci:stan` -- PHPStan level 8
- `composer ci:test:php:unit` -- unit tests
- `composer ci:test:php:functional` -- functional tests (MySQL required)
- `composer ci:mutation` -- mutation testing (MSI >= 60%)

## Conventions
- Use constructor DI via Services.yaml (except auth service)
- Use QueryBuilder for database access, never raw SQL
- User enumeration prevention: dummy responses with randomized timing
- Test doubles for `web-auth/webauthn-lib` (classes are `final`)
- Conventional Commits: `type(scope): subject`
