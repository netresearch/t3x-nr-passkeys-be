<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Tests\Unit\Service;

use Netresearch\NrPasskeysBe\Service\ChallengeService;
use Netresearch\NrPasskeysBe\Service\ConsumedTokenStore;
use Netresearch\NrPasskeysBe\Service\ExtensionConfigurationService;
use Netresearch\NrPasskeysBe\Service\SudoModeTokenService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\Locking\LockingStrategyInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

use function str_ends_with;
use function substr;

#[CoversClass(SudoModeTokenService::class)]
final class SudoModeTokenServiceTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $cache = [];

    private ChallengeService $challengeService;

    private SudoModeTokenService $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = 'test-encryption-key-that-is-long-enough-for-hmac';

        $nonceCache = $this->createMock(FrontendInterface::class);
        $nonceCache
            ->method('set')
            ->willReturnCallback(
                function (string $key, mixed $value): void {
                    $this->cache[$key] = $value;
                },
            );
        $nonceCache
            ->method('get')
            ->willReturnCallback(fn (string $key): mixed => $this->cache[$key] ?? false);
        $nonceCache
            ->method('remove')
            ->willReturnCallback(
                function (string $key): bool {
                    unset($this->cache[$key]);

                    return true;
                },
            );

        $locker = $this->createMock(LockingStrategyInterface::class);
        $locker
            ->method('acquire')
            ->willReturn(true);
        $lockFactory = $this->createMock(LockFactory::class);
        $lockFactory
            ->method('createLocker')
            ->willReturn($locker);

        $typo3ExtConfig = $this->createMock(ExtensionConfiguration::class);
        $typo3ExtConfig
            ->method('get')
            ->willReturn(['challengeTtlSeconds' => 120]);

        // ChallengeService claims each nonce in the database through a fresh
        // ConsumedTokenStore per verification. This one remembers its claims,
        // so a second use of a token is refused here as it is in production.
        $claimed = [];
        $store   = $this->createStub(ConsumedTokenStore::class);
        $store
            ->method('claim')
            ->willReturnCallback(
                function (string $key) use (&$claimed): bool {
                    if (isset($claimed[$key])) {
                        return false;
                    }

                    $claimed[$key] = true;

                    return true;
                },
            );

        for ($i = 0; $i < 10; ++$i) {
            GeneralUtility::addInstance(ConsumedTokenStore::class, $store);
        }

        $this->challengeService = new ChallengeService(
            $nonceCache,
            new ExtensionConfigurationService($typo3ExtConfig),
            $lockFactory,
            new NullLogger(),
        );
        $this->subject = new SudoModeTokenService($this->challengeService, new NullLogger());
    }

    protected function tearDown(): void
    {
        GeneralUtility::purgeInstances();
        unset($GLOBALS['TYPO3_CONF_VARS']);
        parent::tearDown();
    }

    #[Test]
    public function aTokenConfirmsExactlyTheClaimItWasIssuedFor(): void
    {
        $token = $this->subject->issue(7, 'session-a', 'claim-1');

        self::assertTrue($this->subject->isToken($token));
        self::assertTrue($this->subject->verify($token, 7, 'session-a', 'claim-1'));
    }

    #[Test]
    public function aTokenWorksOnlyOnce(): void
    {
        $token = $this->subject->issue(7, 'session-a', 'claim-1');

        self::assertTrue($this->subject->verify($token, 7, 'session-a', 'claim-1'));
        self::assertFalse($this->subject->verify($token, 7, 'session-a', 'claim-1'));
    }

    /**
     * @return iterable<string, array{int, string, string}>
     */
    public static function otherContexts(): iterable
    {
        yield 'another user' => [8, 'session-a', 'claim-1'];

        yield 'another session' => [7, 'session-b', 'claim-1'];

        yield 'another claim' => [7, 'session-a', 'claim-2'];
    }

    #[Test]
    #[DataProvider('otherContexts')]
    public function aTokenIsWorthlessInAnyOtherContext(int $uid, string $session, string $claim): void
    {
        $token = $this->subject->issue(7, 'session-a', 'claim-1');

        self::assertFalse($this->subject->verify($token, $uid, $session, $claim));
    }

    #[Test]
    public function aLoginChallengeTokenIsNoSudoToken(): void
    {
        // Same signer, same format: only the context digest tells them apart.
        $loginToken = SudoModeTokenService::PREFIX . $this->challengeService->createChallengeToken($this->challengeService->generateChallenge());

        self::assertFalse($this->subject->verify($loginToken, 7, 'session-a', 'claim-1'));
    }

    #[Test]
    public function aPasswordIsNoToken(): void
    {
        self::assertFalse($this->subject->isToken('correct horse battery staple'));
        self::assertFalse($this->subject->verify('correct horse battery staple', 7, 'session-a', 'claim-1'));
    }

    #[Test]
    public function aTamperedTokenIsRejected(): void
    {
        $token    = $this->subject->issue(7, 'session-a', 'claim-1');
        $tampered = substr($token, 0, -4) . (str_ends_with($token, 'AAAA') ? 'BBBB' : 'AAAA');

        self::assertFalse($this->subject->verify($tampered, 7, 'session-a', 'claim-1'));
    }

    #[Test]
    public function anEmptyContextNeverVerifies(): void
    {
        $token = $this->subject->issue(7, 'session-a', 'claim-1');

        self::assertFalse($this->subject->verify($token, 0, 'session-a', 'claim-1'));
        self::assertFalse($this->subject->verify($token, 7, '', 'claim-1'));
        self::assertFalse($this->subject->verify($token, 7, 'session-a', ''));
    }
}
