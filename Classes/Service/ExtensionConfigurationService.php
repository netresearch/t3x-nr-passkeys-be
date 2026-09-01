<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Service;

use Netresearch\NrPasskeysBe\Utility\TypeCastTrait;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\NormalizedParams;

final readonly class ExtensionConfigurationService
{
    use TypeCastTrait;

    private \Netresearch\NrPasskeysBe\Configuration\ExtensionConfiguration $config;

    public function __construct(private ExtensionConfiguration $extensionConfiguration)
    {
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
            skipMfaOnPasskeyAuth: !empty($settings['skipMfaOnPasskeyAuth'] ?? false),
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

        $host = $this->getNormalizedParams()->getHttpHost();

        if ($host === '') {
            // CLI / cron / background task: no Host header to spoof, so the
            // 'localhost' fallback is a safe anchor and trust enforcement does
            // not apply.
            return 'localhost';
        }

        $this->assertHostTrustEnforced();

        return $host;
    }

    public function getEffectiveOrigin(): string
    {
        $origin = $this->config->getOrigin();

        if ($origin !== '') {
            return $origin;
        }

        $params = $this->getNormalizedParams();
        $scheme = $params->isHttps() ? 'https' : 'http';
        $host = $params->getHttpHost();

        if ($host === '') {
            // CLI / cron / background task: no Host header to spoof; safe fallback.
            return $scheme . '://localhost';
        }

        $this->assertHostTrustEnforced();

        return $scheme . '://' . $host;
    }

    /**
     * Guard the rpId/origin auto-detection paths.
     *
     * When rpId/origin are left empty the anti-phishing anchor is derived from
     * the request Host header (NormalizedParams::getHttpHost()). That value is
     * only trustworthy when TYPO3's host-header validation (VerifyHostHeader,
     * driven by $GLOBALS['TYPO3_CONF_VARS']['SYS']['trustedHostsPattern']) is
     * actually enforcing a pattern. The allow-all '.*' makes VerifyHostHeader
     * accept ANY Host header, turning the derived rpId/origin into
     * attacker-controlled values. An empty pattern is treated by core as invalid
     * and rejects every Host (fail-closed at the framework level, see
     * VerifyHostHeader::isAllowedHostHeaderValue()); we still refuse to derive an
     * anchor from it. Fail closed in both cases rather than emit a Host-controlled
     * (or otherwise untrustworthy) anchor.
     *
     * @throws RuntimeException if host-header trust is disabled and no explicit
     *                          rpId/origin is configured
     */
    private function assertHostTrustEnforced(): void
    {
        $confVars = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        $pattern = \is_array($confVars) && isset($confVars['SYS']) && \is_array($confVars['SYS']) && \is_string($confVars['SYS']['trustedHostsPattern'] ?? null) ? $confVars['SYS']['trustedHostsPattern'] : '';

        // '.*' makes VerifyHostHeader accept any Host header; '' is treated by core
        // as invalid (rejects every Host). Refuse to derive an anchor from either.
        if ($pattern === '' || $pattern === '.*') {
            throw new RuntimeException(
                'Refusing to derive WebAuthn rpId/origin from the request Host header: ' . 'host-header validation is disabled (trustedHostsPattern is empty or ".*"). ' . 'Configure $GLOBALS[\'TYPO3_CONF_VARS\'][\'SYS\'][\'trustedHostsPattern\'] ' . 'with a strict pattern, or set the rpId and origin extension settings explicitly.',
                1700000060,
            );
        }
    }

    /**
     * Resolve NormalizedParams from the current request, with a $_SERVER fallback for CLI/tests.
     */
    private function getNormalizedParams(): NormalizedParams
    {
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;

        if ($request instanceof ServerRequestInterface) {
            $params = $request->getAttribute('normalizedParams');

            if ($params instanceof NormalizedParams) {
                return $params;
            }
        }

        $confVars = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        $sysConf = \is_array($confVars) && isset($confVars['SYS']) && \is_array($confVars['SYS']) ? $confVars['SYS'] : [];

        return NormalizedParams::createFromServerParams($_SERVER, $sysConf);
    }

    /**
     * Retrieve the TYPO3 encryption key from $GLOBALS['TYPO3_CONF_VARS'].
     *
     * @throws RuntimeException if the key is missing or shorter than 32 characters
     */
    public function getEncryptionKey(): string
    {
        $typo3Conf = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        $sysConf = \is_array($typo3Conf) ? $typo3Conf['SYS'] ?? null : null;
        $key = \is_array($sysConf) && \is_string($sysConf['encryptionKey'] ?? null) ? $sysConf['encryptionKey'] : '';

        if (\strlen($key) < 32) {
            throw new RuntimeException(
                'TYPO3 encryptionKey is missing or too short (min 32 chars). ' . 'Configure it in Settings > Configure Installation-Wide Options.',
                1700000050,
            );
        }

        return $key;
    }
}
