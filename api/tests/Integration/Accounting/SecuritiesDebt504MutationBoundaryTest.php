<?php

declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Accounting;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\{PostingException,PostingService};
use MyInvoice\Service\Property\Payments\Workflow\PaymentMutationContext;
use PHPUnit\Framework\TestCase;
final class SecuritiesDebt504MutationBoundaryTest extends TestCase
{
    private SecuritiesDebt504WorkflowTest $fixture;
    private Connection $db;
    private \DI\Container $container;
    private int $supplier;
    protected function setUp(): void
    {
        $this->fixture=new SecuritiesDebt504WorkflowTest('testNativeCouponAcquisitionAccrualAcrossYearPartialReceiptsAndPrincipalAreNotIncome');(new \ReflectionMethod($this->fixture,'setUp'))->invoke($this->fixture);
        foreach(['db','container','supplier'] as $key)$this->$key=(new \ReflectionProperty($this->fixture,$key))->getValue($this->fixture);
    }
    protected function tearDown(): void{(new \ReflectionMethod($this->fixture,'tearDown'))->invoke($this->fixture);}
    private function call(string $method,mixed ...$args): mixed{return(new \ReflectionMethod($this->fixture,$method))->invoke($this->fixture,...$args);}
    #[\PHPUnit\Framework\Attributes\TestWith(['reverse'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['description'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['replace'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['lines'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['posted'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['reversed_by'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['detach'])]
    public function testNativeJournalOwnerRejectsMutationOfRegisteredAcquisition(string $operation): void
    {
        $terms=$this->call('coupon');$source=$this->call('acquisition',$terms);$this->call('applyCommand',['operation'=>'register','terms'=>$terms]+$source);$journal=$this->container->get(JournalEntryRepository::class);$entry=$journal->find($source['entry_id'],$this->supplier);$before=serialize($entry);
        try{
            match($operation){
                'reverse'=>$this->container->get(PostingService::class)->reverse($this->supplier,$source['entry_id'],['entry_date'=>$terms['acquired_on']]),
                'description'=>$journal->updateDescription($source['entry_id'],$this->supplier,'Synthetic forbidden description'),
                'replace'=>$journal->replace($source['entry_id'],$entry,$entry['lines']),
                'lines'=>$journal->replaceSomeLines($source['entry_id'],$this->supplier,(int)$entry['row_version'],[],[]),
                'posted'=>$journal->markPosted($source['entry_id'],$this->supplier,null),
                'reversed_by'=>$journal->setReversedBy($source['entry_id'],$this->supplier,$source['entry_id']),
                'detach'=>$journal->detachSource($source['entry_id'],$this->supplier),
            };self::fail('The native journal owner allowed an owned acquisition mutation.');
        }catch(PostingException $e){self::assertSame('debt_source_immutable',$e->errorCode);}
        self::assertSame($before,serialize($journal->find($source['entry_id'],$this->supplier)));
    }
    #[\PHPUnit\Framework\Attributes\TestWith(['source'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['statement'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['line'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['sources'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['document'])]
    public function testPhysicalPaymentMutationOwnerRejectsBoundReceipt(string $operation): void
    {
        $lot=$this->call('register',$this->call('coupon'));$this->call('applyCommand',['operation'=>'accrual','lot_id'=>$lot,'accounting_date'=>'2025-12-31','evidence'=>'Synthetic owner boundary accrual']);$bank=$this->call('bank','10.00','2026-01-15','253');$this->call('applyCommand',['operation'=>'coupon_receipt','lot_id'=>$lot,'accounting_date'=>'2026-01-15','evidence'=>'Synthetic owner boundary receipt']+$bank);
        $q=$this->db->pdo()->prepare('SELECT e.source_id,b.statement_id FROM journal_entries e JOIN bank_transactions b ON b.id=e.source_id WHERE e.supplier_id=? AND e.id=?');$q->execute([$this->supplier,$bank['entry_id']]);$source=$q->fetch(\PDO::FETCH_ASSOC);$owner=new PaymentMutationContext($this->db);
        try{match($operation){'source'=>$owner->assertSourceMutable($this->supplier,'bank',(int)$source['source_id']),'statement'=>$owner->assertBankStatementMutable($this->supplier,(int)$source['statement_id']),'line'=>$owner->assertLinesMutable($this->supplier,[$bank['line_id']]),'sources'=>$owner->assertSourcesMutable($this->supplier,[['bank',(int)$source['source_id']]]),'document'=>$owner->assertDocumentMutable($this->supplier,'bank_transaction',(int)$source['source_id'])};self::fail('The payment mutation owner allowed a bound physical receipt.');}catch(PostingException $e){self::assertSame('debt_source_immutable',$e->errorCode);}
    }
    public function testNativeCashReceiptPreservesRealSourceAndItsCashOwnerRejectsDeletion(): void
    {
        $lot=$this->call('register',$this->call('coupon'));$this->call('applyCommand',['operation'=>'accrual','lot_id'=>$lot,'accounting_date'=>'2025-12-31','evidence'=>'Synthetic native cash accrual']);$p=$this->db->pdo();$p->prepare("INSERT INTO cash_registers(supplier_id,name,account_code,currency_code,is_active) VALUES(?,'Synthetic debt cash','211','CZK',1)")->execute([$this->supplier]);$register=(int)$p->lastInsertId();$p->prepare("INSERT INTO cash_documents(supplier_id,register_id,doc_type,purpose,doc_number,issue_date,description,total_amount,currency_code,status) VALUES(?,?,'in','other','SYN-CASH-COUPON','2026-01-15','Synthetic real coupon receipt','20.00','CZK','posted')")->execute([$this->supplier,$register]);$cash=(int)$p->lastInsertId();$dims=(new \ReflectionProperty($this->fixture,'dims'))->getValue($this->fixture);
        $entry=$this->container->get(PostingService::class)->postDocument($this->supplier,'cash',$cash,[['account_code'=>'211','side'=>'debit','amount'=>'20.00','dimensions'=>$dims],['account_code'=>'253','side'=>'credit','amount'=>'20.00','dimensions'=>$dims]],['entry_date'=>'2026-01-15','posted'=>true]);$p->prepare('UPDATE cash_documents SET journal_entry_id=? WHERE supplier_id=? AND id=?')->execute([$entry,$this->supplier,$cash]);$line=$this->call('line',$entry,'253');$this->call('applyCommand',['operation'=>'coupon_receipt','lot_id'=>$lot,'accounting_date'=>'2026-01-15','evidence'=>'Synthetic native cash binding','entry_id'=>$entry,'line_id'=>$line]);$state=$this->container->get(\MyInvoice\Service\Accounting\Closing\SecuritiesDebt504Workflow::class)->detail($this->supplier,$lot,'2026-01-15');self::assertSame('1011.00',$state['book_czk']);self::assertSame('1000.00',$state['principal_balance']);$payment=$state['events'][1]['receipt']['payment'];self::assertSame('cash',$payment['ledger']['entry']['source_type']);self::assertSame($cash,$payment['ledger']['entry']['source_id']);self::assertSame($cash,(int)$payment['source']['id']);
        try{$this->container->get(\MyInvoice\Repository\CashDocumentRepository::class)->deleteJournalEntry($this->supplier,$entry);self::fail('The cash owner deleted its bound native journal.');}catch(PostingException $e){self::assertSame('debt_source_immutable',$e->errorCode);}
    }

}
