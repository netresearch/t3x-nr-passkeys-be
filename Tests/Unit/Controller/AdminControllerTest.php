<?php

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Tests\Unit\Controller;

use Netresearch\NrPasskeysBe\Controller\AdminController;
use Netresearch\NrPasskeysBe\Domain\Model\Credential;
use Netresearch\NrPasskeysBe\Service\CredentialRepository;
use Netresearch\NrPasskeysBe\Service\RateLimiterService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;

#[CoversClass(AdminController::class)]
final class AdminControllerTest extends TestCase
{
    private AdminController $subject;

    private CredentialRepository&MockObject $credentialRepository;

    private RateLimiterService&MockObject $rateLimiterService;

    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->credentialRepository = $this->createMock(CredentialRepository::class);
        $this->rateLimiterService = $this->createMock(RateLimiterService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->subject = new AdminController(
            $this->credentialRepository,
            $this->rateLimiterService,
            $this->logger,
        );
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER']);
        parent::tearDown();
    }

    #[Test]
    public function listActionAsAdmin(): void
    {
        $this->setUpAdminUser(1, 'superadmin');

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn(['beUserUid' => '42']);

        $cred1 = new Credential(
            uid: 10,
            beUser: 42,
            label: 'Key 1',
            createdAt: 1700000000,
            lastUsedAt: 1700001000,
        );
        $cred2 = new Credential(
            uid: 11,
            beUser: 42,
            label: 'Key 2',
            createdAt: 1700002000,
            lastUsedAt: 0,
            revokedAt: 1700003000,
            revokedBy: 1,
        );

        $this->credentialRepository
            ->expects(self::once())
            ->method('findAllByBeUser')
            ->with(42)
            ->willReturn([$cred1, $cred2]);

        $response = $this->subject->listAction($request);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeResponse($response);
        self::assertSame(42, $body['beUserUid']);
        self::assertSame(2, $body['count']);
        self::assertCount(2, $body['credentials']);

        // First credential: not revoked
        self::assertSame(10, $body['credentials'][0]['uid']);
        self::assertSame('Key 1', $body['credentials'][0]['label']);
        self::assertSame(1700000000, $body['credentials'][0]['createdAt']);
        self::assertSame(1700001000, $body['credentials'][0]['lastUsedAt']);
        self::assertFalse($body['credentials'][0]['isRevoked']);

        // Second credential: revoked
        self::assertSame(11, $body['credentials'][1]['uid']);
        self::assertTrue($body['credentials'][1]['isRevoked']);
        self::assertSame(1700003000, $body['credentials'][1]['revokedAt']);
        self::assertSame(1, $body['credentials'][1]['revokedBy']);
    }

    #[Test]
    public function listActionAsNonAdmin(): void
    {
        $this->setUpNonAdminUser(42, 'editor');

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn(['beUserUid' => '42']);

        $this->credentialRepository
            ->expects(self::never())
            ->method('findAllByBeUser');

        $response = $this->subject->listAction($request);

        self::assertSame(403, $response->getStatusCode());
        $body = $this->decodeResponse($response);
        self::assertSame('Unauthorized', $body['error']);
    }

    #[Test]
    public function removeActionSuccess(): void
    {
        $this->setUpAdminUser(1, 'superadmin');

        $request = $this->createJsonRequest([
            'beUserUid' => 42,
            'credentialUid' => 10,
        ]);

        $cred = new Credential(uid: 10, beUser: 42, label: 'Key 1');
        $this->credentialRepository
            ->expects(self::once())
            ->method('findByUidAndBeUser')
            ->with(10, 42)
            ->willReturn($cred);

        $this->credentialRepository
            ->expects(self::once())
            ->method('revoke')
            ->with(10, 1);

        $this->logger
            ->expects(self::once())
            ->method('info')
            ->with('Admin revoked passkey', self::callback(static function (array $context): bool {
                return $context['admin_uid'] === 1
                    && $context['be_user_uid'] === 42
                    && $context['credential_uid'] === 10;
            }));

        $response = $this->subject->removeAction($request);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeResponse($response);
        self::assertSame('ok', $body['status']);
    }

    #[Test]
    public function removeActionCredentialNotFound(): void
    {
        $this->setUpAdminUser(1, 'superadmin');

        $request = $this->createJsonRequest([
            'beUserUid' => 42,
            'credentialUid' => 999,
        ]);

        // User 42 has credential 10, but not 999
        $this->credentialRepository
            ->expects(self::once())
            ->method('findByUidAndBeUser')
            ->with(999, 42)
            ->willReturn(null);

        $this->credentialRepository
            ->expects(self::never())
            ->method('revoke');

        $response = $this->subject->removeAction($request);

        self::assertSame(404, $response->getStatusCode());
        $body = $this->decodeResponse($response);
        self::assertSame('Credential not found for this user', $body['error']);
    }

    #[Test]
    public function unlockActionSuccess(): void
    {
        $this->setUpAdminUser(1, 'superadmin');

        $request = $this->createJsonRequest([
            'beUserUid' => 42,
            'username' => 'lockeduser',
        ]);

        $this->rateLimiterService
            ->expects(self::once())
            ->method('resetLockout')
            ->with('lockeduser');

        $this->logger
            ->expects(self::once())
            ->method('info')
            ->with('Admin unlocked user account', self::callback(static function (array $context): bool {
                return $context['admin_uid'] === 1
                    && $context['be_user_uid'] === 42
                    && $context['username'] === 'lockeduser';
            }));

        $response = $this->subject->unlockAction($request);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeResponse($response);
        self::assertSame('ok', $body['status']);
    }

