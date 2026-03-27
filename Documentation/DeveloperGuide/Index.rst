..  include:: ../Includes.rst.txt

..  _developer-guide:

===============
Developer guide
===============

This chapter describes the extension's architecture and
provides guidance for developers who want to understand,
debug, or extend the extension.

..  toctree::
    :maxdepth: 1
    :hidden:

    Architecture
    ControllersAndServices
    Testing

..  card-grid::
    :columns: 1
    :columns-md: 3
    :gap: 4
    :card-height: 100

    ..  card:: :ref:`Architecture <developer-architecture>`

        Extension structure, login form injection, banner
        injection, interstitial middleware, authentication
        data flow, and domain model.

    ..  card:: :ref:`Controllers and services <developer-controllers-services>`

        Route definitions, controller groups, service
        classes, and JavaScript modules.

    ..  card:: :ref:`Testing <developer-testing>`

        Running unit tests, fuzz tests, functional tests,
        static analysis, code style, E2E, and mutation
        testing.
