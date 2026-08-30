<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Payroll;

/**
 * Rozpad měsíční mzdy ze závislé činnosti (§6 ZDP) — jádro mzdové rekapitulace.
 *
 * Čistá statická funkce bez DB závislosti (vzor {@see \MyInvoice\Service\Tax\DpfoCalculator});
 * `$c` = roční konstanty z {@see \MyInvoice\Repository\TaxConstantsRepository::forExactYear()},
 * mzdové sazby v podklíči `payroll`.
 *
 * ── Odkud jsou mzdové hodnoty ───────────────────────────────────────────────
 * Sazby pojistného a zálohové daně (`payroll`), minimální mzda, měsíční hranice
 * § 38h odst. 2 a rozhodný příjem pocházejí pro ročníky s mzdovým rulesetem
 * z NĚJ — {@see \MyInvoice\Service\Tax\TaxConstants::withPayrollRulesetConstants()}
 * je do roční sady zrcadlí, takže modul Mzdy i tahle starší rekapitulace počítají
 * z jedné sady čísel. Dřív měl každý svoji a nic je nedrželo pohromadě. Rozhraní
 * se tím nemění: pořád se čte z `$c`, jen za těmi čísly stojí jiný zdroj.
 *
 * ── Proč se počítá a neukládá do šablony ────────────────────────────────────
 * Doplatek zdravotního pojištění do minimálního vyměřovacího základu závisí na
 * minimální mzdě, takže se každý rok mění. Šablona s pevnými částkami by po
 * novém roce tiše účtovala špatně.
 *
 * ── Doplatek do minimálního VZ (§3 odst. 10 z. 592/1992 Sb.) ────────────────
 * Je-li vyměřovací základ nižší než minimální (= minimální mzda), odvádí se
 * pojistné z minimálního základu a rozdíl 13,5 % × (min. mzda − hrubá mzda)
 * hradí v plné výši ZAMĚSTNANEC.
 *
 * ── Zaokrouhlení ────────────────────────────────────────────────────────────
 * Model odvozený z reálného deníku účetní (27 měsíců 2024–2026) a ověřený testy:
 *   - pojistné zaměstnance a úhrn ZP se zaokrouhluje na celé Kč NAHORU
 *     (§3 odst. 12 z. 592/1992 Sb., §5c z. 589/1992 Sb.)
 *   - doplatek do minimálního VZ se zaokrouhluje DOLŮ
 *   - ZP zaměstnavatele je DOPOČET do úhrnu 13,5 % z vyměřovacího základu,
 *     ne samostatně zaokrouhlených 9 %
 * Ten dopočet je podstatný: pro hrubou 4 000 / 2024 vychází 361 Kč, kdežto
 * zaokrouhlených 9 % by dalo 360 Kč a úhrn ZP by neseděl na 13,5 % z 18 900.
 *
 * ── Základ pro zálohu na daň (§38h odst. 1 ZDP) ─────────────────────────────
 * Záloha se NEPOČÍTÁ z hrubé mzdy přímo: základ se do 100 Kč zaokrouhlí na celé
 * koruny nahoru a nad 100 Kč na celé STOKORUNY nahoru. U hrubé 24 850 je tedy
 * základ 24 900 a záloha 3 735 Kč, ne 3 728 Kč. Golden hodnoty z deníku účetní
 * mají hrubou mzdu v kulatých stovkách (4 000 / 4 500), takže tohle pravidlo
 * v nich není vidět — proto ho hlídají vlastní testy.
 *
 * ── Progresivní sazba (§38h odst. 2 ZDP) ─────────────────────────────────────
 * Nad měsíční hranicí 3× průměrné mzdy (`advance_tax_high_threshold`) se ČÁST
 * základu nad hranicí daní 23 %, zbytek dál 15 %.
 *
 * ── Maximální vyměřovací základ SP (§15a z. 589/1992) ───────────────────────
 * Strop je 48× průměrná mzda ZA ROK (`social_max_base`) a platí PER ZAMĚSTNANCE.
 * Nad ním se sociální pojistné neplatí — ani zaměstnancem, ani zaměstnavatelem.
 * Zdravotní pojištění strop NEMÁ (zrušen 2013), takže se nekrátí nikdy.
 *
 * Uplatní se jen tehdy, když volající PŘEDÁ dosavadní roční základ zaměstnance
 * (`$ytdSocialBase`). Bez něj se nekrátí — a to je úmysl, ne opomenutí: `compute()`
 * se volá i nad REKAPITULACÍ za všechny zaměstnance, kde je součet hrubých mezd
 * legitimně vyšší než strop jednotlivce. Kdyby se strop aplikoval i tam, srazil by
 * pojistné celé firmě. Kontext zaměstnance dodává {@see PayrollPostingService},
 * který ho čte z `payroll_monthly_records`.
 *
 * ── Slevy na dani (§35ba, §38h odst. 4 ZDP) ─────────────────────────────────
 * Plátce sráží zálohu už SNÍŽENOU o měsíční slevu, takže na 342 (a do příkazu
 * na FÚ) patří `advance_tax_withheld`, ne hrubá `advance_tax`. Slevu lze uplatnit
 * jen u poplatníka s podepsaným prohlášením (§38k) — proto se předává zvenčí
 * a `compute()` bez ní počítá hrubý rozpad (tak to má i reálný deník účetní,
 * kde jednatel prohlášení podepsané nemá a záloha se nesnižuje).
 */
