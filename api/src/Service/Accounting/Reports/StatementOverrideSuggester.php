<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Reports;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\LedgerReportRepository;
use MyInvoice\Repository\StatementDefinitionRepository;
use MyInvoice\Service\Tax\Return\DppoXmlBuilder;
use MyInvoice\Service\Tax\Return\AccountingDecreeService;
use PDO;

/**
 * Návrh výjimek mapování z PODANÉHO přiznání DPPO.
 *
 * Porovná přílohu účetní závěrky (VetaUA aktiva, VetaUD pasiva, VetaUB VZZ, celé tisíce Kč),
 * jak ji z účetnictví vyrobí aplikace, s přílohou podaného přiznání. Kde se dva řádky liší
 * opačně o částku, která odpovídá zůstatku jednoho účtu nebo analytiky (tolerance ±1 tis.
 * na zaokrouhlení), navrhne tento účet přeřadit do řádku, kde ho má podané přiznání.
 *
 * Nic nezapisuje. Návrhy vrací s odůvodněním a uživatel (nebo převodní skript) je potvrdí
 * přes {@see StatementOverrideService::save()}.
 *
 * Přílohu aplikace staví STEJNOU cestou jako podání: výkazy z {@see FinancialStatementService}
 * (rozvaha v rozsahu `auto`, VZZ v plném rozsahu, jako TaxReturnService) a z nich XML
 * přes {@see DppoXmlBuilder}. Obě strany pak čte tentýž {@see DppoAppendixXmlParser}.
 */
final class StatementOverrideSuggester
{
    /** Tolerance rozdílu v tisících — příloha zaokrouhluje každý řádek zvlášť. */
    private const TOLERANCE = 1;

    /** Přesun, který nesedí přesně na žádné straně, se navrhne až od této částky (tis. Kč). */
    private const MIN_FIT = 10;

    /** Řádek s celkem sekce — rozdíl celku nevysvětlí žádný přesun uvnitř sekce. */
    private const SECTION_TOTAL = ['assets' => 'AKTIVA', 'liabilities' => 'PASIVA', 'profit_loss' => 'VH'];

    private const ALL_LEAVES = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

    /** Sloupec, podle kterého se řádky porovnávají. */
    private const COLUMNS = ['VetaUA' => 'kc_netto', 'VetaUD' => 'kc_sled', 'VetaUB' => 'kc_sled'];

    private const STATEMENT_OF = ['VetaUA' => 'balance_sheet', 'VetaUD' => 'balance_sheet', 'VetaUB' => 'income_statement'];

    /**
     * Souhrnné řádky pasiv, které {@see DppoXmlBuilder::buildVetaUD()} skládá přímo v kódu
     * (ne z konstant). Řádek 24 „B.+C." odpovídá dvěma řádkům výkazu, proto tu chybí.
     */
    private const PASIVA_TOP_C_RADKU = [1 => 'PASIVA', 2 => 'P.A.', 25 => 'P.B.', 30 => 'P.C.', 64 => 'P.D.'];

    /** Řádky tiskopisu složené z víc řádků výkazu — jen popisek do výpisu rozdílů. */
    private const COMPOSITE_C_RADKU = ['VetaUD' => [24 => 'P.B.+P.C.']];

    public function __construct(
        private readonly Connection $db,
        private readonly AccountingPeriodRepository $periods,
        private readonly FinancialStatementService $statements,
        private readonly DppoXmlBuilder $builder,
        private readonly DppoAppendixXmlParser $parser,
        private readonly StatementDefinitionRepository $definitions,
        private readonly StatementMapResolver $maps,
        private readonly StatementMapper $mapper,
        private readonly LedgerReportRepository $ledger,
    ) {}

    /**
     * Poslední doložené podání DPPO (status submitted/accepted) za rok období, bez XML.
     *
     * @return array{submission_id:int, year:int, status:string, submitted_at:?string, form_variant:string}|null
     */
    public function filedReturnForPeriod(int $supplierId, int $periodId): ?array
    {
        $year = self::yearOf($this->period($supplierId, $periodId));
        $row = $this->filedReturnRow($supplierId, $year);
        if ($row === null) {
            return null;
        }

        return [
            'submission_id' => (int) $row['id'],
            'year'          => $year,
            'status'        => (string) $row['status'],
            'submitted_at'  => $row['submitted_at'] === null ? null : (string) $row['submitted_at'],
            'form_variant'  => (string) $row['form_variant'],
        ];
    }

