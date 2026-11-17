<?php

declare(strict_types=1);

namespace MyInvoice\Service\Renumber;

use PDO;

/**
 * Přečíslování ostatních pohledávek (OP) nebo ostatních závazků (OZ) za měsíc podle
 * data vzniku (`issued_on`). Postup a zámky viz {@see AgendaRenumberService}.
 *
 * Řady OP/OZ jsou roční (společná řada strany i vlastní řady druhů). Číslo zůstává
 * u pohledávky navázané na vydanou fakturu: její číslo nese úhrada faktury
 * a protizápis vlastního zaúčtování faktury. Koncept číslo nemá, smazaný doklad
 * se nebere.
 */
final class OtherItemRenumberService extends AgendaRenumberService
{
    public const SIDES = ['receivable', 'payable'];

    protected function auditAction(): string
    {
        return 'other_item.renumbered';
    }

    protected function table(): string
    {
        return 'other_items';
    }

    protected function numberColumn(): string
    {
        return 'document_no';
    }

    protected function hasRowVersion(): bool
    {
        return true;
    }

    protected function journalSourceType(): ?string
    {
        return 'other_item';
    }

    protected function normalizeScope(int $supplierId, array $scope): array
    {
        $side = (string) ($scope['side'] ?? '');
        if (!in_array($side, self::SIDES, true)) {
            throw new \InvalidArgumentException('Strana musí být receivable (pohledávky) nebo payable (závazky).');
        }
        return ['side' => $side];
    }

    protected function seriesPatterns(int $supplierId, array $scope): array
    {
        return $this->legacyPatterns($supplierId, [$scope['side'] === 'receivable' ? 'other_receivable' : 'other_payable']);
    }

    protected function monthRows(int $supplierId, string $from, string $to, array $scope, bool $forUpdate): array
    {
        $pdo = $this->db->pdo();
        $params = [$supplierId, $from, $to, (string) $scope['side']];
        if ($forUpdate) {
            $pdo->prepare(
                'SELECT id FROM other_items
                  WHERE supplier_id = ? AND deleted_at IS NULL AND issued_on >= ? AND issued_on <= ? AND side = ?
                    AND document_no IS NOT NULL
                  FOR UPDATE'
            )->execute($params);
        }
        $stmt = $pdo->prepare(
            "SELECT oi.id, oi.document_no, oi.side, oi.kind, oi.status, oi.issued_on, oi.accounting_on,
                    oi.title, oi.partner_name, oi.amount, oi.currency, oi.journal_entry_id, oi.reversal_entry_id,
                    EXISTS (SELECT 1 FROM invoices i
                             WHERE i.other_item_id = oi.id AND i.supplier_id = oi.supplier_id) AS linked_invoice
               FROM other_items oi
              WHERE oi.supplier_id = ? AND oi.deleted_at IS NULL AND oi.issued_on >= ? AND oi.issued_on <= ?
                AND oi.side = ? AND oi.document_no IS NOT NULL
              ORDER BY oi.issued_on ASC, oi.id ASC"
        );
        $stmt->execute($params);
        $rows = [];
        while (($r = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $rows[] = $r + [
                'number'      => (string) $r['document_no'],
                'doc_date'    => (string) $r['issued_on'],
                'ref_dates'   => $r['accounting_on'] !== null ? [(string) $r['accounting_on']] : [],
                'entry_ids'   => self::entryIds($r['journal_entry_id'], $r['reversal_entry_id']),
                'keep_reason' => (bool) $r['linked_invoice'] ? 'linked_invoice' : null,
            ];
        }
        return $rows;
    }

    protected function describe(array $row): array
    {
        return [
            'id'          => (int) $row['id'],
            'number'      => (string) $row['number'],
            'date'        => (string) $row['issued_on'],
            'partner'     => (string) ($row['partner_name'] ?? ''),
            'description' => (string) ($row['title'] ?? ''),
            'amount'      => (float) $row['amount'],
            'currency'    => (string) ($row['currency'] ?? 'CZK'),
            'status'      => (string) $row['status'],
        ];
    }
}