final class PayrollCalculator
{
    /**
     * Poplatník = zaměstnanec v pracovním poměru → 521/331.
     */
    public const TYPE_EMPLOYEE = 'employee';

    /**
     * Poplatník = jednatel/společník s odměnou ze závislé činnosti → 522/366.
     */
    public const TYPE_MANAGING_PARTNER = 'managing_partner';

    /** @return list<string> */
    public static function types(): array
    {
        return [self::TYPE_EMPLOYEE, self::TYPE_MANAGING_PARTNER];
    }

    /**
     * Účty nákladu a závazku vůči poplatníkovi dle typu.
     *
     * @return array{expense:string,payable:string}
     */
    public static function accounts(string $type): array
    {
        return $type === self::TYPE_MANAGING_PARTNER
            // 522 Příjmy společníků ze závislé činnosti / 366 Závazky ke společníkům
            ? ['expense' => '522', 'payable' => '366']
            // 521 Mzdové náklady / 331 Zaměstnanci
            : ['expense' => '521', 'payable' => '331'];
    }

    /**
     * Rozpad hrubé mzdy.
     *
     * @param float $gross hrubá mzda / odměna za měsíc
     * @param array<string,mixed> $c roční konstanty (musí obsahovat `minimum_wage` a `payroll`)
     * @param array{taxpayer:int,children:int,total:int}|null $credits měsíční slevy
     *        ({@see self::monthlyCredits()}); `null` = poplatník bez podepsaného
     *        prohlášení (§38k), záloha se o nic nesnižuje
     *
     * @return array{
     *   gross:int, minimum_wage:int, assessment_base:int,
     *   employee_social:int, employee_health:int, health_min_topup:int,
     *   employee_deductions:int, tax_base:int,
     *   tax_high_threshold:int, tax_high_base:int, advance_tax:int,
     *   credit_taxpayer:int, credit_children:int, credit_total:int,
     *   advance_tax_withheld:int, net:int,
     *   employer_social:int, employer_health:int, employer_total:int,
     *   health_total:int, social_total:int, remittance_total:int, super_gross:int,
     *   social_base:int, social_max_base:int, social_base_capped:bool,
     *   below_participation:bool
     * }
     *
     * @param ?float $ytdSocialBase Vyměřovací základ SP, který zaměstnanec v tomto roce
     *        UŽ vyčerpal (bez tohoto měsíce). `null` = kontext zaměstnance není známý →
     *        strop §15a se neuplatní (viz docblock třídy — rekapitulace za víc lidí).
     */
    public static function compute(
        float $gross,
        array $c,
        ?array $credits = null,
        ?float $ytdSocialBase = null,
    ): array {
        if ($gross < 0) {
            throw new \InvalidArgumentException('Hrubá mzda nesmí být záporná.');
        }
        $p = $c['payroll'] ?? null;
        if (!is_array($p)) {
            throw new \InvalidArgumentException(
                'Roční konstanty neobsahují mzdové sazby (klíč `payroll`).'
            );
        }
        $minimumWage = (float) ($c['minimum_wage'] ?? 0);
        if ($minimumWage <= 0) {
            throw new \InvalidArgumentException('Roční konstanty neobsahují minimální mzdu.');
        }

        // Vyměřovací základ ZP: minimálně minimální mzda (§3 odst. 6 z. 592/1992).
        $assessmentBase = max($gross, $minimumWage);

        // Sociální pojištění se z minimálního základu NEdopočítává — základem je
        // skutečný příjem (z. 589/1992 žádný minimální VZ pro zaměstnance nezná).
        //
        // §15a: nad ročním stropem 48× průměrné mzdy se SP neplatí. Krátí se jen se
        // známým kontextem zaměstnance — bez něj by se strop aplikoval na rekapitulaci
        // za všechny a srazil pojistné celé firmě.
        $socialMaxBase = (float) ($c['social_max_base'] ?? 0);
        $socialBase = $gross;
        $socialBaseCapped = false;
        if ($ytdSocialBase !== null && $socialMaxBase > 0) {
            $remaining = max(0.0, $socialMaxBase - max(0.0, $ytdSocialBase));
            if ($gross > $remaining) {
                $socialBase = $remaining;
                $socialBaseCapped = true;
            }
        }

        // ROZHODNÝ PŘÍJEM (§ 6 odst. 1 písm. a) z. 187/2006) — účast na nemocenském
        // a tím i důchodovém pojištění vzniká až při jeho DOSAŽENÍ. Pod ním jde
        // o zaměstnání malého rozsahu (§ 7) a sociální pojistné se neodvádí VŮBEC,
        // ani zaměstnancem, ani zaměstnavatelem.
        //
        // Dřív se SP počítalo vždy z hrubé mzdy: při 4 400 Kč by se strhlo 313 Kč
        // zaměstnanci a 1 092 Kč zaměstnavateli, které stát nenárokuje. Zdravotního
        // pojištění se to NETÝKÁ — tam žádný rozhodný příjem není a minimální
        // vyměřovací základ platí dál.
        //
        // Hranice je „dosáhne", ne „přesáhne": při přesně rozhodném příjmu účast VZNIKÁ.
        $participationThreshold = (float) ($c['sickness_participation_threshold'] ?? 0);
        $belowParticipation = $participationThreshold > 0 && $gross < $participationThreshold;
        if ($belowParticipation) {
            $socialBase = 0.0;
        }

        $employeeSocial = self::roundUp($socialBase * (float) $p['employee_social']);
        // Zdravotní pojištění strop nemá (zrušen 2013) — počítá se z plné hrubé mzdy.
        $employeeHealth = self::roundUp($gross * (float) $p['employee_health']);

        // Doplatek do minimálního VZ hradí celý zaměstnanec; zaokrouhluje se dolů.
        $healthMinTopup = (int) floor(
            max(0.0, $minimumWage - $gross) * (float) $p['health_total']
        );

        // Úhrn ZP se zaokrouhluje JEDNOU, z vyměřovacího základu (13,5 %) — ne jako
        // součet samostatně zaokrouhlených složek. Zaměstnavatel je dopočet do úhrnu,
        // takže rozpad nemůže dát o korunu víc, než se reálně odvede na ZP.
        $healthTotal = self::roundUp($assessmentBase * (float) $p['health_total']);

        // Pojistka na to, aby složky VŽDY daly úhrn. U mikroskopické hrubé mzdy může
        // ceil zaměstnancových 4,5 % převážit floor doplatku a součet úhrn přestřelit;
        // dřívější `max(0, …)` sice udrželo zaměstnavatele nezáporného, ale rozpad pak
        // tvrdil víc, než kolik `health_total` (a s ním `remittance_total`) uvádí.
        // Ořezává se strana zaměstnance, protože úhrn je zákonná veličina.
        $employeeHealth = min($employeeHealth, $healthTotal);
        $healthMinTopup = min($healthMinTopup, $healthTotal - $employeeHealth);
        $employerHealth = $healthTotal - $employeeHealth - $healthMinTopup;
        // Strop §15a platí pro OBĚ strany — zaměstnavatel nad ním neplatí taky.
        $employerSocial = self::roundUp($socialBase * (float) $p['employer_social']);

        // Záloha na daň: od 2021 ze superhrubé mzdy → z hrubé (§38h ZDP), základ
        // ale zaokrouhlený dle §38h odst. 1 (viz class docblock).
        $taxBase = self::taxBase($gross);
        $highThreshold = (int) ($c['advance_tax_high_threshold'] ?? 0);
        $highRate = (float) ($p['advance_tax_high'] ?? $p['advance_tax']);
        $advanceTax = self::advanceTax($taxBase, (float) $p['advance_tax'], $highRate, $highThreshold);

        // §38h odst. 4: plátce srazí zálohu sníženou o měsíční slevu. Ořez na nulu,
        // protože sleva na poplatníka se neproplácí jako bonus (§35ba odst. 1).
        // Daňové zvýhodnění na děti může nad rámec sražené daně vzniknout jako daňový
        // BONUS (§35c odst. 3, vázaný na minimální roční příjem) — ten se NEMODELUJE,
        // byla by to vymyšlená hodnota bez podkladu; vyjde sražená záloha 0, ne záporná.
        $creditTaxpayer = max(0, (int) ($credits['taxpayer'] ?? 0));
        $creditChildren = max(0, (int) ($credits['children'] ?? 0));
        $creditTotal = $creditTaxpayer + $creditChildren;
        $advanceTaxWithheld = max(0, $advanceTax - $creditTotal);

        $employeeDeductions = $employeeSocial + $employeeHealth + $healthMinTopup;
        $net = (int) round($gross) - $employeeDeductions - $advanceTaxWithheld;

        return [
            'gross'               => (int) round($gross),
            'minimum_wage'        => (int) $minimumWage,
            'assessment_base'     => (int) $assessmentBase,
            // Vyměřovací základ SP po uplatnění stropu §15a. Vrací se vždy, aby šlo
            // z rozpadu poznat, že se krátilo — jinak by rozdíl proti hrubé mzdě
            // vypadal jako chyba výpočtu.
            'social_base'         => (int) round($socialBase),
            'social_max_base'     => (int) $socialMaxBase,
            'social_base_capped'  => $socialBaseCapped,
            // Bez tohohle příznaku by nulové sociální pojistné vypadalo jako chyba —
            // z rozpadu musí být poznat, že jde o zaměstnání malého rozsahu (§ 7).
            'below_participation' => $belowParticipation,
            'employee_social'     => $employeeSocial,
            'employee_health'     => $employeeHealth,
            'health_min_topup'    => $healthMinTopup,
            'employee_deductions' => $employeeDeductions,
            'tax_base'            => $taxBase,
            // Část základu zdaněná 23 % (§38h odst. 2) — nula u běžných mezd.
            'tax_high_threshold'  => $highThreshold,
            'tax_high_base'       => $highThreshold > 0 ? max(0, $taxBase - $highThreshold) : 0,
            // `advance_tax` = záloha PŘED slevami (co by se srazilo bez prohlášení),
            // `advance_tax_withheld` = co se reálně srazí a odvede na FÚ (účtuje se na 342).
            'advance_tax'         => $advanceTax,
            'credit_taxpayer'     => $creditTaxpayer,
            'credit_children'     => $creditChildren,
            'credit_total'        => $creditTotal,
            'advance_tax_withheld' => $advanceTaxWithheld,
            'net'                 => $net,
            'employer_social'     => $employerSocial,
            'employer_health'     => $employerHealth,
            'employer_total'      => $employerSocial + $employerHealth,
            // Úhrny za obě strany = přesně to, co jde příkazem na ZP / OSSZ / FÚ.
            // Rozpad výš je pohled zaměstnance, `employer_*` pohled nákladu — ani
            // jeden nedá částku k úhradě, takže by se rekapitulace nedala porovnat
            // s hromadným příkazem od účetní (proto se vrací zvlášť).
            'health_total'        => $healthTotal,
            'social_total'        => $employeeSocial + $employerSocial,
            // Na FÚ odchází sražená (po slevě) záloha, ne hrubá — jinak by příkaz
            // k úhradě u poplatníka s prohlášením přeplácel o celou slevu.
            'remittance_total'    => $healthTotal + $employeeSocial + $employerSocial + $advanceTaxWithheld,
            'super_gross'         => (int) round($gross) + $employerSocial + $employerHealth,
        ];
    }

