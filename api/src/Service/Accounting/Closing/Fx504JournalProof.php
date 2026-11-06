<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Closing;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingFrameworkRepository;
use MyInvoice\Repository\Fx504ReadRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Repository\DimensionAssignmentRepository;
use MyInvoice\Service\Accounting\AccountingFrameworkService;
use MyInvoice\Service\Accounting\AccountingPeriodStatus;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Accounting\JournalLineAmount;
use PDO;

final class Fx504JournalProof
{
    private readonly string $savepoint;

    private function __construct(
        private readonly Connection $db,
        public readonly array $header,
        public readonly array $lines,
        public readonly array $retained,
        public readonly array $record,
        public readonly array $stepRow,
        public readonly string $slot,
    ) {
        $this->savepoint = 'fx504_' . bin2hex(random_bytes(10));
        $db->pdo()->exec('SAVEPOINT ' . $this->savepoint);
    }

    public static function forPending(Connection $db, int $supplierId, int $stepId, string $slot, int $expectedVersion): self
    {
        (new \MyInvoice\Service\Accounting\Dimension\SystemDimensionWritePolicy($db))->lockContext($supplierId);
        if ($slot === 'reversal') return self::forReversal($db, $supplierId, $stepId, $expectedVersion);
        (new \MyInvoice\Service\Accounting\Dimension\SystemDimensionWritePolicy($db))->lockContext($supplierId);
        $stmt = $db->pdo()->prepare("SELECT * FROM accounting_closing_steps WHERE supplier_id = ? AND id = ? AND step_key = 'fx_revaluation' FOR UPDATE");
        $stmt->execute([$supplierId, $stepId]);
        $step = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$step || $step['status'] !== 'pending' || !in_array($slot, ['saldo', 'bank'], true)) throw self::changed();
        $payload = json_decode($step['payload'], true, 512, JSON_THROW_ON_ERROR);
        $record = $payload['fx504'] ?? null;
        if (!is_array($record) || ($record['expected_version'] ?? null) !== $expectedVersion || ($record['slots'][$slot]['status'] ?? null) !== 'pending') throw self::changed();
        $snapshot = (new Fx504ReadRepository($db))->snapshot($supplierId, (int) $step['period_id'], true);
        $period = $snapshot['period'];
        if ($period['status'] !== 'closing' || (int) $period['row_version'] !== $expectedVersion
            || !is_string($record['snapshot_hash'] ?? null) || !hash_equals($snapshot['snapshot_hash'], $record['snapshot_hash'])) throw self::changed();
        $frameworks = new AccountingFrameworkService($db, new AccountingFrameworkRepository($db));
        if ($frameworks->forDateForUpdate($supplierId, $period['ends_on']) !== AccountingFrameworkService::CZ_504) throw self::changed();
        $lock = $db->pdo()->prepare('SELECT locked_until FROM accounting_supplier_settings WHERE supplier_id = ? FOR UPDATE');
        $lock->execute([$supplierId]);
        $lockedUntil = $lock->fetchColumn();
        if ($lockedUntil && $period['ends_on'] <= $lockedUntil) throw new ClosingException('date_locked', 'Datum zápisu leží v uzamčené části účetnictví.', 422);
        if (($record['case_projection_version'] ?? null) !== 1 || ($record['case_source_hash'] ?? null) !== $snapshot['case_snapshot']['hash']) throw self::changed();
        if (($record['securities_inventory_hash'] ?? null) !== $snapshot['securities_snapshot']['hash']) throw self::changed();
        $preview = SecuritiesValuation504Evidence::preview($snapshot, $record['account_decisions'] ?? [], $record['case_decisions'] ?? null, $record['securities_evidence'] ?? null);
        if ($preview['securities'] !== ($record['securities_assessment'] ?? null)) throw self::changed();
        $built = Fx504Builder::build($preview, $record['decisions'] ?? [], $snapshot['required_types']);
        if ($built['case_materialization'] !== ($record['case_materialization'] ?? null) || hash('sha256', json_encode($built['case_materialization'], JSON_THROW_ON_ERROR)) !== ($record['case_materialization_hash'] ?? null)) throw self::changed();
        $accounts = $db->pdo()->prepare('SELECT id, account_code, account_type, is_active FROM chart_of_accounts WHERE supplier_id = ? ORDER BY id FOR UPDATE');
        $accounts->execute([$supplierId]);
        $byCode = array_column($accounts->fetchAll(PDO::FETCH_ASSOC), null, 'account_code');
        $values = array_column($snapshot['dimension_values'], null, 'id');
        $types = array_column($snapshot['dimension_types'], null, 'id');
        $lines = [];
        $retained = [];
        foreach ($built[$slot] as $index => $line) {
            $account = $byCode[$line['account_code']] ?? null;
            if (!$account || !$account['is_active'] || $account['account_type'] === 'offbalance') throw new ClosingException('account_inactive', 'Účet přecenění chybí nebo není aktivní.', 422);
            $sourceDims = $built[$slot][$index - $index % 2]['dimensions'];
            $retained[$index] = ['dimensions' => $sourceDims];
            foreach ($line['dimensions'] as $type => $value) {
                $definition = $values[$value] ?? null;
                if (!$definition || (int) $definition['type_id'] !== (int) $type || !isset($types[$type])) throw self::changed();
                if ((!$definition['is_active'] || !$types[$type]['is_active']) && ($sourceDims[$type] ?? null) !== $value) {
                    throw new ClosingException('dimension_closed', 'Nově doplněná výsledková dimenze musí být aktivní.', 422);
                }
                $line['dimension_origins'][$type] = ['origin' => 'module', 'ref' => 'fx504:' . substr($snapshot['snapshot_hash'], 0, 32)];
            }
            $line['account_id'] = (int) $account['id'];
            $line['line_no'] = $index;
            $lines[] = $line;
        }
        $slotRecord = $record['slots'][$slot];
        if (!is_string($slotRecord['document_no'] ?? null) || !is_string($record['posted_at'] ?? null)) throw self::changed();
        $header = ['supplier_id' => $supplierId, 'period_id' => (int) $period['id'], 'entry_date' => $period['ends_on'],
            'document_date' => $period['ends_on'], 'document_no' => $slotRecord['document_no'], 'description' => 'Kurzové rozdíly k rozvahovému dni',
            'source_type' => 'fx_revaluation', 'source_id' => $slot === 'saldo' ? ClosingSourceId::fxSaldo((int) $period['id']) : ClosingSourceId::fxBank((int) $period['id']),
            'posted_at' => $record['posted_at'], 'posted_by' => $record['posted_by'] ?? null];
        $step['decoded_payload'] = $payload;
        return new self($db, $header, $lines, $retained, $record, $step, $slot);
    }

    private static function forReversal(Connection $db, int $supplierId, int $stepId, int $expectedVersion): self
    {
        (new \MyInvoice\Service\Accounting\Dimension\SystemDimensionWritePolicy($db))->lockContext($supplierId);
        $stmt = $db->pdo()->prepare("SELECT * FROM accounting_closing_steps WHERE supplier_id = ? AND id = ? AND step_key = 'fx_revaluation' FOR UPDATE");
        $stmt->execute([$supplierId, $stepId]);
        $step = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$step || $step['status'] !== 'done') throw self::changed();
        $payload = json_decode($step['payload'], true, 512, JSON_THROW_ON_ERROR);
        $record = $payload['fx504'] ?? null;
        $reverse = $record['slots']['reversal'] ?? null;
        if (!is_array($reverse) || ($reverse['status'] ?? null) !== 'pending' || ($reverse['expected_version'] ?? null) !== $expectedVersion) throw self::changed();
        $stmt = $db->pdo()->prepare('SELECT * FROM accounting_periods WHERE supplier_id = ? AND id IN (?, ?) ORDER BY starts_on,id FOR UPDATE');
        $stmt->execute([$supplierId, $step['period_id'], $reverse['target_period_id'] ?? 0]);
        $periods = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), null, 'id');
        $source = $periods[$step['period_id']] ?? null;
        $target = $periods[$reverse['target_period_id'] ?? 0] ?? null;
        if (!$source || !$target || !AccountingPeriodStatus::isClosed((string) $source['status']) || $target['status'] !== 'open'
            || (int) $source['row_version'] !== $expectedVersion
            || $target['starts_on'] !== (new \DateTimeImmutable($source['ends_on']))->modify('+1 day')->format('Y-m-d')) throw self::changed();
        $framework = new AccountingFrameworkService($db, new AccountingFrameworkRepository($db));
        if ($framework->forDateForUpdate($supplierId, $source['ends_on']) !== AccountingFrameworkService::CZ_504
            || $framework->forDateForUpdate($supplierId, $target['starts_on']) !== AccountingFrameworkService::CZ_504) throw self::changed();
        $settings = $db->pdo()->prepare('SELECT fx_reversal_at_open, locked_until FROM accounting_supplier_settings WHERE supplier_id = ? FOR UPDATE');
        $settings->execute([$supplierId]);
        $setting = $settings->fetch(PDO::FETCH_ASSOC);
        if ($setting && (!$setting['fx_reversal_at_open'] || ($setting['locked_until'] && $target['starts_on'] <= $setting['locked_until']))) throw self::changed();
        $journal = new JournalEntryRepository($db, new \MyInvoice\Service\ActivityLogger($db));
        $original = $journal->findActiveBySourceForUpdate($supplierId, 'fx_revaluation', ClosingSourceId::fxSaldo((int) $source['id']));
        if (!$original || !$original['posted_at'] || (int) $original['id'] !== ($reverse['source_entry_id'] ?? null)
            || (int) $original['row_version'] !== ($reverse['source_entry_version'] ?? null)
            || (int) $original['period_id'] !== (int) $source['id'] || $original['entry_date'] !== $source['ends_on']) throw self::changed();
        $rows = self::sourceLines($db, $journal, $supplierId, (int) $original['id']);
        if (!hash_equals(self::sourceHash($rows), (string) ($reverse['source_hash'] ?? ''))) throw self::changed();
        $lines = [];
        foreach ($rows as $index => $row) {
            $line = JournalLineAmount::reversal($row);
            unset($line['id'], $line['entry_id'], $line['supplier_id']);
            $line['line_no'] = $index;
            $lines[] = $line;
        }
        if ($lines === []) throw self::changed();
        $header = ['supplier_id' => $supplierId, 'period_id' => (int) $target['id'], 'entry_date' => $target['starts_on'], 'document_date' => $target['starts_on'],
            'source_type' => 'fx_revaluation', 'source_id' => ClosingSourceId::fxReversal((int) $source['id']),
            'document_no' => $reverse['document_no'] ?? null, 'description' => 'Storno přecenění saldokonta k 1. dni období',
            'posted_at' => $reverse['posted_at'] ?? null, 'posted_by' => $reverse['posted_by'] ?? null];
        if ($header['posted_at'] === null || $header['document_no'] === null) throw self::changed();
        $step['decoded_payload'] = $payload;
        return new self($db, $header, $lines, array_map(static fn (array $line): array => array_intersect_key($line, ['dimensions' => true, 'dimension_splits' => true]), $lines), $record, $step, 'reversal');
    }

    public static function sourceLines(Connection $db, JournalEntryRepository $journal, int $supplierId, int $entryId): array
    {
        $rows = $journal->linesForEntry($entryId, $supplierId, true);
        $ids = array_column($rows, 'id');
        $assignments = new DimensionAssignmentRepository($db);
        $dimensions = $assignments->lineDimensions($supplierId, $ids, true);
        $splits = $assignments->lineSplits($supplierId, $ids, true);
        $origins = $assignments->lineOrigins($supplierId, $ids, true);
        $splitOrigins = $assignments->lineSplitOrigins($supplierId, $ids, true);
        foreach ($rows as &$row) {
            $row['dimensions'] = $dimensions[$row['id']] ?? [];
            $row['dimension_splits'] = $splits[$row['id']] ?? [];
            $row['dimension_origins'] = $origins[$row['id']] ?? [];
            $row['dimension_split_origins'] = $splitOrigins[$row['id']] ?? [];
        }
        unset($row);
        return $rows;
    }

    public static function sourceHash(array $rows): string
    {
        return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
    }

    public function assertConnection(Connection $db): void
    {
        if ($db->pdo() !== $this->db->pdo() || !$db->pdo()->inTransaction()) throw self::changed();
        try { $db->pdo()->exec('RELEASE SAVEPOINT ' . $this->savepoint); }
        catch (\PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1305) throw self::changed();
            throw $e;
        }
        $db->pdo()->exec('SAVEPOINT ' . $this->savepoint);
    }

    private function __clone() {}
    public function __serialize(): array { throw new \LogicException('FX proofs cannot be serialized.'); }
    public function __unserialize(array $data): void { throw new \LogicException('FX proofs cannot be restored.'); }

    public function assignments(Connection $db, array $header, array $lines): array
    {
        $this->assertConnection($db);
        $version = $this->slot === 'reversal' ? $this->record['slots']['reversal']['expected_version'] : $this->record['expected_version'];
        $current = self::forPending($db, (int) $this->header['supplier_id'], (int) $this->stepRow['id'], $this->slot, (int) $version);
        if ($header !== $this->header || $lines !== $this->lines || $current->header !== $this->header || $current->lines !== $this->lines
            || $current->record !== $this->record || $current->retained !== $this->retained || $current->stepRow !== $this->stepRow) throw self::changed();
        return $this->retained;
    }

    public static function changed(): ClosingException
    {
        return new ClosingException('fx504_changed', 'Podklady kurzového přecenění se změnily. Načtěte náhled znovu.', 409);
    }
}
