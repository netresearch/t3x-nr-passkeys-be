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

class LoginController
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

        if ($username === '') {
            return new JsonResponse(['error' => 'Username is required'], 400);
        }

        $ip = (string) (GeneralUtility::getIndpEnv('REMOTE_ADDR') ?: '');

        try {
            $this->rateLimiterService->checkRateLimit('login_options', $ip);
            $this->rateLimiterService->checkLockout($username, $ip);
        } catch (RuntimeException $e) {
            return new JsonResponse(['error' => 'Too many requests'], 429);
        }

        $this->rateLimiterService->recordAttempt('login_options', $ip);

        // Look up user - return generic response for unknown users to prevent enumeration
        $user = $this->findBeUser($username);
        if ($user === null) {
            // Return a dummy response to prevent user enumeration
            // Use a short sleep to normalize timing
            \usleep(\random_int(50000, 150000));

            return new JsonResponse([
                'error' => 'Authentication failed',
            ], 401);
        }

        try {
            $result = $this->webAuthnService->createAssertionOptions($username, (int) $user['uid']);

            $optionsJson = $this->webAuthnService->serializeRequestOptions($result['options']);

            return new JsonResponse([
                'options' => \json_decode($optionsJson, true, 512, JSON_THROW_ON_ERROR),
                'challengeToken' => $result['challengeToken'],
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
        } catch (RuntimeException $e) {
            return new JsonResponse(['error' => 'Too many requests'], 429);
        }

        $this->rateLimiterService->recordAttempt('login_verify', $ip);

        $user = $this->findBeUser($username);
        if ($user === null) {
            \usleep(\random_int(50000, 150000));
            return new JsonResponse(['error' => 'Authentication failed'], 401);
        }

        try {
            $this->webAuthnService->verifyAssertionResponse(
                responseJson: $assertion,
                challengeToken: $challengeToken,
                beUserUid: (int) $user['uid'],
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

    /**
     * @return array<string, mixed>|null
     */
    private function findBeUser(string $username): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('be_users');
        $row = $queryBuilder
            ->select('*')
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

        return $row !== false ? $row : null;
    }

}
