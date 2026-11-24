<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Action\Accounting\BulkActionAction;
use MyInvoice\Action\Accounting\BulkDocumentItemsAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\AccountingBulkItemRepository;
use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Accounting\Bulk\BulkActionService;
use MyInvoice\Service\Accounting\Bulk\BulkContext;
use MyInvoice\Service\Accounting\Bulk\BulkOperations;
use MyInvoice\Service\Accounting\Bulk\BulkSelection;
use MyInvoice\Service\Accounting\Bulk\DocumentItemEditor;
use MyInvoice\Service\Accounting\Bulk\DocumentItemSearch;
use MyInvoice\Service\Invoice\InvoiceCalculator;
use MyInvoice\Service\Invoice\PurchaseInvoiceCalculator;
use MyInvoice\Service\Report\KontrolniHlaseniBuilder;
use MyInvoice\Service\Report\VatLedgerService;
use MyInvoice\Tests\Integration\Accounting\Bank\BankPostingTestCase;
use MyInvoice\Tests\Support\SavepointStatus;
use PHPUnit\Framework\Attributes\Group;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Hromadná změna členění DPH (a sazby) na položkách dokladů a agenda Položky dokladů:
 * změní se jen vybrané položky, doklad se přepočte a přeúčtuje cestou úpravy jednoho
 * dokladu, evidence DPH a KH čtou nové zařazení, podané období DPH, uzavřené období
 * a zamčené datum se přeskočí, vrácení obnoví přesně předchozí stav. K tomu filtr
 * položek (text bez ohledu na diakritiku), výběr jen z aktuální firmy, limit a práva.
 */
