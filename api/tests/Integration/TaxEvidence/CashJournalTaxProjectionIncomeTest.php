<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\TaxEvidence;

use PHPUnit\Framework\Attributes\Group;

/**
 * #52 — projekce běžícího roku v daňové evidenci (dlaždice „Daně {rok} · odhad roku",
 * teploměr limitů v Optimalizátoru) smí stát JEN na zdanitelném příjmu, a u plátce DPH
 * bez DPH — tedy přesně to, co vrací TaxProfileRepository::monthlyIncome() ve fakturační
 * větvi. Osvobozený příjem (§4 ZDP, invoices.income_tax_exempt = 1) do ní nepatří:
 *  - do základu daně ani do vyměřovacích základů pojistného nevstupuje,
 *  - do rozhodných příjmů pro pásmo paušálního režimu taky ne (§ 2a odst. 5 ZDP definuje
 *    rozhodné příjmy jako příjmy ze samostatné činnosti, § 7a odst. 1 písm. b) bod 1 uvádí
 *    příjmy od daně osvobozené jako kategorii VEDLE rozhodných příjmů).
 *
 * Dřív se sčítaly oba kbelíky (income_taxable + income_exempt) a osvobozená noha byla
 * u plátce DPH navíc brutto → projekce i teploměr 2 M nafouknuté.
 */
#[Group('integration')]
final class CashJournalTaxProjectionIncomeTest extends CashJournalTestCase
{
    /** Virtuální noha C (úhrada bez importu výpisu) — CashJournalService::incomeAlloc(). */
    public function testProjectionIncomeExcludesExemptInvoiceOnVirtualLeg(): void
    {
        $this->setVatPayer($this->supplierId, true);

        $taxable = $this->saleInvoice($this->supplierId, [
            'without' => 100000.0, 'with' => 121000.0,
            'status' => 'paid', 'paid_at' => self::YEAR . '-06-15',
        ]);
        $this->invoicePayment($this->supplierId, $taxable, 121000.0, 'manual', null, self::YEAR . '-06-15');

        $exempt = $this->saleInvoice($this->supplierId, [
            'without' => 200000.0, 'with' => 242000.0, 'income_tax_exempt' => 1,
            'status' => 'paid', 'paid_at' => self::YEAR . '-07-15',
        ]);
        $this->invoicePayment($this->supplierId, $exempt, 242000.0, 'manual', null, self::YEAR . '-07-15');

        $monthly = $this->service->monthlyTaxableIncome($this->supplierId, self::YEAR);

        self::assertEqualsWithDelta(100000.0, $monthly[6], 0.01, 'Červen = zdanitelná faktura bez DPH.');
        self::assertEqualsWithDelta(
            0.0,
            $monthly[7],
            0.01,
            'Osvobozená faktura NESMÍ vstoupit do projekce (#52) — ani brutto, ani netto.',
        );
        self::assertEqualsWithDelta(
            100000.0,
            array_sum($monthly),
            0.01,
            'YTD příjem pro projekci = 100 000, ne 342 000 (100 000 + 242 000 brutto).',
        );

        $totals = $this->service->build(
            $this->supplierId,
            self::YEAR . '-01-01',
            self::YEAR . '-12-31',
            ['year' => self::YEAR],
        )['totals'];

        self::assertEqualsWithDelta(100000.0, $totals['prijem_danovy'], 0.01);
        self::assertEqualsWithDelta(
            200000.0,
            $totals['prijem_osvobozeny'],
            0.01,
            'Osvobozená noha se u plátce DPH dělí na základ a DPH stejně jako zdanitelná (#52).',
        );
        self::assertEqualsWithDelta(
            63000.0,
            $totals['prijem_nedanovy'],
            0.01,
            'DPH z obou faktur (21 000 + 42 000) patří do nedaňového příjmu.',
        );
    }

