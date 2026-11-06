<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Closing\{ClosingException, ClosingService};
use MyInvoice\Service\Accounting\Dimension\SystemDimensionCatalog;
use PHPUnit\Framework\TestCase;

final class ClosingLegal504Test extends TestCase
{
    private Connection $db;
    private ClosingService $service;
    private int $supplier;
    private int $period;
    private array $dimensions;
    private ?string $failureTrigger = null;
    private bool $committed = false;
    private ?\Psr\Container\ContainerInterface $other = null;
    private int $originalIsolation;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $pdo = $this->db->pdo();
        $this->originalIsolation = (int) $pdo->query('SELECT @@innodb_snapshot_isolation')->fetchColumn();
        if (in_array($this->name(), ['testOrdinaryFailureAfterJournalWriteRollsBackWithinCallerTransaction', 'testReversalPayloadFailureRollsBackItsJournalWithinCallerTransaction'], true)) {
            $this->failureTrigger = 'legal_step_' . bin2hex(random_bytes(6));
            $pdo->exec('CREATE TRIGGER ' . $this->failureTrigger . " BEFORE INSERT ON accounting_closing_steps FOR EACH ROW
                BEGIN IF @myucto_fail_legal_step=1 AND NEW.step_key='provisions' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic legal step failure'; END IF; END");
        }
        if ($this->name() === 'testTypedReleaseFailureAfterJournalRollsBackPendingAndVersion') {
            $this->failureTrigger = 'legal_step_' . bin2hex(random_bytes(6));
            $pdo->exec('CREATE TRIGGER ' . $this->failureTrigger . " BEFORE UPDATE ON accounting_closing_steps FOR EACH ROW
                BEGIN IF @myucto_fail_legal_step=1 AND NEW.step_key='provisions' AND JSON_UNQUOTE(JSON_EXTRACT(NEW.payload,'$.legal_pending.status'))='written'
                THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic legal CAS failure'; END IF; END");
        }
        $pdo->beginTransaction();
        $pdo->exec("INSERT INTO supplier(company_name,street,city,zip,email,country_id,default_currency_id,default_vat_rate_id,accounting_mode)
            SELECT 'Synthetic legal closing','Test 1','Test','10000','legal-closing@example.com',country_id,default_currency_id,default_vat_rate_id,'double_entry' FROM supplier ORDER BY id LIMIT 1");
        $this->supplier = (int) $pdo->lastInsertId();
        $this->period = $container->get(AccountingPeriodRepository::class)->create($this->supplier, 2096, '2096-07-01', '2097-06-30');
        $pdo->prepare("INSERT INTO supplier_accounting_frameworks(supplier_id,effective_from,accounting_framework) VALUES (?,'2096-07-01','cz_504')")->execute([$this->supplier]);
        $container->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplier, '2097-06-30');
        $catalog = (new SystemDimensionCatalog($this->db))->ensureForFramework($this->supplier, '2097-06-30');
        $pdo->prepare('INSERT INTO dimension_values(type_id,supplier_id,code,name) VALUES (?,?,?,?)')
            ->execute([$catalog['activity_kind']['id'], $this->supplier, 'synthetic_legal', 'Synthetic legal activity']);
        $this->dimensions = [$catalog['activity']['id'] => $catalog['activity']['values']['economic'],
            $catalog['activity_kind']['id'] => (int) $pdo->lastInsertId(),
            $catalog['tax_regime']['id'] => $catalog['tax_regime']['values']['subject']];
        $pdo->prepare("UPDATE accounting_periods SET status='closing' WHERE id=?")->execute([$this->period]);
        $this->service = $container->get(ClosingService::class);
    }

    protected function tearDown(): void
    {
        if ($this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
        if ($this->other !== null) $this->other->get(Connection::class)->close();
        if ($this->committed) {
            foreach (['activity_log', 'journal_entries', 'invoice_payments', 'invoices', 'clients'] as $table) {
                $this->db->pdo()->prepare('DELETE FROM ' . $table . ' WHERE supplier_id=?')->execute([$this->supplier]);
            }
            $this->db->pdo()->prepare('DELETE FROM supplier WHERE id=?')->execute([$this->supplier]);
        }
        $this->db->pdo()->exec('SET SESSION innodb_snapshot_isolation=' . $this->originalIsolation);
        $this->db->pdo()->exec('SET @myucto_fail_legal_step=NULL');
        if ($this->failureTrigger !== null) $this->db->pdo()->exec('DROP TRIGGER ' . $this->failureTrigger);
    }

    public function testLegalReserveUsesDated504RolesAndCompleteJointDimensions(): void
    {
        $result = $this->service->createAssistedEntry($this->supplier, $this->period, 'provisions', $this->body());
        $stmt = $this->db->pdo()->prepare('SELECT a.account_code,l.side,l.amount FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id=l.account_id WHERE l.entry_id=? ORDER BY l.id');
        $stmt->execute([$result['entry_id']]);
        self::assertSame([['account_code' => '556', 'side' => 'debit', 'amount' => '100.01'],
            ['account_code' => '941', 'side' => 'credit', 'amount' => '100.01']], $stmt->fetchAll(\PDO::FETCH_ASSOC));
        $stmt = $this->db->pdo()->prepare('SELECT d.dimension_type_id,d.dimension_value_id FROM journal_entry_line_dimensions d JOIN journal_entry_lines l ON l.id=d.line_id WHERE l.entry_id=? ORDER BY l.id,d.dimension_type_id');
        $stmt->execute([$result['entry_id']]);
        self::assertCount(6, $stmt->fetchAll());
        $stmt = $this->db->pdo()->prepare("SELECT payload FROM accounting_closing_steps WHERE supplier_id=? AND period_id=? AND step_key='provisions'");
        $stmt->execute([$this->supplier, $this->period]);
        $payload = json_decode($stmt->fetchColumn(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('SYN-RESERVE-1', $payload['entries'][0]['legal_decision']['reference']);
        self::assertSame($this->dimensions, $payload['entries'][0]['legal_decision']['allocations'][0]['dimensions']);
        $preview = $this->service->provisionsPreview($this->supplier, $this->period);
        self::assertSame($result['entry_id'], $preview['reserves'][0]['entry_id'] ?? null);
        self::assertSame(10001, $preview['reserves'][0]['cells'][0]['available_cents']);
    }

    public function testMissingEvidenceAndAccountingReserveAreRejectedBeforeJournal(): void
    {
        foreach (['missing', 'accounting'] as $case) {
            $body = $this->body();
            if ($case === 'missing') unset($body['legal_decision']);
            else $body['rule_key'] = 'reserve.other.create';
            try {
                $this->service->createAssistedEntry($this->supplier, $this->period, 'provisions', $body);
                self::fail('An unsupported or undocumented reserve must not be posted.');
            } catch (ClosingException $e) {
                self::assertSame('legal_closing_evidence_required', $e->errorCode);
            }
        }
        self::assertSame(0, $this->journalCount());
    }

    public function testFractionalCentIsRejectedBeforePosting(): void
    {
        $body = $this->body();
        $body['amount'] = '100.001';
        try {
            $this->service->createAssistedEntry($this->supplier, $this->period, 'provisions', $body);
            self::fail('Fractional cents must not be rounded into a decision.');
        } catch (ClosingException $e) {
            self::assertSame('validation_failed', $e->errorCode);
        }
        self::assertSame(0, $this->journalCount());
    }

    public function testPartialReleaseKeepsSourceTupleAndCannotSpendItTwice(): void
    {
        $source = $this->service->createAssistedEntry($this->supplier, $this->period, 'provisions', $this->body());
        foreach (['40.01', '60.00'] as $amount) {
            $result = $this->service->createAssistedEntry($this->supplier, $this->period, 'provisions', $this->release($source['entry_id'], $amount));
            $stmt = $this->db->pdo()->prepare('SELECT a.account_code,l.side,l.amount FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id=l.account_id WHERE l.entry_id=? ORDER BY l.id');
            $stmt->execute([$result['entry_id']]);
            self::assertSame([['account_code' => '941', 'side' => 'debit', 'amount' => $amount],
                ['account_code' => '556', 'side' => 'credit', 'amount' => $amount]], $stmt->fetchAll(\PDO::FETCH_ASSOC));
        }
        try {
            $this->service->createAssistedEntry($this->supplier, $this->period, 'provisions', $this->release($source['entry_id'], '0.01'));
            self::fail('A fully released reserve has no available cents.');
        } catch (ClosingException $e) { self::assertSame('legal_closing_source_exceeded', $e->errorCode); }
        self::assertSame(3, $this->journalCount());
    }

    public function testRealReversalRestoresReleaseCapacityButCorruptMirrorDoesNot(): void
    {
        $source = $this->service->createAssistedEntry($this->supplier, $this->period, 'provisions', $this->body());
        $release = $this->service->createAssistedEntry($this->supplier, $this->period, 'provisions', $this->release($source['entry_id'], '100.01'));
        $reversed = $this->service->reverseAssistedEntry($this->supplier, $this->period, $release['entry_id'], $this->body()['row_version']);
        $repo = new \MyInvoice\Service\Accounting\Closing\LegalClosing504Repository($this->db, Bootstrap::buildContainer()->get(\MyInvoice\Repository\JournalEntryRepository::class));
        self::assertSame(10001, array_sum($repo->reserveRemaining($this->supplier, $source['entry_id'], '2097-06-30')));
        $this->db->pdo()->prepare('UPDATE journal_entry_lines SET amount=amount+1 WHERE entry_id=?')->execute([$reversed['reversal_entry_id']]);
        try {
            $this->service->createAssistedEntry($this->supplier, $this->period, 'provisions', $this->release($source['entry_id'], '100.01'));
            self::fail('A corrupt reversal cannot restore capacity.');
        } catch (ClosingException $e) { self::assertSame('legal_closing_source_changed', $e->errorCode); }
    }

    public function testUsedReserveCannotBeReversedEvenAfterUnrelatedCredit(): void
    {
        $source = $this->service->createAssistedEntry($this->supplier, $this->period, 'provisions', $this->body());
        $this->service->createAssistedEntry($this->supplier, $this->period, 'provisions', $this->release($source['entry_id'], '40.01'));
        Bootstrap::buildContainer()->get(\MyInvoice\Service\Accounting\PostingService::class)->postDocument($this->supplier, 'manual', null, [
            ['account_code' => '556', 'side' => 'debit', 'amount' => 40.01, 'dimensions' => $this->dimensions, 'dimension_origins' => array_map(static fn (): array => ['origin' => 'explicit', 'ref' => 'synthetic-test'], $this->dimensions)],
            ['account_code' => '941', 'side' => 'credit', 'amount' => 40.01, 'dimensions' => $this->dimensions, 'dimension_origins' => array_map(static fn (): array => ['origin' => 'explicit', 'ref' => 'synthetic-test'], $this->dimensions)],
        ], ['entry_date' => '2097-06-30', 'posted' => true, 'allow_closing_period' => true]);
        try {
            $this->service->reverseAssistedEntry($this->supplier, $this->period, $source['entry_id'], $this->body()['row_version']);
            self::fail('Using a reserve prevents reversing its creation.');
        } catch (ClosingException $e) { self::assertSame('legal_closing_source_used', $e->errorCode); }
        self::assertSame(3, $this->journalCount());
    }

    public function testFullyReversedUsageAllowsReversingOriginalReserve(): void
    {
        $source = $this->service->createAssistedEntry($this->supplier, $this->period, 'provisions', $this->body());
        $release = $this->service->createAssistedEntry($this->supplier, $this->period, 'provisions', $this->release($source['entry_id'], '100.01'));
        $this->service->reverseAssistedEntry($this->supplier, $this->period, $release['entry_id'], $this->body()['row_version']);
        $result = $this->service->reverseAssistedEntry($this->supplier, $this->period, $source['entry_id'], $this->body()['row_version']);
        self::assertGreaterThan(0, $result['reversal_entry_id']);
        self::assertSame(4, $this->journalCount());
    }

    public function testReversalOfReleaseReversalRestoresUsedCapacity(): void
    {
        $source = $this->service->createAssistedEntry($this->supplier, $this->period, 'provisions', $this->body());
        $release = $this->service->createAssistedEntry($this->supplier, $this->period, 'provisions', $this->release($source['entry_id'], '40.01'));
        $mirror = $this->service->reverseAssistedEntry($this->supplier, $this->period, $release['entry_id'], $this->body()['row_version']);
        $container = Bootstrap::buildContainer();
        $container->get(\MyInvoice\Service\Accounting\PostingService::class)->reverse($this->supplier, $mirror['reversal_entry_id'],
            ['entry_date' => '2097-06-30', 'allow_closing_period' => true]);
        $repo = new \MyInvoice\Service\Accounting\Closing\LegalClosing504Repository($this->db, $container->get(\MyInvoice\Repository\JournalEntryRepository::class));
        self::assertSame(6000, array_sum($repo->reserveRemaining($this->supplier, $source['entry_id'], '2097-06-30')));
        $this->service->createAssistedEntry($this->supplier, $this->period, 'provisions', $this->release($source['entry_id'], '60.00'));
        self::assertSame(0, array_sum($repo->reserveRemaining($this->supplier, $source['entry_id'], '2097-06-30')));
    }

    public function testMirrorClaimedByTwoSourcesCannotRestoreCapacity(): void
    {
        $source = $this->service->createAssistedEntry($this->supplier, $this->period, 'provisions', $this->body());
        $release = $this->service->createAssistedEntry($this->supplier, $this->period, 'provisions', $this->release($source['entry_id'], '100.01'));
        $mirror = $this->service->reverseAssistedEntry($this->supplier, $this->period, $release['entry_id'], $this->body()['row_version']);
        $other = $this->service->createAssistedEntry($this->supplier, $this->period, 'provisions', $this->body());
        $this->db->pdo()->prepare('UPDATE journal_entries SET reversed_by=? WHERE id=?')->execute([$mirror['reversal_entry_id'], $other['entry_id']]);
        try {
            $this->service->createAssistedEntry($this->supplier, $this->period, 'provisions', $this->release($source['entry_id'], '100.01'));
            self::fail('A reused mirror is not an exact source reversal proof.');
        } catch (ClosingException $e) { self::assertSame('legal_closing_source_changed', $e->errorCode); }
    }

    public function testOrdinaryFailureAfterJournalWriteRollsBackWithinCallerTransaction(): void
    {
        $body = $this->body();
        $this->db->pdo()->exec('SET @myucto_fail_legal_step=1');
        try {
            $this->service->createAssistedEntry($this->supplier, $this->period, 'provisions', $body);
            self::fail('The synthetic step failure must reach the caller.');
        } catch (\PDOException $e) { self::assertSame('45000', $e->errorInfo[0]); }
        self::assertTrue($this->db->pdo()->inTransaction());
        self::assertSame(0, $this->journalCount());
        self::assertSame($body['row_version'], $this->body()['row_version']);
        $this->db->pdo()->exec('SET @myucto_fail_legal_step=0');
        $this->service->createAssistedEntry($this->supplier, $this->period, 'provisions', $body);
        self::assertSame(1, $this->journalCount());
    }

    public function testLegalProvisionUsesTargetJointCellsAndPreservesReserveHistory(): void
    {
        $reserve = $this->service->createAssistedEntry($this->supplier, $this->period, 'provisions', $this->body());
        $invoice = $this->invoice();
        $first = $this->runProvision($invoice, '50.01');
        $stmt = $this->db->pdo()->prepare('SELECT a.account_code,l.side,l.amount FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id=l.account_id WHERE l.entry_id=? ORDER BY l.id');
        $stmt->execute([$first['entries'][0]['entry_id']]);
        self::assertSame([['account_code' => '559', 'side' => 'debit', 'amount' => '50.01'],
            ['account_code' => '391', 'side' => 'credit', 'amount' => '50.01']], $stmt->fetchAll(\PDO::FETCH_ASSOC));
        $second = $this->runProvision($invoice, '20.00');
        $stmt->execute([$second['entries'][0]['entry_id']]);
        self::assertSame([['account_code' => '391', 'side' => 'debit', 'amount' => '30.01'],
            ['account_code' => '559', 'side' => 'credit', 'amount' => '30.01']], $stmt->fetchAll(\PDO::FETCH_ASSOC));
        $step = $this->db->pdo()->prepare("SELECT payload FROM accounting_closing_steps WHERE supplier_id=? AND period_id=? AND step_key='provisions'");
        $step->execute([$this->supplier, $this->period]);
        $payload = json_decode($step->fetchColumn(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($reserve['entry_id'], $payload['entries'][0]['entry_id']);
        self::assertCount(3, $payload['entries']);
    }

    public function testPaidReceivableAllowsExactReleaseAndRejectsNewAllowance(): void
    {
        $invoice = $this->invoice();
        $this->runProvision($invoice, '50.01');
        $this->db->pdo()->prepare("INSERT INTO invoice_payments(supplier_id,invoice_id,paid_on,amount,currency,source) VALUES (?,?,'2097-06-30',100,'CZK','manual')")
            ->execute([$this->supplier, $invoice]);
        $released = $this->runProvision($invoice, '0.00');
        self::assertSame(0.0, (float) $released['entries'][0]['legal_amount']);
        self::assertGreaterThan(0, $released['entries'][0]['entry_id']);
        try {
            $this->runProvision($invoice, '0.01');
            self::fail('An already paid receivable cannot support an allowance.');
        } catch (ClosingException $e) { self::assertSame('provision_exceeds_receivable', $e->errorCode); }
    }

    public function testLegalPreviewShowsActualAllowanceAndPaidReceivableNeedingRelease(): void
    {
        $invoice = $this->invoice();
        $this->runProvision($invoice, '50.01');
        $count = $this->journalCount();
        $preview = $this->service->provisionsPreview($this->supplier, $this->period);
        self::assertSame('cz_504', $preview['accounting_framework'] ?? null);
        self::assertSame('559', $preview['items'][0]['legal_account']);
        self::assertSame(50.01, $preview['items'][0]['existing']['legal_amount']);
        self::assertSame($this->dimensions, $preview['items'][0]['existing']['allocations'][0]['dimensions']);
        self::assertFalse($preview['accounting_provisions_available']);
        $this->db->pdo()->prepare("INSERT INTO invoice_payments(supplier_id,invoice_id,paid_on,amount,currency,source) VALUES (?,?,'2097-06-30',100,'CZK','manual')")
            ->execute([$this->supplier, $invoice]);
        $preview = $this->service->provisionsPreview($this->supplier, $this->period);
        self::assertCount(1, $preview['items']);
        self::assertSame(0.0, $preview['items'][0]['remaining']);
        self::assertSame('release_required', $preview['items'][0]['required_action']);
        self::assertSame($count, $this->journalCount());
    }

    public function testAssistedApiCarriesDecisionAndDoesNotRoundFractionalCents(): void
    {
        $action = Bootstrap::buildContainer()->get(\MyInvoice\Action\Accounting\Closing\ClosingAction::class);
        $request = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST', '/')
            ->withAttribute(\MyInvoice\Middleware\SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplier)
            ->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_USER, ['id' => 1, 'role' => 'admin']);
        $response = $action->createEntry($request->withParsedBody($this->body() + ['step' => 'provisions']), new \Slim\Psr7\Response(), ['id' => $this->period]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $body = $this->body() + ['step' => 'provisions'];
        $body['amount'] = '100.001';
        $response = $action->createEntry($request->withParsedBody($body), new \Slim\Psr7\Response(), ['id' => $this->period]);
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('validation_failed', (string) $response->getBody());
        self::assertSame(1, $this->journalCount());
    }

    public function testEarlierAllowanceCannotBeReversedAfterItsReleaseOrReplacement(): void
    {
        $invoice = $this->invoice();
        $first = $this->runProvision($invoice, '50.01')['entries'][0]['entry_id'];
        $this->runProvision($invoice, '20.00');
        $this->runProvision($invoice, '70.01');
        $before = $this->journalCount();
        try {
            $this->service->reverseAssistedEntry($this->supplier, $this->period, $first, $this->body()['row_version']);
            self::fail('Later allowance decisions depend on the original target.');
        } catch (ClosingException $e) { self::assertSame('legal_closing_source_used', $e->errorCode); }
        self::assertSame($before, $this->journalCount());
    }

    public function testReversingReleaseCannotRestoreAllowanceAbovePaidReceivable(): void
    {
        $invoice = $this->invoice();
        $this->runProvision($invoice, '50.01');
        $this->db->pdo()->prepare("INSERT INTO invoice_payments(supplier_id,invoice_id,paid_on,amount,currency,source) VALUES (?,?,'2097-06-30',100,'CZK','manual')")
            ->execute([$this->supplier, $invoice]);
        $release = $this->runProvision($invoice, '0.00')['entries'][0]['entry_id'];
        $before = $this->journalCount();
        try {
            $this->service->reverseAssistedEntry($this->supplier, $this->period, $release, $this->body()['row_version']);
            self::fail('The restored allowance cannot exceed the current unpaid receivable.');
        } catch (ClosingException $e) { self::assertSame('provision_exceeds_receivable', $e->errorCode); }
        self::assertSame($before, $this->journalCount());
    }

    public function testReversalPayloadFailureRollsBackItsJournalWithinCallerTransaction(): void
    {
        $source = $this->service->createAssistedEntry($this->supplier, $this->period, 'provisions', $this->body());
        $version = $this->body()['row_version'];
        $this->db->pdo()->exec('SET @myucto_fail_legal_step=1');
        try {
            $this->service->reverseAssistedEntry($this->supplier, $this->period, $source['entry_id'], $version);
            self::fail('The synthetic step failure must reach the caller.');
        } catch (\PDOException $e) { self::assertSame('45000', $e->errorInfo[0]); }
        self::assertTrue($this->db->pdo()->inTransaction());
        self::assertSame(1, $this->journalCount());
        self::assertSame($version, $this->body()['row_version']);
        $this->db->pdo()->exec('SET @myucto_fail_legal_step=0');
        $this->service->reverseAssistedEntry($this->supplier, $this->period, $source['entry_id'], $version);
        self::assertSame(2, $this->journalCount());
    }

    public function testUnlinkedReserveUsageCannotBeHiddenByUnrelatedCreditButExactReversalRestoresIt(): void
    {
        $source = $this->service->createAssistedEntry($this->supplier, $this->period, 'provisions', $this->body());
        $posting = Bootstrap::buildContainer()->get(\MyInvoice\Service\Accounting\PostingService::class);
        $used = $posting->postDocument($this->supplier, 'manual', null, [
            ['account_code' => '941', 'side' => 'debit', 'amount' => 40, 'dimensions' => $this->dimensions, 'dimension_origins' => array_map(static fn (): array => ['origin' => 'explicit', 'ref' => 'synthetic-test'], $this->dimensions)],
            ['account_code' => '556', 'side' => 'credit', 'amount' => 40, 'dimensions' => $this->dimensions, 'dimension_origins' => array_map(static fn (): array => ['origin' => 'explicit', 'ref' => 'synthetic-test'], $this->dimensions)],
        ], ['entry_date' => '2097-06-30', 'posted' => true, 'allow_closing_period' => true]);
        $posting->postDocument($this->supplier, 'manual', null, [
            ['account_code' => '556', 'side' => 'debit', 'amount' => 40, 'dimensions' => $this->dimensions, 'dimension_origins' => array_map(static fn (): array => ['origin' => 'explicit', 'ref' => 'synthetic-test'], $this->dimensions)],
            ['account_code' => '941', 'side' => 'credit', 'amount' => 40, 'dimensions' => $this->dimensions, 'dimension_origins' => array_map(static fn (): array => ['origin' => 'explicit', 'ref' => 'synthetic-test'], $this->dimensions)],
        ], ['entry_date' => '2097-06-30', 'posted' => true, 'allow_closing_period' => true]);
        try {
            $this->service->createAssistedEntry($this->supplier, $this->period, 'provisions', $this->release($source['entry_id'], '100.01'));
            self::fail('An unrelated credit is not proof that the source usage was reversed.');
        } catch (ClosingException $e) { self::assertSame('legal_closing_source_changed', $e->errorCode); }
        $posting->reverse($this->supplier, $used, ['entry_date' => '2097-06-30', 'allow_closing_period' => true]);
        $result = $this->service->createAssistedEntry($this->supplier, $this->period, 'provisions', $this->release($source['entry_id'], '100.01'));
        self::assertGreaterThan(0, $result['entry_id']);
    }

    public function testRetiredReserveActivityCanBeReleasedOnlyThroughVerifiedSource(): void
    {
        $source = $this->service->createAssistedEntry($this->supplier, $this->period, 'provisions', $this->body());
        $kind = array_values($this->dimensions)[1];
        $this->db->pdo()->prepare('UPDATE dimension_values SET is_active=0 WHERE id=? AND supplier_id=?')->execute([$kind, $this->supplier]);
        $released = $this->service->createAssistedEntry($this->supplier, $this->period, 'provisions', $this->release($source['entry_id'], '40.01'));
        self::assertGreaterThan(0, $released['entry_id']);
        $statement = $this->db->pdo()->prepare('SELECT COUNT(*) FROM journal_entry_line_dimensions d JOIN journal_entry_lines l ON l.id=d.line_id WHERE l.entry_id=? AND d.dimension_value_id=?');
        $statement->execute([$released['entry_id'], $kind]);
        self::assertSame(2, (int) $statement->fetchColumn());
        try {
            $this->service->createAssistedEntry($this->supplier, $this->period, 'provisions', $this->body());
            self::fail('A new unrelated reserve cannot reuse the retired value.');
        } catch (ClosingException $e) { self::assertSame('dimension_closed', $e->errorCode); }
        $repo = Bootstrap::buildContainer()->get(\MyInvoice\Repository\JournalEntryRepository::class);
        self::assertSame($released['entry_id'], $repo->insertCurrentLegalClosing($this->supplier, $this->period, $this->body()['row_version'] - 1));
        self::assertSame(2, $this->journalCount());
    }

    public function testTypedReleaseFailureAfterJournalRollsBackPendingAndVersion(): void
    {
        $source = $this->service->createAssistedEntry($this->supplier, $this->period, 'provisions', $this->body());
        $body = $this->release($source['entry_id'], '40.01');
        $before = $this->db->pdo()->query('SELECT payload FROM accounting_closing_steps WHERE supplier_id=' . $this->supplier)->fetchColumn();
        $this->db->pdo()->exec('SET @myucto_fail_legal_step=1');
        try {
            $this->service->createAssistedEntry($this->supplier, $this->period, 'provisions', $body);
            self::fail('The synthetic CAS failure must abort the whole legal operation.');
        } catch (\PDOException $e) { self::assertSame('45000', $e->errorInfo[0]); }
        self::assertTrue($this->db->pdo()->inTransaction());
        self::assertSame(1, $this->journalCount());
        self::assertSame($body['row_version'], $this->body()['row_version']);
        self::assertSame($before, $this->db->pdo()->query('SELECT payload FROM accounting_closing_steps WHERE supplier_id=' . $this->supplier)->fetchColumn());
    }

    public function testCurrentSourceUsageAfterSnapshotLimitsTypedRelease(): void
    {
        $this->concurrentRelease(false);
    }

    public function testCurrentSourceUsageUnderSnapshotIsolationAbortsOuterTransaction(): void
    {
        $this->concurrentRelease(true);
    }

    private function concurrentRelease(bool $isolation): void
    {
        $source = $this->service->createAssistedEntry($this->supplier, $this->period, 'provisions', $this->body());
        $body = $this->release($source['entry_id'], '40.01');
        $pdo = $this->db->pdo();
        $pdo->commit();
        $this->committed = true;
        $pdo->exec('SET SESSION innodb_snapshot_isolation=' . ($isolation ? 'ON' : 'OFF'));
        $pdo->beginTransaction();
        $pdo->query('SELECT payload FROM accounting_closing_steps WHERE supplier_id=' . $this->supplier)->fetchAll();
        $this->other = Connection::withoutSharedTestConnection(static function (): \Psr\Container\ContainerInterface {
            $container = Bootstrap::buildContainer();
            $container->get(Connection::class)->pdo();
            return $container;
        });
        self::assertNotSame($pdo->query('SELECT CONNECTION_ID()')->fetchColumn(), $this->other->get(Connection::class)->pdo()->query('SELECT CONNECTION_ID()')->fetchColumn());
        $this->other->get(ClosingService::class)->createAssistedEntry($this->supplier, $this->period, 'provisions', $body);
        $body = $this->release($source['entry_id'], '100.01');
        $body['row_version'] = $this->other->get(AccountingPeriodRepository::class)->findById($this->supplier, $this->period)['row_version'];
        try {
            $this->service->createAssistedEntry($this->supplier, $this->period, 'provisions', $body);
            self::fail('A stale source must not be released twice.');
        } catch (ClosingException $e) {
            self::assertFalse($isolation);
            self::assertSame('legal_closing_source_exceeded', $e->errorCode);
            self::assertTrue($pdo->inTransaction());
        } catch (\PDOException $e) {
            self::assertTrue($isolation);
            self::assertSame(1020, (int) $e->errorInfo[1]);
            self::assertFalse($pdo->inTransaction());
        }
        self::assertSame(2, (int) $this->other->get(Connection::class)->pdo()->query('SELECT COUNT(*) FROM journal_entries WHERE supplier_id=' . $this->supplier)->fetchColumn());
    }

    #[\PHPUnit\Framework\Attributes\TestWith(['closed'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['reviewed'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['approved'])]
    public function testReserveSurvivesActualCloseAndOpeningWithRetiredActivity(string $sourceStatus): void
    {
        $source = $this->service->createAssistedEntry($this->supplier, $this->period, 'provisions', $this->body());
        Bootstrap::buildContainer()->get(\MyInvoice\Service\Accounting\PostingService::class)->postDocument($this->supplier, 'manual', null, [
            ['account_code' => '221', 'side' => 'debit', 'amount' => 100.01, 'dimensions' => $this->dimensions, 'dimension_origins' => array_map(static fn (): array => ['origin' => 'explicit', 'ref' => 'synthetic-test'], $this->dimensions)],
            ['account_code' => '602', 'side' => 'credit', 'amount' => 100.01, 'dimensions' => $this->dimensions, 'dimension_origins' => array_map(static fn (): array => ['origin' => 'explicit', 'ref' => 'synthetic-test'], $this->dimensions)],
        ], ['entry_date' => '2097-06-30', 'posted' => true, 'allow_closing_period' => true]);
        $inventory = [];
        foreach ($this->service->inventoryPreview($this->supplier, $this->period)['rows'] as $row) {
            $inventory[(int) $row['account_id']] = ['counted_balance' => (float) $row['book_balance'], 'resolution' => 'resolved', 'note' => null];
        }
        $this->service->saveInventory($this->supplier, $this->period, $this->body()['row_version'], ['complete' => true], $inventory);
        $this->service->runPrecheck($this->supplier, $this->period, $this->body()['row_version']);
        foreach (['depreciation', 'estimates', 'deferrals', 'provisions', 'income_tax'] as $step) {
            $this->service->confirmStep($this->supplier, $this->period, $step, 'skipped', null, $this->body()['row_version']);
        }
        $fx = $this->service->fxPreview($this->supplier, $this->period);
        $this->service->runFxRevaluation($this->supplier, $this->period, [], $this->body()['row_version'], [], ['snapshot_hash' => $fx['snapshot_hash']]);
        $kind = array_values($this->dimensions)[1];
        $this->db->pdo()->prepare('UPDATE dimension_values SET is_active=0 WHERE id=? AND supplier_id=?')->execute([$kind, $this->supplier]);
        \MyInvoice\Tests\Support\NonprofitTaxAssessmentFixture::confirm(Bootstrap::buildContainer(), $this->supplier, $this->period, (int) $this->db->pdo()->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn());
        $closed = $this->service->closeBooks($this->supplier, $this->period, $this->body()['row_version']);
        self::assertSame(0.0, $closed['profit']);
        if ($sourceStatus !== 'closed') {
            $this->db->pdo()->prepare('UPDATE accounting_periods SET status=?,row_version=row_version+1 WHERE supplier_id=? AND id=?')
                ->execute([$sourceStatus, $this->supplier, $this->period]);
        }
        $opened = $this->service->openNext($this->supplier, $this->period, $this->body()['row_version']);
        $next = Bootstrap::buildContainer()->get(AccountingPeriodRepository::class)->findForDate($this->supplier, '2097-07-01');
        self::assertNotNull($next);
        $this->period = (int) $next['id'];
        $this->service->start($this->supplier, $this->period, $this->body()['row_version']);
        $released = $this->service->createAssistedEntry($this->supplier, $this->period, 'provisions', $this->release($source['entry_id'], '100.01'));
        self::assertGreaterThan(0, $released['entry_id']);
        $repo = new \MyInvoice\Service\Accounting\Closing\LegalClosing504Repository($this->db, Bootstrap::buildContainer()->get(\MyInvoice\Repository\JournalEntryRepository::class));
        self::assertSame(0, array_sum($repo->reserveRemaining($this->supplier, $source['entry_id'], '2098-06-30')));
        $stmt = $this->db->pdo()->prepare("UPDATE journal_entry_lines l JOIN chart_of_accounts a ON a.id=l.account_id SET l.amount=l.amount+0.01 WHERE l.entry_id=? AND a.account_code='962' LIMIT 1");
        $stmt->execute([$closed['entry_id']]);
        self::assertSame(1, $stmt->rowCount());
        try {
            $repo->reserveRemaining($this->supplier, $source['entry_id'], '2098-06-30');
            self::fail('A corrupt carry counterpart cannot be ignored as a transfer.');
        } catch (ClosingException $e) { self::assertSame('legal_closing_source_changed', $e->errorCode); }

    }

    public function testPaidProvisionWithRetiredActivityCanBeReleasedWithoutRelabeling(): void
    {
        $invoice = $this->invoice();
        $this->runProvision($invoice, '50.01');
        $kind = array_values($this->dimensions)[1];
        $this->db->pdo()->prepare('UPDATE dimension_values SET is_active=0 WHERE id=? AND supplier_id=?')->execute([$kind, $this->supplier]);
        $this->db->pdo()->prepare("INSERT INTO invoice_payments(invoice_id,supplier_id,amount,paid_on,currency,source) VALUES (?,?,100,'2097-05-01','CZK','manual')")->execute([$invoice, $this->supplier]);
        $released = $this->runProvision($invoice, '0');
        self::assertGreaterThan(0, $released['entries'][0]['entry_id']);
        $repo = new \MyInvoice\Service\Accounting\Closing\LegalClosing504Repository($this->db, Bootstrap::buildContainer()->get(\MyInvoice\Repository\JournalEntryRepository::class));
        self::assertSame([], $repo->provisionCells($this->supplier, $invoice, '2097-06-30'));
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM journal_entry_line_dimensions d JOIN journal_entry_lines l ON l.id=d.line_id WHERE l.entry_id=? AND d.dimension_value_id=?');
        $stmt->execute([$released['entries'][0]['entry_id'], $kind]);
        self::assertSame(2, (int) $stmt->fetchColumn());
    }

    public function testUnlinkedProvisionUsageCannotBeHiddenByUnrelatedCredit(): void
    {
        $invoice = $this->invoice();
        $this->runProvision($invoice, '50.01');
        $posting = Bootstrap::buildContainer()->get(\MyInvoice\Service\Accounting\PostingService::class);
        $used = $posting->postDocument($this->supplier, 'manual', null, [
            ['account_code' => '391', 'side' => 'debit', 'amount' => 20, 'dimensions' => $this->dimensions, 'dimension_origins' => array_map(static fn (): array => ['origin' => 'explicit', 'ref' => 'synthetic-test'], $this->dimensions)],
            ['account_code' => '311', 'side' => 'credit', 'amount' => 20, 'dimensions' => $this->dimensions, 'dimension_origins' => array_map(static fn (): array => ['origin' => 'explicit', 'ref' => 'synthetic-test'], $this->dimensions)],
        ], ['entry_date' => '2097-06-30', 'posted' => true, 'allow_closing_period' => true]);
        $posting->postDocument($this->supplier, 'manual', null, [
            ['account_code' => '559', 'side' => 'debit', 'amount' => 20, 'dimensions' => $this->dimensions, 'dimension_origins' => array_map(static fn (): array => ['origin' => 'explicit', 'ref' => 'synthetic-test'], $this->dimensions)],
            ['account_code' => '391', 'side' => 'credit', 'amount' => 20, 'dimensions' => $this->dimensions, 'dimension_origins' => array_map(static fn (): array => ['origin' => 'explicit', 'ref' => 'synthetic-test'], $this->dimensions)],
        ], ['entry_date' => '2097-06-30', 'posted' => true, 'allow_closing_period' => true]);
        try {
            $this->runProvision($invoice, '0');
            self::fail('An unrelated credit cannot hide an unlinked provision use.');
        } catch (ClosingException $e) { self::assertSame('legal_closing_source_changed', $e->errorCode); }
        $posting->reverse($this->supplier, $used, ['entry_date' => '2097-06-30', 'allow_closing_period' => true]);
        $released = $this->runProvision($invoice, '0');
        self::assertGreaterThan(0, $released['entries'][0]['entry_id']);
        $journal = Bootstrap::buildContainer()->get(\MyInvoice\Repository\JournalEntryRepository::class);
        self::assertSame($released['entries'][0]['entry_id'], $journal->insertCurrentLegalClosing($this->supplier, $this->period, $this->body()['row_version'] - 1));
    }

    public function testHistoricalInvoiceJointCellsSupportExplicitProportionalProvision(): void
    {
        $invoice = $this->invoice();
        $kindType = array_keys($this->dimensions)[1];
        $this->db->pdo()->prepare('INSERT INTO dimension_values(type_id,supplier_id,code,name) VALUES (?,?,?,?)')->execute([$kindType, $this->supplier, 'synthetic_second', 'Synthetic second activity']);
        $otherDimensions = $this->dimensions;
        $otherDimensions[$kindType] = (int) $this->db->pdo()->lastInsertId();
        Bootstrap::buildContainer()->get(\MyInvoice\Service\Accounting\PostingService::class)->postDocument($this->supplier, 'invoice', $invoice, [
            ['account_code' => '311', 'side' => 'debit', 'amount' => 60, 'dimensions' => $this->dimensions, 'dimension_origins' => array_map(static fn (): array => ['origin' => 'explicit', 'ref' => 'synthetic-test'], $this->dimensions)],
            ['account_code' => '602', 'side' => 'credit', 'amount' => 60, 'dimensions' => $this->dimensions, 'dimension_origins' => array_map(static fn (): array => ['origin' => 'explicit', 'ref' => 'synthetic-test'], $this->dimensions)],
            ['account_code' => '311', 'side' => 'debit', 'amount' => 40, 'dimensions' => $otherDimensions, 'dimension_origins' => array_map(static fn (): array => ['origin' => 'explicit', 'ref' => 'synthetic-test'], $otherDimensions)],
            ['account_code' => '602', 'side' => 'credit', 'amount' => 40, 'dimensions' => $otherDimensions, 'dimension_origins' => array_map(static fn (): array => ['origin' => 'explicit', 'ref' => 'synthetic-test'], $otherDimensions)],
        ], ['entry_date' => '2097-01-01', 'posted' => true, 'allow_closing_period' => true]);
        $this->db->pdo()->prepare('UPDATE dimension_values SET is_active=0 WHERE supplier_id=? AND type_id=?')->execute([$this->supplier, $kindType]);
        $this->db->pdo()->prepare("INSERT INTO invoice_payments(supplier_id,invoice_id,paid_on,amount,currency,source) VALUES (?,?,'2097-06-30',25,'CZK','manual')")->execute([$this->supplier, $invoice]);
        $decision = $this->body()['legal_decision'];
        $decision['allocation_basis'] = 'source_joint';
        $decision['payment_allocation'] = 'proportional';
        $decision['allocations'] = [['amount' => '45.00', 'dimensions' => $this->dimensions], ['amount' => '30.00', 'dimensions' => $otherDimensions]];
        $preview = $this->service->provisionsPreview($this->supplier, $this->period);
        self::assertTrue($preview['items'][0]['source_joint']['available'] ?? false);
        self::assertTrue($preview['items'][0]['source_joint']['requires_confirmation']);
        self::assertSame('proportional', $preview['items'][0]['source_joint']['payment_allocation']);
        self::assertEqualsCanonicalizing([4500, 3000], array_column($preview['items'][0]['source_joint']['cells'], 'available_cents'));
        $result = $this->service->runProvisions($this->supplier, $this->period, [['invoice_id' => $invoice, 'legal_amount' => '75.00', 'legal_decision' => $decision]], $this->body()['row_version']);
        self::assertGreaterThan(0, $result['entries'][0]['entry_id']);
        $read = new \MyInvoice\Service\Accounting\Closing\LegalClosing504Repository($this->db, Bootstrap::buildContainer()->get(\MyInvoice\Repository\JournalEntryRepository::class));
        self::assertSame(7500, array_sum(array_column($read->provisionCells($this->supplier, $invoice, '2097-06-30'), 'available_cents')));
        $journal = Bootstrap::buildContainer()->get(\MyInvoice\Repository\JournalEntryRepository::class);
        self::assertSame($result['entries'][0]['entry_id'], $journal->insertCurrentLegalClosing($this->supplier, $this->period, $this->body()['row_version'] - 1));
        $decision['allocations'][0]['amount'] = '45.01';
        $decision['allocations'][1]['amount'] = '29.99';
        try {
            $this->service->runProvisions($this->supplier, $this->period, [['invoice_id' => $invoice, 'legal_amount' => '75.00', 'legal_decision' => $decision]], $this->body()['row_version']);
            self::fail('A source receipt cannot move capacity across historical joint cells.');
        } catch (ClosingException $e) { self::assertSame('legal_closing_source_exceeded', $e->errorCode); }
        $this->db->pdo()->prepare("INSERT INTO invoice_payments(supplier_id,invoice_id,paid_on,amount,currency,source) VALUES (?,?,'2097-06-30',75,'CZK','manual')")->execute([$this->supplier, $invoice]);
        $decision['allocations'] = [];
        $released = $this->service->runProvisions($this->supplier, $this->period, [['invoice_id' => $invoice, 'legal_amount' => '0', 'legal_decision' => $decision]], $this->body()['row_version']);
        self::assertGreaterThan(0, $released['entries'][0]['entry_id']);
        self::assertSame([], $read->provisionCells($this->supplier, $invoice, '2097-06-30'));
    }

    public function testAllowancePreviewAndReleaseAfterRealCarryRemainReadOnly(): void
    {
        $invoice = $this->invoice();
        $this->runProvision($invoice, '50.01');
        Bootstrap::buildContainer()->get(\MyInvoice\Service\Accounting\PostingService::class)->postDocument($this->supplier, 'manual', null, [
            ['account_code' => '501', 'side' => 'debit', 'amount' => 49.99, 'dimensions' => $this->dimensions, 'dimension_origins' => array_map(static fn (): array => ['origin' => 'explicit', 'ref' => 'synthetic-test'], $this->dimensions)],
            ['account_code' => '221', 'side' => 'credit', 'amount' => 49.99, 'dimensions' => $this->dimensions, 'dimension_origins' => array_map(static fn (): array => ['origin' => 'explicit', 'ref' => 'synthetic-test'], $this->dimensions)],
        ], ['entry_date' => '2097-06-30', 'posted' => true, 'allow_closing_period' => true]);
        $inventory = [];
        foreach ($this->service->inventoryPreview($this->supplier, $this->period)['rows'] as $row) {
            $inventory[(int) $row['account_id']] = ['counted_balance' => (float) $row['book_balance'], 'resolution' => 'resolved', 'note' => null];
        }
        $this->service->saveInventory($this->supplier, $this->period, $this->body()['row_version'], ['complete' => true], $inventory);
        $this->service->runPrecheck($this->supplier, $this->period, $this->body()['row_version']);
        foreach (['depreciation', 'estimates', 'deferrals', 'provisions', 'income_tax'] as $step) $this->service->confirmStep($this->supplier, $this->period, $step, 'skipped', null, $this->body()['row_version']);
        $fx = $this->service->fxPreview($this->supplier, $this->period);
        $this->service->runFxRevaluation($this->supplier, $this->period, [], $this->body()['row_version'], [], ['snapshot_hash' => $fx['snapshot_hash']]);
        $this->db->pdo()->prepare('UPDATE dimension_values SET is_active=0 WHERE id=? AND supplier_id=?')->execute([array_values($this->dimensions)[1], $this->supplier]);
        \MyInvoice\Tests\Support\NonprofitTaxAssessmentFixture::confirm(Bootstrap::buildContainer(), $this->supplier, $this->period, (int) $this->db->pdo()->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn());
        $this->service->closeBooks($this->supplier, $this->period, $this->body()['row_version']);
        $this->service->openNext($this->supplier, $this->period, $this->body()['row_version']);
        $next = Bootstrap::buildContainer()->get(AccountingPeriodRepository::class)->findForDate($this->supplier, '2097-07-01');
        $this->period = (int) $next['id'];
        $this->service->start($this->supplier, $this->period, $this->body()['row_version']);
        $this->db->pdo()->commit();
        $this->committed = true;
        $count = $this->journalCount();
        $preview = $this->service->provisionsPreview($this->supplier, $this->period);
        self::assertSame(50.01, $preview['items'][0]['existing']['legal_amount']);
        self::assertSame($count, $this->journalCount());
        self::assertFalse($this->db->pdo()->inTransaction());
        $this->db->pdo()->beginTransaction();
        $released = $this->runProvision($invoice, '0');
        self::assertGreaterThan(0, $released['entries'][0]['entry_id']);
    }

    private function runProvision(int $invoice, string $target): array
    {
        $decision = $this->body()['legal_decision'];
        $decision['reference'] = 'SYN-OP-' . $target;
        $decision['legal_basis'] = '593/1992 §8a';
        $decision['allocations'] = (float) $target === 0.0 ? [] : [['amount' => $target, 'dimensions' => $this->dimensions]];
        return $this->service->runProvisions($this->supplier, $this->period,
            [['invoice_id' => $invoice, 'legal_amount' => $target, 'acct_amount' => 0, 'legal_decision' => $decision]], $this->body()['row_version']);
    }

    private function invoice(): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO clients(supplier_id,company_name,street,city,zip,country_id,main_email,currency_default_id)
            SELECT id,'Synthetic client','Test','Test','10000',country_id,'client@example.com',default_currency_id FROM supplier WHERE id=?")->execute([$this->supplier]);
        $client = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO invoices(supplier_id,varsymbol,client_id,issue_date,due_date,currency_id,created_by,total_with_vat,status)
            SELECT id,'SYN-LEGAL-OP',?,'2097-01-01','2097-01-31',default_currency_id,(SELECT id FROM users ORDER BY id LIMIT 1),100,'issued' FROM supplier WHERE id=?")
            ->execute([$client, $this->supplier]);
        $id = (int) $pdo->lastInsertId();
        Bootstrap::buildContainer()->get(\MyInvoice\Service\Accounting\PostingService::class)->postDocument($this->supplier, 'invoice', $id, [
            ['account_code' => '311', 'side' => 'debit', 'amount' => 100, 'dimensions' => $this->dimensions, 'dimension_origins' => array_map(static fn (): array => ['origin' => 'explicit', 'ref' => 'synthetic-test'], $this->dimensions)],
            ['account_code' => '602', 'side' => 'credit', 'amount' => 100, 'dimensions' => $this->dimensions, 'dimension_origins' => array_map(static fn (): array => ['origin' => 'explicit', 'ref' => 'synthetic-test'], $this->dimensions)],
        ], ['entry_date' => '2097-01-01', 'posted' => true, 'allow_closing_period' => true]);
        return $id;
    }

    private function release(int $sourceEntryId, string $amount): array
    {
        $body = $this->body();
        $body['rule_key'] = 'reserve.repairs.release';
        $body['amount'] = $amount;
        $body['legal_decision']['source_entry_id'] = $sourceEntryId;
        $body['legal_decision']['allocations'][0]['amount'] = $amount;
        return $body;
    }

    private function body(): array
    {
        $version = Bootstrap::buildContainer()->get(AccountingPeriodRepository::class)->findById($this->supplier, $this->period)['row_version'];
        return ['row_version' => $version, 'rule_key' => 'reserve.repairs.create', 'amount' => '100.01', 'description' => 'Synthetic legal reserve',
            'legal_decision' => ['reference' => 'SYN-RESERVE-1', 'legal_basis' => '593/1992 §7', 'taxable_activity' => true,
                'conditions_confirmed' => true, 'evidence' => 'Synthetic repair plan and statutory deposit assessment',
                'allocations' => [['amount' => '100.01', 'dimensions' => $this->dimensions]]]];
    }

    private function journalCount(): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM journal_entries WHERE supplier_id=?');
        $stmt->execute([$this->supplier]);
        return (int) $stmt->fetchColumn();
    }
}
