<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Property\Charges;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\Property\Charges\ChargeRepository;
use MyInvoice\Repository\Property\PropertyReadRepository;
use MyInvoice\Repository\Property\PropertyRegistryRepository;
use MyInvoice\Service\Property\Charges\ChargeException;
use MyInvoice\Service\Property\Charges\ChargePreviewService;
use MyInvoice\Tests\Support\PropertyChargeFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class ChargeRegistryBasisTest extends TestCase
{
    use PropertyChargeFixture;
    private Connection $db;
    private array $f;

    protected function setUp(): void
    {
        $this->db=Bootstrap::buildContainer()->get(Connection::class);
        self::assertStringEndsWith('_test',(string)$this->db->pdo()->query('SELECT DATABASE()')->fetchColumn());
        $this->db->pdo()->beginTransaction();
        $this->f=$this->chargeFixture($this->db);
    }

    protected function tearDown(): void
    {
        if($this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
    }

    #[TestWith([0])]
    #[TestWith([1])]
    public function testStatutoryBasisRequiresConfirmedCompleteHouseRegistry(int $rateIndex): void
    {
        $f=$this->f;
        $request=self::chargeRequest($f);
        $request['rates']=[$request['rates'][$rateIndex]];
        $service=new ChargePreviewService($this->db);
        $state=(new PropertyReadRepository($this->db))->find($f['supplier'],$f['house']['id'],$request['basis_at'])['revision']['state'];
        $state['registry_complete']=false;
        $change=self::registryRequest('incomplete-house',$state);
        unset($change['code']);
        $change['expected_revision']=1;
        (new PropertyRegistryRepository($this->db))->revise($f['supplier'],$f['house']['id'],'house',$change,$f['actor']);
        $this->expectException(ChargeException::class);
        $this->expectExceptionMessage('Metoda nebo okruh jednotek');
        $preview=$service->preview($f['supplier'],$request);
        (new ChargeRepository($this->db))->saveVersion($f['supplier'],['request'=>$request,'preview_hash'=>$preview['preview_hash'],'idempotency_key'=>'incomplete-basis'],$f['actor']);
    }

    public function testCompleteRegistryFactIsIncludedInImmutablePreview(): void
    {
        $f=$this->f;
        $preview=(new ChargePreviewService($this->db))->preview($f['supplier'],self::chargeRequest($f));
        self::assertTrue($preview['preview']['registry']['house']['registry_complete']??false);
    }

    #[TestWith(['324001'])]
    #[TestWith(['324.A1'])]
    public function testComponentAcceptsExistingAnalyticalAccountCode(string $accountCode): void
    {
        $f=$this->f;
        (new ChartOfAccountsRepository($this->db))->insert($f['supplier'],['account_code'=>$accountCode,'name'=>'Synthetic charge analytic','account_type'=>'liability','normal_side'=>'credit']);
        $component=(new ChargeRepository($this->db))->createComponent($f['supplier'],['code'=>'ANALYTIC','name'=>'Synthetic analytical charge','kind'=>'admin_advance','service_id'=>null,
            'account_code'=>$accountCode,'effective_at'=>'2026-01-01T00:00:00+01:00','idempotency_key'=>'analytic-account'],$f['actor']);
        self::assertSame($accountCode,$component['account_code']);
    }
}