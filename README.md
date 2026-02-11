<p align="center">
  <a href="https://www.netresearch.de/">
    <img src="Resources/Public/Icons/Extension.svg" alt="Netresearch" width="80" height="80">
  </a>
</p>

<h1 align="center">Passkeys Backend Authentication</h1>

<p align="center">
  Passwordless TYPO3 backend login via WebAuthn/FIDO2 Passkeys.<br>
  One-click authentication with TouchID, FaceID, YubiKey, and Windows Hello.
</p>

<!-- Row 1: CI/Quality badges -->
<p align="center">
  <a href="https://github.com/netresearch/t3x-nr-passkeys-be/actions/workflows/ci.yml"><img src="https://github.com/netresearch/t3x-nr-passkeys-be/actions/workflows/ci.yml/badge.svg" alt="CI"></a>
  <a href="https://codecov.io/gh/netresearch/t3x-nr-passkeys-be"><img src="https://codecov.io/gh/netresearch/t3x-nr-passkeys-be/graph/badge.svg" alt="codecov"></a>
</p>

<!-- Row 2: Security badges -->
<p align="center">
  <a href="https://securityscorecards.dev/viewer/?uri=github.com/netresearch/t3x-nr-passkeys-be"><img src="https://api.securityscorecards.dev/projects/github.com/netresearch/t3x-nr-passkeys-be/badge" alt="OpenSSF Scorecard"></a>
</p>

<!-- Row 3: Standards badges -->
<p align="center">
  <a href="https://phpstan.org/"><img src="https://img.shields.io/badge/PHPStan-Level%2010-brightgreen.svg" alt="PHPStan"></a>
  <a href="https://infection.github.io/"><img src="https://img.shields.io/badge/Infection%20MSI-%E2%89%A560%25-yellowgreen" alt="Mutation"></a>
  <a href="https://www.php.net/"><img src="https://img.shields.io/badge/PHP-8.2--8.5-blue.svg?logo=php" alt="PHP"></a>
  <a href="https://typo3.org/"><img src="https://img.shields.io/badge/TYPO3-13%20LTS%20%7C%2014-orange.svg?logo=typo3" alt="TYPO3"></a>
  <a href="https://github.com/netresearch/t3x-nr-passkeys-be/blob/main/LICENSE"><img src="https://img.shields.io/github/license/netresearch/t3x-nr-passkeys-be" alt="License"></a>
  <a href="https://github.com/netresearch/t3x-nr-passkeys-be/releases"><img src="https://img.shields.io/github/v/release/netresearch/t3x-nr-passkeys-be" alt="Latest Release"></a>
</p>

---

## Overview

**nr_passkeys_be** replaces traditional password authentication in the TYPO3 backend with modern passkeys. It registers as a TYPO3 authentication service at priority 80, intercepting login requests before the standard password service. When passkey data is present, it performs full WebAuthn assertion verification. Otherwise, it falls through to password login (unless disabled).

|                    |                                          |
|--------------------|------------------------------------------|
| **Extension key**  | `nr_passkeys_be`                         |
| **Package**        | `netresearch/nr-passkeys-be`             |
| **TYPO3**          | 13.4 LTS, 14.x                          |
| **PHP**            | 8.2, 8.3, 8.4, 8.5                      |
| **License**        | GPL-2.0-or-later                         |

## Features

- **Primary authentication** -- Passkeys replace passwords, not just augment them
- **Discoverable login** -- Optional username-less login via resident credentials
- **Admin management** -- Admins can list, revoke passkeys and unlock locked accounts
- **Self-service** -- Users register, rename, and remove their own passkeys
- **Rate limiting** -- Per-endpoint and per-account lockout protection
- **Replay protection** -- HMAC-signed challenge tokens with single-use nonces

### Supported Authenticators

| Platform         | Authenticator                             |
|------------------|-------------------------------------------|
| macOS / iOS      | TouchID, FaceID                           |
| Windows          | Windows Hello                             |
| Cross-platform   | YubiKey, other FIDO2 security keys        |

## Installation

```bash
composer require netresearch/nr-passkeys-be
```

Activate the extension in the TYPO3 Extension Manager or via CLI:

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
| `discoverableLoginEnabled` | `true` | Allow username-less login via resident credentials |

## How It Works

The extension registers a TYPO3 authentication service at priority 80 (above `SaltedPasswordService` at 50). When passkey assertion data is present in the login request, it verifies the WebAuthn assertion. When no passkey data is present, it passes through to the next auth service (standard password login) unless password login is disabled.

### API Endpoints

**Login** (public):
- `POST /passkeys/login/options` -- Generate authentication challenge
- `POST /passkeys/login/verify` -- Verify passkey assertion

**Self-Service** (authenticated, AJAX routes):
- `POST /ajax/passkeys/manage/registration/options` -- Generate registration challenge *
- `POST /ajax/passkeys/manage/registration/verify` -- Complete passkey registration *
- `GET /ajax/passkeys/manage/list` -- List own passkeys
- `POST /ajax/passkeys/manage/rename` -- Rename a passkey label *
- `POST /ajax/passkeys/manage/remove` -- Remove a passkey *

**Admin** (admin-only, AJAX routes):
- `GET /ajax/passkeys/admin/list?beUserUid=N` -- List any user's passkeys
- `POST /ajax/passkeys/admin/remove` -- Revoke a user's passkey *
- `POST /ajax/passkeys/admin/unlock` -- Unlock a locked-out user *

\* Protected by TYPO3 **Sudo Mode** -- write operations require password re-verification (15 min grant lifetime).

## Documentation

Full documentation is available in the [Documentation/](Documentation/) directory, covering installation, configuration, administration, and developer guides.

## Development

```bash
composer install

# Code quality
composer ci:lint:php          # Check code style (PER-CS2.0)
composer ci:lint:php:fix      # Fix code style
composer ci:stan              # PHPStan level 10

# Tests
composer ci:test:php:unit         # Unit tests
composer ci:test:php:functional   # Functional tests (requires MySQL)
composer ci:test:php:all          # All test suites
composer ci:mutation              # Mutation testing (MSI >= 60%)

# Or use make
make ci                           # Run lint + stan + unit + fuzz locally
make up                           # Start DDEV with all TYPO3 versions
make help                         # Show all available targets
```

## Security

If you discover a security vulnerability, please report it responsibly. See [SECURITY.md](SECURITY.md) for details.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).

---

<p align="center">
  Developed and maintained by <a href="https://www.netresearch.de/">Netresearch DTT GmbH</a>
</p>
