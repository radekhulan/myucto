<?php

declare(strict_types=1);

namespace MyInvoice\Service\Vat;

use MyInvoice\Infrastructure\Database\Connection;

/**
 * Napárování sazby z dokladu na řádek `vat_rates` — se zemí, s platností k datu a bez
 * reverse-charge sazeb.
 *
 * Dosavadní párování při importu hledalo NEJBLIŽŠÍ procento napříč celou tabulkou. Tři
 * chyby v jednom dotazu:
 *  - bez filtru na zemi — 23 % se navázalo na kteroukoli 23% sazbu (PL-23 vs. PT-23 je
 *    pak loterie podle pořadí řádků), a polský doklad tak dostal `vat_rate_id` české
 *    sazby se snapshotem 23,00, tedy položku, kterou editor nezobrazí a validace odmítne;
 *  - bez filtru na platnost k datu — sazba zrušená před lety se použila na dnešní doklad;
 *  - bez filtru na `is_reverse_charge` — nulová sazba mohla trefit CZ-RC místo CZ-0, obě
 *    mají `rate_percent = 0.00` a rozlišilo je jen pořadí řádků v tabulce.
 *
 * ── Služba je ČISTĚ ČTECÍ ───────────────────────────────────────────────────────────
 * Sazbu nezakládá, a to ani pro OSS. `vat_rates` je GLOBÁLNÍ tabulka bez `supplier_id`,
 * takže řádek založený z importu jednoho nájemníka mění číselník celé instalaci; navíc
 * `UNIQUE uq_vat_code` koliduje s kódem, který si mezitím založil uživatel ručně —
 * typicky „PL-23" se zemí CZ, protože formulář má CZ předvyplněnou. Automatický zápis
 * tedy buď mlčky rozšiřoval cizí číselník, nebo padal na kolizi, kterou uživatel nemohl
 * pochopit. Nenalezená sazba je proto vždy chyba dokladu s návodem, co založit —
 * a hlavně PRO KTEROU ZEMI.
 *
 * ── `vat_rates` NENÍ důkaz o místě plnění ───────────────────────────────────────────
 * Tahle služba odpovídá na jedinou otázku: „na který řádek `vat_rates` se má položka
 * navázat". NIKDY se nesmí použít jako odpověď na otázku „je tahle sazba tuzemská" —
 * `country` v tabulce vyplňuje uživatel do formuláře, který má CZ předvyplněnou, takže
 * zákazníkovo „PL-23 se zemí CZ" by z polských 23 % udělalo českou sazbu a plnění by
 * skončilo na ř. 1 tuzemského přiznání. Autoritou pro místo plnění je výhradně číselník
 * sazeb členských států ({@see \MyInvoice\Service\Oss\OssRateCodebook}), který je
 * seedovaný migrací a uživatel do něj nesahá. Dřívější pomocník `countryHasRate()` tuhle
 * otázku umožňoval položit, a proto byl zrušen — kdo potřebuje jen „našlo se něco",
 * má `resolve()->found()`, ale ať si přečte tenhle odstavec dřív, než na tom postaví
 * daňové rozhodnutí.
 *
 * ── Proč se prošlá platnost jen VARUJE ──────────────────────────────────────────────
 * Striktní filtr by odmítl podstatnou část migrovaných dokladů: na stock instalaci má
 * CZ-21 `valid_from = 2024-01-01` a pro 21 % v letech 2013–2023 žádný řádek NEEXISTUJE.
 * Krok 2 kaskády proto povolí shodu mimo platnost s varováním. Na výpočty to nemá vliv,
 * protože procento se snapshotuje do `invoice_items.vat_rate_snapshot` a výkazy počítají
 * z něj — stejný argument, jaký už má v komentáři `FakturoidImportService`.
 *
 * ── Nenalezeno = tvrdá chyba, ne fallback ───────────────────────────────────────────
 * Vrátí-li se `id === null`, musí volající odmítnout CELÝ doklad, ne jen položku: doklad
 * s vynechaným řádkem má špatné součty. Žádné `?: 0`, žádné „nejbližší".
 *
 * ── Drift je uzavřený: tahle služba je JEDINÁ cesta k `vat_rate_id` ─────────────────
 * Vlastní „nejbližší procento napříč tabulkou" míval `AiPdfExtractor`,
 * `IdokladImportService` i `FakturoidImportService` — všechny tři bez země i bez
 * `is_reverse_charge`. Po sjednocení už žádný z nich vlastní párování NEMÁ:
 * `FakturoidImportService` a `IdokladImportService` se ptají přímo přes
 * {@see \MyInvoice\Service\Oss\OssItemPlanner}, `AiPdfExtractor` si nechal jen
 * stejnojmenný jednořádkový obal nad `OssItemPlanner::resolveDomesticRate()`.
 * Proto je `$countryIso2` POVINNÝ parametr, ne OSS-specifický přívažek: kdyby byl
 * volitelný, vrátí se dotaz bez země zadními vrátky a s ním i chyba, kvůli které se
 * polských 23 % navázalo na českou sazbu.
 */
