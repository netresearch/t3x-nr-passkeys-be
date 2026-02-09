<?php

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Configuration;

final class ExtensionConfiguration
{
    private const VALID_USER_VERIFICATION = ['required', 'preferred', 'discouraged'];

    private readonly string $userVerification;

    public function __construct(
        private readonly string $rpId = '',
        private readonly string $rpName = 'TYPO3 Backend',
        private readonly string $origin = '',
        private readonly int $challengeTtlSeconds = 120,
        string $userVerification = 'required',
        private readonly bool $discoverableLoginEnabled = false,
        private readonly bool $disablePasswordLogin = false,
        private readonly int $rateLimitMaxAttempts = 10,
        private readonly int $rateLimitWindowSeconds = 300,
        private readonly int $lockoutThreshold = 5,
        private readonly int $lockoutDurationSeconds = 900,
        private readonly string $allowedAlgorithms = 'ES256',
    ) {
        $this->userVerification = \in_array($userVerification, self::VALID_USER_VERIFICATION, true)
            ? $userVerification
            : 'required';
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
        return \array_map('trim', \explode(',', $this->allowedAlgorithms));
    }
}
