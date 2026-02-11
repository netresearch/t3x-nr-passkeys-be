<?php

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Service;

use RuntimeException;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

class ChallengeService
{
    private const HMAC_ALGO = 'sha256';

    public function __construct(
        private readonly FrontendInterface $nonceCache,
        private readonly ExtensionConfigurationService $configService,
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
            throw new RuntimeException('Challenge token signature invalid', 1700000003);
        }

        // Check TTL
        $expiresAt = (int) $expiresAtStr;
        if (\time() > $expiresAt) {
            throw new RuntimeException('Challenge token expired', 1700000004);
        }

        // Invalidate nonce first (single-use replay protection).
        // Remove-before-check avoids TOCTOU race: if two concurrent requests
        // try to use the same nonce, only the first remove() returns true.
        $nonceCacheKey = $this->getNonceCacheKey($nonce);
        $nonceExisted = $this->nonceCache->get($nonceCacheKey) !== false;
        $this->nonceCache->remove($nonceCacheKey);

        if (!$nonceExisted) {
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
        $key = $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] ?? '';
        if (\strlen($key) < 32) {
            throw new RuntimeException(
                'TYPO3 encryptionKey is missing or too short (min 32 chars). '
                . 'Configure it in Settings > Configure Installation-Wide Options.',
                1700000050,
            );
        }

        return \hash_hkdf('sha256', $key, 32, 'nr_passkeys_be_challenge');
    }

    private function getNonceCacheKey(string $nonce): string
    {
        return 'nonce_' . \preg_replace('/[^a-zA-Z0-9_]/', '', $nonce);
    }
}
