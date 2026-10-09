<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission\Registration;

use MyInvoice\Service\Payroll\PayrollEmploymentJmhzEvidenceCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzExternalCodebookCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSpecPackageCatalog;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationEventService;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationXmlException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Podklady pro podporu v nezaměstnanosti v odhlášce A2-OST (EDV REGZEC25
 * 1.4.0.6, blok `unemplcomp`), jak je skládá služba oznámení:
 *
 * - odchodné (10532) a odbytné (10533) jsou povinné (P) jen u služebního
 *   poměru s důvodem 4 nebo 5 a vzniklým nárokem, jinak zakázané (Z),
 * - důvod ukončení služebního poměru (10381) je povinný u služebního poměru
 *   a zakázaný u pracovního vztahu, kde místo něj stojí 10380 s částkami
 *   10530 a 10531 (vzájemné vyloučení),
 * - číselníky C_DUVUKSLUZPOM (10381) a důvodů předčasného ukončení (10534)
 *   odpovídají seznamu kódů z matice norem (EDV 1.4.0.6, listy
 *   C_DUVUKSLUZPOM_ a CIS_důvodů_ukončení_zaměstnání_).
 */
final class PayrollRegistrationA2UnemploymentConditionsTest extends TestCase
{
    /** EDV 1.4.0.6, list C_DUVUKSLUZPOM_ (matice REGZEC25-unemplcomp.rsnterrel-05). */
    private const SERVICE_TERMINATION_CODES = ['1', '2', '3', '4', '5', '6'];

    /** EDV 1.4.0.6, list CIS_důvodů_ukončení_zaměstnání_ (matice REGZEC25-unemplcomp.earlyterm-05). */
    private const EARLY_TERMINATION_CODES = ['1', '2', '3'];

    /** @return iterable<string,array{string,int}> */
    public static function serviceSettlementAmounts(): iterable
    {
        yield 'odchodné' => ['severance_pay', 40_000];
        yield 'odbytné' => ['disposal', 15_000];
    }

    #[DataProvider('serviceSettlementAmounts')]
    public function testServiceSettlementAmountIsSentWhenEntitlementArises(string $key, int $amount): void
    {
        $result = $this->unemployment($this->service(['service_termination_reason' => '4', 'entitlement' => true, 'paid_in_full' => true, $key => $amount]));

        self::assertSame('2', $result['employment_type']);
        self::assertSame('4', $result['service_termination_reason']);
        self::assertSame('A', $result['entitlement']);
        self::assertSame($amount, (int) $result[$key]);
    }

    public function testServiceSettlementAmountIsRequiredWhenEntitlementArises(): void
    {
        $this->assertRefused(
            'registration_a2_settlement_amount_required',
            $this->service(['service_termination_reason' => '5', 'entitlement' => true, 'paid_in_full' => true]),
        );
        $this->assertRefused(
            'registration_a2_settlement_amount_required',
            $this->service(['service_termination_reason' => '5', 'entitlement' => true, 'paid_in_full' => true, 'severance_pay' => 1, 'disposal' => 1]),
        );
    }

    #[DataProvider('serviceSettlementAmounts')]
    public function testServiceSettlementAmountIsForbiddenForOtherReasons(string $key, int $amount): void
    {
        $this->assertRefused(
            'registration_a2_settlement_forbidden',
            $this->service(['service_termination_reason' => '1', $key => $amount]),
        );
    }

    #[DataProvider('serviceSettlementAmounts')]
    public function testServiceSettlementAmountIsForbiddenWithoutEntitlement(string $key, int $amount): void
    {
        $this->assertRefused(
            'registration_a2_settlement_payment_forbidden',
            $this->service(['service_termination_reason' => '4', 'entitlement' => false, $key => $amount]),
        );
    }

    public function testServiceTerminationReasonIsRequiredForServiceRelationship(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->unemployment($this->service([]));
    }

    /** Podmínka Z: důvod neposkytnutí podkladů 2 nebo 3 (10376) důvod 10381 zakazuje. */
    public function testServiceTerminationReasonIsForbiddenWithoutProvidedDocuments(): void
    {
        $this->assertInvalid(['mode' => 'not_provided_2', 'service_termination_reason' => '1']);
        $this->assertInvalid(['mode' => 'not_provided_3', 'average_net_earnings' => 25_000,
            'pension_periods' => [['from' => '2026-01-01', 'to' => '2026-06-30']], 'service_termination_reason' => '1']);
        $this->assertInvalid(['mode' => 'not_provided_3', 'average_net_earnings' => 25_000,
            'pension_periods' => [['from' => '2026-01-01', 'to' => '2026-06-30']], 'disposal' => 1000]);
        self::assertSame(['reason_not_provided' => 2], $this->unemployment(['mode' => 'not_provided_2']));
    }

