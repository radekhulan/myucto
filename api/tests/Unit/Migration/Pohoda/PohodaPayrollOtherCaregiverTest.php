<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\Pohoda;

use MyInvoice\Service\Migration\Pohoda\Payroll\PohodaPayrollJmhzWriter;
use MyInvoice\Service\Payroll\Import\Jmhz\JmhzReportForm;
use PHPUnit\Framework\TestCase;

/**
 * Odpověď „tytéž děti vyživuje i jiná osoba" (10453) z podaných hlášení PAMICA. Bez ní
 * měsíční hlášení v MyÚčtu nejde zmrazit, přestože předchozí program ji ČSSZ už
 * sdělil. Převezme se jen poslední odpověď formuláře, který zvýhodnění na děti nese.
 *
 * Syntetická data, žádné reálné osoby.
 */
final class PohodaPayrollOtherCaregiverTest extends TestCase
{
    public function testLatestAnswerOfAFormWithChildrenWins(): void
    {
        self::assertFalse(PohodaPayrollJmhzWriter::otherCaregiver([
            '2026-05' => [self::form(true)],
            '2026-06' => [self::form(false)],
        ]));
        self::assertTrue(PohodaPayrollJmhzWriter::otherCaregiver([
            '2026-06' => [self::form(true)],
            '2026-05' => [self::form(false)],
        ]), 'Pořadí měsíců rozhoduje, ne pořadí v poli.');
    }

    public function testFormsWithoutChildrenOrSignatureAreIgnored(): void
    {
        self::assertNull(PohodaPayrollJmhzWriter::otherCaregiver([
            '2026-06' => [self::form(false, children: false)],
            '2026-07' => [self::form(false, signed: false)],
        ]));
        self::assertNull(PohodaPayrollJmhzWriter::otherCaregiver(['2026-07' => [self::form(null)]]));
    }

    public function testOtherCaregiverIsTakenFromTheLatestFormNamingThem(): void
    {
        $caregiver = ['given_name' => 'Jana', 'family_name' => 'Zkušební', 'birth_date' => null, 'birth_number' => '8551011238'];

        self::assertSame(
            ['given_name' => 'Jana', 'family_name' => 'Zkušební', 'birth_date' => '1985-01-01', 'birth_number' => '855101/1238'],
            PohodaPayrollJmhzWriter::otherCaregiverPerson(['2026-05' => [self::form(true, caregivers: [$caregiver])]]),
            'Datum narození z rodného čísla, když ho hlášení neuvádí.',
        );
        self::assertNull(PohodaPayrollJmhzWriter::otherCaregiverPerson(['2026-05' => [self::form(true)]]), 'Hlášení osobu nejmenuje.');
        self::assertNull(PohodaPayrollJmhzWriter::otherCaregiverPerson(['2026-05' => [self::form(false, caregivers: [$caregiver])]]));
    }

    /** @param list<array<string,?string>> $caregivers */
    private static function form(?bool $otherCaregiver, bool $children = true, bool $signed = true, array $caregivers = []): JmhzReportForm
    {
        return new JmhzReportForm(
            position: 1,
            formGuid: '00000000-0000-0000-0000-000000000001',
            formType: 'R',
            primary: true,
            variant: 'bezPriznaku',
            hasSummary: true,
            declarationSigned: $signed,
            childCredit: [
                'monthly' => 1267,
                'applied' => 1267,
                'other_caregiver' => $otherCaregiver,
                'caregivers' => $caregivers,
                'children' => $children ? [['order' => '1', 'given_name' => 'Dítě', 'family_name' => 'Zkušební', 'birth_number' => null, 'birth_date' => '2020-01-01', 'ztp_p' => false]] : [],
            ],
        );
    }
}
