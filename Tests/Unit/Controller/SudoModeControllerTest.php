<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Tests\Unit\Controller;

use Netresearch\NrPasskeysBe\Controller\SudoModeController;
use Netresearch\NrPasskeysBe\Domain\Dto\AssertionOptions;
use Netresearch\NrPasskeysBe\Service\CredentialRepository;
use Netresearch\NrPasskeysBe\Service\RateLimiterService;
use Netresearch\NrPasskeysBe\Service\SudoModeTokenService;
use Netresearch\NrPasskeysBe\Service\WebAuthnService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Session\UserSession;
use Webauthn\PublicKeyCredentialRequestOptions;

use function base64_encode;
use function chr;
use function json_decode;
use function json_encode;
use function random_bytes;
use function rtrim;
use function str_repeat;
use function strtr;

#[CoversClass(SudoModeController::class)]
final class SudoModeControllerTest extends TestCase
{
    private WebAuthnService&MockObject $webAuthnService;

    private CredentialRepository&MockObject $credentialRepository;

    private RateLimiterService&MockObject $rateLimiter;

    private SudoModeTokenService&MockObject $tokenService;

    private SudoModeController $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->webAuthnService      = $this->createMock(WebAuthnService::class);
        $this->credentialRepository = $this->createMock(CredentialRepository::class);
        $this->rateLimiter          = $this->createMock(RateLimiterService::class);
        $this->tokenService         = $this->createMock(SudoModeTokenService::class);
        $this->subject              = new SudoModeController(
            $this->webAuthnService,
            $this->credentialRepository,
            $this->rateLimiter,
            $this->tokenService,
            new NullLogger(),
        );

        $backendUser       = $this->createMock(BackendUserAuthentication::class);
        $backendUser->user = ['uid' => 7, 'username' => 'editor', 'realName' => ''];
        $backendUser
            ->method('isAdmin')
            ->willReturn(false);
        $session = $this->createMock(UserSession::class);
        $session
            ->method('getIdentifier')
            ->willReturn('session-a');
        $backendUser
            ->method('getSession')
            ->willReturn($session);
        $GLOBALS['BE_USER'] = $backendUser;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER']);
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $data
     */
    private function request(array $data): ServerRequestInterface
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request
            ->method('getParsedBody')
            ->willReturn($data);
        $stream = $this->createMock(StreamInterface::class);
        $stream
            ->method('__toString')
            ->willReturn(json_encode($data, JSON_THROW_ON_ERROR));
        $request
            ->method('getBody')
            ->willReturn($stream);

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    private static function json(JsonResponse $response): array
    {
        /** @var array<string, mixed> */
        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    #[Test]
    public function withoutAPasskeyNothingIsOffered(): void
    {
        $this->credentialRepository
            ->expects(self::once())
            ->method('countByBeUser')
            ->with(7)
            ->willReturn(0);
        $this->webAuthnService
            ->expects(self::never())
            ->method('createAssertionOptions');

        $response = $this->subject->optionsAction($this->request([]));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['available' => false], self::json($response));
    }

    #[Test]
    public function theOptionsAreForTheLoggedInUserOnly(): void
    {
        $this->credentialRepository
            ->method('countByBeUser')
            ->willReturn(1);
        $options = PublicKeyCredentialRequestOptions::create(challenge: random_bytes(32), rpId: 'example.com');
        $this->webAuthnService
            ->expects(self::once())
            ->method('createAssertionOptions')
            ->with('editor', 7)
            ->willReturn(new AssertionOptions(options: $options, challengeToken: 'ct'));
        $this->webAuthnService
            ->method('serializeRequestOptions')
            ->willReturn('{"challenge":"abc"}');

        $data = self::json($this->subject->optionsAction($this->request(['username' => 'admin'])));

        self::assertTrue($data['available']);
        self::assertSame('ct', $data['challengeToken']);
    }

    #[Test]
    public function withoutABackendUserTheAnswerIs401(): void
    {
        unset($GLOBALS['BE_USER']);

        self::assertSame(401, $this->subject
            ->optionsAction($this->request([]))
            ->getStatusCode());
        self::assertSame(401, $this->subject
            ->verifyAction($this->request([]))
            ->getStatusCode());
    }

