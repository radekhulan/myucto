<?php
declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Property\Services\Settlement;
use PDO;
use DomainException;
use LogicException;
use PHPUnit\Framework\TestCase;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Tests\Support\ChargeIssuanceFixture;
use MyInvoice\Repository\Property\Charges\Issuance\IssuanceRepository;
use MyInvoice\Service\Property\Charges\Issuance\IssuancePreviewService;
use MyInvoice\Service\Property\Services\Settlement\SettlementOriginAdapter;
use MyInvoice\Service\Anonymization\DatabaseCloner;
use MyInvoice\Tests\Support\CloneSandbox;
final class SettlementCloneTest extends TestCase {
 use ChargeIssuanceFixture;
 use \MyInvoice\Tests\Support\PaymentCorrectionFixture;
 private Connection $db;
 private array $f;
 private array $cases;
 private string $source;
 private ?string $target=null;
 private PDO $server;
 private Config $config;
 protected function setUp():void {
  $container=Bootstrap::buildContainer();$config=$container->get(Config::class);$base=(string)$container->get(Connection::class)->pdo()->query('SELECT DATABASE()')->fetchColumn();
  self::assertStringEndsWith('_test',$base);self::assertContains($config->get('db.host'),['localhost','127.0.0.1','::1']);
  $this->config=$config;$this->server=new PDO('mysql:host='.$config->get('db.host').';port='.$config->get('db.port',3306).';charset=utf8mb4',$config->get('db.user'),$config->get('db.pass',''),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
  $this->source=CloneSandbox::acquire($this->server,$base,true);
  $data=$config->all();$data['db']['name']=$this->source;$this->db=Connection::withoutSharedTestConnection(fn()=>new Connection(new Config($data,$config->dataDir())));
  $this->db->pdo()->exec("SET SESSION sql_mode='STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");$this->prepareCases();
 }
 protected function tearDown():void {
  if(isset($this->db)){if($this->db->pdo()->inTransaction())$this->db->pdo()->rollBack();$this->db->close();}
  if(isset($this->server))foreach([$this->target,$this->source??null] as $schema)if($schema!==null&&CloneSandbox::isLeased($schema))CloneSandbox::release($this->server,$schema);
 }
 private function container():\DI\Container {
  $container=Bootstrap::buildContainer();$container->set(Connection::class,$this->db);return $container;
 }
 private function prepareCases():void {
  $this->db->pdo()->beginTransaction();
  $credit=$this->appliedFixture();$claim=$this->newClaimFixture();$reversed=$this->appliedFixture();$reversed['owner']->reverse($reversed['f']['supplier'],$reversed['result']['id'],'2026-12-31',1,'Synthetic clone.person@example.test reverse','clone-source-reverse',$reversed['f']['actor']);
  $f=$this->openingFixture();$d=$this->openingDocument($f)['document'];$facts=$d->snapshot();unset($facts['amounts']);$facts['external_dossier']=$this->dossier($f['clients'][1]);$d=\MyInvoice\Service\Property\Services\Settlement\SettlementDocument::fromEvidence($facts);$repo=$this->container()->get(\MyInvoice\Repository\Property\Services\Settlement\SettlementDocumentRepository::class);$pending=$repo->save($f['supplier'],$d,[$f['selector']],'2026-12-31',0,'clone-pending',$f['actor']);
  $this->cases=[];foreach(['credit'=>$credit,'claim'=>$claim,'reversed'=>$reversed] as $key=>$case)$this->cases[$key]=['supplier'=>$case['f']['supplier'],'actor'=>$case['f']['actor'],'application_id'=>$case['result']['id'],'document_id'=>$case['result']['document_id'],'credit_ids'=>$case['result']['credit_ids'],'debt_ids'=>$case['result']['debt_ids'],'request'=>$case['request']??$case['r'],'preview_hash'=>$case['preview']['preview_hash'],'clients'=>$case['f']['clients']];
  $this->cases['credit']['original_debt']=$credit['debt'];$this->cases['credit']['original_payment']=$credit['op']['id'];$this->cases['pending']=['supplier'=>$f['supplier'],'actor'=>$f['actor'],'document_id'=>$pending['id'],'clients'=>$f['clients']];
  $this->db->pdo()->commit();$this->extendSource();$this->appendDossier();
 }

 private function extendSource():void {
  if(isset($this->cases['extended']))return;$this->db->pdo()->beginTransaction();$payments=$this->correctionPayments();$c=$this->cases['credit'];$this->f=$c;$lot=$payments->credit($c['supplier'],$c['credit_ids'][0]);
  $this->documentRefundAccount($c['clients'][1]);$reservation=$payments->reserve($c['supplier'],$lot['id'],$lot['row_version'],['amount_cents'=>'10000','party_ids'=>[$c['clients'][1]],'disposition'=>'Synthetic clone.person@example.test refund','recipient_account'=>'1000000005/0100'],'clone-reservation',$c['actor']);$bank=$this->bankOn('-30.00','2026-12-31');$r=['kind'=>'refund','source'=>['kind'=>'bank','id'=>$bank,'expected_version'=>0],'amount_cents'=>'3000','accounting_date'=>'2026-12-31','reason'=>'Synthetic clone.person@example.test partial refund','reservation_id'=>$reservation['id'],'expected_reservation_version'=>$reservation['row_version']];$op=$payments->apply($c['supplier'],$r,$payments->preview($c['supplier'],$r)['preview_hash'],'clone-refund',$c['actor']);$this->cases['credit']['refund']=$op['id'];$this->cases['credit']['reservation']=$reservation['id'];
  $c=$this->cases['claim'];$this->f=$c;$bank=$this->bankOn('1000.00','2026-12-31');$r=['kind'=>'allocation','source'=>['kind'=>'bank','id'=>$bank,'expected_version'=>0],'amount_cents'=>'100000','accounting_date'=>'2026-12-31','reason'=>'Synthetic clone.person@example.test new claim payment','debts'=>$c['debt_ids'],'payment'=>['payer_id'=>$c['clients'][1],'debtor_id'=>$c['clients'][1],'received_at'=>'2026-12-31T13:00:00+01:00','choice'=>['claim_ids'=>$c['debt_ids'],'evidence'=>'Synthetic clone.person@example.test choice']]];$op=$payments->apply($c['supplier'],$r,$payments->preview($c['supplier'],$r)['preview_hash'],'clone-claim-payment',$c['actor']);$this->cases['claim']['payment']=$op['id'];
  foreach(['claim','credit'] as $kind){$c=$this->cases[$kind];$debtId=$kind==='claim'?$c['debt_ids'][0]:$c['original_debt'];$debt=(new \MyInvoice\Repository\Property\Payments\DebtRepository($this->db))->currentMany($c['supplier'],[$debtId])[0];$at='2027-01-01T12:00:00+01:00';$unit=(new \MyInvoice\Repository\Property\PropertyReadRepository($this->db))->find($c['supplier'],$debt['snapshot']['unit_id'],$at,null,true);$r=['unit_id'=>$unit['id'],'registry_revision_id'=>$unit['revision']['id'],'effective_at'=>$at,'decision'=>'transfer_not_applicable','reason'=>'Synthetic clone.person@example.test assessment','acquisition'=>['kind'=>'Synthetic contract','applicable_law'=>'Synthetic applicable rule','legal_basis'=>'Synthetic title','evidence'=>'Synthetic clone.person@example.test source','had_and_could_discover'=>true,'knowledge_evidence'=>'Synthetic knowledge','guarantee_evidence'=>'Synthetic guarantee'],'debts'=>[['debt_id'=>$debtId,'expected_event_id'=>null,'groups'=>$debt['snapshot']['liability_groups']]]];$events=new \MyInvoice\Repository\Property\Receivables\PartyEventRepository($this->db);$event=$events->confirm($c['supplier'],$r,$events->preview($c['supplier'],$r)['preview_hash'],'clone-party-event',$c['actor']);$this->cases[$kind]['party_event']=$event['id'];}
  $this->db->pdo()->commit();$this->cases['extended']=true;
 }


 private function dossier(int $recipient):array {
  return ['schema'=>1,'ref'=>'Synthetic clone.person@example.test dossier','issuer'=>['name'=>'Synthetic clone.person@example.test provider'],'issued_at'=>'2027-04-20T10:00:00+02:00','allocation_ref'=>'Synthetic clone.person@example.test external rule','segments'=>[['ordinal'=>1,'valid_from'=>'2026-01-01T00:00:00+01:00','valid_to'=>'2027-01-01T00:00:00+01:00','recipient_ids'=>[$recipient],'basis_ref'=>'Synthetic clone.person@example.test recipient title','allocation_ref'=>'Synthetic clone.person@example.test key']],'deliveries'=>[['segment_ordinal'=>1,'recipient_id'=>$recipient,'issued_to_id'=>$recipient,'delivered_to_id'=>null,'recipient_evidence'=>'','status'=>'pending','delivered_at'=>null,'method'=>null,'delivery_evidence'=>'','due'=>['date'=>null,'basis_ref'=>'']]],'person_changes'=>[]];
 }
 private function appendDossier():void {
  $c=$this->cases['credit'];$repo=$this->container()->get(\MyInvoice\Repository\Property\Services\Settlement\SettlementDossierRepository::class);$d=$this->dossier($c['clients'][1]);$a=$repo->append($c['supplier'],$c['document_id'],['type'=>'acceptance','dossier'=>$d],0,'Synthetic clone.person@example.test acceptance','clone-dossier-accept',$c['actor']);$delivery=$d['deliveries'][0];$delivery=array_replace($delivery,['status'=>'delivered','delivered_to_id'=>$c['clients'][1],'delivered_at'=>'2027-05-03T09:00:00+02:00','method'=>'post','delivery_evidence'=>'Synthetic clone.person@example.test receipt','due'=>['date'=>'2027-06-20','basis_ref'=>'Synthetic clone.person@example.test due agreement']]);$repo->append($c['supplier'],$c['document_id'],['type'=>'delivery','delivery'=>$delivery,'supersedes_event_id'=>$a['id']],1,'Synthetic clone.person@example.test delivery','clone-dossier-delivery',$c['actor']);$repo->append($c['supplier'],$c['document_id'],['type'=>'legal_metadata','person_change'=>['role'=>'creditor','from_ids'=>[$c['clients'][1]],'to_ids'=>[$c['clients'][2]],'effective_at'=>'2027-05-04T10:00:00+02:00','basis'=>'assignment','evidence'=>'Synthetic clone.person@example.test assignment']],2,'Synthetic clone.person@example.test legal metadata','clone-dossier-legal',$c['actor']);
 }
 private function resetDossierTarget():void {
  self::assertTrue(CloneSandbox::isLeased($this->target));$q=$this->server->query('SELECT COUNT(*) FROM '.DatabaseCloner::quote($this->target).'.property_service_settlement_dossier_events');self::assertSame(3,(int)$q->fetchColumn());
  $runtime=sys_get_temp_dir().DIRECTORY_SEPARATOR.$this->target.'_reset_'.bin2hex(random_bytes(4));self::assertTrue(mkdir($runtime));
  try{$env=getenv();$env['MYINVOICE_DB_NAME']=$this->target;$env['MYINVOICE_DATA_DIR']=$runtime;$process=proc_open([PHP_BINARY,Bootstrap::rootDir().'/api/bin/reset.php','--yes','--keep-users-supplier','--skip-files'],[0=>['pipe','r'],1=>['file',$runtime.'/out.log','w'],2=>['file',$runtime.'/err.log','w']],$pipes,Bootstrap::rootDir(),$env);self::assertIsResource($process);fclose($pipes[0]);$exit=proc_close($process);self::assertSame(0,$exit,file_get_contents($runtime.'/out.log').file_get_contents($runtime.'/err.log'));foreach(['property_service_settlement_dossier_events','property_service_settlements','property_service_settlement_applications','property_objects','journal_entries'] as $table)self::assertSame(0,(int)$this->server->query('SELECT COUNT(*) FROM '.DatabaseCloner::quote($this->target).'.'.DatabaseCloner::quote($table))->fetchColumn(),$table);}
  finally{foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($runtime,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST) as $file)$file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());rmdir($runtime);}
 }
 private function financialGraph(array $value,string $path=''):array {
  $out=[];foreach($value as $key=>$part){$next=$path.'/'.$key;if($key==='id'||str_ends_with((string)$key,'_id')||str_ends_with((string)$key,'_cents')||in_array($key,['amount','members','party_ids','recipient_ids','from_ids','to_ids','dimensions','dimension_splits'],true))$out[$next]=$part;elseif(is_array($part))$out+=$this->financialGraph($part,$next);}return $out;
 }
 private function assertClonedRows(Connection $target):void {
  foreach([...\MyInvoice\Service\Property\Services\Settlement\SettlementUseGuard::TABLES,...\MyInvoice\Repository\Property\Services\Settlement\SettlementDossierRepository::TABLES] as $table){$order=match($table){'property_service_settlement_origins'=>'supplier_id,document_id,ordinal','property_service_settlement_heads'=>'supplier_id,document_id',default=>'supplier_id,id'};$before=$this->db->pdo()->query('SELECT * FROM '.$table.' ORDER BY '.$order)->fetchAll(PDO::FETCH_ASSOC);$after=$target->pdo()->query('SELECT * FROM '.$table.' ORDER BY '.$order)->fetchAll(PDO::FETCH_ASSOC);self::assertCount(count($before),$after,$table);
   foreach($before as $i=>$row)foreach($row as $column=>$old){$new=$after[$i][$column];if(str_ends_with($column,'_json')){$oldFact=json_decode($old,true,flags:JSON_THROW_ON_ERROR);$newFact=json_decode($new,true,flags:JSON_THROW_ON_ERROR);self::assertSame($this->financialGraph($oldFact),$this->financialGraph($newFact),$table.'.'.$column);self::assertStringNotContainsString('clone.person@example.test',$new);$hash=substr($column,0,-5).'_hash';if(str_contains($old,'clone.person@example.test')&&isset($row[$hash]))self::assertNotSame($row[$hash],$after[$i][$hash]);}elseif(!str_ends_with($column,'_hash'))self::assertSame($old,$new,$table.'.'.$column);}
  }
 }

 private function fingerprint(Connection $db):string {
  $pdo=$db->pdo();$data=[];foreach($pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM) as [$table,$kind]){if($kind==='VIEW')continue;$data[$table]=$pdo->query('SELECT * FROM '.DatabaseCloner::quote($table))->fetchAll(PDO::FETCH_ASSOC);}return hash('sha256',serialize($data));
 }
 public function testNativeAnonymizedCloneKeepsS8GraphCurrentReplayableAndTenantBound():void {
  $before=$this->fingerprint($this->db);$config=$this->config;
  $this->target=CloneSandbox::anonymized($this->server,$config,$this->source,'synthetic-s8-clone');
  self::assertSame($before,$this->fingerprint($this->db));
  $data=$config->all();$data['db']['name']=$this->target;$target=Connection::withoutSharedTestConnection(fn()=>new Connection(new Config($data,$config->dataDir())));$container=Bootstrap::buildContainer();$container->set(Connection::class,$target);$target->pdo()->beginTransaction();
  try{
   $this->assertClonedRows($target);
   foreach(array_diff_key($this->cases,['extended'=>true]) as $kind=>$case){$supplier=$case['supplier'];(new \MyInvoice\Service\Property\Payments\PaymentLocks($target))->acquire($supplier);$docRepo=$container->get(\MyInvoice\Repository\Property\Services\Settlement\SettlementDocumentRepository::class);$doc=$docRepo->find($supplier,$case['document_id'],true);self::assertStringNotContainsString('clone.person@example.test',json_encode($doc,JSON_THROW_ON_ERROR));
    if(in_array($kind,['credit','pending'],true)){$dossier=$container->get(\MyInvoice\Repository\Property\Services\Settlement\SettlementDossierRepository::class)->readLocked($supplier,$doc['id']);self::assertSame($kind==='credit'?3:0,$dossier['revision_no']);self::assertStringNotContainsString('clone.person@example.test',json_encode($dossier,JSON_THROW_ON_ERROR));self::assertSame($kind==='credit'?'delivered':'pending',$dossier['dossier']['deliveries'][0]['status']);if($kind==='credit'){self::assertSame('2027-06-20',$dossier['dossier']['deliveries'][0]['due']['date']);self::assertSame('2027-05-03T07:00:00.000000Z',$dossier['dossier']['deliveries'][0]['delivered_at']);}}
    if($kind==='pending'){$facts=$doc['document'];$r=['accounting_date'=>'2026-12-31','evidence'=>'Synthetic cloned pending disposition','advances'=>[['origin_key'=>$facts['origins'][0]['kind'].':'.$facts['origins'][0]['id'],'applied_cents'=>'80000']],'reliefs'=>[],'opening_credits'=>[],'new_claim'=>null];$owner=$container->get(\MyInvoice\Repository\Property\Services\Settlement\SettlementApplicationRepository::class);$preview=$owner->preview($supplier,$doc['id'],$r,0);$done=$owner->apply($supplier,$doc['id'],$r,0,$preview['preview_hash'],'clone-pending-apply',$case['actor']);self::assertTrue($done['active']);continue;}
    $app=(new \MyInvoice\Service\Property\Services\Settlement\SettlementHistory($target))->application($supplier,$case['application_id'],true);self::assertSame($case['credit_ids'],$app['credit_ids']);self::assertSame($case['debt_ids'],$app['debt_ids']);self::assertSame($kind!=='reversed',$app['active']);self::assertStringNotContainsString('clone.person@example.test',json_encode($app,JSON_THROW_ON_ERROR));
    $request=$app['receipt']['preview']['request'];$preview=$app['receipt']['preview']['preview_hash'];$owner=$container->get(\MyInvoice\Repository\Property\Services\Settlement\SettlementApplicationRepository::class);$token=$kind==='claim'?'s3-new-claim-apply':'s8-apply';self::assertSame($app['id'],$owner->apply($supplier,$doc['id'],$request,0,$preview,$token,$case['actor'])['id']);
    foreach($app['credit_ids'] as $id){$lot=$container->get(\MyInvoice\Repository\Property\Payments\PaymentRepository::class)->credit($supplier,$id);self::assertSame($kind==='reversed'?'0':'7000',$lot['balance_cents']);self::assertSame($kind==='reversed'?'0':'7000',$lot['reserved_cents']);}
    foreach($app['debt_ids'] as $id){$debt=$container->get(\MyInvoice\Repository\Property\Payments\DebtRepository::class)->currentMany($supplier,[$id])[0];self::assertSame('200000',$debt['snapshot']['amount_cents']);self::assertTrue($debt['active']);self::assertSame('100000',array_sum($debt['paid']).'');$history=(new \MyInvoice\Repository\Property\Receivables\FinancialHistoryRepository($target))->readForDebts($supplier,[$id]);self::assertSame('200000',$history['debts'][$id]['events'][0]['amount_cents']);}
    foreach(array_diff_key($this->cases,['extended'=>true]) as $other)if($other['supplier']!==$supplier){try{$docRepo->find($other['supplier'],$doc['id'],true);self::fail('Foreign tenant document leaked');}catch(DomainException $e){self::assertSame('settlement_document_not_found',$e->getMessage());}}
   }
   foreach(['claim','credit'] as $kind){$c=$this->cases[$kind];$events=(new \MyInvoice\Repository\Property\Receivables\PartyEventRepository($target))->history($c['supplier'],true);self::assertSame($c['party_event'],$events[0]['id']);self::assertStringNotContainsString('clone.person@example.test',json_encode($events,JSON_THROW_ON_ERROR));try{$container->get(\MyInvoice\Repository\Property\Services\Settlement\SettlementApplicationRepository::class)->reverse($c['supplier'],$c['application_id'],'2026-12-31',1,'Synthetic pinned clone reverse','clone-pinned-reverse',$c['actor']);self::fail('Cloned legal source pin bypassed');}catch(\MyInvoice\Service\Property\Payments\PaymentException $e){self::assertSame('property_payment_party_history_in_use',$e->errorCode);}}
   $c=$this->cases['credit'];$debt=$container->get(\MyInvoice\Repository\Property\Payments\DebtRepository::class)->currentMany($c['supplier'],[$c['original_debt']])[0];self::assertSame('20000',array_sum($debt['paid']).'');self::assertSame('20000',array_sum($debt['reductions']).'');$history=(new \MyInvoice\Repository\Property\Receivables\FinancialHistoryRepository($target))->readForDebts($c['supplier'],[$c['original_debt']]);$claim='0';$paid='0';foreach($history['debts'][$c['original_debt']]['events'] as $event){if($event['kind']==='claim')$claim=bcadd($claim,$event['amount_cents'],0);if($event['kind']==='paid')$paid=bcadd($paid,$event['amount_cents'],0);}self::assertSame('20000',$claim);self::assertSame('20000',$paid);
   try{(new \MyInvoice\Service\Anonymization\PaymentsCloneScrubber($target,new \MyInvoice\Service\Anonymization\TextScrubber(new \MyInvoice\Service\Anonymization\Pseudonymizer(str_repeat('synthetic-s8-clone-',3)))))->run();self::fail('Live immutable triggers bypassed');}catch(LogicException $e){self::assertStringContainsString('own transaction',$e->getMessage());}
   $target->pdo()->rollBack();
  }finally{if($target->pdo()->inTransaction())$target->pdo()->rollBack();$target->close();}
  $this->resetDossierTarget();
  self::assertSame($before,$this->fingerprint($this->db));
 }
 private function openingFixture():array {
  $f=$this->chargeFixture($this->db);$this->f=$f;$supplier=$f['supplier'];
  (new \MyInvoice\Repository\AccountingPeriodRepository($this->db))->create($supplier,2026,'2026-01-01','2026-12-31');
  (new \MyInvoice\Service\Accounting\ChartOfAccountsSeeder($this->db))->seedForSupplier($supplier,'2026-01-01');
  $accounts=new \MyInvoice\Repository\ChartOfAccountsRepository($this->db);
  foreach(['315.100','315.210','324.110','379.900','379.910'] as $code){if($accounts->findByCode($supplier,$code)!==null)continue;$parent=$accounts->findByCode($supplier,substr($code,0,3));$accounts->insert($supplier,['account_code'=>$code,'name'=>'Synthetic clone.person@example.test '.$code,'account_type'=>$parent['account_type'],'normal_side'=>$parent['normal_side'],'parent_id'=>$parent['id']]);}
  $common=['unit_id'=>$f['units']['U02'],'service_id'=>$f['service']['id'],'origin_at'=>'2026-01-01T00:00:00+01:00','period_from'=>'2026-01-01','period_to'=>'2026-01-01','due_date'=>'2026-01-15','occurrence_ref'=>'2026','title_ref'=>'Synthetic clone.person@example.test title','evidence_ref'=>'Synthetic clone.person@example.test takeover reconciliation'];
  $links=[['case_ref'=>'unpaid-service','occurrence_ref'=>'2026','amount_cents'=>'40000']];
  $debt=$common+['case_ref'=>'unpaid-service','kind'=>'debt','components'=>[['key'=>'principal','role'=>'principal','account_code'=>'315.100','amount_cents'=>'40000']],'liability_groups'=>[['group_key'=>'A','role'=>'debtor','mode'=>'individual','basis'=>'documented_title','members'=>[$f['clients'][1]],'extent'=>['num'=>'1','den'=>'1'],'evidence_ref'=>'Synthetic clone.person@example.test title','legal_reason'=>'Synthetic clone.person@example.test personal claim','valid_from'=>'2026-01-01T00:00:00+01:00','valid_to'=>null]],'legal'=>['basis'=>'legal_act','legal_act_date'=>'2025-12-15','evidence'=>'Synthetic clone.person@example.test decision','regime'=>'general'],'security'=>['kind'=>'unsecured','evidence'=>'Synthetic clone.person@example.test finding']];
  $advance=$common+['case_ref'=>'service-advance','kind'=>'advance','components'=>[['key'=>'advance','role'=>'service_advance','account_code'=>'324.110','amount_cents'=>'100000']],'advance_state'=>['purpose_ref'=>'Synthetic clone.person@example.test services','settlement_due_on'=>'2027-04-30','prescribed_cents'=>'100000','paid_cents'=>'60000','used_cents'=>'0','unpaid_cases'=>$links]];
  $cost=$common+['case_ref'=>'service-cost','kind'=>'service_carry','components'=>[['key'=>'cost','role'=>'service_cost','account_code'=>'315.210','amount_cents'=>'80000']],'service_state'=>['profile'=>'service_balance_sheet','allocation_ref'=>'Synthetic clone.person@example.test service key','counterpart_ref'=>'Synthetic clone.person@example.test supplier evidence','prescribed_cents'=>'100000','paid_cents'=>'60000','settled_cents'=>'0','cancelled_unpaid_cents'=>'0','unpaid_cases'=>$links,'recipient_ids'=>[$f['clients'][1]]]];
  $request=['mode'=>'post_new','accounting_date'=>'2026-01-01','effective_at'=>'2026-01-01T00:00:00+01:00','expected_revision'=>0,'evidence_ref'=>'Synthetic clone.person@example.test signed takeover','reason'=>'Synthetic clone.person@example.test opening','items'=>[$debt,$advance,$cost],'other_balances'=>[['account_code'=>'321','side'=>'credit','amount_cents'=>'20000','dimensions'=>[]]]];
  $repo=$this->container()->get(\MyInvoice\Repository\Property\Opening\OpeningRepository::class);$preview=$repo->preview($supplier,$request);$result=$repo->apply($supplier,$request,$preview['preview_hash'],'native-s8-opening',$f['actor']);
  $q=$this->db->pdo()->prepare('SELECT id,kind FROM property_opening_items WHERE supplier_id=? AND batch_id=?');$q->execute([$supplier,$result['id']]);$items=array_column($q->fetchAll(PDO::FETCH_ASSOC),'id','kind');
  return $f+['selector'=>['kind'=>'opening','advance_item_id'=>(int)$items['advance'],'service_item_id'=>(int)$items['service_carry']],'opening'=>$result];
 }
 private function openingDocument(array $f):array {
  $current=$this->container()->get(SettlementOriginAdapter::class)->current($f['supplier'],$f['units']['U02'],$f['service']['id'],'2026-12-31',[$f['selector']]);
  $facts=['supplier_id'=>$f['supplier'],'unit_id'=>$f['units']['U02'],'service_id'=>$f['service']['id'],'segment_key'=>'SYNTHETIC-2026','period_from'=>'2026-01-01','period_to'=>'2026-12-31','cutoff_at'=>'2026-01-31T23:59:59+01:00','known_at'=>'2026-10-17T12:00:00+02:00','recipient_ids'=>[$f['clients'][1]],'evidence'=>'Synthetic clone.person@example.test service cost and cutoff dossier','prescribed_cents'=>'100000','paid_cents'=>'60000','actual_cents'=>'80000','origins'=>$current['origins'],'cutoff_allocations'=>[],'cost'=>array_values($current['costs'])[0]];
  return ['document'=>\MyInvoice\Service\Property\Services\Settlement\SettlementDocument::fromEvidence($facts),'current'=>$current];
 }

 private function appliedFixture(bool $apply=true):array {
  $f=$this->openingFixture();$document=$this->openingDocument($f)['document'];
  $docRepo=$this->container()->get(\MyInvoice\Repository\Property\Services\Settlement\SettlementDocumentRepository::class);
  $saved=$docRepo->save($f['supplier'],$document,[$f['selector']],'2026-12-31',0,'s8-owner-document',$f['actor']);
  $source=$document->snapshot()['origins'][0];$debt=$source['debt_id'];$bank=$this->correctionBank('300.00');
  $r=['kind'=>'allocation','source'=>['kind'=>'bank','id'=>$bank,'expected_version'=>0],'amount_cents'=>'30000','accounting_date'=>'2026-02-01','reason'=>'Synthetic clone.person@example.test late payment','debts'=>[$debt],'payment'=>['payer_id'=>$f['clients'][1],'debtor_id'=>$f['clients'][1],'received_at'=>'2026-02-01T10:00:00+01:00','choice'=>['claim_ids'=>[$debt],'evidence'=>'Synthetic clone.person@example.test debtor disposition']]];
  $payments=$this->correctionPayments();$op=$payments->apply($f['supplier'],$r,$payments->preview($f['supplier'],$r)['preview_hash'],'s8-owner-late',$f['actor']);
  $allocation=(int)$this->db->pdo()->query('SELECT id FROM unit_payment_allocations WHERE operation_id='.$op['id'])->fetchColumn();
  $owner=$this->container()->get(\MyInvoice\Repository\Property\Services\Settlement\SettlementApplicationRepository::class);
  $request=['accounting_date'=>'2026-12-31','evidence'=>'Synthetic clone.person@example.test final service disposition','advances'=>[['origin_key'=>$source['kind'].':'.$source['id'],'applied_cents'=>'80000']],'reliefs'=>[['allocation_id'=>$allocation,'amount_cents'=>'10000','entitlement'=>['party_ids'=>[$f['clients'][1]],'evidence'=>'Synthetic clone.person@example.test documented creditor','due_date'=>'2027-04-30','account_code'=>'379.910']]],'opening_credits'=>[],'new_claim'=>null];
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
  (new \MyInvoice\Repository\Property\Payments\DebtRepository($this->db))->register($f['supplier'],['charge_id'=>(int)$this->db->pdo()->query('SELECT charge_id FROM unit_charge_lines WHERE id='.$id)->fetchColumn(),'expected_version'=>2,'principal_line_ids'=>[$id],'title_ref'=>'Synthetic clone.person@example.test service debt','due_date'=>'2026-01-15','legal'=>['basis'=>'legal_act','legal_act_date'=>'2025-12-01','evidence'=>'Synthetic clone.person@example.test decision','regime'=>'general'],'security'=>['kind'=>'unsecured','evidence'=>'Synthetic clone.person@example.test no security'],'damages_for'=>null],'s8-debt',$f['actor']);
  $result=$this->container()->get(SettlementOriginAdapter::class)->current($f['supplier'],$f['units']['U02'],$f['service']['id'],'2026-12-31',[['kind'=>'s3_line','id'=>$id]]);

  $chart=new \MyInvoice\Repository\ChartOfAccountsRepository($this->db);$parent=$chart->findByCode($f['supplier'],'315');$chart->insert($f['supplier'],['account_code'=>'315.210','name'=>'Synthetic clone.person@example.test service cost','account_type'=>'asset','normal_side'=>'debit','parent_id'=>$parent['id']]);
  $dims=$result['origins'][0]['advance_line']['dimensions'];$entry=$this->container()->get(\MyInvoice\Service\Accounting\PostingService::class)->postDocument($f['supplier'],'manual',null,[['account_code'=>'315.210','side'=>'debit','amount'=>bcdiv($actual,'100',2),'dimensions'=>$dims],['account_code'=>'321','side'=>'credit','amount'=>bcdiv($actual,'100',2),'dimensions'=>$dims]],['entry_date'=>'2026-12-31','posted_by'=>$f['actor']]);
  $costId=(int)$this->db->pdo()->query('SELECT MIN(id) FROM journal_entry_lines WHERE entry_id='.$entry)->fetchColumn();$selector=['kind'=>'s3_line','id'=>$id,'cost_line_id'=>$costId];
  $native=$this->container()->get(SettlementOriginAdapter::class)->current($f['supplier'],$f['units']['U02'],$f['service']['id'],'2026-12-31',[$selector]);
  $facts=['supplier_id'=>$f['supplier'],'unit_id'=>$f['units']['U02'],'service_id'=>$f['service']['id'],'segment_key'=>'S3-SERVICE-2026','period_from'=>'2026-01-01','period_to'=>'2026-12-31','cutoff_at'=>'2026-12-31T23:59:59+01:00','known_at'=>'2027-01-01T12:00:00+01:00','recipient_ids'=>[$f['clients'][1]],'evidence'=>'Synthetic clone.person@example.test native service ledger','prescribed_cents'=>'1200000','paid_cents'=>'0','actual_cents'=>$actual,'origins'=>$native['origins'],'cutoff_allocations'=>[],'cost'=>array_values($native['costs'])[0]];
  $doc=\MyInvoice\Service\Property\Services\Settlement\SettlementDocument::fromEvidence($facts);$saved=$this->container()->get(\MyInvoice\Repository\Property\Services\Settlement\SettlementDocumentRepository::class)->save($f['supplier'],$doc,[$selector],'2026-12-31',0,'s3-service-doc',$f['actor']);return compact('f','selector','saved','native','doc');

 }

 private function newClaimFixture():array {

  extract($this->nativeFixture('1400000'));$source=$native['origins'][0];$owner=$this->container()->get(\MyInvoice\Repository\Property\Services\Settlement\SettlementApplicationRepository::class);
  $r=['accounting_date'=>'2026-12-31','evidence'=>'Synthetic clone.person@example.test native service disposition','advances'=>[['origin_key'=>'s3_line:'.$source['id'],'applied_cents'=>'1200000']],'reliefs'=>[],'opening_credits'=>[],'new_claim'=>['party_ids'=>[$f['clients'][1]],'evidence'=>'Synthetic clone.person@example.test independent settlement claim','due_date'=>'2027-04-30','account_code'=>'315.210'],'claim_title'=>['effective_at'=>'2026-12-31T12:00:00+01:00','title_ref'=>'Synthetic clone.person@example.test service settlement title','liability_groups'=>[['group_key'=>'A','role'=>'debtor','mode'=>'individual','basis'=>'documented_title','members'=>[$f['clients'][1]],'extent'=>['num'=>'1','den'=>'1'],'evidence_ref'=>'Synthetic clone.person@example.test title','legal_reason'=>'Synthetic clone.person@example.test service liability','valid_from'=>'2026-12-31T12:00:00+01:00','valid_to'=>null]],'legal'=>['basis'=>'legal_act','legal_act_date'=>'2026-12-31','evidence'=>'Synthetic clone.person@example.test service legal act','regime'=>'general'],'security'=>['kind'=>'unsecured','evidence'=>'Synthetic clone.person@example.test unsecured finding']]];
  $preview=$owner->preview($f['supplier'],$saved['id'],$r,0);$result=$owner->apply($f['supplier'],$saved['id'],$r,0,$preview['preview_hash'],'s3-new-claim-apply',$f['actor']);self::assertCount(1,$result['debt_ids']);
  return compact('f','source','owner','r','preview','result');
 }

 private function bankOn(string $amount,string $date):int {
  $pdo=$this->db->pdo();$pdo->prepare("INSERT INTO bank_statements (supplier_id,file_name,file_hash,account_number,bank_code,currency,statement_date) VALUES (?,'Synthetic',?,'1000000005','0100','CZK',?)")->execute([$this->f['supplier'],hash('sha256',random_bytes(20)),$date]);$statement=(int)$pdo->lastInsertId();
  $pdo->prepare("INSERT INTO bank_transactions (statement_id,posted_at,amount,currency,counterparty_account,counterparty_bank) VALUES (?,?,?,'CZK','1000000005','0100')")->execute([$statement,$date,$amount]);$id=(int)$pdo->lastInsertId();
  $this->paymentFixtureContainer()->get(\MyInvoice\Service\Accounting\PostingService::class)->postDocument($this->f['supplier'],'bank',$id,[['account_code'=>'221','side'=>str_starts_with($amount,'-')?'credit':'debit','amount'=>ltrim($amount,'-')],['account_code'=>'379.900','side'=>str_starts_with($amount,'-')?'debit':'credit','amount'=>ltrim($amount,'-')]],['entry_date'=>$date,'posted_by'=>$this->f['actor']]);return $id;
 }
}
