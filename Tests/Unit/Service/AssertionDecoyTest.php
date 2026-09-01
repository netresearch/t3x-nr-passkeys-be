<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Tests\Unit\Service;

use Netresearch\NrPasskeysBe\Configuration\ExtensionConfiguration;
use Netresearch\NrPasskeysBe\Domain\Model\Credential;
use Netresearch\NrPasskeysBe\Service\AssertionService;
use Netresearch\NrPasskeysBe\Service\ChallengeService;
use Netresearch\NrPasskeysBe\Service\CredentialRepository;
use Netresearch\NrPasskeysBe\Service\ExtensionConfigurationService;
use Netresearch\NrPasskeysBe\Service\WebAuthnCeremonyFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use Webauthn\PublicKeyCredentialDescriptor;

/**
 * The decoy assertion options are the anti-enumeration defence for the public
 * login-options endpoint, so what matters is that an observer cannot tell a decoy
 * response from a real one. These tests pin the properties that made the old decoy
 * recognisable: always exactly one descriptor, always 32 bytes, always without
 * transports — and the empty allowCredentials an existing passkey-less user returned.
 */
#[CoversClass(AssertionService::class)]
final class AssertionDecoyTest extends TestCase
{
    /**
     * Usernames used to sample the decoy shape distribution. Fixed, so the
     * derivation staying deterministic keeps this test deterministic too.
     *
     * @var list<string>
     */
    private const SAMPLE_USERNAMES = [
        'alice', 'bob', 'carol', 'dave', 'erin', 'frank', 'grace', 'heidi',
        'ivan', 'judy', 'karl', 'lena', 'mallory', 'niaj', 'olivia', 'peggy',
        'quinn', 'rupert', 'sybil', 'trent', 'ursula', 'victor', 'walter', 'xena',
    ];

    private CredentialRepository&MockObject $credentialRepository;

    private AssertionService $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $configService = $this->createMock(ExtensionConfigurationService::class);
        $configService->method('getEncryptionKey')->willReturn('test-encryption-key-at-least-32-chars-long');
        $configService->method('getEffectiveRpId')->willReturn('example.com');
        $configService->method('getConfiguration')->willReturn(new ExtensionConfiguration(
            rpId: 'example.com',
            userVerification: 'preferred',
        ));

        $challengeService = $this->createMock(ChallengeService::class);
        $challengeService->method('generateChallenge')->willReturn(\str_repeat("\x01", 32));
        $challengeService->method('createChallengeToken')->willReturn('challenge-token');

        $this->credentialRepository = $this->createMock(CredentialRepository::class);
        $logger = $this->createMock(LoggerInterface::class);

