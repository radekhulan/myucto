<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Import\Attendance;

use MyInvoice\Service\Payroll\Import\Attendance\AttendanceBirthNumber;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceColumnMapper;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceMyUctoSampleProfile;
use MyInvoice\Service\Payroll\Import\Attendance\AttendancePersonAggregator;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceRuleSuggester;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceSheetAnalyzer;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceWorkbookReader;
use PHPUnit\Framework\TestCase;

/**
 * Rodné číslo uložené v Excelu jako číslo (ročníky od 2000 ztratí úvodní
 * nulu) a odpracované dny ze sloupce podkladů.
 */
final class AttendanceBirthNumberTest extends TestCase
{
    public function testNineDigitNumberIsPaddedToValidTenDigitBirthNumber(): void
    {
        $birthNumber = AttendanceFixture::birthNumber('2001-03-01', 'male', 1);
        $asNumber = ltrim(str_replace('/', '', $birthNumber), '0');
        self::assertSame(9, strlen($asNumber));

        $read = AttendanceBirthNumber::read($asNumber, null);

        self::assertSame($birthNumber, $read['value']);
        self::assertNull($read['error']);
        self::assertSame([AttendanceBirthNumber::PADDED_WARNING], $read['warnings']);
    }

    public function testYear2000LosesTwoZerosAndIsStillPadded(): void
    {
        $birthNumber = AttendanceFixture::birthNumber('2000-11-20', 'male', 3);
        $asNumber = ltrim(str_replace('/', '', $birthNumber), '0');
        self::assertSame(8, strlen($asNumber));

        self::assertSame($birthNumber, AttendanceBirthNumber::read($asNumber, null)['value']);
    }

    public function testGenuineNineDigitBirthNumberStaysWithWarning(): void
    {
        // Devítimístné RČ narozeného 1. 3. 1950 (před rokem 1954 bez kontrolní číslice);
        // doplněné nulou by bylo z roku 2005 a kontrolou modulo 11 neprojde.
        $read = AttendanceBirthNumber::read('500301123', null);
        $padded = AttendanceBirthNumber::read('0500301123', null);

        self::assertSame('500301/123', $read['value']);
        self::assertSame('0500301123', $padded['value'], 'Doplněné číslo není platné RČ, zůstává text z podkladů.');
        self::assertSame([AttendanceBirthNumber::NINE_DIGITS_WARNING], $read['warnings']);
    }

    public function testBirthDateDecidesBetweenReadingsAndMismatchIsAnError(): void
    {
        $birthNumber = AttendanceFixture::birthNumber('2001-03-01', 'male', 1);
        $asNumber = ltrim(str_replace('/', '', $birthNumber), '0');

        self::assertSame($birthNumber, AttendanceBirthNumber::read($asNumber, '2001-03-01')['value']);

        $mismatch = AttendanceBirthNumber::read($birthNumber, '2001-03-02');
        self::assertSame($birthNumber, $mismatch['value']);
        self::assertStringContainsString('neodpovídá datu narození 2. 3. 2001', (string) $mismatch['error']);

        self::assertNull(AttendanceBirthNumber::read($birthNumber, '2001-03-01')['error']);
    }

    public function testTextWithSlashAndInvalidNumbersAreUntouched(): void
    {
        $birthNumber = AttendanceFixture::birthNumber('1990-05-01', 'female', 1);
        self::assertSame($birthNumber, AttendanceBirthNumber::read(str_replace('/', '', $birthNumber), null)['value']);
        self::assertSame(['value' => null, 'warnings' => [], 'error' => null], AttendanceBirthNumber::read('  ', null));
        $invalid = AttendanceBirthNumber::read('9055150006', null);
        self::assertSame('9055150006', $invalid['value']);
        self::assertSame([], $invalid['warnings']);
    }

    public function testAggregatorPadsNumericCellAndReadsWorkedDays(): void
    {
        $birthNumber = AttendanceFixture::birthNumber('2001-03-01', 'male', 1);
        $file = AttendanceFixture::file('duben.xlsx', AttendanceFixture::xlsx([
            AttendanceMyUctoSampleProfile::SHEET => ['rows' => [
                1 => ['A' => 'Příjmení a jméno', 'B' => 'Rodné číslo', 'C' => 'Odpracované dny', 'D' => 'Odpracováno (hod)'],
                2 => ['A' => 'Číselná Eva', 'B' => (int) str_replace('/', '', $birthNumber), 'C' => 6, 'D' => 40],
                3 => ['A' => 'Chybná Ida', 'C' => 'hodně', 'D' => 8],
            ]],
        ]));
        $sheets = (new AttendanceWorkbookReader())->read($file['name'], 0, $file['extension'], $file['content']);
        $suggester = new AttendanceRuleSuggester();
        $mapper = new AttendanceColumnMapper(new AttendanceSheetAnalyzer($suggester), $suggester);
        $mapped = $mapper->map($sheets, AttendanceMyUctoSampleProfile::rules());
        $persons = array_column((new AttendancePersonAggregator($mapper))->aggregate($mapped['sheets']), null, 'display_name');

        $eva = $persons['Číselná Eva'];
        self::assertSame($birthNumber, $eva['_birth_number']);
        self::assertContains(AttendanceBirthNumber::PADDED_WARNING, $eva['warnings']);
        self::assertSame(6, $eva['worked_days']);
        self::assertStringEndsWith('!C2', (string) $eva['_worked_days_source']);

        $ida = $persons['Chybná Ida'];
        self::assertNull($ida['worked_days']);
        self::assertStringContainsString('Odpracované dny „hodně“ nejde přečíst', implode(' ', $ida['warnings']));
    }

    public function testWorkedDaysParsingAndSuggestion(): void
    {
        self::assertSame(21, AttendancePersonAggregator::workedDays('21'));
        self::assertSame(5, AttendancePersonAggregator::workedDays('5,0'));
        self::assertNull(AttendancePersonAggregator::workedDays('2,5'));
        self::assertNull(AttendancePersonAggregator::workedDays('32'));
        self::assertNull(AttendancePersonAggregator::workedDays('-1'));

        $suggester = new AttendanceRuleSuggester();
        self::assertSame('worked_days', $suggester->suggest('Odpracované dny')['meaning']);
        self::assertSame('worked_days', $suggester->suggest('Počet odpracovaných dnů')['meaning']);
        self::assertSame('worked_hours', $suggester->suggest('Odpracováno (hod)')['meaning']);
        self::assertSame('worked_hours', $suggester->suggest('Odpracované hodiny')['meaning']);
    }
}
