<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingFrameworkRepository;
use MyInvoice\Repository\DimensionAssignmentRepository;
use MyInvoice\Repository\PohodaImportRepository;
use MyInvoice\Service\Accounting\AccountingFrameworkService;
use MyInvoice\Service\Accounting\Allocation\JointAllocationException;
use MyInvoice\Service\Accounting\Dimension\DimensionException;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Accounting\Dimension\DimensionService;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\ActivityLogger;
use PDO;

/**
 * Přeřazení činnosti neziskové organizace podle střediska. Převod z POHODY dává všem řádkům
 * nákladů a výnosů hlavní činnost ({@see PohodaImporter}), které středisko je hospodářská
 * činnost, dodá účetní jako tabulku středisko => činnost.
 *
 * Mění jen řádky účtů 5 a 6 převedených zápisů ({@see PohodaImportRepository::KIND_JOURNAL_ENTRY},
 * {@see PohodaImportRepository::KIND_DERIVED_ENTRY}). Zápis jde přes
 * {@see DimensionService::saveEntryLines()}, takže platí tytéž pojistky jako při ruční změně:
 * uzavřené období, zmrazení uzávěrkou, společné náklady, členské příspěvky, účtotvorné dimenze.
 * Co pojistka odmítne, se započítá jako přeskočené s jejím kódem a běh pokračuje.
 *
 * Přeskakuje dále:
 * - řádky s rozpadem dimenze: částka je na více hodnot, jedna činnost by rozpad přepsala,
 * - stornované zápisy a jejich storna: dvojice se v součtu ruší a storno musí mít stejnou
 *   činnost jako původní zápis, jinak by rozdíl přešel mezi činnostmi,
 * - řádky, jejichž dimenze Středisko se v MyÚčtu změnila proti převzatému kódu: tabulka mluví
 *   o kódu z POHODY a řádek už patří jinam,
 * - období, která nevedou podle vyhlášky 504.
 *
 * Kód střediska se porovnává přesně. Řazení databáze nerozlišuje velikost písmen ani diakritiku
 * a VYUKA a VÝUKA mohou být dvě různá střediska.
 */
final class NonprofitActivityByCentre
{
    public const OUTCOME_CHANGED = 'changed';
    public const OUTCOME_UNCHANGED = 'unchanged';
    public const OUTCOME_SPLIT = 'split';
    public const OUTCOME_REVERSED = 'reversed';
    public const OUTCOME_CENTRE_CHANGED = 'centre_changed';
    public const OUTCOME_NOT_504 = 'not_504';

    private const ACTIVITIES = ['main', 'economic'];
    private const BATCH = 500;

    public function __construct(
        private readonly Connection $db,
        private readonly DimensionService $dimensions,
        private readonly DimensionAssignmentRepository $assignments,
        private readonly ActivityLogger $audit,
    ) {}

    /**
     * Mapa z příkazové řádky: `VÝUKA=main,ZvukSvětlo=economic`. Kód střediska smí obsahovat `=`,
     * rozhoduje poslední výskyt.
     *
     * @return array<string,string> kód střediska => main|economic
     */
    public static function parseMap(string $raw): array
    {
        $map = [];
        foreach (explode(',', $raw) as $pair) {
            $split = strrpos($pair, '=');
            if ($split === false) {
                throw new PohodaException('invalid_centre_map', sprintf('Položka „%s" nemá tvar středisko=main nebo středisko=economic.', trim($pair)));
            }
            $code = trim(substr($pair, 0, $split));
            $activity = trim(substr($pair, $split + 1));
            if (isset($map[$code]) && $map[$code] !== $activity) {
                throw new PohodaException('invalid_centre_map', sprintf('Středisko „%s" je v mapě dvakrát s různou činností.', $code));
            }
            $map[$code] = $activity;
        }
        return self::validated($map);
    }

