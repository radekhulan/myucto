<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\AnnualSettlement;

use MyInvoice\Service\Payroll\IncomeTax\TaxCreditKind;
use MyInvoice\Service\Payroll\IncomeTax\TaxEvidenceStatus;

/**
 * Převod evidovaných intervalů nároku na POČET MĚSÍCŮ v roce.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Proč se počítá z evidence, a ne z toho, co se měsíčně uplatnilo
 * ─────────────────────────────────────────────────────────────────────────────
 * Měsíční sleva je podle § 35d odst. 3 omezená výší zálohy — kdo měl v květnu
 * nízkou mzdu, dostal slevu jen částečně. Z uplatněné částky proto nárok zpětně
 * odvodit nejde a dělení dvanáctinou by vyrobilo nesmysl. Zákon navíc říká
 * úplně jinou věc: nárok se posuzuje podle SPLNĚNÍ PODMÍNEK, ne podle výplaty.
 *
 *   § 35ba odst. 3: „…o částku ve výši jedné dvanáctiny za každý kalendářní
 *   měsíc, NA JEHOŽ POČÁTKU byly podmínky pro uplatnění nároku na slevu na dani
 *   splněny."
 *
 *   § 35c odst. 10: „…lze poskytnout daňové zvýhodnění ve výši 1/12 za každý
 *   kalendářní měsíc, NA JEHOŽ POČÁTKU byly splněny podmínky pro jeho uplatnění."
 *
 * Testuje se tedy PRVNÍ DEN měsíce — stejně jako to dělá měsíční větev
 * (EvidenceInterval::includesMonthStart). Nárok vzniklý 20. března se do března
 * nepočítá.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Fail-closed
 * ─────────────────────────────────────────────────────────────────────────────
 * Nedoložený nárok (§ 38l) se NEPOČÍTÁ ani jako nula — vrací se překážka.
 * Rozdíl je podstatný: nula znamená „nárok nebyl", překážka znamená „nevíme,
 * jestli byl". První by tiše snížilo přeplatek, druhé zúčtování zastaví.
 */
final class AnnualSettlementClaimMonths
{
    /**
     * @param list<array<string,mixed>> $rows řádky payroll_person_tax_credit_claims
     * @return array{
     *   credits:list<AnnualSettlementCreditMonths>,
     *   blockers:list<AnnualSettlementBlocker>
     * }
     */
    public function credits(array $rows, int $taxYear): array
    {
        $months = [];
        $blockers = [];
        foreach ($rows as $row) {
            $kind = TaxCreditKind::tryFrom((string) ($row['credit_kind'] ?? ''));
            if ($kind === null) {
                $blockers[] = AnnualSettlementBlocker::CreditEvidenceUnverified;
                continue;
            }
            $covered = $this->coveredMonths(
                (string) ($row['effective_from'] ?? ''),
                self::nullableDate($row['effective_to'] ?? null),
                $taxYear,
            );
            if ($covered === []) {
                continue;
            }
            if (TaxEvidenceStatus::tryFrom((string) ($row['evidence_status'] ?? ''))
                !== TaxEvidenceStatus::Verified
            ) {
                $blockers[] = AnnualSettlementBlocker::CreditEvidenceUnverified;
                continue;
            }
            foreach ($covered as $month) {
                $months[$kind->value][$month] = true;
            }
        }

        // § 35ba odst. 1 písm. c) a d): základní a rozšířená sleva na invaliditu
        // se navzájem vylučují — nelze být současně invalidní v prvním či druhém
        // a ve třetím stupni. Souběh je vada evidence, ne součet.
        $basic = $months[TaxCreditKind::DisabilityBasic->value] ?? [];
        $extended = $months[TaxCreditKind::DisabilityExtended->value] ?? [];
        if (array_intersect_key($basic, $extended) !== []) {
            $blockers[] = AnnualSettlementBlocker::CreditEvidenceUnverified;
        }

        $credits = [];
        ksort($months);
        foreach ($months as $kindValue => $set) {
            $credits[] = new AnnualSettlementCreditMonths(
                TaxCreditKind::from($kindValue),
                count($set),
            );
        }

        return ['credits' => $credits, 'blockers' => self::unique($blockers)];
    }

