<?php

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Authentication;

use Netresearch\NrPasskeysBe\Service\ExtensionConfigurationService;
use Netresearch\NrPasskeysBe\Service\RateLimiterService;
use Netresearch\NrPasskeysBe\Service\WebAuthnService;
use Psr\Log\NullLogger;
use RuntimeException;
use TYPO3\CMS\Core\Authentication\AbstractAuthenticationService;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Authentication service for passwordless login via Passkeys (WebAuthn).
 *
 * Priority: 80 (higher than SaltedPasswordService at 50)
 * - If passkey assertion data is present, verify and authenticate
 * - If no passkey data, pass through to next service (password)
 */
class PasskeyAuthenticationService extends AbstractAuthenticationService
{
    private ?WebAuthnService $webAuthnService = null;

    private ?ExtensionConfigurationService $configService = null;

    private ?RateLimiterService $rateLimiterService = null;

    public function getUser(): array|false
    {
        $loginData = $this->login;
        $username = (string) ($loginData['uname'] ?? '');

        // Check if this is a passkey login attempt
        $passkeyAssertion = $this->getPasskeyAssertionFromRequest();
        if ($passkeyAssertion === null) {
            // Not a passkey login - let other services handle it
            // But check if password login is disabled
            if ($this->getExtensionConfigService()->getConfiguration()->isDisablePasswordLogin()) {
                $this->getLogger()->info('Password login disabled, blocking non-passkey attempt', [
                    'username' => $username,
                ]);
                return false;
            }
            return false;
        }

        if ($username === '') {
            return false;
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
        $passkeyAssertion = $this->getPasskeyAssertionFromRequest();
        if ($passkeyAssertion === null) {
            // Not a passkey login attempt - pass to next service
            return 100;
        }

        $challengeToken = $this->getChallengeTokenFromRequest();
        if ($challengeToken === null) {
            $this->getLogger()->warning('Passkey assertion without challenge token');
            return 0;
        }

        $username = (string) ($this->login['uname'] ?? '');
        $ip = (string) (GeneralUtility::getIndpEnv('REMOTE_ADDR') ?: '');

        try {
            // Check lockout
            $this->getRateLimiterService()->checkLockout($username, $ip);

            // Verify the assertion
            $result = $this->getWebAuthnService()->verifyAssertionResponse(
                responseJson: $passkeyAssertion,
                challengeToken: $challengeToken,
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
                'ip' => $ip,
            ]);

            // Return 0 = authentication failed
            return 0;
        }
    }

    private function getPasskeyAssertionFromRequest(): ?string
    {
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        if ($request === null) {
            return $this->login['passkey_assertion'] ?? null;
        }

        $parsedBody = $request->getParsedBody();
        if (\is_array($parsedBody) && isset($parsedBody['passkey_assertion'])) {
            return (string) $parsedBody['passkey_assertion'];
        }

        return $this->login['passkey_assertion'] ?? null;
    }

    private function getChallengeTokenFromRequest(): ?string
    {
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        if ($request === null) {
            return $this->login['passkey_challenge_token'] ?? null;
        }

        $parsedBody = $request->getParsedBody();
        if (\is_array($parsedBody) && isset($parsedBody['passkey_challenge_token'])) {
            return (string) $parsedBody['passkey_challenge_token'];
        }

        return $this->login['passkey_challenge_token'] ?? null;
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
            $this->setLogger(new NullLogger());
        }

        \assert($this->logger instanceof \Psr\Log\LoggerInterface);

        return $this->logger;
    }
}
