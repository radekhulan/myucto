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
 * Každý řádek je v celých korunách a součtové řádky se sčítají ze zaokrouhlených
 * řádků, jak je kontroluje EPO ({@see TaxFormAmount}).
 *
 * Pipeline (§23–§35 ZDP, formulář DPPDP9):
 *   ř.10  VH před zdaněním (Σ 6xx − Σ 5xx mimo 59x)
 *   ř.40  výdaje neuznávané za náklady §25 (nedaňové účty + neuznatelná ZC vyřazení
 *         + účetní ZC vyřazení převyšující daňovou + nabývací cena prodaných podílů nad
 *         příjmy z prodeje §24/2/w + add-back PHM při paušálu na dopravu §24/2/zt)
 *   ř.50  účetní odpisy převyšující daňové (zvýšení základu)
 *   ř.62  ostatní částky zvyšující základ §23 (ruční mimo paušál dopravy)
 *   ř.112 doplňková informace k §23/3 písm. c) — např. paušální výdaj na dopravu (§24/2/zt),
 *         rozpoznáno dle textu ruční snižující položky (klíčová slova „paušál" + „doprav")
 *   ř.150 daňové odpisy převyšující účetní (snížení základu)
 *   ř.160 daňové výdaje převyšující účetní náklady §24 (daňová ZC vyřazení převyšující účetní)
 *   ř.162 ostatní částky snižující základ §23 (ruční mimo paušál dopravy)
 *   ř.170 souhrn částek snižujících výsledek hospodaření (ř. 101–165 mezisoučet: ř.150+ř.160+ř.162+ř.112)
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

    /** Řádky, na které lze ruční zvyšující položku zařadit (`line`); bez něj ř. 62. */
    public const INCREASE_ITEM_LINES = [20, 30, 40, 61, 62];

    /** Řádky, na které lze ruční snižující položku zařadit (`line`); bez něj ř. 162. */
    public const DECREASE_ITEM_LINES = [100, 101, 109, 110, 111, 112, 120, 130, 140, 160, 161, 162];

    /** Řádky, jejichž částku pokyny k tiskopisu chtějí rozvést na zvláštní příloze (VetaR). */
    public const ITEM_LINES_WITH_APPENDIX = [20, 30, 109, 110, 111, 112, 140];

    /** Popisky řádků, které nesou jen ruční položky s explicitním řádkem. */
    private const ITEM_LINE_LABELS = [
        20 => 'Částky neoprávněně zkracující příjmy a nepeněžní příjmy (§23/3/a/1)',
        30 => 'Částky zvyšující výsledek hospodaření podle §23/3/a (mimo ř. 20 a 40)',
        61 => 'Úprava výsledku hospodaření při vstupu do likvidace (zvýšení)',
        100 => 'Příjmy, které nejsou předmětem daně (§18/2)',
        101 => 'Příjmy veřejně prospěšného poplatníka, které nejsou předmětem daně (§18a/1)',
        109 => 'Příjmy osvobozené od daně (§19b)',
        110 => 'Příjmy osvobozené od daně (§19)',
        111 => 'Částky snižující výsledek hospodaření podle §23/3/b',
        120 => 'Příjmy zdaňované zvláštní sazbou daně vybírané srážkou (§36)',
        130 => 'Příjmy zdaňované v samostatném základu daně (§21/4)',
        140 => 'Částky nezahrnované do základu daně (§23/4)',
        161 => 'Úprava výsledku hospodaření při vstupu do likvidace (snížení)',
    ];

    /**
     * @param array<string,mixed> $data   podklady z DppoReturnDataProvider
     * @param array<string,mixed> $inputs ruční vstupy (income_tax_returns.inputs)
     * @param array<string,mixed> $c      roční konstanty (TaxConstantsRepository::forYear)
     * @return array{
     *   lines: list<array{line:int,code:string,label:string,value:float,source:string}>,
     *   tax: float, advances_paid: float, balance_due: float,
     *   next_advances: array{regime:string,count:int,amount:float,total:float,note:string},
     *   summary: array<string,float>,
     *   depreciation_by_group: array{tangible:array<int,float>,intangible:float,unclassified:float},
     *   related_party_country_flag: 'N'|'T'|'Z'|'A',
     *   related_party_appendix: list<array{name:string,country_iso2:string,ic:?string,issued_total:float,received_total:float}>,
     *   bank_account: array{account_number:?string,bank_code:?string,bank_name:?string,iban:?string}|null,
     *   manual_increase_items_line62: list<array{text:string,amount:float}>,
     *   line160_appendix: list<array{group:string,amount:float}>,
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
        // Ruční položky, které SKUTEČNĚ skončí na ř. 62 (viz $line62Reported níže) —
        // tj. bez těch, co paušál dopravy přesouvá na ř. 40 (VetaE). Zdroj pro
        // VetaXmlBuilder::buildVetaR („Zvláštní příloha ř. 62 II. odd.", chyba EPO) —
        // jeden řádek volného textu na položku, ne souhrn, proto se filtruje tady a ne
        // až v builderu (jediné místo, které zná pravidlo paušálu dopravy).
        $line62Items = $this->line62Items($inputs['manual_increase_items'] ?? []);
        $lossCarry = max(0.0, TaxFormAmount::kc((float) ($inputs['loss_carryforward'] ?? 0)));
        [$donations, $donationWarnings] = $this->resolveDonations($inputs, (float) ($c['donation_min_po'] ?? 2000));
        $donations = TaxFormAmount::kc($donations);
        $warnings = array_merge($warnings, $donationWarnings);
        $disabledAvg = max(0.0, (float) ($inputs['disabled_employees_avg'] ?? 0));
        $disabledSevereAvg = max(0.0, (float) ($inputs['disabled_employees_severe_avg'] ?? 0));
        // § 35 odst. 4 ZDP — sleva za zastavenou exekuci (ř. 3 tabulky H, `kc_dpp_f3`).
        // Odvodit z účetnictví nelze: nárok vzniká usnesením exekutora o zastavení exekuce,
        // ne účetním dokladem. Proto ruční vstup.
        $stoppedExecutionCredit = max(0.0, TaxFormAmount::kc((float) ($inputs['stopped_execution_credit'] ?? 0)));
        $advancesPaid = max(0.0, TaxFormAmount::kc((float) ($inputs['tax_paid_advances'] ?? 0)));

        // ── Konstanty ───────────────────────────────────────────────────────
        $rate = (float) ($c['corporate_tax_rate'] ?? 0.21);
        $donationCapPct = (float) ($c['donation_cap_po_pct'] ?? 0.30);
        $creditPerDisabled = (float) ($c['disabled_employee_credit'] ?? 18000);
        $creditPerSevere = (float) ($c['disabled_employee_credit_severe'] ?? 60000);
        $roundBase = (int) ($c['rounding_base_po'] ?? 1000);
        $advLow = (float) ($c['advance_threshold_low'] ?? 30000);
        $advHigh = (float) ($c['advance_threshold_high'] ?? 150000);

        // ── Úpravy základu (§23) ────────────────────────────────────────────
        // Můstek ZC vyřazeného majetku podle pokynů k tiskopisu 25 5404 (vzor pro 2024):
        //   - „K ř. 160 … např. při prodeji hmotného a nehmotného majetku rozdíl, o který
        //     daňová zůstatková cena (§ 29 zákona) převyšuje účetní zůstatkovou cenu" →
        //     daňová ZC vyšší = ř. 160 (se zvláštní přílohou podle účtových skupin nákladů);
        //   - „K ř. 40 … souhrn rozdílů, o které náklady uplatněné v účetnictví převyšují
        //     … daňové výdaje podle § 24 a 25 zákona, s výjimkou rozdílu, o který účetní
        //     odpisy převyšují daňové" → účetní ZC vyšší = ř. 40 (a tím i tabulka A).
        // Ř. 50/150 jsou jen odpisy, ř. 62/162 jen „případy neuvedené na ř. 20 až 61"
        // (resp. 109 až 161) — pro ZC tedy ne. Základ daně to nemění, jen řádky.
        $line40 = self::accountingAdjustments($data)[40];

        // ── Rozpad výpisu ř.40/62/112/162/170 — §24/2/zt paušál na dopravu ────
        // Přesouvá add-back PHM (increase) z obecného ř.62 na ř.40 a paušální výdaj
        // (decrease) z obecného ř.162 na ř.112/170, PŘESNĚ dle toho, jak to podává
        // účetní — věcně ověřeno proti podanému přiznání za rok 2024 (DPPDP9:
        // kc_ii_112=45000 + VetaR příloha k ř. 112, add-back PHM v kc_ii50_40 přes
        // tabulku A). Add-back zaúčtovaných PHM je nedaňový náklad §25/1/x → ř. 40;
        // paušál sám podle pokynů GFŘ patří na ř. 162, podání na ř. 112 s textovou
        // přílohou je ale přijímaná praxe a základ daně (ř. 170/200) je identický.
        // Jinak stejné částky, jen jiné řádky (kosmetika, součty výše beze změny).
        // Položky s explicitním řádkem (`line`, typicky převzaté z podaného přiznání) se
        // vykážou na svém řádku místo obecného ř. 62/162; součty ř. 70/170/200 se nemění.
        $increaseByLine = $this->itemsByLine($inputs['manual_increase_items'] ?? [], self::INCREASE_ITEM_LINES, 62);
        $decreaseByLine = $this->itemsByLine($inputs['manual_decrease_items'] ?? [], self::DECREASE_ITEM_LINES, 162);
        // Každý řádek formuláře v celých korunách, zaokrouhlený JEDNOU z haléřové hodnoty
        // ({@see TaxFormAmount}); součtové řádky se skládají až z těchto zaokrouhlených
        // řádků. Tiskopis: ř. 70 = 20 + 30 + 40 + 50 + 61 + 62, ř. 170 = 100 + 101 + 109
        // + 110 + 111 + 112 + 120 + 130 + 140 + 150 + 160 + 161 + 162, ř. 200 = 10 + 70
        // − 170. Součet v haléřích zaokrouhlený až na konci se od součtu uvedených řádků
        // liší až o korunu a EPO ho odmítne („Hodnota ř.200 se nerovná správné
        // (ř.10+70-170)"). Rozpad paušálu na dopravu tak může posunout základ nejvýš
        // o zaokrouhlení jednotlivých řádků — základ daně JE součtem uvedených řádků.
        $line10 = TaxFormAmount::kc($vh);
        $increaseLines = array_map(TaxFormAmount::kc(...), $increaseByLine);
        $decreaseLines = array_map(TaxFormAmount::kc(...), $decreaseByLine);
        $line40Reported = TaxFormAmount::kc($line40 + $flatRateTravelAddback + ($increaseByLine[40] ?? 0.0));
        // ř.50: účetní > daňové → +, ř.150: daňové > účetní → − (táž funkce i pro projekci)
        [50 => $line50, 150 => $line150] = self::depreciationLines($depAcc, $depTax);
        $line62Reported = TaxFormAmount::kc($manualIncrease - $flatRateTravelAddback - array_sum($increaseByLine));
        $line160Reported = TaxFormAmount::kc($disposalDecrease + ($decreaseByLine[160] ?? 0.0));
        $line162Reported = TaxFormAmount::kc($manualDecrease - $flatRateTravelDeduction - array_sum($decreaseByLine));
        $line112Reported = TaxFormAmount::kc($flatRateTravelDeduction + ($decreaseByLine[112] ?? 0.0));
        $line70Reported = $line40Reported + $line50 + $line62Reported
            + ($increaseLines[20] ?? 0.0) + ($increaseLines[30] ?? 0.0) + ($increaseLines[61] ?? 0.0);
        $line170Reported = $line150 + $line160Reported + $line162Reported + $line112Reported
            + array_sum(array_diff_key($decreaseLines, [112 => true, 160 => true]));

        // ř.200 základ daně před odečty (může být záporný).
        $base = $line10 + $line70Reported - $line170Reported;

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
        $baseAfterLoss = $base - $lossApplied; // ř.250

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
        $rndClaimed = max(0.0, TaxFormAmount::kc((float) ($inputs['rnd_deduction'] ?? 0)));
        $rndApplied = min($rndClaimed, max(0.0, $baseAfterLoss));
        if ($rndClaimed > $rndApplied) {
            $warnings[] = 'Odečet na podporu výzkumu a vývoje (ř. 242) se uplatnil jen do výše základu; '
                . 'zbytek ' . number_format($rndClaimed - $rndApplied, 0, ',', ' ') . ' Kč lze podle § 34 odst. 5 '
                . 'uplatnit v následujících 3 obdobích — systém tenhle přenos NEEVIDUJE, hlídejte si ho.';
        }
        $baseAfterRnd = $baseAfterLoss - $rndApplied;

        $eduClaimed = max(0.0, TaxFormAmount::kc((float) ($inputs['education_deduction'] ?? 0)));
        $eduApplied = min($eduClaimed, max(0.0, $baseAfterRnd));
        if ($eduClaimed > $eduApplied) {
            $warnings[] = 'Odečet na podporu odborného vzdělávání (ř. 243) se uplatnil jen do výše základu '
                . 'sníženého o ztrátu a o odečet na výzkum a vývoj; zbytek '
                . number_format($eduClaimed - $eduApplied, 0, ',', ' ') . ' Kč se neodečte.';
        }
        $baseAfterDeductions = $baseAfterRnd - $eduApplied;

        // ř.260 odečet darů §20/8 — cap % ze základu sníženého podle § 34, tedy nejen
        // o ztrátu, ale i o odečty na VaV a odborné vzdělávání. Dokud odečty § 34 odst. 4
        // neexistovaly, byl `baseAfterLoss` totéž; s nimi už ne.
        $donationCap = $this->donationCap($donationCapPct, $baseAfterDeductions);
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
        $taxGross = TaxFormAmount::kc($roundedBase * $rate);

        // ř.300 slevy §35 = úhrn ř. 4 tabulky H (ř.1 zaměstnanci se ZP + ř.2 TZP + ř.3
        // zastavené exekuce §35/4), omezený výší daně na ř. 290.
        $disabledCredit = TaxFormAmount::kc($disabledAvg * $creditPerDisabled);
        $severeDisabledCredit = TaxFormAmount::kc($disabledSevereAvg * $creditPerSevere);
        $creditsEntitlement = $disabledCredit + $severeDisabledCredit + $stoppedExecutionCredit;
        $credits = min($creditsEntitlement, $taxGross);
        if ($creditsEntitlement > $credits) {
            $warnings[] = 'Sleva §35 byla omezena výší daně na ř. 290; neuplatněný nárok činí '
                . number_format($creditsEntitlement - $credits, 0, ',', ' ') . ' Kč.';
        }

        // ř.310 / ř.340 daň po slevách (nezáporná)
        $taxAfterCredits = max(0.0, $taxGross - $credits);
        $totalTax = $taxAfterCredits;

        // FEATURE 1 — projekce závěrkových operací (§DP): pokud podklady nesou nezaúčtované
        // uzávěrkové kroky, dopočti PROJEKTOVANOU daň z projektovaného VH. Posted čísla zůstávají
        // beze změny (kalkulace výše) — projekce je jen náhled „jak to dopadne po uzávěrce“.
        $projection = null;
        $proj = $data['closing_projection'] ?? null;
        if (is_array($proj) && ($proj['is_projection'] ?? false) === true) {
            $vhProjected = round((float) ($proj['vh_projected'] ?? $vh), 2);
            // Projektovaný základ = posted základ posunutý o rozdíl VH a o změnu rozdílu odpisů
            // ř. 50/150 po přičtení nezaúčtovaných odpisů roku (stejná funkce jako u zaúčtovaných);
            // ostatní úpravy §23 se nemění. ř. 10 je i v projekci v celých korunách.
            $pendingDep = (array) ($proj['depreciation'] ?? []);
            $projectedDep = self::depreciationLines(
                round($depAcc + (float) ($pendingDep['accounting'] ?? 0), 2),
                round($depTax + (float) ($pendingDep['tax'] ?? 0), 2),
            );
            $projectedIncreases = $line70Reported + ($projectedDep[50] - $line50);
            $projectedDecreases = $line170Reported + ($projectedDep[150] - $line150);
            $projectedBase = TaxFormAmount::kc($vhProjected) + $projectedIncreases - $projectedDecreases;
            $projectedTax = $this->taxFromBase($projectedBase, $lossCarry, $donations, $donationCapPct, $rate, $roundBase, $creditsEntitlement, $rndClaimed, $eduClaimed);
            $projection = [
                'vh_posted' => round($vh, 2),
                'vh_projected' => $vhProjected,
                'projected_increases' => $projectedIncreases,
                'projected_decreases' => $projectedDecreases,
                'projected_base' => $projectedBase,
                'projected_tax' => $projectedTax,
                'is_projection' => true,
                'items' => array_values((array) ($proj['items'] ?? [])),
            ];
        }

        // ř.360 doplatek/přeplatek
        $balanceDue = $totalTax - $advancesPaid;

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
        // kterém stojí křížová kontrola příjemce. Počítá se výše s ostatními řádky.
        $itemLine = fn (int $n, array $byLine): array => $this->line($n, (string) $n, self::ITEM_LINE_LABELS[$n], $byLine[$n] ?? 0.0, 'ruční vstup s řádkem přiznání');

        $lines = [
            $this->line(10, '10', 'Výsledek hospodaření před zdaněním', $line10, 'deník: Σ 6xx − Σ 5xx (mimo 59x)'),
            $itemLine(20, $increaseLines),
            $itemLine(30, $increaseLines),
            $this->line(40, '40', 'Výdaje neuznávané za náklady (§25)', $line40Reported, 'nedaňové účty + účetní ZC vyřazení převyšující daňovou'
                . ((float) ($data['securities_cost_excess'] ?? 0) > 0 ? ' + nabývací cena prodaných podílů nad příjmy z prodeje (§24/2/w)' : '')
                . ($flatRateTravelAddback > 0 ? ' + add-back PHM při paušálu na dopravu (§24/2/zt)' : '')),
            $this->line(50, '50', 'Účetní odpisy převyšující daňové', $line50, 'rozdíl odpisů (zvýšení)'),
            $itemLine(61, $increaseLines),
            $this->line(62, '62', 'Ostatní částky zvyšující základ (§23)', $line62Reported, 'ruční vstupy (mimo paušál dopravy)'),
            $this->line(70, '70', 'Souhrn částek zvyšujících výsledek hospodaření', $line70Reported, 'mezisoučet ř. 20–62 (ř.40 + ř.50 + ř.62)'),
            $itemLine(100, $decreaseLines),
            $itemLine(101, $decreaseLines),
            $itemLine(109, $decreaseLines),
            $itemLine(110, $decreaseLines),
            $itemLine(111, $decreaseLines),
            $this->line(112, '112', 'Doplňková informace (§23/3 písm. c) — např. paušální výdaj na dopravu', $line112Reported, 'ruční položka rozpoznaná dle textu (§24/2/zt paušál dopravy)'),
            $itemLine(120, $decreaseLines),
            $itemLine(130, $decreaseLines),
            $itemLine(140, $decreaseLines),
            $this->line(150, '150', 'Daňové odpisy převyšující účetní', $line150, 'rozdíl odpisů (snížení)'),
            $this->line(160, '160', 'Daňové výdaje převyšující účetní náklady (§24)', $line160Reported, 'daňová ZC vyřazeného majetku převyšující účetní'),
            $itemLine(161, $decreaseLines),
            $this->line(162, '162', 'Ostatní částky snižující základ (§23)', $line162Reported, 'ruční vstupy (mimo paušál dopravy)'),
            $this->line(170, '170', 'Souhrn částek snižujících výsledek hospodaření', $line170Reported, 'mezisoučet ř. 101–165 (ř.150 odpisy + ř.160 ZC vyřazení + ř.162 ostatní §23 + ř.112 paušál dopravy)'),
            $this->line(200, '200', 'Základ daně', $base, 'mezisoučet'),
            $this->line(230, '230', 'Odečet daňové ztráty minulých let (§34)', $lossApplied, 'ruční vstup'),
            $this->line(242, '242', 'Odečet na podporu výzkumu a vývoje (§34/4, §34a–34e)', $rndApplied, 'ruční vstup (projekt VaV)'),
            $this->line(243, '243', 'Odečet na podporu odborného vzdělávání (§34/4, §34f–34h)', $eduApplied, 'ruční vstup'),
            $this->line(250, '250', 'Základ daně snížený o ztrátu a odečty §34', max(0.0, $baseAfterDeductions), 'mezisoučet'),
            $this->line(260, '260', 'Odečet darů (§20/8)', $donationApplied, 'ruční vstup, cap ' . (int) round($donationCapPct * 100) . ' %'),
            $this->line(270, '270', 'Základ daně zaokrouhlený (dolů na tis. Kč)', (float) $roundedBase, 'zaokrouhlení'),
            $this->line(290, '290', 'Daň (' . $this->pct($rate) . ' §21)', $taxGross, 'základ × sazba'),
            $this->line(300, '300', 'Slevy na dani (§35 — zaměstnanci se ZP, zastavené exekuce)', $credits, 'ruční vstup'),
            $this->line(310, '310', 'Daň po slevách', $taxAfterCredits, 'mezisoučet'),
            $this->line(340, '340', 'Celková daňová povinnost', $totalTax, 'výsledná daň'),
            $this->line(360, '360', 'Poslední známá daň pro stanovení záloh (§38a)', $totalTax, 'ř. 340'),
        ];
        // Řádky ručních položek s explicitním řádkem jen, když na nich něco je - přiznání
        // bez takových položek má výpis řádků beze změny.
        $lines = array_values(array_filter($lines, fn (array $l): bool
            => !isset(self::ITEM_LINE_LABELS[$l['line']]) || $l['value'] !== 0.0));

        return [
            'lines' => $lines,
            'tax' => $totalTax,
            'advances_paid' => $advancesPaid,
            'balance_due' => $balanceDue,
            'next_advances' => $nextAdvances,
            'projection' => $projection,
            // Průchozí podklady z DppoReturnDataProvider pro DppoXmlBuilder (VetaF/VetaD/VetaNP) —
            // kalkulátor je nepočítá, jen je nese dál, aby builder nemusel dostávat $data zvlášť.
            'depreciation_by_group' => (array) ($data['depreciation_by_group'] ?? ['tangible' => [], 'intangible' => 0.0, 'unclassified' => 0.0]),
            'related_party_country_flag' => (string) ($data['related_party_country_flag'] ?? 'N'),
            'related_party_appendix' => (array) ($data['related_party_appendix'] ?? []),
            'legal_provisions' => (array) ($data['legal_provisions'] ?? LegalProvisionLedgerService::empty()),
            'bank_account' => $data['bank_account'] ?? null,
            'manual_increase_items_line62' => $line62Items,
            'manual_items_line_appendix' => $this->lineAppendixItems($inputs),
            // Rozpad podle účtových skupin se páruje s haléřovou částkou skupin; je to jen
            // textová příloha, číselně ji EPO proti ř. 160 nekontroluje.
            'line160_appendix' => $this->line160Appendix(
                $data['disposal_decrease_groups'] ?? null,
                round($disposalDecrease + ($decreaseByLine[160] ?? 0.0), 2),
            ),
            'summary' => [
                'rate' => $rate,
                'vh' => $line10,
                'base' => $base,
                'rounded_base' => (float) $roundedBase,
                'tax_gross' => $taxGross,
                'credits' => $credits,
                'credits_entitlement' => $creditsEntitlement,
                'disabled_employee_credit_amount' => $disabledCredit,
                'disabled_employee_severe_credit_amount' => $severeDisabledCredit,
                'disabled_employees_avg' => $disabledAvg,
                'disabled_employees_severe_avg' => $disabledSevereAvg,
                'stopped_execution_credit_amount' => $stoppedExecutionCredit,
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
     * Ř. 50 a ř. 150 z účetních a daňových odpisů roku, v celých korunách. Jediné místo
     * rozdílu odpisů — zaúčtované odpisy i projekce s nezaúčtovanými jdou touto funkcí.
     *
     * @return array{50:float,150:float}
     */
    public static function depreciationLines(float $accounting, float $tax): array
    {
        return [
            50 => TaxFormAmount::kc(max(0.0, round($accounting - $tax, 2))),
            150 => TaxFormAmount::kc(max(0.0, round($tax - $accounting, 2))),
        ];
    }

    /**
     * Část ř. 40 a ř. 160, kterou přiznání bere z účetnictví a karet majetku (nedaňové účty,
     * můstek ZC vyřazeného majetku, převis nabývací ceny prodaných podílů nad příjmy z prodeje
     * podle § 24/2/w), bez ručních vstupů. Převzetí podaného přiznání
     * ({@see \MyInvoice\Service\Migration\Shared\FiledDppoInputs}) z ř. 40 a ř. 160 přebírá
     * jen zbytek nad ni, jinak by se tatáž částka v základu objevila dvakrát.
     *
     * @param array<string,mixed> $data podklady z DppoReturnDataProvider
     * @return array{40:float,160:float}
     */
    public static function accountingAdjustments(array $data): array
    {
        return [
            40 => round(
                round((float) ($data['non_deductible_costs'] ?? 0), 2)
                + round((float) ($data['disposal_nondeductible_residual'] ?? 0), 2)
                + max(0.0, round((float) ($data['disposal_tax_increase'] ?? 0), 2))
                + max(0.0, round((float) ($data['securities_cost_excess'] ?? 0), 2)),
                2
            ),
            160 => max(0.0, round((float) ($data['disposal_tax_decrease'] ?? 0), 2)),
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
     * Ruční položky §23, které skutečně skončí na ř. 62 (mimo paušál dopravy — ten
     * jde na ř. 40, viz $flatRateTravelAddback v compute()). Vrací jen `{text, amount}`
     * pár na položku (DppoXmlBuilder::buildVetaR z nich staví VetaR — jeden řádek
     * volného textu na položku, XSD max. 72 znaků).
     *
     * @param mixed $items
     * @return list<array{text:string,amount:float}>
     */
    private function line62Items(mixed $items): array
    {
        if (!is_array($items)) {
            return [];
        }
        $out = [];
        foreach ($items as $item) {
            if (!is_array($item) || $this->isFlatRateTravelItem($item)
                || $this->explicitLine($item, self::INCREASE_ITEM_LINES, 62) !== null) {
                continue;
            }
            $amount = round((float) ($item['amount'] ?? 0), 2);
            $text = trim((string) ($item['text'] ?? ''));
            if ($amount <= 0.0 && $text === '') {
                continue;
            }
            $out[] = ['text' => $text, 'amount' => $amount];
        }
        return $out;
    }

    /**
     * Explicitní řádek ruční položky, pokud je povolený a liší se od obecného řádku.
     * Paušál na dopravu má vlastní zařazení (ř. 40/112) a řádek u něj nerozhoduje.
     *
     * @param array<string,mixed> $item
     * @param list<int> $allowed
     */
    private function explicitLine(array $item, array $allowed, int $default): ?int
    {
        $line = $item['line'] ?? null;
        if (!is_numeric($line) || $this->isFlatRateTravelItem($item)) {
            return null;
        }
        $line = (int) $line;
        return in_array($line, $allowed, true) && $line !== $default ? $line : null;
    }

    /**
     * Součty ručních položek s explicitním řádkem (mimo obecný ř. 62/162).
     *
     * @param list<int> $allowed
     * @return array<int,float>
     */
    private function itemsByLine(mixed $items, array $allowed, int $default): array
    {
        if (!is_array($items)) {
            return [];
        }
        $out = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $line = $this->explicitLine($item, $allowed, $default);
            if ($line !== null) {
                $out[$line] = round(($out[$line] ?? 0.0) + (float) ($item['amount'] ?? 0), 2);
            }
        }
        return $out;
    }

    /**
     * Ruční položky na řádcích, které pokyny chtějí rozvést na zvláštní příloze
     * ({@see ITEM_LINES_WITH_APPENDIX}), pro VetaR: řádek, text a částka.
     *
     * @param array<string,mixed> $inputs
     * @return list<array{line:int,text:string,amount:float}>
     */
    private function lineAppendixItems(array $inputs): array
    {
        $out = [];
        $groups = [
            [$inputs['manual_increase_items'] ?? [], self::INCREASE_ITEM_LINES, 62],
            [$inputs['manual_decrease_items'] ?? [], self::DECREASE_ITEM_LINES, 162],
        ];
        foreach ($groups as [$items, $allowed, $default]) {
            if (!is_array($items)) {
                continue;
            }
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $line = $this->explicitLine($item, $allowed, $default);
                $amount = round((float) ($item['amount'] ?? 0), 2);
                if ($line !== null && in_array($line, self::ITEM_LINES_WITH_APPENDIX, true) && $amount > 0.0) {
                    $out[] = ['line' => $line, 'text' => trim((string) ($item['text'] ?? '')), 'amount' => $amount];
                }
            }
        }
        return $out;
    }

    /**
     * Zvláštní příloha k ř. 160: „rozdělení této souhrnné částky podle účtových skupin
     * účtové třídy - náklady" (pokyny k DPPO). Skupiny dodává {@see DppoReturnDataProvider}
     * z nákladového účtu vyřazení; bez nich (volající předává jen souhrn) jde celá
     * částka do skupiny 54 (zůstatková cena prodaného a vyřazeného majetku).
     *
     * @return list<array{group:string,amount:float}>
     */
    private function line160Appendix(mixed $groups, float $total): array
    {
        if ($total <= 0.0) {
            return [];
        }
        $out = [];
        if (is_array($groups)) {
            ksort($groups);
            foreach ($groups as $group => $amount) {
                $amount = round((float) $amount, 2);
                if ($amount > 0.0) {
                    $out[] = ['group' => (string) $group, 'amount' => $amount];
                }
            }
        }
        if ($out === [] || abs(array_sum(array_column($out, 'amount')) - $total) >= 0.01) {
            return [['group' => '54', 'amount' => $total]];
        }
        return $out;
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
        return self::looksLikeFlatRateTravel($text);
    }

    /**
     * Text mluví o paušálním výdaji na dopravu (§ 24/2/zt). Sdílí ho převzetí podaného
     * přiznání, které podle textu zvláštní přílohy k ř. 112 pozná, zda jde o paušál.
     */
    public static function looksLikeFlatRateTravel(string $text): bool
    {
        $folded = strtr(mb_strtolower($text, 'UTF-8'), [
            'á' => 'a', 'č' => 'c', 'ď' => 'd', 'é' => 'e', 'ě' => 'e', 'í' => 'i', 'ň' => 'n',
            'ó' => 'o', 'ř' => 'r', 'š' => 's', 'ť' => 't', 'ú' => 'u', 'ů' => 'u', 'ý' => 'y', 'ž' => 'z',
        ]);
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
        $baseAfterLoss = $base - $lossApplied;
        $rndApplied = min(max(0.0, $rndDeduction), max(0.0, $baseAfterLoss));
        $baseAfterRnd = $baseAfterLoss - $rndApplied;
        $eduApplied = min(max(0.0, $educationDeduction), max(0.0, $baseAfterRnd));
        $baseAfterDeductions = $baseAfterRnd - $eduApplied;
        $donationCap = $this->donationCap($donationCapPct, $baseAfterDeductions);
        $donationApplied = min($donations, $donationCap);
        $roundedBase = $this->floorTo(max(0.0, $baseAfterDeductions - $donationApplied), $roundBase);
        $taxGross = TaxFormAmount::kc($roundedBase * $rate);
        $credits = min($creditsEntitlement, $taxGross);
        return max(0.0, $taxGross - $credits);
    }

    /**
     * Strop odečtu darů (ř. 260) v celých korunách. Zákon dovoluje odečíst „nejvýše"
     * dané procento ze ř. 250, proto se strop zaokrouhluje DOLŮ — matematické
     * zaokrouhlení by u zlomku nad půl koruny dovolilo odečíst víc, než zákon připouští.
     */
    private function donationCap(float $pct, float $baseAfterDeductions): float
    {
        return max(0.0, floor(round($pct * max(0.0, $baseAfterDeductions), 2)));
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
        return TaxFormAmount::ceilTo($value, $step);
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
