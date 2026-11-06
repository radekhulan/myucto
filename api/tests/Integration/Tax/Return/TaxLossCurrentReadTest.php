<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Tax\Return;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\{TaxConstantsRepository, TaxReturnRepository};
use MyInvoice\Service\Anonymization\DatabaseCloner;
use MyInvoice\Service\Tax\Return\{TaxLossService, TaxReturnException};
use MyInvoice\Tests\Support\OwnedTestDatabaseCleanup;
use PHPUnit\Framework\TestCase;
use PDO;

final class TaxLossCurrentReadTest extends TestCase
{
    public function testExactPlanReadsCommittedClaimsAndEffectiveOwnerAfterEarlierRepeatableReadSnapshot(): void
    {
        $config = Config::load(Bootstrap::rootDir()); $source = $config->get('db.name');
        self::assertStringEndsWith('_test', $source);
        $target = 'myucto_loss_rr_'.bin2hex(random_bytes(5)).'_test';
        $server = new PDO('mysql:host='.$config->get('db.host').';port='.$config->get('db.port',3306).';charset=utf8mb4', $config->get('db.user'), $config->get('db.pass', ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $q = $server->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?'); $q->execute([$target]); self::assertSame(0, (int) $q->fetchColumn());
        $a = null; $b = null;
        try {
            $cloner = new DatabaseCloner($server); $plan = $cloner->plan($source);
            $cloner->copyTables($plan, $target, static function (): void {}); $cloner->finalize($plan, $target);
            $settings = $config->all(); $settings['db']['name'] = $target; $owned = new Config($settings, $config->dataDir());
            $a = Connection::withoutSharedTestConnection(static fn (): Connection => new Connection($owned));
            $b = Connection::withoutSharedTestConnection(static fn (): Connection => new Connection($owned));
            foreach ([$a, $b] as $connection) $connection->pdo()->exec("SET SESSION sql_mode='STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
            $supplier = (int) $b->pdo()->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn(); self::assertGreaterThan(0, $supplier);
            $b->pdo()->prepare("UPDATE supplier SET taxpayer_type='fo' WHERE id=?")->execute([$supplier]);
            $lossB = new TaxLossService($b, new TaxConstantsRepository($b)); $lossB->registerOpeningLoss($supplier, 'fo', 2024, 100000);
            $returnsB = new TaxReturnRepository($b); $draft = $returnsB->create($supplier, 2026, 'fo', [], null);
            $a->pdo()->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ'); $a->pdo()->beginTransaction();
            $a->pdo()->query('SELECT * FROM tax_loss_applications')->fetchAll();
            $lossB->reconcileFinalize($supplier, 'fo', 2025, 0, 60000, null);
            $lossA = new TaxLossService($a, new TaxConstantsRepository($a));
            try { $current = $lossA->allocationPlan($supplier, 'fo', 2026, 50000, null, (int) $draft['id']); }
            catch (\PDOException $e) {
                self::assertTrue(\MyInvoice\Infrastructure\Database\TransactionFailure::aborted($e));
                self::assertSame(1020, (int) $e->errorInfo[1]);
                if ($a->pdo()->inTransaction()) $a->pdo()->rollBack();
                self::assertSame(0, (int) $b->pdo()->query('SELECT COUNT(*) FROM tax_loss_applications WHERE applied_year=2026')->fetchColumn());
                $a->pdo()->beginTransaction();
                $current = $lossA->allocationPlan($supplier, 'fo', 2026, 50000, null, (int) $draft['id']);
            }
            self::assertSame('4000000', $current['available_cents']);
            self::assertSame('1000000', $current['unallocated_cents']);
            try { $lossA->assertExact($current); self::fail('Committed other-year claim must reduce current capacity.'); }
            catch (TaxReturnException $e) { self::assertSame('loss_allocation_insufficient', $e->errorCode); }
            $a->pdo()->rollBack();
            $regular = $returnsB->finalize($supplier, 2026, 'fo', ['computed' => ['tax' => 0]], $draft['row_version']);
            $corrective = $returnsB->create($supplier, 2026, 'fo', [], null, 'opravne');
            $a->pdo()->beginTransaction();
            self::assertSame((int) $regular['id'], (int) (new TaxReturnRepository($a))->findLastFinalized($supplier, 2026, 'fo')['id']);
            $returnsB->finalize($supplier, 2026, 'fo', ['computed' => ['tax' => 0]], $corrective['row_version'], 'opravne');
            try {
                try { $lossA->allocationPlan($supplier, 'fo', 2026, 0, (int) $regular['id'], (int) $corrective['id']); }
                catch (\PDOException $e) {
                    self::assertTrue(\MyInvoice\Infrastructure\Database\TransactionFailure::aborted($e));
                    self::assertSame(1020, (int) $e->errorInfo[1]);
                    if ($a->pdo()->inTransaction()) $a->pdo()->rollBack();
                    $a->pdo()->beginTransaction();
                    $lossA->allocationPlan($supplier, 'fo', 2026, 0, (int) $regular['id'], (int) $corrective['id']);
                }
                self::fail('Earlier snapshot cannot authorize an obsolete effective owner.');
            }
            catch (TaxReturnException $e) { self::assertSame('loss_allocation_owner_unverified', $e->errorCode); }
            $a->pdo()->rollBack();
        } finally {
            foreach ([$a, $b] as $connection) if ($connection !== null) { if ($connection->pdo()->inTransaction()) $connection->pdo()->rollBack(); $connection->close(); }
            OwnedTestDatabaseCleanup::drop($server, $target, [$target]);
        }
    }
}
