<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission\Registration;

use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationBusinessMatrix;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationXmlException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PayrollRegistrationBusinessMatrixTest extends TestCase
{
    /** @return iterable<string,array{string,?string,string}> */
    public static function variants(): iterable
    {
        yield 'activity 10' => ['10', null, '10'];
        yield 'specific activity 11' => ['11', '1', 'SPEC'];
        yield 'prison relationship' => ['1', '2', 'SPEC'];
        yield 'ordinary employment' => ['1', '1', 'OST'];
        yield 'specific-group employment remains full OST' => ['1', '3', 'OST'];
        yield 'customary activity without detail' => ['A', null, 'OST'];
    }

    #[DataProvider('variants')]
    public function testDerivesTheOfficialVariant(
        string $activityCode,
        ?string $relationshipDetailCode,
        string $expected,
    ): void {
        self::assertSame(
            $expected,
            PayrollRegistrationBusinessMatrix::requireActionVariant(
                2,
                $activityCode,
                $relationshipDetailCode,
            ),
        );
    }

    public function testOfficialActionVariantMatrixIsCentralAndCallable(): void
    {
        foreach ([1, 2, 3, 4] as $actionCode) {
            self::assertSame(
                ['OST', '10', 'SPEC'],
                PayrollRegistrationBusinessMatrix::variantsForAction($actionCode),
            );
        }
        foreach ([5, 6, 7, 8] as $actionCode) {
            self::assertSame(
                ['OST'],
                PayrollRegistrationBusinessMatrix::variantsForAction($actionCode),
            );
        }
    }

    public function testA5ToA8RejectEveryNonOstVariant(): void
    {
        foreach ([5, 6, 7, 8] as $actionCode) {
            foreach ([['10', null], ['11', '1']] as [$activity, $detail]) {
                $this->expectCode(
                    'registration_regzec_action_variant_unsupported',
                    static fn () => PayrollRegistrationBusinessMatrix::requireActionVariant(
                        $actionCode,
                        $activity,
                        $detail,
                    ),
                );
            }
        }
    }

    public function testA1RequiresActivityAndCompleteVariantData(): void
    {
        $this->expectCode(
            'registration_regzec_a1_activity_missing',
            static fn () => PayrollRegistrationBusinessMatrix::requireActionVariant(
                1,
                null,
                null,
                false,
            ),
        );
        $this->expectCode(
            'registration_regzec_a1_variant_data_incomplete',
            static fn () => PayrollRegistrationBusinessMatrix::requireActionVariant(
                1,
                '1',
                '1',
                false,
            ),
        );
    }

    private function expectCode(string $code, callable $callback): void
    {
        try {
            $callback();
            self::fail("Očekávána chyba {$code}.");
        } catch (PayrollRegistrationXmlException $exception) {
            self::assertSame($code, $exception->validationCode);
        }
    }
}
