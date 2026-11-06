<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Property;

use MyInvoice\Service\Property\Charges\Issuance\IssuancePublicProjection;
use PHPUnit\Framework\TestCase;

final class ChargeIssuancePublicProjectionTest extends TestCase
{
    public function testExactFinancialCellsExcludeEveryPrivateEnvelopeAndUseCompleteDimensions(): void
    {
        $amount='-9007199254740993001';
        $line=['component_id'=>7,'service_id'=>8,'amount_cents'=>$amount,'dimensions'=>[4=>11,5=>12],
            'account'=>['receivable_account'=>'315001','counter_account'=>'324.01','dimensions'=>[],
                'basis_ref'=>'Private basis'], 'component'=>['name'=>'Private component'], 'calculation'=>['Private calculation']];
        $row=['id'=>19,'batch_id'=>3,'unit_id'=>11,'revision_no'=>2,'previous_charge_id'=>18,'title'=>'prescription',
            'issue_mode'=>'difference','period_from'=>'2026-01-01','period_to'=>'2026-01-31','accounting_date'=>'2026-01-01',
            'due_date'=>'2026-01-15','status'=>'posted','row_version'=>2,'journal_entry_id'=>100,'reversal_entry_id'=>null,
            'amount_cents'=>$amount,'source_key'=>'Private source','created_by'=>42,'snapshot_hash'=>'Private hash',
            'snapshot'=>['lines'=>[$line],'parties'=>['Private person'],'liability_groups'=>['Private deed'],
                'notices'=>['Private address'],'reason'=>'Private reason']];
        $before=$row;
        $actual=IssuancePublicProjection::charge($row);
        self::assertSame($before,$row);
        self::assertSame($amount,$actual['amount_cents']);
        self::assertSame($amount,$actual['lines'][0]['amount_cents']);
        self::assertSame([4=>11,5=>12],(array)$actual['lines'][0]['dimensions']);
        self::assertSame('315001',$actual['lines'][0]['receivable_account']);
        self::assertSame('CZK',$actual['currency']);
        self::assertStringNotContainsString('Private',json_encode($actual,JSON_THROW_ON_ERROR));
        self::assertNull($actual['reversal_entry_id']);
        $row['snapshot']['lines'][0]['dimensions']=[];
        self::assertStringContainsString('"dimensions":{}',json_encode(IssuancePublicProjection::charge($row),JSON_THROW_ON_ERROR));
    }
}
