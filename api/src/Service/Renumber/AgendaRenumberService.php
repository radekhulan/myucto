<?php

declare(strict_types=1);

namespace MyInvoice\Service\Renumber;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\DocumentLockService;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\TaxEvidence\TaxEvidenceYearLock;
use PDO;

/**
 * Společný postup přečíslování dokladů jednoho měsíce v agendách, jejichž řady běží
 * po letech (pokladna, ostatní pohledávky a závazky, interní doklady).
 *
 * Dosavadní řady deníku čísluje rok a řada v číselníku může běžet po letech i bez
 * resetu, takže čísla jednoho měsíce nejde rozdat „od jedničky" bez zásahu do jiných
 * měsíců. Přečíslování proto vezme čísla, která automaticky očíslované doklady měsíce
 * v dané řadě už mají, seřadí je vzestupně a rozdá je dokladům podle data dokladu
 * (při shodě podle id). Množina čísel řady se nemění: jiné měsíce, počítadlo řady ani
 * kontrola úplnosti řady se nedotknou a nevznikne díra ani duplicita.
 *
 * Mimo přečíslování zůstávají ručně zadaná a převzatá čísla (neodpovídají šabloně žádné
 * řady) a doklady, které agenda vyřadí sama (`keep_reason`, např. doklad předaný ven).
 * Jejich čísla se nepřesouvají. Měsíc, jehož doklad nebo zápis dokladu v deníku leží
 * v uzavřeném či uzavíraném účetním období, v datu uzamčeném k datu, nebo v roce
 * s dokončenou roční uzávěrkou daňové evidence, se nepřečísluje vůbec.
 *
 * Kopie čísla v deníku (číslo dokladu a popis zápisu, storno i přeúčtování) se přepíší
 * ve stejné transakci jako číslo dokladu; popis jen v celém slově.
 */
abstract class AgendaRenumberService
{
    public function __construct(
        protected readonly Connection $db,
        private readonly DocumentLockService $locks,
        private readonly ActivityLogger $activity,
    ) {}

    /** Akce auditního záznamu, např. `cash_document.renumbered`. */
    abstract protected function auditAction(): string;

    /** Tabulka dokladů agendy. */
    abstract protected function table(): string;

    /** Sloupec čísla dokladu (unikátní v rámci firmy). */
    abstract protected function numberColumn(): string;

    /** Tabulka má sloupec `row_version` (optimistický zámek editoru). */
    abstract protected function hasRowVersion(): bool;

    /** `source_type` zápisů deníku, které doklad vlastní přes `source_id`; null = jen zápisy uvedené na dokladu. */
    abstract protected function journalSourceType(): ?string;

    /**
     * Ověří a normalizuje rozsah přečíslování (pokladna, strana).
     *
     * @param array<string,mixed> $scope
     * @return array<string,mixed>
     * @throws \InvalidArgumentException
     */
    abstract protected function normalizeScope(int $supplierId, array $scope): array;

    /**
     * Řady agendy pro rozsah: klíč řady => regex celého čísla, skupina 1 = čítač.
     *
     * @param array<string,mixed> $scope
     * @return array<string,string>
     */
    abstract protected function seriesPatterns(int $supplierId, array $scope): array;

    /**
     * Doklady měsíce s číslem seřazené podle data dokladu a id. Řádek nese `id`, `number`,
     * `doc_date`, `ref_dates` (další data pro zámek období), `entry_ids` (zápisy deníku
     * uvedené na dokladu), `keep_reason` (důvod, proč číslo zůstává, jinak null)
     * a popisná pole pro {@see describe()}. S `$forUpdate` řádky zamkne.
     *
     * @param array<string,mixed> $scope
     * @return list<array<string,mixed>>
     */
    abstract protected function monthRows(int $supplierId, string $from, string $to, array $scope, bool $forUpdate): array;

    /**
     * Popis dokladu pro náhled.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    abstract protected function describe(array $row): array;

    /** Kopie čísla mimo deník, kterou agenda drží navíc (v transakci přečíslování). */
    protected function rewriteOtherCopies(int $supplierId, int $id, string $old, string $new): void
    {
    }

    /**
     * Náhled přečíslování (nic nezapisuje).
     *
     * @param array<string,mixed> $scope
     * @return array{period: string, changes: list<array<string,mixed>>, kept: list<array<string,mixed>>, blocked: list<array<string,mixed>>}
     */
    public function preview(int $supplierId, string $period, array $scope = []): array
    {
        $plan = $this->plan($supplierId, $period, $scope, false);
        unset($plan['entries']);
        return $plan;
    }

