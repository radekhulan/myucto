<?php
declare(strict_types=1);
namespace MyInvoice\Tests\Unit\Service\Anonymization;
use MyInvoice\Service\Anonymization\{Pseudonymizer,TextScrubber};
use PHPUnit\Framework\TestCase;
final class LegacyCostCenterIdentityTest extends TestCase
{
    public function testStableIdentityFitsPayrollGrammarAndPreservesReservedOwnerCodes():void
    {
        $a=new Pseudonymizer('synthetic-cost-center-key-123');$b=new Pseudonymizer('synthetic-cost-center-key-456');$scrubber=new TextScrubber($a);$original='synthetic.context@example.test';$code=$scrubber->byStrategy('cost_center',$original);self::assertMatchesRegularExpression('/^CC[A-F0-9]{48}$/D',$code);self::assertSame($code,$scrubber->byStrategy('cost_center',$original));self::assertSame($code,$scrubber->byStrategy('cost_center',strtoupper($original)));self::assertSame($code,$scrubber->byStrategy('cost_center',$code));self::assertNotSame($code,(new TextScrubber($b))->byStrategy('cost_center',$original));foreach(['MZ-EX-123','MZ-SR-456',''] as $reserved)self::assertSame($reserved,$scrubber->byStrategy('cost_center',$reserved));
    }
    public function testPreservesNativePayrollDeductionAndEnforcementOwnerCodes():void
    {
        $scrubber=new TextScrubber(new Pseudonymizer('synthetic-payroll-cost-center-key'));
        foreach(['MZ-SR-2DAAE3A888F0DD49','MZ-EX-7CE6808CF7AB2D41','mz-sr-2daae3a888f0dd49']as$code)self::assertSame($code,$scrubber->byStrategy('cost_center',$code));
        self::assertMatchesRegularExpression('/^CC[A-F0-9]{48}$/D',$scrubber->byStrategy('cost_center','MZ-SR-synthetic.person@example.test'));
    }

    public function testNestedJsonUsesSameIdentityWithoutChangingNumericDimensionsOrMoney():void
    {
        $scrubber=new TextScrubber(new Pseudonymizer('synthetic-cost-center-key-123'));$original='synthetic.context@example.test';$input=['cost_center'=>$original,'nested'=>['cost_center'=>$original,'dimensions'=>['cost_center'=>42],'amount_cents'=>'13200000']];$result=json_decode($scrubber->scrubJson(json_encode($input,JSON_THROW_ON_ERROR)),true,flags:JSON_THROW_ON_ERROR);self::assertSame($scrubber->byStrategy('cost_center',$original),$result['cost_center']);self::assertSame($result['cost_center'],$result['nested']['cost_center']);self::assertSame(42,$result['nested']['dimensions']['cost_center']);self::assertSame('13200000',$result['nested']['amount_cents']);self::assertSame($result,json_decode($scrubber->scrubJson(json_encode($result,JSON_THROW_ON_ERROR)),true,flags:JSON_THROW_ON_ERROR));
    }
}
