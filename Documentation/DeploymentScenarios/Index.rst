..  include:: ../Includes.rst.txt

..  _deployment-scenarios:

====================
Deployment scenarios
====================

Passkeys are bound to a specific domain (the **Relying Party
ID**). This chapter explains how to configure the extension
across different environments and how to handle common
deployment patterns.

..  toctree::
    :maxdepth: 1
    :hidden:

    Onboarding

..  contents:: On this page
    :local:
    :depth: 2

Single environment
==================

The simplest setup: one TYPO3 instance with one domain.

Leave :confval:`rpId` and :confval:`origin` empty (the
default). The extension auto-detects both values from the
incoming HTTP request. Each passkey is registered against
the domain it was created on.

This works for:

-  A single production instance (e.g. ``cms.example.com``)
-  A local DDEV site (e.g. ``mysite.ddev.site``)

No additional configuration is needed.

Multi-environment (local / staging / production)
================================================

A typical setup has three environments:

-  **Local development**: ``mysite.ddev.site``
   (or ``mysite.local``)
-  **Staging**: ``staging.example.com``
-  **Production**: ``www.example.com``

..  _deployment-rpid-per-environment:

Recommended: separate passkeys per environment
----------------------------------------------

Leave :confval:`rpId` empty on all environments. Each
environment auto-detects its own domain, so passkeys are
environment-specific. Users register a separate passkey on
each environment they need access to.

Modern authenticators (iCloud Keychain, Windows Hello,
1Password, YubiKey) make registering on multiple
environments trivial -- it takes about 10 seconds per
environment.

..  tip::

    This is the recommended approach. It avoids sharing
    secrets across environments and keeps each environment
    fully independent.

..  _deployment-context-config:

Environment-specific configuration
-----------------------------------

Use ``TYPO3_CONTEXT`` to apply different settings per
environment:

..  code-block:: php
    :caption: config/system/additional.php

    // Production and Staging: enforce passkey-only login
    if (str_starts_with(
        (string)getenv('TYPO3_CONTEXT'),
        'Production'
    )) {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']
            ['nr_passkeys_be']['disablePasswordLogin']
            = '1';
    }

    // Development: keep password login available
    // (disablePasswordLogin defaults to '0')

Because enforcement is **per user** (only users with
registered passkeys are affected), enabling
:confval:`disablePasswordLogin` on production is safe even
if not all users have passkeys yet -- they can still log in
with a password. Keeping the setting disabled on development
means all users (including those with passkeys) can use
password login for convenience.

..  tip::

    Consider enabling on staging first to verify the
    workflow, and communicate the change to backend users
    who already have passkeys -- they will no longer be
    able to fall back to password login.

..  _deployment-db-sync:

Database synchronisation
------------------------

When syncing the production database to staging or local (a
common workflow), the passkey credential table will contain
credentials bound to the production domain. These
credentials will not work on a different domain.

**Exclude the credential table from database syncs:**

..  code-block:: shell
    :caption: Example: exclude from mysqldump

    mysqldump \
        --ignore-table=mydb.tx_nrpasskeysbe_credential \
        mydb > dump.sql

..  code-block:: yaml
    :caption: Example: exclude in DDEV

    ignore_tables:
      - tx_nrpasskeysbe_credential

After importing a production database dump, users simply
register fresh passkeys on the local or staging environment.
Because enforcement is per user, users with no credentials
in the table can log in with a password regardless of the
:confval:`disablePasswordLogin` setting.

..  important::

    **Exclude the credential table from syncs** when
    :confval:`disablePasswordLogin` is enabled. The per-user
    enforcement counts all non-deleted, non-revoked
    credentials regardless of which domain they were
    registered on (see `ADR-0002
    <https://github.com/netresearch/t3x-nr-passkeys-be/blob/main/docs/adr/0002-no-rpid-aware-enforcement.md>`__).
    If production credentials are imported into a different
    environment, users appear to have passkeys (blocking
    password login) even though those passkeys do not work
    on the new domain.

..  note::

    You do **not** need to exclude ``be_users`` or any
    other table. Only ``tx_nrpasskeysbe_credential`` is
    domain-specific. No security data is exposed by an
    accidental sync because the public keys are useless
    without the private keys stored on users'
    authenticators.

Shared rpId across subdomains
=============================

WebAuthn allows the :confval:`rpId` to be set to a
registrable domain suffix. For example, setting ``rpId``
to ``example.com`` allows passkeys registered on
``staging.example.com`` to also work on
``www.example.com``.

..  warning::

    Sharing passkeys across environments is **not
    recommended**. It requires synchronising:

    -  The **TYPO3** ``encryptionKey``
       (``$GLOBALS['TYPO3_CONF_VARS']['SYS']``
       ``['encryptionKey']``) -- the extension derives user
       handles from it cryptographically. Different keys
       produce different handles, making credentials
       unresolvable.
    -  The **credential table**
       (``tx_nrpasskeysbe_credential``) -- the public key
       material and metadata must be present on both
       systems.
    -  The **backend user UIDs** (``be_users.uid``) -- user
       handles are derived from the UID.

    Sharing the ``encryptionKey`` between environments
    creates a **cross-environment attack vector**: if a
    staging environment is compromised, the attacker can
    forge CSRF tokens, session tokens, and passkey challenge
    tokens that are valid on production. Staging
    environments typically have weaker access controls and
    debug mode enabled, making them a more attractive
    target. The ``encryptionKey`` must be unique per
    environment and treated as a production secret.

If you still need shared subdomains (e.g. ``staging`` and
``www``), set :confval:`rpId` only on those environments
and keep local development on auto-detect:

..  code-block:: php
    :caption: config/system/additional.php

    if (str_starts_with(
        (string)getenv('TYPO3_CONTEXT'),
        'Production'
    )) {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']
            ['nr_passkeys_be']['rpId'] = 'example.com';
        // Leave 'origin' empty -- auto-detected per
        // subdomain. Setting it explicitly would break
        // verification on other subdomains.
    }
    // Development: rpId stays empty
    // -> auto-detect -> mysite.ddev.site

..  important::

    Changing the :confval:`rpId` invalidates all existing
    passkey registrations. Users must register new passkeys
    after the change.
