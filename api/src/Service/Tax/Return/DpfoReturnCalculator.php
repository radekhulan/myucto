<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Return;

use MyInvoice\Service\Report\CzechWorkingDays;
use MyInvoice\Service\Tax\DpfoCalculator;

/**
 * Výpočet přiznání DPFO (DPFDP7 + Příloha 1) — Epic DP (issue #18).
 *
 * ČISTÁ třída bez DB. Skládá dílčí základy §6–§10, §15 nezdanitelné části (s limity
 * darů), progresivní daň 15/23 %, slevy §35ba (poplatník/manželka) a daňové zvýhodnění
 * §35c na děti (sleva + vratitelný bonus), odečet záloh (sražené §6 + zaplacené).
 *
 * Sdílené výpočetní kusy (§7 výdaje, §15, progrese, slevy, děti) přebírá z
 * {@see DpfoCalculator} — jeden zdroj pravdy s daňovým optimalizátorem.
 *
 * Vstup:
 *   $data    = podklady §7 (DpfoReturnDataProvider): s7_income, s7_expenses, s7_base,
 *              expense_mode ('actual'|'pausal'), expense_rate, s7_increase, s7_decrease
 *              (úhrn úprav §23 — ř.105/106 Přílohy 1) a volitelně s7_increase_items/
 *              s7_decrease_items — položkový rozpis pro oddíl E Přílohy 1 (VetaC/VetaE,
 *              {@see DpfoXmlBuilder}), tvar list<{amount:float, description?:string,
 *              text?:string}>. DpfoReturnDataProvider dnes položky nepředává (jen souhrn
 *              increase/decrease) — bez nich builder sestaví jeden souhrnný řádek a
 *              upozorní, viz DpfoXmlBuilder::buildAdjustmentRows.
 *   $inputs  = ruční vstupy (income_tax_returns.inputs): s6_employment{income,withholding},
 *              s8_capital{base}, s9_rental{income,expenses}, s10_other{income,expenses},
 *              tax_paid_advances
 *   $profile = tax_profile (spouse_credit, children_count, §15 dary/úroky/penzijko/životko)
 *   $c       = roční konstanty
 */
