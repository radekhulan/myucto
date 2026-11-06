<?php

declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Property;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Anonymization\{DatabaseCloner, TextScrubber, Pseudonymizer, PaymentsCloneScrubber};
use MyInvoice\Service\Property\Payments\{PaymentSourceProof, PaymentLocks, PaymentException};
use MyInvoice\Service\Property\Payments\Workflow\{PaymentWorkflowFacade, ClearingCells};
use MyInvoice\Repository\Property\Payments\{ClearingRevisionRepository, PaymentRepository, PaymentWorkflowRepository};
use MyInvoice\Service\Property\PropertyInput;
use MyInvoice\Tests\Support\CloneSandbox;
use PDO;
use PHPUnit\Framework\TestCase;
final class PaymentWorkflowCloneTest extends TestCase
{
    use \MyInvoice\Tests\Support\ChargeIssuanceFixture;
    private Connection $db;
    private array $f;
    private string $ownedSchema;
    private PDO $server;
    private array $connections=[];
    private ?string $targetSchema=null;
    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $config = $container->get(Config::class);
        $source = (string) $container->get(Connection::class)->pdo()->query('SELECT DATABASE()')->fetchColumn();
        self::assertStringEndsWith('_test', $source);
        self::assertContains($config->get('db.host'), ['localhost', '127.0.0.1', '::1']);
        $this->server = new PDO('mysql:host=' . $config->get('db.host') . ';port=' . $config->get('db.port', 3306) . ';charset=utf8mb4',
            $config->get('db.user'), $config->get('db.pass', ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->ownedSchema = CloneSandbox::acquire($this->server, $source, true);
        $data = $config->all();
        $data['db']['name'] = $this->ownedSchema;
        for ($i = 0; $i < 2; ++$i) {
            $db = Connection::withoutSharedTestConnection(fn () => new Connection(new Config($data, $config->dataDir())));
            $db->pdo()->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
            $db->pdo()->exec('SET SESSION innodb_snapshot_isolation = 0');
            $db->pdo()->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $this->connections[] = $db;
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->connections as $db) {
            if ($db->pdo()->inTransaction()) $db->pdo()->rollBack();
            $db->close();
        }
        $ownedSchemas=array_values(array_filter([$this->targetSchema,$this->ownedSchema??null],static fn($schema)=>$schema!==null));
        if($this->targetSchema!==null){if(!CloneSandbox::isLeased($this->targetSchema))self::assertMatchesRegularExpression('/^myucto_workflow_clone_[a-f0-9]{10}_test_copy_test$/D',$this->targetSchema);CloneSandbox::dropOrRelease($this->server,$this->targetSchema,$ownedSchemas);}
        if(!CloneSandbox::isLeased($this->ownedSchema)) self::assertMatchesRegularExpression('/^myucto_workflow_clone_[a-f0-9]{10}_test$/D', $this->ownedSchema);
        CloneSandbox::dropOrRelease($this->server,$this->ownedSchema,$ownedSchemas);
    }


    public static function resetModes(): array
    {
        // Rozdíl režimů (uživatelé a firma zůstanou, nebo ne) ověřuje ReceivableLifecycleTest.
        // Tady jde o vyčištění plateb, revizí, rezervací a exportů, které na režimu nezávisí.
        return ['full reset' => [false]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('resetModes')]
    public function testNativeResetClearsWorkflowRevisionReservationsAndExports(bool $keep): void
    {
        $source = $this->connections[0];
        $this->bind($source);
        $source->pdo()->beginTransaction();
        $this->f = $this->issuanceFixture($source);
        [$native, $state] = $this->source();
        $supplier = $this->f['supplier'];
        $actor = $this->f['actor'];
        $revisions = $this->container()->get(ClearingRevisionRepository::class);
        $request = $this->request($state);
        $revisions->apply($supplier, $request, $revisions->preview($supplier, $request)['preview_hash'], 'reset-revision', $actor);
        $payments = $this->container()->get(PaymentRepository::class);
        $credit = $payments->operation($supplier, (int) $native['parts'][0]['operation_id'])['credit_id'];
        \MyInvoice\Tests\Support\RefundAccountFixture::document($this->db->pdo(), $supplier, $this->f['clients'][1]);
        $reservation = $payments->reserve($supplier, $credit, 1, ['amount_cents' => '1000', 'party_ids' => [$this->f['clients'][1]],
            'disposition' => 'Synthetic reset reservation', 'recipient_account' => '1000000005/0100'], 'reset-reserve', $actor);
        $source->pdo()->prepare("INSERT INTO supplier_bank_accounts(supplier_id,label,account_number,bank_code,bank_code_norm,currency,account_canonical,analytic_suffix) VALUES (?,'Synthetic reset','1000000005','0100','0100','CZK','1000000005','100')")->execute([$supplier]);
        $bank = (int) $source->pdo()->lastInsertId();
        $exportRequest = ['reservation_ids' => [$reservation['id']], 'expected_versions' => [$reservation['id'] => 1], 'bank_account_id' => $bank,
            'due_date' => '2026-03-01', 'reason' => 'Synthetic reset', 'payment_symbols' => [$reservation['id'] => ['allow_missing_variable_symbol' => true]]];
        $exports = $this->container()->get(\MyInvoice\Repository\Property\Payments\PaymentExportRepository::class);
        $exports->confirm($supplier, $exportRequest, $exports->preview($supplier, $exportRequest)['preview_hash'], 'reset-export', $actor);
        $source->pdo()->commit();
        foreach (['unit_payment_workflows', 'unit_payment_operations', 'unit_payment_source_revisions', 'unit_payment_exports', 'unit_credit_reservations'] as $table) {
            self::assertGreaterThan(0, (int) $source->pdo()->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn(), $table);
        }
        $users = (int) $source->pdo()->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $suppliers = (int) $source->pdo()->query('SELECT COUNT(*) FROM supplier')->fetchColumn();
        [$exit, $output] = \MyInvoice\Tests\Support\NativePropertyReset::run($this->ownedSchema, $keep);
        self::assertSame(0, $exit, $output);
        \MyInvoice\Tests\Support\NativePropertyReset::assertCleared($source->pdo());
        self::assertSame($keep ? $users : 0, (int) $source->pdo()->query('SELECT COUNT(*) FROM users')->fetchColumn());
        self::assertSame($keep ? $suppliers : 0, (int) $source->pdo()->query('SELECT COUNT(*) FROM supplier')->fetchColumn());
    }

    public function testFullAnonymizationPreservesNativeWorkflowRevisionAndExport(): void
    {
        $source = $this->connections[0];
        $this->bind($source);
        $source->pdo()->beginTransaction();
        $this->f = $this->issuanceFixture($source);
        [$native, $state] = $this->source();
        $supplier = $this->f['supplier'];
        $actor = $this->f['actor'];
        $revisionWriter = $this->container()->get(ClearingRevisionRepository::class);
        $revisionRequest = $this->request($state);
        $revision = $revisionWriter->apply($supplier, $revisionRequest, $revisionWriter->preview($supplier, $revisionRequest)['preview_hash'], 'full-revision', $actor);
        $payments = $this->container()->get(PaymentRepository::class);
        $creditId = $payments->operation($supplier, (int) $native['parts'][0]['operation_id'])['credit_id'];
        \MyInvoice\Tests\Support\RefundAccountFixture::document($this->db->pdo(), $supplier, $this->f['clients'][1]);
        $reservation = $payments->reserve($supplier, $creditId, 1, ['amount_cents' => '1000', 'party_ids' => [$this->f['clients'][1]],
            'disposition' => 'Synthetic person@example.test', 'recipient_account' => '1000000005/0100'], 'full-reserve', $actor);
        $source->pdo()->prepare("INSERT INTO supplier_bank_accounts(supplier_id,label,account_number,bank_code,bank_code_norm,currency,account_canonical,analytic_suffix) VALUES (?,'Synthetic person@example.test','1000000005','0100','0100','CZK','1000000005','100')")->execute([$supplier]);
        $bank = (int) $source->pdo()->lastInsertId();
        $exportRequest = ['reservation_ids' => [$reservation['id']], 'expected_versions' => [$reservation['id'] => 1], 'bank_account_id' => $bank,
            'due_date' => '2026-03-01', 'reason' => 'Synthetic person@example.test', 'payment_symbols' => [$reservation['id'] => ['allow_missing_variable_symbol' => true, 'message' => 'Private person@example.test']]];
        $exportWriter = $this->container()->get(\MyInvoice\Repository\Property\Payments\PaymentExportRepository::class);
        $export = $exportWriter->confirm($supplier, $exportRequest, $exportWriter->preview($supplier, $exportRequest)['preview_hash'], 'full-export', $actor);
        $source->pdo()->commit();
        $original = $this->fingerprint($source->pdo());

        $config = Bootstrap::buildContainer()->get(Config::class);
        $this->targetSchema = CloneSandbox::anonymized($this->server, $config, $this->ownedSchema, 'synthetic-s4-native-full-pipeline');
        $data = $config->all();
        $data['db']['name'] = $this->targetSchema;
        $target = Connection::withoutSharedTestConnection(fn () => new Connection(new Config($data, $config->dataDir())));
        $this->connections[] = $target;
        $this->bind($target);
        foreach (['unit_payment_workflows', 'unit_payment_source_revisions', 'unit_payment_exports', 'unit_credit_reservations'] as $table) {
            $q = $target->pdo()->prepare('SELECT * FROM ' . $table . ' WHERE supplier_id=?');
            $q->execute([$supplier]);
            $rows = $q->fetchAll(PDO::FETCH_ASSOC);
            self::assertNotEmpty($rows);
            self::assertFalse(str_contains(json_encode($rows, JSON_THROW_ON_ERROR), 'person@example.test'), $table . ' retains private text.');
        }
        $target->pdo()->beginTransaction();
        try {
            (new PaymentLocks($target))->acquire($supplier);
            $proof = (new PaymentSourceProof($target))->current($supplier, 'cash', $native['source']['id']);
            self::assertSame(['10000', '6000', '4000'], [$proof['receipt']['amount_cents'], $proof['consumed_cents'], $proof['available_cents']]);
        } finally {
            $target->pdo()->rollBack();
        }
        $q = $target->pdo()->prepare('SELECT request_json,snapshot_hash FROM unit_payment_source_revisions WHERE supplier_id=? AND id=?');
        $q->execute([$supplier, $revision['id']]);
        $cloneRevision = $q->fetch(PDO::FETCH_ASSOC);
        self::assertNotFalse($cloneRevision);
        self::assertSame($revision['id'], $this->container()->get(ClearingRevisionRepository::class)->apply($supplier, json_decode($cloneRevision['request_json'], true, flags: JSON_THROW_ON_ERROR), $cloneRevision['snapshot_hash'], 'full-revision', $actor)['id']);
        $cloneExportWriter = $this->container()->get(\MyInvoice\Repository\Property\Payments\PaymentExportRepository::class);
        $cloneExport = $cloneExportWriter->find($supplier, $export['id']);
        self::assertFalse(str_contains(base64_decode($cloneExport['content_base64'], true), 'person@example.test'));
        self::assertNotSame($export['content_base64'], $cloneExport['content_base64']);
        $q = $target->pdo()->prepare('SELECT request_json,snapshot_hash FROM unit_payment_exports WHERE supplier_id=? AND id=?');
        $q->execute([$supplier, $export['id']]);
        $cloneExportRow = $q->fetch(PDO::FETCH_ASSOC);
        self::assertNotFalse($cloneExportRow);
        self::assertSame($export['id'], $cloneExportWriter->confirm($supplier, json_decode($cloneExportRow['request_json'], true, flags: JSON_THROW_ON_ERROR), $cloneExportRow['snapshot_hash'], 'full-export', $actor)['id']);
        $q = $target->pdo()->prepare('SELECT request_json,preview_hash FROM unit_payment_workflows WHERE supplier_id=? AND id=?');
        $q->execute([$supplier, $native['id']]);
        $cloneOwner = $q->fetch(PDO::FETCH_ASSOC);
        self::assertNotFalse($cloneOwner);
        self::assertSame($native['id'], $this->container()->get(PaymentWorkflowFacade::class)->confirm($supplier, json_decode($cloneOwner['request_json'], true, flags: JSON_THROW_ON_ERROR), $cloneOwner['preview_hash'], 'synthetic-revision-native', $actor)['id']);
        self::assertSame($original, $this->fingerprint($source->pdo()));
    }

    public function testCloneRehashesWorkflowAndRevisionBeforeSourceProofAndPreservesRetryAndCapacity(): void
    {
        $source=$this->connections[0]; $this->bind($source); $source->pdo()->beginTransaction();
        $this->f=$this->issuanceFixture($source); [$native,$state]=$this->source();
        $revision=$this->container()->get(ClearingRevisionRepository::class); $request=$this->request($state);
        $done=$revision->apply($this->f['supplier'],$request,$revision->preview($this->f['supplier'],$request)['preview_hash'],'opaque-revision',$this->f['actor']);
        $owner=(new PaymentWorkflowRepository($source))->find($this->f['supplier'],$native['id']);
        $pendingPlan=$this->container()->get(PaymentWorkflowFacade::class)->preview($this->f['supplier'],$owner['request']);
        $pending=(new PaymentWorkflowRepository($source))->prepare($this->f['supplier'],$owner['request'],$pendingPlan,'opaque-pending',$this->f['actor']);
        $payments=$this->container()->get(PaymentRepository::class);$credit=$payments->operation($this->f['supplier'],(int)$native['parts'][0]['operation_id'])['credit_id'];
        \MyInvoice\Tests\Support\RefundAccountFixture::document($this->db->pdo(),$this->f['supplier'],$this->f['clients'][1]);
        $reservation=$payments->reserve($this->f['supplier'],$credit,1,['amount_cents'=>'1000','party_ids'=>[$this->f['clients'][1]],'disposition'=>'Synthetic refund person@example.test','recipient_account'=>'1000000005/0100'],'clone-reserve',$this->f['actor']);
        $source->pdo()->prepare("INSERT INTO supplier_bank_accounts (supplier_id,label,account_number,bank_code,bank_code_norm,currency,account_canonical,analytic_suffix) VALUES (?,'Synthetic bank person@example.test','1000000005','0100','0100','CZK','1000000005','100')")->execute([$this->f['supplier']]);$bank=(int)$source->pdo()->lastInsertId();
        $exportRequest=['reservation_ids'=>[$reservation['id']],'expected_versions'=>[$reservation['id']=>1],'bank_account_id'=>$bank,'due_date'=>'2026-03-01','reason'=>'Synthetic export person@example.test','payment_symbols'=>[$reservation['id']=>['allow_missing_variable_symbol'=>true,'message'=>'Private person@example.test']]];
        $exportWriter=$this->container()->get(\MyInvoice\Repository\Property\Payments\PaymentExportRepository::class);$exportPreview=$exportWriter->preview($this->f['supplier'],$exportRequest);$export=$exportWriter->confirm($this->f['supplier'],$exportRequest,$exportPreview['preview_hash'],'clone-export',$this->f['actor']);
        $source->pdo()->commit(); $original=$this->fingerprint($source->pdo());
        $cloner=new DatabaseCloner($this->server); $plan=$cloner->plan($this->ownedSchema);
        $this->targetSchema=CloneSandbox::acquire($this->server,$this->ownedSchema);
        $config=Bootstrap::buildContainer()->get(Config::class); $data=$config->all(); $data['db']['name']=$this->targetSchema;
        $target=Connection::withoutSharedTestConnection(fn()=>new Connection(new Config($data,$config->dataDir()))); $this->connections[]=$target;
        $settlementInputs=\MyInvoice\Service\Anonymization\SettlementCloneScrubber::captureInputs($target);
        $scrubber=new TextScrubber(new Pseudonymizer(str_repeat('synthetic-workflow-',3)));
        $registry=(new \MyInvoice\Service\Anonymization\PropertyCloneScrubber($target->pdo(),$scrubber))->run();
        $versions=(new \MyInvoice\Service\Anonymization\ChargesCloneScrubber($target->pdo(),$scrubber))->run($registry);
        $charges=(new \MyInvoice\Service\Anonymization\IssuanceCloneScrubber($target,$scrubber))->run($registry,$versions);
        foreach($target->pdo()->query('SELECT id,partner_name FROM cash_documents')->fetchAll(PDO::FETCH_ASSOC) as $row){
            $fact=json_decode($scrubber->scrubJson(json_encode(['partner_name'=>$row['partner_name']],JSON_THROW_ON_ERROR)),true,flags:JSON_THROW_ON_ERROR);
            $target->pdo()->prepare('UPDATE cash_documents SET partner_name=? WHERE id=?')->execute([$fact['partner_name'],$row['id']]);
        }
        foreach($target->pdo()->query('SELECT id,counterparty_account FROM bank_transactions WHERE counterparty_account IS NOT NULL')->fetchAll(PDO::FETCH_ASSOC) as $row){$fact=json_decode($scrubber->scrubJson(json_encode(['counterparty_account'=>$row['counterparty_account']],JSON_THROW_ON_ERROR)),true,flags:JSON_THROW_ON_ERROR);$target->pdo()->prepare('UPDATE bank_transactions SET counterparty_account=? WHERE id=?')->execute([$fact['counterparty_account'],$row['id']]);}
        foreach($target->pdo()->query('SELECT line_id,dimension_type_id,assignment_origin_ref FROM journal_entry_line_dimensions WHERE assignment_origin_ref IS NOT NULL')->fetchAll(PDO::FETCH_ASSOC) as $row)$target->pdo()->prepare('UPDATE journal_entry_line_dimensions SET assignment_origin_ref=? WHERE line_id=? AND dimension_type_id=?')->execute([$scrubber->scrub($row['assignment_origin_ref']),$row['line_id'],$row['dimension_type_id']]);
        $helper=new PaymentsCloneScrubber($target,$scrubber);
        $target->pdo()->beginTransaction();
        $receipt=\MyInvoice\Service\Anonymization\PaymentWorkflowCloneScrubber::capture($target,$scrubber,static fn(array $v):array=>$v);
        self::assertNotNull($receipt); $target->pdo()->commit(); $target->pdo()->beginTransaction();
        try{$receipt->finish([],static fn(array $v):array=>$v);self::fail('A clone receipt crossed a transaction boundary.');}
        catch(\LogicException $e){self::assertStringContainsString('different transaction',$e->getMessage());}
        finally{$target->pdo()->rollBack();}
        $before=$this->fingerprint($target->pdo());
        $target->pdo()->exec('RENAME TABLE unit_payment_source_heads TO unit_payment_source_heads_synthetic_hold');
        try{$helper->run($charges,$registry,$settlementInputs);self::fail('An incomplete workflow schema was accepted.');}
        catch(\LogicException $e){self::assertStringContainsString('complete schema',$e->getMessage());}
        finally{$target->pdo()->exec('RENAME TABLE unit_payment_source_heads_synthetic_hold TO unit_payment_source_heads');}
        self::assertSame($before,$this->fingerprint($target->pdo()));
        foreach(['unit_payment_workflows'=>'preview_hash','unit_payment_source_revisions'=>'snapshot_hash','unit_payment_exports'=>'snapshot_hash'] as $table=>$column){
            $saved=$target->pdo()->query('SELECT id,'.$column.' FROM '.$table.' ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
            $target->pdo()->prepare('UPDATE '.$table.' SET '.$column.'=? WHERE id=?')->execute([str_repeat('0',64),$saved['id']]);
            $before=$this->fingerprint($target->pdo());
            try{$helper->run($charges,$registry,$settlementInputs);self::fail('A raw workflow/revision hash was repaired.');}
            catch(PaymentException $e){self::assertSame($table==='unit_payment_exports'?'property_payment_clone_export_proof':'property_payment_clone_workflow_proof',$e->errorCode);}
            self::assertSame($before,$this->fingerprint($target->pdo())); self::assertFalse($target->pdo()->inTransaction());
            $target->pdo()->prepare('UPDATE '.$table.' SET '.$column.'=? WHERE id=?')->execute([$saved[$column],$saved['id']]);
        }
        $q=$target->pdo()->prepare('SELECT line_id,dimension_type_id,assignment_origin FROM journal_entry_line_dimensions WHERE supplier_id=? ORDER BY line_id DESC LIMIT 1');
        $q->execute([$this->f['supplier']]); $origin=$q->fetch(PDO::FETCH_ASSOC);
        $target->pdo()->prepare("UPDATE journal_entry_line_dimensions SET assignment_origin='rule_default' WHERE line_id=? AND dimension_type_id=?")->execute([$origin['line_id'],$origin['dimension_type_id']]);
        $before=$this->fingerprint($target->pdo());
        try{$helper->run($charges,$registry,$settlementInputs);self::fail('An unrelated origin mismatch was repaired.');}
        catch(PaymentException $e){self::assertContains($e->errorCode,['property_payment_clone_workflow_proof','property_payment_ledger_mismatch']);}
        self::assertSame($before,$this->fingerprint($target->pdo())); self::assertFalse($target->pdo()->inTransaction());
        $target->pdo()->prepare('UPDATE journal_entry_line_dimensions SET assignment_origin=? WHERE line_id=? AND dimension_type_id=?')->execute([$origin['assignment_origin'],$origin['line_id'],$origin['dimension_type_id']]);
        $target->pdo()->prepare("UPDATE unit_payment_sources SET consumed_cents='5999' WHERE supplier_id=? AND id=?")->execute([$this->f['supplier'],$state['id']]);
        $before=$this->fingerprint($target->pdo());
        try{$helper->run($charges,$registry,$settlementInputs);self::fail('A corrupt financial capacity was repaired during clone.');}
        catch(PaymentException $e){self::assertSame('property_payment_source_capacity_corrupt',$e->errorCode);}
        self::assertSame($before,$this->fingerprint($target->pdo())); self::assertFalse($target->pdo()->inTransaction());
        $target->pdo()->prepare("UPDATE unit_payment_sources SET consumed_cents='6000' WHERE supplier_id=? AND id=?")->execute([$this->f['supplier'],$state['id']]);
        $helper->run($charges,$registry,$settlementInputs);
        $this->bind($target); $target->pdo()->beginTransaction(); (new PaymentLocks($target))->acquire($this->f['supplier']);
        $current=(new PaymentSourceProof($target))->current($this->f['supplier'],'cash',$native['source']['id']);
        self::assertSame(['A'=>'0','B'=>'4000'],ClearingCells::available($current['clearing_cells'],$current['cell_consumed']));
        $workflow=(new PaymentWorkflowRepository($target))->find($this->f['supplier'],$native['id']);
        $pendingRow=(new PaymentWorkflowRepository($target))->find($this->f['supplier'],$pending);
        self::assertSame('pending',$pendingRow['status']); self::assertNull($pendingRow['journal_entry_id']);
        self::assertStringNotContainsString('person@example.test',json_encode($pendingRow,JSON_THROW_ON_ERROR));
        $q=$target->pdo()->prepare('SELECT * FROM unit_payment_source_revisions WHERE id=?'); $q->execute([$done['id']]); $changed=$q->fetch(PDO::FETCH_ASSOC);
        $q=$target->pdo()->prepare('SELECT * FROM unit_payment_exports WHERE supplier_id=? AND id=?');$q->execute([$this->f['supplier'],$export['id']]);$changedExport=$q->fetch(PDO::FETCH_ASSOC);
        self::assertStringNotContainsString('person@example.test',$changedExport['snapshot_json']);self::assertStringNotContainsString('person@example.test',base64_decode($changedExport['content_base64'],true));
        $target->pdo()->commit();
        self::assertStringNotContainsString('person@example.test',json_encode([$workflow,$changed],JSON_THROW_ON_ERROR));
        self::assertNotSame($original,$this->fingerprint($target->pdo()));
        $cloner->finalize($plan,$this->targetSchema);
        $facade=$this->container()->get(PaymentWorkflowFacade::class);
        self::assertSame($native['id'],$facade->confirm($this->f['supplier'],$workflow['request'],$workflow['preview_hash'],'synthetic-revision-native',$this->f['actor'])['id']);
        try{$facade->confirm($this->f['supplier'],$owner['request'],$owner['preview_hash'],'synthetic-revision-native',$this->f['actor']);self::fail('The old private request bypassed the new canonical hash.');}
        catch(PaymentException $e){self::assertSame('property_payment_idempotency_conflict',$e->errorCode);}
        $revision=$this->container()->get(ClearingRevisionRepository::class);
        self::assertSame($done['id'],$revision->apply($this->f['supplier'],json_decode($changed['request_json'],true,flags:JSON_THROW_ON_ERROR),'unused-on-exact-retry','opaque-revision',$this->f['actor'])['id']);
        $before=$this->fingerprint($target->pdo());
        try{$this->container()->get(\MyInvoice\Service\Accounting\PostingService::class)->reverse($this->f['supplier'],$native['journal_entry_id'],['entry_date'=>'2026-03-02']);self::fail('An anonymized source owner escaped its use guard.');}
        catch(\MyInvoice\Service\Accounting\PostingException $e){self::assertSame('property_payment_source_immutable',$e->errorCode);}
        self::assertSame($before,$this->fingerprint($target->pdo()));
        $exportWriter=$this->container()->get(\MyInvoice\Repository\Property\Payments\PaymentExportRepository::class);
        $newExportRequest=json_decode($changedExport['request_json'],true,flags:JSON_THROW_ON_ERROR);
        self::assertSame($export['id'],$exportWriter->confirm($this->f['supplier'],$newExportRequest,$changedExport['snapshot_hash'],'clone-export',$this->f['actor'])['id']);
        try{$exportWriter->confirm($this->f['supplier'],$exportRequest,$exportPreview['preview_hash'],'clone-export',$this->f['actor']);self::fail('Old private export payload bypassed canonical hash.');}catch(PaymentException $e){self::assertSame('property_payment_idempotency_conflict',$e->errorCode);}
        $cancel=$exportWriter->cancelSubmission($this->f['supplier'],$reservation['id'],2,'Synthetic cancellation','Synthetic bank acknowledgement','clone-cancel',$this->f['actor']);self::assertSame('submitted',$cancel['status']);self::assertTrue($cancel['cancel_requested']);
        $payer=json_decode($changedExport['snapshot_json'],true,flags:JSON_THROW_ON_ERROR)['payer'];
        $this->db->pdo()->prepare("INSERT INTO bank_statements (supplier_id,file_name,file_hash,account_number,bank_code,currency,statement_date) VALUES (?,'Synthetic non-execution proof',?,?,?,'CZK','2026-03-02')")->execute([$this->f['supplier'],hash('sha256',random_bytes(20)),$payer['account_number'],$payer['bank_code']]);
        $released=$exportWriter->releaseUnexecuted($this->f['supplier'],$reservation['id'],$cancel['row_version'],(int)$this->db->pdo()->lastInsertId(),'Synthetic statement without the refund','clone-release',$this->f['actor']);self::assertSame('failed',$released['status']);
        $this->container()->get(PaymentRepository::class)->reverse($this->f['supplier'],(int)$native['parts'][0]['operation_id'],2,'2026-03-02','Synthetic reverse','opaque-reverse',$this->f['actor']);
        $target->pdo()->beginTransaction(); (new PaymentLocks($target))->acquire($this->f['supplier']);
        $current=(new PaymentSourceProof($target))->current($this->f['supplier'],'cash',$native['source']['id']);
        self::assertSame(['A'=>'6000','B'=>'4000'],ClearingCells::available($current['clearing_cells'],$current['cell_consumed'])); $target->pdo()->commit();
        self::assertSame($original,$this->fingerprint($source->pdo()));
    }
    private function container(): \DI\Container { $c=Bootstrap::buildContainer(); $c->set(Connection::class,$this->db); return $c; }
    private function bind(Connection $db): void { $this->db=$db; Bootstrap::buildContainer()->set(Connection::class,$db); }
    private function fingerprint(PDO $pdo): string
    {
        $rows=[]; foreach(['unit_payment_sources','unit_payment_operations','unit_credit_lots','unit_credit_parties','unit_payment_audit','unit_payment_workflows','unit_payment_workflow_operations','unit_payment_source_revisions','unit_payment_source_heads','unit_payment_exports','unit_payment_export_items','unit_credit_reservations'] as $table)$rows[$table]=$pdo->query('SELECT * FROM '.$table.' ORDER BY supplier_id')->fetchAll(PDO::FETCH_ASSOC);
        return PropertyInput::hash($rows);
    }
    private function source(): array
    {
        $this->db->pdo()->prepare("INSERT INTO cash_registers (supplier_id,name,account_code) VALUES (?,'Synthetic source revision','211')")->execute([$this->f['supplier']]);
        $request = ['source' => ['kind' => 'cash', 'draft' => ['register_id' => (int) $this->db->pdo()->lastInsertId(),
            'doc_type' => 'in', 'issue_date' => '2026-02-01', 'partner_name' => 'Synthetic payer person@example.test', 'description' => 'Synthetic source person@example.test', 'total_amount' => '100.00']],
            'clearing_cells' => [['key' => 'A', 'amount_cents' => '10000', 'dimensions' => []]],
            'parts' => [['key' => 'credit', 'request' => ['kind' => 'allocation', 'amount_cents' => '6000', 'accounting_date' => '2026-02-01', 'reason' => 'Synthetic credit person@example.test', 'debts' => [],
                'credit_entitlement' => ['third_party' => ['basis' => 'other_documented', 'evidence' => 'Synthetic documented creditor title'], 'unit_id' => $this->f['units']['G12'], 'party_ids' => [$this->f['clients'][1]], 'evidence' => 'Synthetic entitlement deed', 'due_date' => '2026-03-01']]]]];
        $facade = $this->container()->get(PaymentWorkflowFacade::class);
        $preview = $facade->preview($this->f['supplier'], $request);
        $native = $facade->confirm($this->f['supplier'], $request, $preview['preview_hash'], 'synthetic-revision-native', $this->f['actor']);
        return [$native, (new PaymentSourceProof($this->db))->current($this->f['supplier'], 'cash', $native['source']['id'])];
    }

    private function request(array $source): array
    {
        $q = $this->db->pdo()->prepare("SELECT v.type_id,v.id FROM property_objects o JOIN dimension_values v ON v.id=o.dimension_value_id AND v.supplier_id=o.supplier_id WHERE o.supplier_id=? AND o.id=?");
        $q->execute([$this->f['supplier'], $this->f['units']['G12']]); $value = $q->fetch(\PDO::FETCH_ASSOC);
        return ['source_id' => $source['id'], 'expected_source_version' => $source['version'], 'expected_revision_id' => null,
            'accounting_date' => '2026-03-01', 'effective_at' => '2026-03-01T10:00:00+01:00', 'reason' => 'Synthetic analytic correction', 'evidence' => 'Synthetic attribution person@example.test',
            'moves' => [['from_key' => 'A', 'amount_cents' => '4000', 'to' => ['key' => 'B', 'dimensions' => [(int) $value['type_id'] => (int) $value['id']]]]]];
    }

}
