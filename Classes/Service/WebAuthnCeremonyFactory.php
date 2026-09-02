<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Service;

use Cose\Algorithm\Manager as AlgorithmManager;
use Cose\Algorithm\Signature\ECDSA\ES256;
use Cose\Algorithm\Signature\ECDSA\ES384;
use Cose\Algorithm\Signature\ECDSA\ES512;
use Cose\Algorithm\Signature\RSA\RS256;
use Psr\Log\LoggerInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\Denormalizer\WebauthnSerializerFactory;

/**
 * Shared WebAuthn library plumbing used by both the attestation (registration)
 * and assertion (authentication) ceremonies.
 *
 * Centralises construction of the (cached) serializer and the ceremony-step
 * manager factory so the attestation and assertion services stay focused on
 * their respective ceremony logic.
 */
final class WebAuthnCeremonyFactory
{
    private ?SerializerInterface $serializer = null;

    public function __construct(
        private readonly ExtensionConfigurationService $configService,
        private readonly LoggerInterface $logger,
    ) {}

    public function getSerializer(): SerializerInterface
    {
        if (!$this->serializer instanceof SerializerInterface) {
            $attestationManager = $this->createAttestationStatementSupportManager();
            $factory = new WebauthnSerializerFactory($attestationManager);
            $this->serializer = $factory->create();
        }

        return $this->serializer;
    }

    public function createCeremonyFactory(): CeremonyStepManagerFactory
    {
        $factory = new CeremonyStepManagerFactory();
        $origin = $this->configService->getEffectiveOrigin();
        $factory->setAllowedOrigins([$origin]);
        $algorithmManager = $this->createAlgorithmManager();
        $factory->setAlgorithmManager($algorithmManager);
        $factory->setAttestationStatementSupportManager($this->createAttestationStatementSupportManager());

        return $factory;
    }

    private function createAttestationStatementSupportManager(): AttestationStatementSupportManager
    {
        $manager = new AttestationStatementSupportManager();
        $manager->add(new NoneAttestationStatementSupport());

        return $manager;
    }

    private function createAlgorithmManager(): AlgorithmManager
    {
        $algorithms = $this->configService
            ->getConfiguration()
            ->getAllowedAlgorithmsList();
        $manager = AlgorithmManager::create();

        foreach ($algorithms as $algo) {
            match (\strtoupper(\trim($algo))) {
                'ES256' => $manager->add(ES256::create()),
                'ES384' => $manager->add(ES384::create()),
                'ES512' => $manager->add(ES512::create()),
                'RS256' => $manager->add(RS256::create()),
                default => $this->logger->warning('Unknown algorithm configured', ['algorithm' => $algo]),
            };
        }

        return $manager;
    }
}
