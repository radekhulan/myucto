<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Crm;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Crm\CrmAggregationService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * DDKP (§ 28 ZDPH) nesmí vystupovat jako závazek v CRM agregacích.
 *
 * Nález fáze F2: `PayablePredicateCoverageTest` měl pevný seznam DVOU souborů, takže
 * čtyři závazkové dotazy v `CrmAggregationService` nekontroloval vůbec — aging,
 * cashflow predikce a dvě větve „Zaplať dodavatelům" (živá i snapshot pro dismissals).
 * Guard tvrdil, že pokrývá celou třídu B-13, a pokrýval dva soubory z devíti.
 *
 * Daňový doklad k poskytnuté záloze závazek na 321 nemá: peníze odešly už na zálohové
 * faktuře a doklad účtuje jen odpočet DPH (343/314). Jeho `amount_to_pay` je ale
 * GENERATED sloupec `total_with_vat − advance_paid_amount`, takže ve stavu
 * `received`/`booked` nese PLNÉ BRUTTO už zaplacené zálohy.
 *
 * Expozice na ostrých datech je dnes NULOVÁ — jediný DDKP je `status='paid'`, takže
 * ho stavový filtr odfiltruje dřív. Vada je tedy latentní, stejně jako N-008: projeví
 * se v okamžiku, kdy někdo zadá DDKP běžnou cestou (stav `received`). Proto se testuje
 * seedem, ne měřením produkce.
 *
 * Metoda: transakce + rollback, DELTA proti baseline (cizí data v okně nevadí).
 * Kontrolní faktura (`invoice`) v každém případu ověřuje, že dotaz vůbec měří —
 * bez ní by test prošel i tehdy, kdyby agregace vracela konstantní nulu.
 */
#[Group('integration')]
final class CrmPayableDdkpExclusionTest extends TestCase
{
    private Connection $db;
    private CrmAggregationService $crm;
    private \PDO $pdo;

