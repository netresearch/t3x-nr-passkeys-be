# nr_passkeys_be - TYPO3 Passkeys Backend Authentication

See `AGENTS.md` for full project context, commands, file map, and conventions.

## Architecture (quick reference)

Passkeys are **primary credentials** (NOT MFA). Auth service priority 80 (above SaltedPasswordService at 50).

- **Auth flow**: Passkey assertion in request -> verify -> 200 (stop chain). No passkey data -> pass through (100).
- **Challenge security**: HMAC-SHA256 + nonce replay protection + configurable TTL (default 120s)
- **Rate limiting**: Per-endpoint IP limits + account lockout after N failures
- **WebAuthn lib**: `web-auth/webauthn-lib` v5.x (classes are `final`, cannot mock)
- **Auth service DI**: Uses `GeneralUtility::makeInstance()` (not constructor injection) because TYPO3 auth services are instantiated by the service manager

## Commands

```bash
composer ci:lint:php          # Check code style (dry-run)
composer ci:lint:php:fix      # Fix code style
composer ci:stan              # PHPStan level 8
composer ci:test:php:unit     # Unit tests
composer ci:test:php:functional  # Functional tests (MySQL, CI only)
composer ci:test:php:all      # All test suites
composer ci:mutation          # Infection PHP (MSI >= 60%)
```

## TYPO3 Extension Conventions

- Do NOT commit `composer.lock` (it is in `.gitignore`)
- Vendor directory at `.Build/vendor`, web root at `.Build/Web`
- Binary directory at `.Build/bin`
