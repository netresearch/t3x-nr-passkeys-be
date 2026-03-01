..  include:: ../Includes.rst.txt

..  _changelog:

=========
Changelog
=========

0.6.0
=====

Features
--------

- Per-group passkey enforcement with 4 levels: Off, Encourage, Required,
  Enforced
- Configurable grace periods for Required enforcement (1--365 days)
- PSR-15 interstitial middleware prompting users to register passkeys
  (skippable during grace period, mandatory after expiry)
- Encourage-stage dismissible banner with passkey explanation, docs link,
  and administrator contact guidance (supports TYPO3 v12/v13/v14)
- Admin dashboard backend module (Admin Tools > Passkey Management) with
  adoption statistics, per-group enforcement controls, and user list
- Admin actions: Send Reminder (nudge), Clear Nudge, Revoke All
- ``EnforcementLevel`` enum, ``EnforcementStatus`` DTO,
  ``EnforcementService``, ``AdoptionStatsService``
- ``PasskeyBanner.js``, ``PasskeyDashboard.js`` JavaScript modules
- TCA fields ``passkey_enforcement`` and ``passkey_grace_period_days``
  on ``be_groups``
- 5 new admin AJAX endpoints for enforcement and nudge management
- 153 i18n translation units across 4 XLF files
- Context-sensitive help tab in admin module with rollout guide, recovery
  procedures, MFA coexistence, and FAQ

0.5.0
=====

Features
--------

- Per-user password login enforcement: ``disablePasswordLogin`` now blocks
  passwords only for users who have registered passkeys, enabling gradual
  onboarding without locking out new users
- Deployment Scenarios documentation chapter covering multi-environment
  setup, database sync, user onboarding, and local DDEV development

0.4.0
=====

Features
--------

- TYPO3 12.4 LTS support (PHP 8.2+ required)
- Event listener registered via Services.yaml tag for v12 compatibility
  (``#[AsEventListener]`` attribute retained for v13+)
- ``PasskeyInfoElement`` DI-aware FormEngine node with ``setData()``
  for v12 ``NodeFactory`` compatibility
- CI matrix expanded with TYPO3 v12.4 test jobs
- DDEV development environment includes v12 installation

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
