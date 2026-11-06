<?php
declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Property;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\Property\Opening\OpeningRepository;
use MyInvoice\Repository\Property\Payments\{DebtRepository,PaymentOperationsReadRepository};
use MyInvoice\Service\Accounting\{ChartOfAccountsSeeder,PostingService};
use MyInvoice\Service\Property\Payments\PaymentException;
use MyInvoice\Tests\Support\PropertyChargeFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use MyInvoice;
use PDO;
final class OpeningAccessoryOwnershipTest extends TestCase
{
    use PropertyChargeFixture;
    private Connection $db;
    protected function setUp():void
    {
        $this->db=Bootstrap::buildContainer()->get(Connection::class);
        $pdo=$this->db->pdo();self::assertStringEndsWith('_test',$pdo->query('SELECT DATABASE()')->fetchColumn());
        $pdo->exec("SET SESSION sql_mode='STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        $pdo->beginTransaction();
    }
    protected function tearDown():void
    {
        if($this->db->pdo()->inTransaction())$this->db->pdo()->rollBack();
    }
    public static function modes():iterable
    {
        yield 'posted'=>['post_new'];yield 'bound'=>['bind_existing'];
    }
    public static function ownedLines():iterable
    {
        foreach(['post_new','bind_existing'] as $mode)foreach(['principal','interest'] as $role)yield $mode.'-'.$role=>[$mode,$role];
    }
    #[DataProvider('ownedLines')]
    public function testOpeningOwnedLineCannotCreateAnotherAccessory(string $mode,string $role):void
    {
        $f=$this->opening($mode);$pdo=$this->db->pdo();$line=$f['lines'][$role];
        $before=(int)$pdo->query('SELECT COUNT(*) FROM unit_claim_accessory_receipts')->fetchColumn();
        $journals=(int)$pdo->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
        try{(new DebtRepository($this->db))->bindAccessory($f['supplier'],$f['debtId'],$this->request($line),$f['actor']);$actual=(new MyInvoice\Service\Property\Receivables\PropertyReconciliation($this->db))->at($f['supplier'],'2026-01-31',$f['units']['U02']);self::fail('Opening '.$role.' was bound again: '.json_encode(['ledger'=>$actual['ledger_cents'],'subledger'=>$actual['subledger_cents']]));}
        catch(PaymentException $e){self::assertSame('property_payment_accessory_owned_elsewhere',$e->errorCode);}
        self::assertTrue($pdo->inTransaction());self::assertSame($before,(int)$pdo->query('SELECT COUNT(*) FROM unit_claim_accessory_receipts')->fetchColumn());self::assertSame($journals,(int)$pdo->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn());
        $recon=(new MyInvoice\Service\Property\Receivables\PropertyReconciliation($this->db))->at($f['supplier'],'2026-01-31',$f['units']['U02']);self::assertSame(['receivable'=>'1000000','credit'=>'1000000'],$recon['ledger_cents']);self::assertSame($recon['ledger_cents'],$recon['subledger_cents']);
    }
    #[DataProvider('modes')]
    public function testDiscoveryExcludesOpeningButKeepsSameAccountManualLines(string $mode):void
    {
        $f=$this->opening($mode);$entry=Bootstrap::buildContainer()->get(PostingService::class)->postDocument($f['supplier'],'manual',null,[['account_code'=>'315.100','side'=>'debit','amount'=>'20.00'],['account_code'=>'315.410','side'=>'debit','amount'=>'10.00'],['account_code'=>'379.900','side'=>'credit','amount'=>'30.00']],['entry_date'=>'2026-01-31','posted_by'=>$f['actor']]);
        $q=$this->db->pdo()->prepare("SELECT id,entry_id AS journal_entry_id FROM journal_entry_lines WHERE entry_id=? AND side='debit' ORDER BY id");$q->execute([$entry]);$manual=$q->fetchAll(PDO::FETCH_ASSOC);$ids=array_map('intval',array_column($manual,'id'));
        $read=new PaymentOperationsReadRepository($this->db);$visible=array_column($read->accessoryCandidates($f['supplier'])['items'],'id');self::assertSame($ids,$visible);
        foreach($manual as $line){$request=$this->request(['journal_line_id'=>$line['id'],'journal_entry_id'=>$entry]);$repo=new DebtRepository($this->db);$receipt=$repo->bindAccessory($f['supplier'],$f['debtId'],$request,$f['actor']);self::assertSame($receipt['id'],$repo->bindAccessory($f['supplier'],$f['debtId'],$request,$f['actor'])['id']);}
        self::assertSame([],$read->accessoryCandidates($f['supplier'])['items']);
    }
    public function testForeignTenantOpeningCannotBeBound():void
    {
        $own=$this->opening('post_new');$foreign=$this->opening('post_new');self::assertSame([],(new PaymentOperationsReadRepository($this->db))->accessoryCandidates($own['supplier'])['items']);
        try{(new DebtRepository($this->db))->bindAccessory($own['supplier'],$own['debtId'],$this->request($foreign['lines']['principal']),$own['actor']);self::fail('Foreign journal was accepted.');}
        catch(PaymentException $e){self::assertSame('property_payment_ledger_unposted',$e->errorCode);}
    }
    public function testOpeningReversalEntryRetainsItsSourceOwnership():void
    {
        $f=$this->opening('post_new');$request=$f['request'];$request['mode']='revision';$request['expected_revision']=1;$request['accounting_date']='2026-01-02';$request['effective_at']='2026-01-02T00:00:00+01:00';$request['reason']='Synthetic opening correction';
        foreach($request['items'] as &$item)$item['components'][0]['amount_cents']=$item['kind']==='debt'?'890000':'900000';unset($item);
        $repo=Bootstrap::buildContainer()->get(OpeningRepository::class);$repo->apply($f['supplier'],$request,$repo->preview($f['supplier'],$request)['preview_hash'],'ownership-revision',$f['actor']);
        $pdo=$this->db->pdo();$q=$pdo->prepare('SELECT l.id journal_line_id,l.entry_id journal_entry_id FROM property_opening_batches b JOIN journal_entry_lines l ON l.supplier_id=b.supplier_id AND l.entry_id=b.reversal_entry_id WHERE b.supplier_id=? ORDER BY l.id LIMIT 1');$q->execute([$f['supplier']]);$line=$q->fetch(PDO::FETCH_ASSOC);self::assertIsArray($line);
        $debt=(int)$pdo->query('SELECT MAX(id) FROM unit_payment_debts WHERE supplier_id='.$f['supplier'])->fetchColumn();
        try{(new DebtRepository($this->db))->bindAccessory($f['supplier'],$debt,$this->request($line),$f['actor']);self::fail('Opening reversal lost source ownership.');}
        catch(PaymentException $e){self::assertSame('property_payment_accessory_owned_elsewhere',$e->errorCode);}
    }
    private function request(array $line):array
    {
        return ['journal_entry_id'=>(int)$line['journal_entry_id'],'journal_line_id'=>(int)$line['journal_line_id'],'component'=>'interest','evidence'=>'Synthetic independent receivable title','liability_evidence'=>'Synthetic same liable group'];
    }
    private function opening(string $mode):array
    {
        $container=Bootstrap::buildContainer();$db=$this->db;$pdo=$db->pdo();
   $f=$this->chargeFixture($db);$s=$f['supplier'];(new AccountingPeriodRepository($db))->create($s,2026,'2026-01-01','2026-12-31');(new ChartOfAccountsSeeder($db))->seedForSupplier($s,'2026-01-01');
   $accounts=new MyInvoice\Repository\ChartOfAccountsRepository($db);foreach(['315.100','315.410','379.910','379.900'] as $code)if($accounts->findByCode($s,$code)===null){$parent=$accounts->findByCode($s,substr($code,0,3));$accounts->insert($s,['account_code'=>$code,'name'=>'Synthetic '.$code,'account_type'=>$parent['account_type'],'normal_side'=>$parent['normal_side'],'parent_id'=>$parent['id']]);}
   $common=['unit_id'=>$f['units']['U02'],'service_id'=>$f['service']['id'],'origin_at'=>'2026-01-01T00:00:00+01:00','period_from'=>'2026-01-01','period_to'=>'2026-01-01','due_date'=>'2026-01-02','occurrence_ref'=>'2026','title_ref'=>'Synthetic title','evidence_ref'=>'Synthetic reconciliation'];
   $debt=$common+['case_ref'=>'debt-A','kind'=>'debt','components'=>[['key'=>'principal','role'=>'principal','account_code'=>'315.100','amount_cents'=>'990000']],'liability_groups'=>[['group_key'=>'A','role'=>'debtor','mode'=>'individual','basis'=>'documented_title','members'=>[$f['clients'][1]],'extent'=>['num'=>'1','den'=>'1'],'evidence_ref'=>'Synthetic title','legal_reason'=>'Synthetic personal claim','valid_from'=>'2026-01-01T00:00:00+01:00','valid_to'=>null]],'legal'=>['basis'=>'legal_act','legal_act_date'=>'2025-12-15','evidence'=>'Synthetic decision','regime'=>'general'],'security'=>['kind'=>'unsecured','evidence'=>'Synthetic finding']];
   $credit=$common+['case_ref'=>'credit-B','kind'=>'credit','components'=>[['key'=>'credit','role'=>'credit','account_code'=>'379.910','amount_cents'=>'1000000']],'creditor_ids'=>[$f['clients'][18]]];
   $debt['components'][]=['key'=>'interest','role'=>'interest','account_code'=>'315.410','amount_cents'=>'10000'];
   $request=['mode'=>'post_new','accounting_date'=>'2026-01-01','effective_at'=>'2026-01-01T00:00:00+01:00','expected_revision'=>0,'evidence_ref'=>'Synthetic signed takeover','reason'=>'Synthetic opening','items'=>[$debt,$credit]];
   if($mode==='bind_existing'){
   $q=$pdo->prepare('SELECT v.type_id,v.id FROM property_objects o JOIN dimension_values v ON v.id=o.dimension_value_id AND v.supplier_id=o.supplier_id WHERE o.supplier_id=? AND o.id IN (?,?) ORDER BY v.type_id');$q->execute([$s,$f['units']['U02'],$f['service']['id']]);$dimensions=[];foreach($q->fetchAll(PDO::FETCH_ASSOC) as $value)$dimensions[(int)$value['type_id']]=(int)$value['id'];
   $entry=$container->get(MyInvoice\Service\Accounting\PostingService::class)->postDocument($s,'opening',null,[['account_code'=>'315.100','side'=>'debit','amount'=>'9900.00','dimensions'=>$dimensions],['account_code'=>'315.410','side'=>'debit','amount'=>'100.00','dimensions'=>$dimensions],['account_code'=>'379.910','side'=>'credit','amount'=>'10000.00','dimensions'=>$dimensions]],['entry_date'=>'2026-01-01','posted'=>true,'posted_by'=>$f['actor']]);
   $ledger=(new MyInvoice\Service\Property\Payments\PaymentJournalProof($db))->current($s,$entry);$byCode=array_column($ledger['lines'],null,'account_code');$request['mode']='bind_existing';$request['journal_entry_id']=$entry;$request['journal_version']=(int)$pdo->query('SELECT row_version FROM journal_entries WHERE id='.$entry)->fetchColumn();foreach($request['items'] as &$part){foreach($part['components'] as &$component)$component['source_line_id']=$byCode[$component['account_code']]['id'];unset($component);}unset($part);
   }
   $repo=$container->get(OpeningRepository::class);$preview=$repo->preview($s,$request);$result=$repo->apply($s,$request,$preview['preview_hash'],'opening-native',$f['actor']);self::assertSame('posted',$result['status']);self::assertCount(2,$result['items']);
   $debtId=(int)$pdo->query('SELECT id FROM unit_payment_debts WHERE supplier_id='.$s)->fetchColumn();$creditId=(int)$pdo->query('SELECT id FROM unit_credit_lots WHERE supplier_id='.$s)->fetchColumn();
        $q=$pdo->prepare('SELECT role,journal_entry_id,journal_line_id FROM property_opening_components WHERE supplier_id=?');$q->execute([$s]);$lines=array_column($q->fetchAll(PDO::FETCH_ASSOC),null,'role');
        return $f+['debtId'=>$debtId,'lines'=>$lines,'request'=>$request];
    }
}
