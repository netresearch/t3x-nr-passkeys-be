<?php

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
class PublicRouteResolver implements MiddlewareInterface
{
    private const PASSKEYS_ROUTE_PREFIX = 'passkeys_login_';

    public function __construct(
        private readonly RouteDispatcher $dispatcher,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        /** @var Route|null $route */
        $route = $request->getAttribute('route');

        if ($route !== null
            && $route->getOption('access') === 'public'
            && \is_string($route->getOption('_identifier'))
            && \str_starts_with($route->getOption('_identifier'), self::PASSKEYS_ROUTE_PREFIX)
        ) {
            return $this->dispatcher->dispatch($request);
        }

        return $handler->handle($request);
    }
}
