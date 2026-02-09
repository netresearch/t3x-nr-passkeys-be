# nr_passkeys_be - TYPO3 Passkeys Backend Authentication

[![CI](https://github.com/netresearch/t3x-nr-passkeys-be/actions/workflows/ci.yml/badge.svg)](https://github.com/netresearch/t3x-nr-passkeys-be/actions/workflows/ci.yml)
[![Coverage](https://codecov.io/gh/netresearch/t3x-nr-passkeys-be/graph/badge.svg)](https://codecov.io/gh/netresearch/t3x-nr-passkeys-be)
[![OpenSSF Scorecard](https://api.scorecard.dev/projects/github.com/netresearch/t3x-nr-passkeys-be/badge)](https://scorecard.dev/viewer/?uri=github.com/netresearch/t3x-nr-passkeys-be)
[![TYPO3](https://img.shields.io/badge/TYPO3-13%20LTS%20%7C%2014-orange?logo=typo3)](https://get.typo3.org/)
[![PHP](https://img.shields.io/badge/PHP-8.2--8.5-blue?logo=php)](https://www.php.net/)
[![License](https://img.shields.io/badge/License-GPL--2.0--or--later-blue)](LICENSE)
[![PHPStan](https://img.shields.io/badge/PHPStan-Level%208-brightgreen)](https://phpstan.org/)
[![Mutation](https://img.shields.io/badge/Infection%20MSI-%E2%89%A580%25-yellowgreen)](https://infection.github.io/)

Passwordless TYPO3 backend authentication via WebAuthn/FIDO2 Passkeys.
Enables one-click login with TouchID, FaceID, YubiKey, Windows Hello.

|                  | |
|------------------|-|
| **Extension key** | `nr_passkeys_be` |
| **Package**      | `netresearch/nr-passkeys-be` |
| **TYPO3**        | 13.4 LTS, 14.x |
| **PHP**          | 8.2, 8.3, 8.4, 8.5 |
| **License**      | GPL-2.0-or-later |

## Features

- **Primary authentication** - Passkeys replace passwords, not just augment them
- **Discoverable login** - Optional username-less login via resident credentials
- **Admin management** - Admins can list, revoke passkeys and unlock locked accounts
- **Self-service** - Users register, rename, and remove their own passkeys
- **Rate limiting** - Per-endpoint and per-account lockout protection
- **Replay protection** - HMAC-signed challenge tokens with single-use nonces

## Installation

```bash
composer require netresearch/nr-passkeys-be
```

Then activate the extension in the TYPO3 Extension Manager or via CLI:

```bash
vendor/bin/typo3 extension:activate nr_passkeys_be
```

## Configuration

Extension settings are available in **Admin Tools > Settings > Extension Configuration > nr_passkeys_be**:

| Setting | Default | Description |
|---------|---------|-------------|
| `challengeTtl` | `120` | Challenge token lifetime in seconds |
| `maxFailedAttempts` | `5` | Failed login attempts before account lockout |
| `lockoutDuration` | `900` | Lockout duration in seconds (15 min) |
| `disablePasswordLogin` | `false` | Block password login when passkey is registered |
| `discoverableLoginEnabled` | `false` | Allow username-less login via resident credentials |

## How It Works

The extension registers a TYPO3 authentication service at priority 80 (above `SaltedPasswordService` at 50). When passkey assertion data is present in the login request, it verifies the WebAuthn assertion. When no passkey data is present, it passes through to the next auth service (standard password login) unless password login is disabled.

### API Endpoints

**Login** (public):
- `POST /passkeys/login/options` - Generate authentication challenge
- `POST /passkeys/login/verify` - Verify passkey assertion

**Self-Service** (authenticated):
- `POST /passkeys/manage/registration/options` - Generate registration challenge
- `POST /passkeys/manage/registration/verify` - Complete passkey registration
- `GET /passkeys/manage/list` - List own passkeys
- `POST /passkeys/manage/rename` - Rename a passkey label
- `POST /passkeys/manage/remove` - Remove a passkey

**Admin** (admin-only):
- `GET /passkeys/admin/list?beUserUid=N` - List any user's passkeys
- `POST /passkeys/admin/remove` - Revoke a user's passkey
- `POST /passkeys/admin/unlock` - Unlock a locked-out user

## Development

```bash
composer install

# Code quality
composer ci:lint:php          # Check code style (PER-CS2.0)
composer ci:lint:php:fix      # Fix code style
composer ci:stan              # PHPStan level 8

# Tests
composer ci:test:php:unit         # Unit tests
composer ci:test:php:functional   # Functional tests (requires MySQL)
composer ci:test:php:all          # All test suites
composer ci:mutation              # Mutation testing (MSI >= 80%)
```

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
