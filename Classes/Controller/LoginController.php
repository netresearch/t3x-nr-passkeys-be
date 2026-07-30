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

    /**
     * Generic auth-failure message. Deliberately identical across the unknown-user,
     * unknown-credential and verification-failure cases so the response cannot be
     * used as an enumeration oracle.
     */
    private const AUTH_FAILED = 'Authentication failed';

    /**
     * Seconds a freshly issued login token stays redeemable. Enforced by
     * PasskeyAuthenticationService against the expiresAt stored in the token value,
     * so it does not depend on the cache backend implementing lifetimes.
     */
    private const LOGIN_TOKEN_TTL = 120;

    /**
     * Wall-clock budget, in nanoseconds, that every username-first response is padded
     * to before it is returned (150 ms).
     *
     * A delay applied only to the unknown-username branch does not normalize timing —
     * it *is* the signal, and a larger one than the work it was meant to mask. Both
     * branches are padded to the same target instead, so response time carries no
     * information about whether the account exists. The budget must stay comfortably
     * above the real work (a be_users lookup plus challenge generation); a response
     * that exceeds it is returned immediately and would still be distinguishable.
     */
    private const TIMING_BUDGET_NS = 150_000_000;

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
        // against the decoy fails exactly as a wrong passkey would. Every branch
        // below is padded to the same wall-clock budget, so the response time does
        // not reveal which one ran.
        $startedAt = \hrtime(true);
        $beUserUid = $this->findBeUserUid($username);

        try {
            $result = $beUserUid === null
                ? $this->webAuthnService->createDecoyAssertionOptions($username)
                : $this->webAuthnService->createAssertionOptions($username, $beUserUid);

            $optionsJson = $this->webAuthnService->serializeRequestOptions($result->options);

            $response = new JsonResponse([
                'options' => \json_decode($optionsJson, true, 512, JSON_THROW_ON_ERROR),
                'challengeToken' => $result->challengeToken,
            ]);
        } catch (Throwable $e) {
            $this->logger->error('Failed to generate assertion options', [
                'decoy' => $beUserUid === null,
                'error' => $e->getMessage(),
            ]);

            $response = new JsonResponse(['error' => 'Internal error'], 500);
        }

        $this->padToTimingBudget($startedAt);

        return $response;
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

            return new JsonResponse(['error' => self::AUTH_FAILED, 'reason' => 'unknown_credential'], 401);
        }

        return $this->verifyAndIssueToken($assertion, $challengeToken, $beUserUid, '', $ip);
    }

    /**
     * Username-first verify. Never returns a reason: the response for an unknown
     * username and for a known user with an unknown credential must be identical
     * to keep the decoy anti-enumeration defence intact.
     */
    private function verifyUsernameFirst(string $username, string $assertion, string $challengeToken, string $ip): ResponseInterface
    {
        $startedAt = \hrtime(true);
        $beUserUid = $this->findBeUserUid($username);
        if ($beUserUid === null) {
            $this->padToTimingBudget($startedAt);

            return new JsonResponse(['error' => self::AUTH_FAILED], 401);
        }

        $response = $this->verifyAndIssueToken($assertion, $challengeToken, $beUserUid, $username, $ip);

        // Pad rejections only: a 200 already tells the caller the account exists, and
        // the failure paths are the ones an enumerating attacker can compare.
        if ($response->getStatusCode() !== 200) {
            $this->padToTimingBudget($startedAt);
        }

        return $response;
    }

    /**
     * Sleep until the elapsed time since $startedAt reaches TIMING_BUDGET_NS.
     *
     * $startedAt is an hrtime(true) reading (monotonic nanoseconds), so a clock
     * adjustment cannot shorten or extend the budget.
     */
    private function padToTimingBudget(int $startedAt): void
    {
        $remainingNs = self::TIMING_BUDGET_NS - (\hrtime(true) - $startedAt);
        if ($remainingNs > 0) {
            \usleep(\intdiv($remainingNs, 1000));
        }
    }

    /**
     * Verify the assertion signature for a resolved backend user and, on success,
     * issue the login token. Shared by the discoverable and username-first paths.
     * $username is '' for discoverable login.
     */
    private function verifyAndIssueToken(string $assertion, string $challengeToken, int $beUserUid, string $username, string $ip): ResponseInterface
    {
        try {
            $this->webAuthnService->verifyAssertionResponse(
                responseJson: $assertion,
                challengeToken: $challengeToken,
                beUserUid: $beUserUid,
            );
        } catch (Throwable $e) {
            // Throwable, not RuntimeException: webauthn-lib's deserializer throws
            // Webauthn\Exception\InvalidDataException, which extends \Exception, so a
            // structurally invalid assertion object used to escape this catch and
            // surface as an uncaught-exception 500 — skipping recordFailure() on the
            // way out, and leaking paths when debug output is on.
            //
            // Do not feed the cross-IP per-username lockout (passkey assertions are
            // unforgeable; counting them only enables an account-lockout DoS).
            $this->rateLimiterService->recordFailure($username, $ip, countUserLockout: false);

            $this->logger->warning('Passkey assertion verification failed', [
                'username_hash' => \hash('sha256', $username),
                'ip' => $ip,
                'error_code' => $e->getCode(),
                'error_class' => \get_class($e),
            ]);

            return new JsonResponse(['error' => self::AUTH_FAILED], 401);
        }

        $this->rateLimiterService->recordSuccess($username, $ip);

        return $this->issueLoginToken($beUserUid);
    }

    /**
     * Store a single-use login token mapping to the verified backend user and
     * return it. The JS submits it through the login form; the auth service
     * resolves + consumes it. The token proves a completed WebAuthn ceremony
     * without re-spending the single-use challenge.
     *
     * The expiry is written INTO the cached value, not left to the cache TTL: this
     * token authenticates a backend user, and a cache backend that ignores
     * lifetimes would otherwise turn it into a permanent credential. The TTL is
     * still passed so a compliant backend also drops the entry.
     */
    private function issueLoginToken(int $beUserUid): ResponseInterface
    {
        $token = \bin2hex(\random_bytes(32));
        $payload = \json_encode(
            ['uid' => $beUserUid, 'expiresAt' => \time() + self::LOGIN_TOKEN_TTL],
            JSON_THROW_ON_ERROR,
        );

        $cache = GeneralUtility::makeInstance(CacheManager::class)->getCache('nr_passkeys_be_nonce');
        $cache->set('passkey_login_' . $token, $payload, [], self::LOGIN_TOKEN_TTL);

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
