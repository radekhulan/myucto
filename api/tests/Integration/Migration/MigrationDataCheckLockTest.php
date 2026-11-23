<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Migration\Check\MigrationDataCheckService;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Oprava dat po převodu drží stav období firmy zamčený do commitu: podání DPH, KH či OSS
 * nemůže vzniknout ani se označit mezi kontrolou období a zápisem opravy. Druhé spojení
 * se založením podání čeká na zámek (vyprší po sekundě).
 */
#[Group('integration')]
final class MigrationDataCheckLockTest extends TestCase
{
    public function testSubmissionWaitsForFixTransaction(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php missing');
        }
        $c = Bootstrap::buildContainer();
        $pdo = $c->get(Connection::class)->pdo();
        $supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($supplierId === 0) {
            $this->markTestSkipped('Chybí dodavatel (ci-seed).');
        }
        $config = $c->get(Config::class);
        $other = new PDO('mysql:host=' . $config->get('db.host') . ';port=' . $config->get('db.port', 3306) . ';dbname=' . $config->get('db.name') . ';charset=utf8mb4',
            (string) $config->get('db.user'), (string) $config->get('db.pass', ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $other->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $pdo->beginTransaction();
        try {
            $lock = new \ReflectionMethod(MigrationDataCheckService::class, 'lockPeriodState');
            $lock->invoke($c->get(MigrationDataCheckService::class), $supplierId);
            try {
                $other->prepare("INSERT INTO tax_submissions (supplier_id, form_code, period_year, period_month, xml_content, xml_size_bytes, xml_sha256, status, submitted_at)
                    VALUES (?, 'ossei1', 2099, NULL, '<x/>', 4, ?, 'submitted', NOW())")->execute([$supplierId, hash('sha256', 'mchk-lock-' . $supplierId)]);
                $other->prepare("DELETE FROM tax_submissions WHERE supplier_id = ? AND period_year = 2099 AND form_code = 'ossei1'")->execute([$supplierId]);
                self::fail('Podání prošlo přes zámek opravy dat po převodu.');
            } catch (\PDOException $e) {
                self::assertSame(1205, (int) ($e->errorInfo[1] ?? 0), $e->getMessage());
            }
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }
}
