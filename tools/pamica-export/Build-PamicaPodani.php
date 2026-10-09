<?php

declare(strict_types=1);

/**
 * Z JSON korpusu (Export-PamicaPodani.ps1) složí XML podání v oficiálním formátu ČSSZ,
 * každé zvaliduje proti připnutému XSD a zapíše index.csv, xml/VALIDATION.md a slovník
 * atributů raw/attribute-ids.json.
 *
 * Použití:
 *   php Build-PamicaPodani.php --corpus <složka s raw\> [--xsd <api\xsd>] [--only JMHZ,REGZEC25,NEMPRI25,HZUPN20,ELDP]
 *
 * Skript je přenositelný (Windows, Linux, Docker); potřebuje jen PHP s ext-dom. Do XML jde jen to, co
 * je v databázi (atributové bloby podle "(ID n)" v XSD, strukturované sloupce podle názvů).
 * Co v databázi není, se nezapíše a chybějící povinný prvek ukáže validace.
 */

require_once __DIR__ . '/PamicaXsdWriter.php';

$options = getopt('', ['corpus:', 'xsd:', 'only:']);
$corpus = isset($options['corpus']) ? rtrim((string) $options['corpus'], '\\/') : '';
if ($corpus === '' || !is_dir($corpus . '/raw')) {
    fwrite(STDERR, "Použití: php Build-PamicaPodani.php --corpus <složka s raw> [--xsd <api/xsd>] [--only TYP,TYP]\n");
    exit(2);
}
$xsdRoot = isset($options['xsd']) ? rtrim((string) $options['xsd'], '\\/') : dirname(__DIR__, 2) . '/api/xsd';
$only = isset($options['only']) ? array_map('trim', explode(',', (string) $options['only'])) : [];

/** @return list<array<string,mixed>> */
function raw(string $corpus, string $table): array
{
    $file = $corpus . '/raw/' . $table . '.json';
    if (!is_file($file) || filesize($file) === 0) {
        return [];
    }
    $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    return is_array($data) ? $data : [];
}

/** @param list<array<string,mixed>> $rows @return array<int|string,list<array<string,mixed>>> */
function groupBy(array $rows, string $column): array
{
    $out = [];
    foreach ($rows as $row) {
        $out[$row[$column] ?? ''][] = $row;
    }
    return $out;
}

/** @param array<string,mixed>|null $blob @return array<int,list<array{value:string,order:int,section:int,flag:int}>> */
function blobItems(?array $blob): array
{
    $out = [];
    foreach (($blob['items'] ?? []) as $item) {
        $out[(int) $item['id']][] = [
            'value' => (string) $item['value'],
            'order' => (int) $item['order'],
            'section' => (int) $item['section'],
            'flag' => (int) $item['flag'],
        ];
    }
    return $out;
}

/**
 * Položky blobu rozdělené po blocích `order2`. Věta měsíčního hlášení (MHitems) nese v jednom blobu
 * všechna souběžná PPV osoby: blok 0 je primární PPV se souhrnnými daty osoby, každý další blok
 * samostatný formulář osoby (typicky souběžná DPP). Bez rozdělení by se zapsal jen blok 0.
 *
 * @param array<string,mixed>|null $blob
 * @return array<int,array<int,list<array{value:string,order:int,section:int,flag:int}>>>
 */
function blobBlocks(?array $blob): array
{
    $blocks = [];
    foreach (($blob['items'] ?? []) as $item) {
        $blocks[(int) ($item['order2'] ?? 0)]['items'][] = $item;
    }
    ksort($blocks);
    return array_map(fn (array $block): array => blobItems($block), $blocks);
}

/**
 * @param array<int,array{occurrences:int,nonempty:int}> $seen
 * @param array<int,list<array{value:string,order:int,section:int,flag:int}>> $items
 */
function noteSeen(array &$seen, array $items): void
{
    foreach ($items as $id => $list) {
        foreach ($list as $item) {
            $seen[$id]['occurrences'] = ($seen[$id]['occurrences'] ?? 0) + 1;
            $seen[$id]['nonempty'] = ($seen[$id]['nonempty'] ?? 0) + ($item['value'] !== '' ? 1 : 0);
        }
    }
}

final class PamicaIdResolver implements PamicaValueResolver
{
    /** @var array<int,int> ID atributu => kolikrát se hodnota dostala do XML */
    public static array $used = [];

    /**
     * @param array<int,list<array{value:string,order:int,section:int,flag:int}>> $items
     * @param array<int,string> $derived
     * @param array<string,list<PamicaValueResolver>> $repeat
     */
    public function __construct(
        private readonly array $items,
        private readonly ?self $parent = null,
        private readonly array $derived = [],
        private array $repeat = [],
        private readonly int $order = 0,
    ) {}

    /** @param array<string,list<PamicaValueResolver>> $repeat */
    public function withRepeat(array $repeat): self
    {
        $this->repeat = $repeat;
        return $this;
    }

    public function value(PamicaXsdNode $node, array $path): ?string
    {
        foreach ($node->ids as $id) {
            if (isset($this->derived[$id])) {
                return $this->derived[$id];
            }
            foreach ($this->items[$id] ?? [] as $item) {
                if ($item['order'] === $this->order && $item['value'] !== '') {
                    self::$used[$id] = (self::$used[$id] ?? 0) + 1;
                    return $item['value'];
                }
            }
            if ($this->order !== 0) {
                foreach ($this->items[$id] ?? [] as $item) {
                    if ($item['order'] === 0 && $item['value'] !== '') {
                        self::$used[$id] = (self::$used[$id] ?? 0) + 1;
                        return $item['value'];
                    }
                }
            }
        }
        return $this->parent?->value($node, $path);
    }

    public function instances(PamicaXsdNode $node, array $path): ?array
    {
        if (isset($this->repeat[$node->name])) {
            return $this->repeat[$node->name];
        }
        // `order` je 0-based pořadí položky v opakované skupině (u dětí 0, 1, 2). Skupinu tvoří ID, která mají
        // v této větě aspoň jednu položku s order > 0; instance jsou pak všechna order těchto ID včetně 0.
        // Věta s jediným dítětem (jen order 0) se bere jako jedna instance bez opakování.
        $orders = [];
        foreach (self::subtreeIds($node) as $id) {
            $list = $this->items[$id] ?? [];
            if (array_filter($list, fn (array $i): bool => $i['order'] > 0) === []) {
                continue;
            }
            foreach ($list as $item) {
                $orders[$item['order']] = true;
            }
        }
        if ($orders === []) {
            return null;
        }
        ksort($orders);
        return array_map(fn (int $k): self => new self($this->items, $this, [], [], $k), array_keys($orders));
    }

