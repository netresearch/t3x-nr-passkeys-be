..  include:: ../Includes.rst.txt

..  _changelog:

=========
Changelog
=========

Unreleased
==========

Features
--------

- New ``skipMfaOnPasskeyAuth`` extension setting (default enabled): when
  a user authenticates with a passkey, the TYPO3 MFA challenge is
  skipped for that session. A passkey is already multi-factor, so
  requiring TOTP on top is redundant. Password-based logins are
  unaffected and still go through MFA as configured. This resolves the
  MFA-policy dilemma where forcing MFA for password users also forced
  passkey users through a second factor they had already provided.
- Help tab "Passkeys & MFA" section rewritten to name the password-only
  loophole (disabling ``requireMfa`` lets password-only logins through
  without any second factor) and document the recommended production
  combination of ``requireMfa`` + ``skipMfaOnPasskeyAuth`` +
  ``disablePasswordLogin``.

0.7.0
=====

Features
--------

- Help icon button in DocHeader (question-mark icon via TYPO3 ButtonBar
  API) so the Help tab is discoverable without the dropdown menu
- Adoption rate gamification badges on Dashboard: Getting started,
  Bronze (25%), Silver (50%), Gold (75%), Platinum (100%) with icons
- Quick Start guide on Dashboard for new installations with step-by-step
  setup instructions and auto-detected rpId display
- MFA hint on Dashboard informing admins that passkeys are inherently
  multi-factor and TOTP may be redundant
- Configuration status hints when rpId and origin are both auto-detected
- Enhanced Help page MFA section: renamed to "Passkeys & MFA", added
  prominent infobox answering "Are passkeys secure enough without MFA?"
- README: Quick Start section, Passkeys & MFA guidance, TER docs link,
  rpId/rpName/origin in configuration table

Fixes
-----

- Use ``InfoboxViewHelper::STATE_*`` integer constants for cross-version
  ``f:be.infobox`` compatibility (v12/v13/v14)
- Use ``enum_exists(IconSize::class)`` runtime check for ``getIcon()``
  v12 compatibility (v12 uses string, v13+ uses ``IconSize`` enum)
- Badge labels are translatable via ``TranslationTrait``

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
