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

        return [
            'SYS' => [
                'encryptionKey' => 'your-random-string-at-least-64-chars...',
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


Extension log location
======================

The extension logs passkey events (registration, authentication, errors) via
the PSR-3 ``LoggerInterface``.
With the default TYPO3 logging configuration, messages are written to:

..  code-block:: text

    var/log/typo3_<hash>.log

If you have configured a custom log file via ``$GLOBALS['TYPO3_CONF_VARS']['LOG']``,
check the path set for the ``Netresearch\NrPasskeysBe`` namespace.

Example custom configuration:

..  code-block:: php

    $GLOBALS['TYPO3_CONF_VARS']['LOG']['Netresearch']['NrPasskeysBe']['writerConfiguration'] = [
        \TYPO3\CMS\Core\Log\LogLevel::DEBUG => [
            \TYPO3\CMS\Core\Log\Writer\FileWriter::class => [
                'logFile' => \TYPO3\CMS\Core\Core\Environment::getVarPath() . '/log/passkey_auth.log',
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