    /** @return array<string,mixed> */
    public function suggestFromFiledReturn(int $supplierId, int $periodId): array
    {
        $year = self::yearOf($this->period($supplierId, $periodId));
        $row = $this->filedReturnRow($supplierId, $year);
        if ($row === null) {
            throw new ReportException('filed_return_missing', sprintf(
                'Pro rok %d není v evidenci podané přiznání k dani z příjmů právnických osob. Nahrajte XML podaného přiznání.',
                $year,
            ), 404);
        }
        $out = $this->suggestFromXml($supplierId, $periodId, (string) $row['xml_content']);
        $out['source'] = [
            'type'          => 'filed_return',
            'submission_id' => (int) $row['id'],
            'status'        => (string) $row['status'],
            'submitted_at'  => $row['submitted_at'] === null ? null : (string) $row['submitted_at'],
        ];

        return $out;
    }

    /** @return array<string,mixed> */
    public function suggestFromXml(int $supplierId, int $periodId, string $xml): array
    {
        $parsed = $this->parser->parse($xml);
        $year = self::yearOf($this->period($supplierId, $periodId));
        $filedYear = $parsed['zdobd_do'] !== null ? (int) substr($parsed['zdobd_do'], 0, 4) : null;
        if ($filedYear !== null && $filedYear !== $year) {
            throw new ReportException('filed_year_mismatch', sprintf(
                'Přiznání je za rok %d, ale vybrané účetní období je rok %d.',
                $filedYear,
                $year,
            ), 422);
        }
        $hasAppendix = false;
        foreach ($parsed['appendix'] as $rows) {
            $hasAppendix = $hasAppendix || $rows !== [];
        }
        if (!$hasAppendix) {
            throw new ReportException('filed_return_without_appendix',
                'Přiznání nemá přílohu účetní závěrky (věty VetaUA, VetaUB, VetaUD) — není s čím porovnat.', 422);
        }

        $out = $this->suggest($supplierId, $periodId, $parsed['appendix'], $parsed['scope']);
        $out['source'] = ['type' => 'upload'];
        $out['prior_period'] = $this->suggestPriorPeriod($supplierId, $periodId, $parsed['appendix'], $parsed['scope']);

        return $out;
    }

    /**
     * Návrhy pro sloupec minulého období: podané přiznání nese i údaje minulého období
     * (kc_netto_min, kc_min), tedy zařazení z uzavřeného výkazu minulého roku. Porovnají
     * se s výkazem minulého období aplikace (s výjimkami platnými v minulém roce) a návrhy
     * dostanou platnost do minulého roku. Sloupec je pak shodný s podanou závěrkou, když má
     * firma zapnuté převzetí srovnávacího období z uzavřeného výkazu.
     *
     * @param array<string, array<int, array<string,int|float>>> $filed
     * @return array<string,mixed>|null null = firma nemá minulé období nebo podání údaje minulého období nenese
     */
    public function suggestPriorPeriod(int $supplierId, int $periodId, array $filed, ?string $scope = null): ?array
    {
        $prev = $this->ledger->previousPeriod($supplierId, (string) $this->period($supplierId, $periodId)['starts_on']);
        if ($prev === null) {
            return null;
        }
        $columns = ['VetaUA' => ['kc_netto_min' => 'kc_netto'], 'VetaUD' => ['kc_min' => 'kc_sled'], 'VetaUB' => ['kc_min' => 'kc_sled']];
        $prior = [];
        $any = false;
        foreach ($columns as $sentence => $map) {
            foreach ($filed[$sentence] ?? [] as $c => $values) {
                foreach ($map as $from => $to) {
                    if (array_key_exists($from, $values)) {
                        $prior[$sentence][$c][$to] = $values[$from];
                        $any = $any || (int) $values[$from] !== 0;
                    }
                }
            }
        }
        if (!$any) {
            return null;
        }

        $out = $this->suggest($supplierId, (int) $prev['id'], $prior, $scope);
        $prevYear = (int) $prev['fiscal_year'];
        foreach ($out['suggestions'] as $i => $s) {
            foreach ($s['overrides'] as $j => $o) {
                $out['suggestions'][$i]['overrides'][$j]['valid_to_year'] = $prevYear;
            }
            $out['suggestions'][$i]['reason'] .= sprintf(' Týká se sloupce minulého období, výjimka platí do roku %d.', $prevYear);
        }

        return $out;
    }

