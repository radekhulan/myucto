<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Abra;

/**
 * Čte konzistentní, lokálně omezený snímek účetnictví z read-only API ABRA Flexi.
 *
 * Changes API se nikdy nezapíná. Je-li dostupné, první načtení si před exportem
 * poznamená globální verzi a po exportu přehraje změny. Bez něj se používá
 * překryv nad lastUpdate; smazání pak zdroj neumí spolehlivě oznámit.
 */
final class AbraSnapshotBuilder
{
    public const OVERLAP_SECONDS = 300;

    /** @var list<string> */
    public const EVIDENCES = [
        'ucetni-obdobi',
        'ucetni-osnova',
        'ucet',
        'adresar',
        'faktura-vydana',
        'faktura-prijata',
        'prodejka',
        'zavazek',
        'pohledavka',
        'bankovni-ucet',
        'majetek',
        'banka',
        'pokladni-pohyb',
        'vazba',
        'ucetni-denik',
        'pohyb-na-uctech',
        'stav-uctu',
    ];

    /** @var array<string,string|list<string>> evidence Changes API => evidence snímku */
    private const CHANGE_EVIDENCES = [
        'ucetni-obdobi' => 'ucetni-obdobi',
        'ucetni-osnova' => 'ucetni-osnova',
        'ucet' => 'ucet',
        'adresar' => 'adresar',
        'faktura-vydana' => 'faktura-vydana',
        'faktura-vydana-polozka' => 'faktura-vydana',
        'faktura-prijata' => 'faktura-prijata',
        'faktura-prijata-polozka' => 'faktura-prijata',
        'prodejka' => 'prodejka',
        'prodejka-polozka' => 'prodejka',
        'bankovni-ucet' => 'bankovni-ucet',
        'majetek' => 'majetek',
        'banka' => 'banka',
        'banka-polozka' => 'banka',
        'pokladni-pohyb' => 'pokladni-pohyb',
        'pokladni-pohyb-polozka' => 'pokladni-pohyb',
        'vazba' => 'vazba',
        'ucetni-denik' => 'ucetni-denik',
        'pohyb-na-uctech' => 'pohyb-na-uctech',
        'stav-uctu' => 'stav-uctu',
        'interni-doklad' => 'ucetni-denik',
        'interni-doklad-polozka' => 'ucetni-denik',
        'pohledavka' => ['pohledavka', 'ucetni-denik'],
        'pohledavka-polozka' => ['pohledavka', 'ucetni-denik'],
        'zavazek' => 'zavazek',
        'zavazek-polozka' => 'zavazek',
        'vzajemny-zapocet' => 'ucetni-denik',
        'majetek-udalost' => 'ucetni-denik',
        'ucetni-odpis' => 'ucetni-denik',
        'skladovy-pohyb' => 'ucetni-denik',
        'skladovy-pohyb-polozka' => 'ucetni-denik',
    ];

    /** @var list<string> evidence, jejichž řádky se omezují na vybrané roky */
    private const PERIOD_EVIDENCES = [
        'ucetni-obdobi', 'faktura-vydana', 'faktura-prijata', 'prodejka', 'zavazek', 'pohledavka', 'banka',
        'pokladni-pohyb', 'ucetni-denik', 'pohyb-na-uctech', 'stav-uctu',
    ];

    /** @var list<string> */
    private const ACCOUNTING_CONTROL_TRIGGERS = [
        'ucetni-obdobi', 'faktura-vydana', 'faktura-prijata', 'prodejka', 'zavazek', 'pohledavka', 'banka',
        'pokladni-pohyb', 'ucetni-denik', 'pohyb-na-uctech', 'stav-uctu',
    ];

    /** @var list<string> */
    private const RELATION_EVIDENCES = [
        'faktura-vydana', 'faktura-prijata', 'prodejka', 'zavazek', 'pohledavka', 'banka', 'pokladni-pohyb',
    ];

    /** @var list<string> */
    private const FISCAL_DATE_EVIDENCES = [
        'faktura-vydana', 'faktura-prijata', 'prodejka', 'zavazek', 'pohledavka', 'banka', 'pokladni-pohyb',
        'ucetni-denik', 'pohyb-na-uctech',
    ];

    /** @var list<string> */
    private const HISTORICAL_CODEBOOK_EVIDENCES = [
        'ucetni-osnova', 'ucet', 'adresar',
    ];

    /** @var list<string> */
    private const DATE_FIELDS = [
        'datUcto', 'datumUctovani', 'datVyst', 'datPrij', 'datDokl', 'datPohybu',
        'datum', 'datOd', 'platiOdData', 'zacatek', 'rok', 'year', 'ucetniRok',
    ];

    private const MAX_CHANGE_PAGES = 400;
    private const MAX_REPLAY_ROUNDS = 2;
    private const MAX_LINKED_IDS_PER_QUERY = 100;

    /** @var array<string,bool> */
    private array $importedEndpointKeys = [];

    public function __construct(private readonly AbraReadOnlyClient $client) {}

    /** @param array<string,bool> $keys */
    public function useImportedEndpointKeys(array $keys): void
    {
        $this->importedEndpointKeys = $keys;
    }

