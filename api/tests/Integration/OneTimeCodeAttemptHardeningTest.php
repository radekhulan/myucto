<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollDocumentAccessLinkRepository;
use MyInvoice\Service\Auth\EmailOtpService;
use MyInvoice\Service\Auth\TotpService;
use MyInvoice\Service\Mail\Mailer;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Atomické spotřebování pokusů a jednorázovost TOTP nad skutečnou MariaDB.
 * Jednotkové testy běží nad SQLite; tady se ověřuje, že stejné SQL projde
 * i dialektem a omezeními produkční databáze. Vše běží v transakci, která se
 * na konci odvolá, takže v DB nic nezůstane.
 */
#[Group('integration')]
final class OneTimeCodeAttemptHardeningTest extends TestCase
{
    private Connection $db;
    private Config $config;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 3);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php missing');
        }
        try {
            $this->config = Config::load($rootDir);
            $this->db = new Connection($this->config);
            $this->db->pdo()->query('SELECT 1 FROM totp_used_steps LIMIT 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB nedostupná: ' . $e->getMessage());
        }
        $this->db->pdo()->exec('SET FOREIGN_KEY_CHECKS = 0');
        $this->db->pdo()->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->pdo()->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    public function testTotpAcceptsOnlyStrictlyNewerStepOnMariaDb(): void
    {
        $secret = TotpService::generateSecret();
        $totp = new TotpService();
        $generate = new \ReflectionMethod(TotpService::class, 'generateAt');
        $step = (int) floor(time() / 30);

        self::assertTrue($totp->verifyAndConsume($this->db, $secret, $generate->invoke($totp, $secret, $step)));
        self::assertFalse($totp->verifyAndConsume($this->db, $secret, $generate->invoke($totp, $secret, $step)));
        self::assertFalse($totp->verifyAndConsume($this->db, $secret, $generate->invoke($totp, $secret, $step - 1)));
        self::assertTrue($totp->verifyAndConsume($this->db, $secret, $generate->invoke($totp, $secret, $step + 1)));
    }

    public function testPayrollCodeAttemptsCannotExceedPolicyCap(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_document_access_codes (supplier_id, link_id, code_hash, expires_at, created_at, ip)
             VALUES (?, ?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 600 SECOND), UTC_TIMESTAMP(), NULL)'
        )->execute([999001, 999001, hash('sha256', '123456')]);
        $codeId = (int) $pdo->lastInsertId();
        $links = new PayrollDocumentAccessLinkRepository($this->db);

        for ($i = 0; $i < 3; $i++) {
            self::assertTrue($links->reserveCodeAttempt(999001, $codeId, 3));
        }
        self::assertFalse($links->reserveCodeAttempt(999001, $codeId, 3));
        self::assertTrue($links->consumeCode(999001, $codeId));
        self::assertFalse($links->consumeCode(999001, $codeId));
        self::assertFalse($links->reserveCodeAttempt(999001, $codeId, 10));
    }

    public function testEmailOtpCountsEveryComparisonOnMariaDb(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO login_otps (user_id, code_hash, expires_at, ip)
             VALUES (?, ?, NOW() + INTERVAL 10 MINUTE, ?)'
        )->execute([999001, hash('sha256', '123456'), inet_pton('127.0.0.1')]);
        $service = new EmailOtpService(
            $this->db,
            new Config(['auth' => ['email_otp' => ['max_attempts' => 3]]]),
            $this->createStub(Mailer::class),
            new NullLogger(),
        );

        self::assertFalse($service->verify(999001, '000000'));
        self::assertFalse($service->verify(999001, '000001'));
        self::assertTrue($service->verify(999001, '123456'));
        self::assertSame(3, (int) $pdo->query('SELECT attempts FROM login_otps WHERE user_id = 999001')->fetchColumn());
        self::assertFalse($service->verify(999001, '123456'));
    }
}
