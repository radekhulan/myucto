<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Tax\BadDebt;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Tax\BadDebt\Section74bService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * §2.5 (audit PODVOJNE-AUDIT.md) — § 74b ZDPH: korekce odpočtu u neuhrazených závazků dlužníka.
 *
 * Chrání jádro výpočtu: aging (6 kal. měsíců po měsíci splatnosti), dotčená DPH z uplatněného
 * odpočtu poměrně k neuhrazené části, netting snížení/obnovy. Regresní scénáře dle auditu:
 * úplná úhrada, částečná úhrada, splátka, zápočet, částečný nárok + obnova po úhradě.
 *
 * Izolace: vše v transakci s rollbackem.
 */
#[Group('integration')]
final class Section74bServiceTest extends TestCase
{
    private Connection $db;
    private Section74bService $service;

    private int $supplierId = 0;
    private int $userId = 0;
    private int $vendorId = 0;
    private int $currencyId = 0;
    private int $czId = 0;
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
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->czId       = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->userId === 0 || $this->currencyId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        $pdo->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, dic, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, "Dodavatel §74b", "Test 1", "Praha", "11000", ?, "CZ74747474", "v@example.com", "cs", ?, 0, 1)'
        )->execute([$this->supplierId, $this->czId, $this->currencyId]);
        $this->vendorId = (int) $pdo->lastInsertId();
    }

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
     * Seed přijaté faktury. $vat je celková DPH, $withVat celkem s DPH, $advance uhrazeno.
     */
    private function seed(
        string $number,
        string $dueDate,
        float $vat,
        float $withVat,
        float $advance = 0.0,
        bool $reverseCharge = false,
        string $vatDeduction = 'full',
        float $vatDeductionPercent = 100.0,
        ?string $taxDate = null,
    ): int {
        $base = round($withVat - $vat, 2);
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date,
                 due_date, received_at, currency_id, reverse_charge, vendor_snapshot,
                 total_without_vat, total_vat, total_with_vat, advance_paid_amount,
                 status, vat_classification_code, vat_deduction, vat_deduction_percent, created_by)
             VALUES (?, ?, ?, "invoice", ?, ?, ?, ?, ?, ?, "{}", ?, ?, ?, ?, "received", "40", ?, ?, ?)'
        );
        $stmt->execute([
            $this->supplierId, $this->vendorId, $number, $dueDate, $taxDate ?? $dueDate, $dueDate, $dueDate,
            $this->currencyId, $reverseCharge ? 1 : 0, $base, $vat, $withVat, $advance,
            $vatDeduction, $vatDeductionPercent, $this->userId,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @return array<int,array<string,mixed>> preview rows keyed by purchase_invoice_id */
    private function previewRows(int $year, int $month): array
    {
        $out = [];
        foreach ($this->service->previewAging($this->supplierId, $year, $month)['rows'] as $r) {
            $out[(int) $r['purchase_invoice_id']] = $r;
        }
        return $out;
    }

    // ── úplná úhrada → žádná korekce ──────────────────────────────────────────
    public function testFullPaymentNoCorrection(): void
    {
        // Splatnost 2025-01 → korekce od 2025-07 (M+6); ale plně uhrazeno → nedotčeno.
        $id = $this->seed('PF-FULL', '2025-01-15', 2100.0, 12100.0, advance: 12100.0);
        $rows = $this->previewRows(2025, 8);
        self::assertArrayNotHasKey($id, $rows, 'Plně uhrazený závazek nesmí do §74b korekce.');
    }

    // ── neuhrazeno + aged → plné snížení uplatněného odpočtu ───────────────────
    public function testUnpaidAgedFullReduction(): void
    {
        $id = $this->seed('PF-UNPAID', '2025-01-15', 2100.0, 12100.0);
        $rows = $this->previewRows(2025, 8);
        self::assertArrayHasKey($id, $rows);
        self::assertSame('reduction', $rows[$id]['movement']);
        self::assertSame(2100.0, $rows[$id]['target_reduction']);
        self::assertSame(2100.0, $rows[$id]['delta']);
        self::assertSame('corrected', $rows[$id]['state']);
    }

    // ── ještě neuplynulo 6 měsíců → žádná korekce ─────────────────────────────
    public function testNotYetAgedNoCorrection(): void
    {
        // Splatnost 2025-06 → korekce od 2025-12 (M+6); ve 2025-08 ještě ne.
        $id = $this->seed('PF-FRESH', '2025-06-15', 2100.0, 12100.0);
        $rows = $this->previewRows(2025, 8);
        self::assertArrayNotHasKey($id, $rows, 'Před uplynutím 6 měsíců po měsíci splatnosti žádná korekce.');
    }

    // ── přesná hranice §74b: poslední den lhůty (31. 7. 2025) → korekce až od 2025-07 (M+6) ──
    public function testAgingBoundaryStartsInSixthFollowingMonth(): void
    {
        $id = $this->seed('PF-BOUNDARY', '2025-01-15', 2100.0, 12100.0);
        // 2025-06 (M+5) — lhůta (31. 7.) ještě neuplynula → žádná korekce.
        self::assertArrayNotHasKey($id, $this->previewRows(2025, 6),
            'Před posledním dnem 6. měsíce následujícího po splatnosti se §74b neuplatní.');
        // 2025-07 (M+6) — poslední den lhůty náleží do tohoto období → korekce vzniká.
        $rows7 = $this->previewRows(2025, 7);
        self::assertArrayHasKey($id, $rows7, 'V období posledního dne lhůty §74b korekce vzniká.');
        self::assertSame('reduction', $rows7[$id]['movement']);
        self::assertSame(2100.0, $rows7[$id]['target_reduction']);
    }

    // ── od 1. 1. 2027 lhůta tři měsíce (§ 74b odst. 3 ZDPH ve znění zák. č. 180/2026 Sb.) ──
    public function testAgingBoundaryFrom2027IsThirdFollowingMonth(): void
    {
        $id = $this->seed('PF-2027', '2027-01-15', 2100.0, 12100.0, taxDate: '2027-01-01');
        // 2027-03 (M+2) — poslední den lhůty (30. 4. 2027) ještě nenastal.
        self::assertArrayNotHasKey($id, $this->previewRows(2027, 3),
            'Před posledním dnem 3. měsíce po splatnosti se §74b neuplatní.');
        // 2027-04 (M+3) — korekce vzniká, za staré lhůty by to bylo až 2027-07.
        $rows = $this->previewRows(2027, 4);
        self::assertArrayHasKey($id, $rows, 'Od 2027 vzniká korekce ve 3. měsíci po splatnosti.');
        self::assertSame('reduction', $rows[$id]['movement']);
        self::assertSame(2100.0, $rows[$id]['target_reduction']);
    }

    public function testTransitionKeepsSixMonthsFor2026TaxPoint(): void
    {
        $september = $this->seed('PF-2026-09', '2026-09-15', 2100.0, 12100.0);
        self::assertArrayNotHasKey($september, $this->previewRows(2027, 2));
        self::assertSame(2100.0, $this->previewRows(2027, 3)[$september]['target_reduction']);

        $december = $this->seed('PF-2026-12', '2027-01-15', 2100.0, 12100.0, taxDate: '2026-12-31');
        self::assertArrayNotHasKey($december, $this->previewRows(2027, 6));
        self::assertSame(2100.0, $this->previewRows(2027, 7)[$december]['target_reduction']);
    }

    // ── částečná úhrada → poměrné snížení ─────────────────────────────────────
    public function testPartialPaymentProportionalReduction(): void
    {
        // 50 % uhrazeno → snížení odpočtu o 50 % (1 050 z 2 100).
        $id = $this->seed('PF-PART', '2025-01-15', 2100.0, 12100.0, advance: 6050.0);
        $rows = $this->previewRows(2025, 8);
        self::assertArrayHasKey($id, $rows);
        self::assertSame('reduction', $rows[$id]['movement']);
        self::assertSame(0.5, $rows[$id]['unpaid_ratio']);
        self::assertSame(1050.0, $rows[$id]['target_reduction']);
    }

    // ── zápočet = úhrada (advance_paid_amount) ────────────────────────────────
    public function testOffsetTreatedAsPayment(): void
    {
        // Zápočtem uhrazeno 9 075 (75 %) → neuhrazeno 25 % → snížení 525.
        $id = $this->seed('PF-OFFSET', '2025-01-15', 2100.0, 12100.0, advance: 9075.0);
        $rows = $this->previewRows(2025, 8);
        self::assertArrayHasKey($id, $rows);
        self::assertSame(0.25, $rows[$id]['unpaid_ratio']);
        self::assertSame(525.0, $rows[$id]['target_reduction']);
    }

    // ── částečný nárok na odpočet (§75/§76) → korekce jen z uplatněné části ────
    public function testPartialDeductionClaimLimitsReduction(): void
    {
        // Uplatněn jen 50% odpočet (1 050 z 2 100 DPH), neuhrazeno celé → snížení 1 050.
        $id = $this->seed('PF-COEF', '2025-01-15', 2100.0, 12100.0, vatDeduction: 'proportional', vatDeductionPercent: 50.0);
        $rows = $this->previewRows(2025, 8);
        self::assertArrayHasKey($id, $rows);
        self::assertSame(1050.0, $rows[$id]['claimed_deduction_vat']);
        self::assertSame(1050.0, $rows[$id]['target_reduction']);
    }

    // ── reverse charge (samovyměření) → §74b se neuplatní ─────────────────────
    public function testReverseChargeExcluded(): void
    {
        $id = $this->seed('PF-RC', '2025-01-15', 2100.0, 12100.0, reverseCharge: true);
        $rows = $this->previewRows(2025, 8);
        self::assertArrayNotHasKey($id, $rows, 'Reverse charge plnění není dotčeno §74b.');
    }

    // ── splátka: snížení → částečná obnova → plná obnova (netting) ─────────────
    public function testInstallmentsRestoreProportionally(): void
    {
        $id = $this->seed('PF-INSTALL', '2025-01-15', 2100.0, 12100.0);

        // 2025-08: neuhrazeno → snížení 2 100 (id-scoped; sdílená test DB může mít další plnění).
        $rows8 = $this->previewRows(2025, 8);
        self::assertSame('reduction', $rows8[$id]['movement']);
        self::assertSame(2100.0, $rows8[$id]['delta']);
        $this->service->recordAging($this->supplierId, 2025, 8, $this->userId);

        // Splátka 50 % → obnova 1 050.
        $this->db->pdo()->prepare('UPDATE purchase_invoices SET advance_paid_amount = 6050 WHERE id = ?')->execute([$id]);
        $rows9 = $this->previewRows(2025, 9);
        self::assertSame('restoration', $rows9[$id]['movement']);
        self::assertSame(-1050.0, $rows9[$id]['delta']);
        $this->service->recordAging($this->supplierId, 2025, 9, $this->userId);

        // Doplatek → plná obnova zbytku 1 050, stav restored.
        $this->db->pdo()->prepare('UPDATE purchase_invoices SET advance_paid_amount = 12100 WHERE id = ?')->execute([$id]);
        $rows10 = $this->previewRows(2025, 10);
        self::assertSame('restoration', $rows10[$id]['movement']);
        self::assertSame(-1050.0, $rows10[$id]['delta']);
        self::assertSame('restored', $rows10[$id]['state']);
        $this->service->recordAging($this->supplierId, 2025, 10, $this->userId);

        // Po plné obnově už plnění není dotčené.
        $rows11 = $this->previewRows(2025, 11);
        self::assertArrayNotHasKey($id, $rows11, 'Po plné obnově odpočtu §74b plnění mizí z korekcí.');
    }

    // ── obnova po úplné úhradě po předchozím snížení ──────────────────────────
    public function testRestorationAfterFullPayment(): void
    {
        $id = $this->seed('PF-RESTORE', '2025-01-15', 2100.0, 12100.0);
        $this->service->recordAging($this->supplierId, 2025, 8, $this->userId);

        $this->db->pdo()->prepare('UPDATE purchase_invoices SET advance_paid_amount = 12100, paid_at = "2025-09-10" WHERE id = ?')
            ->execute([$id]);

        $rows9 = $this->previewRows(2025, 9);
        self::assertArrayHasKey($id, $rows9);
        self::assertSame('restoration', $rows9[$id]['movement']);
        self::assertSame(-2100.0, $rows9[$id]['delta']);
        self::assertSame('restored', $rows9[$id]['state']);
    }
}
