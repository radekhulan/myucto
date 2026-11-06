<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Reports;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\LedgerReportRepository;
use MyInvoice\Repository\StatementDefinitionRepository;
use MyInvoice\Repository\StatementOverrideRepository;
use PDO;

/**
 * Výjimky mapování účtů do výkazů pro konkrétní firmu — přehled účtů s řádkem, kam dnes
 * jdou, validace a uložení výjimek a náhled dopadu na výkaz.
 *
 * Mapu nikdy neskládá sama: čte ji z {@see StatementMapResolver} a řádek účtu určuje
 * {@see StatementMapper::entriesFor()}, tedy stejným pravidlem, jakým účet zařadí výkaz.
 */
final class StatementOverrideService
{
    public const TYPES = ['balance_sheet', 'income_statement', FinancialStatementService::TYPE_PURPOSE];

    /** Prefixy, které v zůstatcích zachovají kód každého listového účtu (analytiky zvlášť). */
    private const ALL_LEAVES = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

    private const ACCOUNT_TYPES = [
        'balance_sheet' => ['asset', 'liability', 'equity'],
        'income_statement' => ['revenue', 'expense'],
        FinancialStatementService::TYPE_PURPOSE => ['revenue', 'expense'],
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly StatementDefinitionRepository $definitions,
        private readonly StatementOverrideRepository $overrides,
        private readonly StatementMapResolver $maps,
        private readonly StatementMapper $mapper,
        private readonly LedgerReportRepository $ledger,
        private readonly AccountingPeriodRepository $periods,
        private readonly FinancialStatementService $statements,
        private readonly StatementFrameworkResolver $frameworkResolver,
    ) {}

