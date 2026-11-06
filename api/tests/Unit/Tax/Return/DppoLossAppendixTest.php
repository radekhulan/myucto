<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tax\Return;

use MyInvoice\Service\Tax\Return\{DppoLossAppendix,DppoReturnCalculator,DppoXmlBuilder,TaxLossPeriodProof,TaxReturnException};
use MyInvoice\Service\Tax\TaxConstants;
use MyInvoice\Service\Validation\XmlSchemaValidator;
use PHPUnit\Framework\TestCase;

final class DppoLossAppendixTest extends TestCase
{
    private function loss(): array
    {
        $proof=TaxLossPeriodProof::native(1,'po',2024,['starts_on'=>'2024-01-01','ends_on'=>'2024-12-31'],500000,44);
        return ['id'=>7,'origin_year'=>2024,'origin_starts_on'=>$proof['starts_on'],'origin_ends_on'=>$proof['ends_on'],'period_proof_kind'=>$proof['kind'],'period_proof_hash'=>$proof['proof_hash'],'amount'=>'500000.00','source_return_id'=>44,'applied_before_period'=>'100000.00'];
    }

    public function testFrozenCardMapsExactColumnsAndPassesOfficialXsd(): void
    {
        $period=['starts_on'=>'2025-01-01','ends_on'=>'2025-12-31'];$card=DppoLossAppendix::build(1,'po',2025,$period,[$this->loss()],200000,0,5);
        $calc=(new DppoReturnCalculator())->compute(['vh'=>1000000,'period'=>$period],['loss_carryforward'=>200000],TaxConstants::forYear(2025));
        $xml=(new DppoXmlBuilder())->build($this->supplier(),2025,$calc,['zdobd_od'=>'01.01.2025','zdobd_do'=>'31.12.2025'],['loss_appendix'=>$card])['xml'];
        $dom=new \DOMDocument();self::assertTrue($dom->loadXML($xml));$v=$dom->getElementsByTagName('VetaV')->item(0);$i=$dom->getElementsByTagName('VetaI')->item(0);self::assertInstanceOf(\DOMElement::class,$v);self::assertInstanceOf(\DOMElement::class,$i);
        self::assertSame('01.01.2024',$v->getAttribute('pr1e_sl_1_od'));self::assertSame('31.12.2024',$v->getAttribute('pr1e_sl_1_do'));self::assertSame('500000',$v->getAttribute('pr1e_sl_2'));self::assertSame('100000',$v->getAttribute('pr1e_sl_3'));self::assertSame('200000',$v->getAttribute('pr1e_sl_4'));self::assertSame('200000',$v->getAttribute('pr1e_sl_5'));self::assertSame('200000',$i->getAttribute('pr1e_sl_4_celk'));self::assertSame('200000',$i->getAttribute('kc_dppc65_d85'));
        $validation=(new XmlSchemaValidator())->validate($xml,'dppdp9');self::assertSame('passed',$validation['status'],implode(' | ',$validation['errors']));
    }

    public function testFiscalLossAppendixStaysExplicitlyUnsupported(): void
    {
        $this->expectException(TaxReturnException::class);$this->expectExceptionMessage('kalendářních');DppoLossAppendix::build(1,'po',2025,['starts_on'=>'2025-07-01','ends_on'=>'2026-06-30'],[$this->loss()],200000,0,5);
    }

    public function testRehashedRowCannotForgeMissingNativePeriodProof(): void
    {
        $loss=$this->loss();$loss['period_proof_kind']='opening';$this->expectException(TaxReturnException::class);DppoLossAppendix::build(1,'po',2025,['starts_on'=>'2025-01-01','ends_on'=>'2025-12-31'],[$loss],200000,0,5);
    }

    public function testRehashedCardRejectsDuplicateLossAndImpossibleAmounts(): void
    {
        $card=DppoLossAppendix::build(1,'po',2025,['starts_on'=>'2025-01-01','ends_on'=>'2025-12-31'],[$this->loss()],200000,0,5);
        $card['rows'][]=$card['rows'][0];$card['rows'][1]['applied_cents']='0';$card['rows'][1]['remaining_cents']='40000000';$card['remaining_cents']='60000000';unset($card['proof_hash']);
        $card['proof_hash']=\MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules::hash($card);
        $this->expectException(TaxReturnException::class);DppoLossAppendix::verify($card,200000,0);
    }

    public function testRehashedCardCannotReplaceProvenCalendarInterval(): void
    {
        $card=DppoLossAppendix::build(1,'po',2025,['starts_on'=>'2025-01-01','ends_on'=>'2025-12-31'],[$this->loss()],200000,0,5);
        $card['rows'][0]['starts_on']='2024-02-01';unset($card['proof_hash']);$card['proof_hash']=\MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules::hash($card);
        $this->expectException(TaxReturnException::class);DppoLossAppendix::verify($card,200000,0);
    }

    public function testRehashedCardCannotChangeRowsFrozenInXml(): void
    {
        $period=['starts_on'=>'2025-01-01','ends_on'=>'2025-12-31'];$card=DppoLossAppendix::build(1,'po',2025,$period,[$this->loss()],200000,0,5);
        $calc=(new DppoReturnCalculator())->compute(['vh'=>1000000,'period'=>$period],['loss_carryforward'=>200000],TaxConstants::forYear(2025));$xml=(new DppoXmlBuilder())->build($this->supplier(),2025,$calc,['zdobd_od'=>'01.01.2025','zdobd_do'=>'31.12.2025'],['loss_appendix'=>$card])['xml'];
        $card['rows'][0]['starts_on']='2023-01-01';$card['rows'][0]['ends_on']='2023-12-31';$card['rows'][0]['amount_cents']='60000000';$card['rows'][0]['remaining_cents']='30000000';$card['remaining_cents']='30000000';unset($card['proof_hash']);$card['proof_hash']=\MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules::hash($card);
        DppoLossAppendix::verify($card,200000,0);$dom=new \DOMDocument();self::assertTrue($dom->loadXML($xml));$this->expectException(TaxReturnException::class);DppoLossAppendix::assertXml($card,new \DOMXPath($dom));
    }

    private function supplier():array
    {
        return['id'=>1,'company_name'=>'Synthetic Loss s.r.o.','street'=>'Testovací 1','city'=>'Vzorov','zip'=>'100 00','country_iso2'=>'CZ','ic'=>'12345678','dic'=>'CZ12345678','taxpayer_type'=>'po','financial_office_code'=>'451','cz_nace_code'=>'94990'];
    }
}
