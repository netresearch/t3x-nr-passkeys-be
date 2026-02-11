<?php

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Domain\Dto;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Value object representing the currently authenticated backend user.
 *
 * Replaces raw array access to $GLOBALS['BE_USER']->user in controllers.
 */
final readonly class AuthenticatedUser
{
    public function __construct(
        public int $uid,
        public string $username,
        public string $realName,
        public bool $isAdmin,
    ) {}

    public static function fromBackendUser(BackendUserAuthentication $backendUser): ?self
    {
        $user = $backendUser->user;
        if (!\is_array($user)) {
            return null;
        }

        $rawUid = $user['uid'] ?? null;
        if (!\is_numeric($rawUid)) {
            return null;
        }

        $rawUsername = $user['username'] ?? '';
        $rawRealName = $user['realName'] ?? '';

        return new self(
            uid: (int) $rawUid,
            username: \is_string($rawUsername) ? $rawUsername : '',
            realName: \is_string($rawRealName) ? $rawRealName : '',
            isAdmin: $backendUser->isAdmin(),
        );
    }
}
