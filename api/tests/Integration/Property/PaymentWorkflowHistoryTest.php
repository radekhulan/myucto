<?php

declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Property;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Payments\PaymentWorkflowReadRepository;
use MyInvoice\Service\Property\Payments\Workflow\PaymentWorkflowFacade;
use MyInvoice\Service\Property\PropertyInput;
use MyInvoice\Tests\Support\ChargeIssuanceFixture;
use PHPUnit\Framework\TestCase;
final class PaymentWorkflowHistoryTest extends TestCase
{
    use ChargeIssuanceFixture;
    public function testEveryConfirmedHistoryPageIsReachableWithoutDuplicatesOrOtherTenant():void
    {
        $db=Bootstrap::buildContainer()->get(Connection::class);$pdo=$db->pdo();self::assertStringEndsWith('_test',(string)getenv('MYINVOICE_DB_NAME'));self::assertSame(getenv('MYINVOICE_DB_NAME'),$pdo->query('SELECT DATABASE()')->fetchColumn());
        $pdo->beginTransaction();
        try{
            $f=$this->issuanceFixture($db);$pdo->prepare("INSERT INTO cash_registers (supplier_id,name,account_code) VALUES (?,'Synthetic history register','211')")->execute([$f['supplier']]);$register=(int)$pdo->lastInsertId();
            $request=['source'=>['kind'=>'cash','draft'=>['register_id'=>$register,'doc_type'=>'in','issue_date'=>'2026-02-01','partner_name'=>'Synthetic payer','description'=>'Synthetic history','total_amount'=>'2.00']],
                'clearing_cells'=>[['key'=>'original','amount_cents'=>'200','dimensions'=>[]]],'parts'=>[['key'=>'credit','request'=>['kind'=>'allocation','amount_cents'=>'100','accounting_date'=>'2026-02-01','reason'=>'Synthetic title','debts'=>[],
                'credit_entitlement'=>['third_party' => ['basis' => 'other_documented', 'evidence' => 'Synthetic documented creditor title'], 'unit_id'=>$f['units']['G12'],'party_ids'=>[$f['clients'][1]],'evidence'=>'Synthetic entitlement','due_date'=>'2026-03-01']]]]];
            $facade=Bootstrap::buildContainer()->get(PaymentWorkflowFacade::class);$native=$facade->confirm($f['supplier'],$request,$facade->preview($f['supplier'],$request)['preview_hash'],'history-origin',$f['actor']);
            $q=$pdo->prepare('SELECT * FROM unit_payment_workflows WHERE supplier_id=? AND id=?');$q->execute([$f['supplier'],$native['id']]);$row=$q->fetch(\PDO::FETCH_ASSOC);$wanted=[$native['id']];
            $insert=$pdo->prepare("INSERT INTO unit_payment_workflows (supplier_id,source_kind,cash_document_id,source_id,journal_entry_id,status,row_version,request_json,plan_json,request_hash,preview_hash,idempotency_key,created_by) VALUES (?,'cash',?,?,?,'confirmed',?,?,?,?,?,?,?)");
            for($i=0;$i<129;$i++){$token='synthetic-confirmation-'.$i;$insert->execute([$f['supplier'],$row['cash_document_id'],$row['source_id'],$row['journal_entry_id'],$row['row_version'],$row['request_json'],$row['plan_json'],PropertyInput::hash(['request'=>$request,'token'=>$token,'actor'=>$f['actor']]),$row['preview_hash'],$token,$f['actor']]);$wanted[]=(int)$pdo->lastInsertId();}
            rsort($wanted,SORT_NUMERIC);$read=new PaymentWorkflowReadRepository($db);$ids=[];$cursor=0;
            do{$page=$read->historyPage($f['supplier'],$cursor,25);self::assertLessThanOrEqual(25,count($page['items']));array_push($ids,...array_column($page['items'],'id'));$cursor=$page['next_cursor'];}while($cursor!==null);
            self::assertSame($wanted,$ids);self::assertSame([],$read->historyPage($f['supplier']+100000,0,25)['items']);
        }finally{if($pdo->inTransaction())$pdo->rollBack();}
    }
}
