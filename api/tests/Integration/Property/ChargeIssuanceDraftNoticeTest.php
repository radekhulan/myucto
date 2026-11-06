<?php

declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Property;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Charges\ChargeRepository;
use MyInvoice\Repository\Property\Charges\Issuance\IssuanceRepository;
use MyInvoice\Service\Property\Charges\ChargePreviewService;
use MyInvoice\Service\Property\Charges\Issuance\IssuanceException;
use MyInvoice\Service\Property\Charges\Issuance\IssuanceJournalProof;
use MyInvoice\Service\Property\Charges\Issuance\IssuancePreviewService;
use MyInvoice\Tests\Support\ChargeIssuanceFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class ChargeIssuanceDraftNoticeTest extends TestCase
{
    use ChargeIssuanceFixture;
    private Connection $db;
    private array $f;
    private int $version=1;
    private int $token=0;
    protected function setUp(): void
    {
        $this->db=Bootstrap::buildContainer()->get(Connection::class);
        self::assertStringEndsWith('_test',(string)$this->db->pdo()->query('SELECT DATABASE()')->fetchColumn());
        $this->db->pdo()->beginTransaction();$this->f=$this->issuanceFixture($this->db);
    }
    protected function tearDown(): void { if(isset($this->db)&&$this->db->pdo()->inTransaction())$this->db->pdo()->rollBack(); }
    public static function undelivered(): array { return [['draft','initial'],['draft','unchanged'],['posted','initial'],['reversed','unchanged']]; }
    #[DataProvider('undelivered')]
    public function testIssuedServiceDraftAlreadyRequiresDeliveredChange(string $status,string $mode): void
    {
        $first=$this->first($status);$request=$this->request('1100','SECOND');$request['units'][0]['notices'][0]['notice']['mode']=$mode;
        $before=$this->counts();$balance=$this->balance();
        try {
            $second=$this->issue($request);
            if($status==='draft')$this->post($first);
            $this->post($second);
            self::fail('Undelivered service increase issued and posted; receivable='.$this->balance());
        } catch(IssuanceException $e) { self::assertSame('property_charge_notice_unproven',$e->errorCode);self::assertSame(422,$e->httpStatus); }
        self::assertSame($before,$this->counts());self::assertSame($balance,$this->balance());
        try { $this->writer()->issue($this->f['supplier'],['request'=>$request,'preview_hash'=>str_repeat('0',64),'idempotency_key'=>'direct-writer'],$this->f['actor']);self::fail('Current writer accepted no-delivery change.'); }
        catch(IssuanceException $e){self::assertSame('property_charge_notice_unproven',$e->errorCode);}
        self::assertSame($before,$this->counts());self::assertTrue($this->db->pdo()->inTransaction());
    }
    public function testDeliveredNonMonthlyChangeCanBeIssuedAndBothNativeEntriesPosted(): void
    {
        $first=$this->first('draft');$request=$this->request('1100','SECOND');
        $ids=[];foreach($this->f['states']['U01']['ownerships']as$g)foreach($g['members']as$id)$ids[]=$id;
        $request['units'][0]['notices'][0]['notice']=['mode'=>'change','title_ref'=>'Synthetic service increase decision','written_notice_ref'=>'Synthetic written notice',
            'reason'=>'Documented increased annual service need','deliveries'=>array_map(fn($id)=>['recipient_id'=>$id,'represented_ids'=>[$id],'authority_ref'=>'',
                'delivered_at'=>'2025-12-20T10:00:00+01:00','evidence_ref'=>'Synthetic receipt'],$ids),
            'agreement'=>['effective_at'=>'2026-01-01T00:00:00+01:00','recipient_ids'=>$ids,'evidence_ref'=>'Synthetic annual agreement','legal_basis'=>'Explicit assessed non-monthly title']];
        $second=$this->issue($request);$a=$this->post($first);$b=$this->post($second);
        self::assertSame('2100.00',$this->balance());
        foreach([$a,$b]as$batch)foreach($batch['charges']as$c)(new IssuanceJournalProof($this->db))->assertEntry($this->f['supplier'],$c['journal_entry_id'],$c);
        self::assertSame('non_monthly_title',$b['charges'][0]['snapshot']['notices'][0]['notice']['legal_rule']);
        self::assertCount(2,$b['charges'][0]['snapshot']['notices'][0]['notice']['deliveries']);
    }
    public static function unchanged(): array { return [['cancelled','1100','initial','1100.00'],['draft','1000','unchanged','2000.00'],['posted','1000','unchanged','2000.00']]; }
    #[DataProvider('unchanged')]
    public function testCancelledAndUnchangedNoticeContractsRemain(string $status,string $amount,string $mode,string $expected): void
    {
        $first=$this->first($status);$request=$this->request($amount,'SECOND');$request['units'][0]['notices'][0]['notice']['mode']=$mode;
        $second=$this->issue($request);if($status==='draft')$this->post($first);$posted=$this->post($second);
        self::assertSame($expected,$this->balance());self::assertSame([],$posted['charges'][0]['snapshot']['notices'][0]['notice']['deliveries']);
        (new IssuanceJournalProof($this->db))->assertEntry($this->f['supplier'],$posted['charges'][0]['journal_entry_id'],$posted['charges'][0]);
    }
    private function first(string $status): array
    {
        $batch=$this->issue($this->request('1000','FIRST'));
        if(in_array($status,['posted','reversed'],true))$batch=$this->post($batch);
        if(in_array($status,['cancelled','reversed'],true)){
            $c=$batch['charges'][0];$this->writer()->reverse($this->f['supplier'],$c['id'],['row_version'=>$c['row_version'],
                'accounting_date'=>'2026-01-01','effective_at'=>'2026-01-01T11:00:00+01:00','reason'=>'Synthetic correction','evidence_ref'=>'Synthetic deed','idempotency_key'=>'reverse-first'],$this->f['actor']);
        }
        return $batch;
    }
    private function request(string $amount,string $source): array
    {
        $v=self::chargeRequest($this->f);$v['expected_revision']=$this->version++;
        $v['rates']=array_values(array_filter($v['rates'],fn($r)=>$r['component_id']===$this->f['components']['WATER']));$v['rates'][0]['amount']=$amount;
        $p=(new ChargePreviewService($this->db))->preview($this->f['supplier'],$v);
        $saved=(new ChargeRepository($this->db))->saveVersion($this->f['supplier'],['request'=>$v,'preview_hash'=>$p['preview_hash'],'idempotency_key'=>'v-'.$this->version],$this->f['actor']);
        $r=$this->f['issue_request'];$r['version_id']=$saved['id'];$r['source_key']=$source;
        $r['units']=array_values(array_filter($r['units'],fn($u)=>$u['unit_id']===$this->f['units']['U01']));
        if(isset($r['case_identity'])){
            $r['case_identity']['case_ref']='Synthetic service case '.$source;
            if($source==='SECOND')$r['units'][0]['case_distinction']=['against_source_keys'=>['FIRST'],'legal_reason'=>'Separate documented service obligation','evidence_ref'=>'Synthetic separate decision'];
        }
        return $r;
    }
    private function issue(array $r): array { $p=(new IssuancePreviewService($this->db))->preview($this->f['supplier'],$r);return $this->writer()->issue($this->f['supplier'],['request'=>$r,'preview_hash'=>$p['preview_hash'],'idempotency_key'=>'i-'.++$this->token],$this->f['actor']); }
    private function post(array $b): array { return $this->writer()->post($this->f['supplier'],$b['id'],array_map(fn($c)=>['id'=>$c['id'],'row_version'=>$c['row_version']],$b['charges']),$this->f['actor']); }
    private function writer(): IssuanceRepository { return Bootstrap::buildContainer()->get(IssuanceRepository::class); }
    private function balance(): string { $q=$this->db->pdo()->prepare("SELECT COALESCE(SUM(IF(l.side='debit',l.amount,-l.amount)),0) FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id=l.account_id WHERE l.supplier_id=? AND a.account_code='315'");$q->execute([$this->f['supplier']]);return (string)$q->fetchColumn(); }
    private function counts(): array { $out=[];foreach(['unit_charge_batches','unit_charges','unit_charge_lines','unit_charge_notices','unit_charge_issue_audit','journal_entries','journal_entry_lines']as$t){$q=$this->db->pdo()->prepare('SELECT COUNT(*) FROM '.$t.' WHERE supplier_id=?');$q->execute([$this->f['supplier']]);$out[$t]=(int)$q->fetchColumn();}return $out; }
}