final class VatRateResolver
{
    /**
     * Tolerance porovnání procenta (DECIMAL(5,2) vs. float). Shodná s `OssRateCodebook`;
     * ta je private, takže se nedá odkázat — při změně jedné změň i druhou.
     */
    public const EPSILON = 0.005;

    /** @var array<string, VatRateMatch> Klíč "CC|23.00|Y-m-d". */
    private array $cache = [];

    public function __construct(private readonly Connection $db) {}

    /**
     * @param string $countryIso2 země sazby: země dodavatele pro tuzemský řádek,
     *                            `oss_consumer_country` pro OSS řádek
     * @param float  $ratePercent procento z dokladu (23.0, ne 0.23)
     * @param string $onDate      datum plnění 'Y-m-d'
     */
    public function resolve(string $countryIso2, float $ratePercent, string $onDate): VatRateMatch
    {
        $country = strtoupper(trim($countryIso2));
        $key = self::cacheKey($country, $ratePercent, $onDate);

        return $this->cache[$key] ??= $this->lookup($country, $ratePercent, $onDate);
    }

    /**
     * Dávkový pomocník pro backfill — jeden průchod nad seznamem dvojic, ať se stejná
     * kombinace nehledá tisíckrát.
     *
     * @param  list<array{country:string, rate:float, on_date:string}> $requests
     * @return array<string, VatRateMatch> klíč "CC|23.00|YYYY-MM-DD"
     */
    public function resolveBatch(array $requests): array
    {
        $out = [];
        foreach ($requests as $request) {
            $country = strtoupper(trim((string) $request['country']));
            $rate = (float) $request['rate'];
            $onDate = (string) $request['on_date'];
            $key = self::cacheKey($country, $rate, $onDate);
            if (!isset($out[$key])) {
                $out[$key] = $this->resolve($country, $rate, $onDate);
            }
        }

        return $out;
    }

    /** Kaskáda kroků 1–3. */
    private function lookup(string $country, float $ratePercent, string $onDate): VatRateMatch
    {
        // Krok 1 — sazba platná k datu plnění.
        $row = $this->fetchOne(
            'SELECT id, code, rate_percent, valid_from, valid_to
               FROM vat_rates
              WHERE country = ?
                AND is_reverse_charge = 0
                AND ABS(rate_percent - ?) <= ' . self::EPSILON . '
                AND valid_from <= ?
                AND (valid_to IS NULL OR valid_to >= ?)
           ORDER BY is_default DESC, valid_from DESC, display_order ASC, id ASC
              LIMIT 1',
            [$country, $ratePercent, $onDate, $onDate],
        );
        if ($row !== null) {
            return new VatRateMatch(
                (int) $row['id'],
                (string) $row['code'],
                (float) $row['rate_percent'],
                $country,
                VatRateResolution::Matched,
                '',
            );
        }

        // Krok 2 — táž země a totéž procento, ale řádek k datu neplatí.
        $row = $this->fetchOne(
            'SELECT id, code, rate_percent, valid_from, valid_to
               FROM vat_rates
              WHERE country = ?
                AND is_reverse_charge = 0
                AND ABS(rate_percent - ?) <= ' . self::EPSILON . '
           ORDER BY valid_to IS NULL DESC, valid_from DESC, id ASC
              LIMIT 1',
            [$country, $ratePercent],
        );
        if ($row !== null) {
            return new VatRateMatch(
                (int) $row['id'],
                (string) $row['code'],
                (float) $row['rate_percent'],
                $country,
                VatRateResolution::MatchedOutsideValidity,
                sprintf(
                    'Sazba %s (%s %%) není v číselníku vedená jako platná k %s (platnost %s–%s). '
                        . 'Použita i tak — procento se snapshotuje do vat_rate_snapshot, '
                        . 'výkazy počítají z něj.',
                    (string) $row['code'],
                    self::fmtPct((float) $row['rate_percent']),
                    self::fmtDate($onDate),
                    self::fmtDate((string) $row['valid_from']),
                    $row['valid_to'] === null ? 'dosud' : self::fmtDate((string) $row['valid_to']),
                ),
            );
        }

        // Krok 3 — nic. Shoda přes jinou zemi ani „nejbližší" procento se NEHLEDÁ.
        return new VatRateMatch(
            null,
            null,
            null,
            $country,
            VatRateResolution::NoRateInCountry,
            $this->missingRateMessage($country, $ratePercent),
        );
    }

