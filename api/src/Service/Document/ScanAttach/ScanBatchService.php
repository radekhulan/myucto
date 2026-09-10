<?php

declare(strict_types=1);

namespace MyInvoice\Service\Document\ScanAttach;

use MyInvoice\Repository\DocumentExtractionRepository;
use MyInvoice\Repository\ScanBatchRepository;
use MyInvoice\Service\Document\DocumentStorage;

/**
 * Práce s hotovou dávkou: připojení souboru k dokladu, potvrzení a odmítnutí
 * návrhu, přehled výsledku. Sdílí ji background job i akce z UI, takže připojení
 * skenu žije na jednom místě.
 */
final class ScanBatchService
{
    public const LIST_LIMIT = 500;
    public const DEFAULT_TARGETS = ['purchase_invoice'];

    public function __construct(
        private readonly ScanBatchRepository $batches,
        private readonly ScanTargetRegistry $targets,
        private readonly DocumentExtractionRepository $extractions,
        private readonly DocumentStorage $storage,
    ) {}

    /**
     * Typy dokladů, na které se dávka páruje (z parametrů dávky, jen známé typy).
     *
     * @param array<string,mixed> $params
     * @return list<string>
     */
    public function targetTypes(array $params): array
    {
        $types = is_array($params['targets'] ?? null) ? $params['targets'] : self::DEFAULT_TARGETS;
        $known = array_keys($this->targets->all());
        $types = array_values(array_intersect(array_unique(array_map('strval', $types)), $known));
        return $types !== [] ? $types : self::DEFAULT_TARGETS;
    }

    /** Připojí soubor dávky k dokladu (vazba v sekci Dokumenty + případně PDF slot). */
    public function attachItem(int $supplierId, string $targetType, int $targetId, int $itemId): void
    {
        $target = $this->targets->available($targetType);
        if ($target === null) {
            throw new \RuntimeException("K dokladům typu {$targetType} teď nelze připojovat.");
        }
        $item = $this->batches->findItem($supplierId, $itemId);
        if ($item === null || $item['document_id'] === null || $item['doc_filename'] === null) {
            throw new \RuntimeException('Soubor dávky nemá uložený dokument.');
        }
        $path = $this->storage->pathFor($supplierId, (string) $item['sha256'], (string) $item['doc_filename']);
        $target->attach($supplierId, $targetId, (int) $item['document_id'], $path, (string) ($item['doc_original_name'] ?? $item['file_name']));
    }

    /**
     * Potvrdí navržený pár: sken se připojí a ostatní návrhy pro týž doklad
     * i týž soubor se odmítnou.
     *
     * @return array{ok:bool, error?:string}
     */
    public function confirm(int $supplierId, int $matchId, ?int $userId): array
    {
        $m = $this->batches->findMatch($supplierId, $matchId);
        if ($m === null) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        if ($m['state'] !== 'proposed') {
            return ['ok' => false, 'error' => 'not_proposed'];
        }
        $this->attachItem($supplierId, (string) $m['target_type'], (int) $m['target_id'], (int) $m['item_id']);
        $this->batches->setMatchState($supplierId, $matchId, 'confirmed', $userId);
        $touched = $this->batches->rejectCompetingProposals(
            $supplierId, (int) $m['job_id'], $matchId, (string) $m['target_type'], (int) $m['target_id'], (int) $m['item_id'], $userId,
        );
        foreach (array_unique([(int) $m['item_id'], ...$touched]) as $itemId) {
            $this->refreshOutcome($supplierId, $itemId);
        }
        return ['ok' => true];
    }

    /** @return array{ok:bool, error?:string} */
    public function reject(int $supplierId, int $matchId, ?int $userId): array
    {
        $m = $this->batches->findMatch($supplierId, $matchId);
        if ($m === null) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        if ($m['state'] !== 'proposed') {
            return ['ok' => false, 'error' => 'not_proposed'];
        }
        $this->batches->setMatchState($supplierId, $matchId, 'rejected', $userId);
        $this->refreshOutcome($supplierId, (int) $m['item_id']);
        return ['ok' => true];
    }

    /** Přepočítá výsledek souboru ze stavu jeho párů (po potvrzení / odmítnutí). */
    public function refreshOutcome(int $supplierId, int $itemId): void
    {
        $item = $this->batches->findItem($supplierId, $itemId);
        if ($item === null) {
            return;
        }
        $states = $this->batches->matchStatesForItem($supplierId, $itemId);
        $outcome = match (true) {
            in_array('attached', $states, true), in_array('confirmed', $states, true) => ScanMatcher::OUTCOME_ATTACHED,
            in_array('proposed', $states, true) => ScanMatcher::OUTCOME_PROPOSED,
            $item['document_id'] === null => ScanMatcher::OUTCOME_UNREADABLE,
            $item['ownership'] === 'own' => ScanMatcher::OUTCOME_ORPHAN,
            $item['ownership'] === 'foreign' => ScanMatcher::OUTCOME_FOREIGN,
            $item['ownership'] === 'unknown' => ScanMatcher::OUTCOME_UNKNOWN,
            default => ScanMatcher::OUTCOME_UNREADABLE,
        };
        $this->batches->updateItem($supplierId, $itemId, ['outcome' => $outcome]);
    }

