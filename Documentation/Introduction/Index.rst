.. include:: ../Includes.rst.txt

============
Introduction
============

What does it do?
================

Passkeys Backend Authentication provides passwordless authentication for the TYPO3 backend
using the WebAuthn/FIDO2 standard (Passkeys). Backend users can log in with a
single touch or glance using biometric authenticators such as TouchID, FaceID,
Windows Hello, or hardware security keys like YubiKey.

Passkeys are a modern, phishing-resistant replacement for passwords. They use
public-key cryptography: the private key never leaves the user's device, and
the server only stores a public key. This eliminates the risk of credential
theft through phishing or database breaches.

Features
========

- **Passwordless login** -- Authenticate with TouchID, FaceID, YubiKey, or
  Windows Hello instead of a password.
- **Primary credential** -- Passkeys are a first-class authentication method
  (not MFA). The extension registers at priority 80, above the standard
  password service.
- **Credential management** -- Users can register, rename, and remove their
  own passkeys through the TYPO3 User Settings module.
- **Admin panel** -- Administrators can list, revoke, and manage passkeys for
  any backend user, and unlock locked-out accounts.
- **Discoverable login** -- Optional identifierless login (Conditional UI)
  where the browser auto-suggests available passkeys without entering a
  username first. Controlled via a feature flag.
- **Rate limiting** -- Per-endpoint rate limiting by IP address to prevent
  abuse, plus account lockout after configurable failed attempt thresholds.
- **HMAC-signed challenges** -- Challenge tokens are signed with HMAC-SHA256
  using the TYPO3 encryption key. Nonce-based replay protection ensures each
  challenge can only be used once.
- **User enumeration prevention** -- Consistent error responses and randomized
  timing for requests involving unknown usernames.
- **Soft delete and revocation** -- Credentials support both soft deletion and
  admin-initiated revocation with audit trails.
- **Configurable algorithms** -- Supports ES256, ES384, ES512, and RS256
  signing algorithms (configurable via extension settings).
- **TYPO3 v13 and v14** -- Compatible with TYPO3 13.4 LTS and TYPO3 14.x.

Supported authenticators
========================

Any FIDO2/WebAuthn-compliant authenticator works, including:

- Apple TouchID and FaceID (macOS, iOS, iPadOS)
- Windows Hello (fingerprint, face, PIN)
- YubiKey 5 series and newer
- Android fingerprint and face unlock
- Any FIDO2-compliant hardware security key

Browser support
===============

WebAuthn is supported by all modern browsers:

- Chrome / Edge 67+
- Firefox 60+
- Safari 14+
- Chrome for Android 70+
- Safari for iOS 14.5+
