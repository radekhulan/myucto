<?php

declare(strict_types=1);
namespace MyInvoice\Tests\Unit\Tax\Nonprofit;
use MyInvoice\Service\Accounting\Reports\Statement504Builder;
use MyInvoice\Service\Accounting\Reports\Statement504Catalog;
use MyInvoice\Service\Tax\Nonprofit\NonprofitDppoStatementRows;
use MyInvoice\Service\Tax\Return\TaxReturnException;
use PHPUnit\Framework\TestCase;
final class NonprofitDppoStatementRowsTest extends TestCase
{
    private function statements(array $profit,array $balances):array
    {
        $meta=['accounting_framework'=>'cz_504','version_code'=>Statement504Catalog::VERSION,'scope'=>'full','period'=>['id'=>1,'starts_on'=>'2026-01-01','ends_on'=>'2026-12-31']];$b=new Statement504Builder();return[$meta+$b->balanceSheet($balances,$profit,'full'),$meta+$b->incomeStatement($profit,'full')];
    }
    public function testNativeCatalogNumbersAndDependentRoundingReconcileEveryColumn():void
    {
        [$bs,$pl]=$this->statements([['code'=>'602','activity'=>'main','balance_cents'=>-100060],['code'=>'602','activity'=>'economic','balance_cents'=>-100060],['code'=>'518','activity'=>'main','balance_cents'=>60050]],[['code'=>'221','opening_cents'=>0,'closing_cents'=>140070]]);
        $r=(new NonprofitDppoStatementRows())->build($bs,$pl);self::assertCount(count(Statement504Catalog::rows('UR')),$r['UR']);self::assertCount(count(Statement504Catalog::rows('US')),$r['US']);self::assertCount(count(Statement504Catalog::rows('UT')),$r['UT']);$ur=array_column($r['UR'],null,'c_radku');$us=array_column($r['US'],null,'c_radku');$ut=array_column($r['UT'],null,'c_radku');
        self::assertSame($ut[63]['kc_celkem'],$us[7]['kc_posledni']);self::assertSame($ur[85]['kc_posledni'],$us[49]['kc_posledni']);self::assertSame($ur[85]['kc_prvni'],$us[49]['kc_prvni']);self::assertSame($ut[61]['kc_celkem']-$ut[39]['kc_celkem'],$ut[63]['kc_celkem']);
        foreach($ut as$row)self::assertSame($row['kc_celkem'],$row['kc_hlav']+$row['kc_hosp']);
        self::assertArrayNotHasKey('kc_prvni',$us[7]);self::assertArrayNotHasKey('kc_posledni',$us[8]);
    }
    public function testActualUrUsUtElementsValidateAgainstNativeDppdp9Xsd():void
    {
        [$bs,$pl]=$this->statements([],[]);$formatter=new NonprofitDppoStatementRows();$rows=$formatter->build($bs,$pl);
        [$dom,$root]=\MyInvoice\Service\Report\EpoEnvelope::create('DPPDP9','09.01','test');$header=$dom->createElement('VetaD');
        foreach(['typ_dapdpp'=>'A','typ_zo'=>'A','typ_popldpp'=>'3','c_ufo_cil'=>'451','zdobd_od'=>'01.01.2026','zdobd_do'=>'31.12.2026','dokument'=>'DP9','dapdpp_forma'=>'B','k_uladis'=>'DPP','uv_vyhl'=>'504']as$key=>$value)$header->setAttribute($key,$value);
        $root->appendChild($header);$root->appendChild($dom->createElement('VetaO'));$formatter->append($dom,$root,$rows);
        $validation=(new \MyInvoice\Service\Validation\XmlSchemaValidator())->validate($dom->saveXML(),'dppdp9');self::assertSame('passed',$validation['status'],implode(' ',$validation['errors']));
    }
    public function testMissingCatalogRowFailsInsteadOfGeneratingIncompleteAppendix():void
    {
        [$bs,$pl]=$this->statements([],[]);array_pop($pl['rows']);$this->expectException(TaxReturnException::class);(new NonprofitDppoStatementRows())->build($bs,$pl);
    }
    public function testMixedPeriodStatementsCannotBeCombined():void
    {
        [$bs,$pl]=$this->statements([],[]);$pl['period']['id']=2;$this->expectException(TaxReturnException::class);(new NonprofitDppoStatementRows())->build($bs,$pl);
    }
}