    public function choice(array $names, array $path): ?string
    {
        $marker = $this->items[1][0]['value'] ?? null;
        if ($marker !== null && in_array($marker, $names, true)) {
            return $marker;
        }
        return $this->parent?->choice($names, $path);
    }

    /** @return list<int> */
    private static function subtreeIds(PamicaXsdNode $node, int $depth = 0): array
    {
        $ids = $node->ids;
        if ($depth > 30) {
            return $ids;
        }
        foreach ($node->attrs as $a) {
            array_push($ids, ...$a->ids);
        }
        foreach ($node->particles as $p) {
            array_push($ids, ...self::particleIds($p, $depth + 1));
        }
        return $ids;
    }

    /** @return list<int> */
    private static function particleIds(PamicaXsdParticle $p, int $depth): array
    {
        $ids = $p->node !== null ? self::subtreeIds($p->node, $depth) : [];
        foreach ($p->items as $i) {
            array_push($ids, ...self::particleIds($i, $depth));
        }
        return $ids;
    }
}

final class PamicaPathResolver implements PamicaValueResolver
{
    /**
     * @param array<string,string|null> $values
     * @param array<string,list<PamicaPathResolver>> $lists
     * @param (callable(list<string>,list<string>):?string)|null $chooser
     */
    public function __construct(
        private readonly array $values,
        private readonly array $lists = [],
        private $chooser = null,
    ) {}

    public function value(PamicaXsdNode $node, array $path): ?string
    {
        for ($k = count($path); $k >= 1; $k--) {
            $key = implode('.', array_slice($path, -$k));
            if (array_key_exists($key, $this->values)) {
                return $this->values[$key];
            }
        }
        return null;
    }

    public function instances(PamicaXsdNode $node, array $path): ?array
    {
        for ($k = count($path); $k >= 1; $k--) {
            $key = implode('.', array_slice($path, -$k));
            if (array_key_exists($key, $this->lists)) {
                return $this->lists[$key];
            }
        }
        return null;
    }

    public function choice(array $names, array $path): ?string
    {
        return $this->chooser === null ? null : ($this->chooser)($names, $path);
    }
}

/** Hodnota sloupce jako text pro XML (bool -> true/false, datum bez času, prázdné = null). */
function cell(array $row, string $column, bool $dateOnly = false): ?string
{
    $v = $row[$column] ?? null;
    if ($v === null || $v === '') {
        return null;
    }
    if (is_bool($v)) {
        return $v ? 'true' : 'false';
    }
    if (is_float($v) || is_int($v)) {
        return rtrim(rtrim(sprintf('%.6F', $v), '0'), '.');
    }
    $s = (string) $v;
    if ($dateOnly || preg_match('/^\d{4}-\d{2}-\d{2}T00:00:00$/', $s) === 1) {
        return substr($s, 0, 10);
    }
    return $s;
}

/**
 * @param array<string,string> $map klíč => sloupec
 * @return array<string,string|null>
 */
function mapped(array $row, array $map): array
{
    $out = [];
    foreach ($map as $key => $column) {
        $value = cell($row, $column);
        // Rodné číslo se do XML posílá bez lomítka (vzor [0-9]*).
        if ($value !== null && preg_match('/(^|\.)(rodneCislo|rodCislo)$/', $key) === 1) {
            $value = preg_replace('/\D/', '', $value);
        }
        $out[$key] = $value;
    }
    return $out;
}

/** @return array{0:bool,1:list<string>} */
function validateXml(string $xmlFile, string $xsdFile): array
{
    $dom = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    libxml_clear_errors();
    $loaded = $dom->load($xmlFile);
    $errors = [];
    $valid = false;
    if ($loaded) {
        $valid = $dom->schemaValidate($xsdFile);
    }
    foreach (libxml_get_errors() as $error) {
        $errors[] = trim($error->message);
    }
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    return [$valid, $errors];
}

/**
 * Kontrola "jinak platné": do kopie v paměti se doplní zástupné hodnoty za povinné prvky, které databáze
 * PAMICA vůbec nenese (viz README, chybějící údaje), a XML se zvaliduje znovu. Na disk se nic z toho nezapisuje;
 * úspěch jen dokládá, že zbytek struktury a hodnot odpovídá XSD.
 *
 * @return bool|null null = pro tento typ žádná sonda není
 */
function probeValidate(string $xmlFile, string $xsdFile, string $type): ?bool
{
    $dom = new DOMDocument();
    if (!$dom->load($xmlFile)) {
        return false;
    }
    if ($type === 'NEMPRI25') {
        foreach ($dom->getElementsByTagNameNS('*', 'zamestnani') as $element) {
            if ($element->getElementsByTagNameNS('*', 'druhCinnosti')->length === 0) {
                $element->appendChild($dom->createElementNS($element->namespaceURI, 'druhCinnosti', '1'));
            }
        }
        // PPM bez dne nástupu (NEMPRIpol.OdeDne prázdné): zadostODavku nemá jediný údaj a nezapíše se.
        foreach ($dom->getElementsByTagNameNS('*', 'ppm') as $element) {
            if ($element->getElementsByTagNameNS('*', 'zadostODavku')->length === 0) {
                $request = $dom->createElementNS($element->namespaceURI, 'zadostODavku');
                $request->appendChild($dom->createElementNS($element->namespaceURI, 'odeDne', '2000-01-01'));
                $element->appendChild($request);
            }
        }
    } elseif ($type === 'HZUPN20') {
        foreach ($dom->getElementsByTagNameNS('*', 'dokument') as $element) {
            $ns = $element->namespaceURI;
            $first = $element->firstChild;
            foreach (['hlasOsoby', 'hlasZamest'] as $name) {
                $element->insertBefore($dom->createElementNS($ns, $name, 'A'), $first);
                $first = $element->firstChild;
            }
        }
    } else {
        return null;
    }
    $previous = libxml_use_internal_errors(true);
    $ok = $dom->schemaValidate($xsdFile);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    return $ok;
}

