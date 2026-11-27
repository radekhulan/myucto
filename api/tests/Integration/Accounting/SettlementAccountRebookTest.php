<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Repository\AccountingBulkItemRepository;
use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Service\Accounting\Bulk\BulkActionService;
use MyInvoice\Service\Accounting\Bulk\BulkOperations;
use MyInvoice\Service\Accounting\Bulk\BulkSelection;
use MyInvoice\Service\Accounting\DocumentJournalSync;
use MyInvoice\Service\Accounting\JournalRebookService;
use MyInvoice\Service\Accounting\SettlementAccountRebook;
use MyInvoice\Tests\Integration\Accounting\Bank\BankPostingTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Saldokontní účet dokladu (závazek přijaté, pohledávka vydané faktury) při přeúčtování
 * a změně předkontace ({@see SettlementAccountRebook}, {@see \MyInvoice\Service\Accounting\PartnerSettlementAccount::keptForPaidDocument}):
 *
 *  - Hromadné akce → Přeúčtovat účet 321 → 321.200 (311 → 311.200) přesune neuhrazený
 *    doklad, uhrazený vynechá s důvodem `paid_document`; nový účet vydrží další úpravu
 *    dokladu a úhrada ho odúčtuje.
 *  - Hromadná Změnit předkontaci nepřesune uhrazený doklad na jiný závazek / pohledávku.
 *
 * Pod 321 i 311 jsou dvě analytiky, aby se syntetika nepřesměrovala na jedinou analytiku.
 */
#[Group('integration')]
final class SettlementAccountRebookTest extends BankPostingTestCase
{
    private const PAYABLE = '321.200';
    private const RECEIVABLE = '311.200';

