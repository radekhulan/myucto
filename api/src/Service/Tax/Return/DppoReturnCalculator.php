<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Return;

/**
 * Výpočet přiznání DPPO (II.–V. oddíl DPPDP9) — Epic DP (issue #18).
 *
 * ČISTÁ, testovatelná třída bez DB: vstup = podkladová data (VH, nedaňové náklady,
 * rozdíl odpisů, ZC vyřazeného majetku) + ruční vstupy poplatníka + roční konstanty,
 * výstup = struktura řádků formuláře (číslo řádku → hodnota + zdroj/popis) pro FE
 * náhled i XML builder. Podklady dodává {@see DppoReturnDataProvider}.
 *
 * Pipeline (§23–§35 ZDP, formulář DPPDP9):
 *   ř.10  VH před zdaněním (Σ 6xx − Σ 5xx mimo 59x)
 *   ř.40  výdaje neuznávané za náklady §25 (nedaňové účty + neuznatelná ZC vyřazení
 *         + add-back PHM při uplatnění paušálu na dopravu §24/2/zt)
 *   ř.50  účetní odpisy převyšující daňové (zvýšení základu)
 *   ř.62  ostatní částky zvyšující základ §23 (ruční mimo paušál dopravy + rozdíl ZC vyřazení)
 *   ř.112 doplňková informace k §23/3 písm. c) — např. paušální výdaj na dopravu (§24/2/zt),
 *         rozpoznáno dle textu ruční snižující položky (klíčová slova „paušál" + „doprav")
 *   ř.150 daňové odpisy převyšující účetní (snížení základu)
 *   ř.162 ostatní částky snižující základ §23 (ruční mimo paušál dopravy + rozdíl ZC vyřazení)
 *   ř.170 souhrn částek snižujících výsledek hospodaření (ř. 101–165 mezisoučet: ř.150+ř.162+ř.112)
 *   ř.200 základ daně (mezisoučet; může být záporný = daňová ztráta)
 *   ř.230 odečet ztráty minulých let §34
 *   ř.250 základ snížený o ztrátu
 *   ř.260 odečet darů §20/8 (cap % ze základu)
 *   ř.270 základ zaokrouhlený dolů na celé tisíce Kč
 *   ř.290 daň = základ × sazba §21
 *   ř.300 slevy na dani §35 (zaměstnanci se ZP)
 *   ř.310 daň po slevách
 *   ř.340 celková daňová povinnost
 *   ř.360 poslední známá daň pro zálohy §38a
 *   V.    zálohy na další období §38a (prahy 30/150 tis.)
 */
final class DppoReturnCalculator
{
    /**
     * Explicitní druh ruční položky § 23 — paušální výdaj na dopravu (§ 24/2/zt).
     *
     * Zavedeno proto, že se paušál dosud poznával jen podle TEXTU položky. Heuristika
     * je nespolehlivá v obou směrech a nezařazená položka se tiše vykázala na obecném
     * ř. 62/162 místo ř. 40/112 — přiznání tím nemá špatný základ daně, ale špatně
     * vyplněné řádky, čehož si nikdo nevšimne.
     */
    public const KIND_FLAT_RATE_TRAVEL = 'flat_rate_travel';

