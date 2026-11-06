<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;
use PDOException;

final class BankPaymentOrderSubmissionRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * Stav unknown se zapisuje ještě před prvním HTTP voláním. Kolize unikátního
     * klíče vrací existující pokus a volající už banku nikdy znovu neosloví.
     *
     * @return array{created:bool,submission:array<string,mixed>}
     */
    public function begin(
        int $supplierId,
        int $orderId,
        int $connectionId,
        string $provider,
        string $payloadHash,
        ?int $userId,
        string $source = 'purchase_invoice',
    ): array
    {
        $column = $this->sourceColumn($source);
        if ($this->db->pdo()->inTransaction()) {
            throw new \LogicException('Bankovní odeslání nelze zahájit uvnitř databázové transakce.');
        }
        try {
            $stmt = $this->db->pdo()->prepare(
                "INSERT INTO bank_payment_order_submissions
                    (supplier_id, {$column}, connection_id, provider, payload_hash,
                     submitted_by_user_id, status, error_code)
                 VALUES (?, ?, ?, ?, ?, ?, 'unknown', 'submission_started')"
            );
            $stmt->execute([$supplierId, $orderId, $connectionId, $provider, $payloadHash, $userId]);
            $submission = $this->find($supplierId, $orderId, $source);
            if ($submission === null) {
                throw new \RuntimeException('Stav odeslání se nepodařilo načíst.');
            }
            return ['created' => true, 'submission' => $submission];
        } catch (PDOException $e) {
            if ((string) $e->getCode() !== '23000') {
                throw $e;
            }
            $existing = $this->find($supplierId, $orderId, $source);
            if ($existing === null) {
                throw $e;
            }
            return ['created' => false, 'submission' => $existing];
        }
    }

    public function accept(
        int $supplierId,
        int $submissionId,
        string $reference,
        string $status = 'accepted_awaiting_authorization',
        ?string $signHash = null,
    ): void {
        if (!in_array($status, ['accepted_awaiting_authorization', 'import_started', 'awaiting_signature'], true)
            || ($status === 'awaiting_signature') !== ($signHash !== null)) {
            throw new \InvalidArgumentException('Neplatný stav přijetí bankovního příkazu.');
        }
        $this->db->pdo()->prepare(
            "UPDATE bank_payment_order_submissions
                SET status = ?, provider_reference = ?, provider_sign_hash = ?,
                    error_code = NULL, submitted_at = CURRENT_TIMESTAMP
              WHERE supplier_id = ? AND id = ? AND status IN ('unknown', 'creating')"
        )->execute([$status, $reference, $signHash, $supplierId, $submissionId]);
    }

    /**
     * Odeslání po položkách: plán položek se uloží dřív, než se první z nich pošle, a
     * odeslání přejde do stavu creating. Pokračování po přerušení plán jen ověří.
     *
     * @param list<array{line_no:int,instruction_identification:string,amount_minor:int,creditor_iban:string}> $items
     * @return array<int,array{status:string,provider_sign_id:?string,provider_hash:?string}> evidence podle čísla řádku
     */
    public function planItems(int $supplierId, int $submissionId, array $items): array
    {
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) {
            throw new \LogicException('Bankovní odeslání nelze plánovat uvnitř databázové transakce.');
        }
        $existing = $this->items($supplierId, $submissionId);
        if ($existing !== []) {
            if (count($existing) !== count($items)) {
                throw new \DomainException('payment_plan_changed');
            }
            foreach ($items as $item) {
                $row = $existing[$item['line_no']] ?? null;
                if ($row === null || $row['instruction_identification'] !== $item['instruction_identification']
                    || $row['amount_minor'] !== $item['amount_minor'] || $row['creditor_iban'] !== $item['creditor_iban']) {
                    throw new \DomainException('payment_plan_changed');
                }
            }
            return $existing;
        }
        $pdo->beginTransaction();
        try {
            $moved = $pdo->prepare(
                "UPDATE bank_payment_order_submissions SET status = 'creating', error_code = NULL
                  WHERE supplier_id = ? AND id = ? AND status = 'unknown' AND error_code = 'submission_started'"
            );
            $moved->execute([$supplierId, $submissionId]);
            if ($moved->rowCount() !== 1) {
                throw new \DomainException('payment_plan_unavailable');
            }
            $values = [];
            $params = [];
            foreach ($items as $item) {
                $values[] = '(?, ?, ?, ?, ?, ?, ?)';
                $variable = (string) ($item['variable_symbol'] ?? '');
                array_push($params, $submissionId, $supplierId, $item['line_no'], $item['instruction_identification'],
                    $item['amount_minor'], $item['creditor_iban'], $variable !== '' ? substr($variable, 0, 10) : null);
            }
            $pdo->prepare(
                'INSERT INTO bank_payment_submission_items
                    (submission_id, supplier_id, line_no, instruction_identification, amount_minor, creditor_iban, variable_symbol)
                 VALUES ' . implode(', ', $values)
            )->execute($params);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return $this->items($supplierId, $submissionId);
    }

    /**
     * Přechod položky. Povolené jsou jen planned → sending a sending → created/rejected/unknown;
     * jiný výchozí stav znamená souběh nebo poškozenou evidenci a volající nesmí pokračovat.
     */
    public function moveItem(int $supplierId, int $submissionId, int $lineNo, string $to, ?string $signId = null,
        ?string $hash = null, ?string $instructionStatus = null, ?string $errorCode = null): bool
    {
        $from = match ($to) {
            'sending' => 'planned',
            'created', 'rejected', 'unknown' => 'sending',
            default => throw new \InvalidArgumentException('Neplatný stav položky bankovního odeslání.'),
        };
        $stmt = $this->db->pdo()->prepare(
            "UPDATE bank_payment_submission_items
                SET status = ?, provider_sign_id = COALESCE(?, provider_sign_id), provider_hash = COALESCE(?, provider_hash),
                    instruction_status = COALESCE(?, instruction_status), error_code = ?
              WHERE supplier_id = ? AND submission_id = ? AND line_no = ? AND status = ?"
        );
        $stmt->execute([$to, $signId, $hash, $instructionStatus, $errorCode !== null ? mb_substr($errorCode, 0, 80) : null,
            $supplierId, $submissionId, $lineNo, $from]);
        return $stmt->rowCount() === 1;
    }

    /** Dávka se právě zakládá; po přerušení v tomto bodě je výsledek nejasný. */
    public function markBatchSending(int $supplierId, int $submissionId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "UPDATE bank_payment_order_submissions SET error_code = 'batch_sending'
              WHERE supplier_id = ? AND id = ? AND status = 'creating' AND error_code IS NULL"
        );
        $stmt->execute([$supplierId, $submissionId]);
        return $stmt->rowCount() === 1;
    }

    /** @return array{status:string,error_code:?string,payload_hash:string,connection_id:?int}|null */
    public function internalState(int $supplierId, int $submissionId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT status, error_code, payload_hash, connection_id FROM bank_payment_order_submissions WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $submissionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : [
            'status' => (string) $row['status'],
            'error_code' => $row['error_code'] !== null ? (string) $row['error_code'] : null,
            'payload_hash' => (string) $row['payload_hash'],
            'connection_id' => $row['connection_id'] !== null ? (int) $row['connection_id'] : null,
        ];
    }

    /**
     * @return array<int,array{line_no:int,instruction_identification:string,amount_minor:int,creditor_iban:string,
     *     status:string,provider_sign_id:?string,provider_hash:?string,instruction_status:?string}>
     */
    public function items(int $supplierId, int $submissionId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT line_no, instruction_identification, amount_minor, creditor_iban, status, provider_sign_id, provider_hash,
                    instruction_status
               FROM bank_payment_submission_items
              WHERE supplier_id = ? AND submission_id = ?
              ORDER BY line_no'
        );
        $stmt->execute([$supplierId, $submissionId]);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(int) $row['line_no']] = [
                'line_no' => (int) $row['line_no'],
                'instruction_identification' => (string) $row['instruction_identification'],
                'amount_minor' => (int) $row['amount_minor'],
                'creditor_iban' => (string) $row['creditor_iban'],
                'status' => (string) $row['status'],
                'provider_sign_id' => $row['provider_sign_id'] !== null ? (string) $row['provider_sign_id'] : null,
                'provider_hash' => $row['provider_hash'] !== null ? (string) $row['provider_hash'] : null,
                'instruction_status' => $row['instruction_status'] !== null ? (string) $row['instruction_status'] : null,
            ];
        }
        return $result;
    }

    /**
     * Položky odeslání pro kontrolu uživatelem (nejasný nebo přerušený stav): co banka
     * podle evidence založila nebo mohla založit. Bez hashe podpisu.
     *
     * @return list<array{line_no:int,status:string,amount:string,creditor_iban:string,creditor_account:?string,
     *     variable_symbol:?string,instruction_identification:string,provider_sign_id:?string,instruction_status:?string}>
     */
    public function publicItems(int $supplierId, int $submissionId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT line_no, status, amount_minor, creditor_iban, variable_symbol, instruction_identification, provider_sign_id,
                    instruction_status
               FROM bank_payment_submission_items
              WHERE supplier_id = ? AND submission_id = ?
              ORDER BY line_no'
        );
        $stmt->execute([$supplierId, $submissionId]);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $iban = (string) $row['creditor_iban'];
            $result[] = [
                'line_no' => (int) $row['line_no'],
                'status' => (string) $row['status'],
                'amount' => number_format(((int) $row['amount_minor']) / 100, 2, '.', ''),
                'creditor_iban' => $iban,
                'creditor_account' => self::czechAccount($iban),
                'variable_symbol' => $row['variable_symbol'] !== null ? (string) $row['variable_symbol'] : null,
                'instruction_identification' => (string) $row['instruction_identification'],
                'provider_sign_id' => $row['provider_sign_id'] !== null ? (string) $row['provider_sign_id'] : null,
                'instruction_status' => $row['instruction_status'] !== null ? (string) $row['instruction_status'] : null,
            ];
        }
        return $result;
    }

    /** České číslo účtu (předčíslí-číslo/kód banky) z tuzemského IBAN; jiný IBAN nevrací nic. */
    private static function czechAccount(string $iban): ?string
    {
        if (!preg_match('/^CZ\d{2}(\d{4})(\d{6})(\d{10})$/D', $iban, $m)) return null;
        $prefix = ltrim($m[2], '0');
        $number = ltrim($m[3], '0');
        return ($prefix !== '' ? $prefix . '-' : '') . ($number !== '' ? $number : '0') . '/' . $m[1];
    }

    /** Výsledek dotazu na stav platby u banky (jen u založené položky). */
    public function recordItemStatus(int $supplierId, int $submissionId, int $lineNo, string $instructionStatus, ?string $changeInfo): void
    {
        $this->db->pdo()->prepare(
            "UPDATE bank_payment_submission_items SET instruction_status = ?, status_change_info = ?
              WHERE supplier_id = ? AND submission_id = ? AND line_no = ? AND status = 'created'"
        )->execute([$instructionStatus, $changeInfo, $supplierId, $submissionId, $lineNo]);
    }

    /**
     * Podpisový stav dávky pro službu podpisu (bez veřejné projekce).
     *
     * @return array<string,mixed>|null
     */
    public function signingState(int $supplierId, int $submissionId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            // withdrawn: příkaz byl archivován po potvrzení zrušení v bance, nebo mzdová dávka
            // zahozena. Takové odeslání se už nesmí podepsat ani dokončit (vedle náhradního
            // příkazu by šlo o druhou platbu).
            'SELECT s.id, s.supplier_id, s.payment_order_id, s.payroll_batch_id, s.connection_id, s.provider, s.status,
                    s.provider_reference, s.provider_sign_hash, s.provider_poll_id, s.signature_state,
                    TIMESTAMPDIFF(SECOND, s.created_at, CURRENT_TIMESTAMP) AS age_seconds,
                    TIMESTAMPDIFF(SECOND, s.last_polled_at, CURRENT_TIMESTAMP) AS polled_seconds_ago,
                    TIMESTAMPDIFF(SECOND, s.signature_requested_at, CURRENT_TIMESTAMP) AS signature_requested_seconds_ago,
                    CASE WHEN s.payment_order_id IS NOT NULL THEN po.archived_at IS NOT NULL
                         ELSE EXISTS (SELECT 1 FROM payroll_payment_batch_discards d
                                       WHERE d.supplier_id = s.supplier_id AND d.batch_id = s.payroll_batch_id) END AS withdrawn,
                    DATEDIFF(COALESCE(po.payment_date, pb.planned_payment_date), CURRENT_DATE) AS days_to_execution
               FROM bank_payment_order_submissions s
               LEFT JOIN payment_orders po ON po.supplier_id = s.supplier_id AND po.id = s.payment_order_id
               LEFT JOIN payroll_payment_batches pb ON pb.supplier_id = s.supplier_id AND pb.id = s.payroll_batch_id
              WHERE s.supplier_id = ? AND s.id = ?'
        );
        $stmt->execute([$supplierId, $submissionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        foreach (['id', 'supplier_id', 'connection_id', 'age_seconds', 'polled_seconds_ago', 'signature_requested_seconds_ago',
            'payment_order_id', 'payroll_batch_id', 'days_to_execution'] as $key) {
            $row[$key] = $row[$key] !== null ? (int) $row[$key] : null;
        }
        $row['withdrawn'] = (bool) $row['withdrawn'];
        return $row;
    }

    /**
     * Zapíše výsledek kroku podpisu nebo dotazu na stav. Přechod se provede jen z očekávaného
     * stavu, takže souběžný dotaz nepřepíše novější výsledek.
     *
     * @param list<string> $fromStatuses
     * @param array{status?:string,error_code?:?string,provider_poll_id?:?string,signature_state?:?string,polled?:bool,
     *     signature_requested?:bool,next_poll_seconds?:?int,accepted_count?:?int,rejected_count?:?int} $changes
     */
    public function updateSigning(int $supplierId, int $submissionId, array $fromStatuses, array $changes): bool
    {
        $allowed = ['awaiting_signature', 'signed', 'completed', 'partially_rejected', 'expired', 'rejected'];
        if ($fromStatuses === [] || array_diff($fromStatuses, $allowed) !== []
            || (isset($changes['status']) && !in_array($changes['status'], $allowed, true))) {
            throw new \InvalidArgumentException('Neplatný přechod podpisu bankovního příkazu.');
        }
        $set = ($changes['polled'] ?? false) === true ? ['last_polled_at = CURRENT_TIMESTAMP'] : [];
        $params = [];
        foreach (['status', 'error_code', 'provider_poll_id', 'signature_state', 'accepted_count', 'rejected_count'] as $column) {
            if (array_key_exists($column, $changes)) {
                $set[] = $column . ' = ?';
                $params[] = $changes[$column];
            }
        }
        if (($changes['signature_requested'] ?? false) === true) {
            $set[] = 'signature_requested_at = CURRENT_TIMESTAMP';
        }
        if (array_key_exists('next_poll_seconds', $changes)) {
            if ($changes['next_poll_seconds'] === null) {
                $set[] = 'next_poll_at = NULL';
            } else {
                $set[] = 'next_poll_at = TIMESTAMPADD(SECOND, ?, CURRENT_TIMESTAMP)';
                $params[] = max(1, (int) $changes['next_poll_seconds']);
            }
        }
        if ($set === []) {
            return false;
        }
        $placeholders = implode(', ', array_fill(0, count($fromStatuses), '?'));
        $stmt = $this->db->pdo()->prepare(
            'UPDATE bank_payment_order_submissions SET ' . implode(', ', $set)
            . " WHERE supplier_id = ? AND id = ? AND status IN ({$placeholders})"
        );
        $stmt->execute([...$params, $supplierId, $submissionId, ...$fromStatuses]);
        return $stmt->rowCount() === 1;
    }

    /**
     * Odeslání čekající na výsledek podpisu nebo provedení, kterým nastal čas dotazu.
     * Přes všechny firmy (cron); stav banka drží jen 34 dní.
     *
     * @return list<array{id:int,supplier_id:int}>
     */
    public function dueForPolling(string $provider, int $limit): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, supplier_id FROM bank_payment_order_submissions
              WHERE provider = ? AND status IN ('awaiting_signature', 'signed')
                AND next_poll_at IS NOT NULL AND next_poll_at <= CURRENT_TIMESTAMP
              ORDER BY next_poll_at
              LIMIT " . max(1, min(200, $limit))
        );
        $stmt->execute([$provider]);
        return array_map(static fn (array $row): array => ['id' => (int) $row['id'], 'supplier_id' => (int) $row['supplier_id']],
            $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function fail(
        int $supplierId,
        int $submissionId,
        string $status,
        string $errorCode,
        ?int $acceptedCount = null,
        ?int $rejectedCount = null,
        ?int $remoteHttpStatus = null,
    ): void {
        if (!in_array($status, ['rejected', 'unknown'], true)) {
            throw new \InvalidArgumentException('Neplatný stav bankovního odeslání.');
        }
        $stmt = $this->db->pdo()->prepare(
            'UPDATE bank_payment_order_submissions
                SET status = ?, error_code = ?, accepted_count = ?, rejected_count = ?,
                    remote_http_status = ?, submitted_at = CURRENT_TIMESTAMP
              WHERE supplier_id = ? AND id = ? AND status IN (\'unknown\', \'creating\')'
        );
        $stmt->execute([
            $status,
            mb_substr($errorCode, 0, 80),
            $acceptedCount,
            $rejectedCount,
            $remoteHttpStatus,
            $supplierId,
            $submissionId,
        ]);
    }

    public function find(int $supplierId, int $orderId, string $source = 'purchase_invoice'): ?array
    {
        $column = $this->sourceColumn($source);
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, payment_order_id, payroll_batch_id, connection_id, provider, status,
                    provider_reference, error_code, accepted_count, rejected_count,
                    created_at, submitted_at
               FROM bank_payment_order_submissions
              WHERE supplier_id = ? AND {$column} = ?"
        );
        $stmt->execute([$supplierId, $orderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $this->publicProjection($row) : null;
    }

    private function sourceColumn(string $source): string
    {
        return match ($source) {
            'purchase_invoice' => 'payment_order_id',
            'payroll' => 'payroll_batch_id',
            default => throw new \InvalidArgumentException('Neplatný zdroj bankovního příkazu.'),
        };
    }

    private function publicProjection(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            ...($row['payment_order_id'] !== null
                ? ['payment_order_id' => (int) $row['payment_order_id']]
                : ['payroll_batch_id' => (int) $row['payroll_batch_id']]),
            'connection_id' => $row['connection_id'] !== null ? (int) $row['connection_id'] : null,
            'provider' => (string) $row['provider'],
            'status' => (string) $row['status'],
            'provider_reference' => $row['provider_reference'] ?: null,
            'error_code' => $row['error_code'] ?: null,
            'accepted_count' => $row['accepted_count'] !== null ? (int) $row['accepted_count'] : null,
            'rejected_count' => $row['rejected_count'] !== null ? (int) $row['rejected_count'] : null,
            'created_at' => $row['created_at'],
            'submitted_at' => $row['submitted_at'] ?: null,
        ];
    }
}