        $this->subject = new AssertionService(
            $configService,
            $challengeService,
            $this->credentialRepository,
            $logger,
            new WebAuthnCeremonyFactory($configService, $logger),
        );
    }

    /**
     * @return list<PublicKeyCredentialDescriptor>
     */
    private function decoysFor(string $username): array
    {
        return \array_values($this->subject->createDecoyAssertionOptions($username)->options->allowCredentials);
    }

    #[Test]
    public function decoysAreStableForTheSameUsername(): void
    {
        $first = $this->decoysFor('alice');
        $second = $this->decoysFor('alice');

        self::assertSame(
            \array_map(static fn(PublicKeyCredentialDescriptor $d): string => $d->id, $first),
            \array_map(static fn(PublicKeyCredentialDescriptor $d): string => $d->id, $second),
            'Repeated requests for the same unknown username must not change the answer',
        );
    }

    #[Test]
    public function differentUsernamesGetDifferentDecoyIds(): void
    {
        self::assertNotSame($this->decoysFor('alice')[0]->id, $this->decoysFor('bob')[0]->id);
    }

    /**
     * The old decoy always returned exactly one descriptor, so any response with two
     * or more proved a real account.
     */
    #[Test]
    public function decoyCountVariesAcrossUsernames(): void
    {
        $counts = [];

        foreach (self::SAMPLE_USERNAMES as $username) {
            $counts[\count($this->decoysFor($username))] = true;
        }

        self::assertGreaterThan(1, \count($counts), 'Decoy descriptor count must not be fixed');

        foreach (\array_keys($counts) as $count) {
            self::assertGreaterThanOrEqual(1, $count);
            self::assertLessThanOrEqual(3, $count);
        }
    }

    /**
     * The old decoy id was always the full 32-byte HMAC, which real authenticators
     * rarely emit — a 43-character base64url id was a decoy tell.
     */
    #[Test]
    public function decoyIdLengthVariesAndStaysRealistic(): void
    {
        $lengths = [];

        foreach (self::SAMPLE_USERNAMES as $username) {
            foreach ($this->decoysFor($username) as $descriptor) {
                $lengths[\strlen($descriptor->id)] = true;
            }
        }

        self::assertGreaterThan(1, \count($lengths), 'Decoy credential-ID length must not be fixed');

        foreach (\array_keys($lengths) as $length) {
            self::assertContains($length, [16, 20, 32, 64], 'Decoy IDs must use realistic byte lengths');
        }
    }

    /**
     * The old decoy never carried transports, while a real credential registered
     * from a browser almost always does.
     */
    #[Test]
    public function someDecoysCarryPlausibleTransports(): void
    {
        $withTransports = 0;
        $seen = [];

        foreach (self::SAMPLE_USERNAMES as $username) {
            foreach ($this->decoysFor($username) as $descriptor) {
                $transports = $descriptor->transports;

                if ($transports !== []) {
                    ++$withTransports;
                }

                foreach ($transports as $transport) {
                    $seen[$transport] = true;
                }
            }
        }

        self::assertGreaterThan(0, $withTransports, 'Decoys must sometimes report transports');

        foreach (\array_keys($seen) as $transport) {
            self::assertContains($transport, ['internal', 'hybrid', 'usb', 'nfc']);
        }
    }

    /**
     * The oracle the shape randomisation itself opened: the id length and transports
     * were selected from the first two bytes of the same HMAC whose head was then
     * published as the credential id. An unauthenticated caller could therefore
     * recompute `strlen(id) === LENGTHS[ord(id[0]) % n]` and
     * `transports === SETS[ord(id[1]) % m]` — relations that hold for every decoy and
     * only by chance for a real credential, so any response failing them was certainly
     * a real enrolled account.
     *
     * The selectors now come from a separate HMAC the caller never sees, so the
     * relation must hold no more often than chance.
     */
    #[Test]
    public function decoyIdDoesNotEncodeItsOwnLengthAndTransports(): void
    {
        // Read the real constants rather than restating them: they are public source,
        // so an attacker uses exactly these, and a hardcoded copy would silently stop
        // matching the implementation and make this test vacuous.
        $reflection = new ReflectionClass(AssertionService::class);

        /** @var list<int> $lengths */
        $lengths = $reflection->getConstant('DECOY_ID_LENGTHS');

        /** @var list<list<string>> $sets */
        $sets = $reflection->getConstant('DECOY_TRANSPORT_SETS');

        self::assertIsArray($lengths);
        self::assertIsArray($sets);
        self::assertNotSame([], $lengths);
        self::assertNotSame([], $sets);

        $total = 0;
        $selfIdentifying = 0;

        foreach (self::SAMPLE_USERNAMES as $username) {
            foreach ($this->decoysFor($username) as $descriptor) {
                ++$total;

                // Exactly the test an attacker can run, using only what the endpoint
                // returns: the raw credential id and the transports beside it.
                $id = $descriptor->id;
                $lengthMatches = \strlen($id) === $lengths[\ord($id[0]) % \count($lengths)];
                $transportsMatch = $descriptor->transports === $sets[\ord($id[1]) % \count($sets)];

                if ($lengthMatches && $transportsMatch) {
                    ++$selfIdentifying;
                }
            }
        }

        self::assertGreaterThan(0, $total);

        // Both relations holding is a 1-in-24 coincidence per descriptor once the
        // derivations are independent; before the fix it was every single one.
        self::assertLessThan(
            $total / 2,
            $selfIdentifying,
            \sprintf(
                'A decoy id must not encode its own shape: %d of %d descriptors are still self-identifying',
                $selfIdentifying,
                $total,
            ),
        );
    }

    /**
     * The same defect one level down: an id longer than one sha256 block was first
     * stretched by appending `sha256($material . '|1')` to `$material`, and the head
     * of the id *is* `$material`. The tail was therefore computable from the published
     * head with no key at all, so `substr($id, 32) === sha256(substr($id, 0, 32) . '|1')`
     * identified every long decoy just as reliably as the shape relation did.
     *
     * Each block is now its own keyed HMAC, so no published byte predicts another.
     */
    #[Test]
    public function longDecoyIdTailIsNotDerivableFromItsHead(): void
    {
        $longIds = 0;

        foreach (self::SAMPLE_USERNAMES as $username) {
            foreach ($this->decoysFor($username) as $descriptor) {
                $id = $descriptor->id;

                if (\strlen($id) <= 32) {
                    continue;
                }

                ++$longIds;

                self::assertNotSame(
                    \substr($id, 32),
                    \hash('sha256', \substr($id, 0, 32) . '|1', true),
                    'A decoy id longer than one block must not let its tail be computed from its head',
                );
            }
        }

        self::assertGreaterThan(
            0,
            $longIds,
            'The sample must contain ids longer than one sha256 block, or this proves nothing',
        );
    }

    /**
     * The remaining oracle after the shape fix: during rollout most real accounts have
     * no passkey yet, and an empty allowCredentials was something no unknown username
     * ever produced — so it proved the account exists.
     */
    #[Test]
    public function knownUserWithoutCredentialsGetsDecoysInsteadOfAnEmptyList(): void
    {
        $this->credentialRepository->method('findByBeUser')->willReturn([]);

        $options = $this->subject->createAssertionOptions('alice', 42)->options;

        self::assertNotEmpty(
            $options->allowCredentials,
            'An existing user without a passkey must not answer with an empty allowCredentials',
        );
        self::assertSame(
            \array_map(static fn(PublicKeyCredentialDescriptor $d): string => $d->id, $this->decoysFor('alice')),
            \array_map(
                static fn(PublicKeyCredentialDescriptor $d): string => $d->id,
                \array_values($options->allowCredentials),
            ),
            'A passkey-less known user must be indistinguishable from an unknown username',
        );
    }

    #[Test]
    public function knownUserWithCredentialsGetsTheRealOnes(): void
    {
        $credential = new Credential(
            uid: 1,
            beUser: 42,
            credentialId: 'real-credential-id',
            transports: '["internal"]',
        );
        $this->credentialRepository->method('findByBeUser')->willReturn([$credential]);

        $options = $this->subject->createAssertionOptions('alice', 42)->options;

        self::assertCount(1, $options->allowCredentials);
        self::assertSame('real-credential-id', \array_values($options->allowCredentials)[0]->id);
    }
}
