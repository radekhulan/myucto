<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * FR3 (vendor audit 2026-08) — report úplnosti číselné řady VYDANÝCH dokladů.
 * Mezera v řadě je auditní signál pro FÚ (§ 26 odst. 4 ZoÚ — doklady musí být číslovány
 * tak, aby byla zajištěna úplnost). `VarsymbolSeriesCollisionChecker` řeší kolizi ŠABLON
 * (dvě řady vygenerují STEJNÝ VS); tahle třída řeší opačný problém — chybějící ČÍSLO
 * v jinak zdravé řadě. Read-only — nic neopravuje, jen hlásí.
 *
 * Faktury a dobropisy MOHOU sdílet jednu řadu (stejná šablona pro `invoice_number_format`
 * i `credit_note_number_format`) — `VarsymbolGenerator` jim ale drží ODDĚLENÉ countery
 * (`invoice_type` je součástí PK `invoice_counters`); jediné, co je před vzájemnou kolizí
 * chrání, je samoopravná logika v `VarsymbolGenerator::next()`
 * ({@see \MyInvoice\Service\Invoice\VarsymbolGenerator::highestUsedCounter()}), která
 * skenuje `invoices.varsymbol` BEZ ohledu na `invoice_type`. Kontrola úplnosti proto musí
 * dělat totéž: když faktura a dobropis ve stejném scope (supplier-wide, nebo tentýž
 * klient s vlastní šablonou) vyprodukují stejný "digit skeleton"
 * ({@see VarsymbolSeriesCollisionChecker::digitSkeleton()}), sloučit jejich obsazená čísla
 * do JEDNÉ množiny — jinak by report hlásil falešné mezery přesně tam, kde číslo ve
 * skutečnosti použil ten DRUHÝ typ dokladu.
 *
 * Rozsah: report je vždy vázaný na jeden rok (`issue_date`), s výjimkou period='none'
 * (jediný globální counter bez ročního resetu), kde se vždy skenuje CELÁ historie —
 * jinak by report ročním řezem sám vyrobil falešnou mezeru na hranici roku.
 *
 * Dolní hranice rozsahu není konstanta: řada může mít ručně nastavený začátek
 * (`invoice_counters.floor_number`, PUT /api/settings/supplier/invoice-counter) — typicky
 * u přechodu z jiného software, kde se navazuje na rozjetou řadu. Čísla pod ním v tomhle
 * systému nikdy nevznikla, takže je report nesmí hlásit jako chybějící doklady.
 *
 * Scope se sbírá za všechny tři osy číslování, které zná
 * {@see VarsymbolGenerator::resolveTemplateAndPeriod()} — dodavatel, klient s vlastní
 * šablonou a kategorie tržby s vlastní šablonou. K nim případná vlastní řada
 * zjednodušených daňových dokladů firmy (typ `simplified`, migrace 2037).
 *
 * Do KTERÉ řady doklad patří, se ale NEURČUJE podle jeho aktuálního `client_id` /
 * `revenue_category_id`, nýbrž podle toho, jestli jeho VS fakticky odpovídá vzoru té řady.
 * Konfigurace se totiž mění v čase: kategorii tržby přibude vlastní řada uprostřed měsíce,
 * doklad se přeřadí jinam, klientovi se šablona sundá. Přiřazení podle dnešního nastavení
 * by pak zpětně přepsalo historii — doklady vystavené ve staré řadě by ze skenu té řady
 * vypadly (falešná mezera) a v nové řadě by se nezapočítaly, protože jejímu vzoru
 * neodpovídají. Shoda se vzorem je proti tomu imunní a navíc zrcadlí to, co reálně dělá
 * generátor: {@see VarsymbolGenerator::highestUsedCounter()} skenuje `invoices.varsymbol`
 * taky jen podle šablony, napříč celým dodavatelem.
 *
 * Když VS vyhoví vzoru VÍC řad zároveň (dvě kolidující šablony —
 * {@see VarsymbolSeriesCollisionChecker}), rozhodne priorita resolveru
 * (klient > kategorie > dodavatel), ale jen mezi řadami, které jsou s dokladem slučitelné
 * (řada klienta bere jen doklady toho klienta, řada kategorie jen doklady té kategorie).
 * Dodavatelská řada je slučitelná vždy — je to fallback resolveru.
 */
