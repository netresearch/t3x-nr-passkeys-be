<?php

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Tests\Unit\Controller;

use Netresearch\NrPasskeysBe\Controller\JsonBodyTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;

#[CoversClass(JsonBodyTrait::class)]
final class JsonBodyTraitTest extends TestCase
{
    private object $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->subject = new class {
            use JsonBodyTrait;

            /**
             * Expose the private trait method for testing.
             *
             * @return array<string, mixed>
             */
            public function callGetJsonBody(ServerRequestInterface $request): array
            {
                return $this->getJsonBody($request);
            }
        };
    }

    #[Test]
    public function returnsParsedBodyWhenAlreadyArray(): void
    {
        $data = ['username' => 'admin', 'password' => 'secret'];

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn($data);

        // getBody() should never be called when getParsedBody() returns an array
        $request->expects(self::never())->method('getBody');

        $result = $this->subject->callGetJsonBody($request);

        self::assertSame($data, $result);
    }

    #[Test]
    public function fallsBackToParsingRawJsonBody(): void
    {
        $data = ['action' => 'register', 'token' => 'abc123'];

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn(null);

        $stream = $this->createMock(StreamInterface::class);
        $stream->method('__toString')->willReturn(\json_encode($data, JSON_THROW_ON_ERROR));
        $request->method('getBody')->willReturn($stream);

        $result = $this->subject->callGetJsonBody($request);

        self::assertSame($data, $result);
    }

    #[Test]
    public function returnsEmptyArrayForEmptyBody(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn(null);

        $stream = $this->createMock(StreamInterface::class);
        $stream->method('__toString')->willReturn('');
        $request->method('getBody')->willReturn($stream);

        $result = $this->subject->callGetJsonBody($request);

        self::assertSame([], $result);
    }

    #[Test]
    public function returnsEmptyArrayForInvalidJson(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn(null);

        $stream = $this->createMock(StreamInterface::class);
        $stream->method('__toString')->willReturn('{not valid json!!!');
        $request->method('getBody')->willReturn($stream);

        $result = $this->subject->callGetJsonBody($request);

        self::assertSame([], $result);
    }

    #[Test]
    public function returnsEmptyArrayForNonArrayJson(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn(null);

        $stream = $this->createMock(StreamInterface::class);
        $stream->method('__toString')->willReturn('"just a string"');
        $request->method('getBody')->willReturn($stream);

        $result = $this->subject->callGetJsonBody($request);

        self::assertSame([], $result);
    }

    #[Test]
    public function returnsEmptyArrayForNumericJson(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn(null);
        $request->method('getHeaderLine')->willReturn('');

        $stream = $this->createMock(StreamInterface::class);
        $stream->method('__toString')->willReturn('42');
        $request->method('getBody')->willReturn($stream);

        $result = $this->subject->callGetJsonBody($request);

        self::assertSame([], $result);
    }

    #[Test]
    public function returnsEmptyArrayForNonJsonContentType(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn(null);
        $request->method('getHeaderLine')
            ->with('Content-Type')
            ->willReturn('text/plain');

        // Body should not be read when Content-Type is not JSON
        $request->expects(self::never())->method('getBody');

        $result = $this->subject->callGetJsonBody($request);

        self::assertSame([], $result);
    }

    #[Test]
    public function acceptsApplicationJsonContentType(): void
    {
        $data = ['key' => 'value'];

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn(null);
        $request->method('getHeaderLine')
            ->with('Content-Type')
            ->willReturn('application/json; charset=utf-8');

        $stream = $this->createMock(StreamInterface::class);
        $stream->method('__toString')->willReturn(\json_encode($data, JSON_THROW_ON_ERROR));
        $request->method('getBody')->willReturn($stream);

        $result = $this->subject->callGetJsonBody($request);

        self::assertSame($data, $result);
    }

    #[Test]
    public function rejectsDeeplyNestedJson(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn(null);
        $request->method('getHeaderLine')->willReturn('');

        // Create JSON nested 20 levels deep (exceeds depth limit of 16)
        $nested = '{"a":';
        for ($i = 0; $i < 18; $i++) {
            $nested .= '{"a":';
        }
        $nested .= '"v"';
        for ($i = 0; $i < 19; $i++) {
            $nested .= '}';
        }

        $stream = $this->createMock(StreamInterface::class);
        $stream->method('__toString')->willReturn($nested);
        $request->method('getBody')->willReturn($stream);

        $result = $this->subject->callGetJsonBody($request);

        self::assertSame([], $result);
    }
}
