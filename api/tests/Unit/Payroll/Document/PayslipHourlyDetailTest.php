<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Document;

use MyInvoice\Service\Payroll\Document\PayslipHourlyDetail;
use MyInvoice\Service\Payroll\Document\PayslipPdfRenderer;
use MyInvoice\Tests\Fixtures\Payroll\SyntheticHourlyPayslipFixture as Fixture;
use MyInvoice\Tests\Fixtures\Payroll\SyntheticPayslipFixture;
use PHPUnit\Framework\TestCase;

final class PayslipHourlyDetailTest extends TestCase
{
    public function testMinuteAndMixedPrecisionAreNeverApproximateDecimalHours(): void
    {
        self::assertSame('0 h 1 min', PayslipHourlyDetail::duration(50));
        self::assertSame('0,001 h', PayslipHourlyDetail::duration(3));
        self::assertSame('0 h 1 min 3,6 s', PayslipHourlyDetail::duration(53));
        self::assertSame('-4 h', PayslipHourlyDetail::duration(-12000));
        self::assertSame('0 h', PayslipHourlyDetail::duration(0));
    }

    public function testB2AnalyticalAndPayableReconcileWithoutRecalculatingMoney(): void
    {
        $detail = PayslipHourlyDetail::claim(Fixture::source(), 101, 'Základ');
        self::assertSame(2845313, $detail['analytical_minor']);
        self::assertSame(2845400, $detail['payable_minor']);
        self::assertSame(87, $detail['rounding_adjustment_minor']);
        self::assertSame(5690625, $detail['raw_numerator_minor']);
        self::assertSame(2, $detail['raw_denominator']);
        self::assertSame('151 h 45 min', $detail['quantity_text']);
        PayslipHourlyDetail::validate($detail);
    }

    public function testCorrectionDoesNotPairFullQuantityWithDeltaMoney(): void
    {
        $source = Fixture::source(492000, 20000, 3200000, 480000);
        $line = PayslipHourlyDetail::input($source, 80000);
        self::assertSame('delta', $line['display_basis']);
        self::assertSame('4 h', $line['quantity_text']);
        self::assertNull($line['rate_minor']);
        $claim = PayslipHourlyDetail::claim($source, 101, 'Základ');
        self::assertSame('164 h', $claim['quantity_text']);
        self::assertSame(3280000, $claim['payable_minor']);
        self::assertSame(80000, $claim['delta_amount_minor']);
    }

    public function testPremiumNeverUsesContractRateAndDeltaHasNoFabricatedQuantity(): void
    {
        $source = Fixture::source(3000, 5000, 4000, 0);
        $source['purpose'] = $source['full_claim']['purpose'] = 'premium_night';
        $source['full_claim']['worked_duration_units'] = 0;
        $source['worked_duration_delta_units'] = 0;
        $line = PayslipHourlyDetail::input($source, 1000);
        self::assertNull($line['quantity_text']);
        self::assertNull($line['rate_minor']);
        $claim = PayslipHourlyDetail::claim($source, 101, 'Příplatek');
        self::assertNull($claim['segments'][0]['rate_minor']);
    }

    public function testMultipleRatesRemainSeparateAndHaveNoMisleadingSummaryRate(): void
    {
        $document = Fixture::document();
        self::assertNull($document->incomeLines[3]->hourlyDetail['rate_minor']);
        self::assertSame([18500, 18984], array_column($document->hourlyClaims[3]['segments'], 'rate_minor'));
        self::assertSame(1874200, array_sum(array_column($document->hourlyClaims[3]['segments'], 'analytical_minor')));
    }

