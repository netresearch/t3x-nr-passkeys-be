# nr_passkeys_be - TYPO3 Passkeys Backend Authentication

TYPO3 extension providing passwordless backend authentication via WebAuthn/FIDO2 Passkeys.
Supports TouchID, FaceID, YubiKey, Windows Hello for one-click TYPO3 backend login.

- **Vendor**: Netresearch DTT GmbH
- **Namespace**: `Netresearch\NrPasskeysBe`
- **Extension key**: `nr_passkeys_be`
- **TYPO3**: 13.4 LTS + 14.1
- **PHP**: 8.2, 8.3, 8.4, 8.5

## Architecture

### Authentication Flow

Passkeys are primary credentials (NOT MFA). The extension registers an authentication service
at priority 80 (higher than SaltedPasswordService at 50). When passkey assertion data is present
in the login request, it verifies the assertion. When no passkey data is present, it passes
through to the next auth service (standard password login), unless password login is disabled.

### WebAuthn Library

Uses `web-auth/webauthn-lib` v5.x. Key integration points:
- `CeremonyStepManagerFactory` for creating ceremony validators
- `WebauthnSerializerFactory` for JSON serialization/deserialization of WebAuthn objects
- `AttestationStatementSupportManager` configured with `NoneAttestationStatementSupport` only
- Supported algorithms: ES256, ES384, ES512, RS256 (configurable)

### TYPO3 Auth Service Chain

`PasskeyAuthenticationService` extends `AbstractAuthenticationService`:
- `getUser()`: Checks for passkey assertion in request. If absent, falls through (returns false).
  If password login is disabled, blocks non-passkey attempts.
- `authUser()`: Returns 200 (authenticated, stop chain) on success, 0 (failed) on failure,
  100 (not responsible) when no passkey data present.
- Uses `GeneralUtility::makeInstance()` for service dependencies (not constructor injection)
  because TYPO3 auth services are instantiated by the service manager.

### Challenge Security

`ChallengeService` implements HMAC-signed challenge tokens with:
- 32-byte random challenge
- Expiration timestamp (configurable TTL, default 120s)
- Nonce for single-use enforcement (stored in TYPO3 cache, replay-protected)
- HMAC-SHA256 signing using TYPO3 encryption key
- Constant-time comparison for HMAC verification

### Rate Limiting

`RateLimiterService` provides two levels:
- **Per-endpoint rate limiting**: Limits requests per IP per endpoint
- **Lockout**: Locks accounts after N failed authentication attempts (configurable threshold/duration)
- Uses TYPO3 caching framework (`SimpleFileBackend`)

### Controllers

Three controller groups with TYPO3 backend routes:
- `LoginController` (public): `/passkeys/login/options`, `/passkeys/login/verify`
- `ManagementController` (authenticated): Registration, list, rename, remove own passkeys
- `AdminController` (admin-only): List/revoke any user's passkeys, unlock locked accounts

### Domain Model

Single table `tx_nrpasskeysbe_credential` with soft delete and revocation support.
`Credential` is a plain PHP class (not a TYPO3 Extbase model) with `fromArray()`/`toArray()`.
Excluded from PHPStan/coverage analysis (simple getters/setters).

## Key Patterns

- All classes use `declare(strict_types=1)`
- PHP-CS-Fixer with PER-CS2.0, native function invocations, strict params
- PHPStan level 8 with `saschaegerer/phpstan-typo3` and `ergebnis/phpstan-rules`
- User enumeration prevention: dummy responses with randomized timing for unknown users
- Credential ownership verification before any mutation operations
- Label sanitization: trimmed, max 128 chars

## Commands

```bash
# Install dependencies
composer install

# Linting
composer ci:lint:php          # Check code style (dry-run)
composer ci:lint:php:fix      # Fix code style

# Static analysis
composer ci:stan              # PHPStan level 8

# Tests
composer ci:test:php:unit         # Unit tests only
composer ci:test:php:functional   # Functional tests only
composer ci:test:php:all          # All test suites

# Mutation testing
composer ci:mutation          # Infection PHP (MSI >= 80%)
```

## Directory Structure

```
Classes/
  Authentication/     PasskeyAuthenticationService (TYPO3 auth chain)
  Configuration/      ExtensionConfiguration value object
  Controller/         LoginController, ManagementController, AdminController
  Domain/Model/       Credential entity
  LoginProvider/      PasskeyLoginProvider (TYPO3 login provider)
  Service/            WebAuthnService, ChallengeService, CredentialRepository,
                      RateLimiterService, ExtensionConfigurationService
Configuration/
  Backend/Routes.php  TYPO3 backend route definitions
  TCA/                Table configuration
  Services.yaml       Symfony DI configuration
Tests/
  Unit/               Unit tests
  Functional/         Functional tests (require database)
  Fuzz/               Fuzz tests (ChallengeToken, CredentialId, RequestPayload)
```

## TYPO3 Extension Conventions

- Do NOT commit `composer.lock` (it is in `.gitignore`)
- Vendor directory at `.Build/vendor`, web root at `.Build/Web`
- Binary directory at `.Build/bin`
