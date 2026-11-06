<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Property\Charges;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\PropertyProfileRepository;
use MyInvoice\Repository\Property\PropertyReadRepository;
use MyInvoice\Repository\Property\PropertyRegistryRepository;
use MyInvoice\Service\Anonymization\DatabaseCloner;
use MyInvoice\Service\Property\PropertyException;
use MyInvoice\Tests\Support\CloneSandbox;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class ChargesCloneScrubberTest extends TestCase
{
    use \MyInvoice\Tests\Support\PropertyChargeFixture;
    private string $ownedSchema;
    private PDO $server;
    private ?string $targetSchema=null;
    private array $connections = [];

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $config = $container->get(Config::class);
        $source = (string) $container->get(Connection::class)->pdo()->query('SELECT DATABASE()')->fetchColumn();
        self::assertStringEndsWith('_test', $source);
        self::assertContains($config->get('db.host'), ['localhost', '127.0.0.1', '::1']);
        $this->server = new PDO('mysql:host=' . $config->get('db.host') . ';port=' . $config->get('db.port', 3306) . ';charset=utf8mb4',
            $config->get('db.user'), $config->get('db.pass', ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->ownedSchema = CloneSandbox::acquire($this->server, $source, true);
        $data = $config->all();
        $data['db']['name'] = $this->ownedSchema;
        for ($i = 0; $i < 2; ++$i) {
            $db = Connection::withoutSharedTestConnection(fn () => new Connection(new Config($data, $config->dataDir())));
            $db->pdo()->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
            $db->pdo()->exec('SET SESSION innodb_snapshot_isolation = 0');
            $db->pdo()->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $this->connections[] = $db;
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->connections as $db) {
            if ($db->pdo()->inTransaction()) $db->pdo()->rollBack();
            $db->close();
        }
        $ownedSchemas=array_values(array_filter([$this->targetSchema,$this->ownedSchema??null],static fn($schema)=>$schema!==null));
        if($this->targetSchema!==null) { if(!CloneSandbox::isLeased($this->targetSchema)) self::assertMatchesRegularExpression('/^myucto_charge_clone_[a-f0-9]{10}_test_clone_test$/D',$this->targetSchema); CloneSandbox::dropOrRelease($this->server,$this->targetSchema,$ownedSchemas); }
        if(!CloneSandbox::isLeased($this->ownedSchema)) self::assertMatchesRegularExpression('/^myucto_charge_clone_[a-f0-9]{10}_test$/D', $this->ownedSchema);
        CloneSandbox::dropOrRelease($this->server,$this->ownedSchema,$ownedSchemas);
    }

    public function testFullAnonymizationServicePreservesChargeCalculationAndRetryWithoutPersonalText(): void
    {
        [$source]=$this->connections;
        $source->pdo()->beginTransaction();$f=$this->chargeFixture($source);$source->pdo()->commit();
        $request=self::chargeRequest($f);
        $request['decision_ref']='Decision charge-person@example.test';
        $request['reason']='Reason charge-person@example.test';
        $request['rates'][0]['scope_ref']='Scope charge-person@example.test';
        $preview=(new \MyInvoice\Service\Property\Charges\ChargePreviewService($source))->preview($f['supplier'],$request);
        $original=(new \MyInvoice\Repository\Property\Charges\ChargeRepository($source))->saveVersion($f['supplier'],
            ['request'=>$request,'preview_hash'=>$preview['preview_hash'],'idempotency_key'=>'opaque-full-clone'],$f['actor']);
        $sourceHash=$this->fingerprint($source->pdo());
        $config=Bootstrap::buildContainer()->get(Config::class);
        $this->targetSchema=CloneSandbox::anonymized($this->server,$config,$this->ownedSchema,'synthetic-charge-full-service');
        $data=$config->all();$data['db']['name']=$this->targetSchema;
        $target=Connection::withoutSharedTestConnection(fn()=>new Connection(new Config($data,$config->dataDir())));$this->connections[]=$target;
        $reader=new \MyInvoice\Repository\Property\Charges\ChargeReadRepository($target);
        $anonymous=$reader->find($f['supplier'],$original['id']);
        self::assertStringNotContainsString('charge-person@example.test',json_encode($anonymous,JSON_THROW_ON_ERROR));
        self::assertSame($original['preview']['calculation']['unit_totals'],$anonymous['preview']['calculation']['unit_totals']);
        self::assertSame($original['preview']['calculation']['total_cents'],$anonymous['preview']['calculation']['total_cents']);
        self::assertSame($original['request']['rates'][0]['amount'],$anonymous['request']['rates'][0]['amount']);
        self::assertSame($original['id'],(new \MyInvoice\Repository\Property\Charges\ChargeRepository($target))->saveVersion($f['supplier'],
            ['request'=>$anonymous['request'],'preview_hash'=>$anonymous['preview_hash'],'idempotency_key'=>'opaque-full-clone'],$f['actor'])['id']);
        self::assertSame($sourceHash,$this->fingerprint($source->pdo()));
    }

    public function testAnonymousClonePreservesHistoryRetryCasAndRejectsCorruption(): void
    {
        [$source]=$this->connections;
        $source->pdo()->beginTransaction(); $f=$this->chargeFixture($source); $source->pdo()->commit();
        $registry=new PropertyRegistryRepository($source);
        $change=self::registryRequest('unused',$f['states']['U01']); unset($change['code']);
        $change['expected_revision']=1; $change['status']='pending'; $change['idempotency_key']='pending';
        $change['state']['unit_no']='Unit person@example.test';
        $registry->revise($f['supplier'],$f['units']['U01'],'unit',$change,$f['actor']);
        $history=(new PropertyReadRepository($source))->find($f['supplier'],$f['units']['U01'],'2026-01-02T00:00:00Z')['history'];
        $registry->confirm($f['supplier'],$f['units']['U01'],$history[1]['id'],['expected_revision'=>2,'effective_from'=>$change['effective_from'],'reason'=>'Confirm person@example.test','title_ref'=>'Deed person@example.test','idempotency_key'=>'confirmed'],$f['actor']);
        $repo=new \MyInvoice\Repository\Property\Charges\ChargeRepository($source);
        $componentRequest=['code'=>'EXTRA','name'=>'Component person@example.test','kind'=>'other','service_id'=>null,'effective_at'=>'2026-01-01T00:00:00+01:00','account_code'=>'','idempotency_key'=>'opaque-component'];
        $component=$repo->createComponent($f['supplier'],$componentRequest,$f['actor']);
        $request=self::chargeRequest($f); $request['decision_ref']='Decision person@example.test'; $request['reason']='Reason person@example.test';
        $request['rates'][0]['scope_ref']='Scope person@example.test';
        $extra=$request['rates'][1]; $extra['component_id']=$component['id']; $extra['legal_basis']='other_documented'; $extra['scope_ref']='Extra person@example.test'; $request['rates'][]=$extra;
        $preview=(new \MyInvoice\Service\Property\Charges\ChargePreviewService($source))->preview($f['supplier'],$request);
        $input=['request'=>$request,'preview_hash'=>$preview['preview_hash'],'idempotency_key'=>'opaque-first'];
        $first=$repo->saveVersion($f['supplier'],$input,$f['actor']);
        $request['expected_revision']=1; $request['reason']='Correct person@example.test';
        $request['rates'][1]['amount']='-0.005'; $request['rates'][1]['correction']=true; $request['rates'][1]['correction_reason']='Correction person@example.test';
        $preview=(new \MyInvoice\Service\Property\Charges\ChargePreviewService($source))->preview($f['supplier'],$request);
        $second=$repo->saveVersion($f['supplier'],['request'=>$request,'preview_hash'=>$preview['preview_hash'],'idempotency_key'=>'opaque-second'],$f['actor']);
        $sourceHash=$this->fingerprint($source->pdo());
        $cloner=new DatabaseCloner($this->server); $plan=$cloner->plan($this->ownedSchema);
        $targetSchema=CloneSandbox::acquire($this->server,$this->ownedSchema); $this->targetSchema=$targetSchema;
        $config=Bootstrap::buildContainer()->get(Config::class); $data=$config->all(); $data['db']['name']=$targetSchema;
        $target=Connection::withoutSharedTestConnection(fn()=>new Connection(new Config($data,$config->dataDir()))); $this->connections[]=$target;
        $pdo=$target->pdo(); $pdo->exec("SET SESSION sql_mode='STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        $scrubber=new \MyInvoice\Service\Anonymization\TextScrubber(new \MyInvoice\Service\Anonymization\Pseudonymizer(str_repeat('synthetic-charge-',3)));
        $map=(new \MyInvoice\Service\Anonymization\PropertyCloneScrubber($pdo,$scrubber))->run();
        $helper=new \MyInvoice\Service\Anonymization\ChargesCloneScrubber($pdo,$scrubber);
        $pdo->exec('RENAME TABLE unit_charge_audit TO charge_clone_incomplete');
        try { $this->fails(fn()=>$helper->run($map),'complete schema'); }
        finally { $pdo->exec('RENAME TABLE charge_clone_incomplete TO unit_charge_audit'); }
        $row=$pdo->query('SELECT * FROM unit_charge_versions ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        $pdo->prepare('UPDATE unit_charge_versions SET preview_hash=? WHERE id=?')->execute([str_repeat('0',64),$row['id']]);
        $before=$this->fingerprint($pdo);
        $this->fails(fn()=>$helper->run($map),'canonical hash');
        self::assertSame($before,$this->fingerprint($pdo));
        $pdo->prepare('UPDATE unit_charge_versions SET preview_hash=? WHERE id=?')->execute([$row['preview_hash'],$row['id']]);
        $rate=$pdo->query('SELECT * FROM unit_charge_rates ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        $pdo->prepare('UPDATE unit_charge_rates SET calculation_json=? WHERE id=?')->execute(['{}',$rate['id']]);
        $before=$this->fingerprint($pdo); $this->fails(fn()=>$helper->run($map),'materialization'); self::assertSame($before,$this->fingerprint($pdo));
        $pdo->prepare('UPDATE unit_charge_rates SET calculation_json=? WHERE id=?')->execute([$rate['calculation_json'],$rate['id']]);
        $before=$this->fingerprint($pdo); $this->fails(fn()=>$helper->run([]),'revision proof'); self::assertSame($before,$this->fingerprint($pdo));
        $pdo->beginTransaction(); $this->fails(fn()=>$helper->run($map),'own transaction'); self::assertTrue($pdo->inTransaction()); $pdo->rollBack();
        $helper->run($map);
        $read=new \MyInvoice\Repository\Property\Charges\ChargeReadRepository($target);
        $a=$read->find($f['supplier'],$first['id']); $b=$read->find($f['supplier'],$second['id']);
        self::assertStringNotContainsString('person@example.test',json_encode([$a,$b],JSON_THROW_ON_ERROR));
        self::assertSame($a['preview_hash'],$b['preview']['previous_preview_hash']);
        foreach([[$first,$a],[$second,$b]] as [$old,$new]) {
            self::assertSame($old['preview']['calculation']['unit_totals'],$new['preview']['calculation']['unit_totals']);
            self::assertSame($old['request']['frequency'],$new['request']['frequency']);
            foreach($old['preview']['calculation']['rates'] as $i=>$entry) self::assertSame($entry['calculation'],$new['preview']['calculation']['rates'][$i]['calculation']);
        }
        $history=(new PropertyReadRepository($target))->find($f['supplier'],$f['units']['U01'],'2026-01-02T00:00:00Z')['history'];
        self::assertSame(['confirmed','pending','confirmed'],array_column($history,'status'));
        $cloner->finalize($plan,$targetSchema); $this->fails(fn()=>$helper->run($map),'before installing triggers');
        $repo=new \MyInvoice\Repository\Property\Charges\ChargeRepository($target);
        $anonymousComponent=$componentRequest; $anonymousComponent['name']=$scrubber->scrub($componentRequest['name']);
        self::assertSame($component['id'],$repo->createComponent($f['supplier'],$anonymousComponent,$f['actor'])['id']);
        $this->refused('property_charge_idempotency_conflict',fn()=>$repo->createComponent($f['supplier'],$componentRequest,$f['actor']));
        self::assertSame($a,$repo->saveVersion($f['supplier'],['request'=>$a['request'],'preview_hash'=>$a['preview_hash'],'idempotency_key'=>'opaque-first'],$f['actor']));
        $this->refused('property_charge_idempotency_conflict',fn()=>$repo->saveVersion($f['supplier'],$input,$f['actor']));
        $next=$b['request']; $next['expected_revision']=2;
        $preview=(new \MyInvoice\Service\Property\Charges\ChargePreviewService($target))->preview($f['supplier'],$next);
        self::assertSame(3,$repo->saveVersion($f['supplier'],['request'=>$next,'preview_hash'=>$preview['preview_hash'],'idempotency_key'=>'next'],$f['actor'])['revision_no']);
        $this->refused('property_charge_revision_conflict',fn()=>(new \MyInvoice\Service\Property\Charges\ChargePreviewService($target))->preview($f['supplier'],$next));
        self::assertSame($sourceHash,$this->fingerprint($source->pdo()));
    }
    private function fingerprint(PDO $pdo): string
    {
        $rows=[];
        foreach(['unit_charge_components','unit_charge_versions','unit_charge_rates','unit_charge_audit','property_revisions'] as $table) $rows[$table]=$pdo->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        return \MyInvoice\Service\Property\PropertyInput::hash($rows);
    }
    private function fails(callable $work,string $fragment): void
    {
        try { $work(); self::fail('Expected clone refusal'); } catch(\RuntimeException|\LogicException $e) { self::assertStringContainsString($fragment,$e->getMessage()); }
    }
    private function refused(string $code,callable $work): void
    {
        try { $work(); self::fail('Expected '.$code); }
        catch(\MyInvoice\Service\Property\Charges\ChargeException $e) { self::assertSame($code,$e->errorCode); }
    }
}
