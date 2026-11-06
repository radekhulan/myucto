<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Run;

/**
 * Které kontroly mzdového běhu smí účetní trvale skrýt — jediné místo (SSOT).
 *
 * Seznam je VÝČET, ne pravidlo „všechna varování". Skrýt jde jen varování, které
 * popisuje stav, o kterém účetní může vědět, že je v pořádku (osoba bez
 * prohlášení, vztah bez docházky nebo bez vstupu). Nikdy:
 *
 * - blokující chyby — ty drží výpočet nebo schválení a skrytím by jen zmizely
 *   z očí, ne z cesty;
 * - varování s `requires_override` — za ta se přebírá odpovědnost výjimkou
 *   u konkrétního běhu, trvalé skrytí by výjimku obešlo;
 * - zákonné limity a nároky (DPP nad 300 hodin, průměr DPČ, přesčasy, exekuce,
 *   rizikové úspory, sleva na pojistném) — překročení zákona se nemá stát
 *   „normálním stavem".
 *
 * Hodnota říká, ke kterému subjektu se skrytí po osobách váže: `employee` =
 * osoba (souhrnné varování nese id osob v `subject_ids`), `employment` =
 * pracovní vztah z `entity_id` validace.
 */
final class PayrollWarningSuppressionCatalog
{
    public const SUBJECT_SUPPLIER = 'supplier';
    public const SUBJECT_EMPLOYEE = 'employee';
    public const SUBJECT_EMPLOYMENT = 'employment';

    /** Druhy subjektu skrytí po osobách (párováno s `PayrollWarningSubjectType` na webu). */
    public const SUBJECT_TYPES = [self::SUBJECT_EMPLOYEE, self::SUBJECT_EMPLOYMENT];

    /** @var array<string,'employee'|'employment'> */
    public const HIDEABLE = [
        'tax_declaration_not_signed_summary' => self::SUBJECT_EMPLOYEE,
        'time_month_missing' => self::SUBJECT_EMPLOYMENT,
        'employment_without_inputs' => self::SUBJECT_EMPLOYMENT,
        // Jen upozornění předem; překročení limitu je jiný kód a skrýt nejde.
        'dpp_annual_hours_approaching' => self::SUBJECT_EMPLOYMENT,
    ];

    /**
     * Souhrnná varování „N osob…": po skrytí části osob se věta složí znovu
     * se sníženým počtem.
     *
     * @var array<string,true>
     */
    private const AGGREGATE = [
        'tax_declaration_not_signed_summary' => true,
    ];

    public static function isHideableCode(string $code): bool
    {
        return isset(self::HIDEABLE[$code]);
    }

    public static function isHideable(string $code, string $severity, bool $requiresOverride): bool
    {
        return self::isHideableCode($code)
            && $severity !== 'blocker'
            && !$requiresOverride;
    }

    /** @return 'employee'|'employment'|null */
    public static function subjectType(string $code): ?string
    {
        return self::HIDEABLE[$code] ?? null;
    }

    public static function isAggregate(string $code): bool
    {
        return isset(self::AGGREGATE[$code]);
    }

    /**
     * Subjekty, ke kterým se váže skrytí po osobách.
     *
     * @param list<int> $subjectIds
     * @return list<int>
     */
    public static function subjects(
        string $code,
        string $entityType,
        ?int $entityId,
        array $subjectIds,
    ): array {
        if (self::isAggregate($code)) {
            return array_values(array_unique(array_map('intval', $subjectIds)));
        }
        $type = self::subjectType($code);
        if ($type !== null && $entityType === $type && $entityId !== null && $entityId > 0) {
            return [$entityId];
        }
        return [];
    }

    /** Věta souhrnného varování se sníženým počtem, `null` = kód souhrnný není. */
    public static function aggregateMessage(string $code, int $count): ?string
    {
        return match ($code) {
            'tax_declaration_not_signed_summary' =>
                PayrollRunSnapshotBuilder::unsignedDeclarationsMessage($count),
            default => null,
        };
    }
}
