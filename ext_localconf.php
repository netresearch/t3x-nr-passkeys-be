<?php

declare(strict_types=1);

use Netresearch\NrPasskeysBe\Authentication\PasskeyAuthenticationService;
use Netresearch\NrPasskeysBe\LoginProvider\PasskeyLoginProvider;
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

// Register passkey login provider
$GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['backend']['loginProviders'][1700000000] = [
    'provider' => PasskeyLoginProvider::class,
    'sorting' => 25,
    'iconIdentifier' => 'passkeys-be-login',
    'label' => 'LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang.xlf:login.provider.label',
];

// Register cache for challenge nonces
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
