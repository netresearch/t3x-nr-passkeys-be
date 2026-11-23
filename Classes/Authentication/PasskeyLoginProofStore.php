<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Authentication;

use TYPO3\CMS\Core\Authentication\AbstractUserAuthentication;
use WeakMap;

/**
 * Carries a verified passkey login from the authentication service to the
 * PasskeyLoginCompletion middleware within one request.
 *
 * On a fresh browser session TYPO3 runs the authentication services before it
 * creates the user session, and it creates that session without the data the
 * services wrote. The proof therefore lives in memory, keyed by the user
 * object, until the middleware writes it into the session TYPO3 created. The
 * WeakMap drops it together with the user object, so it never outlives the
 * request that verified the passkey.
 */
final class PasskeyLoginProofStore
{
    /** @var WeakMap<AbstractUserAuthentication, array{uid: int, creditMfa: bool}> */
    private WeakMap $proofs;

    public function __construct()
    {
        $this->proofs = new WeakMap();
    }

    public function record(AbstractUserAuthentication $user, int $uid, bool $creditMfa): void
    {
        $this->proofs[$user] = ['uid' => $uid, 'creditMfa' => $creditMfa];
    }

    /**
     * Return the proof recorded for the user object and forget it, so a proof
     * is applied at most once.
     *
     * @return array{uid: int, creditMfa: bool}|null
     */
    public function take(AbstractUserAuthentication $user): ?array
    {
        $proof = $this->proofs[$user] ?? null;
        unset($this->proofs[$user]);

        return $proof;
    }
}
