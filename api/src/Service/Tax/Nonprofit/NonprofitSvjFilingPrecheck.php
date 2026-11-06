<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Nonprofit;

use MyInvoice\Service\Tax\Return\TaxReturnException;

/**
 * Daňová předkontrola SVJ v uzávěrce: zda § 38mb písm. b) ZDP dovoluje nepodat přiznání.
 *
 * Výjimka závisí na druzích všech příjmů období, ne na výsledné dani. Jediný zdanitelný
 * příjem v obecném režimu (nájem společných částí, úrok z prodlení, náhrada nákladů)
 * znamená `filing_required`, i když potvrzený výpočet daně vyjde nula. Bez aktuálního
 * potvrzeného posouzení nebo s nezařazeným příjmem zůstává stav `unassessed`; uzávěrka
 * pak nesmí doplnit nepodání ani nulovou daň sama. Test povinnosti sdílí s výpočtem
 * DPPO jediné pravidlo NonprofitDppoLegalRules::obligation().
 */
final class NonprofitSvjFilingPrecheck
{
    public const FILING_REQUIRED = 'filing_required';
    public const FILING_NOT_REQUIRED = 'filing_not_required';
    public const UNASSESSED = 'unassessed';

    /**
     * @param iterable<array<int,array<string,mixed>>> $resultPages stránky řádků potvrzené revize
     * @return array{status:string,reason:string,legal_basis:string,taxable_source_count:int}
     */
    public static function evaluate(bool $assessmentComplete, iterable $resultPages): array
    {
        if (!$assessmentComplete) return self::result(self::UNASSESSED, 'assessment_incomplete', 0);
        $taxable = 0;
        foreach ($resultPages as $rows) {
            try {
                $obligation = NonprofitDppoLegalRules::obligation('svj', array_values($rows), 0);
            } catch (TaxReturnException) {
                return self::result(self::UNASSESSED, 'income_unclassified', 0);
            }
            $taxable += count($obligation['taxable_sources']);
        }
        return $taxable > 0
            ? self::result(self::FILING_REQUIRED, 'other_taxable_income', $taxable)
            : self::result(self::FILING_NOT_REQUIRED, 'only_exempt_or_withheld_income', 0);
    }

    private static function result(string $status, string $reason, int $taxable): array
    {
        return ['status' => $status, 'reason' => $reason, 'legal_basis' => 'zdp_38mb_b', 'taxable_source_count' => $taxable];
    }
}
