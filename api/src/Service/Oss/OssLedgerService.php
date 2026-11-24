<?php

declare(strict_types=1);

namespace MyInvoice\Service\Oss;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Currency\EcbExchangeRateClient;
use MyInvoice\Service\Invoice\CzkRecap;
use MyInvoice\Service\Report\VatLedgerService;
use Psr\Clock\ClockInterface;

/**
 * Podklad pro OSS přiznání (režim EU) za kalendářní čtvrtletí.
 *
 * ── Přepočet do měny podání: kurz ECB pro POSLEDNÍ DEN OBDOBÍ ────────────────────────
 * Do konce roku 2026 se tady přepočítávalo DENNÍM kurzem ČNB k datu plnění každého
 * dokladu — tedy stejným kurzem, jakým se počítá tuzemský základ daně (§ 4 odst. 8 ZDPH).
 * To je pro OSS špatně a dopadá to přímo na částky, které zákazník podá: Finanční správa
 * k režimu EU uvádí, že „pro přepočet u plnění v jiné měně než euro se použije směnný
 * kurz Evropské centrální banky zveřejněný pro poslední den zdaňovacího období, nebo
 * nejbližší následující den, pokud pro poslední den zdaňovacího období není kurz
 * zveřejněn" (shodně čl. 369h odst. 3 směrnice 2006/112/ES). Jsou to tři rozdíly
 * najednou: jiná banka (ECB, ne ČNB), jeden JEDNOTNÝ kurz na celé čtvrtletí a rozhodné
 * datum konce období místo data plnění.
 *
 * Kurz se proto zjišťuje JEDNOU za období ({@see EcbExchangeRateClient::ratesForPeriodEnd()})
 * a použije se na všechny řádky. Přednost si drží ruční hodnoty na položce — ruční částky
 * (`oss_taxable_amount_return`, `oss_vat_amount_return`) i ruční kurz (`oss_exchange_rate`):
 * to jsou vědomá rozhodnutí účetního nad konkrétním dokladem a systém je nepřepisuje.
 *
 * Když kurz ECB k dispozici NENÍ (nedostupný feed, demo režim, období, které ještě
 * neskončilo), řádek zůstane nepřepočtený a náhled hlásí varování. Tichý návrat k ČNB by
 * dal číslo, které vypadá hotově a do podání nepatří; `conversion_missing_count` navíc
 * drží {@see OssXmlExporter} od vytvoření XML, dokud se to nevyřeší.
 *
 * ── Oprava minulého období jde proti kurzu TOHO období ──────────────────────────────
 * Řádek s vyplněným `oss_original_period` je oprava staršího kvartálu (VetaO) a NESMÍ se
 * přepočítat kurzem běžného čtvrtletí. Původní podání za opravované období použilo kurz
 * ECB pro poslední den TAMTOHO kvartálu; kdyby se oprava přepočetla dnešním kurzem,
 * částka v eurech by se od té podané nikdy neodečetla a v podání by natrvalo zůstal
 * kurzový rozdíl, který nikdy neexistoval. Na kurzu 24 Kč/€ udělá vrácených 100 000 Kč
 * 4 166,67 €, na kurzu 25 Kč/€ 4 000,00 € — a 166,67 € se v žádném dalším přiznání
 * nesrovná.
 *
 * Kurz opravovaného období se hledá ve dvou krocích:
 *   1. ARCHIV — write-once evidence § 110f zapsaná k podání opravovaného kvartálu
 *      ({@see OssEvidenceService::ratesForPeriod()}). Nese kurz, kterým se tehdy
 *      SKUTEČNĚ počítalo, včetně případného ručního kurzu účetního.
 *   2. Kurz ECB pro poslední den opravovaného kvartálu — když archiv kurz nenese
 *      (podání z doby před evidencí, nezapsaná evidence). Je to týž kurz, jaký zákon
 *      pro původní podání předepisoval, takže rozdíl vychází taky.
 * Když neuspěje ani jeden, řádek se NEPŘEPOČTE a oprava se počítá jako neplatná —
 * export XML pak stojí. Číslo z běžného kvartálu by vypadalo hotově a bylo by špatně.
 *
 * ── Práh 10 000 EUR je JINÁ otázka ──────────────────────────────────────────────────
 * {@see OssThresholdService} pracuje s prahem podle § 8 odst. 3 ZDPH, který se přepočítává
 * pevným kurzem stanoveným k datu přijetí směrnice (EU) 2017/2455, ne kurzem konce období.
 * Sjednotit obojí na kurz ECB ke konci čtvrtletí by bylo zaměnění dvou různých pravidel.
 */
final class OssLedgerService
{
    public function __construct(
        private readonly Connection $db,
        private readonly EcbExchangeRateClient $ecb,
        private readonly VatLedgerService $vatLedger,
        private readonly OssThresholdService $threshold,
        private readonly OssRateCodebook $rateCodebook,
        private readonly ClockInterface $clock,
        private readonly OssEvidenceService $evidence,
    ) {}

