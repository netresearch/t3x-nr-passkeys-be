<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Authentication;

use Doctrine\DBAL\ParameterType;
use JsonException;
use Netresearch\NrPasskeysBe\Domain\Enum\EnforcementLevel;
use Netresearch\NrPasskeysBe\Service\EnforcementService;
use Netresearch\NrPasskeysBe\Service\ExtensionConfigurationService;
use Netresearch\NrPasskeysBe\Service\RateLimiterService;
use Netresearch\NrPasskeysBe\Service\WebAuthnService;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\NullLogger;
use Throwable;
use TYPO3\CMS\Core\Authentication\AbstractAuthenticationService;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\NormalizedParams;
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

    private ?EnforcementService $enforcementService = null;

    /**
     * Decoded passkey payload from uident, cached per request.
     *
     * @var array{assertion: string, challengeToken: string}|null|false false = not yet parsed
     */
    private array|false|null $passkeyPayload = false;

    public function getUser(): array|false
    {
        $loginData = $this->login;
        $rawUsername = $loginData['uname'] ?? '';
        $username = \is_string($rawUsername) ? $rawUsername : '';

        $payload = $this->getPasskeyPayload();
        if ($payload === null) {
            // Not a passkey login - let other services handle it
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
            // Not a passkey login attempt - check per-user/per-group enforcement.
            //
            // Fail-open: any failure of the enforcement checks (e.g. a database
            // outage while querying credentials or be_groups) MUST NOT lock every
            // backend user out of password login. On error we log and fall through
            // to the password service (return 100). Legitimate blocks are explicit
            // `return 0` statements that are not affected by the catch.
            try {
                if ($this->getExtensionConfigService()->getConfiguration()->isDisablePasswordLogin()) {
                    $uid = \is_numeric($user['uid'] ?? null) ? (int) $user['uid'] : 0;
                    if ($uid > 0 && $this->hasRegisteredPasskeys($uid)) {
                        $this->getLogger()->warning('Password login blocked for user with registered passkeys', [
                            'be_user_uid' => $uid,
                        ]);

                        return 0;
                    }
                }

                // Per-group enforcement: block password login when the user's group demands passkeys
                /** @var array<string, mixed> $user TYPO3 backend user record from AbstractAuthenticationService */
                $status = $this->getEnforcementService()->getStatus($user);
                if ($status->hasPasskeys) {
                    if ($status->level === EnforcementLevel::Enforced) {
                        $this->getLogger()->warning('Password login blocked by group enforcement', [
                            'username' => $user['username'] ?? '',
                        ]);

                        return 0;
                    }

                    if ($status->level === EnforcementLevel::Required && $status->isGracePeriodExpired()) {
                        $this->getLogger()->warning('Password login blocked: grace period expired', [
                            'username' => $user['username'] ?? '',
                        ]);

                        return 0;
                    }
                }
            } catch (Throwable $e) {
                $this->getLogger()->error('Passkey enforcement check failed; allowing password login (fail-open)', [
                    'be_user_uid' => $user['uid'] ?? null,
                    'error' => $e->getMessage(),
                ]);
            }

            return 100;
        }

        $rawUname = $this->login['uname'] ?? '';
        $username = \is_string($rawUname) ? $rawUname : '';
        $ip = $this->getRemoteAddress();

        try {
            // Check lockout
            $this->getRateLimiterService()->checkLockout($username, $ip);

            // Verify the assertion
            $result = $this->getWebAuthnService()->verifyAssertionResponse(
                responseJson: $payload['assertion'],
                challengeToken: $payload['challengeToken'],
                beUserUid: \is_numeric($user['uid'] ?? null) ? (int) $user['uid'] : 0,
            );

            // Clear lockout on success
            $this->getRateLimiterService()->recordSuccess($username, $ip);

            $this->getLogger()->info('Passkey authentication successful', [
                'be_user_uid' => $user['uid'],
                'username' => $username,
                'credential_uid' => $result->credential->getUid(),
            ]);

            // Mark session as passkey-authenticated so the interstitial middleware
            // knows to skip — users who logged in via passkey should never see it.
            $sessionData = $this->pObj->getSessionData('tx_nrpasskeysbe');
            $merged = \is_array($sessionData) ? $sessionData : [];
            $merged['passkey_authenticated'] = true;
            $this->pObj->setAndSaveSessionData('tx_nrpasskeysbe', $merged);

            // A passkey is already multi-factor (possession + biometric/PIN), so the
            // additional TYPO3 MFA challenge is redundant. Setting the 'mfa' session
            // key to true satisfies the check in AbstractUserAuthentication::evaluateMfaRequirements()
            // and skips the MfaRequiredException path. Password logins are unaffected.
            if ($this->getExtensionConfigService()->getConfiguration()->isSkipMfaOnPasskeyAuth()) {
                $this->pObj->setAndSaveSessionData('mfa', true);
                $this->getLogger()->info('Passkey auth satisfied MFA requirement (skipping TYPO3 MFA challenge)', [
                    'be_user_uid' => $user['uid'],
                    'username_hash' => \hash('sha256', $username),
                ]);
            }

            // Return 200 = authenticated, stop further auth processing
            return 200;
        } catch (Throwable $e) {
            // Passkey assertions are unforgeable; do not feed the cross-IP
            // per-username lockout (avoids an account-lockout DoS).
            $this->getRateLimiterService()->recordFailure($username, $ip, countUserLockout: false);

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

        $rawUident = $this->login['uident'] ?? '';
        $uident = \is_string($rawUident) ? $rawUident : '';
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

    /**
     * Check whether a backend user has at least one active (non-deleted, non-revoked) passkey credential.
     *
     * This duplicates the query logic in {@see \Netresearch\NrPasskeysBe\Service\CredentialRepository::countByBeUser()}.
     * The duplication exists because this auth service cannot use DI (AbstractAuthenticationService
     * is instantiated via GeneralUtility::makeInstance) and CredentialRepository requires DI.
     * If the query conditions change, both locations must be updated.
     */
    private function hasRegisteredPasskeys(int $beUserUid): bool
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('tx_nrpasskeysbe_credential');

        $count = $queryBuilder
            ->count('uid')
            ->from('tx_nrpasskeysbe_credential')
            ->where(
                $queryBuilder->expr()->eq(
                    'be_user',
                    $queryBuilder->createNamedParameter($beUserUid, ParameterType::INTEGER),
                ),
                $queryBuilder->expr()->eq('deleted', 0),
                $queryBuilder->expr()->eq('revoked_at', 0),
            )
            ->executeQuery()
            ->fetchOne();

        return \is_numeric($count) && (int) $count > 0;
    }

    private function getEnforcementService(): EnforcementService
    {
        return $this->enforcementService ??= GeneralUtility::makeInstance(EnforcementService::class);
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

    /**
     * Resolve the remote address from the current request, with $_SERVER fallback.
     */
    private function getRemoteAddress(): string
    {
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        if ($request instanceof ServerRequestInterface) {
            $params = $request->getAttribute('normalizedParams');
            if ($params instanceof NormalizedParams) {
                return $params->getRemoteAddress();
            }
        }

        $confVars = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        $sysConf = \is_array($confVars) && isset($confVars['SYS']) && \is_array($confVars['SYS'])
            ? $confVars['SYS']
            : [];

        return NormalizedParams::createFromServerParams($_SERVER, $sysConf)->getRemoteAddress();
    }
}
