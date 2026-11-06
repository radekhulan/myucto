<?php

declare(strict_types=1);

namespace MyInvoice\Service\Oss;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\TaxConstantsRepository;
use MyInvoice\Service\Currency\CurrencyConversionService;

/**
 * Celounijní práh 10 000 EUR pro přeshraniční B2C plnění — § 8 odst. 3 a § 10i ZDPH.
 *
 * OSS byl v systému čistý ruční přepínač (`supplier.oss_enabled`) bez jakékoli vazby na
 * obrat: práh se nikde nepočítal, nikde nehlídal a manuál to sám přiznával („MyÚčto
 * nehlídá překročení prahu 10 000 EUR"). Uživatel se tak mohl kterýmkoli směrem minout —
 * fakturovat s českou daní i po vzniku povinnosti odvádět daň ve státě spotřeby, nebo
 * naopak zdaňovat v cizině dřív, než mu povinnost vznikla.
 *
 * ── Co se do prahu počítá ───────────────────────────────────────────────────────────
 * VŠECHNA přeshraniční B2C plnění do jiných členských států za kalendářní rok, tedy
 * i ta, která jsou zatím fakturovaná s českou daní. Kdyby se sčítala jen plnění
 * označená `oss_applicable`, sledování by bylo k ničemu: před registrací žádné takové
 * plnění neexistuje, takže by práh nikdy nemohl být překročen.
 *
 * B2C se pozná podle odběratele: klient ze státu EU mimo ČR BEZ DIČ. Plátce s DIČ je
 * B2B — tam se uplatní reverse-charge nebo dodání do JČS a do prahu nepatří.
 *
 * ── Měna a známé zjednodušení ───────────────────────────────────────────────────────
 * Práh je stanoven v EUR, kdežto doklady jsou převážně v Kč. Přepočet se dělá kurzem ČNB
 * ke dni uskutečnění plnění, tedy stejným mechanismem jako zbytek systému. Je to
 * ZJEDNODUŠENÍ: směrnice pracuje s pevným přepočtem národní měny stanoveným k datu
 * přijetí, ne s denním kurzem, takže výsledek se od úředního propočtu může o kus lišit.
 * Proto {@see progress()} vrací UPOZORNĚNÍ, ne závazné určení povinnosti — u hodnot
 * blízko prahu musí rozhodnout účetní. Tvrdit tvrdý verdikt na denním kurzu by bylo
 * horší než nehlídat nic, protože by to budilo falešnou jistotu.
 *
 * ── NENÍ to kurz přepočtu podání ────────────────────────────────────────────────────
 * {@see OssLedgerService} přepočítává částky DO PODÁNÍ kurzem ECB zveřejněným pro poslední
 * den zdaňovacího období (čl. 369h odst. 3 směrnice 2006/112/ES). Tady je řeč o jiném
 * pravidle: práh podle § 8 odst. 3 se přepočítává PEVNÝM kurzem stanoveným k datu přijetí
 * směrnice (EU) 2017/2455, ne kurzem konce čtvrtletí. Obě čísla se proto počítají různě
 * ZÁMĚRNĚ a sjednotit je na jeden kurz by znamenalo zaměnit dvě různá pravidla. Že se
 * tady zatím používá denní kurz ČNB místo toho pevného, je přiznaná nepřesnost výše —
 * ne to, co dělá {@see OssLedgerService}.
 *
 * Nic nemění na dokladech ani nezapíná OSS — jen měří a upozorňuje.
 */
final class OssThresholdService
{
    /** Od kolika procent prahu má smysl uživatele upozorňovat. */
    public const WARN_AT_PCT = 80.0;

    public function __construct(
        private readonly Connection $db,
        private readonly CurrencyConversionService $currency,
        private readonly TaxConstantsRepository $constants,
    ) {}