final class InvoiceSeriesCompletenessService
{
    /** Vydané doklady dle FR3 — proforma není daňový doklad, do auditu číselné řady nepatří. */
    private const SCANNED_TYPES = ['invoice', 'credit_note'];

    /** Strop výčtu chybějících čísel v jednom období; `missing_total` zůstává přesné. */
    private const MAX_LISTED_MISSING = 500;

    /** @var array<string, ?string> cache regexů: "template|bucketKey|period" => regex|null */
    private array $regexCache = [];

    /** @var array<string, list<array<string,mixed>>> cache dokladů: "type|from|to" => řádky */
    private array $documentCache = [];

    /** @var array<string,int>|null cache floorů: "client|category|type|period" => floor_number */
    private ?array $floorCache = null;

    public function __construct(
        private readonly Connection $db,
        private readonly Config $config,
        // Kvůli hasFloor() — detekce, jestli je sloupec `floor_number` v schématu. Záměrně
        // se sem netahá druhá vlastní kontrola schématu; guard je pro floor jediné místo.
        private readonly NumberSeriesGapGuard $gapGuard,
    ) {}

    /**
     * @return list<array{
     *   types: list<string>, client_id: int, client_name: ?string,
     *   revenue_category_id: int, revenue_category_name: ?string,
     *   period: string, template_by_type: array<string,string>,
     *   buckets: list<array{
     *     period_key: string, used_count: int, range_from: int, range_to: int,
     *     missing: list<int>, missing_total: int, missing_truncated: bool,
     *     missing_preview: list<string>,
     *   }>,
     * }>
     */
    public function build(int $supplierId, int $year): array
    {
        $this->regexCache = [];
        $this->documentCache = [];
        $this->floorCache = null;

        $scopes = $this->collectScopes($supplierId);
        $groups = $this->groupByDigitSkeleton($scopes);
        foreach ($groups as $i => $group) {
            $groups[$i]['id'] = $i;
        }

        // Pořadí, ve kterém se rozhoduje vlastnictví dokladu při shodě víc vzorů —
        // kopíruje prioritu VarsymbolGenerator::resolveTemplateAndPeriod().
        $byPriority = $groups;
        usort(
            $byPriority,
            static fn (array $a, array $b): int => self::priorityRank($a) <=> self::priorityRank($b),
        );

        $result = [];
        foreach ($groups as $group) {
            $report = $this->buildGroupReport($supplierId, $group, $year, $byPriority);
            if ($report !== null) {
                $result[] = $report;
            }
        }
        array_push($result, ...$this->buildInferredReports($supplierId, $year, $byPriority));
        return $result;
    }

    /**
     * Importované číslo nemusí odpovídat dnešní šabloně. Bezpečně rozpoznatelná
     * řada má rok vystavení přímo v čísle a stejně široký číselný suffix.
     * Rozsah začíná prvním nalezeným číslem, protože import může být jen částečný.
     * Suffix začínající číslem měsíce se vynechá, aby měsíční řada netvořila
     * falešné mezery v roční řadě.
     *
     * @param list<array<string,mixed>> $byPriority
     * @return list<array<string,mixed>>
     */
    private function buildInferredReports(int $supplierId, int $year, array $byPriority): array
    {
        $series = [];
        foreach (self::SCANNED_TYPES as $type) {
            foreach ($this->fetchDocuments($supplierId, $type, (string) $year, 'year') as $row) {
                if ($this->ownerOf($row, $type, $byPriority) !== null) {
                    continue;
                }
                $number = (string) $row['varsymbol'];
                if (preg_match('/^([A-Za-z]{0,4}[-\/]?)((?:19|20)\d{2})([-\/]?)(\d{3,8})$/D', $number, $matches) !== 1
                    || (int) $matches[2] !== (int) substr((string) $row['issue_date'], 0, 4)) {
                    continue;
                }
                if (strlen($matches[4]) >= 4 && preg_match('/^(0[1-9]|1[0-2])/', $matches[4]) === 1) {
                    continue;
                }
                $prefix = $matches[1] . $matches[2] . $matches[3];
                $width = strlen($matches[4]);
                $key = $prefix . '|' . $width;
                $series[$key]['prefix'] = $prefix;
                $series[$key]['width'] = $width;
                $series[$key]['types'][$type] = true;
                $series[$key]['used'][(int) $matches[4]] = true;
            }
        }

        $reports = [];
        foreach ($series as $candidate) {
            $used = $candidate['used'];
            if (count($used) < 2) {
                continue;
            }
            $numbers = array_keys($used);
            $from = min($numbers);
            $to = max($numbers);
            $missingTotal = $to - $from + 1 - count($used);
            $missing = [];
            for ($n = $from; $n <= $to && count($missing) < self::MAX_LISTED_MISSING; $n++) {
                if (!isset($used[$n])) {
                    $missing[] = $n;
                }
            }
            $template = $candidate['prefix'] . '{' . str_repeat('C', $candidate['width']) . '}';
            $types = array_keys($candidate['types']);
            $reports[] = [
                'types' => $types,
                'client_id' => 0,
                'client_name' => null,
                'revenue_category_id' => 0,
                'revenue_category_name' => null,
                'period' => 'year',
                'template_by_type' => array_fill_keys($types, $template),
                'inferred' => true,
                'buckets' => [[
                    'period_key' => (string) $year,
                    'used_count' => count($used),
                    'range_from' => $from,
                    'range_to' => $to,
                    'missing' => $missing,
                    'missing_total' => $missingTotal,
                    'missing_truncated' => $missingTotal > count($missing),
                    'missing_preview' => array_map(
                        static fn (int $n): string => $candidate['prefix'] . str_pad((string) $n, $candidate['width'], '0', STR_PAD_LEFT),
                        $missing,
                    ),
                ]],
            ];
        }
        return $reports;
    }