    /** @return array<string,mixed> */
    public function preview(int $supplierId, int $year, int $quarter): array
    {
        $quarter = max(1, min(4, $quarter));
        [$start, $end] = OssPeriod::range($year, $quarter);
        $settings = $this->supplierSettings($supplierId);
        $warnings = [];

        if (!$this->hasOssColumns()) {
            $warnings[] = 'Chybí databázová migrace OSS (0137_oss_foundation.sql). Spusťte php api/bin/migrate.php.';
            return $this->emptyPreview($year, $quarter, $start, $end, $settings, $warnings);
        }

        // Vypnutý OSS sem už nedojde — OssReportAction vrací 409 dřív, než se preview zavolá,
        // a UI stránku bez zapnutého režimu vůbec nezpřístupní. Varování proto odpadlo.

        $returnCurrency = strtoupper((string) ($settings['oss_return_currency'] ?? 'EUR'));
        $rows = $this->vatLedger->ossRows($supplierId, $start, $end, returnInCzk: $returnCurrency === 'CZK');
        $countries = [];
        $corrections = [];
        $invoiceIds = [];
        $totalBase = 0.0;
        $totalVat = 0.0;
        $totalCorrections = 0.0;
        $correctionRowCount = 0;
        $invalidCorrectionCount = 0;
        $conversionMissingCount = 0;
        $manualReviewCount = 0;
        $currentPeriod = sprintf('%04dQ%d', $year, $quarter);

        // Kurz se zjišťuje JEDNOU za období, ne per doklad — viz docblock třídy.
        $conversion = $this->periodConversion($rows, $returnCurrency, $end, null, $supplierId, $currentPeriod, $warnings);

        // …a pro každé opravované období JEDNOU jeho vlastní kurz. Opravy z jednoho
        // kvartálu sdílejí kurz, takže se hledá per období, ne per řádek.
        $correctionConversions = [];
        foreach ($rows as $r) {
            $rowPeriod = self::correctionPeriod($r, $currentPeriod);
            if ($rowPeriod === null || isset($correctionConversions[$rowPeriod])) {
                continue;
            }
            [, $correctedEnd] = OssPeriod::range((int) substr($rowPeriod, 0, 4), (int) substr($rowPeriod, 5, 1));
            $correctionConversions[$rowPeriod] = $this->periodConversion(
                $rows,
                $returnCurrency,
                $correctedEnd,
                $rowPeriod,
                $supplierId,
                $currentPeriod,
                $warnings,
            );
        }
        ksort($correctionConversions);

        foreach ($rows as $r) {
            $invoiceId = (int) $r['invoice_id'];
            $invoiceIds[$invoiceId] = true;
            // Počítá se PŘED odbočkou na opravy: řádek k ručnímu posouzení může být
            // stejně dobře oprava minulého období jako běžné plnění, a v obou případech
            // je to řádek, který si má člověk projít.
            if (!empty($r['oss_needs_manual_review'])) {
                $manualReviewCount++;
            }
            $country = strtoupper((string) ($r['oss_consumer_country'] ?? ''));
            if ($country === '') {
                $country = '??';
                $warnings[] = 'Doklad ' . self::docLabel($r) . ' má OSS řádek bez země spotřeby.';
            }

            $rate = (float) $r['vat_rate_snapshot'];
            $rateKey = number_format($rate, 2, '.', '');
            // Oprava minulého období se přepočítává kurzem TOHO období, ne běžného
            // kvartálu — viz docblock třídy.
            $rowPeriod = self::correctionPeriod($r, $currentPeriod);
            $rowConversion = $rowPeriod === null ? $conversion : $correctionConversions[$rowPeriod];
            $conversionEntry = $rowConversion['cross'][self::rowCurrency($r)] ?? null;
            $periodRate = $conversionEntry['rate'] ?? null;
            $baseReturn = self::returnAmount($r, 'oss_taxable_amount_return', 'total_without_vat', $returnCurrency, $periodRate);
            $vatReturn = self::returnAmount($r, 'oss_vat_amount_return', 'total_vat', $returnCurrency, $periodRate);
            $rowRate = self::rowRate($r, $returnCurrency, $conversionEntry);
            $conversionMissing = $baseReturn === null || $vatReturn === null;

            if ($conversionMissing) {
                $conversionMissingCount++;
                $warnings[] = 'Doklad ' . self::docLabel($r) . ' má OSS řádek bez přepočtu do měny podání.';
                $baseReturn = 0.0;
                $vatReturn = 0.0;
            } elseif (abs($vatReturn - ($baseReturn * $rate / 100.0)) > 0.02) {
                $warnings[] = 'Doklad ' . self::docLabel($r) . ' má OSS základ a DPH, které neodpovídají zadané sazbě.';
            }

            // Vnitřní konzistence (výše) říká jen to, že si základ a daň odpovídají —
            // konzistentně ŠPATNÁ sazba jí projde. Teprve číselník ověří, že sazba
            // vůbec platí ve státě spotřeby, a to k datu plnění.
            $rateWarning = $this->rateCodebook->checkRate(
                $country,
                $rate,
                $r['oss_rate_type'] !== null ? (string) $r['oss_rate_type'] : null,
                (string) ($r['tax_date'] ?? $r['issue_date'] ?? date('Y-m-d')),
            );
            if ($rateWarning !== null) {
                $warnings[] = 'Doklad ' . self::docLabel($r) . ': ' . $rateWarning;
            }

            $originalPeriod = strtoupper(trim((string) ($r['oss_original_period'] ?? '')));
            if ($originalPeriod !== '') {
                if ($country === '??' || $conversionMissing) {
                    $invalidCorrectionCount++;
                    continue;
                }
                // Co je platná oprava, rozhoduje JEDINÁ funkce ({@see correctionPeriod()}) —
                // ta, podle které se vybral i kurz. Kdyby tady bylo pravidlo napsané podruhé,
                // rozešlo by se s ní a řádek by se přepočetl podle jednoho pravidla a vykázal
                // podle druhého. Tady se jen pojmenuje, PROČ oprava neprošla.
                if ($rowPeriod === null) {
                    $warnings[] = preg_match('/^\d{4}Q[1-4]$/', $originalPeriod) === 1
                        ? 'Doklad ' . self::docLabel($r) . ' musí mít jako opravu OSS období od Q3 2021, které předchází aktuálnímu přiznání.'
                        : 'Doklad ' . self::docLabel($r) . ' má neplatné původní OSS období.';
                    $invalidCorrectionCount++;
                    continue;
                }

                $key = $rowPeriod . '|' . $country;
                $corrections[$key] ??= [
                    'period' => $rowPeriod,
                    'year' => (int) substr($rowPeriod, 0, 4),
                    'quarter' => (int) substr($rowPeriod, 5, 1),
                    'state_consumption' => $country,
                    'correction' => 0.0,
                    'count' => 0,
                    'rows' => [],
                    // Kurz, kterým se oprava přepočetla — účetní ho kontroluje proti
                    // původnímu podání, ne proti aktuální tabulce ECB.
                    'rate_date' => $rowConversion['rate_date'],
                ];
                $corrections[$key]['correction'] += $vatReturn;
                $corrections[$key]['count']++;
                $corrections[$key]['rows'][] = [
                    'invoice_id' => $invoiceId,
                    'item_id' => (int) $r['item_id'],
                    'doc_number' => $r['doc_number'] !== null ? (string) $r['doc_number'] : null,
                    'invoice_type' => (string) $r['invoice_type'],
                    'tax_date' => $r['tax_date'] !== null ? (string) $r['tax_date'] : null,
                    'client_name' => (string) $r['client_name'],
                    'description' => (string) $r['description'],
                    'currency' => (string) $r['currency'],
                    'base_return' => round($baseReturn, 2),
                    'vat_return' => round($vatReturn, 2),
                    'original_period' => $rowPeriod,
                    'exchange_rate' => $rowRate['rate'],
                    'exchange_rate_date' => $rowRate['date'],
                    'exchange_rate_source' => $rowRate['source'],
                ];
                $totalCorrections += $vatReturn;
                $correctionRowCount++;
                continue;
            }

            // Sem se dojde jen s PRÁZDNÝM `oss_original_period`, tzn. „řádek patří do běžného
            // čtvrtletí". U běžného plnění je to správně, u opravného dokladu skoro nikdy:
            // dobropis obvykle opravuje starší kvartál a jeho záporné částky pak tiše sníží
            // daň TADY místo VetaO za období původního plnění. Rozdíl je vidět až na podání,
            // proto varování jmenuje doklad i konkrétní krok.
            // Vědomě NEblokuje (na rozdíl od chybějícího typu sazby): oprava plnění ze
            // STEJNÉHO kvartálu se opravdu jen nettuje do VetaR a uživatel nemá čím ji
            // potvrdit — InvoiceValidation původní období z běžného kvartálu nepřijme.
            // Blokace by tenhle legitimní případ zavřela do slepé uličky.
            if (in_array((string) $r['invoice_type'], ['credit_note', 'cancellation'], true)) {
                $warnings[] = 'Doklad ' . self::docLabel($r) . ' je opravný doklad bez původního OSS období'
                    . ' — oprava se započte do běžného čtvrtletí Q' . $quarter . ' ' . $year . '.'
                    . ' Pokud opravuje plnění ze staršího období, doplňte na položce původní OSS období (RRRRQn).';
            }

            if (empty($r['oss_rate_type'])) {
                $warnings[] = 'Doklad ' . self::docLabel($r) . ' má OSS řádek bez typu sazby.';
            }

            $countries[$country] ??= [
                'country' => $country,
                'base' => 0.0,
                'vat' => 0.0,
                'rates' => [],
                'rows' => [],
            ];
            $countries[$country]['rates'][$rateKey] ??= [
                'rate' => $rate,
                'rate_type' => $r['oss_rate_type'] ?? null,
                'base' => 0.0,
                'vat' => 0.0,
                'count' => 0,
            ];

            $countries[$country]['base'] += $baseReturn;
            $countries[$country]['vat'] += $vatReturn;
            $countries[$country]['rates'][$rateKey]['base'] += $baseReturn;
            $countries[$country]['rates'][$rateKey]['vat'] += $vatReturn;
            $countries[$country]['rates'][$rateKey]['count']++;
            $countries[$country]['rows'][] = [
                'invoice_id' => $invoiceId,
                'item_id' => (int) $r['item_id'],
                'doc_number' => $r['doc_number'] !== null ? (string) $r['doc_number'] : null,
                'invoice_type' => (string) $r['invoice_type'],
                'tax_date' => $r['tax_date'] !== null ? (string) $r['tax_date'] : null,
                'client_name' => (string) $r['client_name'],
                'description' => (string) $r['description'],
                'currency' => (string) $r['currency'],
                'base' => (float) $r['total_without_vat'],
                'vat' => (float) $r['total_vat'],
                'base_return' => round($baseReturn, 2),
                'vat_return' => round($vatReturn, 2),
                'vat_rate' => $rate,
                'rate_type' => $r['oss_rate_type'] ?? null,
                'supply_type' => $r['oss_supply_type'] ?? null,
                // Kurz, kterým řádek do měny podání skutečně přešel. Evidence § 110f ho
                // opisuje jako doklad k bodu 63c(1)(d) — nesmí si ho počítat podruhé.
                'exchange_rate' => $rowRate['rate'],
                'exchange_rate_date' => $rowRate['date'],
                'exchange_rate_source' => $rowRate['source'],
            ];

            $totalBase += $baseReturn;
            $totalVat += $vatReturn;
        }

        // Příznak „k ručnímu posouzení" nese sama položka (`oss_needs_manual_review`,
        // migrace 1293) a do teď ho nečetl nikdo — ani tenhle náhled, ani přiznání k DPH.
        // Kategorie tím žila jen na stránce reportu importu a po jejím zavření ji nikdo
        // neviděl, přestože právě tohle jsou řádky, u kterých je MÍSTO PLNĚNÍ SPORNÉ:
        // sazba platí v obou zemích, číselník neuměl odpovědět, nebo si doklad protiřečí
        // (OSS a tuzemsky zdaněný řádek na jedné faktuře). Náhled podání je poslední
        // obrazovka před odesláním, takže patří sem.
        //
        // Varování je JEDNO za období, ne za doklad: u migrace 1 670 dokladů by se
        // seznamem jednotlivých dokladů utopila všechna ostatní varování.
        if ($manualReviewCount > 0) {
            $warnings[] = $manualReviewCount . ' řádků v tomto období čeká na RUČNÍ POSOUZENÍ'
                . ' — u nich nešlo spolehlivě určit místo plnění (sazba platí i v zemi dodavatele,'
                . ' číselník neuměl odpovědět, nebo doklad míchá OSS a tuzemské plnění).'
                . ' Projděte je na dokladech dřív, než podání odešlete.';
        }

        $countryRows = array_values(array_map(static function (array $country): array {
            $country['base'] = round($country['base'], 2);
            $country['vat'] = round($country['vat'], 2);
            $country['rates'] = array_values(array_map(static fn (array $rate): array => [
                'rate' => $rate['rate'],
                'rate_type' => $rate['rate_type'],
                'base' => round($rate['base'], 2),
                'vat' => round($rate['vat'], 2),
                'count' => $rate['count'],
            ], $country['rates']));
            usort($country['rates'], static fn (array $a, array $b): int => $b['rate'] <=> $a['rate']);
            return $country;
        }, $countries));
        usort($countryRows, static fn (array $a, array $b): int => strcmp($a['country'], $b['country']));

        $correctionRows = array_values(array_map(static function (array $correction): array {
            $correction['correction'] = round($correction['correction'], 2);
            return $correction;
        }, $corrections));
        usort($correctionRows, static fn (array $a, array $b): int =>
            [$a['year'], $a['quarter'], $a['state_consumption']]
            <=> [$b['year'], $b['quarter'], $b['state_consumption']]
        );

        return [
            'period' => [
                'year' => $year,
                'quarter' => $quarter,
                'start' => $start,
                'end' => $end,
                'label' => 'Q' . $quarter . ' ' . $year,
                'submission_deadline' => self::deadline($year, $quarter),
            ],
            'settings' => $settings,
            'summary' => [
                'return_currency' => $returnCurrency,
                // Rozhodný kurzový den ECB. Liší se od konce období, když ECB pro poslední
                // den nezveřejnila (víkend, svátek TARGET) a použil se nejbližší následující
                // den — účetní to musí vidět, protože přesně tohle kontroluje proti tabulce ECB.
                'return_rate_date' => $conversion['rate_date'],
                // Kolik jednotek měny DOKLADU za 1 jednotku měny podání (24,195 Kč za 1 €).
                'return_rates' => $conversion['rates'],
                // Kurzy opravovaných období (VetaO) — jiné než kurz běžného kvartálu.
                // Účetní je kontroluje proti PŮVODNÍMU podání, takže musí vidět i to,
                // odkud se vzaly (`archive` = evidence § 110f, `ecb` = dopočet z kurzů ECB).
                'correction_rates' => self::correctionRateSummary($correctionConversions),
                'total_base' => round($totalBase, 2),
                'total_vat' => round($totalVat, 2),
                'total_corrections' => round($totalCorrections, 2),
                'total_payable' => round($totalVat + $totalCorrections, 2),
                'invoice_count' => count($invoiceIds),
                'row_count' => count($rows),
                'correction_row_count' => $correctionRowCount,
                'invalid_correction_count' => $invalidCorrectionCount,
                'conversion_missing_count' => $conversionMissingCount,
                // Vedle varování i jako číslo, aby náhled uměl nabídnout PROKLIK do
                // seznamu faktur na filtr „nejisté místo plnění — v OSS podání". Bez něj
                // by uživatel četl, že něco k posouzení je, a musel to hledat ručně.
                'manual_review_count' => $manualReviewCount,
            ],
            'countries' => $countryRows,
            'corrections' => $correctionRows,
            // Práh 10 000 EUR (§ 8 odst. 3 ZDPH) se sleduje za CELÝ kalendářní rok, ne za
            // čtvrtletí podání — proto vlastní blok vedle kvartálních čísel.
            'threshold' => $this->threshold->progress($supplierId, $year),
            'warnings' => array_values(array_unique(array_merge(
                $warnings,
                $this->threshold->registrationSanityWarnings(
                    $supplierId,
                    $year,
                    (bool) ($settings['oss_enabled'] ?? false),
                ),
            ))),
        ];
    }

