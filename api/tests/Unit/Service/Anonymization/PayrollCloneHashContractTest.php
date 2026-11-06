<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Anonymization;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollStatutoryResultRepository;
use MyInvoice\Service\Anonymization\{Pseudonymizer, TextScrubber};
use MyInvoice\Service\Payroll\Run\PayrollRunSnapshotBuilder;
use PHPUnit\Framework\TestCase;

final class PayrollCloneHashContractTest extends TestCase
{
    public function testStatutorySetKeepsItsExistingCanonicalPreimage(): void
    {
        $repository=new PayrollStatutoryResultRepository(new Connection(new Config([])));
        $hash=$repository->resultSetHash('income_tax','fixture.v1','calculated','synthetic',str_repeat('a',64),['z'=>17,'a'=>[]],['status'=>'calculated','amount_minor'=>4200000],[
            ['employee_id'=>2,'input_snapshot'=>['code'=>'SYNTHETIC'],'relationships'=>[
                ['employment_id'=>9,'input_snapshot'=>['id'=>9],'result_snapshot'=>['amount_minor'=>12],'result_status'=>'calculated'],
            ],'result_snapshot'=>['net_minor'=>12],'result_status'=>'calculated'],
        ]);
        self::assertSame('8a74248af31ca5672c5b95c30cf19e1582aceb41851d43779f29a21b178d8148',$hash);
    }

    public function testOfficeRegistrationKeepsItsExistingCanonicalPreimage(): void
    {
        $data=['id'=>9,'effective_from'=>'2026-01-01','social_security_variable_symbol'=>'1101234563','source_reference'=>'synthetic','office_code'=>'TEST','office_name'=>'Synthetic office'];
        self::assertSame('f9f45f5952cbc583a0c37d8b75bb204224dedb85f4fc547b2ec69664bc144a55',PayrollRunSnapshotBuilder::officeRegistrationHash($data));
        $data['sha256']='f9f45f5952cbc583a0c37d8b75bb204224dedb85f4fc547b2ec69664bc144a55';
        self::assertSame($data['sha256'],PayrollRunSnapshotBuilder::officeRegistrationHash($data));
    }

    public function testOnlyTypedCostCenterCodeUsesTheSharedIdentity(): void
    {
        $scrubber=new TextScrubber(new Pseudonymizer('synthetic-payroll-cost-center-key'));
        $json='{"dimensions":[{"type":"cost_center","code":"PRIVATE-OFFICE-71","id":17},{"type":"project","code":"PRIVATE-OFFICE-71","id":18}],"cost_center":"PRIVATE-OFFICE-71","revision_id":19}';
        $result=json_decode($scrubber->scrubJson($json),true,flags:JSON_THROW_ON_ERROR);
        self::assertNotSame('PRIVATE-OFFICE-71',$result['dimensions'][0]['code']);
        self::assertSame($scrubber->byStrategy('cost_center','PRIVATE-OFFICE-71'),$result['dimensions'][0]['code']);
        self::assertSame($result['cost_center'],$result['dimensions'][0]['code']);
        self::assertSame('PRIVATE-OFFICE-71',$result['dimensions'][1]['code']);
        self::assertSame([17,18],array_column($result['dimensions'],'id'));
        self::assertSame(19,$result['revision_id']);
        self::assertSame($result,json_decode($scrubber->scrubJson(json_encode($result,JSON_THROW_ON_ERROR)),true,flags:JSON_THROW_ON_ERROR));
    }
}