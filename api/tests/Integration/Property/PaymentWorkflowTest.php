<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Property;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Property\Payments\Workflow\PaymentWorkflowFacade;
use MyInvoice\Tests\Support\ChargeIssuanceFixture;
use PHPUnit\Framework\TestCase;

final class PaymentWorkflowTest extends TestCase
{
    use ChargeIssuanceFixture;
    private Connection $db;
    private array $f;

    protected function setUp(): void
    {
        $this->db = Bootstrap::buildContainer()->get(Connection::class);
        self::assertStringEndsWith('_test', (string) getenv('MYINVOICE_DB_NAME'));
        self::assertSame(getenv('MYINVOICE_DB_NAME'), $this->db->pdo()->query('SELECT DATABASE()')->fetchColumn());
        $this->db->pdo()->beginTransaction();
        $this->f = $this->issuanceFixture($this->db);
    }

    protected function tearDown(): void
    {
        if ($this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
    }

    public function testUnpostedBankCreatesOneNativeOwnerAndExactSettlementWithRetry(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO bank_statements (supplier_id,file_name,file_hash,account_number,bank_code,currency,statement_date) VALUES (?,'Synthetic workflow',?,'1000000005','0100','CZK','2026-02-01')")
            ->execute([$this->f['supplier'], hash('sha256', random_bytes(20))]);
        $statement = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO bank_transactions (statement_id,posted_at,amount,currency,counterparty_account,counterparty_bank) VALUES (?,'2026-02-01','100.00','CZK','1000000005','0100')")
            ->execute([$statement]);
        $bank = (int) $pdo->lastInsertId();
        $request = $this->request(['kind' => 'bank', 'id' => $bank]);
        $facade = Bootstrap::buildContainer()->get(PaymentWorkflowFacade::class);
        $before = $this->counts();
        $preview = $facade->preview($this->f['supplier'], $request);
        self::assertSame('ready', $preview['status']);
        self::assertSame('10000', $preview['source']['amount_cents']);
        self::assertSame($before, $this->counts(), 'Preview must not insert even a draft or native owner.');
        $result = $facade->confirm($this->f['supplier'], $request, $preview['preview_hash'], 'bank-workflow', $this->f['actor']);
        self::assertSame('6000', $result['settled_cents']);
        self::assertSame('4000', $result['available_cents']);
        $q = $pdo->prepare("SELECT COUNT(*) FROM journal_entries WHERE supplier_id=? AND source_type='bank' AND source_id=? AND reversed_by IS NULL");
        $q->execute([$this->f['supplier'], $bank]);
        self::assertSame(1, (int) $q->fetchColumn());
        $after = $this->counts();
        self::assertSame($result['id'], $facade->confirm($this->f['supplier'], $request, $preview['preview_hash'], 'bank-workflow', $this->f['actor'])['id']);
        self::assertSame($after, $this->counts());
    }

    public function testNewCashIsOnlyCreatedTogetherWithItsOwnerAndSettlement(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO cash_registers (supplier_id,name,account_code) VALUES (?,'Synthetic workflow cash','211')")->execute([$this->f['supplier']]);
        $register = (int) $pdo->lastInsertId();
        $request = $this->request(['kind' => 'cash', 'draft' => [
            'register_id' => $register, 'doc_type' => 'in', 'issue_date' => '2026-02-01',
            'partner_name' => 'Synthetic third party payer', 'description' => 'Synthetic payment', 'total_amount' => '100.00',
        ]]);
        $facade = Bootstrap::buildContainer()->get(PaymentWorkflowFacade::class);
        $before = $this->counts();
        $preview = $facade->preview($this->f['supplier'], $request);
        self::assertSame('ready', $preview['status']);
        self::assertSame($before, $this->counts());
        $result = $facade->confirm($this->f['supplier'], $request, $preview['preview_hash'], 'cash-workflow', $this->f['actor']);
        self::assertSame('cash', $result['source']['kind']);
        self::assertSame('4000', $result['available_cents']);
        $q = $pdo->prepare('SELECT status,journal_entry_id,doc_number,total_amount FROM cash_documents WHERE supplier_id=? AND id=?');
        $q->execute([$this->f['supplier'], $result['source']['id']]);
        $doc = $q->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('posted', $doc['status']);
        self::assertNotNull($doc['doc_number']);
        self::assertSame('100.00', $doc['total_amount']);
        self::assertSame($before['cash_documents'] + 1, $this->counts()['cash_documents']);
    }

    public function testCombinedPartsCannotReuseTheSameClearingCell(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO cash_registers (supplier_id,name,account_code) VALUES (?,'Synthetic cell capacity','211')")->execute([$this->f['supplier']]);
        $request = $this->request(['kind' => 'cash', 'draft' => ['register_id' => (int) $pdo->lastInsertId(),
            'doc_type' => 'in', 'issue_date' => '2026-02-01', 'partner_name' => 'Synthetic payer',
            'description' => 'Synthetic cells', 'total_amount' => '100.00']]);
        $request['clearing_cells'] = [['key' => 'first', 'amount_cents' => '6000', 'dimensions' => []],
            ['key' => 'second', 'amount_cents' => '4000', 'dimensions' => []]];
        $part = $request['parts'][0];
        $part['request']['amount_cents'] = '4000';
        $part['request']['clearing_selection'] = [['key' => 'first', 'amount_cents' => '4000']];
        $request['parts'] = [$part, ['key' => 'second-credit', 'request' => $part['request']]];
        $before = $this->counts();
        try {
            Bootstrap::buildContainer()->get(PaymentWorkflowFacade::class)->preview($this->f['supplier'], $request);
            self::fail('Two individually valid parts consumed 8000 cents from the same 6000-cent cell.');
        } catch (\MyInvoice\Service\Property\Payments\PaymentException $e) {
            self::assertSame('property_payment_clearing_capacity', $e->errorCode);
            self::assertSame('first', $e->details['cell_key']);
        }
        self::assertSame($before, $this->counts());
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testFailureAfterNativeOwnerRollsBackTheWholeWorkflow(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO bank_statements (supplier_id,file_name,file_hash,account_number,bank_code,currency,statement_date) VALUES (?,'Synthetic rollback',?,'1000000005','0100','CZK','2026-02-01')")
            ->execute([$this->f['supplier'], hash('sha256', random_bytes(20))]);
        $statement = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO bank_transactions (statement_id,posted_at,amount,currency) VALUES (?,'2026-02-01','100.00','CZK')")->execute([$statement]);
        $bank = (int) $pdo->lastInsertId();
        $request = $this->request(['kind' => 'bank', 'id' => $bank]);
        $container = Bootstrap::buildContainer();
        $real = $container->get(\MyInvoice\Repository\Property\Payments\PaymentRepository::class);
        $payments = $this->createMock(\MyInvoice\Repository\Property\Payments\PaymentRepository::class);
        $payments->method('preview')->willReturnCallback(fn ($supplier, $input) => $real->preview($supplier, $input));
        $payments->expects(self::once())->method('apply')->willReturnCallback(function () use ($pdo, $bank): never {
            $q = $pdo->prepare("SELECT COUNT(*) FROM journal_entries WHERE supplier_id=? AND source_type='bank' AND source_id=?");
            $q->execute([$this->f['supplier'], $bank]);
            self::assertSame(1, (int) $q->fetchColumn(), 'Fault must occur after the real native owner INSERT.');
            throw new \RuntimeException('Synthetic settlement failure');
        });
        $facade = new PaymentWorkflowFacade($this->db,
            $container->get(\MyInvoice\Service\Property\Payments\Workflow\PaymentWorkflowPlanner::class), $payments,
            $container->get(\MyInvoice\Service\Accounting\Bank\BankPostingService::class),
            $container->get(\MyInvoice\Service\Accounting\Cash\CashDocumentService::class));
        $preview = $facade->preview($this->f['supplier'], $request);
        $before = $this->counts();
        try {
            $facade->confirm($this->f['supplier'], $request, $preview['preview_hash'], 'rollback-workflow', $this->f['actor']);
            self::fail('Expected injected settlement failure.');
        } catch (\RuntimeException $e) {
            self::assertSame('Synthetic settlement failure', $e->getMessage());
        }
        self::assertSame($before, $this->counts());
        self::assertTrue($pdo->inTransaction(), 'The caller transaction remains usable.');
    }

    public function testReadContextUsesRealTenantCandidatesAndNoMutation(): void
    {
        $before = $this->counts();
        $context = (new \MyInvoice\Repository\Property\Payments\PaymentWorkflowReadRepository($this->db))->context($this->f['supplier'], '2026-02-01T12:00:00+01:00', true, true);
        self::assertSame('incomplete', $context['candidate_coverage']['fx']);
        self::assertCount(12, $context['units']);
        self::assertSame($before, $this->counts());
    }

    public function testExistingCashOwnerCanSettleAnotherPartWithoutCreatingAnotherNativeEntry(): void
    {
        $request = $this->cashRequest();
        $facade = Bootstrap::buildContainer()->get(PaymentWorkflowFacade::class);
        $preview = $facade->preview($this->f['supplier'], $request);
        $first = $facade->confirm($this->f['supplier'], $request, $preview['preview_hash'], 'first-cash-part', $this->f['actor']);
        $followup = $this->request(['kind' => 'cash', 'id' => $first['source']['id']]);
        $followup['parts'][0]['request']['amount_cents'] = '1000';
        $preview = $facade->preview($this->f['supplier'], $followup);
        $next = $facade->confirmAuthorized($this->f['supplier'], $followup, $preview['preview_hash'], 'next-cash-part', $this->f['actor'], false, false);
        self::assertSame($first['journal_entry_id'], $next['journal_entry_id']);
        self::assertSame('3000', $next['available_cents']);
        self::assertSame(1, $this->counts()['cash_documents']);
        self::assertSame(3, $this->counts()['journal_entries']);
    }

    public function testNewNativeOwnerRequiresItsSeparatePermissionBeforeAnyInsert(): void
    {
        $request = $this->cashRequest();
        $facade = Bootstrap::buildContainer()->get(PaymentWorkflowFacade::class);
        $preview = $facade->preview($this->f['supplier'], $request); $before = $this->counts();
        try {
            $facade->confirmAuthorized($this->f['supplier'], $request, $preview['preview_hash'], 'cash-forbidden', $this->f['actor'], false, false);
            self::fail('Native cash permission is required.');
        } catch (\MyInvoice\Service\Property\Payments\PaymentException $e) {
            self::assertSame('forbidden', $e->errorCode); self::assertSame(403, $e->httpStatus);
        }
        self::assertSame($before, $this->counts());
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testLateCashFailureReturnsNumberAndRetryUsesItExactlyOnce(): void
    {
        $request = $this->cashRequest(); $container = Bootstrap::buildContainer();
        $real = $container->get(\MyInvoice\Repository\Property\Payments\PaymentRepository::class);
        $payments = $this->createMock(\MyInvoice\Repository\Property\Payments\PaymentRepository::class);
        $payments->method('preview')->willReturnCallback(fn ($supplier, $input) => $real->preview($supplier, $input));
        $number = null;
        $payments->expects(self::once())->method('apply')->willReturnCallback(function () use (&$number): never {
            $q = $this->db->pdo()->prepare("SELECT doc_number FROM cash_documents WHERE supplier_id=? AND status='posted'");
            $q->execute([$this->f['supplier']]); $number = $q->fetchColumn(); self::assertIsString($number);
            throw new \RuntimeException('Synthetic cash late failure');
        });
        $facade = new PaymentWorkflowFacade($this->db, $container->get(\MyInvoice\Service\Property\Payments\Workflow\PaymentWorkflowPlanner::class), $payments,
            $container->get(\MyInvoice\Service\Accounting\Bank\BankPostingService::class), $container->get(\MyInvoice\Service\Accounting\Cash\CashDocumentService::class));
        $preview = $facade->preview($this->f['supplier'], $request); $before = $this->counts();
        try { $facade->confirm($this->f['supplier'], $request, $preview['preview_hash'], 'retry-cash-number', $this->f['actor']); self::fail('Expected late failure.'); }
        catch (\RuntimeException $e) { self::assertSame('Synthetic cash late failure', $e->getMessage()); }
        self::assertSame($before, $this->counts());
        $result = $container->get(PaymentWorkflowFacade::class)->confirm($this->f['supplier'], $request, $preview['preview_hash'], 'retry-cash-number', $this->f['actor']);
        $q = $this->db->pdo()->prepare('SELECT c.doc_number,j.document_no FROM cash_documents c JOIN journal_entries j ON j.id=c.journal_entry_id WHERE c.supplier_id=? AND c.id=?');
        $q->execute([$this->f['supplier'], $result['source']['id']]); $row = $q->fetch(\PDO::FETCH_ASSOC);
        self::assertSame($number, $row['doc_number']); self::assertSame($number, $row['document_no']);
        self::assertSame(1, $this->counts()['cash_documents']); self::assertSame(2, $this->counts()['journal_entries']);
    }

    public function testGenericReversalCannotDestroyTheNativePaymentOwner(): void
    {
        $request = $this->cashRequest(); $container = Bootstrap::buildContainer();
        $facade = $container->get(PaymentWorkflowFacade::class);
        $preview = $facade->preview($this->f['supplier'], $request);
        $result = $facade->confirm($this->f['supplier'], $request, $preview['preview_hash'], 'protected-native', $this->f['actor']);
        $before = $this->counts();
        try {
            $container->get(\MyInvoice\Service\Accounting\PostingService::class)->reverse($this->f['supplier'], $result['journal_entry_id'], ['entry_date' => '2026-02-02', 'posted_by' => $this->f['actor']]);
            self::fail('Generic reversal silently destroyed a source consumed by a credit.');
        } catch (\MyInvoice\Service\Accounting\PostingException $e) {
            self::assertSame('property_payment_source_immutable', $e->errorCode);
        }
        self::assertSame($before, $this->counts());
    }

    private function cashRequest(): array
    {
        $this->db->pdo()->prepare("INSERT INTO cash_registers (supplier_id,name,account_code) VALUES (?,'Synthetic workflow','211')")->execute([$this->f['supplier']]);
        return $this->request(['kind' => 'cash', 'draft' => ['register_id' => (int) $this->db->pdo()->lastInsertId(), 'doc_type' => 'in',
            'issue_date' => '2026-02-01', 'partner_name' => 'Synthetic payer', 'description' => 'Synthetic receipt', 'total_amount' => '100.00']]);
    }

    public function testNativeCashAndJournalWritersCannotDetachOrReplaceTheOwnedSource(): void
    {
        $request = $this->cashRequest(); $container = Bootstrap::buildContainer();
        $facade = $container->get(PaymentWorkflowFacade::class);
        $preview = $facade->preview($this->f['supplier'], $request);
        $result = $facade->confirm($this->f['supplier'], $request, $preview['preview_hash'], 'protected-writers', $this->f['actor']);
        $journal = $container->get(\MyInvoice\Repository\JournalEntryRepository::class);
        $cash = $container->get(\MyInvoice\Service\Accounting\Cash\CashDocumentService::class);
        $entry = $journal->findForUpdate($result['journal_entry_id'], $this->f['supplier']);
        $before = $this->counts();
        foreach ([
            fn () => $journal->replace($entry['id'], $entry, $entry['lines']),
            fn () => $journal->replaceSomeLines($entry['id'], $this->f['supplier'], $entry['row_version'], [], []),
            fn () => $journal->detachSource($entry['id'], $this->f['supplier']),
            fn () => $journal->setReversedBy($entry['id'], $this->f['supplier'], $entry['id']),
            fn () => $cash->deleteDocument($this->f['supplier'], $result['source']['id']),
            fn () => $cash->reverse($this->f['supplier'], $result['source']['id'], ['reason' => 'Synthetic correction'], $this->f['actor']),
        ] as $write) {
            try { $write(); self::fail('A generic writer changed an authoritative payment source.'); }
            catch (\MyInvoice\Service\Accounting\PostingException $e) { self::assertSame('property_payment_source_immutable', $e->errorCode); }
            self::assertSame($before, $this->counts());
            self::assertSame($entry, $journal->findForUpdate($entry['id'], $this->f['supplier']));
        }
    }

    public function testOwnedReversalReleasesExactlyTheConsumedCapacityAndRetainsRetry(): void
    {
        $request = $this->cashRequest(); $container = Bootstrap::buildContainer();
        $facade = $container->get(PaymentWorkflowFacade::class);
        $preview = $facade->preview($this->f['supplier'], $request);
        $result = $facade->confirm($this->f['supplier'], $request, $preview['preview_hash'], 'owned-reversal', $this->f['actor']);
        $payments = $container->get(\MyInvoice\Repository\Property\Payments\PaymentRepository::class);
        $operation = $payments->operation($this->f['supplier'], (int) $result['parts'][0]['operation_id']);
        (new \MyInvoice\Repository\Property\PropertyProfileRepository($this->db))->save($this->f['supplier'], [
            'expected_revision' => 1, 'effective_from' => '2026-02-02T00:00:00+01:00', 'enabled' => false,
            'reason' => 'Synthetic module shutdown', 'title_ref' => 'Synthetic recorded decision', 'idempotency_key' => 'disable-after-credit',
        ], $this->f['actor']);
        $reversal = $payments->reverse($this->f['supplier'], $operation['id'], $operation['row_version'], '2026-02-02', 'Synthetic correction', 'owned-reversal-cancel', $this->f['actor']);
        $source = (new \MyInvoice\Service\Property\Payments\PaymentSourceProof($this->db))->current($this->f['supplier'], 'cash', $result['source']['id']);
        self::assertSame('0', $source['consumed_cents']);
        self::assertSame('10000', $source['available_cents']);
        self::assertNull($source['receipt']['ledger']['entry']['reversed_by']);
        $counts = $this->counts();
        self::assertSame($reversal['id'], $payments->reverse($this->f['supplier'], $operation['id'], $operation['row_version'], '2026-02-02', 'Synthetic correction', 'owned-reversal-cancel', $this->f['actor'])['id']);
        self::assertSame($counts, $this->counts());
        try {
            $container->get(\MyInvoice\Service\Accounting\PostingService::class)->reverse($this->f['supplier'], $reversal['journal_entry_id'], ['entry_date' => '2026-02-03']);
            self::fail('A generic reversal cannot reverse the owned cancellation ledger.');
        } catch (\MyInvoice\Service\Accounting\PostingException $e) {
            self::assertSame('property_payment_source_immutable', $e->errorCode);
        }
    }

    public static function assignmentInsertWriters(): array
    {
        return [['scalar'], ['split'], ['batch']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('assignmentInsertWriters')]
    public function testDirectAssignmentInsertCannotRestampTheClearingReceipt(string $writer): void
    {
        $request = $this->cashRequest(); $container = Bootstrap::buildContainer();
        $facade = $container->get(PaymentWorkflowFacade::class);
        $preview = $facade->preview($this->f['supplier'], $request);
        $result = $facade->confirm($this->f['supplier'], $request, $preview['preview_hash'], 'protected-assignment', $this->f['actor']);
        $source = (new \MyInvoice\Service\Property\Payments\PaymentSourceProof($this->db))->current($this->f['supplier'], 'cash', $result['source']['id']);
        $line = $source['receipt']['clearing_cells'][0]['line']['id'];
        $q = $this->db->pdo()->prepare("SELECT v.type_id,v.id FROM dimension_values v JOIN dimension_types t ON t.id=v.type_id AND t.supplier_id=v.supplier_id WHERE v.supplier_id=? AND t.system_key='unit' ORDER BY v.id LIMIT 1");
        $q->execute([$this->f['supplier']]); $value = $q->fetch(\PDO::FETCH_ASSOC);
        $dims = [(int) $value['type_id'] => (int) $value['id']];
        $repo = $container->get(\MyInvoice\Repository\DimensionAssignmentRepository::class);
        self::assertFalse($repo->replaceLineDimensions($this->f['supplier'], $line, []), 'Exact no-op remains allowed.');
        try {
            match ($writer) {
                'scalar' => $repo->insertLineDimensions($this->f['supplier'], $line, $dims),
                'split' => $repo->insertLineSplits($this->f['supplier'], $line, [(int) $value['type_id'] => [(int) $value['id'] => 1.0]]),
                'batch' => $repo->insertLineAssignments($this->f['supplier'], [$line => ['dimensions' => $dims]]),
            };
            self::fail('Low-level assignment insertion changed a frozen clearing receipt.');
        } catch (\MyInvoice\Service\Accounting\PostingException $e) {
            self::assertSame('property_payment_source_immutable', $e->errorCode);
        }
        self::assertSame($source, (new \MyInvoice\Service\Property\Payments\PaymentSourceProof($this->db))->current($this->f['supplier'], 'cash', $result['source']['id']));
    }

    public function testTypedReversalCannotReturnWithPostedOriginalAndUnreleasedCapacity(): void
    {
        $container = Bootstrap::buildContainer(); $facade = $container->get(PaymentWorkflowFacade::class);
        $request = $this->cashRequest(); $preview = $facade->preview($this->f['supplier'], $request);
        $native = $facade->confirm($this->f['supplier'], $request, $preview['preview_hash'], 'synthetic-boundary-native', $this->f['actor']);
        $payments = $container->get(\MyInvoice\Repository\Property\Payments\PaymentRepository::class);
        $original = $payments->operation($this->f['supplier'], (int) $native['parts'][0]['operation_id']);
        $request = ['kind' => 'reversal', 'original_operation_id' => $original['id'], 'expected_version' => $original['row_version'],
            'accounting_date' => '2026-03-01', 'reason' => 'Synthetic typed boundary', 'amount_cents' => $original['amount_cents']];
        $lines = $original['snapshot']['lines']; foreach ($lines as &$line) $line['side'] = $line['side'] === 'debit' ? 'credit' : 'debit'; unset($line);
        $snapshot = ['request' => $request, 'original_operation_id' => $original['id'], 'original_snapshot_hash' => $original['snapshot_hash'], 'lines' => $lines];
        $token = 'synthetic-boundary-reversal'; $json = static fn (array $v): string => json_encode($v, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        $hash = \MyInvoice\Service\Property\PropertyInput::hash(['request' => $request, 'token' => $token, 'actor' => $this->f['actor']]);
        $this->db->pdo()->prepare("INSERT INTO unit_payment_operations (supplier_id,original_operation_id,kind,amount_cents,accounting_date,request_json,snapshot_json,request_hash,snapshot_hash,idempotency_key,created_by,recorded_at) VALUES (?,?,'reversal',?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6))")
            ->execute([$this->f['supplier'], $original['id'], $original['amount_cents'], $request['accounting_date'], $json($request), $json($snapshot), $hash, \MyInvoice\Service\Property\PropertyInput::hash($snapshot), $token, $this->f['actor']]);
        $pending = (int) $this->db->pdo()->lastInsertId();
        $intent = \MyInvoice\Service\Property\Payments\PaymentReversalIntent::fromCurrentRequest($this->db, $this->f['supplier'], $pending, $payments);
        $entry = $container->get(\MyInvoice\Repository\JournalEntryRepository::class)->insertPropertyPaymentReversal($intent);
        $q = $this->db->pdo()->prepare('SELECT status FROM unit_payment_operations WHERE supplier_id=? AND id=?'); $q->execute([$this->f['supplier'], $original['id']]);
        self::assertSame('reversed', $q->fetchColumn(), 'The typed writer must complete the owner before returning.');
        $reversal = $payments->operation($this->f['supplier'], $pending);
        self::assertSame('posted', $reversal['status']); self::assertSame($entry, $reversal['journal_entry_id']);
        $source = (new \MyInvoice\Service\Property\Payments\PaymentSourceProof($this->db))->current($this->f['supplier'], 'cash', $native['source']['id']);
        self::assertSame('0', $source['consumed_cents']);
    }

    public function testClosedCashSourceCanBeSettledInOpenYearWithoutReopeningInactiveRegisterOrMoneyAccount(): void
    {
        $request=$this->cashRequest(); $facade=Bootstrap::buildContainer()->get(PaymentWorkflowFacade::class);
        $nativeRequest=$request;
        $native=$facade->confirm($this->f['supplier'],$request,$facade->preview($this->f['supplier'],$request)['preview_hash'],'synthetic-historical-owner',$this->f['actor']);
        $proof=new \MyInvoice\Service\Property\Payments\PaymentSourceProof($this->db);
        $original=$proof->current($this->f['supplier'],'cash',$native['source']['id']);
        $this->db->pdo()->prepare("UPDATE cash_registers SET is_active=0 WHERE supplier_id=?")->execute([$this->f['supplier']]);
        $this->db->pdo()->prepare("UPDATE chart_of_accounts SET is_active=0 WHERE supplier_id=? AND account_code='211'")->execute([$this->f['supplier']]);
        $this->db->pdo()->prepare("UPDATE accounting_periods SET status='closed' WHERE supplier_id=?")->execute([$this->f['supplier']]);
        (new \MyInvoice\Repository\AccountingPeriodRepository($this->db))->create($this->f['supplier'],2027,'2027-01-01','2027-12-31');
        $request['source']=['kind'=>'cash','id'=>$native['source']['id']];
        $request['parts'][0]['request']['amount_cents']='4000';
        $request['parts'][0]['request']['accounting_date']='2027-01-02';
        $request['parts'][0]['request']['credit_entitlement']['due_date']='2027-01-31';
        $preview=$facade->preview($this->f['supplier'],$request);
        self::assertNull($preview['snapshot']['native']);
        $result=$facade->confirm($this->f['supplier'],$request,$preview['preview_hash'],'synthetic-open-year-settlement',$this->f['actor']);
        $current=$proof->current($this->f['supplier'],'cash',$native['source']['id']);
        self::assertSame($original['receipt'],$current['receipt']); self::assertSame('10000',$current['consumed_cents']);
        self::assertSame($native['journal_entry_id'],$result['journal_entry_id']);
        self::assertSame('0',$result['available_cents']);
        self::assertSame(1,$this->counts()['cash_documents']);
        $nativeRequest['source']['draft']['issue_date']='2027-01-02'; $nativeRequest['parts']=$request['parts'];
        $counts=$this->counts();
        try{$facade->preview($this->f['supplier'],$nativeRequest);self::fail('A new owner used an inactive register.');}
        catch(\MyInvoice\Service\Property\Payments\PaymentException $e){self::assertSame('property_payment_cash_register_inactive',$e->errorCode);}
        $this->db->pdo()->prepare('UPDATE cash_registers SET is_active=1 WHERE supplier_id=?')->execute([$this->f['supplier']]);
        try{$facade->preview($this->f['supplier'],$nativeRequest);self::fail('A new owner used an inactive money account.');}
        catch(\MyInvoice\Service\Property\Payments\PaymentException $e){self::assertSame('property_payment_source_account_setup_required',$e->errorCode);}
        self::assertSame($counts,$this->counts());
    }

    private function request(array $source): array
    {
        return ['source' => $source, 'clearing_cells' => [['key' => 'unresolved', 'amount_cents' => '10000', 'dimensions' => []]],
            'parts' => [['key' => 'documented-credit', 'request' => ['kind' => 'allocation', 'amount_cents' => '6000', 'accounting_date' => '2026-02-01',
                'reason' => 'Synthetic evidenced credit independent of payer', 'debts' => [],
                'credit_entitlement' => ['third_party' => ['basis' => 'other_documented', 'evidence' => 'Synthetic documented creditor title'], 'unit_id' => $this->f['units']['G12'], 'party_ids' => [$this->f['clients'][1]],
                    'evidence' => 'Synthetic entitlement deed', 'due_date' => '2026-03-01']]]]];
    }

    private function counts(): array
    {
        $result = [];
        foreach (['cash_documents', 'journal_entries', 'unit_payment_sources', 'unit_payment_operations', 'unit_credit_lots', 'unit_payment_audit', 'unit_payment_workflows'] as $table) {
            $q = $this->db->pdo()->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE supplier_id=?');
            $q->execute([$this->f['supplier']]);
            $result[$table] = (int) $q->fetchColumn();
        }
        return $result;
    }
}
