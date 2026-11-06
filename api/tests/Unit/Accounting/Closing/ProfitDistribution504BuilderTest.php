<?php
declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting\Closing;

use MyInvoice\Service\Accounting\AccountingRoleProfile;
use MyInvoice\Service\Accounting\Closing\{ClosingException, ProfitDistribution504Builder as Builder};
use PHPUnit\Framework\TestCase;

final class ProfitDistribution504BuilderTest extends TestCase
{
    public function testZeroTotalStillRequiresBothOppositeJointCells(): void
    {
        $cells=Builder::cells([$this->row(1,'credit','10.01',[1=>11,2=>21]),$this->row(2,'debit','10.01',[1=>12,2=>22])]);
        self::assertCount(2,$cells);
        self::assertSame(0,array_sum(array_column($cells,'available_cents')));
        $lines=Builder::build($cells,$this->body($cells),new AccountingRoleProfile('cz_504'));
        self::assertCount(4,$lines);
        self::assertSame(0,array_sum(array_map(static fn(array $l):int=>(int)round($l['amount']*100)*($l['side']==='credit'?1:-1),$lines)));
        self::assertEqualsCanonicalizing([[1=>11,2=>21],[1=>12,2=>22]],array_column($cells,'dimensions'));
    }

    public function testSingleAxisSplitUsesExactRemainderForEachSourceLine(): void
    {
        $row=$this->row(1,'credit','0.01',[1=>11]);
        $row['dimension_splits']=[2=>[21=>0.5,22=>0.5]];
        $other=$row; $other['id']=2;
        $cells=Builder::cells([$row,$other]);
        self::assertSame(2,array_sum(array_column($cells,'available_cents')));
        self::assertCount(1,$cells);
        self::assertSame(22,$cells[0]['dimensions'][2]);
    }

    public function testIndependentFractionalAxesCannotInventJointCorrelation(): void
    {
        $row=$this->row(1,'credit','100',[]);
        $row['dimension_splits']=[1=>[11=>0.5,12=>0.5],2=>[21=>0.5,22=>0.5]];
        $this->expectException(ClosingException::class);
        Builder::cells([$row]);
    }

    public function testOverAllocationAndUnknownTargetFail(): void
    {
        $cells=Builder::cells([$this->row(1,'credit','10.01',[1=>11])]);
        foreach(['amount','role'] as $field) {
            $body=$this->body($cells);
            $body['allocations'][0][$field]=$field==='amount'?10.02:'shares';
            try { Builder::build($cells,$body,new AccountingRoleProfile('cz_504')); self::fail('Invalid allocation must fail.'); }
            catch(ClosingException $e) { self::assertContains($e->errorCode,['distribution_mismatch','validation_failed']); }
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('fractionalAmounts')]
    public function testRejectsFractionalCentsWithoutRounding(mixed $raw, string $available): void
    {
        $cells=Builder::cells([$this->row(1,'credit',$available,[])]);
        $body=$this->body($cells);
        $body['allocations'][0]['amount']=$raw;
        try { Builder::build($cells,$body,new AccountingRoleProfile('cz_504')); self::fail('A decision amount must be exact cents.'); }
        catch(ClosingException $e) { self::assertSame('validation_failed',$e->errorCode); }
    }

    public static function fractionalAmounts(): array
    {
        return [[0.005,'0.01'],['0.005','0.01'],[100.001,'100'],['100.001','100'],['1.00001e2','100'],
            ['1e2','100'],['100.0000000000000000000001','100'],['99999999999999.99','9999999999999.99']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('exactAmounts')]
    public function testAcceptsExactCentBoundaries(mixed $raw, string $available, int $cents): void
    {
        $cells=Builder::cells([$this->row(1,'credit',$available,[])]);
        $body=$this->body($cells);
        $body['allocations'][0]['amount']=$raw;
        $lines=Builder::build($cells,$body,new AccountingRoleProfile('cz_504'));
        self::assertCount(2,$lines);
        self::assertSame($cents,(int)round($lines[0]['amount']*100));
    }

    public static function exactAmounts(): array
    {
        return [[0.01,'0.01',1],[0.29,'0.29',29],[1.15,'1.15',115],[100.01,'100.01',10001],
            ['0001.2300','1.23',123],['9999999999999.99','9999999999999.99',999999999999999],[100,'100',10000]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('analyticalResources')]
    public function testLossCoveragePostsTheActualAnalyticalResource(string $role, string $account): void
    {
        $cells=Builder::cells([$this->row(1,'debit','25.01',[1=>11,2=>21])]);
        $resource=$this->row(2,'credit','30.00',[1=>11,2=>21]);
        $resource['account_code']=$account;
        $resources=Builder::cells([$resource]);
        $body=$this->body($cells);
        $body['allocations'][0]['role']=$role;
        $lines=Builder::build($cells,$body,new AccountingRoleProfile('cz_504'),$resources);
        self::assertSame($account,$lines[1]['account_code']);
        self::assertSame('debit',$lines[1]['side']);
        self::assertSame(2501,(int)round($lines[1]['amount']*100));
    }

    public static function analyticalResources(): array
    {
        return [['result.funds','911.100'],['result.own_equity','901.100']];
    }

    public function testMultipleResourceAccountsRequireAnExplicitChoice(): void
    {
        $cells=Builder::cells([$this->row(1,'debit','25.01',[1=>11])]);
        $resources=[];
        foreach(['911.100','911.200'] as $index=>$code) {
            $row=$this->row(2+$index,'credit','30.00',[1=>11]);
            $row['account_code']=$code;
            array_push($resources,...Builder::cells([$row]));
        }
        $body=$this->body($cells);
        $body['allocations'][0]['role']='result.funds';
        try { Builder::build($cells,$body,new AccountingRoleProfile('cz_504'),$resources); self::fail('Choose the actual resource account.'); }
        catch(ClosingException $e) { self::assertSame('result_decision_resource_required',$e->errorCode); }
    }

    public function testExplicitChoiceCannotSpendAnotherResourceAccount(): void
    {
        $cells=Builder::cells([$this->row(1,'debit','25.01',[1=>11])]);
        $resources=[];
        foreach(['911.100'=>'100.00','911.200'=>'10.00'] as $code=>$amount) {
            $row=$this->row(count($resources)+2,'credit',$amount,[1=>11]);
            $row['account_code']=$code;
            array_push($resources,...Builder::cells([$row]));
        }
        $body=$this->body($cells);
        $body['allocations'][0]+=['account_code'=>'911.200'];
        $body['allocations'][0]['role']='result.funds';
        try { Builder::build($cells,$body,new AccountingRoleProfile('cz_504'),$resources); self::fail('Do not borrow from another account.'); }
        catch(ClosingException $e) { self::assertSame('insufficient_distributable_resources',$e->errorCode); }
        $body['allocations'][0]['account_code']='911.100';
        $lines=Builder::build($cells,$body,new AccountingRoleProfile('cz_504'),$resources);
        self::assertSame('911.100',$lines[1]['account_code']);
    }
    private function body(array $cells): array
    {
        return ['snapshot_hash'=>Builder::snapshot($cells),'allocations'=>array_map(static fn(array $c):array=>[
            'cell_key'=>$c['key'],'role'=>'result.retained_profit','amount'=>abs($c['available'])],$cells)];
    }
    private function row(int $id,string $side,string $amount,array $dims): array
    {
        return ['id'=>$id,'side'=>$side,'signed_amount'=>$amount,'account_code'=>'931','dimensions'=>$dims];
    }
}