final class DpfoReturnCalculator
{
    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $inputs
     * @param array<string,mixed> $profile
     * @param array<string,mixed> $c
     * @return array{
     *   lines: list<array{line:string,code:string,label:string,value:float,source:string}>,
     *   fields: array<string,float>,
     *   s7: array<string,mixed>,
     *   tax: float, advances: float, balance_due: float,
     *   summary: array<string,float>,
     *   warnings: list<string>,
     *   bank_account: array{account_number:?string,bank_code:?string,bank_name:?string,iban:?string}|null
     * }
     */
    public function compute(array $data, array $inputs, array $profile, array $c): array
    {
        $warnings = [];

        // ── Dílčí základy ────────────────────────────────────────────────────
        $s6Income = max(0.0, $this->num($inputs['s6_employment']['income'] ?? 0));
        $s6Withholding = max(0.0, $this->num($inputs['s6_employment']['withholding'] ?? 0));
        $s6 = $s6Income; // od 2021 dílčí základ §6 = úhrn příjmů (bez superhrubé mzdy)

        $activities = (array) ($data['activities'] ?? $profile['activities'] ?? []);
        $activityCalc = $activities !== [] ? DpfoCalculator::section7Activities($activities, $c) : null;
        $s7Income = round((float) ($activityCalc['income'] ?? $data['s7_income'] ?? 0), 2);
        $s7Expenses = round((float) ($activityCalc['expenses'] ?? $data['s7_expenses'] ?? 0), 2);
        $s7Increase = round((float) ($data['s7_increase'] ?? 0), 2);
        $s7Decrease = round((float) ($data['s7_decrease'] ?? 0), 2);
        // Položkový rozpis pro oddíl E Přílohy 1 (VetaC/VetaE) — jen prošedě, ČISTÁ třída
        // nesumarizuje ani nevaliduje popisy, to dělá DpfoXmlBuilder (má i fallback bez
        // položek). Sem se dostane jen to, co volající v $data skutečně předal.
        $s7IncreaseItems = is_array($data['s7_increase_items'] ?? null) ? array_values($data['s7_increase_items']) : [];
        $s7DecreaseItems = is_array($data['s7_decrease_items'] ?? null) ? array_values($data['s7_decrease_items']) : [];
        $s7BeforeAdjustments = $activityCalc !== null
            ? $s7Income - $s7Expenses
            : (array_key_exists('s7_base', $data)
                ? $this->num($data['s7_base'])
                : $s7Income - $s7Expenses);
        $s7 = round($s7BeforeAdjustments + $s7Increase - $s7Decrease, 2); // může být záporný (ztráta)

        $s8 = max(0.0, $this->num($inputs['s8_capital']['base'] ?? 0));
        $s9Income = max(0.0, $this->num($inputs['s9_rental']['income'] ?? 0));
        // Výdaje procentem z příjmů podle § 9 odst. 4 (30 %, nejvýše dle `expense_caps`).
        // Bez téhle volby se do přiznání plnilo `vyd9proc="N"` i poplatníkovi, který
        // paušál uplatňuje — částky by seděly, ale způsob uplatnění výdajů by byl
        // uvedený nepravdivě a úřad to nepozná (kontroluje jen, že je A nebo N).
        $s9Pausal = (string) ($inputs['s9_rental']['expense_mode'] ?? 'actual') === 'pausal';
        $s9Cap = (float) (($c['expense_caps'][30] ?? 600000));
        $s9Expenses = $s9Pausal
            ? min(round($s9Income * 0.30, 2), $s9Cap)
            : max(0.0, $this->num($inputs['s9_rental']['expenses'] ?? 0));
        $s9 = round($s9Income - $s9Expenses, 2); // §9 smí být záporný (ztráta z nájmu, §5/3 → offset §7/§8/§10)
        $s10Items = [];
        $s10Income = 0.0;
        $s10Expenses = 0.0;
        if (is_array($inputs['s10_items'] ?? null) && $inputs['s10_items'] !== []) {
            foreach ($inputs['s10_items'] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $income = max(0.0, $this->num($item['income'] ?? 0));
                $expensesClaimed = max(0.0, $this->num($item['expenses'] ?? 0));
                $expenses = min($income, $expensesClaimed);
                $s10Income += $income;
                $s10Expenses += $expenses;
                $s10Items[] = array_merge($item, [
                    'income' => $income,
                    'expenses' => $expenses,
                    'allowed_expenses' => $expenses,
                    'disallowed_expenses' => round($expensesClaimed - $expenses, 2),
                ]);
            }
        } else {
            $s10Income = max(0.0, $this->num($inputs['s10_other']['income'] ?? 0));
            $s10Expenses = min($s10Income, max(0.0, $this->num($inputs['s10_other']['expenses'] ?? 0)));
        }
        $s10 = round($s10Income - $s10Expenses, 2);

        // Úhrn dílčích základů §7–§10 (§7 ztráta smí offsetovat §8/§9/§10). ř. 41 (kc_uhrn)
        // SMÍ být záporný (viz XSD dpfdp7_epo2.xsd kc_uhrn — signed decimal); záporný úhrn
        // je daňová ztráta roku, kterou vykazujeme a evidujeme (§34), nikoli ořízneme na 0.
        $group710 = round($s7 + $s8 + $s9 + $s10, 2);
        $base710 = max(0.0, $group710); // kladná část úhrnu §7–§10 (pro základ daně)
        if ($group710 < 0) {
            $warnings[] = 'Součet dílčích základů §7–§10 je záporný (daňová ztráta '
                . number_format(-$group710, 0, ',', ' ') . ' Kč) — nesnižuje základ ze závislé činnosti (§6). '
                . 'Po finalizaci přiznání se eviduje jako pravomocně stanovená ztráta k uplatnění v dalších letech (§34).';
        }

        // Základ daně (ř. 42) = §6 + kladný úhrn §7–§10 (dle instrukce kc_zakldan23: pokud je
        // ř. 41 záporný, tvoří základ jen dílčí základ §6 z ř. 36).
        $totalBase = round($s6 + $base710, 2);

        // ── Odečet daňové ztráty minulých let §34 (ř. 44, kc_ztrata2) ────────
        // Uplatní se max do výše úhrnu §7–§10 (ř. 41), NE do výše §6 — ztráta z podnikání
        // nesmí snížit základ ze závislé činnosti. Vstup loss_carryforward je ruční
        // (návrh FIFO z evidence tax_losses počítá TaxLossService).
        $lossCarry = max(0.0, $this->num($inputs['loss_carryforward'] ?? 0));
        $lossApplied = min($lossCarry, $base710); // ř. 44
        if ($lossApplied > 0) {
            $warnings[] = 'Uplatňujete daňovou ztrátu minulých let (' . number_format($lossApplied, 0, ',', ' ')
                . ' Kč, §34 ř. 44). K přiznání přiložte povinnou samostatnou přílohu pro poplatníky uplatňující odčitatelnou položku podle § 34 odst. 1 zákona.';
        }
        if ($lossCarry > $lossApplied) {
            $warnings[] = 'Ztráta minulých let se uplatnila jen do výše úhrnu §7–§10 (ř. 41); zbytek '
                . number_format($lossCarry - $lossApplied, 0, ',', ' ') . ' Kč zůstává k převodu.';
        }
        $baseAfterLoss = round($totalBase - $lossApplied, 2); // ř. 45 = ř. 42 − ř. 44 (≥ §6 ≥ 0)

        // ── §15 nezdanitelné části (odečítají se od ř. 45) ──────────────────
        // §15/3-4 ZDP: strop úroků 300 000 Kč pro bytovou potřebu obstaranou do 31. 12. 2020,
        // jinak 150 000 Kč (od 2021, zák. 386/2020 Sb.).
        $mortgageCap = !empty($profile['mortgage_pre_2021'])
            ? (float) ($c['mortgage_cap_pre2021'] ?? 300000)
            : (float) ($c['mortgage_cap'] ?? 150000);
        $mortgageMonths = max(0, min(12, (int) ($profile['mortgage_months'] ?? 12)));
        $uroky = min($this->num($profile['mortgage_interest'] ?? 0), $mortgageCap * $mortgageMonths / 12);
        // §15a (od 2024): penzijní produkty + soukromé životní pojištění (+ DIP) sdílejí
        // JEDEN společný roční strop 48 000 Kč. Alokujeme nejdřív penzijko, zbytek životko.
        $retirementCap = (float) ($c['pension_cap'] ?? 48000);
        $penzijko = min($this->num($profile['pension_contrib'] ?? 0), $retirementCap);
        $zivotko = min($this->num($profile['life_insurance'] ?? 0), max(0.0, $retirementCap - $penzijko));
        $dip = min($this->num($profile['dip_contrib'] ?? 0), max(0.0, $retirementCap - $penzijko - $zivotko));
        $care = min($this->num($profile['long_term_care'] ?? 0), max(0.0, $retirementCap - $penzijko - $zivotko - $dip));

        // Dary §15/1: odečet jen když ≥ spodní limit (1 000 Kč nebo 2 % ZD), max % ZD.
        $donations = $this->num($profile['donations'] ?? 0);
        $donationCapPct = (float) ($c['donation_cap_fo_pct'] ?? 0.30);
        $donationMin = min((float) ($c['donation_min_fo'] ?? 1000), (float) ($c['donation_min_fo_pct'] ?? 0.02) * $totalBase);
        $donationCap = round($donationCapPct * $totalBase, 2);
        $daryDeduct = 0.0;
        if ($donations >= $donationMin && $donations > 0) {
            $daryDeduct = min($donations, $donationCap);
            if ($donations > $daryDeduct) {
                $warnings[] = 'Dary přesahují limit §15/1 (' . (int) round($donationCapPct * 100)
                    . ' % základu = ' . number_format($donationCap, 0, ',', ' ') . ' Kč); nadlimit se neodečte.';
            }
        } elseif ($donations > 0) {
            $warnings[] = 'Dary nedosahují spodního limitu §15/1 (min. 1 000 Kč nebo 2 % základu) — neodečtou se.';
        }

        $deductions = round($uroky + $penzijko + $zivotko + $dip + $care + $daryDeduct, 2);
        $baseAfter15 = max(0.0, round($baseAfterLoss - $deductions, 2)); // ř. 55 = ř. 45 − ř. 54

        // ── Základ zaokrouhlený ↓ 100 Kč + daň 15/23 % ───────────────────────
        $roundedBase = $this->floorTo($baseAfter15, (int) ($c['rounding_base_fo'] ?? 100));
        $tax16 = ceil(DpfoCalculator::progressiveTax((float) $roundedBase, $c));

        // ── Slevy §35ba ──────────────────────────────────────────────────────
        $creditTaxpayer = (float) ($c['credit_taxpayer'] ?? 30840);
        $spouseClaim = is_array($profile['spouse_claim'] ?? null) ? $profile['spouse_claim'] : null;
        $creditSpouse = $spouseClaim !== null
            ? DpfoCalculator::spouseCreditFromClaim($spouseClaim, $c)
            : (!empty($profile['spouse_credit']) ? (float) ($c['credit_spouse'] ?? 24840) : 0.0);
        // m_manz (ř.65a) — EPO ověřuje součin počet měsíců × sazba i při nulovém nároku;
        // legacy vstup bez měsíců (jen spouse_credit=true) znamená celý rok.
        $spouseMonths = $spouseClaim !== null
            ? max(0, min(12, (int) ($spouseClaim['eligible_months'] ?? 0)))
            : (!empty($profile['spouse_credit']) ? 12 : 0);
        // ř. 65b (kc_manztpp) — ZTP/P u manžela/manželky zdvojnásobuje slevu (viz
        // DpfoCalculator::spouseCreditFromClaim); EPO chce zvlášť i tu "přidanou" polovinu.
        $spouseZtppExtra = !empty($spouseClaim['ztpp'] ?? null)
            ? round((float) ($c['credit_spouse'] ?? 24840) * $spouseMonths / 12, 2)
            : 0.0;
        $creditDisability12 = round((float) ($c['credit_disability_12'] ?? 2520) * max(0, min(12, (int) ($profile['disability_12_months'] ?? 0))) / 12, 2);
        $creditDisability3 = round((float) ($c['credit_disability_3'] ?? 5040) * max(0, min(12, (int) ($profile['disability_3_months'] ?? 0))) / 12, 2);
        $creditZtpp = round((float) ($c['credit_ztpp'] ?? 16140) * max(0, min(12, (int) ($profile['ztpp_months'] ?? 0))) / 12, 2);
        $slevy35ba = round($creditTaxpayer + $creditSpouse + $creditDisability12 + $creditDisability3 + $creditZtpp, 2);
        $taxAfter35ba = max(0.0, round($tax16 - $slevy35ba, 2));

        // ── Daňové zvýhodnění §35c (sleva + vratitelný bonus) ────────────────
        $children = (array) ($profile['children'] ?? []);
        $childrenCount = count($children) ?: (int) ($profile['children_count'] ?? 0);
        $childTotal = $children !== []
            ? DpfoCalculator::childCreditFromClaims($children, $c['child_credits'])
            : DpfoCalculator::childCreditTotal($childrenCount, $c['child_credits']);
        $childCredit = min($taxAfter35ba, $childTotal);        // sleva do výše daně
        $childBonus = max(0.0, round($childTotal - $taxAfter35ba, 2)); // vratitelný bonus
        $childBonusMinimum = (float) ($c['child_bonus_min'] ?? 100);
        if ($childBonus > 0 && $childBonus < $childBonusMinimum) {
            $warnings[] = 'Vypočtený daňový bonus na děti je nižší než zákonné minimum '
                . number_format($childBonusMinimum, 0, ',', ' ') . ' Kč (§35c odst. 3), proto se neuplatní.';
            $childBonus = 0.0;
        }
        $taxAfterChildren = max(0.0, round($taxAfter35ba - $childCredit, 2)); // daň po zvýhodnění

        $bonusIncome = round($s6Income + $s7Income, 2);
        $bonusIncomeMinimum = (float) ($c['child_bonus_min_income'] ?? 0);
        if ($childBonus > 0 && $bonusIncome < $bonusIncomeMinimum) {
            $warnings[] = 'Daňový bonus na děti se neuplatnil: příjem ze §6 a §7 nedosahuje zákonného minima '
                . number_format($bonusIncomeMinimum, 0, ',', ' ') . ' Kč.';
            $childBonus = 0.0;
        }

        // ── § 16a: samostatný základ daně ────────────────────────────────────
        // Poplatník MŮŽE zahrnout zahraniční podíly na zisku a obdobné příjmy podle
        // § 8 odst. 1 do samostatného základu daně zdaněného 15 % (§ 16a odst. 1 a 2).
        // Je to volba, ne povinnost, a příjmy zahrnuté sem se v § 8 (ř. 38) NEUVÁDÍ —
        // proto se berou ze samostatného vstupu, ne z dílčího základu.
        //
        // Daň se počítá zvlášť a slevy podle § 35ba/§ 35c se na ni NEUPLATŇUJÍ: snižují
        // daň podle § 16, kdežto tohle je samostatný základ vedle ní. Přičítá se proto
        // až k výsledné dani, po slevách i po bonusu.
        $separateBase = max(0.0, $this->num($inputs['s16a_separate_base'] ?? 0));
        $separateRate = (float) ($c['separate_base_rate'] ?? 0.15);
        $separateTax = 0.0;
        if ($separateBase > 0.0) {
            $roundedSeparateBase = $this->floorTo($separateBase, (int) ($c['rounding_base_fo'] ?? 100));
            $separateTax = ceil($roundedSeparateBase * $separateRate);
            $warnings[] = 'Samostatný základ daně podle § 16a: '
                . number_format($roundedSeparateBase, 0, ',', ' ') . ' Kč, daň '
                . number_format($separateTax, 0, ',', ' ') . ' Kč (sazba '
                . (int) round($separateRate * 100) . ' %). Slevy podle § 35ba ani § 35c se na ni '
                . 'neuplatňují. POZOR: do XML se tento údaj NEZAPISUJE — atributy pro samostatný '
                . 'základ nejsou v XSD popsané a doplnění naslepo by znamenalo chybu v podaném '
                . 'přiznání; vyplňte příslušné řádky ručně v EPO.';
        }

        // Výsledná daňová povinnost (po zvýhodnění, − bonus) a vypořádání záloh.
        // Daň ze samostatného základu se přičítá až sem — slevy ji nesnižují.
        $finalTax = round($taxAfterChildren - $childBonus + $separateTax, 2); // záporné = přeplatek z bonusu
        $advancesPaid = max(0.0, $this->num($inputs['tax_paid_advances'] ?? 0));
        $advances = round($s6Withholding + $advancesPaid, 2);
        $balanceDue = round($finalTax - $advances, 2);
        // Poslední známá daňová povinnost pro zálohy (§ 38a) zahrnuje i daň ze samostatného
        // základu — je to součást stanovené daně, ne položka vedle ní.
        $lastKnownTax = max(0.0, round($taxAfterChildren + $separateTax, 2));
        // § 38a odst. 5 ZDP — podíl § 6 na celkovém základu: od horní hranice se zálohy
        // neplatí vůbec, od dolní (ale ne dosahující horní) jen v poloviční výši.
        $employmentExemptShare = (float) ($c['advance_employment_exempt_share'] ?? 0.50);
        $employmentHalfShare = (float) ($c['advance_employment_half_share'] ?? 0.15);
        $employmentShare = $totalBase > 0 ? $s6 / $totalBase : 0.0;
        $advanceFactor = $employmentShare >= $employmentExemptShare
            ? 0.0
            : ($employmentShare >= $employmentHalfShare ? 0.5 : 1.0);
        $advanceLow = (float) ($c['advance_threshold_low'] ?? 30000);
        $advanceHigh = (float) ($c['advance_threshold_high'] ?? 150000);
        $advanceRegime = $advanceFactor <= 0 || $lastKnownTax <= $advanceLow
            ? 'none'
            : ($lastKnownTax <= $advanceHigh ? 'semiannual' : 'quarterly');
        $advanceRate = $advanceRegime === 'semiannual'
            ? (float) ($c['advance_semiannual_rate'] ?? 0.40)
            : (float) ($c['advance_quarterly_rate'] ?? 0.25);
        $advanceAmount = $advanceRegime === 'none'
            ? 0.0
            : ceil($lastKnownTax * $advanceRate * $advanceFactor / 100) * 100;

        $lines = [
            $this->line('31', 'Příjmy ze závislé činnosti (§6)', $s6Income, 'ruční vstup (potvrzení zaměstnavatele)'),
            $this->line('34', 'Dílčí základ daně §6', $s6, 'úhrn příjmů §6'),
            $this->line('37', 'Dílčí základ daně §7 (Příloha 1)', $s7, $data['expense_mode'] === 'pausal' ? 'paušál' : 'skutečné výdaje'),
            $this->line('38', 'Dílčí základ §8 (kapitálový majetek)', $s8, 'ruční vstup'),
            $this->line('39', 'Dílčí základ §9 (nájem)', $s9, 'ruční vstup'),
            $this->line('40', 'Dílčí základ §10 (ostatní)', $s10, 'ruční vstup'),
            $this->line('41', 'Úhrn dílčích základů §7–§10', $group710, 'mezisoučet (smí být záporný = ztráta roku)'),
            $this->line('42', 'Základ daně', $totalBase, 'mezisoučet'),
            $this->line('44', 'Odečet daňové ztráty minulých let (§34)', $lossApplied, 'ruční vstup / evidence ztrát'),
            $this->line('45', 'Základ daně po odečtu ztráty', $baseAfterLoss, 'mezisoučet'),
            $this->line('54', 'Nezdanitelné části základu (§15)', $deductions, 'dary, úroky, penzijko, životko'),
            $this->line('55', 'Základ daně snížený', $baseAfter15, 'mezisoučet'),
            $this->line('56', 'Zaokrouhlený základ (↓100 Kč)', (float) $roundedBase, 'zaokrouhlení'),
            $this->line('57', 'Daň (15 % / 23 % §16)', $tax16, 'progresivní daň'),
            $this->line('60', 'Sleva na poplatníka + manželku (§35ba)', $slevy35ba, 'ruční / profil'),
            $this->line('64', 'Daň po slevách §35ba', $taxAfter35ba, 'mezisoučet'),
            $this->line('72', 'Daňové zvýhodnění na děti (§35c)', $childTotal, 'profil'),
            $this->line('74', 'Daň po uplatnění zvýhodnění', $taxAfterChildren, 'mezisoučet'),
            $this->line('75', 'Daňový bonus', $childBonus, 'vratitelný'),
            $this->line('84', 'Sražené zálohy §6', $s6Withholding, 'ruční vstup'),
            $this->line('85', 'Zaplacené zálohy na daň', $advancesPaid, 'ruční vstup'),
            $this->line('91', 'Doplatek (+) / přeplatek (−)', $balanceDue, '− zálohy'),
        ];

        $fields = [
            'kc_prij6' => $this->i($s6Income),
            'kc_zd6' => $this->i($s6),
            // ř. 36 — EPO kontroluje ř.36 = ř.34; od 2021 dílčí základ §6 = úhrn příjmů
            // (superhrubá mzda zrušena), takže obě hodnoty jsou vždy shodné.
            'kc_zd6p' => $this->i($s6),
            'kc_zd7' => $this->i($s7),
            'kc_zakldan8' => $this->i($s8),
            'kc_zd9' => $this->i($s9),
            'kc_zd10' => $this->i($s10),
            'kc_uhrn' => $this->i($group710),        // ř. 41 — skutečný (i záporný) úhrn §7–§10
            'kc_zakldan' => $this->i($baseAfterLoss), // ř. 45 = ř. 42 − ř. 44
            'kc_zakldan23' => $this->i($totalBase),   // ř. 42 — základ daně (jen §6, je-li ř. 41 < 0)
            'kc_op15_8' => $this->i($daryDeduct),
            'kc_op28_5' => $this->i($uroky),
            'kc_op15_12' => $this->i($penzijko),
            'kc_op15_13' => $this->i($zivotko),
            'kc_op15_inpr' => $this->i($dip),
            'kc_op15_pece' => $this->i($care),
            'kc_odcelk' => $this->i($deductions),
            'kc_zdsniz' => $this->i($baseAfter15),
            'kc_zdzaokr' => (float) $roundedBase,
            'da_dan16' => round($tax16, 2), // 2 desetinná místa dle XSD
            // ř.58 a ř.60 oddílu 4. Zjištěno pokusem proti zkušebnímu EPO 30. 8. 2026:
            // `da_dan16` samo nestačí, EPO hlásilo „daň podle § 16 má být vyplněna"
            // i s ním. ř.58 přebírá daň z ř.57, nebo — máme-li Přílohu 3 — částku
            // z jejího ř.330; tu zatím neumíme, takže se přenáší ř.57 a EPO na to
            // má vlastní kontrolu („Příloha č.3 není vyplněna a hodnota ř.58 se
            // nerovná hodnotě ř.57"). ř.60 je táž daň zaokrouhlená na celé koruny
            // nahoru.
            'da_slezap' => round($tax16, 2),
            'da_celod13' => (float) (int) ceil($tax16),
            'kc_op15_1a' => $this->i($creditTaxpayer),
            'kc_op15_1c' => $this->i($creditSpouse),
            'kc_op15_1d' => $this->i($creditDisability12),
            'kc_op15_1e1' => $this->i($creditDisability3),
            'kc_op15_1e2' => $this->i($creditZtpp),
            'uhrn_slevy35ba' => $this->i($slevy35ba),
            // ř. 71/74 — EPO má samostatné atributy pro "daň po slevách §35ba" a "daň po
            // zvýhodnění §35c". Dřív se tu navíc posílal `da_slevy` (stejná hodnota jako
            // `da_slevy35ba`) — zkušební EPO 31. 8. 2026 potvrdilo pokusem (bisekcí), že
            // `da_slevy` je mezisoučet, kterému žádný tištěný řádek neodpovídá, a jeho
            // přítomnost kazí EPO vlastní kontrolu ř.70 (uhrn_slevy35ba): s `da_slevy`
            // EPO hlásilo „ř.70 se nerovná vzorci" s nápovědou rovnou součtu
            // uhrn_slevy35ba+da_slevy; po vynechání `da_slevy` výtka zmizela. Proto se
            // `da_slevy` do XML vůbec neposílá (viz DpfoXmlBuilder::VETA_D_FIELDS).
            'da_slevy35ba' => $this->i($taxAfter35ba),
            'da_slevy35c' => $this->i($taxAfterChildren),
            'kc_dazvyhod' => $this->i($childTotal),
            'kc_slevy35c' => $this->i($childCredit),
            'kc_danbonus' => $this->i($childBonus),
            'kc_dan_po_db' => $this->i($taxAfterChildren),
            'kc_dan_celk' => $this->i($taxAfterChildren),
            // ř.77a — daňový bonus po odpočtu daně (ř.76−ř.75), vzájemně vylučující
            // protějšek ř.77 (kc_dan_po_db): `$taxAfterChildren` je už zezdola ořízlé
            // na 0 (viz $childCredit = min($taxAfter35ba, $childTotal) výše), takže kdykoli
            // vznikne bonus (childTotal > taxAfter35ba), taxAfterChildren je právě 0 a
            // ř.77a se rovná celému bonusu; jinak je bonus i tenhle atribut 0. Proto
            // `kc_db_po_odpd` = $childBonus ve všech případech. Zkušební EPO 31. 8. 2026
            // (bisekce): tenhle atribut se dřív vůbec neposílal (chyběl v
            // DpfoXmlBuilder::VETA_D_FIELDS) a EPO hlásilo „ř.77a neodpovídá výpočtu
            // (0)" i když bonus byl 0 — nulu je nutné poslat výslovně, stejně jako u
            // kc_dan_po_db/kc_dan_celk (viz komentář u VETA_D_OMIT_WHEN_ZERO v builderu).
            'kc_db_po_odpd' => $this->i($childBonus),
            // Vlastní invalidita/ZTP-P poplatníka (§35ba) — EPO ověřuje ř.66–68 jako
            // počet měsíců × sazba, i když je nárok nulový; bez měsíců formuli neověří.
            'm_invduch' => (float) max(0, min(12, (int) ($profile['disability_12_months'] ?? 0))),
            'm_cinvduch' => (float) max(0, min(12, (int) ($profile['disability_3_months'] ?? 0))),
            'm_ztpp' => (float) max(0, min(12, (int) ($profile['ztpp_months'] ?? 0))),
            'm_manz' => (float) $spouseMonths,
            'kc_manztpp' => $this->i($spouseZtppExtra),
            // ř. 61 — daňová ztráta vzniklá v tomto ZO (záporný ř. 41); EPO chce řádek
            // vyplněný i jako 0, ne prázdný, jinak formuli nemá s čím srovnat.
            'kc_dztrata' => $this->i(max(0.0, round(-$group710, 2))),
            'kc_zalzavc' => $this->i($s6Withholding),
            'kc_zalpred' => $this->i($advancesPaid),
            'kc_zbyvpred' => $this->i($balanceDue),
        ];

        // ř. 44 kc_ztrata2 — emitujeme jen při skutečném uplatnění (jinak řádek zůstane prázdný;
        // poplatník uplatňující ztrátu navíc podává samostatnou přílohu §34/1).
        if ($lossApplied > 0) {
            $fields['kc_ztrata2'] = $this->i($lossApplied);
        }

        // § 38g ZDP — povinnost podat přiznání. Systém čísla zná, poplatník povinnost
        // často ne; nepodané přiznání znamená pokutu podle § 250 DŘ, takže mlčet o tom
        // je horší než upozornit zbytečně.
        $otherIncome = round($s7Income + $s8 + $s9Income + $s10Income, 2);
        $filingLimit = (float) ($c['filing_duty_income_limit'] ?? 50000);
        $otherLimit = (float) ($c['filing_duty_other_income_limit'] ?? 20000);

        if (round($s6Income + $otherIncome, 2) > $filingLimit) {
            $warnings[] = 'Roční příjmy přesahují ' . number_format($filingLimit, 0, ',', ' ')
                . ' Kč — vzniká povinnost podat přiznání (§ 38g odst. 1 ZDP).';
        } elseif ($s6Income > 0.0 && $otherIncome <= $otherLimit) {
            // § 38g odst. 2: zaměstnanec s podepsaným prohlášením u všech plátců a s ostatními
            // příjmy do limitu přiznání podávat nemusí. Podmínku „podepsané prohlášení
            // u všech postupných plátců" systém z dat neověří — proto podmíněná formulace.
            $warnings[] = 'Ostatní příjmy (§ 7–§ 10) nepřesahují ' . number_format($otherLimit, 0, ',', ' ')
                . ' Kč — máte-li příjmy jen ze závislé činnosti a u všech plátců jste podepsal(a) '
                . 'prohlášení k dani, přiznání podávat nemusíte (§ 38g odst. 2 ZDP); stačí roční '
                . 'zúčtování u zaměstnavatele.';
        }

        return [
            'lines' => $lines,
            'fields' => $fields,
            's7' => [
                'income' => $s7Income,
                'expenses' => $s7Expenses,
                'base' => $s7,
                // ř.104 Přílohy 1 (kc_hosp_rozd) — "rozdíl mezi příjmy a výdaji NEBO výsledek
                // hospodaření", tj. §7 základ PŘED úpravami (zvýšení/snížení, ř.105/106),
                // ne konečné `base`/kc_zd7p (to už úpravy zahrnuje). Bez tohoto ř.104 si EPO
                // ř.113 (kc_zd7p) dopočítává ze součtu ř.104–112 jako 0+ř.105-ř.106+…, což
                // s odeslaným kc_zd7p nesedí — zkušební EPO 31. 8. 2026 (bisekce) potvrdilo,
                // že přidání kc_hosp_rozd výtku ř.113 odstraní.
                'before_adjustments' => $s7BeforeAdjustments,
                'expense_mode' => (string) ($data['expense_mode'] ?? 'pausal'),
                'expense_rate' => (int) ($data['expense_rate'] ?? 0),
                'accounting_mode' => (string) ($data['accounting_mode'] ?? 'tax_evidence'),
                'activities' => $activityCalc['items'] ?? [],
                'increase' => $s7Increase,
                'decrease' => $s7Decrease,
                // Oddíl E Přílohy 1 (VetaC/VetaE) — položkový rozpis, viz komentář u
                // $s7IncreaseItems výše a DpfoXmlBuilder::buildAdjustmentRows.
                'increase_items' => $s7IncreaseItems,
                'decrease_items' => $s7DecreaseItems,
                'closing' => $data['closing'] ?? null,
            ],
            // Hrubé §9/§10 podklady pro Přílohu č. 2 (VetaV/VetaJ, {@see DpfoXmlBuilder}) —
            // 'fields' nese jen NET dílčí základ (kc_zd9/kc_zd10), ale VetaV chce hrubé
            // kc_prij9/kc_vyd9/kc_prij10/kc_vyd10 zvlášť (analogie 's7' výše).
            's9' => [
                'income' => $s9Income,
                'expenses' => $s9Expenses,
                'base' => $s9,
                'pausal' => $s9Pausal,
            ],
            's10' => [
                'income' => $s10Income,
                'expenses' => $s10Expenses,
                'base' => $s10,
            ],
            's10_items' => $s10Items,
            'family' => ['children' => $children, 'spouse' => $spouseClaim],
            'tax' => $taxAfterChildren,
            'advances' => $advances,
            'balance_due' => $balanceDue,
            'summary' => [
                'total_base' => $totalBase,
                'rounded_base' => (float) $roundedBase,
                'tax16' => $tax16,
                'tax_after_credits' => $taxAfter35ba,
                'child_bonus' => $childBonus,
                'child_credit' => $childTotal,
                'spouse_credit' => $creditSpouse,
                'bonus_qualifying_income' => $bonusIncome,
                'final_tax' => $finalTax,
                'balance_due' => $balanceDue,
                // § 16a — vykazuje se odděleně, protože do XML se (vědomě) nezapisuje
                // a účetní ho musí v EPO doplnit ručně.
                'separate_base' => round($separateBase, 2),
                'separate_base_tax' => $separateTax,
                's7_profit' => round($s7Income - $s7Expenses, 2),
                'uhrn_710' => $group710,                       // ř. 41 (signed)
                'loss_applied' => $lossApplied,                // ř. 44 uplatněná ztráta minulých let
                'year_tax_loss' => max(0.0, round(-$group710, 2)), // ztráta vzniklá v tomto roce (§34)
            ],
            // Přenos zdroje pro VetaN (žádost o vrácení přeplatku) — {@see DpfoXmlBuilder::buildVetaN}.
            'bank_account' => $data['bank_account'] ?? null,
            'next_advances' => [
                'regime' => $advanceRegime,
                'amount' => $advanceAmount,
                'count' => $advanceRegime === 'quarterly' ? 4 : ($advanceRegime === 'semiannual' ? 2 : 0),
                'employment_income_share' => round($employmentShare, 4),
                'reduction_factor' => $advanceFactor,
                'filing_deadline' => CzechWorkingDays::deadlineFromMonthDay(
                    (int) ($data['year'] ?? date('Y')) + 1,
                    (string) ($c['filing_deadlines']['dpfo_paper'] ?? '04-01'),
                ),
            ],
            'warnings' => $warnings,
        ];
    }

    private function num(mixed $v): float
    {
        return round((float) $v, 2);
    }

    private function i(float $v): float
    {
        return round($v, 0);
    }

    private function floorTo(float $value, int $step): int
    {
        if ($step <= 0) {
            return (int) floor($value);
        }
        return (int) (floor($value / $step) * $step);
    }

    /** @return array{line:string,code:string,label:string,value:float,source:string} */
    private function line(string $code, string $label, float $value, string $source): array
    {
        return ['line' => $code, 'code' => $code, 'label' => $label, 'value' => round($value, 2), 'source' => $source];
    }
}
