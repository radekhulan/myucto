<?php

declare(strict_types=1);
namespace MyInvoice\Tests\Unit\Property\Catalog;
use MyInvoice\Service\Property\Catalog\PropertyAccountingProjection as P;
use MyInvoice\Service\Property\PropertyException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
final class PropertyAccountingProjectionTest extends TestCase
{
    private const PRIVATE='private-catalog-sentinel@example.invalid';
    public static function examples():array
    {
        $private=['actor'=>7,'evidence'=>self::PRIVATE,'request'=>['payload'=>self::PRIVATE]];
        return [
            'lifecycle'=>['lifecycle',['case'=>['id'=>1,'unit_id'=>2,'service_id'=>3,'period_from'=>'2026-01-01','period_to'=>'2026-12-31','policy'=>'service_balance_sheet','service_cost_account'=>'315.210']+$private,'estimate'=>['event_id'=>4,'request'=>$private,'receipt'=>['amount_cents'=>'1100000','sources'=>[$private]]+$private],'unwinds'=>[],'final'=>null,'pending'=>[]]+$private],
            'service reconciliation'=>['reconciliation',['unit_id'=>2,'service_id'=>3,'accounting_date'=>'2026-12-31','period_from'=>'2026-01-01','known_at'=>null,'knowledge_basis'=>'current_ledger','view'=>'before_closing','coverage'=>'incomplete','matches'=>false,'totals'=>['315.210'=>'-1'],'ledger_totals'=>['315.210'=>'0'],'unit_service_balances'=>[2=>[3=>['315.210'=>'-1']]],'ledger_unit_service_balances'=>[],'differences'=>[2=>[3=>['315.210'=>'1']]],'component_totals'=>[],'incomplete'=>[['code'=>'lifecycle_pending','case_id'=>1]+$private],'unregistered_line_ids'=>[],'verified_carry_entry_ids'=>[],'source_line_ids'=>[5]]+$private],
            'funding operation'=>['operation',['id'=>8,'house_id'=>9,'kind'=>'income','accounting_date'=>'2026-01-01','journal_entry_id'=>10,'reversal_of'=>null,'active'=>true,'request'=>['amount_cents'=>'100']+$private,'receipt'=>['effects'=>[['id'=>11,'unit_id'=>2,'account_code'=>'379.920','source_kind'=>null,'source_id'=>null,'amount_cents'=>'100','parties'=>$private]]]+$private,'snapshot'=>$private]+$private],
            'funding balances'=>['balances',['house_id'=>null,'unit_id'=>null,'accounting_date'=>'2026-12-31','known_at'=>null,'knowledge_basis'=>'current_ledger','coverage'=>'typed_opening_issued_and_funding_events','matches'=>true,'totals'=>['955.100'=>'100'],'ledger_totals'=>['955.100'=>'100'],'unit_balances'=>[2=>['955.100'=>'100']],'differences'=>[2=>['955.100'=>'0']],'component_totals'=>[],'unregistered_line_ids'=>[],'sources'=>[$private],'income_lots'=>[$private]]+$private],
        ];
    }
    #[DataProvider('examples')]
    public function testEachProjectionDropsUnexpectedPrivateGraphs(string $method,array $input):void
    {
        $out=P::$method($input);$json=json_encode($out,JSON_THROW_ON_ERROR);self::assertStringNotContainsString(self::PRIVATE,$json);
        $check=static function(mixed $value)use(&$check):void{if(is_array($value)||is_object($value))foreach($value as $key=>$item){self::assertNotContains((string)$key,['actor','evidence','request','payload','snapshot','receipt','parties','sources','income_lots']);$check($item);}};$check($out);
        self::assertSame('CZK',$out['currency']);
    }
    public function testMissingCanonicalAmountFailsClosedInsteadOfBecomingZero():void
    {
        $this->expectException(PropertyException::class);P::cents(null);
    }
}
