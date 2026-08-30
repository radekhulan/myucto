<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

final class PayrollAccountingDefaults
{
    /** @var array<string,array{code:string,type:string}> */
    public const ACCOUNTS = [
        'employment_gross_debit' => ['code' => '521', 'type' => 'expense'],
        'employment_gross_credit' => ['code' => '331', 'type' => 'liability'],
        'partner_gross_debit' => ['code' => '522', 'type' => 'expense'],
        'partner_gross_credit' => ['code' => '366', 'type' => 'liability'],
        'statutory_gross_debit' => ['code' => '523', 'type' => 'expense'],
        'statutory_gross_credit' => ['code' => '366', 'type' => 'liability'],
        'employer_insurance_debit' => ['code' => '524', 'type' => 'expense'],
        // Sociální a zdravotní pojištění MÁ KAŽDÉ SVOU ANALYTIKU.
        //
        // Syntetika 336 pokrývá obě instituce, jenže dluží se DVĚMA různým
        // věřitelům (ČSSZ a zdravotní pojišťovny) a platí se dvěma příkazy.
        // Na společné 336 se závazek vůči ČSSZ a vůči pojišťovnám vzájemně
        // vynetuje a saldo účtu proti dvěma platbám nesedí — rozdíl se pozná
        // teprve tehdy, když se obě strany zaplatí přesně, což je přesně ten
        // okamžik, kdy už je pozdě.
        //
        // Tvar analytiky drží konvenci osnovy (343.100/343.200, 511.100 …):
        // třímístná syntetika, tečka, třímístná analytika.
        //
        // ⚠ ZPĚTNÁ KOMPATIBILITA: tohle je VÝCHOZÍ hodnota pro NOVĚ zakládanou
        // firmu, ne migrace stávajících. Firmy, které mají v
        // `payroll_employer_settings` uloženou 336, ji mají dál a jejich
        // zaúčtované revize se nemění — zmrazený snapshot nese vlastní sadu
        // předkontací (PayrollApprovedRevisionPostingService::execute). Kdo
        // chce rozpad zpětně, přepne si účty v nastavení mezd; obě analytiky
        // doplnila do osnovy migrace 1618.
        'social_insurance_credit' => ['code' => '336.100', 'type' => 'liability'],
        'health_insurance_credit' => ['code' => '336.200', 'type' => 'liability'],
        // Zálohová a srážková daň MAJÍ KAŽDÁ SVOU ANALYTIKU (Ú-13).
        //
        // Obě jsou daní ze závislé činnosti a obě se odvádějí témuž správci
        // daně, jenže jinou platbou (předčíslí 7704 vs. 7720), v jiném termínu
        // a vykazují se jiným hlášením (vyúčtování § 38j vs. § 38d odst. 3).
        // Na společné 342 se závazek z obou daní slije a rozdíl mezi saldem
        // účtu a odvedenými platbami nejde přiřadit k jedné z nich — přestože
        // platební vrstva obě rozlišuje (`advance_tax` / `withholding_tax`).
        // V deníku je rozlišoval jen `allocation_key`, který se do
        // `journal_entry_lines` nepromítá.
        //
        // Tvar analytiky drží konvenci osnovy (336.100/336.200, 343.100 …):
        // třímístná syntetika, tečka, třímístná analytika.
        //
        // ⚠ ZPĚTNÁ KOMPATIBILITA — stejně konzervativně jako u 336: tohle je
        // výchozí hodnota pro NOVĚ zakládanou firmu, ne migrace stávajících.
        // Firmy s uloženou 342 ji mají dál, zaúčtované revize se nemění
        // (zmrazený snapshot nese vlastní sadu předkontací) a rozdělení
        // srážkové daně je navíc v SNAPSHOT_GATED_ACCOUNTS, takže se použije
        // teprve tehdy, když o něm zmrazený snapshot ví. Obě analytiky doplnila
        // do osnovy migrace 1648.
        'income_tax_credit' => ['code' => '342.100', 'type' => 'liability'],
        'withholding_tax_credit' => ['code' => '342.200', 'type' => 'liability'],
        'other_deductions_credit' => ['code' => '379', 'type' => 'liability'],
        // Protiúčet zápočtu čisté mzdy na účet společníka (331/366 MD / 365 D).
        // Firemní default; konkrétní analytiku (365.100…) drží výplatní pravidlo
        // osoby, viz PayrollPartnerSettlement.
        'partner_settlement_credit' => ['code' => '365', 'type' => 'liability'],
        // Povinný příspěvek zaměstnavatele na spoření u rizikové práce
        // (z. č. 324/2025 Sb., 4 % vyměřovacího základu od 1. 1. 2026).
        // Je to ZÁKONNÝ sociální náklad zaměstnavatele, ne mzda zaměstnance:
        // zaměstnanci se nevyplácí, posílá se penzijní společnosti. Proto
        // 527 MD (zákonné sociální náklady) proti 379 D (jiný závazek) —
        // 336 sem nepatří, penzijní společnost není institucí sociálního
        // zabezpečení ani zdravotní pojišťovnou.
        'risky_savings_debit' => ['code' => '527', 'type' => 'expense'],
        'risky_savings_credit' => ['code' => '379', 'type' => 'liability'],
        // Přeplatek na čisté mzdě: zaměstnanci se nedluží, naopak on dluží
        // zaměstnavateli (typicky doplatek ZP do minimálního vyměřovacího
        // základu podle § 3 odst. 10 z. 592/1992 Sb. v měsíci bez peněžního
        // příjmu). Zůstatek závazkového účtu mzdy by byl debetní, takže se
        // překlopí na pohledávku za zaměstnancem.
        'employee_receivable_debit' => ['code' => '335', 'type' => 'asset'],
        // Daňově NEuznatelná část benefitu podle § 25 odst. 1 písm. h) ZDP.
        //
        // Ustanovení je od 1. 1. 2024 vázané na osvobození u zaměstnance:
        // nepeněžní plnění ve formě rekreace, sportu, kultury, tištěných knih,
        // zdravotnických a vzdělávacích zařízení není nákladem „a to v rozsahu,
        // ve kterém je toto plnění u zaměstnance osvobozeno od daně z příjmů".
        // Nedaňová je tedy OSVOBOZENÁ část, ne nadlimitní — nadlimitní část se
        // zaměstnanci zdaní a zaměstnavateli je uznatelná podle § 24 odst. 2
        // písm. j) bodu 4. Viz PayrollPostingLineBuilder::benefitSplit().
        //
        // 528 je v ChartOfAccountsTemplate::NON_DEDUCTIBLE_SYNTHETICS, takže se
        // částka sama propíše do nedaňových nákladů DPPO (DppoReturnDataProvider
        // mapuje 528 na `taxReturn.suggest_528`).
        'non_deductible_benefit_debit' => ['code' => '528', 'type' => 'expense'],
        // Cestovní náhrada NENÍ mzda: je to náhrada výdaje zaměstnance podle
        // části sedmé zákoníku práce, ne odměna za práci. Do 521 (mzdové
        // náklady) proto nepatří — patří na 512 (cestovné), stejně jako
        // cestovné vyúčtované mimo mzdy.
        //
        // Protiúčet vlastní klíč NEMÁ a bere se závazkový účet pracovního
        // vztahu (331, resp. 366): náhrada se zaměstnanci vyplácí SPOLU se
        // mzdou v jedné částce, vstupuje do čisté výplaty i do platebního
        // závazku `net_wage` a musí zůstat v poměrovém rozdělení srážek.
        // Samostatná 333 by ji z kategorie `net_wage` v reconciliaci
        // vyvedla a do rozdělení srážek pustila účet, který se nesráží —
        // rozbor je v migraci 1618.
        'travel_expense_debit' => ['code' => '512', 'type' => 'expense'],
    ];

