<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Tax\Return;

use MyInvoice\Service\Tax\Return\DpfoReturnDataProvider;
use MyInvoice\Tests\Integration\TaxEvidence\CashJournalTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * §7 podklady DPFO přiznání ({@see DpfoReturnDataProvider}) — kasová báze v režimu
 * tax_evidence (audit 2026-07, nález „DPFO paušál ignoruje kasovou bázi").
 *
 * Regrese: v paušálu se §7 příjem MUSÍ brát z peněžního deníku (kasová báze), ne jen
 * z faktur status='paid' — jinak hotovostní tržby bez faktury a částečné úhrady faktur
 * do příjmů nevstoupí a přiznání je podhodnocené. double_entry zůstává na fakturách.
 */
#[Group('integration')]
final class DpfoReturnDataProviderTest extends CashJournalTestCase
{
    private DpfoReturnDataProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = $this->container->get(DpfoReturnDataProvider::class);
        $this->setVatPayer($this->supplierId, false);
    }

    /**
     * OSVČ v tax_evidence s paušálem: zaplacená faktura + hotovostní tržba bez faktury
     * + částečně uhrazená faktura → §7 příjem musí zahrnout VŠE (18000), ne jen faktury
     * status='paid' (10000). Původní chyba počítala jen annualIncome (10000).
     */
    public function testPausalTaxEvidenceIncludesCashSalesAndPartialPayments(): void
    {
        // 1) Plně zaplacená faktura → v annualIncome i v deníku (přes úhradu).
        $paid = $this->saleInvoice($this->supplierId, [
            'without' => 10000.0, 'with' => 10000.0, 'status' => 'paid',
            'paid_at' => self::YEAR . '-06-15',
        ]);
        $this->cashDoc('in', 'invoice_payment', 10000.0, ['invoice_id' => $paid]);

        // 2) Částečně uhrazená (status='issued') → NENÍ v annualIncome, je v deníku.
        $partial = $this->saleInvoice($this->supplierId, [
            'without' => 20000.0, 'with' => 20000.0, 'status' => 'issued',
        ]);
        $this->cashDoc('in', 'invoice_payment', 5000.0, ['invoice_id' => $partial]);

        // 3) Hotovostní tržba bez faktury → NENÍ v annualIncome, je v deníku.
        $this->cashDoc('in', 'sale', 3000.0);

        $annual = $this->container->get(\MyInvoice\Repository\TaxProfileRepository::class)
            ->annualIncome($this->supplierId, self::YEAR, false);
        self::assertEqualsWithDelta(10000.0, $annual, 0.001, 'Předpoklad: annualIncome vidí jen zaplacenou fakturu.');

        $result = $this->provider->gather($this->supplierId, self::YEAR);

        self::assertSame('pausal', $result['expense_mode']);
        self::assertSame('tax_evidence', $result['accounting_mode']);
        self::assertEqualsWithDelta(
            18000.0,
            $result['s7_income'],
            0.001,
            '§7 příjem musí být kasový: 10000 (faktura) + 5000 (částečná úhrada) + 3000 (hotovost).'
        );
        self::assertGreaterThan($annual, $result['s7_income'], 'Kasový příjem musí být vyšší než jen zaplacené faktury.');

        $joined = implode("\n", $result['warnings']);
        self::assertStringContainsString('hotovostní tržby bez faktury', $joined);
        self::assertStringContainsString('částečné úhrady faktur', $joined);
    }

    /**
     * REGRESE (Fáze E nález E1, §23/2 ZDP): FO s podvojným účetnictvím odvozuje §7 z VÝSLEDKU
     * HOSPODAŘENÍ deníku, NE z kasové báze ani ze zaplacených faktur. Bez zaúčtovaných výnosů
     * v deníku je §7 příjem 0 (fakturační/hotovostní čísla se ignorují) + tvrdé varování.
     * (Plný VH scénář s deníkovými zápisy pokrývá {@see DpfoDoubleEntryReturnTest}.)
     */
    public function testDoubleEntryDerivesSection7FromLedgerNotInvoices(): void
    {
        $paid = $this->saleInvoice($this->supplierId, [
            'without' => 10000.0, 'with' => 10000.0, 'status' => 'paid',
            'paid_at' => self::YEAR . '-06-15',
        ]);
        $this->cashDoc('in', 'invoice_payment', 10000.0, ['invoice_id' => $paid]);
        $this->cashDoc('in', 'sale', 3000.0);

        $this->db->pdo()->prepare('UPDATE supplier SET accounting_mode = ? WHERE id = ?')
            ->execute(['double_entry', $this->supplierId]);

        $result = $this->provider->gather($this->supplierId, self::YEAR);

        self::assertSame('double_entry', $result['accounting_mode']);
        self::assertSame('actual', $result['expense_mode'], 'PÚ = skutečné výdaje z VH, ne paušál.');
        self::assertEqualsWithDelta(
            0.0,
            $result['s7_income'],
            0.001,
            'Bez výnosů v deníku je §7 z VH nulový — faktury/hotovost se ignorují.'
        );
        self::assertStringContainsString('výsledku hospodaření', implode("\n", $result['warnings']));
    }

    public function testExpenseModeChangeEmitsSection23Warning(): void
    {
        $profiles = $this->container->get(\MyInvoice\Repository\TaxProfileRepository::class);
        $profiles->upsert($this->supplierId, self::YEAR - 1, [
            'activity_rate' => 60, 'flat_tax_band' => 'none', 'use_actual_expenses' => true,
        ]);
        $profiles->upsert($this->supplierId, self::YEAR, [
            'activity_rate' => 60, 'flat_tax_band' => 'none', 'use_actual_expenses' => false,
        ]);

        $result = $this->provider->gather($this->supplierId, self::YEAR);
        $warnings = implode("\n", $result['warnings']);
        self::assertStringContainsString('VAROVÁNÍ §23 odst. 8 ZDP', $warnings);
        self::assertStringContainsString('otevřené pohledávky', $warnings);
        self::assertStringContainsString('zásoby', $warnings);
    }

    public function testHistoricalYearUsesAccountingModeEffectiveForThatYear(): void
    {
        $this->db->pdo()->prepare('DELETE FROM supplier_accounting_modes WHERE supplier_id = ?')->execute([$this->supplierId]);
        $this->db->pdo()->prepare(
            'INSERT INTO supplier_accounting_modes (supplier_id, effective_from, accounting_mode) VALUES
             (?, ?, ?), (?, ?, ?)'
        )->execute([
            $this->supplierId, '1900-01-01', 'tax_evidence',
            $this->supplierId, (self::YEAR + 1) . '-01-01', 'double_entry',
        ]);
        $this->db->pdo()->prepare("UPDATE supplier SET accounting_mode = 'double_entry' WHERE id = ?")
            ->execute([$this->supplierId]);
        $this->cashDoc('in', 'sale', 3000.0);

        $result = $this->provider->gather($this->supplierId, self::YEAR);
        self::assertSame('tax_evidence', $result['accounting_mode']);
        self::assertSame(3000.0, $result['s7_income']);
    }
}