function ymd(?string $iso): string
{
    return $iso === null || $iso === '' ? '' : substr($iso, 0, 10);
}

/** Zpráva validátoru bez čísel řádků a jmenných prostorů, aby šlo sčítat stejné chyby. */
function normalizeError(string $message): string
{
    $message = preg_replace('/\{[^}]*\}/', '', $message) ?? $message;
    // Hodnoty z databáze (osobní údaje) ve zprávách validátoru nechceme a vzor facetu je zbytečně dlouhý.
    $message = preg_replace("/The value '[^']*'/u", "The value '...'", $message) ?? $message;
    $message = preg_replace("/pattern '[^']*'/u", "pattern '...'", $message) ?? $message;
    $message = preg_replace('/^Element\s+/', '', $message) ?? $message;
    return preg_replace('/\s+/', ' ', $message) ?? $message;
}

// ---------------------------------------------------------------------------------------------

$schemas = [
    'JMHZ' => ['xsd' => '/jmhz/jmhz-1.4.3.6/jmhzPodani.xsd', 'root' => 'jmhz', 'ns' => 'http://schemas.cssz.cz/JMHZ/podani/1.0', 'dir' => 'JMHZ'],
    'REGZEC25' => ['xsd' => '/jmhz/regzec-1.4.0.4/REGZEC25.xsd', 'root' => 'REGZEC', 'ns' => 'http://schemas.cssz.cz/REGZEC/2025', 'dir' => 'REGZEC25'],
    'NEMPRI25' => ['xsd' => '/cssz/nempri25-1.0/NEMPRI25.xsd', 'root' => 'NEMPRI', 'ns' => 'http://schemas.cssz.cz/nem/NEMPRI25', 'dir' => 'NEMPRI25'],
    'HZUPN20' => ['xsd' => '/cssz/hzupn20-1.2/HZUPN20 v1.2.xsd', 'root' => 'PodaniHZUPN', 'ns' => 'http://schemas.cssz.cz/nem/HZUPN20', 'dir' => 'HZUPN20'],
];

/** @var list<array<string,mixed>> $index */
$index = [];
/** @var array<string,list<array{file:string,valid:bool,errors:list<string>}>> $validation */
$validation = [];
/** @var array<string,array<string,int>> $errorCounts */
$errorCounts = [];

$messages = [];
foreach (raw($corpus, 'DataBoxSent') as $m) {
    if (($m['RelAgID'] ?? null) !== null && ($m['RefID'] ?? null) !== null) {
        $messages[(int) $m['RelAgID']][(int) $m['RefID']][] = $m;
    }
}
const AGENDA = ['ELDP' => 108, 'NEMPRI25' => 128, 'ONZ' => 155, 'HZUPN20' => 166, 'REGZEC25' => 188, 'JMHZ' => 190];

function wanted(array $only, string $type): bool
{
    return $only === [] || in_array($type, $only, true);
}

/**
 * @param array<string,mixed> $header
 * @param list<array<string,mixed>> $xmlFiles relativní cesty
 */
function addIndex(array &$index, array $messages, string $type, string $kind, string $period, array $header, int $sentences, array $xmlFiles, array $validResults, string $statusField): void
{
    $id = (int) $header['ID'];
    $linked = $messages[AGENDA[$type] ?? -1][$id] ?? [];
    $status = $header[$statusField] ?? null;
    // Stav dokladu PAMICA (RelStavDP / RefStavDP): 7 = přijato, 1 = rozpracováno; jiné kódy nemají v databázi číselník.
    $label = match ($status) {
        7 => 'accepted',
        1 => 'in_progress',
        default => 'code_' . (string) $status,
    };
    $index[] = [
        'type' => $type,
        'kind' => $kind,
        'period' => $period,
        'submission_id' => $id,
        'sentences' => $sentences,
        'status' => $label,
        'status_code' => $status,
        'submitted' => ymd($header['DatPod'] ?? null),
        'accepted_on' => ymd($header['DatPrij'] ?? null),
        'databox_message_ids' => implode('|', array_map(fn (array $m): string => (string) ($m['MsgID'] ?? ''), $linked)),
        'databox_status' => implode('|', array_map(fn (array $m): string => (string) ($m['StatusIsds'] ?? ''), $linked)),
        'receipt' => array_filter($linked, fn (array $m): bool => ($m['dorucenkaSoubor'] ?? null) !== null) !== [] ? 'yes' : 'no',
        'xml_file' => implode('|', $xmlFiles),
        'valid' => $validResults === [] ? '' : (in_array('no', $validResults, true) ? 'no' : (in_array('gaps_only', $validResults, true) ? 'gaps_only' : 'yes')),
    ];
}

/** @param array{xsd:string,root:string,ns:string,dir:string} $cfg */
function modelFor(array $cfg, string $xsdRoot): array
{
    $model = new PamicaXsdModel();
    $file = $xsdRoot . $cfg['xsd'];
    $model->load($file);
    $root = $model->globalElement($cfg['ns'], $cfg['root']);
    $prefixes = [$cfg['ns'] => ''];
    foreach ($model->rootPrefixes($file) as $prefix => $ns) {
        if ($prefix !== '' && $ns !== $cfg['ns'] && $ns !== PamicaXsdModel::XS && !isset($prefixes[$ns]) && str_contains($ns, 'cssz.cz/JMHZ')) {
            $prefixes[$ns] = $prefix;
        }
    }
    $schemaVersion = '';
    $doc = new DOMDocument();
    $doc->load($file);
    $schemaVersion = $doc->documentElement->getAttribute('version');
    return [$model, $root, new PamicaXsdWriter($prefixes), $schemaVersion, $file];
}

function saveXml(DOMDocument $doc, string $corpus, string $dir, string $name): string
{
    $relative = 'xml/' . $dir . '/' . $name;
    if (!is_dir($corpus . '/xml/' . $dir)) {
        mkdir($corpus . '/xml/' . $dir, 0777, true);
    }
    $doc->save($corpus . '/' . $relative);
    return $relative;
}

/**
 * @param array<string,array<string,list<array<string,mixed>>>> $validation
 * @param array<string,array<string,int>> $errorCounts
 */
