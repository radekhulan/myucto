<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\NonprofitTaxAssessmentRepository;
use MyInvoice\Service\Accounting\Dimension\SystemDimensionCatalog;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentException;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentExporter;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentGuard;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\TestCase;

final class NonprofitTaxAssessmentTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PDO $pdo;
    private NonprofitTaxAssessmentService $service;
    private NonprofitTaxAssessmentGuard $guard;
    private NonprofitTaxAssessmentRepository $repository;
    private NonprofitTaxAssessmentExporter $exporter;
    private int $supplier;
    private int $period;
    private int $actor;
    private int $kind;
    private array $catalog;
    private array $accounts;
    private ?Connection $otherDb = null;
    private bool $committed = false;
    private ?int $snapshotIsolation = null;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $this->name() === 'testHistoricalExportDoesNotHideUnexpectedDatabaseError'
            ? Connection::withoutSharedTestConnection(static fn () => $container->get(Connection::class))
            : $container->get(Connection::class);
        $this->pdo = $this->db->pdo();
        self::assertStringEndsWith('_test', (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn());
        $this->pdo->beginTransaction();
        $seed = (int) $this->pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        self::assertGreaterThan(0, $seed, 'Synthetic seed required');
        $this->actor = (int) $this->pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        $this->supplier = $this->createIsolatedSupplier($this->pdo, $seed);
        $this->pdo->prepare("UPDATE supplier SET accounting_mode = 'double_entry' WHERE id = ?")->execute([$this->supplier]);
        $this->period = $container->get(AccountingPeriodRepository::class)->create($this->supplier, 2026, '2026-01-01', '2026-12-31');
        $this->pdo->prepare("INSERT INTO supplier_accounting_frameworks (supplier_id,effective_from,accounting_framework) VALUES (?,'2026-01-01','cz_504')")->execute([$this->supplier]);
        $this->catalog = (new SystemDimensionCatalog($this->db))->ensureForFramework($this->supplier, '2026-01-01');
        $this->pdo->prepare('INSERT INTO dimension_values (type_id,supplier_id,code,name) VALUES (?,?,?,?)')
            ->execute([$this->catalog['activity_kind']['id'], $this->supplier, 'RENT', 'Syntetický nájem']);
        $this->kind = (int) $this->pdo->lastInsertId();
        foreach (['221' => 'asset', '518' => 'expense', '591' => 'expense', '602' => 'revenue', '682' => 'revenue'] as $code => $type) {
            $this->pdo->prepare('INSERT INTO chart_of_accounts (supplier_id,account_code,name,account_type) VALUES (?,?,?,?)')
                ->execute([$this->supplier, (string) $code, 'Synthetic ' . $code, $type]);
            $this->accounts[$code] = (int) $this->pdo->lastInsertId();
        }
        $this->service = $container->get(NonprofitTaxAssessmentService::class);
        $this->guard = $container->get(NonprofitTaxAssessmentGuard::class);
        $this->repository = $container->get(NonprofitTaxAssessmentRepository::class);
        $this->exporter = $container->get(NonprofitTaxAssessmentExporter::class);
    }

    protected function tearDown(): void
    {
        if ($this->otherDb !== null) {
            if ($this->otherDb->pdo()->inTransaction()) $this->otherDb->pdo()->rollBack();
            $this->otherDb->close();
        }
        if (isset($this->pdo) && $this->pdo->inTransaction()) $this->pdo->rollBack();
        if ($this->snapshotIsolation !== null) $this->pdo->exec('SET SESSION innodb_snapshot_isolation = ' . $this->snapshotIsolation);
        if ($this->committed) {
            $this->pdo->prepare('DELETE FROM activity_log WHERE supplier_id = ?')->execute([$this->supplier]);
            $this->pdo->prepare('DELETE FROM journal_entries WHERE supplier_id = ?')->execute([$this->supplier]);
            $this->pdo->prepare('DELETE FROM supplier WHERE id = ?')->execute([$this->supplier]);
        }
    }

    private function post(string $code, string $amount, bool $dimensions = true, string $origin = 'explicit', bool $red = false, string $source = 'manual', ?int $sourceId = null, string $date = '2026-06-01'): int
    {
        $expense = str_starts_with($code, '5');
        $this->pdo->prepare('INSERT INTO journal_entries (supplier_id,period_id,entry_date,source_type,source_id) VALUES (?,?,?,?,?)')
            ->execute([$this->supplier, $this->period, $date, $source, $sourceId]);
        $entry = (int) $this->pdo->lastInsertId();
        $line = 0;
        foreach ([[$code, $expense ? 'debit' : 'credit'], ['221', $expense ? 'credit' : 'debit']] as $no => [$account, $side]) {
            $this->pdo->prepare('INSERT INTO journal_entry_lines (supplier_id,entry_id,line_no,account_id,side,amount,is_red_storno) VALUES (?,?,?,?,?,?,?)')
                ->execute([$this->supplier, $entry, $no, $this->accounts[$account], $side, $amount, (int) $red]);
            if ($no === 0) {
                $line = (int) $this->pdo->lastInsertId();
                if ($dimensions) $this->assign($line, $origin);
            }
        }
        $this->pdo->prepare('UPDATE journal_entries SET posted_at = NOW() WHERE id = ?')->execute([$entry]);
        return $line;
    }

    private function assign(int $line, string $origin = 'explicit'): void
    {
        foreach (['activity' => $this->catalog['activity']['values']['main'], 'activity_kind' => $this->kind,
            'tax_regime' => $this->catalog['tax_regime']['values']['exempt']] as $axis => $value) {
            $this->pdo->prepare('INSERT INTO journal_entry_line_dimensions (supplier_id,line_id,dimension_type_id,dimension_value_id,assignment_origin) VALUES (?,?,?,?,?)')
                ->execute([$this->supplier, $line, $this->catalog[$axis]['id'], $value, $origin]);
        }
    }

    private function assessment(array $snapshot): array
    {
        $out = ['profile' => ['kind' => 'narrow_vpp', 'evidence' => 'Syntetické stanovy a posouzení §17a'],
            'activities' => [$this->kind => ['evidence' => 'Doložená nájemní činnost']], 'decisions' => [], 'funding' => []];
        foreach ($snapshot['sources'] as $source) {
            $out['decisions'][$source['id']] = ['title' => $source['kind'] === 'revenue' ? 'rent' : 'cost',
                'evidence' => 'Syntetický podklad řádku ' . $source['id'], 'legal_basis' => 'zdp_24_1', 'deductible_cents' => $source['amount_cents']];
        }
        return $out;
    }

    private function confirmation(string $key = 'confirm'): array
    {
        $snapshot = $this->service->read($this->supplier, $this->period)['snapshot'];
        return $this->service->confirm($this->supplier, $this->period, $this->assessment($snapshot), $snapshot['input_hash'], 'Kontrola podkladů', $key, $this->actor);
    }

    private function refused(string $code, callable $work): void
    {
        try { $work(); self::fail('Expected refusal ' . $code); }
        catch (NonprofitTaxAssessmentException $e) { self::assertSame($code, $e->errorCode); }
    }

    private function state(): array
    {
        $out = [];
        foreach (['journal_entries', 'journal_entry_lines', 'journal_entry_line_dimensions', 'nonprofit_tax_assessments', 'activity_log'] as $table) {
            $stmt = $this->pdo->prepare("SELECT * FROM {$table} WHERE supplier_id = ?");
            $stmt->execute([$this->supplier]);
            $out[$table] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        return $out;
    }

    public function testPreviewAndExportAreReadonlyAndMainExemptDimensionDoesNotOverrideTaxableRent(): void
    {
        $this->post('602', '120.00');
        $this->post('518', '80.00');
        $before = $this->state();
        $snapshot = $this->service->read($this->supplier, $this->period)['snapshot'];
        $assessment = $this->assessment($snapshot);
        $preview = $this->service->preview($this->supplier, $this->period, $assessment);
        self::assertSame([], $snapshot['findings']);
        self::assertSame(4000, $preview['result']['assessed_result_cents']);
        self::assertSame('exempt', $preview['result']['rows'][0]['tax_regime']);
        self::assertSame('subject', $preview['result']['rows'][0]['classification']['regime']);
        self::assertSame($before, $this->state());
        $confirmed = $this->service->confirm($this->supplier, $this->period, $assessment, $snapshot['input_hash'], 'Kontrola', 'native', $this->actor);
        self::assertSame($confirmed, $this->service->confirm($this->supplier, $this->period, $assessment, $snapshot['input_hash'], 'Kontrola', 'native', $this->actor));
        self::assertTrue($this->guard->closingCheck($this->supplier, $this->period)['complete']);
        $after = $this->state();
        $export = $this->exporter->export($this->supplier, $confirmed['id']);
        self::assertTrue($export['current_confirmed']);
        self::assertFalse($export['dppo_supported']);
        self::assertSame($after, $this->state());
        foreach (['journal_entries', 'journal_entry_lines', 'journal_entry_line_dimensions', 'activity_log'] as $table) self::assertSame($before[$table], $after[$table]);
    }

    public function testPostingMakesPreviewAndConfirmedAssessmentStale(): void
    {
        $this->post('602', '120.00');
        $confirmed = $this->confirmation();
        $this->post('518', '20.00');
        $this->refused('nonprofit_tax_sources_changed', fn () => $this->service->confirm($this->supplier, $this->period,
            $confirmed['assessment'], $confirmed['input_hash'], 'Zastaralé', 'stale', $this->actor));
        self::assertFalse($this->guard->closingCheck($this->supplier, $this->period)['complete']);
        self::assertFalse($this->exporter->export($this->supplier, $confirmed['id'])['current_confirmed']);
        self::assertCount(1, $this->repository->history($this->supplier, $this->period));
    }

    public function testHistoricalExportSurvivesCurrentJointMismatchWithoutChangingFrozenResult(): void
    {
        $source = $this->post('518', '100.00', false);
        $this->joint($source);
        $confirmed = $this->confirmation();
        $this->pdo->prepare('UPDATE journal_entry_lines SET amount = 90.00 WHERE id = ?')->execute([$source]);
        $before = $this->state();
        $export = $this->exporter->export($this->supplier, $confirmed['id']);
        self::assertFalse($export['current_confirmed']);
        self::assertFalse($export['current_check']['complete']);
        self::assertSame('nonprofit_tax_joint_changed', $export['current_check']['findings'][0]['code']);
        self::assertSame($confirmed['result'], $export['revision']['result']);
        self::assertSame([$confirmed], $this->service->history($this->supplier, $this->period));
        self::assertSame($before, $this->state());
    }

    public function testHistoricalExportDoesNotHideUnexpectedDatabaseError(): void
    {
        $confirmed = $this->confirmation();
        $previous = $this->pdo->getAttribute(PDO::ATTR_STATEMENT_CLASS);
        $this->pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [NonprofitCurrentCheckFailureStatement::class]);
        try {
            $this->exporter->export($this->supplier, $confirmed['id']);
            self::fail('Unexpected database failure must propagate');
        } catch (\PDOException $e) {
            self::assertSame('synthetic-current-check-failure', $e->getMessage());
        } finally {
            $this->pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, $previous);
        }
    }

    public function testMissingOrUnknownSemanticsCannotBeConfirmed(): void
    {
        $this->post('602', '120.00', true, 'unknown');
        $snapshot = $this->service->read($this->supplier, $this->period)['snapshot'];
        self::assertContains('unknown_activity_origin', array_column($snapshot['findings'], 'code'));
        $this->refused('nonprofit_tax_sources_incomplete', fn () => $this->service->preview($this->supplier, $this->period, $this->assessment($snapshot)));
        self::assertSame([], $this->repository->history($this->supplier, $this->period));
    }

    public function testIndependentSplitsRequireJointEvidence(): void
    {
        $line = $this->post('518', '100.00', false);
        $this->pdo->prepare('INSERT INTO journal_entry_line_dimension_splits (supplier_id,line_id,dimension_type_id,dimension_value_id,share,assignment_origin) VALUES (?,?,?,?,1,?)')
            ->execute([$this->supplier, $line, $this->catalog['activity']['id'], $this->catalog['activity']['values']['main'], 'explicit']);
        $snapshot = $this->service->read($this->supplier, $this->period)['snapshot'];
        self::assertContains('joint_allocation_required', array_column($snapshot['findings'], 'code'));
        $this->refused('nonprofit_tax_sources_incomplete', fn () => $this->service->preview($this->supplier, $this->period, $this->assessment($snapshot)));
    }

    public function testClosedPeriodCannotConfirmOrWithdraw(): void
    {
        $this->post('602', '120.00');
        $confirmed = $this->confirmation();
        $this->pdo->prepare("UPDATE accounting_periods SET status = 'closed' WHERE id = ?")->execute([$this->period]);
        $this->refused('nonprofit_tax_period_locked', fn () => $this->confirmation('closed'));
        $this->refused('nonprofit_tax_period_locked', fn () => $this->service->withdraw($this->supplier, $this->period, $confirmed['id'], 'Odvolání', 'withdraw', $this->actor));
        self::assertTrue($this->guard->closingCheck($this->supplier, $this->period)['complete']);
    }

    public function testTenantBoundariesAndImmutableHistory(): void
    {
        $this->post('602', '120.00');
        $confirmed = $this->confirmation();
        $this->refused('nonprofit_tax_assessment_not_found', fn () => $this->repository->find(1, $confirmed['id']));
        $this->refused('nonprofit_tax_period_not_found', fn () => $this->service->read(1, $this->period));
        foreach (['UPDATE nonprofit_tax_assessments SET reason = ? WHERE id = ?', 'DELETE FROM nonprofit_tax_assessments WHERE reason = ? AND id = ?'] as $sql) {
            try { $this->pdo->prepare($sql)->execute([$confirmed['reason'], $confirmed['id']]); self::fail('Immutable history must reject mutation'); }
            catch (\PDOException $e) { self::assertStringContainsString('nonprofit_tax_assessment_immutable', $e->getMessage()); }
        }
        $withdrawn = $this->service->withdraw($this->supplier, $this->period, $confirmed['id'], 'Nové podklady', 'withdraw', $this->actor);
        self::assertSame(2, $withdrawn['revision_no']);
        self::assertSame($confirmed['id'], $withdrawn['previous_id']);
        self::assertFalse($this->guard->closingCheck($this->supplier, $this->period)['complete']);
        self::assertSame($confirmed, $this->repository->find($this->supplier, $confirmed['id']));
    }

    public function testGiftElectionSurvivesWithdrawal(): void
    {
        $this->post('682', '250.00');
        $snapshot = $this->service->read($this->supplier, $this->period)['snapshot'];
        $assessment = $this->assessment($snapshot);
        $id = $snapshot['sources'][0]['id'];
        $assessment['decisions'][$id] = ['title' => 'gift', 'evidence' => 'Darovací smlouva',
            'facts' => ['claim_exemption' => true, 'qualifying_purpose' => true, 'election_evidence' => 'Potvrzená volba']];
        $confirmed = $this->service->confirm($this->supplier, $this->period, $assessment, $snapshot['input_hash'], 'Volba osvobození', 'gift', $this->actor);
        $withdrawn = $this->service->withdraw($this->supplier, $this->period, $confirmed['id'], 'Doplnění jiných podkladů', 'withdraw', $this->actor);
        $assessment['decisions'][$id]['facts'] = ['claim_exemption' => false, 'income_treatment' => 'nonbusiness_activity', 'income_attribution_evidence' => 'Původní obecná podpora'];
        $this->refused('nonprofit_tax_gift_election_locked', fn () => $this->service->confirm($this->supplier, $this->period, $assessment, $snapshot['input_hash'], 'Změna volby', 'gift-changed', $this->actor, $withdrawn['id']));
    }

    public function testSignedReversalAndTechnicalTaxEntriesDoNotDistortPretaxBridge(): void
    {
        $this->post('602', '100.01');
        $this->post('602', '20.01', red: true);
        $this->post('591', '19.00', false);
        $closing = $this->post('602', '80.00', false, source: 'closing', sourceId: $this->period);
        $reversal = $this->post('602', '80.00', false, red: true);
        $this->pdo->prepare('UPDATE journal_entries SET reversed_by = (SELECT entry_id FROM journal_entry_lines WHERE id = ?) WHERE id = (SELECT entry_id FROM journal_entry_lines WHERE id = ?)')->execute([$reversal, $closing]);
        $snapshot = $this->service->read($this->supplier, $this->period)['snapshot'];
        self::assertCount(2, $snapshot['sources']);
        self::assertSame(8000, $snapshot['accounting_result_cents']);
        self::assertSame(8000, $this->service->preview($this->supplier, $this->period, $this->assessment($snapshot))['result']['assessed_result_cents']);
    }

    public function testHistorical500DoesNotEnterNewAssessmentWorkflow(): void
    {
        $this->pdo->prepare("UPDATE supplier_accounting_frameworks SET accounting_framework = 'cz_500' WHERE supplier_id = ?")->execute([$this->supplier]);
        $before = $this->state();
        self::assertSame(['applicable' => false, 'complete' => true, 'assessment_id' => null, 'findings' => []], $this->guard->closingCheck($this->supplier, $this->period));
        $this->refused('nonprofit_tax_framework_unsupported', fn () => $this->service->read($this->supplier, $this->period));
        self::assertSame($before, $this->state());
    }

    private function joint(int $sourceLine): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM journal_entry_lines WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$sourceLine, $this->supplier]);
        $source = $stmt->fetch(PDO::FETCH_ASSOC);
        $source['dimensions'] = $source['dimension_origins'] = [];
        $stmt = $this->pdo->prepare('SELECT * FROM journal_entry_line_dimensions WHERE line_id = ? AND supplier_id = ?');
        $stmt->execute([$sourceLine, $this->supplier]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $dimension) {
            $type = (int) $dimension['dimension_type_id'];
            $source['dimensions'][$type] = (int) $dimension['dimension_value_id'];
            $source['dimension_origins'][$type] = ['origin' => $dimension['assignment_origin'], 'ref' => $dimension['assignment_origin_ref']];
        }
        $types = array_map(static fn (array $type): int => $type['id'], $this->catalog);
        $targets = [];
        $firstPart = bcmul($source['amount'], '0.6', 2);
        foreach ([[$firstPart, 'main'], [bcsub($source['amount'], $firstPart, 2), 'economic']] as [$amount, $activity]) {
            $target = $source;
            $target['amount'] = $amount;
            $target['dimensions'] = [$types['activity'] => $this->catalog['activity']['values'][$activity],
                $types['activity_kind'] => $this->kind, $types['tax_regime'] => $this->catalog['tax_regime']['values']['subject']];
            $targets[] = $target;
        }
        $plan = ['sources' => [['source_line_id' => $sourceLine,
            'compensation' => \MyInvoice\Service\Accounting\JournalLineAmount::reversal($source), 'targets' => $targets]]];
        $stmt = $this->pdo->prepare('SELECT id, semantic_key, code, name FROM dimension_values WHERE supplier_id = ? ORDER BY id');
        $stmt->execute([$this->supplier]);
        $semantics = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), null, 'id');
        $keyVersion = \MyInvoice\Service\Accounting\Allocation\JointAllocationKeyVersion::normalize([
            'valid_from' => '2026-01-01', 'valid_to' => '2027-01-01', 'basis' => 'fixed', 'explanation' => 'Synthetic evidence',
            'cells' => [
                ['activity' => $this->catalog['activity']['values']['main'], 'activity_kind' => $this->kind,
                    'tax_regime' => $this->catalog['tax_regime']['values']['subject'], 'weight' => '3'],
                ['activity' => $this->catalog['activity']['values']['economic'], 'activity_kind' => $this->kind,
                    'tax_regime' => $this->catalog['tax_regime']['values']['subject'], 'weight' => '2'],
            ],
        ]);
        $hash = $keyVersion['content_hash'];
        $snapshot = ['supplier_id' => $this->supplier, 'target_date' => '2026-06-30',
            'period_id' => $this->period, 'key' => $keyVersion, 'source_lines' => [$source],
            'plan' => $plan, 'type_ids' => $types, 'semantic_values' => $semantics];
        $this->pdo->prepare('INSERT INTO joint_allocation_keys (supplier_id,code,name) VALUES (?,?,?)')->execute([$this->supplier, 'KEY-' . $sourceLine, 'Synthetic joint key']);
        $key = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO joint_allocation_key_versions (supplier_id,key_id,version_no,valid_from,valid_to,basis,explanation,rounding_policy,cells_json,content_hash)
            VALUES (?,?,1,'2026-01-01','2027-01-01','fixed','Synthetic evidence','half_up_largest_weight_v1',?,?)")
            ->execute([$this->supplier, $key, \MyInvoice\Service\Accounting\Allocation\JointAllocationKeyVersion::encodeCells($keyVersion), $hash]);
        $version = (int) $this->pdo->lastInsertId();
        $snapshot['key']['id'] = $snapshot['key_version_id'] = $version;
        $snapshot['preview_hash'] = \MyInvoice\Service\Accounting\Allocation\JointAllocationKeyVersion::hash($snapshot);
        $this->pdo->prepare("INSERT INTO joint_allocation_batches (supplier_id,key_version_id,target_date,reason,idempotency_key,request_hash,preview_hash,snapshot_json)
            VALUES (?,?,'2026-06-30','Synthetic joint allocation',?,?,?,?)")
            ->execute([$this->supplier, $version, 'batch-' . $sourceLine, $hash, $snapshot['preview_hash'],
                \MyInvoice\Service\Accounting\Allocation\JointAllocationKeyVersion::encodeJson($snapshot)]);
        $batch = (int) $this->pdo->lastInsertId();
        $lines = \MyInvoice\Service\Accounting\Allocation\JointAllocationJournalProof::materializedLines($plan, $batch, $types);
        $entry = $this->jointEntry($lines, '2026-06-30');
        $this->pdo->prepare('UPDATE joint_allocation_batches SET journal_entry_id = ? WHERE id = ?')->execute([$entry, $batch]);
        return ['batch' => $batch, 'lines' => $lines, 'entry' => $entry];
    }

    private function jointEntry(array $lines, string $date): int
    {
        $this->pdo->prepare("INSERT INTO journal_entries (supplier_id,period_id,entry_date,source_type) VALUES (?,?,?,'manual')")->execute([$this->supplier, $this->period, $date]);
        $entry = (int) $this->pdo->lastInsertId();
        foreach ($lines as $no => $line) {
            $this->pdo->prepare('INSERT INTO journal_entry_lines (supplier_id,entry_id,line_no,account_id,side,amount,is_red_storno) VALUES (?,?,?,?,?,?,?)')
                ->execute([$this->supplier, $entry, $no, $line['account_id'], $line['side'], $line['amount'], (int) !empty($line['is_red_storno'])]);
            $id = (int) $this->pdo->lastInsertId();
            foreach ($line['dimensions'] ?? [] as $type => $value) {
                $origin = $line['dimension_origins'][$type] ?? ['origin' => 'unknown', 'ref' => null];
                $this->pdo->prepare('INSERT INTO journal_entry_line_dimensions (supplier_id,line_id,dimension_type_id,dimension_value_id,assignment_origin,assignment_origin_ref) VALUES (?,?,?,?,?,?)')
                    ->execute([$this->supplier, $id, $type, $value, $origin['origin'], $origin['ref']]);
            }
        }
        $this->pdo->prepare('UPDATE journal_entries SET posted_at = NOW() WHERE id = ?')->execute([$entry]);
        return $entry;
    }

    public function testJointCellsNeutralizeOnlyProvenPoolAndCancellationReopensMissingEvidence(): void
    {
        $source = $this->post('518', '100.00', false);
        $batch = $this->joint($source);
        $snapshot = $this->service->read($this->supplier, $this->period)['snapshot'];
        self::assertSame([], $snapshot['findings']);
        self::assertCount(2, $snapshot['sources']);
        self::assertCount(2, $snapshot['neutral_line_ids']);
        self::assertSame([6000, 4000], array_column($snapshot['sources'], 'amount_cents'));
        self::assertSame(['main', 'economic'], array_column($snapshot['sources'], 'activity'));
        self::assertSame(-10000, $this->service->preview($this->supplier, $this->period, $this->assessment($snapshot))['result']['assessed_result_cents']);
        $cancelEntry = $this->jointEntry(array_map(\MyInvoice\Service\Accounting\JournalLineAmount::reversal(...), $batch['lines']), '2026-07-01');
        $this->pdo->prepare("INSERT INTO joint_allocation_cancellations (supplier_id,batch_id,target_date,reason,idempotency_key,request_hash,reversal_entry_id)
            VALUES (?,?,'2026-07-01','Synthetic cancellation','cancel',?,?)")->execute([$this->supplier, $batch['batch'], str_repeat('c', 64), $cancelEntry]);
        $cancelled = $this->service->read($this->supplier, $this->period)['snapshot'];
        self::assertNotEmpty($cancelled['findings']);
        self::assertSame(-10000, $cancelled['accounting_result_cents']);
        $this->refused('nonprofit_tax_sources_incomplete', fn () => $this->service->preview($this->supplier, $this->period, $this->assessment($cancelled)));
    }

    public function testJointTargetsRetainUnderlyingPurchaseTaxRestriction(): void
    {
        $this->pdo->prepare("INSERT INTO clients (supplier_id,company_name,street,city,zip,country_id,main_email,language,currency_default_id,is_customer,is_vendor)
            SELECT ?, 'Synthetic vendor','Test','Test','10000',country_id,'vendor@example.invalid','cs',default_currency_id,0,1 FROM supplier WHERE id = ?")
            ->execute([$this->supplier, $this->supplier]);
        $vendor = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO purchase_invoices (supplier_id,vendor_id,vendor_invoice_number,issue_date,due_date,received_at,currency_id,vendor_snapshot,total_with_vat,status,created_by,tax_deductible)
            SELECT ?,?,'SYNTHETIC-J6','2026-06-01','2026-06-01','2026-06-01',default_currency_id,'{}',100,'received',?,0 FROM supplier WHERE id = ?")
            ->execute([$this->supplier, $vendor, $this->actor, $this->supplier]);
        $purchase = (int) $this->pdo->lastInsertId();
        $source = $this->post('518', '100.00', false, source: 'purchase_invoice', sourceId: $purchase);
        $this->joint($source);
        $snapshot = $this->service->read($this->supplier, $this->period)['snapshot'];
        self::assertSame([true, true], array_column($snapshot['sources'], 'mandatory_non_deductible'));
        self::assertSame([$purchase, $purchase], array_map(static fn (array $row): int => (int) $row['origin']['id'], $snapshot['sources']));
        $assessment = $this->assessment($snapshot);
        $this->refused('nonprofit_tax_facts_incomplete', fn () => $this->service->preview($this->supplier, $this->period, $assessment));
        foreach ($assessment['decisions'] as &$decision) $decision['deductible_cents'] = 0;
        unset($decision);
        self::assertSame(0, $this->service->preview($this->supplier, $this->period, $assessment)['result']['assessed_result_cents']);
    }

    public function testChangedJointDimensionsCannotReuseFrozenSemanticsAsCurrentProof(): void
    {
        $source = $this->post('518', '100.00', false);
        $this->joint($source);
        $snapshot = $this->service->read($this->supplier, $this->period)['snapshot'];
        $line = $snapshot['sources'][0]['line_id'];
        $this->pdo->prepare("UPDATE journal_entry_line_dimensions SET assignment_origin_ref = 'other' WHERE line_id = ?")->execute([$line]);
        $snapshot = $this->service->read($this->supplier, $this->period)['snapshot'];
        self::assertContains('joint_dimensions_changed', array_column($snapshot['findings'], 'code'));
    }

    public function testCrossYearJointSourceIsMetadataWithoutAddingPriorYearAmount(): void
    {
        $source = $this->post('518', '100.00');
        $priorPeriod = (new AccountingPeriodRepository($this->db))->create($this->supplier, 2025, '2025-01-01', '2025-12-31');
        $this->pdo->prepare("UPDATE supplier_accounting_frameworks SET effective_from = '2025-01-01' WHERE supplier_id = ?")->execute([$this->supplier]);
        $this->pdo->prepare("UPDATE journal_entries SET period_id = ?, entry_date = '2025-12-01' WHERE id = (SELECT entry_id FROM journal_entry_lines WHERE id = ?)")->execute([$priorPeriod, $source]);
        $this->joint($source);
        $snapshot = $this->service->read($this->supplier, $this->period)['snapshot'];
        self::assertSame([], $snapshot['findings']);
        self::assertCount(3, $snapshot['sources']);
        self::assertSame([-10000, 6000, 4000], array_column($snapshot['sources'], 'amount_cents'));
        self::assertSame(0, $snapshot['accounting_result_cents']);
        self::assertSame(0, $this->service->preview($this->supplier, $this->period, $this->assessment($snapshot))['result']['assessed_result_cents']);
        self::assertSame('2025-12-01', $snapshot['source_references'][$source]['line']['entry_date']);
        $this->pdo->prepare('UPDATE journal_entry_lines SET amount = 90.00 WHERE id = ?')->execute([$source]);
        $this->refused('nonprofit_tax_joint_changed', fn () => $this->service->read($this->supplier, $this->period));
    }

    public function testNestedJointAllocationRetainsOriginalTaxOrigin(): void
    {
        $source = $this->post('518', '100.00', false);
        $first = $this->joint($source);
        $snapshot = $this->service->read($this->supplier, $this->period)['snapshot'];
        $target = $snapshot['sources'][0]['line_id'];
        $this->joint($target);
        $snapshot = $this->service->read($this->supplier, $this->period)['snapshot'];
        self::assertSame([], $snapshot['findings']);
        self::assertCount(3, $snapshot['sources']);
        self::assertSame([4000, 3600, 2400], array_column($snapshot['sources'], 'amount_cents'));
        self::assertSame([$source, $source, $source], array_map(static fn (array $row): int => $row['origin']['line_id'], $snapshot['sources']));
        self::assertSame(-10000, $this->service->preview($this->supplier, $this->period, $this->assessment($snapshot))['result']['assessed_result_cents']);
    }

    public function testPeriodCannotDeleteEvidenceButWholeSupplierCascadeCanCleanItsOwnRows(): void
    {
        $confirmed = $this->confirmation();
        $this->service->withdraw($this->supplier, $this->period, $confirmed['id'], 'Doplnění', 'withdraw', $this->actor);
        try {
            $this->pdo->prepare('DELETE FROM accounting_periods WHERE id = ? AND supplier_id = ?')->execute([$this->period, $this->supplier]);
            self::fail('A period with assessment must remain');
        } catch (\PDOException $e) {
            self::assertSame('23000', $e->getCode());
        }
        $this->pdo->prepare('DELETE FROM supplier WHERE id = ?')->execute([$this->supplier]);
        self::assertSame([], $this->repository->history($this->supplier, $this->period));
    }

    public function testConfirmationReadsCurrentLedgerAfterEarlierCallerSnapshot(): void
    {
        $this->post('602', '100.00');
        $closing = $this->post('602', '100.00', false, source: 'closing', sourceId: $this->period);
        $old = $this->service->read($this->supplier, $this->period)['snapshot'];
        $this->pdo->commit();
        $this->committed = true;
        $this->snapshotIsolation = (int) $this->pdo->query('SELECT @@innodb_snapshot_isolation')->fetchColumn();
        $this->pdo->exec('SET SESSION innodb_snapshot_isolation = 0');
        $other = Connection::withoutSharedTestConnection(static fn () => Bootstrap::buildContainer());
        $this->otherDb = Connection::withoutSharedTestConnection(fn () => $other->get(Connection::class));
        $this->pdo->beginTransaction();
        $oldCount = (int) $this->pdo->query('SELECT COUNT(*) FROM journal_entries WHERE supplier_id=' . $this->supplier)->fetchColumn();
        $primary = $this->pdo;
        try {
            $this->pdo = $this->otherDb->pdo();
            $this->post('518', '20.00');
            $reversal = $this->post('602', '100.00', false, red: true);
            $this->pdo->prepare('UPDATE journal_entries SET reversed_by = (SELECT entry_id FROM journal_entry_lines WHERE id = ?) WHERE id = (SELECT entry_id FROM journal_entry_lines WHERE id = ?)')->execute([$reversal, $closing]);
            $secondReversal = $this->post('602', '100.00', false);
            $this->pdo->prepare('UPDATE journal_entries SET reversed_by = (SELECT entry_id FROM journal_entry_lines WHERE id = ?) WHERE id = (SELECT entry_id FROM journal_entry_lines WHERE id = ?)')->execute([$secondReversal, $reversal]);
        } finally { $this->pdo = $primary; }
        $fresh = $other->get(NonprofitTaxAssessmentService::class)->read($this->supplier, $this->period)['snapshot'];
        self::assertNotSame($old['input_hash'], $fresh['input_hash']);
        self::assertSame($oldCount, (int) $this->pdo->query('SELECT COUNT(*) FROM journal_entries WHERE supplier_id=' . $this->supplier)->fetchColumn());
        $otherConfirmed = $other->get(NonprofitTaxAssessmentService::class)->confirm($this->supplier, $this->period,
            $this->assessment($fresh), $fresh['input_hash'], 'Souběžné posouzení', 'other-confirm', $this->actor);
        $this->refused('nonprofit_tax_assessment_changed', fn () => $this->service->confirm($this->supplier, $this->period,
            $this->assessment($fresh), $fresh['input_hash'], 'Aktuální podklady', 'concurrent', $this->actor));
        $confirmed = $this->service->confirm($this->supplier, $this->period, $this->assessment($fresh), $fresh['input_hash'], 'Aktuální podklady', 'concurrent', $this->actor, $otherConfirmed['id']);
        self::assertSame(8000, $confirmed['result']['assessed_result_cents']);
        self::assertCount(2, $confirmed['snapshot']['sources']);
        self::assertTrue($this->guard->closingCheck($this->supplier, $this->period, true)['complete']);
    }

    private function deleteSupplier(): \Psr\Http\Message\ResponseInterface
    {
        $request = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('DELETE', '/api/suppliers/' . $this->supplier)
            ->withAttribute(\MyInvoice\Middleware\SupplierScopeMiddleware::ATTR_CURRENT_ID, 1)
            ->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_USER, ['id' => $this->actor, 'role' => 'admin']);
        return Bootstrap::buildContainer()->get(\MyInvoice\Action\Settings\SettingsAction::class)
            ->deleteSupplierById($request, new \Slim\Psr7\Response(), ['id' => (string) $this->supplier]);
    }

    public function testSupplierDeleteTreatsEmptyYearAssessmentAsAccountingData(): void
    {
        $confirmed = $this->confirmation();
        $this->pdo->commit();
        $this->committed = true;
        $response = $this->deleteSupplier();
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('has_dependencies', $body['error']['code']);
        self::assertSame($confirmed, $this->repository->find($this->supplier, $confirmed['id']));
    }

    public function testSupplierDeleteStillRemovesCompanyWithoutAccountingData(): void
    {
        $this->pdo->commit();
        $this->committed = true;
        $response = $this->deleteSupplier();
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame([], $this->repository->history($this->supplier, $this->period));
    }

    public function testActionRejectsMalformedConfirmationWithoutWritingEvidence(): void
    {
        $snapshot = $this->service->read($this->supplier, $this->period)['snapshot'];
        $valid = ['assessment' => $this->assessment($snapshot), 'input_hash' => $snapshot['input_hash'],
            'reason' => 'Doložené rozhodnutí', 'idempotency_key' => 'invalid-body'];
        $action = Bootstrap::buildContainer()->get(\MyInvoice\Action\Accounting\Closing\NonprofitTaxAssessmentAction::class);
        foreach ([['reason' => ['invalid']], ['input_hash' => []], ['assessment' => 'invalid'], ['expected_assessment_id' => 'invalid']] as $invalid) {
            $request = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST', '/api/accounting/periods/' . $this->period . '/nonprofit-tax/confirm')
                ->withAttribute(\MyInvoice\Middleware\SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplier)
                ->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_USER, ['id' => $this->actor, 'role' => 'admin'])
                ->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_METHOD, 'session')
                ->withParsedBody(array_replace($valid, $invalid));
            $response = $action->confirm($request, new \Slim\Psr7\Response(), ['id' => (string) $this->period]);
            self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());
            self::assertSame([], $this->repository->history($this->supplier, $this->period));
        }
    }

    public function testConfirmationAndWithdrawalRequireSessionEvenForAdmin(): void
    {
        $action = Bootstrap::buildContainer()->get(\MyInvoice\Action\Accounting\Closing\NonprofitTaxAssessmentAction::class);
        foreach (['bearer', null] as $method) {
            $request = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST', '/api/accounting/periods/' . $this->period . '/nonprofit-tax/confirm')
                ->withAttribute(\MyInvoice\Middleware\SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplier)
                ->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_USER, ['id' => $this->actor, 'role' => 'admin'])
                ->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_METHOD, $method)
                ->withParsedBody([]);
            foreach (['confirm', 'withdraw'] as $operation) {
                $response = $action->$operation($request, new \Slim\Psr7\Response(), ['id' => (string) $this->period]);
                self::assertSame(403, $response->getStatusCode());
                self::assertStringContainsString('nonprofit_tax_session_only', (string) $response->getBody());
                self::assertSame([], $this->repository->history($this->supplier, $this->period));
            }
            self::assertSame(200, $action->read($request, new \Slim\Psr7\Response(), ['id' => (string) $this->period])->getStatusCode());
        }
    }

    public function testClosingChecklistRequiresCurrentConfirmedAssessment(): void
    {
        $closing = Bootstrap::buildContainer()->get(\MyInvoice\Service\Accounting\Closing\ClosingService::class);
        $period = ['id' => $this->period, 'fiscal_year' => 2026, 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'status' => 'open'];
        $checks = $closing->buildChecks($this->supplier, $period, onlyKeys: ['nonprofit_tax_assessment']);
        self::assertCount(1, $checks);
        self::assertFalse($checks[0]['ok']);
        self::assertSame('error', $checks[0]['severity']);
        $this->confirmation('closing-check');
        self::assertTrue($closing->buildChecks($this->supplier, $period, onlyKeys: ['nonprofit_tax_assessment'])[0]['ok']);
        $this->post('518', '1.00');
        self::assertFalse($closing->buildChecks($this->supplier, $period, onlyKeys: ['nonprofit_tax_assessment'])[0]['ok']);
    }

    public function testCloseBooksChecksAssessmentBeforeClosingMutations(): void
    {
        $this->pdo->prepare("UPDATE accounting_periods SET status = 'closing' WHERE id = ?")->execute([$this->period]);
        $closing = Bootstrap::buildContainer()->get(\MyInvoice\Service\Accounting\Closing\ClosingService::class);
        try {
            $closing->closeBooks($this->supplier, $this->period, 1);
            self::fail('Closing without a confirmed assessment must be refused.');
        } catch (\MyInvoice\Service\Accounting\Closing\ClosingException $failure) {
            self::assertSame('nonprofit_tax_assessment_required', $failure->errorCode);
        }
        self::assertSame([], $this->repository->history($this->supplier, $this->period));
        $status = $this->pdo->prepare('SELECT status FROM accounting_periods WHERE id = ?');
        $status->execute([$this->period]);
        self::assertSame('closing', $status->fetchColumn());
    }

    public function testContradictoryCumulativeFundingCannotBeConfirmed(): void
    {
        $gifts = ['line:' . $this->post('602', '40.00'), 'line:' . $this->post('602', '40.00')];
        $cost = 'line:' . $this->post('518', '100.00');
        $snapshot = $this->service->read($this->supplier, $this->period)['snapshot'];
        $assessment = $this->assessment($snapshot);
        $assessment['decisions'][$cost]['deductible_cents'] = 6000;
        foreach ($gifts as $gift) {
            $assessment['decisions'][$gift] = ['title' => 'gift', 'evidence' => 'Synthetic gift purpose',
                'facts' => ['claim_exemption' => true, 'qualifying_purpose' => true, 'election_evidence' => 'Synthetic exemption election']];
            $assessment['funding'][] = ['source_id' => $gift, 'cost_id' => $cost, 'amount_cents' => 4000,
                'deductible_funded_cents' => 0, 'evidence' => 'Synthetic contradictory funding'];
        }
        $this->refused('nonprofit_tax_facts_incomplete', fn () => $this->service->confirm($this->supplier, $this->period,
            $assessment, $snapshot['input_hash'], 'Synthetic review', 'contradictory-funding', $this->actor));
        self::assertSame([], $this->repository->history($this->supplier, $this->period));
    }

    public function testPreviousFundingRulesetDoesNotRemainCurrent(): void
    {
        $snapshot = $this->service->read($this->supplier, $this->period)['snapshot'];
        $snapshot['ruleset'] = 'cz-nonprofit-assessment-2024-2026-v1';
        unset($snapshot['input_hash']);
        $snapshot['input_hash'] = \MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules::hash($snapshot);
        $assessment = $this->assessment($snapshot);
        $result = \MyInvoice\Tests\Support\J6CalculatorFactory::legacy()->calculate($snapshot, $assessment);
        $result['ruleset'] = $snapshot['ruleset'];
        $reason = 'Synthetic historical ruleset confirmation';
        $requestHash = \MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules::hash(['action' => 'confirm', 'period_id' => $this->period,
            'assessment' => $assessment, 'input_hash' => $snapshot['input_hash'], 'reason' => $reason,
            'actor' => $this->actor, 'expected_assessment_id' => null]);
        $this->pdo->prepare("INSERT INTO nonprofit_tax_assessments
            (supplier_id,period_id,revision_no,status,reason,ruleset,input_hash,request_hash,idempotency_key,snapshot_json,assessment_json,result_json,created_by)
            VALUES (?,?,1,'confirmed',?,?,?,?,?,?,?,?,?)")
            ->execute([$this->supplier, $this->period, $reason, $snapshot['ruleset'], $snapshot['input_hash'], $requestHash, 'historical-ruleset',
                \MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules::canonicalJson($snapshot),
                \MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules::canonicalJson($assessment),
                \MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules::canonicalJson($result), $this->actor]);
        $legacyId = (int) $this->pdo->lastInsertId();
        $check = $this->guard->closingCheck($this->supplier, $this->period);
        self::assertFalse($check['complete']);
        self::assertContains('assessment_stale', array_column($check['findings'], 'code'));
        self::assertFalse($this->exporter->export($this->supplier, $legacyId)['current_confirmed']);
    }

    public function testReplacingFundingTitleCannotBypassConfirmedLaterDrawdown(): void
    {
        $currentPeriod = $this->period;
        $this->period = (new AccountingPeriodRepository($this->db))->create($this->supplier, 2025, '2025-01-01', '2025-12-31');
        $this->pdo->prepare("UPDATE supplier_accounting_frameworks SET effective_from = '2025-01-01' WHERE supplier_id = ?")->execute([$this->supplier]);
        $grant = 'line:' . $this->post('682', '100.00', date: '2025-06-01');
        $cost = 'line:' . $this->post('518', '100.00', date: '2025-06-01');
        $oldSnapshot = $this->service->read($this->supplier, $this->period)['snapshot'];
        $oldAssessment = $this->assessment($oldSnapshot);
        $oldAssessment['decisions'][$grant] = ['title' => 'public_operating_grant', 'evidence' => 'Synthetic grant decision', 'facts' => ['public_budget' => true]];
        $oldAssessment['funding'] = [['source_id' => $grant, 'cost_id' => $cost, 'amount_cents' => 5000, 'evidence' => 'Synthetic first drawdown']];
        $first = $this->service->confirm($this->supplier, $this->period, $oldAssessment, $oldSnapshot['input_hash'], 'Synthetic first review', 'replacement-prior', $this->actor);
        $this->period = $currentPeriod;
        $currentCost = 'line:' . $this->post('518', '50.00');
        $currentSnapshot = $this->service->read($this->supplier, $this->period)['snapshot'];
        $currentAssessment = $this->assessment($currentSnapshot);
        $currentAssessment['funding_sources'] = [['anchor' => $grant, 'members' => [$grant], 'evidence' => 'Synthetic retained source']];
        $currentAssessment['funding'] = [['source_id' => $grant, 'cost_id' => $currentCost, 'amount_cents' => 5000, 'evidence' => 'Synthetic later drawdown']];
        $later = $this->service->confirm($this->supplier, $this->period, $currentAssessment, $currentSnapshot['input_hash'], 'Synthetic later review', 'replacement-later', $this->actor);
        $replacement = $oldAssessment;
        $replacement['decisions'][$grant] = ['title' => 'other_taxable', 'evidence' => 'Synthetic changed legal title'];
        $replacement['funding'] = [];
        $this->refused('nonprofit_tax_review_required', fn () => $this->service->confirm($this->supplier, $first['period_id'], $replacement,
            $oldSnapshot['input_hash'], 'Synthetic replacement', 'replacement-bypass', $this->actor, $first['id']));
        self::assertCount(1, $this->service->history($this->supplier, $first['period_id']));
        $this->service->withdraw($this->supplier, $this->period, $later['id'], 'Synthetic review of dependency', 'replacement-withdraw-later', $this->actor);
        $accepted = $this->service->confirm($this->supplier, $first['period_id'], $replacement, $oldSnapshot['input_hash'],
            'Synthetic replacement after dependent review', 'replacement-after-review', $this->actor, $first['id']);
        self::assertSame(2, $accepted['revision_no']);
    }

    public function testJointAllocationCannotResetTheOriginalGiftExemptionElection(): void
    {
        $source = $this->post('682', '100.00');
        $snapshot = $this->service->read($this->supplier, $this->period)['snapshot'];
        $assessment = $this->assessment($snapshot);
        $giftDecision = ['title' => 'gift', 'evidence' => 'Synthetic original gift', 'facts' => ['claim_exemption' => true,
            'qualifying_purpose' => true, 'election_evidence' => 'Synthetic original election']];
        $assessment['decisions']['line:' . $source] = $giftDecision;
        $first = $this->service->confirm($this->supplier, $this->period, $assessment, $snapshot['input_hash'], 'Synthetic first election', 'gift-before-joint', $this->actor);
        $this->joint($source);
        $jointSnapshot = $this->service->read($this->supplier, $this->period)['snapshot'];
        self::assertSame([], $jointSnapshot['findings']);
        self::assertCount(2, $jointSnapshot['sources']);
        self::assertSame([$source], array_values(array_unique(array_column(array_column($jointSnapshot['sources'], 'origin'), 'line_id'))));
        $changed = $this->assessment($jointSnapshot);
        foreach ($jointSnapshot['sources'] as $row) $changed['decisions'][$row['id']] = ['title' => 'gift', 'evidence' => 'Synthetic allocated gift',
            'facts' => ['claim_exemption' => false, 'income_treatment' => 'separate_taxable_income', 'income_attribution_evidence' => 'Synthetic separate income']];
        $this->refused('nonprofit_tax_gift_election_locked', fn () => $this->service->confirm($this->supplier, $this->period, $changed,
            $jointSnapshot['input_hash'], 'Synthetic attempted election change', 'gift-after-joint-changed', $this->actor, $first['id']));
        foreach ($jointSnapshot['sources'] as $row) $changed['decisions'][$row['id']] = $giftDecision;
        $accepted = $this->service->confirm($this->supplier, $this->period, $changed, $jointSnapshot['input_hash'],
            'Synthetic preserved original election', 'gift-after-joint-preserved', $this->actor, $first['id']);
        self::assertSame(2, $accepted['revision_no']);
    }

    public function testFirstConfirmationRequiresOneElectionForAllPartsOfTheSameGift(): void
    {
        $source = $this->post('682', '100.00');
        $this->joint($source);
        $snapshot = $this->service->read($this->supplier, $this->period)['snapshot'];
        $assessment = $this->assessment($snapshot);
        $gift = ['title' => 'gift', 'evidence' => 'Synthetic gift', 'facts' => ['claim_exemption' => true,
            'qualifying_purpose' => true, 'election_evidence' => 'Synthetic exemption decision']];
        foreach ($snapshot['sources'] as $row) $assessment['decisions'][$row['id']] = $gift;
        $second = $snapshot['sources'][1]['id'];
        $assessment['decisions'][$second]['facts'] = ['claim_exemption' => false, 'income_treatment' => 'separate_taxable_income',
            'income_attribution_evidence' => 'Synthetic allocated income'];
        $this->refused('nonprofit_tax_facts_incomplete', fn () => $this->service->confirm($this->supplier, $this->period, $assessment,
            $snapshot['input_hash'], 'Synthetic conflicting elections', 'gift-split-conflict', $this->actor));
        $assessment['decisions'][$second] = ['title' => 'other_taxable', 'evidence' => 'Synthetic conflicting title'];
        $this->refused('nonprofit_tax_facts_incomplete', fn () => $this->service->confirm($this->supplier, $this->period, $assessment,
            $snapshot['input_hash'], 'Synthetic conflicting title', 'gift-split-title', $this->actor));
        $assessment['decisions'][$second] = $gift;
        $accepted = $this->service->confirm($this->supplier, $this->period, $assessment, $snapshot['input_hash'],
            'Synthetic consistent gift', 'gift-split-consistent', $this->actor);
        self::assertSame(1, $accepted['revision_no']);
    }

    public function testWithdrawnPriorPeriodStillProtectsGiftElectionAfterLaterAllocation(): void
    {
        $currentPeriod = $this->period;
        $this->period = (new AccountingPeriodRepository($this->db))->create($this->supplier, 2025, '2025-01-01', '2025-12-31');
        $this->pdo->prepare("UPDATE supplier_accounting_frameworks SET effective_from = '2025-01-01' WHERE supplier_id = ?")->execute([$this->supplier]);
        $source = $this->post('682', '100.00', date: '2025-06-01');
        $snapshot = $this->service->read($this->supplier, $this->period)['snapshot'];
        $assessment = $this->assessment($snapshot);
        $gift = ['title' => 'gift', 'evidence' => 'Synthetic original gift', 'facts' => ['claim_exemption' => true,
            'qualifying_purpose' => true, 'election_evidence' => 'Synthetic original election']];
        $assessment['decisions']['line:' . $source] = $gift;
        $first = $this->service->confirm($this->supplier, $this->period, $assessment, $snapshot['input_hash'], 'Synthetic first election', 'gift-prior-year', $this->actor);
        $this->service->withdraw($this->supplier, $this->period, $first['id'], 'Synthetic assessment review', 'gift-prior-withdrawn', $this->actor);
        $this->period = $currentPeriod;
        $this->joint($source);
        $current = $this->service->read($this->supplier, $this->period)['snapshot'];
        self::assertSame([], $current['findings']);
        $changed = $this->assessment($current);
        $positive = array_values(array_filter($current['sources'], static fn (array $row): bool => $row['amount_cents'] > 0));
        $changed['funding_sources'] = [['anchor' => $positive[0]['id'], 'members' => array_column($current['sources'], 'id'),
            'evidence' => 'Synthetic complete cross-year allocation correction']];
        foreach ($current['sources'] as $row) $changed['decisions'][$row['id']] = ['title' => 'gift', 'evidence' => 'Synthetic allocated gift',
            'facts' => ['claim_exemption' => false, 'income_treatment' => 'separate_taxable_income', 'income_attribution_evidence' => 'Synthetic separate income']];
        $this->refused('nonprofit_tax_gift_election_locked', fn () => $this->service->confirm($this->supplier, $this->period, $changed,
            $current['input_hash'], 'Synthetic cross-year attempted change', 'gift-later-changed', $this->actor));
        foreach ($current['sources'] as $row) $changed['decisions'][$row['id']] = $gift;
        $accepted = $this->service->confirm($this->supplier, $this->period, $changed, $current['input_hash'],
            'Synthetic preserved prior election', 'gift-later-preserved', $this->actor);
        self::assertSame(1, $accepted['revision_no']);
    }

    public function testHistoricalFundingUsesLatestRevisionOnceAndCurrentPeriodDoesNotCountItself(): void
    {
        $currentPeriod = $this->period;
        $this->period = (new AccountingPeriodRepository($this->db))->create($this->supplier, 2025, '2025-01-01', '2025-12-31');
        $this->pdo->prepare("UPDATE supplier_accounting_frameworks SET effective_from = '2025-01-01' WHERE supplier_id = ?")->execute([$this->supplier]);
        $grant = 'line:' . $this->post('682', '100.00', date: '2025-06-01');
        $cost = 'line:' . $this->post('518', '100.00', date: '2025-06-01');
        $old = $this->service->read($this->supplier, $this->period)['snapshot'];
        $assessment = $this->assessment($old);
        $assessment['decisions'][$grant] = ['title' => 'public_operating_grant', 'evidence' => 'Rozhodnutí o dotaci', 'facts' => ['public_budget' => true]];
        $assessment['funding'] = [['source_id' => $grant, 'cost_id' => $cost, 'amount_cents' => 5000, 'evidence' => 'Původní podklady čerpání']];
        $first = $this->service->confirm($this->supplier, $this->period, $assessment, $old['input_hash'], 'První posouzení', 'prior-first', $this->actor);
        $assessment['funding'][0]['amount_cents'] = 6000;
        $second = $this->service->confirm($this->supplier, $this->period, $assessment, $old['input_hash'], 'Opravené doložené čerpání', 'prior-second', $this->actor, $first['id']);
        $this->pdo->prepare("UPDATE accounting_periods SET status = 'closed' WHERE id = ?")->execute([$this->period]);
        $this->period = $currentPeriod;
        $refund = 'line:' . $this->post('682', '20.00', red: true);
        $currentCost = 'line:' . $this->post('518', '40.00');
        $snapshot = $this->service->read($this->supplier, $this->period)['snapshot'];
        self::assertCount(1, $snapshot['funding_history']);
        self::assertSame($second['id'], $snapshot['funding_history'][0]['assessment_id']);
        $assessment = $this->assessment($snapshot);
        $assessment['decisions'][$refund] = ['title' => 'public_operating_grant', 'evidence' => 'Vrácení části původní dotace', 'facts' => ['public_budget' => true]];
        $assessment['funding_sources'] = [['anchor' => $grant, 'members' => [$grant, $refund], 'evidence' => 'Stejná dotace a doložená vratka']];
        $assessment['funding'] = [['source_id' => $grant, 'cost_id' => $currentCost, 'amount_cents' => 2001, 'evidence' => 'Navazující čerpání']];
        $this->refused('nonprofit_tax_facts_incomplete', fn () => $this->service->preview($this->supplier, $this->period, $assessment));
        $assessment['funding'][0]['amount_cents'] = 2000;
        $preview = $this->service->preview($this->supplier, $this->period, $assessment);
        self::assertSame(6000, $preview['result']['funding_usage'][0]['prior_drawdown_cents']);
        self::assertSame(0, $preview['result']['funding_usage'][0]['remaining_cents']);
        self::assertSame(-2000, $preview['result']['assessed_result_cents']);
        $this->service->confirm($this->supplier, $this->period, $assessment, $snapshot['input_hash'], 'Čerpání zůstatku', 'current-funding', $this->actor);
        self::assertSame($preview['result'], $this->service->preview($this->supplier, $this->period, $assessment)['result']);
        self::assertSame($second, $this->repository->find($this->supplier, $second['id']));
        self::assertTrue($this->guard->closingCheck($this->supplier, $second['period_id'])['complete']);
        $this->pdo->prepare("UPDATE accounting_periods SET status = 'open' WHERE id = ?")->execute([$second['period_id']]);
        $this->refused('nonprofit_tax_review_required', fn () => $this->service->confirm($this->supplier, $second['period_id'], $second['assessment'],
            $second['input_hash'], 'Změna již čerpaného zdroje', 'prior-changed', $this->actor, $second['id']));
        $this->period = $second['period_id'];
        $this->post('518', '1.00', date: '2025-07-01');
        $this->period = $currentPeriod;
        $this->refused('nonprofit_tax_review_required', fn () => $this->service->preview($this->supplier, $this->period, $assessment));
        self::assertFalse($this->guard->closingCheck($this->supplier, $this->period)['complete']);
    }
}

final class NonprofitCurrentCheckFailureStatement extends \PDOStatement
{
    protected function __construct() {}

    public function execute(?array $params = null): bool
    {
        if (str_contains($this->queryString, 'FROM accounting_periods')) throw new \PDOException('synthetic-current-check-failure');
        return parent::execute($params);
    }
}