    /**
     * Předkontace, které do sady přibyly AŽ POTOM, co se začaly zmrazovat
     * mzdové snapshoty a ukládat firemní nastavení.
     *
     * Starší zmrazený snapshot je neměnný a tyhle klíče v sobě nikdy mít
     * nebude; starší klient nastavení je také neposílá. Kdyby se vyžadovaly
     * tvrdě, znamenalo by přidání nové předkontace, že (a) opakované
     * zaúčtování dřív schválené revize spadne a (b) firma nemůže uložit
     * nastavení mezd. Chybějící klíč se proto doplní výchozím účtem ze
     * směrné osnovy — to je přesně stav, který ve firmě fakticky platil.
     *
     * @var list<string>
     */
    public const OPTIONAL_ACCOUNTS = [
        'risky_savings_debit',
        'risky_savings_credit',
        'employee_receivable_debit',
        'non_deductible_benefit_debit',
        'travel_expense_debit',
        'withholding_tax_credit',
    ];

    /**
     * Předkontace, které se PROJEVÍ AŽ TÍM, že je zmrazený snapshot obsahuje.
     *
     * Rozdíl proti {@see OPTIONAL_ACCOUNTS} je zásadní. Tam chybějící klíč jen
     * doplní výchozí účet a zápis zůstane STEJNÝ, protože jde o kontaci pro
     * částku, která se dřív neúčtovala vůbec (spoření, pohledávka). Tady by ale
     * doplnění výchozího účtu ZMĚNILO ZÁPIS částky, která se odjakživa účtovala
     * jinam — nadlimitní benefit i cestovní náhrada dosud končily na účtu hrubé
     * mzdy.
     *
     * Kdyby se nové rozdělení použilo i na starší zmrazený snapshot, opakované
     * zaúčtování dřív schválené revize by spadlo na kontrolu cílového otisku
     * (`Zaúčtovaná revize má jiný cílový účetní otisk.` v
     * {@see \MyInvoice\Service\Payroll\Posting\PayrollPostingAdapter}) a
     * reconciliace by u zaúčtovaného období ukázala rozdíl proti deníku.
     *
     * Rozhoduje proto SUROVÁ sada předkontací, kterou nese snapshot: klíč v ní
     * je = firma se rozhodla účtovat nově. Klíč v ní není = revize se zaúčtuje
     * byte-identicky jako dřív.
     *
     * @var list<string>
     */
    public const SNAPSHOT_GATED_ACCOUNTS = [
        'non_deductible_benefit_debit',
        'travel_expense_debit',
        // Srážková daň se odjakživa účtovala na účet zálohové daně. Doplnění
        // výchozí analytiky by tedy ZMĚNILO zápis částky, která se dosud
        // účtovala jinam — přesně ten případ, kvůli kterému tenhle seznam je.
        'withholding_tax_credit',
    ];