    /**
     * Progresivní záloha na daň (§38h odst. 2 ZDP): 15 % ze základu do měsíční
     * hranice (3× průměrná mzda) a 23 % jen z ČÁSTI základu nad ni — ne z celého.
     *
     * Bez hranice v konstantách (`advance_tax_high_threshold` = 0) se počítá jednou
     * sazbou; ročníky před zavedením progrese tak zůstávají beze změny.
     */
    private static function advanceTax(int $taxBase, float $lowRate, float $highRate, int $threshold): int
    {
        if ($threshold <= 0 || $taxBase <= $threshold) {
            return self::roundUp($taxBase * $lowRate);
        }
        // Zaokrouhluje se až výsledná záloha, ne každé pásmo zvlášť — jinak by se
        // u základu těsně nad hranicí přidala koruna navíc.
        return self::roundUp(
            $threshold * $lowRate + ($taxBase - $threshold) * $highRate
        );
    }

    /**
     * Základ pro výpočet zálohy na daň (§38h odst. 1 ZDP): do 100 Kč na celé
     * koruny nahoru, nad 100 Kč na celé stokoruny nahoru.
     */
    private static function taxBase(float $gross): int
    {
        $g = round($gross, 2);
        if ($g <= 0) {
            return 0;
        }
        return $g <= 100.0
            ? (int) ceil($g)
            : (int) (ceil($g / 100) * 100);
    }