function recordValidation(array &$validation, array &$errorCounts, string $type, string $relative, string $corpus, string $xsd): string
{
    [$valid, $errors] = validateXml($corpus . '/' . $relative, $xsd);
    $probe = $valid ? null : probeValidate($corpus . '/' . $relative, $xsd, $type);
    $validation[$type][] = ['file' => $relative, 'valid' => $valid, 'errors' => $errors, 'probe' => $probe];
    foreach (array_unique(array_map('normalizeError', $errors)) as $e) {
        $errorCounts[$type][$e] = ($errorCounts[$type][$e] ?? 0) + 1;
    }
    return $valid ? 'yes' : ($probe === true ? 'gaps_only' : 'no');
}

$attributeReport = [];

// --- JMHZ -----------------------------------------------------------------------------------
if (wanted($only, 'JMHZ')) {
    $cfg = $schemas['JMHZ'];
    [$model, $root, $writer, $version, $xsdFile] = modelFor($cfg, $xsdRoot);
    $items = groupBy(raw($corpus, 'MHitems'), 'RefAg');
    $registrations = raw($corpus, 'RegZAMitems');
    $vs = [];
    foreach ($registrations as $r) {
        foreach (blobItems($r['Data'] ?? null)[10221] ?? [] as $i) {
            $vs[$i['value']] = true;
        }
    }
    $employerVs = count($vs) === 1 ? (string) array_key_first($vs) : null;
    $attributeReport['JMHZ'] = ['dictionary' => $model->idMap($root), 'seen' => [], 'used' => []];
    PamicaIdResolver::$used = [];

    foreach (raw($corpus, 'MH') as $header) {
        $id = (int) $header['ID'];
        $summaryItems = blobItems($header['DataAll'] ?? null);
        $derived = [
            10007 => ((int) ($header['RelTyp'] ?? 1)) === 2 ? 'O' : 'R',
            10010 => (string) (int) $header['RelMesic'],
            10011 => (string) (int) $header['Rok'],
        ];
        if (($header['DatPod'] ?? null) !== null) {
            $derived[10005] = (string) $header['DatPod'];
        }
        if ($employerVs !== null) {
            $derived[10221] = $employerVs;
        }
        $summary = new PamicaIdResolver($summaryItems, null, $derived);
        $people = [];
        foreach ($items[$id] ?? [] as $row) {
            foreach (blobBlocks($row['Data'] ?? null) as $personItems) {
                noteSeen($attributeReport['JMHZ']['seen'], $personItems);
                $people[] = new PamicaIdResolver($personItems, $summary);
            }
        }
        noteSeen($attributeReport['JMHZ']['seen'], $summaryItems);
        $summary->withRepeat(['formularOsoby' => $people]);
        $doc = $writer->write($root, $summary, ['verze' => $version]);
        $period = sprintf('%04d-%02d', (int) $header['Rok'], (int) $header['RelMesic']);
        $relative = saveXml($doc, $corpus, $cfg['dir'], $period . '_' . $id . '.xml');
        $valid = recordValidation($validation, $errorCounts, 'JMHZ', $relative, $corpus, $xsdRoot . $cfg['xsd']);
        addIndex($index, $messages, 'JMHZ', ((int) ($header['RelTyp'] ?? 1)) === 2 ? 'O' : 'R', $period, $header, count($people), [$relative], [$valid], 'RelStavDP');
    }
    $attributeReport['JMHZ']['used'] = PamicaIdResolver::$used;
}

// --- REGZEC25 -------------------------------------------------------------------------------
if (wanted($only, 'REGZEC25')) {
    $cfg = $schemas['REGZEC25'];
    [$model, $root, $writer, $version, $xsdFile] = modelFor($cfg, $xsdRoot);
    $items = groupBy(raw($corpus, 'RegZAMitems'), 'RefAg');
    $attributeReport['REGZEC25'] = ['dictionary' => $model->idMap($root), 'seen' => [], 'used' => []];
    PamicaIdResolver::$used = [];
    /** Druh věty PAMICA (RegZAMitems.RelTyp): 1 přihláška při nástupu, 3 registrace trvajícího vztahu (obojí act 1), 2 odhláška (act 2). */
    $actByType = [1 => 1, 3 => 1, 2 => 2];

    foreach (raw($corpus, 'RegZAM') as $header) {
        $id = (int) $header['ID'];
        $employees = [];
        $acts = [];
        $position = 0;
        foreach ($items[$id] ?? [] as $row) {
            $position++;
            $act = $actByType[(int) ($row['RelTyp'] ?? 0)] ?? null;
            $derived = [10014 => (string) ($row['Sqnr'] ?? $position)];
            if ($act !== null) {
                $derived[10008] = (string) $act;
                $acts[$act] = true;
            }
            $created = ymd($row['DatCreate'] ?? null);
            if ($created !== '') {
                $derived[10005] = $created;
            }
            $blob = blobItems($row['Data'] ?? null);
            noteSeen($attributeReport['REGZEC25']['seen'], $blob);
            $employees[] = new PamicaIdResolver($blob, null, $derived);
        }
        $rootResolver = (new PamicaIdResolver([]))->withRepeat(['employee' => $employees]);
        $doc = $writer->write($root, $rootResolver, ['version' => $version]);
        ksort($acts);
        $date = ymd($header['DatPod'] ?? null) ?: ymd($header['DatCreate'] ?? null);
        $relative = saveXml($doc, $corpus, $cfg['dir'], $date . '_' . $id . '.xml');
        $valid = recordValidation($validation, $errorCounts, 'REGZEC25', $relative, $corpus, $xsdRoot . $cfg['xsd']);
        addIndex($index, $messages, 'REGZEC25', 'act' . implode('+', array_keys($acts)), $date, $header, count($employees), [$relative], [$valid], 'RelStavDP');
    }
    $attributeReport['REGZEC25']['used'] = PamicaIdResolver::$used;
}

