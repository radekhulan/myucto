<?php
declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Property;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Receivables\PartyEventRepository;
use MyInvoice\Repository\Property\Charges\Issuance\IssuanceRepository;
use MyInvoice\Repository\Property\Payments\DebtRepository;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Property\Payments\PaymentException;
use MyInvoice\Service\Property\Charges\Issuance\IssuanceException;
use MyInvoice\Tests\Support\{ChargeIssuanceFixture,PaymentCorrectionFixture,ReceivableFixture};
use PHPUnit\Framework\TestCase;
final class PartyHistoryWriterGuardTest extends TestCase
{
    use ChargeIssuanceFixture,PaymentCorrectionFixture,ReceivableFixture;
    private Connection $db;private array $f;
    protected function setUp():void{$this->db=Bootstrap::buildContainer()->get(Connection::class);$this->db->pdo()->beginTransaction();$this->f=$this->issuanceFixture($this->db);}
    protected function tearDown():void{if($this->db->pdo()->inTransaction())$this->db->pdo()->rollBack();}
    public function testReliefApplyCannotChangeTransferredSource():void
    {
        $base=$this->settledPaymentFixture('90000','0');$this->transfer($base);$request=$this->negativePaymentCorrection($base,'800','10000');$repo=$this->correctionWriter();$preview=$repo->preview($this->f['supplier'],$request);
        $this->blocked(fn()=> $repo->apply($this->f['supplier'],$request,$preview['preview_hash'],'relief',$this->f['actor']));
    }
    public function testReliefReverseCannotRestorePinnedOriginalClaim():void
    {
        $base=$this->settledPaymentFixture('1000','0');$request=$this->negativePaymentCorrection($base,'900','0');$repo=$this->correctionWriter();$done=$repo->apply($this->f['supplier'],$request,$repo->preview($this->f['supplier'],$request)['preview_hash'],'relief',$this->f['actor']);$this->transfer($base);
        $reverse=['expected_version'=>2,'accounting_date'=>'2026-02-05','effective_at'=>'2026-02-05T10:00:00+01:00','reason'=>'Synthetic correction reversal','evidence'=>'Synthetic original decision withdrawn'];
        $this->blocked(fn()=> $repo->reverse($this->f['supplier'],$done['id'],$reverse,'reverse',$this->f['actor']));
    }
    public function testIssuanceReverseCannotRemoveTransferredUnpaidClaim():void
    {
        $base=$this->settledPaymentFixture('1','0');$this->correctionPayments()->reverse($this->f['supplier'],$base['operation']['id'],2,'2026-02-02','Synthetic payment withdrawal','undo-payment',$this->f['actor']);$this->transfer($base);
        $this->blocked(fn()=> $this->paymentFixtureContainer()->get(IssuanceRepository::class)->reverse($this->f['supplier'],$base['charge']['id'],['row_version'=>2,'accounting_date'=>'2026-02-05','effective_at'=>'2026-02-05T10:00:00+01:00','reason'=>'Synthetic source cancellation','evidence_ref'=>'Synthetic cancellation','idempotency_key'=>'reverse-charge'],$this->f['actor']));
    }
    public function testAccessoryCannotExtendTransferredLiabilityWithoutNewLegalProof():void
    {
        $base=$this->settledPaymentFixture('1','0');$this->transfer($base);$f=$this->f;
        $entry=$this->paymentFixtureContainer()->get(PostingService::class)->postDocument($f['supplier'],'manual',null,[['account_code'=>'315','side'=>'debit','amount'=>'1.00'],['account_code'=>'379.900','side'=>'credit','amount'=>'1.00']],['entry_date'=>'2026-02-05','posted_by'=>$f['actor']]);
        $q=$this->db->pdo()->prepare("SELECT l.id FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id=l.account_id WHERE l.entry_id=? AND a.account_code='315'");$q->execute([$entry]);$line=(int)$q->fetchColumn();
        $this->blocked(fn()=> (new DebtRepository($this->db))->bindAccessory($f['supplier'],$base['debt']['id'],['journal_entry_id'=>$entry,'journal_line_id'=>$line,'component'=>'interest','evidence'=>'Synthetic additional receivable','liability_evidence'=>'Synthetic title requires new party assessment'],$f['actor']));
    }
    private function transfer(array $base):void
    {
        $old=$base['charge']['snapshot']['liability_groups'][0]['members'][0];$request=$this->request($base,$this->f['clients'][2],1,null,'2026-02-04T10:00:00+01:00',[$old]);$repo=new PartyEventRepository($this->db);$repo->confirm($this->f['supplier'],$request,$repo->preview($this->f['supplier'],$request)['preview_hash'],'transfer',$this->f['actor']);
    }
    private function blocked(callable $write):void
    {
        $before=$this->fingerprint();try{$write();self::fail('Financial writer changed a pinned legal source');}catch(PaymentException|IssuanceException $e){self::assertSame('property_payment_party_history_in_use',$e->errorCode);}
        self::assertSame($before,$this->fingerprint());
    }
    private function fingerprint():string
    {
        $facts=[];foreach(['unit_charges','unit_charge_adjustments','unit_charge_issue_audit','unit_payment_operations','unit_payment_corrections','unit_payment_correction_anchors','unit_payment_reliefs','unit_payment_audit','unit_claim_accessory_receipts','journal_entries'] as $table){$q=$this->db->pdo()->prepare('SELECT * FROM '.$table.' WHERE supplier_id=?');$q->execute([$this->f['supplier']]);$facts[$table]=$q->fetchAll(\PDO::FETCH_ASSOC);}return hash('sha256',json_encode($facts,JSON_THROW_ON_ERROR));
    }
}
