<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Middleware;

use Netresearch\NrPasskeysBe\Domain\Dto\EnforcementStatus;
use Netresearch\NrPasskeysBe\Service\EnforcementService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Http\RedirectResponse;

/**
 * Intercepts backend requests to enforce passkey setup when required.
 *
 * Shows an interstitial page prompting the user to register a passkey
 * when their enforcement policy demands it and they have none yet.
 * Supports grace periods and session-based skip for dismissible prompts.
 */
final class PasskeySetupInterstitial implements MiddlewareInterface
{
    private const SESSION_KEY = 'tx_nrpasskeysbe';

    /**
     * Route identifier prefixes that are exempt from the interstitial.
     *
     * @var list<string>
     */
    private const EXEMPT_ROUTE_PREFIXES = [
        'setup',
        'ajax_setup',
        'logout',
        'passkeys_manage_',
        'passkeys_login_',
        'login',
        'password_reset',
        'mfa',
        'install',
    ];

    public function __construct(
        private readonly EnforcementService $enforcementService,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            return $handler->handle($request);
        }

        /** @var array<string, mixed>|null $userRow */
        $userRow = $backendUser->user;
        if (!\is_array($userRow)) {
            return $handler->handle($request);
        }

        $rawUid = $userRow['uid'] ?? null;
        $uid = \is_numeric($rawUid) ? (int) $rawUid : 0;
        if ($uid === 0) {
            return $handler->handle($request);
        }

        // Read session data once for both passkey-auth and skip checks
        $sessionData = $backendUser->getSessionData(self::SESSION_KEY);
        $sessionArray = \is_array($sessionData) ? $sessionData : [];

        // Users who authenticated via passkey should never see the interstitial
        if (($sessionArray['passkey_authenticated'] ?? false) === true) {
            return $handler->handle($request);
        }

        // Handle skip POST before any other checks
        if ($request->getMethod() === 'POST') {
            $parsedBody = $request->getParsedBody();
            if (\is_array($parsedBody) && ($parsedBody['passkey_setup_skip'] ?? '') === '1') {
                $sessionArray['setup_skipped'] = true;
                $backendUser->setAndSaveSessionData(self::SESSION_KEY, $sessionArray);

                return new RedirectResponse($this->resolveBackendPath($request), 303);
            }
        }

        // Check if request is exempt
        if ($this->isExemptRequest($request)) {
            return $handler->handle($request);
        }

        $status = $this->enforcementService->getStatus($userRow);

        if (!$status->requiresInterstitial()) {
            return $handler->handle($request);
        }

        // Start grace period on first intercept
        if ($status->gracePeriodStart === 0) {
            $this->enforcementService->startGracePeriod($uid);
            $userRow['passkey_grace_period_start'] = \time();
            $status = $this->enforcementService->getStatus($userRow);
        }

        // Check session skip flag
        if (($sessionArray['setup_skipped'] ?? false) === true && $status->canSkip()) {
            return $handler->handle($request);
        }

        $backendPath = $this->resolveBackendPath($request);

        return $this->renderInterstitial($status, $backendPath);
    }

    /**
     * Check if the current request is exempt from the interstitial.
     */
    private function isExemptRequest(ServerRequestInterface $request): bool
    {
        // AJAX requests are exempt
        $acceptHeader = $request->getHeaderLine('Accept');
        if (\str_contains($acceptHeader, 'application/json')) {
            return true;
        }

        // Check route identifier against exempt prefixes
        $route = $request->getAttribute('route');
        if (!$route instanceof Route) {
            return true;
        }

        $identifier = $route->getOption('_identifier');
        if (!\is_string($identifier)) {
            return true;
        }

        foreach (self::EXEMPT_ROUTE_PREFIXES as $prefix) {
            if (\str_starts_with($identifier, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine the backend base path from the normalized request parameters.
     *
     * Falls back to '/typo3/' when normalized params are unavailable.
     */
    private function resolveBackendPath(ServerRequestInterface $request): string
    {
        $normalizedParams = $request->getAttribute('normalizedParams');
        if (\is_object($normalizedParams) && \method_exists($normalizedParams, 'getSitePath')) {
            $sitePath = $normalizedParams->getSitePath();
            if (\is_string($sitePath) && $sitePath !== '') {
                return rtrim($sitePath, '/') . '/typo3/';
            }
        }

        return '/typo3/';
    }

    /**
     * Render the interstitial HTML page prompting passkey setup.
     *
     * Uses inline PHP-rendered HTML for cross-version compatibility (v12/v13/v14).
     */
    private function renderInterstitial(EnforcementStatus $status, string $backendPath = '/typo3/'): HtmlResponse
    {
        $remainingDays = $status->gracePeriodRemainingDays();
        $canSkip = $status->canSkip();
        $escapedBackendPath = \htmlspecialchars($backendPath, ENT_QUOTES, 'UTF-8');

        $graceMessage = $remainingDays > 0
            ? 'You have ' . \htmlspecialchars((string) $remainingDays, ENT_QUOTES, 'UTF-8') . ' days remaining to set up your passkey.'
            : 'Passkey setup is now required.';

        $skipButton = '';
        if ($canSkip) {
            $skipLabel = $remainingDays > 0
                ? 'Skip for now (' . \htmlspecialchars((string) $remainingDays, ENT_QUOTES, 'UTF-8') . ' days remaining)'
                : 'Skip for now';

            $skipButton = <<<HTML
                        <form method="post" style="display:inline">
                            <input type="hidden" name="passkey_setup_skip" value="1" />
                            <button type="submit" style="
                                padding: 10px 24px;
                                background: transparent;
                                color: #b0b0b0;
                                border: 1px solid #555;
                                border-radius: 4px;
                                font-size: 14px;
                                cursor: pointer;
                                text-decoration: none;
                            ">{$skipLabel}</button>
                        </form>
HTML;
        }

        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Set up your passkey</title>
    <style>
        body {
            margin: 0;
            padding: 0;
            background: #1e1e1e;
            color: #e0e0e0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
        }
        .interstitial-container {
            max-width: 520px;
            padding: 48px;
            text-align: center;
        }
        h1 {
            font-size: 28px;
            font-weight: 600;
            margin-bottom: 16px;
            color: #ffffff;
        }
        .description {
            font-size: 15px;
            line-height: 1.6;
            color: #b0b0b0;
            margin-bottom: 24px;
        }
        .grace-period {
            font-size: 14px;
            padding: 12px 20px;
            border-radius: 6px;
            margin-bottom: 32px;
            background: #2a2a2a;
            border: 1px solid #444;
        }
        .actions {
            display: flex;
            flex-direction: column;
            gap: 12px;
            align-items: center;
        }
        .btn-setup {
            display: inline-block;
            padding: 12px 32px;
            background: #0078d4;
            color: #ffffff;
            border: none;
            border-radius: 4px;
            font-size: 15px;
            font-weight: 500;
            text-decoration: none;
            cursor: pointer;
        }
        .btn-setup:hover {
            background: #106ebe;
        }
    </style>
</head>
<body>
    <div class="interstitial-container">
        <h1>Set up your passkey</h1>
        <p class="description">
            Passkeys provide a more secure and convenient way to sign in without passwords.
            They use your device&#039;s built-in biometric sensors or security keys to verify your identity,
            making your account resistant to phishing attacks.
        </p>
        <div class="grace-period">{$graceMessage}</div>
        <div class="actions">
            <a href="{$escapedBackendPath}setup/" class="btn-setup">Set up now</a>
            {$skipButton}
        </div>
    </div>
</body>
</html>
HTML;

        return new HtmlResponse($html);
    }
}