    /**
     * Příloha účetní závěrky, jak ji z účetnictví vyrobí aplikace (tisíce Kč), v rozsahu
     * rozvahy `$scope`. Pro porovnání s podaným přiznáním musí rozsah odpovídat podání:
     * malá ÚJ může podat plnou rozvahu a ve zkrácené by podrobné řádky vyšly nulové, takže
     * každý přesun mezi nimi by vypadal jako rozdíl bez vysvětlení.
     *
     * @param 'full'|'small'|'micro' $scope
     * @return array<string, array<int, array<string,int>>>
     */
    public function appAppendix(int $supplierId, int $periodId, string $scope = 'full'): array
    {
        $period = $this->period($supplierId, $periodId);
        $decree = (new AccountingDecreeService($this->db))->assertDppoPeriodSupported(
            $supplierId, (string) $period['starts_on'], (string) $period['ends_on'],
        );
        // Rozvahový den = konec období. Podané přiznání je vždy za uplynulý rok, kde je to
        // totéž, co výkaz bez `as_of` v TaxReturnService.
        $endsOn = (string) $period['ends_on'];
        $appendix = [
            'balance_sheet'    => $this->statements->balanceSheet($supplierId, $periodId, $endsOn, $scope),
            'income_statement' => $this->statements->incomeStatement($supplierId, $periodId, $endsOn, 'full'),
            // Kategorie a nastavení výkaznictví ovlivní jen metadata VetaD/VetaS/VetaUZ,
            // porovnávají se řádky příloh. Rozsah se bere z podání, proto se kategorie
            // nepočítá (a nic se kvůli ní nezmrazí).
            'category'         => ['scope' => $scope],
            'settings'         => [],
        ];
        $meta = [
            'accounting_decree' => $decree,
            'zdobd_od' => date('d.m.Y', (int) strtotime((string) $period['starts_on'])),
            'zdobd_do' => date('d.m.Y', (int) strtotime((string) $period['ends_on'])),
        ];
        $built = $this->builder->build($this->loadSupplier($supplierId), self::yearOf($period), [], $meta, $appendix);

        return $this->parser->parse((string) $built['xml'])['appendix'];
    }

    /**
     * Návrhy výjimek z porovnání přílohy aplikace s přílohou podaného přiznání.
     *
     * @param array<string, array<int, array<string,int|float>>> $filed věta => c_radku => sloupec => hodnota (tis. Kč)
     * @param 'full'|'small'|'micro'|null $scope rozsah rozvahy podání; null = odvodí se z jeho řádků
     * @return array<string,mixed>
     */
    public function suggest(int $supplierId, int $periodId, array $filed, ?string $scope = null): array
    {
        $period = $this->period($supplierId, $periodId);
        $rowByC = $this->rowCodesByCRadku();
        $scope ??= $this->inferScope($filed, $rowByC, (string) $period['ends_on']);
        $ours = $this->appAppendix($supplierId, $periodId, $scope);

        $diffByRow = [];
        $differences = [];
        foreach (self::COLUMNS as $sentence => $column) {
            $cs = array_unique(array_merge(array_keys($ours[$sentence] ?? []), array_keys($filed[$sentence] ?? [])));
            sort($cs);
            foreach ($cs as $c) {
                $app = (int) round((float) ($ours[$sentence][$c][$column] ?? 0));
                $theirs = (int) round((float) ($filed[$sentence][$c][$column] ?? 0));
                $rowCode = $rowByC[$sentence][$c] ?? null;
                // VI. je v tiskopisu VZZ dvakrát (ř. 39 a 41) — platí první výskyt.
                if ($rowCode !== null && !isset($diffByRow[self::STATEMENT_OF[$sentence]][$rowCode])) {
                    $diffByRow[self::STATEMENT_OF[$sentence]][$rowCode] = $app - $theirs;
                }
                if ($app !== $theirs) {
                    $differences[] = [
                        'sentence' => $sentence,
                        'c_radku'  => (int) $c,
                        'row_code' => $rowCode ?? (self::COMPOSITE_C_RADKU[$sentence][$c] ?? null),
                        'app'      => $app,
                        'filed'    => $theirs,
                        'diff'     => $app - $theirs,
                    ];
                }
            }
        }

        // Aktiva podání nesou brutto a korekci zvlášť. Porovnávají se pak odděleně: přesun
        // pohledávky se hledá v brutto (s pasivy), přesun opravné položky v korekci. Na netto
        // by se pohledávka a její opravná položka ze dvou různých řádků jako jeden přesun
        // najít nedaly (netto záporné v jednom řádku, téměř nulové v cílovém).
        $channels = [
            ['balance_sheet', null, $diffByRow['balance_sheet'] ?? []],
            ['income_statement', null, $diffByRow['income_statement'] ?? []],
        ];
        if (self::hasGrossAndCorrection($filed)) {
            $channels[0] = ['balance_sheet', 'gross', self::columnDiffs($ours, $filed, 'VetaUA', 'kc_brutto', $rowByC)
                + self::columnDiffs($ours, $filed, 'VetaUD', 'kc_sled', $rowByC)];
            $channels[] = ['balance_sheet', 'correction', self::columnDiffs($ours, $filed, 'VetaUA', 'kc_korekce', $rowByC)];
        }

        $suggestions = [];
        $versions = [];
        foreach ($channels as [$type, $channel, $reported]) {
            if (array_filter($reported) === []) {
                continue;
            }
            $version = $versions[$type] ??= $this->definitions->findVersion($type, (string) $period['ends_on']);
            if ($version === null) {
                continue;
            }
            foreach ($this->movesFor($type, $version, $supplierId, $period, $reported, $channel) as $move) {
                $suggestions[] = $move;
            }
        }
        $suggestions = self::linkCorrectionsToReceivables($suggestions);
        usort($suggestions, static fn (array $a, array $b): int => abs($b['amount_thousands']) <=> abs($a['amount_thousands'])
            ?: strcmp($a['account_code'], $b['account_code']));

        return [
            'period_id'   => (int) $period['id'],
            'year'        => self::yearOf($period),
            'scope'       => $scope,
            'suggestions' => $suggestions,
            'differences' => $differences,
        ];
    }

