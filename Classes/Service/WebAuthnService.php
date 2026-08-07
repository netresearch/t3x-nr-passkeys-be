<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Service;

use Netresearch\NrPasskeysBe\Domain\Dto\AssertionOptions;
use Netresearch\NrPasskeysBe\Domain\Dto\RegistrationOptions;
use Netresearch\NrPasskeysBe\Domain\Dto\VerifiedAssertion;
use Netresearch\NrPasskeysBe\Domain\Enum\CredentialDiscoverability;
use Netresearch\NrPasskeysBe\Domain\Model\Credential;
use RuntimeException;
use Webauthn\CredentialRecord;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialRequestOptions;

/**
 * Facade over the WebAuthn attestation (registration) and assertion
 * (authentication) ceremonies.
 *
 * Keeps a single, stable entry point for the controllers and the authentication
 * service while delegating the ceremony logic to the focused {@see AttestationService}
 * and {@see AssertionService}. This preserves the public surface used across the
 * extension; the ceremony detail lives in the dedicated services.
 */
final readonly class WebAuthnService
{
    public function __construct(
        private AttestationService $attestationService,
        private AssertionService $assertionService,
    ) {}

    /**
     * Create registration options for a backend user.
     */
    public function createRegistrationOptions(int $beUserUid, string $username, string $displayName): RegistrationOptions
    {
        return $this->attestationService->createRegistrationOptions($beUserUid, $username, $displayName);
    }

    /**
     * Verify a registration response from the browser.
     *
     * @throws RuntimeException on verification failure
     */
    public function verifyRegistrationResponse(
        string $responseJson,
        string $challengeToken,
        int $beUserUid,
        string $username,
        string $displayName,
    ): CredentialRecord {
        return $this->attestationService->verifyRegistrationResponse(
            $responseJson,
            $challengeToken,
            $beUserUid,
            $username,
            $displayName,
        );
    }

    /**
     * Create assertion options for login (Variant A: username-first).
     */
    public function createAssertionOptions(string $username, int $beUserUid): AssertionOptions
    {
        return $this->assertionService->createAssertionOptions($username, $beUserUid);
    }

    /**
     * Create assertion options for discoverable login (Variant B: identifierless).
     */
    public function createDiscoverableAssertionOptions(): AssertionOptions
    {
        return $this->assertionService->createDiscoverableAssertionOptions();
    }

    /**
     * Create *decoy* assertion options for an unknown username (user-enumeration defence).
     */
    public function createDecoyAssertionOptions(string $username): AssertionOptions
    {
        return $this->assertionService->createDecoyAssertionOptions($username);
    }

    /**
     * Resolve the backend user UID from a passkey assertion response (discoverable login).
     */
    public function findBeUserUidFromAssertion(string $responseJson): ?int
    {
        return $this->assertionService->findBeUserUidFromAssertion($responseJson);
    }

    /**
     * Verify an assertion response for login.
     *
     * @throws RuntimeException on verification failure
     */
    public function verifyAssertionResponse(
        string $responseJson,
        string $challengeToken,
        int $beUserUid,
    ): VerifiedAssertion {
        return $this->assertionService->verifyAssertionResponse($responseJson, $challengeToken, $beUserUid);
    }

    /**
     * Store a verified registration result as a Credential.
     */
    public function storeCredential(
        CredentialRecord $source,
        int $beUserUid,
        string $label,
        CredentialDiscoverability $discoverable = CredentialDiscoverability::Unknown,
    ): Credential {
        return $this->attestationService->storeCredential($source, $beUserUid, $label, $discoverable);
    }

    /**
     * Serialize PublicKeyCredentialCreationOptions to JSON for the browser.
     */
    public function serializeCreationOptions(PublicKeyCredentialCreationOptions $options): string
    {
        return $this->attestationService->serializeCreationOptions($options);
    }

    /**
     * Serialize PublicKeyCredentialRequestOptions to JSON for the browser.
     */
    public function serializeRequestOptions(PublicKeyCredentialRequestOptions $options): string
    {
        return $this->assertionService->serializeRequestOptions($options);
    }
}
