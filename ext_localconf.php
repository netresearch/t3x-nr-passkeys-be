<?php

declare(strict_types=1);

use Netresearch\NrPasskeysBe\Authentication\PasskeyAuthenticationService;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') or die();

// Register passkey authentication service with priority 80 (higher than SaltedPasswordService at 50)
ExtensionManagementUtility::addService(
    'nr_passkeys_be',
    'auth',
    PasskeyAuthenticationService::class,
    [
        'title' => 'Passkey Authentication',
        'description' => 'Authenticates backend users via WebAuthn/Passkey assertions',
        'subtype' => 'authUserBE,getUserBE',
        'available' => true,
        'priority' => 80,
        'quality' => 80,
        'os' => '',
        'exec' => '',
        'className' => PasskeyAuthenticationService::class,
    ]
);

// Security audit logging for passkey authentication events.
// WARNING and above: failed logins, lockouts, rate limiting, password-disable blocks.
// INFO: successful logins, discoverable flow resolutions.
// Uses ??= so site configuration can override.
$GLOBALS['TYPO3_CONF_VARS']['LOG']['Netresearch']['NrPasskeysBe']['writerConfiguration'][\TYPO3\CMS\Core\Log\LogLevel::WARNING] ??= [
    \TYPO3\CMS\Core\Log\Writer\FileWriter::class => [
        'logFile' => 'typo3temp/var/log/passkey_auth.log',
    ],
];

// Register cache for challenge nonces
// Register custom FormEngine element for passkey info display in be_users records
$GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['nodeRegistry'][1739000000] = [
    'nodeName' => 'passkeyInfo',
    'priority' => 40,
    'class' => \Netresearch\NrPasskeysBe\Form\Element\PasskeyInfoElement::class,
];

$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['nr_passkeys_be_nonce'] ??= [];
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['nr_passkeys_be_nonce']['backend'] ??=
    \TYPO3\CMS\Core\Cache\Backend\SimpleFileBackend::class;
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['nr_passkeys_be_nonce']['options'] ??= [
    'defaultLifetime' => 300,
];

// Register cache for rate limiting (FileBackend required for flushByTag support)
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['nr_passkeys_be_ratelimit'] ??= [];
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['nr_passkeys_be_ratelimit']['backend'] ??=
    \TYPO3\CMS\Core\Cache\Backend\FileBackend::class;
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['nr_passkeys_be_ratelimit']['options'] ??= [
    'defaultLifetime' => 600,
];
