<?php
declare(strict_types=1);
namespace MyInvoice\Tests\Unit\Property\Payments;
use MyInvoice\Service\Property\Payments\ClaimPortionAllocator;
use PHPUnit\Framework\TestCase;
final class ClaimPortionAllocatorTest extends TestCase
{
    public function testAccountingRowOrderCannotChoosePartialLegalDisposition():void
    {
        $this->expectException(\DomainException::class);ClaimPortionAllocator::distribute('60',[19=>'70',2=>'30'],null);
    }
    public function testPartialPrincipalDefaultsToProportionalSplitIndependentOfRowOrder():void
    {
        self::assertSame([2=>'18',19=>'42'],ClaimPortionAllocator::distribute('60',[19=>'70',2=>'30'],null,true));
        self::assertSame([2=>'18',19=>'42'],ClaimPortionAllocator::distribute('60',[2=>'30',19=>'70'],null,true));
        // Haléřový zbytek dostane řádek s největším zlomkem, při shodě nižší id.
        self::assertSame([1=>'33',2=>'33',3=>'34'],ClaimPortionAllocator::distribute('100',[3=>'334',1=>'333',2=>'333'],null,true));
        self::assertSame([4=>'1'],ClaimPortionAllocator::distribute('1',[9=>'1',4=>'1'],null,true));
        self::assertSame([5=>'40'],ClaimPortionAllocator::distribute('40',[5=>'50',6=>'0'],null,true));
    }
    public function testDeclaredBreakdownTakesPrecedenceOverProportionalDefault():void
    {
        self::assertSame([2=>'10',19=>'50'],ClaimPortionAllocator::distribute('60',[19=>'70',2=>'30'],['evidence'=>'Synthetic debtor designation','parts'=>[['id'=>19,'amount_cents'=>'50'],['id'=>2,'amount_cents'=>'10']]],true));
    }
    public function testExplicitSameDebtBreakdownPreservesExactAmounts():void
    {
        self::assertSame([2=>'10',19=>'50'],ClaimPortionAllocator::distribute('60',[19=>'70',2=>'30'],['evidence'=>'Synthetic legal allocation within same debt','parts'=>[['id'=>19,'amount_cents'=>'50'],['id'=>2,'amount_cents'=>'10']]]));
        self::assertSame([2=>'30',19=>'70'],ClaimPortionAllocator::distribute('100',[19=>'70',2=>'30'],null));
    }
    public function testDocumentedBreakdownCannotExceedActualReceipt():void
    {
        $this->expectException(\DomainException::class);ClaimPortionAllocator::distribute('60',[19=>'40',2=>'60'],['evidence'=>'Synthetic excess','parts'=>[['id'=>19,'amount_cents'=>'50'],['id'=>2,'amount_cents'=>'10']]]);
    }
}
