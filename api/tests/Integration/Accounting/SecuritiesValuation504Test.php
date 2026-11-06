<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\{ChartOfAccountsSeeder, PostingService};
use MyInvoice\Service\Accounting\Closing\Fx504Service;
use MyInvoice\Service\Accounting\Dimension\SystemDimensionCatalog;
use PHPUnit\Framework\TestCase;

final class SecuritiesValuation504Test extends TestCase
{
    private Connection $db;
    private int $supplier;
    private int $period;
    private array $dims;
    private Fx504Service $service;
    private bool $committed = false;
    private ?Connection $other = null;
    private mixed $previousRate;
    private int $originalIsolation;

    protected function tearDown(): void
    {
        if (!isset($this->db)) return;
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) $pdo->rollBack();
        $this->other?->close();
        if ($this->committed) {
            foreach (['activity_log', 'journal_entries'] as $table) $pdo->prepare('DELETE FROM ' . $table . ' WHERE supplier_id=?')->execute([$this->supplier]);
            $pdo->prepare('DELETE FROM supplier WHERE id=?')->execute([$this->supplier]);
            if ($this->previousRate === false) $pdo->exec("DELETE FROM exchange_rates WHERE rate_date='2025-12-31' AND currency_code='EUR'");
            else $pdo->prepare("UPDATE exchange_rates SET rate=? WHERE rate_date='2025-12-31' AND currency_code='EUR'")->execute([$this->previousRate]);
        }
        $pdo->exec('SET SESSION innodb_snapshot_isolation=' . $this->originalIsolation);
    }

    protected function setUp(): void
    {
        $c=Bootstrap::buildContainer();
        $this->db=$c->get(Connection::class);
        $pdo=$this->db->pdo();
        $this->originalIsolation = (int) $pdo->query('SELECT @@innodb_snapshot_isolation')->fetchColumn();
        $this->previousRate = $pdo->query("SELECT rate FROM exchange_rates WHERE rate_date='2025-12-31' AND currency_code='EUR'")->fetchColumn();
        $pdo->beginTransaction();
        $pdo->exec("INSERT INTO supplier(company_name,street,city,zip,email,country_id,default_currency_id,default_vat_rate_id,accounting_mode)
            SELECT 'Synthetic securities proof','Test 1','Test','10000','securities-proof@example.com',country_id,default_currency_id,default_vat_rate_id,'double_entry' FROM supplier ORDER BY id LIMIT 1");
        $sid=(int)$pdo->lastInsertId();
        $c->get(ChartOfAccountsSeeder::class)->seedForSupplier($sid);
        $pid=$c->get(AccountingPeriodRepository::class)->create($sid,2025,'2025-01-01','2025-12-31');
        $pdo->prepare("INSERT INTO supplier_accounting_frameworks(supplier_id,effective_from,accounting_framework) VALUES (?,'2025-01-01','cz_504')")->execute([$sid]);
        $catalog=(new SystemDimensionCatalog($this->db))->ensureForFramework($sid,'2025-01-01');
        $pdo->prepare('INSERT INTO dimension_values(type_id,supplier_id,code,name) VALUES (?,?,?,?)')->execute([$catalog['activity_kind']['id'],$sid,'portfolio','Synthetic portfolio']);
        $dims=[$catalog['activity']['id']=>$catalog['activity']['values']['main'],$catalog['activity_kind']['id']=>(int)$pdo->lastInsertId(),$catalog['tax_regime']['id']=>$catalog['tax_regime']['values']['subject']];
        $pdo->prepare("INSERT IGNORE INTO chart_of_accounts(supplier_id,account_code,name,account_type,is_active) VALUES (?,'901','Synthetic capital','equity',1)")->execute([$sid]);
        $c->get(PostingService::class)->postDocument($sid,'manual',null,[
            ['account_code'=>'251','side'=>'debit','amount'=>'24000.00','amount_foreign'=>'1000.00','currency_code'=>'EUR','fx_rate'=>'24.000000','dimensions'=>$dims],
            ['account_code'=>'901','side'=>'credit','amount'=>'24000.00','dimensions'=>$dims],
        ],['entry_date'=>'2025-06-01','posted'=>true]);
        $pdo->exec("INSERT INTO exchange_rates(rate_date,currency_code,rate) VALUES ('2025-12-31','EUR',24) ON DUPLICATE KEY UPDATE rate=VALUES(rate)");
        $this->supplier = $sid;
        $this->period = $pid;
        $this->dims = $dims;
        $this->service = $c->get(Fx504Service::class);
        foreach (['921' => 'equity', '549' => 'expense', '649' => 'revenue', '545' => 'expense', '645' => 'revenue', '644' => 'revenue'] as $code => $type) {
            $pdo->prepare('INSERT IGNORE INTO chart_of_accounts(supplier_id,account_code,name,account_type,is_active) VALUES (?,?,?,?,1)')->execute([$sid, $code, 'Synthetic ' . $code, $type]);
        }
    }

    public function testCurrentPureProfitAndLossTurnoverMembershipInvalidatesReviewedSnapshot(): void
    {
        $evidence = $this->evidence();
        $before = $this->preview($evidence);
        self::assertTrue($before['complete']);
        $this->db->pdo()->prepare("UPDATE accounting_periods SET status='closing' WHERE supplier_id=? AND id=?")->execute([$this->supplier, $this->period]);
        $posting = Bootstrap::buildContainer()->get(PostingService::class);
        $posting->postDocument($this->supplier, 'manual', null, [
            ['account_code' => '549', 'side' => 'debit', 'amount' => '1000.00', 'dimensions' => $this->dims],
            ['account_code' => '649', 'side' => 'credit', 'amount' => '1000.00', 'dimensions' => $this->dims],
        ], ['entry_date' => '2025-12-30', 'posted' => true, 'allow_closing_period' => true]);
        $after = $this->service->preview($this->supplier, $this->period, [], true, null, $evidence);
        self::assertNotSame($before['snapshot_hash'], $after['snapshot_hash']);
        self::assertNotSame($before['securities']['inventory_hash'], $after['securities']['inventory_hash']);
        $this->expectException(\MyInvoice\Service\Accounting\Closing\ClosingException::class);
        $this->service->run($this->supplier, $this->period, ['snapshot_hash' => $before['snapshot_hash'], 'securities_evidence' => $evidence], 1);
    }

    public function testProvenZeroCostEquityValuationCompletesNativeFxPreview(): void
    {
        $initial = $this->service->preview($this->supplier, $this->period);
        self::assertFalse($initial['complete']);
        $preview = $this->preview($this->evidence());
        self::assertTrue($preview['complete'], json_encode($preview['securities']['findings']));
        self::assertSame(['loss'=>'0.00','gain'=>'0.00'], $preview['totals']);
    }

    public function testNonzeroEquityDifferenceNeedsSpecificCurrentPostingAndCannotClaimItTwice(): void
    {
        $evidence = $this->evidence();
        $this->db->pdo()->exec("UPDATE exchange_rates SET rate=25 WHERE rate_date='2025-12-31' AND currency_code='EUR'");
        $evidence['valuations'][0]['target_czk'] = '25000.00';
        self::assertContains('securities_posting_required', array_column($this->preview($evidence)['securities']['findings'], 'code'));
        $proof = $this->valuationPosting('921');
        $evidence['valuations'][0]['posted_proof'] = [$proof];
        $preview = $this->preview($evidence);
        self::assertTrue($preview['complete'], json_encode($preview['securities']['findings']));
        self::assertSame('1000.00', $preview['securities']['valuations'][0]['change_czk']);
        $evidence['valuations'][0]['posted_proof'][] = $proof;
        self::assertFalse($this->preview($evidence)['complete']);
    }

    public function testOrdinaryFxGainCannotStandInForEquityValuation(): void
    {
        $evidence = $this->evidence();
        $this->db->pdo()->exec("UPDATE exchange_rates SET rate=25 WHERE rate_date='2025-12-31' AND currency_code='EUR'");
        $evidence['valuations'][0]['target_czk'] = '25000.00';
        $evidence['valuations'][0]['posted_proof'] = [$this->valuationPosting('645')];
        $preview = $this->preview($evidence);
        self::assertFalse($preview['complete']);
        self::assertContains('securities_posting_route_invalid', array_column($preview['securities']['findings'], 'code'));
    }

    public function testValuationCounterpartCannotIntroduceForeignNominal(): void
    {
        $evidence = $this->evidence();
        $this->db->pdo()->exec("UPDATE exchange_rates SET rate=25 WHERE rate_date='2025-12-31' AND currency_code='EUR'");
        $evidence['valuations'][0]['target_czk'] = '25000.00';
        $proof = $this->valuationPosting('921');
        $evidence['valuations'][0]['posted_proof'] = [$proof];
        $this->db->pdo()->prepare("UPDATE journal_entry_lines SET currency_code='EUR',amount_foreign=40,fx_rate=25 WHERE supplier_id=? AND id=?")->execute([$this->supplier, $proof['counterpart_line_id']]);
        $preview = $this->preview($evidence);
        self::assertFalse($preview['complete']);
        self::assertContains('securities_posting_nominal_changed', array_column($preview['securities']['findings'], 'code'));
    }

    public function testReversedValuationCannotRemainAProof(): void
    {
        $evidence = $this->evidence();
        $this->db->pdo()->exec("UPDATE exchange_rates SET rate=25 WHERE rate_date='2025-12-31' AND currency_code='EUR'");
        $evidence['valuations'][0]['target_czk'] = '25000.00';
        $proof = $this->valuationPosting('921');
        $evidence['valuations'][0]['posted_proof'] = [$proof];
        self::assertTrue($this->preview($evidence)['complete']);
        Bootstrap::buildContainer()->get(PostingService::class)->reverse($this->supplier, $proof['entry_id'], ['entry_date' => '2025-12-31']);
        $preview = $this->preview($evidence);
        self::assertFalse($preview['complete']);
        self::assertContains('securities_posting_current_required', array_column($preview['securities']['findings'], 'code'));
    }

    public function testTypedRunPersistsRecomputedEvidenceAndRejectsChangedInventoryWithinCallerTransaction(): void
    {
        $this->db->pdo()->prepare("UPDATE accounting_periods SET status='closing' WHERE supplier_id=? AND id=?")->execute([$this->supplier, $this->period]);
        $evidence = $this->evidence();
        $preview = $this->preview($evidence);
        $body = ['snapshot_hash' => $preview['snapshot_hash'], 'securities_evidence' => $evidence];
        $result = $this->service->run($this->supplier, $this->period, $body, 1);
        self::assertSame(['loss' => '0.00', 'gain' => '0.00'], $result['totals']);
        self::assertTrue($this->db->pdo()->inTransaction());
        $step = $this->db->pdo()->prepare("SELECT payload FROM accounting_closing_steps WHERE supplier_id=? AND period_id=? AND step_key='fx_revaluation'");
        $step->execute([$this->supplier, $this->period]);
        $saved = json_decode($step->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($saved['fx504']['securities_assessment']['complete']);
        self::assertSame($evidence, $saved['fx504']['securities_evidence']);
        $this->valuationPosting('921');
        try {
            $this->service->run($this->supplier, $this->period, $body, 2);
            self::fail('Changed inventory must invalidate the complete assessment.');
        } catch (\MyInvoice\Service\Accounting\Closing\ClosingException $e) {
            self::assertSame('fx504_changed', $e->errorCode);
        }
        self::assertTrue($this->db->pdo()->inTransaction());
    }

    public function testZeroNetInventoryStillRequiresEveryIndependentHolding(): void
    {
        Bootstrap::buildContainer()->get(PostingService::class)->postDocument($this->supplier, 'manual', null, [
            ['account_code' => '251', 'side' => 'credit', 'amount' => '24000.00', 'amount_foreign' => '1000.00', 'currency_code' => 'EUR', 'fx_rate' => '24.000000', 'dimensions' => $this->dims],
            ['account_code' => '901', 'side' => 'debit', 'amount' => '24000.00', 'dimensions' => $this->dims],
        ], ['entry_date' => '2025-07-01', 'posted' => true]);
        $preview = $this->service->preview($this->supplier, $this->period);
        self::assertFalse($preview['complete'], 'A zero-net account cannot erase two unresolved inventory members.');
        self::assertCount(2, $preview['securities']['cells']);
        self::assertContains('securities_evidence_required', array_column($preview['securities']['findings'], 'code'));
    }

    public function testEvidencePreviewWorksInNativeReadOnlyTransaction(): void
    {
        $evidence = $this->evidence();
        $pdo = $this->db->pdo();
        $pdo->commit();
        $this->committed = true;
        $pdo->exec('START TRANSACTION READ ONLY');
        $before = $pdo->query('SELECT COUNT(*) FROM journal_entries WHERE supplier_id=' . $this->supplier)->fetchColumn();
        $preview = Bootstrap::buildContainer()->get(\MyInvoice\Service\Accounting\Closing\ClosingService::class)->fxPreview($this->supplier, $this->period, [], [], null, $evidence);
        self::assertTrue($preview['complete'], json_encode($preview['securities']['findings']));
        self::assertTrue($pdo->inTransaction());
        self::assertSame($before, $pdo->query('SELECT COUNT(*) FROM journal_entries WHERE supplier_id=' . $this->supplier)->fetchColumn());
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM accounting_closing_steps WHERE supplier_id=' . $this->supplier)->fetchColumn());
    }

    #[\PHPUnit\Framework\Attributes\TestWith([false])]
    #[\PHPUnit\Framework\Attributes\TestWith([true])]
    public function testCurrentInventorySeesInsertedHoldingAndCancellationAfterCallerSnapshot(bool $cancel): void
    {
        $pdo = $this->db->pdo();
        $pdo->commit();
        $this->committed = true;
        $pdo->exec('SET SESSION innodb_snapshot_isolation=OFF');
        $pdo->beginTransaction();
        $evidence = $this->evidence();
        $before = $this->preview($evidence);
        self::assertTrue($before['complete']);
        $other = Connection::withoutSharedTestConnection(static function () {
            $c = Bootstrap::buildContainer();
            $c->get(Connection::class)->pdo();
            return $c;
        });
        $this->other = $other->get(Connection::class);
        self::assertNotSame($pdo->query('SELECT CONNECTION_ID()')->fetchColumn(), $this->other->pdo()->query('SELECT CONNECTION_ID()')->fetchColumn());
        if ($cancel) {
            $other->get(PostingService::class)->reverse($this->supplier, $before['securities']['cells'][0]['entry_id'], ['entry_date' => '2025-07-01']);
        } else {
            $this->other->pdo()->prepare("INSERT IGNORE INTO chart_of_accounts(supplier_id,account_code,name,account_type,is_active) VALUES (?,'069','Synthetic other financial asset','asset',1)")->execute([$this->supplier]);
            $other->get(PostingService::class)->postDocument($this->supplier, 'manual', null, [
                ['account_code' => '069', 'side' => 'debit', 'amount' => '24000.00', 'amount_foreign' => '1000.00', 'currency_code' => 'EUR', 'fx_rate' => '24.000000', 'dimensions' => $this->dims],
                ['account_code' => '901', 'side' => 'credit', 'amount' => '24000.00', 'dimensions' => $this->dims],
            ], ['entry_date' => '2025-07-01', 'posted' => true]);
        }
        $after = $this->service->preview($this->supplier, $this->period, [], true, null, $evidence);
        self::assertFalse($after['complete']);
        self::assertCount($cancel ? 0 : 2, $after['securities']['cells']);
        self::assertNotSame($before['snapshot_hash'], $after['snapshot_hash']);
        self::assertTrue($pdo->inTransaction());
    }

    #[\PHPUnit\Framework\Attributes\TestWith([false, false])]
    #[\PHPUnit\Framework\Attributes\TestWith([true, false])]
    #[\PHPUnit\Framework\Attributes\TestWith([true, true])]
    public function testAnnualOpeningRetainsInventoryMembersAndChecksExactClosingChain(bool $mixed, bool $reverse): void
    {
        if ($mixed) $this->moveHolding('069');
        $evidence = $this->evidence();
        $securityLine = $this->preview($evidence)['securities']['cells'][0]['line_id'];
        if ($mixed) {
            $loanEntry = $this->postLoan('069');
            $cells = $this->service->preview($this->supplier, $this->period)['securities']['cells'];
            $loanCell = array_values(array_filter($cells, static fn (array $cell): bool => $cell['entry_id'] === $loanEntry))[0];
            $evidence['non_security_members'] = [$this->monetary($loanCell)];
        }
        $pdo = $this->db->pdo();
        $pdo->prepare('INSERT INTO accounting_supplier_settings(supplier_id,fx_reversal_at_open) VALUES (?,?) ON DUPLICATE KEY UPDATE fx_reversal_at_open=VALUES(fx_reversal_at_open)')->execute([$this->supplier, (int) $reverse]);
        $closing = Bootstrap::buildContainer()->get(\MyInvoice\Service\Accounting\Closing\ClosingService::class);
        $pdo->prepare("UPDATE accounting_periods SET status='closing' WHERE supplier_id=? AND id=?")->execute([$this->supplier, $this->period]);
        foreach (['901', '911', '931', '932', '933', '961', '962'] as $code) {
            $pdo->prepare("INSERT IGNORE INTO chart_of_accounts(supplier_id,account_code,name,account_type,is_active) VALUES (?,?,?,'equity',1)")->execute([$this->supplier, $code, 'Synthetic ' . $code]);
        }
        $version = fn (): int => (int) $pdo->query('SELECT row_version FROM accounting_periods WHERE id=' . $this->period)->fetchColumn();
        $inventory = [];
        foreach ($closing->inventoryPreview($this->supplier, $this->period)['rows'] as $row) $inventory[(int) $row['account_id']] = ['counted_balance' => (float) $row['book_balance'], 'resolution' => 'resolved', 'note' => null];
        $closing->saveInventory($this->supplier, $this->period, $version(), ['complete' => true], $inventory);
        $closing->runPrecheck($this->supplier, $this->period, $version());
        foreach (['depreciation', 'estimates', 'deferrals', 'provisions', 'income_tax'] as $step) $closing->confirmStep($this->supplier, $this->period, $step, 'skipped', null, $version());
        $preview = $this->preview($evidence);
        $original = $securityLine;
        $this->service->run($this->supplier, $this->period, ['snapshot_hash' => $preview['snapshot_hash'], 'securities_evidence' => $evidence], $version());
        $saved = $this->service->preview($this->supplier, $this->period);
        self::assertTrue($saved['complete'], json_encode($saved['securities']['findings']));
        self::assertSame($evidence, $saved['securities']['evidence']);
        \MyInvoice\Tests\Support\NonprofitTaxAssessmentFixture::confirm(Bootstrap::buildContainer(), $this->supplier, $this->period, (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn());
        $closing->closeBooks($this->supplier, $this->period, $version());
        $afterClose = $this->service->preview($this->supplier, $this->period);
        self::assertSame($saved['snapshot_hash'], $afterClose['snapshot_hash']);
        self::assertSame($saved['securities']['inventory_hash'], $afterClose['securities']['inventory_hash']);
        self::assertSame($evidence, $afterClose['securities']['evidence']);
        self::assertSame($saved['securities'], $afterClose['securities']);
        self::assertTrue($afterClose['complete'], json_encode($afterClose['securities']['findings']));
        $opened = $closing->openNext($this->supplier, $this->period, $version());
        $afterOpen = $this->service->preview($this->supplier, $this->period);
        self::assertSame($saved['snapshot_hash'], $afterOpen['snapshot_hash']);
        self::assertSame($saved['securities']['inventory_hash'], $afterOpen['securities']['inventory_hash']);
        self::assertSame($evidence, $afterOpen['securities']['evidence']);
        self::assertSame($saved['securities'], $afterOpen['securities']);
        self::assertTrue($afterOpen['complete'], json_encode($afterOpen['securities']['findings']));
        $pdo->prepare('UPDATE journal_entry_lines SET amount_foreign=amount_foreign+1 WHERE supplier_id=? AND id=?')->execute([$this->supplier, $securityLine]);
        $changedSource = $this->service->preview($this->supplier, $this->period);
        self::assertNotSame($saved['snapshot_hash'], $changedSource['snapshot_hash']);
        self::assertNotSame($saved['securities']['inventory_hash'], $changedSource['securities']['inventory_hash']);
        self::assertNull($changedSource['securities']['evidence']);
        self::assertFalse($changedSource['complete']);
        $pdo->prepare('UPDATE journal_entry_lines SET amount_foreign=amount_foreign-1 WHERE supplier_id=? AND id=?')->execute([$this->supplier, $securityLine]);
        self::assertSame($saved['snapshot_hash'], $this->service->preview($this->supplier, $this->period)['snapshot_hash']);
        $nextId = (int) $opened['next_period_id'];
        $pdo->exec("INSERT INTO exchange_rates(rate_date,currency_code,rate) VALUES ('2026-12-31','EUR',24) ON DUPLICATE KEY UPDATE rate=24");
        $evidence['profile']['as_of'] = '2026-12-31';
        $nextFacts = $evidence['profile']['category_facts'][0];
        $nextFacts['period_id'] = $nextId;
        $nextFacts['starts_on'] = '2026-01-01';
        $nextFacts['ends_on'] = '2026-12-31';
        $evidence['profile']['category_facts'][] = $nextFacts;
        $evidence['valuations'][0]['as_of'] = '2026-12-31';
        $evidence['valuations'][0]['evidence']['valuation_date'] = '2026-12-31';
        $evidence['valuations'][0]['evidence']['recoverability_date'] = '2026-12-31';
        if ($mixed) {
            foreach ($evidence['profile']['category_facts'] as &$fact) $fact['assets_net'] = '48000.00';
            unset($fact);
            $evidence['profile']['category_facts'][0]['net_turnover'] = '1000.00';
            $evidence['profile']['category_facts'][1]['net_turnover'] = $reverse ? '-1000.00' : '0.00';
            $evidence['profile']['category_facts'][1]['assets_net'] = $reverse ? '47000.00' : '48000.00';
            $inventory = $this->service->preview($this->supplier, $nextId, [], false, null, $evidence);
            $known = array_column($evidence['non_security_members'], 'source_cell_hash');
            foreach ($inventory['securities']['cells'] as $cell) {
                if ($cell['line_id'] === $securityLine || in_array($cell['cell_key'], $known, true)) continue;
                $member = $this->monetary($cell);
                self::assertCount(1, $cell['case_origins']);
                $member['case_origin'] = $cell['case_origins'][0];
                $evidence['non_security_members'][] = $member;
            }
        }
        $next = $this->service->preview($this->supplier, $nextId, [], false, null, $evidence);
        self::assertTrue($next['complete'], json_encode($next['securities']['findings']));
        self::assertContains($original, array_column($next['securities']['cells'], 'line_id'));
        if ($mixed) self::assertSame(['loss' => '0.00', 'gain' => $reverse ? '1000.00' : '0.00'], $next['totals']);
        self::assertCount(1, $next['securities']['opening_entry_ids']);
        $openingId = $next['securities']['opening_entry_ids'][0];
        $openingStep = $pdo->prepare("SELECT payload FROM accounting_closing_steps WHERE supplier_id=? AND period_id=? AND step_key='open_next'");
        $openingStep->execute([$this->supplier, $this->period]);
        $originalPayload = $openingStep->fetchColumn();
        self::assertIsString($originalPayload);
        $tamperedPayload = json_decode($originalPayload, true, 512, JSON_THROW_ON_ERROR);
        $tamperedPayload['carry']['open_next']['entry_date'] = '2099-01-01';
        $updateOpeningStep = $pdo->prepare("UPDATE accounting_closing_steps SET payload=? WHERE supplier_id=? AND period_id=? AND step_key='open_next'");
        $updateOpeningStep->execute([json_encode($tamperedPayload, JSON_THROW_ON_ERROR), $this->supplier, $this->period]);
        $tamperedCarry = $this->service->preview($this->supplier, $nextId, [], false, null, $evidence);
        self::assertFalse($tamperedCarry['complete']);
        self::assertContains('securities_opening_unproven', array_column($tamperedCarry['securities']['findings'], 'code'));
        self::assertNotSame($next['snapshot_hash'], $tamperedCarry['snapshot_hash']);
        $updateOpeningStep->execute([$originalPayload, $this->supplier, $this->period]);
        $restoredCarry = $this->service->preview($this->supplier, $nextId, [], false, null, $evidence);
        self::assertTrue($restoredCarry['complete'], json_encode($restoredCarry['securities']['findings']));
        self::assertSame($next['snapshot_hash'], $restoredCarry['snapshot_hash']);
        $pdo->prepare("UPDATE journal_entry_lines l JOIN chart_of_accounts a ON a.id=l.account_id SET l.amount_foreign=500 WHERE l.supplier_id=? AND l.entry_id=? AND a.account_code IN ('251','069')")->execute([$this->supplier, $openingId]);
        $changed = $this->service->preview($this->supplier, $nextId, [], false, null, $evidence);
        self::assertFalse($changed['complete']);
        self::assertContains('securities_opening_unproven', array_column($changed['securities']['findings'], 'code'));
        self::assertNotSame($next['snapshot_hash'], $changed['snapshot_hash']);
    }

    /**
     * Storno zápisu uzavřeného roku do otevřeného roku (např. rozpuštění dohadu) změní jen
     * příznak stornování původního řádku. Zahajovací zápis zůstává doložený, jinak by kurzový
     * krok 504 druhého roku zablokoval každou účetní jednotku, která běžně stornuje dohady.
     */
    public function testLaterReversalOfClosedYearEntryKeepsAnnualOpeningProven(): void
    {
        $evidence = $this->evidence();
        $pdo = $this->db->pdo();
        $container = Bootstrap::buildContainer();
        $closing = $container->get(\MyInvoice\Service\Accounting\Closing\ClosingService::class);
        foreach (['901', '911', '931', '932', '933', '961', '962'] as $code) {
            $pdo->prepare("INSERT IGNORE INTO chart_of_accounts(supplier_id,account_code,name,account_type,is_active) VALUES (?,?,?,'equity',1)")->execute([$this->supplier, $code, 'Synthetic ' . $code]);
        }
        $estimate = $container->get(PostingService::class)->postDocument($this->supplier, 'manual', null, [
            ['account_code' => '911', 'side' => 'debit', 'amount' => '100.00'],
            ['account_code' => '901', 'side' => 'credit', 'amount' => '100.00'],
        ], ['entry_date' => '2025-12-30', 'posted' => true]);
        $pdo->prepare("UPDATE accounting_periods SET status='closing' WHERE supplier_id=? AND id=?")->execute([$this->supplier, $this->period]);
        $version = fn (): int => (int) $pdo->query('SELECT row_version FROM accounting_periods WHERE id=' . $this->period)->fetchColumn();
        $inventory = [];
        foreach ($closing->inventoryPreview($this->supplier, $this->period)['rows'] as $row) $inventory[(int) $row['account_id']] = ['counted_balance' => (float) $row['book_balance'], 'resolution' => 'resolved', 'note' => null];
        $closing->saveInventory($this->supplier, $this->period, $version(), ['complete' => true], $inventory);
        $closing->runPrecheck($this->supplier, $this->period, $version());
        foreach (['depreciation', 'estimates', 'deferrals', 'provisions', 'income_tax'] as $step) $closing->confirmStep($this->supplier, $this->period, $step, 'skipped', null, $version());
        $this->service->run($this->supplier, $this->period, ['snapshot_hash' => $this->preview($evidence)['snapshot_hash'], 'securities_evidence' => $evidence], $version());
        \MyInvoice\Tests\Support\NonprofitTaxAssessmentFixture::confirm($container, $this->supplier, $this->period, (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn());
        $closing->closeBooks($this->supplier, $this->period, $version());
        $nextId = (int) $closing->openNext($this->supplier, $this->period, $version())['next_period_id'];
        $pdo->exec("INSERT INTO exchange_rates(rate_date,currency_code,rate) VALUES ('2026-12-31','EUR',24) ON DUPLICATE KEY UPDATE rate=24");
        $evidence['profile']['as_of'] = '2026-12-31';
        $nextFacts = $evidence['profile']['category_facts'][0];
        $nextFacts['period_id'] = $nextId;
        $nextFacts['starts_on'] = '2026-01-01';
        $nextFacts['ends_on'] = '2026-12-31';
        $evidence['profile']['category_facts'][] = $nextFacts;
        $evidence['valuations'][0]['as_of'] = '2026-12-31';
        $evidence['valuations'][0]['evidence']['valuation_date'] = '2026-12-31';
        $evidence['valuations'][0]['evidence']['recoverability_date'] = '2026-12-31';
        $before = $this->service->preview($this->supplier, $nextId, [], false, null, $evidence);
        self::assertTrue($before['complete'], json_encode($before['securities']['findings']));
        $container->get(PostingService::class)->reverse($this->supplier, $estimate, ['entry_date' => '2026-01-15']);
        $after = $this->service->preview($this->supplier, $nextId, [], false, null, $evidence);
        self::assertSame([], $after['securities']['findings']);
        self::assertTrue($after['complete']);
        self::assertSame($before['securities']['opening_entry_ids'], $after['securities']['opening_entry_ids']);
    }

    public function testMixed069SingleOriginalCellPartitionsExactlyWithoutHidingMonetaryRemainder(): void
    {
        $this->moveHolding('069');
        $initial = $this->service->preview($this->supplier, $this->period);
        self::assertCount(1, $initial['securities']['cells'], '069 must participate in the original inventory.');
        $evidence = $this->evidence();
        $cell = $initial['securities']['cells'][0];
        $valuation = &$evidence['valuations'][0];
        $valuation['members'][0]['book_czk'] = '12000.00';
        $valuation['members'][0]['book_foreign'] = '500.00';
        $valuation['evidence']['quantity'] = $valuation['evidence']['acquired_quantity'] = '5.000000';
        $valuation['evidence']['acquisition_cost_czk'] = $valuation['target_czk'] = '12000.00';
        unset($valuation);
        $evidence['non_security_members'] = [['source_cell_hash' => $cell['cell_key'], 'book_czk' => '12000.00', 'book_foreign' => '500.00',
            'nature' => 'monetary', 'contract_reference' => 'SYN-LOAN-CONTRACT-EXACT-PORTION',
            'case_origin' => ['kind' => 'declared_title', 'origin_line_id' => $cell['line_id'], 'reference' => 'SYN-LOAN-001']]];
        $preview = $this->preview($evidence);
        self::assertTrue($preview['complete'], json_encode($preview['securities']['findings']));
        self::assertCount(1, $preview['positions']);
        self::assertSame('12000.00', $preview['positions'][0]['booked_czk']);
        self::assertSame('500.00', $preview['positions'][0]['foreign_balance']);
        self::assertSame($this->dims, $preview['positions'][0]['dimensions']);
        $evidence['non_security_members'][0]['book_czk'] = '12000.01';
        self::assertFalse($this->preview($evidence)['complete']);
    }

    public function testOwn255RemainsExplicitlyUnsupportedDespiteAccountMonetaryDecision(): void
    {
        $this->moveHolding('255');
        $preview = $this->service->preview($this->supplier, $this->period, ['255' => ['classification' => 'monetary', 'reason' => 'SYN-ACCOUNT-CLASSIFICATION']]);
        self::assertCount(1, $preview['securities']['cells']);
        self::assertContains('securities_own_instrument_unsupported', array_column($preview['securities']['findings'], 'code'));
        self::assertFalse($preview['complete']);
    }

    public function testSeparate069LoanUsesCanonicalGrossCaseAndDoesNotSelfInvalidateTypedPosting(): void
    {
        $this->moveHolding('069');
        $evidence = $this->evidence();
        $entry = $this->postLoan('069');
        $initial = $this->service->preview($this->supplier, $this->period);
        $cell = array_values(array_filter($initial['securities']['cells'], static fn (array $cell): bool => $cell['entry_id'] === $entry))[0];
        $evidence['non_security_members'] = [$this->monetary($cell)];
        $preview = $this->preview($evidence);
        self::assertTrue($preview['complete'], json_encode($preview['securities']['findings']));
        self::assertSame(['loss' => '0.00', 'gain' => '1000.00'], $preview['totals']);
        self::assertSame('23000.00', $preview['positions'][0]['booked_czk']);
        $pdo = $this->db->pdo();
        $pdo->prepare("UPDATE accounting_periods SET status='closing' WHERE supplier_id=? AND id=?")->execute([$this->supplier, $this->period]);
        $result = $this->service->run($this->supplier, $this->period, ['snapshot_hash' => $preview['snapshot_hash'], 'securities_evidence' => $evidence], 1);
        self::assertSame($preview['totals'], $result['totals']);
        $after = $this->preview($evidence);
        self::assertSame($preview['snapshot_hash'], $after['snapshot_hash']);
        self::assertTrue($after['complete']);
        self::assertSame($preview['totals'], $after['totals']);
        $this->postLoan('315');
        self::assertNotSame($preview['snapshot_hash'], $this->preview($evidence)['snapshot_hash']);
        self::assertTrue($pdo->inTransaction());
    }

    public function testOptionalAccountIncludesAllZeroNetCellsAndCannotHideOneMember(): void
    {
        $evidence = $this->evidence();
        $this->moveHolding('315');
        $pdo = $this->db->pdo();
        $account = (int) $pdo->query("SELECT id FROM chart_of_accounts WHERE supplier_id=" . $this->supplier . " AND account_code='315'")->fetchColumn();
        $evidence['inventory_account_ids'] = [$account];
        $initial = $this->preview($evidence);
        self::assertCount(1, $initial['securities']['cells']);
        $cell = $initial['securities']['cells'][0];
        $evidence['valuations'][0]['members'][0]['source_cell_hash'] = $cell['cell_key'];
        self::assertTrue($this->preview($evidence)['complete']);
        Bootstrap::buildContainer()->get(PostingService::class)->postDocument($this->supplier, 'manual', null, [
            ['account_code' => '315', 'side' => 'credit', 'amount' => '24000.00', 'amount_foreign' => '1000.00', 'currency_code' => 'EUR', 'fx_rate' => '24.000000', 'dimensions' => $this->dims],
            ['account_code' => '901', 'side' => 'debit', 'amount' => '24000.00', 'dimensions' => $this->dims],
        ], ['entry_date' => '2025-07-01', 'posted' => true]);
        $after = $this->preview($evidence);
        self::assertCount(2, $after['securities']['cells']);
        self::assertFalse($after['complete']);
        self::assertContains('securities_inventory_incomplete', array_column($after['securities']['findings'], 'code'));
    }

    public function testPartialNativeDocumentCannotBeAllocatedProportionallyToAMonetaryRemainder(): void
    {
        $this->moveHolding('069');
        $evidence = $this->evidence();
        $cell = $this->preview($evidence)['securities']['cells'][0];
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO clients(supplier_id,company_name,street,city,zip,country_id,main_email,currency_default_id) SELECT id,'Synthetic mixed title','Test','Test','10000',country_id,'mixed@example.com',default_currency_id FROM supplier WHERE id=?")->execute([$this->supplier]);
        $client = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO invoices(supplier_id,varsymbol,invoice_type,client_id,issue_date,tax_date,due_date,currency_id,exchange_rate,total_without_vat,total_vat,total_with_vat,status,vat_classification_code,created_by) SELECT id,'2025000001','invoice',?,'2025-06-01','2025-06-01','2025-06-30',default_currency_id,1,24000,0,24000,'issued','1',(SELECT id FROM users ORDER BY id LIMIT 1) FROM supplier WHERE id=?")->execute([$client, $this->supplier]);
        $invoice = (int) $pdo->lastInsertId();
        $pdo->prepare("UPDATE journal_entries SET source_type='invoice',source_id=? WHERE supplier_id=? AND id=?")->execute([$invoice, $this->supplier, $cell['entry_id']]);
        $v = &$evidence['valuations'][0];
        $v['members'][0]['book_czk'] = $v['evidence']['acquisition_cost_czk'] = $v['target_czk'] = '12000.00';
        $v['members'][0]['book_foreign'] = '500.00';
        $v['evidence']['quantity'] = $v['evidence']['acquired_quantity'] = '5.000000';
        unset($v);
        $member = $this->monetary($cell);
        $member['book_czk'] = '12000.00';
        $member['book_foreign'] = '500.00';
        $member['case_origin'] = ['kind' => 'document', 'doc_type' => 'invoice', 'doc_id' => $invoice];
        $evidence['non_security_members'] = [$member];
        $preview = $this->preview($evidence);
        self::assertFalse($preview['complete']);
        self::assertContains('securities_monetary_allocation_unproven', array_column($preview['securities']['findings'], 'code'));
        self::assertSame([], $preview['positions']);
    }

    private function monetary(array $cell): array
    {
        return ['source_cell_hash' => $cell['cell_key'], 'book_czk' => $cell['book_czk'], 'book_foreign' => $cell['book_foreign'], 'nature' => 'monetary',
            'contract_reference' => 'SYN-LOAN-CONTRACT-' . $cell['line_id'], 'case_origin' => ['kind' => 'declared_title', 'origin_line_id' => $cell['line_id'], 'reference' => 'SYN-LOAN-' . $cell['line_id']]];
    }

    private function postLoan(string $code): int
    {
        $this->db->pdo()->prepare("INSERT IGNORE INTO chart_of_accounts(supplier_id,account_code,name,account_type,is_active) VALUES (?,?,?,'asset',1)")->execute([$this->supplier, $code, 'Synthetic ' . $code]);
        return Bootstrap::buildContainer()->get(PostingService::class)->postDocument($this->supplier, 'manual', null, [
            ['account_code' => $code, 'side' => 'debit', 'amount' => '23000.00', 'amount_foreign' => '1000.00', 'currency_code' => 'EUR', 'fx_rate' => '23.000000', 'dimensions' => $this->dims],
            ['account_code' => '901', 'side' => 'credit', 'amount' => '23000.00', 'dimensions' => $this->dims],
        ], ['entry_date' => '2025-07-01', 'posted' => true, 'allow_closing_period' => true]);
    }

    public function testUnverifiedThresholdOverrideCannotAuthorizeValuationEligibility(): void
    {
        $evidence = $this->evidence();
        $constants = \MyInvoice\Service\Tax\TaxConstants::forYear(2025);
        $constants['entity_category_thresholds']['micro']['assets_net'] = 12345678;
        $this->db->pdo()->prepare('INSERT INTO tax_constants(year,data) VALUES (?,?) ON DUPLICATE KEY UPDATE data=VALUES(data)')->execute([2025, json_encode($constants)]);
        $preview = $this->preview($evidence);
        self::assertFalse($preview['complete']);
        self::assertContains('securities_category_limits_unverified', array_column($preview['securities']['findings'], 'code'));
    }

    public function testMarketValuationNeedsLegallyEligibleEntityAndSpecificQuoteEvidence(): void
    {
        $evidence = $this->evidence();
        $evidence['valuations'][0]['instrument']['method'] = 'fair_value_market';
        $evidence['valuations'][0]['evidence'] += ['market' => 'SYN-REGULATED-MARKET', 'market_reference' => 'SYN-OFFICIAL-CLOSE-QUOTE',
            'market_type' => 'regulated', 'quote_currency' => 'EUR', 'market_calendar_reference' => 'SYN-TRADING-CALENDAR',
            'market_calendar_as_of' => '2025-12-31', 'last_trading_date' => '2025-12-31', 'unit_price_foreign' => '100.000000'];
        self::assertContains('securities_method_ineligible', array_column($this->preview($evidence)['securities']['findings'], 'code'));
        $evidence['profile']['micro_exception'] = 'investment_fund';
        $evidence['profile']['registration_reference'] = 'SYN-LICENCE';
        $evidence['profile']['registration_from'] = '2025-01-01';
        self::assertTrue($this->preview($evidence)['complete']);
        $evidence['valuations'][0]['evidence']['quote_currency'] = 'USD';
        self::assertFalse($this->preview($evidence)['complete']);
        $evidence['valuations'][0]['evidence']['quote_currency'] = 'EUR';
        $evidence['profile']['scope'] = 'simplified';
        self::assertContains('securities_method_ineligible', array_column($this->preview($evidence)['securities']['findings'], 'code'));
    }

    public function testFiveAxisValuationRetainsExactCurrentSourceTupleAfterRetirement(): void
    {
        $initial = $this->service->preview($this->supplier, $this->period)['securities']['cells'][0];
        $posting = Bootstrap::buildContainer()->get(PostingService::class);
        $posting->reverse($this->supplier, $initial['entry_id'], ['entry_date' => '2025-06-02']);
        $pdo = $this->db->pdo();
        foreach (['portfolio_region', 'portfolio_funding'] as $code) {
            $pdo->prepare('INSERT INTO dimension_types(supplier_id,code,name,is_active) VALUES (?,?,?,1)')->execute([$this->supplier, $code, 'Synthetic ' . $code]);
            $type = (int) $pdo->lastInsertId();
            $pdo->prepare("INSERT INTO dimension_values(type_id,supplier_id,code,name,is_active) VALUES (?,?,'exact','Synthetic exact value',1)")->execute([$type, $this->supplier]);
            $this->dims[$type] = (int) $pdo->lastInsertId();
        }
        $posting->postDocument($this->supplier, 'manual', null, [
            ['account_code' => '251', 'side' => 'debit', 'amount' => '24000.00', 'amount_foreign' => '1000.00', 'currency_code' => 'EUR', 'fx_rate' => '24.000000', 'dimensions' => $this->dims],
            ['account_code' => '901', 'side' => 'credit', 'amount' => '24000.00', 'dimensions' => $this->dims],
        ], ['entry_date' => '2025-06-01', 'posted' => true]);
        $evidence = $this->evidence();
        $pdo->exec("UPDATE exchange_rates SET rate=25 WHERE rate_date='2025-12-31' AND currency_code='EUR'");
        $evidence['valuations'][0]['target_czk'] = '25000.00';
        $evidence['valuations'][0]['posted_proof'] = [$this->valuationPosting('921')];
        $pdo->prepare('UPDATE dimension_values SET is_active=0 WHERE supplier_id=? AND id=?')->execute([$this->supplier, array_values($this->dims)[4]]);
        $preview = $this->preview($evidence);
        self::assertTrue($preview['complete'], json_encode($preview['securities']['findings']));
        foreach ($preview['securities']['cells'] as $cell) self::assertSame($this->dims, $cell['dimensions']);
        self::assertCount(5, $preview['securities']['cells'][0]['dimensions']);
    }

    public function testSecuritiesOnlyPayloadCannotBeSilentlyAcceptedBy500(): void
    {
        $this->db->pdo()->prepare("UPDATE supplier_accounting_frameworks SET accounting_framework='cz_500' WHERE supplier_id=?")->execute([$this->supplier]);
        $closing = Bootstrap::buildContainer()->get(\MyInvoice\Service\Accounting\Closing\ClosingService::class);
        foreach (['preview', 'run'] as $operation) {
            try {
                if ($operation === 'preview') $closing->fxPreview($this->supplier, $this->period, [], [], null, []);
                else $closing->runFxRevaluation($this->supplier, $this->period, [], 1, [], ['securities_evidence' => null]);
                self::fail('504 evidence must not be accepted by500.');
            } catch (\MyInvoice\Service\Accounting\Closing\ClosingException $e) {
                self::assertSame('fx_case_framework_unsupported', $e->errorCode);
            }
        }
        self::assertSame(0, (int) $this->db->pdo()->query('SELECT COUNT(*) FROM accounting_closing_steps WHERE supplier_id=' . $this->supplier)->fetchColumn());
    }

    #[\PHPUnit\Framework\Attributes\TestWith(['fair_value_market', 'trading', '251', '649', 'price_fx'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['fair_value_market', 'available_for_sale', '063', '921', 'price_fx'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['equity_method', 'participation', '061', '921', 'equity'])]
    public function testSpecificNonzeroValuationRoutesReconcileNativePostings(string $method, string $classification, string $asset, string $counterpart, string $component): void
    {
        $this->moveHolding($asset);
        $evidence = $this->evidence();
        $evidence['profile'] += ['registration_reference' => 'SYN-LICENCE', 'registration_from' => '2025-01-01'];
        $evidence['profile']['micro_exception'] = 'investment_fund';
        $v = &$evidence['valuations'][0];
        $v['instrument']['method'] = $method;
        $v['instrument']['classification'] = $classification;
        $v['instrument'] += ['influence' => 'control', 'influence_reference' => 'SYN-SHARE-VOTING-RIGHTS'];
        $v['evidence'] += ['market' => 'SYN-REGULATED-MARKET', 'market_reference' => 'SYN-CLOSE-QUOTE', 'market_type' => 'regulated', 'quote_currency' => 'EUR',
            'market_calendar_reference' => 'SYN-TRADING-CALENDAR', 'market_calendar_as_of' => '2025-12-31', 'last_trading_date' => '2025-12-31', 'unit_price_foreign' => '100.000000',
            'participation_fraction' => '0.100000', 'issuer_equity_foreign' => '10000.000000', 'issuer_statement_reference' => 'SYN-ISSUER-BS'];
        $v['target_czk'] = '25000.00';
        $this->db->pdo()->exec("UPDATE exchange_rates SET rate=25 WHERE rate_date='2025-12-31' AND currency_code='EUR'");
        $proof = $this->valuationPosting($counterpart, $asset);
        $proof['component'] = $component;
        $v['posted_proof'] = [$proof];
        unset($v);
        $preview = $this->preview($evidence);
        self::assertTrue($preview['complete'], json_encode($preview['securities']['findings']));
        self::assertSame('1000.00', $preview['securities']['valuations'][0]['change_czk']);
        self::assertSame(['loss' => '0.00', 'gain' => '0.00'], $preview['totals']);
    }

    public function testFutureValuationReversalDoesNotEraseProvenEarlierAsOf(): void
    {
        $evidence = $this->evidence();
        $this->db->pdo()->exec("UPDATE exchange_rates SET rate=25 WHERE rate_date='2025-12-31' AND currency_code='EUR'");
        $evidence['valuations'][0]['target_czk'] = '25000.00';
        $proof = $this->valuationPosting('921');
        $evidence['valuations'][0]['posted_proof'] = [$proof];
        self::assertTrue($this->preview($evidence)['complete']);
        Bootstrap::buildContainer()->get(AccountingPeriodRepository::class)->create($this->supplier, 2026, '2026-01-01', '2026-12-31');
        Bootstrap::buildContainer()->get(PostingService::class)->reverse($this->supplier, $proof['entry_id'], ['entry_date' => '2026-01-02']);
        $preview = $this->preview($evidence);
        self::assertTrue($preview['complete'], json_encode($preview['securities']['findings']));
        self::assertSame('1000.00', $preview['securities']['valuations'][0]['change_czk']);
    }

    public function testMalformedInventorySelectionProducesStructuredFinding(): void
    {
        $evidence = $this->evidence();
        $evidence['inventory_account_ids'] = '069';
        $preview = $this->preview($evidence);
        self::assertFalse($preview['complete']);
        self::assertContains('securities_inventory_selection_invalid', array_column($preview['securities']['findings'], 'code'));
    }

    public function testMalformedCaseDecisionUsesCanonicalDomainValidation(): void
    {
        $this->expectException(\MyInvoice\Service\Accounting\Closing\ClosingException::class);
        $this->service->preview($this->supplier, $this->period, [], false, ['broken'], $this->evidence());
    }

    public function testHomeCurrencyMonetaryPartitionStillRequiresCanonicalCurrentOrigin(): void
    {
        $evidence = $this->evidence();
        $this->moveHolding('069');
        $this->db->pdo()->prepare("UPDATE journal_entry_lines SET currency_code='CZK',amount_foreign=NULL,fx_rate=NULL WHERE supplier_id=?")->execute([$this->supplier]);
        $cell = $this->service->preview($this->supplier, $this->period)['securities']['cells'][0];
        $evidence['valuations'] = [];
        $member = $this->monetary($cell);
        $member['case_origin'] = ['kind' => 'document', 'doc_type' => 'invoice', 'doc_id' => 999999999];
        $evidence['non_security_members'] = [$member];
        self::assertFalse($this->preview($evidence)['complete']);
        $evidence['non_security_members'] = [$this->monetary($cell)];
        self::assertTrue($this->preview($evidence)['complete']);
        $evidence['non_security_members'][0]['case_origin']['origin_line_id'] = 999999999;
        self::assertFalse($this->preview($evidence)['complete']);
    }

    private function moveHolding(string $code): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT IGNORE INTO chart_of_accounts(supplier_id,account_code,name,account_type,is_active) VALUES (?,?,?,'asset',1)")->execute([$this->supplier, $code, 'Synthetic ' . $code]);
        $pdo->prepare('UPDATE journal_entry_lines l JOIN chart_of_accounts old ON old.id=l.account_id JOIN chart_of_accounts target ON target.supplier_id=l.supplier_id AND target.account_code=? SET l.account_id=target.id WHERE l.supplier_id=? AND old.account_code=\'251\'')->execute([$code, $this->supplier]);
    }

    private function valuationPosting(string $counterpart, string $asset = '251'): array
    {
        $entry = Bootstrap::buildContainer()->get(PostingService::class)->postDocument($this->supplier, 'manual', null, [
            ['account_code' => $asset, 'side' => 'debit', 'amount' => '1000.00', 'amount_foreign' => '0.00', 'currency_code' => 'EUR', 'fx_rate' => '25.000000', 'dimensions' => $this->dims],
            ['account_code' => $counterpart, 'side' => 'credit', 'amount' => '1000.00', 'dimensions' => $this->dims],
        ], ['entry_date' => '2025-12-31', 'posted' => true, 'allow_closing_period' => true]);
        $stmt = $this->db->pdo()->prepare('SELECT id FROM journal_entry_lines WHERE supplier_id=? AND entry_id=? ORDER BY line_no');
        $stmt->execute([$this->supplier, $entry]);
        $ids = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        return ['entry_id' => $entry, 'asset_line_id' => (int) $ids[0], 'counterpart_line_id' => (int) $ids[1], 'component' => 'fx', 'amount_czk' => '1000.00'];
    }

    private function preview(array $evidence): array
    {
        return $this->service->preview($this->supplier, $this->period, [], false, null, $evidence);
    }

    private function evidence(): array
    {
        $cell = $this->service->preview($this->supplier, $this->period)['securities']['cells'][0];
        $evidence=['profile'=>['scope'=>'full','category'=>'micro','micro_exception'=>'none','reference'=>'SYN-CATEGORY-2025','as_of'=>'2025-12-31','scope_reference'=>'SYN-FULL-BOOKS','inception_reference'=>'SYN-INCORPORATION','first_period_start'=>'2025-01-01','category_facts'=>[['period_id'=>$this->period,'starts_on'=>'2025-01-01','ends_on'=>'2025-12-31','assets_net'=>'24000.00','net_turnover'=>'0.00','average_employees'=>'0','statement_reference'=>'SYN-BS-2025','employees_reference'=>'SYN-PAYROLL-2025']]],
            'valuations'=>[['instrument'=>['kind'=>'equity','identity'=>'SYN-CUSTODY-001/ISSUER-001/CONTRACT-001','classification'=>'trading','method'=>'cost_equity','reference'=>'SYN-ACQUISITION-001','classification_reference'=>'SYN-INVESTMENT-POLICY','designated_on'=>'2025-06-01'],
                'as_of'=>'2025-12-31','evidence'=>['reference'=>'SYN-CUSTODY-2025','valuation_date'=>'2025-12-31','quantity'=>'10.000000','unit_cost_foreign'=>'100.000000','acquisition_cost_czk'=>'24000.00','acquisition_date'=>'2025-06-01','acquired_quantity'=>'10.000000','recoverability_reference'=>'SYN-ISSUER-CURRENT-VALUATION','recoverability_date'=>'2025-12-31','recoverable_unit_value_foreign'=>'100.000000'],
                'members'=>[['source_cell_hash'=>$cell['cell_key'],'book_czk'=>'24000.00','book_foreign'=>'1000.00']],
                'target_czk'=>'24000.00','posted_proof'=>[]]]];
        return $evidence;
    }
}
