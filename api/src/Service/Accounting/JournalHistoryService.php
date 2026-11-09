<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\JournalEntryRepository;
use PDO;

/**
 * Auditní historie účetního zápisu (audit 2026-07, nález „Historie účetního zápisu
 * v UI — SYSTEM VERSIONING timeline"). journal_entries/journal_entry_lines jsou
 * SYSTEM VERSIONED od migrace 1029 — každý re-post (PostingService::rewriteExisting
 * dělá DELETE+re-INSERT řádků) i §35 editace popisu zanechá historickou verzi.
 * Tahle služba je spáruje do čitelné časové osy s diffem (hlavička + řádky dle
 * `line_no`) a co nejlépe je spáruje s activity_log (kdo/kdy) přes časovou blízkost
 * (obě zapisuje stejná transakce — best-effort, ne exaktní FK vazba).
 *
 * Vrací NULL, pokud MariaDB verzi neběží (SYSTEM VERSIONING je MariaDB-only,
 * feature-detekováno v 1029) — FOR SYSTEM_TIME ALL na non-versioned tabulce jen
 * vrátí aktuální řádek, takže degraduje na jedinou verzi bez chyby.
 */
final class JournalHistoryService
{
    /**
     * Hlavičková pole, jejichž změna mezi verzemi se hlásí v header_changes.
     * `reversed_by`/`source_id`/`period_id` doplněny po adversariálním review (M-1):
     * bez `reversed_by` se storno-přechod (PostingService::reverse → setReversedBy)
     * v historii jevil jako verze BEZ ŽÁDNÉ změny — prázdný diff u zápisu, který se
     * ve skutečnosti právě stornoval.
     */
    private const TRACKED_HEADER_FIELDS = [
        'entry_date', 'document_date', 'document_no', 'description', 'posted_at',
        'posted_by_name', 'reversed_by', 'source_id', 'period_id',
    ];

    /** Okno (s) pro spárování verze s nejbližším activity_log záznamem (stejná transakce). */
    private const ACTIVITY_MATCH_WINDOW_SEC = 60;

    public function __construct(
        private readonly JournalEntryRepository $journal,
        private readonly Connection $db,
    ) {}