// --- NEMPRI25 -------------------------------------------------------------------------------
$nempriMap = [
    '@poradoveCislo' => 'PoradCis',
    'dokument.kodOSSZ' => 'KodOSSZ', 'dokument.druhDavky' => 'DruhDavky', 'dokument.opravnePodani' => 'JeOpravne', 'dokument.zahranicni' => 'Zahranici',
    'pojistenec.jmeno' => 'Jmeno', 'pojistenec.prijmeni' => 'Prijmeni', 'pojistenec.rodneCislo' => 'RodCisl',
    'zamestnani.VSZamestnavatel' => 'VSZam', 'zamestnani.ICZamestnavatel' => 'ICZam', 'zamestnani.nazevZamestnavatel' => 'JmZam',
    'zamestnani.zamestnanOd' => 'ZamOd', 'zamestnani.zamestnanDo' => 'ZamDo',
    'rozhodneObdobi.rozhodneObdobiOd' => 'RozObdOd', 'rozhodneObdobi.rozhodneObdobiDo' => 'RozObdDo', 'rozhodneObdobi.pravdepodobnaVysePrijmu' => 'PravVysPrij',
    'dalsiSdeleni' => 'Sdeleni',
    'kontaktPracovnik.kontaktniPracovnik' => 'KonPrac', 'kontaktPracovnik.telefon' => 'KontTel', 'kontaktPracovnik.email' => 'KontEmail',
    'potvrzeniZamestnavatele.pracoval' => 'Pracoval', 'potvrzeniZamestnavatele.pocetOdpracovanychHodin' => 'PocOdHod',
    'potvrzeniZamestnavatele.pracovniDoba' => 'PracDob', 'potvrzeniZamestnavatele.prijemMalyRozsah' => 'KcPrijMR',
    'potvrzeniZamestnavatele.pobiraDuchod' => 'PobiraDuch', 'potvrzeniZamestnavatele.druhDuchodu' => 'DruhDuch',
    'potvrzeniZamestnavatele.jeStudentem' => 'JeStudent', 'potvrzeniZamestnavatele.spadaDoPrazdnin' => 'SpadaDoPrazd',
    'potvrzeniZamestnavatele.dobaVolnaPrvniZamestnani' => 'VolnPrvZamest', 'potvrzeniZamestnavatele.volnoBezNahrady' => 'VolnoBezNahr',
    'potvrzeniZamestnavatele.volnoBezNahradyOd' => 'VolnoBezNahrOd', 'potvrzeniZamestnavatele.volnoBezNahradyDo' => 'VolnoBezNahrDo',
    'potvrzeniZamestnavatele.nastupujePPM' => 'NastupujePPM', 'potvrzeniZamestnavatele.narozeniDitete' => 'NarDitete',
    'potvrzeniZamestnavatele.prevedenaNaJinouPraci' => 'PrJinPrace', 'potvrzeniZamestnavatele.exekuce' => 'Srazka',
    'potvrzeniZamestnavatele.insolvence' => 'Insolvence',
    'oseVznik' => 'Vznik', 'oseTrvani' => 'Trvani', 'oseUkonceni' => 'Ukonceni',
    'dloVznik' => 'Vznik', 'dloTrvani' => 'Trvani', 'dloUkonceni' => 'Ukonceni',
    'zadostODavku.odeDne' => 'OdeDne', 'zadostODavku.doDne' => 'DoDne',
    'zadostODavku.duvodOtcovske' => 'RelDuvodOtcovske', 'zadostODavku.duvodPece' => 'RelDuvodPece',
    'zadostODavku.osetrovanaOsoba.jmeno' => 'OsetrOsJmeno', 'zadostODavku.osetrovanaOsoba.prijmeni' => 'OsetrOsPrij',
    'zadostODavku.osetrovanaOsoba.rodneCislo' => 'OsetrOsRodCis', 'zadostODavku.osetrovanaOsoba.datumNarozeni' => 'OsetrOsDatNar',
    'zadostODavku.kodVztah' => 'RelKodVztah', 'zadostODavku.jeStridani' => 'JeStridani',
    'zadostODavku.narokNaPPMjinouOsobou' => 'NarokPPM', 'zadostODavku.narokNaRPjinaOsobaNecerpaVolnoNeboOSVC' => 'NarokRP',
    'zadostODavku.jinaFOParagraf57' => 'JinaFOParagraf57', 'zadostODavku.spolecnaDomacnost' => 'SpolecDoma',
    'zadostODavku.pecovalOsobne' => 'PecovalOsobne', 'zadostODavku.jeOsamely' => 'JeOsamely',
    'zadostODavku.vPeciDiteDo16Let' => 'DiteDo16Let', 'zadostODavku.kodRodVztah' => 'RelKodRodVztah',
    'zadostODavku.onemocnela' => 'Onemocnela', 'zadostODavku.narizenaKarantena' => 'NarizenKaran',
    'zadostODavku.nemuzePecovatODite' => 'NemuzePecovat',
    'uzavrenaSkola.nazevZarizeniSkoly' => 'NazevSkoly', 'uzavrenaSkola.ICZarizeniSkoly' => 'ICskoly',
    'podkladyProVyplatDavky.pracovalPoslDenPD' => 'PracovalPoslDenPD', 'podkladyProVyplatDavky.pracovniDobaPoslDenPD' => 'PracDobaPoslDenPD',
    'podkladyProVyplatDavky.pocetOdpracHodinPoslDenPD' => 'PocetHodinPoslDenPD', 'podkladyProVyplatDavky.planovaneSmeny' => 'PlanovSmeny',
    'podkladyProVyplatDavky.planovaneSmenyOdpracoval' => 'PlanovSmenyOdprac', 'podkladyProVyplatDavky.datumNavratDoPrace' => 'DatNavratDoPrace',
    'podkladyProVyplatDavky.maVolno' => 'MaVolno',
    'platebniSpojeni.vyplatitUcetCR' => 'MzdaNaUcetCR', 'platebniSpojeni.vyplatitUcetCizina' => 'MzdaNaUcetZahr',
    'platebniSpojeni.vyplatitAdresa' => 'MzdaNaAdresu', 'platebniSpojeni.vyplatitHotovost' => 'MzdaVHotovosti',
    'adresa.obec' => 'AdrObec', 'adresa.ulice' => 'AdrUlice', 'adresa.cisloPopis' => 'AdrCisloPopis', 'adresa.psc' => 'AdrPSC',
    'ucetCZ.bankaKod' => 'KodBankyCR', 'ucetCZ.specSymbol' => 'SpecSymCR',
];

/**
 * Druh dávky NEMPRI25. PAMICA vede ošetřovné pod starým kódem OCR (formáty před NEMPRI25);
 * NEMPRI25 zná jen NEM, VPM, OPP, PPM, OSE a DLO, ošetřovné je OSE.
 */
function nempriKind(array $row): string
{
    $kind = strtoupper(trim((string) ($row['DruhDavky'] ?? '')));
    return $kind === 'OCR' ? 'OSE' : $kind;
}

