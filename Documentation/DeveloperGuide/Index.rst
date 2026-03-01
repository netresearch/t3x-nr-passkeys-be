..  include:: ../Includes.rst.txt

..  _developer-guide:

===============
Developer Guide
===============

This chapter describes the extension's architecture and provides
guidance for developers who want to understand, debug, or extend the
extension.

Architecture overview
=====================

The extension consists of these core components:

..  code-block:: text

    Classes/
      Authentication/     Auth service (TYPO3 auth chain)
      Configuration/      Extension configuration value object
      Controller/         REST API + backend module controllers
      Domain/Dto/         DTOs and value objects
      Domain/Enum/        EnforcementLevel enum
      Domain/Model/       Credential entity
      EventListener/      PSR-14 listeners (login form, banner)
      Form/Element/       PasskeyInfoElement (FormEngine)
      Middleware/          PSR-15 middleware (routes, interstitial)
      Service/            Business logic services
      UserSettings/       PasskeySettingsPanel (User Settings)

Login form injection
====================

The passkey button is injected into the standard TYPO3 login form via
the ``InjectPasskeyLoginFields`` PSR-14 event listener. It listens to
``ModifyPageLayoutOnLoginProviderSelectionEvent`` and:

- Loads :file:`PasskeyLogin.js` via ``PageRenderer::addJsFile()``
- Injects an inline script with ``window.NrPasskeysBeConfig`` that
  provides ``loginOptionsUrl``, ``rpId``, ``origin``, and
  ``discoverableEnabled`` to the JavaScript

The JavaScript builds the passkey UI (button, error area, hidden
fields) dynamically via DOM manipulation and inserts it into
``#typo3-login-form``. No Fluid partial or separate template is
needed.

The passkey management panel in User Settings also uses
``loadJavaScriptModule()`` to load :file:`PasskeyManagement.js` as an
ES module, which imports TYPO3 native APIs (``AjaxRequest``,
``Notification``, ``Modal``, ``sudoModeInterceptor``,
``DocumentService``).

Banner injection
================

The ``InjectPasskeyBanner`` PSR-14 event listener listens to
``AfterBackendPageRenderEvent`` and loads :file:`PasskeyBanner.js` on
every backend page. The JavaScript fetches enforcement status via AJAX,
checks ``sessionStorage`` for prior dismissal, and renders a rich banner
with title, passkey explanation, documentation link, and administrator
contact help text. The banner supports TYPO3 v12/v13 (via
``.scaffold-content-module``) and v14 (via ``typo3-backend-module-router``
parent fallback).

Interstitial middleware
=======================

``PasskeySetupInterstitial`` is a PSR-15 middleware that intercepts
backend requests for users whose effective enforcement level is
**Required** or **Enforced**. If the user has no registered passkeys, it
renders a full-page interstitial prompting them to register.

The middleware exempts login, logout, AJAX, MFA, and passkey API routes.
During the grace period the user can skip the interstitial (protected by
a CSRF nonce). Once the grace period expires or the level is **Enforced**,
skipping is disabled.

Authentication data flow
========================

..  important::

    ``$GLOBALS['TYPO3_REQUEST']`` is ``null`` during the TYPO3 auth
    service chain. Custom POST fields are inaccessible. The only data
    available is ``$this->login`` with keys ``status``, ``uname``,
    ``uident``, and ``uident_text``.

The passkey assertion and challenge token are packed into the
``userident`` field as JSON:

..  code-block:: json

    {
        "_type": "passkey",
        "assertion": {"id": "...", "type": "public-key", "response": {}},
        "challengeToken": "..."
    }

The ``PasskeyAuthenticationService`` reads from
``$this->login['uident']``, detects the ``_type: "passkey"`` marker,
and extracts the assertion and challenge token for verification.

Authentication service
======================

``PasskeyAuthenticationService`` extends TYPO3's
``AbstractAuthenticationService`` and is registered at **priority 80**
(higher than ``SaltedPasswordService`` at 50).

The service implements two methods:

- ``getUser()`` -- Checks if the login data contains a passkey
  payload (JSON with ``_type: "passkey"``). If it does, the user is
  looked up by username. If no passkey data is present, the request
  falls through to the next auth service.

- ``authUser()`` -- Returns:

  - ``200`` -- Authenticated, stop chain (passkey verified)
  - ``100`` -- Not responsible (no passkey data, let next service
    handle it)
  - ``0`` -- Authentication failed

Because TYPO3 authentication services are instantiated by the service
manager (not the DI container), dependencies are obtained via
``GeneralUtility::makeInstance()``.

Public route middleware
=======================

