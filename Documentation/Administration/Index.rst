..  include:: ../Includes.rst.txt

..  _administration:

==============
Administration
==============

This chapter covers administrator-specific functionality for managing passkeys
across all backend users.

Admin API endpoints
===================

The extension provides admin-only AJAX endpoints for credential and account
management. All admin endpoints require the requesting user to have TYPO3
admin privileges. Write operations are protected by **Sudo Mode** (password
re-verification with a 15-minute grant lifetime).

List user credentials
---------------------

..  code-block:: text

   GET /typo3/ajax/passkeys/admin/list?beUserUid=<uid>

Returns all credentials (including revoked ones) for a specific backend user.

Response fields per credential:

- ``uid`` -- Credential record UID
- ``label`` -- User-assigned label
- ``createdAt`` -- Unix timestamp of registration
- ``lastUsedAt`` -- Unix timestamp of last successful login
- ``isRevoked`` -- Whether the credential has been revoked
- ``revokedAt`` -- Unix timestamp of revocation (0 if not revoked)
- ``revokedBy`` -- UID of the admin who revoked the credential

Revoke a credential
-------------------

..  code-block:: text

   POST /typo3/ajax/passkeys/admin/remove
   Content-Type: application/json

   {"beUserUid": 123, "credentialUid": 456}

Revokes a specific passkey for a backend user. The credential is not deleted
but marked as revoked with a timestamp and the revoking admin's UID. Revoked
credentials cannot be used for authentication.

This endpoint requires Sudo Mode verification (HTTP 422 if not verified).

Unlock a locked account
-----------------------

..  code-block:: text

   POST /typo3/ajax/passkeys/admin/unlock
   Content-Type: application/json

   {"beUserUid": 123, "username": "johndoe"}

Resets the lockout counter for a specific backend user. Use this when a user
has been locked out due to too many failed authentication attempts and cannot
wait for the lockout to expire automatically.

This endpoint requires Sudo Mode verification (HTTP 422 if not verified).

Credential lifecycle
====================

Passkeys go through the following states:

1. **Registered** -- The credential is created via the management API and
   stored in the ``tx_nrpasskeysbe_credential`` table.
2. **Active** -- The credential is used for successful logins. The
   ``last_used_at`` and ``sign_count`` fields are updated on each use.
3. **Revoked** -- An administrator revokes the credential via the admin API.
   The ``revoked_at`` timestamp and ``revoked_by`` admin UID are recorded.
   Revoked credentials remain in the database but are rejected during
   authentication.
4. **Deleted** -- A user removes their own credential via the management API.
   The record is soft-deleted (``deleted = 1``).

Database table
==============

The extension uses a single table ``tx_nrpasskeysbe_credential`` with the
following schema:

=============================  =============  ======================================
Column                         Type           Description
=============================  =============  ======================================
``uid``                        int            Primary key (auto-increment)
``be_user``                    int            FK to ``be_users.uid``
``credential_id``              varbinary      WebAuthn credential ID (unique)
``public_key_cose``            blob           COSE-encoded public key
``sign_count``                 int            Signature counter (replay detection)
``user_handle``                varbinary      WebAuthn user handle (SHA-256 hash)
``aaguid``                     char(36)       Authenticator attestation GUID
``transports``                 text           JSON array of transport hints
``label``                      varchar(128)   User-assigned label
``created_at``                 int            Unix timestamp of creation
``last_used_at``               int            Unix timestamp of last use
``revoked_at``                 int            Unix timestamp of revocation (0=active)
``revoked_by``                 int            UID of revoking admin (0=not revoked)
``deleted``                    tinyint        Soft delete flag
=============================  =============  ======================================

Monitoring
==========

The extension logs all significant events using the PSR-3 logging interface:

- Successful passkey registrations
- Successful passkey logins
- Failed authentication attempts (with hashed username and IP)
- Admin credential revocations
- Admin account unlocks
- Rate limit and lockout triggers

Configure TYPO3 logging writers to capture these events. Example for file
logging:

..  code-block:: php

   $GLOBALS['TYPO3_CONF_VARS']['LOG']['Netresearch']['NrPasskeysBe']['writerConfiguration'] = [
       \Psr\Log\LogLevel::INFO => [
           \TYPO3\CMS\Core\Log\Writer\FileWriter::class => [
               'logFileInfix' => 'passkeys',
           ],
       ],
   ];
