<?php

declare(strict_types=1);

namespace MyInvoice\Service\Oss;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\TaxConstantsRepository;

/**
 * Evidence pro účely zvláštního režimu jednoho správního místa — § 110f ZDPH.
 *
 * ── Co zákon žádá a odkud to víme ──────────────────────────────────────────────────
 * § 110f odst. 1 zákona č. 235/2004 Sb. ukládá vést k uskutečněným vybraným plněním
 * evidenci „obsahující údaje podle přímo použitelného předpisu Evropské unie" —
 * strukturu tedy sám nestanoví. Tím předpisem je **čl. 63c prováděcího nařízení Rady
 * (EU) č. 282/2011** ve znění nařízení (EU) 2019/2026; jeho odstavec 1 vyjmenovává
 * body a) až l) a odstavec 3 žádá, aby se záznamy daly poskytnout elektronicky bez
 * prodlení a ZA KAŽDÉ JEDNOTLIVÉ PLNĚNÍ. § 110f odst. 2 pak přidává uchování 10 let od
 * konce kalendářního roku uskutečnění plnění a poskytnutí správci daně elektronicky.
 *
 * POZNÁMKA K ČÍSLOVÁNÍ: zadání téhle epiky mluvilo o „§ 110ze". Ten se ale po novele
 * účinné od 1. 7. 2021 jmenuje *Vyčíslení daně* (daň v eurech na 2 desetinná místa bez
 * zaokrouhlování) — evidenční povinnost je v § 110f. Konstanty {@see LEGAL_BASIS} to
 * drží na jednom místě, ať se odkaz nepřepisuje po celém kódu.
 *
 * ── Proč se hodnoty kopírují, a ne dopočítávají ────────────────────────────────────
 * Evidence má odpovědět na otázku „co bylo podkladem podání", a to i za deset let, kdy
 * původní faktura může být opravená, stornovaná nebo smazaná. Kdyby se evidence počítala
 * z živých dokladů, ukazovala by po opravě jiný stav než podané XML — tedy přesně to,
 * proti čemu má sloužit. Zapisuje se proto v okamžiku archivace podání do write-once
 * tabulky (migrace 1300, triggery zakazují UPDATE i DELETE).
 *
 * ── Nenaplněné body se přiznávají ──────────────────────────────────────────────────
 * Několik bodů čl. 63c dnešní datový model doložit neumí (viz {@see UNSUPPORTED_POINTS}).
 * Záznam u sebe nese seznam těch, které se u něj naplnit nepodařilo, a export je vypíše.
 * Alternativa — nechat sloupec prázdný a nic neříct — by budila dojem splněné povinnosti
 * přesně tam, kde splněná není.
 *
 * ── Kurz přepočtu je součást bodu d) ───────────────────────────────────────────────
 * Do konce roku 2026 se `exchange_rate`/`exchange_rate_date` plnily JEN z ručního kurzu
 * na položce. U naprosté většiny řádků — těch přepočtených kurzem ECB pro poslední den
 * období — tedy zůstaly NULL a `completeness_json` o tom mlčel: evidence tvrdila, že je
 * úplná, a přitom neuměla doložit, jakým kurzem částka v eurech vznikla. Kurz teď dodává
 * {@see OssLedgerService} v náhledu (jediné místo, kde se přepočet rozhoduje) a evidence
 * ho jen opisuje. Když ho doložit nelze — účetní zadal částky v měně podání ručně —
 * přizná se to bodem `d`, místo aby se zpětně dopočítal z podílu částek.
 *
 * Kurz zapsaný tady je zároveň JEDINÝ archivní zdroj pro přepočet opravy minulého
 * období: {@see ratesForPeriod()} ho čte, aby se oprava (VetaO) přepočetla týmž kurzem
 * jako původní podání.
 */
final class OssEvidenceService
{
    public const LEGAL_BASIS = '§ 110f ZDPH; čl. 63c nařízení (EU) č. 282/2011';

    /**
     * Fallback default, kdyby daný rok neměl v TaxConstants klíč (nemělo by nastat).
     * Primárně se čte {@see TaxConstantsRepository::forYear()} pro rok uskutečnění plnění.
     */
    public const RETENTION_YEARS = 10;

