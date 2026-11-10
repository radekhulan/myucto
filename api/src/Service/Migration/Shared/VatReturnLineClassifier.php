<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Shared;

/**
 * Řádek přiznání k DPH (DPHDP3) → zařazení dokladu v MyÚčtu. Jediná tabulka pravidel
 * pro převody z cizích účetních programů.
 *
 * Cizí programy nesou daňový význam členění různě (Money kódem `19Ř40,41K`, Pohoda
 * řádky v číselníku členění, PREMIER sloupci řádků v `KODY_DPH`, Stereo NX řádky
 * v `Lsdph`), ale vždy se to dá převést na **řádky přiznání**. Adaptér zdroje přečte
 * řádky a zdrojové příznaky (krácení, předmět plnění) a zeptá se tady; co řádek
 * přiznání znamená, rozhoduje jen tahle třída.
 *
 *   uskutečněná plnění   ř. 1 / 2 tuzemsko (kód podle sazby), + ř. 51 prodej majetku mimo
 *                        koeficient, 20 dodání zboží do EU, 21 služba do EU, 22 vývoz,
 *                        23 nový dopravní prostředek, 24 zasílání zboží, 25 tuzemský přenos
 *                        (dodavatel, § 92a), 26 plnění s místem mimo tuzemsko (u tuzemského
 *                        odběratele § 71i, {@see saleCodeForCustomer()}), 31 třístranný
 *                        obchod, 50 osvobozené bez nároku, 50 + 51 osvobozené mimo koeficient
 *   přijatá plnění       ř. 40 / 41 tuzemský odpočet, + ř. 47 pořízení majetku;
 *                        samovyměření = výstupní řádek + odpočet ř. 43 / 44: 3/4 zboží z EU,
 *                        5/6 služba z EU, 7/8 dovoz zboží, 10/11 tuzemský přenos (příjemce),
 *                        12/13 ostatní plnění ze zahraničí; bez ř. 43/44 = bez nároku
 *   bez řádku            mimo přiznání
 *
 * Tuzemský přenos (ř. 25 u dodavatele, ř. 10/11 u příjemce) se v KH dělí podle kódu
 * předmětu plnění (4 stavební práce § 92e, 5 odpad a šrot § 92c, 3 nemovitá věc § 92d).
 * Řádek přiznání ho nenese; zdroj, který ho zná, ho předá, jinak platí výchozí 4.
 *
 * Řádky, které tu nejsou (dovoz celním úřadem ř. 42, opravy § 44 a § 74, vypořádání
 * koeficientu, korekce odpočtu…), převod nezařazuje a doklad nechá k ruční kontrole.
 */
final class VatReturnLineClassifier
{
    public const RATE_BASE = 'base';
    public const RATE_REDUCED = 'reduced';

    /** Tuzemské uskutečněné plnění v základní a snížené sazbě. */
    public const DOMESTIC_OUTPUT = [1, 2];

    /** Uskutečněné plnění vyloučené z koeficientu § 76 odst. 4 (prodej majetku, příležitostné osvobozené). */
    public const OUTSIDE_COEFFICIENT = 51;

    public const EXEMPT = 50;

    /** Tuzemský odpočet v základní a snížené sazbě. */
    public const DOMESTIC_DEDUCTION = [40, 41];

    /** Odpočet ze samovyměření v základní a snížené sazbě. */
    public const SELF_ASSESSMENT_DEDUCTION = [43, 44];

    /** Odpočet u pořízení dlouhodobého majetku (§ 78 a násl.). */
    public const FIXED_ASSET = 47;

    /** Výstupní dvojice samovyměření (základní, snížená sazba). */
    public const SELF_ASSESSMENT_PAIRS = [[3, 4], [5, 6], [7, 8], [10, 11], [12, 13]];

    /** Osvobozené plnění mimo koeficient (ř. 50 + 51). */
    public const EXEMPT_OUTSIDE_COEFFICIENT_CODE = '3m';

