<?php

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Tests\Unit\Configuration;

use Netresearch\NrPasskeysBe\Configuration\ExtensionConfiguration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExtensionConfiguration::class)]
final class ExtensionConfigurationTest extends TestCase
{
    #[Test]
    public function userVerificationDefaultsToRequiredForInvalidValue(): void
    {
        $config = new ExtensionConfiguration(userVerification: 'invalid');

        self::assertSame('required', $config->getUserVerification());
    }

    #[Test]
    public function userVerificationAcceptsPreferred(): void
    {
        $config = new ExtensionConfiguration(userVerification: 'preferred');

        self::assertSame('preferred', $config->getUserVerification());
    }

    #[Test]
    public function userVerificationAcceptsDiscouraged(): void
    {
        $config = new ExtensionConfiguration(userVerification: 'discouraged');

        self::assertSame('discouraged', $config->getUserVerification());
    }

    #[Test]
    public function userVerificationAcceptsRequired(): void
    {
        $config = new ExtensionConfiguration(userVerification: 'required');

        self::assertSame('required', $config->getUserVerification());
    }

    #[Test]
    public function defaultValues(): void
    {
        $config = new ExtensionConfiguration();

        self::assertSame('', $config->getRpId());
        self::assertSame('TYPO3 Backend', $config->getRpName());
        self::assertSame('', $config->getOrigin());
        self::assertSame(120, $config->getChallengeTtlSeconds());
        self::assertSame('required', $config->getUserVerification());
        self::assertTrue($config->isDiscoverableLoginEnabled());
        self::assertFalse($config->isDisablePasswordLogin());
        self::assertSame(10, $config->getRateLimitMaxAttempts());
        self::assertSame(300, $config->getRateLimitWindowSeconds());
        self::assertSame(5, $config->getLockoutThreshold());
        self::assertSame(900, $config->getLockoutDurationSeconds());
        self::assertSame('ES256', $config->getAllowedAlgorithms());
    }

    #[Test]
    public function customValues(): void
    {
        $config = new ExtensionConfiguration(
            rpId: 'example.com',
            rpName: 'My App',
            origin: 'https://example.com',
            challengeTtlSeconds: 60,
            userVerification: 'preferred',
            discoverableLoginEnabled: true,
            disablePasswordLogin: true,
            rateLimitMaxAttempts: 20,
            rateLimitWindowSeconds: 600,
            lockoutThreshold: 10,
            lockoutDurationSeconds: 1800,
            allowedAlgorithms: 'ES256,RS256',
        );

        self::assertSame('example.com', $config->getRpId());
        self::assertSame('My App', $config->getRpName());
        self::assertSame('https://example.com', $config->getOrigin());
        self::assertSame(60, $config->getChallengeTtlSeconds());
        self::assertSame('preferred', $config->getUserVerification());
        self::assertTrue($config->isDiscoverableLoginEnabled());
        self::assertTrue($config->isDisablePasswordLogin());
        self::assertSame(20, $config->getRateLimitMaxAttempts());
        self::assertSame(600, $config->getRateLimitWindowSeconds());
        self::assertSame(10, $config->getLockoutThreshold());
        self::assertSame(1800, $config->getLockoutDurationSeconds());
        self::assertSame('ES256,RS256', $config->getAllowedAlgorithms());
    }

    #[Test]
    public function allowedAlgorithmsListSplitsAndTrims(): void
    {
        $config = new ExtensionConfiguration(allowedAlgorithms: 'ES256 , RS256, ES384 ');

        self::assertSame(['ES256', 'RS256', 'ES384'], $config->getAllowedAlgorithmsList());
    }

    #[Test]
    public function allowedAlgorithmsListSingleAlgorithm(): void
    {
        $config = new ExtensionConfiguration(allowedAlgorithms: 'ES256');

        self::assertSame(['ES256'], $config->getAllowedAlgorithmsList());
    }
}
