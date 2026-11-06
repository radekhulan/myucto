<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Report;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Report\DphPriznaniBuilder;
use MyInvoice\Service\Report\KontrolniHlaseniBuilder;
use MyInvoice\Service\Report\VatLedgerService;
use MyInvoice\Service\Report\VatPeriodEntitlementService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * EPIC VH-04 — DPH výkazy podle stavu plátcovství K OBDOBÍ (supplier_vat_status_history).
 *
 * Živý supplier.is_vat_payer je jen cache stavu „dnes"; pro výkaz je rozhodný stav
 * k POSLEDNÍMU DNI období výkazu. Scénáře:
 *   a) firma plátce v 2024, od 1. 1. 2026 neplátce → DPHDP3 za 12/2024 typ P bez
 *      warningu, za 03/2026 warning „nebyl k poslednímu dni období … plátce",
 *   b) KH za období plátcovství projde validací i když je firma DNES neplátce,
 *   c) § 99a odst. 3: registrace 15. 5. 2025 → kvartál tvrdě zamítnut pro 2025
 *      i 2026, povolen 2027; firma odjakživa plátce (baseline 1900) bez omezení,
 *   d) counterparty_dic vydaného dokladu preferuje client_snapshot.dic před
 *      později změněným živým DIČ klienta (fallback na živé, když snapshot DIČ nemá).
 */
