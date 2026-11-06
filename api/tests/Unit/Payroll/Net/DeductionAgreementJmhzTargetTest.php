<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Net;

use MyInvoice\Service\Payroll\Net\DeductionAgreementTerms;
use PHPUnit\Framework\TestCase;

/**
 * Věřitel a zařazení dohody o srážce pro JMHZ (10349 až 10353, IN24/IN28).
 *
 * Pokyny MPSV k MH 1.4.14 (kap. 2.4.15) uvádějí zápůjčku u 10350 i u 10353,
 * takže zařazení se ukládá výslovně a jen u srážky ve prospěch zaměstnavatele.
 * Srážka ze zákona (§ 147 odst. 1 ZP) do 10350 až 10353 nepatří: datový slovník
 * 1.4.1.6 vede 10353 jako srážky „podle § 146 písm. b) ZP", tedy z dohody.
 */
final class DeductionAgreementJmhzTargetTest extends TestCase
{
    public function testEmployerCreditorKeepsTheExplicitTarget(): void
    {
        $terms = DeductionAgreementTerms::fromRequest($this->body([
            'deduction_kind' => 'other',
            'creditor_is_employer' => true,
            'jmhz_target' => '10350',
        ]));

        self::assertTrue($terms->creditorIsEmployer);
        self::assertSame('10350', $terms->jmhzTarget);
        self::assertTrue($terms->jmhzClassificationProvided);
    }

    public function testThirdPartyCreditorHasNoTarget(): void
    {
        $terms = DeductionAgreementTerms::fromRequest($this->body(['creditor_is_employer' => false]));

        self::assertFalse($terms->creditorIsEmployer);
        self::assertNull($terms->jmhzTarget);
    }

    /** Request bez zařazení (import, starší klient) ponechá uložené hodnoty dohody. */
    public function testMissingClassificationKeepsTheCurrentValues(): void
    {
        $current = DeductionAgreementTerms::fromRequest($this->body([
            'creditor_is_employer' => true,
            'jmhz_target' => '10352',
        ]));
        $terms = DeductionAgreementTerms::fromRequest($this->body(), $current);

        self::assertTrue($terms->creditorIsEmployer);
        self::assertSame('10352', $terms->jmhzTarget);
        self::assertFalse($terms->jmhzClassificationProvided);
        self::assertNull(DeductionAgreementTerms::fromRequest($this->body())->creditorIsEmployer);
    }

    /** @return iterable<string, array{array<string,mixed>, string}> */
    public static function invalidClassifications(): iterable
    {
        yield 'zaměstnavatel bez zařazení' => [['creditor_is_employer' => true], 'vyberte'];
        yield 'zařazení u třetí osoby' => [['creditor_is_employer' => false, 'jmhz_target' => '10353'], 'jen ke srážce'];
        yield 'zařazení mimo 10350 až 10353' => [['creditor_is_employer' => true, 'jmhz_target' => '10116'], '10350'];
        yield 'věřitel jako text' => [['creditor_is_employer' => 'ano'], 'Věřitelem je zaměstnavatel'];
        yield 'srážka ze zákona' => [[
            'legal_basis' => 'zp_147_1_c',
            'delivered_on' => '2026-03-01',
            'creditor_is_employer' => true,
            'jmhz_target' => '10353',
        ], '§ 147 odst. 1'];
    }

    /** @param array<string,mixed> $overrides */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidClassifications')]
    public function testInvalidClassificationIsRejected(array $overrides, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        DeductionAgreementTerms::fromRequest($this->body($overrides));
    }

    /** Srážka ze zákona bez zařazení zařazení vynuluje, i když dohoda dřív nějaké měla. */
    public function testStatutoryDeductionClearsTheClassification(): void
    {
        $current = DeductionAgreementTerms::fromRequest($this->body([
            'creditor_is_employer' => true,
            'jmhz_target' => '10350',
        ]));
        $terms = DeductionAgreementTerms::fromRequest($this->body([
            'legal_basis' => 'zp_147_1_c',
            'delivered_on' => '2026-03-01',
        ]), $current);

        self::assertNull($terms->creditorIsEmployer);
        self::assertNull($terms->jmhzTarget);
        self::assertTrue($terms->jmhzClassificationProvided);
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function body(array $overrides = []): array
    {
        return [
            'title' => 'Syntetická zápůjčka',
            'deduction_kind' => 'advance',
            'priority_no' => 100,
            'requested_minor' => 100_000,
            'valid_from' => '2026-03-01',
            ...$overrides,
        ];
    }
}
