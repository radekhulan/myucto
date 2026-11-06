<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eet;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Platební události a jejich idempotence.
 *
 * Klíč `Idempotency-Key` je jedinečný ve firmě (UNIQUE supplier_id + klíč) a nese
 * SHA-256 normalizovaného těla včetně názvu operace. Stejný klíč se stejným tělem
 * vrátí původní výsledek, s jiným tělem (nebo jinou operací) skončí
 * {@see IdempotencyConflictException}. Souběh dvou stejných požadavků řeší
 * unikátní index: druhý INSERT počká na commit prvního, dostane duplicitu, volající
 * transakci odvolá a výsledek znovu načte ({@see isDuplicateKey()}).
 */
final class PaymentEventService
{
    private const COLUMNS = [
        'public_id', 'supplier_id', 'idempotency_key', 'request_hash', 'kind', 'origin', 'payment_method',
        'channel', 'contact_context', 'business_purpose', 'amount', 'currency',
        'original_currency', 'original_amount', 'exchange_rate', 'exchange_rate_source', 'ordered_at', 'received_at',
        'recorded_at', 'paid_on', 'payment_order_status', 'bank_verification_status', 'accounting_settlement_status',
        'invoice_id', 'device_id', 'unit_id', 'eet_decision', 'eet_reason_code', 'policy_version',
        'corrects_event_id', 'note', 'created_by',
    ];

    /** Předpona klíčů, které skládá server (pokladní doklad, storno). Klient ji použít nesmí. */
    public const INTERNAL_KEY_PREFIX = 'sys:';

    public function __construct(private readonly Connection $db) {}

    /**
     * Ověří tvar klíče z hlavičky. Prázdný klíč = 400 u operací, které ho vyžadují.
     * Vyhrazená předpona `sys:` patří interním klíčům serveru ({@see internalKey()}).
     *
     * @throws EetCommandException
     */
    public static function validateKey(?string $key): string
    {
        $key = self::validateShape($key);
        if (self::isInternalKey($key)) {
            throw new EetCommandException('idempotency_key_reserved',
                'Idempotency-Key nesmí začínat vyhrazenou předponou „' . self::INTERNAL_KEY_PREFIX . '".', 400);
        }
        return $key;
    }

    /** Interní klíč serveru, např. `sys:cash-document:123`. */
    public static function internalKey(string $scope, int $id): string
    {
        return self::validateShape(self::INTERNAL_KEY_PREFIX . $scope . ':' . $id);
    }

    public static function isInternalKey(string $key): bool
    {
        return str_starts_with(strtolower($key), self::INTERNAL_KEY_PREFIX);
    }

    /** @throws EetCommandException */
    private static function validateShape(?string $key): string
    {
        $key = trim((string) $key);
        if ($key === '') {
            throw new EetCommandException('idempotency_key_required', 'Chybí hlavička Idempotency-Key.', 400);
        }
        if (preg_match('/^[A-Za-z0-9._:\-]{8,80}$/D', $key) !== 1) {
            throw new EetCommandException('idempotency_key_invalid',
                'Idempotency-Key musí mít 8 až 80 znaků (písmena, číslice, . _ : -).', 400);
        }
        return $key;
    }