    /**
     * @param array<string,mixed> $data   podklady z DppoReturnDataProvider
     * @param array<string,mixed> $inputs ruční vstupy (income_tax_returns.inputs)
     * @param array<string,mixed> $c      roční konstanty (TaxConstantsRepository::forYear)
     * @return array{
     *   lines: list<array{line:int,code:string,label:string,value:float,source:string}>,
     *   tax: float, advances_paid: float, balance_due: float,
     *   next_advances: array{regime:string,count:int,amount:float,total:float,note:string},
     *   summary: array<string,float>,
     *   warnings: list<string>
     * }
     */
    public function compute(array $data, array $inputs, array $c): array
    {
        $warnings = [];

        // ── Podklady ────────────────────────────────────────────────────────
        $vh = round((float) ($data['vh'] ?? 0), 2);                          // ř.10
        $nonDeductible = round((float) ($data['non_deductible_costs'] ?? 0), 2);
        $disposalResidual = round((float) ($data['disposal_nondeductible_residual'] ?? 0), 2);
        $disposalIncrease = max(0.0, round((float) ($data['disposal_tax_increase'] ?? 0), 2));
        $disposalDecrease = max(0.0, round((float) ($data['disposal_tax_decrease'] ?? 0), 2));
        $depTax = round((float) ($data['depreciation']['tax'] ?? 0), 2);
        $depAcc = round((float) ($data['depreciation']['accounting'] ?? 0), 2);

        // ── Ruční vstupy ────────────────────────────────────────────────────
        // §24/2/zt paušál na dopravu: účetní ho spolu s odpovídajícím add-backem PHM
        // podává na samostatných řádcích (40/112/170), ne v obecném katalogu §23 (62/162)
        // — ověřeno proti skutečně podanému přiznání za rok 2024. Ruční položky
        // nemají typovaný kód (jen text), proto se rozpoznávají podle klíčových slov.
        // Součty pro ř.200 (základ) se NEMĚNÍ — jde jen o přerozdělení MEZI řádky výpisu.
        $manualIncrease = $this->sumItems($inputs['manual_increase_items'] ?? []);
        $manualDecrease = $this->sumItems($inputs['manual_decrease_items'] ?? []);
        $flatRateTravelAddback = min($manualIncrease, $this->sumFlatRateTravelItems($inputs['manual_increase_items'] ?? []));
        $flatRateTravelDeduction = min($manualDecrease, $this->sumFlatRateTravelItems($inputs['manual_decrease_items'] ?? []));
        $lossCarry = max(0.0, round((float) ($inputs['loss_carryforward'] ?? 0), 2));
        [$donations, $donationWarnings] = $this->resolveDonations($inputs, (float) ($c['donation_min_po'] ?? 2000));
        $warnings = array_merge($warnings, $donationWarnings);
        $disabledAvg = max(0.0, (float) ($inputs['disabled_employees_avg'] ?? 0));
        $disabledSevereAvg = max(0.0, (float) ($inputs['disabled_employees_severe_avg'] ?? 0));
        $advancesPaid = max(0.0, round((float) ($inputs['tax_paid_advances'] ?? 0), 2));

        // ── Konstanty ───────────────────────────────────────────────────────
        $rate = (float) ($c['corporate_tax_rate'] ?? 0.21);
        $donationCapPct = (float) ($c['donation_cap_po_pct'] ?? 0.30);
        $creditPerDisabled = (float) ($c['disabled_employee_credit'] ?? 18000);
        $creditPerSevere = (float) ($c['disabled_employee_credit_severe'] ?? 60000);
        $roundBase = (int) ($c['rounding_base_po'] ?? 1000);
        $advLow = (float) ($c['advance_threshold_low'] ?? 30000);
        $advHigh = (float) ($c['advance_threshold_high'] ?? 150000);

        // ── Úpravy základu (§23) ────────────────────────────────────────────
        $depIncrease = max(0.0, round($depAcc - $depTax, 2)); // ř.50: účetní > daňové → +
        $depDecrease = max(0.0, round($depTax - $depAcc, 2)); // ř.150: daňové > účetní → −
        $line40 = round($nonDeductible + $disposalResidual, 2);

        // ř.200 základ daně před odečty (může být záporný) — POZOR: záměrně počítá
        // s celkovými (nerozdělenými) $manualIncrease/$manualDecrease/$line40, aby
        // rozpad paušálu na dopravu níže (na ř.40/62/112/162/170) NEOVLIVNIL základ
        // ani daň — jde jen o přerozdělení MEZI řádky výpisu/XML, ne o novou částku.
        $base = round(
            $vh + $line40 + $depIncrease + $manualIncrease + $disposalIncrease
            - $depDecrease - $manualDecrease - $disposalDecrease,
            2
        );

        // ── Rozpad výpisu ř.40/62/112/162/170 — §24/2/zt paušál na dopravu ────
        // Přesouvá add-back PHM (increase) z obecného ř.62 na ř.40 a paušální výdaj
        // (decrease) z obecného ř.162 na ř.112/170, PŘESNĚ dle toho, jak to podává
        // účetní — věcně ověřeno proti podanému přiznání za rok 2024 (DPPDP9:
        // kc_ii_112=45000 + VetaR příloha k ř. 112, add-back PHM v kc_ii50_40 přes
        // tabulku A). Add-back zaúčtovaných PHM je nedaňový náklad §25/1/x → ř. 40;
        // paušál sám podle pokynů GFŘ patří na ř. 162, podání na ř. 112 s textovou
        // přílohou je ale přijímaná praxe a základ daně (ř. 170/200) je identický.
        // Jinak stejné částky, jen jiné řádky (kosmetika, součty výše beze změny).
        $line40Reported = round($line40 + $flatRateTravelAddback, 2);
        $line62Reported = round(($manualIncrease - $flatRateTravelAddback) + $disposalIncrease, 2);
        $line162Reported = round(($manualDecrease - $flatRateTravelDeduction) + $disposalDecrease, 2);
        $line112Reported = $flatRateTravelDeduction;
        $line170Reported = round($depDecrease + $manualDecrease + $disposalDecrease, 2);

        // Položky, které o dopravě mluví, ale za paušál označené nejsou. Systém je zařadit
        // neumí; tiše je vykázat na obecném ř. 62/162 by znamenalo špatně vyplněné přiznání,
        // o kterém se uživatel nedozví.
        $ambiguous = array_merge(
            $this->ambiguousTravelTexts($inputs['manual_increase_items'] ?? []),
            $this->ambiguousTravelTexts($inputs['manual_decrease_items'] ?? []),
        );
        if ($ambiguous !== []) {
            $warnings[] = 'Ruční položky zmiňují dopravu, ale nejsou označené jako paušál '
                . '(§24/2/zt): „' . implode('", „', array_slice($ambiguous, 0, 5)) . '"'
                . (count($ambiguous) > 5 ? ' a další' : '')
                . '. Vykážou se na obecném ř. 62/162 — jde-li o paušál, označte je, ať skončí '
                . 'na ř. 40/112.';
        }

        if ($base < 0) {
            $warnings[] = 'Základ daně je záporný (daňová ztráta ' . number_format(-$base, 0, ',', ' ')
                . ' Kč) — odečet ztráty §34 se neuplatní a vzniká ztráta k převodu do dalších let.';
        }

        // ř.230 odečet ztráty §34 — max do výše kladného základu
        $lossApplied = $base > 0 ? min($lossCarry, $base) : 0.0;
        if ($lossCarry > 0 && $lossApplied < $lossCarry) {
            $warnings[] = 'Ztráta minulých let se uplatnila jen do výše základu daně; zbytek '
                . number_format($lossCarry - $lossApplied, 0, ',', ' ') . ' Kč zůstává k převodu.';
        }
        $baseAfterLoss = round($base - $lossApplied, 2); // ř.250

        // ř.242 odečet na podporu výzkumu a vývoje (§ 34 odst. 4 a § 34a–34e) a ř.243
        // odečet na podporu odborného vzdělávání (§ 34 odst. 4 a § 34f–34h).
        //
        // Výši odpočtu systém spočítat NEMŮŽE — plyne z projektu výzkumu a vývoje, resp.
        // z evidence odborného vzdělávání, které v účetnictví nejsou. Zadává ji poplatník
        // a systém hlídá jen to, co ověřit lze: pořadí a strop podle základu daně.
        // Do doplnění nešla částka zadat vůbec, takže poplatník s VaV projektem platil daň
        // navíc a systém mu odpočet neuměl ani nabídnout.
        //
        // Pořadí je dané anotací XSD u `kc_ii_243`: odborné vzdělávání se odečítá až od
        // základu sníženého mimo jiné o VaV, ne od původního.
        $rndClaimed = max(0.0, (float) ($inputs['rnd_deduction'] ?? 0));
        $rndApplied = min($rndClaimed, max(0.0, $baseAfterLoss));
        if ($rndClaimed > $rndApplied) {
            $warnings[] = 'Odečet na podporu výzkumu a vývoje (ř. 242) se uplatnil jen do výše základu; '
                . 'zbytek ' . number_format($rndClaimed - $rndApplied, 0, ',', ' ') . ' Kč lze podle § 34 odst. 5 '
                . 'uplatnit v následujících 3 obdobích — systém tenhle přenos NEEVIDUJE, hlídejte si ho.';
        }
        $baseAfterRnd = round($baseAfterLoss - $rndApplied, 2);

        $eduClaimed = max(0.0, (float) ($inputs['education_deduction'] ?? 0));
        $eduApplied = min($eduClaimed, max(0.0, $baseAfterRnd));
        if ($eduClaimed > $eduApplied) {
            $warnings[] = 'Odečet na podporu odborného vzdělávání (ř. 243) se uplatnil jen do výše základu '
                . 'sníženého o ztrátu a o odečet na výzkum a vývoj; zbytek '
                . number_format($eduClaimed - $eduApplied, 0, ',', ' ') . ' Kč se neodečte.';
        }
        $baseAfterDeductions = round($baseAfterRnd - $eduApplied, 2);

        // ř.260 odečet darů §20/8 — cap % ze základu sníženého podle § 34, tedy nejen
        // o ztrátu, ale i o odečty na VaV a odborné vzdělávání. Dokud odečty § 34 odst. 4
        // neexistovaly, byl `baseAfterLoss` totéž; s nimi už ne.
        $donationCap = max(0.0, round($donationCapPct * max(0.0, $baseAfterDeductions), 2));
        $donationApplied = min($donations, $donationCap);
        if ($donations > $donationApplied) {
            $warnings[] = 'Dary přesahují limit §20/8 (' . (int) round($donationCapPct * 100)
                . ' % základu = ' . number_format($donationCap, 0, ',', ' ') . ' Kč); nadlimit se neodečte.';
        }
        if ($donationApplied > 0) {
            $warnings[] = 'Dary (§20/8) se odečítají od základu daně. Ověřte, že NEJSOU zároveň '
                . 'uplatněny jako daňový náklad — mají být zaúčtovány na nedaňový účet 543 (jinak by se zvýhodnily dvakrát).';
        }

        // ř.270 základ zaokrouhlený dolů na celé tisíce
        $roundedBase = $this->floorTo(max(0.0, $baseAfterDeductions - $donationApplied), $roundBase);

        // ř.290 daň
        $taxGross = round($roundedBase * $rate, 2);

        // ř.300 slevy §35 (zaměstnanci se ZP)
        $disabledCredit = round($disabledAvg * $creditPerDisabled, 2);
        $severeDisabledCredit = round($disabledSevereAvg * $creditPerSevere, 2);
        $creditsEntitlement = round($disabledCredit + $severeDisabledCredit, 2);
        $credits = min($creditsEntitlement, $taxGross);
        if ($creditsEntitlement > $credits) {
            $warnings[] = 'Sleva §35 byla omezena výší daně na ř. 290; neuplatněný nárok činí '
                . number_format($creditsEntitlement - $credits, 0, ',', ' ') . ' Kč.';
        }

        // ř.310 / ř.340 daň po slevách (nezáporná)
        $taxAfterCredits = max(0.0, round($taxGross - $credits, 2));
        $totalTax = $taxAfterCredits;

        // FEATURE 1 — projekce závěrkových operací (§DP): pokud podklady nesou nezaúčtované
        // uzávěrkové kroky, dopočti PROJEKTOVANOU daň z projektovaného VH. Posted čísla zůstávají
        // beze změny (kalkulace výše) — projekce je jen náhled „jak to dopadne po uzávěrce“.
        $projection = null;
        $proj = $data['closing_projection'] ?? null;
        if (is_array($proj) && ($proj['is_projection'] ?? false) === true) {
            $vhProjected = round((float) ($proj['vh_projected'] ?? $vh), 2);
            // Projektovaný základ = posted základ posunutý o rozdíl VH (ostatní úpravy §23 se nemění).
            $projectedBase = round($base + ($vhProjected - $vh), 2);
            $projectedTax = $this->taxFromBase($projectedBase, $lossCarry, $donations, $donationCapPct, $rate, $roundBase, $creditsEntitlement, $rndClaimed, $eduClaimed);
            $projection = [
                'vh_posted' => round($vh, 2),
                'vh_projected' => $vhProjected,
                'projected_base' => $projectedBase,
                'projected_tax' => $projectedTax,
                'is_projection' => true,
                'items' => array_values((array) ($proj['items'] ?? [])),
            ];
        }

        // ř.360 doplatek/přeplatek
        $balanceDue = round($totalTax - $advancesPaid, 2);

        // V. oddíl — zálohy §38a dle poslední známé daňové povinnosti (ř.340). Splatnosti
        // = 15. den N-tého měsíce ZÁLOHOVÉHO období (období FOLLOWING po tomto), u
        // hospodářského roku posunuté. Kotví se na ZAČÁTEK zálohového období = den PO konci
        // tohoto období (ends_on + 1). U kalendářního i řádného hospodářského roku vyjde
        // stejně jako z počátku období, ale u ZKRÁCENÉHO období (přechod na řádný rok) to
        // správně navazuje na následující řádné období, ne na zkrácený start — jinak by
        // termíny byly posunuté (např. první rok 15. 3.–31. 12. → zálohy chybně od března).
        $startMonth = 1;
        $advanceAnchorKnown = false;
        $periodEnd = (string) ($data['period']['ends_on'] ?? '');
        if ($periodEnd !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodEnd) === 1) {
            $end = \DateTimeImmutable::createFromFormat('!Y-m-d', $periodEnd);
            if ($end !== false) {
                $startMonth = (int) $end->modify('+1 day')->format('n');
                $advanceAnchorKnown = true;
            }
        }
        $nextAdvances = $this->advances($totalTax, $advLow, $advHigh, $startMonth, $c);
        if (!$advanceAnchorKnown && $nextAdvances['regime'] !== 'none') {
            $warnings[] = 'Splatnosti záloh §38a předpokládají kalendářní rok (15. 3./6./9./12., resp. '
                . '15. 6. a 15. 12.) — účetní období nebylo předáno. U hospodářského roku termíny ověřte.';
        }
        $filingDeadline = trim((string) ($inputs['filing_deadline'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $filingDeadline) === 1) {
            $nextAdvances['filing_deadline'] = $filingDeadline;
        }

        // Mezisoučet zvýšení HV se nikdy nevyplňoval, přestože XSD atribut má
        // (kc_ii80_70) a základ se z něj počítá. Zkušební EPO to 30. 8. 2026 vytklo
        // dvakrát: „Řádek 70 II. oddílu není naplněn" a „Hodnota ř.200 se nerovná
        // správné (ř.10+70-170)". Číslo bylo správné, chyběl součtový řádek, na
        // kterém stojí křížová kontrola příjemce.
        $line70Reported = round($line40Reported + $depIncrease + $line62Reported, 2);

        $lines = [
            $this->line(10, '10', 'Výsledek hospodaření před zdaněním', $vh, 'deník: Σ 6xx − Σ 5xx (mimo 59x)'),
            $this->line(40, '40', 'Výdaje neuznávané za náklady (§25)', $line40Reported, 'nedaňové účty + neuznatelná ZC vyřazení'
                . ($flatRateTravelAddback > 0 ? ' + add-back PHM při paušálu na dopravu (§24/2/zt)' : '')),
            $this->line(50, '50', 'Účetní odpisy převyšující daňové', $depIncrease, 'rozdíl odpisů (zvýšení)'),
            $this->line(62, '62', 'Ostatní částky zvyšující základ (§23)', $line62Reported, 'ruční vstupy (mimo paušál dopravy) + můstek účetní/daňové ZC'),
            $this->line(70, '70', 'Souhrn částek zvyšujících výsledek hospodaření', $line70Reported, 'mezisoučet ř. 20–62 (ř.40 + ř.50 + ř.62)'),
            $this->line(112, '112', 'Doplňková informace (§23/3 písm. c) — např. paušální výdaj na dopravu', $line112Reported, 'ruční položka rozpoznaná dle textu (§24/2/zt paušál dopravy)'),
            $this->line(150, '150', 'Daňové odpisy převyšující účetní', $depDecrease, 'rozdíl odpisů (snížení)'),
            $this->line(162, '162', 'Ostatní částky snižující základ (§23)', $line162Reported, 'ruční vstupy (mimo paušál dopravy) + můstek účetní/daňové ZC'),
            $this->line(170, '170', 'Souhrn částek snižujících výsledek hospodaření', $line170Reported, 'mezisoučet ř. 101–165 (ř.150 odpisy + ř.162 ostatní §23 + ř.112 paušál dopravy)'),
            $this->line(200, '200', 'Základ daně', $base, 'mezisoučet'),
            $this->line(230, '230', 'Odečet daňové ztráty minulých let (§34)', $lossApplied, 'ruční vstup'),
            $this->line(242, '242', 'Odečet na podporu výzkumu a vývoje (§34/4, §34a–34e)', $rndApplied, 'ruční vstup (projekt VaV)'),
            $this->line(243, '243', 'Odečet na podporu odborného vzdělávání (§34/4, §34f–34h)', $eduApplied, 'ruční vstup'),
            $this->line(250, '250', 'Základ daně snížený o ztrátu a odečty §34', max(0.0, $baseAfterDeductions), 'mezisoučet'),
            $this->line(260, '260', 'Odečet darů (§20/8)', $donationApplied, 'ruční vstup, cap ' . (int) round($donationCapPct * 100) . ' %'),
            $this->line(270, '270', 'Základ daně zaokrouhlený (dolů na tis. Kč)', (float) $roundedBase, 'zaokrouhlení'),
            $this->line(290, '290', 'Daň (' . $this->pct($rate) . ' §21)', $taxGross, 'základ × sazba'),
            $this->line(300, '300', 'Slevy na dani (§35 — zaměstnanci se ZP)', $credits, 'ruční vstup'),
            $this->line(310, '310', 'Daň po slevách', $taxAfterCredits, 'mezisoučet'),
            $this->line(340, '340', 'Celková daňová povinnost', $totalTax, 'výsledná daň'),
            $this->line(360, '360', 'Poslední známá daň pro stanovení záloh (§38a)', $totalTax, 'ř. 340'),
        ];

        return [
            'lines' => $lines,
            'tax' => $totalTax,
            'advances_paid' => $advancesPaid,
            'balance_due' => $balanceDue,
            'next_advances' => $nextAdvances,
            'projection' => $projection,
            'summary' => [
                'rate' => $rate,
                'vh' => $vh,
                'base' => $base,
                'rounded_base' => (float) $roundedBase,
                'tax_gross' => $taxGross,
                'credits' => $credits,
                'credits_entitlement' => $creditsEntitlement,
                'disabled_employee_credit_amount' => $disabledCredit,
                'disabled_employee_severe_credit_amount' => $severeDisabledCredit,
                'disabled_employees_avg' => $disabledAvg,
                'disabled_employees_severe_avg' => $disabledSevereAvg,
                'total_tax' => $totalTax,
                'balance_due' => $balanceDue,
                'loss_applied' => $lossApplied,
                'rnd_applied' => $rndApplied,
                'education_applied' => $eduApplied,
                'donation_applied' => $donationApplied,
            ],
            'warnings' => $warnings,
        ];
    }