    /**
     * Řádky účetního zápisu pro {@see \MyInvoice\Service\Accounting\PostingService::postDocument()}.
     * Kontace dle deníku účetní (doklad IMZ):
     *
     *   521/522 MD / 331/366 D   hrubá mzda
     *   524     MD / 336     D   pojistné zaměstnavatele (SP + ZP)
     *   331/366 MD / 336     D   pojistné zaměstnance včetně doplatku do min. VZ
     *   331/366 MD / 342     D   záloha na daň PO SLEVĚ (§38h odst. 4) — na 342 patří
     *                            jen to, co se reálně odvede na FÚ
     *                            → na 331/366 zbyde čistá mzda
     *
     * Volitelně se čistá mzda rovnou přeúčtuje jinam (migrace 1178):
     *
     *   331/366 MD / $settlementAccount D   čistá mzda (typicky 365.x — zápočet proti
     *                                       účtu společníka, když se odměna nevyplácí)
     *
     * Pár je součástí TÉHOŽ zápisu, ne samostatného dokladu: jde o jeden hospodářský
     * případ, saldo 331/366 se tak měsíc co měsíc vynuluje a přeúčtování i storno mzdy
     * s ním zacházejí automaticky (jeden `source_id` = RRRRMM).
     *
     * ── Konfigurovatelné účty a rozpad 336 ──────────────────────────────────
     * Konkrétní kódy dodává {@see PayrollPostingAccounts} (bez nich platí syntetiky
     * jako dosud). Vede-li firma sociální a zdravotní na ODDĚLENÝCH analytikách
     * (336.100 / 336.200), rozdělí se i závazek — jinak by na jedné analytice visel
     * součet a saldo by neodpovídalo hromadnému příkazu, který má dva příjemce.
     * Se společným účtem se řádky slijí zpátky do jednoho, aby se deníku firem bez
     * analytiky nic nezměnilo.
     *
     * @param array<string,int> $b výstup {@see self::compute()}
     * @param string|null $settlementAccount kód účtu pro čistou mzdu; NULL = ponechat závazek
     * @param PayrollPostingAccounts|null $accounts kontace firmy; NULL = syntetiky
     * @return list<array{account_code:string,side:string,amount:float,description?:string}>
     */
    public static function lines(
        array $b,
        string $type,
        ?string $settlementAccount = null,
        ?PayrollPostingAccounts $accounts = null,
    ): array {
        $accounts ??= PayrollPostingAccounts::defaults();
        $a = $accounts->forType($type);
        $lines = [];
        $add = static function (string $code, string $side, int $amount, string $desc) use (&$lines): void {
            if ($amount === 0) {
                return; // nulový řádek by jen zaplevelil deník
            }
            $lines[] = [
                'account_code' => $code,
                'side'         => $side,
                'amount'       => (float) $amount,
                'description'  => $desc,
            ];
        };

        /**
         * Závazek z pojistného na stranu D: buď jedním řádkem (společná 336), nebo
         * rozdělený na sociální a zdravotní. Rozděluje se jen tehdy, když složky
         * SEDÍ na úhrn — starší snapshot `payroll_monthly_records.breakdown` je mít
         * nemusí a vymyšlený rozpad by zápis rozvážil.
         */
        $addInsurance = static function (int $social, int $health, int $total, string $desc) use ($accounts, $add): void {
            if ($accounts->insuranceIsPooled() || $social + $health !== $total) {
                $add($accounts->socialPayable, 'credit', $total, $desc);
                return;
            }
            $add($accounts->socialPayable, 'credit', $social, $desc . ' — sociální');
            $add($accounts->healthPayable, 'credit', $health, $desc . ' — zdravotní');
        };

        $add($a['expense'], 'debit',  $b['gross'], 'Hrubá mzda');
        $add($a['payable'], 'credit', $b['gross'], 'Hrubá mzda');

        $add($accounts->employerInsurance, 'debit', $b['employer_total'], 'Zákonné pojistné zaměstnavatele');
        $addInsurance(
            (int) ($b['employer_social'] ?? 0),
            (int) ($b['employer_health'] ?? 0),
            (int) $b['employer_total'],
            'Zákonné pojistné zaměstnavatele',
        );

        $add($a['payable'], 'debit', $b['employee_deductions'], 'Pojistné zaměstnance');
        $addInsurance(
            (int) ($b['employee_social'] ?? 0),
            // Doplatek do minimálního VZ je zdravotní pojistné — patří na tutéž
            // analytiku jako 4,5 %, ne na sociální.
            (int) ($b['employee_health'] ?? 0) + (int) ($b['health_min_topup'] ?? 0),
            (int) $b['employee_deductions'],
            'Pojistné zaměstnance',
        );

        // Fallback na `advance_tax`: snapshoty uložené před zavedením slev
        // (payroll_monthly_records.breakdown) klíč `advance_tax_withheld` nemají.
        $withheld = $b['advance_tax_withheld'] ?? $b['advance_tax'];
        $add($a['payable'], 'debit', $withheld, 'Záloha na daň z příjmu');
        $add($accounts->incomeTaxPayable, 'credit', $withheld, 'Záloha na daň z příjmu');

        // Přeúčtování čisté mzdy (1178). `net` je to, co po srážkách zbylo na 331/366 —
        // stejná částka, jakou by jinak účetní ručně započetla na konci roku.
        if ($settlementAccount !== null && $settlementAccount !== '') {
            $net = (int) ($b['net'] ?? 0);
            $add($a['payable'], 'debit', $net, 'Zápočet čisté mzdy');
            $add($settlementAccount, 'credit', $net, 'Zápočet čisté mzdy');
        }

        return $lines;
    }

