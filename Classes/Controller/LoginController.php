<?php

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

        $ip = (string) (GeneralUtility::getIndpEnv('REMOTE_ADDR') ?: '');

        // Discoverable (usernameless) login
        if ($username === '') {
            if (!$this->configService->getConfiguration()->isDiscoverableLoginEnabled()) {
                return new JsonResponse(['error' => 'Username is required'], 400);
            }

            try {
                $this->rateLimiterService->checkRateLimit('login_options', $ip);
            } catch (RuntimeException) {
                return new JsonResponse(['error' => 'Too many requests'], 429, ['Retry-After' => '60']);
            }

            $this->rateLimiterService->recordAttempt('login_options', $ip);

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
            $this->rateLimiterService->checkRateLimit('login_options', $ip);
            $this->rateLimiterService->checkLockout($username, $ip);
        } catch (RuntimeException) {
            return new JsonResponse(['error' => 'Too many requests'], 429, ['Retry-After' => '60']);
        }

        $this->rateLimiterService->recordAttempt('login_options', $ip);

        // Look up user - return generic response for unknown users to prevent enumeration
        $beUserUid = $this->findBeUserUid($username);
        if ($beUserUid === null) {
            // Use a short sleep to normalize timing
            \usleep(\random_int(50000, 150000));

            return new JsonResponse([
                'error' => 'Authentication failed',
            ], 401);
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

        $ip = (string) (GeneralUtility::getIndpEnv('REMOTE_ADDR') ?: '');

        try {
            $this->rateLimiterService->checkRateLimit('login_verify', $ip);
            $this->rateLimiterService->checkLockout($username, $ip);
        } catch (RuntimeException) {
            return new JsonResponse(['error' => 'Too many requests'], 429, ['Retry-After' => '60']);
        }

        $this->rateLimiterService->recordAttempt('login_verify', $ip);

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
            $this->rateLimiterService->recordFailure($username, $ip);

            $this->logger->warning('Passkey assertion verification failed', [
                'username_hash' => \hash('sha256', $username),
                'ip' => $ip,
                'error_code' => $e->getCode(),
            ]);

            return new JsonResponse(['error' => 'Authentication failed'], 401);
        }
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

}
