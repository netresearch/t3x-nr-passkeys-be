<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Controller;

use Netresearch\NrPasskeysBe\Service\ExtensionConfigurationService;
use Netresearch\NrPasskeysBe\Service\RateLimiterService;
use Netresearch\NrPasskeysBe\Service\WebAuthnService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class LoginController
{
    use JsonBodyTrait;

    public function __construct(
        private readonly WebAuthnService $webAuthnService,
        private readonly ExtensionConfigurationService $configService,
        private readonly RateLimiterService $rateLimiterService,
        private readonly ConnectionPool $connectionPool,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Generate assertion options for passkey login (Variant A: username-first).
     *
     * POST /passkeys/login/options
     * Body: { "username": "..." }
     */
    public function optionsAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->getJsonBody($request);
        $username = isset($body['username']) && \is_scalar($body['username'])
            ? (string) $body['username']
            : '';

        $ip = $this->getRemoteAddress($request);

        // Discoverable (usernameless) login
        if ($username === '') {
            if (!$this->configService->getConfiguration()->isDiscoverableLoginEnabled()) {
                return new JsonResponse(['error' => 'Username is required'], 400);
            }

            try {
                $this->rateLimiterService->consumeRateLimit('login_options', $ip);
            } catch (RuntimeException) {
                return new JsonResponse(['error' => 'Too many requests'], 429, ['Retry-After' => '60']);
            }

            try {
                $result = $this->webAuthnService->createDiscoverableAssertionOptions();

                $optionsJson = $this->webAuthnService->serializeRequestOptions($result->options);

                return new JsonResponse([
                    'options' => \json_decode($optionsJson, true, 512, JSON_THROW_ON_ERROR),
                    'challengeToken' => $result->challengeToken,
                ]);
            } catch (Throwable $e) {
                $this->logger->error('Failed to generate discoverable assertion options', [
                    'error' => $e->getMessage(),
                ]);

                return new JsonResponse(['error' => 'Internal error'], 500);
            }
        }

        try {
            // consumeRateLimit counts this attempt up front (atomic check+increment),
            // so an attempt that is subsequently lockout-rejected still consumes
            // per-IP rate-limit budget. This is intentional: it is still an attempt.
            $this->rateLimiterService->consumeRateLimit('login_options', $ip);
            $this->rateLimiterService->checkLockout($username, $ip);
        } catch (RuntimeException $e) {
            return $this->throttledResponse($e);
        }

        // Look up user. To prevent username enumeration, an unknown user receives a
        // DECOY options response with the SAME shape and HTTP 200 status as a real
        // user (deterministic per-username decoy credentials). A later assertion
        // against the decoy fails exactly as a wrong passkey would.
        $beUserUid = $this->findBeUserUid($username);
        if ($beUserUid === null) {
            // Short randomized sleep to further normalize timing.
            \usleep(\random_int(50000, 150000));

            try {
                $result = $this->webAuthnService->createDecoyAssertionOptions($username);
                $optionsJson = $this->webAuthnService->serializeRequestOptions($result->options);

                return new JsonResponse([
                    'options' => \json_decode($optionsJson, true, 512, JSON_THROW_ON_ERROR),
                    'challengeToken' => $result->challengeToken,
                ]);
            } catch (Throwable $e) {
                $this->logger->error('Failed to generate decoy assertion options', [
                    'error' => $e->getMessage(),
                ]);

                return new JsonResponse(['error' => 'Internal error'], 500);
            }
        }

        try {
            $result = $this->webAuthnService->createAssertionOptions($username, $beUserUid);

            $optionsJson = $this->webAuthnService->serializeRequestOptions($result->options);

            return new JsonResponse([
                'options' => \json_decode($optionsJson, true, 512, JSON_THROW_ON_ERROR),
                'challengeToken' => $result->challengeToken,
            ]);
        } catch (Throwable $e) {
            $this->logger->error('Failed to generate assertion options', [
                'error' => $e->getMessage(),
            ]);

            return new JsonResponse(['error' => 'Internal error'], 500);
        }
    }

    /**
     * Verify an assertion and, on success, issue a short-lived single-use login
     * token that the JS submits through the standard login form; the auth
     * service consumes the token instead of re-verifying the assertion (the
     * challenge is single-use, so it cannot be verified twice).
     *
     * Returning a structured result lets the client drive the WebAuthn Signal
     * API: on the DISCOVERABLE path, a credential ID that is not in the store is
     * reported as reason "unknown_credential" so the authenticator can prune the
     * orphaned passkey. The username-first path never sets that reason — the
     * response for "unknown user" and "known user, unknown credential" must be
     * identical, otherwise the decoy (username-enumeration) defence becomes an
     * oracle.
     *
     * POST /passkeys/login/verify
     * Body: { "assertion": {...}, "challengeToken": "...", "username"?: "..." }
     */
    public function verifyAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->getJsonBody($request);
        $username = isset($body['username']) && \is_scalar($body['username'])
            ? (string) $body['username']
            : '';
        $assertion = isset($body['assertion']) && \is_array($body['assertion'])
            ? \json_encode($body['assertion'], JSON_THROW_ON_ERROR)
            : '';
        $challengeToken = isset($body['challengeToken']) && \is_scalar($body['challengeToken'])
            ? (string) $body['challengeToken']
            : '';

        if ($assertion === '' || $challengeToken === '') {
            return new JsonResponse(['error' => 'Missing required fields'], 400);
        }

        $ip = $this->getRemoteAddress($request);

        try {
            $this->rateLimiterService->consumeRateLimit('login_verify', $ip);
            if ($username !== '') {
                $this->rateLimiterService->checkLockout($username, $ip);
            }
        } catch (RuntimeException $e) {
            return $this->throttledResponse($e);
        }

        return $username === ''
            ? $this->verifyDiscoverable($assertion, $challengeToken, $ip)
            : $this->verifyUsernameFirst($username, $assertion, $challengeToken, $ip);
    }

    /**
     * Discoverable (usernameless) verify. A credential ID that resolves to no
     * user is a genuine unknown credential and is reported to the Signal API.
     */
    private function verifyDiscoverable(string $assertion, string $challengeToken, string $ip): ResponseInterface
    {
        if (!$this->configService->getConfiguration()->isDiscoverableLoginEnabled()) {
            return new JsonResponse(['error' => 'Username is required'], 400);
        }

        // Resolve the user from the credential ID alone (no signature check yet).
        // A miss means the authenticator offered a passkey this server does not
        // know — safe to report: no username is involved, so no enumeration oracle.
        $beUserUid = $this->webAuthnService->findBeUserUidFromAssertion($assertion);
        if ($beUserUid === null) {
            \usleep(\random_int(50000, 150000));

            return new JsonResponse(['error' => 'Authentication failed', 'reason' => 'unknown_credential'], 401);
        }

        try {
            $this->webAuthnService->verifyAssertionResponse(
                responseJson: $assertion,
                challengeToken: $challengeToken,
                beUserUid: $beUserUid,
            );
        } catch (RuntimeException $e) {
            $this->rateLimiterService->recordFailure('', $ip, countUserLockout: false);
            $this->logger->warning('Passkey discoverable verification failed', [
                'ip' => $ip,
                'error_code' => $e->getCode(),
            ]);

            return new JsonResponse(['error' => 'Authentication failed'], 401);
        }

        $this->rateLimiterService->recordSuccess('', $ip);

        return $this->issueLoginToken($beUserUid);
    }

    /**
     * Username-first verify. Never returns a reason: the response for an unknown
     * username and for a known user with an unknown credential must be identical
     * to keep the decoy anti-enumeration defence intact.
     */
    private function verifyUsernameFirst(string $username, string $assertion, string $challengeToken, string $ip): ResponseInterface
    {
        $beUserUid = $this->findBeUserUid($username);
        if ($beUserUid === null) {
            \usleep(\random_int(50000, 150000));

            return new JsonResponse(['error' => 'Authentication failed'], 401);
        }

        try {
            $this->webAuthnService->verifyAssertionResponse(
                responseJson: $assertion,
                challengeToken: $challengeToken,
                beUserUid: $beUserUid,
            );
        } catch (RuntimeException $e) {
            // Do not feed the cross-IP per-username lockout (passkey assertions are
            // unforgeable; counting them only enables an account-lockout DoS).
            $this->rateLimiterService->recordFailure($username, $ip, countUserLockout: false);

            $this->logger->warning('Passkey assertion verification failed', [
                'username_hash' => \hash('sha256', $username),
                'ip' => $ip,
                'error_code' => $e->getCode(),
            ]);

            return new JsonResponse(['error' => 'Authentication failed'], 401);
        }

        $this->rateLimiterService->recordSuccess($username, $ip);

        return $this->issueLoginToken($beUserUid);
    }

    /**
     * Store a single-use login token (120s TTL) mapping to the verified backend
     * user and return it. The JS submits it through the login form; the auth
     * service resolves + consumes it. The token proves a completed WebAuthn
     * ceremony without re-spending the single-use challenge.
     */
    private function issueLoginToken(int $beUserUid): ResponseInterface
    {
        $token = \bin2hex(\random_bytes(32));
        $cache = GeneralUtility::makeInstance(CacheManager::class)->getCache('nr_passkeys_be_nonce');
        $cache->set('passkey_login_' . $token, (string) $beUserUid, [], 120);

        return new JsonResponse(['status' => 'ok', 'loginToken' => $token]);
    }

    /**
     * Build a 429 response distinguishing an account lockout from transient rate
     * limiting (UX-3). The lockout exception carries code 1700000011; the client
     * uses the 'locked' flag to show the dedicated "account locked" message.
     */
    private function throttledResponse(RuntimeException $e): ResponseInterface
    {
        $locked = $e->getCode() === 1700000011;

        return new JsonResponse(
            [
                'error' => $locked
                    ? 'Account temporarily locked. Please contact your administrator.'
                    : 'Too many requests. Please try again later.',
                'locked' => $locked,
            ],
            429,
            ['Retry-After' => '60'],
        );
    }

    private function findBeUserUid(string $username): ?int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('be_users');
        $row = $queryBuilder
            ->select('uid')
            ->from('be_users')
            ->where(
                $queryBuilder->expr()->eq(
                    'username',
                    $queryBuilder->createNamedParameter($username),
                ),
                $queryBuilder->expr()->eq('disable', 0),
                $queryBuilder->expr()->eq('deleted', 0),
            )
            ->executeQuery()
            ->fetchAssociative();

        if ($row === false) {
            return null;
        }

        $rawUid = $row['uid'] ?? null;

        return \is_numeric($rawUid) ? (int) $rawUid : null;
    }

    /**
     * Resolve the remote address from the request, with $_SERVER fallback.
     */
    private function getRemoteAddress(ServerRequestInterface $request): string
    {
        $params = $request->getAttribute('normalizedParams');
        if ($params instanceof NormalizedParams) {
            return $params->getRemoteAddress();
        }

        $confVars = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        $sysConf = \is_array($confVars) && isset($confVars['SYS']) && \is_array($confVars['SYS'])
            ? $confVars['SYS']
            : [];

        return NormalizedParams::createFromServerParams($_SERVER, $sysConf)->getRemoteAddress();
    }

}
