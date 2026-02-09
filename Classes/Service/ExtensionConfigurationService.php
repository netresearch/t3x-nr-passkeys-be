<?php

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Service;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class ExtensionConfigurationService
{
    private readonly \Netresearch\NrPasskeysBe\Configuration\ExtensionConfiguration $config;

    public function __construct(
        private readonly ExtensionConfiguration $extensionConfiguration,
    ) {
        $settings = $this->extensionConfiguration->get('nr_passkeys_be');
        if (!\is_array($settings)) {
            $settings = [];
        }
        $this->config = new \Netresearch\NrPasskeysBe\Configuration\ExtensionConfiguration(
            rpId: (string) ($settings['rpId'] ?? ''),
            rpName: (string) ($settings['rpName'] ?? 'TYPO3 Backend'),
            origin: (string) ($settings['origin'] ?? ''),
            challengeTtlSeconds: (int) ($settings['challengeTtlSeconds'] ?? 120),
            userVerification: (string) ($settings['userVerification'] ?? 'required'),
            discoverableLoginEnabled: (bool) ($settings['discoverableLoginEnabled'] ?? false),
            disablePasswordLogin: (bool) ($settings['disablePasswordLogin'] ?? false),
            rateLimitMaxAttempts: (int) ($settings['rateLimitMaxAttempts'] ?? 10),
            rateLimitWindowSeconds: (int) ($settings['rateLimitWindowSeconds'] ?? 300),
            lockoutThreshold: (int) ($settings['lockoutThreshold'] ?? 5),
            lockoutDurationSeconds: (int) ($settings['lockoutDurationSeconds'] ?? 900),
            allowedAlgorithms: (string) ($settings['allowedAlgorithms'] ?? 'ES256'),
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

        $host = (string) GeneralUtility::getIndpEnv('HTTP_HOST');

        return $host !== '' ? $host : 'localhost';
    }

    public function getEffectiveOrigin(): string
    {
        $origin = $this->config->getOrigin();
        if ($origin !== '') {
            return $origin;
        }

        $isHttps = (bool) GeneralUtility::getIndpEnv('TYPO3_SSL');
        $scheme = $isHttps ? 'https' : 'http';
        $host = (string) GeneralUtility::getIndpEnv('HTTP_HOST');

        return $scheme . '://' . ($host !== '' ? $host : 'localhost');
    }
}
