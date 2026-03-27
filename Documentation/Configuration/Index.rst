..  include:: ../Includes.rst.txt

..  _configuration:

=============
Configuration
=============

All settings are managed through the TYPO3 Extension
Configuration module:

:guilabel:`Admin Tools > Settings > Extension Configuration > nr_passkeys_be`

..  figure:: /Images/Configuration/ExtensionSettings.png
    :alt: TYPO3 Extension Configuration screen for
        nr_passkeys_be showing all available settings
    :class: with-border with-shadow
    :zoom: lightbox

    The extension configuration screen with all available
    settings grouped by category.

Relying Party settings
======================

..  confval:: rpId

   :type: string
   :Default: *(auto-detected from HTTP_HOST)*

   The Relying Party identifier. This is typically the domain name of your
   TYPO3 installation (e.g. ``example.com``). If left empty, it is
   auto-detected from the ``HTTP_HOST`` server variable.

   ..  important::

      Once passkeys are registered against a specific ``rpId``, changing it
      will invalidate all existing registrations. Users would need to register
      new passkeys.

..  confval:: rpName

   :type: string
   :Default: ``TYPO3 Backend``

   A human-readable name for the Relying Party. This is displayed to users
   during passkey registration (e.g. in the browser's passkey creation dialog).

..  confval:: origin

   :type: string
   :Default: *(auto-detected)*

   The expected origin for WebAuthn operations (e.g.
   ``https://example.com``). If left empty, it is auto-detected from the
   current request scheme and host.

Challenge settings
==================

..  confval:: challengeTtlSeconds

   :type: int
   :Default: ``120``

   The time-to-live for challenge tokens in seconds. After this period, the
   challenge expires and the user must request a new one. The default of 120
   seconds provides enough time for users to interact with their authenticator.

Discoverable login
==================

..  confval:: discoverableLoginEnabled

   :type: bool
   :Default: ``true``

   Enable discoverable (identifierless) login. When enabled (default), the
   browser can suggest available passkeys without the user entering a username
   first (Conditional UI / Variant B). The user simply clicks a suggested
   passkey from the browser's autofill dropdown.

   When disabled, users must enter their username first, then authenticate
   with their passkey (Variant A: username-first flow).

Password login control
======================

..  confval:: disablePasswordLogin

   :type: bool
   :Default: ``false``

   Enforce passkey-only authentication on a per-user basis. When enabled,
   password login is blocked **only for users who have registered at least
   one passkey**. Users without passkeys can still log in with a password,
   allowing gradual migration without lockouts.

   This enables a smooth onboarding workflow:

   1. Admin creates a new backend user with a password (as usual).
   2. User logs in with password, registers a passkey in User Settings.
   3. From that point on, the user must use their passkey -- password login
      is no longer accepted for that account.

   When this setting is active, users cannot remove their last passkey to
   prevent locking themselves out.

   ..  seealso::

      For more granular per-group enforcement with grace periods, see
      :ref:`enforcement`.

Rate limiting
=============

..  confval:: rateLimitMaxAttempts

   :type: int
   :Default: ``10``

   Maximum number of requests allowed per IP address per endpoint within the
   rate limit window. Exceeding this limit returns HTTP 429 (Too Many
   Requests).

..  confval:: rateLimitWindowSeconds

   :type: int
   :Default: ``300``

   Duration of the rate limiting window in seconds. The attempt counter resets
   after this period.

Account lockout
===============

..  confval:: lockoutThreshold

   :type: int
   :Default: ``5``

   Number of consecutive failed authentication attempts before the account is
   temporarily locked. Applies per username/IP combination.

..  confval:: lockoutUserThreshold

   :type: int
   :Default: ``15``

   Total number of failed authentication attempts across all
   IP addresses before the account is locked. This threshold
   should be higher than :confval:`lockoutThreshold` to catch
   distributed brute force attacks where requests come from
   many different IP addresses.

..  confval:: lockoutDurationSeconds

   :type: int
   :Default: ``900``

   Duration of the account lockout in seconds (default: 15 minutes). After
   this period the lockout expires automatically. Administrators can also
   manually unlock accounts via the admin API.

Cryptographic algorithms
========================

..  confval:: allowedAlgorithms

   :type: string
   :Default: ``ES256``

   Comma-separated list of allowed signing algorithms for passkey
   registration. Supported values:

   - ``ES256`` -- ECDSA with SHA-256 (recommended, widely supported)
   - ``ES384`` -- ECDSA with SHA-384
   - ``ES512`` -- ECDSA with SHA-512
   - ``RS256`` -- RSA with SHA-256

   Example for multiple algorithms: ``ES256,RS256``

User verification
=================

..  confval:: userVerification

   :type: string
   :Default: ``required``

   The user verification requirement for WebAuthn ceremonies. Valid values:

   - ``required`` -- The authenticator must verify the user (e.g. biometric or
     PIN). This is the most secure option.
   - ``preferred`` -- The authenticator should verify the user if possible, but
     authentication proceeds even without verification.
   - ``discouraged`` -- The authenticator should not verify the user. Use this
     only if you want the fastest possible authentication.

   Invalid values fall back to ``required``.