    /**
     * Položka přijatého dokladu mimo předmět DPH (bez řádku přiznání a oddílu KH, sazba 0 %).
     * Na dokladu se samovyměřením ji musí nést každá položka, která součástí přenesené
     * povinnosti není (kurzový rozdíl proti základu samovyměření, plnění mimo přiznání):
     * položku BEZ kódu evidence DPH u dokladu s příznakem `reverse_charge` zařadí jako
     * samovyměření podle země dodavatele a dopočte z ní daň.
     */
    public const PURCHASE_OUTSIDE_SCOPE_CODE = 'mimo';

    /** Výchozí kód předmětu plnění tuzemského přenosu: stavební a montážní práce (§ 92e). */
    public const DEFAULT_REVERSE_SUBJECT = '4';

    /** Uskutečněná plnění mimo tuzemský ř. 1/2: řádek → kód zařazení. */
    private const SALE_CODES = [20 => '20', 21 => '22', 22 => '26', 23 => '23n', 24 => '24z', 25 => '25s', 26 => self::OTHER_FOREIGN_SALE_CODE, 31 => '31', 50 => '3'];

    /** Ř. 26 u zahraničního odběratele: služba s místem plnění mimo tuzemsko (§ 9 odst. 1). */
    public const OTHER_FOREIGN_SALE_CODE = '26s';

    /** Ř. 26 u tuzemského odběratele: osvobozené dodání s nárokem na odpočet podle § 71i. */
    public const DOMESTIC_EXEMPT_WITH_CREDIT_CODE = '71i';

    private const DOMESTIC_REVERSE_SALE_LINE = 25;

    /** Tuzemský přenos u dodavatele (ř. 25): kód předmětu plnění → kód zařazení. */
    private const DOMESTIC_REVERSE_SALE = ['4' => '25s', '5' => '25s5', '3' => '25s3'];

    /** Výstupní řádky samovyměření → kód zařazení přijatého dokladu. */
    private const SELF_ASSESSMENT = [3 => '23', 4 => '23', 5 => '24e', 6 => '24e', 7 => '25', 8 => '25', 10 => '5', 11 => '5', 12 => '24', 13 => '24'];

    private const DOMESTIC_REVERSE_PURCHASE_CODE = '5';

    /** Tuzemský přenos u příjemce (ř. 10/11): kód předmětu plnění → kód zařazení. */
    private const DOMESTIC_REVERSE_PURCHASE = ['4' => '5', '5' => '5c', '3' => '5d'];

    /** Řádky přiznání v základní a ve snížené sazbě (výstup, samovyměření, odpočet). */
    private const BASE_LINES = [1, 3, 5, 7, 10, 12, 40, 43];
    private const REDUCED_LINES = [2, 4, 6, 8, 11, 13, 41, 44];

    /**
     * Kód zařazení uskutečněného plnění z jednoho řádku přiznání (bez tuzemských ř. 1, 2).
     * `$subject` = kód předmětu plnění u ř. 25; null / '' = výchozí 4.
     */
    public static function saleCode(int $line, ?string $subject = null): ?string
    {
        if ($line === self::DOMESTIC_REVERSE_SALE_LINE) {
            return self::DOMESTIC_REVERSE_SALE[self::subject($subject)] ?? null;
        }
        return self::SALE_CODES[$line] ?? null;
    }

    /**
     * Ř. 26 (pln_ost) nese dvě různá plnění: službu s místem plnění mimo tuzemsko (`26s`)
     * a tuzemské dodání osvobozené s nárokem na odpočet podle § 71i (`71i`). Řádek přiznání
     * je rozliší jen podle odběratele: tuzemský odběratel (CZ, nebo země neznámá) dostane
     * `71i`, zahraniční `26s`. Stejné pravidlo jako výchozí klasifikace nové faktury
     * ({@see \MyInvoice\Repository\InvoiceRepository::defaultSaleClassificationCode()}).
     * Přiznání ani KH se tím nemění, obě klasifikace míří na ř. 26 bez oddílu KH.
     *
     * Jiné kódy vrací beze změny. Volají ho převody vydaných dokladů, jakmile znají zemi
     * odběratele (ISO 3166-1 alpha-2, jak ji má karta klienta).
     */
    public static function saleCodeForCustomer(?string $code, ?string $customerCountry): ?string
    {
        if ($code !== self::OTHER_FOREIGN_SALE_CODE) {
            return $code;
        }
        $country = strtoupper(trim((string) $customerCountry));
        return $country === '' || $country === 'CZ' ? self::DOMESTIC_EXEMPT_WITH_CREDIT_CODE : $code;
    }

