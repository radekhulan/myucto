<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Property;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Charges\ChargeRepository;
use MyInvoice\Repository\Property\Charges\Issuance\IssuanceRepository;
use MyInvoice\Service\Property\Charges\ChargePreviewService;
use MyInvoice\Service\Property\Charges\Issuance\IssuanceCaseIdentity;
use MyInvoice\Service\Property\Charges\Issuance\IssuanceContextService;
use MyInvoice\Service\Property\Charges\Issuance\IssuanceException;
use MyInvoice\Service\Property\Charges\Issuance\IssuanceJournalProof;
use MyInvoice\Service\Property\Charges\Issuance\IssuancePreviewService;
use MyInvoice\Tests\Support\ChargeIssuanceFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class ChargeIssuanceCaseIdentityTest extends TestCase
{
    use ChargeIssuanceFixture;
    private Connection $db;
    private array $f;
    private array $request;
    private int $token = 0;

    protected function setUp(): void
    {
        $this->db = Bootstrap::buildContainer()->get(Connection::class);
        self::assertStringEndsWith('_test',(string)$this->db->pdo()->query('SELECT DATABASE()')->fetchColumn());
        $this->db->pdo()->beginTransaction();
        $this->f = $this->issuanceFixture($this->db);
        $version = self::chargeRequest($this->f);
        $version['expected_revision'] = 1;
        $version['rates'] = array_values(array_filter($version['rates'],fn($r)=>$r['component_id']===$this->f['components']['ADMIN']));
        $preview = (new ChargePreviewService($this->db))->preview($this->f['supplier'],$version);
        $saved = (new ChargeRepository($this->db))->saveVersion($this->f['supplier'],['request'=>$version,'preview_hash'=>$preview['preview_hash'],'idempotency_key'=>'identity-version'],$this->f['actor']);
        $this->request = $this->f['issue_request']; $this->request['version_id'] = $saved['id'];
        $this->request['units'] = array_values(array_filter($this->request['units'],fn($u)=>$u['unit_id']===$this->f['units']['G12']));
    }

    protected function tearDown(): void
    {
        if(isset($this->db)&&$this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
    }

    public static function duplicateCases(): array
    {
        return ['reload'=>['same'],'changed dates and title'=>['metadata'],'Unicode equivalent'=>['unicode'],'distinction cannot bypass'=>['distinction']];
    }

    #[DataProvider('duplicateCases')]
    public function testNewOpaqueKeyCannotPostTheSameLegalCaseTwice(string $change): void
    {
        $this->request['case_identity']['case_ref']='Případ Á';
        $first=$this->post($this->issue($this->request));
        self::assertSame('1000.00',$this->balance());
        $request=$this->request; $request['source_key']='NEW-UI-UUID';
        if($change==='metadata') { $request['accounting_date']='2026-01-02';$request['due_date']='2026-02-15';$request['title']='extra_contribution'; }
        if($change==='unicode') $request['case_identity']['case_ref']="  PŘÍPAD   A\u{0301}  ";
        if($change==='distinction') $request['units'][0]['case_distinction']=$this->distinction([$this->request['source_key']]);
        $before=$this->counts();
        try { $this->post($this->issue($request)); self::fail('Reload duplicated native receivable: '.$this->balance()); }
        catch(IssuanceException $e) { self::assertSame('property_charge_case_exists',$e->errorCode); self::assertSame(409,$e->httpStatus); }
        self::assertSame($before,$this->counts()); self::assertSame('1000.00',$this->balance());
        (new IssuanceJournalProof($this->db))->assertEntry($this->f['supplier'],$first['charges'][0]['journal_entry_id'],$first['charges'][0]);
        self::assertTrue($this->db->pdo()->inTransaction());
    }

    public function testIndependentEvidencedCaseAndRetryPreserveExactAmounts(): void
    {
        $this->post($this->issue($this->request));
        $request=$this->request; $request['source_key']='INDEPENDENT'; $request['case_identity']['occurrence_ref']='2026-SPLATKA-B';
        try { $this->issue($request); self::fail('Overlapping legal cases were not distinguished.'); }
        catch(IssuanceException $e) { self::assertSame('property_charge_case_distinction_required',$e->errorCode); }
        $request['units'][0]['case_distinction']=$this->distinction([$this->request['source_key']]);
        $preview=$this->preview($request);
        self::assertSame(['period_overlap'],$preview['snapshot']['charges'][0]['case_candidates'][0]['reasons']);
        $input=['request'=>$request,'preview_hash'=>$preview['preview_hash'],'idempotency_key'=>'same-token'];
        $writer=$this->writer(); $batch=$writer->issue($this->f['supplier'],$input,$this->f['actor']);
        self::assertSame($batch,$writer->issue($this->f['supplier'],$input,$this->f['actor']));
        $posted=$this->post($batch); self::assertSame('2000.00',$this->balance());
        (new IssuanceJournalProof($this->db))->assertEntry($this->f['supplier'],$posted['charges'][0]['journal_entry_id'],$posted['charges'][0]);
        $input['request']['case_identity']['evidence_ref']='Changed legal evidence';
        try { $writer->issue($this->f['supplier'],$input,$this->f['actor']); self::fail('Token ignored changed evidence.'); }
        catch(IssuanceException $e) { self::assertSame('property_charge_issue_idempotency_conflict',$e->errorCode); }
        $context=(new IssuanceContextService($this->db))->context($this->f['supplier'],$request['version_id'],'2026-01-01T10:00:00+01:00');
        self::assertCount(2,$context['case_families']);
        self::assertSame($request['case_identity'],$context['case_families'][1]['case_identity']);
    }

    public function testCurrentCandidateRevisionInvalidatesOldPreview(): void
    {
        $first=$this->issue($this->request);
        $request=$this->request; $request['source_key']='SEPARATE';$request['case_identity']['case_ref']='Separate case';
        $request['units'][0]['case_distinction']=$this->distinction([$this->request['source_key']]);
        $preview=$this->preview($request); $this->post($first);
        $before=$this->counts();
        try { $this->writer()->issue($this->f['supplier'],['request'=>$request,'preview_hash'=>$preview['preview_hash'],'idempotency_key'=>'stale-preview'],$this->f['actor']); self::fail('Old candidate revision authorized write.'); }
        catch(IssuanceException $e) { self::assertSame('property_charge_issue_preview_stale',$e->errorCode); }
        self::assertSame($before,$this->counts());
        self::assertNotSame($preview['preview_hash'],$this->preview($request)['preview_hash']);
    }

    public function testContinuationKeepsRootIdentityAndTerminalFamilyCannotBeRecreated(): void
    {
        $first=$this->post($this->issue($this->request))['charges'][0];
        $reverse=['row_version'=>$first['row_version'],'accounting_date'=>'2026-02-01','effective_at'=>'2026-02-01T10:00:00+01:00',
            'reason'=>'Synthetic full reversal','evidence_ref'=>'Synthetic deed','idempotency_key'=>'reverse-first'];
        $this->writer()->reverse($this->f['supplier'],$first['id'],$reverse,$this->f['actor']); self::assertSame('0.00',$this->balance());
        $replacement=$this->request; $replacement['issue_mode']='replacement'; $replacement['units'][0]['expected_revision']=1; $replacement['units'][0]['previous_charge_id']=$first['id'];
        $changed=$replacement; $changed['case_identity']['evidence_ref']='Changed root';
        try { $this->issue($changed); self::fail('Root metadata changed.'); }
        catch(IssuanceException $e) { self::assertSame('property_charge_case_identity_changed',$e->errorCode); }
        $new=$this->request;$new['source_key']='RELOADED-TERMINAL';
        try { $this->issue($new);self::fail('Terminal family lost identity.'); }
        catch(IssuanceException $e) { self::assertSame('property_charge_case_exists',$e->errorCode); }
        $last=$this->post($this->issue($replacement));self::assertSame('1000.00',$this->balance());
        self::assertSame($first['snapshot']['case_identity'],$last['charges'][0]['snapshot']['case_identity']);
    }

    public function testMultiUnitCollisionRollsBackEntireBatchAndCallerWorkSurvives(): void
    {
        $this->issue($this->request);
        $request=$this->f['issue_request'];$request['version_id']=$this->request['version_id'];$request['source_key']='MULTI-RELOAD';
        $previewHash=str_repeat('0',64);$before=$this->counts();
        try { $this->writer()->issue($this->f['supplier'],['request'=>$request,'preview_hash'=>$previewHash,'idempotency_key'=>'multi-collision'],$this->f['actor']);self::fail('Colliding unit accepted.'); }
        catch(IssuanceException $e) { self::assertSame('property_charge_case_exists',$e->errorCode); }
        self::assertSame($before,$this->counts());self::assertTrue($this->db->pdo()->inTransaction());
        $this->db->pdo()->rollBack();
        self::assertSame(0,(int)$this->db->pdo()->query('SELECT COUNT(*) FROM unit_charge_batches WHERE supplier_id='.(int)$this->f['supplier'])->fetchColumn());
    }

    public function testLegacyStoredShapeKeepsExactBytesAndCanBeReplacedWithoutInventedIdentity(): void
    {
        $this->db->pdo()->exec('SAVEPOINT legacy_fixture');
        $modern=$this->issue($this->request);$tables=['unit_charge_batches','unit_charges','unit_charge_lines','unit_charge_parties','unit_charge_notices','unit_charge_issue_audit'];$rows=[];
        foreach($tables as $table){$columns=$this->db->pdo()->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND (GENERATION_EXPRESSION IS NULL OR GENERATION_EXPRESSION='') ORDER BY ORDINAL_POSITION");$columns->execute([$table]);$names=$columns->fetchAll(\PDO::FETCH_COLUMN);self::assertNotEmpty($names);$q=$this->db->pdo()->prepare('SELECT `'.implode('`,`',$names).'` FROM '.$table.' WHERE supplier_id=? ORDER BY id');$q->execute([$this->f['supplier']]);$rows[$table]=$q->fetchAll(\PDO::FETCH_ASSOC);}
        $this->db->pdo()->exec('ROLLBACK TO SAVEPOINT legacy_fixture');$hashes=[];
        $strip=static function(array $charge):array{unset($charge['case_identity'],$charge['case_distinction'],$charge['case_candidates']);return $charge;};
        foreach($rows['unit_charges']as&$row){$old=$row['snapshot_hash'];$snapshot=$strip(json_decode($row['snapshot_json'],true,flags:JSON_THROW_ON_ERROR));$row['snapshot_json']=\MyInvoice\Service\Property\Charges\Issuance\IssuanceRequest::json($snapshot);$row['snapshot_hash']=\MyInvoice\Service\Property\PropertyInput::hash($snapshot);$hashes[$old]=$row['snapshot_hash'];}unset($row);
        foreach($rows['unit_charge_batches']as&$row){
            $request=json_decode($row['request_json'],true,flags:JSON_THROW_ON_ERROR);unset($request['case_identity']);
            $snapshot=json_decode($row['snapshot_json'],true,flags:JSON_THROW_ON_ERROR);$snapshot['request']=$request;$snapshot['charges']=array_map($strip,$snapshot['charges']);
            $old=$row['preview_hash'];$row['preview_hash']=\MyInvoice\Service\Property\PropertyInput::hash($snapshot);$hashes[$old]=$row['preview_hash'];
            $old=$row['request_hash'];$row['request_hash']=\MyInvoice\Service\Property\PropertyInput::hash(['request'=>$request,'preview_hash'=>$row['preview_hash'],'idempotency_key'=>$row['idempotency_key'],'actor'=>(int)$row['created_by']]);$hashes[$old]=$row['request_hash'];
            $row['request_json']=\MyInvoice\Service\Property\Charges\Issuance\IssuanceRequest::json($request);$row['snapshot_json']=\MyInvoice\Service\Property\Charges\Issuance\IssuanceRequest::json($snapshot);
        }unset($row);
        foreach($rows['unit_charge_issue_audit']as&$row){$payload=json_decode($row['payload_json'],true,flags:JSON_THROW_ON_ERROR);foreach($payload as &$value)if(is_string($value)&&isset($hashes[$value]))$value=$hashes[$value];unset($value);$row['payload_json']=\MyInvoice\Service\Property\Charges\Issuance\IssuanceRequest::json($payload);$row['payload_hash']=\MyInvoice\Service\Property\PropertyInput::hash($payload);}unset($row);
        foreach($rows as $table=>$records)foreach($records as $row)$this->db->pdo()->prepare('INSERT INTO '.$table.' (`'.implode('`,`',array_keys($row)).'`) VALUES ('.implode(',',array_fill(0,count($row),'?')).')')->execute(array_values($row));
        $reader=new \MyInvoice\Repository\Property\Charges\Issuance\IssuanceReadRepository($this->db);
        $legacy=$reader->batch($this->f['supplier'],$modern['id']);self::assertArrayNotHasKey('case_identity',$legacy['request']);
        $oldBytes=$rows['unit_charge_batches'][0]['snapshot_json'];
        self::assertSame($oldBytes,\MyInvoice\Service\Property\Charges\Issuance\IssuanceRequest::json($legacy['snapshot']));
        self::assertSame($legacy,$this->writer()->issue($this->f['supplier'],['request'=>$legacy['request'],'preview_hash'=>$legacy['preview_hash'],'idempotency_key'=>$rows['unit_charge_batches'][0]['idempotency_key']],$this->f['actor']));
        $posted=$this->post($legacy);self::assertSame('1000.00',$this->balance());$first=$posted['charges'][0];
        $this->writer()->reverse($this->f['supplier'],$first['id'],['row_version'=>2,'accounting_date'=>'2026-02-01','effective_at'=>'2026-02-01T10:00:00+01:00','reason'=>'Synthetic legacy correction','evidence_ref'=>'Synthetic old title','idempotency_key'=>'legacy-reverse'],$this->f['actor']);
        $request=$legacy['request'];$request['issue_mode']='replacement';$request['units'][0]['expected_revision']=1;$request['units'][0]['previous_charge_id']=$first['id'];
        $next=$this->post($this->issue($request));self::assertSame('1000.00',$this->balance());self::assertArrayNotHasKey('case_identity',$next['charges'][0]['snapshot']);
        self::assertSame($oldBytes,\MyInvoice\Service\Property\Charges\Issuance\IssuanceRequest::json($reader->batch($this->f['supplier'],$legacy['id'])['snapshot']));
        unset($request['case_identity']);$request['issue_mode']='initial';$request['source_key']='NEW-WITHOUT-IDENTITY';$request['units'][0]['expected_revision']=0;$request['units'][0]['previous_charge_id']=null;
        try{$this->issue($request);self::fail('New initial accepted missing identity.');}catch(IssuanceException $e){self::assertSame('property_charge_case_identity_required',$e->errorCode);}
    }

    private function distinction(array $keys): array { return ['against_source_keys'=>$keys,'legal_reason'=>'Separate synthetic installment B under another original title','evidence_ref'=>'Synthetic decision item B']; }
    private function preview(array $request): array { return (new IssuancePreviewService($this->db))->preview($this->f['supplier'],$request); }
    private function issue(array $request): array { $p=$this->preview($request);return $this->writer()->issue($this->f['supplier'],['request'=>$request,'preview_hash'=>$p['preview_hash'],'idempotency_key'=>'identity-'.++$this->token],$this->f['actor']); }
    private function post(array $batch): array { return $this->writer()->post($this->f['supplier'],$batch['id'],array_map(fn($c)=>['id'=>$c['id'],'row_version'=>$c['row_version']],$batch['charges']),$this->f['actor']); }
    private function writer(): IssuanceRepository { return Bootstrap::buildContainer()->get(IssuanceRepository::class); }
    private function balance(): string { $q=$this->db->pdo()->prepare("SELECT COALESCE(SUM(IF(l.side='debit',l.amount,-l.amount)),0) FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id=l.account_id WHERE l.supplier_id=? AND a.account_code='315'");$q->execute([$this->f['supplier']]);return (string)$q->fetchColumn(); }
    private function counts(): array { $out=[];foreach(['unit_charge_batches','unit_charges','unit_charge_lines','unit_charge_issue_audit','journal_entries','journal_entry_lines']as$t){$q=$this->db->pdo()->prepare('SELECT COUNT(*) FROM '.$t.' WHERE supplier_id=?');$q->execute([$this->f['supplier']]);$out[$t]=(int)$q->fetchColumn();}return $out; }
}