    /**
     * Přehled dávky pro UI: připojeno, návrhy k potvrzení, doklady bez skenu,
     * skeny firmy bez dokladu, nerozpoznané skeny a (zatím prázdné) rozpory.
     *
     * @param array<string,mixed> $job řádek import_jobs
     * @return array<string,mixed>
     */
    public function overview(int $supplierId, array $job): array
    {
        $jobId = (int) $job['id'];
        $params = is_array($job['params'] ?? null) ? $job['params'] : [];
        $types = $this->targetTypes($params);
        $from = isset($params['date_from']) && is_string($params['date_from']) ? $params['date_from'] : null;
        $to = isset($params['date_to']) && is_string($params['date_to']) ? $params['date_to'] : null;

        $matches = $this->batches->listMatches($supplierId, $jobId);
        $idsByType = [];
        foreach ($matches as $m) {
            $idsByType[(string) $m['target_type']][] = (int) $m['target_id'];
        }
        $labels = [];
        foreach ($idsByType as $type => $ids) {
            $t = $this->targets->get($type);
            $labels[$type] = $t !== null ? $t->describe($supplierId, $ids) : [];
        }

        $items = $this->batches->listItems($supplierId, $jobId);
        $ext = $this->extractions->mapOkBySha($supplierId, array_column($items, 'sha256'), ScanExtractionNormalizer::SCHEMA_VERSION);

        $attached = [];
        $candidates = [];
        foreach ($matches as $m) {
            $row = [
                'match_id' => (int) $m['id'],
                'item_id' => (int) $m['item_id'],
                'file_name' => (string) $m['file_name'],
                'document_id' => $m['document_id'],
                'target_type' => (string) $m['target_type'],
                'target_id' => (int) $m['target_id'],
                'target' => $labels[$m['target_type']][(int) $m['target_id']] ?? null,
                'method' => (string) $m['method'],
                'level' => (string) $m['level'],
                'note' => (string) ($m['note'] ?? ''),
                'state' => (string) $m['state'],
            ];
            if ($m['state'] === 'attached' || $m['state'] === 'confirmed') {
                $attached[] = $row;
            } elseif ($m['state'] === 'proposed') {
                $candidates[] = $row + ['scan' => self::scanSummary($ext[(string) $m['sha256']] ?? null)];
            }
        }

        $orphans = [];
        $unrecognized = [];
        foreach ($items as $i) {
            $row = [
                'item_id' => (int) $i['id'],
                'file_name' => (string) $i['file_name'],
                'document_id' => $i['document_id'],
                'outcome' => (string) $i['outcome'],
                'error' => $i['error'] !== null ? (string) $i['error'] : null,
                'scan' => self::scanSummary($ext[(string) $i['sha256']] ?? null),
            ];
            if ($i['outcome'] === ScanMatcher::OUTCOME_ORPHAN) {
                $orphans[] = $row;
            } elseif (in_array($i['outcome'], [ScanMatcher::OUTCOME_FOREIGN, ScanMatcher::OUTCOME_UNKNOWN, ScanMatcher::OUTCOME_UNREADABLE], true)) {
                $unrecognized[] = $row;
            }
        }

        $missing = [];
        $missingTotal = 0;
        foreach ($types as $type) {
            $t = $this->targets->available($type);
            if ($t === null) {
                continue;
            }
            $w = $t->withoutScan($supplierId, $from, $to, self::LIST_LIMIT);
            $missingTotal += $w['total'];
            foreach ($w['rows'] as $r) {
                $missing[] = ['target_type' => $type] + $r;
            }
        }

        $byOutcome = $this->batches->countItemsByOutcome($supplierId, $jobId);
        return [
            'targets' => $types,
            'date_from' => $from,
            'date_to' => $to,
            'counts' => [
                'files' => array_sum($byOutcome),
                'attached' => count($attached),
                'candidates' => count($candidates),
                'missing' => $missingTotal,
                'orphans' => count($orphans),
                'unrecognized' => count($unrecognized),
                'discrepancies' => 0,
            ],
            'attached' => array_slice($attached, 0, self::LIST_LIMIT),
            'candidates' => array_slice($candidates, 0, self::LIST_LIMIT),
            'missing' => array_slice($missing, 0, self::LIST_LIMIT),
            'orphans' => array_slice($orphans, 0, self::LIST_LIMIT),
            'unrecognized' => array_slice($unrecognized, 0, self::LIST_LIMIT),
            // Porovnání připojeného skenu s údaji dokladu doplní kontrola dokladů
            // proti přílohám; do té doby je sekce prázdná a UI ukáže vysvětlení.
            'discrepancies' => ['available' => false, 'rows' => []],
            'list_limit' => self::LIST_LIMIT,
        ];
    }

    /**
     * @param array<string,mixed>|null $e
     * @return array<string,mixed>|null
     */
    private static function scanSummary(?array $e): ?array
    {
        if ($e === null) {
            return null;
        }
        $out = [];
        foreach (['company_role', 'vendor_name', 'vendor_ico', 'buyer_name', 'buyer_ico', 'document_number', 'variable_symbol',
            'issue_date', 'tax_date', 'total_with_vat', 'currency', 'barcode', 'license_plate', 'card_last4'] as $k) {
            $out[$k] = $e[$k] ?? null;
        }
        return $out;
    }
}