    public function testMinimumSegmentsAreThresholdNotFictitiousPartialTopUps(): void
    {
        $source = Fixture::source(3000, 1600);
        $source['purpose'] = $source['full_claim']['purpose'] = 'minimum_wage_top_up';
        $source['full_claim']['component_code'] = 'DOPLATEK_MINIMALNI_MZDA';
        $source['full_claim']['raw_numerator_minor'] = 1545;
        $source['full_claim']['raw_denominator'] = 1;
        $source['full_claim']['analytical_minor'] = 1545;
        $source['full_claim']['worked_duration_units'] = 0;
        $source['full_claim']['segments'][0]['regular_worked_duration_units'] = 3000;
        $source['full_claim']['segments'][0]['raw_numerator_minor'] = 21545;
        $source['minimum_wage']['assessment_evidence'] = ['threshold' => ['analytical_minor' => 21545], 'eligible' => ['analytical_minor' => 20000]];
        $claim = PayslipHourlyDetail::claim($source, 101, 'Minimum');
        self::assertSame('minimum_threshold', $claim['segment_role']);
        self::assertNull($claim['segments'][0]['rate_minor']);
        self::assertSame(21545, $claim['segments'][0]['analytical_minor']);
        self::assertSame(1600, $claim['payable_minor']);
        self::assertSame(20000, $claim['minimum_assessment']['eligible']['analytical_minor']);
        PayslipHourlyDetail::validate($claim);
    }

    public function testFrozenPremiumPolicyShowsPercentageWithoutBaseRateSubstitution(): void
    {
        $source = Fixture::source(3000, 5000);
        $source['purpose'] = $source['full_claim']['purpose'] = 'premium_overtime';
        $source['full_claim']['segments'][0]['policy_version_id'] = 7;
        $source['calculation_context']['policy_versions'] = [['id' => 7, 'date_from' => '2026-07-01', 'date_to' => '2026-07-31',
            'rates' => ['overtime' => ['statutory_basis' => 'average', 'statutory_rate_bp' => 2500, 'agreed_rate_bp' => 1000]]]];
        $claim = PayslipHourlyDetail::claim($source, 101, 'Přesčas');
        self::assertNull($claim['segments'][0]['rate_minor']);
        self::assertSame('25,00 % průměrného výdělku', $claim['segments'][0]['rate_label']);
    }

    public function testHydratedDetailRejectsChangedDisplayedAnalyticalAmount(): void
    {
        $claim = PayslipHourlyDetail::claim(Fixture::source(), 101, 'Základ');
        $claim['segments'][0]['analytical_minor']++;
        $this->expectException(\DomainException::class);
        PayslipHourlyDetail::validate($claim);
    }

    public function testHtmlAndPdfKeepFrozenFullClaimsAndDeltaDistinct(): void
    {
        $renderer = new PayslipPdfRenderer();
        $document = Fixture::document();
        $html = $renderer->renderHtml($document);
        self::assertStringContainsString('0 h 1 min', $html);
        self::assertStringContainsString('souhrn přepočtené mzdy není částkou nového doplatku', $html);
        self::assertStringContainsString('Zúčtovaný rozdíl: 4 h', $html);
        self::assertStringContainsString('164 h', $html);
        self::assertStringContainsString('Úplný splatný nárok', $html);
        self::assertStringContainsString('Korunové zaokrouhlení nároku', $html);
        $pdf = $renderer->render($document);
        self::assertSame('mz-16-payslip-v5', $pdf->rendererVersion);
        self::assertStringStartsWith('%PDF-', $pdf->pdfBytes);
        $path = getenv('MYINVOICE_HOURLY_PAYSLIP_QA_OUTPUT');
        if (is_string($path) && $path !== '') file_put_contents($path, $pdf->pdfBytes);
    }

    public function testIncludedOvertimeCorrectionDoesNotInventPaidDeltaHours(): void
    {
        $source = Fixture::source(6000, 20000, 20000, 6000);
        $source['purpose'] = $source['full_claim']['purpose'] = 'achieved_overtime';
        $source['full_claim']['worked_duration_units'] = 9000;
        $source['worked_duration_delta_units'] = 3000;
        $line = PayslipHourlyDetail::input($source, 20000);
        self::assertNull($line['quantity_duration_units']);
        self::assertNull($line['rate_minor']);
    }

