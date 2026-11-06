<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Import\Attendance;

/**
 * Významy sloupců a jednotky importu docházky.
 *
 * Záměrně konstanty, ne `enum`: stejné hodnoty drží klient jako union typy
 * a kontrakt je páruje přes `const:` (PayrollEnumContractTest).
 */
final class AttendanceMeaning
{
    public const SOURCE_SYSTEM = 'attendance';

    public const UNIT_HOURS = 'hours';
    public const UNIT_DURATION = 'excel_duration';
    public const UNIT_AMOUNT = 'amount';
    public const UNIT_TEXT = 'text';

    public const UNITS = [
        self::UNIT_HOURS,
        self::UNIT_DURATION,
        self::UNIT_AMOUNT,
        self::UNIT_TEXT,
    ];

    /** Stavy párování osoby na pracovní vztah ({@see AttendancePersonMatcher}). */
    public const MATCH_STATUSES = ['linked', 'matched', 'ambiguous', 'not_found'];

    public const IGNORE = 'ignore';
    public const PERSON_NAME = 'person_name';
    public const COMPONENT = 'component';

    /** Údaje o osobě — berou se jako text, první neprázdná hodnota vyhrává. */
    public const IDENTITY = [
        'person_name',
        'personal_number',
        'birth_number',
        'birth_date',
        'health_insurer_code',
        'relation_label',
        'department',
        'cost_center',
        'position',
        'weekly_hours',
        'start_end_note',
        'start_date',
        'monthly_wage',
        'hourly_rate',
        'end_date',
        'bank_account',
    ];

    /** Měsíční souhrny hodin, které jdou do evidence dávky. */
    public const HOURS = [
        'worked_hours',
        'overtime_hours',
        'night_hours',
        'weekend_hours',
        'holiday_work_hours',
        'afternoon_hours',
        'fund_hours',
        'vacation_hours',
        'holiday_hours',
        'sick_hours',
        'doctor_hours',
        'care_hours',
        'paternity_hours',
        'unpaid_leave_hours',
        'unexcused_hours',
        'obstacle_employee_hours',
        'obstacle_employer_hours',
        'business_trip_hours',
        'home_office_hours',
        'compensatory_time_off_hours',
    ];

    /** Kontrolní hodnoty z mzdového exportu — jen k porovnání, nic nezakládají. */
    public const REFERENCE = [
        'reference_gross',
        'reference_net',
        'reference_hours',
    ];

    /**
     * Srážky z čisté mzdy (obědy placené zaměstnancem, jiné srážky). Do hrubé
     * mzdy nejdou; import z nich založí dohodu o srážce na importovaný měsíc.
     */
    public const DEDUCTIONS = [
        'net_meal_deduction',
        'net_other_deduction',
    ];

    public const ALL = [
        self::IGNORE,
        ...self::IDENTITY,
        ...self::HOURS,
        self::COMPONENT,
        ...self::DEDUCTIONS,
        ...self::REFERENCE,
    ];

    public static function isValid(string $meaning): bool
    {
        return in_array($meaning, self::ALL, true);
    }

    public static function isHours(string $meaning): bool
    {
        return in_array($meaning, self::HOURS, true) || $meaning === 'reference_hours';
    }

    public static function isMoney(string $meaning): bool
    {
        return in_array($meaning, ['component', 'reference_gross', 'reference_net'], true) || self::isDeduction($meaning);
    }

    public static function isDeduction(string $meaning): bool
    {
        return in_array($meaning, self::DEDUCTIONS, true);
    }

    public static function isIdentity(string $meaning): bool
    {
        return in_array($meaning, self::IDENTITY, true);
    }

    /**
     * Identitní údaje (i sazba, konec dohody a číslo účtu) jsou text; čísla
     * a data z nich čte až agregace a zakládání osob.
     *
     * @return list<string>
     */
    public static function allowedUnits(string $meaning): array
    {
        if (self::isHours($meaning)) {
            return [self::UNIT_HOURS, self::UNIT_DURATION];
        }
        if (self::isMoney($meaning)) {
            return [self::UNIT_AMOUNT];
        }
        if ($meaning === 'weekly_hours') {
            return [self::UNIT_TEXT, self::UNIT_HOURS, self::UNIT_DURATION];
        }

        return [self::UNIT_TEXT];
    }
}
