..  Copyright (c) 2025-2026 Netresearch DTT GmbH
..  SPDX-License-Identifier: GPL-2.0-or-later

..  include:: ../Includes.rst.txt

..  _installation:

============
Installation
============

Prerequisites
=============

- TYPO3 12.4 LTS, TYPO3 13.4 LTS, or TYPO3 14.3 LTS
- PHP 8.2, 8.3, 8.4, 8.5, or 8.6
- HTTPS is **required** for WebAuthn (except for ``localhost`` during
  development)
- A configured TYPO3 encryption key
  (``$GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey']``,
  minimum 32 characters)

Installation via Composer
=========================

This is the recommended way to install the extension:

..  code-block:: bash
    :caption: Install via Composer

    composer require netresearch/nr-passkeys-be

Database schema update
======================

Run the TYPO3 setup command during deployment, before the first request
reaches the updated extension. It installs the credential, consumed-token
and rate-limit tables and updates the backend user and group tables.

..  code-block:: bash

    vendor/bin/typo3 extension:setup

Use the Maintenance module's database structure analyzer to verify the
result. For a cluster deployment follow :ref:`security-deployment`.

Verify the installation
=======================

After activation:

1. The TYPO3 backend login page should show a
   :guilabel:`Sign in with a passkey` button below the Login button.

   ..  figure:: /Images/Login/LoginPageWithPasskey.png
       :alt: TYPO3 login form with the passkey button visible
       :class: with-border with-shadow
       :width: 200px
       :zoom: lightbox

       The passkey button appears below the Login button, separated
       by an "or" divider.

2. In :guilabel:`User Settings`, a "Passkeys" section should appear
   where authenticated users can register their first passkey.

..  note::

    HTTPS is mandatory for WebAuthn to function. The only exception is
    ``localhost`` for local development. If you are running TYPO3
    behind a reverse proxy, ensure that the ``TYPO3_SSL`` environment
    variable or the ``[SYS][reverseProxySSL]`` configuration is set
    correctly.
