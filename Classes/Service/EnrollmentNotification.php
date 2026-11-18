<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Service;

use Closure;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\Email;
use Throwable;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Mail\MailerInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/** @internal */
final readonly class EnrollmentNotification
{
    /** @param Closure(): MailerInterface $mailer */
    public function __construct(
        private ConnectionPool $connections,
        private LanguageServiceFactory $languages,
        private Closure $mailer,
        private LoggerInterface $logger,
    ) {}

    public function notify(int $uid): void
    {
        try {
            $row = $this->connections
                ->getConnectionForTable('be_users')
                ->select(
                    ['email', 'lang'],
                    'be_users',
                    ['uid' => $uid, 'deleted' => 0, 'disable' => 0],
                )
                ->fetchAssociative();
            $address = $row['email'] ?? null;

            if (!is_string($address) || !GeneralUtility::validEmail($address)) {
                $this->logger->warning(
                    'Passkey enrollment notification has no valid recipient',
                    ['be_user_uid' => $uid],
                );

                return;
            }

            $locale   = $row['lang'] ?? null;
            $language = $this->languages->create(is_string($locale) && $locale !== '' ? $locale : 'en');
            $settings = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
            $system   = is_array($settings) ? $settings['SYS'] ?? null : null;
            $siteName = is_array($system) ? $system['sitename'] ?? '' : '';
            $siteName = is_string($siteName) && $siteName !== '' ? $siteName : 'TYPO3';
            $prefix   = 'LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang.xlf:enrollment.email.';
            $message  = (new Email())
                ->to($address)
                ->subject($language->sL($prefix . 'subject'))
                ->text(
                    sprintf($language->sL($prefix . 'body'), $siteName),
                );
            ($this->mailer)()->send($message);
        } catch (Throwable $error) {
            $this->logger->warning(
                'Passkey enrollment notification could not be sent',
                ['be_user_uid' => $uid, 'exceptionType' => $error::class],
            );
        }
    }
}
