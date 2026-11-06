<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Property;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Charges\Issuance\IssuanceRepository;
use MyInvoice\Service\Property\Charges\Issuance\IssuancePreviewService;
use MyInvoice\Service\Property\Receivables\PropertyReceivableProjection;
use MyInvoice\Tests\Support\ChargeIssuanceFixture;
use PHPUnit\Framework\TestCase;

/** Karta jednotky čte jen uzávěr jednotky a dává stejný výsledek jako čtení celé firmy. */
final class PropertyReceivableUnitCardTest extends TestCase
{
    use ChargeIssuanceFixture;

    private Connection $db;

    protected function setUp(): void
    {
        $this->db=Bootstrap::buildContainer()->get(Connection::class);
        $this->db->pdo()->beginTransaction();
    }

    protected function tearDown(): void
    {
        if($this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
    }

    public function testScopedUnitCardMatchesWholeCompanyProjection(): void
    {
        $f=$this->issuanceFixture($this->db);
        $container=Bootstrap::buildContainer();
        $container->set(Connection::class,$this->db);
        $issuance=$container->get(IssuanceRepository::class);
        $preview=(new IssuancePreviewService($this->db))->preview($f['supplier'],$f['issue_request']);
        $batch=$issuance->issue($f['supplier'],['request'=>$f['issue_request'],'preview_hash'=>$preview['preview_hash'],'idempotency_key'=>'unit-card'],$f['actor']);
        $issuance->post($f['supplier'],$batch['id'],array_map(fn($c)=>['id'=>$c['id'],'row_version'=>1],$batch['charges']),$f['actor']);

        $projection=new PropertyReceivableProjection($this->db);
        $asOf='2026-02-01T00:00:00+01:00';
        $known='2027-01-01T00:00:00Z';
        $checked=0;
        foreach($f['units'] as $unitId) {
            $scoped=$projection->at($f['supplier'],$asOf,$known,['unit_id'=>$unitId]);
            $whole=$projection->at($f['supplier'],$asOf,$known,['unit_id'=>$unitId],true);
            foreach(['claims','credits','totals','coverage','precision'] as $key) self::assertSame($whole[$key],$scoped[$key],$key.' differs for unit '.$unitId);
            foreach($scoped['claims'] as $claim) self::assertSame($unitId,$claim['unit_id']);
            $checked+=count($scoped['claims']);
        }
        self::assertSame(count($batch['charges']),$checked);
    }
}