    /**
     * Set up GLOBALS['BE_USER'] as an admin user.
     */
    private function setUpAdminUser(int $uid, string $username): void
    {
        $backendUser = $this->createMock(\TYPO3\CMS\Core\Authentication\BackendUserAuthentication::class);
        $backendUser->user = [
            'uid' => $uid,
            'username' => $username,
            'admin' => 1,
        ];
        $backendUser->method('isAdmin')->willReturn(true);
        $GLOBALS['BE_USER'] = $backendUser;
    }

    /**
     * Set up GLOBALS['BE_USER'] as a non-admin user.
     */
    private function setUpNonAdminUser(int $uid, string $username): void
    {
        $backendUser = $this->createMock(\TYPO3\CMS\Core\Authentication\BackendUserAuthentication::class);
        $backendUser->user = [
            'uid' => $uid,
            'username' => $username,
            'admin' => 0,
        ];
        $backendUser->method('isAdmin')->willReturn(false);
        $GLOBALS['BE_USER'] = $backendUser;
    }

    /**
     * Create a mock ServerRequestInterface with JSON body data.
     *
     * @param array<string, mixed> $data
     */
    private function createJsonRequest(array $data): ServerRequestInterface&MockObject
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn($data);

        $stream = $this->createMock(StreamInterface::class);
        $stream->method('__toString')->willReturn(\json_encode($data, JSON_THROW_ON_ERROR));
        $request->method('getBody')->willReturn($stream);

        return $request;
    }

    #[Test]
    public function removeActionAsNonAdmin(): void
    {
        $this->setUpNonAdminUser(42, 'editor');

        $request = $this->createJsonRequest([
            'beUserUid' => 42,
            'credentialUid' => 10,
        ]);

        $this->credentialRepository
            ->expects(self::never())
            ->method('findByUidAndBeUser');

        $this->credentialRepository
            ->expects(self::never())
            ->method('revoke');

        $response = $this->subject->removeAction($request);

        self::assertSame(403, $response->getStatusCode());
        $body = $this->decodeResponse($response);
        self::assertSame('Unauthorized', $body['error']);
    }

    #[Test]
    public function unlockActionAsNonAdmin(): void
    {
        $this->setUpNonAdminUser(42, 'editor');

        $request = $this->createJsonRequest([
            'beUserUid' => 42,
            'username' => 'lockeduser',
        ]);

        $this->rateLimiterService
            ->expects(self::never())
            ->method('resetLockout');

        $response = $this->subject->unlockAction($request);

        self::assertSame(403, $response->getStatusCode());
        $body = $this->decodeResponse($response);
        self::assertSame('Unauthorized', $body['error']);
    }

    #[Test]
    public function listActionWithMissingBeUserUid(): void
    {
        $this->setUpAdminUser(1, 'superadmin');

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn([]);

        $this->credentialRepository
            ->expects(self::never())
            ->method('findAllByBeUser');

        $response = $this->subject->listAction($request);

        self::assertSame(400, $response->getStatusCode());
        $body = $this->decodeResponse($response);
        self::assertSame('Missing beUserUid parameter', $body['error']);
    }

    #[Test]
    public function removeActionWithMissingFields(): void
    {
        $this->setUpAdminUser(1, 'superadmin');

        $request = $this->createJsonRequest([]);

        $this->credentialRepository
            ->expects(self::never())
            ->method('findByUidAndBeUser');

        $response = $this->subject->removeAction($request);

        self::assertSame(400, $response->getStatusCode());
        $body = $this->decodeResponse($response);
        self::assertSame('Missing required fields', $body['error']);
    }

    #[Test]
    public function unlockActionWithMissingFields(): void
    {
        $this->setUpAdminUser(1, 'superadmin');

        $request = $this->createJsonRequest([]);

        $this->rateLimiterService
            ->expects(self::never())
            ->method('resetLockout');

        $response = $this->subject->unlockAction($request);

        self::assertSame(400, $response->getStatusCode());
        $body = $this->decodeResponse($response);
        self::assertSame('Missing required fields', $body['error']);
    }

    #[Test]
    public function listActionWithoutBeUser(): void
    {
        // Do NOT set $GLOBALS['BE_USER']
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn(['beUserUid' => '42']);

        $response = $this->subject->listAction($request);

        self::assertSame(403, $response->getStatusCode());
        $body = $this->decodeResponse($response);
        self::assertSame('Unauthorized', $body['error']);
    }

    #[Test]
    public function removeActionWithoutBeUser(): void
    {
        // Do NOT set $GLOBALS['BE_USER']
        $request = $this->createJsonRequest([
            'beUserUid' => 42,
            'credentialUid' => 10,
        ]);

        $response = $this->subject->removeAction($request);

        self::assertSame(403, $response->getStatusCode());
        $body = $this->decodeResponse($response);
        self::assertSame('Unauthorized', $body['error']);
    }

    #[Test]
    public function unlockActionWithoutBeUser(): void
    {
        // Do NOT set $GLOBALS['BE_USER']
        $request = $this->createJsonRequest([
            'beUserUid' => 42,
            'username' => 'lockeduser',
        ]);

        $response = $this->subject->unlockAction($request);

        self::assertSame(403, $response->getStatusCode());
        $body = $this->decodeResponse($response);
        self::assertSame('Unauthorized', $body['error']);
    }

    /**
     * Decode a PSR-7 response body as JSON.
     *
     * @return array<string, mixed>
     */
    private function decodeResponse(\Psr\Http\Message\ResponseInterface $response): array
    {
        $body = (string) $response->getBody();
        $decoded = \json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        \assert(\is_array($decoded));
        return $decoded;
    }
}