    /**
     * Body čl. 63c odst. 1, které se z dnešního datového modelu doložit nedají.
     * Vědomě jsou v konstantě a ne v komentáři: export je vypisuje uživateli, takže
     * seznam musí být strojově čitelný a nesmí se rozejít s tím, co kód opravdu plní.
     */
    public const UNSUPPORTED_POINTS = [
        'i' => 'Zálohy přijaté před uskutečněním plnění se k OSS řádku neváží — proforma'
            . ' nemá vazbu na konkrétní položku, ze které OSS plnění vzniklo.',
        'k_goods' => 'U zboží se nesleduje místo zahájení a ukončení odeslání nebo přepravy;'
            . ' evidence proto u zboží nese jen podklad o odběrateli (jako u služeb).',
        'l' => 'Doklad o vrácení zboží se needviduje samostatně — vrácení je v systému'
            . ' zachyceno opravným dokladem, ne důkazem o vrácení věci.',
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly TaxConstantsRepository $taxConstants,
    ) {}

    /**
     * Zapíše evidenci k právě archivovanému OSS podání. Vrací počet zapsaných záznamů.
     *
     * Volá se z {@see \MyInvoice\Action\Report\OssReportAction::download()} hned po
     * archivaci XML, aby evidence a snapshot podání pocházely z JEDNOHO čtení dat.
     * Idempotence stojí na unikátním klíči (supplier, submission, seq): opakovaný běh
     * nad týmž podáním nic nezdvojí, ale ani nepřepíše (write-once trigger).
     *
     * Best-effort z pohledu volajícího: selhání evidence nesmí shodit stažení XML —
     * ale MUSÍ být hlasité v logu. Rozhodnutí o tom dělá volající, ne tahle metoda.
     *
     * @param array<string,mixed> $preview náhled, ze kterého vzniklo podání
     */
    public function capture(
        int $supplierId,
        int $submissionId,
        int $year,
        int $quarter,
        array $preview,
        ?int $userId,
    ): int {
        if (!$this->isAvailable() || $this->alreadyCaptured($supplierId, $submissionId)) {
            return 0;
        }

        $records = $this->build($supplierId, $year, $quarter, $preview);
        if ($records === []) {
            return 0;
        }

        // Plný INSERT, nikoli INSERT IGNORE: `IGNORE` by kromě duplicit spolkl i chyby
        // typu „hodnota se nevejde do sloupce" a evidence by tiše přišla o řádek. Duplicitu
        // řeší kontrola výš, takže potlačovat není co.
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO oss_filing_evidence
                (supplier_id, submission_id, period_year, period_quarter, seq,
                 consumption_country, supply_type, supply_description, supply_quantity, supply_unit,
                 supply_date, taxable_amount, taxable_currency, taxable_amount_return, return_currency,
                 exchange_rate, exchange_rate_date, adjusted_period, vat_rate, vat_rate_type,
                 vat_amount, vat_amount_return, payments_json,
                 invoice_id, invoice_item_id, invoice_snapshot_json,
                 customer_name, place_evidence_json, completeness_json, retain_until, captured_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $written = 0;
        foreach ($records as $r) {
            $stmt->execute([
                $supplierId, $submissionId, $year, $quarter, $r['seq'],
                $r['consumption_country'], $r['supply_type'], $r['supply_description'],
                $r['supply_quantity'], $r['supply_unit'],
                $r['supply_date'], $r['taxable_amount'], $r['taxable_currency'],
                $r['taxable_amount_return'], $r['return_currency'],
                $r['exchange_rate'], $r['exchange_rate_date'], $r['adjusted_period'],
                $r['vat_rate'], $r['vat_rate_type'],
                $r['vat_amount'], $r['vat_amount_return'],
                self::json($r['payments']),
                $r['invoice_id'], $r['invoice_item_id'], self::json($r['invoice_snapshot']),
                $r['customer_name'], self::json($r['place_evidence']), self::json($r['completeness']),
                $r['retain_until'], $userId,
            ]);
            $written += $stmt->rowCount();
        }

        return $written;
    }

    /**
     * Evidenční záznamy období tak, jak se zapsaly do archivu.
     *
     * @return array{legal_basis:string, retention_years:int, unsupported:array<string,string>,
     *               available:bool, records:list<array<string,mixed>>}
     */
    public function records(int $supplierId, int $year, int $quarter, ?int $submissionId = null): array
    {
        $head = [
            'legal_basis'     => self::LEGAL_BASIS,
            'retention_years' => (int) ($this->taxConstants->forYear($year)['oss_evidence_retention_years'] ?? self::RETENTION_YEARS),
            'unsupported'     => self::UNSUPPORTED_POINTS,
            'available'       => $this->isAvailable(),
        ];
        if (!$this->isAvailable()) {
            return $head + ['records' => []];
        }

        // Bez konkrétního podání se bere POSLEDNÍ zapsaná sada za období — ne sjednocení
        // všech. Opakované stažení téhož čtvrtletí zakládá novou sadu a jejich sloučení
        // by plnění zdvojilo, což je u evidence horší chyba než chybějící záznam.
        $submissionId ??= $this->latestSubmissionId($supplierId, $year, $quarter);
        if ($submissionId === null) {
            return $head + ['records' => []];
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM oss_filing_evidence
              WHERE supplier_id = ? AND submission_id = ?
           ORDER BY seq'
        );
        $stmt->execute([$supplierId, $submissionId]);

        return $head + [
            'submission_id' => $submissionId,
            'records'       => array_map(self::normalize(...), $stmt->fetchAll(\PDO::FETCH_ASSOC)),
        ];
    }

    /**
     * Kurzy, kterými se BĚŽNÁ plnění daného čtvrtletí skutečně přepočetla do měny podání,
     * čtené z write-once evidence — tedy z archivu podání, ne z dnešních dokladů.
     *
     * K čemu to je: oprava minulého období (VetaO) musí jít proti TÉMUŽ kurzu jako původní
     * podání. Kurzem běžného kvartálu by se rozdíl v eurech od částky, která se za
     * opravované období podala, nikdy neodečetl — kurzový posun by v podání zůstal natrvalo.
     *
     * Bere se POSLEDNÍ sada evidence za období (táž, kterou vrací {@see records()}) a jen
     * řádky bez `adjusted_period`: opravy zapsané v tomtéž čtvrtletí nesou kurz JINÉHO,
     * staršího období a jako „kurz tohohle kvartálu" by lhaly.
     *
     * Měna, u které evidence nese víc RŮZNÝCH kurzů (typicky ruční kurz účetního na jedné
     * položce vedle kurzu ECB na ostatních), se záměrně vynechá: jednotný kurz období pak
     * neexistuje a vybrat jeden z nich by znamenalo hádat. Volající v takovém případě sáhne
     * po kurzu ECB pro poslední den opravovaného období.
     *
     * @return array<string, array{rate:float, rate_date:?string}> klíč = měna dokladu;
     *         `rate` = kolik jednotek měny podání za 1 jednotku měny dokladu
     */
    public function ratesForPeriod(int $supplierId, int $year, int $quarter, string $returnCurrency): array
    {
        if (!$this->isAvailable()) {
            return [];
        }
        $submissionId = $this->latestSubmissionId($supplierId, $year, $quarter);
        if ($submissionId === null) {
            return [];
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT UPPER(TRIM(taxable_currency)) AS currency, exchange_rate,
                    MIN(exchange_rate_date) AS rate_date
               FROM oss_filing_evidence
              WHERE supplier_id = ? AND submission_id = ?
                AND UPPER(TRIM(return_currency)) = ?
                AND adjusted_period IS NULL
                AND exchange_rate IS NOT NULL
                AND UPPER(TRIM(taxable_currency)) <> UPPER(TRIM(return_currency))
           GROUP BY UPPER(TRIM(taxable_currency)), exchange_rate'
        );
        $stmt->execute([$supplierId, $submissionId, strtoupper(trim($returnCurrency))]);

        $variants = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $rate = (float) $row['exchange_rate'];
            if ($rate <= 0.0) {
                continue;
            }
            $variants[(string) $row['currency']][] = [
                'rate'      => $rate,
                'rate_date' => $row['rate_date'] !== null ? (string) $row['rate_date'] : null,
            ];
        }

        $out = [];
        foreach ($variants as $currency => $found) {
            if (count($found) === 1) {
                $out[$currency] = $found[0];
            }
        }

        return $out;
    }