    /**
     * Stav čerpání prahu za kalendářní rok.
     *
     * @return array{
     *   year:int, threshold_eur:float, total_eur:float, pct:float,
     *   exceeded:bool, exceeded_on:?string, near_threshold:bool,
     *   by_country:list<array{country:string, amount_eur:float}>,
     *   unconverted_rows:int, warnings:list<string>
     * }
     */
    public function progress(int $supplierId, int $year): array
    {
        $threshold = $this->constants->ossThresholdEur($year);
        $rows = $this->b2cRows($supplierId, $year);

        $total = 0.0;
        $exceededOn = null;
        $byCountry = [];
        $unconverted = 0;
        $warnings = [];

        foreach ($rows as $r) {
            $eur = $this->toEur($r);
            if ($eur === null) {
                $unconverted++;
                continue;
            }
            $country = strtoupper((string) $r['country']);
            $byCountry[$country] = ($byCountry[$country] ?? 0.0) + $eur;

            // Datum překročení = den plnění, kterým součet poprvé přesáhl práh. Řádky
            // chodí seřazené podle data, takže stačí sledovat první přechod.
            $total += $eur;
            if ($exceededOn === null && $total > $threshold) {
                $exceededOn = (string) $r['tax_date'];
            }
        }

        $total = round($total, 2);
        $pct = $threshold > 0.0 ? round($total / $threshold * 100, 1) : 0.0;
        $exceeded = $exceededOn !== null;
        $near = !$exceeded && $pct >= self::WARN_AT_PCT;

        if ($exceeded) {
            $warnings[] = sprintf(
                'Přeshraniční B2C plnění do EU dosáhla %s EUR a %s překročila práh %s EUR '
                    . '(§ 8 odst. 3 ZDPH). Od překročení se místo plnění přesouvá do státu spotřeby — '
                    . 'ověřte registraci do OSS. Přepočet je orientační (denní kurz ČNB).',
                number_format($total, 2, ',', ' '),
                (new \DateTimeImmutable((string) $exceededOn))->format('j. n. Y'),
                number_format($threshold, 0, ',', ' '),
            );
        } elseif ($near) {
            $warnings[] = sprintf(
                'Přeshraniční B2C plnění do EU jsou na %s %% prahu %s EUR (§ 8 odst. 3 ZDPH). '
                    . 'Sledujte zbytek roku — po překročení vzniká povinnost odvádět daň ve státě spotřeby.',
                number_format($pct, 1, ',', ' '),
                number_format($threshold, 0, ',', ' '),
            );
        }
        if ($unconverted > 0) {
            // Tiché vynechání by práh podhodnotilo — a právě podhodnocení je ten
            // nebezpečný směr (uživatel by povinnost přehlédl).
            $warnings[] = sprintf(
                'U %d řádků se nepodařilo zjistit kurz do EUR — do součtu nevstoupily, '
                    . 'skutečné čerpání prahu je tedy vyšší.',
                $unconverted,
            );
        }

        arsort($byCountry);
        $countries = [];
        foreach ($byCountry as $code => $amount) {
            $countries[] = ['country' => $code, 'amount_eur' => round($amount, 2)];
        }

        return [
            'year'             => $year,
            'threshold_eur'    => $threshold,
            'total_eur'        => $total,
            'pct'              => $pct,
            'exceeded'         => $exceeded,
            'exceeded_on'      => $exceededOn,
            'near_threshold'   => $near,
            'by_country'       => $countries,
            'unconverted_rows' => $unconverted,
            'warnings'         => $warnings,
        ];
    }

    /**
     * Varování do OSS přehledu: fakturuje se v režimu OSS, ačkoli práh překročen nebyl?
     * Samo o sobě to chybou není (registrace je dobrovolná), ale při zapnutém OSS bez
     * skutečné registrace by šlo o daň odvedenou do nesprávného státu.
     *
     * @return list<string>
     */
    public function registrationSanityWarnings(int $supplierId, int $year, bool $ossEnabled): array
    {
        $p = $this->progress($supplierId, $year);
        $out = $p['warnings'];

        if ($ossEnabled && !$p['exceeded'] && $p['total_eur'] > 0.0) {
            $out[] = sprintf(
                'OSS je zapnutý, ale přeshraniční B2C plnění za %d dosáhla jen %s EUR z prahu %s EUR. '
                    . 'Dobrovolná registrace je možná — ověřte, že skutečně platí, jinak daň míří do nesprávného státu.',
                $year,
                number_format($p['total_eur'], 2, ',', ' '),
                number_format($p['threshold_eur'], 0, ',', ' '),
            );
        }
        if (!$ossEnabled && $p['exceeded']) {
            $out[] = 'Práh je překročen, ale OSS režim není zapnutý — plnění se dál fakturují s českou daní.';
        }

        return $out;
    }