    /**
     * @return array<string, mixed>
     */
    private static function credential(bool $userVerified): array
    {
        $flags             = $userVerified ? 0x05 : 0x01;
        $authenticatorData = str_repeat("\0", 32) . chr($flags) . "\0\0\0\1";

        return [
            'id'       => 'x',
            'response' => ['authenticatorData' => rtrim(strtr(base64_encode($authenticatorData), '+/', '-_'), '=')],
        ];
    }

    #[Test]
    public function theOptionsAlwaysDemandUserVerification(): void
    {
        $this->credentialRepository
            ->method('countByBeUser')
            ->willReturn(1);
        $options = PublicKeyCredentialRequestOptions::create(challenge: random_bytes(32), rpId: 'example.com');
        $this->webAuthnService
            ->method('createAssertionOptions')
            ->willReturn(new AssertionOptions(options: $options, challengeToken: 'ct'));
        $this->webAuthnService
            ->method('serializeRequestOptions')
            ->willReturn('{"challenge":"abc","userVerification":"preferred"}');

        $data = self::json($this->subject->optionsAction($this->request([])));

        self::assertIsArray($data['options']);
        self::assertSame('required', $data['options']['userVerification']);
    }

    #[Test]
    public function anAssertionWithoutUserVerificationYieldsNoToken(): void
    {
        // A key that is merely plugged in proves possession, not the person.
        $this->tokenService
            ->expects(self::never())
            ->method('issue');

        $response = $this->subject->verifyAction($this->request([
            'claim'          => 'claim-1',
            'challengeToken' => 'ct',
            'credential'     => self::credential(false),
        ]));

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function aVerifiedAssertionYieldsATokenForThatClaim(): void
    {
        $this->webAuthnService
            ->expects(self::once())
            ->method('verifyAssertionResponse')
            ->with(self::isString(), 'ct', 7);
        $this->tokenService
            ->expects(self::once())
            ->method('issue')
            ->with(7, 'session-a', 'claim-1')
            ->willReturn('nrpk-sudo:token');

        $response = $this->subject->verifyAction($this->request([
            'claim'          => 'claim-1',
            'challengeToken' => 'ct',
            'credential'     => self::credential(true),
        ]));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['token' => 'nrpk-sudo:token'], self::json($response));
    }

    #[Test]
    public function aFailedAssertionYieldsNoToken(): void
    {
        $this->webAuthnService
            ->method('verifyAssertionResponse')
            ->willThrowException(new RuntimeException('nope'));
        $this->tokenService
            ->expects(self::never())
            ->method('issue');

        $response = $this->subject->verifyAction($this->request([
            'claim'          => 'claim-1',
            'challengeToken' => 'ct',
            'credential'     => ['id' => 'x'],
        ]));

        self::assertSame(403, $response->getStatusCode());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function incompleteBodies(): iterable
    {
        yield 'no claim' => [['challengeToken' => 'ct', 'credential' => ['id' => 'x']]];

        yield 'claim with odd characters' => [['claim' => 'a b/c', 'challengeToken' => 'ct', 'credential' => ['id' => 'x']]];

        yield 'no challenge token' => [['claim' => 'claim-1', 'credential' => ['id' => 'x']]];

        yield 'credential not an object' => [['claim' => 'claim-1', 'challengeToken' => 'ct', 'credential' => 'x']];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('incompleteBodies')]
    public function anIncompleteRequestIsRejected(array $body): void
    {
        $this->webAuthnService
            ->expects(self::never())
            ->method('verifyAssertionResponse');

        self::assertSame(400, $this->subject
            ->verifyAction($this->request($body))
            ->getStatusCode());
    }

    #[Test]
    public function theRateLimitStopsVerificationBeforeTheAssertionIsChecked(): void
    {
        $this->rateLimiter
            ->method('consumeRateLimit')
            ->willThrowException(new RuntimeException('limit'));
        $this->webAuthnService
            ->expects(self::never())
            ->method('verifyAssertionResponse');

        $response = $this->subject->verifyAction($this->request([
            'claim'          => 'claim-1',
            'challengeToken' => 'ct',
            'credential'     => ['id' => 'x'],
        ]));

        self::assertSame(429, $response->getStatusCode());
    }
}
