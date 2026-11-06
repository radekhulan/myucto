<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Payroll;

use MyInvoice\Service\Payroll\IncomeTax\SeparateTaxBaseRegime;

/**
 * Srážková daň ze samostatného základu — § 6 odst. 4, § 7 odst. 6 a § 36 ZDP.
 *
 * Mzdový modul znal jen zálohovou daň ze závislé činnosti, takže firma se zaměstnanci
 * na dohodu o provedení práce musela mzdy počítat mimo systém. Audit to vedl mezi
 * vysokými riziky.
 *
 * ── Proč samostatná třída, a ne větev v PayrollCalculator ───────────────────────────
 * Srážková daň není varianta zálohové daně, ale SAMOSTATNÝ ZÁKLAD DANĚ. Neuplatňují se
 * na ni slevy (§ 35ba), nevstupuje do ročního zúčtování ani do přiznání a nesnižuje se
 * o pojistné. Vměstnat ji do {@see PayrollCalculator} jako příznak by znamenalo protáhnout
 * celým výpočtem větve, které se navzájem vylučují — a hlavně by hrozilo, že se na
 * srážkový základ omylem uplatní sleva, což zákon nedovoluje.
 *
 * ── Kdy se použije ──────────────────────────────────────────────────────────────────
 *   § 6/4 a) DPP od JEDNOHO zaměstnavatele BEZ podepsaného prohlášení k dani, jejíž
 *          úhrn za měsíc NEDOSÁHNE rozhodné částky pro účast na nemocenském
 *          pojištění (§ 7a z. č. 187/2006 Sb.; 2025 11 500 Kč, 2026 12 000 Kč).
 *          Tatáž rozhodná částka řídí i odvody SP+ZP z DPP — pod ní se neodvádí.
 *          Písmeno b) (ostatní příjmy pod rozhodnou částkou, jakýkoli vztah) tahle
 *          třída nepočítá, jen na něj upozorní — viz {@see self::letterBNotice()}.
 *   § 7/6  autorský honorář do 10 000 Kč měsíčně od jednoho plátce.
 *   § 36   příjmy nerezidentů (sazba se řídí smlouvou o zamezení dvojího zdanění;
 *          tu systém nezná, proto se bere zákonná sazba a hlásí se to).
 *
 * Překročení limitu NENÍ „o kolik víc" — daní se běžným režimem CELÁ částka, ne jen
 * část nad limitem. Proto {@see applies()} vrací bool, ne poměr.
 */
final class WithholdingTaxCalculator
{
    /** Důvody srážky — určují limit i to, co se hlásí. */
    public const REASON_DPP = 'dpp';
    public const REASON_AUTHOR_FEE = 'author_fee';
    public const REASON_NON_RESIDENT = 'non_resident';

    /**
     * Důvod srážky, který tahle zjednodušená rekapitulace umí spočítat, nebo `null`.
     *
     * Počítá jen písmeno a) § 6 odst. 4 ZDP (DPP). Písmeno b) — příjmy v úhrnné
     * výši nedosahující u téhož plátce za měsíc rozhodné částky — dopadá na
     * JAKÝKOLI druh vztahu, tedy i na pracovní poměr, DPČ a odměnu jednatele.
     * Tahle cesta ho ale nepočítá: srážka tam jde vedle pojistného, jehož
     * povinnost se řídí sjednanou částkou, a tu rekapitulace z jedné hrubé částky
     * za měsíc nezná. Místo tiše špatné zálohy proto na takový měsíc upozorní
     * {@see self::letterBNotice()}; výpočet dělá modul Mzdy.
     */
    public static function reasonForEmploymentType(string $employmentType): ?string
    {
        return $employmentType === 'dpp' ? self::REASON_DPP : null;
    }

    /**
     * Upozornění, že měsíc spadá pod § 6 odst. 4 písm. b) ZDP a zjednodušená
     * rekapitulace jeho srážkovou daň nespočítá; `null`, když se ho to netýká.
     *
     * Týká se jen znění § 6 odst. 4 od 1. 1. 2025 („nedosahující“, hranice ostrá),
     * které je ověřené. Ročníky se starším zněním nesou v konstantách
     * `dpp_withholding_limit_inclusive = true` a zůstávají beze změny.
     *
     * @param array<string,mixed> $c roční daňové konstanty
     */
    public static function letterBNotice(
        string $employmentType,
        float $amount,
        array $c,
        bool $taxDeclarationSigned,
    ): ?string {
        if ($employmentType === 'dpp' || $taxDeclarationSigned || $amount <= 0) {
            return null;
        }
        if (($c['dpp_withholding_limit_inclusive'] ?? true) !== false) {
            return null;
        }
        $threshold = (float) ($c['sickness_participation_threshold'] ?? 0);
        if ($threshold <= 0 || $amount >= $threshold) {
            return null;
        }

        return sprintf(
            'Hrubý příjem %s Kč nedosahuje rozhodné částky %s Kč a zaměstnanec nepodepsal '
                . 'prohlášení k dani. Podle § 6 odst. 4 písm. b) ZDP se z něj sráží srážková '
                . 'daň 15 %% (samostatný základ), ne záloha — týká se každého druhu vztahu. '
                . 'Zjednodušená rekapitulace tuhle srážku nepočítá; spočítejte měsíc v modulu Mzdy.',
            number_format($amount, 2, ',', ' '),
            number_format($threshold, 0, ',', ' '),
        );
    }

