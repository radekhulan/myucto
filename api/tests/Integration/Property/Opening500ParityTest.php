<?php
declare(strict_types=1);
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Tests\Support\PropertyChargeFixture;
use PHPUnit\Framework\TestCase;
final class Opening500ParityTest extends TestCase {
 use PropertyChargeFixture;
 public function testBusiness315ClassificationIsUnchanged():void {
  $container=Bootstrap::buildContainer();$db=$container->get(Connection::class);$pdo=$db->pdo();self::assertStringEndsWith('_test',(string)getenv('MYINVOICE_DB_NAME'));self::assertSame(getenv('MYINVOICE_DB_NAME'),$pdo->query('SELECT DATABASE()')->fetchColumn());$pdo->beginTransaction();
  try {
   $f=$this->chargeFixture($db);$s=$f['supplier'];$pdo->prepare("UPDATE supplier_accounting_frameworks SET accounting_framework='cz_500' WHERE supplier_id=?")->execute([$s]);(new MyInvoice\Repository\AccountingPeriodRepository($db))->create($s,2026,'2026-01-01','2026-12-31');(new MyInvoice\Service\Accounting\ChartOfAccountsSeeder($db))->seedForSupplier($s,'2026-01-01');
   $accounts=new MyInvoice\Repository\ChartOfAccountsRepository($db);foreach(['315.100','315.210','379.910','324.110','388.210','389.110'] as $code)if($accounts->findByCode($s,$code)===null){$parent=$accounts->findByCode($s,substr($code,0,3));$accounts->insert($s,['account_code'=>$code,'name'=>'Synthetic '.$code,'account_type'=>$parent['account_type'],'normal_side'=>$parent['normal_side'],'parent_id'=>$parent['id']]);}
   $lines=[];foreach([['315.100','debit','10000.00'],['379.910','credit','10000.00'],['315.210','debit','80000.00'],['324.110','credit','80000.00'],['388.210','debit','50000.00'],['389.110','credit','50000.00']] as [$code,$side,$amount])$lines[]=['account_code'=>$code,'side'=>$side,'amount'=>$amount];
   $container->get(MyInvoice\Service\Accounting\PostingService::class)->postDocument($s,'opening',null,$lines,['entry_date'=>'2026-01-01','document_date'=>'2026-01-01','posted'=>true,'posted_by'=>$f['actor']]);
   $result=(new MyInvoice\Service\Property\Receivables\PropertyReconciliation($db))->at($s,'2026-01-31');self::assertSame('9000000',$result['ledger_cents']['receivable']);self::assertSame('1000000',$result['ledger_cents']['credit']);self::assertFalse($result['matches']);
  }finally{if($pdo->inTransaction())$pdo->rollBack();}
 }
}