    /**
     * @return array{entry_id:int, versions:list<array<string,mixed>>, activity:list<array<string,mixed>>}|null
     */
    public function build(int $id, int $supplierId): ?array
    {
        $raw = $this->journal->history($id, $supplierId);
        if ($raw === null) {
            return null;
        }
        $headers = $raw['headers'];
        $lines = $raw['lines'];
        $activity = $this->loadActivity($id);

        $versions = [];
        $prevHeader = null;
        $prevLines = [];
        $currentLines = [];
        $last = count($headers) - 1;
        // Záznam activity_log patří nejvýš jedné verzi (verze vzniklé v téže sekundě).
        $usedActivity = [];

        foreach ($headers as $i => $h) {
            // Řádky "narozené" ve stejné transakci jako TATO verze hlavičky — tj. jejich
            // valid_from spadá do okna [tato verze, další verze). replace() dělá
            // UPDATE hlavičky → DELETE starých řádků → INSERT nových jako TŘI samostatné
            // příkazy ve stejné transakci (každý s vlastním, o pár desítek µs pozdějším
            // ROW_START) a VŽDY přepíše ÚPLNĚ VŠECHNY řádky (i nezměněné) — takže
            // "narozené v tomto okně" = kompletní nová sada řádků pro tuto verzi.
            //
            // Pokud v okně NEvznikly žádné nové řádky (typicky §35 editace description,
            // která journal_entry_lines vůbec nemění), řádky se PŘEVEZMOU z předchozí
            // verze beze změny — jinak by verze po pouhé editaci popisu vypadala jako
            // zápis bez řádků. Ověřeno smoke testy nad reálným re-postem i description-edit.
            $windowEnd = $i < $last ? $headers[$i + 1]['valid_from'] : null;
            $bornInWindow = array_values(array_filter($lines, static function (array $l) use ($h, $windowEnd): bool {
                return $l['valid_from'] >= $h['valid_from'] && ($windowEnd === null || $l['valid_from'] < $windowEnd);
            }));
            if ($bornInWindow !== []) {
                // Výměna jen části řádků (přeúčtování dvojice na místě, replaceSomeLines)
                // nechá ostatní řádky žít dál, takže sada verze = řádky živé na konci
                // jejího okna, ne jen nově narozené. Při přepisu všech řádků je to totéž.
                $currentLines = self::aliveAtWindowEnd($lines, $h['valid_from'], $windowEnd);
            }
            $windowLines = $currentLines;

            $headerChanges = $prevHeader === null ? null : $this->diffHeader($prevHeader, $h);

            // Atribuce „kdo" (M-1 fix po adversariálním review): storno-přechod
            // (reversed_by NULL → hodnota) NESMÍ se spárovat časovou blízkostí —
            // PostingService::reverse loguje `accounting.reversed` s entity_id
            // PROTIZÁPISU (reversalId), ne originálu, takže loadActivity() nad tímto
            // zápisem tento záznam nikdy nenajde a nearestActivity() by omylem chytila
            // nejbližší cizí activity_log řádek (typicky `accounting.posted` téhož
            // zápisu) → verze storna by se tvářila jako „zaúčtoval X", což je nepravda.
            // Pro storno-verzi se atribuce hledá VÝHRADNĚ přes entity_id protizápisu;
            // pokud se nedohledá, radši žádná atribuce (null) než hádaná/chybná.
            // Vznik zápisu (první verze) nese autora taky (§ 35 odst. 3 ZoÚ: kdo a kdy
            // u každé verze): záznam zaúčtování v activity_log, jinak kdo zápis zaúčtoval.
            if ($prevHeader === null) {
                $changedBy = $this->nearestActivity($activity, (string) $h['valid_from'], $usedActivity)
                    ?? self::postedBy($h);
            } else {
                $isReversalTransition = $prevHeader['reversed_by'] === null && $h['reversed_by'] !== null;
                $changedBy = $isReversalTransition
                    ? $this->reversalActivity((int) $h['reversed_by'])
                    : $this->nearestActivity($activity, (string) $h['valid_from'], $usedActivity);
            }

            $versions[] = [
                'version'    => (int) $h['row_version'],
                'is_current' => $i === $last,
                'valid_from' => $h['valid_from'],
                'valid_to'   => $i === $last ? null : $h['valid_to'],
                'header'     => [
                    'entry_date'     => $h['entry_date'],
                    'document_date'  => $h['document_date'],
                    'document_no'    => $h['document_no'],
                    'description'    => $h['description'],
                    'source_type'    => $h['source_type'],
                    'source_id'      => $h['source_id'],
                    'posted_at'      => $h['posted_at'],
                    'posted_by'      => $h['posted_by'],
                    'posted_by_name' => $h['posted_by_name'],
                    'reversed_by'    => $h['reversed_by'],
                ],
                'lines' => array_map($this->exportLine(...), $windowLines),
                'header_changes' => $headerChanges,
                'line_changes'   => $prevHeader === null ? null : $this->diffLines($prevLines, $windowLines),
                'changed_by'     => $changedBy,
            ];

            $prevHeader = $h;
            $prevLines = $windowLines;
        }

        return [
            'entry_id' => $id,
            // Nejnovější verze první — přirozené čtení pro auditora ("co se stalo naposledy").
            'versions' => array_reverse($versions),
            'activity' => $activity,
        ];
    }