/**
 * Číselníkové hodnoty NEMPRI25 (duvodOtcovske, duvodPece, kodVztah, kodRodVztah). PAMICA drží
 * nevyplněný číselník jako 0, kterou žádný z číselníků ČSSZ nezná (CIS_DUVPREVZETI: DOH, ONE, ROZ, UMR);
 * prvek je pak nepovinný a vynechá se.
 */
const NEMPRI_CODEBOOK_KEYS = ['zadostODavku.duvodOtcovske', 'zadostODavku.duvodPece', 'zadostODavku.kodVztah', 'zadostODavku.kodRodVztah'];

if (wanted($only, 'NEMPRI25')) {
    $cfg = $schemas['NEMPRI25'];
    [$model, $root, $writer, $version, $xsdFile] = modelFor($cfg, $xsdRoot);
    $sentences = groupBy(raw($corpus, 'NEMPRIpol'), 'RefAg');
    $children = groupBy(raw($corpus, 'NEMPRIdeti'), 'RefPol');
    $care = groupBy(raw($corpus, 'NEMPRIpecovalDny'), 'RefPol');
    $worked = groupBy(raw($corpus, 'NEMPRIpraceVeDnech'), 'RefPol');
    $leave = groupBy(raw($corpus, 'NEMPRIpracVolno'), 'RefPol');
    $shifts = groupBy(raw($corpus, 'NEMPRIrozvrhSmen'), 'RefPol');

    $intervals = static function (array $rows, string $from, string $to): array {
        return array_map(fn (array $r): PamicaPathResolver => new PamicaPathResolver(['od' => cell($r, $from, true), 'do' => cell($r, $to, true)]), $rows);
    };
    $chooseKind = static function (array $row) {
        return static function (array $names, array $path) use ($row): ?string {
            if (in_array('nem', $names, true)) {
                $kind = strtolower(nempriKind($row));
                return in_array($kind, $names, true) ? $kind : null;
            }
            $columns = ['onemocnela' => 'Onemocnela', 'narizenaKarantena' => 'NarizenKaran', 'nemuzePecovatODite' => 'NemuzePecovat', 'uzavrenaSkola' => 'ZarizeniUzavreno'];
            foreach ($names as $name) {
                if (isset($columns[$name]) && ($row[$columns[$name]] ?? false) === true) {
                    return $name;
                }
            }
            return null;
        };
    };

    foreach (raw($corpus, 'NEMPRI') as $header) {
        $id = (int) $header['ID'];
        $resolvers = [];
        $kinds = [];
        foreach ($sentences[$id] ?? [] as $row) {
            $values = mapped($row, $nempriMap);
            $values['dokument.druhDavky'] = nempriKind($row) ?: null;
            foreach (NEMPRI_CODEBOOK_KEYS as $key) {
                if (($values[$key] ?? null) !== null && trim($values[$key], '0 ') === '') {
                    $values[$key] = null;
                }
            }
            $kinds[nempriKind($row)] = true;
            $account = (string) ($row['UcetCR'] ?? '');
            if ($account !== '') {
                if (str_contains($account, '-')) {
                    [$values['ucetCZ.predcisli'], $values['ucetCZ.ucetCislo']] = explode('-', $account, 2);
                } else {
                    $values['ucetCZ.ucetCislo'] = $account;
                }
            }
            $kids = $children[$row['ID']] ?? [];
            $lists = [
                'pecovalVeDnech.obdobi' => $intervals($care[$row['ID']] ?? [], 'PecovalOd', 'PecovalDo'),
                'seznamPraceVeDnech.obdobi' => $intervals($worked[$row['ID']] ?? [], 'PracovalOd', 'PracovalDo'),
                'pracovniVolno.obdobi' => $intervals($leave[$row['ID']] ?? [], 'VolnoOd', 'VolnoDo'),
                'seznamRozvrhuSmen.obdobi' => $intervals($shifts[$row['ID']] ?? [], 'SmenaOd', 'SmenaDo'),
            ];
            $months = [];
            for ($n = 1; $n <= 12; $n++) {
                $date = $row['DatR' . $n] ?? null;
                if ($date === null) {
                    continue;
                }
                $months[] = new PamicaPathResolver([
                    'kalendarniMesic' => (string) (int) substr((string) $date, 5, 2),
                    'kalendarniRok' => substr((string) $date, 0, 4),
                    'zapocitatelnyPrijem' => cell($row, 'KcPrijR' . $n),
                    'vylouceneDny' => cell($row, 'VyldnyR' . $n),
                ]);
            }
            $lists['seznamObdobi.obdobi'] = $months;
            $ordinal = 0;
            $lists['deti.dite'] = array_map(function (array $kid) use (&$ordinal): PamicaPathResolver {
                $ordinal++;
                return new PamicaPathResolver([
                    'jmeno' => cell($kid, 'DiteJmeno'), 'prijmeni' => cell($kid, 'DitePrijmeni'),
                    'rodneCislo' => preg_replace('/\D/', '', (string) cell($kid, 'DiteRodCisl')) ?: null, 'datumNarozeni' => cell($kid, 'DiteDatNar', true),
                    'poradoveCisloDitete' => (string) $ordinal,
                ]);
            }, $kids);
            if ($kids !== []) {
                $values['zadostODavku.dite.jmeno'] = cell($kids[0], 'DiteJmeno');
                $values['zadostODavku.dite.prijmeni'] = cell($kids[0], 'DitePrijmeni');
                $values['zadostODavku.dite.rodneCislo'] = preg_replace('/\D/', '', (string) cell($kids[0], 'DiteRodCisl')) ?: null;
                $values['zadostODavku.dite.datumNarozeni'] = cell($kids[0], 'DiteDatNar', true);
            }
            $resolvers[] = new PamicaPathResolver($values, $lists, $chooseKind($row));
        }
        $rootResolver = new PamicaPathResolver([], ['datovaVeta' => $resolvers]);
        $doc = $writer->write($root, $rootResolver, ['version' => $version]);
        ksort($kinds);
        $date = ymd($header['DatPod'] ?? null);
        $name = ($date !== '' ? $date : 'nepodano') . '_' . $id . '.xml';
        $relative = saveXml($doc, $corpus, $cfg['dir'], $name);
        $valid = recordValidation($validation, $errorCounts, 'NEMPRI25', $relative, $corpus, $xsdRoot . $cfg['xsd']);
        addIndex($index, $messages, 'NEMPRI25', implode('+', array_keys($kinds)), $date, $header, count($resolvers), [$relative], [$valid], 'RefStavDP');
    }
}