#[Group('integration')]
final class VatStatusPeriodTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private DphPriznaniBuilder $dph;
    private KontrolniHlaseniBuilder $kh;
    private VatPeriodEntitlementService $entitlement;
    private VatLedgerService $ledger;

    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private int $userId = 0;
    private int $czId = 0;
    private int $seq = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildContainer();
            $this->db = $c->get(Connection::class);
            $this->dph = $c->get(DphPriznaniBuilder::class);
            $this->kh = $c->get(KontrolniHlaseniBuilder::class);
            $this->entitlement = $c->get(VatPeriodEntitlementService::class);
            $this->ledger = $c->get(VatLedgerService::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $source = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($source === 0 || $this->userId === 0 || $this->currencyId === 0 || $this->vatRateId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);
        $pdo->prepare('UPDATE supplier SET is_identified = 0 WHERE id = ?')->execute([$this->supplierId]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
    }

    // ── a) DPHDP3: typ P pro období plátcovství, warning pro období po odregistraci ──

    public function testDphdp3ForPayerPeriodBuildsAsTypePEvenWhenDeregisteredToday(): void
    {
        $pdo = $this->db->pdo();
        // Plátce od nepaměti, od 1. 1. 2026 neplátce → živá cache dnes (2026+) je 0.
        $this->setVatPayerAt($pdo, $this->supplierId, '1900-01-01', true);
        $this->setVatPayerAt($pdo, $this->supplierId, '2026-01-01', false);

        $result = $this->dph->build($this->supplierId, 2024, 12, 'monthly');
        $dp = (new \SimpleXMLElement($result['xml']))->DPHDP3;

        self::assertSame('P', (string) $dp->VetaD['typ_platce'], 'Za 12/2024 byla firma plátcem → typ P.');
        self::assertStringNotContainsString(
            'nebyl v průběhu období',
            implode("\n", $result['warnings']),
            'Za období plátcovství nesmí být warning neplátce.',
        );
    }

    public function testDphdp3ForNonPayerPeriodWarnsAboutStatusAtPeriod(): void
    {
        $pdo = $this->db->pdo();
        $this->setVatPayerAt($pdo, $this->supplierId, '1900-01-01', true);
        $this->setVatPayerAt($pdo, $this->supplierId, '2026-01-01', false);

        $result = $this->dph->build($this->supplierId, 2026, 3, 'monthly');

        self::assertStringContainsString(
            'nebyl v průběhu období evidovaný jako plátce DPH',
            implode("\n", $result['warnings']),
            'Za 03/2026 už firma plátcem nebyla — warning musí mluvit o období.',
        );
    }

    // ── b) KH: validace stavem k období, ne dneškem ─────────────────────────────

    public function testKontrolniHlaseniForPayerPeriodPassesValidationWhenDeregisteredToday(): void
    {
        $pdo = $this->db->pdo();
        $this->setVatPayerAt($pdo, $this->supplierId, '1900-01-01', true);
        $this->setVatPayerAt($pdo, $this->supplierId, '2026-01-01', false);

        $result = $this->kh->build($this->supplierId, 2024, 12, 'monthly');
        $warnings = implode("\n", $result['warnings']);

        self::assertStringNotContainsString('nebyl v průběhu období plátcem DPH', $warnings);
        self::assertStringNotContainsString('Identifikovaná osoba', $warnings);

        // Kontrast: za období po odregistraci warning JE.
        $after = $this->kh->build($this->supplierId, 2026, 3, 'monthly');
        self::assertStringContainsString(
            'nebyl v průběhu období plátcem DPH',
            implode("\n", $after['warnings']),
        );
    }

    // ── c) § 99a odst. 3: rok registrace + následující rok bez kvartálu ─────────

    public function testQuarterlyEntitlementDeniedInRegistrationYearAndNextYear(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE supplier SET vat_period = "quarterly", is_vat_payer = 1 WHERE id = ?')
            ->execute([$this->supplierId]);
        // Neplátce od nepaměti, registrace k 15. 5. 2025 (přechod 0→1 v historii).
        $this->setVatPayerAt($pdo, $this->supplierId, '1900-01-01', false);
        $this->setVatPayerAt($pdo, $this->supplierId, '2025-05-15', true);

        $r2025 = $this->entitlement->evaluate($this->supplierId, 2025);
        self::assertFalse($r2025['ok'], 'Rok registrace (2025): kvartál nelze — tvrdý ne-nárok.');
        self::assertStringContainsString('§ 99a odst. 3', implode("\n", $r2025['warnings']));

        $r2026 = $this->entitlement->evaluate($this->supplierId, 2026);
        self::assertFalse($r2026['ok'], 'Rok bezprostředně následující (2026): kvartál stále nelze.');
        self::assertStringContainsString('§ 99a odst. 3', implode("\n", $r2026['warnings']));

        $r2027 = $this->entitlement->evaluate($this->supplierId, 2027);
        self::assertTrue($r2027['ok'], 'Od 2027 už § 99a odst. 3 nebrání (obrat je pod limitem).');
        self::assertStringNotContainsString('§ 99a odst. 3', implode("\n", $r2027['warnings']));
    }

    public function testQuarterlyEntitlementUnrestrictedForAlwaysPayer(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE supplier SET vat_period = "quarterly", is_vat_payer = 1 WHERE id = ?')
            ->execute([$this->supplierId]);
        // Baseline 1900 = plátce, žádný přechod 0→1 → žádné omezení z registrace.
        $this->setVatPayerAt($pdo, $this->supplierId, '1900-01-01', true);

        $r = $this->entitlement->evaluate($this->supplierId, 2026);

        self::assertTrue($r['ok'], 'Firma odjakživa plátce nemá z § 99a odst. 3 žádné omezení.');
        self::assertStringNotContainsString('§ 99a odst. 3', implode("\n", $r['warnings']));
    }

    // ── d) counterparty_dic ze snapshotu dokladu má přednost před živým DIČ ─────

    public function testSaleCounterpartyDicPrefersClientSnapshotOverLiveDic(): void
    {
        $pdo = $this->db->pdo();
        $this->setVatPayerAt($pdo, $this->supplierId, '1900-01-01', true);

        $clientId = $this->client('Odběratel snapshot', 'CZ11122333');
        // Doklad se snapshotem nese DIČ platné v okamžiku vystavení…
        $withSnapshot = $this->sale($clientId, '2024-06-10', '{"dic":"CZ11122333"}');
        // …doklad bez DIČ ve snapshotu padá na živý join (legacy chování).
        $withoutSnapshot = $this->sale($clientId, '2024-06-15', '{}');

        // Klient si později DIČ změnil — živá hodnota se do starých výkazů nesmí propsat.
        $pdo->prepare('UPDATE clients SET dic = ? WHERE id = ?')->execute(['CZ99988777', $clientId]);

        $dicByInvoice = [];
        foreach ($this->ledger->rows($this->supplierId, '2024-06-01', '2024-06-30') as $row) {
            if ($row['source'] === 'sale') {
                $dicByInvoice[(int) $row['invoice_id']] = $row['counterparty_dic'];
            }
        }

        self::assertSame('CZ11122333', $dicByInvoice[$withSnapshot] ?? null, 'Snapshot DIČ má přednost před změněným živým DIČ.');
        self::assertSame('CZ99988777', $dicByInvoice[$withoutSnapshot] ?? null, 'Bez DIČ ve snapshotu se použije živý join (fallback).');
    }

    /**
     * Přijatý doklad: DIČ uvedené na dokladu (snapshot dodavatele) má přednost, i když ho
     * karta nemá - jinak plátce s neúplnou kartou spadne v KH z B.2 do B.3. Prázdné DIČ
     * ve snapshotu kartu nepřebíjí a snapshot jiného kontaktu (přehozený dodavatel) se
     * nepoužije.
     */
    public function testPurchaseCounterpartyDicPrefersDocumentSnapshotOfTheSameVendor(): void
    {
        $this->setVatPayerAt($this->db->pdo(), $this->supplierId, '1900-01-01', true);
        $vendorWithoutDic = $this->client('Dodavatel bez DIČ na kartě', '');
        $vendorWithDic = $this->client('Dodavatel s DIČ na kartě', 'CZ22233444');

        $documentDic = $this->purchase($vendorWithoutDic, '{"company_name":"Dodavatel","dic":"CZ33344555"}');
        $emptySnapshotDic = $this->purchase($vendorWithDic, '{"company_name":"Dodavatel","dic":""}');
        $otherContact = $this->purchase($vendorWithDic, '{"id":' . ($vendorWithDic + 1000) . ',"dic":"CZ44455666"}');
        $sameContact = $this->purchase($vendorWithDic, '{"id":' . $vendorWithDic . ',"dic":"CZ55566777"}');

        $dicByPurchase = [];
        foreach ($this->ledger->rows($this->supplierId, '2024-06-01', '2024-06-30') as $row) {
            if ($row['source'] === 'purchase') {
                $dicByPurchase[(int) $row['invoice_id']] = $row['counterparty_dic'];
            }
        }

        self::assertSame('CZ33344555', $dicByPurchase[$documentDic] ?? null, 'DIČ z dokladu, i když karta DIČ nemá.');
        self::assertSame('CZ22233444', $dicByPurchase[$emptySnapshotDic] ?? null, 'Prázdné DIČ ve snapshotu nepřebíjí kartu.');
        self::assertSame('CZ22233444', $dicByPurchase[$otherContact] ?? null, 'Snapshot jiného kontaktu se nepoužije.');
        self::assertSame('CZ55566777', $dicByPurchase[$sameContact] ?? null, 'Snapshot téhož kontaktu platí.');
    }

    // ── helpers ─────────────────────────────────────────────────────────────────

    /** Přijatá faktura 10 000 + 21 % z 10. 6. 2024 s jednou položkou; vrací purchase_invoice_id. */
    private function purchase(int $vendorId, string $vendorSnapshotJson): int
    {
        $this->seq++;
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date,
                 due_date, received_at, received_at_source, currency_id, exchange_rate, reverse_charge,
                 vendor_snapshot, total_without_vat, total_vat, total_with_vat, status,
                 vat_classification_code, vat_deduction, created_by)
             VALUES (?, ?, ?, "invoice", "2024-06-10", "2024-06-10", "2024-06-10", "2024-06-10", "manual", ?, 1, 0, ?,
                     10000, 2100, 12100, "received", "40", "full", ?)'
        )->execute([$this->supplierId, $vendorId, 'PDIC-' . $this->seq, $this->currencyId, $vendorSnapshotJson, $this->userId]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO purchase_invoice_items
                (purchase_invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index, vat_classification_code)
             VALUES (?, "Test", 1, "ks", 10000, ?, 21, 10000, 2100, 12100, 0, "40")'
        )->execute([$id, $this->vatRateId]);

        return $id;
    }

    private function client(string $name, string $dic): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, dic, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Test 1", "Praha", "11000", ?, ?, "vh04@example.com", "cs", ?, 1, 0)'
        )->execute([$this->supplierId, $name, $this->czId, $dic, $this->currencyId]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /** Vydaná faktura 10 000 + 21 % s jednou položkou; vrací invoice_id. */
    private function sale(int $clientId, string $taxDate, string $clientSnapshotJson): int
    {
        $this->seq++;
        $base = 10000.0;
        $vat = 2100.0;
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO invoices
                (supplier_id, client_id, varsymbol, invoice_type, issue_date, tax_date,
                 due_date, currency_id, reverse_charge, client_snapshot, supplier_snapshot,
                 total_without_vat, total_vat, total_with_vat, status, created_by)
             VALUES (?, ?, ?, "invoice", ?, ?, ?, ?, 0, ?, "{}", ?, ?, ?, "issued", ?)'
        )->execute([
            $this->supplierId, $clientId, 'VH04-' . $this->seq,
            $taxDate, $taxDate, $taxDate, $this->currencyId, $clientSnapshotJson,
            $base, $vat, $base + $vat, $this->userId,
        ]);
        $invoiceId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO invoice_items
                (invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, "Položka VH-04", 1, "ks", ?, ?, 21, ?, ?, ?, 0)'
        )->execute([$invoiceId, $base, $this->vatRateId, $base, $vat, $base + $vat]);

        return $invoiceId;
    }
}