    /**
     * OSS je opt-in v nastavení firmy. Endpointy se na tohle ptají, aby nezaregistrovanému
     * dodavateli nevracely OSS podklad — UI ho ze stejného důvodu skrývá z menu i routeru.
     */
    public function isEnabledFor(int $supplierId): bool
    {
        return (bool) ($this->supplierSettings($supplierId)['oss_enabled'] ?? false);
    }

    /** @return array<string,mixed> */
    private function supplierSettings(int $supplierId): array
    {
        $hasSettings = $this->hasSupplierOssColumns();
        if (!$hasSettings) {
            return [
                'oss_enabled' => false,
                'oss_valid_from' => null,
                'oss_valid_to' => null,
                'oss_identification_country' => null,
                'oss_return_currency' => 'EUR',
            ];
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT oss_enabled, oss_valid_from, oss_valid_to, oss_identification_country, oss_return_currency
               FROM supplier WHERE id = ?'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        return [
            'oss_enabled' => (bool) ($row['oss_enabled'] ?? false),
            'oss_valid_from' => $row['oss_valid_from'] ?? null,
            'oss_valid_to' => $row['oss_valid_to'] ?? null,
            'oss_identification_country' => $row['oss_identification_country'] ?? null,
            'oss_return_currency' => $row['oss_return_currency'] ?? 'EUR',
        ];
    }

