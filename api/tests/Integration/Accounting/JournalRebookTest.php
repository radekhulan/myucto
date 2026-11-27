<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Action\Accounting\JournalRebookAction;
use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Service\Accounting\DocumentJournalSync;
use MyInvoice\Service\Accounting\DocumentRepostService;
use MyInvoice\Service\Accounting\JournalRebookService;
use MyInvoice\Tests\Integration\Accounting\Bank\BankPostingTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Hromadné přeúčtování (stránka Přeúčtovat doklady): běh nanečisto → ostrý běh podle
 * něj → vrácení. Každý doklad jde přes DocumentRepostService, takže tu se ověřuje
 * výběr, výměna účtu, pojistky proti změně mezi běhy a vrácení.
 */
#[Group('integration')]
final class JournalRebookTest extends BankPostingTestCase
{
    private JournalRebookService $rebook;
    private ImportJobRepository $jobs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rebook = $this->container->get(JournalRebookService::class);
        $this->jobs = $this->container->get(ImportJobRepository::class);
    }

    private function postedSaleInvoice(string $date = '-06-10'): int
    {
        $docId = $this->saleInvoice('SYN-JR-' . random_int(10000, 99999), $this->client('Syntetický odběratel'), 1000.0);
        $this->db->pdo()->prepare(
            'INSERT INTO invoice_items
                (invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, "Služba", 1, "ks", 1000, (SELECT MIN(id) FROM vat_rates), 0, 1000, 0, 1000, 0)'
        )->execute([$docId]);
        $this->posting->postDocument($this->supplierId, 'invoice', $docId,
            $this->posting->buildFromInvoice($this->supplierId, $docId),
            ['entry_date' => self::YEAR . $date, 'user_id' => $this->userId, 'posted' => true]);
        return $docId;
    }

    private function filter(array $over = []): array
    {
        return $over + [
            'from_account' => '602', 'to_account' => '604', 'side' => 'credit',
            'date_from' => self::YEAR . '-01-01', 'date_to' => self::YEAR . '-12-31',
            'source_types' => ['invoice'], 'mode' => JournalRebookService::MODE_REWRITE,
        ];
    }

    private function runJob(array $params): int
    {
        $jobId = $this->jobs->create($this->supplierId, JournalRebookService::SOURCE, $params, $this->userId);
        $this->rebook->run($jobId);
        return $jobId;
    }

    private function dryRun(array $filter): int
    {
        return $this->runJob(['kind' => 'dry_run'] + $this->rebook->normalizeFilter($this->supplierId, $filter));
    }

    private function applyRun(int $dryJobId, array $excluded = []): int
    {
        $dry = $this->jobs->find($dryJobId, $this->supplierId);
        return $this->runJob(array_merge((array) $dry['params'], [
            'kind' => 'apply', 'dry_run_job_id' => $dryJobId, 'excluded_entry_ids' => $excluded,
        ]));
    }

    /** @return array<string,array<string,mixed>> status => položky */
    private function items(int $jobId): array
    {
        $out = [];
        foreach ($this->rebook->items($this->supplierId, $jobId, null, 1, 200)['items'] as $item) {
            $out[$item['status']][] = $item;
        }
        return $out;
    }

    private function codes(string $sourceType, int $docId): array
    {
        $entry = $this->journal->findBySource($this->supplierId, $sourceType, $docId);
        self::assertNotNull($entry);
        return array_map('strval', array_keys($this->linesByAccountCode((int) $entry['id'])));
    }

    public function testDryRunChangesNothingAndApplyThenRollbackRoundTrips(): void
    {
        $a = $this->postedSaleInvoice();
        $b = $this->postedSaleInvoice('-07-10');

        $count = $this->rebook->count($this->supplierId, $this->rebook->normalizeFilter($this->supplierId, $this->filter()));
        self::assertGreaterThanOrEqual(2, $count['by_source']['invoice']);

        $dryId = $this->dryRun($this->filter(['date_from' => self::YEAR . '-06-01', 'date_to' => self::YEAR . '-07-31']));
        $dry = $this->items($dryId);
        self::assertCount(2, $dry['would_change'] ?? [], json_encode($dry));
        self::assertSame('replace', $dry['would_change'][0]['strategy']);
        self::assertContains('602', $this->codes('invoice', $a), 'Nanečisto nesmí nic zapsat.');

        $applyId = $this->applyRun($dryId);
        $job = $this->jobs->find($applyId, $this->supplierId);
        self::assertSame('completed', $job['status'], (string) $job['last_error']);
        self::assertSame(2, (int) $job['created_count']);
        foreach ([$a, $b] as $docId) {
            self::assertContains('604', $this->codes('invoice', $docId));
            self::assertNotContains('602', $this->codes('invoice', $docId));
        }
        $applied = $this->items($applyId)['applied'];
        self::assertSame(1, $applied[0]['items_synced']);

        // Další úprava faktury změnu nevrátí: nový účet je i na položce.
        $this->container->get(DocumentJournalSync::class)->repostForceEdit($this->supplierId, 'invoice', $a, ['user_id' => $this->userId]);
        self::assertContains('604', $this->codes('invoice', $a));

        $rollbackId = $this->runJob(['kind' => 'rollback', 'rollback_of_job_id' => $applyId]);
        $rolled = $this->items($rollbackId);
        // Doklad A se po přeúčtování upravil (force-edit přepsal zápis), proto se nevrací.
        self::assertSame('changed_after_apply', $rolled['failed'][0]['reason_code'] ?? null, json_encode($rolled));
        self::assertSame($a, $rolled['failed'][0]['source_id']);
        self::assertSame($b, $rolled['applied'][0]['source_id']);
        self::assertContains('602', $this->codes('invoice', $b));
        self::assertNotContains('604', $this->codes('invoice', $b));
    }

    public function testApplySkipsDocumentChangedAfterDryRunAndHonoursExclusions(): void
    {
        $a = $this->postedSaleInvoice();
        $b = $this->postedSaleInvoice();
        $c = $this->postedSaleInvoice();
        $dryId = $this->dryRun($this->filter(['date_from' => self::YEAR . '-06-10', 'date_to' => self::YEAR . '-06-10']));
        $entries = [];
        foreach ($this->items($dryId)['would_change'] as $item) $entries[$item['source_id']] = $item['entry_id'];

        $repost = $this->container->get(DocumentRepostService::class);
        $plan = $repost->plan($this->supplierId, 'invoice', $b);
        $lines = array_map(static fn (array $l): array => ['account_code' => $l['account_code'] === '602' ? '601' : $l['account_code']] + $l, $plan['lines']);
        $repost->repost($this->supplierId, 'invoice', $b, $lines, ['user_id' => $this->userId]);

        $applyId = $this->applyRun($dryId, [$entries[$c]]);
        $items = $this->items($applyId);
        $bySource = [];
        foreach ($items as $status => $list) foreach ($list as $item) $bySource[$item['source_id']] = [$status, $item['reason_code']];
        self::assertSame(['applied', null], $bySource[$a]);
        self::assertSame(['skipped', 'changed_after_dry_run'], $bySource[$b]);
        self::assertSame(['excluded', 'excluded_by_user'], $bySource[$c]);
        self::assertContains('601', $this->codes('invoice', $b));
        self::assertContains('602', $this->codes('invoice', $c));
    }

    public function testRewriteModeSkipsClosedPeriodAndCounterAccountIsNotRebooked(): void
    {
        $open = $this->postedSaleInvoice();
        $this->db->pdo()->prepare("UPDATE accounting_periods SET status = 'closed' WHERE id = ?")->execute([$this->periodId]);
        $dryId = $this->dryRun($this->filter(['date_from' => self::YEAR . '-06-10', 'date_to' => self::YEAR . '-06-10']));
        $item = $this->items($dryId)['skipped'][0] ?? null;
        self::assertSame('period_not_open', $item['reason_code'] ?? null);
        $this->db->pdo()->prepare("UPDATE accounting_periods SET status = 'open' WHERE id = ?")->execute([$this->periodId]);

        $dryId = $this->dryRun($this->filter(['from_account' => '311', 'to_account' => '315', 'side' => 'debit',
            'date_from' => self::YEAR . '-06-10', 'date_to' => self::YEAR . '-06-10']));
        $skipped = $this->items($dryId)['skipped'] ?? [];
        self::assertSame($open, $skipped[0]['source_id'] ?? null);
        // Pohledávka 311 je saldokontní účet dokladu, 315 ale pohledávkou faktury být nemůže
        // (úhrada by ji neuzavřela), proto se doklad vynechá s důvodem cílového účtu.
        self::assertSame('invalid_settlement_account', $skipped[0]['reason_code']);
        self::assertStringContainsString('315', (string) $skipped[0]['message']);
    }

    public function testBankPostingWithoutDocumentIsRebooked(): void
    {
        $tx = $this->transaction($this->statement(), -5000.0, ['description' => 'Výplata mzdy']);
        $this->service->postManual($this->supplierId, $tx, ['debit_account_code' => '331', 'credit_account_code' => '221'], $this->meta());

        $dryId = $this->dryRun($this->filter(['from_account' => '331', 'to_account' => '333', 'side' => 'debit',
            'source_types' => ['bank'], 'text' => 'mzdy']));
        self::assertCount(1, $this->items($dryId)['would_change'] ?? [], json_encode($this->items($dryId)));
        $applyId = $this->applyRun($dryId);
        self::assertSame('completed', $this->jobs->find($applyId, $this->supplierId)['status']);
        self::assertContains('333', $this->codes('bank', $tx));
        self::assertNotContains('331', $this->codes('bank', $tx));

        $dryId = $this->dryRun($this->filter(['from_account' => '221.100', 'to_account' => '395', 'side' => 'credit', 'source_types' => ['bank']]));
        self::assertSame('bank_invariant', ($this->items($dryId)['skipped'][0] ?? [])['reason_code'] ?? null);
    }

    public function testStartRequiresCompanyAdminAndMatchingDryRun(): void
    {
        $this->postedSaleInvoice();
        $action = $this->container->get(JournalRebookAction::class);
        $request = static fn (array $rights, ?string $systemKey = null) => new \MyInvoice\Security\EffectiveRole(9, 'Test', 'staff', true, $rights, $systemKey);

        $call = function (array $rights, array $body, ?string $systemKey = null) use ($action, $request): array {
            $req = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST', '/api/accounting/journal-rebook/start')
                ->withAttribute(\MyInvoice\Middleware\SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
                ->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant'])
                ->withAttribute('auth.effective_role', $request($rights, $systemKey))
                ->withParsedBody($body);
            $resp = $action->start($req, new \Slim\Psr7\Response());
            return [$resp->getStatusCode(), json_decode((string) $resp->getBody(), true)];
        };

        [$status] = $call(['accounting' => 2, 'accounting.journal.post' => 2], $this->filter() + ['dry_run' => true]);
        self::assertSame(403, $status);

        // Právo z editoru rolí přeúčtování neotevře, smí ho jen správce firmy.
        [$status] = $call(['accounting' => 2, 'accounting.journal.post' => 2, 'accounting.journal.bulk_repost' => 2], $this->filter() + ['dry_run' => true]);
        self::assertSame(403, $status);

        [$status, $body] = $call(
            ['accounting' => 2, 'accounting.journal.post' => 2],
            ['dry_run' => false, 'dry_run_job_id' => 999999999, 'excluded_entry_ids' => []],
            'admin',
        );
        self::assertSame(409, $status);
        self::assertSame('matching_dry_run_required', $body['error']['code'] ?? $body['code'] ?? null, json_encode($body));
    }

    public function testManualEntryWithInternalDocumentIsRebookedInPlaceAndRolledBack(): void
    {
        $map = $this->accounts->codeToIdMap($this->supplierId);
        $entryId = $this->journal->insert([
            'supplier_id' => $this->supplierId, 'period_id' => $this->periodId, 'entry_date' => self::YEAR . '-06-12',
            'document_no' => 'ID-SYN-1', 'description' => 'Přeúčtování zálohy', 'source_type' => 'manual', 'source_id' => null,
            'posted_at' => date('Y-m-d H:i:s'), 'posted_by' => $this->userId,
        ], [
            ['account_id' => $map['378']['id'], 'side' => 'debit', 'amount' => 700.0],
            ['account_id' => $map['395']['id'], 'side' => 'credit', 'amount' => 700.0],
        ]);
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO internal_documents (supplier_id, title, document_date, entry_date, amount, status, origin, document_no, journal_entry_id, posted_at)
                       VALUES (?, 'Syntetický interní doklad', ?, ?, 700, 'posted', 'draft', 'ID-SYN-1', ?, NOW())")
            ->execute([$this->supplierId, self::YEAR . '-06-12', self::YEAR . '-06-12', $entryId]);
        $docId = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO internal_document_lines (supplier_id, internal_document_id, position, account_code, side, amount)
                       VALUES (?, ?, 0, '378', 'debit', 700), (?, ?, 1, '395', 'credit', 700)")
            ->execute([$this->supplierId, $docId, $this->supplierId, $docId]);
        $lineIds = array_column($this->journal->linesForEntry($entryId, $this->supplierId), 'id');

        $filter = $this->filter(['from_account' => '378', 'to_account' => '335', 'side' => 'debit', 'source_types' => ['manual'],
            'date_from' => self::YEAR . '-06-12', 'date_to' => self::YEAR . '-06-12']);
        $dryId = $this->dryRun($filter);
        $dry = $this->items($dryId)['would_change'] ?? [];
        self::assertCount(1, $dry, json_encode($this->items($dryId)));
        self::assertSame($entryId, $dry[0]['source_id']);

        $applyId = $this->applyRun($dryId);
        self::assertSame('completed', $this->jobs->find($applyId, $this->supplierId)['status'], (string) $this->jobs->find($applyId, $this->supplierId)['last_error']);
        $codes = array_map('strval', array_keys($this->linesByAccountCode($entryId)));
        self::assertContains('335', $codes);
        self::assertNotContains('378', $codes);
        self::assertSame($lineIds, array_column($this->journal->linesForEntry($entryId, $this->supplierId), 'id'), 'Řádky zůstávají tytéž.');
        $docCodes = $pdo->query('SELECT account_code FROM internal_document_lines WHERE internal_document_id = ' . $docId . ' ORDER BY position')->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(['335', '395'], $docCodes);

        $rollbackId = $this->runJob(['kind' => 'rollback', 'rollback_of_job_id' => $applyId]);
        self::assertCount(1, $this->items($rollbackId)['applied'] ?? [], json_encode($this->items($rollbackId)));
        self::assertContains('378', array_map('strval', array_keys($this->linesByAccountCode($entryId))));
        self::assertSame(['378', '395'], $pdo->query('SELECT account_code FROM internal_document_lines WHERE internal_document_id = ' . $docId . ' ORDER BY position')->fetchAll(\PDO::FETCH_COLUMN));

        $this->db->pdo()->prepare('INSERT INTO accounting_supplier_settings (supplier_id, locked_until) VALUES (?, ?) ON DUPLICATE KEY UPDATE locked_until = VALUES(locked_until)')
            ->execute([$this->supplierId, self::YEAR . '-06-30']);
        $lockedDry = $this->dryRun($this->filter(['from_account' => '395', 'to_account' => '343', 'side' => 'credit', 'source_types' => ['manual'],
            'date_from' => self::YEAR . '-06-12', 'date_to' => self::YEAR . '-06-12']));
        self::assertSame('date_locked', ($this->items($lockedDry)['skipped'][0] ?? [])['reason_code'] ?? null, 'V zamčeném datu jen daňově neutrální přesun.');
    }
}
