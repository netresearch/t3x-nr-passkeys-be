..  include:: ../Includes.rst.txt

..  _administration:

==============
Administration
==============

This chapter covers administrator-specific functionality for
managing passkeys across all backend users.

..  figure:: /Images/Administration/AdminDashboard.png
    :alt: Admin dashboard overview showing adoption statistics
        and user list
    :class: with-border with-shadow
    :zoom: lightbox

    The Passkey Management module provides adoption statistics
    and per-group enforcement controls.

..  toctree::
    :maxdepth: 1
    :hidden:

    Enforcement
    Database

Passkey enforcement
===================

The extension supports per-group enforcement of passkeys with
configurable grace periods. Administrators can gradually roll
out passkeys from gentle encouragement to mandatory adoption.
See :ref:`enforcement` for the complete guide covering
enforcement levels, grace periods, the admin dashboard, and
recovery procedures.

Admin API endpoints
===================

The extension provides admin-only AJAX endpoints for
credential and account management. All admin endpoints
require the requesting user to have TYPO3 admin privileges.
Write operations are protected by **Sudo Mode** (password
re-verification with a 15-minute grant lifetime).

List user credentials
---------------------

..  code-block:: text
    :caption: List credentials for a backend user

    GET /typo3/ajax/passkeys/admin/list?beUserUid=<uid>

Returns all credentials (including revoked ones) for a
specific backend user.

Response fields per credential:

-  ``uid`` -- Credential record UID
-  ``label`` -- User-assigned label
-  ``createdAt`` -- Unix timestamp of registration
-  ``lastUsedAt`` -- Unix timestamp of last successful login
-  ``isRevoked`` -- Whether the credential has been revoked
-  ``revokedAt`` -- Unix timestamp of revocation (0 if not
   revoked)
-  ``revokedBy`` -- UID of the admin who revoked the
   credential

Revoke a credential
-------------------

..  code-block:: text
    :caption: Revoke a specific credential

    POST /typo3/ajax/passkeys/admin/remove
    Content-Type: application/json

    {"beUserUid": 123, "credentialUid": 456}

Revokes a specific passkey for a backend user. The credential
is not deleted but marked as revoked with a timestamp and the
revoking admin's UID. Revoked credentials cannot be used for
authentication.

This endpoint requires Sudo Mode verification (HTTP 422 if
not verified).

Unlock a locked account
-----------------------

..  code-block:: text
    :caption: Unlock a locked-out user account

    POST /typo3/ajax/passkeys/admin/unlock
    Content-Type: application/json

    {"beUserUid": 123, "username": "johndoe"}

Resets the lockout counter for a specific backend user. Use
this when a user has been locked out due to too many failed
authentication attempts and cannot wait for the lockout to
expire automatically.

This endpoint requires Sudo Mode verification (HTTP 422 if
not verified).

Revoke all credentials
----------------------

..  code-block:: text
    :caption: Revoke all passkeys for a user

    POST /typo3/ajax/passkeys/admin/revoke-all
    Content-Type: application/json

    {"beUserUid": 123}

Revokes all passkeys for a backend user at once. Useful for
device loss or account recovery scenarios.

This endpoint requires Sudo Mode verification (HTTP 422 if
not verified).

..  versionadded:: 0.6.0

Update group enforcement
------------------------

..  code-block:: text
    :caption: Change enforcement level for a group

    POST /typo3/ajax/passkeys/admin/update-enforcement
    Content-Type: application/json

    {"groupUid": 1, "enforcement": "encourage"}

Changes the passkey enforcement level for a backend user
group. Valid levels: ``off``, ``encourage``, ``required``,
``enforced``.

This endpoint requires Sudo Mode verification (HTTP 422 if
not verified).

..  versionadded:: 0.6.0

Send passkey setup reminder
----------------------------

..  code-block:: text
    :caption: Set a nudge flag for a user

    POST /typo3/ajax/passkeys/admin/send-reminder
    Content-Type: application/json

    {"beUserUid": 123}

Sets a nudge flag for a user, causing the encourage-stage
banner to reappear even if previously dismissed.

This endpoint requires Sudo Mode verification (HTTP 422 if
not verified).

..  versionadded:: 0.6.0

Clear nudge
-----------

..  code-block:: text
    :caption: Remove an active nudge flag

    POST /typo3/ajax/passkeys/admin/clear-nudge
    Content-Type: application/json

    {"beUserUid": 123}

Removes the active nudge flag for a user.

This endpoint requires Sudo Mode verification (HTTP 422 if
not verified).

..  versionadded:: 0.6.0

..  seealso::

    :ref:`Database and monitoring
    <administration-database>` for credential lifecycle,
    table schema, and logging configuration.
