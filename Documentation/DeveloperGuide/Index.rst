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
      Controller/         REST API controllers (Login, Manage, Admin)
      Domain/Model/       Credential entity
      EventListener/      PSR-14 listener (login form injection)
      Middleware/          PSR-15 middleware (public route resolver)
      Service/            Business logic services

Login form injection
====================

The passkey button is injected into the standard TYPO3 login form via
the ``InjectPasskeyLoginFields`` PSR-14 event listener. It listens to
``ModifyPageLayoutOnLoginProviderSelectionEvent`` and:

- Adds :file:`PasskeyLogin.js` via ``PageRenderer::addJsFile()``
- Injects an inline script with ``window.NrPasskeysBeConfig`` that
  provides ``loginOptionsUrl``, ``rpId``, ``origin``, and
  ``discoverableEnabled`` to the JavaScript

The JavaScript builds the passkey UI (button, error area, hidden
fields) dynamically via DOM manipulation and inserts it into
``#typo3-login-form``. No Fluid partial or separate template is
needed.

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
bodies.

..  card-grid::
    :columns: 1
    :columns-md: 3
    :gap: 4
    :card-height: 100

    ..  card:: LoginController (public)

        Handles the passkey login flow. Routes have
        ``access: public``.

        - ``POST /passkeys/login/options``
        - ``POST /passkeys/login/verify``

    ..  card:: ManagementController

        Passkey lifecycle for the current user.

        - ``POST .../registration/options``
        - ``POST .../registration/verify``
        - ``GET /passkeys/manage/list``
        - ``POST /passkeys/manage/rename``
        - ``POST /passkeys/manage/remove``

    ..  card:: AdminController (admin)

        Administrative operations for any user.

        - ``GET /passkeys/admin/list``
        - ``POST /passkeys/admin/remove``
        - ``POST /passkeys/admin/unlock``

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

    # Unit tests (263 tests)
    composer ci:test:php:unit

    # Static analysis (PHPStan level 8)
    composer ci:stan

    # Code style (PER-CS2.0)
    composer ci:lint:php

    # JavaScript unit tests (Vitest)
    npx vitest run

    # E2E tests (Playwright)
    npx playwright test

    # Mutation testing
    composer ci:mutation
