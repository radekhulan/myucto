<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tax\Return;

use MyInvoice\Service\Tax\Return\Dppo504CapabilityService;
use MyInvoice\Service\Tax\Return\TaxReturnException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class Dppo504CapabilityServiceTest extends TestCase
{
    public static function periods(): array
    {
        return [
            'calendar 2025' => ['2025-01-01', '2025-12-31', true, true],
            'fiscal ending 2026' => ['2025-07-01', '2026-06-30', true, true],
            'calendar 2026' => ['2026-01-01', '2026-12-31', true, false],
            'outside ruleset' => ['2027-01-01', '2027-12-31', false, false],
        ];
    }

    #[DataProvider('periods')]
    public function testOperationsAreGrantedByRulesetAndPinnedFormPeriod(string $from, string $to, bool $compute, bool $xml): void
    {
        $capabilities = (new Dppo504CapabilityService())->capabilities($from, $to, 'A', true);
        self::assertSame($compute, $capabilities[Dppo504CapabilityService::COMPUTE]['allowed']);
        self::assertSame($xml, $capabilities[Dppo504CapabilityService::FINALIZE]['allowed']);
        self::assertSame($xml, $capabilities[Dppo504CapabilityService::EXPORT_XML]['allowed']);
        self::assertSame($xml, $capabilities[Dppo504CapabilityService::SUBMIT]['allowed']);
    }

    public function testOfficialManifestIsPinnedToLocalSchemaDigest(): void
    {
        $manifest = (new Dppo504CapabilityService())->manifest();
        self::assertSame('dppdp9', $manifest['form_code']);
        self::assertSame('05.01.01', $manifest['form_version']);
        self::assertSame('2025-10-21', $manifest['form_date']);
        self::assertSame('db3846b9495f38362a845a5becee732347cea995df44ec03006011ed12f3272c', $manifest['xsd_sha256']);
        self::assertTrue($manifest['xsd_verified']);
    }

    public function testTypeARejectsLastDateThatOtherReturnTypesMayStart(): void
    {
        $service = new Dppo504CapabilityService();
        self::assertFalse($service->capabilities('2025-12-31', '2026-12-31', 'A', true)[Dppo504CapabilityService::EXPORT_XML]['allowed']);
        self::assertTrue($service->capabilities('2025-12-31', '2026-12-31', 'B', true)[Dppo504CapabilityService::EXPORT_XML]['allowed']);
    }

    public function testUnsupportedFormPeriodFailsClosedWithStableCode(): void
    {
        try {
            (new Dppo504CapabilityService())->assert(Dppo504CapabilityService::FINALIZE, '2026-01-01', '2026-12-31');
            self::fail('Finalization must stay closed without an official form supporting the period.');
        } catch (TaxReturnException $e) {
            self::assertSame('nonprofit_dppo_form_period_not_supported', $e->errorCode);
            self::assertSame(422, $e->httpStatus);
        }
    }

    public function testFinalizationXmlAndSubmissionStayClosedUntilCalculationIsAccepted(): void
    {
        $service = new Dppo504CapabilityService();
        $closed = $service->capabilities('2025-01-01', '2025-12-31');
        self::assertTrue($closed[Dppo504CapabilityService::COMPUTE]['allowed']);
        foreach ([Dppo504CapabilityService::FINALIZE, Dppo504CapabilityService::EXPORT_XML, Dppo504CapabilityService::SUBMIT] as $operation) {
            self::assertFalse($closed[$operation]['allowed'], $operation);
            self::assertSame('nonprofit_dppo_calculation_not_accepted', $closed[$operation]['code']);
            try {
                $service->assert($operation, '2025-01-01', '2025-12-31');
                self::fail($operation . ' must stay closed without accepted calculation.');
            } catch (TaxReturnException $e) {
                self::assertSame('nonprofit_dppo_calculation_not_accepted', $e->errorCode);
            }
        }
        self::assertTrue($service->assert(Dppo504CapabilityService::FINALIZE, '2025-01-01', '2025-12-31', 'A', true)['allowed']);
    }
}