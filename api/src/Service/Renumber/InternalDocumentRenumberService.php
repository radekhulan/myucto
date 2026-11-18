<?php

declare(strict_types=1);

namespace MyInvoice\Service\Renumber;

use PDO;

/**
 * Přečíslování interních dokladů za měsíc podle data účetního případu (`entry_date`),
 * podle kterého doklad dostává číslo z řady i zápis v deníku. Postup a zámky viz
 * {@see AgendaRenumberService}.
 *
 * Interní doklad nikdo mimo firmu nedostává, takže se přečíslují všechny automaticky
 * očíslované (řada ID i nové řady číselníku). Bere se doklad se zápisem v deníku:
 * koncept číslo nemá a koncept, který si číslo drží po smazaném zápisu, se nepřesouvá.
 * Ruční zápisy deníku bez interního dokladu čerpají z téže řady ID, přečíslování se
 * jich netýká a jejich čísla zůstávají.
 */
final class InternalDocumentRenumberService extends AgendaRenumberService
{
    protected function auditAction(): string
    {
        return 'internal_document.renumbered';
    }

    protected function table(): string
    {
        return 'internal_documents';
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
        // Zápis interního dokladu je ruční zápis bez source_id, váže ho journal_entry_id.
        return null;
    }

    protected function normalizeScope(int $supplierId, array $scope): array
    {
        return [];
    }

    protected function seriesPatterns(int $supplierId, array $scope): array
    {
        return $this->legacyPatterns($supplierId, ['manual'], [0])
            + $this->nativePatterns($supplierId, ['manual']);
    }

    protected function monthRows(int $supplierId, string $from, string $to, array $scope, bool $forUpdate): array
    {
        // Měsíc se hledá přes zápisy deníku (index firma + datum zápisu), doklad přes
        // unikátní vazbu na zápis; interní doklady samy index podle data případu nemají.
        $stmt = $this->db->pdo()->prepare(
            "SELECT d.id, d.document_no, d.kind, d.status, d.entry_date, d.document_date, d.title,
                    d.partner_name, d.amount, d.journal_entry_id, d.reversal_entry_id
               FROM journal_entries je
               JOIN internal_documents d ON d.supplier_id = je.supplier_id AND d.journal_entry_id = je.id
              WHERE je.supplier_id = ? AND je.entry_date >= ? AND je.entry_date <= ?
                AND d.entry_date >= ? AND d.entry_date <= ? AND d.document_no IS NOT NULL
              ORDER BY d.entry_date ASC, d.id ASC"
            . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $stmt->execute([$supplierId, $from, $to, $from, $to]);
        $rows = [];
        while (($r = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $rows[] = $r + [
                'number'      => (string) $r['document_no'],
                'doc_date'    => (string) $r['entry_date'],
                'ref_dates'   => [],
                'entry_ids'   => self::entryIds($r['journal_entry_id'], $r['reversal_entry_id']),
                'keep_reason' => null,
            ];
        }
        return $rows;
    }

    protected function describe(array $row): array
    {
        return [
            'id'          => (int) $row['id'],
            'number'      => (string) $row['number'],
            'date'        => (string) $row['entry_date'],
            'partner'     => (string) ($row['partner_name'] ?? ''),
            'description' => (string) ($row['title'] ?? ''),
            'amount'      => (float) $row['amount'],
            'currency'    => 'CZK',
            'status'      => (string) $row['status'],
            'kind'        => (string) ($row['kind'] ?? ''),
        ];
    }
}
