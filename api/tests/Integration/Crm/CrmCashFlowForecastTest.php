<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Crm;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Crm\CrmAggregationService;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Charakterizační (golden baseline) test pro CrmAggregationService::cashFlowForecast
 * PŘED přepisem SQL 10.6→11.8 (Epic SQL fáze 2, F5 — 2×weeksAhead dotazů v PHP smyčce
 * → jeden GROUP BY week bucket). Zamyká per-week atribuci in/out/net/running, aby přepis
 * na single-query zůstal behavior-preserving.
 *
 * In  = SUM(amount_to_pay - paid_total) nezaplacených vystavených faktur s due_date v týdnu.
 * Out = SUM(total_with_vat) nezaplacených přijatých faktur s due_date v týdnu.
 * amount_to_pay = total_with_vat − advance_paid_amount (STORED gen-col; advance=0 → =gross).
 *
 * Metoda: transakce + rollback, DELTA proti baseline (jiná reálná data se splatností
 * v okně nevadí). due_date sázíme přesně na hranice týdnů, které SQL počítá. Soft-skip.
 */
#[Group('integration')]
final class CrmCashFlowForecastTest extends TestCase
{
    use \MyInvoice\Tests\Support\IsolatedSupplierTrait;
    private Connection $db;
    private CrmAggregationService $crm;
    private \PDO $pdo;

