<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;

/**
 * Dvě předepsané tabulky nulového hlášení (kap. 4 pravidel podání).
 *
 * Vypadají skoro stejně a znamenají něco úplně jiného:
 *
 * - **`amendment_unreportable_month`** anuluje měsíc, který už neměl být hlášen,
 *   protože pracovněprávní vztah v něm neměl pokračovat. Trvání pojištění se
 *   smrskne na jediný kalendářní den a kód ELDP se neuvádí. Místo výkonu
 *   práce, stanovený fond, prohlášení poplatníka a příznaky ročního
 *   zúčtování se „neopravují": jsou povinné, takže opravný formulář nese
 *   hodnotu z opravovaného formuláře.
 * - **`regular_without_work`** vykazuje registrovaný vztah, ve kterém se jen
 *   nepracovalo a nic se nezúčtovalo. Trvání pojištění pokrývá celý měsíc,
 *   místo výkonu práce se uvádí a ELDP blok se uvede, pokud kód existuje.
 *
 * Záměna vykáže neexistující pojistný vztah, nebo naopak zruší existující.
 * Situace se proto NIKDY neodvozuje z dat — volající ji musí pojmenovat.
 *
 * Tabulky jsou opsané doslova a mají připnutý otisk. Rozdíl mezi nimi je sám
 * o sobě předmětem testu, aby se nemohly nepozorovaně sblížit.
 */
final class JmhzZeroReportProfile
{
    public const SITUATION_AMENDMENT_UNREPORTABLE_MONTH = 'amendment_unreportable_month';
    public const SITUATION_REGULAR_WITHOUT_WORK = 'regular_without_work';

    public const TABLES_SHA256 =
        '5a3be07539e606ee00efe73ed2c8a687e5e0a3906e24fba527c54e642dd459b1';

    /** Hodnota se uvede jako nula. */
    public const RULE_ZERO = 'zero';

    /** Atribut se neuvede vůbec. */
    public const RULE_OMIT = 'omit';

    /**
     * „Neopravuje se": povinný atribut opravný formulář nemění, uvede se
     * tedy hodnota z formuláře, který se opravuje.
     */
    public const RULE_UNCHANGED = 'unchanged';

    /** Příznak se uvede jako „ne". */
    public const RULE_FALSE = 'false';

    /** Uvede se skutečná hodnota, jinak nula (souběžný pracovněprávní vztah). */
    public const RULE_ZERO_OR_ACTUAL_WHEN_CONCURRENT = 'zero_or_actual_when_concurrent';

    /** Uvede se skutečná hodnota z evidence. */
    public const RULE_ACTUAL = 'actual';

    /** Trvání pojištění tvoří jediný kalendářní den. */
    public const RULE_SINGLE_DAY = 'single_day';

    /** První den vykazovaného měsíce. */
    public const RULE_FIRST_DAY_OF_MONTH = 'first_day_of_month';

    /** Poslední den vykazovaného měsíce. */
    public const RULE_LAST_DAY_OF_MONTH = 'last_day_of_month';

    /** Uvede se jen tehdy, když kód ELDP existuje. */
    public const RULE_WITH_ELDP_CODE = 'with_eldp_code';

    /** Uvede se nula jen tehdy, když kód ELDP existuje. */
    public const RULE_ZERO_WITH_ELDP_CODE = 'zero_with_eldp_code';