    /**
     * Návod k chybějící sazbě.
     *
     * Zemi musí hláška pojmenovat, jinak radí špatně: formulář v Nastavení → Sazby DPH má
     * zemi předvyplněnou na CZ, takže „založte sazbu 23 % (kód PL-23)" uživatele spolehlivě
     * dovede k řádku se zemí CZ — a ten se pak na polský doklad nenaváže. Druhá věta
     * pokrývá případ, kdy do téhle pasti uživatel už jednou šlápl: `uq_vat_code` je na
     * samotném kódu, takže druhý pokus se správnou zemí skončí na kolizi a jediné řešení
     * je opravit zemi u existujícího řádku.
     *
     * Hláška se ptá na TUTÉŽ zemi, se kterou byl resolver zavolán — pro tuzemský řádek na
     * zemi dodavatele, pro OSS řádek na stát spotřeby. Nesmí proto tvrdit nic o tom, kam
     * se plnění vykáže: o tom rozhoduje `oss_applicable` na položce a číselník sazeb
     * členských států, ne `country` v `vat_rates`. Dřívější znění tvrdilo opak („se zemí CZ
     * by se plnění vykázalo v tuzemském přiznání") a při párování proti tuzemsku si
     * protiřečilo — zemi CZ ve stejné větě doporučovalo i před ní varovalo. Místo toho
     * hláška nabízí ověření, které dává smysl v obou rolích: sazba, která v dané zemi
     * neplatí, bývá plnění do jiného členského státu.
     */
    private function missingRateMessage(string $country, float $ratePercent): string
    {
        $country = $country === '' ? '?' : $country;
        $code = self::rateCode($country, $ratePercent);
        $existing = $this->fetchOne(
            'SELECT country FROM vat_rates WHERE code = ? LIMIT 1',
            [$code],
        );

        if ($existing !== null) {
            return sprintf(
                'Sazba %s %% pro %s v číselníku sazeb DPH neexistuje. Kód %s v něm sice je, ale se zemí %s — '
                    . 'opravte u něj zemi na %s v Nastavení → Sazby DPH a import opakujte.',
                self::fmtPct($ratePercent),
                $country,
                $code,
                strtoupper((string) $existing['country']),
                $country,
            );
        }

        // Formulář má zemi předvyplněnou na CZ, takže varování před ponecháním výchozí
        // hodnoty patří jen tam, kde se zakládá sazba pro JINOU zemi.
        $prefillWarning = $country === 'CZ'
            ? ''
            : ' Formulář má zemi předvyplněnou na CZ — nechte-li ji tak, doklad sazbu opět nenajde a import se odmítne znovu.';

        return sprintf(
            'Sazba %s %% pro %s v číselníku sazeb DPH neexistuje. Ověřte nejdřív, že plnění opravdu patří '
                . 'do %s — procento, které v téhle zemi neplatí, bývá plnění do jiného členského státu '
                . '(režim OSS na položce). Pokud tam patří, založte sazbu v Nastavení → Sazby DPH jako sazbu '
                . 'pro zemi %s (kód %s) a import opakujte.%s',
            self::fmtPct($ratePercent),
            $country,
            $country,
            $country,
            $code,
            $prefillWarning,
        );
    }

    /**
     * @param  list<mixed> $params
     * @return ?array<string,mixed>
     */
    private function fetchOne(string $sql, array $params): ?array
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    private static function cacheKey(string $country, float $ratePercent, string $onDate): string
    {
        return $country . '|' . number_format($ratePercent, 2, '.', '') . '|' . $onDate;
    }

    /** Kód sazby, např. 'PL-23' nebo 'SI-9.5' — bez mezer a desetinné čárky (VARCHAR(20)). */
    private static function rateCode(string $country, float $percent): string
    {
        return $country . '-' . self::rateKey($percent);
    }

    public static function rateKey(float $percent): string
    {
        return rtrim(rtrim(number_format($percent, 2, '.', ''), '0'), '.');
    }

    private static function fmtPct(float $percent): string
    {
        return rtrim(rtrim(number_format($percent, 2, ',', ' '), '0'), ',');
    }

    private static function fmtDate(string $date): string
    {
        try {
            return (new \DateTimeImmutable($date))->format('j. n. Y');
        } catch (\Exception) {
            return $date;
        }
    }
}
