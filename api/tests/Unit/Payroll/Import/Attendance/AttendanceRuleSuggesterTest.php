<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Import\Attendance;

use MyInvoice\Service\Payroll\Import\Attendance\AttendanceRules;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceRuleSuggester;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AttendanceRuleSuggesterTest extends TestCase
{
    /** @return iterable<string,array{string,string,?string}> */
    public static function headers(): iterable
    {
        // Hlavičky šablony Vzoru MyÚčto pozná návrh i ve vlastním sešitu.
        yield ['Státní občanství', 'citizenship', null];
        yield ['PSČ', 'address_postal_code', null];
        yield ['Obec trvalého pobytu', 'address_city', null];
        yield ['CZ-ISCO', 'cz_isco_code', null];
        yield ['Místo výkonu práce', 'workplace', null];
        yield ['Prohlášení poplatníka', 'tax_declaration', null];
        yield ['Hodinová sazba (Kč/h)', 'hourly_rate', null];
        yield ['Jméno a příjmení', 'person_name', null];
        yield ['Zaměstnanec', 'person_name', null];
        yield ['Osobní číslo', 'personal_number', null];
        yield ['Rodné číslo', 'birth_number', null];
        yield ['Datum narození', 'birth_date', null];
        yield ['Zdravotní pojišťovna', 'health_insurer_code', null];
        yield ['ZP', 'health_insurer_code', null];
        yield ['Druh poměru', 'relation_label', null];
        yield ['Oddělení', 'department', null];
        yield ['Středisko', 'cost_center', null];
        yield ['Název pozice', 'position', null];
        yield ['Týdenní fond', 'weekly_hours', null];
        yield ['Nástup/ukončení', 'start_end_note', null];
        yield ['Fond prac. doby', 'fund_hours', null];
        yield ['Práce celkem', 'worked_hours', null];
        yield ["Práce\ncelkem", 'worked_hours', null];
        yield ['Odpracováno', 'worked_hours', null];
        yield ['Přesčas', 'overtime_hours', null];
        yield ['Volno za přesčas', 'compensatory_time_off_hours', null];
        yield ['Práce noční', 'night_hours', null];
        yield ['Práce o víkendu', 'weekend_hours', null];
        yield ['Práce ve svátek', 'holiday_work_hours', null];
        yield ['Svátek', 'holiday_hours', null];
        yield ['Odpolední', 'afternoon_hours', null];
        yield ['Dovolená', 'vacation_hours', null];
        yield ['Nemoc', 'sick_hours', null];
        yield ['DPN', 'sick_hours', null];
        yield ['Lékař', 'doctor_hours', null];
        yield ['OČR', 'care_hours', null];
        yield ['Ošetření člena rodiny', 'care_hours', null];
        yield ['Otcovská', 'paternity_hours', null];
        yield ['Neplacené volno', 'unpaid_leave_hours', null];
        yield ['Neomluvená absence', 'unexcused_hours', null];
        yield ['Paragraf', 'obstacle_employee_hours', null];
        yield ['Překážka na straně zaměstnavatele', 'obstacle_employer_hours', null];
        yield ['Služební cesta', 'business_trip_hours', null];
        yield ['Home office', 'home_office_hours', null];
        yield ['Hrubá mzda', 'reference_gross', null];
        yield ['Čistá mzda', 'reference_net', null];
        yield ['Hodiny', 'reference_hours', null];
        yield ['Odměna', 'component', 'ODMENA'];
        yield ['Prémie', 'component', 'ODMENA'];
        yield ['Mzda úkol', 'component', 'MZDA_UKOLOVA'];
        yield ['Úkolová mzda', 'component', 'MZDA_UKOLOVA'];
        yield ['Příplatek noční', 'component', 'PREMIE_PRIPLATKY'];
        yield ['Srážky', 'ignore', null];
        yield ['Poznámka', 'ignore', null];
    }

    #[DataProvider('headers')]
    public function testSuggestsMeaningFromCzechHeader(string $header, string $meaning, ?string $component): void
    {
        self::assertSame(
            ['meaning' => $meaning, 'component_code' => $component],
            (new AttendanceRuleSuggester())->suggest($header),
        );
    }

    public function testRuleValidationRejectsUnitThatDoesNotFitMeaning(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AttendanceRules::validate([
            ['sheet' => null, 'header' => 'Dovolená', 'meaning' => 'vacation_hours', 'unit' => 'amount', 'component_code' => null],
        ]);
    }

    public function testRuleValidationRequiresComponentCodeForComponent(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AttendanceRules::validate([
            ['sheet' => null, 'header' => 'Odměna', 'meaning' => 'component', 'unit' => 'amount', 'component_code' => null],
        ]);
    }

    public function testRuleValidationNormalizesComponentCode(): void
    {
        $rules = AttendanceRules::validate([
            ['sheet' => ' ', 'header' => ' Odměna ', 'meaning' => 'component', 'unit' => null, 'component_code' => 'odmena'],
        ]);

        self::assertSame(
            [['sheet' => null, 'header' => 'Odměna', 'meaning' => 'component', 'unit' => null, 'component_code' => 'ODMENA']],
            $rules,
        );
    }
}