    /**
     * Zálohy na daň §38a dle poslední známé daňové povinnosti:
     *  ≤ 30 000 → žádné; 30 000–150 000 → 2 pololetní po 40 %;
     *  > 150 000 → 4 čtvrtletní po 25 %. Záloha zaokrouhlena nahoru na celé stokoruny.
     *
     * @return array{regime:string,count:int,amount:float,total:float,note:string}
     */
    private function advances(float $lastTax, float $low, float $high, int $startMonth, array $c): array
    {
        if ($lastTax <= $low) {
            return ['regime' => 'none', 'count' => 0, 'amount' => 0.0, 'total' => 0.0,
                'note' => 'Poslední známá daňová povinnost ≤ ' . number_format($low, 0, ',', ' ') . ' Kč — zálohy se neplatí.'];
        }
        if ($lastTax <= $high) {
            $amount = $this->ceilTo($lastTax * (float) ($c['advance_semiannual_rate'] ?? 0.40), (int) ($c['advance_rounding_step'] ?? 100));
            $due = $this->advanceDueDates($startMonth, (array) ($c['advance_semiannual_months'] ?? [6, 12]));
            return ['regime' => 'semiannual', 'count' => 2, 'amount' => $amount, 'total' => $amount * 2,
                'note' => '2 pololetní zálohy po 40 % (splatné ' . $due . ').'];
        }
        $amount = $this->ceilTo($lastTax * (float) ($c['advance_quarterly_rate'] ?? 0.25), (int) ($c['advance_rounding_step'] ?? 100));
        $due = $this->advanceDueDates($startMonth, (array) ($c['advance_quarterly_months'] ?? [3, 6, 9, 12]));
        return ['regime' => 'quarterly', 'count' => 4, 'amount' => $amount, 'total' => $amount * 4,
            'note' => '4 čtvrtletní zálohy po 25 % (splatné ' . $due . ').'];
    }

