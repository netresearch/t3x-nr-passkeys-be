..  include:: ../Includes.rst.txt

..  _deployment-scenarios:

====================
Deployment Scenarios
====================

Passkeys are bound to a specific domain (the **Relying Party ID**). This
chapter explains how to configure the extension across different
environments and how to handle common deployment patterns.

..  contents:: On this page
   :local:
   :depth: 2

Single environment
==================

The simplest setup: one TYPO3 instance with one domain.

Leave :confval:`rpId` and :confval:`origin` empty (the default). The
extension auto-detects both values from the incoming HTTP request. Each
passkey is registered against the domain it was created on.

This works for:

-  A single production instance (e.g. ``cms.example.com``)
-  A local DDEV site (e.g. ``mysite.ddev.site``)

No additional configuration is needed.

Multi-environment (local / staging / production)
================================================

A typical setup has three environments:

-  **Local development**: ``mysite.ddev.site`` (or ``mysite.local``)
-  **Staging**: ``staging.example.com``
-  **Production**: ``www.example.com``

..  _deployment-rpid-per-environment:

Recommended: separate passkeys per environment
----------------------------------------------

Leave :confval:`rpId` empty on all environments. Each environment
auto-detects its own domain, so passkeys are environment-specific. Users
register a separate passkey on each environment they need access to.

Modern authenticators (iCloud Keychain, Windows Hello, 1Password,
YubiKey) make registering on multiple environments trivial -- it takes
about 10 seconds per environment.

..  tip::

   This is the recommended approach. It avoids sharing secrets across
   environments and keeps each environment fully independent.

..  _deployment-context-config:

Environment-specific configuration
-----------------------------------

Use ``TYPO3_CONTEXT`` to apply different settings per environment:

..  code-block:: php
   :caption: config/system/additional.php

   // Production and Staging: enforce passkey-only login
   if (str_starts_with((string)getenv('TYPO3_CONTEXT'), 'Production')) {
       $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_passkeys_be']['disablePasswordLogin'] = '1';
   }

   // Development: keep password login available for convenience
   // (disablePasswordLogin defaults to '0', no override needed)

This lets you enforce passkey-only login on production while keeping
password login available locally for new users or quick access.

..  important::

   Before enabling :confval:`disablePasswordLogin` on production:

   1. Verify all regular backend users have registered at least one
      passkey.
   2. Ensure at least one admin account has multiple passkeys on different
      authenticators for emergency recovery.
   3. Communicate the change to all backend users in advance.
   4. Consider enabling on staging first to verify the workflow.

..  _deployment-db-sync:

Database synchronisation
------------------------

When syncing the production database to staging or local (a common
workflow), the passkey credential table will contain credentials bound to
the production domain. These credentials will not work on a different
domain.

**Exclude the credential table from database syncs:**

..  code-block:: shell
   :caption: Example: exclude from mysqldump

   mysqldump --ignore-table=mydb.tx_nrpasskeysbe_credential mydb > dump.sql

..  code-block:: yaml
   :caption: Example: exclude in DDEV (via mysql-sync-db custom command)

   ignore_tables:
     - tx_nrpasskeysbe_credential

After importing a production database dump, users simply register fresh
passkeys on the local or staging environment. If
:confval:`disablePasswordLogin` is active but environment-specific (see
above), password login is available on non-production environments for
this initial registration.

..  note::

   You do **not** need to exclude ``be_users`` or any other table. Only
   ``tx_nrpasskeysbe_credential`` is domain-specific. If the credential
   table is accidentally included in a sync, the imported credentials
   will not work on the different domain -- users simply register fresh
   passkeys. No security data is exposed because the public keys are
   useless without the private keys stored on users' authenticators.

Shared rpId across subdomains
=============================

WebAuthn allows the :confval:`rpId` to be set to a registrable domain
suffix. For example, setting ``rpId`` to ``example.com`` allows passkeys
registered on ``staging.example.com`` to also work on
``www.example.com``.

