<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Property;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Charges\Issuance\IssuanceRepository;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Anonymization\DatabaseCloner;
use MyInvoice\Service\Property\Charges\Issuance\IssuancePreviewService;
use MyInvoice\Service\Property\Charges\Issuance\IssuanceUseGuard;
use MyInvoice\Service\Property\Charges\Issuance\IssuanceException;
use MyInvoice\Tests\Support\ChargeIssuanceFixture;
use MyInvoice\Tests\Support\CloneSandbox;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class ChargeIssuanceCloneTest extends TestCase
{
    use ChargeIssuanceFixture;
    private PDO $server;
    private string $schema;
    private array $connections=[];
    private ?string $targetSchema=null;

    protected function setUp(): void
    {
        $container=Bootstrap::buildContainer(); $config=$container->get(Config::class);
        $source=(string)$container->get(Connection::class)->pdo()->query('SELECT DATABASE()')->fetchColumn();
        self::assertStringEndsWith('_test',$source); self::assertContains($config->get('db.host'),['localhost','127.0.0.1','::1']);
        $this->server=new PDO('mysql:host='.$config->get('db.host').';port='.$config->get('db.port',3306).';charset=utf8mb4',
            $config->get('db.user'),$config->get('db.pass',''),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $this->schema=CloneSandbox::acquire($this->server,$source,true);
        $data=$config->all(); $data['db']['name']=$this->schema;
        for($i=0;$i<2;$i++) {
            $db=Connection::withoutSharedTestConnection(fn()=>new Connection(new Config($data,$config->dataDir())));
            $db->pdo()->exec("SET SESSION sql_mode='STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
            $db->pdo()->exec('SET SESSION innodb_snapshot_isolation=0');
            $db->pdo()->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $this->connections[]=$db;
        }
    }

    protected function tearDown(): void
    {
        foreach($this->connections as $db) { if($db->pdo()->inTransaction()) $db->pdo()->rollBack(); $db->close(); }
        $ownedSchemas=array_values(array_filter([$this->targetSchema,$this->schema??null],static fn($schema)=>$schema!==null));
        if($this->targetSchema!==null) { if(!CloneSandbox::isLeased($this->targetSchema)) self::assertMatchesRegularExpression('/^myucto_issue_clone_[a-f0-9]{10}_test_copy_test$/D',$this->targetSchema); CloneSandbox::dropOrRelease($this->server,$this->targetSchema,$ownedSchemas); }
        if(isset($this->schema,$this->server)) {
            if(!CloneSandbox::isLeased($this->schema)) self::assertMatchesRegularExpression('/^myucto_issue_clone_[a-f0-9]{10}_test$/D',$this->schema);
            CloneSandbox::dropOrRelease($this->server,$this->schema,$ownedSchemas);
        }
    }

    public function testClonePreservesPostedAndPendingClaimsRetryCasAndOwnership(): void
    {
        [$source]=$this->connections;
        $source->pdo()->beginTransaction(); $f=$this->issuanceFixture($source); $source->pdo()->commit();
        $request=$f['issue_request']; $request['reason']='Decision person@example.test';
        $request['case_identity']['case_ref']='Alpha private case';
        $request['case_identity']['decision_ref']='Decision person@example.test';
        foreach($request['units'] as &$unit) foreach($unit['liability_groups'] as &$group) $group['evidence_ref']='Deed person@example.test'; unset($unit,$group);
        $preview=(new IssuancePreviewService($source))->preview($f['supplier'],$request);
        $input=['request'=>$request,'preview_hash'=>$preview['preview_hash'],'idempotency_key'=>'opaque-issued'];
        $writer=$this->writer($source); $batch=$writer->issue($f['supplier'],$input,$f['actor']);
        $posted=$writer->post($f['supplier'],$batch['id'],array_map(fn($c)=>['id'=>$c['id'],'row_version'=>1],$batch['charges']),$f['actor']);
        $pending=$request; $pending['source_key']='pending-case'; $pending['units']=[$request['units'][0]];
        $pending['case_identity']['case_ref']='Beta private case';
        $pending['units'][0]['case_distinction']=['against_source_keys'=>[$request['source_key']],'legal_reason'=>'Separate synthetic claim person@example.test','evidence_ref'=>'Separate decision person@example.test'];
        $preview=(new IssuancePreviewService($source))->preview($f['supplier'],$pending);
        $pendingBatch=$writer->issue($f['supplier'],['request'=>$pending,'preview_hash'=>$preview['preview_hash'],'idempotency_key'=>'opaque-pending'],$f['actor']);
        $reverse=['row_version'=>2,'accounting_date'=>'2026-02-01','effective_at'=>'2026-02-01T10:00:00+01:00',
            'reason'=>'Reverse person@example.test','evidence_ref'=>'Title person@example.test','idempotency_key'=>'opaque-reverse'];
        $writer->reverse($f['supplier'],$posted['charges'][0]['id'],$reverse,$f['actor']);
        $eventInput=['request'=>['kind'=>'security_basis','effective_at'=>'2026-02-02T10:00:00+01:00','expected_event_id'=>0,
            'reason'=>'Assessment person@example.test','evidence_ref'=>'Evidence person@example.test',
            'facts'=>['assessment_scale'=>'Synthetic scale person@example.test','rank'=>2,'legal_reason'=>'Legal title person@example.test']],
            'idempotency_key'=>'opaque-evidence'];
        $writer->recordEvidence($f['supplier'],$posted['charges'][1]['id'],$eventInput,$f['actor']);
        $sourceHash=$this->fingerprint($source->pdo());
        $cloner=new DatabaseCloner($this->server); $plan=$cloner->plan($this->schema); $this->targetSchema=CloneSandbox::acquire($this->server,$this->schema);
        $config=Bootstrap::buildContainer()->get(Config::class); $data=$config->all(); $data['db']['name']=$this->targetSchema;
        $target=Connection::withoutSharedTestConnection(fn()=>new Connection(new Config($data,$config->dataDir()))); $this->connections[]=$target;
        $pseudonymizer=new \MyInvoice\Service\Anonymization\Pseudonymizer(str_repeat('synthetic-issuance-',3));
        $pseudonymizer->dictionary()->add('Alpha private case','Same synthetic case');
        $pseudonymizer->dictionary()->add('Beta private case','Same synthetic case');
        $scrubber=new \MyInvoice\Service\Anonymization\TextScrubber($pseudonymizer);
        $registryMap=(new \MyInvoice\Service\Anonymization\PropertyCloneScrubber($target->pdo(),$scrubber))->run();
        $versionMap=(new \MyInvoice\Service\Anonymization\ChargesCloneScrubber($target->pdo(),$scrubber))->run($registryMap);
        $helper=new \MyInvoice\Service\Anonymization\IssuanceCloneScrubber($target,$scrubber);
        $row=$target->pdo()->query('SELECT id,snapshot_hash FROM unit_charges ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        $target->pdo()->prepare('UPDATE unit_charges SET snapshot_hash=? WHERE id=?')->execute([str_repeat('0',64),$row['id']]);
        $before=$this->fingerprint($target->pdo());
        try { $helper->run($registryMap,$versionMap); self::fail('Corrupt original hash accepted.'); }
        catch(IssuanceException $e) { self::assertSame('property_charge_issue_history_corrupt',$e->errorCode); }
        self::assertSame($before,$this->fingerprint($target->pdo()));
        $target->pdo()->prepare('UPDATE unit_charges SET snapshot_hash=? WHERE id=?')->execute([$row['snapshot_hash'],$row['id']]);
        $before=$this->fingerprint($target->pdo());
        try { $helper->run([],$versionMap); self::fail('Missing registry proof accepted.'); }
        catch(\RuntimeException $e) { self::assertStringContainsString('registry revision proof',$e->getMessage()); }
        self::assertSame($before,$this->fingerprint($target->pdo()));
        $line=$target->pdo()->query('SELECT id,amount FROM journal_entry_lines ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        $target->pdo()->prepare('UPDATE journal_entry_lines SET amount=amount+0.01 WHERE id=?')->execute([$line['id']]);
        $before=$this->fingerprint($target->pdo());
        try { $helper->run($registryMap,$versionMap); self::fail('Changed financial source accepted.'); }
        catch(IssuanceException $e) { self::assertSame('property_charge_issue_ledger_mismatch',$e->errorCode); }
        self::assertSame($before,$this->fingerprint($target->pdo()));
        $target->pdo()->prepare('UPDATE journal_entry_lines SET amount=? WHERE id=?')->execute([$line['amount'],$line['id']]);
        $target->pdo()->beginTransaction();
        try { $helper->run($registryMap,$versionMap); self::fail('Caller transaction accepted.'); }
        catch(\LogicException $e) { self::assertStringContainsString('own transaction',$e->getMessage()); }
        self::assertTrue($target->pdo()->inTransaction()); $target->pdo()->rollBack();
        $helper->run($registryMap,$versionMap);
        $reader=new \MyInvoice\Repository\Property\Charges\Issuance\IssuanceReadRepository($target);
        $anonymous=$reader->batch($f['supplier'],$batch['id']); $draft=$reader->batch($f['supplier'],$pendingBatch['id']);
        self::assertStringNotContainsString('person@example.test',json_encode([$anonymous,$draft],JSON_THROW_ON_ERROR));
        self::assertSame($batch['snapshot']['total_cents'],$anonymous['snapshot']['total_cents']);
        self::assertNotSame(\MyInvoice\Service\Property\Charges\Issuance\IssuanceCaseIdentity::canonical($anonymous['request']['case_identity']['case_ref']),
            \MyInvoice\Service\Property\Charges\Issuance\IssuanceCaseIdentity::canonical($draft['request']['case_identity']['case_ref']));
        foreach($anonymous['charges'] as $charge) self::assertSame($anonymous['request']['case_identity'],$charge['snapshot']['case_identity']);
        self::assertSame($anonymous['request']['case_identity'],$draft['charges'][0]['snapshot']['case_candidates'][0]['case_identity']);
        self::assertSame([$request['source_key']],$draft['request']['units'][0]['case_distinction']['against_source_keys']);
        self::assertSame($batch['charges'][0]['snapshot']['liability_groups'][0]['members'],$anonymous['charges'][0]['snapshot']['liability_groups'][0]['members']);
        $state=$anonymous['charges'][0]['snapshot']['registry']['revision'];
        self::assertSame($registryMap[$f['supplier']][$state['id']]['after_state_hash'],$state['state_hash']);
        self::assertSame($state['state_hash'],\MyInvoice\Service\Property\PropertyInput::hash($state['state']));
        $cloner->finalize($plan,$this->targetSchema);
        try { $helper->run($registryMap,$versionMap); self::fail('Live triggers accepted.'); }
        catch(\LogicException $e) { self::assertStringContainsString('before installing triggers',$e->getMessage()); }
        $writer=$this->writer($target);
        self::assertSame($anonymous,$writer->issue($f['supplier'],['request'=>$anonymous['request'],'preview_hash'=>$anonymous['preview_hash'],'idempotency_key'=>'opaque-issued'],$f['actor']));
        try { $writer->issue($f['supplier'],$input,$f['actor']); self::fail('Old personal payload accepted.'); }
        catch(IssuanceException $e) { self::assertSame('property_charge_issue_idempotency_conflict',$e->errorCode); }
        $reverse['reason']=$scrubber->scrub($reverse['reason']); $reverse['evidence_ref']=$scrubber->scrub($reverse['evidence_ref']);
        self::assertSame($anonymous['charges'][0],$writer->reverse($f['supplier'],$anonymous['charges'][0]['id'],$reverse,$f['actor']));
        $events=$reader->evidence($f['supplier'],$anonymous['charges'][1]['id']);
        self::assertCount(1,$events); self::assertStringNotContainsString('person@example.test',json_encode($events,JSON_THROW_ON_ERROR));
        self::assertSame($events[0],$writer->recordEvidence($f['supplier'],$anonymous['charges'][1]['id'],['request'=>$events[0]['request'],'idempotency_key'=>'opaque-evidence'],$f['actor']));
        $target->pdo()->beginTransaction();
        try { (new IssuanceUseGuard($target))->assertEntriesMutable($f['supplier'],[$anonymous['charges'][1]['journal_entry_id']]); self::fail('Clone lost ownership.'); }
        catch(IssuanceException $e) { self::assertSame('property_charge_issue_source_immutable',$e->errorCode); }
        $target->pdo()->rollBack();
        $cancel=$reverse; $cancel['row_version']=1; $cancel['idempotency_key']='cancel-pending';
        $cancelled=$writer->reverse($f['supplier'],$draft['charges'][0]['id'],$cancel,$f['actor']);
        self::assertSame('cancelled',$cancelled['status']);
        self::assertSame($cancelled,$writer->reverse($f['supplier'],$draft['charges'][0]['id'],$cancel,$f['actor']));
        self::assertSame($sourceHash,$this->fingerprint($source->pdo()));
    }

    public function testFullAnonymizationServicePreservesVerifiedIssuedAmountsAndRetryWithoutPrivateText(): void
    {
        [$source]=$this->connections;
        $source->pdo()->beginTransaction();$f=$this->issuanceFixture($source);$source->pdo()->commit();
        $request=$f['issue_request'];$request['reason']='Decision issuance-person@example.test';
        foreach($request['accounts'] as &$account) $account['basis_ref']='Basis issuance-person@example.test';unset($account);
        $preview=(new IssuancePreviewService($source))->preview($f['supplier'],$request);
        $original=$this->writer($source)->issue($f['supplier'],['request'=>$request,'preview_hash'=>$preview['preview_hash'],
            'idempotency_key'=>'opaque-full-issuance'],$f['actor']);
        $original=$this->writer($source)->post($f['supplier'],$original['id'],
            array_map(fn($charge)=>['id'=>$charge['id'],'row_version'=>1],$original['charges']),$f['actor']);
        $sourceHash=$this->fingerprint($source->pdo());
        $config=Bootstrap::buildContainer()->get(Config::class);
        $this->targetSchema=CloneSandbox::anonymized($this->server,$config,$this->schema,'synthetic-issuance-full-service');
        $data=$config->all();$data['db']['name']=$this->targetSchema;
        $target=Connection::withoutSharedTestConnection(fn()=>new Connection(new Config($data,$config->dataDir())));$this->connections[]=$target;
        $anonymous=(new \MyInvoice\Repository\Property\Charges\Issuance\IssuanceReadRepository($target))->batch($f['supplier'],$original['id']);
        self::assertFalse(str_contains(json_encode($anonymous,JSON_THROW_ON_ERROR),'issuance-person@example.test'));
        self::assertSame($original['snapshot']['total_cents'],$anonymous['snapshot']['total_cents']);
        self::assertSame(array_column($original['charges'],'amount_cents'),array_column($anonymous['charges'],'amount_cents'));
        self::assertSame($original['charges'][0]['snapshot']['lines'][0]['dimensions'],$anonymous['charges'][0]['snapshot']['lines'][0]['dimensions']);
        self::assertSame($anonymous,$this->writer($target)->issue($f['supplier'],['request'=>$anonymous['request'],
            'preview_hash'=>$anonymous['preview_hash'],'idempotency_key'=>'opaque-full-issuance'],$f['actor']));
        self::assertSame($sourceHash,$this->fingerprint($source->pdo()));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('representativeHistories')]
    public function testReminderRepresentativeAddedAfterSnapshotCannotBeDeleted(string $kind): void
    {
        [$reader,$source]=$this->connections;
        $source->pdo()->beginTransaction();$f=$this->issuanceFixture($source);
        $client=$f['clients'][1];
        $source->pdo()->prepare("INSERT INTO clients (supplier_id,company_name,street,city,zip,country_id,main_email,currency_default_id)
            SELECT supplier_id,'Synthetic delivery representative',street,city,zip,country_id,'representative@example.test',currency_default_id FROM clients WHERE id=?")
            ->execute([$client]);
        $representative=(int)$source->pdo()->lastInsertId();$source->pdo()->commit();
        $request=$f['issue_request'];
        if($kind==='notice') {
            $members=$request['units'][0]['liability_groups'][0]['members'];
            $request['units'][0]['notices'][0]['notice']=['mode'=>'change','title_ref'=>'Synthetic advance decision',
                'written_notice_ref'=>'Synthetic written change','reason'=>'Synthetic annual advance change',
                'deliveries'=>[['recipient_id'=>$representative,'represented_ids'=>$members,'authority_ref'=>'Synthetic delivery authority',
                    'delivered_at'=>'2025-12-31T12:00:00Z','evidence_ref'=>'Synthetic notice delivery']],
                'agreement'=>['effective_at'=>'2025-12-31T13:00:00Z','recipient_ids'=>$members,
                    'evidence_ref'=>'Synthetic annual agreement','legal_basis'=>'Synthetic documented annual effective date']];
        }
        $preview=(new IssuancePreviewService($source))->preview($f['supplier'],$request);
        $reader->pdo()->beginTransaction();
        $guard=new \MyInvoice\Service\Property\PropertyHistoryUseGuard($reader);
        self::assertFalse($guard->clientHasHistory($f['supplier'],$representative));
        $batch=$this->writer($source)->issue($f['supplier'],['request'=>$request,'preview_hash'=>$preview['preview_hash'],
            'idempotency_key'=>'synthetic-representative-issue'],$f['actor']);
        $charge=$batch['charges'][0];$member=$charge['snapshot']['liability_groups'][0]['members'][0];
        if($kind==='reminder') {
            $event=$this->writer($source)->recordEvidence($f['supplier'],$charge['id'],['request'=>[
            'kind'=>'reminder','effective_at'=>'2026-02-02T10:00:00+01:00','expected_event_id'=>0,
            'reason'=>'Synthetic reminder','evidence_ref'=>'Synthetic delivery evidence','facts'=>[
                'recipient_id'=>$representative,'represented_ids'=>[$member],'authority_ref'=>'Synthetic authority',
                'delivered_at'=>'2026-02-02T09:00:00+01:00','delivery_ref'=>'Synthetic delivery']],
            'idempotency_key'=>'synthetic-representative-reminder'],$f['actor']);
            self::assertSame($representative,$event['request']['facts']['recipient_id']);
        }
        self::assertFalse($guard->clientHasHistory($f['supplier'],$representative));
        $container=Bootstrap::buildContainer();$container->set(Connection::class,$reader);
        $http=(new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('DELETE','/api/clients/'.$representative)
            ->withAttribute(\MyInvoice\Middleware\SupplierScopeMiddleware::ATTR_CURRENT_ID,$f['supplier'])
            ->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_USER,['id'=>$f['actor'],'role'=>'admin']);
        $response=($container->get(\MyInvoice\Action\Client\DeleteClientAction::class))($http,new \Slim\Psr7\Response(),['id'=>$representative]);
        self::assertSame(409,$response->getStatusCode(),(string)$response->getBody());
        self::assertSame('has_dependencies',json_decode((string)$response->getBody(),true)['error']['code']);
        self::assertTrue($reader->pdo()->inTransaction());
        self::assertTrue($guard->clientHasHistory($f['supplier'],$representative,true));
        self::assertFalse($guard->clientHasHistory($f['supplier']+1000000,$representative,true));
    }

    public static function representativeHistories(): iterable
    {
        yield 'reminder' => ['reminder'];
        yield 'notice' => ['notice'];
    }

    private function fingerprint(PDO $pdo): string
    {
        $rows=[];
        foreach(['unit_charge_batches','unit_charges','unit_charge_lines','unit_charge_parties','unit_charge_notices','unit_charge_adjustments','unit_charge_evidence_events','unit_charge_issue_audit'] as $table)
            $rows[$table]=$pdo->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        return \MyInvoice\Service\Property\PropertyInput::hash($rows);
    }

    private function writer(Connection $db): IssuanceRepository
    {
        $container=Bootstrap::buildContainer(); self::assertInstanceOf(\DI\Container::class,$container); $container->set(Connection::class,$db);
        return new IssuanceRepository($db,$container->get(PostingService::class),$container->get(\MyInvoice\Repository\JournalEntryRepository::class));
    }
}
