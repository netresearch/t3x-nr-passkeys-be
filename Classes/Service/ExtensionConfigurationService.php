<?php

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Service;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class ExtensionConfigurationService
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

    private static function intVal(mixed $value, int $default = 0): int
    {
        return \is_numeric($value) ? (int) $value : $default;
    }

    private static function stringVal(mixed $value, string $default = ''): string
    {
        return \is_string($value) ? $value : $default;
    }
}