    /**
     * @param array{url:string,username:string,password:string} $credentials
     * @param list<int> $years
     * @param array<string,mixed> $syncState
     * @param array<string,mixed> $company
     * @param callable(string,int,int):void $progress
     * @param callable():bool $cancelled
     * @return array<string,mixed>
     */
    public function build(array $credentials, array $years, string $mode, array $syncState,
        array $company, callable $progress, callable $cancelled): array
    {
        $years = self::years($years);
        if ($years === []) {
            throw new AbraException('invalid_year', 'Vyberte aspoň jeden účetní rok.');
        }
        if (!in_array($mode, ['initial', 'sync'], true)) {
            throw new AbraException('invalid_mode', 'Neplatný režim převodu ABRA Flexi.');
        }

        $startedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $vazbaCursor = self::nonNegativeInt($syncState['vazba_cursor'] ?? null) ?? 0;
        $changesEnabled = $this->client->changesStatus($credentials);
        $snapshot = array_fill_keys(self::EVIDENCES, []);
        $meta = [
            'periods' => [],
            'company' => [
                'ico' => mb_substr((string) ($company['ico'] ?? ''), 0, 20),
                'name' => mb_substr((string) ($company['name'] ?? ''), 0, 190),
                'base_currency' => self::currency($company['base_currency'] ?? null),
            ],
            'mode' => $mode,
            'years' => $years,
            'delta' => $mode === 'sync',
            'warnings' => [],
            'unavailable' => [],
            'deletions' => [],
            'unhandled_changes' => [],
            'excluded_by_period' => [],
            'cross_year_referenced' => [],
        ];

        try {
            $settings = $this->client->get($credentials, 'nastaveni')['winstrom']['nastaveni'] ?? [];
            $meta['source_oss'] = AbraOssSettingsImporter::sourceState(is_array($settings) ? $settings : [], $years);
        } catch (AbraException $error) {
            if (!in_array($error->errorCode, ['abra_http_400', 'abra_http_404'], true)) throw $error;
            $meta['source_oss'] = ['eu' => null, 'non_eu' => null, 'import' => null, 'valid_from' => null];
            $meta['warnings'][] = 'Nastavení OSS nelze ze zdroje přečíst. Cílová firma se automaticky nezmění.';
        }

        if ($mode === 'initial') {
            if ($changesEnabled) {
                $baseline = $this->globalVersion($this->client->get($credentials, 'ucetni-obdobi', [
                    'limit' => 1,
                    'detail' => 'id',
                    'add-global-version' => 'true',
                ]));
                $this->fetch($credentials, self::EVIDENCES, [], $snapshot, $meta, $progress, $cancelled, $years);
                $state = $this->replayChanges($credentials, $baseline + 1, $snapshot, $meta, $progress, $cancelled, $years);
            } else {
                $this->fetch($credentials, self::EVIDENCES, [], $snapshot, $meta, $progress, $cancelled, $years);
                $state = self::fallbackState($startedAt);
                $meta['warnings'][] = 'Changes API není zapnuté. Synchronizace používá lastUpdate s překryvem a nezachytí smazané záznamy.';
            }
        } elseif ($changesEnabled && ($syncState['strategy'] ?? null) === 'changes'
            && self::nonNegativeInt($syncState['cursor'] ?? null) !== null) {
            $state = $this->replayChanges(
                $credentials,
                self::nonNegativeInt($syncState['cursor']) + 1,
                $snapshot,
                $meta,
                $progress,
                $cancelled,
                $years,
            );
        } elseif ($changesEnabled) {
            $baseline = $this->globalVersion($this->client->get($credentials, 'ucetni-obdobi', [
                'limit' => 1,
                'detail' => 'id',
                'add-global-version' => 'true',
            ]));
            $query = $this->fallbackQuery($syncState, $startedAt);
            $this->fetch($credentials, self::EVIDENCES, $query, $snapshot, $meta, $progress, $cancelled, $years, true, $vazbaCursor);
            $state = $this->replayChanges($credentials, $baseline + 1, $snapshot, $meta, $progress, $cancelled, $years);
        } else {
            $query = $this->fallbackQuery($syncState, $startedAt);
            $this->fetch($credentials, self::EVIDENCES, $query, $snapshot, $meta, $progress, $cancelled, $years, true, $vazbaCursor);
            $state = self::fallbackState($startedAt);
            $meta['warnings'][] = 'Changes API není zapnuté. Synchronizace používá lastUpdate s překryvem a nezachytí smazané záznamy.';
        }

        foreach ($snapshot['vazba'] as $link) {
            if (!is_array($link)) continue;
            $id = self::nonNegativeInt($link['id'] ?? null);
            if ($id !== null) $vazbaCursor = max($vazbaCursor, $id);
        }
        $state['vazba_cursor'] = $vazbaCursor;
        $this->scopeToYears($snapshot, $years, $meta);
        $this->fetchLinkedInvoices($credentials, $snapshot, $meta, $cancelled);
        $this->captureVatProjection($credentials, $snapshot, $years, $meta, $progress, $cancelled);
        $meta['periods'] = array_values($snapshot['ucetni-obdobi']);
        $meta['sync_state'] = $state;
        $meta['warnings'] = array_values(array_unique(array_map(
            static fn (mixed $warning): string => mb_substr((string) $warning, 0, 300),
            $meta['warnings'],
        )));
        $snapshot['_meta'] = $meta;
        return $snapshot;
    }

    public function capturePages(?\Closure $observer): void { $this->client->capturePages($observer); }

    public function usePageCache(?AbraPageCache $cache): void { $this->client->usePageCache($cache); }

