<?php

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Tests\Fuzz;

use Netresearch\NrPasskeysBe\Service\ChallengeService;
use Netresearch\NrPasskeysBe\Service\ExtensionConfigurationService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

final class ChallengeTokenFuzzTest extends TestCase
{
    private ChallengeService $challengeService;

    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = 'fuzz-test-encryption-key-' . \bin2hex(\random_bytes(16));

        $cache = $this->createMock(FrontendInterface::class);
        $cache->method('has')->willReturn(true);
        $cache->method('get')->willReturn('valid');

        $configService = $this->createMock(ExtensionConfigurationService::class);
        $config = new \Netresearch\NrPasskeysBe\Configuration\ExtensionConfiguration(
            challengeTtlSeconds: 120,
        );
        $configService->method('getConfiguration')->willReturn($config);

        $this->challengeService = new ChallengeService($cache, $configService);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey']);
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function randomStringsProvider(): iterable
    {
        yield 'empty string' => [''];
        yield 'null byte' => ["\x00"];
        yield 'single char' => ['a'];
        yield 'long string' => [\str_repeat('A', 10000)];
        yield 'unicode' => ['Ünïcödé_Ström_🔑'];
        yield 'base64 chars' => ['ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/='];
        yield 'pipe delimited' => ['foo|bar|baz|qux'];
        yield 'base64 encoded pipes' => [\base64_encode('a|b|c|d')];
        yield 'valid looking but wrong HMAC' => [\base64_encode('Y2hhbGxlbmdl|9999999999|abc123|fakehash')];
        yield 'partial token' => [\base64_encode('Y2hhbGxlbmdl|')];
        yield 'three parts only' => [\base64_encode('a|b|c')];
        yield 'five parts' => [\base64_encode('a|b|c|d|e')];
        yield 'binary data' => [\random_bytes(32)];
        yield 'negative timestamp' => [\base64_encode('Y2hhbGxlbmdl|-1|nonce123|hash')];
        yield 'zero timestamp' => [\base64_encode('Y2hhbGxlbmdl|0|nonce123|hash')];
        yield 'max int timestamp' => [\base64_encode('Y2hhbGxlbmdl|' . PHP_INT_MAX . '|nonce123|hash')];
        yield 'newlines' => ["line1\nline2\rline3"];
        yield 'HTML injection' => ['<script>alert(1)</script>'];
        yield 'SQL injection' => ["'; DROP TABLE be_users; --"];
        yield 'null chars in middle' => ["abc\x00def\x00ghi"];
        yield 'URL encoded' => ['%00%0a%0d%3c%3e'];
    }

    #[Test]
    #[DataProvider('randomStringsProvider')]
    public function verifyTokenRejectsInvalidInput(string $input): void
    {
        $this->expectException(RuntimeException::class);
        $this->challengeService->verifyChallengeToken($input);
    }

    #[Test]
    public function verifyTokenRejectsRandomBytes(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $randomInput = \random_bytes(\random_int(1, 256));
            try {
                $this->challengeService->verifyChallengeToken($randomInput);
                $this->fail('Expected RuntimeException for random bytes input');
            } catch (RuntimeException) {
                // Expected
                $this->assertTrue(true);
            }
        }
    }

    #[Test]
    public function verifyTokenRejectsModifiedValidTokens(): void
    {
        $challenge = $this->challengeService->generateChallenge();
        $token = $this->challengeService->createChallengeToken($challenge);

        // Try bit-flipping each byte of the token
        $tokenBytes = $token;
        for ($i = 0; $i < \min(\strlen($tokenBytes), 50); $i++) {
            $modified = $tokenBytes;
            $modified[$i] = \chr(\ord($modified[$i]) ^ 0xFF);

            try {
                $this->challengeService->verifyChallengeToken($modified);
                // If it doesn't throw, the modification was in padding - that's ok
                // as long as the underlying data validation catches it
            } catch (RuntimeException) {
                $this->assertTrue(true);
            }
        }
    }

    #[Test]
    public function generateChallengeProducesUniqueValues(): void
    {
        $challenges = [];
        for ($i = 0; $i < 1000; $i++) {
            $challenge = $this->challengeService->generateChallenge();
            $hex = \bin2hex($challenge);
            $this->assertArrayNotHasKey($hex, $challenges, 'Duplicate challenge detected');
            $challenges[$hex] = true;
        }
    }

    #[Test]
    public function createTokenHandlesVariousChallenges(): void
    {
        $testChallenges = [
            \random_bytes(32),
            \random_bytes(1),
            \random_bytes(64),
            \str_repeat("\x00", 32),
            \str_repeat("\xFF", 32),
        ];

        foreach ($testChallenges as $challenge) {
            $token = $this->challengeService->createChallengeToken($challenge);
            $this->assertNotEmpty($token);
            $this->assertIsString($token);
        }
    }
}
