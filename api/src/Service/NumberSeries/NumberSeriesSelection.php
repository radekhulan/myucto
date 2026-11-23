<?php

declare(strict_types=1);

namespace MyInvoice\Service\NumberSeries;

use MyInvoice\Infrastructure\Database\Connection;

/**
 * Volba řady na konceptu dokladu (`number_series_id`) — jediné místo, kde se volba
 * z editoru nebo API ověří a zapíše, pro všechny druhy s výběrem řady.
 *
 * Klíč v těle požadavku chybí → volba se nemění (a u nového dokladu se doplní výchozí
 * řada uživatele, má-li ji). `null`/0 → bez volby (výchozí řada druhu při vystavení).
 * Jinak musí řada patřit firmě a druhu dokladu, jinak validační chyba.
 */
final class NumberSeriesSelection
{
    private const TABLES = ['invoices', 'purchase_invoices', 'cash_documents', 'internal_documents'];

    public function __construct(
        private readonly Connection $db,
        private readonly NumberSeriesAllocator $allocator,
    ) {}

    /**
     * Ověří volbu řady z těla požadavku. Vrací null = bez volby, false = klíč chybí (neměnit).
     *
     * @param array<string,mixed> $body
     * @throws NumberSeriesException
     */
    public function fromBody(int $supplierId, array $body, string $kind, int $registerId = 0): int|false|null
    {
        if (!array_key_exists('number_series_id', $body)) {
            return false;
        }
        $value = $body['number_series_id'];
        if ($value === null || $value === '' || (is_numeric($value) && (int) $value === 0)) {
            return null;
        }
        if (!is_numeric($value) || (int) $value < 0) {
            throw new NumberSeriesException('series_invalid', 'Neplatná číselná řada.');
        }
        $row = $this->allocator->resolve($supplierId, $kind, (int) $value, $registerId);
        return $row === null ? null : (int) $row['id'];
    }

    /**
     * Zapíše volbu na doklad. U nového dokladu bez volby doplní výchozí řadu uživatele.
     *
     * @param array<string,mixed> $body
     */
    public function apply(string $table, int $supplierId, int $docId, array $body, string $kind, int $registerId = 0, ?int $userId = null, bool $isNew = false): void
    {
        if (!in_array($table, self::TABLES, true) || !$this->db->hasColumn($table, 'number_series_id')) {
            return;
        }
        $selected = $this->fromBody($supplierId, $body, $kind, $registerId);
        if ($selected === false) {
            if (!$isNew || $userId === null) {
                return;
            }
            $selected = $this->userDefaultFor($supplierId, $kind, $registerId, $userId);
            if ($selected === null) {
                return;
            }
        }
        $this->db->pdo()->prepare("UPDATE {$table} SET number_series_id = ? WHERE id = ? AND supplier_id = ?")
            ->execute([$selected, $docId, $supplierId]);
    }

    /** Výchozí řada uživatele pro druh, jen je-li to opravdu volba (jinak null). */
    public function userDefaultFor(int $supplierId, string $kind, int $registerId, ?int $userId): ?int
    {
        if ($userId === null || $userId <= 0 || !$this->db->hasTable('number_series_user_defaults')) {
            return null;
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT series_id FROM number_series_user_defaults
              WHERE supplier_id = ? AND user_id = ? AND doc_kind = ? AND scope_id IN (?, 0)
              ORDER BY scope_id DESC LIMIT 1'
        );
        $stmt->execute([$supplierId, $userId, $kind, $registerId]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            return null;
        }
        try {
            $row = $this->allocator->resolve($supplierId, $kind, (int) $id, $registerId);
        } catch (NumberSeriesException) {
            return null;
        }
        return $row === null ? null : (int) $row['id'];
    }
}