    /**
     * Ploché řádky pro CSV export (čl. 63c odst. 3 — „elektronicky bez prodlení").
     * Sloupce nesou v hlavičce písmeno bodu, aby šlo doložit, co který znamená.
     *
     * @param list<array<string,mixed>> $records
     * @return list<list<string>> první řádek = hlavička
     */
    public static function csvRows(array $records): array
    {
        $out = [[
            'poradi',
            '63c(1)(a) stat_spotreby',
            '63c(1)(b) druh_plneni',
            '63c(1)(b) popis',
            '63c(1)(b) mnozstvi',
            '63c(1)(b) jednotka',
            '63c(1)(c) datum_plneni',
            '63c(1)(d) zaklad_dane',
            '63c(1)(d) mena_dokladu',
            '63c(1)(d) zaklad_dane_v_mene_podani',
            '63c(1)(d) mena_podani',
            // Kurz patří k bodu d): bez něj se nedá doložit, jak částka v měně podání
            // z částky na dokladu vznikla. Prázdno = evidence kurz doložit neumí
            // a přiznává to v `nenaplnene_body`.
            '63c(1)(d) kurz',
            '63c(1)(d) datum_kurzu',
            '63c(1)(e) opravovane_obdobi',
            '63c(1)(f) sazba',
            '63c(1)(f) typ_sazby',
            '63c(1)(g) dan',
            '63c(1)(g) dan_v_mene_podani',
            '63c(1)(h) uhrady',
            '63c(1)(j) doklad',
            '63c(1)(k) odberatel',
            '63c(1)(k) podklad_mista_plneni',
            'uchovat_do',
            'nenaplnene_body',
        ]];

        foreach ($records as $r) {
            $payments = implode('; ', array_map(
                static fn (array $p): string => sprintf('%s %s %s', $p['paid_on'], $p['amount'], $p['currency']),
                (array) ($r['payments'] ?? []),
            ));
            $place = (array) ($r['place_evidence'] ?? []);
            $out[] = [
                (string) $r['seq'],
                (string) $r['consumption_country'],
                (string) ($r['supply_type'] ?? ''),
                (string) $r['supply_description'],
                $r['supply_quantity'] !== null ? (string) $r['supply_quantity'] : '',
                (string) ($r['supply_unit'] ?? ''),
                (string) $r['supply_date'],
                (string) $r['taxable_amount'],
                (string) $r['taxable_currency'],
                (string) $r['taxable_amount_return'],
                (string) $r['return_currency'],
                (string) ($r['exchange_rate'] ?? ''),
                (string) ($r['exchange_rate_date'] ?? ''),
                (string) ($r['adjusted_period'] ?? ''),
                (string) $r['vat_rate'],
                (string) ($r['vat_rate_type'] ?? ''),
                (string) $r['vat_amount'],
                (string) $r['vat_amount_return'],
                $payments,
                (string) (($r['invoice_snapshot']['doc_number'] ?? '') ?: ('#' . ($r['invoice_id'] ?? ''))),
                (string) ($r['customer_name'] ?? ''),
                implode('; ', array_map(
                    static fn ($k, $v): string => $k . '=' . (is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE)),
                    array_keys($place),
                    array_values($place),
                )),
                (string) $r['retain_until'],
                implode('; ', array_keys((array) ($r['completeness'] ?? []))),
            ];
        }

