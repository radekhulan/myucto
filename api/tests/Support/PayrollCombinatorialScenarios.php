<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support;

/**
 * Dimenze kombinačních scénářů mezd (brána G4) a jejich omezení.
 *
 * Omezení vyřazují jen kombinace, které právně nebo věcně nedávají smysl
 * (srážková daň u pracovního poměru, sleva na dítě bez prohlášení, nepřítomnost
 * u jednatele, mateřská u nepojištěné dohody …). Co právně možné je, ale
 * aplikace to odmítne, omezení NEvyřazují: na to se má přijít.
 */
final class PayrollCombinatorialScenarios
{
    /** Kombinace pro CI: prvních N z hladového pokrytí pokrývá nejvíc dvojic. */
    public const CI_LIMIT = 12;

    /** @return array<string,list<string>> */
    public static function dimensions(): array
    {
        return [
            'relation' => ['hpp', 'dpc', 'dpp', 'statutory'],
            'tax' => ['declaration', 'no_declaration', 'withholding', 'nonresident'],
            'insurance' => ['below', 'threshold', 'above'],
            'absence' => ['none', 'vacation', 'dpn_window', 'dpn_beyond', 'ocr', 'ppm', 'parental', 'unpaid_leave', 'paid_obstacle'],
            'holiday' => ['inside', 'outside'],
            'credit' => ['none', 'taxpayer', 'children', 'employer_7a', 'pensioner'],
            'concurrency' => ['single', 'hpp_dpp'],
            'span' => ['full', 'start_mid', 'end_mid'],
        ];
    }

    /** @param array<string,string> $c částečné přiřazení */
    public static function allowed(array $c): bool
    {
        $is = static fn (string $dimension, string ...$values): bool => isset($c[$dimension]) && in_array($c[$dimension], $values, true);
        $known = static fn (string ...$dimensions): bool => array_diff($dimensions, array_keys($c)) === [];

        // Srážková daň jen tam, kde ji zákon připouští: příjem pod rozhodnou částkou
        // u dohody nebo vztahu, který účast na pojištění nezakládá (§ 6 odst. 4 ZDP).
        if ($is('tax', 'withholding') && ($is('relation', 'hpp') || $is('insurance', 'threshold', 'above'))) {
            return false;
        }
        // Slevy na dani jen s prohlášením; nerezident jen základní slevu.
        if ($is('credit', 'taxpayer', 'children') && $is('tax', 'no_declaration', 'withholding')) {
            return false;
        }
        if ($is('credit', 'children') && $is('tax', 'nonresident')) {
            return false;
        }
        // Sleva zaměstnavatele § 7a: pracovní poměr se zkráceným úvazkem po celý měsíc.
        if ($is('credit', 'employer_7a') && ($is('relation', 'dpc', 'dpp', 'statutory') || $is('span', 'start_mid', 'end_mid')
            || $is('absence', 'ppm', 'parental'))) {
            return false;
        }
        // Sleva pracujícího důchodce jen u pojištěného vztahu.
        if ($is('credit', 'pensioner') && ($is('absence', 'ppm', 'parental')
            || ($known('relation', 'insurance') && !self::insured($c['relation'], $c['insurance'])))) {
            return false;
        }
        // Jednatel nemá fond pracovní doby ani nepřítomnosti.
        if ($is('relation', 'statutory') && $known('absence') && !$is('absence', 'none')) {
            return false;
        }
        if ($is('absence', 'none') && $is('holiday', 'inside')) {
            return false;
        }
        // Dávkové nepřítomnosti jen u nemocensky pojištěného vztahu.
        if ($is('absence', 'dpn_window', 'dpn_beyond', 'ocr', 'ppm', 'parental')
            && $known('relation', 'insurance') && !self::insured($c['relation'], $c['insurance'])) {
            return false;
        }
        if ($is('absence', 'ppm', 'parental') && $is('span', 'start_mid', 'end_mid')) {
            return false;
        }
        if ($is('absence', 'ppm') && $is('holiday', 'outside')) {
            return false;
        }
        // Neschopnost trvající od předchozího měsíce ani svátek 6. 7. nejdou s nástupem 15. 7.
        if ($is('span', 'start_mid') && ($is('absence', 'dpn_beyond') || $is('holiday', 'inside'))) {
            return false;
        }
        // Souběh = hlavní pracovní poměr a k němu dohoda o provedení práce.
        if ($is('concurrency', 'hpp_dpp') && $is('relation', 'dpc', 'dpp', 'statutory')) {
            return false;
        }

        return true;
    }

    /** @return list<array<string,string>> */
    public static function all(): array
    {
        return PairwiseCombinations::generate(self::dimensions(), self::allowed(...));
    }

    public static function insured(string $relation, string $insurance): bool
    {
        return $relation === 'hpp' || $insurance !== 'below';
    }

    /** Měsíční částka mzdy v haléřích podle druhu vztahu a vztahu k rozhodné částce. */
    public static function amountMinor(string $relation, string $insurance): int
    {
        return match ($relation) {
            // Pracovní poměr je pojištěný vždy; hranicí je tu minimální vyměřovací
            // základ zdravotního pojištění (22 400 Kč). Pod ním jen na poloviční úvazek,
            // jinak by mzda nedosáhla minimální mzdy.
            'hpp' => ['below' => 1_500_000, 'threshold' => 2_240_000, 'above' => 4_000_000][$insurance],
            'dpp' => ['below' => 1_100_000, 'threshold' => 1_200_000, 'above' => 1_500_000][$insurance],
            'dpc' => ['below' => 400_000, 'threshold' => 450_000, 'above' => 1_500_000][$insurance],
            'statutory' => ['below' => 400_000, 'threshold' => 450_000, 'above' => 3_000_000][$insurance],
        };
    }

    /** @param array<string,string> $c */
    public static function id(array $c): string
    {
        return implode('/', $c);
    }
}
