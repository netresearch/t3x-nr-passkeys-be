..  include:: ../Includes.rst.txt

..  _introduction:

============
Introduction
============

What does it do?
================

Passkeys Backend Authentication provides passwordless authentication
for the TYPO3 backend using the WebAuthn/FIDO2 standard (Passkeys).
Backend users can log in with a single touch or glance using biometric
authenticators such as TouchID, FaceID, Windows Hello, or hardware
security keys like YubiKey.

The passkey button is injected directly into the **standard TYPO3 login
form** via a PSR-14 event listener -- no login provider switching
needed. Users see the familiar login page with a
:guilabel:`Sign in with a passkey` button below the Login button.

Passkeys are a modern, phishing-resistant replacement for passwords.
They use public-key cryptography: the private key never leaves the
user's device, and the server only stores a public key. This eliminates
the risk of credential theft through phishing or database breaches.

Features
========

..  card-grid::
    :columns: 1
    :columns-md: 2
    :gap: 4
    :card-height: 100

    ..  card:: Passwordless login

        Authenticate with TouchID, FaceID, YubiKey, or Windows Hello
        instead of a password. Injected directly into the standard
        TYPO3 login form.

    ..  card:: Primary credential

        Passkeys are a first-class authentication method (not MFA).
        The extension registers at priority 80, above the standard
        password service.

    ..  card:: Credential management

        Users can register, rename, and remove their own passkeys
        through the TYPO3 User Settings module.

    ..  card:: Admin panel

        Administrators can list, revoke, and manage passkeys for any
        backend user, and unlock locked-out accounts.

    ..  card:: Discoverable login

        Optional usernameless login (Conditional UI) where the browser
        auto-suggests available passkeys. Controlled via extension
        settings.

    ..  card:: Security hardened

        HMAC-signed challenges with nonce replay protection, rate
        limiting by IP, account lockout, user enumeration prevention,
        and audit logging.

    ..  card:: Configurable algorithms

        Supports ES256, ES384, ES512, and RS256 signing algorithms.
        Configurable user verification requirement.

    ..  card:: TYPO3 v13 and v14

        Compatible with TYPO3 13.4 LTS and TYPO3 14.x.
        PHP 8.2, 8.3, 8.4, and 8.5 supported.

Supported authenticators
========================

Any FIDO2/WebAuthn-compliant authenticator works, including:

- Apple TouchID and FaceID (macOS, iOS, iPadOS)
- Windows Hello (fingerprint, face, PIN)
- YubiKey 5 series and newer
- Android fingerprint and face unlock
- Any FIDO2-compliant hardware security key

Browser support
===============

WebAuthn is supported by all modern browsers:

=========================  ===========
Browser                    Version
=========================  ===========
Chrome / Edge              67+
Firefox                    60+
Safari                     14+
Chrome for Android         70+
Safari for iOS             14.5+
=========================  ===========
