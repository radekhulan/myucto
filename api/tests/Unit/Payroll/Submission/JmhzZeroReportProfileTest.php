<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzXmlException;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzZeroReportProfile;
use PHPUnit\Framework\TestCase;

final class JmhzZeroReportProfileTest extends TestCase
{
    public function testBothDocumentedTablesArePinned(): void
    {
        self::assertSame(
            [
                JmhzZeroReportProfile::SITUATION_AMENDMENT_UNREPORTABLE_MONTH,
                JmhzZeroReportProfile::SITUATION_REGULAR_WITHOUT_WORK,
            ],
            JmhzZeroReportProfile::situations(),
        );
        self::assertSame(
            JmhzZeroReportProfile::TABLES_SHA256,
            JmhzZeroReportProfile::fingerprint(),
        );
    }

    /**
     * Obě tabulky pokrývají tytéž atributy — liší se jen předpisem. Kdyby jedna
     * atribut vynechala, chyběl by v generovaném hlášení bez varování.
     */
    public function testTablesCoverTheSameAttributes(): void
    {
        $amendment = JmhzZeroReportProfile::table(
            JmhzZeroReportProfile::SITUATION_AMENDMENT_UNREPORTABLE_MONTH,
        );
        $regular = JmhzZeroReportProfile::table(
            JmhzZeroReportProfile::SITUATION_REGULAR_WITHOUT_WORK,
        );

        self::assertSame(array_keys($amendment), array_keys($regular));
        self::assertCount(37, $amendment);
    }

    /**
     * Jádro rizika kap. 4: obě tabulky vypadají skoro stejně. Test drží přesný
     * seznam atributů, ve kterých se liší — kdyby se sblížily, záměna situace
     * by přestala být poznat, a to je chyba, která vykáže neexistující
     * pojistný vztah.
     */
    public function testTablesDifferExactlyInTheDocumentedAttributes(): void
    {
        $amendment = JmhzZeroReportProfile::table(
            JmhzZeroReportProfile::SITUATION_AMENDMENT_UNREPORTABLE_MONTH,
        );
        $regular = JmhzZeroReportProfile::table(
            JmhzZeroReportProfile::SITUATION_REGULAR_WITHOUT_WORK,
        );
        // PHP převádí číselné klíče pole na int, takže se musí vrátit na text.
        $different = [];
        foreach ($amendment as $attributeId => $rule) {
            if ($rule !== $regular[$attributeId]) {
                $different[] = (string) $attributeId;
            }
        }
        sort($different);

        self::assertSame(JmhzZeroReportProfile::divergentAttributes(), $different);
    }

    /**
     * Nejnápadnější rozdíl: anulační hlášení smrskne trvání pojištění na jediný
     * den, kdežto řádné nulové hlášení pokrývá celý měsíc.
     */
    public function testInsuranceIntervalIsASingleDayOnlyWhenAnnulling(): void
    {
        self::assertSame(
            JmhzZeroReportProfile::RULE_SINGLE_DAY,
            JmhzZeroReportProfile::rule(
                JmhzZeroReportProfile::SITUATION_AMENDMENT_UNREPORTABLE_MONTH,
                '10354',
            ),
        );
        self::assertSame(
            JmhzZeroReportProfile::RULE_FIRST_DAY_OF_MONTH,
            JmhzZeroReportProfile::rule(
                JmhzZeroReportProfile::SITUATION_REGULAR_WITHOUT_WORK,
                '10354',
            ),
        );
        self::assertSame(
            JmhzZeroReportProfile::RULE_LAST_DAY_OF_MONTH,
            JmhzZeroReportProfile::rule(
                JmhzZeroReportProfile::SITUATION_REGULAR_WITHOUT_WORK,
                '10355',
            ),
        );
    }

    /**
     * Druhý zaměnitelný rozdíl: anulační hlášení kód ELDP, jeho platnost
     * ani vyměřovací základ neuvádí, protože vztah neexistoval.
     */
    public function testEldpIsOmittedOnlyWhenAnnulling(): void
    {
        foreach (['10240', '10241', '10242', '10245'] as $attributeId) {
            self::assertSame(
                JmhzZeroReportProfile::RULE_OMIT,
                JmhzZeroReportProfile::rule(
                    JmhzZeroReportProfile::SITUATION_AMENDMENT_UNREPORTABLE_MONTH,
                    $attributeId,
                ),
                "Atribut {$attributeId} se v anulačním hlášení neuvádí.",
            );
            self::assertNotSame(
                JmhzZeroReportProfile::RULE_OMIT,
                JmhzZeroReportProfile::rule(
                    JmhzZeroReportProfile::SITUATION_REGULAR_WITHOUT_WORK,
                    $attributeId,
                ),
                "Atribut {$attributeId} se v řádném nulovém hlášení uvádí.",
            );
        }
    }

    /**
     * Pravidla podání 1.4.5, ř. 564-593: místo výkonu práce, stanovený fond,
     * prohlášení poplatníka a roční zúčtování se v anulačním formuláři
     * „neopravují". Jsou povinné (P), takže je nejde vynechat; opravný
     * formulář nese hodnotu z opravovaného.
     */
    public function testMandatoryAttributesAreCarriedOverWhenAnnulling(): void
    {
        foreach (['10419', '10319', '10320', '10229', '10230', '10231', '10259'] as $attributeId) {
            self::assertSame(
                JmhzZeroReportProfile::RULE_UNCHANGED,
                JmhzZeroReportProfile::rule(
                    JmhzZeroReportProfile::SITUATION_AMENDMENT_UNREPORTABLE_MONTH,
                    $attributeId,
                ),
                "Atribut {$attributeId} se v anulačním hlášení neopravuje.",
            );
        }
    }

    public function testUnknownSituationFailsClosed(): void
    {
        $this->expectException(JmhzXmlException::class);
        $this->expectExceptionMessageMatches('/není v pravidlech JMHZ popsaná/');
        JmhzZeroReportProfile::table('nulove_hlaseni');
    }

    public function testUnknownAttributeFailsClosed(): void
    {
        $this->expectException(JmhzXmlException::class);
        $this->expectExceptionMessageMatches('/není v tabulce nulového hlášení/');
        JmhzZeroReportProfile::rule(
            JmhzZeroReportProfile::SITUATION_REGULAR_WITHOUT_WORK,
            '19999',
        );
    }

    /**
     * Měsíc bez příjmu je jen ten, kde je celý vektor výdělků i osvobozený
     * příjem nula. Nezjištěná částka (null) nulou není.
     */
    public function testMonthWithoutIncomeNeedsEveryAmountToBeZero(): void
    {
        self::assertTrue(JmhzZeroReportProfile::isMonthWithoutIncome([]));
        self::assertTrue(JmhzZeroReportProfile::isMonthWithoutIncome(['10328' => 0, '10329' => 0]));
        self::assertFalse(JmhzZeroReportProfile::isMonthWithoutIncome(['10328' => 0, '10342' => 120_000]));
        self::assertFalse(JmhzZeroReportProfile::isMonthWithoutIncome(['10328' => null]));
        self::assertFalse(JmhzZeroReportProfile::isMonthWithoutIncome(['10328' => 0], 50_000));
        self::assertSame(
            JmhzZeroReportProfile::RULE_ZERO,
            JmhzZeroReportProfile::rule(JmhzZeroReportProfile::SITUATION_REGULAR_WITHOUT_WORK, '10345'),
        );
    }
}