    public function testCompensationPrintsExplicitAppliedFloorAndDoesNotGuessFromRawAverage(): void
    {
        $source = Fixture::source(3000, 14340);
        $source['purpose'] = $source['full_claim']['purpose'] = 'holiday_time_off_compensation';
        $source['full_claim']['segments'][0]['average_hourly_minor'] = 10000;
        $source['full_claim']['segments'][0]['applied_average_hourly_minor'] = 14340;
        $claim = PayslipHourlyDetail::claim($source, 101, 'Náhrada');
        self::assertSame('Použitý průměr 143,40 Kč/h', $claim['segments'][0]['rate_label']);
        self::assertNull($claim['segments'][0]['rate_minor']);
        unset($source['full_claim']['segments'][0]['applied_average_hourly_minor']);
        self::assertNull(PayslipHourlyDetail::claim($source, 101, 'Náhrada')['segments'][0]['rate_label']);
    }

    public function testRejectsTamperedDelta(): void
    {
        $this->expectException(\DomainException::class);
        PayslipHourlyDetail::input(Fixture::source(), 1);
    }

    public function testRejectsTamperedSegment(): void
    {
        $source = Fixture::source();
        $source['full_claim']['segments'][0]['raw_numerator_minor']++;
        $this->expectException(\DomainException::class);
        PayslipHourlyDetail::claim($source, 101, 'Základ');
    }

    public function testHourlyPageUsesPayslipDateAndCurrencyFormat(): void
    {
        $html = (new PayslipPdfRenderer())->renderHtml(Fixture::document());
        $hourly = substr($html, (int) strpos($html, 'Rozpis hodinových nároků'));
        self::assertStringContainsString('1. 7. 2026 – 31. 7. 2026', $hourly);
        self::assertStringContainsString('· 2026-07</div>', $hourly);
        self::assertStringNotContainsString('2026-07-01', $hourly);
        self::assertStringNotContainsString(' Kč', $hourly);
        self::assertStringContainsString(' CZK', $hourly);
        self::assertStringContainsString('187,50 CZK/h', $html);
    }

    public function testDifficultEnvironmentFactorsAreDeclined(): void
    {
        $label = static function (int $factors): ?string {
            $source = Fixture::source(3000, 5000);
            $source['purpose'] = $source['full_claim']['purpose'] = 'premium_difficult_environment';
            $source['full_claim']['segments'][0] += ['policy_version_id' => 7, 'factors' => $factors];
            $source['calculation_context']['policy_versions'] = [['id' => 7, 'date_from' => '2026-07-01', 'date_to' => '2026-07-31',
                'rates' => ['difficult_environment' => ['statutory_basis' => 'minimum', 'statutory_rate_bp' => 1000, 'agreed_rate_bp' => null]]]];
            return PayslipHourlyDetail::claim($source, 101, 'Ztížené prostředí')['segments'][0]['rate_label'];
        };
        self::assertSame('10,00 % hodinového minima, 3 vlivy', $label(3));
        self::assertSame('10,00 % hodinového minima, 5 vlivů', $label(5));
    }

    public function testConfirmedZeroAgreementHasNoEmptyHourlyPage(): void
    {
        $document = SyntheticPayslipFixture::document();
        $zero = Fixture::source(0, 20000);
        $document = new \MyInvoice\Service\Payroll\Document\PayslipDocumentData(...[...get_object_vars($document), 'hourlyClaims' => [PayslipHourlyDetail::claim($zero, 101, 'Odměna z dohody')]]);
        $html = (new PayslipPdfRenderer())->renderHtml($document);
        self::assertStringNotContainsString('<pagebreak', $html);
        self::assertStringNotContainsString('Rozpis hodinových nároků', $html);
    }

    public function testLegacyRendererVersionIsUnchangedAndHourlyIsDistinct(): void
    {
        self::assertSame('mz-16-payslip-v4', PayslipPdfRenderer::versionFor(SyntheticPayslipFixture::document()));
        self::assertSame('3080460e77d07f0452ec26865044ca902f6cdeea8d424ca93a78d1e3372e98bb', hash('sha256', (new PayslipPdfRenderer())->renderHtml(SyntheticPayslipFixture::document())));
        self::assertSame('mz-16-payslip-v5', PayslipPdfRenderer::versionFor(Fixture::document()));
    }
}
