<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Service\Accounting\Expense\PurchaseDiscountReclass;
use MyInvoice\Service\Accounting\DocumentPostingGroup;
use MyInvoice\Tests\Integration\Accounting\Bank\BankPostingTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class PurchaseDiscountReclassPeriodTest extends BankPostingTestCase
{
    public static function frozenStates(): iterable
    {
        yield 'closed' => ['closed', false];
        yield 'reviewed' => ['reviewed', false];
        yield 'approved' => ['approved', false];
        yield 'posted closing' => ['closing', true];
    }

    #[DataProvider('frozenStates')]
    public function testAppliedReclassCannotFillForeignTraceInFrozenBooks(string $status, bool $closing): void
    {
        [$purchaseId, $entryId] = $this->repostedPurchase();
        $before = $this->trace($entryId);
        if ($closing) {
            $accounts = $this->accounts->codeToIdMap($this->supplierId);
            $this->journal->insert([
                'supplier_id' => $this->supplierId, 'period_id' => $this->periodId,
                'entry_date' => self::YEAR . '-12-31', 'document_no' => 'SYNTH-CLOSE',
                'source_type' => 'closing', 'posted_at' => date('Y-m-d H:i:s'),
            ], [
                ['account_id' => $accounts['701']['id'], 'side' => 'debit', 'amount' => 1],
                ['account_id' => $accounts['702']['id'], 'side' => 'credit', 'amount' => 1],
            ]);
        }
        $this->db->pdo()->prepare('UPDATE accounting_periods SET status = ? WHERE id = ?')
            ->execute([$status, $this->periodId]);
        $service = $this->container->get(PurchaseDiscountReclass::class);
        $preview = $service->run($this->supplierId, $purchaseId, false, $this->userId);
        self::assertSame(1, $preview['trace_fixes']);
        $result = $service->run($this->supplierId, $purchaseId, true, $this->userId);
        self::assertFalse($result['applied']);
        self::assertNotEmpty($result['message']);
        self::assertSame($before, $this->trace($entryId));
    }

    public function testOpenBooksStillFillForeignTrace(): void
    {
        [$purchaseId, $entryId] = $this->repostedPurchase();
        $result = $this->container->get(PurchaseDiscountReclass::class)
            ->run($this->supplierId, $purchaseId, true, $this->userId);
        self::assertTrue($result['applied']);
        self::assertSame(1, $result['trace_fixes']);
        $line = $this->trace($entryId);
        self::assertSame('EUR', $line['currency_code']);
        self::assertSame('36.00', $line['amount_foreign']);
        self::assertSame('900.00', $line['amount']);
    }

    public static function ownedPostingVariants(): iterable
    {
        yield 'stornovaný přímý člen' => [false, true];
        yield 'jen vlastněné zápisy bez přímého zdroje' => [true, true];
        yield 'nepřevzatá skupina vyžaduje vědomé přeúčtování' => [false, false];
    }

    #[DataProvider('ownedPostingVariants')]
    public function testReclassReportsTakenOverOwnedPosting(bool $manualOnly, bool $takenOver): void
    {
        [$purchaseId, $base] = $this->repostedPurchase();
        $owned = $this->posting->postDocument($this->supplierId, 'manual', null, [
            ['account_code' => '343', 'side' => 'debit', 'amount' => 189],
            ['account_code' => '321', 'side' => 'credit', 'amount' => 189],
        ], ['entry_date' => self::YEAR . '-06-10']);
        (new DocumentPostingGroup($this->db))->attach($this->supplierId, 'purchase_invoice', $purchaseId, [$base, $owned]);
        $this->posting->reverse($this->supplierId, $base);
        if ($takenOver) {
            $this->db->pdo()->prepare("INSERT INTO money_s3_import_map (supplier_id, kind, money_key, target_id)
                VALUES (?, 'journal_entry', 'synth|DISCOUNT-OWNED', ?)")->execute([$this->supplierId, $owned]);
        }
        $expected = $takenOver ? 'taken_over' : 'posting_group_requires_repost';
        if ($manualOnly) {
            $this->db->pdo()->prepare("UPDATE journal_entries SET source_id = NULL
                WHERE supplier_id = ? AND source_type = 'purchase_invoice' AND source_id = ?")
                ->execute([$this->supplierId, $purchaseId]);
        }
        $before = $this->trace($owned);
        $service = $this->container->get(PurchaseDiscountReclass::class);
        $candidates = array_column($service->candidates($this->supplierId), null, 'purchase_invoice_id');
        self::assertArrayHasKey($purchaseId, $candidates, 'Živý vlastněný předpis patří do náhledu i bez přímého zdroje.');
        self::assertSame($expected, $candidates[$purchaseId]['state']);
        $result = $service->run($this->supplierId, $purchaseId, true, $this->userId);
        self::assertSame($expected, $result['state']);
        self::assertSame($owned, $result['entry_id']);
        self::assertFalse($result['applied']);
        self::assertSame($before, $this->trace($owned));
    }

    private function repostedPurchase(): array
    {
        $purchaseId = $this->purchaseInvoice('SYNTH-FX-DISCOUNT', $this->client('Syntetický dodavatel'), 36);
        $pdo = $this->db->pdo();
        $currency = (int) $pdo->query("SELECT id FROM currencies WHERE code = 'EUR' ORDER BY id LIMIT 1")->fetchColumn();
        $vat = (int) $pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn();
        self::assertGreaterThan(0, $currency);
        self::assertGreaterThan(0, $vat);
        $pdo->prepare('UPDATE purchase_invoices SET currency_id = ?, exchange_rate = 25 WHERE id = ?')
            ->execute([$currency, $purchaseId]);
        $insert = $pdo->prepare("INSERT INTO purchase_invoice_items
            (purchase_invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
             vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index, expense_kind)
            VALUES (?, ?, 1, 'ks', ?, ?, 0, ?, 0, ?, ?, 'service')");
        foreach ([['Syntetická služba', 40], ['Sleva na službu', -4]] as $index => [$label, $amount]) {
            $insert->execute([$purchaseId, $label, $amount, $vat, $amount, $amount, $index]);
        }
        $built = $this->posting->buildFromPurchaseInvoice($this->supplierId, $purchaseId);
        $meta = ['entry_date' => self::YEAR . '-06-10', 'posted_by' => $this->userId, 'user_id' => $this->userId];
        $original = $this->posting->postDocument($this->supplierId, 'purchase_invoice', $purchaseId, $built, $meta);
        $this->posting->reverse($this->supplierId, $original, $meta);
        $entryId = $this->posting->postDocument($this->supplierId, 'purchase_invoice', $purchaseId, $built, $meta);
        $pdo->prepare('UPDATE journal_entry_lines SET currency_code = NULL, fx_rate = NULL, amount_foreign = NULL WHERE entry_id = ?')
            ->execute([$entryId]);
        return [$purchaseId, $entryId];
    }

    private function trace(int $entryId): array
    {
        $stmt = $this->db->pdo()->prepare("SELECT l.currency_code, l.fx_rate, l.amount_foreign, l.amount
            FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id = l.account_id
            WHERE l.entry_id = ? AND a.account_code LIKE '321%'");
        $stmt->execute([$entryId]);
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }
}
