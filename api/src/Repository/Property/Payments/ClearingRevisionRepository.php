<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Property\Payments;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Property\Charges\Issuance\IssuanceRequest;
use MyInvoice\Service\Property\Payments\{PaymentException, PaymentLocks, PaymentSourceProof};
use MyInvoice\Service\Property\Payments\Workflow\{ClearingCells, ClearingRevisionCells, ClearingRevisionHistory, ClearingRevisionIntent, ClearingRevisionJournalPlan};
use MyInvoice\Service\Property\{PropertyInput, PropertyInstant};
use PDO;

final class ClearingRevisionRepository
{
    public function __construct(private readonly Connection $db, private readonly JournalEntryRepository $journal) {}

    public function preview(int $supplier, array $request): array
    {
        $request = self::normalize($request);
        return (new DimensionPeriodGuard($this->db))->transaction(fn (): array => $this->plan($supplier, $request));
    }

    public function apply(int $supplier, array $request, string $previewHash, string $token, int $actor): array
    {
        $request = self::normalize($request); PropertyInput::id($actor, 'actor'); PropertyInput::code($token, 'idempotency_key');
        $hash = ClearingRevisionHistory::requestHash($request, $token, $actor);
        return (new DimensionPeriodGuard($this->db))->transaction(function () use ($supplier, $request, $previewHash, $token, $actor, $hash): array {
            (new PaymentLocks($this->db))->acquire($supplier);
            $q = $this->db->pdo()->prepare('SELECT * FROM unit_payment_source_revisions WHERE supplier_id=? AND idempotency_key=? FOR UPDATE');
            $q->execute([$supplier, $token]); $old = $q->fetch(PDO::FETCH_ASSOC);
            if ($old) {
                if (!hash_equals($old['request_hash'], $hash)) PaymentException::reject('idempotency_conflict');
                if ($old['status'] !== 'posted') ClearingRevisionHistory::corrupt();
                $source = $this->source($supplier, (int) $old['source_id']);
                if (($source['revision_number'] ?? 0) < (int) $old['revision_no']) ClearingRevisionHistory::corrupt();
                return self::result($old);
            }
            $plan = $this->plan($supplier, $request);
            if (!hash_equals($plan['preview_hash'], $previewHash)) PaymentException::reject('preview_stale');
            $snapshot = $plan['snapshot']; $number = $snapshot['revision_number'];
            $q = $this->db->pdo()->prepare("INSERT INTO unit_payment_source_revisions (supplier_id,source_id,revision_no,previous_revision_id,expected_source_version,accounting_date,effective_at,request_json,snapshot_json,request_hash,snapshot_hash,idempotency_key,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $q->execute([$supplier, $request['source_id'], $number, $request['expected_revision_id'], $request['expected_source_version'],
                $request['accounting_date'], PropertyInstant::sql($request['effective_at']), IssuanceRequest::json($request), IssuanceRequest::json($snapshot), $hash,
                PropertyInput::hash($snapshot), $token, $actor]);
            $id = (int) $this->db->pdo()->lastInsertId();
            $entry = $this->journal->insertPropertySourceRevision(ClearingRevisionIntent::pending($this->db, $this, $supplier, $id));
            $q = $this->db->pdo()->prepare('SELECT * FROM unit_payment_source_revisions WHERE supplier_id=? AND id=? FOR UPDATE'); $q->execute([$supplier, $id]);
            return self::result($q->fetch(PDO::FETCH_ASSOC));
        });
    }

    public function completePending(ClearingRevisionIntent $intent, int $entry): void
    {
        $proof = $intent->journal($this->db); $supplier = $intent->supplier; $id = $intent->revisionId;
        $ledger = (new \MyInvoice\Service\Property\Payments\PaymentJournalProof($this->db))->current($supplier, $entry);
        if ($ledger['entry']['source_type'] !== 'unit_payment_revision' || $ledger['entry']['source_id'] !== $id || $ledger['entry']['reversed_by'] !== null
            || $ledger['entry']['entry_date'] !== $proof['header']['entry_date'] || $ledger['entry']['period_id'] !== $proof['header']['period_id']
            || PropertyInput::hash(\MyInvoice\Service\Property\Payments\PaymentJournalProof::canonicalLines($ledger['lines'])) !== PropertyInput::hash(\MyInvoice\Service\Property\Payments\PaymentJournalProof::canonicalLines($proof['lines']))) PaymentException::reject('source_revision_intent');
        $q = $this->db->pdo()->prepare('SELECT request_json,revision_no FROM unit_payment_source_revisions WHERE supplier_id=? AND id=? FOR UPDATE'); $q->execute([$supplier, $id]);
        $row = $q->fetch(PDO::FETCH_ASSOC); $request = json_decode($row['request_json'], true, flags: JSON_THROW_ON_ERROR); $number = (int) $row['revision_no'];
        $q = $this->db->pdo()->prepare("UPDATE unit_payment_source_revisions SET status='posted',journal_entry_id=? WHERE supplier_id=? AND id=? AND status='pending' AND journal_entry_id IS NULL");
        $q->execute([$entry, $supplier, $id]); if ($q->rowCount() !== 1) PaymentException::reject('source_version_conflict');
        if ($request['expected_revision_id'] === null) {
            $this->db->pdo()->prepare('INSERT INTO unit_payment_source_heads (supplier_id,source_id,revision_id,row_version) VALUES (?,?,?,1)')->execute([$supplier, $request['source_id'], $id]);
        } else {
            $q = $this->db->pdo()->prepare('UPDATE unit_payment_source_heads SET revision_id=?,row_version=row_version+1 WHERE supplier_id=? AND source_id=? AND revision_id=? AND row_version=?');
            $q->execute([$id, $supplier, $request['source_id'], $request['expected_revision_id'], $number - 1]);
            if ($q->rowCount() !== 1) PaymentException::reject('source_version_conflict');
        }
        $q = $this->db->pdo()->prepare('UPDATE unit_payment_sources SET row_version=row_version+1 WHERE supplier_id=? AND id=? AND row_version=?');
        $q->execute([$supplier, $request['source_id'], $request['expected_source_version']]);
        if ($q->rowCount() !== 1) PaymentException::reject('source_version_conflict');
        $verified = $this->source($supplier, $request['source_id']);
        if (($verified['revision_id'] ?? null) !== $id) ClearingRevisionHistory::corrupt();
    }

    public function provePending(Connection $db, int $supplier, int $id): array
    {
        if ($db->pdo() !== $this->db->pdo() || !$db->pdo()->inTransaction()) throw new \LogicException('Source revision requires its owner transaction.');
        (new PaymentLocks($db))->acquire($supplier);
        $q = $db->pdo()->prepare('SELECT * FROM unit_payment_source_revisions WHERE supplier_id=? AND id=? FOR UPDATE'); $q->execute([$supplier, $id]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['status'] !== 'pending' || $row['journal_entry_id'] !== null) PaymentException::reject('source_revision_intent');
        $request = json_decode($row['request_json'], true, flags: JSON_THROW_ON_ERROR);
        $snapshot = json_decode($row['snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        if (!hash_equals($row['request_hash'], ClearingRevisionHistory::requestHash($request, $row['idempotency_key'], (int) $row['created_by']))
            || !hash_equals($row['snapshot_hash'], PropertyInput::hash($snapshot))) ClearingRevisionHistory::corrupt();
        $plan = $this->plan($supplier, self::normalize($request));
        if (PropertyInput::hash($plan['snapshot']) !== $row['snapshot_hash'] || (int) $row['source_id'] !== $request['source_id']
            || (int) $row['revision_no'] !== $snapshot['revision_number'] || (int) $row['expected_source_version'] !== $request['expected_source_version']
            || ($row['previous_revision_id'] === null ? null : (int) $row['previous_revision_id']) !== $request['expected_revision_id']
            || $row['accounting_date'] !== $request['accounting_date'] || $row['effective_at'] !== PropertyInstant::sql($request['effective_at'])) PaymentException::reject('source_revision_intent');
        $journal = $plan['snapshot']['journal'];
        $journal['header'] += ['source_id' => $id, 'description' => $request['reason'], 'posted_at' => date('Y-m-d H:i:s'), 'posted_by' => (int) $row['created_by']];
        return $journal;
    }

    private function plan(int $supplier, array $request): array
    {
        (new PaymentLocks($this->db))->acquire($supplier);
        $source = $this->source($supplier, $request['source_id']);
        if ($source['version'] !== $request['expected_source_version'] || ($source['revision_id'] ?? null) !== $request['expected_revision_id']) PaymentException::reject('source_version_conflict');
        if ($request['accounting_date'] < ($source['revision_date'] ?? $source['receipt']['date'])) PaymentException::reject('source_revision_date');
        $cells = $source['clearing_cells'] ?? $source['receipt']['clearing_cells'] ?? [['key' => 'original', 'amount_cents' => $source['receipt']['amount_cents'], 'line' => $source['receipt']['clearing_line']]];
        $consumed = $source['cell_consumed'] ?? ['original' => $source['consumed_cents']];
        $pinned = [];
        $q = $this->db->pdo()->prepare("SELECT id,accounting_date,snapshot_json,snapshot_hash FROM unit_payment_operations WHERE supplier_id=? AND source_id=? AND kind IN ('allocation','refund') AND status='posted' ORDER BY id FOR UPDATE");
        $q->execute([$supplier, $request['source_id']]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $operation) {
            if (!hash_equals($operation['snapshot_hash'], PropertyInput::hash(json_decode($operation['snapshot_json'], true, flags: JSON_THROW_ON_ERROR)))) ClearingRevisionHistory::corrupt();
            if ($request['accounting_date'] < $operation['accounting_date']) PaymentException::reject('source_revision_date');
            $pinned[] = ['id' => (int) $operation['id'], 'snapshot_hash' => $operation['snapshot_hash']];
        }
        $plan = ClearingRevisionCells::plan($cells, $consumed, $request['moves']);
        $journal = (new ClearingRevisionJournalPlan($this->db))->current($supplier, $request['accounting_date'], $plan);
        $snapshot = ['source_id' => $request['source_id'], 'source_receipt_hash' => PropertyInput::hash($source['receipt']),
            'revision_number' => ($source['revision_number'] ?? 0) + 1, 'cell_consumed' => $consumed, 'pinned_operations' => $pinned, 'plan' => $plan, 'journal' => $journal];
        return ['status' => 'ready', 'snapshot' => $snapshot, 'preview_hash' => PropertyInput::hash(['request' => $request, 'snapshot' => $snapshot])];
    }

    private function source(int $supplier, int $id): array
    {
        $q = $this->db->pdo()->prepare('SELECT bank_transaction_id,cash_document_id FROM unit_payment_sources WHERE supplier_id=? AND id=? FOR UPDATE'); $q->execute([$supplier, $id]);
        $row = $q->fetch(PDO::FETCH_ASSOC); if (!$row) PaymentException::reject('source_not_found');
        return (new PaymentSourceProof($this->db))->current($supplier, $row['bank_transaction_id'] === null ? 'cash' : 'bank', (int) ($row['bank_transaction_id'] ?? $row['cash_document_id']));
    }

    private static function normalize(array $request): array
    {
        PropertyInput::fields($request, ['source_id', 'expected_source_version', 'expected_revision_id', 'accounting_date', 'effective_at', 'reason', 'evidence', 'moves']);
        $date = IssuanceRequest::date($request['accounting_date'] ?? null); $at = PropertyInstant::normalize($request['effective_at'] ?? null);
        if ((new \DateTimeImmutable($at))->setTimezone(new \DateTimeZone('Europe/Prague'))->format('Y-m-d') !== $date) PaymentException::reject('source_revision_date');
        if (!is_array($request['moves'] ?? null)) PropertyInput::invalid('moves');
        return ['source_id' => PropertyInput::id($request['source_id'] ?? null, 'source_id'), 'expected_source_version' => PropertyInput::id($request['expected_source_version'] ?? null, 'expected_source_version'),
            'expected_revision_id' => ($request['expected_revision_id'] ?? null) === null ? null : PropertyInput::id($request['expected_revision_id'], 'expected_revision_id'),
            'accounting_date' => $date, 'effective_at' => $at, 'reason' => PropertyInput::text($request['reason'] ?? null, 'reason', 2000),
            'evidence' => PropertyInput::text($request['evidence'] ?? null, 'evidence', 2000), 'moves' => $request['moves']];
    }

    private static function result(array $row): array
    {
        return ['id' => (int) $row['id'], 'source_id' => (int) $row['source_id'], 'revision_number' => (int) $row['revision_no'], 'journal_entry_id' => (int) $row['journal_entry_id'],
            'status' => $row['status'], 'snapshot_hash' => $row['snapshot_hash']];
    }
}
