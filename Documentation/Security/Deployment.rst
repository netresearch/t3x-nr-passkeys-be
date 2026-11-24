..  Copyright (c) 2025-2026 Netresearch DTT GmbH
..  SPDX-License-Identifier: GPL-2.0-or-later

..  include:: ../Includes.rst.txt

..  _security-deployment:

===================================
Production deployment requirements
===================================

The extension's security mechanisms depend on certain TYPO3
and server configurations being set correctly. Review each
section below before deploying to production.

..  _security-trusted-hosts:

Trusted hosts pattern
=====================

When :confval:`rpId` and :confval:`origin` are left empty
(the default), the extension auto-detects them from the
``HTTP_HOST`` server variable. An attacker who can inject an
arbitrary ``Host`` header could cause the extension to
generate challenge tokens bound to a malicious origin.

TYPO3 mitigates this with the ``trustedHostsPattern``
setting, but the default value ``.*`` allows **any** host
header.

..  warning::

    You **must** configure ``trustedHostsPattern`` in
    production. Leaving it at the default ``.*`` disables
    host header validation entirely.

..  code-block:: php
    :caption: config/system/settings.php

    $GLOBALS['TYPO3_CONF_VARS']['SYS']['trustedHostsPattern']
        = '(^|\.)example\.com$';

Alternatively, set :confval:`rpId` and :confval:`origin`
explicitly in the extension configuration. This bypasses
auto-detection entirely and removes the dependency on host
header validation for passkey operations.

..  _security-reverse-proxy:

Reverse proxy and IP detection
==============================

Rate limiting and account lockout use the client's IP address
(via ``GeneralUtility::getIndpEnv('REMOTE_ADDR')``). Behind
a reverse proxy, all requests appear to originate from the
proxy's IP address unless TYPO3 is configured to read the
real client IP from forwarded headers.

Without this configuration:

-  **Rate limiting becomes ineffective** -- all clients share
   a single counter and hit the limit collectively.
-  **Account lockout affects all users** -- one locked account
   blocks authentication for every user behind the same
   proxy.

Configure TYPO3 to trust your reverse proxy:

..  code-block:: php
    :caption: config/system/settings.php

    // IP address(es) of your reverse proxy (comma-separated)
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['reverseProxyIP']
        = '10.0.0.1,10.0.0.2';

    // Use the last (rightmost) value in X-Forwarded-For
    $GLOBALS['TYPO3_CONF_VARS']['SYS']
        ['reverseProxyHeaderMultiValue'] = 'last';

..  tip::

    If you use a CDN or cloud load balancer (e.g. AWS ALB,
    Cloudflare), ensure the ``X-Forwarded-For`` header chain
    is properly configured and that TYPO3's
    ``reverseProxyIP`` matches the load balancer's egress IP
    range.

..  _security-multi-server-caching:

Multi-server cache backends
===========================

Use one shared nonce cache for all nodes so that login tickets remain available
across requests. The database primary arbitrates single-use claims and rate limits.

..  code-block:: php
    :caption: config/system/additional.php

    // Use Redis for nonce cache
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']
        ['cacheConfigurations']['nr_passkeys_be_nonce']
        ['backend']
        = \TYPO3\CMS\Core\Cache\Backend\RedisBackend::class;
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']
        ['cacheConfigurations']['nr_passkeys_be_nonce']
        ['options'] = [
            'database' => 3,
            'defaultLifetime' => 300,
            // 'hostname' => '127.0.0.1',
            // 'port' => 6379,
            // 'password' => '',
        ];

Cluster deployment and upgrades
------------------------------

Apply the database schema before sending requests to the new workers. Route
consumed-token and rate-limit tables to one writable database primary across
all nodes. Use the same encryption key, a shared nonce cache and synchronized
clocks on all workers. Local caches make a challenge issued by one node
unavailable on the next. The database arbitrates claims and request budgets.

Drain every old worker before opening traffic on the new version. Finish
or invalidate pending short-lived challenges and login tickets, then verify
a fresh registration and login across different nodes. The changes to MFA
and replay handling apply only after all old workers have left the pool.

The move from cached counters to database counters starts a new throttling
window. A recovery suspension limits when a password session may start.
Review and terminate sessions created during the exception after recovery.

A verified passkey login skips the second factor only for users who have
an active MFA provider, also on a fresh browser session. With
``requireMfa``, users without a provider are still sent to the MFA setup.
The extension keeps the verified result in memory for the request and
writes it into the session only when the Core has created that session
for the same account. Log out before switching backend accounts.
