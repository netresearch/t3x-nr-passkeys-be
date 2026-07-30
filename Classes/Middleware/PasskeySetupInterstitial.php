<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Middleware;

use Netresearch\NrPasskeysBe\Domain\Dto\EnforcementStatus;
use Netresearch\NrPasskeysBe\Service\EnforcementService;
use Netresearch\NrPasskeysBe\Utility\TranslationTrait;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Localization\LanguageService;

/**
 * Intercepts backend requests to enforce passkey setup when required.
 *
 * Shows an interstitial page prompting the user to register a passkey
 * when their enforcement policy demands it and they have none yet.
 * Supports grace periods and session-based skip for dismissible prompts.
 */
final class PasskeySetupInterstitial implements MiddlewareInterface
{
    use TranslationTrait;

    private const SESSION_KEY = 'tx_nrpasskeysbe';

    /**
     * How long (seconds) a "no interstitial needed" decision is cached in the
     * session to avoid re-running the enforcement queries (credential count +
     * group enforcement) on every backend request. Kept short so an admin's
     * enforcement change takes effect quickly.
     */
    private const ENFORCEMENT_CACHE_TTL = 60;

    /**
     * Route identifier prefixes that are exempt from the interstitial.
     *
     * NOTE: do NOT add a bare 'ajax_' here. TYPO3 core registers every backend
     * AJAX route as 'ajax_'.<id> (AbstractServiceProvider), so a broad 'ajax_'
     * prefix would exempt ~260 state-changing endpoints (ajax_record_process =
     * DataHandler save, ajax_file_process, ...) and let an enforced-but-unenrolled
     * user drive the backend through them, defeating enforcement. Only the AJAX
     * routes the enrollment/login flow actually needs are exempted, by their
     * explicit identifier prefix.
     *
     * @var list<string>
     */
    private const EXEMPT_ROUTE_PREFIXES = [
        'ajax_login',
        'ajax_logout',
        'ajax_mfa',
        'ajax_passkeys_manage_',
        'ajax_passkeys_enforcement_status',
        // 'user_setup' is the real User Settings module identifier (where passkey
        // registration lives); it MUST be exempt so users forced into setup by the
        // interstitial can actually reach the registration panel. 'setup' covers the
        // standalone setup_mfa route.
        'user_setup',
        'setup',
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
        private readonly UriBuilder $uriBuilder,
        private readonly LoggerInterface $logger,
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

        // Never interstitial a switch-user (impersonation) session: passkey
        // registration is refused in that mode (ManagementController), so the prompt
        // would be a dead end. Enforcement applies to the impersonated user's own
        // sessions, not to an admin acting on their behalf.
        if ($backendUser->getOriginalUserIdWhenInSwitchUserMode() !== null) {
            return $handler->handle($request);
        }

        // Check if request is exempt before any interstitial logic
        if ($this->isExemptRequest($request)) {
            return $handler->handle($request);
        }

        // Handle skip POST with CSRF nonce validation
        if ($request->getMethod() === 'POST') {
            $parsedBody = $request->getParsedBody();
            if (\is_array($parsedBody) && ($parsedBody['passkey_setup_skip'] ?? '') === '1') {
                $submittedNonce = $parsedBody['passkey_setup_nonce'] ?? '';
                $storedNonce = $sessionArray['skip_nonce'] ?? '';

                if (\is_string($submittedNonce) && \is_string($storedNonce)
                    && $storedNonce !== '' && \hash_equals($storedNonce, $submittedNonce)
                ) {
                    $sessionArray['setup_skipped'] = true;
                    unset($sessionArray['skip_nonce']);
                    $backendUser->setAndSaveSessionData(self::SESSION_KEY, $sessionArray);

                    return new RedirectResponse($this->resolveBackendPath($request), 303);
                }

                // Invalid nonce — fall through to re-render the interstitial
                $serverParams = $request->getServerParams();
                $clientIp = \is_string($serverParams['REMOTE_ADDR'] ?? null)
                    ? $serverParams['REMOTE_ADDR']
                    : 'unknown';

                $this->logger->warning('CSRF nonce validation failed on passkey setup skip form', [
                    'ip' => $clientIp,
                    'beUserUid' => $uid,
                ]);
            }
        }

        // PERF-1 / ONB-3: reuse a recent "no interstitial needed" decision instead of
        // re-querying the database on every backend request. The short TTL bounds how
        // long a stale decision (e.g. enforcement raised, or passkeys revoked) can
        // suppress the interstitial, so it cannot be permanently suppressed.
        $now = \time();
        $decidedAt = $sessionArray['enforcement_ok_at'] ?? 0;
        if (\is_int($decidedAt) && $decidedAt > 0 && ($now - $decidedAt) < self::ENFORCEMENT_CACHE_TTL) {
            return $handler->handle($request);
        }

        $status = $this->enforcementService->getStatus($userRow);

        if (!$status->requiresInterstitial()) {
            // Cache the clear decision so subsequent requests skip the queries.
            $sessionArray['enforcement_ok_at'] = $now;
            $backendUser->setAndSaveSessionData(self::SESSION_KEY, $sessionArray);

            return $handler->handle($request);
        }

        // No longer compliant — drop any cached "ok" decision so it is not reused.
        if (isset($sessionArray['enforcement_ok_at'])) {
            unset($sessionArray['enforcement_ok_at']);
            $backendUser->setAndSaveSessionData(self::SESSION_KEY, $sessionArray);
        }

        // Start grace period on first intercept — construct updated status
        // directly instead of re-querying the database
        if ($status->gracePeriodStart === 0) {
            $now = \time();
            $this->enforcementService->startGracePeriod($uid);
            $status = new EnforcementStatus(
                level: $status->level,
                gracePeriodDays: $status->gracePeriodDays,
                gracePeriodStart: $now,
                hasPasskeys: $status->hasPasskeys,
            );

            $this->logger->info('Grace period started for passkey setup', [
                'beUserUid' => $uid,
                'gracePeriodDays' => $status->gracePeriodDays,
                'enforcementLevel' => $status->level->value,
            ]);
        }

        // Check session skip flag
        if (($sessionArray['setup_skipped'] ?? false) === true && $status->canSkip()) {
            return $handler->handle($request);
        }

        // Generate CSRF nonce for the skip form and store it in session
        $nonce = \bin2hex(\random_bytes(16));
        $sessionArray['skip_nonce'] = $nonce;
        $backendUser->setAndSaveSessionData(self::SESSION_KEY, $sessionArray);

        $backendPath = $this->resolveBackendPath($request);

        $this->logger->info('Passkey setup interstitial rendered', [
            'beUserUid' => $uid,
            'enforcementLevel' => $status->level->value,
            'canSkip' => $status->canSkip(),
        ]);

        return $this->renderInterstitial($status, $backendPath, $nonce, $this->resolveColorScheme($backendUser));
    }

