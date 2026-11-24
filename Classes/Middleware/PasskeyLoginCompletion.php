<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Middleware;

use Netresearch\NrPasskeysBe\Authentication\PasskeyLoginProofStore;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Backend\Routing\RouteRedirect;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Authentication\Mfa\MfaProviderRegistry;

use function in_array;
use function is_array;
use function is_numeric;
use function parse_url;

use const PHP_URL_PATH;

/**
 * Completes a passkey login after the core authentication has run.
 *
 * TYPO3 runs the authentication services before it creates the session of a
 * new login and checks the MFA requirement right after creating it, without an
 * event in between. On a fresh browser session, data the passkey service would
 * write during authentication is lost, so this middleware wraps the core
 * authentication and writes the result of the passkey login recorded in the
 * PasskeyLoginProofStore into the session the core created:
 *
 * - always the passkey marker read by the setup interstitial;
 * - the "mfa" key only under the conditions of mayCreditMfa(): a verified
 *   passkey of a user who already has an active, unlocked MFA provider. A
 *   passkey stands in for the second factor the user has set up; it never
 *   replaces setting one up. With BE.requireMfa a user without a provider is
 *   therefore still sent to the MFA setup, so the password fallback of that
 *   account gets its own second factor.
 *
 * When "mfa" is written and the core already answered with a redirect to the
 * MFA challenge, that redirect is replaced by the login target the core
 * MfaController uses after a successful verification.
 */
final readonly class PasskeyLoginCompletion implements MiddlewareInterface
{
    private const MFA_ROUTES = ['auth_mfa', 'setup_mfa'];

    public function __construct(
        private PasskeyLoginProofStore $proofs,
        private UriBuilder $uriBuilder,
        private MfaProviderRegistry $mfaProviderRegistry,
        private LoggerInterface $logger,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $user  = null;
        $proof = null;

        try {
            $response = $handler->handle($request);
        } finally {
            // Take the proof even when the inner stack throws, so it never
            // outlives the request that recorded it.
            $user  = $GLOBALS['BE_USER'] ?? null;
            $proof = $user instanceof BackendUserAuthentication ? $this->proofs->take($user) : null;
        }

        if ($user instanceof BackendUserAuthentication && $proof !== null && $this->sessionBelongsTo($user, $proof['uid'])) {
            $response = $this->complete($request, $response, $user, $proof['creditMfa']);
        }

        return $response;
    }

    /**
     * Write the passkey marker and, where allowed, the MFA credit into the
     * session the core created, and send the user past an MFA redirect.
     */
    private function complete(
        ServerRequestInterface $request,
        ResponseInterface $response,
        BackendUserAuthentication $user,
        bool $creditMfa,
    ): ResponseInterface {
        $stored                        = $user->getSessionData('tx_nrpasskeysbe');
        $data                          = is_array($stored) ? $stored : [];
        $data['passkey_authenticated'] = true;
        $user->setAndSaveSessionData('tx_nrpasskeysbe', $data);

        if (!$this->mayCreditMfa($request, $user, $creditMfa)) {
            return $response;
        }

        $user->setAndSaveSessionData('mfa', true);
        $this->logger->info('Passkey login credited towards MFA', ['be_user_uid' => $user->getUserId()]);

        if (!$this->redirectsToMfa($response)) {
            return $response;
        }

        // Only the target changes: status and the session cookie the core
        // authentication attached to the response stay as they are.
        return $response->withHeader(
            'Location',
            (string) $this->uriBuilder->buildUriWithRedirect('login', [], $this->routeRedirect($request)),
        );
    }

    /**
     * The passkey stands in for the second factor only where the core would
     * otherwise ask for it interactively:
     *
     * - the passkey service credited MFA (user verification, setting enabled);
     * - the user has an active MFA provider: a passkey never replaces setting
     *   one up, so requireMfa still sends users without a provider to the setup;
     * - no provider is locked: a provider locked after failed attempts keeps the
     *   account blocked until an administrator unlocks it;
     * - not an AJAX route: a login there keeps the core behaviour. With
     *   requireMfa the core answers a bare 401 without a session cookie;
     *   without it the next request asks for the second factor.
     */
    private function mayCreditMfa(
        ServerRequestInterface $request,
        BackendUserAuthentication $user,
        bool $creditMfa,
    ): bool {
        $route = $request->getAttribute('route');

        if (!$creditMfa || $route instanceof Route && (bool) ($route->getOption('ajax') ?? false)) {
            return false;
        }

        return $this->mfaProviderRegistry->hasActiveProviders($user) && !$this->mfaProviderRegistry->hasLockedProviders($user);
    }

    private function sessionBelongsTo(BackendUserAuthentication $user, int $uid): bool
    {
        $userUid = is_array($user->user) && is_numeric($user->user['uid'] ?? null) ? (int) $user->user['uid'] : 0;

        return $uid > 0 && $userUid === $uid && $user
            ->getSession()
            ->getUserId() === $uid;
    }

    /**
     * The same target the core BackendUserAuthenticator hands to the MFA
     * endpoint: the redirect parameter of the request, if any.
     */
    private function routeRedirect(ServerRequestInterface $request): ?RouteRedirect
    {
        return RouteRedirect::createFromRequest($request);
    }

    private function redirectsToMfa(ResponseInterface $response): bool
    {
        $status = $response->getStatusCode();

        if ($status < 300 || $status >= 400) {
            return false;
        }

        $location = parse_url($response->getHeaderLine('Location'), PHP_URL_PATH);

        if (in_array($location, [null, false, ''], true)) {
            return false;
        }

        $mfaPaths = [];

        foreach (self::MFA_ROUTES as $route) {
            $mfaPaths[] = parse_url((string) $this->uriBuilder->buildUriFromRoute($route), PHP_URL_PATH);
        }

        return in_array($location, $mfaPaths, true);
    }
}