    /**
     * Splatnosti záloh §38a = 15. den N-tého měsíce zdaňovacího období. Pro kalendářní
     * rok (startMonth=1) vrací klasické 15. 6. / 15. 12.; pro hospodářský rok posune.
     *
     * @param list<int> $monthsOfPeriod pořadová čísla měsíců období (6=půlrok, 12=konec)
     */
    private function advanceDueDates(int $startMonth, array $monthsOfPeriod): string
    {
        $labels = [];
        foreach ($monthsOfPeriod as $k) {
            $month = (($startMonth - 1 + ($k - 1)) % 12) + 1;
            $labels[] = '15. ' . $month . '.';
        }
        return implode(', ', array_slice($labels, 0, -1))
            . (count($labels) > 1 ? ' a ' : '') . end($labels);
    }

    /**
     * Dary §20/8: PO smí odečíst jen dary, jejichž HODNOTA jednoho daru je alespoň 2 000 Kč.
     * Preferuje se položkový vstup (donation_items: text + amount) — položky pod 2 000 Kč se
     * vyloučí s upozorněním. Bez položek (jen agregát `donations`) minimum nelze spolehlivě
     * ověřit → jen varování k ověření.
     *
     * @param array<string,mixed> $inputs
     * @return array{0:float,1:list<string>}
     */
    private function resolveDonations(array $inputs, float $minDonation): array
    {
        $items = $inputs['donation_items'] ?? null;
        if (is_array($items) && $items !== []) {
            $eligible = 0.0;
            $excludedCount = 0;
            $excludedSum = 0.0;
            foreach ($items as $item) {
                $amount = is_array($item) ? (float) ($item['amount'] ?? 0) : (is_numeric($item) ? (float) $item : 0.0);
                if ($amount <= 0) {
                    continue;
                }
                if ($amount >= $minDonation) {
                    $eligible += $amount;
                } else {
                    $excludedCount++;
                    $excludedSum += $amount;
                }
            }
            $warn = [];
            if ($excludedCount > 0) {
                $warn[] = 'Z darů bylo vyloučeno ' . $excludedCount . ' pod hranicí 2 000 Kč ('
                    . number_format($excludedSum, 0, ',', ' ') . ' Kč) — §20 odst. 8 ZDP odečet daru '
                    . 'nižšího než 2 000 Kč neumožňuje.';
            }
            return [round(max(0.0, $eligible), 2), $warn];
        }

        $agg = max(0.0, round((float) ($inputs['donations'] ?? 0), 2));
        $warn = [];
        if ($agg > 0) {
            $warn[] = 'Dary jsou zadány souhrnnou částkou — ověřte, že žádný jednotlivý dar nebyl pod '
                . '2 000 Kč (§20 odst. 8 ZDP odečet takového daru neumožňuje).';
        }
        return [$agg, $warn];
    }