    /**
     * Řádky živé na konci okna verze: narozené před koncem okna a smazané až s další
     * verzí nebo vůbec (aktuální verze). Seřazené podle line_no.
     *
     * @param list<array<string,mixed>> $lines
     * @return list<array<string,mixed>>
     */
    private static function aliveAtWindowEnd(array $lines, string $windowStart, ?string $windowEnd): array
    {
        $alive = array_values(array_filter($lines, static function (array $l) use ($windowStart, $windowEnd): bool {
            if ($windowEnd === null) {
                return $l['valid_from'] >= $windowStart || (int) substr((string) $l['valid_to'], 0, 4) >= 2038;
            }
            return $l['valid_from'] < $windowEnd && $l['valid_to'] >= $windowEnd;
        }));
        if ($windowEnd === null) {
            $alive = array_values(array_filter($alive, static fn (array $l): bool => (int) substr((string) $l['valid_to'], 0, 4) >= 2038));
        }
        usort($alive, static fn (array $a, array $b): int => [$a['line_no'], $a['id']] <=> [$b['line_no'], $b['id']]);
        return $alive;
    }

    /**
     * Autor vzniku zápisu, když activity_log záznam chybí (převzaté zápisy, importy).
     *
     * @param array<string,mixed> $header
     * @return array<string,mixed>|null
     */
    private static function postedBy(array $header): ?array
    {
        if ($header['posted_by'] === null) {
            return null;
        }
        return [
            'action'     => 'accounting.posted',
            'user_id'    => (int) $header['posted_by'],
            'user_name'  => $header['posted_by_name'],
            'created_at' => $header['posted_at'],
        ];
    }