    /**
     * Kurz období pro každou měnu, která se v něm objevila a nemá ruční přepočet.
     *
     * `$forPeriod` říká, ČÍ kurz se hledá: `null` = běžné čtvrtletí, `RRRRQn` = opravované
     * období (VetaO). Obě větve berou jen řádky, které do daného období patří — jinak by
     * chybějící kurz opravovaného kvartálu vyvolal varování i u běžných plnění a naopak.
     *
     * U opravovaného období má přednost ARCHIV (evidence § 110f) před dopočtem z kurzů
     * ECB: nese kurz, kterým se tehdy skutečně počítalo. Podrobně viz docblock třídy.
     *
     * Vrací `cross[MĚNA]` = kolik jednotek MĚNY PODÁNÍ za 1 jednotku měny dokladu —
     * tedy táž orientace, jakou má ruční pole `oss_exchange_rate` na položce. Kdyby se
     * orientace mezi ruční a automatickou cestou lišila, obě by daly stejně věrohodně
     * vypadající, ale řádově jiné podání.
     *
     * @param list<array<string,mixed>> $rows
     * @param list<string>              $warnings
     * @return array{rate_date:?string,
     *               cross:array<string,array{rate:float, date:?string, source:string}>,
     *               rates:array<string,float>}
     */
    private function periodConversion(
        array $rows,
        string $returnCurrency,
        string $end,
        ?string $forPeriod,
        int $supplierId,
        string $currentPeriod,
        array &$warnings,
    ): array {
        $empty = ['rate_date' => null, 'cross' => [], 'rates' => []];
        $label = $forPeriod !== null ? self::periodLabel($forPeriod) : null;

        $needed = [];
        foreach ($rows as $r) {
            if (self::correctionPeriod($r, $currentPeriod) !== $forPeriod) {
                continue;
            }
            // Ruční částky i ruční kurz mají přednost — takový řádek kurz období nepotřebuje
            // a nesmí kvůli němu vzniknout varování o nedostupném kurzu.
            if ($r['oss_taxable_amount_return'] !== null && $r['oss_vat_amount_return'] !== null) {
                continue;
            }
            if ($r['oss_exchange_rate'] !== null) {
                continue;
            }
            $currency = self::rowCurrency($r);
            if ($currency !== $returnCurrency && $currency !== '') {
                $needed[$currency] = true;
            }
        }
        if ($needed === []) {
            return $empty;
        }

        $cross = [];

        // 1) Archiv opravovaného období. Kurz, který se za to období opravdu podal, má
        //    přednost před dopočtem — i kdyby se od kurzu ECB o setiny lišil, oprava se
        //    musí vyrovnat proti tomu, co v podání JE, ne proti tomu, co tam být mělo.
        if ($forPeriod !== null) {
            $archived = $this->evidence->ratesForPeriod(
                $supplierId,
                (int) substr($forPeriod, 0, 4),
                (int) substr($forPeriod, 5, 1),
                $returnCurrency,
            );
            foreach (array_keys($needed) as $currency) {
                $hit = $archived[$currency] ?? null;
                if ($hit === null || $hit['rate'] <= 0.0) {
                    continue;
                }
                $cross[$currency] = ['rate' => $hit['rate'], 'date' => $hit['rate_date'], 'source' => 'archive'];
                unset($needed[$currency]);
            }
        }

        // 2) Kurz ECB pro poslední den (opravovaného) období.
        $set = null;
        if ($needed !== []) {
            try {
                $set = $this->ecb->ratesForPeriodEnd(new \DateTimeImmutable($end));
            } catch (\Exception) {
                $set = null;
            }
        }

        if ($needed !== [] && $set === null) {
            // NEPŘEPÍNÁ se tiše na ČNB ani na kurz běžného kvartálu. Denní kurz ČNB k datu
            // plnění je pro tuzemský základ daně správný, pro OSS podání ale dá jiné částky —
            // a chyba by se projevila až po odeslání. Raději prázdno a varování, které
            // pojmenuje, co s tím.
            $warnings[] = match (true) {
                $forPeriod !== null => sprintf(
                    'Opravu období %s nelze přepočíst: archiv podání za %s kurz nenese a kurz ECB pro'
                        . ' poslední den opravovaného období (%s) se nepodařilo získat. Kurzem běžného'
                        . ' čtvrtletí se oprava přepočíst NESMÍ — rozdíl v %s by se od částky, která se'
                        . ' za %s skutečně podala, nikdy neodečetl. Doplňte na položce ruční kurz nebo'
                        . ' ruční částky pro OSS.',
                    $label,
                    $label,
                    self::fmtDate($end),
                    $returnCurrency,
                    $label,
                ),
                $end > $this->clock->now()->format('Y-m-d') => sprintf(
                    'Kurz ECB pro poslední den období (%s) zatím nebyl zveřejněn — období ještě neskončilo.'
                        . ' Řádky v jiné měně než %s proto nejsou přepočtené. ECB kurzy vydává v pracovní den'
                        . ' kolem 16:00; náhled načtěte znovu po skončení období.',
                    self::fmtDate($end),
                    $returnCurrency,
                ),
                default => sprintf(
                    'Kurz ECB pro poslední den období (%s) se nepodařilo získat, takže řádky v jiné měně než %s'
                        . ' nejsou přepočtené. Zkuste náhled načíst znovu (feed ECB může být dočasně nedostupný);'
                        . ' pokud to nepomůže, doplňte na položkách ruční kurz nebo ruční částky pro OSS.'
                        . ' Kurz ČNB se místo něj vědomě nepoužije — podání se přepočítává kurzem ECB'
                        . ' zveřejněným pro poslední den zdaňovacího období.',
                    self::fmtDate($end),
                    $returnCurrency,
                ),
            };
        }

        if ($set !== null) {
            foreach (array_keys($needed) as $currency) {
                $rate = EcbExchangeRateClient::crossRate($set['rates'], $currency, $returnCurrency);
                if ($rate === null || $rate <= 0.0) {
                    $warnings[] = sprintf(
                        'Kurz ECB pro měnu %s ke dni %s neexistuje — ECB tuhle měnu nekótuje.%s'
                            . ' Doplňte na položkách dokladů v této měně ruční kurz nebo ruční částky pro OSS.',
                        $currency,
                        self::fmtDate($set['rate_date']),
                        $label !== null ? ' Jde o opravu období ' . $label . '.' : '',
                    );
                    continue;
                }
                $cross[$currency] = ['rate' => $rate, 'date' => $set['rate_date'], 'source' => 'ecb'];
            }
        }

        if ($cross === []) {
            // `rate_date` z prázdného přepočtu by tvrdil, že se něčím počítalo.
            return $set === null ? $empty : ['rate_date' => $set['rate_date'], 'cross' => [], 'rates' => []];
        }

        // Do odpovědi jde kurz v podobě, ve které ho člověk kontroluje proti tabulce ECB
        // (resp. proti původnímu podání): kolik jednotek měny DOKLADU za 1 jednotku měny
        // podání (24,195 Kč za 1 €).
        $rates = [];
        $rateDate = null;
        foreach ($cross as $currency => $entry) {
            $rates[$currency] = round(1 / $entry['rate'], 6);
            $rateDate ??= $entry['date'];
        }

        return ['rate_date' => $rateDate ?? ($set['rate_date'] ?? null), 'cross' => $cross, 'rates' => $rates];
    }

