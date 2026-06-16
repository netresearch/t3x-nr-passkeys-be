<!-- Managed by agent: keep sections and order; edit content, not structure. Last updated: 2026-03-27 -->

# AGENTS.md -- Classes

## Overview
TYPO3 extension source code. Namespace: `Netresearch\NrPasskeysBe`. Follows PER-CS3.0 and PHPStan level 10.

## Key Files
| File | Purpose |
|------|---------|
| `Authentication/PasskeyAuthenticationService.php` | TYPO3 auth chain service (priority 80). Uses `GeneralUtility::makeInstance()` for deps. |
| `Configuration/ExtensionConfiguration.php` | Typed value object for extension settings |
| `Controller/LoginController.php` | Public endpoints: `/passkeys/login/options`, `/passkeys/login/verify` |
| `Controller/ManagementController.php` | Authenticated: register, list, rename, remove own passkeys |
| `Controller/AdminController.php` | Admin-only JSON API: list/revoke passkeys, update enforcement, send reminders |
| `Controller/AdminModuleController.php` | Backend module: admin dashboard with adoption stats and enforcement controls |
| `Controller/JsonBodyTrait.php` | Shared JSON request body parsing for all controllers |
| `Utility/TranslationTrait.php` | Shared translation helper with fallback (uses $GLOBALS['LANG']) |
| `Controller/BackendUserTrait.php` | Extracts authenticated user from $GLOBALS['BE_USER'] as AuthenticatedUser DTO |
| `Domain/Dto/EnforcementStatus.php` | User's enforcement status, grace period, passkey ownership |
| `Domain/Dto/AdoptionStats.php` | Passkey adoption statistics per group |
| `Domain/Dto/GroupEnforcementInfo.php` | User group's enforcement configuration |
| `Domain/Dto/UserPasskeyStatus.php` | Backend user's passkey registration status |
| `Domain/Enum/EnforcementLevel.php` | Off, Encourage, Required, Enforced (backed string enum) |
| `Domain/Model/Credential.php` | Plain PHP entity with `fromArray()`/`toArray()`, soft delete + revocation |
| `EventListener/InjectPasskeyLoginFields.php` | PSR-14: injects passkey fields into standard login form |
| `EventListener/InjectPasskeyBanner.php` | PSR-14: injects adoption banner for Encourage enforcement |
| `Form/Element/PasskeyInfoElement.php` | FormEngine element showing passkey info in user records |
| `Middleware/PasskeySetupInterstitial.php` | PSR-15: blocks navigation until passkey registered (Required level) |
| `Middleware/PublicRouteResolver.php` | PSR-15: resolves public (unauthenticated) routes |
| `Service/WebAuthnService.php` | Core WebAuthn ceremony logic (attestation + assertion) |
| `Service/ChallengeService.php` | HMAC-signed challenge tokens with nonce replay protection |
| `Service/CredentialRepository.php` | Database CRUD via TYPO3 QueryBuilder |
| `Service/RateLimiterService.php` | Per-endpoint rate limiting + account lockout |
| `Service/EnforcementService.php` | Evaluates enforcement level and grace period for a user |
| `Service/AdoptionStatsService.php` | Calculates adoption metrics for dashboard |
| `Service/ExtensionConfigurationService.php` | Reads extension configuration + centralized encryptionKey access |
| `Utility/TypeCastTrait.php` | Shared type coercion helpers: intVal(mixed), stringVal(mixed) |
| `UserSettings/PasskeySettingsPanel.php` | User settings panel (uses `callUserFunction`, no DI) |

## Golden Samples (follow these patterns)
| Pattern | Reference |
|---------|-----------|
| Service with DI | `Service/ChallengeService.php` |
| Controller with JSON | `Controller/LoginController.php` |
| Auth service (no DI) | `Authentication/PasskeyAuthenticationService.php` |

## Code style & conventions
- **PER-CS3.0** via php-cs-fixer (not PSR-12)
- `declare(strict_types=1)` in all files
- Namespace: `Netresearch\NrPasskeysBe\` (PSR-4 from Classes/)
- Use constructor DI via `Services.yaml` for all services/controllers
- **Exception**: `PasskeyAuthenticationService` uses `GeneralUtility::makeInstance()` (TYPO3 auth chain, no DI)
- **Exception**: `PasskeySettingsPanel` uses `GeneralUtility::makeInstance()` (TYPO3 callUserFunction, no DI)
- Shared traits: `JsonBodyTrait`, `BackendUserTrait` (Controller/); `TranslationTrait`, `TypeCastTrait` (Utility/)
- No Extbase models — `Credential` is a plain PHP class
- No ViewHelpers in this extension
- User enumeration prevention: dummy responses with randomized timing for unknown users
- Label sanitization: trimmed, max 128 chars
- All security-sensitive services inject `LoggerInterface` for audit logging
- Never log plaintext usernames -- use `hash('sha256', $username)`

## Security & safety
- Use QueryBuilder with explicit restriction removal for credential queries
- HMAC-SHA256 for challenge tokens, constant-time comparison
- Nonce-based replay protection for challenges
- Rate limiting per IP per endpoint with atomic locking (LockFactory)
- Account lockout with dual counters: per-IP+username and per-username (distributed attack defense)
- Comprehensive audit logging on all security-sensitive operations
- Credential ownership verification before any mutation

## Build & tests
| Task | Command |
|------|---------|
| CGL check | `composer ci:test:php:cgl` |
| CGL fix | `composer ci:cgl` |
| PHPStan | `composer ci:test:php:phpstan` |
| Unit tests | `composer ci:test:php:unit` |

## PR/commit checklist
- [ ] `composer ci:test:php:cgl` passes
- [ ] `composer ci:test:php:phpstan` passes (PHPStan level 10)
- [ ] `composer ci:test:php:unit` passes
- [ ] TCA changes have matching SQL in `ext_tables.sql`
- [ ] No deprecated TYPO3 APIs
- [ ] Tested on TYPO3 ^12.4, ^13.4, and ^14.3
