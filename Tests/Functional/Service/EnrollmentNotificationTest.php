<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Tests\Functional\Service;

use Netresearch\NrPasskeysBe\Service\EnrollmentNotification;
use Netresearch\NrPasskeysBe\Service\WebAuthnService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LogLevel;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;
use TYPO3\CMS\Core\Console\CommandRegistry;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Log\Logger;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Log\LogRecord;
use TYPO3\CMS\Core\Log\Writer\WriterInterface;
use TYPO3\CMS\Core\Mail\FileSpool;
use TYPO3\CMS\Core\Mail\MailerInterface;
use TYPO3\CMS\Core\Mail\MboxTransport;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Webauthn\CredentialRecord;
use Webauthn\TrustPath\EmptyTrustPath;

#[CoversClass(EnrollmentNotification::class)]
final class EnrollmentNotificationTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['setup'];

    protected array $testExtensionsToLoad = ['netresearch/nr-passkeys-be'];

    /** @var resource|null */
    private mixed $mailbox = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
        $this->mailbox = tmpfile();
        self::assertIsResource($this->mailbox);
        $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport']              = 'mbox';
        $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport_mbox_file']    = stream_get_meta_data($this->mailbox)['uri'];
        $GLOBALS['TYPO3_CONF_VARS']['MAIL']['defaultMailFromAddress'] = 'no-reply@example.test';
        $db                                                           = $this
            ->getConnectionPool()
            ->getConnectionForTable('be_users');
        $db->update('be_users', ['email' => 'owner@example.test'], ['uid' => 1]);
        $db->update('be_users', ['email' => 'other@example.test'], ['uid' => 2]);
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['sitename'] = 'Example backend';
    }

    #[Test]
    public function notifiesOnlyTheOwnerThroughTheConfiguredTransport(): void
    {
        $this->enroll();

        $message = stream_get_contents($this->mailbox);
        self::assertIsString($message);
        self::assertStringContainsString('To: owner@example.test', $message);
        self::assertStringContainsString('From:', $message);
        self::assertStringContainsString('no-reply@example.test', $message);
        self::assertStringContainsString('A passkey was added to your account', $message);
        self::assertStringContainsString('Example backend', quoted_printable_decode($message));
        self::assertStringNotContainsString('other@example.test', $message);
        self::assertStringNotContainsString('sensitive-key-label', $message);
    }

    #[Test]
    public function missingAddressProducesNoMessage(): void
    {
        $this
            ->getConnectionPool()
            ->getConnectionForTable('be_users')
            ->update('be_users', ['email' => ''], ['uid' => 1]);
        $this->enroll();
        self::assertSame('', stream_get_contents($this->mailbox));
    }

    #[Test]
    public function unavailableMailTransportLeavesEnrollmentIntact(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport'] = 'no-such-transport';
        $this->enroll();
        self::assertSame('', stream_get_contents($this->mailbox));
        $this->assertCredentialPersisted();
    }

    private function enroll(): void
    {
        $source = CredentialRecord::create(
            'test-id',
            'public-key',
            [],
            'none',
            new EmptyTrustPath(),
            Uuid::v4(),
            'test-public-key',
            'test-handle',
            0,
        );
        $this
            ->get(WebAuthnService::class)
            ->storeCredential($source, 1, 'sensitive-key-label');
    }

    protected function tearDown(): void
    {
        if (is_resource($this->mailbox)) {
            fclose($this->mailbox);
        }

        parent::tearDown();
    }

    #[Test]
    public function rendersTheNoticeInTheAccountLanguage(): void
    {
        $this
            ->getConnectionPool()
            ->getConnectionForTable('be_users')
            ->update(
                'be_users',
                ['lang' => 'de'],
                ['uid' => 1],
            );
        $this->enroll();
        $message = stream_get_contents($this->mailbox);
        self::assertIsString($message);
        self::assertStringContainsString('Backend-Konto auf Example backend', quoted_printable_decode($message));
    }

    private function assertCredentialPersisted(): void
    {
        self::assertSame(
            1,
            $this
                ->getConnectionPool()
                ->getConnectionForTable('tx_nrpasskeysbe_credential')
                ->count(
                    '*',
                    'tx_nrpasskeysbe_credential',
                    ['be_user' => 1],
                ),
        );
    }

    #[Test]
    public function sendingFailureLeavesTheCredentialStored(): void
    {
        // An existing file cannot be the parent directory of another mailbox.
        $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport_mbox_file'] = stream_get_meta_data($this->mailbox)['uri'] . '/unwritable.mbox';
        $logger                                                    = $this
            ->get(LogManager::class)
            ->getLogger(EnrollmentNotification::class);
        self::assertInstanceOf(Logger::class, $logger);
        $writer = $this->createMock(WriterInterface::class);
        $writer
            ->expects(self::once())
            ->method('writeLog')
            ->willReturnCallback(
                static function (LogRecord $record) use ($writer): WriterInterface {
                    self::assertSame(LogLevel::WARNING, $record->getLevel());
                    self::assertSame('Passkey enrollment notification could not be sent', $record->getMessage());
                    self::assertSame(['be_user_uid' => 1, 'exceptionType' => RuntimeException::class], $record->getData());

                    return $writer;
                },
            );
        $logger->addWriter(LogLevel::WARNING, $writer);
        self::assertInstanceOf(MboxTransport::class, $this
            ->get(MailerInterface::class)
            ->getTransport());
        $this->enroll();
        $this->assertCredentialPersisted();
        self::assertSame('', stream_get_contents($this->mailbox));
    }

    #[Test]
    public function fileSpoolDeliversOnlyAfterEnrollmentReturns(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport_spool_type']     = 'file';
        $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport_spool_filepath'] = Environment::getVarPath() . '/transient/passkey-mail-' . bin2hex(random_bytes(8));
        $this->enroll();
        $this->assertCredentialPersisted();
        self::assertSame('', stream_get_contents($this->mailbox));
        $mailer = $this->get(MailerInterface::class);
        $spool  = $mailer->getTransport();
        self::assertInstanceOf(FileSpool::class, $spool);
        $worker = new CommandTester($this
            ->get(CommandRegistry::class)
            ->get('mailer:spool:send'));
        self::assertSame(0, $worker->execute(['--message-limit' => 1]));
        $message = stream_get_contents($this->mailbox);
        self::assertIsString($message);
        self::assertStringContainsString('To: owner@example.test', $message);
    }
}
