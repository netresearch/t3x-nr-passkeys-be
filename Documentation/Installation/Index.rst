.. include:: ../Includes.rst.txt

============
Installation
============

Prerequisites
=============

- TYPO3 13.4 LTS or TYPO3 14.x
- PHP 8.2, 8.3, 8.4, or 8.5
- HTTPS is **required** for WebAuthn (except for ``localhost`` during
  development)
- A configured TYPO3 encryption key (``$GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey']``,
  minimum 32 characters)

Installation via Composer
=========================

This is the recommended way to install the extension:

.. code-block:: bash

   composer require netresearch/nr-passkeys-be

Activate the extension
======================

After installation, activate the extension in the TYPO3 backend:

1. Go to :guilabel:`Admin Tools > Extensions`
2. Search for "Passkeys Backend Authentication"
3. Click the activate button

Or use the CLI:

.. code-block:: bash

   vendor/bin/typo3 extension:activate nr_passkeys_be

Database schema update
======================

The extension adds a ``tx_nrpasskeysbe_credential`` table. After activation,
run the database schema update:

1. Go to :guilabel:`Admin Tools > Maintenance > Analyze Database Structure`
2. Apply the suggested changes

Or use the CLI:

.. code-block:: bash

   vendor/bin/typo3 database:updateschema

Verify the installation
=======================

After activation:

1. The TYPO3 backend login page should show a "Passkey" login provider tab.
2. In :guilabel:`User Settings`, a "Passkeys" section should appear where
   authenticated users can register their first passkey.

.. note::

   HTTPS is mandatory for WebAuthn to function. The only exception is
   ``localhost`` for local development. If you are running TYPO3 behind a
   reverse proxy, ensure that the ``TYPO3_SSL`` environment variable or the
   ``[SYS][reverseProxySSL]`` configuration is set correctly.
