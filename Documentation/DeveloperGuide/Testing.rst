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

    # E2E tests (Playwright, PHP built-in server + MySQL)
    # Set TYPO3_BASE_URL to override the default http://localhost:8080
    Build/Scripts/runTests.sh e2e