    /**
     * Přeřadí činnost, případně jen spočítá, co by se stalo. Zkouška nanečisto projde stejnou
     * cestou včetně pojistek a na konci se odvolá, takže odpovídá ostrému běhu. Celý běh je
     * jedna transakce, v auditu tak nezůstane nic, co se nezapsalo. Opakovaný běh nic nemění
     * a do auditu nepíše.
     *
     * @param array<string,string> $map kód střediska => main|economic
     * @return array{
     *     dry_run: bool, changed: int, unchanged: int, skipped: int, no_centre: int,
     *     outside_map: array<string,int>, unknown_centres: list<string>,
     *     rows: list<array{centre:string, activity:string, outcome:string, lines:int, debit:string, credit:string}>
     * }
     */
    public function run(int $supplierId, array $map, bool $dryRun = false, ?int $actor = null): array
    {
        $map = self::validated($map);
        [$typeId, $values] = $this->activity($supplierId);
        $guard = new DimensionPeriodGuard($this->db);
        $discard = new class extends \RuntimeException {};
        $summary = [];
        try {
            return $guard->transaction(function () use ($guard, $supplierId, $map, $dryRun, $actor, $typeId, $values, $discard, &$summary): array {
                // Pořadí zámků jako u ostatních zápisů dimenzí: nejdřív období, pak deník.
                $guard->lockPeriods($supplierId);
                $summary = $this->reassign($supplierId, $map, $typeId, $values, $dryRun, $actor);
                if ($dryRun) {
                    throw $discard;
                }
                if ($summary['changed'] > 0) {
                    $this->audit->log('dimension.activity_by_centre', $actor, 'dimension', $typeId, ['map' => $map] + $summary, supplierId: $supplierId);
                }
                return $summary;
            });
        } catch (\RuntimeException $e) {
            if ($e !== $discard) {
                throw $e;
            }
            return $summary;
        }
    }

    /**
     * @param array<string,string> $map
     * @param array<string,int> $values main|economic => id hodnoty
     * @return array<string,mixed>
     */
    private function reassign(int $supplierId, array $map, int $typeId, array $values, bool $dryRun, ?int $actor): array
    {
        $state = ['rows' => [], 'no_centre' => 0, 'outside_map' => [], 'seen' => []];
        $frameworks = new AccountingFrameworkService($this->db, new AccountingFrameworkRepository($this->db));
        $frameworkOn = [];
        foreach ([PohodaImportRepository::KIND_JOURNAL_ENTRY, PohodaImportRepository::KIND_DERIVED_ENTRY] as $kind) {
            $after = 0;
            while (($entryIds = $this->entryPage($supplierId, $kind, $after)) !== []) {
                $after = (int) end($entryIds);
                $pending = [];
                foreach ($this->lines($supplierId, $entryIds, $typeId) as $line) {
                    $code = (string) ($line['cost_center'] ?? '');
                    if ($code === '') {
                        $state['no_centre']++;
                        continue;
                    }
                    if (!isset($map[$code])) {
                        $state['outside_map'][$code] = ($state['outside_map'][$code] ?? 0) + 1;
                        continue;
                    }
                    $state['seen'][$code] = true;
                    $date = (string) $line['entry_date'];
                    $frameworkOn[$date] ??= $frameworks->forDate($supplierId, $date);
                    $activity = $map[$code];
                    $outcome = match (true) {
                        (bool) $line['has_split'] => self::OUTCOME_SPLIT,
                        (bool) $line['reversed'] => self::OUTCOME_REVERSED,
                        $frameworkOn[$date] !== AccountingFrameworkService::CZ_504 => self::OUTCOME_NOT_504,
                        $line['centre_value'] !== null && (string) $line['centre_value'] !== $code => self::OUTCOME_CENTRE_CHANGED,
                        (int) $line['activity_value'] === $values[$activity] => self::OUTCOME_UNCHANGED,
                        default => null,
                    };
                    if ($outcome === null) {
                        $pending[(int) $line['entry_id']][(int) $line['id']] = $line;
                        continue;
                    }
                    self::tally($state['rows'], $code, $activity, $outcome, $line);
                }
                $this->applyPending($supplierId, $pending, $map, $typeId, $values, $dryRun, $actor, $state['rows']);
            }
        }
        return self::summary($state, $map, $dryRun);
    }

