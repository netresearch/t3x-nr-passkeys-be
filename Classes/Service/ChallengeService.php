<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Service;

use Psr\Log\LoggerInterface;
use RuntimeException;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\Locking\LockingStrategyInterface;

/**
 * Manages HMAC-signed, single-use, time-limited WebAuthn challenge tokens.
 */
final class ChallengeService
{
    private const HMAC_ALGO = 'sha256';

    public function __construct(
        private readonly FrontendInterface $nonceCache,
        private readonly ExtensionConfigurationService $configService,
        private readonly LockFactory $lockFactory,
        private readonly LoggerInterface $logger,
    ) {}

    public function generateChallenge(): string
    {
        return \random_bytes(32);
    }

    public function createChallengeToken(string $challenge): string
    {
        $ttl = $this->configService->getConfiguration()->getChallengeTtlSeconds();
        $expiresAt = \time() + $ttl;
        $nonce = \bin2hex(\random_bytes(16));

        $payload = \base64_encode($challenge) . '|' . $expiresAt . '|' . $nonce;
        $hmac = \hash_hmac(self::HMAC_ALGO, $payload, $this->getSigningKey());

        // Store nonce in cache to ensure single-use
        $this->nonceCache->set(
            $this->getNonceCacheKey($nonce),
            'valid',
            [],
            $ttl + 60, // extra buffer for clock skew
        );

        return \base64_encode($payload . '|' . $hmac);
    }

    /**
     * @throws RuntimeException if token is invalid, expired, or replayed
     */
    public function verifyChallengeToken(string $token): string
    {
        $decoded = \base64_decode($token, true);
        if ($decoded === false) {
            throw new RuntimeException('Invalid challenge token encoding', 1700000001);
        }

        $parts = \explode('|', $decoded);
        if (\count($parts) !== 4) {
            throw new RuntimeException('Invalid challenge token format', 1700000002);
        }

        [$challengeB64, $expiresAtStr, $nonce, $hmac] = $parts;

        // Verify HMAC (constant-time comparison)
        $payload = $challengeB64 . '|' . $expiresAtStr . '|' . $nonce;
        $expectedHmac = \hash_hmac(self::HMAC_ALGO, $payload, $this->getSigningKey());

        if (!\hash_equals($expectedHmac, $hmac)) {
            $this->logger->warning('Invalid HMAC signature on challenge token (possible tampering)', [
                'noncePrefix' => \substr($nonce, 0, 8) . '...',
            ]);

            throw new RuntimeException('Challenge token signature invalid', 1700000003);
        }

        // Check TTL
        $expiresAt = (int) $expiresAtStr;
        if (\time() > $expiresAt) {
            $this->logger->warning('Expired challenge token presented', [
                'expiredAt' => $expiresAt,
                'noncePrefix' => \substr($nonce, 0, 8) . '...',
            ]);

            throw new RuntimeException('Challenge token expired', 1700000004);
        }

        // Atomic nonce invalidation: lock ensures only one concurrent request
        // can consume a given nonce, preventing replay via TOCTOU race.
        $nonceCacheKey = $this->getNonceCacheKey($nonce);
        $locker = $this->lockFactory->createLocker(
            'passkey_nonce_' . $nonceCacheKey,
            LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE,
        );

        if (!$locker->acquire(LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE)) {
            $this->logger->error('Failed to acquire nonce lock', [
                'nonceCacheKey' => $nonceCacheKey,
            ]);

            throw new RuntimeException('Failed to acquire nonce lock', 1700000007);
        }

        try {
            $nonceExisted = $this->nonceCache->get($nonceCacheKey) !== false;
            $this->nonceCache->remove($nonceCacheKey);
        } finally {
            $locker->release();
        }

        if (!$nonceExisted) {
            $this->logger->warning('Challenge nonce replay attempt (nonce already consumed or expired)', [
                'noncePrefix' => \substr($nonce, 0, 8) . '...',
            ]);

            throw new RuntimeException('Challenge nonce already used or expired', 1700000005);
        }

        $challenge = \base64_decode($challengeB64, true);
        if ($challenge === false) {
            throw new RuntimeException('Invalid challenge data in token', 1700000006);
        }

        return $challenge;
    }

    private function getSigningKey(): string
    {
        $key = $this->configService->getEncryptionKey();

        return \hash_hkdf('sha256', $key, 32, 'nr_passkeys_be_challenge');
    }

    private function getNonceCacheKey(string $nonce): string
    {
        return 'nonce_' . \preg_replace('/[^a-zA-Z0-9_]/', '', $nonce);
    }
}
