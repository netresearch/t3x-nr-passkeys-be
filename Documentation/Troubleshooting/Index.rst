..  include:: ../Includes.rst.txt

..  _troubleshooting:

===============
Troubleshooting
===============

"Failed to generate options" / encryptionKey too short
======================================================

**Symptoms**

-   The passkey settings panel shows:
    *"Passkey management is unavailable. The TYPO3 encryption key is missing
    or too short."*
-   The management API returns HTTP 500 with
    ``Failed to generate options: TYPO3 encryptionKey is missing or too short
    (min 32 chars).``

**Error codes**

-   ``1700000040`` (WebAuthnService)
-   ``1700000050`` (ChallengeService)

**Cause**

Both HMAC-signed challenge tokens and the WebAuthn credential serialization
depend on ``$GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey']``.
This key must be at least 32 characters long.
Fresh TYPO3 installations that skipped the Install Tool wizard may have
an empty or very short key.

**Fix**

1.  Open **Admin Tools > Settings > Configure Installation-Wide Options**.
2.  Set ``[SYS][encryptionKey]`` to a random string of at least 64 characters.
    The Install Tool offers a "Generate" button for this.
3.  Alternatively, set it in :file:`config/system/settings.php`:

    ..  code-block:: php
        :caption: config/system/settings.php

        return [
            'SYS' => [
                'encryptionKey' => 'your-random-string...',
            ],
        ];


"Passkeys require a secure connection (HTTPS)"
===============================================

**Symptoms**

The login page shows *"Passkeys require a secure connection (HTTPS)."* and
the passkey button is disabled.

**Cause**

The WebAuthn specification requires a `secure context
<https://developer.mozilla.org/en-US/docs/Web/Security/Secure_Contexts>`__.
Browsers block ``navigator.credentials.create()`` and
``navigator.credentials.get()`` on plain HTTP origins.

**Fix**

-   Use HTTPS for your TYPO3 backend.
    In local development ``https://localhost`` or ``https://*.ddev.site``
    satisfies the requirement.
-   ``http://localhost`` is also treated as a secure context by most browsers,
    but other HTTP origins are not.


"Account temporarily locked" or "Too many requests" (HTTP 429)
==============================================================

**Symptoms**

The login screen shows *"Account temporarily locked. Please contact your
administrator."* or *"Too many attempts. Please try again later."* and the
login endpoints return HTTP 429.

**Cause**

After too many failed attempts the account is locked for a configurable
duration (per-IP and per-account counters). Rate limiting additionally throttles
bursts of requests per IP. The "locked" message indicates the account lockout;
"too many attempts" indicates transient rate limiting.

**Fix**

-   Wait for the lockout/rate-limit window to expire (``lockoutDurationSeconds``
    / ``rateLimitWindowSeconds`` in the extension configuration), then retry.
-   An administrator can clear a lockout immediately from the be_users record
    (:guilabel:`Reset login lock`), the admin API, or the CLI:
    ``vendor/bin/typo3 passkeys:recovery --unlock=USERNAME``.


"Authentication was cancelled or no passkey found for this site"
================================================================

**Symptoms**

The browser passkey dialog opens and immediately fails, or never offers the
registered passkey, with a ``NotAllowedError``.

**Cause**

This is usually an **RP ID / origin mismatch**: a passkey is bound to the
Relying Party ID (the registrable domain) it was created for. If the backend is
reached on a different host than when the passkey was registered (e.g. a bare
domain vs ``www``, a different subdomain, or a reverse-proxy host), the browser
will not surface the credential. It can also mean no passkey is registered yet.

**Fix**

-   Ensure the backend is always reached on the same host the passkey was
    registered on. Pin a stable ``rpId``/``origin`` in the extension
    configuration if auto-detection picks the wrong host (see
    :ref:`Security deployment <security-deployment>`).
-   Confirm the user actually has a registered, non-revoked passkey
    (:guilabel:`Admin Tools > Passkey Management`).


"Your browser does not support Passkeys (WebAuthn)"
===================================================

**Symptoms**

The passkey button is disabled and a notice states the browser is unsupported.

**Cause**

The browser does not expose ``window.PublicKeyCredential`` (very old browsers,
or hardened environments where the WebAuthn API is disabled).

**Fix**

Use a current browser (recent Chrome, Edge, Firefox or Safari). Password login
remains available unless an administrator has enforced passwordless login.


Lost or broken authenticator (no access to a passkey)
=====================================================

**Symptoms**

A user can no longer authenticate because their device/security key is lost or
broken, and — under **Enforced** or expired **Required** enforcement — password
login is refused.

**Fix**

See :ref:`Recovery procedures <enforcement>` in the enforcement chapter: an
administrator revokes the lost credential and/or lowers the group enforcement,
or — if all administrators are locked out — recovers from the CLI with
``vendor/bin/typo3 passkeys:recovery``.


Extension log location
======================

The extension logs passkey events (registration, authentication, errors) via
the PSR-3 ``LoggerInterface``.
With the default TYPO3 logging configuration, messages are written to:

..  code-block:: text
    :caption: Default log file location

    var/log/typo3_<hash>.log

If you have configured a custom log file via
``$GLOBALS['TYPO3_CONF_VARS']['LOG']``, check the path set
for the ``Netresearch\NrPasskeysBe`` namespace.

Example custom configuration:

..  code-block:: php
    :caption: Custom logging configuration

    $GLOBALS['TYPO3_CONF_VARS']['LOG']
        ['Netresearch']['NrPasskeysBe']
        ['writerConfiguration'] = [
            \TYPO3\CMS\Core\Log\LogLevel::DEBUG => [
                \TYPO3\CMS\Core\Log\Writer\FileWriter::class
                => [
                    'logFile' =>
                        \TYPO3\CMS\Core\Core\Environment
                            ::getVarPath()
                        . '/log/passkey_auth.log',
                ],
            ],
        ];


Enabling debug mode
===================

To see full stack traces in error responses (development only):

1.  Open **Admin Tools > Settings > Configure Installation-Wide Options**.
2.  Set ``[SYS][displayErrors]`` to ``1``.
3.  Set ``[SYS][devIPmask]`` to your IP address or ``*``.

..  warning::

    Never enable ``displayErrors`` on production systems.
    Detailed error output may expose sensitive configuration details.
