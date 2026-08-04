<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Backend\Http\RouteDispatcher;
use TYPO3\CMS\Backend\Routing\Route;

/**
 * Dispatches passkeys public routes before BackendUserAuthenticator.
 *
 * TYPO3's BackendUserAuthenticator checks a hardcoded $publicRoutes list
 * and ignores the route's 'access' => 'public' option. This causes
 * extension-registered public backend routes to get a 302 redirect to
 * the login page. This middleware short-circuits authentication for our
 * public passkeys endpoints by dispatching them directly.
 */
final readonly class PublicRouteResolver implements MiddlewareInterface
{
    private const PASSKEYS_ROUTE_PREFIX = 'passkeys_login_';

    public function __construct(
        private RouteDispatcher $dispatcher,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        /** @var Route|null $route */
        $route = $request->getAttribute('route');

        $identifier = $route?->getOption('_identifier');

        if ($route !== null
            && $route->getOption('access') === 'public'
            && \is_string($identifier)
            && \str_starts_with($identifier, self::PASSKEYS_ROUTE_PREFIX)
        ) {
            return $this->dispatcher->dispatch($request);
        }

        return $handler->handle($request);
    }
}
