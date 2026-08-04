<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Tests\Unit\Middleware;

use Netresearch\NrPasskeysBe\Middleware\PublicRouteResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Backend\Http\RouteDispatcher;
use TYPO3\CMS\Backend\Routing\Route;

#[CoversClass(PublicRouteResolver::class)]
final class PublicRouteResolverTest extends TestCase
{
    private PublicRouteResolver $subject;

    private RouteDispatcher&MockObject $dispatcher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dispatcher = $this->createMock(RouteDispatcher::class);
        $this->subject = new PublicRouteResolver($this->dispatcher);
    }

    #[Test]
    public function dispatchesPublicPasskeyLoginRoute(): void
    {
        $route = $this->createMock(Route::class);
        $route->method('getOption')
            ->willReturnCallback(static fn(string $option): mixed => match ($option) {
                'access' => 'public',
                '_identifier' => 'passkeys_login_options',
                default => null,
            });

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')
            ->with('route')
            ->willReturn($route);

        $expectedResponse = $this->createMock(ResponseInterface::class);
        $this->dispatcher
            ->expects(self::once())
            ->method('dispatch')
            ->with($request)
            ->willReturn($expectedResponse);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $response = $this->subject->process($request, $handler);

        self::assertSame($expectedResponse, $response);
    }

    #[Test]
    public function passesThroughWhenRouteIsNull(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')
            ->with('route')
            ->willReturn(null);

        $expectedResponse = $this->createMock(ResponseInterface::class);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())
            ->method('handle')
            ->with($request)
            ->willReturn($expectedResponse);

        $this->dispatcher->expects(self::never())->method('dispatch');

        $response = $this->subject->process($request, $handler);

        self::assertSame($expectedResponse, $response);
    }

    #[Test]
    public function passesThroughWhenRouteIsNotPublic(): void
    {
        $route = $this->createMock(Route::class);
        $route->method('getOption')
            ->willReturnCallback(static fn(string $option): mixed => match ($option) {
                'access' => 'something_else',
                '_identifier' => 'passkeys_login_options',
                default => null,
            });

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')
            ->with('route')
            ->willReturn($route);

        $expectedResponse = $this->createMock(ResponseInterface::class);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())
            ->method('handle')
            ->with($request)
            ->willReturn($expectedResponse);

        $this->dispatcher->expects(self::never())->method('dispatch');

        $response = $this->subject->process($request, $handler);

        self::assertSame($expectedResponse, $response);
    }

    #[Test]
    public function passesThroughWhenIdentifierDoesNotMatchPrefix(): void
    {
        $route = $this->createMock(Route::class);
        $route->method('getOption')
            ->willReturnCallback(static fn(string $option): mixed => match ($option) {
                'access' => 'public',
                '_identifier' => 'main',
                default => null,
            });

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')
            ->with('route')
            ->willReturn($route);

        $expectedResponse = $this->createMock(ResponseInterface::class);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())
            ->method('handle')
            ->with($request)
            ->willReturn($expectedResponse);

        $this->dispatcher->expects(self::never())->method('dispatch');

        $response = $this->subject->process($request, $handler);

        self::assertSame($expectedResponse, $response);
    }

    #[Test]
    public function passesThroughWhenIdentifierIsNotString(): void
    {
        $route = $this->createMock(Route::class);
        $route->method('getOption')
            ->willReturnCallback(static fn(string $option): mixed => match ($option) {
                'access' => 'public',
                '_identifier' => null,
                default => null,
            });

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')
            ->with('route')
            ->willReturn($route);

        $expectedResponse = $this->createMock(ResponseInterface::class);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())
            ->method('handle')
            ->with($request)
            ->willReturn($expectedResponse);

        $this->dispatcher->expects(self::never())->method('dispatch');

        $response = $this->subject->process($request, $handler);

        self::assertSame($expectedResponse, $response);
    }
}
