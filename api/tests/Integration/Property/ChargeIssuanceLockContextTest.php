<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Property;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Charges\Issuance\IssuanceRepository;
use MyInvoice\Service\Anonymization\DatabaseCloner;
use MyInvoice\Service\Property\Charges\Issuance\IssuancePreviewService;
use MyInvoice\Service\Property\Charges\Issuance\IssuanceUseGuard;
use MyInvoice\Tests\Support\ChargeIssuanceFixture;
use PDO;
use PHPUnit\Framework\TestCase;

final class ChargeIssuanceLockContextTest extends TestCase
{
    use ChargeIssuanceFixture;

    public function testStandaloneClaimGuardLocksCurrentContextBeforeClaimsDespiteOldSnapshot(): void
    {
        $config = Bootstrap::buildContainer()->get(Config::class);
        $source = (string) $config->get('db.name');
        self::assertStringEndsWith('_test', $source);
        self::assertContains($config->get('db.host'), ['localhost', '127.0.0.1', '::1']);
        $owned = 'myucto_s3_lock_' . bin2hex(random_bytes(5)) . '_test';
        $server = new PDO('mysql:host=' . $config->get('db.host') . ';port=' . $config->get('db.port', 3306) . ';charset=utf8mb4',
            $config->get('db.user'), $config->get('db.pass', ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $cloner = new DatabaseCloner($server);
        $plan = $cloner->plan($source);
        $cloner->copyTables($plan, $owned, static function (): void {});
        $cloner->finalize($plan, $owned);
        $connections = [];
        try {
            $data = $config->all(); $data['db']['name'] = $owned;
            for ($i = 0; $i < 2; ++$i) {
                $db = Connection::withoutSharedTestConnection(fn () => new Connection(new Config($data, $config->dataDir())));
                $db->pdo()->exec("SET SESSION sql_mode='STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
                $db->pdo()->exec('SET SESSION innodb_snapshot_isolation=0');
                $db->pdo()->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                $db->pdo()->exec('SET SESSION innodb_lock_wait_timeout=1');
                $connections[] = $db;
            }
            [$a, $b] = $connections;
            $a->pdo()->beginTransaction();
            $a->pdo()->query('SELECT COUNT(*) FROM supplier_accounting_frameworks')->fetchColumn();
            $b->pdo()->beginTransaction();
            $f = $this->issuanceFixture($b);
            $container = Bootstrap::buildContainer(); $container->set(Connection::class, $b);
            $preview = (new IssuancePreviewService($b))->preview($f['supplier'], $f['issue_request']);
            $batch = $container->get(IssuanceRepository::class)->issue($f['supplier'], ['request' => $f['issue_request'], 'preview_hash' => $preview['preview_hash'], 'idempotency_key' => 'synthetic-lock-claims'], $f['actor']);
            $b->pdo()->commit();
            $q = $a->pdo()->prepare('SELECT COUNT(*) FROM unit_charges WHERE supplier_id=?');
            $q->execute([$f['supplier']]); self::assertSame(0, (int) $q->fetchColumn(), 'Caller snapshot predates all claims.');
            self::assertCount(12, (new IssuanceUseGuard($a))->lockClaims($f['supplier']));
            foreach ([
                'SELECT id FROM supplier_accounting_frameworks WHERE supplier_id=? ORDER BY effective_from,id FOR UPDATE',
                'SELECT id FROM supplier WHERE id=? FOR UPDATE',
                'SELECT id FROM dimension_types WHERE supplier_id=? ORDER BY id FOR UPDATE',
                'SELECT id FROM property_objects WHERE supplier_id=? ORDER BY id FOR UPDATE',
                'SELECT id FROM unit_charges WHERE supplier_id=? ORDER BY id FOR UPDATE',
            ] as $sql) {
                $b->pdo()->beginTransaction();
                try {
                    $q = $b->pdo()->prepare($sql); $q->execute([$f['supplier']]); $q->fetchAll();
                    self::fail('Claims were locked without their preceding current context: ' . $sql);
                } catch (\PDOException $e) {
                    self::assertSame(1205, (int) ($e->errorInfo[1] ?? 0));
                } finally {
                    if ($b->pdo()->inTransaction()) $b->pdo()->rollBack();
                }
            }
            self::assertCount(12, $batch['charges']);
        } finally {
            foreach ($connections as $db) { if ($db->pdo()->inTransaction()) $db->pdo()->rollBack(); $db->close(); }
            self::assertMatchesRegularExpression('/^myucto_s3_lock_[a-f0-9]{10}_test$/D', $owned);
            $server->exec('DROP DATABASE ' . DatabaseCloner::quote($owned));
        }
    }
}
