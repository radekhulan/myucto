<?php

declare(strict_types=1);
namespace MyInvoice\Tests\Unit\Tax\Nonprofit;
use MyInvoice\Action\Codebook\TaxConstantsAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\TaxConstantsRepository;
use MyInvoice\Service\Tax\Nonprofit\{NonprofitDppoReduction,NonprofitDppoStoredProof};
use MyInvoice\Service\Tax\Return\{DppoReturnCalculator,TaxReturnException};
use MyInvoice\Service\Tax\TaxConstants;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
final class NonprofitAnnualConstantsTest extends TestCase
{
    private function repository():array
    {
        $pdo=new PDO('sqlite::memory:',options:[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$pdo->exec('CREATE TABLE tax_constants(year INTEGER PRIMARY KEY,data TEXT NOT NULL)');$db=new Connection(new \MyInvoice\Infrastructure\Config\Config([]));(new \ReflectionProperty($db,'pdo'))->setValue($db,$pdo);return[$pdo,new TaxConstantsRepository($db)];
    }
    private function data(array$inputs):array
    {return(new \ReflectionMethod(NonprofitDppoCalculatorTest::class,'data'))->invoke(new NonprofitDppoCalculatorTest('testReductionAboveLimitRejectedInsteadOfClipped'),$inputs);}
    private function override():array
    {return['nonprofit_reduction_rate'=>0.25,'nonprofit_reduction_min'=>400000,'nonprofit_reduction_max'=>500000,'nonprofit_reduction_university_min'=>1500000,'nonprofit_reduction_university_max'=>4000000];}
    public function testExactYearOverrideControlsNativeCalculationAndSurvivesAsFrozenProof():void
    {
        [$pdo,$repo]=$this->repository();$pdo->prepare('INSERT INTO tax_constants(year,data)VALUES(?,?)')->execute([2026,json_encode($this->override(),JSON_THROW_ON_ERROR)]);$constants=$repo->forExactYear(2026);$inputs=['nonprofit'=>['reduction_kc'=>400000,'reduction_evidence'=>'Synthetic annual override']];$data=$this->data($inputs);$calc=new DppoReturnCalculator();$computed=$calc->compute($data,$inputs,$constants);self::assertSame(400000,$computed['nonprofit']['reduction_limit_kc']);self::assertSame(84000.0,$computed['tax']);$record=NonprofitDppoStoredProof::capture($data,$inputs,$constants,[]);
        $pdo->prepare('UPDATE tax_constants SET data=? WHERE year=2026')->execute([json_encode(array_replace($this->override(),['nonprofit_reduction_min'=>0,'nonprofit_reduction_max'=>50000]),JSON_THROW_ON_ERROR)]);$current=$repo->forExactYear(2026);self::assertSame(50000,NonprofitDppoReduction::limit(800000,'standard',$current));NonprofitDppoStoredProof::assertCalculation($computed,$record);self::assertSame(400000,$record['constants']['nonprofit_reduction_min']);
        try{$calc->compute($data,$inputs,$current);self::fail('Current lower annual ceiling must reject the same new election');}catch(TaxReturnException$e){self::assertSame('nonprofit_dppo_reduction_exceeded',$e->errorCode);}
    }
    public function testRateAndUniversityBandUseTheirAnnualValues():void
    {
        $c=array_replace(TaxConstants::forYear(2026),$this->override(),['nonprofit_reduction_min'=>0,'nonprofit_reduction_max'=>2000000,'nonprofit_reduction_rate'=>0.4]);self::assertSame(1200000,NonprofitDppoReduction::limit(3000000,'standard',$c));self::assertSame(1500000,NonprofitDppoReduction::limit(3000000,'university_research',$c));self::assertSame(0,NonprofitDppoReduction::limit(3000000,'ineligible',$c));self::assertSame(999,NonprofitDppoReduction::limit(999,'standard',$this->override()));
    }
    public function testRateKeepsStoredPrecisionAndAcceptsScientificNotation():void
    {
        $c=array_replace(TaxConstants::forYear(2026),['nonprofit_reduction_min'=>0,'nonprofit_reduction_rate'=>0.9999999999999999]);self::assertSame(99,NonprofitDppoReduction::limit(100,'standard',$c));
        $c['nonprofit_reduction_rate']=1.0e-7;self::assertSame(10,NonprofitDppoReduction::limit(100000000,'standard',$c));
    }
    public function testAllKnownYearsContainVerifiedReductionBandsAndLegacyOverridesMerge():void
    {
        foreach(TaxConstants::availableYears()as$year){$c=TaxConstants::forYear($year);foreach(['nonprofit_reduction_rate'=>0.30,'nonprofit_reduction_min'=>300000,'nonprofit_reduction_max'=>1000000,'nonprofit_reduction_university_min'=>1000000,'nonprofit_reduction_university_max'=>3000000]as$key=>$expected)self::assertSame($expected,$c[$key],$year.':'.$key);}
        [$pdo,$repo]=$this->repository();$pdo->exec("INSERT INTO tax_constants VALUES(2025,'{\"credit_taxpayer\":99999}')");self::assertSame(300000,$repo->forExactYear(2025)['nonprofit_reduction_min']);$this->expectException(\OutOfRangeException::class);$repo->forExactYear(2099);
    }
    public function testAdminBoundsCarrybackPeriodCountWithoutChangingMonetaryLimit():void
    {
        $action=(new \ReflectionClass(TaxConstantsAction::class))->newInstanceWithoutConstructor();$validate=new \ReflectionMethod($action,'validate');$d=array_replace(TaxConstants::forYear(2026),$this->override());
        foreach([0,100]as$years){$d['tax_loss_carryback_years']=$years;self::assertNull($validate->invoke($action,$d,2026));}
        $d['tax_loss_carryback_years']=101;self::assertNotNull($validate->invoke($action,$d,2026));
        $d['tax_loss_carryback_years']=2;$d['tax_loss_carryback_limit']=99999999999999;self::assertNull($validate->invoke($action,$d,2026));
    }
    public static function invalid():array
    {return[['nonprofit_reduction_rate',true],['nonprofit_reduction_rate',NAN],['nonprofit_reduction_rate',1.01],['nonprofit_reduction_min',false],['nonprofit_reduction_min',-1],['nonprofit_reduction_min',1.5],['nonprofit_reduction_min',600000],['nonprofit_reduction_max',INF],['nonprofit_reduction_university_min',5000000],['tax_loss_carryback_years',-1],['tax_loss_carryback_years',true],['tax_loss_carryback_years',1.5],['tax_loss_carryback_limit',-1],['tax_loss_carryback_limit',INF]];}
    #[DataProvider('invalid')]
    public function testAdminRejectsInvalidAnnualNumbers(string$key,mixed$value):void
    {$d=array_replace(TaxConstants::forYear(2026),$this->override(),[$key=>$value]);$action=(new \ReflectionClass(TaxConstantsAction::class))->newInstanceWithoutConstructor();self::assertNotNull((new \ReflectionMethod($action,'validate'))->invoke($action,$d,2026),$key);}
}