    /**
     * Období, PROTI KTERÉMU se řádek přepočítává: opravovaný kvartál (`RRRRQn`) u opravy
     * minulého období, `null` u běžného plnění.
     *
     * Jediná definice toho, co je platná oprava — používá ji jak výběr kurzu, tak hlavní
     * smyčka náhledu. Řádek s vyplněným, ale neplatným původním obdobím tady vyjde jako
     * běžný a smyčka ho vzápětí odmítne jako neplatnou opravu; do součtů běžného kvartálu
     * se tedy nedostane.
     *
     * @param array<string,mixed> $row
     */
    private static function correctionPeriod(array $row, string $currentPeriod): ?string
    {
        $period = strtoupper(trim((string) ($row['oss_original_period'] ?? '')));
        if ($period === '' || preg_match('/^\d{4}Q[1-4]$/', $period) !== 1) {
            return null;
        }
        // Režim EU běží od Q3 2021 a opravovat lze jen období, které aktuálnímu přiznání
        // PŘEDCHÁZÍ — oprava plnění z téhož kvartálu se nettuje do VetaR, ne do VetaO.
        if ($period < '2021Q3' || $period >= $currentPeriod) {
            return null;
        }

        return $period;
    }

    /**
     * Kurz, kterým řádek do měny podání SKUTEČNĚ přešel, v téže orientaci jako ruční pole
     * `oss_exchange_rate` (kolik jednotek měny podání za 1 jednotku měny dokladu).
     *
     * Pořadí přednosti musí sedět s {@see returnAmount()} — evidence § 110f tenhle kurz
     * opisuje jako doklad k bodu 63c(1)(d). Kdyby se rozešly, evidence by tvrdila, že se
     * počítalo kurzem, kterým se nepočítalo.
     *
     * `rate = null` znamená „kurz nelze doložit": buď se řádek nepřepočetl vůbec, nebo obě
     * částky v měně podání zadal ručně účetní a systém neví, jakým kurzem. Zpětný dopočet
     * z podílu částek by vypadal jako doklad o kurzu, a nebyl by jím — evidence tuhle mezeru
     * radši přizná.
     *
     * @param array<string,mixed> $row
     * @param array{rate:float, date:?string, source:string}|null $periodRate
     * @return array{rate:?float, date:?string, source:?string}
     */
    private static function rowRate(array $row, string $returnCurrency, ?array $periodRate): array
    {
        if ($row['oss_exchange_rate'] !== null) {
            return [
                'rate' => (float) $row['oss_exchange_rate'],
                'date' => $row['oss_exchange_rate_date'] !== null ? (string) $row['oss_exchange_rate_date'] : null,
                'source' => 'manual',
            ];
        }
        if (self::rowCurrency($row) === $returnCurrency) {
            // Doklad je rovnou v měně podání — nepřepočítávalo se, kurz je 1 a žádný
            // kurzový den k němu neexistuje.
            return ['rate' => 1.0, 'date' => null, 'source' => 'identity'];
        }
        if ($row['oss_taxable_amount_return'] !== null && $row['oss_vat_amount_return'] !== null) {
            return ['rate' => null, 'date' => null, 'source' => 'manual_amount'];
        }
        if ($periodRate === null) {
            return ['rate' => null, 'date' => null, 'source' => null];
        }

        return ['rate' => $periodRate['rate'], 'date' => $periodRate['date'], 'source' => $periodRate['source']];
    }

