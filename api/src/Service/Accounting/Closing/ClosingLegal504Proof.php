<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Closing;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\{AccountingFrameworkRepository, DimensionAssignmentRepository, JournalEntryRepository};
use MyInvoice\Service\Accounting\{AccountingFrameworkService, AccountingRoleProfile, JournalLineComparison, PostingException};
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use PDO;

final class ClosingLegal504Proof
{
    private readonly string $savepoint;

    private function __construct(
        private readonly Connection $db,
        public readonly array $header,
        public readonly array $lines,
        public readonly array $stepRow,
        public readonly array $pending,
        public readonly array $record,
        public readonly array $sourceLineIds,
    ) {
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
        if ($period === false || $period['status'] !== 'closing') throw self::changed();
        $date = (string) $period['ends_on'];
        $framework = new AccountingFrameworkService($db, new AccountingFrameworkRepository($db));
        if ($framework->forDateForUpdate($supplierId, $date) !== AccountingFrameworkService::CZ_504) throw self::changed();
        $stmt = $pdo->prepare("SELECT * FROM accounting_closing_steps WHERE supplier_id=? AND period_id=? AND step_key='provisions' FOR UPDATE");
        $stmt->execute([$supplierId, $periodId]);
        $step = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($step === false) throw self::changed();
        $step['decoded_payload'] = json_decode((string) $step['payload'], true, flags: JSON_THROW_ON_ERROR);
        $pending = $step['decoded_payload']['legal_pending'] ?? null;
        if (!is_array($pending) || ($pending['expected_version'] ?? null) !== $expectedVersion
            || !in_array($pending['status'] ?? null, ['pending', 'written'], true)
            || !in_array($pending['operation'] ?? null, ['reserve_release', 'reserve_asset_source', 'provision_release', 'provision_source'], true)) throw self::changed();
        $written = $pending['status'] === 'written';
        if ((int) $period['row_version'] !== $expectedVersion + ($written ? 1 : 0)) throw self::changed();
        $body = $pending['body'] ?? null;
        if (!is_array($body) || trim((string) ($body['description'] ?? '')) === '') throw self::changed();
        $read = new LegalClosing504Repository($db, $journal);
        $profile = new AccountingRoleProfile(AccountingFrameworkService::CZ_504);
        if ($pending['operation'] === 'reserve_asset_source') {
            if (($body['rule_key'] ?? '') !== 'reserve.repairs.create') throw self::changed();
            $decision = AssetReserveSourceDecision::normalize($body['legal_decision']['reserve_source'] ?? null);
            $sourceEvidence = (new AssetReserveSourceRepository($db))->evidence($supplierId, $date, $decision);
            if (!hash_equals($decision['source_hash'], $sourceEvidence['source_hash'])) throw AssetReserveSourceDecision::changed();
            $body['legal_decision']['allocations'] = AssetReserveSourceDecision::allocations($sourceEvidence, ProfitDistribution504Builder::allocationCents($body['amount']));
            $built = LegalClosing504Builder::reserve($profile, $body);
            $sourceLineIds = array_column($sourceEvidence['contributions'], 'line_id');
            $record = ['rule_key' => $body['rule_key'], 'amount' => ProfitDistribution504Builder::allocationCents($body['amount']) / 100,
                'description' => trim((string) $body['description']), 'legal_decision' => $built['decision'], 'asset_source_evidence' => $sourceEvidence];
            $originRef = 'asset_reserve:' . $decision['asset_id'];
        } elseif ($pending['operation'] === 'reserve_release') {
            if (($body['rule_key'] ?? '') !== 'reserve.repairs.release') throw self::changed();
            $sourceId = (int) ($body['legal_decision']['source_entry_id'] ?? 0);
            $remaining = $written ? array_fill_keys(array_map(LegalClosing504Builder::cellKey(...), $body['legal_decision']['allocations'] ?? []), PHP_INT_MAX)
                : $read->reserveRemaining($supplierId, $sourceId, $date);
            $built = LegalClosing504Builder::reserve($profile, $body, $remaining);
            $source = $journal->findForUpdate($sourceId, $supplierId);
            if ($source === null || $source['posted_at'] === null) throw self::changed();
            $sourceLineIds = array_map('intval', array_column($source['lines'], 'id'));
            $record = ['rule_key' => $body['rule_key'], 'amount' => ProfitDistribution504Builder::allocationCents($body['amount']) / 100,
                'description' => trim((string) $body['description']), 'legal_decision' => $built['decision']];
            $originRef = 'legal_reserve:' . $sourceId;
        } else {
            $invoiceId = (int) ($body['invoice_id'] ?? 0);
            if ($invoiceId <= 0) throw self::changed();
            $decision = LegalClosing504Builder::decision($profile, 'provision.legal', $body['legal_decision'] ?? null, $body['legal_amount'] ?? null, true);
            if (!$written && LegalClosing504Builder::targetCents($body['legal_amount']) > $read->receivableRemaining($supplierId, $invoiceId, $date)) {
                throw new ClosingException('provision_exceeds_receivable', 'Cílová opravná položka přesahuje aktuální neuhrazenou hodnotu pohledávky.', 422);
            }
            $current = $read->provisionCells($supplierId, $invoiceId, $date, $written ? (int) ($pending['entry_id'] ?? 0) : null);
            $sourceLineIds = $read->verifiedSourceLineIds($supplierId);
            $movements = LegalClosing504Builder::provisionMovements($decision, $current);
            if ($movements === []) throw self::changed();
            $sourceEvidence = null;
            if ($pending['operation'] === 'provision_source') {
                if (($decision['allocation_basis'] ?? '') !== 'source_joint' || ($decision['payment_allocation'] ?? '') !== 'proportional') throw self::changed();
                if ($written) {
                    $stored = array_values(array_filter($step['decoded_payload']['entries'] ?? [], static fn (array $row): bool => (int) ($row['entry_id'] ?? 0) === (int) ($pending['entry_id'] ?? 0)));
                    if (count($stored) !== 1 || !is_array($stored[0]['source_evidence'] ?? null)) throw self::changed();
                    $sourceEvidence = $stored[0]['source_evidence'];
                } else {
                    $sourceEvidence = $read->receivableSource($supplierId, $invoiceId, $date);
                    LegalClosing504Builder::assertAvailable($decision['allocations'], array_combine(array_keys($sourceEvidence['cells']), array_column($sourceEvidence['cells'], 'available_cents')));
                }
                $sourceLineIds = array_values(array_unique([...$sourceLineIds, ...$sourceEvidence['source_line_ids']]));
            } else {
                foreach ($movements as $cell) if ($cell['direction'] !== 'release') throw self::changed();
            }
            $built = ['decision' => $decision, 'lines' => LegalClosing504Builder::provisionMovementLines($profile, $movements)];
            $record = ['rule_key' => 'allowance.receivable.legal.change', 'invoice_id' => $invoiceId,
                'legal_amount' => LegalClosing504Builder::targetCents($body['legal_amount']) / 100, 'legal_decision' => $decision,
                'movement_allocations' => $movements];
            if ($sourceEvidence !== null) $record['source_evidence'] = $sourceEvidence;
            $originRef = 'legal_provision:' . $invoiceId;
        }
        $stmt = $pdo->prepare('SELECT locked_until FROM accounting_supplier_settings WHERE supplier_id=? FOR UPDATE');
        $stmt->execute([$supplierId]);
        $lockedUntil = $stmt->fetchColumn();
        if (!$written && $lockedUntil && $date <= $lockedUntil) throw new PostingException('date_locked', 'Datum zápisu leží v uzamčené části účetnictví.', 422);
        $lines = $read->resolveAccounts($supplierId, $built['lines']);
        $stmt = $pdo->prepare('SELECT id,is_active,account_type FROM chart_of_accounts WHERE supplier_id=? ORDER BY id FOR UPDATE');
        $stmt->execute([$supplierId]);
        $accounts = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), null, 'id');
        foreach ($lines as $index => &$line) {
            $account = $accounts[$line['account_id']] ?? null;
            if ($account === null || !$account['is_active'] || $account['account_type'] === 'offbalance') throw new PostingException('account_inactive', 'Účet doloženého zápisu chybí nebo není aktivní.', 422);
            $line['line_no'] = $index;
            foreach ($line['dimensions'] as $type => $value) $line['dimension_origins'][$type] = ['origin' => 'module', 'ref' => $originRef];
        }
        unset($line);
        $header = ['supplier_id' => $supplierId, 'period_id' => $periodId, 'entry_date' => $date, 'document_date' => $date,
            'source_type' => 'manual', 'source_id' => null, 'document_no' => $pending['document_no'] ?? null,
            'description' => trim((string) $body['description']), 'posted_at' => $pending['posted_at'] ?? null, 'posted_by' => $pending['posted_by'] ?? null];
        if (!is_string($header['document_no']) || $header['document_no'] === '' || !is_string($header['posted_at'])) throw self::changed();
        $record += ['document_no' => $header['document_no'], 'created_at' => $header['posted_at']];
        if ($written) {
            $entry = $journal->findForUpdate((int) ($pending['entry_id'] ?? 0), $supplierId);
            if ($entry === null || $entry['reversed_by'] !== null || $entry['row_version'] !== ($pending['entry_version'] ?? null)) throw self::changed();
            foreach ($header as $key => $value) if ((string) ($entry[$key] ?? '') !== (string) ($value ?? '')) throw self::changed();
            $assignments = new DimensionAssignmentRepository($db);
            $dims = $assignments->lineDimensions($supplierId, array_column($entry['lines'], 'id'), true);
            $splits = $assignments->lineSplits($supplierId, array_column($entry['lines'], 'id'), true);
            foreach ($entry['lines'] as &$line) {
                $line['dimensions'] = $dims[$line['id']] ?? [];
                $line['dimension_splits'] = $splits[$line['id']] ?? [];
            }
            unset($line);
            if (!JournalLineComparison::sameLines($lines, $entry['lines'])) throw self::changed();
            $stored = array_values(array_filter($step['decoded_payload']['entries'] ?? [], static fn (array $row): bool => (int) ($row['entry_id'] ?? 0) === (int) $entry['id']));
            if (count($stored) !== 1 || $stored[0] !== ['entry_id' => (int) $entry['id']] + $record) throw self::changed();
        }
        return new self($db, $header, $lines, $step, $pending, $record, $sourceLineIds);
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
    public function __serialize(): array { throw new \LogicException('Asset carry proofs cannot be serialized.'); }
    public function __unserialize(array $data): void { throw new \LogicException('Asset carry proofs cannot be restored.'); }

    public function indices(Connection $db, array $header, array $lines): array
    {
        $this->assertConnection($db);
        $journal = new JournalEntryRepository($db, new \MyInvoice\Service\ActivityLogger($db));
        $current = self::forPending($db, $journal, (int) $this->stepRow['supplier_id'], (int) $this->stepRow['period_id'], (int) $this->pending['expected_version']);
        if ($header !== $this->header || $lines !== $this->lines || $current->header !== $this->header || $current->lines !== $this->lines
            || $current->pending !== $this->pending || (int) $current->stepRow['id'] !== (int) $this->stepRow['id'] || $current->record !== $this->record || $current->sourceLineIds !== $this->sourceLineIds) throw self::changed();
        return array_fill_keys(array_keys($lines), true);
    }

    public static function changed(): ClosingException
    {
        return new ClosingException('legal_closing_source_changed', 'Doložené rozhodnutí nebo jeho zdroj se změnily. Obnovte podklady.', 409);
    }
}