    private ImportJobRepository $jobs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->jobs = $this->container->get(ImportJobRepository::class);
        $this->analytic('321', '321.100', 'Závazky ostatní');
        $this->analytic('321', self::PAYABLE, 'Závazky tuzemské faktury');
        $this->analytic('311', '311.100', 'Pohledávky ostatní');
        $this->analytic('311', self::RECEIVABLE, 'Pohledávky pachtovné');
    }

    /**
     * BEZ OPRAVY PADÁ: přeúčtování 321 → 321.200 dřív oba doklady vynechalo s důvodem
     * `not_item_account`. Teď neuhrazený přejde na 321.200 (ostatní řádky beze změny),
     * uhrazený zůstane s důvodem `paid_document` a úhrada neuhrazeného odúčtuje 321.200.
     */
    public function testBulkRebookMovesUnpaidPayableSkipsPaidAndPaymentSettlesNewAccount(): void
    {
        $vendor = $this->client('Dodavatel přeúčtování závazku');
        $open = $this->postedPurchase('SYN-SAR-OPEN', $vendor);
        $paid = $this->postedPurchase('SYN-SAR-PAID', $vendor);
        $tx = $this->transaction($this->statement(), -50.0, ['match_status' => 'manual']);
        $this->paymentMatch($tx, $paid, 50.0);
        self::assertSame('posted', $this->service->handleTransaction($tx, $this->userId)['action']);
        $openBefore = $this->linesByAccountCode($this->documentEntry('purchase_invoice', $open));

        $dryId = $this->dryRun('rebook_account', 'purchase_invoice', [$open, $paid],
            ['from_account' => '321', 'to_account' => self::PAYABLE, 'side' => 'both', 'mode' => JournalRebookService::MODE_REWRITE]);
        $dry = $this->bySource($dryId);
        self::assertSame('would_change', $dry[$open]['status'], (string) json_encode($dry[$open], JSON_UNESCAPED_UNICODE));
        self::assertSame(['skipped', SettlementAccountRebook::PAID_DOCUMENT], [$dry[$paid]['status'], $dry[$paid]['reason_code']]);
        self::assertStringContainsString('uhrazený', (string) $dry[$paid]['message']);

        $applied = $this->bySource($this->applyRun($dryId));
        self::assertSame('applied', $applied[$open]['status'], (string) json_encode($applied[$open], JSON_UNESCAPED_UNICODE));
        self::assertSame(1, (int) $applied[$open]['items_synced'], 'Nový závazek je uložený v protiúčtu řádku.');

        $openEntry = $this->documentEntry('purchase_invoice', $open);
        $lines = $this->linesByAccountCode($openEntry);
        self::assertSame(['debit' => 0.0, 'credit' => 121.0], $lines[self::PAYABLE] ?? null);
        self::assertArrayNotHasKey('321', $lines);
        unset($lines[self::PAYABLE], $openBefore['321']);
        self::assertSame($openBefore, $lines, 'Ostatní řádky zápisu beze změny.');
        self::assertSame(self::PAYABLE, $this->db->pdo()->query("SELECT counter_account_code FROM purchase_invoice_items WHERE purchase_invoice_id = {$open}")->fetchColumn());
        self::assertSame(121.0, $this->linesByAccountCode($this->documentEntry('purchase_invoice', $paid))['321']['credit'] ?? null, 'Uhrazený zůstává na 321.');

        // Další zaúčtování dokladu (úprava) nový závazek nevrátí.
        $this->container->get(DocumentJournalSync::class)->repostForceEdit($this->supplierId, 'purchase_invoice', $open, ['user_id' => $this->userId, 'posted_by' => $this->userId]);
        $openEntry = $this->documentEntry('purchase_invoice', $open);
        self::assertSame(121.0, $this->linesByAccountCode($openEntry)[self::PAYABLE]['credit'] ?? null);

        $pay = $this->transaction($this->statement(), -121.0, ['match_status' => 'manual']);
        $this->paymentMatch($pay, $open, 121.0);
        self::assertSame('posted', $this->service->handleTransaction($pay, $this->userId)['action']);
        $bankEntry = $this->documentEntry('bank', $pay);
        self::assertSame(['debit' => 121.0, 'credit' => 0.0], $this->linesByAccountCode($bankEntry)[self::PAYABLE] ?? null, 'Úhrada odúčtuje nový závazek.');
        self::assertSame(0.0, $this->balance(self::PAYABLE, [$openEntry, $bankEntry]));
    }

    /** BEZ OPRAVY PADÁ: totéž pro pohledávku vydané faktury 311 → 311.200. */
    public function testBulkRebookMovesUnpaidReceivableSkipsPaidAndPaymentSettlesNewAccount(): void
    {
        $customer = $this->client('Odběratel přeúčtování pohledávky');
        $open = $this->postedIssued('SYNSAR01', $customer);
        $paid = $this->postedIssued('SYNSAR02', $customer);
        $this->db->pdo()->prepare('UPDATE invoices SET paid_total = 500 WHERE id = ?')->execute([$paid]);

        $dryId = $this->dryRun('rebook_account', 'invoice', [$open, $paid],
            ['from_account' => '311', 'to_account' => self::RECEIVABLE, 'side' => 'both', 'mode' => JournalRebookService::MODE_REWRITE]);
        $dry = $this->bySource($dryId);
        self::assertSame('would_change', $dry[$open]['status'], (string) json_encode($dry[$open], JSON_UNESCAPED_UNICODE));
        self::assertSame(['skipped', SettlementAccountRebook::PAID_DOCUMENT], [$dry[$paid]['status'], $dry[$paid]['reason_code']]);

        $this->applyRun($dryId);
        $openEntry = $this->documentEntry('invoice', $open);
        self::assertSame(['debit' => 1000.0, 'credit' => 0.0], $this->linesByAccountCode($openEntry)[self::RECEIVABLE] ?? null);
        self::assertSame(1000.0, $this->linesByAccountCode($this->documentEntry('invoice', $paid))['311']['debit'] ?? null);

        $this->container->get(DocumentJournalSync::class)->repostForceEdit($this->supplierId, 'invoice', $open, ['user_id' => $this->userId, 'posted_by' => $this->userId]);
        $openEntry = $this->documentEntry('invoice', $open);
        self::assertSame(1000.0, $this->linesByAccountCode($openEntry)[self::RECEIVABLE]['debit'] ?? null);

        $tx = $this->transaction($this->statement(), 1000.0, ['match_status' => 'auto_exact', 'matched_invoice_id' => $open]);
        $this->invoicePayment($open, $tx, 1000.0);
        self::assertSame('posted', $this->service->handleTransaction($tx, $this->userId)['action']);
        $bankEntry = $this->documentEntry('bank', $tx);
        self::assertSame(['debit' => 0.0, 'credit' => 1000.0], $this->linesByAccountCode($bankEntry)[self::RECEIVABLE] ?? null, 'Úhrada odúčtuje novou pohledávku.');
        self::assertSame(0.0, $this->balance(self::RECEIVABLE, [$openEntry, $bankEntry]));
    }

    /** Cílový účet se ověřuje jako účet na kartě partnera: syntetika s analytikami ani DPH ne. */
    public function testBulkRebookRejectsInvalidTargetAccount(): void
    {
        $vendor = $this->client('Dodavatel neplatný cíl');
        $open = $this->postedPurchase('SYN-SAR-BAD', $vendor);
        $this->analytic('325', '325.100', 'Ostatní závazky A');
        $this->analytic('325', '325.200', 'Ostatní závazky B');
        $dry = $this->bySource($this->dryRun('rebook_account', 'purchase_invoice', [$open],
            ['from_account' => '321', 'to_account' => '325', 'side' => 'both', 'mode' => JournalRebookService::MODE_REWRITE]));
        self::assertSame(['skipped', SettlementAccountRebook::INVALID_ACCOUNT], [$dry[$open]['status'], $dry[$open]['reason_code']]);
        self::assertSame(121.0, $this->linesByAccountCode($this->documentEntry('purchase_invoice', $open))['321']['credit'] ?? null);
    }

    /**
     * BEZ OPRAVY PADÁ: hromadná Změnit předkontaci s jiným závazkem přesune neuhrazený
     * doklad, uhrazený (částečně) zůstává na závazku svého zápisu, na kterém leží úhrada.
     */
    public function testPostingRuleChangeKeepsPayableOfPaidPurchase(): void
    {
        $this->postingRule('syn.purchase_325', '518', '325');
        $vendor = $this->client('Dodavatel změna předkontace');
        $open = $this->postedPurchase('SYN-SAR-RULE-OPEN', $vendor);
        $paid = $this->postedPurchase('SYN-SAR-RULE-PAID', $vendor);
        $tx = $this->transaction($this->statement(), -50.0, ['match_status' => 'manual']);
        $this->paymentMatch($tx, $paid, 50.0);
        self::assertSame('posted', $this->service->handleTransaction($tx, $this->userId)['action']);

        $dryId = $this->dryRun('set_posting_rule', 'purchase_invoice', [$open, $paid],
            ['rule_key' => 'syn.purchase_325', 'mode' => JournalRebookService::MODE_REWRITE]);
        $applied = $this->bySource($this->applyRun($dryId));
        self::assertSame('applied', $applied[$paid]['status'], (string) json_encode($applied[$paid], JSON_UNESCAPED_UNICODE));

        self::assertSame(121.0, $this->linesByAccountCode($this->documentEntry('purchase_invoice', $open))['325']['credit'] ?? null,
            'Neuhrazený doklad přejde na závazek nové předkontace.');
        $paidLines = $this->linesByAccountCode($this->documentEntry('purchase_invoice', $paid));
        self::assertSame(121.0, $paidLines['321']['credit'] ?? null, 'Uhrazený doklad zůstává na 321, kde leží úhrada.');
        self::assertArrayNotHasKey('325', $paidLines);
    }

    /** BEZ OPRAVY PADÁ: totéž pro pohledávku uhrazené vydané faktury. */
    public function testPostingRuleChangeKeepsReceivableOfPaidInvoice(): void
    {
        $this->postingRule('syn.sale_311_100', '311.100', '602');
        $customer = $this->client('Odběratel změna předkontace');
        $open = $this->postedIssued('SYNSAR03', $customer);
        $paid = $this->postedIssued('SYNSAR04', $customer);
        $this->db->pdo()->prepare('UPDATE invoices SET paid_total = 400 WHERE id = ?')->execute([$paid]);

        $dryId = $this->dryRun('set_posting_rule', 'invoice', [$open, $paid],
            ['rule_key' => 'syn.sale_311_100', 'mode' => JournalRebookService::MODE_REWRITE]);
        $this->applyRun($dryId);

        self::assertSame(1000.0, $this->linesByAccountCode($this->documentEntry('invoice', $open))['311.100']['debit'] ?? null,
            'Neuhrazená faktura přejde na pohledávku nové předkontace.');
        $paidLines = $this->linesByAccountCode($this->documentEntry('invoice', $paid));
        self::assertSame(1000.0, $paidLines['311']['debit'] ?? null, 'Uhrazená faktura zůstává na 311, kde leží úhrada.');
        self::assertArrayNotHasKey('311.100', $paidLines);
    }

    // ── fixtury ──────────────────────────────────────────────────────────────

    private function analytic(string $parentCode, string $code, string $name): void
    {
        if ($this->accounts->findByCode($this->supplierId, $code) !== null) {
            return;
        }
        $parent = $this->accounts->findByCode($this->supplierId, $parentCode);
        self::assertNotNull($parent, 'Osnova firmy musí mít účet ' . $parentCode . '.');
        $this->accounts->insert($this->supplierId, [
            'account_code' => $code, 'name' => $name, 'account_type' => $parent['account_type'], 'normal_side' => $parent['normal_side'],
            'parent_id' => (int) $parent['id'], 'is_synthetic' => false, 'is_active' => true,
        ]);
    }

    private function postingRule(string $key, string $debit, string $credit): void
    {
        $this->db->pdo()->prepare(
            "INSERT INTO posting_rules (supplier_id, rule_key, description, debit_account_code, credit_account_code, priority, is_active)
             VALUES (?, ?, 'Syntetická předkontace', ?, ?, 0, 1)"
        )->execute([$this->supplierId, $key, $debit, $credit]);
    }

    private function postedPurchase(string $number, int $vendorId): int
    {
        $id = $this->purchaseInvoice($number, $vendorId, 121.0);
        $this->db->pdo()->prepare(
            "INSERT INTO purchase_invoice_items
                (purchase_invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, 'Syntetická položka', 1, 'ks', 121, (SELECT id FROM vat_rates ORDER BY id LIMIT 1), 0, 121, 0, 121, 0)"
        )->execute([$id]);
        $this->posting->postDocument($this->supplierId, 'purchase_invoice', $id, $this->posting->buildFromPurchaseInvoice($this->supplierId, $id), [
            'entry_date' => self::YEAR . '-06-10', 'posted' => true, 'posted_by' => $this->userId,
        ]);
        return $id;
    }

    private function postedIssued(string $varsymbol, int $clientId): int
    {
        $id = $this->saleInvoice($varsymbol, $clientId, 1000.0);
        $this->db->pdo()->prepare(
            "INSERT INTO invoice_items
                (invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, 'Služba', 1, 'ks', 1000, (SELECT MIN(id) FROM vat_rates), 0, 1000, 0, 1000, 0)"
        )->execute([$id]);
        $this->posting->postDocument($this->supplierId, 'invoice', $id, $this->posting->buildFromInvoice($this->supplierId, $id), [
            'entry_date' => self::YEAR . '-06-10', 'posted' => true, 'posted_by' => $this->userId,
        ]);
        return $id;
    }

    /** @param array<string,mixed> $params */
    private function runJob(array $params): int
    {
        $jobId = $this->jobs->create($this->supplierId, BulkActionService::SOURCE, $params, $this->userId);
        $this->container->get(BulkActionService::class)->run($jobId);
        $job = $this->jobs->find($jobId, $this->supplierId);
        self::assertContains($job['status'], ['completed', 'completed_with_warnings'], (string) $job['last_error']);
        return $jobId;
    }

    /**
     * @param list<int> $ids
     * @param array<string,mixed> $params
     */
    private function dryRun(string $operation, string $target, array $ids, array $params): int
    {
        $ids = $this->container->get(BulkSelection::class)->explicitIds($this->supplierId, $target, $ids);
        $normalized = $this->container->get(BulkOperations::class)->get($operation, $target)
            ->normalizeParams($this->supplierId, $target, ['ids' => $ids] + $params);
        return $this->runJob(['kind' => 'dry_run', 'operation' => $operation, 'target' => $target,
            'selection_count' => count($ids), 'ids' => $ids] + $normalized);
    }

    private function applyRun(int $dryJobId): int
    {
        $dry = $this->jobs->find($dryJobId, $this->supplierId);
        return $this->runJob(array_merge((array) $dry['params'], [
            'kind' => 'apply', 'dry_run_job_id' => $dryJobId, 'excluded_item_ids' => [],
        ]));
    }

    /** @return array<int,array<string,mixed>> source_id => položka */
    private function bySource(int $jobId): array
    {
        $out = [];
        foreach ($this->container->get(AccountingBulkItemRepository::class)->page($this->supplierId, $jobId, null, 1, 200)['items'] as $item) {
            $out[$item['source_id']] = $item;
        }
        return $out;
    }

    private function documentEntry(string $sourceType, int $sourceId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM journal_entries
              WHERE supplier_id = ? AND source_type = ? AND source_id = ? AND reversed_by IS NULL
              ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$this->supplierId, $sourceType, $sourceId]);
        $id = (int) $stmt->fetchColumn();
        self::assertGreaterThan(0, $id, 'Chybí zápis ' . $sourceType . ' #' . $sourceId);
        return $id;
    }

    /** @param list<int> $entryIds MD − D účtu přes zadané zápisy. */
    private function balance(string $code, array $entryIds): float
    {
        $in = implode(',', array_map('intval', $entryIds));
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(CASE WHEN l.side = 'debit' THEN l.amount ELSE -l.amount END), 0)
               FROM journal_entry_lines l
               JOIN chart_of_accounts a ON a.id = l.account_id AND a.supplier_id = l.supplier_id
              WHERE l.supplier_id = ? AND l.entry_id IN ({$in}) AND a.account_code = ?"
        );
        $stmt->execute([$this->supplierId, $code]);
        return round((float) $stmt->fetchColumn(), 2);
    }
}
