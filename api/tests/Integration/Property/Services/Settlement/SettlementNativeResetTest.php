<?php
declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Property\Services\Settlement;
use PDO;
use PHPUnit\Framework\TestCase;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Charges\Issuance\IssuanceRepository;
use MyInvoice\Service\Property\Charges\Issuance\IssuancePreviewService;
use MyInvoice\Service\Property\Services\Settlement\{SettlementOriginAdapter,SettlementHistory,SettlementUseGuard};
use MyInvoice\Service\Anonymization\DatabaseCloner;
use MyInvoice\Service\Property\PropertyResetCleanup;
use MyInvoice\Tests\Support\{ChargeIssuanceFixture,PaymentCorrectionFixture};
final class SettlementNativeResetTest extends TestCase {
 use ChargeIssuanceFixture,PaymentCorrectionFixture;
 private Connection $db;private array $f;private PDO $server;private string $ownedSchema;private string $runtime;private array $evidence=[];
 protected function setUp():void {
  $container=Bootstrap::buildContainer();$config=$container->get(Config::class);$sourcePdo=$container->get(Connection::class)->pdo();$source=(string)$sourcePdo->query('SELECT DATABASE()')->fetchColumn();$configured=(string)getenv('MYINVOICE_DB_NAME');self::assertMatchesRegularExpression('/^[A-Za-z0-9_]+_test$/D',$configured);self::assertSame($configured,$source);self::assertContains($config->get('db.host'),['localhost','127.0.0.1','::1']);
  $this->ownedSchema='myucto_s8_reset_'.bin2hex(random_bytes(5)).'_test';$this->runtime=sys_get_temp_dir().DIRECTORY_SEPARATOR.$this->ownedSchema.'_'.bin2hex(random_bytes(4));self::assertTrue(mkdir($this->runtime));$this->server=new PDO('mysql:host='.$config->get('db.host').';port='.$config->get('db.port',3306).';charset=utf8mb4',$config->get('db.user'),$config->get('db.pass',''),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$q=$this->server->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?');$q->execute([$this->ownedSchema]);self::assertSame(0,(int)$q->fetchColumn());
  try{$cloner=new DatabaseCloner($this->server);$plan=$cloner->plan($source);$cloner->copyTables($plan,$this->ownedSchema,static function():void{});$cloner->finalize($plan,$this->ownedSchema);}catch(\Throwable $e){\MyInvoice\Tests\Support\OwnedTestDatabaseCleanup::drop($this->server,$this->ownedSchema,[$this->ownedSchema]);throw $e;}
  $data=$config->all();$data['db']['name']=$this->ownedSchema;$this->db=Connection::withoutSharedTestConnection(fn()=>new Connection(new Config($data,$this->runtime)));self::assertSame($this->ownedSchema,(string)$this->db->pdo()->query('SELECT DATABASE()')->fetchColumn());$this->db->pdo()->exec("SET SESSION sql_mode='STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");$this->db->pdo()->beginTransaction();
 }
 protected function tearDown():void {
  if(isset($this->db)){if($this->db->pdo()->inTransaction())$this->db->pdo()->rollBack();$this->db->close();}if(isset($this->server,$this->ownedSchema)){self::assertMatchesRegularExpression('/^myucto_s8_reset_[a-f0-9]{10}_test$/D',$this->ownedSchema);\MyInvoice\Tests\Support\OwnedTestDatabaseCleanup::drop($this->server,$this->ownedSchema,[$this->ownedSchema]);$q=$this->server->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?');$q->execute([$this->ownedSchema]);self::assertSame(0,(int)$q->fetchColumn());}
  if(isset($this->runtime)&&is_dir($this->runtime)){foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->runtime,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST) as $file)$file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());rmdir($this->runtime);}
 }
 private function container():\DI\Container {$container=Bootstrap::buildContainer();$container->set(Connection::class,$this->db);return $container;}
 private function rows(string $sql,array $args=[]):array {$q=$this->db->pdo()->prepare($sql);$q->execute($args);return $q->fetchAll(PDO::FETCH_ASSOC);}
 private function counts(array $tables):array {$out=[];foreach($tables as $table)$out[$table]=(int)$this->db->pdo()->query('SELECT COUNT(*) FROM '.DatabaseCloner::quote($table))->fetchColumn();return $out;}
 private function kept(array $suppliers):array {
  $marks=implode(',',array_fill(0,count($suppliers),'?'));$out=[];$out['users']=$this->rows('SELECT * FROM users ORDER BY id');$out['currencies']=$this->rows('SELECT * FROM currencies ORDER BY id');$out['supplier']=$this->rows('SELECT * FROM supplier WHERE id IN ('.$marks.') ORDER BY id',$suppliers);
  foreach(['supplier_vat_status_history','supplier_accounting_frameworks','accounting_periods','chart_of_accounts'] as $table)$out[$table]=$this->rows('SELECT * FROM '.$table.' WHERE supplier_id IN ('.$marks.') ORDER BY id',$suppliers);
  $out['dimension_types']=$this->rows("SELECT * FROM dimension_types WHERE system_key IS NULL OR system_key NOT IN ('unit','service') ORDER BY id");$out['dimension_values']=$this->rows("SELECT v.* FROM dimension_values v JOIN dimension_types t ON t.id=v.type_id WHERE t.system_key IS NULL OR t.system_key NOT IN ('unit','service') ORDER BY v.id");return $out;
 }
 private static function fingerprints(array $rows):array {return array_map(static fn($r)=>['count'=>count($r),'sha256'=>hash('sha256',json_encode($r,JSON_THROW_ON_ERROR))],$rows);}
 private function reset():array {
  self::assertMatchesRegularExpression('/^[A-Za-z0-9_]+_test$/D',$this->ownedSchema);self::assertSame($this->ownedSchema,(string)$this->db->pdo()->query('SELECT DATABASE()')->fetchColumn());self::assertFalse($this->db->pdo()->inTransaction());$env=getenv();$env['MYINVOICE_DB_NAME']=$this->ownedSchema;$env['MYINVOICE_DATA_DIR']=$this->runtime;
  $command=[PHP_BINARY,Bootstrap::rootDir().'/api/bin/reset.php','--yes','--keep-users-supplier','--skip-files'];$process=proc_open($command,[0=>['pipe','r'],1=>['file',$this->runtime.'/reset-output.log','w'],2=>['file',$this->runtime.'/reset-error.log','w']],$pipes,Bootstrap::rootDir(),$env);self::assertIsResource($process);fclose($pipes[0]);$exit=proc_close($process);$output=file_get_contents($this->runtime.'/reset-output.log').file_get_contents($this->runtime.'/reset-error.log');return [$exit,$output];
 }
 public function testGlobalKeepResetRemovesActiveS8GraphsAndPreservesConfiguration():void {
  $root=strtolower(str_replace('\\','/',Bootstrap::rootDir()));foreach([Bootstrap::class,Connection::class,\MyInvoice\Repository\Property\Services\Settlement\SettlementApplicationRepository::class,PropertyResetCleanup::class] as $class)self::assertStringStartsWith($root.'/api/src/',strtolower(str_replace('\\','/',(new \ReflectionClass($class))->getFileName())));
  $credit=$this->appliedFixture();$claim=$this->newClaimFixture();$pdo=$this->db->pdo();$suppliers=[$credit['f']['supplier'],$claim['f']['supplier']];$control=$this->createIsolatedSupplier($pdo,$credit['f']['supplier']);$suppliers[]=$control;$pdo->prepare("INSERT INTO supplier_accounting_frameworks (supplier_id,effective_from,accounting_framework) VALUES (?,'1900-01-01','cz_504')")->execute([$control]);(new \MyInvoice\Repository\AccountingPeriodRepository($this->db))->create($control,2026,'2026-01-01','2026-12-31');(new \MyInvoice\Service\Accounting\ChartOfAccountsSeeder($this->db))->seedForSupplier($control,'2026-01-01');
  $dimensions=new \MyInvoice\Repository\DimensionRepository($this->db);$type=$dimensions->createType($control,false,['code'=>'RESETCONTROL','name'=>'Synthetic preserved custom dimension','kind'=>'custom']);$typeRow=$this->rows('SELECT * FROM dimension_types WHERE id=?',[$type])[0];$value=$dimensions->createValue($typeRow,['code'=>'PRESERVED','name'=>'Synthetic preserved custom value'],$credit['f']['actor']);$customBefore=$this->rows('SELECT * FROM dimension_values WHERE id=?',[$value]);
  $history=new SettlementHistory($this->db);foreach([$credit,$claim] as $graph){$current=$history->application($graph['f']['supplier'],$graph['result']['id'],true);self::assertTrue($current['active']);self::assertNotEmpty($current['effects']);$links=$this->rows('SELECT a.id FROM property_service_settlement_applications a JOIN property_service_settlements d ON d.supplier_id=a.supplier_id AND d.id=a.document_id JOIN property_service_settlement_requests r ON r.supplier_id=a.supplier_id AND r.id=a.request_id JOIN property_service_settlement_heads h ON h.supplier_id=a.supplier_id AND h.document_id=a.document_id AND h.application_id=a.id WHERE a.supplier_id=? AND a.id=?',[$graph['f']['supplier'],$graph['result']['id']]);self::assertCount(1,$links);}
  self::assertSame('10000',$credit['payments']->credit($credit['f']['supplier'],$credit['result']['credit_ids'][0])['balance_cents']);$newDebt=(new \MyInvoice\Repository\Property\Payments\DebtRepository($this->db))->current($claim['f']['supplier'],$claim['result']['debt_ids'][0]);self::assertTrue($newDebt['active']);self::assertSame('200000',$newDebt['snapshot']['amount_cents']);self::assertNull($newDebt['snapshot']['charge_id']);self::assertNotNull($newDebt['snapshot']['settlement_effect_id']);
  $propertyDimensions=$this->rows("SELECT v.id FROM dimension_values v JOIN dimension_types t ON t.id=v.type_id WHERE t.system_key IN ('unit','service') ORDER BY v.id");self::assertNotEmpty($propertyDimensions);$tables=array_values(array_filter($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN),static fn($t)=>preg_match('/^(property_service_settlement|property_opening_|unit_payment_|unit_credit_|unit_claim_|unit_charge)/',$t)||in_array($t,['bank_statements','bank_transactions','unit_charges','unit_charge_lines','journal_entries','journal_entry_lines','journal_entry_line_dimensions','journal_entry_line_dimension_splits',...PropertyResetCleanup::TABLES],true)));sort($tables);$before=$this->counts($tables);
  foreach([...SettlementUseGuard::TABLES,'unit_payment_debts','unit_payment_allocations','unit_payment_operations','unit_credit_lots','unit_credit_parties','bank_statements','bank_transactions','journal_entries','journal_entry_lines','journal_entry_line_dimensions','property_opening_batches','property_objects'] as $table)self::assertGreaterThan(0,$before[$table],$table);
  $kept=$this->kept($suppliers);$this->evidence=['contract'=>'GLOBAL business wipe, keep-users-supplier; no tenant business-preservation claim','before_counts'=>$before,'kept_before'=>self::fingerprints($kept),'suppliers'=>$suppliers,'property_dimension_count'=>count($propertyDimensions),'active_applications'=>[$credit['result']['id'],$claim['result']['id']],'credit_cents'=>'10000','new_s8_claim_cents'=>'200000'];$pdo->commit();[$exit,$output]=$this->reset();$this->evidence['reset_exit']=$exit;$this->evidence['reset_output']=$output;$after=$this->counts($tables);$this->evidence['after_counts']=$after;self::assertSame(0,$exit,$output);
  foreach($after as $table=>$count)self::assertSame(0,$count,$table);self::assertSame(1,(int)$pdo->query('SELECT @@FOREIGN_KEY_CHECKS')->fetchColumn());self::assertSame([], $this->rows("SELECT id FROM dimension_types WHERE system_key IN ('unit','service')"));$ids=array_column($propertyDimensions,'id');self::assertSame([], $this->rows('SELECT id FROM dimension_values WHERE id IN ('.implode(',',array_fill(0,count($ids),'?')).')',$ids));self::assertSame($customBefore,$this->rows('SELECT * FROM dimension_values WHERE id=?',[$value]));$keptAfter=$this->kept($suppliers);$this->evidence['kept_after']=self::fingerprints($keptAfter);self::assertSame($kept,$keptAfter,'Users, suppliers, configuration and unrelated custom dimensions are retained exactly.');
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
 private function openingDocument(array $f):array {
  $current=$this->container()->get(SettlementOriginAdapter::class)->current($f['supplier'],$f['units']['U02'],$f['service']['id'],'2026-12-31',[$f['selector']]);
  $facts=['supplier_id'=>$f['supplier'],'unit_id'=>$f['units']['U02'],'service_id'=>$f['service']['id'],'segment_key'=>'SYNTHETIC-2026','period_from'=>'2026-01-01','period_to'=>'2026-12-31','cutoff_at'=>'2026-01-31T23:59:59+01:00','known_at'=>'2026-10-17T12:00:00+02:00','recipient_ids'=>[$f['clients'][1]],'evidence'=>'Synthetic service cost and cutoff dossier','prescribed_cents'=>'100000','paid_cents'=>'60000','actual_cents'=>'80000','origins'=>$current['origins'],'cutoff_allocations'=>[],'cost'=>array_values($current['costs'])[0]];
  return ['document'=>\MyInvoice\Service\Property\Services\Settlement\SettlementDocument::fromEvidence($facts),'current'=>$current];
 }

 private function appliedFixture(bool $apply=true):array {
  $f=$this->openingFixture();$document=$this->openingDocument($f)['document'];
  $docRepo=$this->container()->get(\MyInvoice\Repository\Property\Services\Settlement\SettlementDocumentRepository::class);
  $saved=$docRepo->save($f['supplier'],$document,[$f['selector']],'2026-12-31',0,'s8-owner-document',$f['actor']);
  $source=$document->snapshot()['origins'][0];$debt=$source['debt_id'];$bank=$this->correctionBank('300.00');
  $r=['kind'=>'allocation','source'=>['kind'=>'bank','id'=>$bank,'expected_version'=>0],'amount_cents'=>'30000','accounting_date'=>'2026-02-01','reason'=>'Synthetic late payment','debts'=>[$debt],'payment'=>['payer_id'=>$f['clients'][1],'debtor_id'=>$f['clients'][1],'received_at'=>'2026-02-01T10:00:00+01:00','choice'=>['claim_ids'=>[$debt],'evidence'=>'Synthetic debtor disposition']]];
  $payments=$this->correctionPayments();$op=$payments->apply($f['supplier'],$r,$payments->preview($f['supplier'],$r)['preview_hash'],'s8-owner-late',$f['actor']);
  $allocation=(int)$this->db->pdo()->query('SELECT id FROM unit_payment_allocations WHERE operation_id='.$op['id'])->fetchColumn();
  $owner=$this->container()->get(\MyInvoice\Repository\Property\Services\Settlement\SettlementApplicationRepository::class);
  $request=['accounting_date'=>'2026-12-31','evidence'=>'Synthetic final service disposition','advances'=>[['origin_key'=>$source['kind'].':'.$source['id'],'applied_cents'=>'80000']],'reliefs'=>[['allocation_id'=>$allocation,'amount_cents'=>'10000','entitlement'=>['party_ids'=>[$f['clients'][1]],'evidence'=>'Synthetic documented creditor','due_date'=>'2027-04-30','account_code'=>'379.910']]],'opening_credits'=>[],'new_claim'=>null];
  $preview=$owner->preview($f['supplier'],$saved['id'],$request,0);

  $result=$apply?$owner->apply($f['supplier'],$saved['id'],$request,0,$preview['preview_hash'],'s8-apply',$f['actor']):null;

 return compact('f','document','docRepo','saved','source','debt','bank','r','payments','op','allocation','owner','request','preview','result');
 }
 private function nativeFixture(string $actual):array {

  $f=$this->issuanceFixture($this->db);$this->f=$f;$repo=$this->container()->get(IssuanceRepository::class);
  $preview=(new IssuancePreviewService($this->db))->preview($f['supplier'],$f['issue_request']);
  $batch=$repo->issue($f['supplier'],['request'=>$f['issue_request'],'preview_hash'=>$preview['preview_hash'],'idempotency_key'=>'native-s8-issue'],$f['actor']);
  $expected=[];foreach($batch['charges'] as $charge)$expected[]=['id'=>$charge['id'],'row_version'=>$charge['row_version']];
  $repo->post($f['supplier'],$batch['id'],$expected,$f['actor'],\MyInvoice\Repository\Property\Receivables\ChargeDebtRegistrar::MODE_MANUAL);
  $q=$this->db->pdo()->prepare('SELECT l.id FROM unit_charge_lines l JOIN unit_charges c ON c.supplier_id=l.supplier_id AND c.id=l.charge_id WHERE l.supplier_id=? AND c.unit_id=? AND l.service_id=?');$q->execute([$f['supplier'],$f['units']['U02'],$f['service']['id']]);$id=(int)$q->fetchColumn();
  (new \MyInvoice\Repository\Property\Payments\DebtRepository($this->db))->register($f['supplier'],['charge_id'=>(int)$this->db->pdo()->query('SELECT charge_id FROM unit_charge_lines WHERE id='.$id)->fetchColumn(),'expected_version'=>2,'principal_line_ids'=>[$id],'title_ref'=>'Synthetic service debt','due_date'=>'2026-01-15','legal'=>['basis'=>'legal_act','legal_act_date'=>'2025-12-01','evidence'=>'Synthetic decision','regime'=>'general'],'security'=>['kind'=>'unsecured','evidence'=>'Synthetic no security'],'damages_for'=>null],'s8-debt',$f['actor']);
  $result=$this->container()->get(SettlementOriginAdapter::class)->current($f['supplier'],$f['units']['U02'],$f['service']['id'],'2026-12-31',[['kind'=>'s3_line','id'=>$id]]);

  $chart=new \MyInvoice\Repository\ChartOfAccountsRepository($this->db);$parent=$chart->findByCode($f['supplier'],'315');$chart->insert($f['supplier'],['account_code'=>'315.210','name'=>'Synthetic service cost','account_type'=>'asset','normal_side'=>'debit','parent_id'=>$parent['id']]);
  $dims=$result['origins'][0]['advance_line']['dimensions'];$cat=(new \MyInvoice\Service\Accounting\Dimension\SystemDimensionCatalog($this->db))->ensureForFramework($f['supplier'],'2026-12-31');$dims[$cat['activity']['id']]=$cat['activity']['values']['main'];$entry=$this->container()->get(\MyInvoice\Service\Accounting\PostingService::class)->postDocument($f['supplier'],'manual',null,[['account_code'=>'315.210','side'=>'debit','amount'=>bcdiv($actual,'100',2),'dimensions'=>$dims],['account_code'=>'321','side'=>'credit','amount'=>bcdiv($actual,'100',2),'dimensions'=>$dims]],['entry_date'=>'2026-12-31','posted_by'=>$f['actor']]);
  $costId=(int)$this->db->pdo()->query('SELECT MIN(id) FROM journal_entry_lines WHERE entry_id='.$entry)->fetchColumn();$selector=['kind'=>'s3_line','id'=>$id,'cost_line_id'=>$costId];
  $native=$this->container()->get(SettlementOriginAdapter::class)->current($f['supplier'],$f['units']['U02'],$f['service']['id'],'2026-12-31',[$selector]);
  $facts=['supplier_id'=>$f['supplier'],'unit_id'=>$f['units']['U02'],'service_id'=>$f['service']['id'],'segment_key'=>'S3-SERVICE-2026','period_from'=>'2026-01-01','period_to'=>'2026-12-31','cutoff_at'=>'2026-12-31T23:59:59+01:00','known_at'=>'2027-01-01T12:00:00+01:00','recipient_ids'=>[$f['clients'][1]],'evidence'=>'Synthetic native service ledger','prescribed_cents'=>'1200000','paid_cents'=>'0','actual_cents'=>$actual,'origins'=>$native['origins'],'cutoff_allocations'=>[],'cost'=>array_values($native['costs'])[0]];
  $doc=\MyInvoice\Service\Property\Services\Settlement\SettlementDocument::fromEvidence($facts);$saved=$this->container()->get(\MyInvoice\Repository\Property\Services\Settlement\SettlementDocumentRepository::class)->save($f['supplier'],$doc,[$selector],'2026-12-31',0,'s3-service-doc',$f['actor']);return compact('f','selector','saved','native','doc');

 }

 private function newClaimFixture(bool $apply=true):array {

  extract($this->nativeFixture('1400000'));$source=$native['origins'][0];$owner=$this->container()->get(\MyInvoice\Repository\Property\Services\Settlement\SettlementApplicationRepository::class);
  $r=['accounting_date'=>'2026-12-31','evidence'=>'Synthetic native service disposition','advances'=>[['origin_key'=>'s3_line:'.$source['id'],'applied_cents'=>'1200000']],'reliefs'=>[],'opening_credits'=>[],'new_claim'=>['party_ids'=>[$f['clients'][1]],'evidence'=>'Synthetic independent settlement claim','due_date'=>'2027-04-30','account_code'=>'315.210'],'claim_title'=>['effective_at'=>'2026-12-31T12:00:00+01:00','title_ref'=>'Synthetic service settlement title','liability_groups'=>[['group_key'=>'A','role'=>'debtor','mode'=>'individual','basis'=>'documented_title','members'=>[$f['clients'][1]],'extent'=>['num'=>'1','den'=>'1'],'evidence_ref'=>'Synthetic title','legal_reason'=>'Synthetic service liability','valid_from'=>'2026-12-31T12:00:00+01:00','valid_to'=>null]],'legal'=>['basis'=>'legal_act','legal_act_date'=>'2026-12-31','evidence'=>'Synthetic service legal act','regime'=>'general'],'security'=>['kind'=>'unsecured','evidence'=>'Synthetic unsecured finding']]];
  $preview=$owner->preview($f['supplier'],$saved['id'],$r,0);$result=$apply?$owner->apply($f['supplier'],$saved['id'],$r,0,$preview['preview_hash'],'s3-new-claim-apply',$f['actor']):null;
  return compact('f','saved','source','owner','r','preview','result');
 }

 private function bankOn(string $amount,string $date):int {
  $pdo=$this->db->pdo();$pdo->prepare("INSERT INTO bank_statements (supplier_id,file_name,file_hash,account_number,bank_code,currency,statement_date) VALUES (?,'Synthetic',?,'1000000005','0100','CZK',?)")->execute([$this->f['supplier'],hash('sha256',random_bytes(20)),$date]);$statement=(int)$pdo->lastInsertId();
  $pdo->prepare("INSERT INTO bank_transactions (statement_id,posted_at,amount,currency,counterparty_account,counterparty_bank) VALUES (?,?,?,'CZK','1000000005','0100')")->execute([$statement,$date,$amount]);$id=(int)$pdo->lastInsertId();
  $this->paymentFixtureContainer()->get(\MyInvoice\Service\Accounting\PostingService::class)->postDocument($this->f['supplier'],'bank',$id,[['account_code'=>'221','side'=>str_starts_with($amount,'-')?'credit':'debit','amount'=>ltrim($amount,'-')],['account_code'=>'379.900','side'=>str_starts_with($amount,'-')?'debit':'credit','amount'=>ltrim($amount,'-')]],['entry_date'=>$date,'posted_by'=>$this->f['actor']]);return $id;
 }
}
