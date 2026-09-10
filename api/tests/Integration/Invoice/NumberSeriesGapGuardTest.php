<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Invoice;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Repository\RecurringTemplateRepository;
use MyInvoice\Service\Invoice\NumberSeriesGapGuard;
use MyInvoice\Service\Invoice\RecurringInvoiceGenerator;
use MyInvoice\Service\Invoice\VarsymbolGenerator;
use MyInvoice\Tests\Integration\Stock\StockTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pojistka proti díře na konci číselné řady ({@see NumberSeriesGapGuard}).
 *
 * Počítadlo, které stojí výš než nejvyšší vydané číslo období, se při přidělení
 * srovná dolů. Ruční začátek řady (setCounter) se nesrovnává, mezera uprostřed
 * řady se nezaplňuje a dvě souběžná vystavení nedostanou stejné číslo.
 *
 * Každý test si zakládá vlastního dodavatele (StockTestCase) s explicitní šablonou
 * a pracuje v období 2099-06.
 */
#[Group('integration')]
final class NumberSeriesGapGuardTest extends StockTestCase
{
    private const INVOICE_TPL = 'T{YY}{MM}{CCC}';
    private const DATE        = '2099-06-10';
    private const PERIOD      = '209906';

    /** @var list<Connection> druhá spojení pro souběh */
    private array $extraConnections = [];

