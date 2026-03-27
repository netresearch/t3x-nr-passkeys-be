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

The extension uses two TYPO3 caches:

-  ``nr_passkeys_be_nonce`` -- Stores single-use nonces for
   challenge replay protection (default backend:
   ``SimpleFileBackend``).
-  ``nr_passkeys_be_ratelimit`` -- Stores rate-limit counters
   and lockout flags (default backend: ``FileBackend``).

File-based cache backends store data on the local filesystem.
In a **multi-server deployment** (multiple TYPO3 instances
behind a load balancer), each server maintains its own
independent cache. This has two consequences:

1. **Nonce replay across servers** -- A challenge token
   consumed on server A still exists in server B's cache,
   allowing a replayed token to pass verification on
   server B.
2. **Rate-limit bypass** -- An attacker can distribute
   requests across servers, with each server tracking only
   a fraction of the total attempts.

For multi-server deployments, configure a shared cache
backend:

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

    // Use Redis for rate-limit cache
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']
        ['cacheConfigurations']['nr_passkeys_be_ratelimit']
        ['backend']
        = \TYPO3\CMS\Core\Cache\Backend\RedisBackend::class;
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']
        ['cacheConfigurations']['nr_passkeys_be_ratelimit']
        ['options'] = [
            'database' => 4,
            'defaultLifetime' => 600,
            // 'hostname' => '127.0.0.1',
            // 'port' => 6379,
            // 'password' => '',
        ];

..  note::

    Single-server deployments (including DDEV and most
    small-to-medium installations) work correctly with the
    default file-based backends. This only applies when
    multiple application servers share the same domain.
