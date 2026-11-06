<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Property\Charges;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Charges\ChargeRepository;
use MyInvoice\Repository\Property\Charges\ChargeReadRepository;
use MyInvoice\Repository\Property\PropertyRegistryRepository;
use MyInvoice\Service\Property\Charges\ChargeException;
use MyInvoice\Service\Property\Charges\ChargePreviewService;
use MyInvoice\Tests\Support\PropertyChargeFixture;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class ChargeVersionTest extends TestCase
{
    use PropertyChargeFixture;
    private Connection $db; private array $f;
    protected function setUp(): void
    {
        $this->db=Bootstrap::buildContainer()->get(Connection::class);
        self::assertStringEndsWith('_test',(string)$this->db->pdo()->query('SELECT DATABASE()')->fetchColumn());
        $this->db->pdo()->beginTransaction(); $this->f=$this->chargeFixture($this->db);
    }
    protected function tearDown(): void { if($this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack(); }
    public function testTwelveUnitImmutableScheduleAndRetry(): void
    {
        $f=$this->f; $request=self::chargeRequest($f); $preview=(new ChargePreviewService($this->db))->preview($f['supplier'],$request);
        self::assertSame('26400000',$preview['preview']['calculation']['total_cents']);
        $amounts=array_column($preview['preview']['calculation']['unit_totals'],'amount_cents','unit_id');
        self::assertCount(12,$amounts); self::assertSame('2380000',$amounts[$f['units']['U01']]); self::assertSame('340000',$amounts[$f['units']['G12']]);
        $repo=new ChargeRepository($this->db); $input=['request'=>$request,'preview_hash'=>$preview['preview_hash'],'idempotency_key'=>'schedule'];
        $saved=$repo->saveVersion($f['supplier'],$input,$f['actor']);
        self::assertSame($saved,$repo->saveVersion($f['supplier'],$input,$f['actor']));
        self::assertCount(3,$saved['preview']['calculation']['rates']);
        self::assertSame('service_advance_notice_before_issue',$saved['preview']['calculation']['issuance_facts'][0]['code']);
        $input['request']['reason']='Changed same token'; $this->refused('property_charge_idempotency_conflict',fn()=>$repo->saveVersion($f['supplier'],$input,$f['actor']));
        foreach(['unit_charge_versions','unit_charge_rates','unit_charge_components'] as $table) {
            try { $this->db->pdo()->exec('DELETE FROM '.$table.' WHERE supplier_id='.$f['supplier']); self::fail('Immutable history deleted'); }
            catch(\PDOException $e) { self::assertSame('45000',(string)$e->getCode()); }
        }
        self::assertSame(1,count((new ChargeReadRepository($this->db))->versions($f['supplier'],$f['house']['id'])));
        $stmt=$this->db->pdo()->prepare('SELECT COUNT(*) FROM unit_charge_audit WHERE supplier_id=?');$stmt->execute([$f['supplier']]);self::assertSame(4,(int)$stmt->fetchColumn());
        $stmt=$this->db->pdo()->prepare('SELECT COUNT(*) FROM journal_entries WHERE supplier_id=?');$stmt->execute([$f['supplier']]);self::assertSame(0,(int)$stmt->fetchColumn());
    }
    public function testRegistryChangeInvalidatesPreviewWithoutMultiplyingOwnership(): void
    {
        $f=$this->f; $request=self::chargeRequest($f); $service=new ChargePreviewService($this->db);$old=$service->preview($f['supplier'],$request);
        $change=self::registryRequest('unused',$f['states']['U01']);unset($change['code']);$change['expected_revision']=1;$change['idempotency_key']='owner-change';
        $change['state']['ownerships']=[['group_key'=>'NEW','kind'=>'single','share'=>['num'=>'1','den'=>'1'],'members'=>[$f['clients'][18]],'title_ref'=>'Synthetic transfer']];
        (new PropertyRegistryRepository($this->db))->revise($f['supplier'],$f['units']['U01'],'unit',$change,$f['actor']);
        $this->refused('property_charge_preview_stale',fn()=>(new ChargeRepository($this->db))->saveVersion($f['supplier'],['request'=>$request,'preview_hash'=>$old['preview_hash'],'idempotency_key'=>'stale'],$f['actor']));
        $new=$service->preview($f['supplier'],$request);
        self::assertNotSame($old['preview_hash'],$new['preview_hash']);
        self::assertSame($old['preview']['calculation']['unit_totals'],$new['preview']['calculation']['unit_totals']);
        self::assertSame([],(new ChargeReadRepository($this->db))->versions($f['supplier'],$f['house']['id']));
    }
    public function testMidmonthCorrectionHasExplicitSnapshotAndFrozenPreviousDifference(): void
    {
        $f=$this->f;$request=self::chargeRequest($f);$service=new ChargePreviewService($this->db);$repo=new ChargeRepository($this->db);$first=$service->preview($f['supplier'],$request);
        $saved=$repo->saveVersion($f['supplier'],['request'=>$request,'preview_hash'=>$first['preview_hash'],'idempotency_key'=>'first'],$f['actor']);
        $request['expected_revision']=1;$request['effective_from']=$request['basis_at']='2026-06-15T12:30:00+02:00';
        $request['rates'][1]['amount']='-0.005';$request['rates'][1]['correction']=true;$request['rates'][1]['correction_reason']='Synthetic explicitly approved correction';
        $second=$service->preview($f['supplier'],$request);
        self::assertSame($saved['id'],$second['preview']['overlapping_versions'][0]['version_id']);
        self::assertSame('2026-06-15T10:30:00.000000Z',$second['preview']['overlapping_versions'][0]['from']);
        self::assertSame('-6',$second['preview']['calculation']['rates'][1]['calculation']['total_cents']);
        self::assertSame('25199994',$second['preview']['calculation']['total_cents']);
        $repo->saveVersion($f['supplier'],['request'=>$request,'preview_hash'=>$second['preview_hash'],'idempotency_key'=>'second'],$f['actor']);
        $read=new ChargeReadRepository($this->db);
        self::assertSame($saved,$read->at($f['supplier'],$f['house']['id'],'2026-06-15T12:29:59+02:00'));
        self::assertSame(2,$read->at($f['supplier'],$f['house']['id'],'2026-06-15T12:30:00+02:00')['revision_no']);
    }
    public function testMissingUnitBudgetAndForeignComponentRefused(): void
    {
        $f=$this->f;$request=self::chargeRequest($f);
        $request['rates'][0]['unit_ids'][]=PHP_INT_MAX;
        $this->refused('property_charge_unit_unavailable',fn()=>(new ChargePreviewService($this->db))->preview($f['supplier'],$request));
        $request=self::chargeRequest($f);$request['rates'][0]['component_id']=PHP_INT_MAX;
        $this->refused('property_charge_component_missing',fn()=>(new ChargePreviewService($this->db))->preview($f['supplier'],$request));
        $this->refused('property_charge_not_found',fn()=>(new ChargeReadRepository($this->db))->find($f['supplier'],PHP_INT_MAX));
    }
    public function testActualTenantKeysAndDisabledProfileHistory(): void
    {
        $f=$this->f;$other=$this->chargeFixture($this->db);$request=self::chargeRequest($f);
        $request['rates'][0]['component_id']=$other['components']['LONG'];
        $this->refused('property_charge_component_missing',fn()=>(new ChargePreviewService($this->db))->preview($f['supplier'],$request));
        $request=self::chargeRequest($f);$preview=(new ChargePreviewService($this->db))->preview($f['supplier'],$request);
        $saved=(new ChargeRepository($this->db))->saveVersion($f['supplier'],['request'=>$request,'preview_hash'=>$preview['preview_hash'],'idempotency_key'=>'tenant-safe'],$f['actor']);
        try {
            $this->db->pdo()->prepare("INSERT INTO unit_charge_rates (supplier_id,version_id,component_id,rate_json,calculation_json) VALUES (?,?,?,'{}','{}')")
                ->execute([$other['supplier'],$saved['id'],$other['components']['LONG']]);
            self::fail('Cross-tenant version binding accepted');
        } catch(\PDOException $e) { self::assertSame('23000',(string)$e->getCode()); }
        $this->refused('property_charge_not_found',fn()=>(new ChargeReadRepository($this->db))->find($other['supplier'],$saved['id']));
        (new \MyInvoice\Repository\Property\PropertyProfileRepository($this->db))->save($f['supplier'],['expected_revision'=>1,'effective_from'=>'2026-07-01T00:00:00+02:00','enabled'=>false,'reason'=>'Synthetic cessation','title_ref'=>'Synthetic deed','idempotency_key'=>'off'],$f['actor']);
        self::assertSame($saved,(new ChargeReadRepository($this->db))->at($f['supplier'],$f['house']['id'],'2026-08-01T00:00:00+02:00'));
        $request['expected_revision']=1;$request['basis_at']=$request['effective_from']='2026-08-01T00:00:00+02:00';
        $this->refused('property_charge_profile_required',fn()=>(new ChargePreviewService($this->db))->preview($f['supplier'],$request));
    }

    public function testCorruptRequestCannotChangeHistoricalDecisionBehindIntactPreview(): void
    {
        $f=$this->f;$request=self::chargeRequest($f);$preview=(new ChargePreviewService($this->db))->preview($f['supplier'],$request);
        $saved=(new ChargeRepository($this->db))->saveVersion($f['supplier'],['request'=>$request,'preview_hash'=>$preview['preview_hash'],'idempotency_key'=>'corruption-proof'],$f['actor']);
        $stmt=$this->db->pdo()->prepare('SELECT * FROM unit_charge_versions WHERE id=?');$stmt->execute([$saved['id']]);$row=$stmt->fetch(\PDO::FETCH_ASSOC);
        $request=json_decode($row['request_json'],true);$request['reason']='Unrelated corrupt decision';$row['request_json']=json_encode($request,JSON_THROW_ON_ERROR);
        $decode=new \ReflectionMethod(ChargeReadRepository::class,'decode');
        $this->refused('property_charge_history_corrupt',fn()=>$decode->invoke(new ChargeReadRepository($this->db),$row));
    }

    private function refused(string $code,callable $work): void
    {
        try { $work(); self::fail('Expected '.$code); } catch(ChargeException $e) { self::assertSame($code,$e->errorCode); }
    }
}
