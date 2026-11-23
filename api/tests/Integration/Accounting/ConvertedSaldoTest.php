<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Repository\SaldoRepository;
use MyInvoice\Service\Accounting\AdvanceDeductionService;
use MyInvoice\Service\Accounting\JournalSettlementException;
use MyInvoice\Service\Accounting\JournalSettlementService;
use MyInvoice\Tests\Integration\Accounting\Bank\BankPostingTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Saldokonto nad deníkem převzatým z programu, který zálohy čerpá červeným stornem na straně
 * vzniku zálohy (Helios), platí přijaté zálohy z devizového účtu a vede saldo po řádcích deníku.
 */
#[Group('integration')]
final class ConvertedSaldoTest extends BankPostingTestCase
{
    public static function currencies(): array
    {
        return ['koruny' => ['CZK'], 'eura' => ['EUR']];
    }

    /** Čerpání zálohy červeným stornem na 324 (strana Dal) je strana čerpání i u faktury v cizí měně. */
    #[DataProvider('currencies')]
    public function testRedStornoDeductionIsNotReportedAsOverdeducted(string $currency): void
    {
        $partner = $this->client('Syntetický odběratel zálohy');
        $advance = $this->saleInvoice('ZAL-RS-' . $currency, $partner, 1000.0, 'proforma', 'paid');
        $final = $this->saleInvoice('FIN-RS-' . $currency, $partner, 2000.0);
        if ($currency !== 'CZK') {
            $this->db->pdo()->prepare('UPDATE invoices SET currency_id = ?, exchange_rate = 25 WHERE id = ?')
                ->execute([$this->currencyRow($this->supplierId, $currency), $final]);
        }
        $map = $this->accounts->codeToIdMap($this->supplierId);
        $this->journal->insert([
            'supplier_id' => $this->supplierId, 'period_id' => $this->periodId, 'entry_date' => self::YEAR . '-06-10',
            'document_no' => 'FIN-RS', 'description' => 'Konečná faktura', 'source_type' => 'invoice', 'source_id' => $final,
            'posted_at' => date('Y-m-d H:i:s'), 'posted_by' => $this->userId,
        ], [
            ['account_id' => $map['311']['id'], 'side' => 'debit', 'amount' => 1000.0],
            ['account_id' => $map['602']['id'], 'side' => 'credit', 'amount' => 2000.0],
            ['account_id' => $map['324']['id'], 'side' => 'credit', 'amount' => 1000.0, 'is_red_storno' => true],
        ]);
        $this->db->pdo()->prepare("INSERT INTO invoice_advance_deductions (supplier_id, final_invoice_id, advance_invoice_id, amount, source) VALUES (?, ?, ?, 1000, 'import')")
            ->execute([$this->supplierId, $final, $advance]);

        $issues = $this->container->get(AdvanceDeductionService::class)->deductionIssues($this->supplierId, '324');
        self::assertSame([], array_values(array_filter($issues, static fn (array $i): bool => $i['doc_id'] === $final)));

        // Čerpání nad stranu čerpání zaúčtování se hlásí dál.
        $this->db->pdo()->prepare('UPDATE invoice_advance_deductions SET amount = 1500 WHERE final_invoice_id = ?')->execute([$final]);
        $issues = $this->container->get(AdvanceDeductionService::class)->deductionIssues($this->supplierId, '324');
        $own = array_values(array_filter($issues, static fn (array $i): bool => $i['doc_id'] === $final));
        self::assertCount(1, $own);
        self::assertEqualsWithDelta(500.0, $own[0]['excess'], 0.001);
    }

    public static function advanceStatuses(): array
    {
        return ['neuhrazená' => ['received', 7500.0], 'označená jako uhrazená' => ['paid', null]];
    }

    /** Korunová záloha zaplacená z eurového účtu: úhrada kurzem, za který banka pohyb zaúčtovala. */
    #[DataProvider('advanceStatuses')]
    public function testForeignPaymentOfCzkDocumentUsesBookedRate(string $status, ?float $expectedOpen): void
    {
        $vendor = $this->client('Syntetický dodavatel zálohy');
        $doc = $this->purchaseInvoice('ZF-EUR-' . $status, $vendor, 10000.0, 'advance');
        $this->postPredpis('purchase_invoice', $doc, '314', '321', 10000.0);
        $this->db->pdo()->prepare('UPDATE purchase_invoices SET status = ?, paid_at = ? WHERE id = ?')
            ->execute([$status, $status === 'paid' ? self::YEAR . '-06-20' : null, $doc]);
        $tx = $this->transaction($this->statement(), -100.0, ['currency' => 'EUR']);
        $map = $this->accounts->codeToIdMap($this->supplierId);
        $this->journal->insert([
            'supplier_id' => $this->supplierId, 'period_id' => $this->periodId, 'entry_date' => self::YEAR . '-06-15',
            'document_no' => 'BANKA-EUR', 'description' => 'Záloha z devizového účtu', 'source_type' => 'bank', 'source_id' => $tx,
            'posted_at' => date('Y-m-d H:i:s'), 'posted_by' => $this->userId,
        ], [
            ['account_id' => $map['321']['id'], 'side' => 'debit', 'amount' => 2500.0],
            ['account_id' => $map['221']['id'], 'side' => 'credit', 'amount' => 2500.0],
        ]);
        $this->paymentMatch($tx, $doc, 100.0);

        $saldo = $this->container->get(SaldoRepository::class);
        $account = $saldo->resolveAccount($this->supplierId, '321');
        self::assertNotNull($account);
        $items = $saldo->documentOpenItems($this->supplierId, 'purchase_invoice', $doc, (int) $account['id'], self::YEAR . '-12-31');
        if ($expectedOpen === null) {
            self::assertSame([], $items);
            return;
        }
        self::assertCount(1, $items);
        self::assertEqualsWithDelta($expectedOpen, abs((float) $items[0]['booked_signed'] * (1 - (float) $items[0]['paid_ratio'])), 0.01);
    }

    /** Převod smí založit ostatní položku nad řádkem zaúčtování dokladu, aplikace ne. */
    public function testConversionItemOnDocumentPostingLine(): void
    {
        $vendor = $this->client('Syntetický dodavatel převodu');
        $doc = $this->purchaseInvoice('PRV-1', $vendor, 3000.0);
        $entry = $this->postPredpis('purchase_invoice', $doc, '518', '321', 3000.0);
        $lineId = (int) $this->db->pdo()->query("SELECT l.id FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id = l.account_id
            WHERE l.entry_id = {$entry} AND a.account_code LIKE '321%'")->fetchColumn();
        $service = $this->container->get(JournalSettlementService::class);
        try {
            $service->createOtherItem($this->supplierId, ['line_id' => $lineId, 'amount' => 1000.0, 'partner_id' => $vendor], $this->userId);
            self::fail('Aplikace nesmí založit položku nad zaúčtováním dokladu.');
        } catch (JournalSettlementException $e) {
            self::assertSame('line_source', $e->errorCode);
        }
        $out = $service->createOtherItem($this->supplierId, ['line_id' => $lineId, 'amount' => 1000.0, 'partner_id' => $vendor], $this->userId, true, true);
        self::assertSame('payable', $out['item']['side']);
        self::assertEqualsWithDelta(1000.0, (float) $out['item']['amount'], 0.001);
    }
}
