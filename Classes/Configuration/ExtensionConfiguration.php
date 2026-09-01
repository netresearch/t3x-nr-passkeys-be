<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Configuration;

/**
 * Typed, immutable value object for the extension's configuration settings.
 */
final readonly class ExtensionConfiguration
{
    private const VALID_USER_VERIFICATION = ['required', 'preferred', 'discouraged'];

    private string $userVerification;

    public function __construct(
        private string $rpId = '',
        private string $rpName = 'TYPO3 Backend',
        private string $origin = '',
        private int $challengeTtlSeconds = 120,
        string $userVerification = 'required',
        private bool $discoverableLoginEnabled = true,
        private bool $disablePasswordLogin = false,
        private bool $skipMfaOnPasskeyAuth = true,
        private int $rateLimitMaxAttempts = 10,
        private int $rateLimitWindowSeconds = 300,
        private int $lockoutThreshold = 5,
        private int $lockoutUserThreshold = 15,
        private int $lockoutDurationSeconds = 900,
        private string $allowedAlgorithms = 'ES256',
    ) {
        $this->userVerification = \in_array($userVerification, self::VALID_USER_VERIFICATION, true) ? $userVerification : 'required';
    }

    public function getRpId(): string
    {
        return $this->rpId;
    }

    public function getRpName(): string
    {
        return $this->rpName;
    }

    public function getOrigin(): string
    {
        return $this->origin;
    }

    public function getChallengeTtlSeconds(): int
    {
        return $this->challengeTtlSeconds;
    }

    public function getUserVerification(): string
    {
        return $this->userVerification;
    }

    public function isDiscoverableLoginEnabled(): bool
    {
        return $this->discoverableLoginEnabled;
    }

    public function isDisablePasswordLogin(): bool
    {
        return $this->disablePasswordLogin;
    }

    /**
     * Whether the TYPO3 MFA challenge should be skipped after a successful
     * passkey authentication. A passkey already satisfies multi-factor
     * (possession + biometric/PIN), so an additional TOTP step is redundant.
     * Password-based logins remain unaffected and still go through MFA.
     */
    public function isSkipMfaOnPasskeyAuth(): bool
    {
        return $this->skipMfaOnPasskeyAuth;
    }

    public function getRateLimitMaxAttempts(): int
    {
        return $this->rateLimitMaxAttempts;
    }

    public function getRateLimitWindowSeconds(): int
    {
        return $this->rateLimitWindowSeconds;
    }

    public function getLockoutThreshold(): int
    {
        return $this->lockoutThreshold;
    }

    /**
     * Per-username lockout threshold (without IP). Higher than per-IP threshold
     * to catch distributed attacks across multiple IPs targeting the same account.
     */
    public function getLockoutUserThreshold(): int
    {
        return $this->lockoutUserThreshold;
    }

    public function getLockoutDurationSeconds(): int
    {
        return $this->lockoutDurationSeconds;
    }

    public function getAllowedAlgorithms(): string
    {
        return $this->allowedAlgorithms;
    }

    /**
     * @return list<string>
     */
    public function getAllowedAlgorithmsList(): array
    {
        return \array_map(trim(...), \explode(',', $this->allowedAlgorithms));
    }
}