    /**
     * Nese podání u aktiv brutto i korekci? (Příloha z EPO je nese vždy, ručně sestavené
     * XML nemusí — pak se aktiva porovnají jen na netto jako dřív.)
     *
     * @param array<string, array<int, array<string,int|float>>> $filed
     */
    private static function hasGrossAndCorrection(array $filed): bool
    {
        foreach ($filed['VetaUA'] ?? [] as $values) {
            if (array_key_exists('kc_brutto', $values) && array_key_exists('kc_korekce', $values)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Rozdíly aplikace − podání v jednom sloupci jedné věty, klíčované kódem řádku výkazu.
     *
     * @param array<string, array<int, array<string,int|float>>> $ours
     * @param array<string, array<int, array<string,int|float>>> $filed
     * @param array<string, array<int,string>>                    $rowByC
     * @return array<string,int>
     */
    private static function columnDiffs(array $ours, array $filed, string $sentence, string $column, array $rowByC): array
    {
        $out = [];
        $cs = array_unique(array_merge(array_keys($ours[$sentence] ?? []), array_keys($filed[$sentence] ?? [])));
        sort($cs);
        foreach ($cs as $c) {
            $rowCode = $rowByC[$sentence][$c] ?? null;
            if ($rowCode === null || isset($out[$rowCode])) {
                continue;
            }
            $out[$rowCode] = (int) round((float) ($ours[$sentence][$c][$column] ?? 0))
                - (int) round((float) ($filed[$sentence][$c][$column] ?? 0));
        }

        return $out;
    }

    /**
     * Opravná položka přesunutá do téhož řádku jako pohledávka navržená jedním prefixem
     * dostane vazbu na tu pohledávku (`follows_prefix`): řádek korekce se pak bere z toho,
     * kam výkaz zařadí pohledávku, a při dalším přeřazení pohledávky ji korekce následuje.
     *
     * @param list<array<string,mixed>> $suggestions
     * @return list<array<string,mixed>>
     */
    private static function linkCorrectionsToReceivables(array $suggestions): array
    {
        $receivables = [];
        foreach ($suggestions as $s) {
            if ($s['statement_type'] === 'balance_sheet' && $s['target'] === 'gross' && count($s['overrides']) === 1) {
                $receivables[(string) $s['to_row_code']][] = (string) $s['overrides'][0]['account_prefix'];
            }
        }
        foreach ($suggestions as $i => $s) {
            if ($s['target'] !== 'correction' || count($receivables[(string) $s['to_row_code']] ?? []) !== 1) {
                continue;
            }
            $follows = $receivables[(string) $s['to_row_code']][0];
            foreach ($s['overrides'] as $j => $o) {
                $suggestions[$i]['overrides'][$j]['follows_prefix'] = $follows;
            }
            $suggestions[$i]['follows_prefix'] = $follows;
            $suggestions[$i]['reason'] .= sprintf(' Korekce patří k pohledávce %s a bude následovat její zařazení.', $follows);
        }

        return $suggestions;
    }

    /**
     * Rozsah rozvahy podání podle nejhlubšího řádku, který podání obsahuje: řádek úrovně 3
     * a hlubší (mimo C.II.1./C.II.2., které zkrácená rozvaha malé ÚJ nese vždy) = plný,
     * úroveň 2 = malá, jinak mikro.
     *
     * @param array<string, array<int, array<string,int|float>>> $filed
     * @param array<string, array<int,string>>                    $rowByC
     * @return 'full'|'small'|'micro'
     */
    private function inferScope(array $filed, array $rowByC, string $asOf): string
    {
        $version = $this->definitions->findVersion('balance_sheet', $asOf);
        if ($version === null) {
            return 'full';
        }
        $levels = [];
        foreach ($this->definitions->rows((int) $version['id']) as $r) {
            $levels[(string) $r['row_code']] = (int) $r['level'];
        }
        $max = 0;
        foreach (['VetaUA', 'VetaUD'] as $sentence) {
            foreach (array_keys($filed[$sentence] ?? []) as $c) {
                $code = $rowByC[$sentence][$c] ?? null;
                if ($code === null || $code === 'C.II.1.' || $code === 'C.II.2.') {
                    continue;
                }
                $max = max($max, $levels[$code] ?? 0);
            }
        }

        return match (true) {
            $max >= 3 => 'full',
            $max === 2 => 'small',
            default => 'micro',
        };
    }

    /**
     * Přesuny, které vysvětlí rozdíly jednoho výkazu.
     *
     * Rozdíly se nevyhodnocují na každém řádku zvlášť (mezisoučet by přesun počítal
     * několikrát), ale na „uzlech": řádcích, které podání uvádí, s rozdílem po odečtení
     * rozdílů jejich nejbližších uvedených potomků. U nejhlubšího uvedeného řádku je to celý
     * rozdíl, u mezisoučtu jen část, kterou nevysvětlí uvedené podřádky (podřádek, který
     * tiskopis neposílá).
     *
     * Kandidáti jsou účty jednoho uzlu seskupené podle společného začátku kódu (365.100,
     * 365.* …), takže jde najít i přesun celé skupiny analytik. Přesuny se vybírají
     * postupně: přednost má ten, který sedí na obou stranách, pak ten, který sedí aspoň na
     * jedné, pak větší částka. Každý přijatý přesun sníží zbývající rozdíl obou uzlů, takže
     * víc přesunů do jednoho řádku (361, 365, 379.4* → C.I.6.) se najde postupně.
     *
     * @param array<string,mixed> $version
     * @param array<string,mixed> $period
     * @param array<string,int>   $reported row_code → aplikace − podané (tis. Kč), řádky uvedené v příloze
     * @param 'gross'|'correction'|null $channel rozdíly jen brutto / jen korekce; null = netto
     * @return list<array<string,mixed>>
     */
    private function movesFor(string $type, array $version, int $supplierId, array $period, array $reported, ?string $channel = null): array
    {
        $rows = $this->definitions->rows((int) $version['id']);
        $byCode = [];
        foreach ($rows as $r) {
            $byCode[(string) $r['row_code']] = $r;
        }
        $reported = array_intersect_key($reported, $byCode);

        // Nejbližší uvedený předek každého řádku; zbytkový rozdíl uzlu.
        $nearest = function (string $code) use ($byCode, $reported): ?string {
            $seen = [];
            $parent = $byCode[$code]['parent_row_code'] ?? null;
            while ($parent !== null && $parent !== '' && !isset($seen[$parent])) {
                $seen[$parent] = true;
                if (isset($reported[$parent])) {
                    return (string) $parent;
                }
                $parent = $byCode[$parent]['parent_row_code'] ?? null;
            }
            return null;
        };
        $residual = $reported;
        foreach (array_keys($reported) as $code) {
            $ancestor = $nearest((string) $code);
            if ($ancestor !== null) {
                $residual[$ancestor] -= $reported[$code];
            }
        }
        $deepestReported = function (string $rowCode) use ($byCode, $reported, $nearest): ?string {
            return isset($reported[$rowCode]) ? $rowCode : (isset($byCode[$rowCode]) ? $nearest($rowCode) : null);
        };

        // Příspěvky účtů (po analytikách) do uzlů.
        $asOf = (string) $period['ends_on'];
        $map = $this->maps->accountMap($version, $supplierId, (int) $period['fiscal_year']);
        $balances = $this->ledger->syntheticBalances(
            $supplierId,
            $asOf,
            (string) $period['starts_on'],
            $this->mapper->noCompensationPrefixes($map),
            self::ALL_LEAVES,
        );
        $contrib = [];
        $allCodes = [];
        foreach ($balances as $b) {
            $cents = (int) round(((float) $b['md'] - (float) $b['d']) * 100);
            $entries = $this->mapper->entriesFor($map, (string) $b['code'], $cents);
            foreach ($this->mapper->map($rows, $map, [$b]) as $rowCode => $v) {
                $node = $deepestReported((string) $rowCode);
                foreach ($v['accounts'] as $a) {
                    $code = (string) $a['account_code'];
                    $allCodes[$code] = true;
                    if ($node === null || ($channel !== null && (string) $a['target'] !== $channel)) {
                        continue;
                    }
                    $condition = 'any';
                    foreach ($entries as $e) {
                        if ((string) $e['row_code'] === (string) $rowCode && (string) $e['target'] === (string) $a['target']) {
                            $condition = (string) $e['balance_condition'];
                            break;
                        }
                    }
                    // Ve sloupci korekce se porovnává korekce sama (kladná), jinak netto příspěvek.
                    $net = $a['target'] === 'correction' && $channel !== 'correction' ? -(float) $a['amount'] : (float) $a['amount'];
                    $item = $contrib[$node][$code] ?? [
                        'net' => 0.0, 'name' => (string) $a['name'], 'row' => (string) $rowCode,
                        'target' => (string) $a['target'], 'condition' => $condition,
                    ];
                    $item['net'] = round($item['net'] + $net, 2);
                    $contrib[$node][$code] = $item;
                }
            }
        }

        // Kandidáti: skupiny účtů uzlu podle společného začátku kódu, bez duplicitních skupin.
        $candidates = [];
        foreach ($contrib as $node => $accounts) {
            $groups = [];
            foreach (array_keys($accounts) as $code) {
                $code = (string) $code;
                for ($len = 3; $len <= strlen($code); $len++) {
                    $groups[substr($code, 0, $len)][$code] = true;
                }
            }
            $unique = [];
            foreach ($groups as $members) {
                $list = array_map('strval', array_keys($members));
                sort($list);
                $unique[implode(',', $list)] = $list;
            }
            foreach ($unique as $members) {
                $net = 0.0;
                $targets = [];
                $conditions = [];
                foreach ($members as $code) {
                    $net += $accounts[$code]['net'];
                    $targets[$accounts[$code]['target']] = true;
                    $conditions[$accounts[$code]['condition']] = true;
                }
                $t = (int) round($net / 1000);
                if ($t === 0) {
                    continue;
                }
                $candidates[] = [
                    'node'      => (string) $node,
                    'members'   => $members,
                    'net'       => round($net, 2),
                    't'         => $t,
                    'name'      => count($members) === 1 ? $accounts[$members[0]]['name'] : sprintf('%d účtů', count($members)),
                    'row'       => $accounts[$members[0]]['row'],
                    'target'    => count($targets) === 1 ? (string) array_key_first($targets) : 'gross',
                    'condition' => count($conditions) === 1 ? (string) array_key_first($conditions) : 'any',
                ];
            }
        }

        // Druh účtů řádku VZZ (5 = náklady, 6 = výnosy) podle mapy včetně podřádků; řádek bez
        // vlastní mapy (nový podřádek J.1. apod.) převezme druh nejbližšího předka. Výnos
        // nepatří do nákladového řádku, i kdyby tam částka numericky seděla.
        $kinds = [];
        if ($type !== 'balance_sheet') {
            foreach ($map as $m) {
                $digit = substr((string) $m['account_prefix'], 0, 1);
                $code = (string) $m['row_code'];
                $seen = [];
                while ($code !== '' && isset($byCode[$code]) && !isset($seen[$code])) {
                    $seen[$code] = true;
                    $kinds[$code][$digit] = true;
                    $code = (string) ($byCode[$code]['parent_row_code'] ?? '');
                }
            }
        }
        $kindOf = static function (string $code) use ($kinds, $byCode): array {
            $seen = [];
            while ($code !== '' && !isset($seen[$code])) {
                $seen[$code] = true;
                if (isset($kinds[$code])) {
                    return $kinds[$code];
                }
                $code = (string) ($byCode[$code]['parent_row_code'] ?? '');
            }
            return [];
        };

        $isNode = static fn (string $code): bool => (string) ($byCode[$code]['row_type'] ?? '') !== 'computed';
        $used = [];
        $moves = [];
        for ($guard = 0; $guard < 500; $guard++) {
            $best = null;
            $bestRank = null;
            $ties = 0;
            foreach ($candidates as $i => $c) {
                foreach ($c['members'] as $code) {
                    if (isset($used[$code])) {
                        continue 2;
                    }
                }
                $source = $c['node'];
                $t = $c['t'];
                $ds = $residual[$source] ?? 0;
                if (abs($t) <= self::TOLERANCE || !$isNode($source) || $ds === 0 || ($ds > 0) !== ($t > 0)) {
                    continue;
                }
                $exactSource = abs($ds - $t) <= self::TOLERANCE;
                $fitsSource = abs($ds) >= abs($t) - self::TOLERANCE;
                $digit = substr((string) $c['members'][0], 0, 1);
                foreach ($residual as $to => $dt) {
                    $to = (string) $to;
                    if ($to === $source || !$isNode($to)
                        || (string) $byCode[$to]['section'] !== (string) $byCode[$source]['section']) {
                        continue;
                    }
                    if ($dt === 0 || ($dt > 0) === ($t > 0)) {
                        continue;
                    }
                    $kind = $kindOf($to);
                    if ($kind !== [] && !isset($kind[$digit])) {
                        continue;
                    }
                    $exactTarget = abs($dt + $t) <= self::TOLERANCE;
                    $fitsTarget = abs($dt) >= abs($t) - self::TOLERANCE;
                    // Přesun přesný na jedné straně smí druhou stranu přestřelit — dorovná ji
                    // další přesun (351.* ven z C.II.2.2. a zároveň 350 dovnitř). Bez přesné
                    // strany se musí vejít do obou a nesmí jít o drobnost.
                    if (!$exactSource && !$exactTarget && (!$fitsSource || !$fitsTarget)) {
                        continue;
                    }
                    if (!($exactSource && $exactTarget) && abs($t) < self::MIN_FIT) {
                        continue;
                    }
                    // O kolik přesun přestřelí zbývající rozdíl uzlů — menší je věrohodnější
                    // než hlubší řádek (hloubka rozhoduje až při stejném přestřelení).
                    $overshoot = max(0, abs($t) - abs($ds)) + max(0, abs($t) - abs($dt));
                    $rank = [
                        $exactSource && $exactTarget ? 2 : (($exactSource || $exactTarget) ? 1 : 0),
                        abs($t),
                        -count($c['members']),
                        -$overshoot,
                        (int) $byCode[$to]['level'],
                    ];
                    if ($bestRank === null || $rank > $bestRank) {
                        $best = ['i' => $i, 'to' => $to];
                        $bestRank = $rank;
                        $ties = 0;
                    } elseif ($rank === $bestRank) {
                        $ties++;
                    }
                }
            }
            if ($best === null) {
                break;
            }
            $c = $candidates[$best['i']];
            $residual[$c['node']] -= $c['t'];
            $residual[$best['to']] += $c['t'];
            foreach ($c['members'] as $code) {
                $used[$code] = true;
            }
            $confidence = match ($bestRank[0]) {
                2 => 'exact',
                1 => 'partial',
                default => 'fit',
            };
            // Nepřesný přesun, který není větší než rozdíl celku sekce, může být právě tím
            // rozdílem (jiný zůstatek, ne jiné zařazení) — nejistý.
            $totalDiff = (int) ($reported[self::SECTION_TOTAL[(string) $byCode[$c['node']]['section']] ?? ''] ?? 0);
            $suspicious = $confidence !== 'exact' && abs($totalDiff) > self::TOLERANCE
                && abs($c['t']) <= abs($totalDiff) + self::TOLERANCE;
            $moves[] = [
                'suggestion' => $this->suggestion($type, (int) $version['id'], $c, $best['to'], $byCode, array_keys($allCodes), $confidence, $ties > 0 || $suspicious),
                'from'       => $c['node'],
                'to'         => $best['to'],
            ];
        }

        // Jistý je jen přesun, po kterém (spolu s ostatními) oba jeho řádky sedí. Přestřelený
        // rozdíl, který žádný další přesun nedorovnal, znamená, že návrh vysvětluje jen část.
        $out = [];
        foreach ($moves as $move) {
            $s = $move['suggestion'];
            if (abs($residual[$move['from']] ?? 0) > self::TOLERANCE || abs($residual[$move['to']] ?? 0) > self::TOLERANCE) {
                $s['ambiguous'] = true;
                $s['reason'] .= sprintf(' Po všech navržených přesunech řádky %s / %s stále nesedí, ověřte.', $move['from'], $move['to']);
            }
            $out[] = $s;
        }

        return $out;
    }

    /**
     * @param array<string,mixed>                $c       kandidát (uzel, účty, částka)
     * @param array<string, array<string,mixed>> $byCode
     * @param list<string|int>                   $allCodes všechny účty s příspěvkem ve výkazu
     * @return array<string,mixed>
     */
    private function suggestion(string $type, int $versionId, array $c, string $to, array $byCode, array $allCodes, string $confidence, bool $tie): array
    {
        $members = $c['members'];
        // Jeden prefix, jen když ve výkazu nezasáhne žádný jiný účet; jinak účet po účtu.
        $prefix = (string) array_reduce(
            $members,
            static function (?string $carry, string $code): string {
                if ($carry === null) {
                    return $code;
                }
                $len = 0;
                $max = min(strlen($carry), strlen($code));
                while ($len < $max && $carry[$len] === $code[$len]) {
                    $len++;
                }
                return substr($carry, 0, $len);
            },
        );
        $exclusive = strlen($prefix) >= 3 && strlen($prefix) <= 10;
        foreach ($allCodes as $code) {
            $code = (string) $code;
            if ($exclusive && !in_array($code, $members, true) && str_starts_with($code, $prefix)) {
                $exclusive = false;
            }
        }
        $toRow = $byCode[$to];
        $target = $c['target'] === 'correction' && (string) $toRow['section'] === 'assets' ? 'correction' : 'gross';
        $overrides = array_map(
            static fn (string $p): array => [
                'account_prefix'    => $p,
                'row_code'          => $to,
                'target'            => $target,
                'balance_condition' => $c['condition'],
                'sign'              => 1,
                'note'              => null,
                'follows_prefix'    => null,
            ],
            $exclusive ? [$prefix] : $members,
        );
        $label = $exclusive ? $prefix : implode(', ', $members);

        return [
            'statement_type'    => $type,
            'version_id'        => $versionId,
            'account_code'      => $label,
            'account_name'      => (string) $c['name'],
            'accounts'          => $members,
            'amount'            => $c['net'],
            'amount_thousands'  => $c['t'],
            'current_row_code'  => (string) $c['row'],
            'from_row_code'     => (string) $c['node'],
            'from_label'        => (string) $byCode[$c['node']]['label'],
            'to_row_code'       => $to,
            'to_label'          => (string) $toRow['label'],
            'to_is_subtotal'    => (string) $toRow['row_type'] === 'subtotal',
            'balance_condition' => (string) $c['condition'],
            'target'            => $target,
            'follows_prefix'    => null,
            'sign'              => 1,
            'overrides'         => $overrides,
            'confidence'        => $confidence,
            'ambiguous'         => $tie || $confidence === 'fit',
            'reason'            => sprintf(
                '%s%s (%s tis. Kč) aplikace vykazuje v řádku %s, podané přiznání ho má v řádku %s (%s)%s.',
                $label,
                $target === 'correction' ? ' jako korekci' : '',
                number_format($c['t'], 0, ',', ' '),
                (string) $c['node'],
                $to,
                (string) $toRow['label'],
                match ($confidence) {
                    'exact'   => '; rozdíl obou řádků odpovídá přesně',
                    'partial' => '; přesně odpovídá rozdíl jednoho z řádků, do druhého jde víc přesunů',
                    default   => '; částka se do rozdílů jen vejde, ověřte',
                },
            ),
        ];
    }

    /**
     * Převod čísla řádku tiskopisu na kód řádku výkazu — čte se z mapy, kterou používá
     * sám {@see DppoXmlBuilder} (jeho konstanty), aby se převod nemohl rozejít s tím, co
     * builder do XML skutečně zapíše. Konstanty jsou soukromé, proto reflexe; chybějící
     * konstanta (přejmenování) jen zúží porovnání, nic nerozbije.
     *
     * @return array<string, array<int,string>>
     */
    public function rowCodesByCRadku(): array
    {
        $ref = new \ReflectionClass(DppoXmlBuilder::class);
        $pairs = static function (string ...$names) use ($ref): array {
            $out = [];
            $walk = static function (mixed $node) use (&$walk, &$out): void {
                if (!is_array($node)) {
                    return;
                }
                if (count($node) === 2 && isset($node[0], $node[1]) && is_string($node[0]) && is_int($node[1])) {
                    $out[$node[1]] ??= $node[0];
                    return;
                }
                foreach ($node as $child) {
                    $walk($child);
                }
            };
            foreach ($names as $name) {
                if ($ref->hasConstant($name)) {
                    $walk($ref->getConstant($name));
                }
            }
            return $out;
        };

        return [
            'VetaUA' => $pairs('AKTIVA_C_RADKU', 'AKTIVA_DETAIL_C_RADKU'),
            'VetaUD' => self::PASIVA_TOP_C_RADKU + $pairs(
                'PASIVA_A_C_RADKU',
                'PASIVA_B_C_RADKU',
                'PASIVA_C_C_RADKU',
                'PASIVA_D_C_RADKU',
                'PASIVA_DETAIL_C_RADKU',
            ),
            'VetaUB' => $pairs('VZZ_C_RADKU'),
        ];
    }

    /** @return array<string,mixed> */
    private function period(int $supplierId, int $periodId): array
    {
        $period = $this->periods->findById($supplierId, $periodId);
        if ($period === null) {
            throw new ReportException('period_not_found', 'Účetní období #' . $periodId . ' neexistuje.', 404);
        }

        return $period;
    }

    /** @param array<string,mixed> $period */
    private static function yearOf(array $period): int
    {
        return (int) substr((string) $period['ends_on'], 0, 4);
    }

    /** @return array<string,mixed>|null */
    private function filedReturnRow(int $supplierId, int $year): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, status, submitted_at, form_variant, xml_content
               FROM tax_submissions
              WHERE supplier_id = ? AND form_code = 'dppdp9' AND period_year = ?
                AND status IN ('submitted','accepted')
           ORDER BY submitted_at DESC, generated_at DESC, id DESC
              LIMIT 1"
        );
        $stmt->execute([$supplierId, $year]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /** Hlavička poplatníka pro builder — tytéž sloupce, jaké čte TaxReturnService. */
    private function loadSupplier(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT s.id, s.company_name, s.street, s.city, s.zip,
                    COALESCE(c.iso2, 'CZ') AS country_iso2,
                    s.ic, s.dic, s.taxpayer_type, s.financial_office_code,
                    s.workplace_code, s.cz_nace_code, s.phone, s.email,
                    s.street_number_pop, s.street_number_orient,
                    s.opr_jmeno, s.opr_prijmeni, s.opr_postaveni, s.epo_taxpayer_code
               FROM supplier s
          LEFT JOIN countries c ON c.id = s.country_id
              WHERE s.id = ?"
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new ReportException('supplier_not_found', 'Firma #' . $supplierId . ' neexistuje.', 404);
        }

        return $row;
    }
}