    /**
     * @param list<string> $evidences
     * @param array<string,mixed> $query
     * @param array<string,mixed> $snapshot
     * @param array<string,mixed> $meta
     * @param callable(string,int,int):void $progress
     * @param callable():bool $cancelled
     * @param list<int> $years
     */
    private function fetch(array $credentials, array $evidences, array $query, array &$snapshot,
        array &$meta, callable $progress, callable $cancelled, array $years,
        bool $retryWithoutFilter = false, ?int $vazbaCursor = null): void
    {
        $total = count($evidences);
        foreach ($evidences as $index => $evidence) {
            $this->assertNotCancelled($cancelled);
            $pageProgress = static fn (int $done, int $all) => $progress($evidence, $done, $all);
            $progress($evidence, 0, 0);
            $evidenceQuery = $query;
            if ($evidence === 'vazba' && $vazbaCursor !== null) {
                $evidenceQuery['filter'] = 'id > ' . $vazbaCursor;
            }
            if ($evidence === 'ucetni-obdobi' || $evidence === 'bankovni-ucet') {
                unset($evidenceQuery['filter']);
            }
            if (in_array($evidence, ['ucetni-denik', 'pohyb-na-uctech'], true)) {
                $evidenceQuery['postingState'] = 'posted';
            }
            if (in_array($evidence, self::RELATION_EVIDENCES, true)) {
                $evidenceQuery['relations'] = 'polozkyDokladu,vazby,vazebni-doklady';
            }
            if (in_array($evidence, self::HISTORICAL_CODEBOOK_EVIDENCES, true)) {
                $evidenceQuery['filtrovat-platnost'] = 'false';
            }
            if (in_array($evidence, self::FISCAL_DATE_EVIDENCES, true)) {
                $periodFilter = self::fiscalDateFilter($snapshot['ucetni-obdobi'], $years);
                if ($periodFilter !== null) {
                    $evidenceQuery['filter'] = isset($evidenceQuery['filter'])
                        ? '(' . $evidenceQuery['filter'] . ') and (' . $periodFilter . ')'
                        : $periodFilter;
                }
            }
            try {
                $snapshot[$evidence] = $evidence === 'stav-uctu'
                    ? $this->balances($credentials, $snapshot['ucetni-obdobi'], $years, $cancelled)
                    : $this->client->list($credentials, $evidence, $evidenceQuery, $pageProgress, $cancelled);
            } catch (AbraException $e) {
                if ($retryWithoutFilter && isset($evidenceQuery['filter'])
                    && !in_array($evidence, self::PERIOD_EVIDENCES, true)
                    && $evidence !== 'vazba'
                    && in_array($e->errorCode, ['abra_http_400', 'abra_http_404'], true)) {
                    unset($evidenceQuery['filter']);
                    try {
                        $snapshot[$evidence] = $evidence === 'stav-uctu'
                            ? $this->balances($credentials, $snapshot['ucetni-obdobi'], $years, $cancelled)
                            : $this->client->list($credentials, $evidence, $evidenceQuery, $pageProgress, $cancelled);
                    } catch (AbraException $retry) {
                        if (!$this->unavailable($retry)) {
                            throw $retry;
                        }
                        $this->recordUnavailable($evidence, $snapshot, $meta);
                    }
                } elseif ($this->unavailable($e)) {
                    $this->recordUnavailable($evidence, $snapshot, $meta);
                } else {
                    throw $e;
                }
            }
            if ($evidence === 'ucetni-obdobi') {
                self::assertUnambiguousPeriods($snapshot[$evidence], $years);
            }
            $progress($evidence, $index + 1, $total);
        }
    }

    /**
     * Po každém přehrání znovu načte celé dotčené evidence. Následující kolo zachytí
     * změny, které vznikly během jejich načítání.
     *
     * @param array<string,mixed> $snapshot
     * @param array<string,mixed> $meta
     * @param callable(string,int,int):void $progress
     * @param callable():bool $cancelled
     * @param list<int> $years
     * @return array{strategy:string,cursor:int,deletions_tracked:bool}
     */
    private function replayChanges(array $credentials, int $start, array &$snapshot, array &$meta,
        callable $progress, callable $cancelled, array $years): array
    {
        $cursor = max(0, $start);
        $checkpoint = max(0, $start - 1);
        for ($round = 0; $round < self::MAX_REPLAY_ROUNDS; $round++) {
            $drained = $this->drainChanges($credentials, $cursor, $cancelled);
            $checkpoint = $drained['cursor'];
            foreach ($drained['deletions'] as $evidence => $count) {
                $meta['deletions'][$evidence] = (int) ($meta['deletions'][$evidence] ?? 0) + $count;
            }
            foreach ($drained['unknown'] as $evidence => $count) {
                $meta['unhandled_changes'][$evidence] = (int) ($meta['unhandled_changes'][$evidence] ?? 0) + $count;
            }
            if ($drained['evidences'] === []) {
                break;
            }
            $evidences = $drained['evidences'];
            if (in_array('banka', $evidences, true)) {
                $evidences[] = 'bankovni-ucet';
                $evidences = array_values(array_unique($evidences));
            }
            if (array_intersect($evidences, self::ACCOUNTING_CONTROL_TRIGGERS) !== []) {
                $evidences = array_values(array_unique([
                    ...$evidences,
                    'ucetni-obdobi',
                    'ucetni-denik',
                    'pohyb-na-uctech',
                    'stav-uctu',
                ]));
            }
            usort($evidences, static fn (string $a, string $b): int
                => array_search($a, self::EVIDENCES, true) <=> array_search($b, self::EVIDENCES, true));
            $this->fetch($credentials, $evidences, [], $snapshot, $meta, $progress, $cancelled, $years);
            $cursor = $checkpoint + 1;
            if ($round === self::MAX_REPLAY_ROUNDS - 1) {
                throw new AbraException('source_busy', 'Data v ABRA Flexi se během načítání opakovaně mění. Opakujte synchronizaci později.');
            }
        }

        foreach ($meta['deletions'] as $evidence => $count) {
            $meta['warnings'][] = "ABRA Flexi hlásí {$count} smazaných záznamů evidence {$evidence}. Již zaúčtované místní záznamy se automaticky nemažou.";
        }
        foreach ($meta['unhandled_changes'] as $evidence => $count) {
            $meta['warnings'][] = "ABRA Flexi hlásí {$count} změn nepodporované evidence {$evidence}; změny byly zaznamenány, ale nepřevádějí se.";
        }
        return ['strategy' => 'changes', 'cursor' => $checkpoint, 'deletions_tracked' => true];
    }

