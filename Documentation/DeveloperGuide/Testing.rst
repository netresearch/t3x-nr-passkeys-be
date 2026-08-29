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

    # ... or against a TYPO3 that is already running
    TYPO3_BASE_URL=https://your-typo3.local Build/Scripts/runTests.sh -s e2e

The E2E environment
===================

``-s e2e`` brings up everything the browser needs: MariaDB, a TYPO3
installed by Composer with this extension required from the checkout,
PHP-FPM and Apache, plus the Playwright container that drives them. The
instance lives in ``.Build/e2e-typo3`` and is thrown away when the suite
passes; a failed run keeps it, so the backend can be inspected
afterwards.

The specs only ever open ``/typo3``. The frontend exists because
provisioning refuses to hand over an instance whose frontend does not
answer 200 — ``e2e_provision_typoscript()`` in
``Build/Scripts/runTests.conf`` gives the root page just enough
TypoScript for that.

Docker is required. Nothing is installed on the host, and DDEV is not
involved.
