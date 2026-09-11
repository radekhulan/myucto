<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Action\Admin;

use MyInvoice\Action\Admin\BackupDownloadAction;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\MfaStepUpService;
use MyInvoice\Service\Auth\ReauthException;
use MyInvoice\Service\Auth\SensitiveOperationReauth;
use MyInvoice\Service\Backup\BackupArchiveCatalog;
use MyInvoice\Service\IpMatcher;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Heslo k šifrovaným zálohám otevírá dump celé databáze. Tenhle test hlídá to
 * jediné, co u něj nesmí selhat: nevydá se bez čerstvého ověření, a když se
 * vydá, jde o skutečné heslo z konfigurace — ne prázdný řetězec, který by
 * uživatel marně zkoušel do 7-Zipu.
 */
#[AllowMockObjectsWithoutExpectations]
final class BackupPasswordRevealTest extends TestCase
{
    private const SECRET = 'Synthetic-Backup-Secret-2026';

    public function testPasswordIsReturnedOnlyAfterFreshReauthentication(): void
    {
        $reauth = $this->createMock(SensitiveOperationReauth::class);
        $reauth->expects(self::once())
            ->method('verify')
            ->with(
                self::anything(),
                17,
                ['password' => 'x'],
                'backup_password_reveal',
                MfaStepUpService::OPERATION_BACKUP_PASSWORD,
                'backup.reauth_failed',
                self::anything(),
            );

        $payload = $this->reveal($reauth, ['password' => 'x']);

        self::assertTrue($payload['encrypted']);
        self::assertSame(self::SECRET, $payload['password']);
        self::assertSame('AES-256', $payload['cipher']);
    }

    /** Selhané ověření nesmí vydat nic a musí si odnést svůj HTTP stav. */
    public function testFailedReauthenticationYieldsItsOwnStatusAndNoPassword(): void
    {
        $reauth = $this->createMock(SensitiveOperationReauth::class);
        $reauth->method('verify')->willThrowException(
            new ReauthException('invalid_password', 'Neplatné heslo.', 401),
        );

        $response = $this->call($reauth, ['password' => 'spatne']);
        $body = (string) $response->getBody();

        self::assertSame(401, $response->getStatusCode());
        self::assertStringNotContainsString(self::SECRET, $body);
        self::assertStringContainsString('invalid_password', $body);
    }

    /** Bez superadmina se k ověření vůbec nedojde. */
    public function testNonSuperadminIsRefusedBeforeAnyVerification(): void
    {
        $reauth = $this->createMock(SensitiveOperationReauth::class);
        $reauth->expects(self::never())->method('verify');

        $response = $this->action($reauth)->revealPassword(
            $this->request(['password' => 'x'])->withAttribute(
                AuthMiddleware::ATTR_USER,
                ['id' => 17, 'role' => 'accountant'],
            ),
            (new ResponseFactory())->createResponse(),
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertStringNotContainsString(self::SECRET, (string) $response->getBody());
    }

    /**
     * Nešifrované zálohy nejsou chyba ověření — je to odpověď, kterou musí
     * provozovatel dostat, a to bez vymyšleného hesla.
     */
    public function testUnencryptedInstallationAnswersInsteadOfInventingAPassword(): void
    {
        $reauth = $this->createMock(SensitiveOperationReauth::class);
        $response = $this->action($reauth, password: '')->revealPassword(
            $this->request(['password' => 'x']),
            (new ResponseFactory())->createResponse(),
        );
        $payload = json_decode((string) $response->getBody(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($payload['encrypted']);
        self::assertNull($payload['password']);
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function reveal(SensitiveOperationReauth $reauth, array $body): array
    {
        return (array) json_decode((string) $this->call($reauth, $body)->getBody(), true);
    }

    private function call(SensitiveOperationReauth $reauth, array $body): \Psr\Http\Message\ResponseInterface
    {
        return $this->action($reauth)->revealPassword(
            $this->request($body),
            (new ResponseFactory())->createResponse(),
        );
    }

    private function action(
        SensitiveOperationReauth $reauth,
        string $password = self::SECRET,
    ): BackupDownloadAction {
        $config = new Config(['cron' => ['backup' => ['password' => $password]]]);

        return new BackupDownloadAction(
            new BackupArchiveCatalog($config),
            $config,
            $reauth,
            $this->createMock(ActivityLogger::class),
            new IpMatcher(),
        );
    }

    /** @param array<string,mixed> $body */
    private function request(array $body): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('POST', 'http://localhost/api/admin/backups/password')
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute(AuthMiddleware::ATTR_TOKEN, 'session-token')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 17, 'role' => 'admin'])
            ->withParsedBody($body);
    }
}