    /**
     * @param callable():bool $cancelled
     * @return array{evidences:list<string>,deletions:array<string,int>,unknown:array<string,int>,cursor:int}
     */
    private function drainChanges(array $credentials, int $start, callable $cancelled): array
    {
        $affected = [];
        $deletions = [];
        $unknown = [];
        $cursor = max(0, $start);
        $checkpoint = max(0, $start - 1);
        for ($page = 0; $page < self::MAX_CHANGE_PAGES; $page++) {
            $this->assertNotCancelled($cancelled);
            $root = $this->client->changes($credentials, $cursor)['winstrom'] ?? null;
            if (!is_array($root) || !is_array($root['changes'] ?? null) || !array_is_list($root['changes'])) {
                throw new AbraException('invalid_changes', 'ABRA Flexi nevrátila platný seznam změn.');
            }
            $global = self::nonNegativeInt($root['@globalVersion'] ?? null);
            if ($global === null) {
                throw new AbraException('invalid_changes', 'ABRA Flexi nevrátila platný bod synchronizace.');
            }
            $checkpoint = max($checkpoint, $global);
            foreach ($root['changes'] as $change) {
                if (!is_array($change)) {
                    throw new AbraException('invalid_changes', 'ABRA Flexi nevrátila platný záznam změny.');
                }
                $sourceEvidence = (string) ($change['@evidence'] ?? '');
                $evidences = (array) (self::CHANGE_EVIDENCES[$sourceEvidence] ?? []);
                if ($evidences === []) {
                    $label = self::safeEvidence($sourceEvidence);
                    $unknown[$label] = ($unknown[$label] ?? 0) + 1;
                    continue;
                }
                foreach ($evidences as $evidence) {
                    $affected[$evidence] = true;
                    if (($change['@operation'] ?? null) === 'delete') {
                        $deletions[$evidence] = ($deletions[$evidence] ?? 0) + 1;
                    }
                }
            }
            $next = $root['next'] ?? 'none';
            if ($next === 'none' || $next === null || $next === '') {
                return [
                    'evidences' => array_values(array_keys($affected)),
                    'deletions' => $deletions,
                    'unknown' => $unknown,
                    'cursor' => $checkpoint,
                ];
            }
            $nextCursor = self::nonNegativeInt($next);
            if ($nextCursor === null || $nextCursor <= $cursor) {
                throw new AbraException('invalid_changes', 'ABRA Flexi vrátila neplatné pokračování seznamu změn.');
            }
            $cursor = $nextCursor;
        }
        throw new AbraException('changes_limit', 'ABRA Flexi vrátila příliš mnoho změn v jednom běhu. Spusťte synchronizaci znovu.');
    }