    /** Prodej majetku mimo koeficient (ř. 1/2 + 51) podle sazby: jen aktuální 21 % / 12 %. */
    public static function assetSaleCode(float $rate): ?string
    {
        return match (true) {
            abs($rate - 21.0) < 0.01 => '1m',
            abs($rate - 12.0) < 0.01 => '2m',
            default => null,
        };
    }

    /**
     * Vydaný doklad v tuzemském přenesení daňové povinnosti (ř. 25, § 92a): některý z kódů
     * zařazení dokladu nebo položek je kódem ř. 25. Takový doklad nese i příznak
     * `reverse_charge` na hlavičce (text o přenesení na dokladu, účtování bez daně).
     *
     * @param list<?string> $codes
     */
    public static function isDomesticReverseSale(array $codes): bool
    {
        foreach ($codes as $code) {
            if ($code !== null && in_array($code, self::DOMESTIC_REVERSE_SALE, true)) {
                return true;
            }
        }
        return false;
    }

    public static function isSelfAssessmentOutput(int $line): bool
    {
        return isset(self::SELF_ASSESSMENT[$line]);
    }

    /**
     * Kód zařazení přijatého dokladu se samovyměřením podle výstupního řádku.
     * `$subject` = kód předmětu plnění u ř. 10/11; null / '' = výchozí 4.
     */
    public static function selfAssessmentCode(int $outputLine, ?string $subject = null): ?string
    {
        $code = self::SELF_ASSESSMENT[$outputLine] ?? null;
        if ($code === self::DOMESTIC_REVERSE_PURCHASE_CODE) {
            return self::DOMESTIC_REVERSE_PURCHASE[self::subject($subject)] ?? null;
        }
        return $code;
    }

    /**
     * Totéž pro zdroj, který řádky vede jako celou dvojici (`3,4`, `10,11`): jiná sada
     * řádků kódem samovyměření není.
     *
     * @param list<int>|null $lines
     */
    public static function selfAssessmentCodeForPair(?array $lines, ?string $subject = null): ?string
    {
        return $lines !== null && in_array($lines, self::SELF_ASSESSMENT_PAIRS, true)
            ? self::selfAssessmentCode($lines[0], $subject)
            : null;
    }

    /**
     * Třída sazby podle řádků přiznání (u samovyměření ř. 43 = základní, 44 = snížená).
     *
     * @param list<int> $lines
     */
    public static function rateClass(array $lines): ?string
    {
        foreach ($lines as $line) {
            if (in_array($line, self::BASE_LINES, true)) {
                return self::RATE_BASE;
            }
            if (in_array($line, self::REDUCED_LINES, true)) {
                return self::RATE_REDUCED;
            }
        }
        return null;
    }

    /**
     * Tuzemská sazba třídy k datu plnění (%). Pro doklady, u kterých ji nejde spočítat
     * z daně (samovyměření, nulová daň) ani ji nenese položka.
     */
    public static function domesticRate(string $class, string $date): float
    {
        if ($class === self::RATE_BASE) {
            return match (true) {
                $date >= '2013-01-01' => 21.0,
                $date >= '2010-01-01' => 20.0,
                default => 19.0,
            };
        }
        return match (true) {
            $date >= '2024-01-01' => 12.0,
            $date >= '2013-01-01' => 15.0,
            $date >= '2012-01-01' => 14.0,
            $date >= '2010-01-01' => 10.0,
            $date >= '2008-01-01' => 9.0,
            default => 5.0,
        };
    }

