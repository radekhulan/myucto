<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Absence;

use MyInvoice\Service\Payroll\Absence\AbsenceHolidayTreatment;
use MyInvoice\Service\Payroll\Absence\PayrollWageReplacementTitle;
use MyInvoice\Service\Payroll\PayrollAbsenceValidator;
use PHPUnit\Framework\TestCase;

final class PayrollWageReplacementTitleTest extends TestCase
{
    /**
     * Krácení mzdy se ptá jinak než měsíční hlášení: nepotřebuje pro každý druh
     * absence vykazovaný atribut, jen odpověď „zůstává ta doba v základní
     * mzdě?". U evidované absence je vždycky „ne", takže žádný podporovaný druh
     * nesmí propadnout. Nemoc a karanténa tu chybí záměrně — dělí se oknem
     * § 192 ZP na dva tituly a jednu hodnotu nemají.
     */
    public function testEveryRecordableAbsenceTypeHasATitle(): void
    {
        $reflection = new \ReflectionClass(PayrollAbsenceValidator::class);
        $types = $reflection->getConstant('TYPES');
        self::assertIsArray($types);

        foreach ($types as $type) {
            if ($type === 'dpn' || $type === 'quarantine') {
                self::assertNull(PayrollWageReplacementTitle::forAbsenceType($type));
                continue;
            }
            self::assertNotNull(
                PayrollWageReplacementTitle::forAbsenceType((string) $type),
                "Druh absence {$type} nemá titul náhrady, a krácení mzdy by se u něj zastavilo.",
            );
        }
    }

    /**
     * Zacházení se svátkem musí být doslova to, které při schválení absence
     * rozhodlo o penězích — jinak by se krátilo za jiné hodiny, než za které
     * se náhrada vyplatila.
     */
    public function testHolidayTreatmentMatchesTheMoneyRules(): void
    {
        self::assertSame(
            AbsenceHolidayTreatment::ExcludeFromLeave,
            PayrollWageReplacementTitle::holidayTreatment('vacation'),
        );
        self::assertSame(
            AbsenceHolidayTreatment::CompensateSickness,
            PayrollWageReplacementTitle::holidayTreatment('dpn'),
        );
        self::assertSame(
            AbsenceHolidayTreatment::CompensateSickness,
            PayrollWageReplacementTitle::holidayTreatment('quarantine'),
        );
        self::assertSame(
            AbsenceHolidayTreatment::Ignore,
            PayrollWageReplacementTitle::holidayTreatment('unpaid_leave'),
        );
    }

    /** Náhradní volno za přesčas se neplatí — § 114 odst. 3 ZP. */
    public function testCompensatoryTimeOffAndUnexcusedAbsenceArePaidByNoTitle(): void
    {
        self::assertSame(
            PayrollWageReplacementTitle::Unpaid,
            PayrollWageReplacementTitle::forAbsenceType('compensatory_time_off'),
        );
        self::assertSame(
            PayrollWageReplacementTitle::Unpaid,
            PayrollWageReplacementTitle::forAbsenceType('unexcused'),
        );
    }
}
