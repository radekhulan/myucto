<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Bank;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Bank\BankNoticeConfirmationService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\TestCase;

final class BankNoticeConfirmationDetailPerformanceTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private \Psr\Container\ContainerInterface $container;
    private int $supplier;

    protected function setUp(): void
    {
        $this->container = Bootstrap::buildContainer();
        $this->db = $this->container->get(Connection::class);
        $pdo = $this->db->pdo();
        self::assertStringEndsWith('_test', (string) $pdo->query('SELECT DATABASE()')->fetchColumn());
        $pdo->beginTransaction();
        $source = (int) $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        self::assertGreaterThan(0, $source, 'Vyžadován syntetický seed testovací databáze.');
        $this->supplier = $this->createIsolatedSupplier($pdo, $source);
        $pdo->prepare("UPDATE supplier SET accounting_mode='tax_evidence',accounting_enabled=0 WHERE id=?")->execute([$this->supplier]);
        $pdo->prepare("INSERT INTO currencies (supplier_id,code,label,symbol,name_cs,name_en,account_number,bank_code) VALUES (?,'CZK','Synthetic batch','Kč','Koruna','Koruna','1000000005','0100')")->execute([$this->supplier]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
    }

    private function movement(string $source = 'email_notice', string $vs = '157'): array
    {
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO bank_statements (supplier_id,source,file_name,file_hash,account_number,bank_code,currency,statement_date) VALUES (?,?,'Synthetic batch',?,'1000000005','0100','CZK','2099-06-15')")->execute([$this->supplier, $source, hash('sha256', random_bytes(20))]);
        $statement = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO bank_transactions (statement_id,source,source_ref,posted_at,amount,currency,variable_symbol) VALUES (?,?,?,'2099-06-15',100,'CZK',?)")->execute([$statement, $source === 'email_notice' ? 'email_notice' : 'statement', 'synthetic-batch:' . bin2hex(random_bytes(10)), $vs]);
        return [(int) $pdo->lastInsertId(), $statement];
    }

    private function selects(callable $operation): array
    {
        $pdo = $this->db->pdo();
        $before = (int) $pdo->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetch(PDO::FETCH_NUM)[1];
        $result = $operation();
        $after = (int) $pdo->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetch(PDO::FETCH_NUM)[1];
        return [$after - $before, $result];
    }

    private function addNotices(int $statement, int $count): array
    {
        $stmt = $this->db->pdo()->prepare("INSERT INTO bank_transactions (statement_id,source,source_ref,posted_at,amount,currency,variable_symbol) VALUES (?,'email_notice',?,'2099-06-15',100,'CZK',?)");
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $stmt->execute([$statement, 'synthetic-perf:' . bin2hex(random_bytes(10)), (string) (500000 + $i)]);
            $ids[] = (int) $this->db->pdo()->lastInsertId();
        }
        return $ids;
    }

    public function testDetailOnlyInspectsVisiblePageWithConstantQueryCount(): void
    {
        [$notice, $statement] = $this->movement();
        $service = new BankNoticeConfirmationService($this->db);
        [$one, $first] = $this->selects(fn () => $service->statementDetail($this->supplier, $statement, [$notice]));
        $this->addNotices($statement, 999);
        [$many, $last] = $this->selects(fn () => $service->statementDetail($this->supplier, $statement, [$notice]));
        self::assertSame($one, $many);
        self::assertLessThanOrEqual(5, $many);
        self::assertSame(1, $first['confirmation_summary']['total']);
        self::assertSame(1000, $last['confirmation_summary']['total']);
        self::assertSame([$notice], array_column($last['items'], 'id'));
        self::assertSame(0, (int) $this->db->pdo()->query('SELECT COUNT(*) FROM bank_notice_confirmations WHERE supplier_id=' . $this->supplier)->fetchColumn());
    }

    public function testReconcileLimitOnlyEvaluatesRequestedBatchAmongThousandNotices(): void
    {
        [$notice, $statement] = $this->movement();
        $service = new BankNoticeConfirmationService($this->db);
        [$one] = $this->selects(fn () => $service->reconcileStatement($this->supplier, $statement, dryRun: true, limit: 1));
        $ids = $this->addNotices($statement, 999);
        [$many, $preview] = $this->selects(fn () => $service->reconcileStatement($this->supplier, $statement, dryRun: true, limit: 1));
        self::assertSame($one, $many, 'Dávkový limit musí omezit vyhodnocení, nikoliv jen počet zapsaných řádků.');
        self::assertLessThanOrEqual(15, $many);
        self::assertTrue($preview['has_more']);
        self::assertSame(1, $preview['processed']);
        self::assertSame(1000, $preview['confirmation_summary']['total']);
        self::assertSame([$notice], array_column($preview['items'], 'id'));
        self::assertSame(0, (int) $this->db->pdo()->query('SELECT COUNT(*) FROM bank_notice_confirmations WHERE supplier_id=' . $this->supplier)->fetchColumn());
        $applied = $service->reconcileStatement($this->supplier, $statement, limit: 1);
        self::assertSame(1, $applied['processed']);
        self::assertSame(1, (int) $this->db->pdo()->query('SELECT COUNT(*) FROM bank_notice_confirmations WHERE supplier_id=' . $this->supplier)->fetchColumn());
        $empty = $service->reconcileStatement($this->supplier, $statement, limit: 1, afterId: end($ids));
        self::assertSame(0, $empty['processed']);
        self::assertSame([], $empty['items']);
        self::assertFalse($empty['has_more']);
        self::assertSame(1000, $empty['confirmation_summary']['total']);
    }

    public function testMatchingWithoutNoticesAvoidsSupplierLockAndDoesNotCacheAbsence(): void
    {
        [$target] = $this->movement('gpc');
        $service = new BankNoticeConfirmationService($this->db);
        [$count, $blocked] = $this->selects(fn () => $service->blocksMatching($this->supplier, $target));
        self::assertFalse($blocked);
        self::assertLessThanOrEqual(2, $count, 'Firma bez avíz nesmí pro každé párování brát bank-import a otevírat rekonstrukci.');
        [$notice] = $this->movement();
        self::assertFalse($service->blocksMatching($this->supplier, $target));
        self::assertSame('confirmed', $service->inspectNotice($this->supplier, $notice)['confirmation_state'], 'Nově přidané avízo musí být viditelné i po předchozím negativním dotazu.');
    }
    public function testProvisionalBalanceOnlyUsesAnchorWithinConfirmingStatementWindow(): void
    {
        [$notice] = $this->movement();
        [$target, $statement] = $this->movement('gpc');
        [$anchor, $anchorStatement] = $this->movement('gpc', '999999');
        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE bank_transactions SET balance=1000 WHERE id=?')->execute([$notice]);
        $pdo->prepare("UPDATE bank_transactions SET balance=1000,posted_at='2100-06-15' WHERE id=?")->execute([$anchor]);
        $pdo->prepare("UPDATE bank_statements SET statement_date='2100-06-15',curr_balance=1000 WHERE id=?")->execute([$anchorStatement]);
        $service = new BankNoticeConfirmationService($this->db);
        self::assertSame('provisional_balance_not_covered', $service->reconcileNotice($this->supplier, $notice)['confirmation_reason']);
        // Datum právě potvrzujícího výpisu rozšiřuje identifikační okno až do jeho konce.
        $pdo->prepare("UPDATE bank_statements SET statement_date='2099-06-30' WHERE id=?")->execute([$statement]);
        $pdo->prepare("UPDATE bank_statements SET statement_date='2099-06-30' WHERE id=?")->execute([$anchorStatement]);
        $pdo->prepare("UPDATE bank_transactions SET posted_at='2099-06-30' WHERE id=?")->execute([$anchor]);
        self::assertSame('confirmed', $service->reconcileNotice($this->supplier, $notice)['confirmation_state']);
    }

}
