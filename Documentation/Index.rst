..  include:: Includes.rst.txt

==================================
Passkeys Backend Authentication
==================================

:Extension key:
    nr_passkeys_be

:Package name:
    netresearch/nr-passkeys-be

:Version:
    |release|

:Language:
    en

:Author:
    Netresearch DTT GmbH

:License:
    This document is published under the
    `GPL-2.0-or-later <https://www.gnu.org/licenses/gpl-2.0.html>`__
    license.

:Rendered:
    |today|

----

Passwordless TYPO3 backend authentication via WebAuthn/FIDO2 Passkeys.
Enables one-click login with TouchID, FaceID, YubiKey, and Windows
Hello -- directly on the standard TYPO3 login form.

..  figure:: /Images/Login/LoginPageWithPasskey.png
    :alt: TYPO3 login form with Sign in with a passkey button
    :class: with-shadow
    :width: 400px

    The passkey button appears below the Login button with an "or"
    divider.

----

..  card-grid::
    :columns: 1
    :columns-md: 2
    :gap: 4
    :card-height: 100

    ..  card:: :ref:`Introduction <introduction>`

        Learn what the extension does, which authenticators and
        browsers are supported, and see the full feature list.

    ..  card:: :ref:`Installation <installation>`

        Install via Composer, activate the extension, and run the
        database schema update.

    ..  card:: :ref:`Configuration <configuration>`

        Configure relying party, challenge TTL, discoverable login,
        rate limiting, account lockout, and cryptographic algorithms.

    ..  card:: :ref:`Usage <usage>`

        Register passkeys, log in with a single touch, and manage
        your credentials in User Settings.

    ..  card:: :ref:`Administration <administration>`

        Admin API for listing, revoking credentials and unlocking
        locked-out accounts.

    ..  card:: :ref:`Developer Guide <developer-guide>`

        Architecture overview, authentication service, controllers,
        services, and how to run tests.

    ..  card:: :ref:`Security <security>`

        WebAuthn security model, HMAC-signed challenges, rate
        limiting, and user enumeration prevention.

    ..  card:: :ref:`Changelog <changelog>`

        Version history and release notes.

..  toctree::
    :maxdepth: 2
    :titlesonly:
    :hidden:

    Introduction/Index
    Installation/Index
    Configuration/Index
    Usage/Index
    Administration/Index
    DeveloperGuide/Index
    Security/Index
    Changelog/Index
