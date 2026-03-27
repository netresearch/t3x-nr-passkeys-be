..  include:: ../Includes.rst.txt

..  _deployment-onboarding:

================
User onboarding
================

Onboarding workflow with disablePasswordLogin
==============================================

When :confval:`disablePasswordLogin` is enabled, the
extension enforces passkey-only login **per user**: password
login is blocked only for users who have at least one
registered passkey. Users without passkeys can still log in
with a password.

This enables a smooth onboarding workflow:

1. **Admin creates a new backend user** with a password
   (as usual in TYPO3).
2. **User logs in with their password** for the first time.
3. **User registers a passkey** in
   :guilabel:`User Settings > Passkeys`.
4. **From this point on**, the user must use their passkey
   -- password login is no longer accepted for this
   account.

..  note::

    An admin **cannot** register a passkey on behalf of
    another user. The WebAuthn ceremony requires physical
    interaction with the user's own authenticator (TouchID,
    YubiKey, etc.).

Recovery scenarios
==================

If a user loses access to their authenticator:

1. An admin revokes the user's passkeys via the
   :ref:`Admin API <administration>`. Each revocation is
   recorded with the admin's UID and timestamp for audit
   purposes.
2. Once all passkeys are revoked, password login becomes
   available again for that user (the per-user enforcement
   lifts when no active credentials remain).
3. The user logs in with their password and registers a
   new passkey.

..  tip::

    Consider requiring users to register at least two
    passkeys on different authenticators (e.g. laptop +
    phone) for redundancy.

Containerized and multi-server deployments
==========================================

When running TYPO3 in Docker containers or behind a load
balancer, the file-based cache backends lose state on
container restart and are not shared across servers. This
affects nonce replay protection and rate limiting.

See :ref:`Multi-server cache backends
<security-multi-server-caching>` for Redis configuration,
and :ref:`Reverse proxy and IP detection
<security-reverse-proxy>` for rate limiting behind a load
balancer.

Local development with DDEV
============================

DDEV sites (``*.ddev.site``) use HTTPS by default and are
treated as secure contexts by browsers. Passkeys work out
of the box.

..  code-block:: shell
    :caption: Starting a DDEV environment

    ddev start
    # Open https://mysite.ddev.site/typo3
    # Passkeys work immediately

For ``http://localhost`` (without HTTPS), most browsers also
treat this as a secure context, so passkeys will work.
However, custom local domains over plain HTTP (e.g.
``http://mysite.local``) will **not** work -- WebAuthn
requires a secure context.

See also :ref:`Troubleshooting: HTTPS requirement
<troubleshooting>`.

..  seealso::

    :ref:`Security: Production deployment requirements
    <security-deployment>` for trusted hosts pattern,
    reverse proxy configuration, and multi-server cache
    backends.
