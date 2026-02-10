<?php

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Tests\Fuzz;

use Netresearch\NrPasskeysBe\Configuration\ExtensionConfiguration;
use Netresearch\NrPasskeysBe\Controller\LoginController;
use Netresearch\NrPasskeysBe\Service\ExtensionConfigurationService;
use Netresearch\NrPasskeysBe\Service\RateLimiterService;
use Netresearch\NrPasskeysBe\Service\WebAuthnService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Throwable;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class RequestPayloadFuzzTest extends TestCase
{
    private LoginController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $webAuthnService = $this->createMock(WebAuthnService::class);
        $webAuthnService->method('createDiscoverableAssertionOptions')
            ->willThrowException(new RuntimeException('Fuzz: no real WebAuthn context'));
        $webAuthnService->method('createAssertionOptions')
            ->willThrowException(new RuntimeException('Fuzz: no real WebAuthn context'));
        $configService = $this->createMock(ExtensionConfigurationService::class);
        $configService->method('getConfiguration')->willReturn(new ExtensionConfiguration());
        $rateLimiter = $this->createMock(RateLimiterService::class);
        $connectionPool = $this->createMock(ConnectionPool::class);

        $this->controller = new LoginController(
            $webAuthnService,
            $configService,
            $rateLimiter,
            $connectionPool,
            new NullLogger(),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedJsonProvider(): iterable
    {
        yield 'empty string' => [''];
        yield 'not json' => ['not json at all'];
        yield 'partial json' => ['{"username":'];
        yield 'array instead of object' => ['[1,2,3]'];
        yield 'null' => ['null'];
        yield 'number' => ['42'];
        yield 'string' => ['"just a string"'];
        yield 'boolean' => ['true'];
        yield 'deeply nested' => [\str_repeat('{"a":', 100) . '1' . \str_repeat('}', 100)];
        yield 'huge string value' => ['{"username":"' . \str_repeat('A', 100000) . '"}'];
        yield 'unicode username' => ['{"username":"ünïcödé_üser_🔑"}'];
        yield 'null username' => ['{"username":null}'];
        yield 'integer username' => ['{"username":42}'];
        yield 'array username' => ['{"username":["admin"]}'];
        yield 'object username' => ['{"username":{"name":"admin"}}'];
        yield 'empty username' => ['{"username":""}'];
        yield 'whitespace username' => ['{"username":"   "}'];
        yield 'sql injection username' => ['{"username":"\'; DROP TABLE be_users; --"}'];
        yield 'xss username' => ['{"username":"<script>alert(1)</script>"}'];
        yield 'null bytes in username' => ['{"username":"admin\u0000evil"}'];
        yield 'path traversal' => ['{"username":"../../etc/passwd"}'];
        yield 'extra fields' => ['{"username":"admin","extra":"malicious","__proto__":{"polluted":true}}'];
        yield 'binary in json' => ["\x00\x01\x02\x03"];
    }

    #[Test]
    #[DataProvider('malformedJsonProvider')]
    public function optionsActionHandlesMalformedInput(string $body): void
    {
        $request = $this->createRequestWithBody($body);
        $response = $this->controller->optionsAction($request);

        $this->assertInstanceOf(ResponseInterface::class, $response);
        $statusCode = $response->getStatusCode();
        $this->assertContains($statusCode, [400, 401, 429, 500]);
    }

    #[Test]
    #[DataProvider('malformedJsonProvider')]
    public function verifyActionHandlesMalformedInput(string $body): void
    {
        $request = $this->createRequestWithBody($body);
        $response = $this->controller->verifyAction($request);

        $this->assertInstanceOf(ResponseInterface::class, $response);
        $statusCode = $response->getStatusCode();
        $this->assertContains($statusCode, [400, 401, 429, 500]);
    }

    #[Test]
    public function optionsActionHandlesRandomBytes(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $randomBody = \random_bytes(\random_int(1, 1024));
            $request = $this->createRequestWithBody($randomBody);

            try {
                $response = $this->controller->optionsAction($request);
                $this->assertInstanceOf(ResponseInterface::class, $response);
            } catch (Throwable $e) {
                // Any unhandled exception is a bug
                $this->fail('Unhandled exception for random input: ' . $e->getMessage());
            }
        }
    }

    private function createRequestWithBody(string $body): ServerRequestInterface
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('__toString')->willReturn($body);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn(null);
        $request->method('getBody')->willReturn($stream);

        return $request;
    }
}
