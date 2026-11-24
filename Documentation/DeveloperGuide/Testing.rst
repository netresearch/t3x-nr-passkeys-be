..  Copyright (c) 2025-2026 Netresearch DTT GmbH
..  SPDX-License-Identifier: GPL-2.0-or-later

..  include:: ../Includes.rst.txt

..  _developer-testing:

=======
Testing
=======

The extension includes a comprehensive test suite covering
unit tests, fuzz tests, functional tests, static analysis,
code style checks, JavaScript tests, end-to-end tests, and
mutation testing.

Running tests
=============

..  code-block:: bash
    :caption: PHP test commands

    # Unit tests
    composer ci:test:php:unit

    # Fuzz tests
    composer ci:test:php:fuzz

    # Functional tests (requires MySQL)
    composer ci:test:php:functional

    # Static analysis (PHPStan level 10)
    composer ci:test:php:phpstan

    # Code style (PER-CS3.0)
    composer ci:test:php:cgl

    # Mutation testing (Infection, min-MSI 80%, covered-MSI 80%)
    composer ci:mutation

..  code-block:: bash
    :caption: JavaScript and E2E test commands

    # JavaScript unit tests (Vitest)
    npx vitest run

    # E2E tests (Playwright); installs its own TYPO3 13 in containers
    Build/Scripts/runTests.sh -s e2e

    # ... the same against TYPO3 14
    E2E_TYPO3_VERSION=14 Build/Scripts/runTests.sh -s e2e

    # ... with backend sessions in Valkey
    E2E_VARIANT=redis Build/Scripts/runTests.sh -s e2e

The E2E environment
===================

``-s e2e`` brings up everything the browser needs: MariaDB, a TYPO3
installed by Composer with this extension required from the checkout,
PHP-FPM and Apache, plus the Playwright container that drives them. The
instance lives in ``.Build/e2e-typo3`` and is thrown away when the suite
passes; a failed run keeps it, so the backend can be inspected
afterwards.

With ``E2E_VARIANT=redis`` the instance stores backend sessions in Valkey
through the Core's Redis session backend. That backend throws when a session
record to update does not exist, so a write before the Core has created the
session fails there and passes on the database. CI runs both variants.

The specs only ever open ``/typo3``. The frontend exists because
provisioning refuses to hand over an instance whose frontend does not
answer 200 — ``e2e_provision_typoscript()`` in
``Build/Scripts/runTests.conf`` gives the root page just enough
TypoScript for that.

The provisioned run uses Docker for the services and Playwright.

Using an existing test instance
-------------------------------

Install the extension and apply its database schema on the test instance.
Use a dedicated backend administrator for browser login. The dashboard tests
change group enforcement and open user management. Use a disposable test
database with the fixture names below reserved for this suite.

From the extension checkout, import the same fixture the runner uses.
Replace ``passkeys_e2e`` with the test database name and use a MariaDB client
configured for that database. Import it once per database.

..  code-block:: bash

    mariadb passkeys_e2e < Tests/Fixtures/e2e-admin.sql

The fixture creates the group ``E2E passkey enforcement``, its member
``e2e_passkey_reminder`` with an active reminder, and ``e2e_passkey_management``
with one display-only credential. It also creates ``e2e_passkey_totp``, an
administrator with an active TOTP provider. Its password is copied from the
user named ``admin``, so that user must exist and its password must be the one
in ``TYPO3_ADMIN_PASS``. For a later run on the same database,
renew the one-day reminder before running the dashboard tests.

..  code-block:: sql

    UPDATE be_users SET passkey_nudge_until = UNIX_TIMESTAMP() + 86400
    WHERE username = 'e2e_passkey_reminder';

Export ``TYPO3_ADMIN_USER`` and ``TYPO3_ADMIN_PASS`` in the shell with the test
administrator's credentials. The browser and TYPO3 must agree on the
WebAuthn origin and relying party ID.

The login endpoints count requests per client address, and the suite sends
all of them from one address. A full run sends more than ten requests to
``/typo3/passkeys/login/options``, so the default of
:confval:`rateLimitMaxAttempts` (10 per window) answers later requests
with 429 and fails the API tests. Raise the limit on the test instance the way
the provisioned runner does, for example in
``config/system/additional.php``:

..  code-block:: php

    $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_passkeys_be']
        ['rateLimitMaxAttempts'] = '500';

..  code-block:: bash
    :caption: Run Playwright against the prepared instance

    npm ci
    npx playwright install chromium
    TYPO3_BASE_URL=https://typo3.example.test npm run test:e2e