// --- HZUPN20 --------------------------------------------------------------------------------
if (wanted($only, 'HZUPN20')) {
    $cfg = $schemas['HZUPN20'];
    [$model, $root, $writer, $version, $xsdFile] = modelFor($cfg, $xsdRoot);
    $sentences = groupBy(raw($corpus, 'HZUPNpol'), 'RefAg');
    $worked = groupBy(raw($corpus, 'HZUPNpracoval'), 'RefPol');
    $map = [
        '@poradoveCislo' => 'PoradCis',
        'dokument.zahranicni' => 'Zahranicni', 'dokument.cisloPotvrzeni' => 'CisloPotvrzeni', 'dokument.kodOSSZ' => 'KodOSSZ',
        'dokument.nazevOSSZ' => 'NazevOSSZ', 'dokument.datumVystaveni' => 'DatumVystaveni', 'dokument.opravnePodani' => 'OpravnePodani',
        'pojistenec.jmeno' => 'Jmeno', 'pojistenec.prijmeni' => 'Prijmeni', 'pojistenec.titul' => 'Titul',
        'pojistenec.rodCislo' => 'RodCisl', 'pojistenec.datumNar' => 'DatNar',
        'zamestnani.nazevZamestnavatel' => 'JmZam', 'zamestnani.ICZamestnavatel' => 'ICZam', 'zamestnani.variabilniSymbol' => 'VSZam',
        'potvrzeniZamestnavatele.navratDoPrace' => 'NavratDoPrace', 'potvrzeniZamestnavatele.duvodNavratDoPrace' => 'DuvodNavratuDoPrace',
        'potvrzeniZamestnavatele.datumNavratDoPrace' => 'DatumNavratuDoPrace',
        'potvrzeniZamestnavatele.pocetOdpracHodinPoslDenPD' => 'PocetOdpracHodinPoslDenPD',
        'potvrzeniZamestnavatele.pracovniDobaPoslDenPD' => 'PracovniDobaPoslDenPD',
    ];
    foreach (raw($corpus, 'HZUPN') as $header) {
        $id = (int) $header['ID'];
        $resolvers = [];
        foreach ($sentences[$id] ?? [] as $row) {
            $intervals = array_map(fn (array $r): PamicaPathResolver => new PamicaPathResolver(['pracovalOd' => cell($r, 'PracovalOd', true), 'pracovalDo' => cell($r, 'PracovalDo', true)]), $worked[$row['ID']] ?? []);
            $resolvers[] = new PamicaPathResolver(mapped($row, $map), ['praceVeDnech.interval' => $intervals]);
        }
        $doc = $writer->write($root, new PamicaPathResolver([], ['FormularHZUPN' => $resolvers]), ['version' => $version]);
        $date = ymd($header['DatPod'] ?? null);
        $relative = saveXml($doc, $corpus, $cfg['dir'], ($date !== '' ? $date : 'nepodano') . '_' . $id . '.xml');
        $valid = recordValidation($validation, $errorCounts, 'HZUPN20', $relative, $corpus, $xsdRoot . $cfg['xsd']);
        addIndex($index, $messages, 'HZUPN20', '', $date, $header, count($resolvers), [$relative], [$valid], 'RefStavDP');
    }
}

// --- ELDP -----------------------------------------------------------------------------------
if (wanted($only, 'ELDP')) {
    // formCommonTypes.xsd nemá globální element, proto malý adaptér s jediným elementem eldpSeznam typu eldpType
    // (stejně jako EldpSchemaCatalog v aplikaci); typy přicházejí beze změny z oficiálního souboru.
    $bundle = sys_get_temp_dir() . '/pamica-eldp-' . bin2hex(random_bytes(4));
    mkdir($bundle, 0777, true);
    $source = $xsdRoot . '/jmhz/jmhz-1.4.3.6';
    foreach (['formCommonTypes.xsd', 'baseTypes2.xsd'] as $file) {
        copy($source . '/' . $file, $bundle . '/' . $file);
    }
    $ns = 'http://schemas.cssz.cz/JMHZ/form/1.0';
    file_put_contents($bundle . '/eldp-adapter.xsd', <<<XSD
        <?xml version="1.0" encoding="UTF-8"?>
        <xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema" xmlns="$ns" targetNamespace="$ns" elementFormDefault="qualified">
          <xs:include schemaLocation="formCommonTypes.xsd"/>
          <xs:element name="eldpSeznam" type="eldpType"/>
        </xs:schema>
        XSD);
    $model = new PamicaXsdModel();
    $model->load($bundle . '/eldp-adapter.xsd');
    $root = $model->globalElement($ns, 'eldpSeznam');
    $writer = new PamicaXsdWriter([$ns => '']);
    $attributeReport['ELDP'] = ['dictionary' => $model->idMap($root), 'seen' => []];
    $sentences = groupBy(raw($corpus, 'ELDPpol'), 'RefAg');

    foreach (raw($corpus, 'ELDP') as $header) {
        $id = (int) $header['ID'];
        $files = [];
        $valids = [];
        $types = [];
        foreach ($sentences[$id] ?? [] as $row) {
            $sections = [];
            for ($n = 1; $n <= 3; $n++) {
                if (cell($row, 'Kod' . $n) === null) {
                    continue;
                }
                $sections[] = new PamicaPathResolver([
                    'kod' => cell($row, 'Kod' . $n), 'platnostOd' => cell($row, 'DatOd' . $n, true), 'platnostDo' => cell($row, 'DatDo' . $n, true),
                    'pocetDnu' => cell($row, 'Dny' . $n), 'vymerovaciZaklad' => cell($row, 'VymZakl' . $n),
                    'vylouceneDny.vylouceneDobyCelkem' => cell($row, 'VylDoby' . $n), 'odecitaneDny.odecitaneDobyCelkem' => cell($row, 'DobyOdec' . $n),
                ]);
            }
            $types[(string) ($row['TypELDP'] ?? '')] = true;
            $doc = $writer->write($root, new PamicaPathResolver([], ['eldp' => $sections]));
            $relative = saveXml($doc, $corpus, 'ELDP', ((string) ($header['Rok'] ?? 'rok')) . '_' . $id . '-' . $row['ID'] . '.xml');
            $files[] = $relative;
            $valids[] = recordValidation($validation, $errorCounts, 'ELDP', $relative, $corpus, $bundle . '/eldp-adapter.xsd');
        }
        ksort($types);
        addIndex($index, $messages, 'ELDP', 'TypELDP ' . implode('+', array_keys($types)), (string) ($header['Rok'] ?? ''), $header, count($sentences[$id] ?? []), $files, $valids, 'RefStavDP');
    }
}

