<?php

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Tests\Unit\Service;

use Netresearch\NrPasskeysBe\Service\ChallengeService;
use Netresearch\NrPasskeysBe\Service\ExtensionConfigurationService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

#[CoversClass(ChallengeService::class)]
final class ChallengeServiceTest extends TestCase
{
    private FrontendInterface&MockObject $nonceCacheMock;
    private ExtensionConfigurationService $configService;
    private ChallengeService $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = 'test-encryption-key-that-is-long-enough-for-hmac';

        $this->nonceCacheMock = $this->createMock(FrontendInterface::class);
        $this->configService = $this->createConfigService([
            'challengeTtlSeconds' => 120,
        ]);

        $this->subject = new ChallengeService(
            $this->nonceCacheMock,
            $this->configService,
        );
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']);
        parent::tearDown();
    }

    /**
     * Create a real ExtensionConfigurationService with mocked TYPO3 ExtensionConfiguration.
     *
     * @param array<string, mixed> $settings
     */
    private function createConfigService(array $settings = []): ExtensionConfigurationService
    {
        $typo3ExtConfig = $this->createMock(ExtensionConfiguration::class);
        $typo3ExtConfig
            ->method('get')
            ->with('nr_passkeys_be')
            ->willReturn($settings);

        return new ExtensionConfigurationService($typo3ExtConfig);
    }

    #[Test]
    public function generateChallengeReturns32Bytes(): void
    {
        $challenge = $this->subject->generateChallenge();

        self::assertSame(32, \strlen($challenge));
    }

    #[Test]
    public function generateChallengeReturnsDifferentValuesEachCall(): void
    {
        $challenge1 = $this->subject->generateChallenge();
        $challenge2 = $this->subject->generateChallenge();

        self::assertNotSame($challenge1, $challenge2);
    }

    #[Test]
    public function createChallengeTokenReturnsBase64EncodedString(): void
    {
        $this->nonceCacheMock
            ->expects(self::once())
            ->method('set')
            ->with(
                self::matchesRegularExpression('/^nonce_[a-zA-Z0-9]+$/'),
                'valid',
                [],
                180, // 120 TTL + 60 buffer
            );

        $challenge = \random_bytes(32);
        $token = $this->subject->createChallengeToken($challenge);

        // Must be valid base64
        $decoded = \base64_decode($token, true);
        self::assertNotFalse($decoded, 'Token must be valid base64');

        // Decoded token has format: base64(challenge)|expiresAt|nonce|hmac
        $parts = \explode('|', $decoded);
        self::assertCount(4, $parts, 'Decoded token must have 4 pipe-separated parts');

        // First part is base64-encoded challenge
        $restoredChallenge = \base64_decode($parts[0], true);
        self::assertSame($challenge, $restoredChallenge);

        // Second part is an integer timestamp in the future
        $expiresAt = (int) $parts[1];
        self::assertGreaterThan(\time(), $expiresAt);
        self::assertLessThanOrEqual(\time() + 120, $expiresAt);

        // Third part is a hex nonce (32 hex chars = 16 bytes)
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $parts[2]);

        // Fourth part is the HMAC (sha256 produces 64 hex chars)
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $parts[3]);
    }

    #[Test]
    public function verifyChallengeTokenWithValidToken(): void
    {
        $challenge = \random_bytes(32);

        // createChallengeToken stores the nonce
        $this->nonceCacheMock
            ->method('set');

        $token = $this->subject->createChallengeToken($challenge);

        // verifyChallengeToken checks nonce existence via get() and removes it
        $this->nonceCacheMock
            ->method('get')
            ->willReturn('valid');

        $this->nonceCacheMock
            ->expects(self::once())
            ->method('remove');

        $result = $this->subject->verifyChallengeToken($token);

        self::assertSame($challenge, $result);
    }

    #[Test]
    public function verifyChallengeTokenWithExpiredToken(): void
    {
        // Create a service with a very short TTL so the token is already expired
        $configService = $this->createConfigService(['challengeTtlSeconds' => -1]);
        $service = new ChallengeService($this->nonceCacheMock, $configService);

        $challenge = \random_bytes(32);
        $token = $service->createChallengeToken($challenge);

        // The HMAC is still valid, but the expiry timestamp is in the past
        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(1700000004);
        $this->expectExceptionMessage('Challenge token expired');

        $service->verifyChallengeToken($token);
    }

    #[Test]
    public function verifyChallengeTokenWithTamperedToken(): void
    {
        $challenge = \random_bytes(32);
        $token = $this->subject->createChallengeToken($challenge);

        // Decode the token, tamper with it, re-encode
        $decoded = \base64_decode($token, true);
        self::assertNotFalse($decoded);

        $parts = \explode('|', $decoded);
        // Tamper with the challenge portion
        $parts[0] = \base64_encode(\random_bytes(32));
        $tampered = \base64_encode(\implode('|', $parts));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(1700000003);
        $this->expectExceptionMessage('Challenge token signature invalid');

        $this->subject->verifyChallengeToken($tampered);
    }

    #[Test]
    public function verifyChallengeTokenWithReplayedNonce(): void
    {
        $challenge = \random_bytes(32);
        $token = $this->subject->createChallengeToken($challenge);

        // Nonce is NOT in cache (already consumed or expired) -- get() returns false
        $this->nonceCacheMock
            ->method('get')
            ->willReturn(false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(1700000005);
        $this->expectExceptionMessage('Challenge nonce already used or expired');

        $this->subject->verifyChallengeToken($token);
    }

    #[Test]
    public function verifyChallengeTokenWithInvalidEncoding(): void
    {
        // Not valid base64 at all (contains characters invalid in strict base64)
        $invalidToken = '!!!not-base64!!!';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(1700000001);
        $this->expectExceptionMessage('Invalid challenge token encoding');

        $this->subject->verifyChallengeToken($invalidToken);
    }

    #[Test]
    public function verifyChallengeTokenWithInvalidFormat(): void
    {
        // Valid base64, but decoded content does not have 4 pipe-separated parts
        $invalidPayload = \base64_encode('only-one-part');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(1700000002);
        $this->expectExceptionMessage('Invalid challenge token format');

        $this->subject->verifyChallengeToken($invalidPayload);
    }

    #[Test]
    public function verifyChallengeTokenWithThreePartsIsInvalid(): void
    {
        $invalidPayload = \base64_encode('part1|part2|part3');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(1700000002);

        $this->subject->verifyChallengeToken($invalidPayload);
    }

    #[Test]
    public function verifyChallengeTokenWithFivePartsIsInvalid(): void
    {
        $invalidPayload = \base64_encode('part1|part2|part3|part4|part5');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(1700000002);

        $this->subject->verifyChallengeToken($invalidPayload);
    }

    #[Test]
    public function createChallengeTokenThrowsWhenEncryptionKeyIsEmpty(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = '';
        $challenge = \random_bytes(32);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(1700000050);
        $this->expectExceptionMessage('encryptionKey is missing or too short');

        $this->subject->createChallengeToken($challenge);
    }

    #[Test]
    public function createChallengeTokenThrowsWhenEncryptionKeyIsTooShort(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = 'short';
        $challenge = \random_bytes(32);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(1700000050);

        $this->subject->createChallengeToken($challenge);
    }

    #[Test]
    public function verifyChallengeTokenThrowsWhenEncryptionKeyIsEmpty(): void
    {
        // Create a valid token first
        $challenge = \random_bytes(32);
        $this->nonceCacheMock->method('set');
        $token = $this->subject->createChallengeToken($challenge);

        // Now remove the encryption key
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = '';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(1700000050);

        $this->subject->verifyChallengeToken($token);
    }

    #[Test]
    public function verifyChallengeTokenThrowsWhenChallengeDataIsNotValidBase64(): void
    {
        // Craft a token with invalid base64 in the challenge portion
        $expiresAt = \time() + 120;
        $nonce = \bin2hex(\random_bytes(16));

        // Use a string that looks like base64 but decodes to something that makes
        // the inner base64_decode fail (not valid base64 in strict mode).
        // The challenge portion needs to not be valid strict base64.
        $invalidChallengeB64 = '!!!invalid-base64!!!';
        $payload = $invalidChallengeB64 . '|' . $expiresAt . '|' . $nonce;
        $key = $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'];
        $hmac = \hash_hmac('sha256', $payload, $key);

        // Store a valid nonce
        $this->nonceCacheMock
            ->method('get')
            ->willReturn('valid');

        $token = \base64_encode($payload . '|' . $hmac);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(1700000006);
        $this->expectExceptionMessage('Invalid challenge data in token');

        $this->subject->verifyChallengeToken($token);
    }

    #[Test]
    public function createChallengeTokenStoredNonceWithCorrectTtlBuffer(): void
    {
        $this->nonceCacheMock
            ->expects(self::once())
            ->method('set')
            ->with(
                self::matchesRegularExpression('/^nonce_[a-zA-Z0-9]+$/'),
                'valid',
                [],
                180, // 120 TTL + 60 buffer
            );

        $this->subject->createChallengeToken(\random_bytes(32));
    }

    #[Test]
    public function generateChallengeReturnsRawBytes(): void
    {
        $challenge = $this->subject->generateChallenge();

        // Should be raw bytes, not hex or base64
        self::assertSame(32, \strlen($challenge));
        // Raw bytes may contain non-printable characters
        self::assertIsString($challenge);
    }

    #[Test]
    public function verifyChallengeTokenRemovesNonceAfterVerification(): void
    {
        $challenge = \random_bytes(32);
        $this->nonceCacheMock->method('set');
        $token = $this->subject->createChallengeToken($challenge);

        $this->nonceCacheMock
            ->method('get')
            ->willReturn('valid');

        $this->nonceCacheMock
            ->expects(self::once())
            ->method('remove')
            ->with(self::matchesRegularExpression('/^nonce_[a-zA-Z0-9]+$/'));

        $this->subject->verifyChallengeToken($token);
    }

    #[Test]
    public function createChallengeTokenProducesDifferentTokensForSameChallenge(): void
    {
        $challenge = \random_bytes(32);

        $token1 = $this->subject->createChallengeToken($challenge);
        $token2 = $this->subject->createChallengeToken($challenge);

        // Tokens should differ because nonces are random
        self::assertNotSame($token1, $token2);
    }
}