    /**
     * @param array<string,mixed> $line
     * @return array{account_code:?string, account_name:?string, side:string, amount:float, is_red_storno:bool, cost_center:?string, line_no:int, pair_no?:int}
     */
    private function exportLine(array $line): array
    {
        $out = [
            'account_code' => $line['account_code'] ?? null,
            'account_name' => $line['account_name'] ?? null,
            'side'         => $line['side'],
            'amount'       => (float) $line['amount'],
            'is_red_storno' => (bool) ($line['is_red_storno'] ?? false),
            'cost_center'  => $line['cost_center'] ?? null,
            'line_no'      => (int) $line['line_no'],
        ];
        // Číslo dvojice jen u zápisu po dvojicích; složený zápis má výstup beze změny.
        if (isset($line['pair_no'])) {
            $out['pair_no'] = (int) $line['pair_no'];
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $prev
     * @param array<string,mixed> $curr
     * @return array<string, array{before:mixed, after:mixed}>
     */
    private function diffHeader(array $prev, array $curr): array
    {
        $changes = [];
        foreach (self::TRACKED_HEADER_FIELDS as $field) {
            $before = $prev[$field] ?? null;
            $after = $curr[$field] ?? null;
            if ($before !== $after) {
                $changes[$field] = ['before' => $before, 'after' => $after];
            }
        }
        return $changes;
    }

    /**
     * Diff řádků mezi dvěma verzemi, spárovaný přes `line_no` (stabilní pořadové
     * číslo — replace() vždy přepíše řádky se sekvenčním line_no 0..n).
     *
     * @param list<array<string,mixed>> $prevLines
     * @param list<array<string,mixed>> $currLines
     * @return list<array<string,mixed>>
     */
    private function diffLines(array $prevLines, array $currLines): array
    {
        $byLineNo = static function (array $lines): array {
            $out = [];
            foreach ($lines as $l) {
                $out[(int) $l['line_no']] = $l;
            }
            return $out;
        };
        $prevByNo = $byLineNo($prevLines);
        $currByNo = $byLineNo($currLines);

        $changes = [];
        foreach ($currByNo as $no => $cl) {
            if (!isset($prevByNo[$no])) {
                $changes[] = ['type' => 'added', 'line_no' => $no, 'line' => $this->exportLine($cl)];
                continue;
            }
            $pl = $prevByNo[$no];
            $changed = (int) $pl['account_id'] !== (int) $cl['account_id']
                || $pl['side'] !== $cl['side']
                || abs((float) $pl['amount'] - (float) $cl['amount']) > 0.001
                || (bool) ($pl['is_red_storno'] ?? false) !== (bool) ($cl['is_red_storno'] ?? false)
                || ($pl['cost_center'] ?? null) !== ($cl['cost_center'] ?? null);
            if ($changed) {
                $changes[] = [
                    'type'    => 'changed',
                    'line_no' => $no,
                    'before'  => $this->exportLine($pl),
                    'after'   => $this->exportLine($cl),
                ];
            }
        }
        foreach ($prevByNo as $no => $pl) {
            if (!isset($currByNo[$no])) {
                $changes[] = ['type' => 'removed', 'line_no' => $no, 'line' => $this->exportLine($pl)];
            }
        }
        usort($changes, static fn (array $a, array $b): int => $a['line_no'] <=> $b['line_no']);
        return $changes;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function loadActivity(int $entryId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT al.id, al.user_id, u.name AS user_name, al.action, al.payload, al.created_at
               FROM activity_log al
          LEFT JOIN users u ON u.id = al.user_id
              WHERE al.entity_type = 'journal_entry' AND al.entity_id = ?
           ORDER BY al.created_at ASC, al.id ASC
              LIMIT 200"
        );
        $stmt->execute([$entryId]);

        return array_map(static function (array $r): array {
            $r['id'] = (int) $r['id'];
            $r['user_id'] = $r['user_id'] !== null ? (int) $r['user_id'] : null;
            $r['payload'] = $r['payload'] !== null ? json_decode((string) $r['payload'], true) : null;
            return $r;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * PŘESNÁ atribuce storna (M-1 fix) — `accounting.reversed` loguje PostingService::reverse
     * s `entity_id` = ID PROTIZÁPISU (reversalId), ne originálu (viz reverse() ř. 343-352).
     * Hledá se proto přímo podle entity_id protizápisu a konkrétní akce, NE časovou
     * blízkostí — vyloučí to omylem spárování s cizí/nesouvisející aktivitou originálu.
     *
     * @return array<string,mixed>|null
     */
    private function reversalActivity(int $reversalId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT al.user_id, u.name AS user_name, al.action, al.created_at
               FROM activity_log al
          LEFT JOIN users u ON u.id = al.user_id
              WHERE al.entity_type = 'journal_entry' AND al.entity_id = ? AND al.action = 'accounting.reversed'
           ORDER BY al.id ASC
              LIMIT 1"
        );
        $stmt->execute([$reversalId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return [
            'action'     => $row['action'],
            'user_id'    => $row['user_id'] !== null ? (int) $row['user_id'] : null,
            'user_name'  => $row['user_name'],
            'created_at' => $row['created_at'],
        ];
    }

    /**
     * Best-effort spárování verze s activity_log záznamem podle časové blízkosti —
     * obojí zapisuje stejná transakce (PostingService/updateDescription logují
     * ATOMICKY před commitem), takže ROW_START nové verze a activity.created_at
     * leží v řádu milisekund/sekund od sebe. NENÍ to FK vazba — jen prezentační pomoc.
     *
     * @param list<array<string,mixed>> $activity
     * @param array<int,true> $used už přiřazené záznamy (jeden záznam = nejvýš jedna verze)
     * @return array<string,mixed>|null
     */
    private function nearestActivity(array $activity, string $validFrom, array &$used = []): ?array
    {
        if ($activity === []) {
            return null;
        }
        try {
            $target = new \DateTimeImmutable($validFrom);
        } catch (\Throwable) {
            return null;
        }

        $best = null;
        $bestDiff = null;
        foreach ($activity as $a) {
            if (isset($used[$a['id']])) {
                continue;
            }
            try {
                $t = new \DateTimeImmutable((string) $a['created_at']);
            } catch (\Throwable) {
                continue;
            }
            $diff = abs($t->getTimestamp() - $target->getTimestamp());
            if ($diff > self::ACTIVITY_MATCH_WINDOW_SEC) {
                continue;
            }
            if ($bestDiff === null || $diff < $bestDiff) {
                $bestDiff = $diff;
                $best = $a;
            }
        }
        if ($best === null) {
            return null;
        }
        $used[$best['id']] = true;
        return [
            'action'     => $best['action'],
            'user_id'    => $best['user_id'],
            'user_name'  => $best['user_name'],
            'created_at' => $best['created_at'],
        ];
    }
}
