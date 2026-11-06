<?php
declare(strict_types=1);
namespace MyInvoice\Tests\Unit\Property\Receivables;
use MyInvoice\Service\Property\Receivables\{PropertySaldoCursor,PropertySaldoSummary};
use MyInvoice\Service\Property\PropertyException;
use MyInvoice\Repository\Property\Receivables\PropertySaldoReadProof;
use PHPUnit\Framework\TestCase;
final class PropertySaldoSummaryTest extends TestCase
{
    public function testReadonlySelectionCannotAuthorizeGuardedPaymentPosting():void
    {
        $selection=new \MyInvoice\Repository\Property\Receivables\PropertySaldoReadSelection([],[],[],[]);
        self::assertTrue((new \ReflectionClass($selection))->isReadOnly());
        $writer=(new \ReflectionClass(\MyInvoice\Service\Property\Payments\Workflow\PaymentWorkflowJournalPlan::class))->newInstanceWithoutConstructor();
        $this->expectException(\TypeError::class);$writer->currentPayment($selection);
    }
    public function testCursorPinsTenantAndBothTimesWithoutGrantingAuthority():void
    {
        $at='2026-02-01T00:00:00.000000Z';$known='2026-03-01T00:00:00.000000Z';$cursor=PropertySaldoCursor::encode(7,$at,$known,12);
        self::assertSame(12,PropertySaldoCursor::decode($cursor,7,$at,$known));
        foreach([[8,$at,$known],[7,$known,$known],[7,$at,$at]] as [$supplier,$a,$k]){try{PropertySaldoCursor::decode($cursor,$supplier,$a,$k);self::fail('Foreign cursor accepted');}catch(PropertyException $e){self::assertSame(422,$e->httpStatus);}}
    }
    public function testSummaryKeepsSeparateCreditsAndAccessoryReversalAndUnknownNulls():void
    {
        $at='2026-03-01T00:00:00.000000Z';$event=static fn($id,$kind,$amount)=>['id'=>$id,'kind'=>$kind,'amount_cents'=>$amount,'date'=>'2026-01-01','effective_at'=>'2026-01-01T00:00:00.000000Z','recorded_at'=>'2026-01-01T00:00:00.000000Z'];
        $card=['scope'=>['unit_id'=>1],'as_of'=>$at,'known_at'=>$at,'precision'=>'instant','credits'=>[],
            'totals'=>['gross_receivable_cents'=>'100','gross_credit_cents'=>'200','reserved_credit_cents'=>'30','available_credit_cents'=>'170','guarantee_exposure_cents'=>'0'],
            'coverage'=>['financial_complete'=>true,'service_settlements'=>'not_implemented','opening_import'=>'verified_recorded_batches','scope'=>'recorded_s3_s4_s6','unresolved_negative_charge_line_ids'=>[]],
            'claims'=>[['due_date'=>'2026-01-01','amount_cents'=>'100','liability'=>['complete'=>true],'events'=>[$event('accessory:1','claim','50'),$event('allocation:8','paid','20'),$event('allocation:8:reversal','paid','-20'),$event('allocation:9','paid','10')]]]];
        $summary=PropertySaldoSummary::fromCard($card,[8=>true,9=>true]);self::assertSame('40',$summary['totals']['accessory_receivable_cents']);self::assertSame('60',$summary['totals']['principal_receivable_cents']);self::assertSame('100',$summary['totals']['gross_receivable_cents']);self::assertSame('200',$summary['totals']['gross_credit_cents']);self::assertFalse($summary['coverage']['complete']);
        $card['coverage']['financial_complete']=false;foreach($card['totals'] as &$amount)$amount=null;unset($amount);$card['coverage']['unresolved_negative_charge_line_ids']=[9];$summary=PropertySaldoSummary::fromCard($card,[8=>true,9=>true]);foreach($summary['totals'] as $amount)self::assertNull($amount);foreach($summary['aging'] as $amount)self::assertNull($amount);self::assertSame(1,$summary['coverage']['unresolved_negative_charge_line_count']);
    }
    public function testMissingImmutableDebtLineMaterializationCannotBecomeAZeroSummary():void
    {
        $fact=['lines'=>[['id'=>10]]];$json=json_encode($fact,JSON_THROW_ON_ERROR);$row=['id'=>1,'snapshot_json'=>$json,'snapshot_hash'=>\MyInvoice\Service\Property\PropertyInput::hash($fact)];
        $rows=array_fill_keys(['unit_payment_debt_lines','unit_payment_correction_anchors','unit_payment_reliefs','unit_payment_corrections','unit_credit_lots','unit_payment_sources','unit_payment_operations'],[]);$rows['unit_payment_debts']=[$row];
        $this->expectException(PropertyException::class);PropertySaldoReadProof::materializations($rows);
    }
}
