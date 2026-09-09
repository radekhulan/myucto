<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Security;

use MyInvoice\Action\Document\DocumentJobsAction;
use MyInvoice\Action\Auth\MfaStepUpAction;
use MyInvoice\Action\WorkReport\PublicWorkReportGetAction;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\DocumentFolderRepository;
use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Repository\WorkReportLinkRepository;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Security\PermissionResolver;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\EmailOtpService;
use MyInvoice\Service\Auth\BruteForceGuard;
use MyInvoice\Service\Auth\MfaRecoveryCodeService;
use MyInvoice\Service\Auth\MfaStepUpService;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Auth\TotpService;
use MyInvoice\Service\Document\DocumentStorage;
use MyInvoice\Service\Mail\Mailer;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Tenant\PublicTenantGuard;
use MyInvoice\Service\Tenant\SupplierAccess;
use MyInvoice\Service\Tenant\SupplierAccessResolver;
use MyInvoice\Service\WorkReport\WorkReportLinkService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class SecurityReportAuthBoundariesTest extends TestCase
{
    public function testUsedLoginTotpCannotCreatePersistentCredentialProof(): void
    {
        $pdo = \PDO::connect('sqlite::memory:');
        $pdo->exec('CREATE TABLE totp_used_steps (secret_fingerprint TEXT, time_step INTEGER, PRIMARY KEY (secret_fingerprint, time_step))');
        $pdo->exec('CREATE TABLE users (id INTEGER, totp_secret TEXT, totp_enabled INTEGER, is_active INTEGER)');
        $pdo->exec("INSERT INTO users VALUES (1, 'synthetic-encrypted-secret', 1, 1)");
        $db = $this->createStub(Connection::class);
        $db->method('pdo')->willReturn($pdo);
        $secret = TotpService::generateSecret();
        $totp = new TotpService();
        $code = $totp->currentCode($secret);
        self::assertTrue($totp->verifyAndConsume($db, $secret, $code));
        $stepUp = $this->createMock(MfaStepUpService::class);
        $stepUp->method('assertAllowed')->willReturn('api_token.create');
        $stepUp->expects(self::never())->method('issue');
        $crypto = $this->createStub(SecretEncryption::class);
        $crypto->method('decrypt')->willReturn($secret);
        $action = new MfaStepUpAction($db, $stepUp, $totp, $crypto, $this->createStub(BruteForceGuard::class), $this->createStub(ActivityLogger::class), $this->createStub(IpMatcher::class), $this->createStub(MfaRecoveryCodeService::class));
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/auth/mfa/totp')
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 1])
            ->withAttribute(AuthMiddleware::ATTR_TOKEN, str_repeat('a', 64))
            ->withParsedBody(['operation' => 'api_token.create', 'code' => $code]);
        self::assertSame(401, $action->totp($request, new Response())->getStatusCode());
    }

    public function testTotpCanOnlyAuthorizeOneOperationAcrossServiceInstances(): void
    {
        $pdo = \PDO::connect('sqlite::memory:');
        $pdo->exec('CREATE TABLE totp_used_steps (secret_fingerprint TEXT, time_step INTEGER, PRIMARY KEY (secret_fingerprint, time_step))');
        $db = $this->createStub(Connection::class);
        $db->method('pdo')->willReturn($pdo);
        $secret = TotpService::generateSecret();
        $totp = new TotpService();
        $code = $totp->currentCode($secret);
        self::assertTrue($totp->verifyAndConsume($db, $secret, $code));
        self::assertFalse((new TotpService())->verifyAndConsume($db, $secret, $code));
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM totp_used_steps')->fetchColumn());
        self::assertSame(hash('sha256', $secret), $pdo->query('SELECT secret_fingerprint FROM totp_used_steps')->fetchColumn());
    }

    public function testTotpConsumesTheMatchedSkewStepAndRejectsExpiredCodes(): void
    {
        $pdo = \PDO::connect('sqlite::memory:');
        $pdo->exec('CREATE TABLE totp_used_steps (secret_fingerprint TEXT, time_step INTEGER, PRIMARY KEY (secret_fingerprint, time_step))');
        $db = $this->createStub(Connection::class);
        $db->method('pdo')->willReturn($pdo);
        $secret = TotpService::generateSecret();
        $totp = new TotpService();
        $generate = new \ReflectionMethod(TotpService::class, 'generateAt');
        $step = (int) floor(time() / 30);
        $code = $generate->invoke($totp, $secret, $step + 1);
        self::assertTrue($totp->verifyAndConsume($db, $secret, $code));
        self::assertFalse($totp->verifyAndConsume($db, $secret, $code));
        self::assertSame($step + 1, (int) $pdo->query('SELECT time_step FROM totp_used_steps')->fetchColumn());
        self::assertFalse($totp->verifyAndConsume($db, $secret, 'not-a-code'));
    }

    #[DataProvider('publicIdentities')]
    public function testPublicReportRequiresAuthorizedStaffOrLinkSession(string $method, bool $denied, string $type, array $permissions, bool $expected): void
    {
        $service = $this->createStub(WorkReportLinkService::class);
        $service->method('findActiveLink')->willReturn(['id' => 1, 'supplier_id' => 2, 'scope' => 'client']);
        $service->method('buildPreview')->willReturn(['private' => 'report']);
        $guard = $this->createStub(PublicTenantGuard::class);
        $guard->method('allows')->willReturn(true);
        $access = $this->createStub(SupplierAccessResolver::class);
        $access->method('resolve')->willReturn(new SupplierAccess(2, $denied, null));
        $roles = $this->createStub(PermissionResolver::class);
        $roles->method('resolve')->willReturn(new EffectiveRole(1, 'Test', $type, true, $permissions));
        $action = new PublicWorkReportGetAction($service, new Config([]), $guard, $access, $roles);
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/public/work-report/' . str_repeat('a', 64))
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 1])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, $method);
        $response = $action($request, new Response(), ['token' => str_repeat('a', 64)]);
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame($expected, $body['requires_auth']);
        self::assertSame(!$expected, array_key_exists('preview', $body));
    }

    public static function publicIdentities(): array
    {
        return [
            'foreign staff' => ['session', true, 'staff', ['invoices' => 1], true],
            'client' => ['session', false, 'client', ['invoices' => 1], true],
            'staff without invoices' => ['session', false, 'staff', [], true],
            'bearer' => ['bearer', false, 'staff', ['invoices' => 1], true],
            'authorized staff' => ['session', false, 'staff', ['invoices' => 1], false],
        ];
    }

    #[DataProvider('otpRaces')]
    public function testEmailOtpUsesAtomicConsumptionAndAttemptCounts(string $interleaved, string $code, int $attempts, bool $accepted): void
    {
        $pdo = \PDO::connect('sqlite::memory:');
        $pdo->createFunction('NOW', static fn() => '2026-09-09 12:00:00');
        $pdo->exec('CREATE TABLE login_otps (id INTEGER, user_id INTEGER, code_hash TEXT, attempts INTEGER, created_at TEXT, expires_at TEXT, used_at TEXT)');
        $pdo->prepare('INSERT INTO login_otps VALUES (1, 1, ?, 0, ?, ?, NULL)')
            ->execute([hash('sha256', '123456'), '2026-09-09 11:59:00', '2026-09-09 12:10:00']);
        $pdo->createFunction('UNIX_TIMESTAMP', static function () use ($pdo, $interleaved): int {
            $pdo->exec('UPDATE login_otps SET ' . $interleaved . ' WHERE id = 1');
            return 1;
        });
        $db = $this->createStub(Connection::class);
        $db->method('pdo')->willReturn($pdo);
        $service = new EmailOtpService($db, new Config([]), $this->createStub(Mailer::class), new NullLogger());
        self::assertSame($accepted, $service->verify(1, $code));
        self::assertSame($attempts, (int) $pdo->query('SELECT attempts FROM login_otps')->fetchColumn());
    }

    #[DataProvider('otpRaces')]
    public function testWorkReportOtpUsesAtomicConsumptionAndAttemptCounts(string $interleaved, string $code, int $attempts, bool $accepted): void
    {
        $pdo = \PDO::connect('sqlite::memory:');
        $pdo->createFunction('NOW', static fn() => '2026-09-09 12:00:00');
        $pdo->exec('CREATE TABLE work_report_link_codes (id INTEGER, link_id INTEGER, email TEXT, code_hash TEXT, attempts INTEGER, created_at TEXT, expires_at TEXT, used_at TEXT)');
        $pdo->exec('CREATE TABLE clients (id INTEGER, main_email TEXT)');
        $pdo->exec("INSERT INTO clients VALUES (1, 'synthetic@example.test')");
        $pdo->exec('CREATE TABLE client_email_contacts (client_id INTEGER, email TEXT, is_active INTEGER)');
        $pdo->exec('CREATE TABLE work_report_link_sessions (link_id INTEGER, email TEXT, session_hash TEXT, last_seen_at TEXT, ip TEXT)');
        $pdo->prepare('INSERT INTO work_report_link_codes VALUES (1, 1, ?, ?, 0, ?, ?, NULL)')
            ->execute(['synthetic@example.test', hash('sha256', '123456'), '2026-09-09 11:59:00', '2026-09-09 12:10:00']);
        $pdo->createFunction('UNIX_TIMESTAMP', static function () use ($pdo, $interleaved): int {
            $pdo->exec('UPDATE work_report_link_codes SET ' . $interleaved . ' WHERE id = 1');
            return 1;
        });
        $db = $this->createStub(Connection::class);
        $db->method('pdo')->willReturn($pdo);
        $service = new \ReflectionClass(WorkReportLinkService::class)->newInstanceWithoutConstructor();
        foreach (['db' => $db, 'config' => new Config([]), 'links' => new WorkReportLinkRepository($db)] as $name => $value) {
            new \ReflectionProperty(WorkReportLinkService::class, $name)->setValue($service, $value);
        }
        $result = $service->verifyCode(['id' => 1, 'client_id' => 1, 'project_id' => null], 'synthetic@example.test', $code, '127.0.0.1');
        self::assertSame($accepted, $result !== null);
        self::assertSame((int) $accepted, (int) $pdo->query('SELECT COUNT(*) FROM work_report_link_sessions')->fetchColumn());
        self::assertSame($attempts, (int) $pdo->query('SELECT attempts FROM work_report_link_codes')->fetchColumn());
    }

    public static function otpRaces(): array
    {
        return [
            'consumed concurrently' => ["used_at = '2026-09-09 12:00:00'", '123456', 0, false],
            'attempt cap reached concurrently' => ['attempts = 5', '123456', 5, false],
            'failed attempts accumulate' => ['attempts = 2', '000000', 3, false],
            'valid unused code' => ['attempts = 0', '123456', 0, true],
        ];
    }

    #[DataProvider('uploadMethods')]
    public function testForeignUploadJobIsRejectedBeforeFilesystemAccess(string $method): void
    {
        $pdo = \PDO::connect('sqlite::memory:');
        $pdo->exec('CREATE TABLE import_jobs (id INTEGER, supplier_id INTEGER, source TEXT, status TEXT, created_by INTEGER, params TEXT, processed INTEGER DEFAULT 0, created_count INTEGER DEFAULT 0, skipped_count INTEGER DEFAULT 0, failed_count INTEGER DEFAULT 0, cancel_requested INTEGER DEFAULT 0, total_items INTEGER)');
        $pdo->exec("INSERT INTO import_jobs (id, supplier_id, source, status, created_by, params) VALUES (42, 1, 'document_folder_import', 'queued', 2, '{}')");
        $db = $this->createStub(Connection::class);
        $db->method('pdo')->willReturn($pdo);
        $action = new DocumentJobsAction(new ImportJobRepository($db), $this->createStub(DocumentStorage::class), new DocumentFolderRepository($db), $this->createStub(ActivityLogger::class));
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/documents/upload/test')
            ->withQueryParams(['job_id' => 42])
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 1)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 3, 'role' => 'accountant']);
        self::assertSame(404, $action->$method($request, new Response())->getStatusCode());
    }

    public static function uploadMethods(): array
    {
        return [['uploadChunkBytes'], ['uploadChunkFiles'], ['uploadFinish']];
    }
}
