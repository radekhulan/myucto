<?php

declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Property;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Anonymization\DatabaseCloner;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Property\Payments\{PaymentLocks,PaymentSourceProof,PaymentException};
use MyInvoice\Repository\Property\Payments\PaymentRepository;
use MyInvoice\Tests\Support\CloneSandbox;
use PDO;
use PHPUnit\Framework\TestCase;
final class UnitPaymentsCloneTest extends TestCase
{
    use \MyInvoice\Tests\Support\ChargeIssuanceFixture;
    use \MyInvoice\Tests\Support\PaymentCorrectionFixture;
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
        if($this->targetSchema!==null){if(!CloneSandbox::isLeased($this->targetSchema))self::assertMatchesRegularExpression('/^myucto_payment_clone_[a-f0-9]{10}_test_copy_test$/D',$this->targetSchema);CloneSandbox::dropOrRelease($this->server,$this->targetSchema,$ownedSchemas);}
        if(!CloneSandbox::isLeased($this->ownedSchema)) self::assertMatchesRegularExpression('/^myucto_payment_clone_[a-f0-9]{10}_test$/D', $this->ownedSchema);
        CloneSandbox::dropOrRelease($this->server,$this->ownedSchema,$ownedSchemas);
    }


    public function testCloneRehashesOnlyScrubbedFactsPreservingClaimsRefundRetryAndRawProof():void
    {
        [$source]=$this->connections;$source->pdo()->beginTransaction();$f=$this->issuanceFixture($source);$this->db=$source;$this->f=$f;$base=$this->settledPaymentFixture('1','0');$debtor=$base['charge']['snapshot']['liability_groups'][0]['members'][0];$bank=$this->bank($source,$f);$source->pdo()->commit();
        $source->pdo()->beginTransaction();(new PaymentLocks($source))->acquire($f['supplier']);
        $q=$source->pdo()->prepare("SELECT l.id FROM journal_entry_lines l JOIN journal_entries e ON e.id=l.entry_id JOIN chart_of_accounts a ON a.id=l.account_id WHERE e.supplier_id=? AND e.source_type='bank' AND e.source_id=? AND a.account_code='379.900'");$q->execute([$f['supplier'],$bank]);$line=(int)$q->fetchColumn();
        $q=$source->pdo()->prepare('SELECT v.id,v.type_id FROM property_objects o JOIN dimension_values v ON v.id=o.dimension_value_id WHERE o.supplier_id=? AND o.id=?');$q->execute([$f['supplier'],array_values($f['units'])[0]]);$value=$q->fetch(PDO::FETCH_ASSOC);
        (new \MyInvoice\Repository\DimensionAssignmentRepository($source))->replaceLineDimensions($f['supplier'],$line,[(int)$value['type_id']=>(int)$value['id']],[(int)$value['type_id']=>['origin'=>'explicit','ref'=>'person@example.test']]);$source->pdo()->commit();
        $request=['kind'=>'allocation','source'=>['kind'=>'bank','id'=>$bank,'expected_version'=>0],'amount_cents'=>'10000','accounting_date'=>'2026-02-01','reason'=>'Title person@example.test','debts'=>[],
            'credit_entitlement'=>['third_party' => ['basis' => 'other_documented', 'evidence' => 'Synthetic documented creditor title'], 'unit_id'=>$base['charge']['unit_id'],'party_ids'=>[$debtor],'evidence'=>'Evidence person@example.test','due_date'=>'2026-03-01']];
        $writer=$this->writer($source);$oldHash=$writer->preview($f['supplier'],$request)['preview_hash'];$op=$writer->apply($f['supplier'],$request,$oldHash,'opaque-credit',$f['actor']);
        \MyInvoice\Tests\Support\RefundAccountFixture::document($this->db->pdo(),$f['supplier'],$debtor);$reservation=$writer->reserve($f['supplier'],$op['credit_id'],1,['amount_cents'=>'5000','party_ids'=>[$debtor],'disposition'=>'Instruction person@example.test','recipient_account'=>'1000000005/0100'],'opaque-reservation',$f['actor']);
        $this->db=$source;$this->f=$f;$out=$this->correctionBank('-50.00');
        $refund=['kind'=>'refund','source'=>['kind'=>'bank','id'=>$out,'expected_version'=>0],'amount_cents'=>'3000','accounting_date'=>'2026-02-01','reservation_id'=>$reservation['id'],'expected_reservation_version'=>1,'reason'=>'Synthetic partial refund'];
        $refunded=$writer->apply($f['supplier'],$refund,$writer->preview($f['supplier'],$refund)['preview_hash'],'partial-refund',$f['actor']);
        $setoffRequest=['kind'=>'setoff','credit_id'=>$op['credit_id'],'expected_credit_version'=>3,'amount_cents'=>'1000','accounting_date'=>'2026-02-01','reason'=>'Synthetic credit setoff person@example.test','debts'=>[$base['debt']['id']],
            'payment'=>['debtor_id'=>$debtor,'payer_id'=>$debtor,'received_at'=>'2026-02-01T10:00:00+01:00','choice'=>['claim_ids'=>[$base['debt']['id']],'evidence'=>'Synthetic order']],
            'legal'=>['certain'=>true,'unconditional'=>true,'scope_cents'=>'1000','mutuality_evidence'=>'Synthetic reciprocal parties person@example.test','eligibility_evidence'=>'Synthetic enforceability','declaration_evidence'=>'Synthetic declaration','delivery_evidence'=>'Synthetic receipt','delivered_at'=>'2026-02-01T10:00:00+01:00','effect_evidence'=>'Synthetic effect date','early_performance_evidence'=>'Synthetic agreed early performance']];
        $setoffRepo=$this->paymentFixtureContainer()->get(\MyInvoice\Repository\Property\Payments\CreditSetoffRepository::class);$setoff=$setoffRepo->apply($f['supplier'],$setoffRequest,$setoffRepo->preview($f['supplier'],$setoffRequest)['preview_hash'],'setoff',$f['actor']);
        $sourceHash=$this->fingerprint($source->pdo());
        $cloner=new DatabaseCloner($this->server);$plan=$cloner->plan($this->ownedSchema);$this->targetSchema=CloneSandbox::acquire($this->server,$this->ownedSchema);
        $config=Bootstrap::buildContainer()->get(Config::class);$data=$config->all();$data['db']['name']=$this->targetSchema;$target=Connection::withoutSharedTestConnection(fn()=>new Connection(new Config($data,$config->dataDir())));$this->connections[]=$target;
        $settlementInputs=\MyInvoice\Service\Anonymization\SettlementCloneScrubber::captureInputs($target);
        $scrubber=new \MyInvoice\Service\Anonymization\TextScrubber(new \MyInvoice\Service\Anonymization\Pseudonymizer(str_repeat('synthetic-payments-',3)));
        $registry=(new \MyInvoice\Service\Anonymization\PropertyCloneScrubber($target->pdo(),$scrubber))->run();
        $versions=(new \MyInvoice\Service\Anonymization\ChargesCloneScrubber($target->pdo(),$scrubber))->run($registry);
        $charges=(new \MyInvoice\Service\Anonymization\IssuanceCloneScrubber($target,$scrubber))->run($registry,$versions);
        foreach($target->pdo()->query('SELECT line_id,dimension_type_id,assignment_origin_ref FROM journal_entry_line_dimensions WHERE assignment_origin_ref IS NOT NULL')->fetchAll(PDO::FETCH_ASSOC) as $row)$target->pdo()->prepare('UPDATE journal_entry_line_dimensions SET assignment_origin_ref=? WHERE line_id=? AND dimension_type_id=?')->execute([$scrubber->scrub($row['assignment_origin_ref']),$row['line_id'],$row['dimension_type_id']]);
        foreach($target->pdo()->query('SELECT id,counterparty_account FROM bank_transactions WHERE counterparty_account IS NOT NULL')->fetchAll(PDO::FETCH_ASSOC) as $row){$fact=json_decode($scrubber->scrubJson(json_encode(['counterparty_account'=>$row['counterparty_account']],JSON_THROW_ON_ERROR)),true,flags:JSON_THROW_ON_ERROR);$target->pdo()->prepare('UPDATE bank_transactions SET counterparty_account=? WHERE id=?')->execute([$fact['counterparty_account'],$row['id']]);}
        foreach($target->pdo()->query('SELECT id,partner_name FROM cash_documents')->fetchAll(PDO::FETCH_ASSOC) as $row){$fact=json_decode($scrubber->scrubJson(json_encode(['partner_name'=>$row['partner_name']],JSON_THROW_ON_ERROR)),true,flags:JSON_THROW_ON_ERROR);$target->pdo()->prepare('UPDATE cash_documents SET partner_name=? WHERE id=?')->execute([$fact['partner_name'],$row['id']]);}
        $helper=new \MyInvoice\Service\Anonymization\PaymentsCloneScrubber($target,$scrubber);
        $target->pdo()->prepare('UPDATE unit_payment_operations SET snapshot_hash=? WHERE id=?')->execute([str_repeat('0',64),$op['id']]);$corrupt=$this->fingerprint($target->pdo());
        try{$helper->run($charges,$registry,$settlementInputs);self::fail('Corrupt hash was repaired.');}catch(PaymentException $e){self::assertSame('property_payment_history_corrupt',$e->errorCode);}
        self::assertSame($corrupt,$this->fingerprint($target->pdo()));$target->pdo()->prepare('UPDATE unit_payment_operations SET snapshot_hash=? WHERE id=?')->execute([$oldHash,$op['id']]);
        $target->pdo()->beginTransaction();try{$helper->run($charges,$registry,$settlementInputs);self::fail('Caller transaction accepted.');}catch(\LogicException $e){self::assertStringContainsString('own transaction',$e->getMessage());}finally{$target->pdo()->rollBack();}
        $q=$target->pdo()->prepare('SELECT bt.counterparty_account FROM bank_transactions bt JOIN bank_statements bs ON bs.id=bt.statement_id WHERE bs.supplier_id=? AND bt.id=?');$q->execute([$f['supplier'],$bank]);$counterparty=$q->fetchColumn();
        $target->pdo()->prepare('UPDATE bank_transactions SET counterparty_account=? WHERE id=?')->execute([$counterparty==='1000000005'?'19-1000000005':'1000000005',$bank]);$mismatch=$this->fingerprint($target->pdo());
        try{try{$helper->run($charges,$registry,$settlementInputs);self::fail('Changed physical source repaired.');}catch(PaymentException $e){self::assertSame('property_payment_source_changed',$e->errorCode);}self::assertSame($mismatch,$this->fingerprint($target->pdo()));}
        finally{$target->pdo()->prepare('UPDATE bank_transactions SET counterparty_account=? WHERE id=?')->execute([$counterparty,$bank]);}
        $helper->run($charges,$registry,$settlementInputs);$cloned=$this->fingerprint($target->pdo());$target->pdo()->beginTransaction();
        try{(new PaymentLocks($target))->acquire($f['supplier']);$target->pdo()->prepare("UPDATE journal_entry_line_dimensions SET assignment_origin='rule_default' WHERE supplier_id=? AND line_id=? AND dimension_type_id=?")->execute([$f['supplier'],$line,(int)$value['type_id']]);$mismatch=$this->fingerprint($target->pdo());
            try{(new PaymentSourceProof($target))->current($f['supplier'],'bank',$bank);self::fail('Unrelated origin mismatch repaired.');}catch(PaymentException $e){self::assertSame('property_payment_source_changed',$e->errorCode);}self::assertSame($mismatch,$this->fingerprint($target->pdo()));
        }finally{if($target->pdo()->inTransaction())$target->pdo()->rollBack();}
        self::assertSame($cloned,$this->fingerprint($target->pdo()));$cloner->finalize($plan,$this->targetSchema);
        try{$helper->run($charges,$registry,$settlementInputs);self::fail('Live triggers accepted.');}catch(\LogicException $e){self::assertStringContainsString('trigger installation',$e->getMessage());}
        $clone=$this->writer($target);$changed=$clone->operation($f['supplier'],$op['id']);self::assertNotSame($oldHash,$changed['snapshot_hash']);
        self::assertStringNotContainsString('person@example.test',json_encode($changed,JSON_THROW_ON_ERROR));
        self::assertSame($op['id'],$clone->apply($f['supplier'],$changed['request'],$changed['snapshot_hash'],'opaque-credit',$f['actor'])['id']);
        try{$clone->apply($f['supplier'],$request,$oldHash,'opaque-credit',$f['actor']);self::fail('Old private payload silently accepted.');}catch(PaymentException $e){self::assertSame('property_payment_idempotency_conflict',$e->errorCode);}
        self::assertSame('2000',$clone->credit($f['supplier'],$op['credit_id'])['reserved_cents']);self::assertSame('6000',$clone->credit($f['supplier'],$op['credit_id'])['balance_cents']);
        $clone->cancelReservation($f['supplier'],$reservation['id'],2,'Synthetic cancellation','cancel',$f['actor']);
        $clone->reverse($f['supplier'],$refunded['id'],2,'2026-02-02','Synthetic refund reversal','reverse-refund',$f['actor']);
        $this->db=$target;$clonedSetoff=$clone->operation($f['supplier'],$setoff['id']);$cloneSetoffRepo=$this->paymentFixtureContainer()->get(\MyInvoice\Repository\Property\Payments\CreditSetoffRepository::class);self::assertStringNotContainsString('person@example.test',json_encode($clonedSetoff,JSON_THROW_ON_ERROR));
        self::assertSame($setoff['id'],$cloneSetoffRepo->apply($f['supplier'],$clonedSetoff['request'],$clonedSetoff['snapshot_hash'],'setoff',$f['actor'])['id']);
        $clone->reverse($f['supplier'],$setoff['id'],2,'2026-02-02','Synthetic setoff withdrawal','reverse-setoff',$f['actor']);
        $clone->reverse($f['supplier'],$op['id'],2,'2026-02-02','Synthetic reversal','reverse',$f['actor']);
        self::assertSame('0',$clone->credit($f['supplier'],$op['credit_id'])['balance_cents']);
        self::assertSame($sourceHash,$this->fingerprint($source->pdo()));
    }
    public function testCorrectionCloneRequiresVerifiedS3MapAndPreservesReverseRetryAndConsumedBank():void
    {
        [$source]=$this->connections;$this->db=$source;$source->pdo()->beginTransaction();$this->f=$this->issuanceFixture($source);$f=$this->f;
        $base=$this->settledPaymentFixture('100000','0');$request=$this->negativePaymentCorrection($base,'800','20000');$request['reason']='Synthetic relief person@example.test';
        $repo=$this->correctionWriter();$hash=$repo->preview($f['supplier'],$request)['preview_hash'];$done=$repo->apply($f['supplier'],$request,$hash,'opaque-correction',$f['actor']);
        $reservation=$this->correctionPayments()->reserve($f['supplier'],$done['credit_id'],1,['amount_cents'=>'1000','party_ids'=>[$f['clients'][1]],'disposition'=>'Synthetic reservation','recipient_account'=>'1000000005/0100'],'reserve',$f['actor']);$source->pdo()->commit();$before=$this->fingerprint($source->pdo());
        $cloner=new DatabaseCloner($this->server);$plan=$cloner->plan($this->ownedSchema);$this->targetSchema=CloneSandbox::acquire($this->server,$this->ownedSchema);
        $config=Bootstrap::buildContainer()->get(Config::class);$data=$config->all();$data['db']['name']=$this->targetSchema;$target=Connection::withoutSharedTestConnection(fn()=>new Connection(new Config($data,$config->dataDir())));$this->connections[]=$target;
        $settlementInputs=\MyInvoice\Service\Anonymization\SettlementCloneScrubber::captureInputs($target);
        $scrubber=new \MyInvoice\Service\Anonymization\TextScrubber(new \MyInvoice\Service\Anonymization\Pseudonymizer(str_repeat('synthetic-correction-',3)));
        foreach($target->pdo()->query('SELECT id,counterparty_account FROM bank_transactions WHERE counterparty_account IS NOT NULL')->fetchAll(PDO::FETCH_ASSOC) as $row){$fact=json_decode($scrubber->scrubJson(json_encode(['counterparty_account'=>$row['counterparty_account']],JSON_THROW_ON_ERROR)),true,flags:JSON_THROW_ON_ERROR);$target->pdo()->prepare('UPDATE bank_transactions SET counterparty_account=? WHERE id=?')->execute([$fact['counterparty_account'],$row['id']]);}
        $registry=(new \MyInvoice\Service\Anonymization\PropertyCloneScrubber($target->pdo(),$scrubber))->run();$versions=(new \MyInvoice\Service\Anonymization\ChargesCloneScrubber($target->pdo(),$scrubber))->run($registry);
        foreach($target->pdo()->query('SELECT id,partner_name FROM cash_documents')->fetchAll(PDO::FETCH_ASSOC) as $row){$fact=json_decode($scrubber->scrubJson(json_encode(['partner_name'=>$row['partner_name']],JSON_THROW_ON_ERROR)),true,flags:JSON_THROW_ON_ERROR);$target->pdo()->prepare('UPDATE cash_documents SET partner_name=? WHERE id=?')->execute([$fact['partner_name'],$row['id']]);}
        foreach($target->pdo()->query('SELECT line_id,dimension_type_id,assignment_origin_ref FROM journal_entry_line_dimensions WHERE assignment_origin_ref IS NOT NULL')->fetchAll(PDO::FETCH_ASSOC) as $row)$target->pdo()->prepare('UPDATE journal_entry_line_dimensions SET assignment_origin_ref=? WHERE line_id=? AND dimension_type_id=?')->execute([$scrubber->scrub($row['assignment_origin_ref']),$row['line_id'],$row['dimension_type_id']]);
        $charges=(new \MyInvoice\Service\Anonymization\IssuanceCloneScrubber($target,$scrubber))->run($registry,$versions);$helper=new \MyInvoice\Service\Anonymization\PaymentsCloneScrubber($target,$scrubber);$unchanged=$this->fingerprint($target->pdo());
        try{$helper->run([],$registry,$settlementInputs);self::fail('Unproved S3 hashes accepted.');}catch(PaymentException $e){self::assertSame('property_payment_clone_issuance_proof',$e->errorCode);}self::assertSame($unchanged,$this->fingerprint($target->pdo()));
        $helper->run($charges,$registry,$settlementInputs);$cloner->finalize($plan,$this->targetSchema);$this->db=$target;$clone=$this->correctionWriter();$read=$clone->find($f['supplier'],$done['id']);
        self::assertNotSame($hash,$read['snapshot_hash']);self::assertStringNotContainsString('person@example.test',json_encode($read,JSON_THROW_ON_ERROR));
        self::assertSame($done['id'],$clone->apply($f['supplier'],$read['request'],$read['snapshot_hash'],'opaque-correction',$f['actor'])['id']);
        $this->correctionPayments()->cancelReservation($f['supplier'],$reservation['id'],1,'Synthetic cancellation','cancel',$f['actor']);
        self::assertSame('reversed',$clone->reverse($f['supplier'],$done['id'],['expected_version'=>2,'accounting_date'=>'2026-02-03','effective_at'=>'2026-02-03T10:00:00+01:00','reason'=>'Synthetic reverse','evidence'=>'Synthetic decision'],'reverse',$f['actor'])['status']);
        self::assertSame($done['id'],$clone->apply($f['supplier'],$read['request'],$read['snapshot_hash'],'opaque-correction',$f['actor'])['id']);
        $target->pdo()->beginTransaction();(new PaymentLocks($target))->acquire($f['supplier']);self::assertSame('0',(new PaymentSourceProof($target))->current($f['supplier'],'bank',$base['bank'])['available_cents']);$target->pdo()->commit();
        self::assertSame($before,$this->fingerprint($source->pdo()));
    }
    private function fingerprint(PDO $pdo):string
    {
        $rows=[];foreach(['unit_payment_sources','unit_payment_operations','unit_credit_lots','unit_credit_parties','unit_credit_reservations','unit_credit_events','unit_payment_audit','unit_payment_corrections','unit_payment_correction_anchors','unit_payment_reliefs','unit_payment_debts','bank_transactions','journal_entry_line_dimensions'] as $table)$rows[$table]=$pdo->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC);return \MyInvoice\Service\Property\PropertyInput::hash($rows);
    }
    private function writer(Connection $db):PaymentRepository
    {
        $container=Bootstrap::buildContainer();$container->set(Connection::class,$db);return new PaymentRepository($db,$container->get(PostingService::class));
    }
    private function bank(Connection $db,array $f):int
    {
        $pdo=$db->pdo();$pdo->prepare("INSERT INTO bank_statements (supplier_id,file_name,file_hash,account_number,bank_code,currency,statement_date) VALUES (?,'Synthetic',?,'1000000005','0100','CZK','2026-02-01')")->execute([$f['supplier'],hash('sha256',random_bytes(20))]);$statement=(int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO bank_transactions (statement_id,posted_at,amount,currency) VALUES (?,'2026-02-01','100.00','CZK')")->execute([$statement]);$id=(int)$pdo->lastInsertId();
        $container=Bootstrap::buildContainer();$container->set(Connection::class,$db);$container->get(PostingService::class)->postDocument($f['supplier'],'bank',$id,[['account_code'=>'221','side'=>'debit','amount'=>'100.00'],['account_code'=>'379.900','side'=>'credit','amount'=>'100.00']],['entry_date'=>'2026-02-01','posted_by'=>$f['actor']]);return $id;
    }
}