    /** Bankovní noha B — agregace czk_base/czk_exempt v CashJournalRepository. */
    public function testProjectionIncomeExcludesExemptInvoiceOnBankLeg(): void
    {
        $this->setVatPayer($this->supplierId, true);
        $statement = $this->statement($this->supplierId, $this->accountA);

        $taxable = $this->saleInvoice($this->supplierId, [
            'without' => 100000.0, 'with' => 121000.0,
            'status' => 'paid', 'paid_at' => self::YEAR . '-06-15',
        ]);
        $txTaxable = $this->bankTx($statement, 121000.0, ['posted_at' => self::YEAR . '-06-15']);
        $this->invoicePayment($this->supplierId, $taxable, 121000.0, 'bank', $txTaxable, self::YEAR . '-06-15');

        $exempt = $this->saleInvoice($this->supplierId, [
            'without' => 200000.0, 'with' => 242000.0, 'income_tax_exempt' => 1,
            'status' => 'paid', 'paid_at' => self::YEAR . '-07-15',
        ]);
        $txExempt = $this->bankTx($statement, 242000.0, ['posted_at' => self::YEAR . '-07-15']);
        $this->invoicePayment($this->supplierId, $exempt, 242000.0, 'bank', $txExempt, self::YEAR . '-07-15');

        $monthly = $this->service->monthlyTaxableIncome($this->supplierId, self::YEAR);

        self::assertEqualsWithDelta(100000.0, $monthly[6], 0.01, 'Červen = zdanitelná faktura bez DPH.');
        self::assertEqualsWithDelta(0.0, $monthly[7], 0.01, 'Osvobozená faktura mimo projekci (#52).');
        self::assertEqualsWithDelta(100000.0, array_sum($monthly), 0.01);

        $totals = $this->service->build(
            $this->supplierId,
            self::YEAR . '-01-01',
            self::YEAR . '-12-31',
            ['year' => self::YEAR],
        )['totals'];

        self::assertEqualsWithDelta(100000.0, $totals['prijem_danovy'], 0.01);
        self::assertEqualsWithDelta(
            200000.0,
            $totals['prijem_osvobozeny'],
            0.01,
            'Bankovní czk_exempt musí být bez DPH, stejně jako czk_base vedle ní (#52).',
        );
        self::assertEqualsWithDelta(63000.0, $totals['prijem_nedanovy'], 0.01);
    }

    /** Neplátce DPH: osvobozený příjem zůstává v plné výši v osvobozeném kbelíku, mimo projekci. */
    public function testNonVatPayerKeepsGrossExemptOutsideProjection(): void
    {
        $this->setVatPayer($this->supplierId, false);

        $taxable = $this->saleInvoice($this->supplierId, [
            'without' => 50000.0, 'with' => 50000.0,
            'status' => 'paid', 'paid_at' => self::YEAR . '-03-10',
        ]);
        $this->invoicePayment($this->supplierId, $taxable, 50000.0, 'mark_paid', null, self::YEAR . '-03-10');

        $exempt = $this->saleInvoice($this->supplierId, [
            'without' => 30000.0, 'with' => 30000.0, 'income_tax_exempt' => 1,
            'status' => 'paid', 'paid_at' => self::YEAR . '-04-10',
        ]);
        $this->invoicePayment($this->supplierId, $exempt, 30000.0, 'mark_paid', null, self::YEAR . '-04-10');

        $monthly = $this->service->monthlyTaxableIncome($this->supplierId, self::YEAR);

        self::assertEqualsWithDelta(50000.0, array_sum($monthly), 0.01);
        self::assertEqualsWithDelta(0.0, $monthly[4], 0.01);

        $totals = $this->service->build(
            $this->supplierId,
            self::YEAR . '-01-01',
            self::YEAR . '-12-31',
            ['year' => self::YEAR],
        )['totals'];

        self::assertEqualsWithDelta(30000.0, $totals['prijem_osvobozeny'], 0.01, 'U neplátce se nic nedělí.');
        self::assertEqualsWithDelta(0.0, $totals['prijem_nedanovy'], 0.01);
    }
}