#[Group('integration')]
final class BulkVatClassificationTest extends BankPostingTestCase
{
    private ImportJobRepository $jobs;
    private AccountingBulkItemRepository $items;
    private int $rate21 = 0;
    private int $rate0 = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->jobs = $this->container->get(ImportJobRepository::class);
        $this->items = $this->container->get(AccountingBulkItemRepository::class);
        $pdo = $this->db->pdo();
        $this->rate21 = (int) $pdo->query("SELECT id FROM vat_rates WHERE code = 'CZ-21'")->fetchColumn();
        $this->rate0 = (int) $pdo->query("SELECT id FROM vat_rates WHERE code = 'CZ-0'")->fetchColumn();
    }

    // ── pomocníci ────────────────────────────────────────────────────────────

    /**
     * Vystavená faktura s položkami (popis => základ při 21 %), přepočtená a zaúčtovaná.
     *
     * @param array<string,float> $lines
     */
    private function postedIssued(array $lines, string $date = '-06-10', bool $post = true): int
    {
        $docId = $this->saleInvoice('SYN-DPH-' . random_int(100000, 999999), $this->client('Syntetický host'), 0.0);
        $this->db->pdo()->prepare('UPDATE invoices SET issue_date = ?, tax_date = ?, due_date = ? WHERE id = ?')
            ->execute([self::YEAR . $date, self::YEAR . $date, self::YEAR . $date, $docId]);
        $order = 0;
        foreach ($lines as $description => $base) {
            $this->db->pdo()->prepare(
                'INSERT INTO invoice_items
                    (invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id, vat_rate_snapshot,
                     total_without_vat, total_vat, total_with_vat, order_index, vat_classification_code)
                 VALUES (?, ?, 1, "ks", ?, ?, 21, 0, 0, 0, ?, "1")'
            )->execute([$docId, $description, $base, $this->rate21, $order++]);
        }
        $this->container->get(InvoiceCalculator::class)->recompute($docId);
        if ($post) {
            $this->posting->postDocument($this->supplierId, 'invoice', $docId,
                $this->posting->buildFromInvoice($this->supplierId, $docId),
                ['entry_date' => self::YEAR . $date, 'user_id' => $this->userId, 'posted' => true]);
        }
        return $docId;
    }

    /** @param array<string,float> $lines */
    private function postedPurchase(array $lines): int
    {
        $docId = $this->purchaseInvoice('SYN-PF-' . random_int(100000, 999999), $this->client('Syntetický dodavatel'), 0.0);
        $order = 0;
        foreach ($lines as $description => $base) {
            $this->db->pdo()->prepare(
                'INSERT INTO purchase_invoice_items
                    (purchase_invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id, vat_rate_snapshot,
                     total_without_vat, total_vat, total_with_vat, order_index, vat_classification_code, expense_account_code)
                 VALUES (?, ?, 1, "ks", ?, ?, 21, 0, 0, 0, ?, "40", "518")'
            )->execute([$docId, $description, $base, $this->rate21, $order++]);
        }
        $this->db->pdo()->prepare('UPDATE purchase_invoices SET vat_classification_code = "40" WHERE id = ?')->execute([$docId]);
        $this->container->get(PurchaseInvoiceCalculator::class)->recompute($docId);
        $this->posting->postDocument($this->supplierId, 'purchase_invoice', $docId,
            $this->posting->buildFromPurchaseInvoice($this->supplierId, $docId),
            ['entry_date' => self::YEAR . '-06-10', 'user_id' => $this->userId, 'posted' => true]);
        return $docId;
    }

    /** @return list<array<string,mixed>> položky v pořadí */
    private function itemsOf(string $table, string $fk, int $docId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT description, vat_classification_code, vat_rate_id, total_without_vat, total_vat, total_with_vat
               FROM {$table} WHERE {$fk} = ? ORDER BY order_index, id"
        );
        $stmt->execute([$docId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @return array<string,string> */
    private function totals(string $table, int $docId): array
    {
        $stmt = $this->db->pdo()->prepare("SELECT total_without_vat, total_vat, total_with_vat, vat_classification_code FROM {$table} WHERE id = ?");
        $stmt->execute([$docId]);
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    /** Částka řádku zápisu dokladu na účtu (součet stran). */
    private function journalAmount(string $sourceType, int $docId, string $account): float
    {
        $entry = $this->journal->findBySource($this->supplierId, $sourceType, $docId);
        self::assertNotNull($entry);
        $sum = 0.0;
        foreach ($this->journal->linesForEntry((int) $entry['id'], $this->supplierId) as $line) {
            if ($this->accountCode((int) $line['account_id']) === $account || str_starts_with($this->accountCode((int) $line['account_id']), $account)) {
                $sum += (float) $line['amount'];
            }
        }
        return round($sum, 2);
    }

    /** @return array<string,array<string,mixed>> popis => kanonický řádek evidence DPH */
    private function ledger(string $source, int $docId): array
    {
        $rows = $this->container->get(VatLedgerService::class)
            ->documentRows($this->supplierId, $source, $docId, self::YEAR . '-01-01', self::YEAR . '-12-31', true);
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['description']] = $row;
        }
        return $out;
    }

    /** @param list<int> $itemIds */
    private function dryRun(string $operation, string $target, array $ids, array $params): int
    {
        $ids = DocumentItemSearch::isItemTarget($target)
            ? $this->container->get(DocumentItemSearch::class)->explicitIds($this->supplierId, $target, $ids)
            : $this->container->get(BulkSelection::class)->explicitIds($this->supplierId, $target, $ids);
        $normalized = $this->container->get(BulkOperations::class)->get($operation, $target)
            ->normalizeParams($this->supplierId, $target, ['ids' => $ids] + $params);
        return $this->runJob(['kind' => 'dry_run', 'operation' => $operation, 'target' => $target,
            'selection_count' => count($ids), 'ids' => $ids] + $normalized);
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

    private function applyRun(int $dryJobId): int
    {
        $dry = $this->jobs->find($dryJobId, $this->supplierId);
        return $this->runJob(array_merge((array) $dry['params'], ['kind' => 'apply', 'dry_run_job_id' => $dryJobId, 'excluded_item_ids' => []]));
    }

    private function rollbackRun(int $applyJobId): int
    {
        $apply = (array) $this->jobs->find($applyJobId, $this->supplierId)['params'];
        unset($apply['dry_run_job_id'], $apply['excluded_item_ids']);
        return $this->runJob(['kind' => 'rollback', 'rollback_of_job_id' => $applyJobId] + $apply);
    }

    /** @return array<int,array<string,mixed>> source_id => položka úlohy */
    private function bySource(int $jobId): array
    {
        $out = [];
        foreach ($this->items->page($this->supplierId, $jobId, null, 1, 200)['items'] as $item) {
            $out[$item['source_id']] = $item;
        }
        return $out;
    }

    /** @return list<int> id položek dokladu podle textu */
    private function itemIds(string $table, string $fk, int $docId, string $like): array
    {
        $stmt = $this->db->pdo()->prepare("SELECT id FROM {$table} WHERE {$fk} = ? AND description LIKE ? ORDER BY id");
        $stmt->execute([$docId, $like]);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** @param array<string,int> $rights */
    private function request(string $method, string $path, array $rights): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest($method, $path)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant'])
            ->withAttribute('auth.effective_role', new EffectiveRole(9, 'Test', 'staff', true, $rights, ''));
    }

    // ── členění DPH na položkách ─────────────────────────────────────────────

    #[Group('fulltext-transactions')]
    public function testItemPreviewRequiresOwnTransactionAndCanBeRepeated(): void
    {
        $pdo = $this->db->pdo();
        $docId = $this->postedIssued(['Služba' => 100.0], post: false);
        $pdo->prepare("UPDATE invoices SET status = 'draft' WHERE id = ?")->execute([$docId]);
        $before = $this->itemsOf('invoice_items', 'invoice_id', $docId);
        $counters = SavepointStatus::read($pdo);
        $ctx = new BulkContext(0, $this->supplierId, $this->userId, 'invoice_item', [],
            $this->request('PUT', '/api/invoices/' . $docId, ['invoices' => 2]));
        $editor = $this->container->get(DocumentItemEditor::class);
        $changes = [0 => ['vat_classification_code' => 'mimo', 'vat_rate_id' => $this->rate0]];

        $pdo->beginTransaction();
        try {
            self::assertSame($before, $this->itemsOf('invoice_items', 'invoice_id', $docId));
            try {
                $editor->save($ctx, 'invoice', $docId, $changes, true);
                self::fail('Vnořený náhled položky musí být odmítnut.');
            } catch (\LogicException $e) {
                self::assertSame('Zápis položek hromadné akce vyžaduje vlastní transakci.', $e->getMessage());
            }
            self::assertTrue($pdo->inTransaction());
            self::assertSame($counters, SavepointStatus::read($pdo));
        } finally {
            $pdo->rollBack();
        }

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $result = $editor->save($ctx, 'invoice', $docId, $changes, true);
            self::assertTrue($result['ok'], json_encode($result, JSON_UNESCAPED_UNICODE));
            self::assertSame('mimo', $result['after']['items'][0]['vat_classification_code']);
            self::assertFalse($pdo->inTransaction());
            self::assertSame($before, $this->itemsOf('invoice_items', 'invoice_id', $docId));
        }
        self::assertSame($counters, SavepointStatus::read($pdo));
    }

    #[Group('fulltext-transactions')]
    public function testItemOutsideVatChangesOnlySelectedItemRecalculatesRepostsAndRollsBackExactly(): void
    {
        $docId = $this->postedIssued(['Ubytování v apartmánu' => 2000.0, 'Parkování' => 300.0, 'Místní poplatek z pobytu' => 100.0]);
        $other = $this->postedIssued(['Ubytování v apartmánu' => 1000.0]);
        $before = $this->itemsOf('invoice_items', 'invoice_id', $docId);
        $totalsBefore = $this->totals('invoices', $docId);
        self::assertSame(504.0, $this->journalAmount('invoice', $docId, '343'));
        self::assertSame('1', $this->ledger('sale', $docId)['Místní poplatek z pobytu']['code']);

        $fee = $this->itemIds('invoice_items', 'invoice_id', $docId, 'Místní%');
        $params = ['vat_classification_code' => 'mimo', 'vat_rate_id' => $this->rate0];
        $dryId = $this->dryRun('set_vat_classification', 'invoice_item', $fee, $params);
        $dry = $this->bySource($dryId);
        self::assertSame([$docId], array_keys($dry), 'Záznamem úlohy je doklad vybrané položky.');
        self::assertSame('would_change', $dry[$docId]['status'], json_encode($dry[$docId]));
        self::assertSame(121.0, (float) $dry[$docId]['before']['items'][0]['total_with_vat']);
        self::assertSame(100.0, (float) $dry[$docId]['after']['items'][0]['total_with_vat'], 'Náhled ukáže dopad sazby na částky.');
        self::assertSame($before, $this->itemsOf('invoice_items', 'invoice_id', $docId), 'Nanečisto se nic neuloží.');

        $applyId = $this->applyRun($dryId);
        $applied = $this->bySource($applyId)[$docId];
        self::assertSame('applied', $applied['status'], json_encode($applied));

        $after = $this->itemsOf('invoice_items', 'invoice_id', $docId);
        self::assertSame(array_slice($before, 0, 2), array_slice($after, 0, 2), 'Ubytování a parkování zůstanou beze změny.');
        self::assertSame(['mimo', $this->rate0, '100.00', '0.00', '100.00'],
            [$after[2]['vat_classification_code'], (int) $after[2]['vat_rate_id'], $after[2]['total_without_vat'], $after[2]['total_vat'], $after[2]['total_with_vat']]);
        self::assertSame(['2400.00', '483.00', '2883.00'], array_values(array_slice($this->totals('invoices', $docId), 0, 3)), 'Součty dokladu se přepočtou.');
        self::assertSame(483.0, $this->journalAmount('invoice', $docId, '343'), 'Zaúčtovaný doklad se přeúčtuje.');

        $ledger = $this->ledger('sale', $docId);
        self::assertSame('mimo', $ledger['Místní poplatek z pobytu']['code']);
        self::assertNull($ledger['Místní poplatek z pobytu']['dphdp3_line'], 'Mimo předmět daně nejde na řádek přiznání.');
        self::assertSame([], KontrolniHlaseniBuilder::rowSections($ledger['Místní poplatek z pobytu']), 'Ani do KH.');
        self::assertSame('1', $ledger['Parkování']['code']);
        self::assertSame('1', $this->ledger('sale', $other)['Ubytování v apartmánu']['code'], 'Jiný doklad se nemění.');

        $rolled = $this->bySource($this->rollbackRun($applyId))[$docId];
        self::assertSame('applied', $rolled['status'], json_encode($rolled));
        self::assertSame($before, $this->itemsOf('invoice_items', 'invoice_id', $docId), 'Vrácení obnoví zařazení, sazbu i částky.');
        self::assertSame($totalsBefore, $this->totals('invoices', $docId));
        self::assertSame(504.0, $this->journalAmount('invoice', $docId, '343'));
    }

    #[Group('fulltext-transactions')]
    public function testPurchaseItemClassificationUsesVatTreatmentAndRollsBack(): void
    {
        $docId = $this->postedPurchase(['Kancelářské potřeby' => 1000.0, 'Správní poplatek' => 200.0]);
        $before = $this->itemsOf('purchase_invoice_items', 'purchase_invoice_id', $docId);
        $fee = $this->itemIds('purchase_invoice_items', 'purchase_invoice_id', $docId, 'Správní%');

        $dryId = $this->dryRun('set_vat_classification', 'purchase_invoice_item', $fee, ['vat_classification_code' => '42']);
        self::assertSame('would_change', $this->bySource($dryId)[$docId]['status'] ?? null, json_encode($this->bySource($dryId)));
        $applyId = $this->applyRun($dryId);
        $applied = $this->bySource($applyId)[$docId];
        self::assertSame('applied', $applied['status'], json_encode($applied));
        $after = $this->itemsOf('purchase_invoice_items', 'purchase_invoice_id', $docId);
        self::assertSame($before[0], $after[0]);
        self::assertSame('42', $after[1]['vat_classification_code']);
        self::assertSame('42', $this->ledger('purchase', $docId)['Správní poplatek']['code']);
        self::assertSame('40', $this->ledger('purchase', $docId)['Kancelářské potřeby']['code']);

        $this->rollbackRun($applyId);
        self::assertSame($before, $this->itemsOf('purchase_invoice_items', 'purchase_invoice_id', $docId));
    }

    #[Group('fulltext-transactions')]
    public function testPurchaseItemRateChangeGoesThroughDocumentUpdate(): void
    {
        $docId = $this->postedPurchase(['Kancelářské potřeby' => 1000.0, 'Správní poplatek' => 200.0]);
        $before = $this->itemsOf('purchase_invoice_items', 'purchase_invoice_id', $docId);
        $dryId = $this->dryRun('set_vat_classification', 'purchase_invoice_item',
            $this->itemIds('purchase_invoice_items', 'purchase_invoice_id', $docId, 'Správní%'),
            ['vat_classification_code' => 'mimo', 'vat_rate_id' => $this->rate0]);
        $applyId = $this->applyRun($dryId);
        self::assertSame('applied', $this->bySource($applyId)[$docId]['status'], json_encode($this->bySource($applyId)));
        $after = $this->itemsOf('purchase_invoice_items', 'purchase_invoice_id', $docId);
        self::assertSame($before[0], $after[0]);
        self::assertSame(['mimo', $this->rate0, '200.00', '0.00'],
            [$after[1]['vat_classification_code'], (int) $after[1]['vat_rate_id'], $after[1]['total_without_vat'], $after[1]['total_vat']]);
        self::assertSame('210.00', $this->totals('purchase_invoices', $docId)['total_vat']);

        $this->rollbackRun($applyId);
        self::assertSame($before, $this->itemsOf('purchase_invoice_items', 'purchase_invoice_id', $docId));
    }

    #[Group('fulltext-transactions')]
    public function testDocumentTargetChangesAllStandardItems(): void
    {
        $docId = $this->postedIssued(['Služba A' => 100.0, 'Služba B' => 200.0], post: false);
        $this->db->pdo()->prepare("UPDATE invoices SET status = 'draft' WHERE id = ?")->execute([$docId]);
        $dryId = $this->dryRun('set_vat_classification', 'invoice', [$docId], ['vat_classification_code' => '1p']);
        self::assertSame('applied', $this->bySource($this->applyRun($dryId))[$docId]['status']);
        self::assertSame(['1p', '1p'], array_column($this->itemsOf('invoice_items', 'invoice_id', $docId), 'vat_classification_code'));
    }

    // ── účet a dimenze vybraných položek ─────────────────────────────────────

    public function testItemAccountRebookChangesOnlySelectedItemRepostsAndRollsBack(): void
    {
        $docId = $this->postedIssued(['Ubytování v apartmánu' => 1000.0, 'Parkování' => 200.0]);
        $parking = $this->itemIds('invoice_items', 'invoice_id', $docId, 'Parkování');
        $accounts = fn (): array => $this->db->pdo()->query("SELECT revenue_account_code FROM invoice_items WHERE invoice_id = {$docId} ORDER BY order_index, id")->fetchAll(\PDO::FETCH_COLUMN);
        $before = $accounts();
        self::assertSame(0.0, $this->journalAmount('invoice', $docId, '604'));

        $dryId = $this->dryRun('rebook_account', 'invoice_item', $parking, ['to_account' => '604']);
        self::assertSame('would_change', $this->bySource($dryId)[$docId]['status'], json_encode($this->bySource($dryId)));
        $applyId = $this->applyRun($dryId);
        self::assertSame('applied', $this->bySource($applyId)[$docId]['status'], json_encode($this->bySource($applyId)));
        self::assertSame([$before[0], '604'], $accounts(), 'Účet dostane jen vybraná položka.');
        self::assertSame(200.0, $this->journalAmount('invoice', $docId, '604'), 'Zaúčtovaný doklad se přeúčtuje.');

        self::assertSame('applied', $this->bySource($this->rollbackRun($applyId))[$docId]['status']);
        self::assertSame($before, $accounts());
        self::assertSame(0.0, $this->journalAmount('invoice', $docId, '604'));
    }

    public function testItemDimensionsOnlyOnSelectedItems(): void
    {
        $dimensions = $this->container->get(\MyInvoice\Service\Accounting\Dimension\DimensionService::class);
        $assignments = $this->container->get(\MyInvoice\Repository\DimensionAssignmentRepository::class);
        $dimensions->setEnabled($this->supplierId, true);
        $type = $dimensions->ensureDefaultTypes($this->supplierId)['cost_center'];
        $value = (int) $dimensions->createValue($this->supplierId, $type, ['code' => 'S-POL' . random_int(1000, 9999), 'name' => 'Syntetické středisko'])['id'];
        $docId = $this->postedIssued(['Ubytování v apartmánu' => 1000.0, 'Parkování' => 200.0, 'Snídaně' => 100.0]);

        $dryId = $this->dryRun('set_dimensions', 'invoice_item', $this->itemIds('invoice_items', 'invoice_id', $docId, 'Parkování'),
            ['dimensions' => [['type_id' => $type, 'mode' => 'set', 'value_id' => $value]]]);
        $applyId = $this->applyRun($dryId);
        self::assertSame('applied', $this->bySource($applyId)[$docId]['status'], json_encode($this->bySource($applyId)));
        self::assertSame([2 => [$type => $value]], $assignments->documentDimensions($this->supplierId, 'invoice', $docId)['items'],
            'Dimenzi dostane jen druhá položka, ostatní položky téhož dokladu zůstanou.');

        $this->rollbackRun($applyId);
        self::assertSame([], $assignments->documentDimensions($this->supplierId, 'invoice', $docId)['items']);
    }

    // ── pojistky ─────────────────────────────────────────────────────────────

    public function testFiledVatPeriodIsSkippedEvenForAdminAndNothingChanges(): void
    {
        $docId = $this->postedIssued(['Místní poplatek z pobytu' => 100.0]);
        $this->db->pdo()->prepare(
            "INSERT INTO tax_submissions (supplier_id, form_code, period_year, period_month, period_quarter, form_variant, xml_content,
                xml_size_bytes, xml_sha256, validation_status, status, submitted_at, summary_json, generated_by)
             VALUES (?, 'dphkh1', ?, 6, NULL, 'B', '<test/>', 7, ?, 'passed', 'submitted', NOW(), '{}', NULL)"
        )->execute([$this->supplierId, self::YEAR, hash('sha256', 'syn-kh')]);
        $before = $this->itemsOf('invoice_items', 'invoice_id', $docId);

        $item = $this->bySource($this->dryRun('set_vat_classification', 'invoice_item',
            $this->itemIds('invoice_items', 'invoice_id', $docId, '%'), ['vat_classification_code' => 'mimo']))[$docId];
        self::assertSame(['skipped', 'vat_period_filed'], [$item['status'], $item['reason_code']], json_encode($item));
        self::assertSame($before, $this->itemsOf('invoice_items', 'invoice_id', $docId));
    }

    public function testClosedPeriodIsSkipped(): void
    {
        $docId = $this->postedIssued(['Místní poplatek z pobytu' => 100.0]);
        $ids = $this->itemIds('invoice_items', 'invoice_id', $docId, '%');
        $this->db->pdo()->prepare("UPDATE accounting_periods SET status = 'closed' WHERE id = ?")->execute([$this->periodId]);
        $item = $this->bySource($this->dryRun('set_vat_classification', 'invoice_item', $ids, ['vat_classification_code' => 'mimo']))[$docId];
        self::assertSame(['skipped', 'period_not_open'], [$item['status'], $item['reason_code']]);
        self::assertSame('1', $this->itemsOf('invoice_items', 'invoice_id', $docId)[0]['vat_classification_code']);
    }

    public function testDateLockIsSkipped(): void
    {
        $locked = $this->postedIssued(['Místní poplatek z pobytu' => 100.0], '-05-10');
        $this->db->pdo()->prepare('INSERT INTO accounting_supplier_settings (supplier_id, locked_until) VALUES (?, ?) ON DUPLICATE KEY UPDATE locked_until = VALUES(locked_until)')
            ->execute([$this->supplierId, self::YEAR . '-05-31']);
        $item = $this->bySource($this->dryRun('set_vat_classification', 'invoice_item',
            $this->itemIds('invoice_items', 'invoice_id', $locked, '%'), ['vat_classification_code' => 'mimo']))[$locked];
        self::assertSame(['skipped', 'date_locked'], [$item['status'], $item['reason_code']]);
        self::assertSame('1', $this->itemsOf('invoice_items', 'invoice_id', $locked)[0]['vat_classification_code']);
    }

    // ── agenda Položky dokladů ───────────────────────────────────────────────

    public function testItemSearchFiltersTextWithoutDiacriticsAndPagesByDocument(): void
    {
        $a = $this->postedIssued(['Ubytování' => 1000.0, 'Místní poplatek z pobytu' => 50.0], '-03-05', false);
        $b = $this->postedIssued(['Místní poplatek z pobytu' => 30.0, 'Parkování' => 100.0], '-03-20', false);
        $search = $this->container->get(DocumentItemSearch::class);
        $filters = DocumentItemSearch::filters(['filter' => ['q' => 'MISTNI poplatek', 'date_from' => self::YEAR . '-03-01', 'date_to' => self::YEAR . '-03-31']]);

        $page = $search->page($this->supplierId, 'invoice_item', $filters, null, 1);
        self::assertSame([$b], array_column($page['items'], 'document_id'), 'Nejnovější doklad první, celé doklady na stránku.');
        self::assertSame(['Místní poplatek z pobytu'], array_column($page['items'], 'description'));
        $next = $search->page($this->supplierId, 'invoice_item', $filters, $page['next_cursor'], 1);
        self::assertSame([$a], array_column($next['items'], 'document_id'));
        self::assertSame(['count' => 2, 'capped' => false], $search->count($this->supplierId, 'invoice_item', $filters, BulkSelection::LIMIT + 1));

        $byRate = DocumentItemSearch::filters(['filter' => ['vat_code' => '1', 'doc_number' => 'SYN-DPH-', 'date_from' => self::YEAR . '-03-01', 'date_to' => self::YEAR . '-03-31']]);
        self::assertCount(4, $search->matchingIds($this->supplierId, 'invoice_item', $byRate, 10));

        $response = $this->container->get(BulkDocumentItemsAction::class)(
            $this->request('GET', '/api/accounting/bulk-actions/document-items', ['invoices' => 1])
                ->withQueryParams(['target' => 'invoice_item', 'filter' => ['q' => 'místní', 'date_from' => self::YEAR . '-03-01', 'date_to' => self::YEAR . '-03-31']]),
            new Response(),
        );
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(2, $body['count']);
    }

    public function testStartRefusesForeignItemsEnforcesLimitAndPermission(): void
    {
        $docId = $this->postedIssued(['Místní poplatek z pobytu' => 50.0], post: false);
        $own = $this->itemIds('invoice_items', 'invoice_id', $docId, '%');
        $body = ['dry_run' => true, 'operation' => 'set_vat_classification', 'target' => 'invoice_item',
            'selection' => ['ids' => $own], 'params' => ['vat_classification_code' => 'mimo']];
        $rights = ['accounting' => 2, 'accounting.bulk_changes' => 2, 'invoices' => 2];

        [$status] = $this->callStart(['accounting' => 2, 'invoices' => 2], $body);
        self::assertSame(403, $status, 'Bez práva Hromadné změny akci nespustí.');

        [$status, $resp] = $this->callStart(['accounting' => 2, 'accounting.bulk_changes' => 2, 'invoices' => 1], $body);
        self::assertSame([409, 'operation_unavailable'], [$status, $resp['error']['code'] ?? null], 'Bez práva upravovat faktury operace není dostupná.');

        $foreign = $this->foreignItemId();
        [$status, $resp] = $this->callStart($rights, ['selection' => ['ids' => [...$own, $foreign]]] + $body);
        self::assertSame([422, 'foreign_ids'], [$status, $resp['error']['code'] ?? null], json_encode($resp));

        [$status, $resp] = $this->callStart($rights, ['selection' => ['ids' => range(1, BulkSelection::LIMIT + 1)]] + $body);
        self::assertSame([422, 'too_many_items'], [$status, $resp['error']['code'] ?? null]);

        [$status, $resp] = $this->callStart($rights, ['params' => ['vat_classification_code' => '40']] + $body);
        self::assertSame([422, 'validation_failed'], [$status, $resp['error']['code'] ?? null], 'Kód přijatých plnění na vydané faktuře neprojde.');
    }

    /**
     * @param array<string,int> $rights
     * @param array<string,mixed> $body
     * @return array{0:int, 1:array<string,mixed>}
     */
    private function callStart(array $rights, array $body): array
    {
        $request = $this->request('POST', '/api/accounting/bulk-actions/start', $rights)->withParsedBody($body);
        $response = $this->container->get(BulkActionAction::class)->start($request, new Response());
        return [$response->getStatusCode(), (array) json_decode((string) $response->getBody(), true)];
    }

    private function foreignItemId(): int
    {
        $other = $this->otherSupplierId();
        $client = $this->client('Cizí odběratel');
        $this->db->pdo()->prepare('UPDATE clients SET supplier_id = ? WHERE id = ?')->execute([$other, $client]);
        $this->db->pdo()->prepare(
            'INSERT INTO invoices (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                currency_id, reverse_charge, total_without_vat, total_vat, total_with_vat, paid_total, status, vat_classification_code, created_by)
             VALUES (?, NULL, "invoice", ?, ?, ?, ?, ?, 0, 1, 0, 1, 0, "draft", "1", ?)'
        )->execute([$other, $client, self::YEAR . '-06-10', self::YEAR . '-06-10', self::YEAR . '-06-10', $this->currencyId, $this->userId]);
        $invoiceId = (int) $this->db->pdo()->lastInsertId();
        $this->db->pdo()->prepare(
            'INSERT INTO invoice_items (invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id, vat_rate_snapshot,
                total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, "Cizí položka", 1, "ks", 1, ?, 21, 1, 0, 1, 0)'
        )->execute([$invoiceId, $this->rate21]);
        return (int) $this->db->pdo()->lastInsertId();
    }
}