    /**
     * @param array<int,array<int,array<string,mixed>>> $pending zápis => řádek => data řádku
     * @param array<string,string> $map
     * @param array<string,int> $values
     * @param array<string,array<string,mixed>> $rows
     */
    private function applyPending(int $supplierId, array $pending, array $map, int $typeId, array $values, bool $dryRun, ?int $actor, array &$rows): void
    {
        if ($pending === []) {
            return;
        }
        $lineIds = [];
        foreach ($pending as $lines) {
            array_push($lineIds, ...array_keys($lines));
        }
        $current = $this->assignments->lineDimensions($supplierId, $lineIds, true);
        foreach ($pending as $entryId => $lines) {
            $dims = [];
            $changes = [];
            foreach ($lines as $lineId => $line) {
                $target = $values[$map[(string) $line['cost_center']]];
                $dims[$lineId] = [$typeId => $target] + ($current[$lineId] ?? []);
                $changes[$lineId] = ['from' => $line['activity_value'] === null ? null : (int) $line['activity_value'], 'to' => $target];
            }
            $refused = null;
            try {
                $this->dimensions->saveEntryLines($supplierId, $entryId, $dims);
                if (!$dryRun) {
                    $this->audit->log('dimension.journal_lines_updated', $actor, 'dimension', $entryId, [
                        'source' => 'pohoda_activity_by_centre',
                        'lines' => $changes,
                    ], supplierId: $supplierId);
                }
            } catch (DimensionException|PostingException|JointAllocationException $e) {
                // Pojistku odvolal savepoint uvnitř saveEntryLines, chyba databáze sem nepatří.
                $refused = $e->errorCode;
            }
            foreach ($lines as $line) {
                $code = (string) $line['cost_center'];
                self::tally($rows, $code, $map[$code], $refused ?? self::OUTCOME_CHANGED, $line);
            }
        }
    }

