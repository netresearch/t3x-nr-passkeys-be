..  include:: ../Includes.rst.txt

..  _developer-controllers-services:

========================
Controllers and services
========================

Controllers
===========

The extension registers backend routes for three controller
groups. All controllers use the ``JsonBodyTrait`` for parsing
JSON request bodies. Login routes use :file:`Routes.php`
(public access). Management and admin routes use
:file:`AjaxRoutes.php` (AJAX, with Sudo Mode on write
operations). All paths below are relative to ``/typo3/``.

..  card-grid::
    :columns: 1
    :columns-md: 3
    :gap: 4
    :card-height: 100

    ..  card:: LoginController (public)

        Handles the passkey login flow. Routes have
        ``access: public`` (via :file:`Routes.php`).

        -  ``POST /passkeys/login/options``
        -  ``POST /passkeys/login/verify``

    ..  card:: ManagementController (AJAX)

        Passkey lifecycle for the current user
        (via :file:`AjaxRoutes.php`). Write operations
        require Sudo Mode re-authentication.

        -  ``POST /ajax/passkeys/manage/registration/options``
           \*
        -  ``POST /ajax/passkeys/manage/registration/verify``
           \*
        -  ``GET /ajax/passkeys/manage/list``
        -  ``POST /ajax/passkeys/manage/rename`` \*
        -  ``POST /ajax/passkeys/manage/remove`` \*

    ..  card:: AdminController (AJAX, admin)

        Administrative operations for any user
        (via :file:`AjaxRoutes.php`). Write operations
        require Sudo Mode re-authentication.

        -  ``GET /ajax/passkeys/admin/list``
        -  ``POST /ajax/passkeys/admin/remove`` \*
        -  ``POST /ajax/passkeys/admin/revoke-all`` \*
        -  ``POST /ajax/passkeys/admin/unlock`` \*
        -  ``POST /ajax/passkeys/admin/update-enforcement``
           \*
        -  ``POST /ajax/passkeys/admin/send-reminder`` \*
        -  ``POST /ajax/passkeys/admin/clear-nudge`` \*

    ..  card:: AdminModuleController (Backend module)

        Renders the Admin Tools > Passkey Management
        backend module with Dashboard and Help tabs
        (via :file:`Modules.php`).

    ..  card:: Enforcement status (AJAX)

        Provides enforcement status for the banner.

        -  ``GET /ajax/passkeys/enforcement/status``

Routes marked with ``*`` are protected by TYPO3's Sudo Mode.
When accessed without a recent password verification, they
return HTTP 422 with ``sudoModeInitialization`` data. The
JavaScript handles this transparently by showing a password
dialog and retrying the request.

Service classes
===============

..  card-grid::
    :columns: 1
    :columns-md: 2
    :gap: 4
    :card-height: 100

    ..  card:: WebAuthnService

        Orchestrates WebAuthn ceremonies using
        ``web-auth/webauthn-lib`` v5.x. Handles registration
        options, attestation verification, assertion options,
        and assertion verification.

    ..  card:: ChallengeService

        Generates and verifies HMAC-signed challenge tokens
        with nonce replay protection.

    ..  card:: CredentialRepository

        Database access layer for
        ``tx_nrpasskeysbe_credential``. Uses
        ``ConnectionPool`` directly (no Extbase).

    ..  card:: RateLimiterService

        Per-endpoint rate limiting by IP and account lockout
        after configurable failed attempts. Uses TYPO3
        caching framework.

    ..  card:: ExtensionConfigurationService

        Reads extension configuration and computes effective
        values for ``rpId`` and ``origin`` (auto-detection
        from request).

    ..  card:: EnforcementService

        Determines the effective enforcement level for a
        user by resolving their group memberships (strictest
        level wins, shortest grace period wins).

    ..  card:: AdoptionStatsService

        Provides adoption statistics for the admin dashboard:
        overall counts, per-group breakdowns, users without
        passkeys, and grace period status.

JavaScript modules
==================

-  :file:`PasskeyLogin.js` -- Login form passkey button and
   WebAuthn flow
-  :file:`PasskeyManagement.js` -- User Settings passkey
   management panel
-  :file:`PasskeyBanner.js` -- Encourage-stage onboarding
   banner
-  :file:`PasskeyDashboard.js` -- Admin dashboard enforcement
   controls
-  :file:`PasskeyAdminInfo.js` -- Admin passkey info in user
   records