    private int $supplierId = 0;
    private int $czkId = 0;
    private int $userId = 0;
    private int $czId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db = $container->get(Connection::class);
            $this->crm = $container->get(CrmAggregationService::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        $this->pdo = $this->db->pdo();

        self::assertStringEndsWith('_test', (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn());
        $this->pdo->beginTransaction();
        $this->inTx = true;
        $this->czId = (int) $this->pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn();
        $vatId = (int) $this->pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn();
        self::assertGreaterThan(0, $this->czId);
        self::assertGreaterThan(0, $vatId);
        // Supplier a jeho výchozí měna tvoří kruhový FK; po založení obou je hned opravíme.
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            $this->pdo->prepare("INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id)
                VALUES ('Syntetické cashflow', 'Test 1', 'Praha', '11000', ?, 'cashflow@example.invalid', 0, ?)")->execute([$this->czId, $vatId]);
            $this->supplierId = (int) $this->pdo->lastInsertId();
            $this->pdo->prepare("INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en) VALUES (?, 'CZK', 'CZK', 'Kč', 'Koruna', 'Crown')")->execute([$this->supplierId]);
            $this->czkId = (int) $this->pdo->lastInsertId();
            $this->pdo->prepare('UPDATE supplier SET default_currency_id = ? WHERE id = ?')->execute([$this->czkId, $this->supplierId]);
        } finally {
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
        $this->pdo->prepare("INSERT INTO users (email, password_hash, name) VALUES (?, ?, 'Syntetický cashflow uživatel')")
            ->execute(['cashflow-' . bin2hex(random_bytes(6)) . '@example.invalid', password_hash('Syntetické nepoužité heslo', PASSWORD_BCRYPT, ['cost' => 4])]);
        $this->userId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->db->close();
        }
    }

    public function testForecastBucketsInOutPerWeek(): void
    {
        $today = new \DateTimeImmutable('today');
        $nextMonday = $today->modify('+1 weeks')->modify('Monday this week');

        $client = $this->party('CF Klient', true, false);
        $vendor = $this->party('CF Dodavatel', false, true);

        $base = $this->crm->cashFlowForecast($this->supplierId, 2, 'CZK');
        $baseW0In = (float) $base['weeks'][0]['in'];
        $baseW0Out = (float) $base['weeks'][0]['out'];
        $baseW1In = (float) $base['weeks'][1]['in'];
        $baseTotalIn = (float) $base['total_in'];
        $baseTotalOut = (float) $base['total_out'];

        // Týden 0 (od dneška): in 1210, out 605.
        $this->invoice($client, $today->format('Y-m-d'), 1210.0);
        $this->purchase($vendor, $today->format('Y-m-d'), 605.0);
        // Týden 1 (pondělí příštího týdne): in 2420.
        $this->invoice($client, $nextMonday->format('Y-m-d'), 2420.0);

        $after = $this->crm->cashFlowForecast($this->supplierId, 2, 'CZK');
        self::assertEqualsWithDelta($baseW0In + 1210.0, (float) $after['weeks'][0]['in'], 0.01, 'Týden 0 in += 1210.');
        self::assertEqualsWithDelta($baseW0Out + 605.0, (float) $after['weeks'][0]['out'], 0.01, 'Týden 0 out += 605.');
        self::assertEqualsWithDelta($baseW1In + 2420.0, (float) $after['weeks'][1]['in'], 0.01, 'Týden 1 in += 2420.');
        self::assertEqualsWithDelta($baseTotalIn + 3630.0, (float) $after['total_in'], 0.01, 'total_in += 1210+2420.');
        self::assertEqualsWithDelta($baseTotalOut + 605.0, (float) $after['total_out'], 0.01, 'total_out += 605.');

        // net týdne 0 = in − out; running týdne 1 = kumulativně.
        self::assertEqualsWithDelta(
            (float) $after['weeks'][0]['in'] - (float) $after['weeks'][0]['out'],
            (float) $after['weeks'][0]['net'],
            0.01,
            'net[0] = in[0] − out[0].',
        );
        self::assertEqualsWithDelta(
            (float) $after['weeks'][0]['net'] + (float) $after['weeks'][1]['net'],
            (float) $after['weeks'][1]['running'],
            0.01,
            'running[1] = net[0] + net[1].',
        );
    }

    public function testDraftLayerIsOptionalAndUsesStoredAmountsAndWeekBoundaries(): void
    {
        $client = $this->party('CF Koncept', true, false);
        $today = new \DateTimeImmutable('today');
        $end = $today->modify('Sunday this week');
        $monday = $end->modify('+1 day');
        $endNext = $monday->modify('Sunday this week');
        $baseline = $this->crm->cashFlowForecast($this->supplierId, 2, 'CZK');
        $baseDraft = $this->crm->cashFlowForecast($this->supplierId, 2, 'CZK', true);
        $draft = $this->invoice($client, $today->format('Y-m-d'), 1000);
        $this->pdo->prepare("UPDATE invoices SET status = 'draft', advance_paid_amount = 100, paid_total = 200 WHERE id = ?")->execute([$draft]);
        foreach ([[$end, 50], [$monday, 200], [$endNext, 300], [$endNext->modify('+1 day'), 10000]] as [$date, $gross]) {
            $id = $this->invoice($client, $date->format('Y-m-d'), $gross);
            $this->pdo->prepare("UPDATE invoices SET status = 'draft' WHERE id = ?")->execute([$id]);
        }
        $without = $this->crm->cashFlowForecast($this->supplierId, 2, 'CZK');
        self::assertSame($baseline, $without, 'Výchozí odpověď zůstává beze změny včetně tvaru JSON.');
        self::assertSame($without, $this->crm->cashFlowForecast($this->supplierId, 2, 'CZK', false));
        $with = $this->crm->cashFlowForecast($this->supplierId, 2, 'CZK', true);
        self::assertEqualsWithDelta($baseDraft['weeks'][0]['draft_in'] + 750, $with['weeks'][0]['draft_in'], 0.001);
        self::assertEqualsWithDelta($baseDraft['weeks'][1]['draft_in'] + 500, $with['weeks'][1]['draft_in'], 0.001);
        self::assertEqualsWithDelta($baseDraft['total_draft_in'] + 1250, $with['total_draft_in'], 0.001);
        self::assertSame($without['total_in'], $with['total_in']);
        self::assertEqualsWithDelta($with['total_net'] + $with['total_draft_in'], $with['total_outlook_net'], 0.001);
        self::assertEqualsWithDelta($with['total_outlook_net'], $with['weeks'][1]['outlook_running'], 0.001);
        // Vystavení pouze přesune uloženou částku mezi vrstvami, součet se nezmění.
        $this->pdo->prepare("UPDATE invoices SET status = 'issued' WHERE id = ?")->execute([$draft]);
        $issued = $this->crm->cashFlowForecast($this->supplierId, 2, 'CZK', true);
        self::assertEqualsWithDelta($with['total_in'] + 700, $issued['total_in'], 0.001);
        self::assertEqualsWithDelta($with['total_draft_in'] - 700, $issued['total_draft_in'], 0.001);
        self::assertSame($with['total_outlook_net'], $issued['total_outlook_net']);
    }

    public function testDraftScopeCurrencyTenantAndUnscheduledDrilldown(): void
    {
        $client = $this->party('CF Filtry', true, false);
        $today = date('Y-m-d');
        $base = $this->crm->cashFlowForecast($this->supplierId, 2, 'CZK', true);
        $overdue = $this->invoice($client, date('Y-m-d', strtotime('-1 day')), 125);
        $this->pdo->prepare("UPDATE invoices SET status = 'draft' WHERE id = ?")->execute([$overdue]);
        foreach (['proforma', 'rent_calendar', 'advance_calendar', 'tax_document', 'invoice'] as $type) {
            $id = $this->invoice($client, $today, 9999);
            $this->pdo->prepare("UPDATE invoices SET status = 'draft', invoice_type = ?, advance_paid_amount = IF(? IN ('invoice','tax_document'), total_with_vat, 0) WHERE id = ?")->execute([$type, $type, $id]);
        }
        $this->pdo->prepare("INSERT INTO currencies (supplier_id, code, symbol, label, name_cs, name_en) VALUES (?, 'XCF', 'X', 'Testovací měna', 'Testovací měna', 'Test currency')")->execute([$this->supplierId]);
        $foreignCurrency = (int) $this->pdo->lastInsertId();
        $foreign = $this->invoice($client, $today, 777);
        $this->pdo->prepare("UPDATE invoices SET status = 'draft', currency_id = ? WHERE id = ?")->execute([$foreignCurrency, $foreign]);
        $otherSupplier = $this->createIsolatedSupplier($this->pdo, $this->supplierId);
        $ownSupplier = $this->supplierId;
        $ownCzk = $this->czkId;
        $this->supplierId = $otherSupplier;
        try {
            $this->pdo->prepare("INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en) VALUES (?, 'CZK', 'CZK', 'Kč', 'Koruna', 'Crown')")->execute([$otherSupplier]);
            $this->czkId = (int) $this->pdo->lastInsertId();
            $otherClient = $this->party('CF Jiná firma', true, false);
            $otherInvoice = $this->invoice($otherClient, $today, 888);
            $this->pdo->prepare("UPDATE invoices SET status = 'draft' WHERE id = ?")->execute([$otherInvoice]);
        } finally {
            $this->supplierId = $ownSupplier;
            $this->czkId = $ownCzk;
        }
        $after = $this->crm->cashFlowForecast($this->supplierId, 2, 'CZK', true);
        self::assertSame($base['total_draft_in'], $after['total_draft_in']);
        self::assertSame($base['unscheduled_draft_count'] + 1, $after['unscheduled_draft_count']);
        $repository = Bootstrap::buildContainer()->get(\MyInvoice\Repository\InvoiceRepository::class);
        [$where, $params] = $repository->listWhere(['supplier_id' => $this->supplierId, 'cashflow_draft_unscheduled' => true, 'currency' => 'CZK']);
        $stmt = $this->pdo->prepare("SELECT i.id FROM invoices i JOIN currencies cur ON cur.id = i.currency_id WHERE $where");
        $stmt->execute($params);
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        self::assertContains($overdue, $ids);
        self::assertNotContains($foreign, $ids);
        self::assertNotContains($otherInvoice, $ids);
        self::assertCount($after['unscheduled_draft_count'], $ids);
    }

    // ── seed helpers ───────────────────────────────────────────────────────────

    private function party(string $name, bool $customer, bool $vendor): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, dic, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Test 1", "Praha", "11000", ?, "CZ12345678", "cf@example.com", "cs", ?, ?, ?)'
        );
        $stmt->execute([$this->supplierId, $name, $this->czId, $this->czkId, $customer ? 1 : 0, $vendor ? 1 : 0]);
        return (int) $this->pdo->lastInsertId();
    }

    private function invoice(int $clientId, string $dueDate, float $gross): int
    {
        $issue = date('Y-m-d');
        $net = round($gross / 1.21, 2);
        $stmt = $this->pdo->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, exchange_rate, reverse_charge, total_without_vat, total_vat, total_with_vat,
                 status, vat_classification_code, created_by)
             VALUES (?, ?, "invoice", ?, ?, ?, ?, ?, 1.0, 0, ?, ?, ?, "issued", "1", ?)'
        );
        $stmt->execute([
            $this->supplierId, 'CF-' . uniqid(), $clientId, $issue, $issue, $dueDate,
            $this->czkId, $net, round($gross - $net, 2), $gross, $this->userId,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    private function purchase(int $vendorId, string $dueDate, float $gross): int
    {
        $issue = date('Y-m-d');
        $net = round($gross / 1.21, 2);
        $stmt = $this->pdo->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, varsymbol, vendor_invoice_number, document_kind,
                 issue_date, tax_date, due_date, received_at, currency_id, exchange_rate, reverse_charge,
                 vendor_snapshot, total_without_vat, total_vat, total_with_vat, status,
                 vat_classification_code, vat_deduction, created_by)
             VALUES (?, ?, ?, ?, "invoice", ?, ?, ?, ?, ?, 1.0, 0, "{}", ?, ?, ?, "received", "40", "full", ?)'
        );
        $stmt->execute([
            $this->supplierId, $vendorId, 'CFP-' . uniqid(), 'CFP-' . uniqid(),
            $issue, $issue, $dueDate, $issue, $this->czkId, $net, round($gross - $net, 2), $gross, $this->userId,
        ]);
        return (int) $this->pdo->lastInsertId();
    }
}