        return $out;
    }

    /**
     * § 110f odst. 2 písm. a): 10 let od KONCE kalendářního roku, ve kterém bylo plnění
     * uskutečněno. Rozhoduje rok PLNĚNÍ, ne rok podání — u opravy staršího období se
     * tím lhůta odvíjí od původního plnění, ne od čtvrtletí, ve kterém se oprava podala.
     *
     * Čistá a veřejná schválně: totéž datum potřebuje spočítat i případná retenční
     * úloha, a dvě kopie pravidla „+10 let" by se rozešly o hraniční rok.
     */
    public static function retainUntil(string $supplyDate, int $retentionYears = self::RETENTION_YEARS): string
    {
        $year = (int) substr($supplyDate, 0, 4);

        return sprintf('%04d-12-31', $year + $retentionYears);
    }

    /** § 110f odst. 2 písm. a) — uchování evidence pro rok PLNĚNÍ dané položky. */
    private function retentionYears(string $supplyDate): int
    {
        $year = (int) substr($supplyDate, 0, 4);

        return (int) ($this->taxConstants->forYear($year)['oss_evidence_retention_years'] ?? self::RETENTION_YEARS);
    }

    public function isAvailable(): bool
    {
        return $this->db->hasTable('oss_filing_evidence');
    }

    /**
     * Kurz bodu d) pro jeden řádek: kolik jednotek měny podání za 1 jednotku měny dokladu.
     *
     * Autoritou je NÁHLED — tam se o přepočtu rozhoduje a tam je i pořadí přednosti
     * (ruční kurz → shodná měna → kurz období → kurz opravovaného období). Evidence si
     * ho nesmí počítat podruhé, jinak by dokládala jiný kurz, než jakým se přepočítalo.
     * Čtení z `$detail` je jen záchrana pro náhled bez těchto klíčů (starší volající,
     * testy) a umí doložit pouze ruční kurz na položce.
     *
     * Doklad rovnou v měně podání dostane kurz 1 bez kurzového dne — nepřepočítával se,
     * takže žádný den ECB k němu neexistuje a `null` by se četlo jako „nevíme".
     *
     * @param array<string,mixed> $row    řádek náhledu
     * @param array<string,mixed> $detail údaje položky z databáze
     * @return array{0:?float, 1:?string} kurz a jeho datum
     */
    private static function exchangeRate(array $row, array $detail, string $taxableCurrency, string $returnCurrency): array
    {
        $rate = $row['exchange_rate'] ?? $detail['oss_exchange_rate'] ?? null;
        $date = $row['exchange_rate_date'] ?? $detail['oss_exchange_rate_date'] ?? null;

        if ($rate === null && strtoupper(trim($taxableCurrency)) === strtoupper(trim($returnCurrency))) {
            return [1.0, null];
        }
        if ($rate === null || (float) $rate <= 0.0) {
            return [null, null];
        }

        return [(float) $rate, $date !== null ? (string) $date : null];
    }

    /**
     * Sestaví evidenční záznamy z náhledu období. Oddělené od zápisu, aby šlo mapování
     * na body čl. 63c otestovat bez databáze zápisu (čtení dokladu se nevyhne).
     *
     * @param array<string,mixed> $preview
     * @return list<array<string,mixed>>
     */
    private function build(int $supplierId, int $year, int $quarter, array $preview): array
    {
        $returnCurrency = (string) ($preview['summary']['return_currency'] ?? 'EUR');

        // Běžná plnění i opravy jdou do TÉŽE evidence — čl. 63c odst. 1 písm. e) mluví
        // o „následném zvýšení nebo snížení základu daně" jako o údaji evidence, ne
        // o samostatné evidenci. Odlišuje je `adjusted_period`.
        $itemIds = [];
        $sources = [];
        foreach (($preview['countries'] ?? []) as $country) {
            foreach (($country['rows'] ?? []) as $row) {
                $sources[] = [$row, strtoupper((string) $country['country']), null];
                $itemIds[] = (int) $row['item_id'];
            }
        }
        foreach (($preview['corrections'] ?? []) as $correction) {
            foreach (($correction['rows'] ?? []) as $row) {
                $sources[] = [$row, strtoupper((string) $correction['state_consumption']), (string) $correction['period']];
                $itemIds[] = (int) $row['item_id'];
            }
        }
        if ($sources === []) {
            return [];
        }

        $details = $this->itemDetails($supplierId, $itemIds);
        $payments = $this->payments($supplierId, array_values(array_unique(
            array_map(static fn (array $s): int => (int) $s[0]['invoice_id'], $sources)
        )));

        $records = [];
        $seq = 0;
        foreach ($sources as [$row, $country, $adjustedPeriod]) {
            $itemId = (int) $row['item_id'];
            $invoiceId = (int) $row['invoice_id'];
            $detail = $details[$itemId] ?? [];
            $supplyDate = (string) ($row['tax_date'] ?? $detail['issue_date'] ?? '');
            if ($supplyDate === '') {
                // Bez data plnění nejde spočítat lhůtu uchování ani naplnit bod c).
                // Náhled takový řádek do podání nepustí, sem se tedy dojít nemá — ale
                // kdyby ano, evidence si datum nevymyslí.
                continue;
            }

            $completeness = self::UNSUPPORTED_POINTS;
            if (($detail['oss_supply_type'] ?? null) !== 'goods') {
                unset($completeness['k_goods']);
            }
            $rateType = $row['rate_type'] ?? $detail['oss_rate_type'] ?? null;
            if ($rateType === null) {
                $completeness['f'] = 'Typ sazby ve státě spotřeby se nepodařilo určit —'
                    . ' číselník sazeb členských států na sazbu k datu plnění neodpověděl.';
            }

            // Bod d) — kurz, kterým částka v měně podání vznikla. Náhled ho nese pro
            // VŠECHNY cesty přepočtu (ruční kurz, kurz ECB období, kurz opravovaného
            // období), ne jen pro ruční pole na položce; `$detail` je záložní čtení pro
            // volající, kteří náhled sestavili bez těchto klíčů.
            $taxableCurrency = (string) ($row['currency'] ?? $detail['currency'] ?? 'CZK');
            [$exchangeRate, $exchangeRateDate] = self::exchangeRate($row, $detail, $taxableCurrency, $returnCurrency);
            if ($exchangeRate === null) {
                $completeness['d'] = 'Kurz použitý pro přepočet do měny podání nelze doložit —'
                    . ' částky v měně podání zadal ručně účetní, nebo se řádek do měny podání'
                    . ' vůbec nepřepočetl. Zpětný dopočet z podílu částek by doklad o kurzu'
                    . ' nenahradil, proto se neuvádí.';
            }

            $records[] = [
                'seq'                   => ++$seq,
                'consumption_country'   => $country,
                'supply_type'           => $detail['oss_supply_type'] ?? ($row['supply_type'] ?? null),
                'supply_description'    => (string) ($row['description'] ?? $detail['description'] ?? ''),
                'supply_quantity'       => isset($detail['quantity']) ? (float) $detail['quantity'] : null,
                'supply_unit'           => $detail['unit'] ?? null,
                'supply_date'           => $supplyDate,
                'taxable_amount'        => round((float) ($detail['total_without_vat'] ?? $row['base'] ?? 0.0), 2),
                'taxable_currency'      => $taxableCurrency,
                'taxable_amount_return' => round((float) ($row['base_return'] ?? 0.0), 2),
                'return_currency'       => $returnCurrency,
                'exchange_rate'         => $exchangeRate,
                'exchange_rate_date'    => $exchangeRateDate,
                'adjusted_period'       => $adjustedPeriod,
                'vat_rate'              => round((float) ($row['vat_rate'] ?? $detail['vat_rate_snapshot'] ?? 0.0), 2),
                'vat_rate_type'         => $rateType,
                'vat_amount'            => round((float) ($detail['total_vat'] ?? $row['vat'] ?? 0.0), 2),
                'vat_amount_return'     => round((float) ($row['vat_return'] ?? 0.0), 2),
                'payments'              => $payments[$invoiceId] ?? [],
                'invoice_id'            => $invoiceId ?: null,
                'invoice_item_id'       => $itemId ?: null,
                // Bod j) — „údaje uvedené na dokladu". Opisuje se hlavička faktury, ne
                // celý doklad: evidence je o plnění, ne náhrada archivu faktur.
                'invoice_snapshot'      => [
                    'doc_number'     => $row['doc_number'] ?? $detail['doc_number'] ?? null,
                    'invoice_type'   => $row['invoice_type'] ?? $detail['invoice_type'] ?? null,
                    'issue_date'     => $detail['issue_date'] ?? null,
                    'tax_date'       => $supplyDate,
                    'currency'       => $row['currency'] ?? $detail['currency'] ?? null,
                    'client_name'    => $row['client_name'] ?? $detail['client_name'] ?? null,
                    'client_vat_id'  => $detail['client_dic'] ?? null,
                    'total_with_vat' => isset($detail['invoice_total']) ? round((float) $detail['invoice_total'], 2) : null,
                ],
                'customer_name'         => $row['client_name'] ?? $detail['client_name'] ?? null,
                // Bod k) — podklad, ze kterého se místo plnění určilo. U nás je to země
                // odběratele a jeho (ne)existující DIČ; nic dalšího systém neshromažďuje,
                // a co se neshromažďuje, se do evidence nepíše.
                'place_evidence'        => [
                    'customer_country'  => $detail['client_country'] ?? null,
                    'customer_vat_id'   => $detail['client_dic'] ?? null,
                    'source'            => 'client_registry',
                    'determined_from'   => 'Země odběratele evidovaná u kontaktu k datu podání.',
                ],
                'completeness'          => $completeness,
                'retain_until'          => self::retainUntil($supplyDate, $this->retentionYears($supplyDate)),
            ];
        }

        return $records;
    }

    /**
     * Údaje položky a dokladu, které náhled nenese (množství, jednotka, DIČ a země
     * odběratele, celková částka dokladu). Jeden dotaz na celé období — evidence za
     * čtvrtletí s tisíci řádky nesmí dělat dotaz na řádek.
     *
     * @param list<int> $itemIds
     * @return array<int, array<string,mixed>>
     */
    private function itemDetails(int $supplierId, array $itemIds): array
    {
        $itemIds = array_values(array_unique(array_filter($itemIds)));
        if ($itemIds === []) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($itemIds), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT ii.id AS item_id, ii.description, ii.quantity, ii.unit, ii.vat_rate_snapshot,
                    ii.total_without_vat, ii.total_vat,
                    ii.oss_supply_type, ii.oss_rate_type, ii.oss_exchange_rate, ii.oss_exchange_rate_date,
                    i.varsymbol AS doc_number, i.invoice_type, i.issue_date,
                    i.total_with_vat AS invoice_total,
                    COALESCE(cur.code, 'CZK') AS currency,
                    c.company_name AS client_name, c.dic AS client_dic,
                    UPPER(TRIM(co.iso2)) AS client_country
               FROM invoice_items ii
               JOIN invoices i ON i.id = ii.invoice_id
               JOIN clients c ON c.id = i.client_id
          LEFT JOIN currencies cur ON cur.id = i.currency_id
          LEFT JOIN countries co ON co.id = c.country_id
              WHERE i.supplier_id = ? AND ii.id IN ({$ph})"
        );
        $stmt->execute([$supplierId, ...$itemIds]);

        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $out[(int) $row['item_id']] = $row;
        }
        return $out;
    }

    /**
     * Bod h) — datum a částka přijatých úhrad. Váže se na DOKLAD, ne na položku:
     * úhrada se v systému páruje na fakturu a rozpad na položky by byl domyšlený.
     *
     * @param list<int> $invoiceIds
     * @return array<int, list<array{paid_on:string, amount:float, currency:string}>>
     */
    private function payments(int $supplierId, array $invoiceIds): array
    {
        $invoiceIds = array_values(array_unique(array_filter($invoiceIds)));
        if ($invoiceIds === [] || !$this->db->hasTable('invoice_payments')) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($invoiceIds), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT invoice_id, paid_on, amount, currency
               FROM invoice_payments
              WHERE supplier_id = ? AND invoice_id IN ({$ph})
           ORDER BY paid_on, id"
        );
        $stmt->execute([$supplierId, ...$invoiceIds]);

        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $out[(int) $row['invoice_id']][] = [
                'paid_on'  => (string) $row['paid_on'],
                'amount'   => round((float) $row['amount'], 2),
                'currency' => (string) $row['currency'],
            ];
        }
        return $out;
    }

    /**
     * Je k tomuhle podání evidence už zapsaná? Write-once tabulka nemá „přepsat", takže
     * druhý běh musí skončit tady, ne až na unikátním klíči.
     */
    private function alreadyCaptured(int $supplierId, int $submissionId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM oss_filing_evidence WHERE supplier_id = ? AND submission_id = ? LIMIT 1'
        );
        $stmt->execute([$supplierId, $submissionId]);

        return $stmt->fetchColumn() !== false;
    }

    private function latestSubmissionId(int $supplierId, int $year, int $quarter): ?int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT submission_id FROM oss_filing_evidence
              WHERE supplier_id = ? AND period_year = ? AND period_quarter = ?
           ORDER BY submission_id DESC LIMIT 1'
        );
        $stmt->execute([$supplierId, $year, $quarter]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (int) $value;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function normalize(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['seq'] = (int) $row['seq'];
        $row['period_year'] = (int) $row['period_year'];
        $row['period_quarter'] = (int) $row['period_quarter'];
        $row['invoice_id'] = $row['invoice_id'] !== null ? (int) $row['invoice_id'] : null;
        $row['invoice_item_id'] = $row['invoice_item_id'] !== null ? (int) $row['invoice_item_id'] : null;
        foreach (['payments_json' => 'payments', 'invoice_snapshot_json' => 'invoice_snapshot',
                  'place_evidence_json' => 'place_evidence', 'completeness_json' => 'completeness'] as $src => $dst) {
            $row[$dst] = $row[$src] !== null ? (json_decode((string) $row[$src], true) ?: []) : [];
            unset($row[$src]);
        }
        return $row;
    }

    private static function json(mixed $value): string
    {
        return json_encode($value ?? [], JSON_UNESCAPED_UNICODE) ?: '[]';
    }
}
