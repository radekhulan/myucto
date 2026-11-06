<?php
declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Property\Charges;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\Property\Charges\Issuance\{IssuanceRepository,IssuanceReadRepository};
use MyInvoice\Service\Property\Charges\Issuance\{IssuancePreviewService,IssuanceJournalProof};
use MyInvoice\Tests\Support\ChargeIssuanceFixture;
use PHPUnit\Framework\TestCase;
final class IssuancePreviewAccountRedirectTest extends TestCase
{
    use ChargeIssuanceFixture;
    private Connection $db;
    private \DI\Container $container;
    private array $f;
    protected function setUp():void{$this->container=Bootstrap::buildContainer();$this->db=$this->container->get(Connection::class);self::assertStringEndsWith('_test',(string)$this->db->pdo()->query('SELECT DATABASE()')->fetchColumn());$this->db->pdo()->beginTransaction();$this->f=$this->issuanceFixture($this->db);foreach(['315.100','324.110','324.200','955.100'] as $code)$this->leaf($code);}
    protected function tearDown():void{if($this->db->pdo()->inTransaction())$this->db->pdo()->rollBack();}
    private function leaf(string $code):void{$chart=new ChartOfAccountsRepository($this->db);if($chart->findByCode($this->f['supplier'],$code))return;$parent=$chart->findByCode($this->f['supplier'],substr($code,0,3));$chart->insert($this->f['supplier'],['account_code'=>$code,'name'=>'Synthetic canonical '.$code,'account_type'=>$parent['account_type'],'normal_side'=>$parent['normal_side'],'parent_id'=>$parent['id']]);}
    private function request():array{$r=$this->f['issue_request'];foreach($r['accounts'] as &$account){$account['receivable_account']='315.100';$account['counter_account']=$account['component_id']===$this->f['components']['LONG']?'955':($account['component_id']===$this->f['components']['ADMIN']?'324.200':'324.110');}unset($account);return $r;}
    private function post(array $request):array{$s=$this->f['supplier'];$preview=(new IssuancePreviewService($this->db))->preview($s,$request);$owner=$this->container->get(IssuanceRepository::class);$batch=$owner->issue($s,['request'=>$request,'preview_hash'=>$preview['preview_hash'],'idempotency_key'=>'synthetic-canonical-issue'],$this->f['actor']);return $owner->post($s,$batch['id'],array_map(static fn(array $charge):array=>['id'=>$charge['id'],'row_version'=>$charge['row_version']],$batch['charges']),$this->f['actor']);}
    public function testNewPreviewFreezesTheSameCanonicalLeafThatPostingWrites():void
    {
        $r=$this->request();$s=$this->f['supplier'];$preview=(new IssuancePreviewService($this->db))->preview($s,$r);self::assertSame('955',$preview['snapshot']['request']['accounts'][0]['counter_account']);foreach($preview['snapshot']['charges'] as $charge)foreach($charge['lines'] as $line)if($line['component']['kind']==='long_term_advance')self::assertSame('955.100',$line['account']['counter_account']);
        $batch=$this->post($r);foreach($batch['charges'] as $charge){(new IssuanceJournalProof($this->db))->assertEntry($s,$charge['journal_entry_id'],$charge);self::assertSame('955.100',$charge['snapshot']['lines'][0]['account']['counter_account']);}
    }
    public function testHistoricalSyntheticReceiptAndTypedReversalNeverRetargetAfterAddingChild():void
    {
        $s=$this->f['supplier'];$this->db->pdo()->prepare('DELETE FROM chart_of_accounts WHERE supplier_id=? AND account_code=?')->execute([$s,'955.100']);$batch=$this->post($this->request());$charge=$batch['charges'][0];self::assertSame('955',$charge['snapshot']['lines'][0]['account']['counter_account']);$hash=$charge['snapshot_hash'];$this->leaf('955.100');$fresh=Bootstrap::buildContainer();$fresh->set(Connection::class,$this->db);self::assertSame('955.100',$fresh->get(\MyInvoice\Service\Accounting\PostingService::class)->redirectedAccountCode($s,'955'));
        $current=(new IssuanceReadRepository($this->db))->find($s,$charge['id'],true);self::assertSame($hash,$current['snapshot_hash']);self::assertSame('955',$current['snapshot']['lines'][0]['account']['counter_account']);(new IssuanceJournalProof($this->db))->assertEntry($s,$current['journal_entry_id'],$current);$reversed=$fresh->get(IssuanceRepository::class)->reverse($s,$current['id'],['row_version'=>$current['row_version'],'accounting_date'=>'2026-02-01','effective_at'=>'2026-02-01T12:00:00+01:00','reason'=>'Synthetic historical native reversal','evidence_ref'=>'Synthetic signed reversal','idempotency_key'=>'synthetic-historical-reverse'],$this->f['actor']);self::assertSame('reversed',$reversed['status']);self::assertSame($hash,$reversed['snapshot_hash']);(new IssuanceJournalProof($this->db))->assertEntry($s,$reversed['reversal_entry_id'],$reversed,true);
    }
    public function testServiceSelectorCannotRedirectIntoTheAdministrationLiability():void
    {
        $s=$this->f['supplier'];$this->db->pdo()->prepare('DELETE FROM chart_of_accounts WHERE supplier_id=? AND account_code=?')->execute([$s,'324.110']);$r=$this->request();foreach($r['accounts'] as &$account)if($account['component_id']===$this->f['components']['WATER'])$account['counter_account']='324';unset($account);
        try{(new IssuancePreviewService($this->db))->preview($s,$r);self::fail('Service advance was silently redirected to administration liability.');}catch(\MyInvoice\Service\Property\Charges\Issuance\IssuanceException $error){self::assertSame('property_charge_issue_account_invalid',$error->errorCode);}
    }

}