    /** @return list<int> další dávka převedených zápisů, indexem bez řazení všech */
    private function entryPage(int $supplierId, string $kind, int $after): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT DISTINCT target_id FROM pohoda_import_map
              WHERE supplier_id = ? AND kind = ? AND target_id > ?
              ORDER BY target_id LIMIT ' . self::BATCH
        );
        $stmt->execute([$supplierId, $kind, $after]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @param list<int> $entryIds
     * @return list<array<string,mixed>>
     */
    private function lines(int $supplierId, array $entryIds, int $typeId): array
    {
        $marks = implode(',', array_fill(0, count($entryIds), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT l.id, l.entry_id, l.side, l.amount, l.cost_center, e.entry_date,
                    d.dimension_value_id AS activity_value,
                    vc.code AS centre_value,
                    EXISTS (SELECT 1 FROM journal_entry_line_dimension_splits sp WHERE sp.supplier_id = l.supplier_id AND sp.line_id = l.id) AS has_split,
                    e.reversed_by IS NOT NULL
                        OR EXISTS (SELECT 1 FROM journal_entries o WHERE o.supplier_id = e.supplier_id AND o.reversed_by = e.id) AS reversed
               FROM journal_entries e
               JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
               JOIN chart_of_accounts a ON a.id = l.account_id AND a.supplier_id = l.supplier_id
               LEFT JOIN journal_entry_line_dimensions d ON d.line_id = l.id AND d.dimension_type_id = ?
               LEFT JOIN (journal_entry_line_dimensions dc
                          JOIN dimension_types tc ON tc.id = dc.dimension_type_id AND tc.kind = 'cost_center'
                          JOIN dimension_values vc ON vc.id = dc.dimension_value_id) ON dc.line_id = l.id
              WHERE e.supplier_id = ? AND e.id IN ({$marks}) AND e.posted_at IS NOT NULL AND a.account_code REGEXP '^[56]'
              ORDER BY l.entry_id, l.id"
        );
        $stmt->execute([$typeId, $supplierId, ...$entryIds]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array{0:int,1:array<string,int>} typ dimenze, main|economic => id hodnoty */
    private function activity(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT t.id AS type_id, v.id AS value_id, v.semantic_key
               FROM dimension_types t
               JOIN dimension_values v ON v.type_id = t.id
              WHERE t.supplier_id = ? AND t.system_key = 'activity' AND v.semantic_key IN ('main', 'economic')"
        );
        $stmt->execute([$supplierId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $values = array_map('intval', array_column($rows, 'value_id', 'semantic_key'));
        if (array_diff(self::ACTIVITIES, array_keys($values)) !== []) {
            throw new PohodaException('activity_dimension_missing', 'Firma nemá dimenzi Činnost. Přeřazení je jen pro firmu účtující podle vyhlášky 504 po převodu neziskové agendy.');
        }
        return [(int) $rows[0]['type_id'], $values];
    }

    /**
     * @param array<array-key,mixed> $map
     * @return array<string,string>
     */
    private static function validated(array $map): array
    {
        if ($map === []) {
            throw new PohodaException('invalid_centre_map', 'Mapa středisek je prázdná.');
        }
        $out = [];
        foreach ($map as $code => $activity) {
            $code = trim((string) $code);
            if ($code === '') {
                throw new PohodaException('invalid_centre_map', 'Mapa středisek obsahuje prázdný kód.');
            }
            if (!in_array($activity, self::ACTIVITIES, true)) {
                throw new PohodaException('invalid_centre_map', sprintf('Středisko „%s": činnost musí být main nebo economic.', $code));
            }
            $out[$code] = $activity;
        }
        return $out;
    }

    /**
     * @param array<string,array<string,mixed>> $rows
     * @param array<string,mixed> $line
     */
    private static function tally(array &$rows, string $code, string $activity, string $outcome, array $line): void
    {
        $key = $code . "\0" . $activity . "\0" . $outcome;
        $rows[$key] ??= ['centre' => $code, 'activity' => $activity, 'outcome' => $outcome, 'lines' => 0, 'debit' => 0, 'credit' => 0];
        $rows[$key]['lines']++;
        $rows[$key][(string) $line['side']] += (int) round((float) $line['amount'] * 100);
    }

    /**
     * @param array{rows:array<string,array<string,mixed>>, no_centre:int, outside_map:array<array-key,int>, seen:array<array-key,bool>} $state
     * @param array<string,string> $map
     * @return array<string,mixed>
     */
    private static function summary(array $state, array $map, bool $dryRun): array
    {
        $rows = array_values($state['rows']);
        usort($rows, static fn (array $a, array $b): int => [$a['centre'], $a['outcome']] <=> [$b['centre'], $b['outcome']]);
        $counted = static fn (string $outcome): int => array_sum(array_column(array_filter($rows, static fn (array $r): bool => $r['outcome'] === $outcome), 'lines'));
        $changed = $counted(self::OUTCOME_CHANGED);
        $unchanged = $counted(self::OUTCOME_UNCHANGED);
        $outsideMap = [];
        foreach ($state['outside_map'] as $code => $lines) {
            $outsideMap[(string) $code] = $lines;
        }
        return [
            'dry_run' => $dryRun,
            'changed' => $changed,
            'unchanged' => $unchanged,
            'skipped' => array_sum(array_column($rows, 'lines')) - $changed - $unchanged,
            'no_centre' => $state['no_centre'],
            'outside_map' => $outsideMap,
            'unknown_centres' => array_map('strval', array_values(array_diff(array_map('strval', array_keys($map)), array_map('strval', array_keys($state['seen']))))),
            'rows' => array_map(static fn (array $r): array => [
                'debit' => number_format($r['debit'] / 100, 2, '.', ''),
                'credit' => number_format($r['credit'] / 100, 2, '.', ''),
            ] + $r, $rows),
        ];
    }
}
