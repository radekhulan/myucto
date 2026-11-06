<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Property\Services\Reconciliation;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Closing\ClosingService;
use MyInvoice\Tests\Integration\Property\Services\Settlement\ServiceLifecycleNativeTest;
use PHPUnit\Framework\TestCase;

final class ServiceReconciliationClosingTest extends TestCase
{
    private ServiceLifecycleNativeTest $fixture;
    private Connection $db;

    protected function setUp(): void
    {
        $this->fixture=new ServiceLifecycleNativeTest('testNativeBalanceYearRolloverUnwindsExactEstimateAndFinalizes');
        (new \ReflectionMethod($this->fixture,'setUp'))->invoke($this->fixture);
        $this->db=(new \ReflectionProperty($this->fixture,'db'))->getValue($this->fixture);
    }

    protected function tearDown(): void
    {
        if(isset($this->fixture))(new \ReflectionMethod($this->fixture,'tearDown'))->invoke($this->fixture);
    }

    public function testNativeServiceProofIsAMandatorySelectiveClosingCheck(): void
    {
        $d=(new \ReflectionMethod($this->fixture,'openingBase'))->invoke($this->fixture,'service_balance_sheet',false);
        $supplier=$d['f']['supplier'];
        $container=(new \ReflectionProperty($this->fixture,'container'))->getValue($this->fixture);
        $query=$this->db->pdo()->prepare('SELECT * FROM accounting_periods WHERE supplier_id=? AND starts_on<=? AND ends_on>=?');
        $query->execute([$supplier,'2027-03-31','2027-03-31']);
        $period=$query->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($period);
        $closing=$container->get(ClosingService::class);
        $checks=$closing->buildChecks($supplier,$period,null,'2027-03-31',onlyKeys:['property_service_reconciliation']);
        self::assertCount(1,$checks);
        self::assertSame('property_service_reconciliation',$checks[0]['key']);
        self::assertSame('error',$checks[0]['severity']);
        self::assertTrue($checks[0]['ok'],json_encode($checks[0]));
        $this->db->pdo()->prepare("UPDATE property_service_lifecycle_events SET receipt_json=NULL,receipt_hash=NULL WHERE supplier_id=? AND case_id=? AND event_key='estimate'")->execute([$supplier,$d['case']]);
        $checks=$closing->buildChecks($supplier,$period,null,'2027-03-31',onlyKeys:['property_service_reconciliation']);
        self::assertCount(1,$checks);
        self::assertFalse($checks[0]['ok']);
        self::assertSame('incomplete',$checks[0]['value']['coverage']);
        self::assertContains('lifecycle_pending',array_column($checks[0]['value']['incomplete'],'code'));
        $failures=(new \ReflectionMethod($closing,'failingErrorChecks'))->invoke($closing,$supplier,$period);
        self::assertContains('property_service_reconciliation',$failures);
    }
}