    /**
     * Vydaný doklad, řádky jako množina (PREMIER, Stereo NX): tuzemsko je libovolná
     * podmnožina ř. 1, 2 (+ 51), jinak jediný řádek nebo 50 + 51.
     *
     * @param list<int> $lines seřazené, bez duplicit
     * @return array{in_return:bool,code:?string,domestic:bool,asset_sale:bool}|null null = převod nezařadí;
     *         `code` null u tuzemského plnění = kód podle sazby (1 / 2)
     */
    public static function saleFromLineSet(array $lines): ?array
    {
        if ($lines === []) {
            return ['in_return' => false, 'code' => null, 'domestic' => false, 'asset_sale' => false];
        }
        $domestic = array_values(array_intersect($lines, self::DOMESTIC_OUTPUT));
        $rest = array_values(array_diff($lines, [...self::DOMESTIC_OUTPUT, self::OUTSIDE_COEFFICIENT]));
        if ($domestic !== [] && $rest === []) {
            return ['in_return' => true, 'code' => null, 'domestic' => true, 'asset_sale' => in_array(self::OUTSIDE_COEFFICIENT, $lines, true)];
        }
        if ($lines === [self::EXEMPT, self::OUTSIDE_COEFFICIENT]) {
            return ['in_return' => true, 'code' => self::EXEMPT_OUTSIDE_COEFFICIENT_CODE, 'domestic' => false, 'asset_sale' => false];
        }
        if (count($lines) === 1 && isset(self::SALE_CODES[$lines[0]])) {
            return ['in_return' => true, 'code' => self::saleCode($lines[0]), 'domestic' => false, 'asset_sale' => false];
        }
        return null;
    }

    /**
     * Přijatý doklad, řádky jako množina (PREMIER, Stereo NX). Samovyměření = výstupní
     * řádek (+ odpočet ř. 43/44), tuzemský odpočet = podmnožina ř. 40, 41; ř. 47 příznak
     * majetku.
     *
     * @param list<int> $lines seřazené, bez duplicit
     * @return array{in_return:bool,deduction:'full'|'reduced'|'none',reverse:bool,code:?string,fixed_asset:bool}|null
     *         `code` null u tuzemského odpočtu = kód podle sazby (40 / 41)
     */
    public static function purchaseFromLineSet(array $lines, bool $reduced): ?array
    {
        if ($lines === []) {
            return ['in_return' => false, 'deduction' => 'none', 'reverse' => false, 'code' => null, 'fixed_asset' => false];
        }
        $asset = in_array(self::FIXED_ASSET, $lines, true);
        $deduction = $reduced ? 'reduced' : 'full';
        $rest = array_values(array_diff($lines, [self::FIXED_ASSET]));
        $output = array_values(array_filter($rest, static fn (int $l): bool => self::isSelfAssessmentOutput($l)));
        $claim = array_values(array_intersect($rest, self::SELF_ASSESSMENT_DEDUCTION));
        $domestic = array_values(array_intersect($rest, self::DOMESTIC_DEDUCTION));
        $unknown = array_values(array_diff($rest, $output, $claim, $domestic));
        if ($unknown !== []) {
            return null;
        }
        if ($output !== []) {
            $codes = array_values(array_unique(array_map(static fn (int $l): ?string => self::selfAssessmentCode($l), $output)));
            if (count($codes) !== 1 || $domestic !== []) {
                return null;
            }
            return [
                'in_return' => true,
                'deduction' => $claim !== [] ? $deduction : 'none',
                'reverse' => true,
                'code' => $codes[0],
                'fixed_asset' => $asset,
            ];
        }
        if ($claim !== []) {
            // Odpočet ze samovyměření bez výstupního řádku - zdroj ho vede jen u kódu,
            // který daň na výstupu vede jinde. Převod to neodhaduje.
            return null;
        }
        if ($domestic !== []) {
            return ['in_return' => true, 'deduction' => $deduction, 'reverse' => false, 'code' => null, 'fixed_asset' => $asset];
        }
        return null;
    }

    private static function subject(?string $subject): string
    {
        return $subject === null || $subject === '' ? self::DEFAULT_REVERSE_SUBJECT : $subject;
    }
}
