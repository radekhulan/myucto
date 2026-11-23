<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Action\Accounting\BulkActionAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\AccountingBulkItemRepository;
use MyInvoice\Repository\DimensionAssignmentRepository;
use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Accounting\Bulk\BulkActionService;
use MyInvoice\Service\Accounting\Bulk\BulkOperations;
use MyInvoice\Service\Accounting\Bulk\BulkSelection;
use MyInvoice\Service\Accounting\Dimension\DimensionRuleService;
use MyInvoice\Service\Accounting\Dimension\DimensionService;
use MyInvoice\Service\Accounting\JournalRebookService;
use MyInvoice\Tests\Integration\Accounting\Bank\BankPostingTestCase;
use PHPUnit\Framework\Attributes\Group;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Hromadné akce v seznamech: běh nanečisto → ostrý běh → vrácení pro každou operaci,
 * důvody přeskočení (uzavřené období, zmrazené dimenze, zámek data, povinná dimenze,
 * pravidla mazání jednoho záznamu), práva, limit a omezení výběru na aktuální firmu.
 */
#[Group('integration')]
final class BulkActionTest extends BankPostingTestCase
{
    private ImportJobRepository $jobs;
    private AccountingBulkItemRepository $items;
    private DimensionService $dimensions;
    private DimensionAssignmentRepository $assignments;
    /** @var array<string,int> druh => id typu */
    private array $types = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->jobs = $this->container->get(ImportJobRepository::class);
        $this->items = $this->container->get(AccountingBulkItemRepository::class);
        $this->dimensions = $this->container->get(DimensionService::class);
        $this->assignments = $this->container->get(DimensionAssignmentRepository::class);
        $this->dimensions->setEnabled($this->supplierId, true);
        $this->types = $this->dimensions->ensureDefaultTypes($this->supplierId);
    }

    // ── pomocníci ────────────────────────────────────────────────────────────

    private function value(string $kind, string $code): int
    {
        return (int) $this->dimensions->createValue($this->supplierId, $this->types[$kind], [
            'code' => $code . random_int(1000, 9999), 'name' => 'Syntetická hodnota ' . $code,
        ])['id'];
    }

    private function postedSaleInvoice(string $date = '-06-10', array $header = [], int $items = 1): int
    {
        $docId = $this->saleInvoice('SYN-BA-' . random_int(100000, 999999), $this->client('Syntetický odběratel'), 1000.0 * $items);
        for ($i = 0; $i < $items; $i++) {
            $this->db->pdo()->prepare(
                'INSERT INTO invoice_items
                    (invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                     vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
                 VALUES (?, "Služba", 1, "ks", 1000, (SELECT MIN(id) FROM vat_rates), 0, 1000, 0, 1000, ?)'
            )->execute([$docId, $i]);
        }
        if ($header !== []) {
            $this->dimensions->saveDocument($this->supplierId, 'invoice', $docId, $header, null);
        }
        $this->posting->postDocument($this->supplierId, 'invoice', $docId,
            $this->posting->buildFromInvoice($this->supplierId, $docId),
            ['entry_date' => self::YEAR . $date, 'user_id' => $this->userId, 'posted' => true]);
        return $docId;
    }

    private function manualEntry(string $debit, string $credit, float $amount, string $date = '-06-12', ?int $periodId = null): int
    {
        $map = $this->accounts->codeToIdMap($this->supplierId);
        return $this->journal->insert([
            'supplier_id' => $this->supplierId, 'period_id' => $periodId ?? $this->periodId, 'entry_date' => self::YEAR . $date,
            'document_no' => 'ID-SYN-' . random_int(1000, 9999), 'description' => 'Syntetický zápis', 'source_type' => 'manual', 'source_id' => null,
            'posted_at' => date('Y-m-d H:i:s'), 'posted_by' => $this->userId,
        ], [
            ['account_id' => $map[$debit]['id'], 'side' => 'debit', 'amount' => $amount],
            ['account_id' => $map[$credit]['id'], 'side' => 'credit', 'amount' => $amount],
        ]);
    }

    private function manualEntryAt(string $debit, string $credit, float $amount, string $date, int $periodId): int
    {
        $map = $this->accounts->codeToIdMap($this->supplierId);
        return $this->journal->insert([
            'supplier_id' => $this->supplierId, 'period_id' => $periodId, 'entry_date' => $date,
            'document_no' => 'ID-SYN-' . random_int(1000, 9999), 'description' => 'Syntetický zápis', 'source_type' => 'manual', 'source_id' => null,
            'posted_at' => date('Y-m-d H:i:s'), 'posted_by' => $this->userId,
        ], [
            ['account_id' => $map[$debit]['id'], 'side' => 'debit', 'amount' => $amount],
            ['account_id' => $map[$credit]['id'], 'side' => 'credit', 'amount' => $amount],
        ]);
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

    /** @param list<int> $excludedItemIds */
    private function applyRun(int $dryJobId, array $excludedItemIds = []): int
    {
        $dry = $this->jobs->find($dryJobId, $this->supplierId);
        return $this->runJob(array_merge((array) $dry['params'], [
            'kind' => 'apply', 'dry_run_job_id' => $dryJobId, 'excluded_item_ids' => $excludedItemIds,
        ]));
    }

    private function rollbackRun(int $applyJobId): int
    {
        $apply = (array) $this->jobs->find($applyJobId, $this->supplierId)['params'];
        unset($apply['dry_run_job_id'], $apply['excluded_item_ids']);
        return $this->runJob(['kind' => 'rollback', 'rollback_of_job_id' => $applyJobId] + $apply);
    }

    /** @return array<int,array<string,mixed>> source_id => položka */
    private function bySource(int $jobId): array
    {
        $out = [];
        foreach ($this->items->page($this->supplierId, $jobId, null, 1, 200)['items'] as $item) {
            $out[$item['source_id']] = $item;
        }
        return $out;
    }

    /** @return array<string,array<int,int>> kód účtu => typ => hodnota (živý zápis dokladu) */
    private function lineDims(string $sourceType, int $sourceId): array
    {
        $entry = $this->journal->findBySource($this->supplierId, $sourceType, $sourceId);
        self::assertNotNull($entry);
        return $this->entryLineDims((int) $entry['id']);
    }

    /** @return array<string,array<int,int>> */
    private function entryLineDims(int $entryId): array
    {
        $dims = $this->assignments->entryLineDimensions($this->supplierId, $entryId);
        $out = [];
        foreach ($this->journal->linesForEntry($entryId, $this->supplierId) as $line) {
            $code = $this->accountCode((int) $line['account_id']);
            $out[$code] = ($out[$code] ?? []) + ($dims[(int) $line['id']] ?? []);
        }
        return $out;
    }

    /** @return array<int,int> */
    private function header(int $invoiceId): array
    {
        return $this->assignments->documentDimensions($this->supplierId, 'invoice', $invoiceId)['header'];
    }

    /**
     * @param array<string,int> $rights
     * @param array<string,mixed> $body
     * @return array{0:int, 1:array<string,mixed>}
     */
    private function callStart(array $rights, array $body, string $systemKey = ''): array
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/accounting/bulk-actions/start')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant'])
            ->withAttribute('auth.effective_role', new EffectiveRole(9, 'Test', 'staff', true, $rights, $systemKey))
            ->withParsedBody($body);
        $response = $this->container->get(BulkActionAction::class)->start($request, new Response());
        return [$response->getStatusCode(), (array) json_decode((string) $response->getBody(), true)];
    }

    // ── set_dimensions ───────────────────────────────────────────────────────

    public function testSetDimensionsOnDocumentsWithModesMultipleTypesAndExactRollback(): void
    {
        $centerOld = $this->value('cost_center', 'S-OLD');
        $centerNew = $this->value('cost_center', 'S-NEW');
        $project = $this->value('project', 'P-1');
        $projectKept = $this->value('project', 'P-KEEP');
        $center = $this->types['cost_center'];
        $projectType = $this->types['project'];

        $a = $this->postedSaleInvoice(header: [$center => $centerOld]);
        $b = $this->postedSaleInvoice();
        $c = $this->postedSaleInvoice(header: [$projectType => $projectKept]);

        $dryId = $this->dryRun('set_dimensions', 'invoice', [$a, $b, $c], ['scope' => 'document', 'dimensions' => [
            ['type_id' => $center, 'mode' => 'set', 'value_id' => $centerNew],
            ['type_id' => $projectType, 'mode' => 'fill', 'value_id' => $project],
        ]]);
        $dry = $this->bySource($dryId);
        self::assertSame('would_change', $dry[$a]['status'], json_encode($dry[$a]));
        self::assertSame([$center => $centerOld], $this->header($a), 'Nanečisto se nic neuloží.');

        $applyId = $this->applyRun($dryId);
        self::assertSame('applied', $this->bySource($applyId)[$a]['status']);
        self::assertSame([$center => $centerNew, $projectType => $project], $this->header($a));
        self::assertSame([$center => $centerNew, $projectType => $project], $this->header($b));
        self::assertSame([$center => $centerNew, $projectType => $projectKept], $this->header($c), 'Režim „jen kde je prázdné" ponechá vyplněnou hodnotu.');
        foreach ($this->lineDims('invoice', $a) as $code => $dims) {
            self::assertSame($centerNew, $dims[$center] ?? null, 'Hlavička dokladu platí pro řádek ' . $code);
        }

        $clearDry = $this->dryRun('set_dimensions', 'invoice', [$a], ['scope' => 'document', 'dimensions' => [
            ['type_id' => $projectType, 'mode' => 'clear'],
        ]]);
        $this->applyRun($clearDry);
        self::assertSame([$center => $centerNew], $this->header($a));

        $linesBefore = $this->lineDims('invoice', $b);
        $rollbackId = $this->rollbackRun($applyId);
        $rolled = $this->bySource($rollbackId);
        self::assertSame('applied', $rolled[$b]['status'], json_encode($rolled[$b]));
        self::assertSame('failed', $rolled[$a]['status'], 'Doklad A se po hromadné změně znovu změnil, vrácení ho nechá být.');
        self::assertSame('changed_after_apply', $rolled[$a]['reason_code']);
        self::assertSame([], $this->header($b), 'Vrácení obnoví i „bez hodnoty".');
        self::assertSame([$projectType => $projectKept], $this->header($c));
        self::assertNotSame($linesBefore, $this->lineDims('invoice', $b));
        foreach ($this->lineDims('invoice', $b) as $dims) {
            self::assertArrayNotHasKey($center, $dims);
        }
    }

    public function testItemsScopeReachesOnlyProfitAndLossLines(): void
    {
        $center = $this->types['cost_center'];
        $value = $this->value('cost_center', 'S-ITEM');
        $docId = $this->postedSaleInvoice(items: 2);

        $dryId = $this->dryRun('set_dimensions', 'invoice', [$docId], ['scope' => 'items', 'dimensions' => [
            ['type_id' => $center, 'mode' => 'set', 'value_id' => $value],
        ]]);
        $this->applyRun($dryId);

        $doc = $this->assignments->documentDimensions($this->supplierId, 'invoice', $docId);
        self::assertSame([], $doc['header']);
        self::assertSame([1 => [$center => $value], 2 => [$center => $value]], $doc['items']);
        $lines = $this->lineDims('invoice', $docId);
        self::assertSame($value, $lines['602'][$center] ?? null, 'Výnosový řádek nese hodnotu položky.');
        self::assertArrayNotHasKey($center, $lines['311'] ?? [], 'Řádek pohledávky hodnotu položky nedostane.');
    }

    public function testItemsScopeLeavesHeaderSplitAloneAndFillTreatsItAsValue(): void
    {
        $center = $this->types['cost_center'];
        $a = $this->value('cost_center', 'S-SPA');
        $b = $this->value('cost_center', 'S-SPB');
        $item = $this->value('cost_center', 'S-ITM');
        $docId = $this->postedSaleInvoice();
        $this->dimensions->saveDocument($this->supplierId, 'invoice', $docId, [], null, false, [0 => [$center => [$a => 0.5, $b => 0.5]]]);
        $split = [0 => [$center => [$a => 0.5, $b => 0.5]]];
        self::assertEquals($split, $this->assignments->documentSplits($this->supplierId, 'invoice', $docId));

        $fill = $this->bySource($this->dryRun('set_dimensions', 'invoice', [$docId], ['scope' => 'items', 'dimensions' => [
            ['type_id' => $center, 'mode' => 'fill', 'value_id' => $item],
        ]]))[$docId];
        self::assertSame('unchanged', $fill['status'], 'Rozpad hlavičky je hodnota: „jen kde je prázdné" položku nevyplní.');

        $this->applyRun($this->dryRun('set_dimensions', 'invoice', [$docId], ['scope' => 'items', 'dimensions' => [
            ['type_id' => $center, 'mode' => 'set', 'value_id' => $item],
        ]]));
        $doc = $this->assignments->documentDimensions($this->supplierId, 'invoice', $docId);
        self::assertSame([1 => [$center => $item]], $doc['items']);
        self::assertEquals($split, $this->assignments->documentSplits($this->supplierId, 'invoice', $docId),
            'Změna položek rozpad hlavičky nesmaže.');
    }

    public function testJournalLinesOnlyOnChosenAccountPrefixesAndRollback(): void
    {
        $center = $this->types['cost_center'];
        $value = $this->value('cost_center', 'S-LINE');
        $entryId = $this->manualEntry('518', '321', 400.0);

        $dryId = $this->dryRun('set_dimensions', 'journal_entry', [$entryId], ['account_prefixes' => '5, 6', 'dimensions' => [
            ['type_id' => $center, 'mode' => 'set', 'value_id' => $value],
        ]]);
        self::assertSame('would_change', $this->bySource($dryId)[$entryId]['status'] ?? null, json_encode($this->bySource($dryId)));
        $applyId = $this->applyRun($dryId);
        self::assertSame('applied', $this->bySource($applyId)[$entryId]['status']);
        $lines = $this->entryLineDims($entryId);
        self::assertSame($value, $lines['518'][$center] ?? null);
        self::assertArrayNotHasKey($center, $lines['321'] ?? []);

        $this->rollbackRun($applyId);
        self::assertSame([], $this->entryLineDims($entryId)['518'] ?? []);

        $noMatch = $this->dryRun('set_dimensions', 'journal_entry', [$entryId], ['account_prefixes' => ['7'], 'dimensions' => [
            ['type_id' => $center, 'mode' => 'set', 'value_id' => $value],
        ]]);
        $item = $this->bySource($noMatch)[$entryId];
        self::assertSame(['unchanged', 'no_matching_lines'], [$item['status'], $item['reason_code']]);
    }

    public function testDryRunReportsRequiredDimensionViolationAndApplyCreatesNone(): void
    {
        $center = $this->types['cost_center'];
        $value = $this->value('cost_center', 'S-REQ');
        $docId = $this->postedSaleInvoice(header: [$center => $value]);
        (new DimensionRuleService($this->db))->create($this->supplierId, [
            'dimension_type_id' => $center, 'account_mask' => '602', 'enforcement' => 'error',
        ], $this->userId);

        $dryId = $this->dryRun('set_dimensions', 'invoice', [$docId], ['scope' => 'document', 'dimensions' => [
            ['type_id' => $center, 'mode' => 'clear'],
        ]]);
        $item = $this->bySource($dryId)[$docId];
        self::assertSame(['skipped', 'dimension_required'], [$item['status'], $item['reason_code']], json_encode($item));
        self::assertSame(0, (int) $this->jobs->find($dryId, $this->supplierId)['created_count']);
        self::assertSame([$center => $value], $this->header($docId));
        self::assertSame($value, $this->lineDims('invoice', $docId)['602'][$center] ?? null);
    }

    public function testRequiredDimensionCheckCoversRestampedPaymentEntries(): void
    {
        $center = $this->types['cost_center'];
        $value = $this->value('cost_center', 'S-PAY');
        $docId = $this->postedSaleInvoice(header: [$center => $value]);
        $tx = $this->transaction($this->statement(), 1000.0, ['match_status' => 'manual', 'matched_invoice_id' => $docId]);
        $this->invoicePayment($docId, $tx, 1000.0);
        $this->service->handleTransaction($tx, $this->userId);
        $bankEntry = $this->journal->findBySource($this->supplierId, 'bank', $tx);
        self::assertNotNull($bankEntry, 'Úhrada je zaúčtovaná.');
        self::assertSame($value, $this->entryLineDims((int) $bankEntry['id'])['221.100'][$center] ?? null, 'Úhrada převzala středisko faktury.');
        // Povinné středisko jen na bankovním účtu: vlastní zápis faktury ho nemá, hlídá se jen úhrada.
        (new DimensionRuleService($this->db))->create($this->supplierId, [
            'dimension_type_id' => $center, 'account_mask' => '221', 'enforcement' => 'error',
        ], $this->userId);

        $item = $this->bySource($this->dryRun('set_dimensions', 'invoice', [$docId], ['scope' => 'document', 'dimensions' => [
            ['type_id' => $center, 'mode' => 'clear'],
        ]]))[$docId];
        self::assertSame(['skipped', 'dimension_required'], [$item['status'], $item['reason_code']], json_encode($item));
        self::assertSame($value, $this->entryLineDims((int) $bankEntry['id'])['221.100'][$center] ?? null);
    }

    public function testClosedPeriodAndFrozenDimensionsAreSkipped(): void
    {
        $center = $this->types['cost_center'];
        $value = $this->value('cost_center', 'S-CLOSED');
        $docId = $this->postedSaleInvoice();
        $change = ['scope' => 'document', 'dimensions' => [['type_id' => $center, 'mode' => 'set', 'value_id' => $value]]];

        $this->db->pdo()->prepare("UPDATE accounting_periods SET status = 'closed' WHERE id = ?")->execute([$this->periodId]);
        $item = $this->bySource($this->dryRun('set_dimensions', 'invoice', [$docId], $change))[$docId];
        self::assertSame(['skipped', 'period_not_open'], [$item['status'], $item['reason_code']]);

        $this->db->pdo()->prepare("UPDATE accounting_periods SET status = 'open' WHERE id = ?")->execute([$this->periodId]);
        $this->manualEntryOfSource('closing', '702', '431', '-12-31');
        $item = $this->bySource($this->dryRun('set_dimensions', 'invoice', [$docId], $change))[$docId];
        self::assertSame(['skipped', 'dimensions_frozen'], [$item['status'], $item['reason_code']]);
        self::assertSame([], $this->header($docId));
    }

    private function manualEntryOfSource(string $sourceType, string $debit, string $credit, string $date): int
    {
        $map = $this->accounts->codeToIdMap($this->supplierId);
        return $this->journal->insert([
            'supplier_id' => $this->supplierId, 'period_id' => $this->periodId, 'entry_date' => self::YEAR . $date,
            'document_no' => null, 'description' => 'Syntetická uzávěrka', 'source_type' => $sourceType, 'source_id' => null,
            'posted_at' => date('Y-m-d H:i:s'), 'posted_by' => $this->userId,
        ], [
            ['account_id' => $map[$debit]['id'], 'side' => 'debit', 'amount' => 1.0],
            ['account_id' => $map[$credit]['id'], 'side' => 'credit', 'amount' => 1.0],
        ]);
    }

    // ── set_posting_rule ─────────────────────────────────────────────────────

    public function testSetPostingRuleRepostsRollsBackAndRespectsPeriodAndLock(): void
    {
        $this->db->pdo()->prepare(
            "INSERT INTO posting_rules (supplier_id, rule_key, description, debit_account_code, credit_account_code, priority, is_active)
             VALUES (?, 'syn.sale_604', 'Syntetická předkontace', '311', '604', 0, 1)"
        )->execute([$this->supplierId]);
        $docId = $this->postedSaleInvoice();
        $locked = $this->postedSaleInvoice('-02-10');
        $this->db->pdo()->prepare("UPDATE invoice_items SET revenue_account_code = '602' WHERE invoice_id IN (?, ?)")->execute([$docId, $locked]);

        $params = ['rule_key' => 'syn.sale_604', 'mode' => JournalRebookService::MODE_REWRITE];
        $this->db->pdo()->prepare("UPDATE accounting_periods SET status = 'closed' WHERE id = ?")->execute([$this->periodId]);
        $closed = $this->bySource($this->dryRun('set_posting_rule', 'invoice', [$docId, $locked], $params));
        self::assertSame(['skipped', 'period_not_open'], [$closed[$docId]['status'], $closed[$docId]['reason_code']], json_encode($closed[$docId]));
        $this->db->pdo()->prepare("UPDATE accounting_periods SET status = 'open' WHERE id = ?")->execute([$this->periodId]);
        $this->db->pdo()->prepare('INSERT INTO accounting_supplier_settings (supplier_id, locked_until) VALUES (?, ?) ON DUPLICATE KEY UPDATE locked_until = VALUES(locked_until)')
            ->execute([$this->supplierId, self::YEAR . '-03-31']);

        $dryId = $this->dryRun('set_posting_rule', 'invoice', [$docId, $locked], $params);
        $dry = $this->bySource($dryId);
        self::assertSame('would_change', $dry[$docId]['status'], json_encode($dry[$docId]));
        self::assertContains('604', array_column($dry[$docId]['after']['lines'], 'account_code'));
        self::assertSame(['would_change', 'tax_neutral_rewrite'], [$dry[$locked]['status'], $dry[$locked]['reason_code']],
            'V zamčeném datu projde jen daňově neutrální přesun výnosu, jako u Přeúčtovat.');
        self::assertSame(['602'], array_values(array_unique(array_filter(array_map('strval', array_keys($this->linesByAccountCode((int) $this->journal->findBySource($this->supplierId, 'invoice', $docId)['id']))), static fn (string $c): bool => str_starts_with($c, '6')))));

        $applyId = $this->applyRun($dryId);
        self::assertSame('applied', $this->bySource($applyId)[$docId]['status'], json_encode($this->bySource($applyId)[$docId]));
        $codes = array_map('strval', array_keys($this->linesByAccountCode((int) $this->journal->findBySource($this->supplierId, 'invoice', $docId)['id'])));
        self::assertContains('604', $codes);
        self::assertNotContains('602', $codes);
        $item = $this->db->pdo()->query("SELECT posting_rule_key, revenue_account_code FROM invoice_items WHERE invoice_id = {$docId}")->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('syn.sale_604', $item['posting_rule_key']);

        $this->rollbackRun($applyId);
        $codes = array_map('strval', array_keys($this->linesByAccountCode((int) $this->journal->findBySource($this->supplierId, 'invoice', $docId)['id'])));
        self::assertContains('602', $codes);
        self::assertNotContains('604', $codes);
        self::assertNull($this->db->pdo()->query("SELECT posting_rule_key FROM invoice_items WHERE invoice_id = {$docId}")->fetchColumn() ?: null);
    }

    public function testSetPostingRuleInFiledVatPeriodRewritesOnlyTaxNeutrally(): void
    {
        $insert = $this->db->pdo()->prepare(
            "INSERT INTO posting_rules (supplier_id, rule_key, description, debit_account_code, credit_account_code, priority, is_active)
             VALUES (?, ?, 'Syntetická předkontace', ?, ?, 0, 1)"
        );
        $insert->execute([$this->supplierId, 'syn.sale_604', '311', '604']);
        $insert->execute([$this->supplierId, 'syn.sale_accrual', '311', '384']);
        $docId = $this->postedSaleInvoice();
        $this->db->pdo()->prepare("UPDATE invoice_items SET revenue_account_code = '602' WHERE invoice_id = ?")->execute([$docId]);
        // Podané přiznání k DPH za červen (zámek k datu správce posunul zpět, nikde není)
        // a podané přiznání k dani z příjmů: přesun výnosu do jiné třídy už není neutrální.
        $submission = $this->db->pdo()->prepare(
            "INSERT INTO tax_submissions (supplier_id, form_code, period_year, period_month, period_quarter, form_variant, xml_content,
                xml_size_bytes, xml_sha256, validation_status, status, submitted_at, summary_json, generated_by)
             VALUES (?, ?, ?, ?, NULL, 'B', '<test/>', 7, ?, 'passed', 'submitted', NOW(), '{}', NULL)"
        );
        $submission->execute([$this->supplierId, 'dphdp3', self::YEAR, 6, hash('sha256', 'syn-filed-vat')]);
        $submission->execute([$this->supplierId, 'dppdp9', self::YEAR, null, hash('sha256', 'syn-filed-income')]);

        $taxChange = $this->bySource($this->dryRun('set_posting_rule', 'invoice', [$docId],
            ['rule_key' => 'syn.sale_accrual', 'mode' => JournalRebookService::MODE_REWRITE_OR_REVERSE]))[$docId];
        self::assertSame(['skipped', 'period_filed'], [$taxChange['status'], $taxChange['reason_code']], json_encode($taxChange));
        self::assertStringContainsString('přiznání k DPH za 6/' . self::YEAR, (string) $taxChange['message']);

        $neutral = $this->bySource($this->dryRun('set_posting_rule', 'invoice', [$docId],
            ['rule_key' => 'syn.sale_604', 'mode' => JournalRebookService::MODE_REWRITE]))[$docId];
        self::assertSame('would_change', $neutral['status'], 'Přesun výnosu mezi účty bez daňového dopadu v podaném období projde, jako u Přeúčtovat.');
    }

    // ── rebook_account nad výběrem ───────────────────────────────────────────

    public function testRebookFromListSelectionTouchesOnlySelectedDocuments(): void
    {
        $a = $this->postedSaleInvoice();
        $b = $this->postedSaleInvoice();
        $dryId = $this->dryRun('rebook_account', 'invoice', [$a], ['from_account' => '602', 'to_account' => '604', 'side' => 'credit', 'mode' => 'rewrite']);
        self::assertSame([$a], array_keys($this->bySource($dryId)));
        $this->applyRun($dryId);
        self::assertContains('604', array_map('strval', array_keys($this->linesByAccountCode((int) $this->journal->findBySource($this->supplierId, 'invoice', $a)['id']))));
        self::assertContains('602', array_map('strval', array_keys($this->linesByAccountCode((int) $this->journal->findBySource($this->supplierId, 'invoice', $b)['id']))));
    }

    // ── delete ───────────────────────────────────────────────────────────────

    public function testDeleteFollowsSingleRecordRules(): void
    {
        $draft = $this->saleInvoice('', $this->client('Syntetický odběratel'), 100.0, 'invoice', 'draft');
        $posted = $this->postedSaleInvoice();

        $dryId = $this->dryRun('delete', 'invoice', [$draft, $posted], []);
        $dry = $this->bySource($dryId);
        self::assertSame('would_change', $dry[$draft]['status'], json_encode($dry[$draft]));
        self::assertSame('skipped', $dry[$posted]['status'], 'Vystavený zaúčtovaný doklad chrání retenční lhůta jako při smazání jednoho dokladu.');
        self::assertNotNull($dry[$posted]['reason_code']);
        self::assertSame(1, (int) $this->db->pdo()->query("SELECT COUNT(*) FROM invoices WHERE id = {$draft}")->fetchColumn(), 'Nanečisto se nic nesmaže.');
        self::assertNotNull($this->journal->findBySource($this->supplierId, 'invoice', $posted), 'Nanečisto se zápis nestornuje.');

        $applyId = $this->applyRun($dryId);
        self::assertSame('applied', $this->bySource($applyId)[$draft]['status']);
        self::assertSame(0, (int) $this->db->pdo()->query("SELECT COUNT(*) FROM invoices WHERE id = {$draft}")->fetchColumn());
        self::assertSame(1, (int) $this->db->pdo()->query("SELECT COUNT(*) FROM invoices WHERE id = {$posted}")->fetchColumn());
    }

    public function testDeleteJournalEntriesOpenDeletedLockedReversedOrSkipped(): void
    {
        // Storno zámkem zablokovaného zápisu jde na dnešek, proto zápis leží v loňském roce
        // zamčeném k 31. 3. (dnešek zamčený není).
        $year = (int) date('Y') - 1;
        $period = $this->periods->findForDate($this->supplierId, $year . '-01-20');
        $periodId = $period !== null ? (int) $period['id'] : $this->periods->create($this->supplierId, $year, $year . '-01-01', $year . '-12-31');
        $this->db->pdo()->prepare("UPDATE accounting_periods SET status = 'open' WHERE id = ?")->execute([$periodId]);
        $open = $this->manualEntry('518', '321', 100.0, '-06-20');
        $locked = $this->manualEntryAt('518', '321', 200.0, $year . '-01-20', $periodId);
        $this->db->pdo()->prepare('INSERT INTO accounting_supplier_settings (supplier_id, locked_until) VALUES (?, ?) ON DUPLICATE KEY UPDATE locked_until = VALUES(locked_until)')
            ->execute([$this->supplierId, $year . '-03-31']);

        $skip = $this->bySource($this->dryRun('delete', 'journal_entry', [$open, $locked], ['journal_fallback' => 'skip']));
        self::assertSame('would_change', $skip[$open]['status'], json_encode($skip[$open]));
        self::assertSame(['skipped', 'date_locked'], [$skip[$locked]['status'], $skip[$locked]['reason_code']]);
        self::assertNotNull($this->journal->find($open, $this->supplierId), 'Nanečisto se zápis nesmaže.');

        $dryId = $this->dryRun('delete', 'journal_entry', [$open, $locked], ['journal_fallback' => 'reverse']);
        $dry = $this->bySource($dryId);
        self::assertSame(['would_change', 'reverse', 'reversed_instead'], [$dry[$locked]['status'], $dry[$locked]['strategy'], $dry[$locked]['reason_code']], json_encode($dry[$locked]));
        self::assertNull($this->journal->find($locked, $this->supplierId)['reversed_by'] ?? null, 'Nanečisto se nestornuje.');

        $applied = $this->bySource($this->applyRun($dryId));
        self::assertSame('applied', $applied[$open]['status']);
        self::assertSame(['applied', 'reverse'], [$applied[$locked]['status'], $applied[$locked]['strategy']], json_encode($applied[$locked]));
        self::assertNull($this->journal->find($open, $this->supplierId));
        self::assertNotNull($this->journal->find($locked, $this->supplierId)['reversed_by'] ?? null);
    }

    // ── spuštění: práva, limit, firma, potvrzení ─────────────────────────────

    public function testStartEnforcesPermissionLimitTenantAndTypedConfirmation(): void
    {
        $own = $this->saleInvoice('', $this->client('Syntetický odběratel'), 100.0, 'invoice', 'draft');
        $body = ['dry_run' => true, 'operation' => 'delete', 'target' => 'invoice', 'selection' => ['ids' => [$own]], 'params' => []];

        [$status] = $this->callStart(['accounting' => 2, 'invoices' => 2, 'invoices.delete' => 2], $body);
        self::assertSame(403, $status, 'Bez práva Hromadné změny akci nespustí.');

        $rights = ['accounting' => 2, 'accounting.bulk_changes' => 2, 'invoices' => 2, 'invoices.delete' => 2];
        $foreign = $this->foreignInvoiceId();
        [$status, $resp] = $this->callStart($rights, ['selection' => ['ids' => [$own, $foreign]]] + $body);
        self::assertSame([422, 'foreign_ids'], [$status, $resp['error']['code'] ?? null], json_encode($resp));

        [$status, $resp] = $this->callStart($rights, ['selection' => ['ids' => range(1, BulkSelection::LIMIT + 1)]] + $body);
        self::assertSame([422, 'too_many_items'], [$status, $resp['error']['code'] ?? null]);

        [$status, $resp] = $this->callStart(['accounting' => 2, 'accounting.bulk_changes' => 2, 'invoices' => 2], $body);
        self::assertSame([409, 'operation_unavailable'], [$status, $resp['error']['code'] ?? null], 'Smazání vyžaduje i právo smazat fakturu.');

        $dryId = $this->dryRun('delete', 'invoice', [$own], []);
        [$status, $resp] = $this->callStart($rights, ['dry_run' => false, 'dry_run_job_id' => $dryId, 'excluded_item_ids' => [], 'confirm_count' => 2]);
        self::assertSame([422, 'confirm_count_mismatch'], [$status, $resp['error']['code'] ?? null]);
        self::assertSame(1, $resp['error']['expected'] ?? null);
    }

    public function testRebookAccountIsOnlyForCompanyAdmin(): void
    {
        $own = $this->saleInvoice('', $this->client('Syntetický odběratel'), 100.0, 'invoice', 'draft');
        $rights = ['accounting' => 2, 'accounting.bulk_changes' => 2, 'accounting.journal.post' => 2,
            'accounting.journal.bulk_repost' => 2, 'invoices' => 2, 'purchase_invoices' => 2];
        $body = ['dry_run' => true, 'operation' => 'rebook_account', 'target' => 'invoice', 'selection' => ['ids' => [$own]],
            'params' => ['from_account' => '602', 'to_account' => '604', 'side' => 'credit', 'mode' => 'rewrite']];

        [$status, $resp] = $this->callStart($rights, $body);
        self::assertSame([409, 'operation_unavailable'], [$status, $resp['error']['code'] ?? null], 'Právo z editoru rolí přeúčtování neotevře.');

        $operations = $this->container->get(BulkOperations::class);
        foreach (['invoice', 'purchase_invoice', 'journal_entry', 'invoice_item', 'purchase_invoice_item'] as $target) {
            $operation = $operations->get('rebook_account', $target);
            self::assertNotNull($operation->unavailableReason($this->roleRequest($rights, ''), $this->supplierId, $target), $target);
            self::assertNull($operation->unavailableReason($this->roleRequest(['accounting' => 2, 'invoices' => 2, 'purchase_invoices' => 2], 'admin'), $this->supplierId, $target), $target);
        }
        self::assertNull($operations->get('set_dimensions', 'invoice')->unavailableReason($this->roleRequest($rights, ''), $this->supplierId, 'invoice'),
            'Ostatní hromadné akce role s právem Hromadné změny dál smí.');
    }

    /** @param array<string,int> $rights */
    private function roleRequest(array $rights, string $systemKey): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('POST', '/api/accounting/bulk-actions/start')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant'])
            ->withAttribute('auth.effective_role', new EffectiveRole(9, 'Test', 'staff', true, $rights, $systemKey));
    }

    private function foreignInvoiceId(): int
    {
        $other = $this->otherSupplierId();
        $client = $this->client('Cizí odběratel');
        $this->db->pdo()->prepare('UPDATE clients SET supplier_id = ? WHERE id = ?')->execute([$other, $client]);
        $this->db->pdo()->prepare(
            'INSERT INTO invoices (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                currency_id, reverse_charge, total_without_vat, total_vat, total_with_vat, paid_total, status, vat_classification_code, created_by)
             VALUES (?, NULL, "invoice", ?, ?, ?, ?, ?, 0, 1, 0, 1, 0, "draft", "1", ?)'
        )->execute([$other, $client, self::YEAR . '-06-10', self::YEAR . '-06-10', self::YEAR . '-06-10', $this->currencyId, $this->userId]);
        return (int) $this->db->pdo()->lastInsertId();
    }
}
