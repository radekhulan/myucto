<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Tax\BadDebt;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Report\DphPriznaniBuilder;
use MyInvoice\Service\Report\KontrolniHlaseniBuilder;
use MyInvoice\Service\Tax\BadDebt\Section74bService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * §2.5 (audit PODVOJNE-AUDIT.md) — EP-9: napojení §74b korekce do DPHDP3 (ř. 34 + ř. 40/41)
 * a KH (B.2, zdph_44='P'). Chrání per-sazba rozpad, znaménka a součty.
 *
 * ⚠️ Integrační (DB): NEspouštět společně s dalšími agenty na sdílené myucto_test — vše
 * v transakci s rollbackem. Seeduje přijatou fakturu s 21% i 12% položkou, zaeviduje snížení
 * §74b a ověří rozpad + znaménka v periodCorrectionLines a promítnutí do obou výkazů.
 */
#[Group('integration')]
final class Section74bReportMappingTest extends TestCase
{
    private Connection $db;
    private Section74bService $service;
    private AccountingPeriodRepository $periods;
    private int $bankPayYear = 0;
    private int $bankPeriodId = 0;

    private int $supplierId = 0;
    private int $userId = 0;
    private int $vendorId = 0;
    private int $currencyId = 0;
    private int $czId = 0;
    private int $rate21Id = 0;
    private int $rate12Id = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 5);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $c = Bootstrap::buildContainer();
            $this->db      = $c->get(Connection::class);
            $this->service = $c->get(Section74bService::class);
            $this->periods = $c->get(AccountingPeriodRepository::class);
            $this->container = $c;
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        // Plátce DPH jako tenant, ať DPHDP3/KH builder projde.
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier WHERE is_vat_payer = 1 ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->czId       = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        $this->rate21Id   = (int) ($pdo->query("SELECT id FROM vat_rates WHERE rate_percent = 21 ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->rate12Id   = (int) ($pdo->query("SELECT id FROM vat_rates WHERE rate_percent = 12 ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        if (in_array(0, [$this->supplierId, $this->userId, $this->currencyId, $this->czId, $this->rate21Id, $this->rate12Id], true)) {
            $this->markTestSkipped('Chybí základní data v DB (plátce DPH / sazby 21+12).');
        }

        // Vzdálený budoucí rok pro bank-paid scénáře (žádná kolize s reálnými periodami/doklady).
        $this->bankPayYear = 2099;

        $pdo->beginTransaction();
        $this->inTx = true;

        $pdo->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, dic, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, "Dodavatel §74b EP9", "Test 1", "Praha", "11000", ?, "CZ64949681", "v@example.com", "cs", ?, 0, 1)'
        )->execute([$this->supplierId, $this->czId, $this->currencyId]);
        $this->vendorId = (int) $pdo->lastInsertId();
    }

    /** @var mixed */
    private $container;

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
    }

    /**
     * Seeduje přijatou fakturu se dvěma sazbovými položkami (21 % + 12 %), plně neuhrazenou,
     * se splatností 2025-01 (aged od 2025-07). Vrací její id.
     */
    private function seedTwoRateInvoice(): int
    {
        $pdo = $this->db->pdo();
        // 21 %: základ 10 000 / DPH 2 100 ; 12 %: základ 5 000 / DPH 600 ; celkem 15 000 / 2 700.
        $stmt = $pdo->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date,
                 due_date, received_at, currency_id, reverse_charge, vendor_snapshot,
                 total_without_vat, total_vat, total_with_vat, advance_paid_amount,
                 status, vat_classification_code, vat_deduction, vat_deduction_percent, created_by)
             VALUES (?, ?, "PF-74B-EP9", "invoice", "2025-01-15", "2025-01-15", "2025-01-15", "2025-01-15",
                     ?, 0, "{}", 15000, 2700, 17700, 0, "received", "40", "full", 100, ?)'
        );
        $stmt->execute([$this->supplierId, $this->vendorId, $this->currencyId, $this->userId]);
        $invoiceId = (int) $pdo->lastInsertId();

        $item = $pdo->prepare(
            'INSERT INTO purchase_invoice_items
                (purchase_invoice_id, description, quantity, unit, unit_price_without_vat,
                 vat_rate_id, vat_rate_snapshot, total_without_vat, total_vat, total_with_vat,
                 order_index, vat_classification_code)
             VALUES (?, ?, 1, "ks", ?, ?, ?, ?, ?, ?, ?, "40")'
        );
        $item->execute([$invoiceId, 'Plnění 21 %', 10000, $this->rate21Id, 21, 10000, 2100, 12100, 0]);
        $item->execute([$invoiceId, 'Plnění 12 %', 5000,  $this->rate12Id, 12, 5000,  600,  5600,  1]);

        return $invoiceId;
    }

    /**
     * Běžný červencový odpočet (21 % i 12 %), aby ř. 40/41 v přiznání za 7/2025
     * existovaly nezávisle na obsahu testovací DB.
     */
    private function seedJulyDeduction(): int
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date,
                 due_date, received_at, currency_id, reverse_charge, vendor_snapshot,
                 total_without_vat, total_vat, total_with_vat, advance_paid_amount,
                 status, vat_classification_code, vat_deduction, vat_deduction_percent, created_by)
             VALUES (?, ?, "PF-74B-JUL", "invoice", "2025-07-10", "2025-07-10", "2025-07-10", "2025-07-10",
                     ?, 0, "{}", 30000, 5400, 35400, 0, "received", "40", "full", 100, ?)'
        );
        $stmt->execute([$this->supplierId, $this->vendorId, $this->currencyId, $this->userId]);
        $invoiceId = (int) $pdo->lastInsertId();

        $item = $pdo->prepare(
            'INSERT INTO purchase_invoice_items
                (purchase_invoice_id, description, quantity, unit, unit_price_without_vat,
                 vat_rate_id, vat_rate_snapshot, total_without_vat, total_vat, total_with_vat,
                 order_index, vat_classification_code)
             VALUES (?, ?, 1, "ks", ?, ?, ?, ?, ?, ?, ?, "40")'
        );
        $item->execute([$invoiceId, 'Červencové plnění 21 %', 20000, $this->rate21Id, 21, 20000, 4200, 24200, 0]);
        $item->execute([$invoiceId, 'Červencové plnění 12 %', 10000, $this->rate12Id, 12, 10000, 1200, 11200, 1]);

        return $invoiceId;
    }

    // ── periodCorrectionLines: per-sazba rozpad + znaménka snížení ─────────────
    public function testPeriodCorrectionLinesSplitsByRateWithReductionSigns(): void
    {
        $this->seedTwoRateInvoice();
        $this->service->recordAging($this->supplierId, 2025, 7, $this->userId);

        $lines = $this->service->periodCorrectionLines($this->supplierId, 2025, 7);

        // Testovací dodavatel je reálný plátce DPH (buildery vyžadují validní DIČ/adresu),
        // může mít další historické doklady → agregát basic/reduced/opr_dluz NENÍ izolovaný.
        // Per-sazba rozpad a znaménka snížení proto ověřujeme na NAŠEM seedovaném dokladu
        // (per-invoice, izolované na PF-74B-EP9). Znaménka agregátu do ř. 40/41/34 pokrývá
        // {@see testDphdp3EmitsSection74bReduction}.
        $ours = array_values(array_filter(
            $lines['invoices'],
            fn ($r) => $r['vendor_invoice_number'] === 'PF-74B-EP9'
        ));
        self::assertCount(1, $ours);
        self::assertSame('reduction', $ours[0]['movement']);
        // Snížení: ř. 40 (21 %) i ř. 41 (12 %) ZÁPORNĚ (základ i daň).
        self::assertSame(-10000.0, $ours[0]['base21']);
        self::assertSame(-2100.0, $ours[0]['vat21']);
        self::assertSame(-5000.0, $ours[0]['base12']);
        self::assertSame(-600.0, $ours[0]['vat12']);
        // ř. 34 opr_dluz je opačné znaménko k odpočtu (Σ DPH korekce dokladu KLADNĚ).
        self::assertSame(2700.0, round(-($ours[0]['vat21'] + $ours[0]['vat12']), 2));
    }

    /** Položka 0 % (vratné obaly) na ř. 40/41 nebyla, snížení odpočtu ji na ř. 41 nesmí poslat. */
    public function testZeroRateItemStaysOutOfReduction(): void
    {
        $id = $this->seedTwoRateInvoice();
        $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoice_items
                (purchase_invoice_id, description, quantity, unit, unit_price_without_vat,
                 vat_rate_id, vat_rate_snapshot, total_without_vat, total_vat, total_with_vat,
                 order_index, vat_classification_code)
             VALUES (?, "Vratné obaly", 1, "ks", 300, ?, 0, 300, 0, 300, 2, "40")'
        )->execute([$id, $this->rate21Id]);
        $this->db->pdo()->prepare('UPDATE purchase_invoices SET total_without_vat = 15300, total_with_vat = 18000 WHERE id = ?')->execute([$id]);
        $this->service->recordAging($this->supplierId, 2025, 7, $this->userId);

        $ours = array_values(array_filter(
            $this->service->periodCorrectionLines($this->supplierId, 2025, 7)['invoices'],
            fn ($r) => $r['vendor_invoice_number'] === 'PF-74B-EP9'
        ));
        self::assertCount(1, $ours);
        self::assertSame(-10000.0, $ours[0]['base21']);
        self::assertSame(-5000.0, $ours[0]['base12']);
        self::assertSame(-600.0, $ours[0]['vat12']);
    }

    // ── DPHDP3: §74b snížení sníží ř. 40/41 daň a zvýší ř. 34 opr_dluz ─────────
    public function testDphdp3EmitsSection74bReduction(): void
    {
        $this->seedTwoRateInvoice();
        // Vlastní odpočet ZA ČERVENEC, ať je co snižovat. Dřív se test spoléhal na to,
        // že testovací DB je klon produkce a reálný dodavatel má v červenci své odpočty —
        // nad prázdnou/izolovanou myucto_test proto Veta4 v `$before` vůbec nevznikla
        // a test padal na „Veta4 musí být přítomna". Teď je soběstačný.
        $this->seedJulyDeduction();
        $builder = $this->container->get(DphPriznaniBuilder::class);

        // Testovací dodavatel je reálný plátce DPH s vlastními odpočty → absolutní hodnoty
        // Veta4 nejsou izolované (odp_tuz23_nar dominuje reálný odpočet). §74b efekt proto
        // ověřujeme jako DELTU: přiznání BEZ korekce (před recordAging) vs. S korekcí (po).
        // Snížení odpočtu musí ř. 40/41 daň SNÍŽIT (delta < 0) a ř. 34 opr_dluz ZVÝŠIT
        // (delta > 0). Přesné per-sazba částky pokrývá {@see testPeriodCorrectionLines…}.
        $before = $this->dphVetaAttrs($builder->build($this->supplierId, 2025, 7, 'monthly'));
        $this->service->recordAging($this->supplierId, 2025, 7, $this->userId);
        $after = $this->dphVetaAttrs($builder->build($this->supplierId, 2025, 7, 'monthly'));

        self::assertLessThan(0, $after['odp_tuz23_nar'] - $before['odp_tuz23_nar'], 'ř. 40 daň klesla o §74b snížení (21 %).');
        self::assertLessThan(0, $after['odp_tuz5_nar'] - $before['odp_tuz5_nar'], 'ř. 41 daň klesla (12 %).');
        self::assertGreaterThan(0, $after['opr_dluz'] - $before['opr_dluz'], 'ř. 34 opr_dluz vzrostl (§74b snížení KLADNĚ).');
    }

    /** @return array{odp_tuz23_nar:int, odp_tuz5_nar:int, opr_dluz:int} */
    private function dphVetaAttrs(array $result): array
    {
        $dom = new \DOMDocument();
        self::assertTrue($dom->loadXML((string) $result['xml']));
        $veta3 = $dom->getElementsByTagName('Veta3')->item(0);
        $veta4 = $dom->getElementsByTagName('Veta4')->item(0);
        self::assertNotNull($veta4, 'Veta4 (ř. 40/41) musí být přítomna.');
        return [
            'odp_tuz23_nar' => $veta4 !== null ? (int) $veta4->getAttribute('odp_tuz23_nar') : 0,
            'odp_tuz5_nar'  => $veta4 !== null ? (int) $veta4->getAttribute('odp_tuz5_nar') : 0,
            'opr_dluz'      => $veta3 !== null ? (int) $veta3->getAttribute('opr_dluz') : 0,
        ];
    }

    // ── KH: §74b doklad v B.2 se zdph_44='P' (i pod 10 000 Kč) ─────────────────
    public function testKontrolniHlaseniPutsSection74bInB2WithBadDebtFlag(): void
    {
        $this->seedTwoRateInvoice();
        $this->service->recordAging($this->supplierId, 2025, 7, $this->userId);

        $builder = $this->container->get(KontrolniHlaseniBuilder::class);
        $result = $builder->build($this->supplierId, 2025, 7);

        $dom = new \DOMDocument();
        self::assertTrue($dom->loadXML($result['xml']));
        $found = false;
        foreach ($dom->getElementsByTagName('VetaB2') as $b2) {
            if ($b2->getAttribute('dic_dod') === '64949681') {
                $found = true;
                self::assertSame('P', $b2->getAttribute('zdph_44'), '§74b oprava se v B.2 značí zdph_44=P.');
            }
        }
        self::assertTrue($found, '§74b korekce musí být v KH B.2 (i pod 10 000 Kč).');
    }

    // ── Zdroj `unpaid`: skutečné úhrady (záloha + banka + hotovost), NE amount_to_pay ──
    // Regrese k opravě §74b: amount_to_pay je generovaný sloupec (total_with_vat −
    // advance_paid_amount), který bankovní/hotovostní úhrady neodráží. Plně/částečně
    // bankou uhrazené doklady se proto nesmí hlásit jako 100% neuhrazené.

    /** Plně bankou uhrazený doklad → unpaid=0 → target 0 → §74b ho NEFLAGNE. */
    public function testFullyBankPaidInvoiceIsNotFlagged(): void
    {
        $due = sprintf('%04d-01-15', $this->bankPayYear);
        $id  = $this->seedSingleRateInvoice('PF-74B-PAID', $due, 10000.0, 2100.0); // total 12 100
        $this->seedBankPayment($id, 12100.0, sprintf('%04d-03-20', $this->bankPayYear));

        $preview = $this->service->previewAging($this->supplierId, $this->bankPayYear, 7);

        self::assertNull(
            $this->findPreviewRow($preview, $id),
            'Plně bankou uhrazený doklad (unpaid=0) §74b neflagne — žádný řádek korekce.'
        );
    }

    /** Skutečně neuhrazený doklad (bez záloh/úhrad) → plné snížení odpočtu. */
    public function testGenuineUnpaidInvoiceIsFullyReduced(): void
    {
        $due = sprintf('%04d-01-15', $this->bankPayYear);
        $id  = $this->seedSingleRateInvoice('PF-74B-UNPAID', $due, 10000.0, 2100.0);

        $preview = $this->service->previewAging($this->supplierId, $this->bankPayYear, 7);

        $row = $this->findPreviewRow($preview, $id);
        self::assertNotNull($row, 'Skutečně neuhrazený doklad musí být v korekci §74b.');
        self::assertTrue($row['aged']);
        self::assertSame(1.0, $row['unpaid_ratio']);
        self::assertSame(2100.0, $row['target_reduction'], 'Plné snížení = celý uplatněný odpočet.');
        self::assertSame('reduction', $row['movement']);
    }

    /** Částečně bankou uhrazený doklad (50 %) → poměrné snížení odpočtu. */
    public function testPartiallyBankPaidInvoiceIsProportionallyReduced(): void
    {
        $due = sprintf('%04d-01-15', $this->bankPayYear);
        $id  = $this->seedSingleRateInvoice('PF-74B-HALF', $due, 10000.0, 2100.0); // total 12 100
        $this->seedBankPayment($id, 6050.0, sprintf('%04d-03-20', $this->bankPayYear)); // 50 %

        $preview = $this->service->previewAging($this->supplierId, $this->bankPayYear, 7);

        $row = $this->findPreviewRow($preview, $id);
        self::assertNotNull($row, 'Částečně uhrazený doklad zůstává v korekci §74b.');
        self::assertSame(0.5, $row['unpaid_ratio']);
        self::assertSame(1050.0, $row['target_reduction'], 'Poměrné snížení = 50 % odpočtu.');
        self::assertSame('reduction', $row['movement']);
    }

    /** @param array{rows:list<array<string,mixed>>} $preview */
    private function findPreviewRow(array $preview, int $invoiceId): ?array
    {
        foreach ($preview['rows'] as $r) {
            if ((int) $r['purchase_invoice_id'] === $invoiceId) {
                return $r;
            }
        }
        return null;
    }

    /** Přijatá faktura, jedna sazba (21 %), plně neuhrazená (advance=0). Vrací její id. */
    private function seedSingleRateInvoice(string $number, string $due, float $base, float $vat): int
    {
        $pdo   = $this->db->pdo();
        $total = $base + $vat;
        $stmt  = $pdo->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date,
                 due_date, received_at, currency_id, reverse_charge, vendor_snapshot,
                 total_without_vat, total_vat, total_with_vat, advance_paid_amount,
                 status, vat_classification_code, vat_deduction, vat_deduction_percent, created_by)
             VALUES (?, ?, ?, "invoice", ?, ?, ?, ?, ?, 0, "{}", ?, ?, ?, 0, "received", "40", "full", 100, ?)'
        );
        $stmt->execute([
            $this->supplierId, $this->vendorId, $number, $due, $due, $due, $due,
            $this->currencyId, $base, $vat, $total, $this->userId,
        ]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * Zaeviduje bankovní úhradu přijaté faktury: bank_statement + bank_transaction +
     * payment_match + POSTED 'bank' zápis v ledgeru (reversed_by NULL, shodná měna CZK) —
     * přesně vzor, na který §74b nově čte skutečnou úhradu (viz paidAdvanceAmount).
     */
    private function seedBankPayment(int $invoiceId, float $amount, string $postedDate): void
    {
        $pdo = $this->db->pdo();
        if ($this->bankPeriodId === 0) {
            $this->bankPeriodId = $this->periods->create(
                $this->supplierId,
                $this->bankPayYear,
                sprintf('%04d-01-01', $this->bankPayYear),
                sprintf('%04d-12-31', $this->bankPayYear)
            );
        }

        $pdo->prepare(
            "INSERT INTO bank_statements
                (file_name, file_hash, account_number, bank_code, currency, statement_date)
             VALUES (?, ?, '123456789', '0100', 'CZK', ?)"
        )->execute([
            's74b-' . $invoiceId . '.gpc',
            hash('sha256', 's74b' . $invoiceId . $postedDate . uniqid('', true)),
            $postedDate,
        ]);
        $statementId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            "INSERT INTO bank_transactions
                (statement_id, posted_at, amount, currency, variable_symbol)
             VALUES (?, ?, ?, 'CZK', '0')"
        )->execute([$statementId, $postedDate, $amount]);
        $txId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            "INSERT INTO payment_matches
                (supplier_id, bank_transaction_id, purchase_invoice_id, amount, match_type)
             VALUES (?, ?, ?, ?, 'manual')"
        )->execute([$this->supplierId, $txId, $invoiceId, $amount]);

        $pdo->prepare(
            'INSERT INTO journal_entries
                (supplier_id, period_id, entry_date, source_type, source_id, posted_at, posted_by, reversed_by)
             VALUES (?, ?, ?, "bank", ?, ?, ?, NULL)'
        )->execute([
            $this->supplierId, $this->bankPeriodId, $postedDate, $txId,
            $postedDate . ' 10:00:00', $this->userId,
        ]);
    }
}
