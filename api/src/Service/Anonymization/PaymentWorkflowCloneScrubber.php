<?php

declare(strict_types=1);

namespace MyInvoice\Service\Anonymization;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TriggerMetadata;
use MyInvoice\Service\Property\Payments\PaymentException;
use MyInvoice\Service\Property\Payments\PaymentJournalProof;
use MyInvoice\Service\Property\Payments\Workflow\ClearingRevisionHistory;
use MyInvoice\Service\Property\Payments\Workflow\PaymentSourceEligibility;
use MyInvoice\Service\Property\PropertyInput;
use PDO;

final class PaymentWorkflowCloneScrubber
{
    private const TABLES = ['unit_payment_workflows', 'unit_payment_workflow_operations', 'unit_payment_source_revisions', 'unit_payment_source_heads'];
    private bool $finished = false;

    private function __construct(private readonly Connection $db, private readonly TextScrubber $scrubber,
        private readonly \Closure $text, private readonly array $rows, private readonly array $sources,
        private readonly array $operations, private readonly string $savepoint, private readonly ?PaymentExportCloneScrubber $exports) {}

    private function __clone() {}
    public function __serialize(): array { throw new \LogicException('Clone proof cannot be serialized.'); }

    public static function capture(Connection $db, TextScrubber $scrubber, \Closure $text): ?self
    {
        $pdo = $db->pdo();
        if (!$pdo->inTransaction()) throw new \LogicException('Workflow clone requires the payment clone transaction.');
        $q = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (?,?,?,?)');
        $q->execute(self::TABLES); $count = (int) $q->fetchColumn();
        if ($count === 0) return null;
        if ($count !== count(self::TABLES)) throw new \LogicException('Workflow clone requires its complete schema.');
        if (TriggerMetadata::any($pdo)) throw new \LogicException('Workflow clone must precede trigger installation.');
        $exports = PaymentExportCloneScrubber::capture($db, $scrubber, $text);
        $rows = [];
        foreach (self::TABLES as $table) $rows[$table] = $pdo->query('SELECT * FROM ' . $table . ' ORDER BY supplier_id,' . ($table === 'unit_payment_source_heads' ? 'source_id' : 'id') . ' FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC);
        if ($exports === null && $rows['unit_payment_workflows'] === [] && $rows['unit_payment_source_revisions'] === [] && $rows['unit_payment_source_heads'] === [] && $rows['unit_payment_workflow_operations'] === []) return null;
        $sources = $operations = [];
        foreach ($pdo->query('SELECT * FROM unit_payment_sources ORDER BY supplier_id,id FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            self::assertHash($row['receipt_hash'], self::decode($row['receipt_json'])); $sources[$row['supplier_id']][$row['id']] = $row;
        }
        foreach ($pdo->query('SELECT * FROM unit_payment_operations ORDER BY supplier_id,id FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            self::assertHash($row['snapshot_hash'], self::decode($row['snapshot_json'])); $operations[$row['supplier_id']][$row['id']] = $row;
        }
        $owners = []; $links = [];
        foreach ($rows['unit_payment_workflows'] as $row) {
            $request = self::decode($row['request_json']); $plan = self::decode($row['plan_json']);
            self::assertHash($row['request_hash'], ['request' => $request, 'token' => $row['idempotency_key'], 'actor' => (int) $row['created_by']]);
            self::assertHash($row['preview_hash'], $plan);
            if (PropertyInput::hash($request) !== PropertyInput::hash($plan['request']) || $plan['source']['supplier_id'] !== (int) $row['supplier_id']) self::corrupt();
            if (!isset($plan['source']['money_account_receipt']) || !hash_equals($plan['source']['source_etag'], PaymentSourceEligibility::etag($plan['source']))) self::corrupt();
            foreach ($plan['parts'] as $part) self::assertHash($part['plan']['preview_hash'], $part['plan']['snapshot']);
            if ($row['source_id'] !== null && !isset($sources[$row['supplier_id']][$row['source_id']])) self::corrupt();
            $owners[$row['supplier_id']][$row['id']] = $plan;
        }
        foreach ($rows['unit_payment_workflow_operations'] as $row) {
            $owner = $owners[$row['supplier_id']][$row['workflow_id']] ?? null;
            $operation = $operations[$row['supplier_id']][$row['operation_id']] ?? null;
            $part = $owner['parts'][(int) $row['part_no'] - 1] ?? null;
            if (!$owner || !$operation || !$part || $part['key'] !== $row['part_key']) self::corrupt();
            if (isset($links[$row['supplier_id']][$row['workflow_id']][$row['part_no']])) self::corrupt();
            $links[$row['supplier_id']][$row['workflow_id']][$row['part_no']] = true;
        }
        foreach ($rows['unit_payment_workflows'] as $row) if ($row['status'] === 'confirmed'
            && count($links[$row['supplier_id']][$row['id']] ?? []) !== count($owners[$row['supplier_id']][$row['id']]['parts'])) self::corrupt();
        foreach ($rows['unit_payment_source_revisions'] as $row) {
            $request = self::decode($row['request_json']); $snapshot = self::decode($row['snapshot_json']);
            self::assertHash($row['request_hash'], ['request' => $request, 'idempotency_key' => $row['idempotency_key'], 'actor' => (int) $row['created_by']]);
            self::assertHash($row['snapshot_hash'], $snapshot);
            $source = $sources[$row['supplier_id']][$row['source_id']] ?? null;
            if (!$source || $snapshot['source_id'] !== (int) $row['source_id'] || $snapshot['source_receipt_hash'] !== $source['receipt_hash']) self::corrupt();
            foreach ($snapshot['pinned_operations'] as $pinned) {
                $operation = $operations[$row['supplier_id']][$pinned['id']] ?? null;
                if (!$operation || $operation['snapshot_hash'] !== $pinned['snapshot_hash'] || (int) $operation['source_id'] !== (int) $row['source_id']) self::corrupt();
            }
        }
        $ledgerProof = new PaymentJournalProof($db);
        foreach ($sources as $supplier => $records) foreach ($records as $source) {
            $receipt = self::decode($source['receipt_json']);
            if ($receipt['amount_cents'] !== $source['amount_cents'] || $receipt['direction'] !== $source['direction']
                || $receipt['ledger']['entry']['id'] !== (int) $source['journal_entry_id']) self::corrupt();
            $ledgerProof->assertSame((int) $supplier, $text($receipt['ledger']));
        }
        foreach ($rows['unit_payment_source_revisions'] as $row) if ($row['status'] === 'posted') {
            $snapshot = self::decode($row['snapshot_json']);
            $actual = $ledgerProof->current((int) $row['supplier_id'], (int) $row['journal_entry_id']);
            if ($actual['entry']['source_type'] !== 'unit_payment_revision' || $actual['entry']['source_id'] !== (int) $row['id']
                || $actual['entry']['entry_date'] !== $row['accounting_date'] || $actual['entry']['reversed_by'] !== null
                || PropertyInput::hash(PaymentJournalProof::canonicalLines($actual['lines'])) !== PropertyInput::hash(PaymentJournalProof::canonicalLines($text($snapshot['plan']['lines'])))) self::corrupt();
        }
        $savepoint = 'property_clone_' . bin2hex(random_bytes(12)); $pdo->exec('SAVEPOINT ' . $savepoint);
        return new self($db, $scrubber, $text, $rows, $sources, $operations, $savepoint, $exports);
    }

    public function finish(array $hashes, \Closure $references): void
    {
        $pdo = $this->db->pdo();
        if ($this->finished || !$pdo->inTransaction()) throw new \LogicException('Workflow clone proof is no longer current.');
        try { $pdo->exec('RELEASE SAVEPOINT ' . $this->savepoint); }
        catch (\PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1305) throw new \LogicException('Workflow clone proof belongs to a different transaction.', 0, $e);
            throw $e;
        }
        $this->finished = true;
        foreach ($this->sources as $supplier => $rows) foreach ($rows as $id => $row) {
            $expected = ($this->text)(self::decode($row['receipt_json']));
            $this->assertTransformed('unit_payment_sources', $supplier, $id, 'receipt', $row['receipt_hash'], $expected, $hashes);
        }
        foreach ($this->operations as $supplier => $rows) foreach ($rows as $id => $row) {
            $expected = $references(($this->text)(self::decode($row['snapshot_json'])));
            $this->assertTransformed('unit_payment_operations', $supplier, $id, 'snapshot', $row['snapshot_hash'], $expected, $hashes);
        }
        foreach ($this->rows['unit_payment_workflows'] as $row) {
            $request = $this->transform(self::decode($row['request_json']));
            $plan = $references($this->transform(self::decode($row['plan_json'])));
            $plan['source']['source_etag'] = PaymentSourceEligibility::etag($plan['source']);
            foreach ($plan['parts'] as &$part) $part['plan']['preview_hash'] = PropertyInput::hash($part['plan']['snapshot']);
            unset($part);
            $this->update('unit_payment_workflows', $row, ['request_json' => self::json($request), 'plan_json' => self::json($plan),
                'request_hash' => PropertyInput::hash(['request' => $request, 'token' => $row['idempotency_key'], 'actor' => (int) $row['created_by']]), 'preview_hash' => PropertyInput::hash($plan)]);
        }
        foreach ($this->rows['unit_payment_source_revisions'] as $row) {
            $request = $this->transform(self::decode($row['request_json']));
            $snapshot = $references($this->transform(self::decode($row['snapshot_json'])));
            $this->update('unit_payment_source_revisions', $row, ['request_json' => self::json($request), 'snapshot_json' => self::json($snapshot),
                'request_hash' => ClearingRevisionHistory::requestHash($request, $row['idempotency_key'], (int) $row['created_by']), 'snapshot_hash' => PropertyInput::hash($snapshot)]);
        }
        $this->exports?->finish($hashes, $references);
    }

    private function assertTransformed(string $table, int $supplier, int $id, string $field, string $oldHash, array $expected, array $hashes): void
    {
        $q = $this->db->pdo()->prepare('SELECT ' . $field . '_hash,' . $field . '_json FROM ' . $table . ' WHERE supplier_id=? AND id=? FOR UPDATE');
        $q->execute([$supplier, $id]); $row = $q->fetch(PDO::FETCH_ASSOC);
        $hash = PropertyInput::hash($expected);
        if (!$row || ($hashes[$oldHash] ?? null) !== $hash || $row[$field . '_hash'] !== $hash || PropertyInput::hash(self::decode($row[$field . '_json'])) !== $hash) self::corrupt();
    }

    private function transform(array $data): array
    {
        $transformed = ($this->text)($data);
        $walk = function (array $original, array $changed) use (&$walk): array {
            foreach ($original as $key => $value) if (is_array($value)) $changed[$key] = $walk($value, $changed[$key]);
            if (isset($original['source']['kind'], $original['source']['draft'])) $changed['source']['draft'] = $this->native($original['source']['draft'], 'cash_documents');
            if (isset($original['money_account_receipt'], $original['raw'], $original['kind'])) {
                $changed['raw'] = $this->native($original['raw'], $original['kind'] === 'bank' ? 'bank_transactions' : 'cash_documents');
                if ($original['kind'] === 'bank' && is_string($original['raw']['recipient_account'] ?? null)) $changed['raw']['recipient_account'] = $this->scrubber->byStrategy('bank_account', $original['raw']['recipient_account']);
                if (is_array($original['native_draft'])) $changed['native_draft'] = $this->native($original['native_draft'], 'cash_documents');
                if (is_array($original['register'])) $changed['register'] = $this->native($original['register'], 'cash_registers');
            }
            return $changed;
        };
        return $walk($data, $transformed);
    }

    private function native(array $row, string $table): array
    {
        foreach (AnonymizationPolicy::COLUMNS[$table] as $column => $strategy) if ($strategy !== 'keep' && is_string($row[$column] ?? null)) $row[$column] = $this->scrubber->byStrategy($strategy, $row[$column]);
        return $row;
    }

    private function update(string $table, array $row, array $values): void
    {
        $q = $this->db->pdo()->prepare('UPDATE ' . $table . ' SET ' . implode(',', array_map(fn ($key) => $key . '=?', array_keys($values))) . ' WHERE supplier_id=? AND id=?');
        $q->execute([...array_values($values), $row['supplier_id'], $row['id']]);
    }

    private static function decode(string $json): array { return json_decode($json, true, flags: JSON_THROW_ON_ERROR); }
    private static function json(array $data): string { return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION); }
    private static function assertHash(string $hash, array $data): void { if (!hash_equals($hash, PropertyInput::hash($data))) self::corrupt(); }
    private static function corrupt(): never { PaymentException::reject('clone_workflow_proof'); }
}