    /**
     * Stavy účtů bez parametru vracejí jen aktuální období. Každý vybraný rok se proto
     * čte zvlášť a označí se obdobím pro víceletou rekonciliaci.
     *
     * @param list<mixed> $periods
     * @param list<int> $years
     * @param callable():bool $cancelled
     * @return list<array<string,mixed>>
     */
    private function balances(array $credentials, array $periods, array $years, callable $cancelled): array
    {
        $out = [];
        foreach ($years as $year) {
            $matches = array_values(array_filter($periods, static fn (mixed $period): bool
                => is_array($period) && AbraSource::periodYear($period) === $year));
            if ($matches === []) {
                continue;
            }
            $period = $matches[0];
            $id = $period['id'] ?? $period['kod'] ?? null;
            if (!is_int($id) && !is_string($id)) {
                throw new AbraException('invalid_period', "Účetní období roku {$year} nemá platný identifikátor.");
            }
            $id = trim((string) $id);
            if ($id === '') {
                continue;
            }
            foreach ($this->client->list($credentials, 'stav-uctu', [
                'idUcetniObdobi' => $id,
            ], null, $cancelled) as $row) {
                if (is_array($row)) {
                    $row['postingPeriod'] ??= 'code:' . $year;
                    $out[] = $row;
                }
            }
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $snapshot
     * @param array<string,mixed> $meta
     * @param callable():bool $cancelled
     */
    private function fetchLinkedInvoices(array $credentials, array &$snapshot, array &$meta, callable $cancelled): void
    {
        $wanted = self::linkedInvoiceKeys($snapshot, $this->importedEndpointKeys);

        foreach (array_keys($wanted) as $evidence) {
            $existing = [];
            foreach ($snapshot[$evidence] as $row) {
                if (is_array($row)) {
                    $id = self::positiveId(AbraSource::reference($row['id'] ?? null));
                    if ($id !== null) {
                        $existing[$id] = true;
                    }
                }
            }
            $ids = array_values(array_filter(array_keys($wanted[$evidence]),
                fn (int $id): bool => !isset($existing[$id])));
            sort($ids, SORT_NUMERIC);
            $added = 0;
            foreach (array_chunk($ids, self::MAX_LINKED_IDS_PER_QUERY) as $batch) {
                $this->assertNotCancelled($cancelled);
                $rows = $this->client->list($credentials, $evidence, [
                    'filter' => 'id in (' . implode(',', $batch) . ')',
                    'relations' => 'polozkyDokladu,vazby,vazebni-doklady',
                ], null, $cancelled);
                foreach ($rows as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $id = self::positiveId(AbraSource::reference($row['id'] ?? null));
                    if ($id === null || !isset($wanted[$evidence][$id]) || isset($existing[$id])) {
                        continue;
                    }
                    $snapshot[$evidence][] = $row;
                    $existing[$id] = true;
                    $added++;
                }
            }
            if ($added > 0) {
                $meta['cross_year_referenced'][$evidence] = $added;
            }
        }
    }

    private function captureVatProjection(array $credentials, array $snapshot, array $years, array &$meta,
        callable $progress, callable $cancelled): void
    {
        $selected = [];
        $documentDates = [];
        foreach (AbraSource::DOCUMENT_EVIDENCES as $evidence) {
            foreach (AbraSource::rows($snapshot, $evidence) as $row) {
                $key = AbraSource::sourceKey($row);
                if ($key === '') continue;
                $selected[$evidence . '|' . $key] = true;
                $date = self::date($row['datUcto'] ?? null);
                if ($date !== null) $documentDates[] = $date;
            }
        }
        $meta['vat_projection'] = [];
        if ($selected === []) return;
        $filter = ($meta['mode'] ?? null) === 'initial'
            ? self::fiscalDateFilter($snapshot['ucetni-obdobi'], $years) : null;
        if ($filter === null && $documentDates !== []) {
            sort($documentDates);
            $firstDay = substr($documentDates[0], 0, 7) . '-01';
            $lastDay = (new \DateTimeImmutable($documentDates[array_key_last($documentDates)]))
                ->format('Y-m-t');
            $filter = "datUcto >= '{$firstDay}' and datUcto <= '{$lastDay}'";
        }
        if ($filter === null) $filter = self::fiscalDateFilter($snapshot['ucetni-obdobi'], $years);
        if ($filter === null) {
            $meta['warnings'][] = 'vat_projection_period_missing_requires_review';
            return;
        }
        $projection = [];
        $yearSet = array_fill_keys($years, true);
        try {
            $this->client->scan($credentials, 'podklady-dph', ['filter' => $filter],
                static function (array $rows) use (&$projection, $selected, $yearSet): void {
                    foreach ($rows as $row) {
                        $year = (int) ($row['rokDuzp'] ?? 0);
                        $month = (int) ($row['mesicDuzp'] ?? 0);
                        if (!isset($yearSet[$year]) || $month < 1 || $month > 12) continue;
                        $evidence = (string) ($row['idDokl@evidencePath'] ?? '');
                        $key = AbraSource::reference($row['idDokl'] ?? null);
                        $identity = $evidence . '|' . $key;
                        if ($key === '' || !isset($selected[$identity])) continue;
                        $class = AbraSource::reference($row['clenDph'] ?? null);
                        if ($class === '') continue;
                        $group = $year . '|' . $month . '|' . $class;
                        $projection[$identity][$group] ??= [
                            'year' => $year, 'month' => $month, 'class' => $class,
                            'base' => 0.0, 'vat' => 0.0, 'rows' => 0,
                        ];
                        $projection[$identity][$group]['base'] += (float) ($row['sumZklTuz'] ?? 0);
                        $projection[$identity][$group]['vat'] += (float) ($row['vypSumDphTuz'] ?? 0);
                        ++$projection[$identity][$group]['rows'];
                    }
                },
                static fn (int $done, int $total) => $progress('podklady-dph', $done, $total),
                $cancelled);
        } catch (AbraException $error) {
            if (!$this->unavailable($error)) throw $error;
            $meta['warnings'][] = 'vat_projection_unavailable_requires_review';
            return;
        }
        foreach ($projection as $identity => $groups) {
            $meta['vat_projection'][$identity] = array_values(array_map(static function (array $group): array {
                $group['base'] = round($group['base'], 2);
                $group['vat'] = round($group['vat'], 2);
                return $group;
            }, $groups));
        }
    }

    /** @param array<string,bool> $importedEndpointKeys */
    public static function linkedInvoiceKeys(array $snapshot, array $importedEndpointKeys = []): array
    {
        $movementKeys = ['banka' => [], 'pokladni-pohyb' => []];
        $wanted = array_fill_keys(AbraSource::DOCUMENT_EVIDENCES, []);
        foreach ($importedEndpointKeys as $endpoint => $_) {
            [$evidence, $key] = array_pad(explode('|', $endpoint, 2), 2, '');
            if (isset($movementKeys[$evidence]) && $key !== '') {
                $movementKeys[$evidence][$key] = true;
            }
        }
        foreach (array_keys($movementKeys) as $evidence) {
            foreach ($snapshot[$evidence] ?? [] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $key = AbraSource::sourceKey($row);
                if ($key !== '') {
                    $movementKeys[$evidence][$key] = true;
                }
                self::collectInvoiceRelations($row, '', $wanted);
            }
        }
        foreach ($snapshot['vazba'] ?? [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $a = AbraSource::relation($row['a@ref'] ?? $row['a'] ?? null);
            $b = AbraSource::relation($row['b@ref'] ?? $row['b'] ?? null);
            if ($a !== null && $b !== null) {
                self::collectLinkedInvoiceRelation($a, $b, $movementKeys, $wanted);
                self::collectLinkedInvoiceRelation($b, $a, $movementKeys, $wanted);
            }
        }

        return $wanted;
    }

    /**
     * @param array<string,array<string,bool>> $movementKeys
     * @param array<string,array<int,bool>> $wanted
     * @param array{evidence:string,key:string} $movement
     * @param array{evidence:string,key:string} $other
     */
    private static function collectLinkedInvoiceRelation(array $movement, array $other,
        array $movementKeys, array &$wanted): void
    {
        $movementEvidence = self::relationEvidence($movement['evidence']);
        $invoiceEvidence = self::relationEvidence($other['evidence']);
        if (!isset($movementKeys[$movementEvidence][$movement['key']])
            || !isset($wanted[$invoiceEvidence])) {
            return;
        }
        $id = self::positiveId($other['key']);
        if ($id !== null) {
            $wanted[$invoiceEvidence][$id] = true;
        }
    }

    /** @param array<string,array<int,bool>> $wanted */
    private static function collectInvoiceRelations(mixed $value, string $hint, array &$wanted): void
    {
        $relation = AbraSource::relation($value);
        if ($relation !== null) {
            $evidence = self::relationEvidence($relation['evidence']) ?: $hint;
            $id = self::positiveId($relation['key']);
            if ($id !== null && isset($wanted[$evidence])) {
                $wanted[$evidence][$id] = true;
            }
        }
        if (!is_array($value)) {
            return;
        }
        foreach ($value as $key => $item) {
            $childHint = is_string($key) ? (self::relationEvidence($key) ?: $hint) : $hint;
            self::collectInvoiceRelations($item, $childHint, $wanted);
        }
    }

    private static function relationEvidence(string $value): string
    {
        $normalized = mb_strtolower(preg_replace('/[^a-z0-9]+/i', '', $value) ?? '');
        return match (true) {
            str_contains($normalized, 'fakturavydana') => 'faktura-vydana',
            str_contains($normalized, 'fakturaprijata') => 'faktura-prijata',
            str_contains($normalized, 'prodejka') => 'prodejka',
            str_contains($normalized, 'zavazek') => 'zavazek',
            str_contains($normalized, 'pohledavka') => 'pohledavka',
            str_contains($normalized, 'pokladnipohyb') => 'pokladni-pohyb',
            $normalized === 'banka' => 'banka',
            default => '',
        };
    }

    private static function positiveId(string $value): ?int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return is_int($id) ? $id : null;
    }

    private static function currency(mixed $value): string
    {
        $currency = mb_strtoupper(AbraSource::reference($value));
        return preg_match('/^[A-Z]{3}$/D', $currency) === 1 ? $currency : '';
    }

    /** @param list<mixed> $periods @param list<int> $years */
    private static function assertUnambiguousPeriods(array $periods, array $years): void
    {
        foreach ($years as $year) {
            $count = 0;
            foreach ($periods as $period) {
                if (is_array($period) && AbraSource::periodYear($period) === $year && ++$count > 1) {
                    throw new AbraException('ambiguous_period', "ABRA Flexi obsahuje více účetních období pro rok {$year}. Převod se nespustil.");
                }
            }
        }
    }

    /** @param list<mixed> $periods @param list<int> $years */
    private static function fiscalDateFilter(array $periods, array $years): ?string
    {
        $selected = array_fill_keys($years, true);
        $starts = [];
        $ends = [];
        foreach ($periods as $period) {
            if (!is_array($period) || !isset($selected[AbraSource::periodYear($period) ?? 0])) {
                continue;
            }
            $from = self::date($period['platiOdData'] ?? $period['datOd'] ?? null);
            $to = self::date($period['platiDoData'] ?? $period['datDo'] ?? null);
            if ($from !== null && $to !== null && $from <= $to) {
                $starts[] = $from;
                $ends[] = $to;
            }
        }
        if ($starts === [] || $ends === []) {
            return null;
        }
        sort($starts);
        sort($ends);
        return "datUcto >= '{$starts[0]}' and datUcto <= '{$ends[array_key_last($ends)]}'";
    }

    /**
     * @param array<string,mixed> $snapshot
     * @param list<int> $years
     * @param array<string,mixed> $meta
     */
    private function scopeToYears(array &$snapshot, array $years, array &$meta): void
    {
        $selected = array_fill_keys($years, true);
        $intervals = [];
        foreach ($snapshot['ucetni-obdobi'] as $period) {
            if (!is_array($period) || !isset($selected[AbraSource::periodYear($period) ?? 0])) {
                continue;
            }
            $from = self::date($period['platiOdData'] ?? $period['datOd'] ?? null);
            $to = self::date($period['platiDoData'] ?? $period['datDo'] ?? null);
            if ($from !== null && $to !== null && $from <= $to) {
                $intervals[] = [$from, $to];
            }
        }
        $outsideInvoices = [];
        foreach (self::PERIOD_EVIDENCES as $evidence) {
            $rows = $snapshot[$evidence];
            $kept = [];
            $unknown = 0;
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    $unknown++;
                    $kept[] = $row;
                    continue;
                }
                $inside = $evidence === 'ucetni-obdobi'
                    ? (($year = AbraSource::periodYear($row)) !== null ? isset($selected[$year]) : null)
                    : self::inSelectedPeriods($row, $selected, $intervals);
                if ($inside === null) {
                    $unknown++;
                    $kept[] = $row;
                } elseif ($inside) {
                    $kept[] = $row;
                } elseif (in_array($evidence, AbraSource::DOCUMENT_EVIDENCES, true)) {
                    $outsideInvoices[$evidence][] = $row;
                }
            }
            $excluded = count($rows) - count($kept);
            if ($excluded > 0) {
                $meta['excluded_by_period'][$evidence] = $excluded;
            }
            if ($unknown > 0) {
                $meta['warnings'][] = "Evidence {$evidence} obsahuje {$unknown} záznamů bez rozpoznatelného období; byly předány importeru ke kontrole.";
            }
            $snapshot[$evidence] = $kept;
        }

        $this->scopeLinks($snapshot, $meta);
        $references = [];
        foreach (['banka', 'pokladni-pohyb', 'vazba', 'ucetni-denik', 'pohyb-na-uctech'] as $evidence) {
            foreach ($snapshot[$evidence] as $row) {
                if (is_array($row)) {
                    if ($evidence === 'vazba') {
                        self::allScalarTokens($row, $references);
                        foreach (['a', 'b'] as $side) {
                            $relation = AbraSource::relation($row[$side . '@ref'] ?? $row[$side] ?? null);
                            if ($relation !== null && in_array(self::relationEvidence($relation['evidence']),
                                AbraSource::DOCUMENT_EVIDENCES, true)) {
                                $references[mb_strtolower($relation['key'])] = true;
                            }
                        }
                    } else {
                        self::references($row, false, $references);
                    }
                }
            }
        }
        foreach ($outsideInvoices as $evidence => $rows) {
            foreach ($rows as $row) {
                if (self::matchesReference($row, $references)) {
                    $snapshot[$evidence][] = $row;
                    $meta['excluded_by_period'][$evidence]--;
                }
            }
            if (($meta['excluded_by_period'][$evidence] ?? 0) <= 0) {
                unset($meta['excluded_by_period'][$evidence]);
            }
        }
    }

