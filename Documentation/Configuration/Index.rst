.. include:: ../Includes.rst.txt

=============
Configuration
=============

All settings are managed through the TYPO3 Extension Configuration module:

:guilabel:`Admin Tools > Settings > Extension Configuration > nr_passkeys_be`

Relying Party settings
======================

.. confval:: rpId

   :type: string
   :Default: *(auto-detected from HTTP_HOST)*

   The Relying Party identifier. This is typically the domain name of your
   TYPO3 installation (e.g. ``example.com``). If left empty, it is
   auto-detected from the ``HTTP_HOST`` server variable.

   .. important::

      Once passkeys are registered against a specific ``rpId``, changing it
      will invalidate all existing registrations. Users would need to register
      new passkeys.

.. confval:: rpName

   :type: string
   :Default: ``TYPO3 Backend``

   A human-readable name for the Relying Party. This is displayed to users
   during passkey registration (e.g. in the browser's passkey creation dialog).

.. confval:: origin

   :type: string
   :Default: *(auto-detected)*

   The expected origin for WebAuthn operations (e.g.
   ``https://example.com``). If left empty, it is auto-detected from the
   current request scheme and host.

Challenge settings
==================

.. confval:: challengeTtlSeconds

   :type: int
   :Default: ``120``

   The time-to-live for challenge tokens in seconds. After this period, the
   challenge expires and the user must request a new one. The default of 120
   seconds provides enough time for users to interact with their authenticator.

Discoverable login
==================

.. confval:: discoverableLoginEnabled

   :type: bool
   :Default: ``false``

   Enable discoverable (identifierless) login. When enabled, the browser can
   suggest available passkeys without the user entering a username first
   (Conditional UI / Variant B). The user simply clicks a suggested passkey
   from the browser's autofill dropdown.

   When disabled (default), users must enter their username first, then
   authenticate with their passkey (Variant A: username-first flow).

Password login control
======================

.. confval:: disablePasswordLogin

   :type: bool
   :Default: ``false``

   Disable traditional password login entirely. When enabled, only passkey
   authentication is accepted. Non-passkey login attempts are blocked.

   .. warning::

      Enabling this setting locks out any backend user who has not yet
      registered a passkey. Ensure all users have at least one registered
      passkey before enabling this option.

   When this setting is active, users cannot remove their last passkey to
   prevent locking themselves out.

Rate limiting
=============

.. confval:: rateLimitMaxAttempts

   :type: int
   :Default: ``10``

   Maximum number of requests allowed per IP address per endpoint within the
   rate limit window. Exceeding this limit returns HTTP 429 (Too Many
   Requests).

.. confval:: rateLimitWindowSeconds

   :type: int
   :Default: ``300``

   Duration of the rate limiting window in seconds. The attempt counter resets
   after this period.

Account lockout
===============

.. confval:: lockoutThreshold

   :type: int
   :Default: ``5``

   Number of consecutive failed authentication attempts before the account is
   temporarily locked. Applies per username/IP combination.

.. confval:: lockoutDurationSeconds

   :type: int
   :Default: ``900``

   Duration of the account lockout in seconds (default: 15 minutes). After
   this period the lockout expires automatically. Administrators can also
   manually unlock accounts via the admin API.

Cryptographic algorithms
========================

.. confval:: allowedAlgorithms

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

.. confval:: userVerification

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