    /** @param array{client_id:int, revenue_category_id:int} $group */
    private static function priorityRank(array $group): int
    {
        if ($group['client_id'] > 0) {
            return 0;
        }
        return $group['revenue_category_id'] > 0 ? 1 : 2;
    }

    /**
     * Per-scope šablony (supplier-wide + každý klient a každá kategorie tržby s VLASTNÍ
     * šablonou) — stejný princip jako {@see VarsymbolSeriesCollisionChecker::collectSeries()},
     * jen navíc s obdobím (`invoice_number_period`), které report potřebuje pro bucketing.
     *
     * @return list<array{client_id:int, client_name:?string, revenue_category_id:int,
     *                    revenue_category_name:?string, period:string, templates: array<string,string>}>
     */
    private function collectScopes(int $supplierId): array
    {
        $pdo = $this->db->pdo();

        $simplifiedColumn = $this->db->hasColumn('supplier', 'simplified_number_format')
            ? 'simplified_number_format' : 'NULL';
        $stmt = $pdo->prepare(
            "SELECT invoice_number_format, credit_note_number_format, invoice_number_period,
                    {$simplifiedColumn} AS simplified_number_format
               FROM supplier WHERE id = ? LIMIT 1"
        );
        $stmt->execute([$supplierId]);
        $sup = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $supplierTemplates = [];
        foreach (self::SCANNED_TYPES as $type) {
            $tpl = trim((string) ($sup["{$type}_number_format"] ?? ''));
            if ($tpl === '') {
                $tpl = trim((string) $this->config->get("varsymbol.templates.{$type}", ''));
            }
            if ($tpl !== '') {
                $supplierTemplates[$type] = $tpl;
            }
        }
        $supplierPeriod = self::normalizePeriod((string) ($sup['invoice_number_period'] ?? ''));

        $scopes = [];
        if ($supplierTemplates !== []) {
            $scopes[] = [
                'client_id'             => 0,
                'client_name'           => null,
                'revenue_category_id'   => 0,
                'revenue_category_name' => null,
                'period'                => $supplierPeriod,
                'templates'             => $supplierTemplates,
            ];
        }

        // Vlastní řada zjednodušených daňových dokladů (migrace 2037), jen na úrovni firmy,
        // s obdobím firmy, stejně jako ji vyhodnocuje generátor. Bez šablony žádná není.
        $simplifiedTemplate = trim((string) ($sup['simplified_number_format'] ?? ''));
        if ($simplifiedTemplate !== '') {
            $scopes[] = [
                'client_id'             => 0,
                'client_name'           => null,
                'revenue_category_id'   => 0,
                'revenue_category_name' => null,
                'period'                => $supplierPeriod,
                'templates'             => [VarsymbolGenerator::SIMPLIFIED => $simplifiedTemplate],
            ];
        }

        $stmt = $pdo->prepare(
            "SELECT id, company_name, invoice_number_format, credit_note_number_format, invoice_number_period
                 FROM clients
                WHERE supplier_id = ?
                  AND (COALESCE(invoice_number_format, '') <> '' OR COALESCE(credit_note_number_format, '') <> '')"
        );
        $stmt->execute([$supplierId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $cli) {
            $templates = self::templatesFromRow($cli);
            if ($templates === []) {
                continue;
            }
            $scopes[] = [
                'client_id'             => (int) $cli['id'],
                'client_name'           => (string) $cli['company_name'],
                'revenue_category_id'   => 0,
                'revenue_category_name' => null,
                'period'                => self::periodFromRow($cli, $supplierPeriod),
                'templates'             => $templates,
            ];
        }

        $stmt = $pdo->prepare(
            "SELECT id, label, invoice_number_format, credit_note_number_format, invoice_number_period
                 FROM revenue_categories
                WHERE supplier_id = ?
                  AND (COALESCE(invoice_number_format, '') <> '' OR COALESCE(credit_note_number_format, '') <> '')"
        );
        $stmt->execute([$supplierId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $cat) {
            $templates = self::templatesFromRow($cat);
            if ($templates === []) {
                continue;
            }
            $scopes[] = [
                'client_id'             => 0,
                'client_name'           => null,
                'revenue_category_id'   => (int) $cat['id'],
                'revenue_category_name' => (string) $cat['label'],
                'period'                => self::periodFromRow($cat, $supplierPeriod),
                'templates'             => $templates,
            ];
        }

        return $scopes;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,string>
     */
    private static function templatesFromRow(array $row): array
    {
        $templates = [];
        foreach (self::SCANNED_TYPES as $type) {
            $tpl = trim((string) ($row["{$type}_number_format"] ?? ''));
            if ($tpl !== '') {
                $templates[$type] = $tpl;
            }
        }
        return $templates;
    }

    /** @param array<string,mixed> $row */
    private static function periodFromRow(array $row, string $supplierPeriod): string
    {
        return $row['invoice_number_period'] !== null && $row['invoice_number_period'] !== ''
            ? self::normalizePeriod((string) $row['invoice_number_period'])
            : $supplierPeriod;
    }

    /**
     * V rámci KAŽDÉHO scope zvlášť: má-li scope šablonu pro invoice i credit_note a obě
     * vyprodukují stejný digit skeleton, slouč je do jedné logické řady (types=[oba]).
     * Jinak zůstanou jako samostatné řady (jedna skupina na typ).
     *
     * @param list<array{client_id:int, client_name:?string, revenue_category_id:int,
     *                   revenue_category_name:?string, period:string, templates: array<string,string>}> $scopes
     * @return list<array{client_id:int, client_name:?string, revenue_category_id:int,
     *                    revenue_category_name:?string, period:string, types: list<string>,
     *                    template_by_type: array<string,string>}>
     */
    private function groupByDigitSkeleton(array $scopes): array
    {
        $groups = [];
        foreach ($scopes as $scope) {
            $identity = [
                'client_id'             => $scope['client_id'],
                'client_name'           => $scope['client_name'],
                'revenue_category_id'   => $scope['revenue_category_id'],
                'revenue_category_name' => $scope['revenue_category_name'],
                'period'                => $scope['period'],
            ];
            $types = array_keys($scope['templates']);
            if (count($types) === 2) {
                $skeletonA = VarsymbolSeriesCollisionChecker::digitSkeleton($scope['templates'][$types[0]]);
                $skeletonB = VarsymbolSeriesCollisionChecker::digitSkeleton($scope['templates'][$types[1]]);
                if ($skeletonA !== '' && $skeletonA === $skeletonB) {
                    $groups[] = $identity + [
                        'types'            => $types,
                        'template_by_type' => $scope['templates'],
                    ];
                    continue;
                }
            }
            foreach ($scope['templates'] as $type => $tpl) {
                $groups[] = $identity + [
                    'types'            => [$type],
                    'template_by_type' => [$type => $tpl],
                ];
            }
        }
        return $groups;
    }

    /**
     * @param array{id:int, client_id:int, client_name:?string, revenue_category_id:int,
     *               revenue_category_name:?string, period:string, types: list<string>,
     *               template_by_type: array<string,string>} $group
     * @param list<array<string,mixed>> $byPriority všechny řady seřazené dle priority resolveru
     * @return array{types: list<string>, client_id: int, client_name: ?string,
     *               revenue_category_id: int, revenue_category_name: ?string,
     *               period: string, template_by_type: array<string,string>, buckets: list<array<string,mixed>>}|null
     */
    private function buildGroupReport(int $supplierId, array $group, int $year, array $byPriority): ?array
    {
        $bucketKeys = self::bucketKeysFor($group['period'], $year);

        $buckets = [];
        foreach ($bucketKeys as $bucketKey) {
            $used = []; // int => true
            foreach ($group['types'] as $type) {
                $template = $group['template_by_type'][$type];
                $regex = $this->regexFor($template, $bucketKey, $group['period']);
                if ($regex === null) {
                    continue; // šablona bez {C+} — fixní číslo, nedá se "chybět"
                }
                // Sken jde přes VŠECHNY doklady dodavatele v období; do řady patří ty,
                // jejichž VS odpovídá jejímu vzoru a které si nenárokuje řada s vyšší
                // prioritou (viz docblock třídy).
                foreach ($this->fetchDocuments($supplierId, $type, $bucketKey, $group['period']) as $row) {
                    if (preg_match($regex, (string) $row['varsymbol'], $m) !== 1) {
                        continue;
                    }
                    if ($this->ownerOf($row, $type, $byPriority) !== $group['id']) {
                        continue;
                    }
                    $used[(int) $m[1]] = true;
                }
            }
            if ($used === []) {
                continue;
            }
            $max = max(array_keys($used));

            // Řada nemusí začínat jedničkou. Kdo přechází z jiného software s rozjetou
            // řadou, nastaví si ruční začátek (PUT /api/settings/supplier/invoice-counter
            // → `invoice_counters.floor_number`) a čísla pod ním v tomhle systému NIKDY
            // nevznikla — hlásit je jako chybějící doklady je falešný účetní poplach.
            $floor = $this->floorFor($supplierId, $group, $bucketKey);

            // Pojistka proti nekonzistenci: stojí-li floor NAD nejnižším skutečně použitým
            // číslem (floor se nastavil dodatečně, po vystavení starších dokladů), snížíme
            // ho pod ně. Jinak by rozsah vydané číslo vynechal a aritmetika mezer by lhala.
            $floor = min($floor, min(array_keys($used)) - 1);
            $rangeFrom = $floor + 1;

            // Počet mezer je aritmetika, ne výčet: všechna obsazená čísla leží
            // v [$rangeFrom..$max], takže chybí právě $max - $floor - count($used).
            // Díky tomu je celkové číslo správné i tehdy, když se výčet níže usekne.
            $missingTotal = $max - $floor - count($used);

            // Výčet je stropovaný. Jediný doklad s ručně zadaným (nebo importem
            // rozbitým) číslem posune $max o několik řádů a report by pak stavěl
            // statisícipoložkové pole i s vyrenderovanými náhledy — sestava se tím
            // uvaří dřív, než ji stihne někdo přečíst. Useknutí se hlásí ven, aby si
            // uživatel nespletl "prvních 500" s "všechno".
            $missing = [];
            for ($n = $rangeFrom; $n <= $max && count($missing) < self::MAX_LISTED_MISSING; $n++) {
                if (!isset($used[$n])) {
                    $missing[] = $n;
                }
            }
            $previewTemplate = $group['template_by_type'][$group['types'][0]];
            $buckets[] = [
                'period_key'        => $bucketKey,
                'used_count'        => count($used),
                'range_from'        => $rangeFrom,
                'range_to'          => $max,
                'missing'           => $missing,
                'missing_total'     => $missingTotal,
                'missing_truncated' => $missingTotal > count($missing),
                'missing_preview'   => array_map(
                    static fn (int $n): string => self::previewRender($previewTemplate, $bucketKey, $group['period'], $n),
                    $missing,
                ),
            ];
        }

        if ($buckets === []) {
            return null; // scope bez jakýchkoli dokladů v požadovaném roce — nic k hlášení
        }

        // Popisek se ZÁMĚRNĚ neskládá tady (žádný natvrdo český label v API payloadu) —
        // frontend si ho poskládá z `types` + `client_name` přes t() (AGENTS.md i18n).
        return [
            'types'                 => $group['types'],
            'client_id'             => $group['client_id'],
            'client_name'           => $group['client_name'],
            'revenue_category_id'   => $group['revenue_category_id'],
            'revenue_category_name' => $group['revenue_category_name'],
            'period'                => $group['period'],
            'template_by_type'      => $group['template_by_type'],
            'buckets'               => $buckets,
        ];
    }

    /**
     * Ruční začátek řady pro danou skupinu a období — čísla <= floor v systému nikdy
     * nevznikla a do rozsahu úplnosti nepatří.
     *
     * Klíč `invoice_counters` je (supplier_id, client_id, revenue_category_id, invoice_type,
     * period); skupina reportu drží přesně ty čtyři osy (client/kategorie ze scope,
     * `period_key` bucketu = `VarsymbolGenerator::makePeriodKey()`), takže se mapuje 1:1.
     *
     * Sdílí-li skupina jednu řadu pro VÍC typů dokladu (stejný digit skeleton pro fakturu
     * i dobropis), každý typ má v tabulce VLASTNÍ řádek počítadla. Rozsah je ale jeden
     * společný, takže se bere NEJNIŽŠÍ floor: kdyby se vzal nejvyšší, čísla legitimně
     * vydaná tím druhým typem pod ním by z rozsahu vypadla a report by skutečnou mezeru
     * zamlčel. Report smí falešný poplach ztišit, ale nikdy ne skrýt díru.
     *
     * Typ BEZ řádku počítadla se do minima nepočítá — chybějící řádek není „řada začíná
     * od jedničky", je to „o téhle řadě počítadlo nic neví".
     *
     * @param array{client_id:int, revenue_category_id:int, types: list<string>} $group
     */
    private function floorFor(int $supplierId, array $group, string $bucketKey): int
    {
        $floors = $this->floorMap($supplierId);
        if ($floors === []) {
            return 0;
        }

        $found = [];
        foreach ($group['types'] as $type) {
            $key = "{$group['client_id']}|{$group['revenue_category_id']}|{$type}|{$bucketKey}";
            if (isset($floors[$key])) {
                $found[] = $floors[$key];
            }
        }
        return $found === [] ? 0 : min($found);
    }

    /**
     * Všechny ruční začátky řad dodavatele jedním dotazem. Starší schéma sloupec
     * `floor_number` nemá — detekci schématu drží {@see NumberSeriesGapGuard::hasFloor()},
     * aby existovalo jedno místo, které o něm rozhoduje.
     *
     * @return array<string,int>
     */
    private function floorMap(int $supplierId): array
    {
        if ($this->floorCache !== null) {
            return $this->floorCache;
        }
        if (!$this->gapGuard->hasFloor('invoice_counters')) {
            return $this->floorCache = [];
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT client_id, revenue_category_id, invoice_type, period, floor_number
                 FROM invoice_counters WHERE supplier_id = ?'
        );
        $stmt->execute([$supplierId]);

        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $key = ((int) $row['client_id']) . '|' . ((int) $row['revenue_category_id'])
                 . '|' . ((string) $row['invoice_type']) . '|' . ((string) $row['period']);
            $map[$key] = (int) $row['floor_number'];
        }
        return $this->floorCache = $map;
    }

    /**
     * Řada, které doklad PATŘÍ. Bere první řadu v pořadí priority resolveru, která je
     * s dokladem slučitelná a jejímuž vzoru jeho VS odpovídá. Období si každá řada počítá
     * z `issue_date` dokladu podle SVÉHO `period` — dvě řady můžou mít různý reset
     * (měsíční dodavatel vs. roční klient), takže bucket volajícího tu použít nelze.
     *
     * @param array<string,mixed> $row
     * @param list<array<string,mixed>> $byPriority
     */
    private function ownerOf(array $row, string $invoiceType, array $byPriority): ?int
    {
        foreach (self::candidateSeriesTypes($row, $invoiceType) as $type) {
            foreach ($byPriority as $group) {
                if (!in_array($type, $group['types'], true)) {
                    continue;
                }
                // Řada klienta bere jen doklady toho klienta, řada kategorie jen doklady té
                // kategorie; dodavatelská řada (obojí 0) je fallback, ta bere cokoli.
                if ($group['client_id'] > 0 && $group['client_id'] !== (int) $row['client_id']) {
                    continue;
                }
                if ($group['revenue_category_id'] > 0
                    && $group['revenue_category_id'] !== (int) $row['revenue_category_id']) {
                    continue;
                }
                $regex = $this->regexFor(
                    $group['template_by_type'][$type],
                    self::bucketKeyForDate((string) $row['issue_date'], $group['period']),
                    $group['period'],
                );
                if ($regex !== null && preg_match($regex, (string) $row['varsymbol']) === 1) {
                    return (int) $group['id'];
                }
            }
        }
        return null; // VS neodpovídá žádné známé řadě (ruční číslo, import) — nikam se nepočítá
    }

    /**
     * Řady, do kterých faktura může patřit, v pořadí, ve kterém se zkouší. Faktura
     * se může ocitnout v řadě faktur i v řadě zjednodušených dokladů (migrace 2037):
     * číslo dostala dřív, než firma řadu zavedla, nebo jí někdo příznak `is_simplified`
     * přepnul až po vystavení. Rozhoduje tvar čísla jako všude jinde v reportu; příznak
     * jen určí, která z obou řad má přednost, kdyby VS vyhověl oběma vzorům.
     *
     * @param array<string,mixed> $row
     * @return list<string>
     */
    private static function candidateSeriesTypes(array $row, string $invoiceType): array
    {
        if (($row['invoice_type'] ?? null) !== 'invoice'
            || !in_array($invoiceType, ['invoice', VarsymbolGenerator::SIMPLIFIED], true)
        ) {
            return [$invoiceType];
        }
        return !empty($row['is_simplified'])
            ? [VarsymbolGenerator::SIMPLIFIED, 'invoice']
            : ['invoice', VarsymbolGenerator::SIMPLIFIED];
    }

    /**
     * Doklady dodavatele daného typu v období — bez jakéhokoli filtru na klienta či
     * kategorii; příslušnost k řadě řeší až {@see ownerOf()} podle tvaru VS.
     *
     * @return list<array<string,mixed>>
     */
    private function fetchDocuments(int $supplierId, string $invoiceType, string $bucketKey, string $period): array
    {
        [$from, $to] = self::bucketDateRange($bucketKey, $period);

        // Cache je na (typ, rozsah dat), ne na scope — víc řad se stejným obdobím tak
        // sdílí jediný dotaz místo jednoho na každou z nich.
        $cacheKey = "{$invoiceType}|{$from}|{$to}";
        if (isset($this->documentCache[$cacheKey])) {
            return $this->documentCache[$cacheKey];
        }

        // Řada faktur nese i doklady bez vlastní řady (daňový doklad k platbě, penalizace,
        // platební kalendář); jejich čísla jsou v řadě obsazená stejně jako faktury.
        // Řada zjednodušených dokladů čísluje jen faktury; které z nich do ní patří,
        // rozhodne tvar čísla ({@see ownerOf()}), ne příznak.
        $types = $invoiceType === VarsymbolGenerator::SIMPLIFIED
            ? ['invoice']
            : VarsymbolGenerator::documentTypesInSeries($invoiceType);
        $in = implode(',', array_fill(0, count($types), '?'));
        $simplifiedColumn = $this->db->hasColumn('invoices', 'is_simplified') ? 'is_simplified' : '0 AS is_simplified';
        $stmt = $this->db->pdo()->prepare(
            "SELECT varsymbol, invoice_type, {$simplifiedColumn}, client_id, revenue_category_id, issue_date
                 FROM invoices
                WHERE supplier_id = ? AND invoice_type IN ($in)
                  AND varsymbol IS NOT NULL AND varsymbol <> ''
                  AND calendar_id IS NULL
                  AND issue_date >= ? AND issue_date <= ?"
        );
        $stmt->execute([$supplierId, ...$types, $from, $to]);

        return $this->documentCache[$cacheKey] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** Memoizovaný {@see counterRegex()} — týž vzor se testuje na každý doklad v období. */
    private function regexFor(string $template, string $bucketKey, string $period): ?string
    {
        $cacheKey = "{$template}|{$bucketKey}|{$period}";
        if (!array_key_exists($cacheKey, $this->regexCache)) {
            $this->regexCache[$cacheKey] = self::counterRegex($template, $bucketKey, $period);
        }
        return $this->regexCache[$cacheKey];
    }

    /** Bucket, do kterého doklad s daným `issue_date` spadá v řadě s daným obdobím. */
    private static function bucketKeyForDate(string $issueDate, string $period): string
    {
        return match ($period) {
            'year'  => substr($issueDate, 0, 4),
            'month' => substr($issueDate, 0, 4) . substr($issueDate, 5, 2),
            default => 'ALL',
        };
    }

    /** @return array{0:string,1:string} [issue_date od, issue_date do] pro daný bucket. */
    private static function bucketDateRange(string $bucketKey, string $period): array
    {
        return match ($period) {
            'year'  => ["{$bucketKey}-01-01", "{$bucketKey}-12-31"],
            'month' => [
                substr($bucketKey, 0, 4) . '-' . substr($bucketKey, 4, 2) . '-01',
                (new \DateTimeImmutable(substr($bucketKey, 0, 4) . '-' . substr($bucketKey, 4, 2) . '-01'))
                    ->modify('last day of this month')->format('Y-m-d'),
            ],
            default => ['0001-01-01', '9999-12-31'], // 'none' — celá historie
        };
    }

    /** @return list<string> */
    private static function bucketKeysFor(string $period, int $year): array
    {
        return match ($period) {
            'year'  => [(string) $year],
            'month' => array_map(static fn (int $m): string => sprintf('%04d%02d', $year, $m), range(1, 12)),
            default => ['ALL'], // 'none' — jeden globální bucket bez ohledu na $year
        };
    }

    private static function normalizePeriod(string $period): string
    {
        return in_array($period, ['year', 'month', 'none'], true) ? $period : 'month';
    }

    /**
     * Regex pro vytažení counteru z varsymbolu — zrcadlí
     * {@see VarsymbolGenerator::buildCounterMatcher()}, jen s bucketKey/period místo
     * konkrétního data (report pracuje po obdobích, ne po jednotlivých dnech) a
     * s wildcard fallbackem pro edge-case `period='none'` + datový placeholder v šabloně
     * (counter se nikdy nereseduje, takže rok/měsíc v šabloně nejde vázat na bucket).
     */
    private static function counterRegex(string $template, string $bucketKey, string $period): ?string
    {
        if (!preg_match('/\{C+\}/', $template)) {
            return null;
        }

        [$year, $month] = self::bucketYearMonth($bucketKey, $period);

        $parts = preg_split(
            '/(\{(?:YYYY|YY|MM)(?:[+-]\d{1,3})?\}|\{C+\})/',
            $template,
            -1,
            PREG_SPLIT_DELIM_CAPTURE,
        ) ?: [$template];

        $regex = '';
        foreach ($parts as $part) {
            if (preg_match('/^\{C+\}$/', $part) === 1) {
                $regex .= '(\d+)';
                continue;
            }
            if (preg_match(InvoiceNumberFormat::DATE_TOKEN_RE, $part, $m) === 1 && $m[0] === $part) {
                // Období nefixuje rok (period='none') nebo měsíc (period='year') → wildcard
                // o šířce tokenu; posun na šířku nemá vliv.
                $value = InvoiceNumberFormat::tokenValue($m[1], (int) ($m[2] ?? 0), $year, $month);
                $regex .= $value !== null
                    ? preg_quote($value, '/')
                    : '\d{' . InvoiceNumberFormat::tokenWidth($m[1]) . '}';
                continue;
            }
            $regex .= preg_quote($part, '/');
        }
        return '/^' . $regex . '$/';
    }

    /**
     * Rok/měsíc, které dané období fixuje. `null` = období hodnotu neurčuje, takže
     * ji regex nesmí zadrátovat (roční řada nezná měsíc, `none` nezná ani rok).
     *
     * @return array{0: ?int, 1: ?int}
     */
    private static function bucketYearMonth(string $bucketKey, string $period): array
    {
        return match ($period) {
            'year'  => [(int) $bucketKey, null],
            'month' => [(int) substr($bucketKey, 0, 4), (int) substr($bucketKey, 4, 2)],
            default => [null, null],
        };
    }

    /** Náhled chybějícího čísla vyrenderovaný přes stejnou šablonu (pro čitelnost v UI). */
    private static function previewRender(string $template, string $bucketKey, string $period, int $counter): string
    {
        [$year, $month] = self::bucketYearMonth($bucketKey, $period);
        $date = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year ?? 0, $month ?? 1));

        $rendered = InvoiceNumberFormat::expandDateTokens($template, $date);
        return preg_replace_callback('/\{(C+)\}/', static function (array $m) use ($counter): string {
            return str_pad((string) $counter, strlen($m[1]), '0', STR_PAD_LEFT);
        }, $rendered) ?? $rendered;
    }
}