    /**
     * Posoudí, jestli se na odměnu srážková daň vůbec vztahuje.
     *
     * @param array<string,mixed> $c roční daňové konstanty
     */
    public static function applies(
        string $reason,
        float $amount,
        array $c,
        bool $taxDeclarationSigned = false,
    ): bool {
        if ($amount <= 0) {
            return false;
        }
        // Od roku 2027 je DPP pod rozhodnou částkou samostatným základem ZÁLOHY
        // (§ 6 odst. 4 a 18 ZDP ve znění zák. č. 360/2025 a 180/2026 Sb.):
        // zaokrouhluje se nahoru, odvádí se se zálohami a vstupuje do ročního
        // zúčtování. Tahle zjednodušená rekapitulace umí jen srážkovou daň,
        // takže se rok 2027 nesmí tiše spočítat po staru ani běžnou zálohou.
        if ($reason === self::REASON_DPP
            && SeparateTaxBaseRegime::forTaxYear((int) ($c['year'] ?? 0))->isAdvance()
            && !$taxDeclarationSigned
            && self::withinLimit($reason, $amount, $c)
        ) {
            throw new \DomainException(
                'Od roku 2027 se z DPP pod rozhodnou částkou nesráží srážková daň, ale záloha'
                . ' ze samostatného základu (§ 6 odst. 4 ZDP). Spočítejte měsíc v modulu Mzdy.',
            );
        }

        return match ($reason) {
            // Podepsané prohlášení srážku vylučuje — příjem jde do zálohové daně, i když
            // je pod limitem. Bez téhle podmínky by se zaměstnanci s prohlášením upřely
            // slevy na dani.
            self::REASON_DPP => !$taxDeclarationSigned && self::withinLimit($reason, $amount, $c),
            self::REASON_AUTHOR_FEE => self::withinLimit($reason, $amount, $c),
            // U nerezidenta limit není — rozhoduje daňová rezidence, ne výše příjmu.
            self::REASON_NON_RESIDENT => true,
            default => throw new \InvalidArgumentException('Neznámý důvod srážky: ' . $reason),
        };
    }

    /**
     * Vejde se odměna do hranice pro daný důvod srážky?
     *
     * ── Proč to není prosté `<=` ────────────────────────────────────────────────
     * U DPP na tom, jestli je hranice „do" nebo „pod", visí jiná daň. § 6 odst. 4
     * písm. a) ZDP zněl do 31. 12. 2024 „NEPŘESÁHNE … 10 000 Kč" (hranice včetně)
     * a od 1. 1. 2025 zní — po novele zák. č. 470/2024 Sb. — „NEDOSÁHNE částky
     * rozhodné pro účast … na nemocenském pojištění" (hranice ostrá). Odměna
     * PŘESNĚ na rozhodné částce už tedy srážkou nejde: podle § 7a z. č. 187/2006 Sb.
     * jí zakládá účast na nemocenském pojištění („aspoň ve výši rozhodné částky"),
     * a proto se daní zálohou a odvádí se z ní SP i ZP. Obě hranice na sebe
     * navazují bez díry i bez překryvu — což je celý smysl té novely.
     *
     * Rok se sem nepašuje: rozdíl nese příznak v ročních konstantách, aby přepočet
     * historického měsíce nepřeklopil starý režim do nového.
     *
     * § 7 odst. 6 ZDP (autorský honorář) novelou dotčený nebyl a zní dál
     * „nepřesáhne … 10 000 Kč" — hranice tam zůstává včetně.
     *
     * @param array<string,mixed> $c roční daňové konstanty
     */
    private static function withinLimit(string $reason, float $amount, array $c): bool
    {
        $key = self::limitKeyFor($reason);
        if ($key === null) {
            return true;
        }
        $limit = self::limit($c, $key);
        if ($reason !== self::REASON_DPP) {
            return $amount <= $limit;
        }

        // Chybějící příznak = novější, přísnější výklad. Fail-safe: raději zálohová
        // daň (kterou lze v ročním zúčtování narovnat) než neoprávněná srážka
        // (kterou už poplatník nedožene).
        return ($c['dpp_withholding_limit_inclusive'] ?? false) === true
            ? $amount <= $limit
            : $amount < $limit;
    }

    /** Klíč roční konstanty s hranicí, nebo `null` pro důvod bez hranice. */
    private static function limitKeyFor(string $reason): ?string
    {
        return match ($reason) {
            self::REASON_DPP => 'dpp_withholding_limit',
            self::REASON_AUTHOR_FEE => 'author_fee_withholding_limit',
            default => null,
        };
    }