    public function testServiceAndEmploymentPartsAreMutuallyExclusive(): void
    {
        $this->assertInvalid($this->service(['service_termination_reason' => '1', 'termination_reason' => '1']));
        $this->assertInvalid($this->service(['service_termination_reason' => '4', 'entitlement' => true, 'paid_in_full' => true, 'golden_handshake' => 1000]));
        $this->assertInvalid($this->employment(['termination_reason' => '1', 'service_termination_reason' => '1']));
        $this->assertInvalid($this->employment(['termination_reason' => '4', 'entitlement' => true, 'paid_in_full' => true, 'severance_pay' => 1000]));
        $this->assertInvalid($this->employment(['termination_reason' => '4', 'entitlement' => true, 'paid_in_full' => true, 'disposal' => 1000]));
    }

    public function testServiceTerminationCodebookMatchesNormMatrix(): void
    {
        foreach (self::SERVICE_TERMINATION_CODES as $code) {
            $result = $this->unemployment($this->service(['service_termination_reason' => $code]
                + (in_array($code, ['4', '5'], true) ? ['entitlement' => false] : [])));
            self::assertSame($code, $result['service_termination_reason']);
        }
        foreach (['0', '7', '9', '10'] as $code) {
            $this->assertInvalid($this->service(['service_termination_reason' => $code]), "kód {$code}");
        }
    }

    public function testEarlyTerminationCodebookMatchesNormMatrix(): void
    {
        $catalog = $this->catalog();
        foreach (self::EARLY_TERMINATION_CODES as $code) {
            $catalog->requireEarlyTerminationReason($code);
        }
        foreach (['0', '4', '9'] as $code) {
            try {
                $catalog->requireEarlyTerminationReason($code);
                self::fail("Důvod předčasného ukončení {$code} není v číselníku a musí se odmítnout.");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $result = $this->invoke(['mode' => 'not_provided_2', 'early_termination_reason' => '2'], true);
        self::assertSame('2', $result['early_termination_reason']);
        foreach (['0', '4'] as $code) {
            $this->expectRefusal(fn () => $this->invoke(['mode' => 'not_provided_2', 'early_termination_reason' => $code], true), "kód {$code}");
        }
    }

    /** @param array<string,mixed> $extra @return array<string,mixed> */
    private function service(array $extra): array
    {
        return $this->base() + ['employment_type' => '2'] + $extra;
    }

    /** @param array<string,mixed> $extra @return array<string,mixed> */
    private function employment(array $extra): array
    {
        return $this->base() + ['employment_type' => '1'] + $extra;
    }

    /** @return array<string,mixed> */
    private function base(): array
    {
        return [
            'mode' => 'provided',
            'average_net_earnings' => 25_000,
            'pension_periods' => [['from' => '2026-01-01', 'to' => '2026-06-30']],
        ];
    }

    /** @param array<string,mixed> $input */
    private function assertRefused(string $code, array $input): void
    {
        try {
            $this->unemployment($input);
            self::fail("Podání muselo skončit kódem {$code}.");
        } catch (PayrollRegistrationXmlException $exception) {
            self::assertSame($code, $exception->validationCode);
        }
    }

    /** @param array<string,mixed> $input */
    private function assertInvalid(array $input, string $label = ''): void
    {
        $this->expectRefusal(fn () => $this->unemployment($input), $label);
    }

    private function expectRefusal(callable $work, string $label): void
    {
        try {
            $work();
            self::fail('Podklady se musely odmítnout. ' . $label);
        } catch (\InvalidArgumentException|PayrollRegistrationXmlException) {
            $this->addToAssertionCount(1);
        }
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function unemployment(array $input): array
    {
        $result = $this->invoke($input, false);
        self::assertIsArray($result);

        return $result;
    }

    /** @param array<string,mixed>|null $input @return array<string,mixed>|null */
    private function invoke(?array $input, bool $earlyTerminationApplies): ?array
    {
        $reflection = new \ReflectionClass(PayrollRegistrationEventService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('jmhzEvidence')->setValue($service, $this->catalog());

        /** @var array<string,mixed>|null */
        return $reflection->getMethod('unemployment')->invoke(
            $service,
            $input,
            'OST',
            '1',
            false,
            ['start_date' => '2026-01-01', 'end_date' => '2026-06-30'],
            $earlyTerminationApplies,
        );
    }

    private function catalog(): PayrollEmploymentJmhzEvidenceCatalog
    {
        return new PayrollEmploymentJmhzEvidenceCatalog(
            new JmhzSpecPackageCatalog(),
            new JmhzExternalCodebookCatalog(new JmhzSpecPackageCatalog()),
        );
    }
}