    /** Výchozí účet klíče, nebo `null` u neznámého klíče. */
    public static function defaultCode(string $key): ?string
    {
        return self::ACCOUNTS[$key]['code'] ?? null;
    }

    public static function isOptional(string $key): bool
    {
        return in_array($key, self::OPTIONAL_ACCOUNTS, true);
    }

    /**
     * Nese SUROVÁ (nedoplněná) sada předkontací všechny klíče, bez kterých se
     * dělení {@see SNAPSHOT_GATED_ACCOUNTS} nesmí použít?
     *
     * @param array<string,mixed> $configuredAccounts sada, jak přišla ze
     *        zmrazeného snapshotu — PŘED doplněním výchozích účtů
     */
    public static function snapshotAllowsSplit(array $configuredAccounts, string $key): bool
    {
        if (!in_array($key, self::SNAPSHOT_GATED_ACCOUNTS, true)) {
            return true;
        }
        $value = $configuredAccounts[$key] ?? null;

        return is_string($value) && trim($value) !== '';
    }

    /** @return array<string,string> */
    public static function codes(): array
    {
        return array_map(
            static fn (array $definition): string => $definition['code'],
            self::ACCOUNTS,
        );
    }

    /**
     * @param array<string,mixed>|null $configuredAccounts
     * @return array{
     *   gross_debit:string,
     *   gross_credit:string,
     *   employer_insurance_debit:string,
     *   employer_insurance_credit:string
     * }
     */
    public static function forRelation(
        string $relationType,
        ?array $configuredAccounts = null,
    ): array
    {
        $accounts = $configuredAccounts ?? self::codes();
        $keys = self::relationAccountKeys($relationType);
        return [
            'gross_debit' => self::account($accounts, $keys['gross_debit']),
            'gross_credit' => self::account($accounts, $keys['gross_credit']),
            'employer_insurance_debit' => self::account(
                $accounts,
                $keys['employer_insurance_debit'],
            ),
            'employer_insurance_credit' => self::account(
                $accounts,
                $keys['employer_insurance_credit'],
            ),
        ];
    }

    /**
     * @return array{
     *   gross_debit:string,
     *   gross_credit:string,
     *   employer_insurance_debit:string,
     *   employer_insurance_credit:string
     * }
     */
    public static function relationAccountKeys(string $relationType): array
    {
        [$grossDebit, $grossCredit] = match ($relationType) {
            'employment', 'small_scale_employment', 'dpp', 'dpc' => [
                'employment_gross_debit',
                'employment_gross_credit',
            ],
            'partner_dependent' => [
                'partner_gross_debit',
                'partner_gross_credit',
            ],
            'statutory_body' => [
                'statutory_gross_debit',
                'statutory_gross_credit',
            ],
            default => throw new \InvalidArgumentException(
                "Neznámý typ pracovního vztahu: {$relationType}.",
            ),
        };

        return [
            'gross_debit' => $grossDebit,
            'gross_credit' => $grossCredit,
            'employer_insurance_debit' => 'employer_insurance_debit',
            'employer_insurance_credit' => 'social_insurance_credit',
        ];
    }

    /** @param array<string,mixed> $accounts */
    private static function account(array $accounts, string $key): string
    {
        $account = $accounts[$key] ?? null;
        if (!is_string($account) || trim($account) === '') {
            throw new \InvalidArgumentException(
                "Chybí účetní předkontace {$key}.",
            );
        }

        return $account;
    }
}
