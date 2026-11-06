<?php
declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Property;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Opening\OpeningRepository;
use MyInvoice\Repository\Property\Payments\{CreditSetoffRepository,DebtRepository,PaymentRepository};
use MyInvoice\Service\Property\Payments\PaymentException;
use MyInvoice\Tests\Support\{ChargeIssuanceFixture,PaymentCorrectionFixture};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OpeningTemporalNativeTest extends TestCase
{
    use ChargeIssuanceFixture,PaymentCorrectionFixture;
    private Connection $db;
    private array $f;
    private array $native;
    private int $debt;
    private int $credit;
    private int $debtor;
    protected function setUp():void
    {
        $container=Bootstrap::buildContainer();$this->db=$container->get(Connection::class);$pdo=$this->db->pdo();
        self::assertStringEndsWith('_test',(string)getenv('MYINVOICE_DB_NAME'));self::assertSame(getenv('MYINVOICE_DB_NAME'),$pdo->query('SELECT DATABASE()')->fetchColumn());$pdo->beginTransaction();
        $this->f=$this->issuanceFixture($this->db);$this->native=$this->settledPaymentFixture('1','0');$f=$this->f;$s=$f['supplier'];$this->debtor=$this->native['charge']['snapshot']['liability_groups'][0]['members'][0];
        $accounts=new \MyInvoice\Repository\ChartOfAccountsRepository($this->db);
        foreach(['315.100','379.910','379.900'] as $code)if($accounts->findByCode($s,$code)===null){$parent=$accounts->findByCode($s,substr($code,0,3));$accounts->insert($s,['account_code'=>$code,'name'=>'Synthetic '.$code,'account_type'=>$parent['account_type'],'normal_side'=>$parent['normal_side'],'parent_id'=>$parent['id']]);}
        $common=['unit_id'=>$this->native['charge']['unit_id'],'service_id'=>null,'origin_at'=>'2026-01-01T00:00:00+01:00','period_from'=>'2026-01-01','period_to'=>'2026-01-01','due_date'=>'2026-01-02','occurrence_ref'=>'opening-temporal','title_ref'=>'Synthetic independent title','evidence_ref'=>'Synthetic signed takeover'];
        $debt=$common+['case_ref'=>'opening-debt','kind'=>'debt','components'=>[['key'=>'principal','role'=>'principal','account_code'=>'315.100','amount_cents'=>'1000000']],'liability_groups'=>[['group_key'=>'debtor','role'=>'debtor','mode'=>'individual','basis'=>'documented_title','members'=>[$this->debtor],'extent'=>['num'=>'1','den'=>'1'],'evidence_ref'=>'Synthetic title','legal_reason'=>'Synthetic personal claim','valid_from'=>'2026-01-01T00:00:00+01:00','valid_to'=>null]],'legal'=>['basis'=>'legal_act','legal_act_date'=>'2025-12-15','evidence'=>'Synthetic decision','regime'=>'general'],'security'=>['kind'=>'unsecured','evidence'=>'Synthetic finding']];
        $credit=$common+['case_ref'=>'opening-credit','kind'=>'credit','components'=>[['key'=>'credit','role'=>'credit','account_code'=>'379.910','amount_cents'=>'1000000']],'creditor_ids'=>[$this->debtor]];
        $request=['mode'=>'post_new','accounting_date'=>'2026-02-01','effective_at'=>'2026-02-01T12:00:00+01:00','expected_revision'=>0,'evidence_ref'=>'Synthetic signed takeover','reason'=>'Synthetic opening','items'=>[$debt,$credit]];
        $repo=$container->get(OpeningRepository::class);$repo->apply($s,$request,$repo->preview($s,$request)['preview_hash'],'opening-temporal',$f['actor']);
        $this->debt=(int)$pdo->query('SELECT id FROM unit_payment_debts WHERE supplier_id='.$s.' AND opening_item_id IS NOT NULL')->fetchColumn();
        $this->credit=(int)$pdo->query('SELECT id FROM unit_credit_lots WHERE supplier_id='.$s.' AND opening_item_id IS NOT NULL')->fetchColumn();
    }
    protected function tearDown():void{if($this->db->pdo()->inTransaction())$this->db->pdo()->rollBack();}
    public static function instants():array
    {
        return [
            'prior instant'=>['2026-02-01','2026-02-01T11:59:59+01:00',false],
            'later posting retains actual receipt instant'=>['2026-02-02','2026-02-01T11:59:59+01:00',false],
            'boundary'=>['2026-02-01','2026-02-01T12:00:00+01:00',true],
            'later instant'=>['2026-02-01','2026-02-01T12:00:01+01:00',true],
        ];
    }
    #[DataProvider('instants')]
    public function testOpeningDebtCapacityStartsAtTakeover(string $date,string $at,bool $allowed):void
    {
        $bank=$this->correctionBank('200.00');$s=$this->f['supplier'];$payments=$this->paymentFixtureContainer()->get(PaymentRepository::class);
        $request=['kind'=>'allocation','source'=>['kind'=>'bank','id'=>$bank,'expected_version'=>0],'amount_cents'=>'20000','accounting_date'=>$date,'reason'=>'Synthetic opening payment','debts'=>[$this->debt],'payment'=>['payer_id'=>$this->debtor,'debtor_id'=>$this->debtor,'received_at'=>$at,'choice'=>['claim_ids'=>[$this->debt],'evidence'=>'Synthetic disposition']]];
        $before=$this->counts();
        if(!$allowed){try{$payments->preview($s,$request);self::fail('Payment preceding takeover accepted.');}catch(PaymentException $e){self::assertSame('property_payment_backdated_payment',$e->errorCode);}self::assertSame($before,$this->counts());return;}
        $preview=$payments->preview($s,$request);self::assertSame('20000',$preview['snapshot']['allocations'][0]['amount_cents']);self::assertSame($this->debt,$preview['snapshot']['allocations'][0]['debt_id']);
        $payments->apply($s,$request,$preview['preview_hash'],'temporal-pay',$this->f['actor']);
        $current=(new DebtRepository($this->db))->current($s,$this->debt);self::assertSame('20000',array_reduce($current['paid'],fn($sum,$v)=>bcadd($sum,$v,0),'0'));
    }
    public function testImportedCreditCannotFundSetoffBeforeItsEffectiveInstant():void
    {
        $request=['kind'=>'setoff','credit_id'=>$this->credit,'expected_credit_version'=>1,'amount_cents'=>'100','accounting_date'=>'2026-02-01','reason'=>'Synthetic reciprocal setoff','debts'=>[$this->native['debt']['id']],
            'payment'=>['debtor_id'=>$this->debtor,'payer_id'=>$this->debtor,'received_at'=>'2026-02-01T11:59:59+01:00','choice'=>['claim_ids'=>[$this->native['debt']['id']],'evidence'=>'Synthetic order']],
            'legal'=>['certain'=>true,'unconditional'=>true,'scope_cents'=>'100','mutuality_evidence'=>'Synthetic same legal creditor','eligibility_evidence'=>'Synthetic due claim','declaration_evidence'=>'Synthetic declaration','delivery_evidence'=>'Synthetic receipt','delivered_at'=>'2026-02-01T11:59:59+01:00','effect_evidence'=>'Synthetic effect date']];
        $repo=$this->paymentFixtureContainer()->get(CreditSetoffRepository::class);$before=$this->counts();
        try{$repo->preview($this->f['supplier'],$request);self::fail('Credit preceding takeover spent.');}catch(PaymentException $e){self::assertSame('property_payment_backdated_payment',$e->errorCode);}
        self::assertSame($before,$this->counts());$request['payment']['received_at']=$request['legal']['delivered_at']='2026-02-01T12:00:00+01:00';
        $preview=$repo->preview($this->f['supplier'],$request);$repo->apply($this->f['supplier'],$request,$preview['preview_hash'],'temporal-setoff',$this->f['actor']);
        self::assertSame('999900',$this->correctionPayments()->credit($this->f['supplier'],$this->credit)['balance_cents']);
    }
    private function counts():array
    {
        $out=[];foreach(['journal_entries','unit_payment_operations','unit_payment_allocations','unit_credit_events'] as $table){$q=$this->db->pdo()->prepare('SELECT COUNT(*) FROM '.$table.' WHERE supplier_id=?');$q->execute([$this->f['supplier']]);$out[$table]=(int)$q->fetchColumn();}return $out;
    }
}