    /** @param array<string,mixed> $snapshot @param array<string,mixed> $meta */
    private function scopeLinks(array &$snapshot, array &$meta): void
    {
        $selected = $this->importedEndpointKeys;
        $untyped = [];
        foreach (['faktura-vydana', 'faktura-prijata', 'prodejka', 'zavazek', 'pohledavka', 'banka', 'pokladni-pohyb'] as $evidence) {
            foreach ($snapshot[$evidence] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                if (in_array($evidence, ['banka', 'pokladni-pohyb'], true)
                    && AbraSource::bool($row['storno'] ?? null) === true) continue;
                foreach (['id', 'kod', 'code', 'key', 'extId'] as $field) {
                    foreach (self::tokens($row[$field] ?? null) as $token) {
                        $selected[$evidence . '|' . $token] = true;
                        $untyped[$token] = true;
                    }
                }
            }
        }
        $kept = [];
        foreach ($snapshot['vazba'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $matches = false;
            $movementReferences = [];
            foreach (['a', 'b'] as $side) {
                $relation = AbraSource::relation($row[$side . '@ref'] ?? $row[$side] ?? null);
                $evidence = $relation !== null ? self::relationEvidence($relation['evidence']) : '';
                if (in_array($evidence, ['banka', 'pokladni-pohyb'], true)) {
                    $movementReferences[] = $evidence . '|' . $relation['key'];
                }
            }
            if ($movementReferences !== []) {
                $matches = array_intersect_key(array_fill_keys($movementReferences, true), $selected) !== [];
                if ($matches) $kept[] = $row;
                continue;
            }
            foreach (['a', 'b'] as $side) {
                $relation = AbraSource::relation($row[$side . '@ref'] ?? $row[$side] ?? null);
                $evidence = $relation !== null ? self::relationEvidence($relation['evidence']) : '';
                if ($evidence !== '') {
                    $matches = isset($selected[$evidence . '|' . $relation['key']]);
                } else {
                    $references = [];
                    self::allScalarTokens($row[$side] ?? null, $references);
                    $matches = array_intersect_key($references, $untyped) !== [];
                }
                if ($matches) break;
            }
            if ($matches) {
                $kept[] = $row;
            }
        }
        $excluded = count($snapshot['vazba']) - count($kept);
        if ($excluded > 0) {
            $meta['excluded_by_period']['vazba'] = $excluded;
        }
        $snapshot['vazba'] = $kept;
    }

    /** @param array<string,bool> $out */
    private static function allScalarTokens(mixed $value, array &$out): void
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                self::allScalarTokens($item, $out);
            }
            return;
        }
        foreach (self::tokens($value) as $token) {
            $out[$token] = true;
        }
    }

    /** @param array<string,bool> $out */
    private static function references(array $value, bool $referenceContext, array &$out): void
    {
        foreach ($value as $key => $item) {
            $context = $referenceContext || (is_string($key)
                && preg_match('/faktur|doklad|vazb/i', $key) === 1);
            if (is_array($item)) {
                self::references($item, $context, $out);
            } elseif ($context && (is_string($item) || is_int($item))) {
                foreach (self::tokens($item) as $token) {
                    $out[$token] = true;
                }
            }
        }
    }

    /** @param array<string,bool> $references */
    private static function matchesReference(array $row, array $references): bool
    {
        foreach (['id', 'kod', 'code', 'key', 'extId'] as $field) {
            if (!array_key_exists($field, $row)) {
                continue;
            }
            foreach (self::tokens($row[$field]) as $token) {
                if (isset($references[$token])) {
                    return true;
                }
            }
        }
        return false;
    }

    /** @return list<string> */
    private static function tokens(mixed $value): array
    {
        if (!is_string($value) && !is_int($value)) {
            return [];
        }
        $value = trim((string) $value);
        if ($value === '') {
            return [];
        }
        $tokens = [mb_strtolower($value)];
        if (str_contains($value, '/')) {
            $tokens[] = mb_strtolower((string) preg_replace('~^.*/~', '', $value));
        }
        if (preg_match('/^(?:id|code|key|ext):(.+)$/i', $value, $match) === 1) {
            $tokens[] = mb_strtolower($match[1]);
        }
        return array_values(array_unique($tokens));
    }

    private static function rowPeriodYear(array $row): ?int
    {
        foreach (['postingPeriod', 'ucetniObdobi', 'obdobi'] as $field) {
            $value = $row[$field] ?? null;
            if (is_string($value) && preg_match('/(?:19|20|21|22)\d{2}/', $value, $match) === 1) {
                return (int) $match[0];
            }
        }
        return null;
    }

    /** @param array<int,bool> $selected @param list<array{string,string}> $intervals */
    private static function inSelectedPeriods(array $row, array $selected, array $intervals): ?bool
    {
        $periodYear = self::rowPeriodYear($row);
        if ($periodYear !== null) {
            return isset($selected[$periodYear]);
        }
        $date = self::rowDate($row);
        if ($date === null) {
            return null;
        }
        foreach ($intervals as [$from, $to]) {
            if ($date >= $from && $date <= $to) {
                return true;
            }
        }
        return $intervals !== [] ? false : isset($selected[(int) substr($date, 0, 4)]);
    }

    private static function rowDate(array $row): ?string
    {
        foreach (self::DATE_FIELDS as $field) {
            $date = self::date($row[$field] ?? null);
            if ($date !== null) {
                return $date;
            }
        }
        return null;
    }

    private static function date(mixed $value): ?string
    {
        if (!is_string($value) || preg_match('/^((?:19|20|21|22)\d{2}-\d{2}-\d{2})/', $value, $match) !== 1) {
            return null;
        }
        return checkdate((int) substr($match[1], 5, 2), (int) substr($match[1], 8, 2), (int) substr($match[1], 0, 4))
            ? $match[1]
            : null;
    }

    /** @param list<int> $years @return list<int> */
    private static function years(array $years): array
    {
        $out = [];
        foreach ($years as $year) {
            if (is_int($year) && $year >= 1900 && $year <= 2200) {
                $out[$year] = $year;
            }
        }
        sort($out);
        return array_values($out);
    }

    /** @return array{strategy:string,watermark:string,overlap_seconds:int,deletions_tracked:bool} */
    private static function fallbackState(\DateTimeImmutable $watermark): array
    {
        return [
            'strategy' => 'last_update',
            'watermark' => $watermark->format(DATE_ATOM),
            'overlap_seconds' => self::OVERLAP_SECONDS,
            'deletions_tracked' => false,
        ];
    }

    /** @param array<string,mixed> $syncState @return array{filter:string} */
    private function fallbackQuery(array $syncState, \DateTimeImmutable $now): array
    {
        $raw = (string) ($syncState['watermark'] ?? '');
        if ($raw === '') {
            throw new AbraException('invalid_checkpoint', 'Uložený bod synchronizace ABRA Flexi chybí.');
        }
        try {
            $watermark = new \DateTimeImmutable($raw);
        } catch (\Throwable) {
            throw new AbraException('invalid_checkpoint', 'Uložený bod synchronizace ABRA Flexi není platný.');
        }
        $overlap = self::nonNegativeInt($syncState['overlap_seconds'] ?? self::OVERLAP_SECONDS);
        $overlap = min(3600, $overlap ?? self::OVERLAP_SECONDS);
        $from = $watermark->setTimezone(new \DateTimeZone('UTC'))->modify("-{$overlap} seconds");
        return ['filter' => "lastUpdate > '" . $from->format('Y-m-d\TH:i:sP') . "'"];
    }

    private function globalVersion(array $payload): int
    {
        $version = self::nonNegativeInt($payload['winstrom']['@globalVersion'] ?? null);
        if ($version === null) {
            throw new AbraException('missing_global_version', 'ABRA Flexi nevrátila globální verzi pro bezpečné načtení dat.');
        }
        return $version;
    }

    private function unavailable(AbraException $e): bool
    {
        return in_array($e->errorCode, ['abra_http_400', 'abra_http_404'], true);
    }

    /** @param array<string,mixed> $snapshot @param array<string,mixed> $meta */
    private function recordUnavailable(string $evidence, array &$snapshot, array &$meta): void
    {
        $snapshot[$evidence] = [];
        $meta['unavailable'][] = $evidence;
        $meta['warnings'][] = "Evidence {$evidence} není v připojené firmě dostupná.";
    }

    /** @param callable():bool $cancelled */
    private function assertNotCancelled(callable $cancelled): void
    {
        if ($cancelled()) {
            throw new AbraException('cancelled', 'Převod byl zrušen.', [], 409);
        }
    }

    private static function nonNegativeInt(mixed $value): ?int
    {
        $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        return is_int($parsed) ? $parsed : null;
    }

    private static function safeEvidence(string $evidence): string
    {
        return preg_match('/^[a-z][a-z0-9-]{0,79}$/D', $evidence) === 1 ? $evidence : 'neznámá';
    }
}