    /** @param mixed $items @return float */
    private function sumItems(mixed $items): float
    {
        return ManualItemsSum::sum($items);
    }

    /**
     * Součet ručních položek (§23), jejichž text odpovídá paušálnímu výdaji na dopravu
     * §24/2/zt (a jeho protějšku — add-backu PHM, viz kompletní mechanismus v {@see compute()}).
     * Ruční položky nemají typovaný kód, jen volný text — proto detekce dle klíčových slov.
     *
     * @param mixed $items
     */
    private function sumFlatRateTravelItems(mixed $items): float
    {
        if (!is_array($items)) {
            return 0.0;
        }
        $sum = 0.0;
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $amount = (float) ($item['amount'] ?? 0);
            if ($amount <= 0) {
                continue;
            }
            if ($this->isFlatRateTravelItem($item)) {
                $sum += $amount;
            }
        }
        return round(max(0.0, $sum), 2);
    }

    /**
     * Je položka paušálním výdajem na dopravu (§ 24/2/zt)?
     *
     * PŘEDNOST má explicitní `kind`. Rozpoznávání podle TEXTU zůstává jen jako fallback
     * pro položky zadané dřív (a přes API bez `kind`) — je nespolehlivé v obou směrech:
     * „paušál na dopravu vozidla" projde, ale „krácený výdaj na automobil dle zt" ne,
     * a naopak text s oběma slovy může být něco úplně jiného. Chybné zařazení nemění
     * základ daně, ale vykáže částku na jiném řádku přiznání, než na kterém být má.
     *
     * @param array<string,mixed> $item
     */
    private function isFlatRateTravelItem(array $item): bool
    {
        if (($item['kind'] ?? null) === self::KIND_FLAT_RATE_TRAVEL) {
            return true;
        }
        // Explicitně jiný druh položky heuristiku VYPÍNÁ — jinak by text „paušál na
        // dopravu" přebil vědomé zařazení účetní.
        if (!empty($item['kind'])) {
            return false;
        }

        return $this->matchesFlatRateTravelText((string) ($item['text'] ?? ''));
    }

    /** Klíčová slova „paušál" + „doprav" (diakritiku nezávisle) — pokrývá oba směry §24/2/zt. */
    private function matchesFlatRateTravelText(string $text): bool
    {
        $folded = $this->foldCzechDiacritics(mb_strtolower($text, 'UTF-8'));
        return str_contains($folded, 'pausal') && str_contains($folded, 'doprav');
    }

    /**
     * Položky, které o dopravě mluví, ale za paušál označené nejsou. Systém je zařadit
     * neumí — a mlčet by znamenalo tiše je vykázat na obecném ř. 62/162.
     *
     * @param mixed $items
     * @return list<string>
     */
    private function ambiguousTravelTexts(mixed $items): array
    {
        if (!is_array($items)) {
            return [];
        }
        $out = [];
        foreach ($items as $item) {
            if (!is_array($item) || $this->isFlatRateTravelItem($item)) {
                continue;
            }
            $text = (string) ($item['text'] ?? '');
            $folded = $this->foldCzechDiacritics(mb_strtolower($text, 'UTF-8'));
            if ($text !== '' && (str_contains($folded, 'doprav') || str_contains($folded, 'vozidl')
                || str_contains($folded, 'automobil') || str_contains($folded, 'phm'))) {
                $out[] = $text;
            }
        }

        return $out;
    }

    private function foldCzechDiacritics(string $s): string
    {
        static $map = [
            'á' => 'a', 'č' => 'c', 'ď' => 'd', 'é' => 'e', 'ě' => 'e', 'í' => 'i', 'ň' => 'n',
            'ó' => 'o', 'ř' => 'r', 'š' => 's', 'ť' => 't', 'ú' => 'u', 'ů' => 'u', 'ý' => 'y', 'ž' => 'z',
        ];
        return strtr($s, $map);
    }

    /**
     * Daň ze základu daně (ř.200) shodným řetězcem jako posted větev: odečet ztráty §34 →
     * odečty §34/4 (VaV, odborné vzdělávání) → odečet darů §20/8 (cap % ze základu
     * sníženého podle §34) → zaokrouhlení na tis. → sazba §21 → slevy §35.
     * Používá se pro PROJEKTOVANOU daň (Feature 1) z projektovaného základu.
     *
     * Řetězec MUSÍ zůstat shodný s hlavní větví — jinak by se projekce a skutečný výpočet
     * rozešly přesně u poplatníka, který odečty §34/4 uplatňuje.
     */
    private function taxFromBase(
        float $base,
        float $lossCarry,
        float $donations,
        float $donationCapPct,
        float $rate,
        int $roundBase,
        float $creditsEntitlement,
        float $rndDeduction = 0.0,
        float $educationDeduction = 0.0,
    ): float {
        $lossApplied = $base > 0 ? min($lossCarry, $base) : 0.0;
        $baseAfterLoss = round($base - $lossApplied, 2);
        $rndApplied = min(max(0.0, $rndDeduction), max(0.0, $baseAfterLoss));
        $baseAfterRnd = round($baseAfterLoss - $rndApplied, 2);
        $eduApplied = min(max(0.0, $educationDeduction), max(0.0, $baseAfterRnd));
        $baseAfterDeductions = round($baseAfterRnd - $eduApplied, 2);
        $donationCap = max(0.0, round($donationCapPct * max(0.0, $baseAfterDeductions), 2));
        $donationApplied = min($donations, $donationCap);
        $roundedBase = $this->floorTo(max(0.0, $baseAfterDeductions - $donationApplied), $roundBase);
        $taxGross = round($roundedBase * $rate, 2);
        $credits = min($creditsEntitlement, $taxGross);
        return max(0.0, round($taxGross - $credits, 2));
    }

    private function floorTo(float $value, int $step): int
    {
        if ($step <= 0) {
            return (int) floor($value);
        }
        return (int) (floor($value / $step) * $step);
    }

    private function ceilTo(float $value, int $step): float
    {
        if ($step <= 0) {
            return round($value, 2);
        }
        return ceil($value / $step) * $step;
    }

    private function pct(float $rate): string
    {
        return rtrim(rtrim(number_format($rate * 100, 2, ',', ''), '0'), ',') . ' %';
    }

    /** @return array{line:int,code:string,label:string,value:float,source:string} */
    private function line(int $line, string $code, string $label, float $value, string $source): array
    {
        return ['line' => $line, 'code' => $code, 'label' => $label, 'value' => round($value, 2), 'source' => $source];
    }
}