if (isset($bundle) && is_dir($bundle)) {
    foreach (glob($bundle . '/*') ?: [] as $file) {
        unlink($file);
    }
    rmdir($bundle);
}

// --- ONZ (jen evidence, XML se neskládá: ČSSZ XSD pro ONZ v repozitáři není) ----------------
if (wanted($only, 'ONZ')) {
    $sentences = groupBy(raw($corpus, 'ONZpol'), 'RefAg');
    foreach (raw($corpus, 'ONZ') as $header) {
        $id = (int) $header['ID'];
        $kinds = [];
        foreach ($sentences[$id] ?? [] as $row) {
            $kinds[(string) ($row['RelTyp'] ?? '')] = true;
        }
        ksort($kinds);
        addIndex($index, $messages, 'ONZ', 'RelTyp ' . implode('+', array_keys($kinds)), ymd($header['DatPod'] ?? null), $header, count($sentences[$id] ?? []), [], [], 'RelStavDP');
    }
}

// --- výstupy --------------------------------------------------------------------------------
if (!is_dir($corpus . '/xml')) {
    mkdir($corpus . '/xml', 0777, true);
}
if ($only === []) {
    $handle = fopen($corpus . '/index.csv', 'wb');
    $columns = ['type', 'kind', 'period', 'submission_id', 'sentences', 'status', 'status_code', 'submitted', 'accepted_on', 'databox_message_ids', 'databox_status', 'receipt', 'xml_file', 'valid'];
    fputcsv($handle, $columns, ',', '"', '');
    usort($index, fn (array $a, array $b): int => [$a['type'], $a['submitted'], $a['submission_id']] <=> [$b['type'], $b['submitted'], $b['submission_id']]);
    foreach ($index as $row) {
        fputcsv($handle, array_map(fn (string $c) => $row[$c] ?? '', $columns), ',', '"', '');
    }
    fclose($handle);
}

$state = static fn (array $f): string => $f['valid'] ? 'yes' : (($f['probe'] ?? null) === true ? 'gaps_only' : 'no');
$md = ['# Validace rekonstruovaných XML', '', 'Každý soubor je validován `DOMDocument::schemaValidate` proti XSD připnutému v `api/xsd`.', ''];
$md[] = 'Výsledky: `OK` = projde bez výhrad; `JEN CHYBEJICI UDAJE` = neprojde jen proto, že databáze PAMICA nenese povinný prvek'
    . ' (po doplnění zástupné hodnoty jen v paměti projde, na disk se nic nezapisuje; seznam prvků je v README);'
    . ' `CHYBA` = neprojde z jiného důvodu (typicky vadná hodnota ve zdrojových datech).';
$md[] = '';
$md[] = '| Typ | Souborů | OK | Jen chybějící údaje | Chyba |';
$md[] = '|---|---|---|---|---|';
foreach ($validation as $type => $files) {
    $counts = array_count_values(array_map($state, $files));
    $md[] = sprintf('| %s | %d | %d | %d | %d |', $type, count($files), $counts['yes'] ?? 0, $counts['gaps_only'] ?? 0, $counts['no'] ?? 0);
}
$md[] = '';
foreach ($validation as $type => $files) {
    $md[] = '## ' . $type;
    $md[] = '';
    if (!empty($errorCounts[$type])) {
        arsort($errorCounts[$type]);
        $md[] = 'Nejčastější nálezy validátoru (počet souborů; chybějící údaje jsou záměrně nedoplněné):';
        $md[] = '';
        foreach (array_slice($errorCounts[$type], 0, 25, true) as $message => $count) {
            $md[] = '- ' . $count . 'x ' . str_replace('|', '\\|', mb_substr($message, 0, 300));
        }
        $md[] = '';
    }
    $md[] = '| Soubor | Výsledek | První nález |';
    $md[] = '|---|---|---|';
    foreach ($files as $f) {
        $first = $f['errors'][0] ?? '';
        $label = ['yes' => 'OK', 'gaps_only' => 'JEN CHYBEJICI UDAJE', 'no' => 'CHYBA (' . count($f['errors']) . ')'][$state($f)];
        $md[] = sprintf('| %s | %s | %s |', $f['file'], $label, str_replace(['|', "\n"], ['\\|', ' '], mb_substr(normalizeError($first), 0, 160)));
    }
    $md[] = '';
}
if ($only === []) {
    file_put_contents($corpus . '/xml/VALIDATION.md', implode("\n", $md) . "\n");
}

$dictionary = [];
foreach ($attributeReport as $type => $info) {
    $known = $info['dictionary'];
    $entries = [];
    foreach ($info['seen'] as $id => $count) {
        $entries[$id] = [
            'occurrences' => $count['occurrences'],
            'nonempty' => $count['nonempty'],
            'written_to_xml' => $info['used'][$id] ?? 0,
            'paths' => $known[$id] ?? [],
            'known' => isset($known[$id]),
        ];
    }
    ksort($entries);
    $dictionary[$type] = [
        'seen' => $entries,
        'unknown' => array_values(array_filter(array_keys($entries), fn (int $id): bool => !$entries[$id]['known'])),
        'not_written_to_xml' => array_values(array_filter(array_keys($entries), fn (int $id): bool => $entries[$id]['written_to_xml'] === 0 && $entries[$id]['nonempty'] > 0)),
        'dictionary' => $known,
    ];
}
if ($only === []) {
    file_put_contents($corpus . '/raw/attribute-ids.json', json_encode($dictionary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

foreach ($validation as $type => $files) {
    $counts = array_count_values(array_map($state, $files));
    fwrite(STDOUT, sprintf("%-10s souborů %4d, OK %4d, jen chybějící údaje %4d, chyba %4d\n", $type, count($files), $counts['yes'] ?? 0, $counts['gaps_only'] ?? 0, $counts['no'] ?? 0));
}
