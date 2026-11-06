<?php

declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Property\Services\Settlement;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Property\PropertyInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ServiceLifecycleFinalDateTest extends TestCase
{
    private ServiceLifecycleNativeTest $fixture;
    private Connection $db;
    protected function setUp(): void
    {
        $this->fixture=new ServiceLifecycleNativeTest('testNativeBalanceYearRolloverUnwindsExactEstimateAndFinalizes');
        (new \ReflectionMethod($this->fixture,'setUp'))->invoke($this->fixture);
        $this->db=(new \ReflectionProperty($this->fixture,'db'))->getValue($this->fixture);
    }
    protected function tearDown(): void {(new \ReflectionMethod($this->fixture,'tearDown'))->invoke($this->fixture);}
    public static function policies(): array {return [['service_balance_sheet'],['service_profit_and_loss']];}
    private function invoke(string $method,mixed ...$args): mixed {return(new \ReflectionMethod($this->fixture,$method))->invoke($this->fixture,...$args);}
    private function fingerprint(int $supplier): string
    {
        $out=[];foreach(['property_service_lifecycle_cases','property_service_lifecycle_events','property_service_lifecycle_claims','property_service_lifecycle_commands','journal_entries','journal_entry_lines','journal_entry_line_dimensions','journal_entry_line_dimension_splits'] as $table){$q=$this->db->pdo()->prepare('SELECT * FROM '.$table.' WHERE supplier_id=?');$q->execute([$supplier]);$rows=array_map(fn($a)=>json_encode($a,JSON_THROW_ON_ERROR),$q->fetchAll(\PDO::FETCH_ASSOC));sort($rows,SORT_STRING);$out[$table]=$rows;}return PropertyInput::hash($out);
    }
    #[DataProvider('policies')]
    public function testFinalCannotPrecedeVerified2028Unwind(string $policy): void
    {
        $d=$this->invoke('base',$policy);$f=$d['f'];$invoice=$this->invoke('invoice',$d);
        (new AccountingPeriodRepository($this->db))->create($f['supplier'],2028,'2028-01-01','2028-12-31');
        $d['owner']->unwind($f['supplier'],$d['case'],'2028-01-01',$f['actor']);$before=$this->fingerprint($f['supplier']);$refused=false;
        try{$d['owner']->finalize($f['supplier'],$d['case'],$invoice['invoice'],$invoice['line']['id'],'2027-04-01','Synthetic final predating native unwind',$f['actor']);}
        catch(\DomainException $e){$refused=true;self::assertSame('settlement_lifecycle_final_before_unwind',$e->getMessage());}
        self::assertTrue($refused,'Final2027 was accepted after actual native unwind2028');self::assertSame($before,$this->fingerprint($f['supplier']),'Rejected final wrote financial/event/claim data');
    }
    #[DataProvider('policies')]
    public function testS6LiteralMethodologyStillReachesS8AfterDateGuard(string $policy): void
    {
        $this->invoke('openingLifecycle',$policy,false);
    }
    #[DataProvider('policies')]
    public function testFinalAfterVerified2027UnwindRemainsValid(string $policy): void
    {
        $d=$this->invoke('base',$policy);$f=$d['f'];$invoice=$this->invoke('invoice',$d);$d['owner']->unwind($f['supplier'],$d['case'],'2027-04-01',$f['actor']);
        $d['owner']->assertFinalDate($f['supplier'],$d['case'],'2027-04-01');
        $done=$d['owner']->finalize($f['supplier'],$d['case'],$invoice['invoice'],$invoice['line']['id'],'2027-04-01','Synthetic valid final after exact native unwind',$f['actor']);self::assertSame('1120000',$done['amount_cents']);self::assertGreaterThan(0,$done['cost_line_id']);
    }
}