    /**
     * Období a verze výkazu platná ke konci období. Výjimky slouží roční závěrce a příloze
     * DPPO, proto se editor i náhled počítají k poslednímu dni období (u uzavřeného roku
     * totéž, co výkaz bez `as_of`; u běžného roku zahrne vše, co je zatím zaúčtované).
     *
     * @return array{period: array<string,mixed>, version: array<string,mixed>, as_of: string}
     */
    public function context(int $supplierId, int $periodId, string $type): array
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new ReportException('validation_failed', 'statement_type musí být jedno z: ' . implode(', ', self::TYPES) . '.', 422);
        }
        $period = $this->periods->findById($supplierId, $periodId);
        if ($period === null) {
            throw new ReportException('period_not_found', 'Účetní období #' . $periodId . ' neexistuje.', 404);
        }
        $asOf = (string) $period['ends_on'];
        if ($this->frameworkResolver->context($supplierId, $periodId, $asOf)['framework'] === 'cz_504') {
            throw new ReportException('statement_mapping_editor_unsupported', 'Editor mapování výkazů pro rámec 504 zatím není k dispozici.');
        }
        $version = $this->definitions->findVersion($type, $asOf);
        if ($version === null) {
            throw new ReportException('statement_version_missing', 'Pro rozvahový den ' . $asOf . ' neexistuje verze mapování výkazu.');
        }

        return ['period' => $period, 'version' => $version, 'as_of' => $asOf];
    }

    /**
     * Editor výjimek: řádky výkazu, výjimky firmy a účty s tím, kam je výkaz dnes zařadí.
     *
     * @return array<string,mixed>
     */
    public function overview(int $supplierId, int $periodId, string $type): array
    {
        $ctx = $this->context($supplierId, $periodId, $type);
        $version = $ctx['version'];
        $versionId = (int) $version['id'];
        $map = $this->maps->accountMap($version, $supplierId, (int) $ctx['period']['fiscal_year']);

        return [
            'statement_type' => $type,
            'version'        => [
                'id'             => $versionId,
                'statement_type' => (string) $version['statement_type'],
                'version_code'   => (string) $version['version_code'],
            ],
            'period'         => [
                'id'          => (int) $ctx['period']['id'],
                'fiscal_year' => (int) $ctx['period']['fiscal_year'],
                'starts_on'   => (string) $ctx['period']['starts_on'],
                'ends_on'     => (string) $ctx['period']['ends_on'],
            ],
            'as_of'          => $ctx['as_of'],
            'rows'           => $this->rowsWithValues($type, $supplierId, $periodId, $ctx['as_of'], $versionId),
            'overrides'      => array_map(self::overrideOut(...), $this->overrides->forVersion($supplierId, $versionId)),
            'accounts'       => $this->accounts($supplierId, $type, $ctx, $map),
        ];
    }

    /**
     * Nahradí celou sadu výjimek firmy pro verzi výkazu.
     *
     * @param list<array<string,mixed>> $items
     * @return list<array<string,mixed>>
     */
    public function save(int $supplierId, int $versionId, array $items, ?int $userId): array
    {
        $version = $this->versionOrFail($versionId);
        $normalized = $this->validateSet($supplierId, $version, $items);
        $this->overrides->replaceForVersion($supplierId, $versionId, $normalized, $userId);

        return array_map(self::overrideOut(...), $this->overrides->forVersion($supplierId, $versionId));
    }

    /**
     * Přidá jednu výjimku. Validuje se celá výsledná sada, aby nevznikl duplicitní prefix.
     *
     * @param array<string,mixed> $item
     * @return array<string,mixed>
     */
    public function create(int $supplierId, int $versionId, array $item, ?int $userId): array
    {
        $existing = $this->overrides->forVersion($supplierId, $this->versionOrFail($versionId)['id']);
        $saved = $this->save($supplierId, $versionId, [...$existing, $item], $userId);
        $from = $item['valid_from_year'] ?? null;
        $key = self::key(
            rtrim(trim((string) ($item['account_prefix'] ?? '')), '*'),
            (string) ($item['balance_condition'] ?? 'any'),
            $from === null || $from === '' ? null : (int) $from,
        );
        foreach ($saved as $o) {
            if (self::key($o['account_prefix'], $o['balance_condition'], $o['valid_from_year']) === $key) {
                return $o;
            }
        }

        return $saved[array_key_last($saved)] ?? [];
    }

    /**
     * @param array<string,mixed> $item
     * @return array<string,mixed>
     */
    public function update(int $supplierId, int $id, array $item): array
    {
        $current = $this->overrides->find($supplierId, $id);
        if ($current === null) {
            throw new ReportException('not_found', 'Výjimka mapování nenalezena.', 404);
        }
        $version = $this->versionOrFail((int) $current['version_id']);
        $set = [];
        foreach ($this->overrides->forVersion($supplierId, (int) $version['id']) as $o) {
            $set[] = $o['id'] === $id ? $item + $current : $o;
        }
        $normalized = $this->validateSet($supplierId, $version, $set);
        foreach ($normalized as $n) {
            if (($n['id'] ?? null) === $id) {
                $this->overrides->update($supplierId, $id, $n);
            }
        }

        return self::overrideOut((array) $this->overrides->find($supplierId, $id));
    }

    public function delete(int $supplierId, int $id): void
    {
        if ($this->overrides->find($supplierId, $id) === null) {
            throw new ReportException('not_found', 'Výjimka mapování nenalezena.', 404);
        }
        $this->overrides->delete($supplierId, $id);
    }

    /**
     * Náhled dopadu neuložené sady výjimek: řádky výkazu, jejichž hodnota se změní.
     * Nic se neukládá — výkaz se postaví dvakrát, podruhé přes {@see StatementMapResolver::simulate()}.
     *
     * @param list<array<string,mixed>> $items
     * @return array<string,mixed>
     */
    public function preview(int $supplierId, int $periodId, string $type, array $items): array
    {
        $ctx = $this->context($supplierId, $periodId, $type);
        $version = $ctx['version'];
        $normalized = $this->validateSet($supplierId, $version, $items);

        $build = fn (): array => $this->statementValues($type, $supplierId, $periodId, $ctx['as_of']);
        $before = $build();
        $after = $this->maps->simulate($supplierId, (int) $version['id'], $normalized, $build);

        $changed = [];
        foreach ($after['rows'] as $code => $row) {
            $old = $before['rows'][$code]['value'] ?? 0.0;
            if (self::cents($old) === self::cents($row['value'])) {
                continue;
            }
            $changed[] = [
                'row_code'     => $code,
                'display_code' => $row['display_code'],
                'label'        => $row['label'],
                'level'        => $row['level'],
                'section'      => $row['section'],
                'before'       => $old,
                'after'        => $row['value'],
                'delta'        => round($row['value'] - $old, 2),
            ];
        }

        return [
            'statement_type'  => $type,
            'version_id'      => (int) $version['id'],
            'rows'            => $changed,
            'balanced_before' => $before['balanced'],
            'balanced_after'  => $after['balanced'],
        ];
    }

    /**
     * Validace a normalizace sady výjimek pro verzi. Vrací tvar pro repository.
     *
     * @param array<string,mixed>       $version
     * @param list<array<string,mixed>> $items
     * @return list<array{id?:int,account_prefix:string,row_code:string,target:string,balance_condition:string,sign:int,note:?string}>
     */
    public function validateSet(int $supplierId, array $version, array $items): array
    {
        $rows = [];
        foreach ($this->definitions->rows((int) $version['id']) as $r) {
            $rows[(string) $r['row_code']] = $r;
        }

        $out = [];
        $byPrefix = [];
        foreach (array_values($items) as $i => $item) {
            $n = $this->normalizeItem((array) $item, $rows, $i + 1);
            $prefix = $n['account_prefix'];
            $mine = $n['balance_condition'] === 'any' ? ['debit', 'credit'] : [$n['balance_condition']];
            // Víc výjimek pro týž účet a stranu zůstatku smí být jen v nepřekrývajících se
            // letech platnosti (jedna do 2023, druhá od 2024).
            foreach ($byPrefix[$prefix] ?? [] as $other) {
                if (array_intersect($other['sides'], $mine) !== []
                    && self::yearsOverlap($other['from'], $other['to'], $n['valid_from_year'], $n['valid_to_year'])) {
                    throw new ReportException('validation_failed', sprintf(
                        'Účet %s má víc výjimek pro stejnou stranu zůstatku se stejnou platností — ponechte jednu, nebo jim rozdělte roky platnosti.',
                        $prefix,
                    ), 422);
                }
            }
            $byPrefix[$prefix][] = ['sides' => $mine, 'from' => $n['valid_from_year'], 'to' => $n['valid_to_year']];
            $out[] = $n;
        }

        // Strany zůstatku pokryté výjimkami v každém roce platnosti: pro každou výjimku
        // sjednocení stran výjimek téhož účtu, jejichž platnost se s ní překrývá.
        $sides = [];
        foreach ($byPrefix as $prefix => $variants) {
            foreach ($variants as $v) {
                $covered = [];
                foreach ($variants as $w) {
                    if (self::yearsOverlap($v['from'], $v['to'], $w['from'], $w['to'])) {
                        $covered = [...$covered, ...$w['sides']];
                    }
                }
                $sides[] = ['prefix' => (string) $prefix, 'taken' => array_values(array_unique($covered))];
            }
        }

        // Výjimka jen pro jednu stranu saldového účtu potřebuje, aby druhá strana měla kam
        // jít: převezme se z mapy bez výjimky (StatementMapResolver::applyOverrides). Když
        // tam není, výkaz by se kvůli nepárovému saldovému prefixu nesestavil vůbec.
        $base = null;
        foreach ($sides as ['prefix' => $prefix, 'taken' => $taken]) {
            $missing = array_values(array_diff(['debit', 'credit'], $taken));
            if ($missing === []) {
                continue;
            }
            $base ??= $this->maps->simulate(
                $supplierId,
                (int) $version['id'],
                [],
                fn (): array => $this->maps->accountMap($version, $supplierId),
            );
            $inherited = [];
            foreach (StatementMapResolver::longestMatching($base, (string) $prefix) as $m) {
                $c = (string) $m['balance_condition'];
                foreach ($c === 'any' ? ['debit', 'credit'] : [$c] as $side) {
                    $inherited[$side] = true;
                }
            }
            foreach ($missing as $side) {
                if (!isset($inherited[$side])) {
                    throw new ReportException('validation_failed', sprintf(
                        'Výjimka pro účet %s platí jen pro jednu stranu zůstatku a mapa výkazu pro opačnou stranu (%s) nic nemá. '
                        . 'Doplňte výjimku i pro druhou stranu, nebo zvolte „jakýkoli zůstatek".',
                        (string) $prefix,
                        $side === 'debit' ? 'debetní' : 'kreditní',
                    ), 422);
                }
            }
        }

        // Korekce, která následuje pohledávku, musí skončit v řádku aktiv: účet pohledávky
        // zařazený do pasiv (nebo do výsledovky) by z ní udělal nesmysl.
        foreach ($out as $n) {
            if ($n['follows_prefix'] === null) {
                continue;
            }
            $base ??= $this->maps->simulate(
                $supplierId,
                (int) $version['id'],
                [],
                fn (): array => $this->maps->accountMap($version, $supplierId),
            );
            $resolved = StatementMapResolver::applyOverrides(
                $base,
                StatementMapResolver::validIn($out, $n['valid_from_year'] ?? $n['valid_to_year']),
            );
            foreach ($resolved as $m) {
                if ((string) $m['account_prefix'] === $n['account_prefix'] && ($m['follows_prefix'] ?? null) === $n['follows_prefix']
                    && (string) ($rows[(string) $m['row_code']]['section'] ?? '') !== 'assets') {
                    throw new ReportException('validation_failed', sprintf(
                        'Výjimka pro účet %s: účet pohledávky %s výkaz nezařazuje do aktiv, korekce ho následovat nemůže.',
                        $n['account_prefix'],
                        $n['follows_prefix'],
                    ), 422);
                }
            }
        }

        return $out;
    }

    /**
     * @param array<string,mixed>                $item
     * @param array<string,array<string,mixed>>  $rows
     * @return array{id?:int,account_prefix:string,row_code:string,target:string,balance_condition:string,sign:int,note:?string}
     */
    private function normalizeItem(array $item, array $rows, int $position): array
    {
        // Zápis „351.*" (všechny analytiky syntetiky) je totéž co prefix „351." — mapa
        // porovnává začátek kódu, hvězdička se proto jen odřízne.
        $prefix = rtrim(trim((string) ($item['account_prefix'] ?? '')), '*');
        if (preg_match('/^\d{3}[0-9A-Za-z.]{0,7}$/', $prefix) !== 1) {
            throw new ReportException('validation_failed', sprintf(
                'Výjimka č. %d: účet musí být kód syntetiky nebo analytiky (např. 365 nebo 365.100), nejvýš 10 znaků.',
                $position,
            ), 422);
        }
        $rowCode = trim((string) ($item['row_code'] ?? ''));
        $row = $rows[$rowCode] ?? null;
        if ($row === null) {
            throw new ReportException('validation_failed', sprintf(
                'Výjimka pro účet %s: řádek „%s" ve výkazu neexistuje.',
                $prefix,
                $rowCode,
            ), 422);
        }
        $target = trim((string) ($item['target'] ?? 'gross')) ?: 'gross';
        if (!in_array($target, ['gross', 'correction'], true)) {
            throw new ReportException('validation_failed', "target musí být 'gross' nebo 'correction'.", 422);
        }
        if ($target === 'correction' && (string) $row['section'] !== 'assets') {
            throw new ReportException('validation_failed', sprintf(
                'Výjimka pro účet %s: korekci lze zadat jen u řádku aktiv.',
                $prefix,
            ), 422);
        }
        $condition = trim((string) ($item['balance_condition'] ?? 'any')) ?: 'any';
        if (!in_array($condition, ['any', 'debit', 'credit'], true)) {
            throw new ReportException('validation_failed', "balance_condition musí být 'any', 'debit' nebo 'credit'.", 422);
        }
        $sign = (int) ($item['sign'] ?? 1);
        if (!in_array($sign, [1, -1], true)) {
            throw new ReportException('validation_failed', 'sign musí být 1 nebo -1.', 422);
        }
        $note = trim((string) ($item['note'] ?? ''));
        if (mb_strlen($note) > 255) {
            throw new ReportException('validation_failed', sprintf('Výjimka pro účet %s: poznámka je delší než 255 znaků.', $prefix), 422);
        }
        $follows = rtrim(trim((string) ($item['follows_prefix'] ?? '')), '*');
        if ($follows !== '') {
            if (preg_match('/^\d{3}[0-9A-Za-z.]{0,7}$/', $follows) !== 1) {
                throw new ReportException('validation_failed', sprintf(
                    'Výjimka pro účet %s: účet pohledávky musí být kód syntetiky nebo analytiky, nejvýš 10 znaků.',
                    $prefix,
                ), 422);
            }
            if ($target !== 'correction') {
                throw new ReportException('validation_failed', sprintf(
                    'Výjimka pro účet %s: vazbu na pohledávku může mít jen korekce.',
                    $prefix,
                ), 422);
            }
            if ($follows === $prefix) {
                throw new ReportException('validation_failed', sprintf(
                    'Výjimka pro účet %s: korekce nemůže následovat sama sebe.',
                    $prefix,
                ), 422);
            }
        }
        $from = self::year($item['valid_from_year'] ?? null, $prefix);
        $to = self::year($item['valid_to_year'] ?? null, $prefix);
        if ($from !== null && $to !== null && $from > $to) {
            throw new ReportException('validation_failed', sprintf(
                'Výjimka pro účet %s: platnost od roku %d je pozdější než do roku %d.',
                $prefix,
                $from,
                $to,
            ), 422);
        }

        $out = [
            'account_prefix'    => $prefix,
            'row_code'          => $rowCode,
            'target'            => $target,
            'follows_prefix'    => $follows === '' ? null : $follows,
            'balance_condition' => $condition,
            'sign'              => $sign,
            'note'              => $note === '' ? null : $note,
            'valid_from_year'   => $from,
            'valid_to_year'     => $to,
        ];
        if (isset($item['id']) && (int) $item['id'] > 0) {
            $out['id'] = (int) $item['id'];
        }

        return $out;
    }

    /** Rok platnosti výjimky: prázdné = bez omezení, jinak celý rok 1900–2999. */
    private static function year(mixed $value, string $prefix): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_int($value) && !(is_string($value) && ctype_digit(trim($value)))) {
            throw new ReportException('validation_failed', sprintf('Výjimka pro účet %s: rok platnosti musí být celé číslo.', $prefix), 422);
        }
        $year = (int) $value;
        if ($year < 1900 || $year > 2999) {
            throw new ReportException('validation_failed', sprintf('Výjimka pro účet %s: rok platnosti %d je mimo rozsah.', $prefix, $year), 422);
        }

        return $year;
    }

    /** Překrývají se dvě období platnosti (NULL = bez omezení)? */
    private static function yearsOverlap(?int $fromA, ?int $toA, ?int $fromB, ?int $toB): bool
    {
        return ($fromA === null || $toB === null || $fromA <= $toB)
            && ($fromB === null || $toA === null || $fromB <= $toA);
    }

    /** @return array<string,mixed> */
    private function versionOrFail(int $versionId): array
    {
        $version = $this->definitions->findVersionById($versionId);
        if ($version === null) {
            throw new ReportException('not_found', 'Verze výkazu #' . $versionId . ' neexistuje.', 404);
        }

        return $version;
    }

    /**
     * Řádky výkazu s hodnotou běžného období — z výkazu samotného (plný rozsah), aby editor
     * ukazoval stejné kódy a popisky jako výkaz. Když výkaz sestavit nejde (účelová VZZ bez
     * úplné mapy funkcí), vrátí holé řádky definice bez hodnot.
     *
     * @return list<array<string,mixed>>
     */
    private function rowsWithValues(string $type, int $supplierId, int $periodId, string $asOf, int $versionId): array
    {
        $values = [];
        try {
            $values = $this->statementValues($type, $supplierId, $periodId, $asOf)['rows'];
        } catch (ReportException) {
            $values = [];
        }

        $out = [];
        foreach ($this->definitions->rows($versionId) as $r) {
            $code = (string) $r['row_code'];
            $out[] = [
                'row_code'        => $code,
                'display_code'    => $values[$code]['display_code'] ?? $code,
                'parent_row_code' => $r['parent_row_code'] === null ? null : (string) $r['parent_row_code'],
                'section'         => (string) $r['section'],
                'label'           => (string) $r['label'],
                'level'           => (int) $r['level'],
                'row_type'        => (string) $r['row_type'],
                'value'           => $values[$code]['value'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * Hodnoty všech řádků výkazu v plném rozsahu (aktiva netto, pasiva a VZZ částka).
     *
     * @return array{rows: array<string, array{display_code:string,label:string,level:int,section:string,value:float}>, balanced: ?bool}
     */
    private function statementValues(string $type, int $supplierId, int $periodId, string $asOf): array
    {
        $data = match ($type) {
            'balance_sheet' => $this->statements->balanceSheet($supplierId, $periodId, $asOf, 'full'),
            FinancialStatementService::TYPE_PURPOSE => $this->statements->incomeStatementByFunction($supplierId, $periodId, $asOf, 'full'),
            default => $this->statements->incomeStatement($supplierId, $periodId, $asOf, 'full'),
        };

        $rows = [];
        $push = static function (array $r, string $section, float $value) use (&$rows): void {
            $rows[(string) $r['row_code']] = [
                'display_code' => (string) $r['display_code'],
                'label'        => (string) $r['label'],
                'level'        => (int) $r['level'],
                'section'      => $section,
                'value'        => round($value, 2),
            ];
        };
        if ($type === 'balance_sheet') {
            foreach ($data['assets'] as $r) {
                $push($r, 'assets', (float) $r['net']);
            }
            foreach ($data['liabilities'] as $r) {
                $push($r, 'liabilities', (float) $r['amount']);
            }
        } else {
            foreach ($data['rows'] as $r) {
                $push($r, 'profit_loss', (float) $r['amount']);
            }
        }

        return ['rows' => $rows, 'balanced' => isset($data['checks']['balanced']) ? (bool) $data['checks']['balanced'] : null];
    }

    /**
     * Účty firmy s druhem odpovídajícím výkazu, jejich zůstatek a řádek, kam je výkaz dnes
     * zařadí (a zda podle globální mapy, mapy funkcí, nebo výjimky firmy).
     *
     * @param array{period: array<string,mixed>, version: array<string,mixed>, as_of: string} $ctx
     * @param list<array<string,mixed>> $map
     * @return list<array<string,mixed>>
     */
    private function accounts(int $supplierId, string $type, array $ctx, array $map): array
    {
        $accountDefinitions=\MyInvoice\Repository\AccountDefinitionRepository::relation($ctx['as_of'],$supplierId);
        $types = self::ACCOUNT_TYPES[$type];
        $balances = $this->ledger->syntheticBalances(
            $supplierId,
            $ctx['as_of'],
            (string) $ctx['period']['starts_on'],
            $this->mapper->noCompensationPrefixes($map),
            self::ALL_LEAVES,
        );

        // Listový účet může mít u saldového prefixu dvě položky (debetní a kreditní strana).
        $sidesByCode = [];
        $leafBalance = [];
        foreach ($balances as $b) {
            $code = (string) $b['code'];
            $balance = round((float) $b['md'] - (float) $b['d'], 2);
            $sidesByCode[$code][] = self::cents($balance);
            $leafBalance[$code] = round(($leafBalance[$code] ?? 0.0) + $balance, 2);
        }

        $ph = implode(',', array_fill(0, count($types), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT a.account_code, a.name, a.account_type, a.is_synthetic, a.is_active,
                    p.account_code AS parent_code
               FROM {$accountDefinitions} a
          LEFT JOIN {$accountDefinitions} p ON p.id = a.parent_id
              WHERE a.supplier_id = ? AND a.account_type IN ({$ph})
              ORDER BY a.account_code"
        );
        $stmt->execute([$supplierId, ...$types]);
        $chart = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $childSum = [];
        foreach ($chart as $a) {
            $parent = $a['parent_code'];
            if ($parent !== null) {
                $childSum[(string) $parent] = round(($childSum[(string) $parent] ?? 0.0) + ($leafBalance[(string) $a['account_code']] ?? 0.0), 2);
            }
        }

        $out = [];
        foreach ($chart as $a) {
            $code = (string) $a['account_code'];
            $balance = round(($leafBalance[$code] ?? 0.0) + ($childSum[$code] ?? 0.0), 2);
            if ((int) $a['is_active'] !== 1 && self::cents($balance) === 0) {
                continue;
            }

            $entries = [];
            foreach ($sidesByCode[$code] ?? [null] as $cents) {
                foreach ($this->mapper->entriesFor($map, $code, $cents) as $e) {
                    $key = $e['row_code'] . '|' . $e['target'] . '|' . $e['balance_condition'];
                    $entries[$key] = [
                        'row_code'          => (string) $e['row_code'],
                        'target'            => (string) $e['target'],
                        'balance_condition' => (string) $e['balance_condition'],
                        'source'            => (string) ($e['source'] ?? 'global'),
                    ];
                }
            }

            $out[] = [
                'account_code' => $code,
                'name'         => (string) $a['name'],
                'account_type' => (string) $a['account_type'],
                'is_synthetic' => (int) $a['is_synthetic'] === 1,
                'parent_code'  => $a['parent_code'] === null ? null : (string) $a['parent_code'],
                'balance'      => $balance,
                'mappings'     => array_values($entries),
            ];
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $o
     * @return array<string,mixed>
     */
    private static function overrideOut(array $o): array
    {
        return [
            'id'                => (int) ($o['id'] ?? 0),
            'version_id'        => (int) ($o['version_id'] ?? 0),
            'account_prefix'    => (string) ($o['account_prefix'] ?? ''),
            'row_code'          => (string) ($o['row_code'] ?? ''),
            'target'            => (string) ($o['target'] ?? 'gross'),
            'follows_prefix'    => isset($o['follows_prefix']) && $o['follows_prefix'] !== '' ? (string) $o['follows_prefix'] : null,
            'balance_condition' => (string) ($o['balance_condition'] ?? 'any'),
            'sign'              => (int) ($o['sign'] ?? 1),
            'note'              => $o['note'] ?? null,
            'valid_from_year'   => isset($o['valid_from_year']) ? (int) $o['valid_from_year'] : null,
            'valid_to_year'     => isset($o['valid_to_year']) ? (int) $o['valid_to_year'] : null,
            'updated_at'        => $o['updated_at'] ?? null,
        ];
    }

    private static function key(string $prefix, string $condition, ?int $validFrom): string
    {
        return $prefix . '|' . $condition . '|' . ($validFrom ?? '');
    }

    private static function cents(float|int|string|null $amount): int
    {
        return (int) round(((float) $amount) * 100.0);
    }
}