    /**
     * Kurzy opravovaných období do `summary`. Vedle kurzu se vydává i jeho ZDROJ: kurz
     * z archivu a kurz dopočtený z ECB se můžou o setiny lišit a účetní musí vědět, proti
     * čemu své číslo kontroluje.
     *
     * @param array<string, array{rate_date:?string, cross:array<string,array{rate:float, date:?string, source:string}>, rates:array<string,float>}> $conversions
     * @return list<array{period:string, label:string, rate_date:?string, rates:array<string,float>, sources:array<string,string>}>
     */
    private static function correctionRateSummary(array $conversions): array
    {
        $out = [];
        foreach ($conversions as $period => $conversion) {
            $out[] = [
                'period' => $period,
                'label' => self::periodLabel($period),
                'rate_date' => $conversion['rate_date'],
                'rates' => $conversion['rates'],
                'sources' => array_map(
                    static fn (array $entry): string => $entry['source'],
                    $conversion['cross'],
                ),
            ];
        }

        return $out;
    }

    /** `2026Q1` → `Q1 2026`. Do hlášek pro uživatele, který kvartály čte takhle. */
    private static function periodLabel(string $period): string
    {
        return 'Q' . substr($period, 5, 1) . ' ' . substr($period, 0, 4);
    }

    /**
     * Částka v měně podání. Pořadí přednosti je závazné: ruční částka → shodná měna →
     * ruční kurz → kurz OBDOBÍ. Ruční hodnoty jsou vědomé rozhodnutí účetního nad
     * konkrétním dokladem, takže je automatika nikdy nepřebíjí.
     *
     * „Kurz období" je u běžného plnění kurz ECB pro poslední den vykazovaného čtvrtletí,
     * u opravy minulého období kurz OPRAVOVANÉHO kvartálu — volající ho vybírá přes
     * {@see periodConversion()} a sem posílá už hotový.
     *
     * `null` = přepočet chybí. Volající to počítá do `conversion_missing_count`, kterým
     * {@see OssXmlExporter} export XML zastaví — nula místo chybějícího přepočtu by
     * vypadala jako hotové číslo.
     *
     * @param array<string,mixed> $row
     * @param ?float $periodRate kolik jednotek měny podání za 1 jednotku měny dokladu
     */
    private static function returnAmount(
        array $row,
        string $field,
        string $sourceField,
        string $returnCurrency,
        ?float $periodRate,
    ): ?float {
        if ($row[$field] !== null) {
            return (float) $row[$field];
        }
        if (self::rowCurrency($row) === $returnCurrency) {
            return (float) $row[$sourceField];
        }
        if ($row['oss_exchange_rate'] !== null) {
            // HALF_UP přes bcmath, ne `round()`: tahle částka jde do PODÁNÍ (VetaR
            // taxable_amount / vat_amount). `round()` kvůli binární nepřesnosti dá
            // u součinu na půlhaléřové hranici o haléř míň — změřeno 603× na 1,6 mil.
            // kombinací částek a reálných kurzů ČNB. SSOT je CzkRecap::multiplyHalfUp().
            return CzkRecap::multiplyHalfUp((float) $row[$sourceField], (float) $row['oss_exchange_rate']);
        }
        if ($periodRate === null) {
            return null;
        }

        // Přes CzkRecap se nejde schválně: ten formátuje kurz na šest desetinných míst,
        // což u kurzu 0,0413… (CZK→EUR) uřízne relativní rozdíl ~2e-6. Na čtvrtletním
        // základu jsou to koruny navíc v částce, která se podává. Zaokrouhlení je stejné.
        return EcbExchangeRateClient::applyRate((float) $row[$sourceField], $periodRate);
    }

