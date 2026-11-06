<?php
declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Property;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Charges\ChargeRepository;
use MyInvoice\Repository\Property\Charges\Issuance\IssuanceRepository;
use MyInvoice\Repository\Property\Receivables\{FinancialHistoryRepository,PartyEventRepository};
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Property\Charges\ChargePreviewService;
use MyInvoice\Service\Property\Charges\Issuance\IssuancePreviewService;
use MyInvoice\Service\Property\Receivables\{PropertyReconciliation,PropertyReceivableProjection};
use MyInvoice\Tests\Support\ChargeIssuanceFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PropertyReconciliationScopeTest extends TestCase
{
    use ChargeIssuanceFixture;
    private Connection $db;
    private array $f;

    protected function setUp():void
    {
        $this->db=Bootstrap::buildContainer()->get(Connection::class);
        self::assertStringEndsWith('_test',(string)getenv('MYINVOICE_DB_NAME'));
        self::assertSame(getenv('MYINVOICE_DB_NAME'),$this->db->pdo()->query('SELECT DATABASE()')->fetchColumn());
        $this->db->pdo()->beginTransaction();$this->f=$this->issuanceFixture($this->db);
    }
    protected function tearDown():void{if($this->db->pdo()->inTransaction())$this->db->pdo()->rollBack();}

    public static function accountingScopes():array
    {
        return ['other unit'=>['U02','2026-02-01',true,false],
            'future same unit'=>['G12','2026-03-01',true,true],
            'relevant same unit'=>['G12','2026-02-01',false,false],
            'future other unit'=>['U02','2026-03-01',true,true]];
    }
    #[DataProvider('accountingScopes')]
    public function testReconciliationIncludesOnlyUnresolvedChargesOfItsUnitAndAccountingDate(string $negativeUnit,string $negativeDate,bool $unitMatches,bool $allMatches):void
    {
        $fixture=$this->negativeCharge($negativeUnit,$negativeDate);
        $reconciliation=new PropertyReconciliation($this->db);$supplier=$this->f['supplier'];
        $unit=$reconciliation->at($supplier,'2026-02-28',$this->f['units']['G12']);
        self::assertSame($unitMatches,$unit['matches'],json_encode($unit,JSON_THROW_ON_ERROR));
        self::assertSame(['receivable'=>$unitMatches?'0':'-10000','credit'=>'0'],$unit['difference_cents']);
        $all=$reconciliation->at($supplier,'2026-02-28');
        self::assertSame($allMatches,$all['matches'],json_encode($all,JSON_THROW_ON_ERROR));
        self::assertSame(['receivable'=>$allMatches?'0':'-10000','credit'=>'0'],$all['difference_cents']);
        $affected=$reconciliation->at($supplier,$negativeDate,$this->f['units'][$negativeUnit]);
        self::assertFalse($affected['matches']);self::assertSame('-10000',$affected['difference_cents']['receivable']);
        $history=(new FinancialHistoryRepository($this->db))->read($supplier,true);
        self::assertSame($fixture['line_ids'],$history['unresolved_negative_charge_line_ids']);
    }

    public function testRelevantUnknownStillBlocksEvenWhenAnIndependentEntryMakesLedgerTotalsEqual():void
    {
        $this->negativeCharge('G12','2026-02-01');
        $this->manualReceivable('G12','100.00');
        foreach([$this->f['units']['G12'],null] as $unitId){
            $result=(new PropertyReconciliation($this->db))->at($this->f['supplier'],'2026-02-28',$unitId);
            self::assertSame(['receivable'=>'0','credit'=>'0'],$result['difference_cents']);
            self::assertFalse($result['matches'],'Equal totals do not resolve an unanchored negative charge.');
        }
    }

    public function testIgnoringAnotherUnitsBlockerDoesNotHideAnActualLedgerMismatch():void
    {
        $this->negativeCharge('U02','2026-02-01');
        $this->manualReceivable('G12','1.00');
        $result=(new PropertyReconciliation($this->db))->at($this->f['supplier'],'2026-02-28',$this->f['units']['G12']);
        self::assertSame(['receivable'=>'100','credit'=>'0'],$result['difference_cents']);self::assertFalse($result['matches']);
    }

    public function testProjectionScopesUnknownCoverageByUnitEffectiveTimeAndPostingKnowledge():void
    {
        $fixture=$this->negativeCharge('U02','2026-02-01');
        $projection=new PropertyReceivableProjection($this->db);$supplier=$this->f['supplier'];
        $scopes=[[],['unit_id'=>$this->f['units']['U02']],['unit_id'=>$this->f['units']['G12']],
            ['client_id'=>$this->f['clients'][1]],['unit_id'=>$this->f['units']['G12'],'client_id'=>$this->f['clients'][1]]];
        foreach($scopes as $scope){
            $before=$projection->at($supplier,'2026-02-28T23:59:59+01:00',$fixture['known_before'],$scope,true);
            self::assertSame([],$before['coverage']['unresolved_negative_charge_line_ids']);
            self::assertTrue($before['coverage']['financial_complete']);
            $beforeOrigin=$projection->at($supplier,'2025-12-31T23:59:59+01:00',$fixture['known_after'],$scope,true);
            self::assertSame([],$beforeOrigin['coverage']['unresolved_negative_charge_line_ids']);
            self::assertTrue($beforeOrigin['coverage']['financial_complete']);
            $current=$projection->at($supplier,'2026-02-28T23:59:59+01:00',$fixture['known_after'],$scope,true);
            $unaffected=($scope['unit_id']??null)===$this->f['units']['G12'];
            self::assertSame($unaffected?[]:$fixture['line_ids'],$current['coverage']['unresolved_negative_charge_line_ids']);
            self::assertSame($unaffected,$current['coverage']['financial_complete']);
            $atOrigin=$projection->at($supplier,'2026-01-01T00:00:00+01:00',$fixture['known_after'],$scope,true);
            self::assertSame($unaffected?[]:$fixture['line_ids'],$atOrigin['coverage']['unresolved_negative_charge_line_ids']);
            if($unaffected)self::assertIsString($current['totals']['gross_receivable_cents']);
            else self::assertNull($current['totals']['gross_receivable_cents']);
        }
        $history=(new FinancialHistoryRepository($this->db))->read($supplier,true);
        self::assertSame($fixture['line_ids'],$history['unresolved_negative_charge_line_ids']);
    }

    public function testFutureAccountingDateDoesNotReplaceTheDocumentedEarlierClaimOrigin():void
    {
        $fixture=$this->negativeCharge('G12','2026-03-01');$supplier=$this->f['supplier'];
        $reconciliation=(new PropertyReconciliation($this->db))->at($supplier,'2026-02-28',$this->f['units']['G12']);
        self::assertSame(['receivable'=>'0','credit'=>'0'],$reconciliation['difference_cents']);self::assertTrue($reconciliation['matches']);
        $projection=(new PropertyReceivableProjection($this->db))->at($supplier,'2026-02-28T23:59:59+01:00',$fixture['known_after'],['unit_id'=>$this->f['units']['G12']],true);
        self::assertSame($fixture['line_ids'],$projection['coverage']['unresolved_negative_charge_line_ids']);
        self::assertFalse($projection['coverage']['financial_complete']);
        self::assertSame('2025-12-31T23:00:00.000000Z',$fixture['negative']['snapshot']['claim_origin_at']);
    }

    private function negativeCharge(string $unitCode,string $date):array
    {
        $supplier=$this->f['supplier'];$actor=$this->f['actor'];$container=$this->container();
        $repository=$container->get(IssuanceRepository::class);$request=$this->f['issue_request'];
        $preview=(new IssuancePreviewService($this->db))->preview($supplier,$request);
        $initial=$repository->issue($supplier,['request'=>$request,'preview_hash'=>$preview['preview_hash'],'idempotency_key'=>'initial'],$actor);
        $initial=$repository->post($supplier,$initial['id'],array_map(fn($c)=>['id'=>$c['id'],'row_version'=>$c['row_version']],$initial['charges']),$actor,\MyInvoice\Repository\Property\Receivables\ChargeDebtRegistrar::MODE_MANUAL);
        $original=array_values(array_filter($initial['charges'],fn($c)=>$c['unit_id']===$this->f['units'][$unitCode]))[0];
        $knownBefore=PartyEventRepository::now();
        $version=self::chargeRequest($this->f);$version['expected_revision']=1;
        foreach($version['rates'] as &$rate)if($rate['component_id']===$this->f['components']['ADMIN'])$rate['amount']='900';unset($rate);
        $versionPreview=(new ChargePreviewService($this->db))->preview($supplier,$version);
        $saved=(new ChargeRepository($this->db))->saveVersion($supplier,['request'=>$version,'preview_hash'=>$versionPreview['preview_hash'],'idempotency_key'=>'reduced-version'],$actor);
        $request['version_id']=$saved['id'];$request['issue_mode']='difference';$request['accounting_date']=$date;$request['issued_at']=$date.'T10:00:00+01:00';
        $request['units']=array_values(array_filter($request['units'],fn($u)=>$u['unit_id']===$original['unit_id']));
        $request['units'][0]['expected_revision']=1;$request['units'][0]['previous_charge_id']=$original['id'];
        $preview=(new IssuancePreviewService($this->db))->preview($supplier,$request);self::assertSame('-10000',$preview['snapshot']['total_cents']);
        $batch=$repository->issue($supplier,['request'=>$request,'preview_hash'=>$preview['preview_hash'],'idempotency_key'=>'unanchored-negative'],$actor);
        $batch=$repository->post($supplier,$batch['id'],array_map(fn($c)=>['id'=>$c['id'],'row_version'=>$c['row_version']],$batch['charges']),$actor);
        $negative=$batch['charges'][0];self::assertSame('posted',$negative['status']);
        $q=$this->db->pdo()->prepare('SELECT id FROM unit_charge_lines WHERE supplier_id=? AND charge_id=? AND amount_cents<0 ORDER BY id');$q->execute([$supplier,$negative['id']]);
        $ids=array_map('intval',$q->fetchAll(\PDO::FETCH_COLUMN));self::assertCount(1,$ids);
        $q=$this->db->pdo()->prepare('SELECT COUNT(*) FROM unit_payment_corrections WHERE supplier_id=?');$q->execute([$supplier]);self::assertSame(0,(int)$q->fetchColumn());
        return ['negative'=>$negative,'line_ids'=>$ids,'known_before'=>$knownBefore,'known_after'=>PartyEventRepository::now()];
    }
    private function manualReceivable(string $unitCode,string $amount):void
    {
        $q=$this->db->pdo()->prepare('SELECT v.type_id,v.id FROM property_objects o JOIN dimension_values v ON v.supplier_id=o.supplier_id AND v.id=o.dimension_value_id WHERE o.supplier_id=? AND o.id=?');
        $q->execute([$this->f['supplier'],$this->f['units'][$unitCode]]);$dimension=$q->fetch(\PDO::FETCH_ASSOC);
        $dimensions=[(int)$dimension['type_id']=>(int)$dimension['id']];
        $this->container()->get(PostingService::class)->postDocument($this->f['supplier'],'manual',null,
            [['account_code'=>'315','side'=>'debit','amount'=>$amount,'dimensions'=>$dimensions],['account_code'=>'324','side'=>'credit','amount'=>$amount,'dimensions'=>$dimensions]],
            ['entry_date'=>'2026-02-02','posted_by'=>$this->f['actor'],'description'=>'Synthetic independent ledger adjustment']);
    }
    private function container():\DI\Container{$container=Bootstrap::buildContainer();$container->set(Connection::class,$this->db);return $container;}
}
