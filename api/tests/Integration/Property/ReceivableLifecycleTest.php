<?php
declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Property;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Anonymization\{DatabaseCloner,TextScrubber,Pseudonymizer,PropertyCloneScrubber,ChargesCloneScrubber,IssuanceCloneScrubber,PaymentsCloneScrubber,ReceivablesCloneScrubber};
use MyInvoice\Repository\Property\Receivables\{PartyEventRepository,DebtCertificateRepository};
use MyInvoice\Service\Property\Payments\PaymentLocks;
use MyInvoice\Service\Property\PropertyException;
use MyInvoice\Tests\Support\{ChargeIssuanceFixture,CloneSandbox,PaymentCorrectionFixture,ReceivableFixture};
use PDO;
use PHPUnit\Framework\TestCase;
final class ReceivableLifecycleTest extends TestCase
{
    use ChargeIssuanceFixture,PaymentCorrectionFixture,ReceivableFixture;
    private Connection $db;private array $f;private PDO $server;private string $ownedSchema;private ?string $targetSchema=null;private string $targetSchemaName;private array $connections=[];private Config $config;
    protected function setUp():void
    {
        $container=Bootstrap::buildContainer();$this->config=$container->get(Config::class);$source=(string)$container->get(Connection::class)->pdo()->query('SELECT DATABASE()')->fetchColumn();
        self::assertStringEndsWith('_test',$source);self::assertContains($this->config->get('db.host'),['localhost','127.0.0.1','::1']);
        $this->targetSchemaName='myucto_s5_lifecycle_'.bin2hex(random_bytes(5)).'_test_copy_test';$this->server=new PDO('mysql:host='.$this->config->get('db.host').';port='.$this->config->get('db.port',3306).';charset=utf8mb4',$this->config->get('db.user'),$this->config->get('db.pass',''),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $this->ownedSchema=CloneSandbox::acquire($this->server,$source,true);
        $this->connect($this->ownedSchema);$this->connect($this->ownedSchema);$this->db=$this->connections[0];
    }
    protected function tearDown():void
    {
        foreach($this->connections as $db){if($db->pdo()->inTransaction())$db->pdo()->rollBack();$db->close();}
        $schemas=array_filter([$this->targetSchema,$this->ownedSchema]);
        foreach($schemas as $schema){
            if($schema===$this->ownedSchema){
                self::assertMatchesRegularExpression('/^myucto_clonesandbox_[a-f0-9]{10}_test$/D',$schema);
                self::assertTrue(CloneSandbox::isLeased($schema));
            }else{
                self::assertSame($this->targetSchemaName,$schema);
                self::assertMatchesRegularExpression('/^myucto_s5_lifecycle_[a-f0-9]{10}_test(?:_copy_test)?$/D',$schema);
            }
            CloneSandbox::dropOrRelease($this->server,$schema,$schemas);
        }
    }
    public function testOldSnapshotCannotHideCurrentPartyHeadAndPeriodOwnershipSerializes():void
    {
        [$a,$b]=$this->connections;$a->pdo()->beginTransaction();$this->f=$this->issuanceFixture($a);$base=$this->settledPaymentFixture('1000','0');$old=$base['charge']['snapshot']['liability_groups'][0]['members'][0];$request=$this->request($base,$this->f['clients'][2],1,null,'2026-02-02T10:00:00+01:00',[$old]);$a->pdo()->commit();
        $writerA=new PartyEventRepository($a);$writerB=new PartyEventRepository($b);$preview=$writerA->preview($this->f['supplier'],$request);
        $a->pdo()->beginTransaction();$a->pdo()->query('SELECT COUNT(*) FROM property_claim_party_events')->fetchColumn();
        $winner=$writerB->confirm($this->f['supplier'],$request,$preview['preview_hash'],'winner',$this->f['actor']);
        try{$writerA->confirm($this->f['supplier'],$request,$preview['preview_hash'],'stale',$this->f['actor']);self::fail('Old snapshot hid current party event');}catch(PropertyException $e){self::assertSame('property_receivable_version_conflict',$e->errorCode);}
        self::assertCount(1,$writerA->history($this->f['supplier'],true));$a->pdo()->rollBack();
        $a->pdo()->beginTransaction();(new PaymentLocks($a))->acquire($this->f['supplier']);$b->pdo()->exec('SET SESSION innodb_lock_wait_timeout=1');
        try{$writerB->preview($this->f['supplier'],$request);self::fail('Party confirmation bypassed owner locks');}catch(\PDOException $e){self::assertSame(1205,(int)$e->errorInfo[1]);}$a->pdo()->rollBack();self::assertFalse($b->pdo()->inTransaction());
        self::assertSame($winner['id'],$writerB->confirm($this->f['supplier'],$request,$preview['preview_hash'],'winner',$this->f['actor'])['id']);
    }
    public static function resetModes(): array
    {
        return ['keep users and supplier' => [true], 'full reset' => [false]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('resetModes')]
    public function testNativeResetClearsTransferGuaranteesAndCertificates(bool $keep): void
    {
        $source = $this->db;
        $source->pdo()->beginTransaction();
        $this->f = $this->issuanceFixture($source);
        $base = $this->settledPaymentFixture('1000', '5000');
        $old = $base['charge']['snapshot']['liability_groups'][0]['members'][0];
        $request = $this->request($base, $this->f['clients'][2], 1, null, '2026-02-02T10:00:00+01:00', [$old]);
        $events = new PartyEventRepository($source);
        $events->confirm($this->f['supplier'], $request, $events->preview($this->f['supplier'], $request)['preview_hash'], 'reset-transfer', $this->f['actor']);
        $certificates = new DebtCertificateRepository($source);
        $request = ['unit_id' => $base['charge']['unit_id'], 'as_of' => '2026-02-03T00:00:00+01:00', 'known_at' => PartyEventRepository::now(),
            'requester_id' => $old, 'authority_evidence' => 'Synthetic authority', 'purpose' => 'Synthetic reset', 'coverage_acknowledgement' => 'Recorded S3 S4 scope'];
        $certificates->issue($this->f['supplier'], $request, $certificates->preview($this->f['supplier'], $request)['preview_hash'], 'reset-certificate', $this->f['actor']);
        $source->pdo()->commit();
        foreach (['property_claim_party_events', 'property_claim_party_event_debts', 'property_claim_party_members', 'property_debt_certificates'] as $table) {
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

    public function testFullAnonymizationPreservesPaymentsLegalHistoryAndRetryWithoutPrivateText(): void
    {
        $source = $this->db;
        $source->pdo()->beginTransaction();
        $this->f = $this->issuanceFixture($source);
        $f = $this->f;
        $base = $this->settledPaymentFixture('1000', '5000');
        $old = $base['charge']['snapshot']['liability_groups'][0]['members'][0];
        $request = $this->request($base, $f['clients'][2], 1, null, '2026-02-02T10:00:00+01:00', [$old]);
        $request['acquisition']['evidence'] = 'Synthetic full-pipeline-person@example.test';
        $events = new PartyEventRepository($source);
        $event = $events->confirm($f['supplier'], $request, $events->preview($f['supplier'], $request)['preview_hash'], 'full-transfer', $f['actor']);
        $certificates = new DebtCertificateRepository($source);
        $certRequest = ['unit_id' => $base['charge']['unit_id'], 'as_of' => '2026-02-03T00:00:00+01:00', 'known_at' => PartyEventRepository::now(),
            'requester_id' => $old, 'authority_evidence' => 'Synthetic full-pipeline-person@example.test', 'purpose' => 'Synthetic history', 'coverage_acknowledgement' => 'S3 S4 scope only'];
        $certificate = $certificates->issue($f['supplier'], $certRequest, $certificates->preview($f['supplier'], $certRequest)['preview_hash'], 'full-certificate', $f['actor']);
        $this->correctionPayments()->reserve($f['supplier'], $base['operation']['credit_id'], 1, ['amount_cents' => '1000', 'party_ids' => [$f['clients'][1]],
            'disposition' => 'Synthetic full-pipeline-person@example.test', 'recipient_account' => '1000000005/0100'], 'full-reservation', $f['actor']);
        $source->pdo()->commit();
        $original = $this->fingerprint($source->pdo());
        $money = $this->moneySnapshot($source->pdo(), $f['supplier']);

        $this->targetSchema = $this->targetSchemaName;
        (new \MyInvoice\Service\Anonymization\AnonymizationService($this->config))->run(
            new \MyInvoice\Service\Anonymization\AnonymizationOptions(source: $this->ownedSchema, target: $this->targetSchema, seed: 'synthetic-s4-s5-full-pipeline'),
            static function (): void {},
        );
        $target = $this->connect($this->targetSchema);
        foreach (['property_claim_party_events', 'property_debt_certificates', 'unit_credit_reservations'] as $table) {
            $q = $target->pdo()->prepare('SELECT * FROM ' . $table . ' WHERE supplier_id=?');
            $q->execute([$f['supplier']]);
            $rows = $q->fetchAll(PDO::FETCH_ASSOC);
            self::assertNotEmpty($rows);
            self::assertFalse(str_contains(json_encode($rows, JSON_THROW_ON_ERROR), 'full-pipeline-person@example.test'), $table . ' retains private text.');
        }
        self::assertSame($money, $this->moneySnapshot($target->pdo(), $f['supplier']));
        $cloneEvents = new PartyEventRepository($target);
        $cloneEvent = $cloneEvents->history($f['supplier'])[0];
        self::assertSame($event['id'], $cloneEvent['id']);
        self::assertSame($event['id'], $cloneEvents->confirm($f['supplier'], $cloneEvent['snapshot']['request'], $cloneEvent['snapshot_hash'], 'full-transfer', $f['actor'])['id']);
        $cloneCertificates = new DebtCertificateRepository($target);
        $cloneCertificate = $cloneCertificates->find($f['supplier'], $certificate['id']);
        self::assertSame($certificate['id'], $cloneCertificates->issue($f['supplier'], $cloneCertificate['snapshot']['request'], $cloneCertificate['snapshot_hash'], 'full-certificate', $f['actor'])['id']);
        $this->db = $target;
        $credit = $this->correctionPayments()->credit($f['supplier'], $base['operation']['credit_id']);
        self::assertSame(['5000', '1000', '4000'], [$credit['balance_cents'], $credit['reserved_cents'], $credit['available_cents']]);
        self::assertSame($original, $this->fingerprint($source->pdo()));
    }

    private function moneySnapshot(PDO $pdo, int $supplier): array
    {
        $result = [];
        foreach (['unit_payment_allocations', 'unit_credit_lots', 'unit_credit_reservations'] as $table) {
            $q = $pdo->prepare('SELECT COUNT(*) n, SUM(amount_cents) amount_cents FROM ' . $table . ' WHERE supplier_id=?');
            $q->execute([$supplier]);
            $result[$table] = $q->fetch(PDO::FETCH_ASSOC);
        }
        $q = $pdo->prepare("SELECT COUNT(*) n, SUM(CASE l.side WHEN 'debit' THEN l.amount ELSE 0 END) debit, SUM(CASE l.side WHEN 'credit' THEN l.amount ELSE 0 END) credit FROM journal_entry_lines l WHERE l.supplier_id=?");
        $q->execute([$supplier]);
        $result['journal'] = $q->fetch(PDO::FETCH_ASSOC);
        return $result;
    }

    public function testClonePreservesLegalHistoryRetryAndRejectsTamperWithoutRepair():void
    {
        $this->db->pdo()->beginTransaction();$this->f=$this->issuanceFixture($this->db);$base=$this->settledPaymentFixture('1000','5000');$f=$this->f;$old=$base['charge']['snapshot']['liability_groups'][0]['members'][0];$request=$this->request($base,$f['clients'][2],1,null,'2026-02-02T10:00:00+01:00',[$old]);$request['acquisition']['evidence']='Synthetic person@example.test';$request['acquisition']['kind']='Synthetic deed person@example.test';$repo=new PartyEventRepository($this->db);$event=$repo->confirm($f['supplier'],$request,$repo->preview($f['supplier'],$request)['preview_hash'],'opaque-transfer',$f['actor']);
        $certRepo=new DebtCertificateRepository($this->db);$certRequest=['unit_id'=>$base['charge']['unit_id'],'as_of'=>'2026-02-03T00:00:00+01:00','known_at'=>PartyEventRepository::now(),'requester_id'=>$old,'authority_evidence'=>'person@example.test','purpose'=>'Synthetic history','coverage_acknowledgement'=>'S3 S4 scope only'];$certificate=$certRepo->issue($f['supplier'],$certRequest,$certRepo->preview($f['supplier'],$certRequest)['preview_hash'],'opaque-certificate',$f['actor']);$this->db->pdo()->commit();
        $sourceHash=$this->fingerprint($this->db->pdo());$cloner=new DatabaseCloner($this->server);$plan=$cloner->plan($this->ownedSchema);$this->targetSchema=$this->targetSchemaName;$cloner->copyTables($plan,$this->targetSchema,static function():void{});$target=$this->connect($this->targetSchema);
        $settlementInputs=\MyInvoice\Service\Anonymization\SettlementCloneScrubber::captureInputs($target);
        $scrubber=new TextScrubber(new Pseudonymizer(str_repeat('synthetic-s5-',4)));$registry=(new PropertyCloneScrubber($target->pdo(),$scrubber))->run();$versions=(new ChargesCloneScrubber($target->pdo(),$scrubber))->run($registry);$issuance=(new IssuanceCloneScrubber($target,$scrubber))->run($registry,$versions);
        foreach($target->pdo()->query('SELECT id,counterparty_account FROM bank_transactions WHERE counterparty_account IS NOT NULL')->fetchAll(PDO::FETCH_ASSOC) as $row){$fact=json_decode($scrubber->scrubJson(json_encode(['counterparty_account'=>$row['counterparty_account']],JSON_THROW_ON_ERROR)),true,flags:JSON_THROW_ON_ERROR);$target->pdo()->prepare('UPDATE bank_transactions SET counterparty_account=? WHERE id=?')->execute([$fact['counterparty_account'],$row['id']]);}
        foreach($target->pdo()->query('SELECT line_id,dimension_type_id,assignment_origin_ref FROM journal_entry_line_dimensions WHERE assignment_origin_ref IS NOT NULL')->fetchAll(PDO::FETCH_ASSOC) as $row)$target->pdo()->prepare('UPDATE journal_entry_line_dimensions SET assignment_origin_ref=? WHERE line_id=? AND dimension_type_id=?')->execute([$scrubber->scrub($row['assignment_origin_ref']),$row['line_id'],$row['dimension_type_id']]);
        foreach($target->pdo()->query('SELECT id,partner_name FROM cash_documents')->fetchAll(PDO::FETCH_ASSOC) as $row){$fact=json_decode($scrubber->scrubJson(json_encode(['partner_name'=>$row['partner_name']],JSON_THROW_ON_ERROR)),true,flags:JSON_THROW_ON_ERROR);$target->pdo()->prepare('UPDATE cash_documents SET partner_name=? WHERE id=?')->execute([$fact['partner_name'],$row['id']]);}
        $payments=(new PaymentsCloneScrubber($target,$scrubber))->run($issuance,$registry,$settlementInputs);$helper=new ReceivablesCloneScrubber($target,$scrubber);
        $target->pdo()->prepare('UPDATE property_claim_party_events SET snapshot_hash=? WHERE id=?')->execute([str_repeat('0',64),$event['id']]);$corrupt=$this->fingerprint($target->pdo());
        try{$helper->run($registry,$issuance,$payments);self::fail('Corrupt raw hash repaired');}catch(PropertyException $e){self::assertSame('property_receivable_history_corrupt',$e->errorCode);}self::assertSame($corrupt,$this->fingerprint($target->pdo()));
        $target->pdo()->prepare('UPDATE property_claim_party_events SET snapshot_hash=? WHERE id=?')->execute([$event['snapshot_hash'],$event['id']]);
        $target->pdo()->beginTransaction();try{$helper->run($registry,$issuance,$payments);self::fail('Caller transaction accepted');}catch(\LogicException $e){self::assertStringContainsString('own transaction',$e->getMessage());}finally{$target->pdo()->rollBack();}
        $map=$helper->run($registry,$issuance,$payments);
        $target->pdo()->beginTransaction();
        try{$target->pdo()->prepare('DELETE FROM unit_payment_allocations WHERE supplier_id=? AND id=?')->execute([$f['supplier'],$base['allocation_id']]);
            try{(new \MyInvoice\Repository\Property\Receivables\FinancialHistoryRepository($target))->read($f['supplier'],true);self::fail('Missing materialized allocation changed saldo silently');}catch(PropertyException $e){self::assertSame('property_receivable_history_corrupt',$e->errorCode);}
        }finally{$target->pdo()->rollBack();}
        $cloneFinancial=(new \MyInvoice\Repository\Property\Receivables\FinancialHistoryRepository($target))->read($f['supplier']);self::assertCount(2,$cloneFinancial['debts'][$base['debt']['id']]['events']);
        $cloner->finalize($plan,$this->targetSchema);
        self::assertNotSame($event['snapshot_hash'],$map[$event['snapshot_hash']]);$cloneEvent=(new PartyEventRepository($target))->history($f['supplier'])[0];self::assertStringNotContainsString('person@example.test',json_encode($cloneEvent,JSON_THROW_ON_ERROR));
        self::assertSame($cloneEvent['id'],(new PartyEventRepository($target))->confirm($f['supplier'],$cloneEvent['snapshot']['request'],$cloneEvent['snapshot_hash'],'opaque-transfer',$f['actor'])['id']);
        $cloneCert=(new DebtCertificateRepository($target))->find($f['supplier'],$certificate['id']);self::assertStringNotContainsString('person@example.test',json_encode($cloneCert,JSON_THROW_ON_ERROR));self::assertSame($cloneCert['id'],(new DebtCertificateRepository($target))->issue($f['supplier'],$cloneCert['snapshot']['request'],$cloneCert['snapshot_hash'],'opaque-certificate',$f['actor'])['id']);
        $this->db=$target;try{$this->correctionPayments()->reverse($f['supplier'],$base['operation']['id'],2,'2026-02-04','Synthetic withdrawal','blocked',$f['actor']);self::fail('Clone lost pinned source ownership');}catch(\MyInvoice\Service\Property\Payments\PaymentException $e){self::assertSame('property_payment_party_history_in_use',$e->errorCode);}
        self::assertSame($sourceHash,$this->fingerprint($this->connections[0]->pdo()));
        try{$helper->run($registry,$issuance,$payments);self::fail('Live triggers accepted');}catch(\LogicException $e){self::assertStringContainsString('trigger installation',$e->getMessage());}
    }
    private function connect(string $schema):Connection{$data=$this->config->all();$data['db']['name']=$schema;$db=Connection::withoutSharedTestConnection(fn()=>new Connection(new Config($data,$this->config->dataDir())));$db->pdo()->exec("SET SESSION sql_mode='STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");$db->pdo()->exec('SET SESSION innodb_snapshot_isolation=0');$db->pdo()->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');$this->connections[]=$db;return $db;}
    private function fingerprint(PDO $pdo):string{$data=[];foreach(['property_claim_party_events','property_claim_party_event_debts','property_claim_party_members','property_debt_certificates','unit_payment_operations','unit_payment_debts','unit_credit_lots'] as $table)$data[$table]=$pdo->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC);return hash('sha256',json_encode($data,JSON_THROW_ON_ERROR));}
}