    /** @param array<string,mixed> $row */
    private static function rowCurrency(array $row): string
    {
        return strtoupper(trim((string) ($row['currency'] ?? '')));
    }

    private static function fmtDate(string $date): string
    {
        try {
            return (new \DateTimeImmutable($date))->format('j. n. Y');
        } catch (\Exception) {
            return $date;
        }
    }

    private static function deadline(int $year, int $quarter): string
    {
        [$start] = OssPeriod::range($year, $quarter);
        return (new \DateTimeImmutable($start))->modify('+4 months -1 day')->format('Y-m-d');
    }

    /** @return array<string,mixed> */
    private function emptyPreview(int $year, int $quarter, string $start, string $end, array $settings, array $warnings): array
    {
        return [
            'period' => [
                'year' => $year,
                'quarter' => $quarter,
                'start' => $start,
                'end' => $end,
                'label' => 'Q' . $quarter . ' ' . $year,
                'submission_deadline' => self::deadline($year, $quarter),
            ],
            'settings' => $settings,
            'summary' => [
                'return_currency' => $settings['oss_return_currency'] ?? 'EUR',
                'return_rate_date' => null,
                'return_rates' => [],
                'correction_rates' => [],
                'total_base' => 0.0,
                'total_vat' => 0.0,
                'total_corrections' => 0.0,
                'total_payable' => 0.0,
                'invoice_count' => 0,
                'row_count' => 0,
                'correction_row_count' => 0,
                'invalid_correction_count' => 0,
                'conversion_missing_count' => 0,
                'manual_review_count' => 0,
            ],
            'countries' => [],
            'corrections' => [],
            // Bez OSS sloupců nelze práh počítat — nulový blok drží tvar odpovědi,
            // ať se UI nemusí ptát, jestli klíč vůbec existuje.
            'threshold' => [
                'year' => $year, 'threshold_eur' => 0.0, 'total_eur' => 0.0, 'pct' => 0.0,
                'exceeded' => false, 'exceeded_on' => null, 'near_threshold' => false,
                'by_country' => [], 'unconverted_rows' => 0, 'warnings' => [],
            ],
            'warnings' => $warnings,
        ];
    }

    private function hasOssColumns(): bool
    {
        return $this->db->hasColumn('invoice_items', 'oss_applicable');
    }

    private function hasSupplierOssColumns(): bool
    {
        return $this->db->hasColumn('supplier', 'oss_enabled');
    }

    /** @param array<string,mixed> $row */
    private static function docLabel(array $row): string
    {
        return (string) ($row['doc_number'] ?? ('#' . (int) $row['invoice_id']));
    }
}