    /**
     * @param list<array<string,mixed>> $rows řádky payroll_person_tax_child_claims
     * @return array{
     *   children:list<AnnualSettlementChildMonths>,
     *   blockers:list<AnnualSettlementBlocker>
     * }
     */
    public function children(array $rows, int $taxYear): array
    {
        $byChild = [];
        $blockers = [];
        foreach ($rows as $row) {
            $reference = (string) ($row['child_reference'] ?? '');
            $covered = $this->coveredMonths(
                (string) ($row['effective_from'] ?? ''),
                self::nullableDate($row['effective_to'] ?? null),
                $taxYear,
            );
            if ($reference === '' || $covered === []) {
                continue;
            }
            if (TaxEvidenceStatus::tryFrom((string) ($row['evidence_status'] ?? ''))
                !== TaxEvidenceStatus::Verified
            ) {
                $blockers[] = AnnualSettlementBlocker::ChildEvidenceUnverified;
                continue;
            }
            // § 38l odst. 3 písm. c) a § 35c odst. 9: bez potvrzení o společně
            // hospodařící domácnosti a bez vyloučení souběžného uplatnění druhým
            // poplatníkem nárok doložený není.
            if ((int) ($row['shared_household_confirmed'] ?? 0) !== 1
                || (int) ($row['other_claimant_excluded'] ?? 0) !== 1
            ) {
                $blockers[] = AnnualSettlementBlocker::ChildClaimConflict;
                continue;
            }
            $order = (int) ($row['child_order'] ?? 0);
            if ($order < 1) {
                $blockers[] = AnnualSettlementBlocker::ChildClaimConflict;
                continue;
            }
            $ztpP = (int) ($row['ztp_p'] ?? 0) === 1;
            if (!isset($byChild[$reference])) {
                $byChild[$reference] = ['orders' => [], 'months' => [], 'ztp_p' => []];
            }
            $byChild[$reference]['orders'][$order] = true;
            foreach ($covered as $month) {
                $byChild[$reference]['months'][$month] = true;
                if ($ztpP) {
                    $byChild[$reference]['ztp_p'][$month] = true;
                }
            }
        }

        ksort($byChild);
        $children = [];
        $orders = [];
        foreach ($byChild as $reference => $data) {
            // Pořadí pro určení výše (§ 35c odst. 1) musí být v rámci roku
            // jedno. Kdyby se během roku změnilo, roční částka by závisela na
            // tom, který řádek se náhodou vezme jako první.
            if (count($data['orders']) !== 1) {
                $blockers[] = AnnualSettlementBlocker::ChildClaimConflict;
                continue;
            }
            $order = (int) array_key_first($data['orders']);
            if (isset($orders[$order])) {
                $blockers[] = AnnualSettlementBlocker::ChildClaimConflict;
                continue;
            }
            $orders[$order] = true;
            $claimedMonths = array_map('intval', array_keys($data['months']));
            sort($claimedMonths);
            $ztpPClaimedMonths = array_map('intval', array_keys($data['ztp_p']));
            sort($ztpPClaimedMonths);
            $children[] = new AnnualSettlementChildMonths(
                (string) $reference,
                $order,
                count($claimedMonths),
                count($ztpPClaimedMonths),
                $claimedMonths,
                $ztpPClaimedMonths,
            );
        }

        // Pořadí musí tvořit souvislou řadu od jedné — stejná kontrola jako
        // v měsíční větvi (`tax-child-order-gap`). Mezera znamená, že se buď
        // na dítě zapomnělo, nebo je pořadí špatně; obojí mění částku.
        ksort($orders);
        if ($orders !== [] && array_keys($orders) !== range(1, count($orders))) {
            $blockers[] = AnnualSettlementBlocker::ChildClaimConflict;
        }

        return ['children' => $children, 'blockers' => self::unique($blockers)];
    }

    /**
     * Měsíce roku, na jejichž POČÁTKU interval platil.
     *
     * @return list<int>
     */
    private function coveredMonths(
        string $effectiveFrom,
        ?string $effectiveTo,
        int $taxYear,
    ): array {
        $months = [];
        for ($month = 1; $month <= AnnualTaxRates::MONTHS_IN_YEAR; $month++) {
            $monthStart = sprintf('%04d-%02d-01', $taxYear, $month);
            if ($effectiveFrom <= $monthStart
                && ($effectiveTo === null || $effectiveTo >= $monthStart)
            ) {
                $months[] = $month;
            }
        }

        return $months;
    }

    private static function nullableDate(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param list<AnnualSettlementBlocker> $blockers
     * @return list<AnnualSettlementBlocker>
     */
    private static function unique(array $blockers): array
    {
        $unique = [];
        foreach ($blockers as $blocker) {
            $unique[$blocker->value] = $blocker;
        }
        ksort($unique);

        return array_values($unique);
    }
}
