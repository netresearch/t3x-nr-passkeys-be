.. include:: ../Includes.rst.txt

=========
Changelog
=========

1.0.0
=====

Initial release.

Features
--------

- Passwordless backend authentication via WebAuthn/FIDO2 Passkeys
- Support for TouchID, FaceID, YubiKey, Windows Hello, and other
  FIDO2-compliant authenticators
- Authentication service at priority 80 (above standard password service)
- Credential registration, listing, renaming, and removal for users
- Admin API for listing, revoking credentials and unlocking accounts
- HMAC-SHA256 signed challenge tokens with nonce replay protection
- Per-endpoint rate limiting by IP address
- Account lockout after configurable failed attempt threshold
- Discoverable login (identifierless, Conditional UI) behind feature flag
- Option to disable password login entirely (passkey-only mode)
- Configurable signing algorithms (ES256, ES384, ES512, RS256)
- Configurable user verification requirement
- User enumeration prevention with randomized timing
- Soft delete and admin revocation with audit trails
- Signature counter tracking for clone detection
- TYPO3 13.4 LTS and TYPO3 14.x compatibility
- PHP 8.2, 8.3, 8.4, and 8.5 support
- PSR-3 logging for all significant events
