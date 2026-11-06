<?php

declare(strict_types=1);
namespace MyInvoice\Repository\Property\Payments;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Property\PropertyInput;
use MyInvoice\Service\Property\Payments\PaymentException;
use PDO;

final class PaymentWorkflowRepository
{
    public function __construct(private readonly Connection $db) {}

    public function find(int $supplier, int $id): array
    {
        if (!$this->db->pdo()->inTransaction()) throw new \LogicException('Workflow requires its owner transaction.');
        $q = $this->db->pdo()->prepare('SELECT * FROM unit_payment_workflows WHERE supplier_id=? AND id=? FOR UPDATE');
        $q->execute([$supplier, $id]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row) PaymentException::reject('workflow_not_found');
        $row['request'] = json_decode($row['request_json'], true, flags: JSON_THROW_ON_ERROR);
        $row['plan'] = json_decode($row['plan_json'], true, flags: JSON_THROW_ON_ERROR);
        if (!hash_equals($row['request_hash'], self::requestHash($row['request'], $row['idempotency_key'], (int) $row['created_by']))
            || !hash_equals($row['preview_hash'], PropertyInput::hash($row['plan']))) PaymentException::reject('workflow_hash_mismatch');
        return $row;
    }

    public function retry(int $supplier, array $request, string $previewHash, string $token, int $actor): ?array
    {
        $q = $this->db->pdo()->prepare('SELECT id FROM unit_payment_workflows WHERE supplier_id=? AND idempotency_key=? FOR UPDATE');
        $q->execute([$supplier, $token]);
        $id = $q->fetchColumn();
        if ($id === false) return null;
        $row = $this->find($supplier, (int) $id);
        if (!hash_equals($row['request_hash'], self::requestHash($request, $token, $actor)) || !hash_equals($row['preview_hash'], $previewHash)) PaymentException::reject('idempotency_conflict');
        if ($row['status'] !== 'confirmed') PaymentException::reject('workflow_pending');
        return $row;
    }

    public function prepare(int $supplier, array $request, array $plan, string $token, int $actor): int
    {
        PropertyInput::code($token, 'idempotency_key');
        PropertyInput::id($actor, 'actor');
        $facts = $plan['snapshot']['source'];
        $q = $this->db->pdo()->prepare('INSERT INTO unit_payment_workflows (supplier_id,source_kind,bank_transaction_id,cash_document_id,request_json,plan_json,request_hash,preview_hash,idempotency_key,created_by) VALUES (?,?,?,?,?,?,?,?,?,?)');
        $q->execute([$supplier, $facts['kind'], $facts['kind'] === 'bank' ? $facts['id'] : null, $facts['kind'] === 'cash' ? $facts['id'] : null,
            self::json($request), self::json($plan['snapshot']), self::requestHash($request, $token, $actor), $plan['preview_hash'], $token, $actor]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    public function bindCash(int $supplier, int $id, int $version, int $cash): void
    {
        $this->cas('UPDATE unit_payment_workflows SET cash_document_id=?,row_version=row_version+1 WHERE supplier_id=? AND id=? AND row_version=? AND cash_document_id IS NULL AND journal_entry_id IS NULL AND status=\'pending\'', [$cash, $supplier, $id, $version]);
    }

    public function bindJournal(int $supplier, int $id, int $version, int $entry): void
    {
        $this->cas("UPDATE unit_payment_workflows SET journal_entry_id=?,row_version=row_version+1 WHERE supplier_id=? AND id=? AND row_version=? AND journal_entry_id IS NULL AND status='pending'", [$entry, $supplier, $id, $version]);
    }

    public static function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    private static function requestHash(array $request, string $token, int $actor): string
    {
        return PropertyInput::hash(['request' => $request, 'token' => $token, 'actor' => $actor]);
    }

    private function cas(string $sql, array $values): void
    {
        $q = $this->db->pdo()->prepare($sql); $q->execute($values);
        if ($q->rowCount() !== 1) PaymentException::reject('workflow_version_conflict');
    }
}
