<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Service;

use Netresearch\NrPasskeysBe\Utility\TypeCastTrait;
use RuntimeException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class ExtensionConfigurationService
{
    use TypeCastTrait;
    private readonly \Netresearch\NrPasskeysBe\Configuration\ExtensionConfiguration $config;

    public function __construct(
        private readonly ExtensionConfiguration $extensionConfiguration,
    ) {
        $settings = $this->extensionConfiguration->get('nr_passkeys_be');
        if (!\is_array($settings)) {
            $settings = [];
        }
        $this->config = new \Netresearch\NrPasskeysBe\Configuration\ExtensionConfiguration(
            rpId: self::stringVal($settings['rpId'] ?? null),
            rpName: self::stringVal($settings['rpName'] ?? null, 'TYPO3 Backend'),
            origin: self::stringVal($settings['origin'] ?? null),
            challengeTtlSeconds: self::intVal($settings['challengeTtlSeconds'] ?? null, 120),
            userVerification: self::stringVal($settings['userVerification'] ?? null, 'required'),
            discoverableLoginEnabled: !empty($settings['discoverableLoginEnabled'] ?? true),
            disablePasswordLogin: !empty($settings['disablePasswordLogin'] ?? false),
            rateLimitMaxAttempts: self::intVal($settings['rateLimitMaxAttempts'] ?? null, 10),
            rateLimitWindowSeconds: self::intVal($settings['rateLimitWindowSeconds'] ?? null, 300),
            lockoutThreshold: self::intVal($settings['lockoutThreshold'] ?? null, 5),
            lockoutUserThreshold: self::intVal($settings['lockoutUserThreshold'] ?? null, 15),
            lockoutDurationSeconds: self::intVal($settings['lockoutDurationSeconds'] ?? null, 900),
            allowedAlgorithms: self::stringVal($settings['allowedAlgorithms'] ?? null, 'ES256'),
        );
    }

    public function getConfiguration(): \Netresearch\NrPasskeysBe\Configuration\ExtensionConfiguration
    {
        return $this->config;
    }

    public function getEffectiveRpId(): string
    {
        $rpId = $this->config->getRpId();
        if ($rpId !== '') {
            return $rpId;
        }

        $rawHost = GeneralUtility::getIndpEnv('HTTP_HOST');
        $host = \is_string($rawHost) ? $rawHost : '';

        return $host !== '' ? $host : 'localhost';
    }

    public function getEffectiveOrigin(): string
    {
        $origin = $this->config->getOrigin();
        if ($origin !== '') {
            return $origin;
        }

        $rawSsl = GeneralUtility::getIndpEnv('TYPO3_SSL');
        $isHttps = \is_string($rawSsl) ? $rawSsl !== '' && $rawSsl !== '0' : !empty($rawSsl);
        $scheme = $isHttps ? 'https' : 'http';
        $rawHost = GeneralUtility::getIndpEnv('HTTP_HOST');
        $host = \is_string($rawHost) ? $rawHost : '';

        return $scheme . '://' . ($host !== '' ? $host : 'localhost');
    }

    /**
     * Retrieve the TYPO3 encryption key from $GLOBALS['TYPO3_CONF_VARS'].
     *
     * @throws RuntimeException if the key is missing or shorter than 32 characters
     */
    public function getEncryptionKey(): string
    {
        $typo3Conf = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        $sysConf = \is_array($typo3Conf) ? ($typo3Conf['SYS'] ?? null) : null;
        $key = \is_array($sysConf) && \is_string($sysConf['encryptionKey'] ?? null)
            ? $sysConf['encryptionKey']
            : '';

        if (\strlen($key) < 32) {
            throw new RuntimeException(
                'TYPO3 encryptionKey is missing or too short (min 32 chars). '
                . 'Configure it in Settings > Configure Installation-Wide Options.',
                1700000050,
            );
        }

        return $key;
    }
}
