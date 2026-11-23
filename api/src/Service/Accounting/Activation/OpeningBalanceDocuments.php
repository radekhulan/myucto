<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Activation;

/**
 * Doklad zastoupený v počátečních stavech - převzatý z předchozího systému s vazbou na
 * otevírací zápis období (`journal_entries.source_type = 'opening'`).
 *
 * Typicky neuhrazená faktura z minulého roku, kterou převod přenesl kvůli saldu a párování
 * úhrad, nebo pokladní doklad počátečního stavu. Jeho částka už v deníku je (v počátečních
 * stavech), vlastní zápis mít nesmí: doúčtování by ji zapsalo podruhé. Jediná definice pro
 * počítadlo ({@see PendingBackfillCounter}) i obě doúčtování ({@see DocumentBackfill},
 * {@see CashBackfill}), aby se nerozešly.
 */
final class OpeningBalanceDocuments
{
    public const NOTE = 'Zastoupeno v počátečních stavech';

    /**
     * SQL podmínka „doklad není v počátečních stavech" pro WHERE nad tabulkou dokladu.
     *
     * @param 'invoice'|'purchase_invoice'|'cash' $docType typ dokladu v `journal_entry_document_links`
     * @param string $alias alias tabulky dokladu se sloupci `id` a `supplier_id`
     */
    public static function notInOpeningSql(string $docType, string $alias): string
    {
        return 'NOT ' . self::inOpeningSql($docType, $alias);
    }

    /**
     * SQL podmínka „doklad je v počátečních stavech" (vazba na živý otevírací zápis).
     *
     * @param 'invoice'|'purchase_invoice'|'cash' $docType typ dokladu v `journal_entry_document_links`
     * @param string $alias alias tabulky dokladu se sloupci `id` a `supplier_id`
     */
    public static function inOpeningSql(string $docType, string $alias): string
    {
        if (!in_array($docType, ['invoice', 'purchase_invoice', 'cash'], true) || preg_match('/^[a-z_][a-z0-9_]*$/', $alias) !== 1) {
            throw new \InvalidArgumentException('Neplatný typ dokladu nebo alias.');
        }
        return "EXISTS (SELECT 1 FROM journal_entry_document_links obd_l
                          JOIN journal_entries obd_e ON obd_e.id = obd_l.entry_id AND obd_e.supplier_id = obd_l.supplier_id
                         WHERE obd_l.supplier_id = {$alias}.supplier_id AND obd_l.doc_type = '{$docType}'
                           AND obd_l.doc_id = {$alias}.id AND obd_e.source_type = 'opening' AND obd_e.reversed_by IS NULL)";
    }

    /**
     * Je doklad firmy v počátečních stavech? Táž podmínka jako {@see inOpeningSql()}.
     *
     * @param 'invoice'|'purchase_invoice'|'cash' $docType
     */
    public static function isInOpening(\PDO $pdo, int $supplierId, string $docType, int $docId): bool
    {
        $table = match ($docType) {
            'invoice'          => 'invoices',
            'purchase_invoice' => 'purchase_invoices',
            'cash'             => 'cash_documents',
            default            => throw new \InvalidArgumentException('Neplatný typ dokladu.'),
        };
        $stmt = $pdo->prepare("SELECT 1 FROM {$table} d WHERE d.id = ? AND d.supplier_id = ? AND " . self::inOpeningSql($docType, 'd'));
        $stmt->execute([$docId, $supplierId]);
        return $stmt->fetchColumn() !== false;
    }
}