    /** @var array<string, array<array-key, string>> */
    private const TABLES = [
        self::SITUATION_AMENDMENT_UNREPORTABLE_MONTH => [
            '10286' => self::RULE_ZERO,
            '10419' => self::RULE_UNCHANGED,
            '10319' => self::RULE_UNCHANGED,
            '10320' => self::RULE_UNCHANGED,
            '10344' => self::RULE_ZERO_OR_ACTUAL_WHEN_CONCURRENT,
            '10116' => self::RULE_FALSE,
            '10482' => self::RULE_ZERO_OR_ACTUAL_WHEN_CONCURRENT,
            '10371' => self::RULE_ZERO_OR_ACTUAL_WHEN_CONCURRENT,
            '10229' => self::RULE_UNCHANGED,
            '10230' => self::RULE_UNCHANGED,
            '10231' => self::RULE_UNCHANGED,
            '10232' => self::RULE_FALSE,
            '10247' => self::RULE_FALSE,
            '10251' => self::RULE_FALSE,
            '10259' => self::RULE_UNCHANGED,
            '10260' => self::RULE_ZERO,
            '10261' => self::RULE_ZERO,
            '10265' => self::RULE_ZERO,
            '10268' => self::RULE_ZERO,
            '10328' => self::RULE_ZERO,
            '10329' => self::RULE_OMIT,
            '10330' => self::RULE_OMIT,
            '10331' => self::RULE_OMIT,
            '10345' => self::RULE_ZERO,
            '10354' => self::RULE_SINGLE_DAY,
            '10355' => self::RULE_SINGLE_DAY,
            '10240' => self::RULE_OMIT,
            '10241' => self::RULE_OMIT,
            '10242' => self::RULE_OMIT,
            '10356' => self::RULE_ZERO,
            '10245' => self::RULE_OMIT,
            '10481' => self::RULE_ZERO,
            '10370' => self::RULE_ZERO,
            '10372' => self::RULE_FALSE,
            '10490' => self::RULE_FALSE,
            '10546' => self::RULE_FALSE,
            '10535' => self::RULE_ZERO,
        ],
        self::SITUATION_REGULAR_WITHOUT_WORK => [
            '10286' => self::RULE_ZERO,
            '10419' => self::RULE_ACTUAL,
            '10319' => self::RULE_ACTUAL,
            '10320' => self::RULE_ACTUAL,
            '10344' => self::RULE_ZERO_OR_ACTUAL_WHEN_CONCURRENT,
            '10116' => self::RULE_FALSE,
            '10482' => self::RULE_ZERO_OR_ACTUAL_WHEN_CONCURRENT,
            '10371' => self::RULE_ZERO_OR_ACTUAL_WHEN_CONCURRENT,
            '10229' => self::RULE_ACTUAL,
            '10230' => self::RULE_ACTUAL,
            '10231' => self::RULE_ACTUAL,
            '10232' => self::RULE_FALSE,
            '10247' => self::RULE_FALSE,
            '10251' => self::RULE_FALSE,
            '10259' => self::RULE_ZERO,
            '10260' => self::RULE_ZERO,
            '10261' => self::RULE_ZERO,
            '10265' => self::RULE_ZERO,
            '10268' => self::RULE_ZERO,
            '10328' => self::RULE_ZERO,
            '10329' => self::RULE_OMIT,
            '10330' => self::RULE_OMIT,
            '10331' => self::RULE_OMIT,
            '10345' => self::RULE_ZERO,
            '10354' => self::RULE_FIRST_DAY_OF_MONTH,
            '10355' => self::RULE_LAST_DAY_OF_MONTH,
            '10240' => self::RULE_WITH_ELDP_CODE,
            '10241' => self::RULE_FIRST_DAY_OF_MONTH,
            '10242' => self::RULE_LAST_DAY_OF_MONTH,
            '10356' => self::RULE_ZERO,
            '10245' => self::RULE_ZERO_WITH_ELDP_CODE,
            '10481' => self::RULE_ZERO,
            '10370' => self::RULE_ZERO,
            '10372' => self::RULE_FALSE,
            '10490' => self::RULE_FALSE,
            '10546' => self::RULE_FALSE,
            '10535' => self::RULE_ZERO,
        ],
    ];

    /**
     * Atributy, ve kterých se obě tabulky liší. Je to jádro rizika: kdyby se
     * sblížily, záměna situace by přestala být poznat.
     *
     * @var list<string>
     */
    private const DIVERGENT_ATTRIBUTES = [
        '10229', '10230', '10231', '10240', '10241', '10242', '10245', '10259',
        '10319', '10320', '10354', '10355', '10419',
    ];

    /** @return list<string> */
    public static function situations(): array
    {
        return array_keys(self::TABLES);
    }

    /**
     * Předpis pro danou situaci. Situace se předává výslovně; odvodit ji z dat
     * nelze, protože rozdíl je v tom, co se stalo, ne v tom, co je v evidenci.
     *
     * ID atributů jsou číselná, takže je PHP jako klíče pole normalizuje na
     * `int`; typ klíče proto musí být `array-key`.
     *
     * @return array<array-key, string> atribut => pravidlo
     */
    public static function table(string $situation): array
    {
        return self::TABLES[$situation] ?? throw new JmhzXmlException(
            'jmhz_zero_report_situation_unknown',
            "Situace nulového hlášení {$situation} není v pravidlech JMHZ popsaná.",
        );
    }

    public static function rule(string $situation, string $attributeId): string
    {
        $table = self::table($situation);

        return $table[$attributeId] ?? throw new JmhzXmlException(
            'jmhz_zero_report_attribute_unknown',
            "Atribut {$attributeId} není v tabulce nulového hlášení {$situation}.",
        );
    }

    /**
     * Vztah nemá v měsíci zúčtovaný žádný příjem: vektor výdělků podle
     * atributů (v haléřích i v korunách, rozhoduje jen nula) a osvobozený
     * příjem jsou nulové. Je to podmínka situace
     * {@see self::SITUATION_REGULAR_WITHOUT_WORK} („nebyl zúčtován příjem ze
     * závislé činnosti“); v řádném hlášení jiná situace nastat nemůže,
     * anulování nevykazovaného měsíce je vždy opravné hlášení.
     *
     * @param array<array-key,mixed> $earningsByAttribute
     */
    public static function isMonthWithoutIncome(array $earningsByAttribute, int $exemptIncome = 0): bool
    {
        if ($exemptIncome !== 0) {
            return false;
        }
        foreach ($earningsByAttribute as $amount) {
            if (!is_int($amount) || $amount !== 0) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    public static function divergentAttributes(): array
    {
        return self::DIVERGENT_ATTRIBUTES;
    }

    public static function fingerprint(): string
    {
        return hash('sha256', CanonicalJson::encode([
            'schema_reference' => 'jmhz-zero-report-profile.v1',
            'source' => 'Pravidla podání JMHZ a související procesy 1.4.4, kap. 4',
            'tables' => self::TABLES,
        ]));
    }
}
