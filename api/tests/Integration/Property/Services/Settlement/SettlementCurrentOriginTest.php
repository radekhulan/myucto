<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Anonymization\DatabaseCloner;
use MyInvoice\Tests\Support\OwnedTestDatabaseCleanup;
use MyInvoice\Tests\Support\ChargeIssuanceFixture;
use MyInvoice\Repository\Property\Charges\Issuance\IssuanceRepository;
use MyInvoice\Service\Property\Charges\Issuance\IssuancePreviewService;
use MyInvoice\Service\Property\Services\Settlement\SettlementOriginAdapter;
final class SettlementCurrentOriginTest extends TestCase {
 use ChargeIssuanceFixture;
 use \MyInvoice\Tests\Support\PaymentCorrectionFixture;
 private array $f;
 private Connection $db;
 private static Connection $connection;
 private static Config $config;
 private static PDO $server;
 private static string $schema;
 public static function setUpBeforeClass():void {
  $container=Bootstrap::buildContainer();$config=$container->get(Config::class);$source=(string)$container->get(Connection::class)->pdo()->query('SELECT DATABASE()')->fetchColumn();$configured=(string)getenv('MYINVOICE_DB_NAME');self::assertMatchesRegularExpression('/^[A-Za-z0-9_]+_test$/D',$configured);self::assertLessThanOrEqual(64,strlen($configured));self::assertSame($configured,$source);self::assertContains($config->get('db.host'),['localhost','127.0.0.1','::1']);
  self::$schema='myucto_s8_origin_'.bin2hex(random_bytes(5)).'_test';self::assertNotSame($source,self::$schema);self::$server=new PDO('mysql:host='.$config->get('db.host').';port='.$config->get('db.port',3306).';charset=utf8mb4',$config->get('db.user'),$config->get('db.pass',''),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
  $cloner=new DatabaseCloner(self::$server);$plan=$cloner->plan($source);$cloner->copyTables($plan,self::$schema,static function():void{});$cloner->finalize($plan,self::$schema);
  $data=$config->all();$data['db']['name']=self::$schema;self::$config=new Config($data,$config->dataDir());self::$connection=Connection::withoutSharedTestConnection(fn()=>new Connection(self::$config));self::assertSame(self::$schema,(string)self::$connection->pdo()->query('SELECT DATABASE()')->fetchColumn());
 }
 public static function tearDownAfterClass():void {
  try{if(isset(self::$connection)){if(self::$connection->pdo()->inTransaction())self::$connection->pdo()->rollBack();self::$connection->close();}}
  finally{if(isset(self::$server,self::$schema)){OwnedTestDatabaseCleanup::drop(self::$server,self::$schema,[self::$schema]);$q=self::$server->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?');$q->execute([self::$schema]);self::assertSame(0,(int)$q->fetchColumn());}}
 }
 private function container():\DI\Container {$container=Bootstrap::buildContainer();$container->set(Config::class,self::$config);$container->set(Connection::class,self::$connection);return $container;}
 private function paymentFixtureContainer():\DI\Container {return $this->container();}
 private function other():Connection {$db=Connection::withoutSharedTestConnection(fn()=>new Connection(self::$config));self::assertSame(self::$schema,(string)$db->pdo()->query('SELECT DATABASE()')->fetchColumn());return $db;}
 protected function setUp():void {$this->db=self::$connection;$configured=self::$schema;self::assertMatchesRegularExpression('/^[A-Za-z0-9_]+_test$/D',$configured);self::assertLessThanOrEqual(64,strlen($configured));self::assertSame($configured,(string)$this->db->pdo()->query('SELECT DATABASE()')->fetchColumn());self::assertSame($this->db->pdo(),$this->container()->get(Connection::class)->pdo());$this->db->pdo()->exec('SET SESSION sql_mode=\'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION\'');$this->db->pdo()->beginTransaction();}
 protected function tearDown():void {if($this->db->pdo()->inTransaction())$this->db->pdo()->rollBack();}
 public function testRealPostedS3LineHasNoCarriedPayment():void {
  $f=$this->issuanceFixture($this->db);$repo=$this->container()->get(IssuanceRepository::class);
  $preview=(new IssuancePreviewService($this->db))->preview($f['supplier'],$f['issue_request']);
  $batch=$repo->issue($f['supplier'],['request'=>$f['issue_request'],'preview_hash'=>$preview['preview_hash'],'idempotency_key'=>'native-s8-issue'],$f['actor']);
  $expected=[];foreach($batch['charges'] as $charge)$expected[]=['id'=>$charge['id'],'row_version'=>$charge['row_version']];
  $repo->post($f['supplier'],$batch['id'],$expected,$f['actor'],\MyInvoice\Repository\Property\Receivables\ChargeDebtRegistrar::MODE_MANUAL);
  $q=$this->db->pdo()->prepare('SELECT l.id FROM unit_charge_lines l JOIN unit_charges c ON c.supplier_id=l.supplier_id AND c.id=l.charge_id WHERE l.supplier_id=? AND c.unit_id=? AND l.service_id=?');$q->execute([$f['supplier'],$f['units']['U02'],$f['service']['id']]);$id=(int)$q->fetchColumn();
  (new \MyInvoice\Repository\Property\Payments\DebtRepository($this->db))->register($f['supplier'],['charge_id'=>(int)$this->db->pdo()->query('SELECT charge_id FROM unit_charge_lines WHERE id='.$id)->fetchColumn(),'expected_version'=>2,'principal_line_ids'=>[$id],'title_ref'=>'Synthetic service debt','due_date'=>'2026-01-15','legal'=>['basis'=>'legal_act','legal_act_date'=>'2025-12-01','evidence'=>'Synthetic decision','regime'=>'general'],'security'=>['kind'=>'unsecured','evidence'=>'Synthetic no security'],'damages_for'=>null],'s8-debt',$f['actor']);
  $result=$this->container()->get(SettlementOriginAdapter::class)->current($f['supplier'],$f['units']['U02'],$f['service']['id'],'2026-12-31',[['kind'=>'s3_line','id'=>$id]]);
  self::assertSame('0',$result['origins'][0]['carried_paid_cents']);self::assertSame('1200000',$result['origins'][0]['prescribed_cents']);self::assertSame([],$result['allocations']);
  $chart=new \MyInvoice\Repository\ChartOfAccountsRepository($this->db);$parent=$chart->findByCode($f['supplier'],'315');$chart->insert($f['supplier'],['account_code'=>'315.210','name'=>'Synthetic service cost','account_type'=>'asset','normal_side'=>'debit','parent_id'=>$parent['id']]);
  $dims=$result['origins'][0]['advance_line']['dimensions'];$entry=$this->container()->get(\MyInvoice\Service\Accounting\PostingService::class)->postDocument($f['supplier'],'manual',null,[['account_code'=>'315.210','side'=>'debit','amount'=>'8000.00','dimensions'=>$dims],['account_code'=>'321','side'=>'credit','amount'=>'8000.00','dimensions'=>$dims]],['entry_date'=>'2026-12-31','posted_by'=>$f['actor']]);
  $costId=(int)$this->db->pdo()->query('SELECT MIN(id) FROM journal_entry_lines WHERE entry_id='.$entry)->fetchColumn();$selector=['kind'=>'s3_line','id'=>$id,'cost_line_id'=>$costId];
  $native=$this->container()->get(SettlementOriginAdapter::class)->current($f['supplier'],$f['units']['U02'],$f['service']['id'],'2026-12-31',[$selector]);
  $facts=['supplier_id'=>$f['supplier'],'unit_id'=>$f['units']['U02'],'service_id'=>$f['service']['id'],'segment_key'=>'S3-SERVICE-2026','period_from'=>'2026-01-01','period_to'=>'2026-12-31','cutoff_at'=>'2026-12-31T23:59:59+01:00','known_at'=>'2027-01-01T12:00:00+01:00','recipient_ids'=>[$f['clients'][1]],'evidence'=>'Synthetic native service ledger','prescribed_cents'=>'1200000','paid_cents'=>'0','actual_cents'=>'800000','origins'=>$native['origins'],'cutoff_allocations'=>[],'cost'=>array_values($native['costs'])[0]];
  $doc=\MyInvoice\Service\Property\Services\Settlement\SettlementDocument::fromEvidence($facts);$saved=$this->container()->get(\MyInvoice\Repository\Property\Services\Settlement\SettlementDocumentRepository::class)->save($f['supplier'],$doc,[$selector],'2026-12-31',0,'s3-service-doc',$f['actor']);self::assertSame($doc->hash(),$saved['document_hash']);

 }

 private function openingFixture():array {
  $f=$this->chargeFixture($this->db);$this->f=$f;$supplier=$f['supplier'];
  (new \MyInvoice\Repository\AccountingPeriodRepository($this->db))->create($supplier,2026,'2026-01-01','2026-12-31');
  (new \MyInvoice\Service\Accounting\ChartOfAccountsSeeder($this->db))->seedForSupplier($supplier,'2026-01-01');
  $accounts=new \MyInvoice\Repository\ChartOfAccountsRepository($this->db);
  foreach(['315.100','315.210','324.110','379.900','379.910'] as $code){if($accounts->findByCode($supplier,$code)!==null)continue;$parent=$accounts->findByCode($supplier,substr($code,0,3));$accounts->insert($supplier,['account_code'=>$code,'name'=>'Synthetic '.$code,'account_type'=>$parent['account_type'],'normal_side'=>$parent['normal_side'],'parent_id'=>$parent['id']]);}
  $common=['unit_id'=>$f['units']['U02'],'service_id'=>$f['service']['id'],'origin_at'=>'2026-01-01T00:00:00+01:00','period_from'=>'2026-01-01','period_to'=>'2026-01-01','due_date'=>'2026-01-15','occurrence_ref'=>'2026','title_ref'=>'Synthetic title','evidence_ref'=>'Synthetic takeover reconciliation'];
  $links=[['case_ref'=>'unpaid-service','occurrence_ref'=>'2026','amount_cents'=>'40000']];
  $debt=$common+['case_ref'=>'unpaid-service','kind'=>'debt','components'=>[['key'=>'principal','role'=>'principal','account_code'=>'315.100','amount_cents'=>'40000']],'liability_groups'=>[['group_key'=>'A','role'=>'debtor','mode'=>'individual','basis'=>'documented_title','members'=>[$f['clients'][1]],'extent'=>['num'=>'1','den'=>'1'],'evidence_ref'=>'Synthetic title','legal_reason'=>'Synthetic personal claim','valid_from'=>'2026-01-01T00:00:00+01:00','valid_to'=>null]],'legal'=>['basis'=>'legal_act','legal_act_date'=>'2025-12-15','evidence'=>'Synthetic decision','regime'=>'general'],'security'=>['kind'=>'unsecured','evidence'=>'Synthetic finding']];
  $advance=$common+['case_ref'=>'service-advance','kind'=>'advance','components'=>[['key'=>'advance','role'=>'service_advance','account_code'=>'324.110','amount_cents'=>'100000']],'advance_state'=>['purpose_ref'=>'Synthetic services','settlement_due_on'=>'2027-04-30','prescribed_cents'=>'100000','paid_cents'=>'60000','used_cents'=>'0','unpaid_cases'=>$links]];
  $cost=$common+['case_ref'=>'service-cost','kind'=>'service_carry','components'=>[['key'=>'cost','role'=>'service_cost','account_code'=>'315.210','amount_cents'=>'80000']],'service_state'=>['profile'=>'service_balance_sheet','allocation_ref'=>'Synthetic service key','counterpart_ref'=>'Synthetic supplier evidence','prescribed_cents'=>'100000','paid_cents'=>'60000','settled_cents'=>'0','cancelled_unpaid_cents'=>'0','unpaid_cases'=>$links,'recipient_ids'=>[$f['clients'][1]]]];
  $request=['mode'=>'post_new','accounting_date'=>'2026-01-01','effective_at'=>'2026-01-01T00:00:00+01:00','expected_revision'=>0,'evidence_ref'=>'Synthetic signed takeover','reason'=>'Synthetic opening','items'=>[$debt,$advance,$cost],'other_balances'=>[['account_code'=>'321','side'=>'credit','amount_cents'=>'20000','dimensions'=>[]]]];
  $repo=$this->container()->get(\MyInvoice\Repository\Property\Opening\OpeningRepository::class);$preview=$repo->preview($supplier,$request);$result=$repo->apply($supplier,$request,$preview['preview_hash'],'native-s8-opening',$f['actor']);
  $q=$this->db->pdo()->prepare('SELECT id,kind FROM property_opening_items WHERE supplier_id=? AND batch_id=?');$q->execute([$supplier,$result['id']]);$items=array_column($q->fetchAll(PDO::FETCH_ASSOC),'id','kind');
  return $f+['selector'=>['kind'=>'opening','advance_item_id'=>(int)$items['advance'],'service_item_id'=>(int)$items['service_carry']],'opening'=>$result];
 }
 public function testRealS6ImportKeepsCarriedPaidSeparateFromLatePhysicalAllocation():void {
  $f=$this->openingFixture();$adapter=$this->container()->get(SettlementOriginAdapter::class);
  $before=$adapter->current($f['supplier'],$f['units']['U02'],$f['service']['id'],'2026-12-31',[$f['selector']]);
  self::assertSame('60000',$before['origins'][0]['carried_paid_cents']);self::assertSame('40000',$before['origins'][0]['receivable_cents']);self::assertSame([],$before['allocations']);
  $bank=$this->correctionBank('300.00');$debt=$before['origins'][0]['debt_id'];
  $request=['kind'=>'allocation','source'=>['kind'=>'bank','id'=>$bank,'expected_version'=>0],'amount_cents'=>'30000','accounting_date'=>'2026-02-01','reason'=>'Synthetic late payment','debts'=>[$debt],'payment'=>['payer_id'=>$f['clients'][1],'debtor_id'=>$f['clients'][1],'received_at'=>'2026-02-01T10:00:00+01:00','choice'=>['claim_ids'=>[$debt],'evidence'=>'Synthetic debtor disposition']]];
  $payments=$this->correctionPayments();$payments->apply($f['supplier'],$request,$payments->preview($f['supplier'],$request)['preview_hash'],'s8-late-payment',$f['actor']);
  $after=$adapter->current($f['supplier'],$f['units']['U02'],$f['service']['id'],'2026-12-31',[$f['selector']]);
  self::assertSame('60000',$after['origins'][0]['carried_paid_cents']);self::assertCount(1,$after['allocations']);self::assertSame('30000',$after['allocations'][0]['amount_cents']);self::assertNotSame($before['current_hash'],$after['current_hash']);self::assertSame($before['origins'],$after['origins']);
  $this->db->pdo()->prepare('UPDATE bank_transactions SET amount=? WHERE id=?')->execute(['301.00',$bank]);try{$adapter->current($f['supplier'],$f['units']['U02'],$f['service']['id'],'2026-12-31',[$f['selector']]);self::fail('Changed bank capacity accepted');}catch(\MyInvoice\Service\Property\Payments\PaymentException $e){self::assertContains($e->errorCode,['property_payment_source_clearing_mismatch','property_payment_source_changed']);}
 }
 public function testWrongUnitRejected():void {$f=$this->openingFixture();$this->expectExceptionMessage('settlement_origin_scope');$this->container()->get(SettlementOriginAdapter::class)->current($f['supplier'],$f['units']['U03'],$f['service']['id'],'2026-12-31',[$f['selector']]);}
 public function testWrongTenantRejected():void {$f=$this->openingFixture();$other=$this->chargeFixture($this->db);(new \MyInvoice\Repository\AccountingPeriodRepository($this->db))->create($other['supplier'],2026,'2026-01-01','2026-12-31');$this->expectException(\MyInvoice\Service\Property\Opening\OpeningException::class);$this->container()->get(SettlementOriginAdapter::class)->current($other['supplier'],$other['units']['U02'],$other['service']['id'],'2026-12-31',[$f['selector']]);}
 public function testClosedApplicationDateRejected():void {$f=$this->openingFixture();$this->db->pdo()->prepare("UPDATE accounting_periods SET status='closed' WHERE supplier_id=?")->execute([$f['supplier']]);$this->expectExceptionMessage('Účetní období');try{$this->container()->get(SettlementOriginAdapter::class)->current($f['supplier'],$f['units']['U02'],$f['service']['id'],'2026-12-31',[$f['selector']]);}catch(\MyInvoice\Service\Property\Payments\PaymentException $e){self::assertSame('property_payment_target_period_not_open',$e->errorCode);throw new \DomainException('Účetní období');}}
 public function testWrongRoleRejected():void {$f=$this->openingFixture();$selector=$f['selector'];$selector['advance_item_id']=$selector['service_item_id'];$this->expectExceptionMessage('settlement_source_role');$this->container()->get(SettlementOriginAdapter::class)->current($f['supplier'],$f['units']['U02'],$f['service']['id'],'2026-12-31',[$selector]);}
 public function testChangedFullTupleRejectedByNativeProof():void {$f=$this->openingFixture();$this->db->pdo()->prepare("UPDATE journal_entry_lines SET cost_center='TAMPER' WHERE supplier_id=? AND entry_id=?")->execute([$f['supplier'],$f['opening']['journal_entry_id']]);$this->expectException(\MyInvoice\Service\Property\Opening\OpeningException::class);$this->container()->get(SettlementOriginAdapter::class)->current($f['supplier'],$f['units']['U02'],$f['service']['id'],'2026-12-31',[$f['selector']]);}

 private function openingDocument(array $f):array {
  $current=$this->container()->get(SettlementOriginAdapter::class)->current($f['supplier'],$f['units']['U02'],$f['service']['id'],'2026-12-31',[$f['selector']]);
  $facts=['supplier_id'=>$f['supplier'],'unit_id'=>$f['units']['U02'],'service_id'=>$f['service']['id'],'segment_key'=>'SYNTHETIC-2026','period_from'=>'2026-01-01','period_to'=>'2026-12-31','cutoff_at'=>'2026-01-31T23:59:59+01:00','known_at'=>'2026-10-17T12:00:00+02:00','recipient_ids'=>[$f['clients'][1]],'evidence'=>'Synthetic service cost and cutoff dossier','prescribed_cents'=>'100000','paid_cents'=>'60000','actual_cents'=>'80000','origins'=>$current['origins'],'cutoff_allocations'=>[],'cost'=>array_values($current['costs'])[0]];
  return ['document'=>\MyInvoice\Service\Property\Services\Settlement\SettlementDocument::fromEvidence($facts),'current'=>$current];
 }
 public function testImmutableDocumentRetryAndRevisionAreNative():void {
  $f=$this->openingFixture();$d=$this->openingDocument($f)['document'];$repo=$this->container()->get(\MyInvoice\Repository\Property\Services\Settlement\SettlementDocumentRepository::class);
  $stored=$repo->save($f['supplier'],$d,[$f['selector']],'2026-12-31',0,'document-1',$f['actor']);self::assertSame($d->hash(),$stored['document_hash']);
  $same=$repo->save($f['supplier'],$d,[$f['selector']],'2026-12-31',0,'document-1',$f['actor']);self::assertSame($stored,$same);
  $next=$repo->save($f['supplier'],$d,[$f['selector']],'2026-12-31',1,'document-2',$f['actor']);self::assertSame(2,$next['revision_no']);self::assertSame($stored['id'],$next['previous_id']);self::assertSame($stored,$repo->find($f['supplier'],$stored['id'],true));
  try{$this->db->pdo()->prepare('UPDATE property_service_settlements SET document_hash=? WHERE id=?')->execute([str_repeat('0',64),$stored['id']]);self::fail('Immutable document changed');}catch(PDOException $e){self::assertSame('45000',$e->getCode());}
 }
 public function testChangedRetryRejectedAndLeavesHistoryUntouched():void {
  $f=$this->openingFixture();$d=$this->openingDocument($f)['document'];$repo=$this->container()->get(\MyInvoice\Repository\Property\Services\Settlement\SettlementDocumentRepository::class);$stored=$repo->save($f['supplier'],$d,[$f['selector']],'2026-12-31',0,'document-1',$f['actor']);
  try{$repo->save($f['supplier'],$d,[$f['selector']],'2026-12-30',0,'document-1',$f['actor']);self::fail('Changed retry accepted');}catch(DomainException $e){self::assertSame('settlement_idempotency_conflict',$e->getMessage());}
  self::assertSame($stored,$repo->find($f['supplier'],$stored['id'],true));
 }
 public function testStaleCasRejectedWithoutSecondDocument():void {
  $f=$this->openingFixture();$d=$this->openingDocument($f)['document'];$repo=$this->container()->get(\MyInvoice\Repository\Property\Services\Settlement\SettlementDocumentRepository::class);$repo->save($f['supplier'],$d,[$f['selector']],'2026-12-31',0,'document-1',$f['actor']);
  try{$repo->save($f['supplier'],$d,[$f['selector']],'2026-12-31',0,'document-stale',$f['actor']);self::fail('Stale accepted');}catch(DomainException $e){self::assertSame('settlement_revision_conflict',$e->getMessage());}
  self::assertSame(1,(int)$this->db->pdo()->query('SELECT COUNT(*) FROM property_service_settlements WHERE supplier_id='.$f['supplier'])->fetchColumn());
 }

 public function testOldCallerSnapshotCannotHideClosedTarget():void {
  $f=$this->openingFixture();$this->db->pdo()->commit();$this->db->pdo()->beginTransaction();$this->db->pdo()->query('SELECT COUNT(*) FROM accounting_periods')->fetchColumn();
  $other=$this->other();$other->pdo()->prepare("UPDATE accounting_periods SET status='closed' WHERE supplier_id=?")->execute([$f['supplier']]);
  try{$this->container()->get(SettlementOriginAdapter::class)->current($f['supplier'],$f['units']['U02'],$f['service']['id'],'2026-12-31',[$f['selector']]);self::fail('Stale caller missed closed target');}catch(\MyInvoice\Service\Property\Payments\PaymentException $e){self::assertSame('property_payment_target_period_not_open',$e->errorCode);}catch(PDOException $e){self::assertSame(1020,(int)$e->errorInfo[1]);self::assertFalse($this->db->pdo()->inTransaction());}finally{$other->close();}
 }
 public function testOldCallerSnapshotCannotHideChangedOriginTuple():void {
  $f=$this->openingFixture();$this->db->pdo()->commit();$this->db->pdo()->beginTransaction();$this->db->pdo()->query('SELECT COUNT(*) FROM journal_entry_lines')->fetchColumn();
  $other=$this->other();$other->pdo()->prepare("UPDATE journal_entry_lines SET cost_center='TAMPER' WHERE supplier_id=? AND entry_id=?")->execute([$f['supplier'],$f['opening']['journal_entry_id']]);
  try{$this->container()->get(SettlementOriginAdapter::class)->current($f['supplier'],$f['units']['U02'],$f['service']['id'],'2026-12-31',[$f['selector']]);self::fail('Stale tuple accepted');}catch(\MyInvoice\Service\Property\Opening\OpeningException $e){self::assertSame('property_opening_ledger_changed',$e->errorCode);}catch(PDOException $e){self::assertSame(1020,(int)$e->errorInfo[1]);self::assertFalse($this->db->pdo()->inTransaction());}finally{$other->close();}
 }
 public function testOwnerRollbackRemovesDocumentAndEveryOriginAnchor():void {
  $f=$this->openingFixture();$d=$this->openingDocument($f)['document'];$repo=$this->container()->get(\MyInvoice\Repository\Property\Services\Settlement\SettlementDocumentRepository::class);
  $this->db->pdo()->exec('SAVEPOINT synthetic_owner');$saved=$repo->save($f['supplier'],$d,[$f['selector']],'2026-12-31',0,'rollback-document',$f['actor']);self::assertSame(1,$saved['revision_no']);
  $this->db->pdo()->exec('ROLLBACK TO SAVEPOINT synthetic_owner');
  foreach(['property_service_settlements','property_service_settlement_origins'] as $table)self::assertSame(0,(int)$this->db->pdo()->query('SELECT COUNT(*) FROM '.$table.' WHERE supplier_id='.$f['supplier'])->fetchColumn());
 }
 public function testActualS6LatePaymentProducesExactPureDispositionWithoutRewritingDocument():void {
  $f=$this->openingFixture();$initial=$this->openingDocument($f);$document=$initial['document'];$repo=$this->container()->get(\MyInvoice\Repository\Property\Services\Settlement\SettlementDocumentRepository::class);$stored=$repo->save($f['supplier'],$document,[$f['selector']],'2026-12-31',0,'immutable-cutoff',$f['actor']);
  $bank=$this->correctionBank('300.00');$debt=$initial['current']['origins'][0]['debt_id'];$payments=$this->correctionPayments();
  $request=['kind'=>'allocation','source'=>['kind'=>'bank','id'=>$bank,'expected_version'=>0],'amount_cents'=>'30000','accounting_date'=>'2026-02-01','reason'=>'Synthetic late payment','debts'=>[$debt],'payment'=>['payer_id'=>$f['clients'][1],'debtor_id'=>$f['clients'][1],'received_at'=>'2026-02-01T10:00:00+01:00','choice'=>['claim_ids'=>[$debt],'evidence'=>'Synthetic debtor disposition']]];
  $payments->apply($f['supplier'],$request,$payments->preview($f['supplier'],$request)['preview_hash'],'late-after-cutoff',$f['actor']);
  $native=$this->container()->get(SettlementOriginAdapter::class)->current($f['supplier'],$f['units']['U02'],$f['service']['id'],'2026-12-31',[$f['selector']]);$source=$native['origins'][0];$key=$source['kind'].':'.$source['id'];$evidence=$native['evidence'][$key];$allocation=$native['allocations'][0];
  $current=['supplier_id'=>$f['supplier'],'segment_key'=>'SYNTHETIC-2026','as_of'=>'2026-12-31T12:00:00+01:00','revision'=>1,'application_id'=>null,'origins'=>[['source'=>$source,'version'=>1,'financial_head'=>\MyInvoice\Service\Property\PropertyInput::hash($evidence),'party_head'=>\MyInvoice\Service\Property\PropertyInput::hash($evidence['debt']['snapshot']['liability_groups']),'prior_reduction_cents'=>'0','advance_available_cents'=>$source['prescribed_cents'],'active'=>true]],'allocations'=>[['id'=>$allocation['id'],'origin_key'=>$key,'source_hash'=>$allocation['source_hash'],'amount_cents'=>$allocation['amount_cents'],'operation_version'=>$allocation['operation_version'],'financial_head'=>$native['current_hash'],'relieved_cents'=>'0','active'=>$allocation['active'],'accounting_date'=>$allocation['accounting_date']]],'cost'=>['source'=>array_values($native['costs'])[0],'version'=>1,'available_cents'=>'80000'],'parties'=>[['id'=>$f['clients'][1],'supplier_id'=>$f['supplier']]]];
  $credit=$source['receivable_line'];$credit['account_code']='379.910';$credit['account_id']=(int)$this->db->pdo()->query("SELECT id FROM chart_of_accounts WHERE supplier_id=".$f['supplier']." AND account_code='379.910'")->fetchColumn();
  $disposition=['accounting_date'=>'2026-12-31','evidence'=>'Synthetic assessed late payment release','advances'=>[['origin_key'=>$key,'applied_cents'=>'80000']],'reliefs'=>[['allocation_id'=>$allocation['id'],'amount_cents'=>'10000','entitlement'=>['party_ids'=>[$f['clients'][1]],'evidence'=>'Synthetic creditor title','due_date'=>'2027-04-30','line'=>$credit]]],'opening_credits'=>[],'new_claim'=>null];
  $plan=\MyInvoice\Service\Property\Services\Settlement\SettlementPlanner::plan($document,$current,$disposition)->snapshot();
  self::assertSame('90000',$plan['current_paid_cents']);self::assertSame('80000',$plan['totals']['advance_applied_cents']);self::assertSame('10000',$plan['totals']['cancelled_unpaid_cents']);self::assertSame('10000',$plan['totals']['released_allocations_cents']);self::assertSame('0',$plan['totals']['opening_paid_credit_cents']);self::assertSame('10000',$plan['totals']['new_credit_cents']);self::assertSame($allocation['id'],$plan['reliefs'][0]['allocation_id']);self::assertSame($stored,$repo->find($f['supplier'],$stored['id'],true));
  self::assertSame(0,(int)$this->db->pdo()->query('SELECT COUNT(*) FROM property_service_settlement_applications WHERE supplier_id='.$f['supplier'])->fetchColumn());
 }

 public function testClosedHistoricalOriginCanBeReadForOpenTargetYear():void {
  $f=$this->openingFixture();$this->db->pdo()->prepare("UPDATE accounting_periods SET status='closed' WHERE supplier_id=?")->execute([$f['supplier']]);(new \MyInvoice\Repository\AccountingPeriodRepository($this->db))->create($f['supplier'],2027,'2027-01-01','2027-12-31');
  $result=$this->container()->get(SettlementOriginAdapter::class)->current($f['supplier'],$f['units']['U02'],$f['service']['id'],'2027-04-30',[$f['selector']]);self::assertSame('60000',$result['origins'][0]['carried_paid_cents']);self::assertSame('100000',$result['origins'][0]['prescribed_cents']);
 }
 public function testForeignDocumentAnchorCannotBeInserted():void {
  $f=$this->openingFixture();$d=$this->openingDocument($f)['document'];$saved=$this->container()->get(\MyInvoice\Repository\Property\Services\Settlement\SettlementDocumentRepository::class)->save($f['supplier'],$d,[$f['selector']],'2026-12-31',0,'native-doc',$f['actor']);$origin=$d->snapshot()['origins'][0];
  try{$this->db->pdo()->prepare('INSERT INTO property_service_settlement_origins (supplier_id,document_id,ordinal,opening_component_id,opening_advance_component_id,debt_id) VALUES (?,?,?,?,?,?)')->execute([1,$saved['id'],0,$origin['id'],$origin['opening_advance_component_id'],$origin['debt_id']]);self::fail('Cross tenant anchor accepted');}catch(PDOException $e){self::assertSame('23000',$e->getCode());}
 }
}
