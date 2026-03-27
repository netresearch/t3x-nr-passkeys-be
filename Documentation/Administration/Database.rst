..  include:: ../Includes.rst.txt

..  _administration-database:

============================
Database and monitoring
============================

Credential lifecycle
====================

Passkeys go through the following states:

1. **Registered** -- The credential is created via the
   management API and stored in the
   ``tx_nrpasskeysbe_credential`` table.
2. **Active** -- The credential is used for successful
   logins. The ``last_used_at`` and ``sign_count`` fields
   are updated on each use.
3. **Revoked** -- An administrator revokes the credential
   via the admin API. The ``revoked_at`` timestamp and
   ``revoked_by`` admin UID are recorded. Revoked
   credentials remain in the database but are rejected
   during authentication.
4. **Deleted** -- A user removes their own credential via
   the management API. The record is soft-deleted
   (``deleted = 1``).

Database table
==============

The extension uses a single table
``tx_nrpasskeysbe_credential`` with the following schema:

..  list-table:: Credential table schema
    :header-rows: 1
    :widths: 25 15 60

    *   - Column
        - Type
        - Description

    *   - ``uid``
        - int
        - Primary key (auto-increment)

    *   - ``be_user``
        - int
        - FK to ``be_users.uid``

    *   - ``credential_id``
        - varbinary
        - WebAuthn credential ID (unique)

    *   - ``public_key_cose``
        - blob
        - COSE-encoded public key

    *   - ``sign_count``
        - int
        - Signature counter (replay detection)

    *   - ``user_handle``
        - varbinary
        - WebAuthn user handle (SHA-256 hash)

    *   - ``aaguid``
        - char(36)
        - Authenticator attestation GUID

    *   - ``transports``
        - text
        - JSON array of transport hints

    *   - ``label``
        - varchar(128)
        - User-assigned label

    *   - ``created_at``
        - int
        - Unix timestamp of creation

    *   - ``last_used_at``
        - int
        - Unix timestamp of last use

    *   - ``revoked_at``
        - int
        - Unix timestamp of revocation (0=active)

    *   - ``revoked_by``
        - int
        - UID of revoking admin (0=not revoked)

    *   - ``deleted``
        - tinyint
        - Soft delete flag

Monitoring
==========

The extension logs all significant events using the PSR-3
logging interface:

-  Successful passkey registrations
-  Successful passkey logins
-  Failed authentication attempts (with hashed username
   and IP)
-  Admin credential revocations
-  Admin account unlocks
-  Rate limit and lockout triggers

Configure TYPO3 logging writers to capture these events.
Example for file logging:

..  code-block:: php
    :caption: Logging configuration for passkey events

    $GLOBALS['TYPO3_CONF_VARS']['LOG']
        ['Netresearch']['NrPasskeysBe']
        ['writerConfiguration'] = [
            \Psr\Log\LogLevel::INFO => [
                \TYPO3\CMS\Core\Log\Writer\FileWriter::class
                => [
                    'logFileInfix' => 'passkeys',
                ],
            ],
        ];