    protected function tearDown(): void
    {
        foreach ($this->extraConnections as $connection) {
            if ($connection->pdo()->inTransaction()) {
                $connection->pdo()->rollBack();
            }
            $connection->close();
        }
        if (isset($this->db)) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            foreach ($this->supplierIds as $sid) {
                $pdo->prepare('DELETE FROM invoices WHERE supplier_id = ?')->execute([$sid]);
                $pdo->prepare('DELETE FROM recurring_invoice_templates WHERE supplier_id = ?')->execute([$sid]);
                $pdo->prepare('DELETE FROM invoice_counters WHERE supplier_id = ?')->execute([$sid]);
                $pdo->prepare('DELETE FROM purchase_invoices WHERE supplier_id = ?')->execute([$sid]);
                $pdo->prepare('DELETE FROM purchase_invoice_counters WHERE supplier_id = ?')->execute([$sid]);
            }
        }
        parent::tearDown();
    }

    public function testRunawayCounterIsClampedWhenRecurringCronIssues(): void
    {
        $sid = $this->numberedSupplier();
        $clientId = $this->client($sid);
        // Počítadlo utekl o 37 čísel, žádná faktura v období není.
        $this->setInvoiceCounter($sid, 37);

        $repo = $this->container->get(RecurringTemplateRepository::class);
        $tplId = $repo->create([
            'supplier_id'      => $sid,
            'client_id'        => $clientId,
            'name'             => 'Test měsíční fakturace',
            'frequency'        => 'monthly',
            'day_of_month'     => 1,
            'end_of_month'     => false,
            'anchor_date'      => '2099-06-01',
            'next_run_date'    => '2099-06-01',
            'invoice_type'     => 'invoice',
            'currency_id'      => $this->currencyIdFor($sid),
            'payment_due_days' => 14,
            'auto_issue'       => true,
            'auto_send_email'  => false,
            'status'           => 'active',
        ], $this->userId);
        $repo->replaceItems($tplId, [[
            'description'            => 'Služba',
            'quantity'               => 1.0,
            'unit'                   => 'měs',
            'unit_price_without_vat' => 1000.0,
            'vat_rate_id'            => $this->vatRateId,
            'order_index'            => 0,
        ]]);

        $result = $this->container->get(RecurringInvoiceGenerator::class)
            ->generate($tplId, null, $this->userId, '127.0.0.1', 'phpunit');

        self::assertTrue($result['issued']);
        self::assertSame('T9906001', $result['varsymbol'], 'První faktura období má číslo 001, ne 038.');
        self::assertSame(1, $this->invoiceCounter($sid), 'Počítadlo se srovnalo na vydané číslo.');

        $stmt = $this->db->pdo()->prepare(
            "SELECT payload FROM activity_log
              WHERE supplier_id = ? AND action = 'number_series.counter_clamped'
              ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$sid]);
        $payload = json_decode((string) $stmt->fetchColumn(), true);
        self::assertIsArray($payload, 'Srovnání se musí zapsat do activity_log.');
        self::assertSame(37, $payload['from_counter']);
        self::assertSame(0, $payload['to_counter']);
        self::assertSame(self::PERIOD, $payload['period']);
    }

    public function testExplicitSeriesStartIsNotClampedBack(): void
    {
        $sid = $this->numberedSupplier();
        $gen = $this->container->get(VarsymbolGenerator::class);
        $gen->setCounter($sid, 'invoice', 100, new \DateTimeImmutable(self::DATE));

        self::assertSame('T9906100', $this->nextInTransaction($gen, $this->db, $sid));
    }

    public function testMiddleGapAfterDeletedInvoiceIsNotRefilled(): void
    {
        $sid = $this->numberedSupplier();
        $clientId = $this->client($sid);
        $gen = $this->container->get(VarsymbolGenerator::class);

        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $ids[] = $this->insertIssued($this->db, $sid, $clientId, $this->nextInTransaction($gen, $this->db, $sid));
        }
        $this->db->pdo()->prepare('DELETE FROM invoices WHERE id = ?')->execute([$ids[1]]);

        self::assertSame('T9906004', $this->nextInTransaction($gen, $this->db, $sid));
    }

    public function testStaleSnapshotOfConcurrentIssueDoesNotDuplicateNumber(): void
    {
        $sid = $this->numberedSupplier();
        $clientId = $this->client($sid);
        $this->setInvoiceCounter($sid, 5);

        $genA = $this->container->get(VarsymbolGenerator::class);
        [$dbB, $genB] = $this->secondConnection();

        // B je cizí transakce (volající už transakci drží), otevře ji a přečte doklady
        // dřív, než A vystaví a commitne. Úroveň izolace se připíná: starý snímek
        // vzniká jen v REPEATABLE READ. Snapshot isolation MariaDB se vypíná, jinak by
        // zámek počítadla v B skončil chybou 1020 dřív, než se ke kontrole snímku dojde.
        $pdoB = $dbB->pdo();
        $pdoB->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdoB->exec('SET SESSION innodb_snapshot_isolation = OFF');
        $pdoB->beginTransaction();
        $pdoB->query("SELECT COUNT(*) FROM invoices WHERE supplier_id = {$sid}")->fetchColumn();

        $pdoA = $this->db->pdo();
        $pdoA->beginTransaction();
        $vsA = $genA->next($sid, 'invoice', new \DateTimeImmutable(self::DATE));
        $this->insertIssued($this->db, $sid, $clientId, $vsA);
        $pdoA->commit();

        $vsB = $genB->next($sid, 'invoice', new \DateTimeImmutable(self::DATE));
        $this->insertIssued($dbB, $sid, $clientId, $vsB);
        $pdoB->commit();

        self::assertSame('T9906001', $vsA);
        self::assertSame('T9906002', $vsB, 'Druhé vystavení nesmí ze starého snímku dostat totéž číslo.');
    }

    public function testConcurrentIssueWaitsForSeriesLock(): void
    {
        $sid = $this->numberedSupplier();
        $clientId = $this->client($sid);
        $this->setInvoiceCounter($sid, 5);

        $genA = $this->container->get(VarsymbolGenerator::class);
        [$dbB, $genB] = $this->secondConnection();

        $pdoA = $this->db->pdo();
        $pdoA->beginTransaction();
        $vsA = $genA->next($sid, 'invoice', new \DateTimeImmutable(self::DATE));

        $pdoB = $dbB->pdo();
        $pdoB->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $pdoB->beginTransaction();
        try {
            $genB->next($sid, 'invoice', new \DateTimeImmutable(self::DATE));
            self::fail('Druhé vystavení nesmí přidělit číslo, dokud první drží řadu.');
        } catch (\PDOException $e) {
            self::assertStringContainsString('Lock wait timeout', $e->getMessage());
        } finally {
            if ($pdoB->inTransaction()) {
                $pdoB->rollBack();
            }
        }

        $this->insertIssued($this->db, $sid, $clientId, $vsA);
        $pdoA->commit();
        self::assertSame('T9906001', $vsA);
        self::assertSame(1, $this->invoiceCounter($sid));
    }

    public function testRunawayPurchaseCounterIsClamped(): void
    {
        $sid = $this->createSupplier('tax_evidence', false, true);
        $vendorId = $this->client($sid);
        $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoice_counters (supplier_id, period, last_number) VALUES (?, ?, 37)'
        )->execute([$sid, self::PERIOD]);
        $piId = $this->purchaseInvoice($sid, $vendorId, ['issue_date' => '2099-06-15', 'status' => 'draft']);

        $vs = $this->container->get(PurchaseInvoiceRepository::class)->ensureVarsymbol($piId, $sid);

        self::assertSame('PF9906001', $vs);
        $stmt = $this->db->pdo()->prepare(
            'SELECT last_number FROM purchase_invoice_counters WHERE supplier_id = ? AND period = ?'
        );
        $stmt->execute([$sid, self::PERIOD]);
        self::assertSame(1, (int) $stmt->fetchColumn());
    }

    private function numberedSupplier(): int
    {
        $sid = $this->createSupplier('tax_evidence', false, true);
        $this->db->pdo()->prepare(
            "UPDATE supplier SET invoice_number_format = ?, invoice_number_period = 'month' WHERE id = ?"
        )->execute([self::INVOICE_TPL, $sid]);
        return $sid;
    }

    private function setInvoiceCounter(int $supplierId, int $value): void
    {
        $this->db->pdo()->prepare(
            "INSERT INTO invoice_counters (supplier_id, client_id, revenue_category_id, invoice_type, period, last_number)
             VALUES (?, 0, 0, 'invoice', ?, ?)
             ON DUPLICATE KEY UPDATE last_number = VALUES(last_number)"
        )->execute([$supplierId, self::PERIOD, $value]);
    }

    private function invoiceCounter(int $supplierId): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT last_number FROM invoice_counters
              WHERE supplier_id = ? AND client_id = 0 AND revenue_category_id = 0
                AND invoice_type = 'invoice' AND period = ?"
        );
        $stmt->execute([$supplierId, self::PERIOD]);
        return (int) $stmt->fetchColumn();
    }

    private function nextInTransaction(VarsymbolGenerator $gen, Connection $db, int $supplierId): string
    {
        $db->pdo()->beginTransaction();
        $vs = $gen->next($supplierId, 'invoice', new \DateTimeImmutable(self::DATE));
        $db->pdo()->commit();
        return $vs;
    }

    private function insertIssued(Connection $db, int $supplierId, int $clientId, string $varsymbol): int
    {
        $db->pdo()->prepare(
            "INSERT INTO invoices
                (invoice_type, varsymbol, client_id, supplier_id, issue_date, tax_date, due_date,
                 currency_id, status, total_without_vat, total_with_vat, created_by)
             VALUES ('invoice', ?, ?, ?, ?, ?, '2099-06-24', ?, 'issued', 100, 121, ?)"
        )->execute([$varsymbol, $clientId, $supplierId, self::DATE, self::DATE, $this->currencyIdFor($supplierId), $this->userId]);
        return (int) $db->pdo()->lastInsertId();
    }

    /**
     * Vlastní DB session (ne sdílené testovací spojení), jinak by test souběh neměřil.
     *
     * @return array{0: Connection, 1: VarsymbolGenerator}
     */
    private function secondConnection(): array
    {
        [$db, $gen] = Connection::withoutSharedTestConnection(static function (): array {
            $container = Bootstrap::buildApp()->getContainer();
            return [$container->get(Connection::class), $container->get(VarsymbolGenerator::class)];
        });
        $this->extraConnections[] = $db;
        self::assertNotSame($this->db->pdo(), $db->pdo(), 'Souběh potřebuje dvě různá spojení.');
        return [$db, $gen];
    }
}
