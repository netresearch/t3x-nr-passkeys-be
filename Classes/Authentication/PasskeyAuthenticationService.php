<?php

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Authentication;

use Doctrine\DBAL\ParameterType;
use JsonException;
use Netresearch\NrPasskeysBe\Service\ExtensionConfigurationService;
use Netresearch\NrPasskeysBe\Service\RateLimiterService;
use Netresearch\NrPasskeysBe\Service\WebAuthnService;
use Psr\Log\NullLogger;
use RuntimeException;
use Throwable;
use TYPO3\CMS\Core\Authentication\AbstractAuthenticationService;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Authentication service for passwordless login via Passkeys (WebAuthn).
 *
 * Priority: 80 (higher than SaltedPasswordService at 50)
 * - If passkey assertion data is present in uident, verify and authenticate
 * - If no passkey data, pass through to next service (password)
 *
 * The passkey assertion and challenge token are packed into the standard
 * userident form field as JSON with _type="passkey". This is necessary
 * because $GLOBALS['TYPO3_REQUEST'] is not available during the auth
 * service chain, so custom POST fields are inaccessible. The uident
 * field is the standard TYPO3 mechanism for passing auth credentials.
 */
class PasskeyAuthenticationService extends AbstractAuthenticationService
{
    private ?WebAuthnService $webAuthnService = null;

    private ?ExtensionConfigurationService $configService = null;

    private ?RateLimiterService $rateLimiterService = null;

    /**
     * Decoded passkey payload from uident, cached per request.
     *
     * @var array{assertion: string, challengeToken: string}|null|false false = not yet parsed
     */
    private array|null|false $passkeyPayload = false;

    public function getUser(): array|false
    {
        $loginData = $this->login;
        $username = (string) ($loginData['uname'] ?? '');

        $payload = $this->getPasskeyPayload();
        if ($payload === null) {
            // Not a passkey login - let other services handle it
            if ($this->getExtensionConfigService()->getConfiguration()->isDisablePasswordLogin()) {
                $this->getLogger()->warning('Password login disabled, blocking non-passkey attempt', [
                    'username' => $username,
                ]);
                return false;
            }
            return false;
        }

        $this->getLogger()->info('Passkey login attempt', [
            'username' => $username,
            'assertion_length' => \strlen($payload['assertion']),
        ]);

        if ($username === '') {
            // Discoverable login: resolve user from credential ID in the assertion
            $beUserUid = $this->getWebAuthnService()->findBeUserUidFromAssertion($payload['assertion']);
            if ($beUserUid === null) {
                $this->getLogger()->info('Discoverable login: could not resolve user from assertion');
                return false;
            }

            $user = $this->fetchUserByUid($beUserUid);
            if (!\is_array($user)) {
                $this->getLogger()->info('Discoverable login: user not found for resolved UID', [
                    'be_user_uid' => $beUserUid,
                ]);
                return false;
            }

            return $user;
        }

        // Look up the user by username
        $user = $this->fetchUserRecord($username);
        if (!\is_array($user)) {
            // Don't reveal whether user exists
            $this->getLogger()->info('Passkey login attempt for unknown user', [
                'username_hash' => \hash('sha256', $username),
            ]);
            return false;
        }

        return $user;
    }

    public function authUser(array $user): int
    {
        $payload = $this->getPasskeyPayload();
        if ($payload === null) {
            // Not a passkey login attempt - pass to next service
            return 100;
        }

        $username = (string) ($this->login['uname'] ?? '');
        $ip = (string) (GeneralUtility::getIndpEnv('REMOTE_ADDR') ?: '');

        try {
            // Check lockout
            $this->getRateLimiterService()->checkLockout($username, $ip);

            // Verify the assertion
            $result = $this->getWebAuthnService()->verifyAssertionResponse(
                responseJson: $payload['assertion'],
                challengeToken: $payload['challengeToken'],
                beUserUid: (int) $user['uid'],
            );

            // Clear lockout on success
            $this->getRateLimiterService()->recordSuccess($username, $ip);

            $this->getLogger()->info('Passkey authentication successful', [
                'be_user_uid' => $user['uid'],
                'username' => $username,
                'credential_uid' => $result['credential']->getUid(),
            ]);

            // Return 200 = authenticated, stop further auth processing
            return 200;
        } catch (RuntimeException $e) {
            $this->getRateLimiterService()->recordFailure($username, $ip);

            $this->getLogger()->warning('Passkey authentication failed', [
                'be_user_uid' => $user['uid'],
                'username' => $username,
                'error_code' => $e->getCode(),
                'error_message' => $e->getMessage(),
                'ip' => $ip,
            ]);

            // Return 0 = authentication failed
            return 0;
        }
    }

    /**
     * Extract and validate the passkey payload from the uident login field.
     *
     * The JS packs assertion + challengeToken into userident as JSON:
     * {"_type":"passkey","assertion":{...},"challengeToken":"..."}
     *
     * @return array{assertion: string, challengeToken: string}|null
     */
    private function getPasskeyPayload(): ?array
    {
        if ($this->passkeyPayload !== false) {
            return $this->passkeyPayload;
        }

        $this->passkeyPayload = null;

        $uident = (string) ($this->login['uident'] ?? '');
        if ($uident === '' || $uident[0] !== '{') {
            return null;
        }

        try {
            $data = \json_decode($uident, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!\is_array($data) || ($data['_type'] ?? '') !== 'passkey') {
            return null;
        }

        $assertion = $data['assertion'] ?? null;
        $challengeToken = $data['challengeToken'] ?? null;

        if (!\is_array($assertion) || !\is_string($challengeToken) || $challengeToken === '') {
            $this->getLogger()->warning('Passkey payload has invalid structure');
            return null;
        }

        $this->passkeyPayload = [
            'assertion' => \json_encode($assertion, JSON_THROW_ON_ERROR),
            'challengeToken' => $challengeToken,
        ];

        return $this->passkeyPayload;
    }

    /**
     * Fetch a be_users record by UID for discoverable login.
     *
     * @return array<string, mixed>|false
     */
    private function fetchUserByUid(int $uid): array|false
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('be_users');

        $row = $queryBuilder
            ->select('*')
            ->from('be_users')
            ->where(
                $queryBuilder->expr()->eq(
                    'uid',
                    $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER),
                ),
                $queryBuilder->expr()->eq('disable', 0),
                $queryBuilder->expr()->eq('deleted', 0),
            )
            ->executeQuery()
            ->fetchAssociative();

        return $row !== false ? $row : false;
    }

    private function getWebAuthnService(): WebAuthnService
    {
        if ($this->webAuthnService === null) {
            $this->webAuthnService = GeneralUtility::makeInstance(WebAuthnService::class);
        }

        return $this->webAuthnService;
    }

    private function getExtensionConfigService(): ExtensionConfigurationService
    {
        if ($this->configService === null) {
            $this->configService = GeneralUtility::makeInstance(ExtensionConfigurationService::class);
        }

        return $this->configService;
    }

    private function getRateLimiterService(): RateLimiterService
    {
        if ($this->rateLimiterService === null) {
            $this->rateLimiterService = GeneralUtility::makeInstance(RateLimiterService::class);
        }

        return $this->rateLimiterService;
    }

    private function getLogger(): \Psr\Log\LoggerInterface
    {
        if ($this->logger === null) {
            try {
                $this->setLogger(GeneralUtility::makeInstance(LogManager::class)->getLogger(static::class));
            } catch (Throwable) {
                $this->setLogger(new NullLogger());
            }
        }

        \assert($this->logger instanceof \Psr\Log\LoggerInterface);

        return $this->logger;
    }
}