``PublicRouteResolver`` is a PSR-15 middleware that allows passkey
login API endpoints (``/typo3/passkeys/login/*``) to be accessed
without an authenticated backend session. Without it, TYPO3 would
redirect unauthenticated requests to the login page.

Controllers
===========

The extension registers backend routes for three controller groups.
All controllers use the ``JsonBodyTrait`` for parsing JSON request
bodies. Login routes use ``Routes.php`` (public access). Management
and admin routes use ``AjaxRoutes.php`` (AJAX, with Sudo Mode on
write operations). All paths below are relative to ``/typo3/``.

..  card-grid::
    :columns: 1
    :columns-md: 3
    :gap: 4
    :card-height: 100

    ..  card:: LoginController (public)

        Handles the passkey login flow. Routes have
        ``access: public`` (via ``Routes.php``).

        - ``POST /passkeys/login/options``
        - ``POST /passkeys/login/verify``

    ..  card:: ManagementController (AJAX)

        Passkey lifecycle for the current user
        (via ``AjaxRoutes.php``). Write operations
        require Sudo Mode re-authentication.

        - ``POST /ajax/passkeys/manage/registration/options`` *
        - ``POST /ajax/passkeys/manage/registration/verify`` *
        - ``GET /ajax/passkeys/manage/list``
        - ``POST /ajax/passkeys/manage/rename`` *
        - ``POST /ajax/passkeys/manage/remove`` *

    ..  card:: AdminController (AJAX, admin)

        Administrative operations for any user
        (via ``AjaxRoutes.php``). Write operations
        require Sudo Mode re-authentication.

        - ``GET /ajax/passkeys/admin/list``
        - ``POST /ajax/passkeys/admin/remove`` *
        - ``POST /ajax/passkeys/admin/revoke-all`` *
        - ``POST /ajax/passkeys/admin/unlock`` *
        - ``POST /ajax/passkeys/admin/update-enforcement`` *
        - ``POST /ajax/passkeys/admin/send-reminder`` *
        - ``POST /ajax/passkeys/admin/clear-nudge`` *

    ..  card:: AdminModuleController (Backend module)

        Renders the Admin Tools > Passkey Management
        backend module with Dashboard and Help tabs
        (via ``Modules.php``).

    ..  card:: Enforcement status (AJAX)

        Provides enforcement status for the banner.

        - ``GET /ajax/passkeys/enforcement/status``

Routes marked with ``*`` are protected by TYPO3's Sudo Mode. When
accessed without a recent password verification, they return HTTP 422
with ``sudoModeInitialization`` data. The JavaScript handles this
transparently by showing a password dialog and retrying the request.

Service classes
===============

..  card-grid::
    :columns: 1
    :columns-md: 2
    :gap: 4
    :card-height: 100

    ..  card:: WebAuthnService

        Orchestrates WebAuthn ceremonies using
        ``web-auth/webauthn-lib`` v5.x. Handles registration options,
        attestation verification, assertion options, and assertion
        verification.

    ..  card:: ChallengeService

        Generates and verifies HMAC-signed challenge tokens with
        nonce replay protection.

    ..  card:: CredentialRepository

        Database access layer for
        ``tx_nrpasskeysbe_credential``. Uses ``ConnectionPool``
        directly (no Extbase).

    ..  card:: RateLimiterService

        Per-endpoint rate limiting by IP and account lockout after
        configurable failed attempts. Uses TYPO3 caching framework.

    ..  card:: ExtensionConfigurationService

        Reads extension configuration and computes effective values
        for ``rpId`` and ``origin`` (auto-detection from request).

    ..  card:: EnforcementService

        Determines the effective enforcement level for a user by
        resolving their group memberships (strictest level wins,
        shortest grace period wins).

    ..  card:: AdoptionStatsService

        Provides adoption statistics for the admin dashboard:
        overall counts, per-group breakdowns, users without
        passkeys, and grace period status.

Domain model
============

The ``Credential`` class is a plain PHP value object (not Extbase)
with ``fromArray()``/``toArray()`` for database serialization.

Key fields:

- ``credentialId`` -- WebAuthn credential identifier (binary)
- ``publicKeyCose`` -- COSE-encoded public key (binary blob)
- ``signCount`` -- Counter incremented on each use (clone detection)
- ``userHandle`` -- SHA-256 hash of the user UID + encryption key
- ``aaguid`` -- Authenticator Attestation GUID
- ``transports`` -- JSON array of transport hints

Running tests
=============

..  code-block:: bash
    :caption: Available test commands

    # Unit tests
    composer ci:test:php:unit

    # Fuzz tests
    composer ci:test:php:fuzz

    # Functional tests (requires MySQL)
    composer ci:test:php:functional

    # Static analysis (PHPStan level 10)
    composer ci:test:php:phpstan

    # Code style (PER-CS3.0)
    composer ci:test:php:cgl

    # JavaScript unit tests (Vitest)
    npx vitest run

    # E2E tests (Playwright, requires DDEV)
    npx playwright test

    # Mutation testing (Infection, min-MSI 60%, covered-MSI 75%)
    composer ci:mutation

JavaScript modules:

- :file:`PasskeyLogin.js` -- Login form passkey button and WebAuthn flow
- :file:`PasskeyManagement.js` -- User Settings passkey management panel
- :file:`PasskeyBanner.js` -- Encourage-stage onboarding banner
- :file:`PasskeyDashboard.js` -- Admin dashboard enforcement controls
- :file:`PasskeyAdminInfo.js` -- Admin passkey info in user records