    /**
     * Potvrzené QR příkazy faktury, které ještě nevypořádalo bankovní párování,
     * s částkou, kterou dosud blokují: zamýšlená alokace snížená o opravy EET jejich
     * tržby. Plně opravená tržba neblokuje nic. Jediné místo pravidla „co z dluhu
     * faktury drží QR příkaz čekající na banku" (příjem platby, PPD, ruční úhrada
     * i bankovní párování).
     *
     * @return list<array{id:int, blocked_cents:int}>
     */
    public function openConfirmedIntents(int $supplierId, int $invoiceId, bool $forUpdate = false): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT e.id,
                    a.amount + COALESCE((SELECT SUM(c.total_amount)
                                           FROM eet_records r
                                           JOIN eet_records c ON c.supplier_id = r.supplier_id AND c.corrects_record_id = r.id
                                                             AND c.kind = 'correction'
                                          WHERE r.supplier_id = e.supplier_id AND r.payment_event_id = e.id AND r.kind = 'sale'), 0) AS blocked
               FROM payment_events e
               JOIN payment_event_allocations a ON a.payment_event_id = e.id AND a.supplier_id = e.supplier_id
                                               AND a.invoice_payment_id IS NULL AND a.bank_transaction_id IS NULL
                                               AND a.cash_document_id IS NULL
              WHERE e.supplier_id = ? AND e.invoice_id = ? AND e.origin = 'payment_intent'
                AND e.payment_order_status = 'operator_confirmed'
                AND e.bank_verification_status = 'pending' AND e.accounting_settlement_status = 'pending'
              ORDER BY e.id" . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $stmt->execute([$supplierId, $invoiceId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $out[] = ['id' => (int) $row['id'], 'blocked_cents' => max(0, EetValues::toCents(EetValues::amount((string) $row['blocked'])))];
        }
        return $out;
    }

    /** Součet částek, které z dluhu faktury blokují potvrzené QR příkazy ({@see openConfirmedIntents()}). */
    public function openConfirmedIntentCents(int $supplierId, int $invoiceId): int
    {
        return array_sum(array_column($this->openConfirmedIntents($supplierId, $invoiceId), 'blocked_cents'));
    }

    /**
     * Úhrada faktury nad dluh, který zbývá po potvrzených QR příkazech, by na jedny peníze
     * založila druhou úhradu (a tržbu). `$paidBeforeCents` = úhrady bez té posuzované
     * (`$newCents`). Volat pod zámkem faktury.
     *
     * @throws EetCommandException 422 `eet_qr_pending`
     */
    public function assertNoQrOverlap(int $supplierId, int $invoiceId, int $dueCents, int $paidBeforeCents, int $newCents, int $toleranceCents = 1): void
    {
        $blocked = $this->openConfirmedIntentCents($supplierId, $invoiceId);
        if ($blocked <= 0 || $paidBeforeCents + $newCents + $blocked <= $dueCents + $toleranceCents) {
            return;
        }
        throw new EetCommandException('eet_qr_pending',
            'Faktura má potvrzený QR příkaz (' . EetValues::fromCents($blocked) . ' Kč), který čeká na připsání bankou. '
            . 'Úhradu přijměte nejvýše do zbývající částky. Pokud zákazník QR platbu neprovedl, vytvořte opravu EET s důvodem „neprovedená platba".',
            422,
            ['pending_qr' => EetValues::fromCents($blocked), 'remaining' => EetValues::fromCents(max(0, $dueCents - $paidBeforeCents - $blocked)),
                'correction' => 'failed_payment'],
        );
    }

    /**
     * Otisk požadavku: operace + rekurzivně seřazené tělo bez prázdných hodnot.
     *
     * @param array<string,mixed> $payload
     */
    public static function requestHash(string $operation, array $payload): string
    {
        $normalized = self::normalize($payload);
        return hash('sha256', $operation . "\n" . json_encode($normalized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Stejný klíč se stejným otiskem → původní událost; jiný otisk → konflikt; žádná → null.
     *
     * @return array<string,mixed>|null
     * @throws IdempotencyConflictException
     */
    public function replay(int $supplierId, string $key, string $hash): ?array
    {
        $event = $this->findByKey($supplierId, $key);
        if ($event === null) {
            return null;
        }
        if (!hash_equals((string) $event['request_hash'], $hash)) {
            throw new IdempotencyConflictException();
        }
        return $event;
    }

    /** @return array<string,mixed>|null */
    public function findByKey(int $supplierId, string $key): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM payment_events WHERE supplier_id = ? AND idempotency_key = ?');
        $stmt->execute([$supplierId, $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @return array<string,mixed>|null */
    public function findByConfirmKey(int $supplierId, string $key): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM payment_events WHERE supplier_id = ? AND confirm_idempotency_key = ?');
        $stmt->execute([$supplierId, $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @return array<string,mixed>|null */
    public function findByPublicId(int $supplierId, string $publicId, bool $forUpdate = false): ?array
    {
        if (!EetValues::isUuid($publicId)) {
            return null;
        }
        $stmt = $this->db->pdo()->prepare('SELECT * FROM payment_events WHERE public_id = ? AND supplier_id = ?'
            . ($forUpdate ? ' FOR UPDATE' : ''));
        $stmt->execute([$publicId, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @return array<string,mixed>|null */
    public function find(int $supplierId, int $id, bool $forUpdate = false): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM payment_events WHERE id = ? AND supplier_id = ?'
            . ($forUpdate ? ' FOR UPDATE' : ''));
        $stmt->execute([$id, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * Založí událost. Volá se v transakci příkazu jako PRVNÍ zápis, aby souběžný
     * požadavek se stejným klíčem čekal na unikátním indexu dřív, než cokoli zapíše.
     *
     * @param array<string,mixed> $row
     */
    public function insert(array $row): int
    {
        $row['public_id'] ??= EetValues::uuid4();
        $unknown = array_diff(array_keys($row), self::COLUMNS);
        if ($unknown !== []) {
            throw new \LogicException('Neznámé sloupce platební události: ' . implode(', ', $unknown));
        }
        $columns = array_keys($row);
        $this->db->pdo()->prepare(
            'INSERT INTO payment_events (' . implode(', ', $columns) . ') VALUES ('
            . implode(', ', array_fill(0, count($columns), '?')) . ')'
        )->execute(array_values($row));
        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @param array{invoice_id?:?int, invoice_payment_id?:?int, cash_document_id?:?int, bank_transaction_id?:?int} $refs */
    public function allocate(int $supplierId, int $eventId, string $amount, array $refs): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payment_event_allocations
               (supplier_id, payment_event_id, invoice_id, invoice_payment_id, cash_document_id, bank_transaction_id, amount)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $supplierId, $eventId,
            $refs['invoice_id'] ?? null, $refs['invoice_payment_id'] ?? null,
            $refs['cash_document_id'] ?? null, $refs['bank_transaction_id'] ?? null,
            $amount,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @return list<array<string,mixed>> */
    public function allocations(int $supplierId, int $eventId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, invoice_id, invoice_payment_id, cash_document_id, bank_transaction_id, amount
               FROM payment_event_allocations WHERE supplier_id = ? AND payment_event_id = ? ORDER BY id'
        );
        $stmt->execute([$supplierId, $eventId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** Duplicitní unikátní klíč (souběžný požadavek se stejným Idempotency-Key). */
    public static function isDuplicateKey(\Throwable $e): bool
    {
        return $e instanceof \PDOException && (int) ($e->errorInfo[1] ?? 0) === 1062;
    }

    private static function normalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return is_string($value) ? trim($value) : $value;
        }
        $out = [];
        foreach ($value as $k => $v) {
            if ($v === null || $v === '') {
                continue;
            }
            $out[$k] = self::normalize($v);
        }
        if (!array_is_list($out)) {
            ksort($out);
        }
        return $out;
    }
}
