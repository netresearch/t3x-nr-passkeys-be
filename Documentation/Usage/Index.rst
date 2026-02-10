..  include:: ../Includes.rst.txt

..  _usage:

=====
Usage
=====

Registering a passkey
=====================

Before you can use passwordless login, you need to register at least
one passkey:

1. Log in to the TYPO3 backend with your regular password.
2. Go to :guilabel:`User Settings` (click your avatar in the
   top-right corner).
3. Find the **Passkeys** section.
4. Click :guilabel:`Register new passkey`.
5. Your browser will prompt you to create a passkey using your
   preferred authenticator (TouchID, Windows Hello, YubiKey, etc.).
6. Optionally give the passkey a descriptive label (e.g. "MacBook
   TouchID" or "Office YubiKey").
7. Click :guilabel:`Save`.

..  figure:: /Images/UserSettings/PasskeyManagement.png
    :alt: User Settings page with Passkeys management section
    :class: with-shadow

    Manage your passkeys in the User Settings module.

You can register multiple passkeys for the same account -- for
example, one on your laptop and one on a hardware security key.

Logging in with a passkey
=========================

Username-first flow (default)
-----------------------------

1. Navigate to the TYPO3 backend login page.
2. Enter your **username**.
3. Click :guilabel:`Sign in with a passkey`.
4. Your browser will prompt you to verify with your authenticator.
5. Upon successful verification, you are logged in.

..  figure:: /Images/Login/LoginPageUsernameFirst.png
    :alt: Login form with username filled and passkey button ready
    :class: with-shadow
    :width: 400px

    Enter your username, then click Sign in with a passkey.

Discoverable login (usernameless)
---------------------------------

When :confval:`discoverableLoginEnabled` is set to ``true``:

1. Navigate to the TYPO3 backend login page.
2. The browser may automatically show available passkeys in an
   autofill dropdown (Conditional UI).
3. Select your passkey.
4. Verify with your authenticator.
5. You are logged in without typing a username.

..  note::

    Discoverable login requires that the passkey was registered as a
    *resident credential* (stored on the authenticator). Most modern
    authenticators do this by default.

Error handling
--------------

If a passkey login fails (for example, the server cannot verify the
assertion), a passkey-specific error message is shown on the login
page:

..  figure:: /Images/Login/LoginPagePasskeyError.png
    :alt: Login form showing passkey authentication failed error
    :class: with-shadow
    :width: 400px

    A clear error message tells you the passkey was not accepted.

Managing your passkeys
======================

In :guilabel:`User Settings > Passkeys`, you can:

- **View** all your registered passkeys with their labels, creation
  dates, and last-used timestamps.
- **Rename** a passkey by clicking its label and entering a new name
  (max 128 characters).
- **Remove** a passkey you no longer need.

..  important::

    If :confval:`disablePasswordLogin` is enabled, you cannot remove
    your last remaining passkey. This prevents you from locking
    yourself out of the system.

Fallback to password login
==========================

By default, password login remains available. If a user does not have
a passkey registered or their authenticator is unavailable, they can
still log in with their regular TYPO3 password.

This fallback can be disabled with the
:confval:`disablePasswordLogin` setting.
