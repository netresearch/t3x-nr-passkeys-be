<?php

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Tests\Unit\Service;

use Netresearch\NrPasskeysBe\Service\ExtensionConfigurationService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Utility\GeneralUtility;

#[CoversClass(ExtensionConfigurationService::class)]
final class ExtensionConfigurationServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        GeneralUtility::flushInternalRuntimeCaches();
    }

    protected function tearDown(): void
    {
        GeneralUtility::flushInternalRuntimeCaches();
        unset($_SERVER['HTTP_HOST'], $_SERVER['HTTPS']);
        parent::tearDown();
    }

    /**
     * Create a real ExtensionConfigurationService with mocked TYPO3 ExtensionConfiguration.
     *
     * @param array<string, mixed>|null $settings null simulates non-array return
     */
    private function createService(?array $settings = []): ExtensionConfigurationService
    {
        $typo3ExtConfig = $this->createMock(ExtensionConfiguration::class);
        $typo3ExtConfig
            ->method('get')
            ->with('nr_passkeys_be')
            ->willReturn($settings);

        return new ExtensionConfigurationService($typo3ExtConfig);
    }

    // --- getConfiguration() tests ---

    #[Test]
    public function getConfigurationReturnsDefaultValuesWithEmptySettings(): void
    {
        $service = $this->createService([]);
        $config = $service->getConfiguration();

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
    public function getConfigurationUsesProvidedSettings(): void
    {
        $service = $this->createService([
            'rpId' => 'example.com',
            'rpName' => 'My TYPO3 Site',
            'origin' => 'https://example.com',
            'challengeTtlSeconds' => 60,
            'userVerification' => 'preferred',
            'discoverableLoginEnabled' => true,
            'disablePasswordLogin' => true,
            'rateLimitMaxAttempts' => 20,
            'rateLimitWindowSeconds' => 600,
            'lockoutThreshold' => 10,
            'lockoutDurationSeconds' => 1800,
            'allowedAlgorithms' => 'ES256,RS256',
        ]);
        $config = $service->getConfiguration();

        self::assertSame('example.com', $config->getRpId());
        self::assertSame('My TYPO3 Site', $config->getRpName());
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
    public function getConfigurationHandlesNonArrayReturnFromExtensionConfig(): void
    {
        $service = $this->createService(null);
        $config = $service->getConfiguration();

        // Should fall back to all defaults
        self::assertSame('', $config->getRpId());
        self::assertSame('TYPO3 Backend', $config->getRpName());
    }

    // --- getEffectiveRpId() tests ---

    #[Test]
    public function getEffectiveRpIdReturnsConfiguredRpIdWhenSet(): void
    {
        $service = $this->createService(['rpId' => 'mysite.example.com']);

        self::assertSame('mysite.example.com', $service->getEffectiveRpId());
    }

    #[Test]
    public function getEffectiveRpIdFallsBackToHttpHostWhenRpIdEmpty(): void
    {
        $_SERVER['HTTP_HOST'] = 'server.example.org';
        GeneralUtility::flushInternalRuntimeCaches();
        $service = $this->createService(['rpId' => '']);

        self::assertSame('server.example.org', $service->getEffectiveRpId());
    }

    #[Test]
    public function getEffectiveRpIdFallsBackToLocalhostWhenNoHttpHost(): void
    {
        unset($_SERVER['HTTP_HOST']);
        GeneralUtility::flushInternalRuntimeCaches();
        $service = $this->createService(['rpId' => '']);

        self::assertSame('localhost', $service->getEffectiveRpId());
    }

    // --- getEffectiveOrigin() tests ---

    #[Test]
    public function getEffectiveOriginReturnsConfiguredOriginWhenSet(): void
    {
        $service = $this->createService(['origin' => 'https://mysite.example.com']);

        self::assertSame('https://mysite.example.com', $service->getEffectiveOrigin());
    }

    #[Test]
    public function getEffectiveOriginBuildsHttpsOriginFromServerVars(): void
    {
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'secure.example.com';
        GeneralUtility::flushInternalRuntimeCaches();
        $service = $this->createService(['origin' => '']);

        self::assertSame('https://secure.example.com', $service->getEffectiveOrigin());
    }

    #[Test]
    public function getEffectiveOriginBuildsHttpOriginWhenHttpsOff(): void
    {
        $_SERVER['HTTPS'] = '';
        $_SERVER['HTTP_HOST'] = 'plain.example.com';
        GeneralUtility::flushInternalRuntimeCaches();
        $service = $this->createService(['origin' => '']);

        self::assertSame('http://plain.example.com', $service->getEffectiveOrigin());
    }

    #[Test]
    public function getEffectiveOriginBuildsHttpOriginWhenHttpsNotSet(): void
    {
        unset($_SERVER['HTTPS']);
        $_SERVER['HTTP_HOST'] = 'plain.example.com';
        GeneralUtility::flushInternalRuntimeCaches();
        $service = $this->createService(['origin' => '']);

        self::assertSame('http://plain.example.com', $service->getEffectiveOrigin());
    }

    #[Test]
    public function getEffectiveOriginFallsBackToLocalhostWhenNoServerVars(): void
    {
        unset($_SERVER['HTTPS'], $_SERVER['HTTP_HOST']);
        GeneralUtility::flushInternalRuntimeCaches();
        $service = $this->createService(['origin' => '']);

        self::assertSame('http://localhost', $service->getEffectiveOrigin());
    }

    #[Test]
    public function getEffectiveOriginIgnoresConfiguredRpIdForOrigin(): void
    {
        // Even when rpId is set, origin should not use it -- origin is a separate setting
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'host.example.com';
        GeneralUtility::flushInternalRuntimeCaches();
        $service = $this->createService([
            'rpId' => 'rpid.example.com',
            'origin' => '',
        ]);

        // Origin should come from HTTP_HOST, not rpId
        self::assertSame('https://host.example.com', $service->getEffectiveOrigin());
    }

    #[Test]
    public function getConfigurationReturnsSameInstanceOnMultipleCalls(): void
    {
        $service = $this->createService(['rpId' => 'test.example.com']);

        $config1 = $service->getConfiguration();
        $config2 = $service->getConfiguration();

        self::assertSame($config1, $config2);
    }
}