    /**
     * Přečísluje měsíc. Vrací stejný tvar jako {@see preview()} + počet změněných dokladů.
     *
     * @param array<string,mixed> $scope
     * @return array{period: string, changes: list<array<string,mixed>>, kept: list<array<string,mixed>>, blocked: list<array<string,mixed>>, renumbered: int}
     * @throws \DomainException měsíc obsahuje doklad v uzavřeném nebo uzamčeném období
     */
    public function renumber(int $supplierId, string $period, array $scope, ?int $userId): array
    {
        $pdo = $this->db->pdo();
        $ownTransaction = !$pdo->inTransaction();
        if ($ownTransaction) {
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $pdo->beginTransaction();
        }
        try {
            $plan = $this->plan($supplierId, $period, $scope, true);
            if ($plan['blocked'] !== []) {
                throw new \DomainException('Měsíc je po uzávěrce nebo uzamčený k datu, přečíslovat ho nelze.');
            }
            $moves = array_values(array_filter($plan['changes'], static fn (array $c): bool => $c['changed']));

            $table = $this->table();
            $column = $this->numberColumn();
            $version = $this->hasRowVersion() ? ', row_version = row_version + 1' : '';
            // Dvoufázově kvůli unikátnímu indexu čísla: čísla se mezi doklady měsíce vyměňují.
            $tmp = $pdo->prepare("UPDATE {$table} SET {$column} = ? WHERE id = ? AND supplier_id = ?");
            foreach ($moves as $m) {
                $tmp->execute(['~RN' . $m['id'], $m['id'], $supplierId]);
            }
            $final = $pdo->prepare("UPDATE {$table} SET {$column} = ?{$version} WHERE id = ? AND supplier_id = ?");
            foreach ($moves as $m) {
                $final->execute([$m['new_number'], $m['id'], $supplierId]);
                $this->rewriteJournal($supplierId, array_keys($plan['entries'][$m['id']] ?? []), (string) $m['number'], (string) $m['new_number']);
                $this->rewriteOtherCopies($supplierId, (int) $m['id'], (string) $m['number'], (string) $m['new_number']);
            }

            if ($moves !== []) {
                $this->activity->log($this->auditAction(), $userId, 'supplier', $supplierId, [
                    'period'  => $plan['period'],
                    'scope'   => $plan['scope'],
                    'changes' => array_map(static fn (array $m): array => [
                        'id' => $m['id'], 'from' => $m['number'], 'to' => $m['new_number'],
                    ], $moves),
                ], null, null, $supplierId);
            }
            if ($ownTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        unset($plan['entries']);
        return $plan + ['renumbered' => count($moves)];
    }

    /**
     * @param array<string,mixed> $scope
     * @return array{period: string, scope: array<string,mixed>, changes: list<array<string,mixed>>, kept: list<array<string,mixed>>, blocked: list<array<string,mixed>>, entries: array<int, array<int,string>>}
     */
    private function plan(int $supplierId, string $period, array $scope, bool $forUpdate): array
    {
        $period = self::normalizePeriod($period);
        $scope = $this->normalizeScope($supplierId, $scope);
        $from = substr($period, 0, 4) . '-' . substr($period, 4, 2) . '-01';
        $to = date('Y-m-t', (int) strtotime($from));

        $patterns = $this->seriesPatterns($supplierId, $scope);
        $rows = $this->monthRows($supplierId, $from, $to, $scope, $forUpdate);
        $entries = $this->journalEntries($supplierId, $rows);

        /** @var array<string, list<array<string,mixed>>> $groups */
        $groups = [];
        $kept = [];
        foreach ($rows as $row) {
            $number = (string) $row['number'];
            $series = null;
            $counter = 0;
            if ($row['keep_reason'] === null) {
                foreach ($patterns as $key => $regex) {
                    if (preg_match($regex, $number, $m) === 1) {
                        $series = $key;
                        $counter = (int) ($m[1] ?? 0);
                        break;
                    }
                }
            }
            if ($series === null) {
                $kept[] = $this->describe($row) + ['reason' => $row['keep_reason'] ?? 'manual_number'];
                continue;
            }
            $groups[$series][] = $row + ['counter' => $counter];
        }

        // Po uzávěrce (i rozpracované) se čísla nemění: podvojné účetnictví podle stavu
        // účetního období dokladu i jeho zápisů, daňová evidence podle roční uzávěrky.
        $yearClosed = TaxEvidenceYearLock::isFinal($this->db, $supplierId, (int) substr($period, 0, 4));
        $blocked = [];
        foreach ($groups as $members) {
            foreach ($members as $row) {
                $dates = [(string) $row['doc_date'], ...$row['ref_dates'], ...array_values($entries[(int) $row['id']] ?? [])];
                $reason = $yearClosed ? 'year_closed' : $this->lockReason($supplierId, $dates);
                if ($reason !== null) {
                    $blocked[] = $this->describe($row) + ['reason' => $reason];
                }
            }
        }

        $changes = [];
        foreach ($groups as $key => $members) {
            $numbers = $members;
            usort($numbers, static fn (array $a, array $b): int
                => [$a['counter'], (string) $a['number']] <=> [$b['counter'], (string) $b['number']]);
            foreach ($members as $i => $row) {
                $new = (string) $numbers[$i]['number'];
                $changes[] = $this->describe($row) + [
                    'new_number' => $new,
                    'series'     => $key,
                    'changed'    => $new !== (string) $row['number'],
                    '_order'     => [(string) $row['doc_date'], (int) $row['id']],
                ];
            }
        }
        usort($changes, static fn (array $a, array $b): int => $a['_order'] <=> $b['_order']);
        $changes = array_map(static function (array $c): array {
            unset($c['_order']);
            return $c;
        }, $changes);

        return [
            'period'  => $period,
            'scope'   => $scope,
            'changes' => $changes,
            'kept'    => $kept,
            'blocked' => $blocked,
            'entries' => $entries,
        ];
    }

    /** @param list<string> $dates */
    private function lockReason(int $supplierId, array $dates): ?string
    {
        $found = null;
        foreach (array_unique(array_filter($dates, static fn (string $d): bool => $d !== '')) as $date) {
            $lock = $this->locks->forDate($supplierId, substr($date, 0, 10));
            $reason = match (true) {
                $lock->inClosedPeriod  => 'period_closed',
                $lock->inClosingPeriod => 'period_closing',
                $lock->dateLocked      => 'date_locked',
                default                => null,
            };
            if ($reason === 'period_closed') {
                return $reason;
            }
            $found ??= $reason;
        }
        return $found;
    }

    /**
     * Zápisy deníku dokladů: přes `source_type`/`source_id`, zápisy uvedené na dokladu
     * a jejich storna (storno nese `source_id` NULL, k originálu ho váže `reversed_by`).
     *
     * @param list<array<string,mixed>> $rows
     * @return array<int, array<int,string>> id dokladu => [id zápisu => datum zápisu]
     */
    private function journalEntries(int $supplierId, array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        $pdo = $this->db->pdo();
        /** @var array<int,int> $owner id zápisu => id dokladu */
        $owner = [];
        foreach ($rows as $row) {
            foreach ($row['entry_ids'] as $entryId) {
                $owner[(int) $entryId] = (int) $row['id'];
            }
        }
        $source = $this->journalSourceType();
        if ($source !== null) {
            foreach (array_chunk(array_map(static fn (array $r): int => (int) $r['id'], $rows), 500) as $chunk) {
                $in = implode(',', array_fill(0, count($chunk), '?'));
                $stmt = $pdo->prepare(
                    "SELECT id, source_id FROM journal_entries
                      WHERE supplier_id = ? AND source_type = ? AND source_id IN ({$in})"
                );
                $stmt->execute([$supplierId, $source, ...$chunk]);
                while (($e = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
                    $owner[(int) $e['id']] ??= (int) $e['source_id'];
                }
            }
        }

        $result = [];
        $pending = array_keys($owner);
        // Dvě kola: zápisy dokladu, pak jejich storna.
        for ($round = 0; $round < 2 && $pending !== []; $round++) {
            $next = [];
            foreach (array_chunk($pending, 500) as $chunk) {
                $in = implode(',', array_fill(0, count($chunk), '?'));
                $stmt = $pdo->prepare(
                    "SELECT id, entry_date, reversed_by FROM journal_entries WHERE supplier_id = ? AND id IN ({$in})"
                );
                $stmt->execute([$supplierId, ...$chunk]);
                while (($e = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
                    $id = (int) $e['id'];
                    $doc = $owner[$id];
                    $result[$doc][$id] = (string) $e['entry_date'];
                    $reversal = $e['reversed_by'] !== null ? (int) $e['reversed_by'] : null;
                    if ($reversal !== null && !isset($owner[$reversal])) {
                        $owner[$reversal] = $doc;
                        $next[] = $reversal;
                    }
                }
            }
            $pending = $next;
        }
        return $result;
    }

    /**
     * Přepíše kopie čísla v zápisech dokladu: číslo dokladu (i `STORNO …`) a popis.
     * Popis se mění jen v celém slově, aby VPD-2026-0001 nepřepsal začátek VPD-2026-00010.
     *
     * @param list<int> $entryIds
     */
    private function rewriteJournal(int $supplierId, array $entryIds, string $old, string $new): void
    {
        if ($entryIds === []) {
            return;
        }
        $pattern = '(?<![0-9A-Za-z])' . preg_quote($old, '/') . '(?![0-9A-Za-z])';
        $in = implode(',', array_fill(0, count($entryIds), '?'));
        $this->db->pdo()->prepare(
            "UPDATE journal_entries
                SET document_no = CASE
                        WHEN document_no = ? THEN ?
                        WHEN document_no = CONCAT('STORNO ', ?) THEN CONCAT('STORNO ', ?)
                        ELSE document_no END,
                    description = REGEXP_REPLACE(description, ?, ?),
                    row_version = row_version + 1
              WHERE supplier_id = ? AND id IN ({$in})
                AND (document_no IN (?, CONCAT('STORNO ', ?)) OR description REGEXP ?)"
        )->execute([$old, $new, $old, $new, $pattern, $new, $supplierId, ...$entryIds, $old, $old, $pattern]);
    }

    /**
     * Regex čísla dosavadní roční řady deníku ({@see \MyInvoice\Service\Accounting\Closing\DocumentSeriesService::format()}),
     * skupina 1 = čítač.
     */
    protected static function legacyRegex(string $prefix, int $fiscalYear, ?string $template): string
    {
        $template = trim((string) $template);
        if ($template === '') {
            $template = \MyInvoice\Service\Accounting\Closing\DocumentSeriesService::DEFAULT_TEMPLATE;
        }
        $parts = preg_split('/(\{C+\})/', $template, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $regex = '';
        foreach ($parts as $part) {
            if (preg_match('/^\{C+\}$/', $part) === 1) {
                $regex .= '(\d+)';
                continue;
            }
            $regex .= preg_quote(strtr($part, [
                '{PREFIX}' => $prefix,
                '{YYYY}'   => (string) $fiscalYear,
                '{YY}'     => substr((string) $fiscalYear, -2),
            ]), '/');
        }
        return '/^' . $regex . '$/D';
    }

    /**
     * Regexy dosavadních ročních řad deníku: všechny roky a rozsahy (`register_id`).
     *
     * @param list<string> $codes
     * @param list<int>|null $registerIds null = všechny rozsahy
     * @return array<string,string>
     */
    protected function legacyPatterns(int $supplierId, array $codes, ?array $registerIds = null): array
    {
        $in = implode(',', array_fill(0, count($codes), '?'));
        $sql = "SELECT series_code, register_id, fiscal_year, prefix, number_format
                  FROM accounting_document_series
                 WHERE supplier_id = ? AND series_code IN ({$in})";
        $params = [$supplierId, ...$codes];
        if ($registerIds !== null) {
            $sql .= ' AND register_id IN (' . implode(',', array_fill(0, count($registerIds), '?')) . ')';
            $params = [...$params, ...$registerIds];
        }
        $stmt = $this->db->pdo()->prepare($sql . ' ORDER BY fiscal_year DESC, series_code, register_id');
        $stmt->execute($params);
        $out = [];
        while (($r = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $key = 'legacy:' . $r['series_code'] . ':' . $r['register_id'] . ':' . $r['fiscal_year'];
            $out[$key] = self::legacyRegex((string) $r['prefix'], (int) $r['fiscal_year'],
                $r['number_format'] !== null ? (string) $r['number_format'] : null);
        }
        return $out;
    }

    /**
     * Regexy nových (nativních) řad číselníku daných druhů.
     *
     * @param list<string> $kinds
     * @return array<string,string>
     */
    protected function nativePatterns(int $supplierId, array $kinds, int $registerId = 0): array
    {
        if (!$this->db->hasTable('number_series')) {
            return [];
        }
        $in = implode(',', array_fill(0, count($kinds), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, template, prefix FROM number_series
              WHERE supplier_id = ? AND doc_kind IN ({$in}) AND storage = 'native' AND template IS NOT NULL
                AND (scope_type = 'company' OR (scope_type = 'cash_register' AND scope_id = ?))
              ORDER BY sort_order, id"
        );
        $stmt->execute([$supplierId, ...$kinds, $registerId]);
        $out = [];
        while (($r = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $out['native:' . $r['id']] = \MyInvoice\Service\NumberSeries\NumberSeriesTemplate::counterRegex(
                (string) $r['template'], $r['prefix'] !== null ? (string) $r['prefix'] : null, null, null,
            );
        }
        return $out;
    }

    /**
     * @param list<mixed> $ids
     * @return list<int>
     */
    protected static function entryIds(mixed ...$ids): array
    {
        return array_values(array_map('intval', array_filter($ids, static fn (mixed $v): bool => $v !== null && (int) $v > 0)));
    }

    public static function normalizePeriod(string $period): string
    {
        $digits = preg_replace('/\D/', '', $period) ?? '';
        if (!preg_match('/^(\d{4})(0[1-9]|1[0-2])$/', $digits)) {
            throw new \InvalidArgumentException('Období musí být ve tvaru RRRR-MM.');
        }
        return $digits;
    }
}
