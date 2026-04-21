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
  <a href="https://www.bestpractices.dev/projects/12037"><img src="https://www.bestpractices.dev/projects/12037/badge" alt="OpenSSF Best Practices"></a>
  <a href="https://securityscorecards.dev/viewer/?uri=github.com/netresearch/t3x-nr-passkeys-be"><img src="https://api.securityscorecards.dev/projects/github.com/netresearch/t3x-nr-passkeys-be/badge" alt="OpenSSF Scorecard"></a>
</p>

<!-- Row 3: Standards badges -->
<p align="center">
  <a href="https://phpstan.org/"><img src="https://img.shields.io/badge/PHPStan-Level%2010-brightgreen.svg" alt="PHPStan"></a>
  <a href="https://infection.github.io/"><img src="https://img.shields.io/badge/Infection%20MSI-%E2%89%A580%25-brightgreen" alt="Mutation"></a>
  <a href="https://www.php.net/"><img src="https://img.shields.io/badge/PHP-8.2--8.5-blue.svg?logo=php" alt="PHP"></a>
  <a href="https://typo3.org/"><img src="https://img.shields.io/badge/TYPO3-12%20LTS%20%7C%2013%20LTS%20%7C%2014-orange.svg?logo=typo3" alt="TYPO3"></a>
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
| **TYPO3**          | 12.4 LTS, 13.4 LTS, 14.x                |
| **PHP**            | 8.2, 8.3, 8.4, 8.5                      |
| **License**        | GPL-2.0-or-later                         |

## Features

- **Primary authentication** -- Passkeys replace passwords, not just augment them
- **Discoverable login** -- Optional username-less login via resident credentials
- **Per-group enforcement** -- 4 levels (Off, Encourage, Required, Enforced) with configurable grace periods for gradual rollout
- **Onboarding banner** -- Dismissible banner with passkey explanation, docs link, and administrator contact for encouraged users
- **Setup interstitial** -- PSR-15 middleware prompts users to register passkeys after login (skippable during grace period)
- **Admin dashboard** -- Backend module with adoption stats, per-group enforcement controls, user list, and bulk actions
- **Admin management** -- Admins can list, revoke passkeys, send reminders, and unlock locked accounts
- **Self-service** -- Users register, rename, and remove their own passkeys in User Settings
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

## Quick Start

After installation, follow these steps:

1. **Review extension settings** — Go to **Admin Tools > Settings > Extension Configuration > nr_passkeys_be** and verify the Relying Party settings (`rpId`, `rpName`, `origin`). The defaults auto-detect from your domain, which works for most single-domain setups.

2. **Open the Passkey Management module** — Navigate to **Admin Tools > Passkey Management**. The Dashboard shows adoption statistics and the Help tab provides a full rollout guide with recovery procedures and FAQ.

3. **Register your own passkey** — Go to **User Settings > Passkeys** and register a passkey to verify the setup works on your device.

4. **Enable enforcement for a pilot group** — On the Dashboard, set a small group (e.g., your admin team) to *Encourage*. Users will see a dismissible banner prompting them to set up a passkey.

5. **Roll out gradually** — Progress through *Encourage* → *Required* → *Enforced* as adoption grows. See the Help tab in the backend module for the complete rollout guide.

> **Full documentation:** [docs.typo3.org/p/netresearch/nr-passkeys-be](https://docs.typo3.org/p/netresearch/nr-passkeys-be/main/en-us/) · [Configuration reference](Documentation/Configuration/Index.rst)

## Passkeys & MFA

Passkeys are **inherently multi-factor**: they combine *something you have* (your device) with *something you are* (biometric) or *something you know* (device PIN). They are also phishing-resistant — the credential is cryptographically bound to the origin.

**For most TYPO3 backends, passkeys alone are more secure than password + TOTP.** The FIDO Alliance and W3C consider passkeys a stronger replacement for password + OTP. Adding TYPO3's MFA on top is optional defence-in-depth, not a requirement.

Consider keeping MFA enabled alongside passkeys only if:
- Your security policy explicitly mandates independent factors (e.g., PCI-DSS)
- Your threat model includes authenticator device compromise

See the Help tab in **Admin Tools > Passkey Management** for details on MFA coexistence and middleware processing order.

## Configuration

Extension settings are available in **Admin Tools > Settings > Extension Configuration > nr_passkeys_be**:

| Setting | Default | Description |
|---------|---------|-------------|
| `rpId` | *(auto-detect)* | Relying Party domain (e.g., `example.com`) |
| `rpName` | `TYPO3 Backend` | Display name shown during passkey registration |
| `origin` | *(auto-detect)* | Full origin URL (e.g., `https://example.com`) |
| `challengeTtlSeconds` | `120` | Challenge token lifetime in seconds |
| `discoverableLoginEnabled` | `true` | Allow username-less login via resident credentials |
| `disablePasswordLogin` | `false` | Block password login for users with registered passkeys |
| `rateLimitMaxAttempts` | `10` | Requests per IP per endpoint before rate limiting |
| `rateLimitWindowSeconds` | `300` | Rate limit window duration in seconds |
| `lockoutThreshold` | `5` | Failed login attempts (per IP) before account lockout |
| `lockoutUserThreshold` | `15` | Failed login attempts (per username, all IPs) before account lockout |
| `lockoutDurationSeconds` | `900` | Lockout duration in seconds (15 min) |
| `userVerification` | `required` | WebAuthn user verification requirement |
| `allowedAlgorithms` | `ES256` | Comma-separated signing algorithms |

See the [Configuration documentation](Documentation/Configuration/Index.rst) for detailed descriptions of each setting.

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
- `POST /ajax/passkeys/admin/revoke-all` -- Revoke all passkeys for a user *
- `POST /ajax/passkeys/admin/unlock` -- Unlock a locked-out user *
- `POST /ajax/passkeys/admin/update-enforcement` -- Update group enforcement level *
- `POST /ajax/passkeys/admin/send-reminder` -- Send passkey setup reminder *
- `POST /ajax/passkeys/admin/clear-nudge` -- Clear active nudge for a user *

**Enforcement** (authenticated, AJAX route):
- `GET /ajax/passkeys/enforcement/status` -- Get enforcement status for banner

\* Protected by TYPO3 **Sudo Mode** -- write operations require password re-verification (15 min grant lifetime).

## Documentation

- **Online documentation:** [docs.typo3.org/p/netresearch/nr-passkeys-be](https://docs.typo3.org/p/netresearch/nr-passkeys-be/main/en-us/)
- **In the backend:** Admin Tools > Passkey Management > Help tab (rollout guide, recovery, FAQ)
- **Source:** [Documentation/](Documentation/) directory (RST format)

## Development

```bash
composer install

# Code quality
composer ci:test:php:cgl       # Check code style (PER-CS3.0)
composer ci:cgl                # Fix code style
composer ci:test:php:phpstan   # PHPStan level 10

# Tests
composer ci:test:php:unit         # Unit tests
composer ci:test:php:functional   # Functional tests (requires MySQL)
composer ci:test:php:all          # All test suites
composer ci:mutation              # Mutation testing (MSI >= 80%)

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
