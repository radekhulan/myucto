<?php

declare(strict_types=1);

namespace MyInvoice\Service\Renumber;

use PDO;

/**
 * Přečíslování pokladních dokladů jedné pokladny za měsíc podle data dokladu (datum
 * úhrady, `issue_date`). Postup a zámky viz {@see AgendaRenumberService}.
 *
 * Přečíslují se jen VÝDAJOVÉ doklady bez DPH. Číslo zůstává beze změny u:
 * - příjmového dokladu: příjmový pokladní doklad je stvrzenka předaná plátci, u plátce
 *   DPH zjednodušený daňový doklad a u evidované tržby účtenka EET, takže jeho číslo
 *   už má odběratel a kopie firmy se od něj nesmí lišit;
 * - dokladu evidovaného v EET (i vrácení peněz k dobropisu), číslo nese platební událost
 *   odeslané tržby;
 * - výdajového dokladu s DPH: číslo pokladního dokladu jde do evidence DPH a nad limit
 *   do kontrolního hlášení jako evidenční číslo daňového dokladu.
 *
 * Koncept číslo nemá (dostane ho při zaúčtování), přečíslování se ho netýká.
 */
final class CashDocumentRenumberService extends AgendaRenumberService
{
    protected function auditAction(): string
    {
        return 'cash_document.renumbered';
    }

    protected function table(): string
    {
        return 'cash_documents';
    }

    protected function numberColumn(): string
    {
        return 'doc_number';
    }

    protected function hasRowVersion(): bool
    {
        return false;
    }

    protected function journalSourceType(): ?string
    {
        return 'cash';
    }

    protected function normalizeScope(int $supplierId, array $scope): array
    {
        $registerId = (int) ($scope['register_id'] ?? 0);
        if ($registerId <= 0) {
            throw new \InvalidArgumentException('Vyberte pokladnu.');
        }
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM cash_registers WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$registerId, $supplierId]);
        if ($stmt->fetchColumn() === false) {
            throw new \InvalidArgumentException('Pokladna nenalezena.');
        }
        return ['register_id' => $registerId];
    }

    protected function seriesPatterns(int $supplierId, array $scope): array
    {
        $registerId = (int) $scope['register_id'];
        return $this->legacyPatterns($supplierId, ['cash_in', 'cash_out'], [0, $registerId])
            + $this->nativePatterns($supplierId, ['cash_in', 'cash_out'], $registerId);
    }

    protected function monthRows(int $supplierId, string $from, string $to, array $scope, bool $forUpdate): array
    {
        $pdo = $this->db->pdo();
        $params = [$supplierId, (int) $scope['register_id'], $from, $to];
        if ($forUpdate) {
            $pdo->prepare(
                'SELECT id FROM cash_documents
                  WHERE supplier_id = ? AND register_id = ? AND issue_date >= ? AND issue_date <= ? AND doc_number IS NOT NULL
                  FOR UPDATE'
            )->execute($params);
        }
        $stmt = $pdo->prepare(
            "SELECT cd.id, cd.doc_number, cd.doc_type, cd.issue_date, cd.tax_date, cd.vat_mode, cd.status,
                    cd.partner_name, cd.description, cd.total_amount, cd.currency_code,
                    cd.journal_entry_id, cd.reversal_entry_id,
                    EXISTS (SELECT 1 FROM payment_event_allocations a
                              JOIN eet_records r ON r.payment_event_id = a.payment_event_id AND r.supplier_id = a.supplier_id
                             WHERE a.cash_document_id = cd.id AND a.supplier_id = cd.supplier_id) AS eet
               FROM cash_documents cd
              WHERE cd.supplier_id = ? AND cd.register_id = ? AND cd.issue_date >= ? AND cd.issue_date <= ?
                AND cd.doc_number IS NOT NULL
              ORDER BY cd.issue_date ASC, cd.id ASC"
        );
        $stmt->execute($params);
        $rows = [];
        while (($r = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $rows[] = $r + [
                'number'      => (string) $r['doc_number'],
                'doc_date'    => (string) $r['issue_date'],
                'ref_dates'   => $r['tax_date'] !== null ? [(string) $r['tax_date']] : [],
                'entry_ids'   => self::entryIds($r['journal_entry_id'], $r['reversal_entry_id']),
                'keep_reason' => match (true) {
                    (bool) $r['eet']          => 'eet',
                    $r['doc_type'] === 'in'   => 'cash_in',
                    $r['vat_mode'] === 'vat'  => 'vat_document',
                    default                   => null,
                },
            ];
        }
        return $rows;
    }

    protected function describe(array $row): array
    {
        return [
            'id'          => (int) $row['id'],
            'number'      => (string) $row['number'],
            'date'        => (string) $row['issue_date'],
            'doc_type'    => (string) $row['doc_type'],
            'partner'     => (string) ($row['partner_name'] ?? ''),
            'description' => (string) ($row['description'] ?? ''),
            'amount'      => (float) $row['total_amount'],
            'currency'    => (string) ($row['currency_code'] ?? 'CZK'),
            'status'      => (string) $row['status'],
        ];
    }
}
