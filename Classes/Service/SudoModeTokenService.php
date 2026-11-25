<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Service;

use Psr\Log\LoggerInterface;
use RuntimeException;

use function hash;
use function hash_equals;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * Short-lived proof that the current backend user just confirmed a sudo-mode
 * claim with a passkey.
 *
 * TYPO3 asks for the user's password before sensitive operations (changing a
 * password, creating an admin, editing group permissions, MFA setup). The core
 * accepts any other proof through SudoModeVerifyEvent, but that event only
 * carries the string typed into the password field. This service issues the
 * string the browser puts there after a successful passkey assertion.
 *
 * The token is a ChallengeService token whose "challenge" is a digest of user,
 * session and claim. ChallengeService already provides the HMAC, the expiry and
 * the single-use nonce; binding the digest to the context means a token issued
 * for one claim, user or session is worthless for any other. A login challenge
 * (32 random bytes) never equals such a digest, so the two token kinds cannot
 * be swapped.
 */
final readonly class SudoModeTokenService
{
    public const PREFIX = 'nrpk-sudo:';

    public function __construct(
        private ChallengeService $challengeService,
        private LoggerInterface $logger,
    ) {}

    public function issue(int $beUserUid, string $sessionIdentifier, string $claimId): string
    {
        return self::PREFIX . $this->challengeService->createChallengeToken(
            self::contextDigest($beUserUid, $sessionIdentifier, $claimId),
        );
    }

    /**
     * Whether the value looks like a token at all: anything else is a password
     * and must reach the core's password check untouched.
     */
    public function isToken(string $value): bool
    {
        return str_starts_with($value, self::PREFIX);
    }

    /**
     * Consumes the token. A second call with the same token fails.
     */
    public function verify(string $token, int $beUserUid, string $sessionIdentifier, string $claimId): bool
    {
        if (!$this->isToken($token) || $beUserUid <= 0 || $sessionIdentifier === '' || $claimId === '') {
            return false;
        }

        try {
            $challenge = $this->challengeService->verifyChallengeToken(substr($token, strlen(self::PREFIX)));
        } catch (RuntimeException $e) {
            $this->logger->warning('Sudo mode passkey token rejected', [
                'be_user_uid' => $beUserUid,
                'error'       => $e->getMessage(),
            ]);

            return false;
        }

        if (!hash_equals(self::contextDigest($beUserUid, $sessionIdentifier, $claimId), $challenge)) {
            $this->logger->warning('Sudo mode passkey token does not match user, session or claim', [
                'be_user_uid' => $beUserUid,
            ]);

            return false;
        }

        return true;
    }

    private static function contextDigest(int $beUserUid, string $sessionIdentifier, string $claimId): string
    {
        return hash(
            'sha256',
            'nr_passkeys_be_sudo|' . $beUserUid . '|' . hash('sha256', $sessionIdentifier) . '|' . $claimId,
            true,
        );
    }
}