..  warning::

   Sharing passkeys across environments is **not recommended**. It
   requires synchronising:

   -  The **TYPO3** ``encryptionKey``
      (``$GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey']``) -- the
      extension derives user handles from it cryptographically. Different
      keys produce different handles, making credentials unresolvable.
   -  The **credential table** (``tx_nrpasskeysbe_credential``) -- the
      public key material and metadata must be present on both systems.
   -  The **backend user UIDs** (``be_users.uid``) -- user handles are
      derived from the UID.

   Sharing the ``encryptionKey`` between environments creates a
   **cross-environment attack vector**: if a staging environment is
   compromised, the attacker can forge CSRF tokens, session tokens, and
   passkey challenge tokens that are valid on production. Staging
   environments typically have weaker access controls and debug mode
   enabled, making them a more attractive target. The ``encryptionKey``
   must be unique per environment and treated as a production secret.

If you still need shared subdomains (e.g. ``staging`` and ``www``), set
:confval:`rpId` only on those environments and keep local development on
auto-detect:

..  code-block:: php
   :caption: config/system/additional.php

   if (str_starts_with((string)getenv('TYPO3_CONTEXT'), 'Production')) {
       $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_passkeys_be']['rpId'] = 'example.com';
       // Leave 'origin' empty -- it is auto-detected per subdomain.
       // Setting it explicitly would break verification on other subdomains.
   }
   // Development: rpId stays empty -> auto-detect -> mysite.ddev.site

..  important::

   Changing the :confval:`rpId` invalidates all existing passkey
   registrations. Users must register new passkeys after the change.

..  _deployment-onboarding:

User onboarding
===============

Onboarding workflow with ``disablePasswordLogin``
-------------------------------------------------

When :confval:`disablePasswordLogin` is enabled, the extension enforces
passkey-only login **per user**: password login is blocked only for users
who have at least one registered passkey. Users without passkeys can
still log in with a password.

This enables a smooth onboarding workflow:

1. **Admin creates a new backend user** with a password (as usual in
   TYPO3).
2. **User logs in with their password** for the first time.
3. **User registers a passkey** in :guilabel:`User Settings > Passkeys`.
4. **From this point on**, the user must use their passkey -- password
   login is no longer accepted for this account.

..  note::

   An admin **cannot** register a passkey on behalf of another user. The
   WebAuthn ceremony requires physical interaction with the user's own
   authenticator (TouchID, YubiKey, etc.).

Recovery scenarios
------------------

If a user loses access to their authenticator:

1. An admin revokes the user's passkeys via the
   :ref:`Admin API <administration>`. Each revocation is recorded with
   the admin's UID and timestamp for audit purposes.
2. Once all passkeys are revoked, password login becomes available again
   for that user (the per-user enforcement lifts when no active
   credentials remain).
3. The user logs in with their password and registers a new passkey.

..  tip::

   Consider requiring users to register at least two passkeys on
   different authenticators (e.g. laptop + phone) for redundancy.

Containerized and multi-server deployments
==========================================

When running TYPO3 in Docker containers or behind a load balancer,
the file-based cache backends lose state on container restart and are
not shared across servers. This affects nonce replay protection and
rate limiting.

See :ref:`Multi-server cache backends <security-multi-server-caching>`
for Redis configuration, and
:ref:`Reverse proxy and IP detection <security-reverse-proxy>` for
rate limiting behind a load balancer.

Local development with DDEV
===========================

DDEV sites (``*.ddev.site``) use HTTPS by default and are treated as
secure contexts by browsers. Passkeys work out of the box.

..  code-block:: shell

   ddev start
   # Open https://mysite.ddev.site/typo3 -- passkeys work immediately

For ``http://localhost`` (without HTTPS), most browsers also treat this
as a secure context, so passkeys will work. However, custom local
domains over plain HTTP (e.g. ``http://mysite.local``) will **not**
work -- WebAuthn requires a secure context.

See also :ref:`Troubleshooting: HTTPS requirement <troubleshooting>`.

..  seealso::

   :ref:`Security: Production deployment requirements <security-deployment>`
   for trusted hosts pattern, reverse proxy configuration, and
   multi-server cache backends.
