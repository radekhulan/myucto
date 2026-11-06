<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Closing;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\{AccountingFrameworkRepository, JournalEntryRepository};
use MyInvoice\Service\Accounting\{AccountingFrameworkService, PostingException};
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use PDO;

final class AssetReserveCorrection504Proof
{
    private readonly string $savepoint;

    private function __construct(private readonly Connection $db, public readonly array $header, public readonly array $lines,
        public readonly array $stepRow, public readonly array $pending, public readonly array $revision) {
        $this->savepoint = 'asset_carry_' . bin2hex(random_bytes(10));
        $db->pdo()->exec('SAVEPOINT ' . $this->savepoint);
    }

    public static function forPending(Connection $db, JournalEntryRepository $journal, int $supplierId, int $periodId, int $expectedVersion): self
    {
        (new \MyInvoice\Service\Accounting\Dimension\SystemDimensionWritePolicy($db))->lockContext($supplierId);
        $pdo = $db->pdo();
        $stmt = $pdo->prepare('SELECT * FROM accounting_periods WHERE supplier_id=? AND id=? FOR UPDATE');
        $stmt->execute([$supplierId, $periodId]);
        $period = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($period === false || $period['status'] !== 'closing') throw AssetReserveSourceDecision::changed();
        $date = $period['ends_on'];
        if ((new AccountingFrameworkService($db, new AccountingFrameworkRepository($db)))->forDateForUpdate($supplierId, $date) !== AccountingFrameworkService::CZ_504) throw AssetReserveSourceDecision::changed();
        $stmt = $pdo->prepare("SELECT * FROM accounting_closing_steps WHERE supplier_id=? AND period_id=? AND step_key='provisions' FOR UPDATE");
        $stmt->execute([$supplierId, $periodId]);
        $step = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($step === false) throw AssetReserveSourceDecision::changed();
        $step['decoded_payload'] = json_decode($step['payload'], true, flags: JSON_THROW_ON_ERROR);
        $pending = $step['decoded_payload']['asset_correction_pending'] ?? null;
        if (!is_array($pending) || !in_array($pending['status'] ?? null, ['pending', 'written'], true)
            || ($pending['expected_version'] ?? null) !== $expectedVersion) throw AssetReserveSourceDecision::changed();
        $written = $pending['status'] === 'written';
        if ((int) $period['row_version'] !== $expectedVersion + ($written ? 1 : 0)) throw AssetReserveSourceDecision::changed();
        $read = new LegalClosing504Repository($db, $journal);
        if ($written) {
            $revision = $step['decoded_payload']['asset_source_revisions'][$pending['revision_index']] ?? [];
            $read->verifyAssociationTransfer($supplierId, $revision);
            $state = (new AssetReserveSourceRepository($db))->bindings($supplierId)[$revision['source_evidence']['asset_id']] ?? null;
            if ($state === null || end($state['revisions']) !== $revision) throw AssetReserveSourceDecision::changed();
            return new self($db, [], [], $step, $pending, $revision);
        }
        $body = $pending['body'] ?? [];
        if (($body['mode'] ?? null) !== 'prospective_closed_history') throw AssetReserveSourceDecision::invalid();
        $source = AssetReserveSourceDecision::normalize($body['reserve_source'] ?? null);
        $evidence = (new AssetReserveSourceRepository($db))->correctionEvidence($supplierId, $date, $body);
        if (!hash_equals($source['source_hash'], $evidence['source']['source_hash'])) throw AssetReserveSourceDecision::changed();
        $stmt = $pdo->prepare('SELECT locked_until FROM accounting_supplier_settings WHERE supplier_id=? FOR UPDATE');
        $stmt->execute([$supplierId]);
        $locked = $stmt->fetchColumn();
        if ($locked && $date <= $locked) throw new PostingException('date_locked', 'Datum zápisu leží v uzamčené části účetnictví.', 422);
        $lines = $read->resolveAccounts($supplierId, $evidence['lines']);
        $stmt = $pdo->prepare('SELECT id,is_active,account_type FROM chart_of_accounts WHERE supplier_id=? ORDER BY id FOR UPDATE');
        $stmt->execute([$supplierId]);
        $accounts = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), null, 'id');
        foreach ($lines as $index => &$line) {
            $account = $accounts[$line['account_id']] ?? null;
            if ($account === null || !$account['is_active'] || $account['account_type'] === 'offbalance') throw new PostingException('account_inactive', 'Účet doloženého zápisu chybí nebo není aktivní.', 422);
            $line['line_no'] = $index;
            foreach ($line['dimensions'] as $type => $value) $line['dimension_origins'][$type] = ['origin' => 'module', 'ref' => 'asset_reserve_correction:' . $source['asset_id']];
        }
        unset($line);
        $header = ['supplier_id' => $supplierId, 'period_id' => $periodId, 'entry_date' => $date, 'document_date' => $date,
            'source_type' => 'manual', 'source_id' => null, 'document_no' => $pending['document_no'],
            'description' => 'Oprava analytického přiřazení existující rezervy', 'posted_at' => $pending['posted_at'], 'posted_by' => $pending['meta']['user_id'] ?? null];
        $revision = ['version' => 'asset-reserve-revision-v2', 'period_id' => $periodId, 'effective_on' => $date,
            'revision_number' => $evidence['revision_number'], 'previous_hash' => $evidence['previous']['source_hash'],
            'previous_source_evidence' => $evidence['previous'], 'source_evidence' => $evidence['source'],
            'source_date' => $evidence['source_date'], 'previous_source_date' => $evidence['previous_source_date'],
            'reason' => $evidence['reason'], 'original_purpose' => $evidence['original_purpose'],
            'continued_use_evidence' => $source['continued_use_evidence'], 'transfers' => $evidence['transfers'],
            'pinned_entries' => $evidence['pinned_entries'], 'created_at' => $pending['posted_at'], 'created_by' => $pending['meta']['user_id'] ?? null];
        return new self($db, $header, $lines, $step, $pending, $revision);
    }

    public function assertConnection(Connection $db): void
    {
        if ($db->pdo() !== $this->db->pdo() || !$db->pdo()->inTransaction()) throw AssetReserveSourceDecision::changed();
        try { $db->pdo()->exec('RELEASE SAVEPOINT ' . $this->savepoint); }
        catch (\PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1305) throw AssetReserveSourceDecision::changed();
            throw $e;
        }
        $db->pdo()->exec('SAVEPOINT ' . $this->savepoint);
    }

    private function __clone() {}
    public function __serialize(): array { throw new \LogicException('Asset carry proofs cannot be serialized.'); }
    public function __unserialize(array $data): void { throw new \LogicException('Asset carry proofs cannot be restored.'); }

    public function indices(Connection $db, array $header, array $lines): array
    {
        $this->assertConnection($db);
        $journal = new JournalEntryRepository($db, new \MyInvoice\Service\ActivityLogger($db));
        $current = self::forPending($db, $journal, (int) $this->stepRow['supplier_id'], (int) $this->stepRow['period_id'], (int) $this->pending['expected_version']);
        if ($header !== $this->header || $lines !== $this->lines || $current->header !== $this->header || $current->lines !== $this->lines
            || $current->pending !== $this->pending || (int) $current->stepRow['id'] !== (int) $this->stepRow['id'] || $current->revision !== $this->revision) throw AssetReserveSourceDecision::changed();
        return array_fill_keys(array_keys($lines), true);
    }
}
