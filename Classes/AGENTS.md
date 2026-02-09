<!-- Managed by agent: keep sections and order; edit content, not structure. Last updated: 2026-02-09 -->

# AGENTS.md -- Classes

## Overview
TYPO3 extension source code. Namespace: `Netresearch\NrPasskeysBe`. Follows PER-CS2.0 and PHPStan level 8.

## Key Files
| File | Purpose |
|------|---------|
| `Authentication/PasskeyAuthenticationService.php` | TYPO3 auth chain service (priority 80). Uses `GeneralUtility::makeInstance()` for deps. |
| `Configuration/ExtensionConfiguration.php` | Typed value object for extension settings |
| `Controller/LoginController.php` | Public endpoints: `/passkeys/login/options`, `/passkeys/login/verify` |
| `Controller/ManagementController.php` | Authenticated: register, list, rename, remove own passkeys |
| `Controller/AdminController.php` | Admin-only: list/revoke any user's passkeys, unlock accounts |
| `Controller/JsonBodyTrait.php` | Shared JSON request body parsing for all controllers |
| `Domain/Model/Credential.php` | Plain PHP entity with `fromArray()`/`toArray()`, soft delete + revocation |
| `LoginProvider/PasskeyLoginProvider.php` | TYPO3 backend login form integration |
| `Service/WebAuthnService.php` | Core WebAuthn ceremony logic (attestation + assertion) |
| `Service/ChallengeService.php` | HMAC-signed challenge tokens with nonce replay protection |
| `Service/CredentialRepository.php` | Database CRUD via TYPO3 QueryBuilder |
| `Service/RateLimiterService.php` | Per-endpoint rate limiting + account lockout |
| `Service/ExtensionConfigurationService.php` | Reads extension configuration from TYPO3 |

## Golden Samples (follow these patterns)
| Pattern | Reference |
|---------|-----------|
| Service with DI | `Service/ChallengeService.php` |
| Controller with JSON | `Controller/LoginController.php` |
| Auth service (no DI) | `Authentication/PasskeyAuthenticationService.php` |

## Code style & conventions
- **PER-CS2.0** via php-cs-fixer (not PSR-12)
- `declare(strict_types=1)` in all files
- Namespace: `Netresearch\NrPasskeysBe\` (PSR-4 from Classes/)
- Use constructor DI via `Services.yaml` for all services/controllers
- **Exception**: `PasskeyAuthenticationService` uses `GeneralUtility::makeInstance()` because TYPO3 auth services are instantiated by the service manager (no DI available)
- No Extbase models — `Credential` is a plain PHP class
- No ViewHelpers in this extension
- User enumeration prevention: dummy responses with randomized timing for unknown users
- Label sanitization: trimmed, max 128 chars

## Security & safety
- Use QueryBuilder with explicit restriction removal for credential queries
- HMAC-SHA256 for challenge tokens, constant-time comparison
- Nonce-based replay protection for challenges
- Rate limiting per IP per endpoint
- Account lockout after configurable failed attempts
- Credential ownership verification before any mutation

## Build & tests
| Task | Command |
|------|---------|
| Lint check | `composer ci:lint:php` |
| Lint fix | `composer ci:lint:php:fix` |
| PHPStan | `composer ci:stan` |
| Unit tests | `composer ci:test:php:unit` |

## PR/commit checklist
- [ ] `composer ci:lint:php` passes
- [ ] `composer ci:stan` passes (PHPStan level 8)
- [ ] `composer ci:test:php:unit` passes
- [ ] TCA changes have matching SQL in `ext_tables.sql`
- [ ] No deprecated TYPO3 APIs
- [ ] Tested on TYPO3 ^13.4 and ^14.1
