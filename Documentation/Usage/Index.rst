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
4. Enter a descriptive name in the text field (e.g. "MacBook TouchID"
   or "Office YubiKey"). The default is "Passkey".
5. Click :guilabel:`Add Passkey`.
6. Your browser will prompt you to create a passkey using your
   preferred authenticator (TouchID, Windows Hello, YubiKey, etc.).
7. After successful registration the passkey appears in the list and
   the name input resets for the next registration.

..  figure:: /Images/UserSettings/PasskeyManagement.png
    :alt: User Settings page with Passkeys management section
    :zoom: lightbox

    Manage your passkeys in the User Settings module.

You can register multiple passkeys for the same account -- for
example, one on your laptop and one on a hardware security key.

Logging in with a passkey
=========================

Discoverable login (default)
----------------------------

With :confval:`discoverableLoginEnabled` enabled (the default):

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

Username-first flow
-------------------

When :confval:`discoverableLoginEnabled` is set to ``false``:

1. Navigate to the TYPO3 backend login page.
2. Enter your **username**.
3. Click :guilabel:`Sign in with a passkey`.
4. Your browser will prompt you to verify with your authenticator.
5. Upon successful verification, you are logged in.

..  figure:: /Images/Login/LoginPageUsernameFirst.png
    :alt: Login form with username filled and passkey button ready
    :width: 200px
    :zoom: lightbox

    Enter your username, then click Sign in with a passkey.

Error handling
--------------

If a passkey login fails (for example, the server cannot verify the
assertion), a passkey-specific error message is shown on the login
page:

..  figure:: /Images/Login/LoginPagePasskeyError.png
    :alt: Login form showing passkey authentication failed error
    :width: 200px
    :zoom: lightbox

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

When passkey setup is required
==============================

Your administrator may configure passkey enforcement for your user group.
When this happens, you will see an interstitial page after logging in that
prompts you to register a passkey.

The interstitial page explains the benefits of passkeys and offers two options:

- :guilabel:`Set up now` -- Takes you directly to
  :guilabel:`User Settings > Passkeys` where you can register a passkey
  (see :ref:`usage` above).
- :guilabel:`Skip for now` -- Dismisses the prompt for the current session.
  This option is only available during the grace period.

..  note::

    The grace period is a window (e.g. 14 days) set by your administrator.
    During this time, you can skip the setup prompt and continue working.
    A countdown shows how many days remain: *"You have N days remaining to
    set up your passkey."*

Once the grace period expires, the :guilabel:`Skip for now` option disappears
and you must register a passkey before you can access the TYPO3 backend.

..  tip::

    Register your passkey early, even during the grace period. Passkeys
    provide stronger security than passwords and make logging in faster --
    a single touch or glance replaces typing a password.

If your group's enforcement level is set to **Enforced**, there is no grace
period at all. The setup prompt appears immediately after login and cannot be
skipped.
