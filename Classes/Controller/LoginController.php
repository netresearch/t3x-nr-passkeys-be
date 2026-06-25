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
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\NormalizedParams;

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
     * Verify assertion is not needed as a separate endpoint.
     * The verification happens through the standard TYPO3 login form submission
     * with hidden fields (passkey_assertion + passkey_challenge_token).
     *
     * This endpoint exists for optional AJAX-only flow.
     *
     * POST /passkeys/login/verify
     */
    public function verifyAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->getJsonBody($request);
        $username = isset($body['username']) && \is_scalar($body['username'])
            ? (string) $body['username']
            : '';
        $assertion = isset($body['assertion']) && \is_scalar($body['assertion'])
            ? (string) $body['assertion']
            : '';
        $challengeToken = isset($body['challengeToken']) && \is_scalar($body['challengeToken'])
            ? (string) $body['challengeToken']
            : '';

        if ($username === '' || $assertion === '' || $challengeToken === '') {
            return new JsonResponse(['error' => 'Missing required fields'], 400);
        }

        $ip = $this->getRemoteAddress($request);

        try {
            $this->rateLimiterService->consumeRateLimit('login_verify', $ip);
            $this->rateLimiterService->checkLockout($username, $ip);
        } catch (RuntimeException $e) {
            return $this->throttledResponse($e);
        }

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

            $this->rateLimiterService->recordSuccess($username, $ip);

            return new JsonResponse(['status' => 'ok']);
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