    private int $supplierId = 0;
    private int $czkId = 0;
    private int $userId = 0;
    private int $czId = 0;
    private int $vendorId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db = $container->get(Connection::class);
            $this->crm = $container->get(CrmAggregationService::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        $this->pdo = $this->db->pdo();

        $this->supplierId = (int) ($this->pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($this->pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId = (int) ($this->pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        $this->czkId = (int) ($this->pdo->query(
            "SELECT id FROM currencies WHERE supplier_id = {$this->supplierId} AND code = 'CZK' ORDER BY id LIMIT 1"
        )->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->userId === 0 || $this->czId === 0 || $this->czkId === 0) {
            $this->markTestSkipped('Chybí supplier/user/country/CZK.');
        }

        $this->pdo->beginTransaction();
        $this->inTx = true;

        // Skryté úkoly by test rozbily podle obsahu DB — přesně křehkost z N-022, kdy
        // test podle stavu cizích dat buď prošel, nebo padl. Uvnitř transakce je
        // odklidíme (rollback je vrátí), takže je test soběstačný.
        $this->pdo->prepare('DELETE FROM crm_action_item_dismissals WHERE supplier_id = ?')
            ->execute([$this->supplierId]);

        $this->vendorId = $this->vendor();
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

    /** agingPayables: DDKP nesmí nafouknout žádný bucket, běžná faktura ano. */
    public function testAgingPayablesIgnoresAdvanceVatDocument(): void
    {
        $due = (new \DateTimeImmutable('today'))->modify('+10 days')->format('Y-m-d');

        $before = $this->agingTotal();
        $this->purchase('tax_document', $due, 36_863.01);
        $afterDdkp = $this->agingTotal();

        self::assertEqualsWithDelta(
            $before,
            $afterDdkp,
            0.01,
            'DDKP se nesmí objevit v aging závazků — peníze odešly už na zálohové faktuře.',
        );

        $this->purchase('invoice', $due, 1_000.0);
        self::assertEqualsWithDelta(
            $before + 1_000.0,
            $this->agingTotal(),
            0.01,
            'Běžná přijatá faktura se v aging objevit MUSÍ — jinak test měří konstantní nulu.',
        );
    }

    /** cashFlowForecast: DDKP nesmí zvýšit predikovaný odtok. */
    public function testCashFlowForecastIgnoresAdvanceVatDocument(): void
    {
        $due = (new \DateTimeImmutable('today'))->modify('+2 days')->format('Y-m-d');

        $before = (float) $this->crm->cashFlowForecast($this->supplierId, 2, 'CZK')['total_out'];
        $this->purchase('tax_document', $due, 36_863.01);
        $afterDdkp = (float) $this->crm->cashFlowForecast($this->supplierId, 2, 'CZK')['total_out'];

        self::assertEqualsWithDelta(
            $before,
            $afterDdkp,
            0.01,
            'DDKP není platební cíl — v predikci odtoku nemá co dělat.',
        );

        $this->purchase('invoice', $due, 500.0);
        self::assertEqualsWithDelta(
            $before + 500.0,
            (float) $this->crm->cashFlowForecast($this->supplierId, 2, 'CZK')['total_out'],
            0.01,
            'Běžná přijatá faktura odtok zvýšit MUSÍ.',
        );
    }

    /**
     * actionItems „Zaplať dodavatelům": DDKP po splatnosti nesmí generovat úkol.
     * Kdyby ho generoval, uživatel by dostal výzvu zaplatit podruhé částku, která
     * už odešla na zálohové faktuře.
     */
    public function testOverduePayablesActionItemIgnoresAdvanceVatDocument(): void
    {
        $overdue = (new \DateTimeImmutable('today'))->modify('-30 days')->format('Y-m-d');

        $before = $this->overduePayablesCount();
        $this->purchase('tax_document', $overdue, 36_863.01);

        self::assertSame(
            $before,
            $this->overduePayablesCount(),
            'DDKP po splatnosti nesmí vyrobit úkol „Zaplať dodavatelům" — vedl by k druhé platbě.',
        );

        $this->purchase('invoice', $overdue, 750.0);
        self::assertSame(
            $before + 1,
            $this->overduePayablesCount(),
            'Běžná faktura po splatnosti úkol vyrobit MUSÍ.',
        );
    }

    /**
     * Snapshot pro skrývání úkolů čte druhým dotazem tytéž doklady. Kdyby se oba
     * rozešly, skrytí úkolu by se rozsypalo — proto musí filtrovat shodně.
     */
    public function testDismissalSnapshotMatchesLiveQuery(): void
    {
        $overdue = (new \DateTimeImmutable('today'))->modify('-30 days')->format('Y-m-d');
        $this->purchase('tax_document', $overdue, 36_863.01);
        $this->purchase('invoice', $overdue, 750.0);

        $live = $this->overduePayablesCount();
        $snapshot = count($this->invokeSnapshot('overdue_payables'));

        self::assertSame(
            $live,
            $snapshot,
            'Snapshot pro dismissals musí vidět tytéž doklady jako živý dotaz.',
        );
    }

    public function testConfirmedAccountSettlementRemovesPurchaseFromPayableReads(): void
    {
        $overdue = (new \DateTimeImmutable('today'))->modify('-30 days')->format('Y-m-d');
        $agingBefore = $this->agingTotal();
        $actionBefore = $this->overduePayablesCount();
        $snapshotBefore = count($this->invokeSnapshot('overdue_payables'));

        $purchaseId = $this->purchase('invoice', $overdue, 199.0);
        self::assertEqualsWithDelta($agingBefore + 199.0, $this->agingTotal(), 0.01);
        self::assertSame($actionBefore + 1, $this->overduePayablesCount());
        self::assertSame($snapshotBefore + 1, count($this->invokeSnapshot('overdue_payables')));

        $this->settlePurchase($purchaseId, 199.0);
        self::assertEqualsWithDelta($agingBefore, $this->agingTotal(), 0.01,
            'Plně vyrovnaná přijatá faktura nesmí zůstat v aging závazků.');
        self::assertSame($actionBefore, $this->overduePayablesCount(),
            'Plně vyrovnaná přijatá faktura nesmí zůstat v „Zaplať dodavatelům".');
        self::assertSame($snapshotBefore, count($this->invokeSnapshot('overdue_payables')),
            'Snapshot skrytých akcí musí používat stejný zbytek jako živý widget.');

        $future = (new \DateTimeImmutable('today'))->modify('+2 days')->format('Y-m-d');
        $cashflowBefore = (float) $this->crm->cashFlowForecast($this->supplierId, 2, 'CZK')['total_out'];
        $futureId = $this->purchase('invoice', $future, 365.0);
        self::assertEqualsWithDelta(
            $cashflowBefore + 365.0,
            (float) $this->crm->cashFlowForecast($this->supplierId, 2, 'CZK')['total_out'],
            0.01,
        );
        $this->settlePurchase($futureId, 365.0);
        self::assertEqualsWithDelta($cashflowBefore,
            (float) $this->crm->cashFlowForecast($this->supplierId, 2, 'CZK')['total_out'], 0.01,
            'Plně vyrovnaná faktura nesmí vytvářet budoucí odtok cashflow.');
    }

    // ── helpers ────────────────────────────────────────────────────────────────

    private function agingTotal(): float
    {
        $sum = 0.0;
        foreach ($this->crm->agingPayables($this->supplierId) as $row) {
            if (($row['currency'] ?? '') === 'CZK') {
                $sum += (float) $row['total'];
            }
        }
        return $sum;
    }

    private function overduePayablesCount(): int
    {
        $result = $this->crm->actionItems($this->supplierId, $this->userId);
        foreach ($result['items'] ?? [] as $item) {
            if (($item['type'] ?? '') === 'overdue_payables') {
                return (int) ($item['count'] ?? 0);
            }
        }
        return 0;
    }

    /**
     * `snapshotCurrentIds` je private — čte se reflexí záměrně: veřejná cesta
     * (dismiss + restore) by do testu zatáhla zápis do `crm_action_dismissals`
     * a tím i další proměnnou, zatímco ověřovaným tvrzením je jen shoda filtrů.
     *
     * @return list<int>
     */
    private function invokeSnapshot(string $itemType): array
    {
        $m = new \ReflectionMethod(CrmAggregationService::class, 'snapshotCurrentIds');
        /** @var list<int> $ids */
        $ids = $m->invoke($this->crm, $this->supplierId, $itemType);
        return $ids;
    }

    private function vendor(): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, dic, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Test 1", "Praha", "11000", ?, "CZ12345678", "ddkp@example.com", "cs", ?, 0, 1)'
        );
        $stmt->execute([$this->supplierId, 'DDKP Dodavatel', $this->czId, $this->czkId]);
        return (int) $this->pdo->lastInsertId();
    }

    private function purchase(string $documentKind, string $dueDate, float $gross): int
    {
        $issue = date('Y-m-d');
        $net = round($gross / 1.21, 2);
        $stmt = $this->pdo->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, varsymbol, vendor_invoice_number, document_kind,
                 issue_date, tax_date, due_date, received_at, currency_id, exchange_rate, reverse_charge,
                 vendor_snapshot, total_without_vat, total_vat, total_with_vat, status,
                 vat_classification_code, vat_deduction, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1.0, 0, "{}", ?, ?, ?, "received", "40", "full", ?)'
        );
        $stmt->execute([
            $this->supplierId, $this->vendorId, 'DDKP-' . uniqid(), 'DDKP-' . uniqid(), $documentKind,
            $issue, $issue, $dueDate, $issue, $this->czkId,
            $net, round($gross - $net, 2), $gross, $this->userId,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    private function settlePurchase(int $purchaseId, float $amount): void
    {
        $accountId = (int) ($this->pdo->query(
            "SELECT id FROM chart_of_accounts
              WHERE supplier_id = {$this->supplierId} AND account_code LIKE '365%'
              ORDER BY id LIMIT 1"
        )->fetchColumn() ?: 0);
        self::assertGreaterThan(0, $accountId, 'Test vyžaduje účet 365.');
        $this->pdo->prepare(
            "INSERT INTO invoice_settlements
                (supplier_id, doc_type, doc_id, settled_on, amount, account_id, status, created_by)
             VALUES (?, 'purchase_invoice', ?, CURDATE(), ?, ?, 'confirmed', ?)"
        )->execute([$this->supplierId, $purchaseId, $amount, $accountId, $this->userId]);
    }
}