    /**
     * Výpočet srážky. Základ se podle § 36 odst. 3 zaokrouhluje na celé koruny dolů,
     * daň na celé koruny dolů.
     *
     * @param array<string,mixed> $c roční daňové konstanty
     * @return array{
     *   reason:string, gross:int, base:int, rate:float, tax:int, net:int,
     *   insurance_applies:bool, warnings:list<string>
     * }
     */
    public static function compute(string $reason, float $amount, array $c, bool $taxDeclarationSigned = false): array
    {
        if (!self::applies($reason, $amount, $c, $taxDeclarationSigned)) {
            throw new \InvalidArgumentException(
                'Na tuto odměnu se srážková daň nevztahuje — použij zálohovou daň (PayrollCalculator).'
            );
        }

        $rate = (float) ($c['withholding_rate'] ?? 0.15);
        if ($rate <= 0) {
            throw new \InvalidArgumentException('Roční konstanty neobsahují sazbu srážkové daně.');
        }

        $base = (int) floor($amount);
        $tax = (int) floor($base * $rate);
        $warnings = [];

        if ($reason === self::REASON_NON_RESIDENT) {
            $warnings[] = 'Sazba u nerezidenta se může řídit smlouvou o zamezení dvojího zdanění '
                . '(často 5 nebo 10 % místo zákonných ' . rtrim(rtrim(number_format($rate * 100, 2, ',', ' '), '0'), ',')
                . ' %). Systém smlouvy nezná — ověřte sazbu podle státu rezidence příjemce.';
        }
        if ($reason === self::REASON_DPP) {
            // § 6 odst. 4 písm. a) mluví o ÚHRNNÉ výši u téhož plátce za kalendářní měsíc.
            // Systém drží jeden mzdový záznam na zaměstnance a měsíc, takže úhrn musí
            // zadat uživatel — dvě odměny po 8 000 Kč jsou 16 000 Kč a srážková daň se
            // neuplatní, ačkoli ani jedna sama limit nepřekročí. Původní znění varovalo
            // jen na souběh u JINÝCH zaměstnavatelů, tedy na jedinou mezeru, kterou
            // systém zavřít nemůže, a mlčelo o té, kterou zavřít lze.
            $warnings[] = 'Rozhodná částka ' . number_format(self::limit($c, 'dpp_withholding_limit'), 0, ',', ' ')
                . ' Kč platí na ÚHRN odměn od jednoho zaměstnavatele za kalendářní měsíc '
                . '(§ 6 odst. 4 ZDP). Vyplácíte-li v témž měsíci víc dohod, zadejte jejich '
                . 'součet — systém drží jeden mzdový záznam na měsíc. Souběh u JINÉHO '
                . 'zaměstnavatele posuďte sami, ten systém nevidí.';
        }

        return [
            'reason' => $reason,
            'gross'  => (int) round($amount),
            'base'   => $base,
            'rate'   => $rate,
            'tax'    => $tax,
            'net'    => $base - $tax,
            // Do limitu se z DPP neodvádí pojistné (od 1. 1. 2024); u honoráře
            // a nerezidenta se pojistné z titulu srážky neodvádí vůbec.
            'insurance_applies' => false,
            'warnings' => $warnings,
        ];
    }

    /**
     * Odměna, která limit PŘEKROČILA, se celá daní běžným režimem — ne jen část nad
     * limitem. Tahle metoda existuje kvůli srozumitelnému hlášení, ne kvůli výpočtu.
     *
     * @param array<string,mixed> $c
     */
    public static function overLimitReason(string $reason, float $amount, array $c): ?string
    {
        $key = self::limitKeyFor($reason);
        if ($key === null || self::withinLimit($reason, $amount, $c)) {
            return null;
        }
        $limit = self::limit($c, $key);

        // Odměna PŘESNĚ na rozhodné částce je taky „mimo srážku", ale slovo
        // „přesahuje" by na ni sedělo špatně a účetní by hlášku považoval za chybu
        // systému. Rozlišujeme proto obě strany hranice. DPP se od 1. 1. 2025
        // poměřuje ROZHODNOU ČÁSTKOU (§ 6 odst. 4 písm. a) ZDP), autorský honorář
        // dál pevným limitem (§ 7 odst. 6) — hláška má mluvit jazykem zákona,
        // ať si ji účetní dohledá.
        $isDpp = $reason === self::REASON_DPP;
        $formatted = number_format($limit, 0, ',', ' ');
        $relation = $amount > $limit
            ? sprintf('přesahuje %s %s Kč', $isDpp ? 'rozhodnou částku' : 'limit', $formatted)
            : sprintf('dosahuje %s %s Kč', $isDpp ? 'rozhodné částky' : 'limitu', $formatted);

        return sprintf(
            'Odměna %s Kč %s — srážkovou daní se nedaní ani část pod ní, zdaňuje se celá '
                . 'částka běžným režimem (zálohová daň, odvody SP a ZP).',
            number_format($amount, 2, ',', ' '),
            $relation,
        );
    }

    /** @param array<string,mixed> $c */
    private static function limit(array $c, string $key): float
    {
        $v = $c[$key] ?? null;
        if ($v === null) {
            throw new \InvalidArgumentException('Roční konstanty neobsahují limit `' . $key . '`.');
        }

        return (float) $v;
    }
}
