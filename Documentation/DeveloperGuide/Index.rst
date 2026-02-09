.. include:: ../Includes.rst.txt

===============
Developer Guide
===============

This chapter describes the extension's architecture and provides guidance for
developers who want to understand, debug, or extend the extension.

Architecture overview
=====================

The extension consists of these core components:

.. code-block:: text

   Classes/
     Authentication/     Authentication service (TYPO3 auth chain)
     Configuration/      Extension configuration value object
     Controller/         REST API controllers (Login, Management, Admin)
     Domain/Model/       Credential entity
     LoginProvider/      TYPO3 login provider (login page UI)
     Service/            Business logic services

Authentication service
======================

``PasskeyAuthenticationService`` extends TYPO3's ``AbstractAuthenticationService``
and is registered at **priority 80** (higher than ``SaltedPasswordService`` at
50). This means the passkey service is consulted first during authentication.

The service implements two methods:

- ``getUser()`` -- Checks if the login request contains passkey assertion data.
  If it does, the user is looked up by username. If no passkey data is present,
  the request falls through to the next auth service (password login), unless
  password login is disabled.

- ``authUser()`` -- Returns:

  - ``200`` -- Authenticated, stop chain (passkey verified successfully)
  - ``100`` -- Not responsible (no passkey data in request, let next service
    handle it)
  - ``0`` -- Authentication failed

Because TYPO3 authentication services are instantiated by the service manager
(not the DI container), dependencies are obtained via
``GeneralUtility::makeInstance()`` rather than constructor injection.

Controllers
===========

The extension registers backend routes for three controller groups. All
controllers use the ``JsonBodyTrait`` for parsing JSON request bodies.

LoginController (public)
------------------------

Handles the passkey login flow. Routes have ``access: public`` because they
are called before authentication.

- ``POST /passkeys/login/options`` -- Generates assertion options for a given
  username. Returns WebAuthn ``PublicKeyCredentialRequestOptions`` and an
  HMAC-signed challenge token.
- ``POST /passkeys/login/verify`` -- Optional AJAX verification endpoint.
  The primary authentication happens through TYPO3's standard login form
  submission via the authentication service.

ManagementController (authenticated)
------------------------------------

Handles passkey lifecycle operations for the currently authenticated user.

- ``POST /passkeys/manage/registration/options`` -- Generates registration
  options (``PublicKeyCredentialCreationOptions``).
- ``POST /passkeys/manage/registration/verify`` -- Verifies the browser's
  attestation response and stores the new credential.
- ``GET /passkeys/manage/list`` -- Lists the user's active credentials.
- ``POST /passkeys/manage/rename`` -- Renames a credential label.
- ``POST /passkeys/manage/remove`` -- Soft-deletes a credential.

AdminController (admin-only)
----------------------------

Provides administrative operations. All actions require the current user to be
a TYPO3 admin.

- ``GET /passkeys/admin/list`` -- Lists all credentials (including revoked)
  for any backend user.
- ``POST /passkeys/admin/remove`` -- Revokes a credential with audit trail.
- ``POST /passkeys/admin/unlock`` -- Resets lockout for a locked user.

Service classes
===============

WebAuthnService
---------------

Central service that orchestrates WebAuthn ceremonies using the
``web-auth/webauthn-lib`` v5.x library. Responsibilities:

- Create registration options (``PublicKeyCredentialCreationOptions``)
- Verify attestation responses and store credentials
- Create assertion options (``PublicKeyCredentialRequestOptions``)
- Verify assertion responses for login
- Serialize WebAuthn objects to JSON for the browser
- Manage the ``CeremonyStepManagerFactory`` with configured algorithms and
  allowed origins

ChallengeService
----------------

Generates and verifies HMAC-signed challenge tokens. Each token encodes:

- The 32-byte random challenge (base64)
- An expiration timestamp
- A single-use nonce

The token is signed with HMAC-SHA256 using the TYPO3 encryption key.
Verification includes HMAC validation, expiration check, and nonce
consumption (replay protection via the nonce cache).

CredentialRepository
--------------------

Database access layer for the ``tx_nrpasskeysbe_credential`` table. Uses
TYPO3's ``ConnectionPool`` and ``QueryBuilder`` directly (no Extbase
persistence).

RateLimiterService
------------------

Provides two protection mechanisms:

- **Per-endpoint rate limiting** -- Limits requests per IP per endpoint within
  a configurable time window.
- **Account lockout** -- Locks accounts after a configurable number of failed
  authentication attempts per username/IP combination.

Uses TYPO3's caching framework (``FileBackend`` for ``flushByTag`` support).
Lockout entries are tagged for per-user flush during admin unlock.

ExtensionConfigurationService
-----------------------------

Reads and provides the extension configuration from TYPO3's
``ExtensionConfiguration`` API. Also computes effective values for ``rpId``
and ``origin`` when they are not explicitly configured (auto-detection from
the current request).

Domain model
============

The ``Credential`` class is a plain PHP value object (not an Extbase model)
with ``fromArray()``/``toArray()`` for database serialization. Key fields:

- ``credentialId`` -- The WebAuthn credential identifier (binary)
- ``publicKeyCose`` -- The COSE-encoded public key (binary blob)
- ``signCount`` -- Counter incremented on each use (clone detection)
- ``userHandle`` -- SHA-256 hash of the user UID + encryption key
- ``aaguid`` -- Authenticator Attestation GUID
- ``transports`` -- JSON array of transport hints (e.g. ``["usb", "nfc"]``)

Login provider
==============

``PasskeyLoginProvider`` implements TYPO3's ``LoginProviderInterface``. It:

- Registers as a login provider tab on the TYPO3 backend login page
- Provides both ``render()`` (TYPO3 v13) and ``modifyView()`` (TYPO3 v14)
  methods for cross-version compatibility
- Loads the ``PasskeyLogin.js`` script and assigns Fluid template variables

Running tests
=============

The extension includes unit tests, functional tests, and fuzz tests.

.. code-block:: bash

   # Unit tests
   composer ci:test:php:unit

   # Functional tests (requires MySQL)
   composer ci:test:php:functional

   # Static analysis
   composer ci:stan

   # Code style check
   composer ci:lint:php

   # Mutation testing
   composer ci:mutation
