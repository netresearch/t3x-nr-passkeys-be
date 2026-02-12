..  include:: ../Includes.rst.txt

..  _changelog:

=========
Changelog
=========

0.3.0
=====

Features
--------

- Inline name input for passkey registration -- users can name their
  passkey before registering (defaults to "Passkey")
- Accessible ``aria-label`` on the name input field
- Input is disabled during registration and reset after success

Refactoring
-----------

- Rewrote ``PasskeyManagement.js`` from IIFE to ES module using TYPO3
  native APIs: ``AjaxRequest``, ``Notification``, ``Modal``,
  ``SeverityEnum``, ``sudoModeInterceptor``, ``DocumentService``
- Replaced ``PageRenderer::addJsFile()`` with
  ``loadJavaScriptModule()``
- Replaced inline style with CSS class

Fixes
-----

- Escape label in removal confirmation modal (XSS prevention)
- Defer DOM initialization with ``DocumentService.ready()``
- Resolve ``AjaxRequest`` responses and check status before showing
  success notifications

0.2.0
=====

Features
--------

- Warn about short or missing TYPO3 encryption key in the passkey
  settings panel (minimum 32 characters required)
- Include exception details in management API error responses for
  authenticated users

Documentation
-------------

- Added Troubleshooting section covering encryption key issues, HTTPS
  requirements, log location, and debug mode

0.1.0
=====

Initial release.

Features
--------

- Passwordless backend authentication via WebAuthn/FIDO2 Passkeys
- Passkey button injected into the standard TYPO3 login form via
  PSR-14 event listener (no login provider switching)
- Support for TouchID, FaceID, YubiKey, Windows Hello, and other
  FIDO2-compliant authenticators
- Authentication service at priority 80 (above standard password
  service)
- Authentication data packed into ``userident`` field as JSON
  (``$GLOBALS['TYPO3_REQUEST']`` is null during auth chain)
- Credential registration, listing, renaming, and removal for users
- Admin API for listing, revoking credentials and unlocking accounts
- HMAC-SHA256 signed challenge tokens with nonce replay protection
- Per-endpoint rate limiting by IP address
- Account lockout after configurable failed attempt threshold
- Discoverable login (usernameless, Conditional UI) behind feature
  flag
- Option to disable password login entirely (passkey-only mode)
- Configurable signing algorithms (ES256, ES384, ES512, RS256)
- Configurable user verification requirement
- User enumeration prevention with randomized timing
- Soft delete and admin revocation with audit trails
- Signature counter tracking for clone detection
- Passkey-specific error message on failed login attempts via
  sessionStorage detection
- Default audit log writer (WARNING+ to
  :file:`typo3temp/var/log/passkey_auth.log`)
- TYPO3 13.4 LTS and TYPO3 14.x compatibility
- PHP 8.2, 8.3, 8.4, and 8.5 support
- Comprehensive test suite (unit, fuzz, functional, JavaScript)
- PSR-3 logging for all significant events