    /**
     * Přeshraniční B2C plnění roku. B2C = odběratel ze státu EU mimo ČR BEZ DIČ;
     * plátce s DIČ je B2B a do prahu nepatří.
     *
     * U řádků už označených jako OSS má přednost `oss_taxable_amount_return`, pokud je
     * podání v EUR — to je hodnota, kterou systém do podání skutečně posílá, takže
     * dvojí přepočet by ji zbytečně rozešel. Pozor, tahle hodnota je RUČNÍ (automatický
     * přepočet kurzem ECB se na položku nezapisuje, počítá ho až náhled podání), takže
     * ročnímu součtu prahu tím vzniká míchání dvou kurzových základů. Vzhledem k tomu,
     * že celý práh je tu jen upozornění s přiznanou nepřesností (viz docblock třídy),
     * je to menší zlo než rozejít se s číslem, které uživatel na dokladu sám zadal.
     *
     * @return list<array<string,mixed>>
     */
    private function b2cRows(int $supplierId, int $year): array
    {
        $hasOss = $this->db->hasColumn('invoice_items', 'oss_applicable');
        $ossSelect = $hasOss
            ? 'ii.oss_applicable, ii.oss_taxable_amount_return, ii.oss_consumer_country'
            : '0 AS oss_applicable, NULL AS oss_taxable_amount_return, NULL AS oss_consumer_country';

        $sql =
            "SELECT i.effective_tax_date AS tax_date,
                    COALESCE(cur.code, 'CZK') AS currency,
                    ii.total_without_vat,
                    COALESCE(NULLIF(UPPER(TRIM(co.iso2)), ''), '??') AS country,
                    {$ossSelect}
               FROM invoice_items ii
               JOIN invoices i ON i.id = ii.invoice_id
               JOIN clients c ON c.id = i.client_id
          LEFT JOIN countries co ON co.id = c.country_id
          LEFT JOIN currencies cur ON cur.id = i.currency_id
              WHERE i.supplier_id = ?
                AND i.status NOT IN ('draft', 'cancelled')
                AND i.invoice_type NOT IN ('proforma', 'penalty','rent_calendar','advance_calendar')
                AND YEAR(i.effective_tax_date) = ?
                AND co.is_eu = 1
                AND UPPER(TRIM(co.iso2)) <> 'CZ'
                -- B2C: odběratel bez DIČ. S DIČ jde o B2B (reverse-charge / dodání do JČS).
                AND (c.dic IS NULL OR TRIM(c.dic) = '')
           ORDER BY i.effective_tax_date, i.id, ii.id";

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$supplierId, $year]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /** @param array<string,mixed> $r */
    private function toEur(array $r): ?float
    {
        // Řádek už přepočtený pro OSS podání v EUR — ber hodnotu, kterou systém posílá.
        if (!empty($r['oss_applicable']) && $r['oss_taxable_amount_return'] !== null) {
            return (float) $r['oss_taxable_amount_return'];
        }

        $amount = (float) $r['total_without_vat'];
        $currency = strtoupper((string) ($r['currency'] ?? 'CZK'));
        if ($currency === 'EUR') {
            return round($amount, 2);
        }
        if (empty($r['tax_date'])) {
            return null;
        }

        $converted = $this->currency->convert(
            $amount,
            $currency,
            'EUR',
            new \DateTimeImmutable((string) $r['tax_date']),
        );

        return $converted === null ? null : (float) $converted['amount'];
    }
}