    /**
     * Zaokrouhlení pojistného na celé koruny nahoru. `ceil()` na floatu je citlivý
     * na binární reprezentaci (0,045 × 4 500 = 202,49999…), proto se hodnota
     * nejdřív srovná na haléře.
     */
    private static function roundUp(float $value): int
    {
        return (int) ceil(round($value, 2));
    }

    /**
     * Měsíční daňové slevy (§35ba, §35c ZDP) — vstup do {@see self::compute()}.
     * Uplatní se jen u poplatníka s podepsaným prohlášením (§38k ZDP); volající
     * (rekapitulace / mzdový list) rozhoduje, jestli je podepsané.
     *
     * `credit_taxpayer` a `child_credits` v {@see \MyInvoice\Service\Tax\TaxConstants}
     * jsou ROČNÍ částky (slouží i DPFO výpočtu OSVČ) — u závislé činnosti se uplatňují
     * měsíčně jako 1/12 (§38h odst. 4 ZDP), proto dělení tady.
     *
     * Právě proto tyhle dvě konstanty zůstaly v `TaxConstants` a nezrcadlí se
     * z mzdového rulesetu jako sazby: čte je i daňová část a jejich per-klíč
     * override je živá funkce číselníku. Že nesou tutéž zákonnou částku jako
     * měsíční parametry rulesetu, hlídá `TaxConstantsPayrollRatesMatchRulesetTest`.
     *
     * @param array<string,mixed> $c roční konstanty (TaxConstantsRepository::forExactYear)
     * @return array{taxpayer:int, children:int, total:int}
     */
    public static function monthlyCredits(array $c, bool $taxpayerCredit, int $childCount): array
    {
        $taxpayer = $taxpayerCredit ? (int) round(((float) ($c['credit_taxpayer'] ?? 0)) / 12) : 0;

        $table = $c['child_credits'] ?? [];
        $children = 0;
        for ($i = 1; $i <= max(0, $childCount); $i++) {
            $annual = (float) ($table[min($i, count($table)) - 1] ?? 0);
            $children += (int) round($annual / 12);
        }

        return ['taxpayer' => $taxpayer, 'children' => $children, 'total' => $taxpayer + $children];
    }

}