    /**
     * Check if the current request is exempt from the interstitial.
     */
    private function isExemptRequest(ServerRequestInterface $request): bool
    {
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
     * Resolve the user's backend color scheme preference (TYPO3 v13.3+).
     *
     * Returns 'light', 'dark' or 'auto'. On v12 (no colorScheme user setting)
     * this returns 'auto', which follows the operating system preference.
     */
    private function resolveColorScheme(BackendUserAuthentication $backendUser): string
    {
        $scheme = $backendUser->uc['colorScheme'] ?? null;

        return \is_string($scheme) && \in_array($scheme, ['light', 'dark'], true) ? $scheme : 'auto';
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
                return \rtrim($sitePath, '/') . '/typo3/';
            }
        }

        return '/typo3/';
    }

    /**
     * Render the interstitial HTML page prompting passkey setup.
     *
     * Uses inline PHP-rendered HTML for cross-version compatibility (v12/v13/v14).
     * All user-facing strings use LanguageService for i18n when available.
     */
    private function renderInterstitial(EnforcementStatus $status, string $backendPath, string $nonce, string $colorScheme): HtmlResponse
    {
        $remainingDays = $status->gracePeriodRemainingDays();
        $canSkip = $status->canSkip();
        $escapedBackendPath = \htmlspecialchars($backendPath, ENT_QUOTES, 'UTF-8');
        $escapedNonce = \htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8');

        // Link to the real User Settings module (identifier "user_setup") where the
        // passkey registration panel is rendered. The module is exempt from this
        // middleware (see EXEMPT_ROUTE_PREFIXES) so the user can actually reach it.
        $setupUrl = (string) $this->uriBuilder->buildUriFromRoute('user_setup');
        $escapedSetupUrl = \htmlspecialchars($setupUrl, ENT_QUOTES, 'UTF-8');

        $title = $this->translate('interstitial.title', 'Set up your passkey');
        $description = $this->translate('interstitial.description', 'Passkeys provide a more secure and convenient way to sign in without passwords. They use your device\'s built-in biometric sensors or security keys to verify your identity, making your account resistant to phishing attacks.');
        $setupLabel = $this->translate('interstitial.button.setup', 'Set up now');

        if ($remainingDays > 0 && $canSkip) {
            $graceTemplate = $this->translate('interstitial.grace.remaining', 'You have %d days remaining to set up your passkey.');
            $graceMessage = \sprintf($graceTemplate, $remainingDays);
        } else {
            $graceMessage = $this->translate('interstitial.grace.required', 'Passkey setup is now required.');
        }

        $escapedTitle = \htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $escapedDescription = \htmlspecialchars($description, ENT_QUOTES, 'UTF-8');
        $escapedSetupLabel = \htmlspecialchars($setupLabel, ENT_QUOTES, 'UTF-8');
        $escapedGraceMessage = \htmlspecialchars($graceMessage, ENT_QUOTES, 'UTF-8');

        $skipButton = '';
        if ($canSkip) {
            if ($remainingDays > 0) {
                $skipTemplate = $this->translate('interstitial.button.skipRemaining', 'Skip for now (%d days remaining)');
                $skipLabel = \sprintf($skipTemplate, $remainingDays);
            } else {
                $skipLabel = $this->translate('interstitial.button.skip', 'Skip for now');
            }
            $escapedSkipLabel = \htmlspecialchars($skipLabel, ENT_QUOTES, 'UTF-8');

            $skipButton = <<<HTML
                        <form method="post" action="{$escapedBackendPath}" class="skip-form">
                            <input type="hidden" name="passkey_setup_skip" value="1" />
                            <input type="hidden" name="passkey_setup_nonce" value="{$escapedNonce}" />
                            <button type="submit" class="btn-skip">{$escapedSkipLabel}</button>
                        </form>
HTML;
        }

        $htmlLang = 'en';
        $lang = $GLOBALS['LANG'] ?? null;
        if ($lang instanceof LanguageService) {
            $locale = $lang->getLocale();
            if ($locale !== null) {
                $langCode = $locale->getLanguageCode();
                if ($langCode !== '') {
                    $htmlLang = $langCode;
                }
            }
        }
        $escapedHtmlLang = \htmlspecialchars($htmlLang, ENT_QUOTES, 'UTF-8');
        $escapedColorScheme = \htmlspecialchars($colorScheme, ENT_QUOTES, 'UTF-8');

        $html = <<<HTML
<!DOCTYPE html>
<html lang="{$escapedHtmlLang}" data-color-scheme="{$escapedColorScheme}">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>{$escapedTitle}</title>
    <style>
        /* Scheme-aware palette: light defaults, dark values applied either by
           the OS preference (data-color-scheme="auto") or the user's explicit
           TYPO3 backend color scheme setting. Brand teal (#2F99A4) accents. */
        :root {
            color-scheme: light dark;
            --int-bg: #ffffff;
            --int-text: #313131;
            --int-text-strong: #000000;
            --int-text-muted: #6a6a6a;
            --int-surface: #f5f5f5;
            --int-border: #cccccc;
            --int-accent: #2F99A4;
            --int-accent-text: #ffffff;
        }
        :root[data-color-scheme="light"] {
            color-scheme: light;
        }
        @media (prefers-color-scheme: dark) {
            :root:not([data-color-scheme="light"]) {
                --int-bg: #1e1e1e;
                --int-text: #e0e0e0;
                --int-text-strong: #ffffff;
                --int-text-muted: #b0b0b0;
                --int-surface: #2a2a2a;
                --int-border: #444444;
            }
        }
        :root[data-color-scheme="dark"] {
            color-scheme: dark;
            --int-bg: #1e1e1e;
            --int-text: #e0e0e0;
            --int-text-strong: #ffffff;
            --int-text-muted: #b0b0b0;
            --int-surface: #2a2a2a;
            --int-border: #444444;
        }
        body {
            margin: 0;
            padding: 0;
            background: var(--int-bg);
            color: var(--int-text);
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
            color: var(--int-text-strong);
        }
        .description {
            font-size: 15px;
            line-height: 1.6;
            color: var(--int-text-muted);
            margin-bottom: 24px;
        }
        .grace-period {
            font-size: 14px;
            padding: 12px 20px;
            border-radius: 6px;
            margin-bottom: 32px;
            background: var(--int-surface);
            border: 1px solid var(--int-border);
        }
        .actions {
            display: flex;
            flex-direction: column;
            gap: 12px;
            align-items: center;
        }
        .skip-form {
            display: inline;
        }
        .btn-setup {
            display: inline-block;
            padding: 12px 32px;
            background: var(--int-accent);
            color: var(--int-accent-text);
            border: none;
            border-radius: 4px;
            font-size: 15px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
        }
        .btn-setup:hover {
            filter: brightness(0.92);
        }
        .btn-skip {
            padding: 10px 24px;
            background: transparent;
            color: var(--int-text-muted);
            border: 1px solid var(--int-border);
            border-radius: 4px;
            font-size: 14px;
            cursor: pointer;
            text-decoration: none;
        }
        .btn-setup:focus-visible,
        .btn-skip:focus-visible {
            outline: 2px solid var(--int-accent);
            outline-offset: 2px;
        }
    </style>
</head>
<body>
    <main class="interstitial-container" role="main">
        <h1>{$escapedTitle}</h1>
        <p class="description">{$escapedDescription}</p>
        <div class="grace-period">{$escapedGraceMessage}</div>
        <div class="actions">
            <a href="{$escapedSetupUrl}" class="btn-setup" autofocus>{$escapedSetupLabel}</a>
            {$skipButton}
        </div>
    </main>
</body>
</html>
HTML;

        return new HtmlResponse($html);
    }

}
