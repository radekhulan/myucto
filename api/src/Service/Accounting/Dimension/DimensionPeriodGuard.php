<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Dimension;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Service\Accounting\AccountingPeriodStatus;
use PDO;

final class DimensionPeriodGuard
{
    private static int $savepoint = 0;

    public function __construct(private readonly Connection $db) {}

    /**
     * @template T
     * @param callable():T $work
     * @return T
     */
    public function transaction(callable $work): mixed
    {
        $pdo = $this->db->pdo();
        $own = !$pdo->inTransaction();
        $savepoint = 'dimension_period_' . ++self::$savepoint;
        $own ? $pdo->beginTransaction() : $pdo->exec('SAVEPOINT ' . $savepoint);
        try {
            $result = $work();
            $own ? $pdo->commit() : $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            return $result;
        } catch (\Throwable $e) {
            if (!TransactionFailure::rollbackIfAborted($pdo, $e) && $pdo->inTransaction()) {
                $own ? $pdo->rollBack() : $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
            }
            throw $e;
        }
    }

    /** @param list<array{0:string,1:int}> $sources @return array<int,array<string,mixed>> */
    public function lockSources(int $supplierId, array $sources): array
    {
        $this->lockPeriods($supplierId);
        $find = $this->db->pdo()->prepare(
            'SELECT id FROM journal_entries WHERE supplier_id = ? AND source_type = ? AND source_id = ? FOR UPDATE'
        );
        $entryIds = [];
        foreach ($sources as [$sourceType, $sourceId]) {
            $find->execute([$supplierId, $sourceType, $sourceId]);
            array_push($entryIds, ...array_map('intval', $find->fetchAll(PDO::FETCH_COLUMN)));
        }
        return $this->lockEntries($supplierId, $entryIds);
    }

    /** @return array<int,string> */
    public function lockPeriods(int $supplierId): array
    {
        $pdo = $this->db->pdo();
        if (!$pdo->inTransaction()) {
            throw new \LogicException('Dimension period locks require a transaction.');
        }
        // Current read je nutný i při snapshotu vytvořeném dříve transakcí volajícího.
        // Zdroj mohl být mezitím zaúčtovaný do kteréhokoli období firmy.
        $periods = $pdo->prepare(
            'SELECT id, status FROM accounting_periods WHERE supplier_id = ? ORDER BY starts_on, id FOR UPDATE'
        );
        $periods->execute([$supplierId]);
        $statuses = [];
        foreach ($periods->fetchAll(PDO::FETCH_ASSOC) as $period) {
            $statuses[(int) $period['id']] = (string) $period['status'];
        }
        return $statuses;
    }

    /**
     * @param list<int> $entryIds
     * @param bool $withDimensionFreeze zjistit i zmrazení dimenzí uzávěrkou; volající, který dimenze
     *        nemění, ho nechce (zámek období ho před souběžnou uzávěrkou chrání i bez toho)
     * @return array<int,array<string,mixed>>
     */
    public function lockEntries(int $supplierId, array $entryIds, bool $withDimensionFreeze = true): array
    {
        $pdo = $this->db->pdo();
        if (!$pdo->inTransaction()) {
            throw new \LogicException('Dimension period locks require a transaction.');
        }
        $entryIds = array_values(array_unique(array_map('intval', $entryIds)));
        if ($entryIds === []) {
            return [];
        }
        sort($entryIds, SORT_NUMERIC);
        $statuses = $this->lockPeriods($supplierId);
        // Období se zamyká před deníkem stejně jako v PostingService a closeBooks().
        $entryRows = [];
        foreach (array_chunk($entryIds, 1000) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $entries = $pdo->prepare(
                "SELECT id, period_id, source_type, source_id, entry_date, posted_at, reversed_by
                   FROM journal_entries WHERE supplier_id = ? AND id IN ({$marks}) ORDER BY id FOR UPDATE"
            );
            $entries->execute([$supplierId, ...$chunk]);
            array_push($entryRows, ...$entries->fetchAll(PDO::FETCH_ASSOC));
        }
        $out = [];
        $frozen = [];
        if ($withDimensionFreeze) {
            $closing = $pdo->prepare(
                "SELECT id FROM journal_entries FORCE INDEX (idx_je_supplier_period_source) WHERE supplier_id = ? AND period_id = ?
                    AND source_type = 'closing' AND posted_at IS NOT NULL AND reversed_by IS NULL LIMIT 1 FOR UPDATE"
            );
            $entryPeriods = array_flip(array_map('intval', array_column($entryRows, 'period_id')));
            foreach ($statuses as $periodId => $_) {
                if (isset($entryPeriods[$periodId])) {
                    $closing->execute([$supplierId, $periodId]);
                    $frozen[$periodId] = $closing->fetchColumn() !== false;
                }
            }
        }
        foreach ($entryRows as $entry) {
            $periodId = (int) $entry['period_id'];
            if (!isset($statuses[$periodId])) {
                throw new DimensionException('period_not_open', 'Účetní období zápisu se změnilo. Obnovte data.', 409);
            }
            $entry['period_status'] = $statuses[$periodId];
            if ($withDimensionFreeze) {
                $entry['dimensions_frozen'] = $frozen[$periodId];
            }
            $out[(int) $entry['id']] = $entry;
        }
        return $out;
    }

    /** @param array<string,mixed> $entry */
    public function assertMutable(array $entry): void
    {
        if (!array_key_exists('dimensions_frozen', $entry)) {
            throw new \LogicException('Zápis byl zamčen bez zjištění zmrazení dimenzí.');
        }
        if (AccountingPeriodStatus::isClosed((string) $entry['period_status']) || !empty($entry['dimensions_frozen'])) {
            throw new DimensionException('period_not_open', 'Dimenze uzavřeného účetního období nelze měnit. Nejprve znovu otevřete účetní knihy.', 409);
        }
    }
}
