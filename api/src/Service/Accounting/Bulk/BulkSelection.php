<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Bulk;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Výběr záznamů hromadné akce: vydané faktury, přijaté faktury nebo zápisy deníku.
 *
 * Úloha pracuje vždy s pevným seznamem id, který vznikne při spuštění běhu nanečisto
 * (ruční výběr ze seznamu, nebo „všech N podle filtru" vyhodnocené kódem seznamu).
 * Id musí patřit aktuální firmě: cizí id se nepřeskočí potichu, celý výběr se odmítne.
 */
final class BulkSelection
{
    public const LIMIT = 500;
    public const TARGETS = ['invoice', 'purchase_invoice', 'journal_entry'];

    /** Druh záznamu => [tabulka, zdroj zápisu v deníku]. */
    private const TABLES = [
        'invoice' => ['invoices', 'invoice'],
        'purchase_invoice' => ['purchase_invoices', 'purchase_invoice'],
        'journal_entry' => ['journal_entries', null],
    ];

    public function __construct(private readonly Connection $db) {}

    public static function assertTarget(string $target): void
    {
        if (!in_array($target, self::TARGETS, true)) {
            throw new BulkActionException('validation_failed', 'Neznámý druh záznamů hromadné akce.', 422);
        }
    }

    /**
     * Ověří ručně vybraná id: kladná celá čísla, nejvýš {@see LIMIT}, všechna z aktuální firmy.
     *
     * @param mixed $raw
     * @return list<int>
     * @throws BulkActionException no_items / too_many_items / foreign_ids / validation_failed
     */
    public function explicitIds(int $supplierId, string $target, mixed $raw): array
    {
        self::assertTarget($target);
        if (!is_array($raw) || !array_is_list($raw)
            || array_filter($raw, static fn (mixed $id): bool => !is_int($id) || $id <= 0) !== []) {
            throw new BulkActionException('validation_failed', 'Neplatný seznam vybraných záznamů.', 422);
        }
        $ids = array_values(array_unique($raw));
        if ($ids === []) {
            throw new BulkActionException('no_items', 'Vyberte alespoň jeden záznam.', 422);
        }
        if (count($ids) > self::LIMIT) {
            throw self::tooMany(count($ids));
        }
        $table = self::TABLES[$target][0];
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->pdo()->prepare("SELECT id FROM {$table} WHERE supplier_id = ? AND id IN ({$marks})");
        $stmt->execute([$supplierId, ...$ids]);
        $found = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        if (count($found) !== count($ids)) {
            throw new BulkActionException('foreign_ids',
                'Některé vybrané záznamy v aktuální firmě nejsou (' . (count($ids) - count($found)) . '). Obnovte seznam a vyberte znovu.',
                422, ['unknown' => count($ids) - count($found)]);
        }
        sort($found);
        return $found;
    }

    public static function tooMany(int $matching): BulkActionException
    {
        return new BulkActionException('too_many_items',
            'Výběr obsahuje ' . $matching . ' záznamů, najednou jde zpracovat nejvýš ' . self::LIMIT
                . '. Zužte filtr seznamu nebo vyberte méně záznamů.',
            422, ['matching' => $matching, 'limit' => self::LIMIT]);
    }

    /**
     * Záznamy výběru pro běh nanečisto v pořadí data: klíč položky, aktivní zápis
     * v deníku (NULL = nezaúčtovaný doklad), datum, číslo a popis.
     *
     * @param list<int> $ids
     * @return list<array{source_type:string, source_id:int, entry_id:?int, entry_date:string, document_no:?string, description:?string}>
     */
    public function rows(int $supplierId, string $target, array $ids): array
    {
        self::assertTarget($target);
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $sql = match ($target) {
            'invoice' => "SELECT i.id AS source_id, je.id AS entry_id,
                                 COALESCE(je.entry_date, i.tax_date, i.issue_date, DATE(i.created_at)) AS entry_date,
                                 i.varsymbol AS document_no, c.company_name AS description
                            FROM invoices i
                       LEFT JOIN clients c ON c.id = i.client_id
                       LEFT JOIN journal_entries je ON je.supplier_id = i.supplier_id AND je.source_type = 'invoice'
                                 AND je.active_source_id = i.id AND je.posted_at IS NOT NULL
                           WHERE i.supplier_id = ? AND i.id IN ({$marks})",
            'purchase_invoice' => "SELECT pi.id AS source_id, je.id AS entry_id,
                                 COALESCE(je.entry_date, pi.tax_date, pi.issue_date, DATE(pi.created_at)) AS entry_date,
                                 COALESCE(NULLIF(pi.vendor_invoice_number, ''), pi.varsymbol) AS document_no, c.company_name AS description
                            FROM purchase_invoices pi
                       LEFT JOIN clients c ON c.id = pi.vendor_id
                       LEFT JOIN journal_entries je ON je.supplier_id = pi.supplier_id AND je.source_type = 'purchase_invoice'
                                 AND je.active_source_id = pi.id AND je.posted_at IS NOT NULL
                           WHERE pi.supplier_id = ? AND pi.id IN ({$marks})",
            default => "SELECT je.id AS source_id, je.id AS entry_id, je.entry_date, je.document_no, je.description
                          FROM journal_entries je
                         WHERE je.supplier_id = ? AND je.id IN ({$marks})",
        };
        $stmt = $this->db->pdo()->prepare($sql . ' ORDER BY 3, 1');
        $stmt->execute([$supplierId, ...$ids]);
        return array_map(static fn (array $r): array => [
            'source_type' => $target,
            'source_id'   => (int) $r['source_id'],
            'entry_id'    => $r['entry_id'] === null ? null : (int) $r['entry_id'],
            'entry_date'  => (string) $r['entry_date'],
            'document_no' => $r['document_no'] === null ? null : (string) $r['document_no'],
            'description' => $r['description'] === null ? null : (string) $r['description'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Základ položky z řádku výběru.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function itemBase(array $row): array
    {
        return [
            'entry_id'    => isset($row['entry_id']) ? (int) $row['entry_id'] : null,
            'source_type' => (string) $row['source_type'],
            'source_id'   => (int) $row['source_id'],
            'entry_date'  => (string) $row['entry_date'],
            'document_no' => $row['document_no'] ?? null,
            'description' => $row['description'] ?? null,
            'amount'      => 0.0,
            'before'      => [],
            'after'       => [],
        ];
    }

    /**
     * Položka předchozího běhu jako základ položky dalšího běhu.
     *
     * @param array<string,mixed> $item
     * @return array<string,mixed>
     */
    public static function copyItem(array $item): array
    {
        return array_intersect_key($item, array_flip([
            'entry_id', 'source_type', 'source_id', 'entry_date', 'document_no', 'description',
            'amount', 'strategy', 'target_date', 'date_shifted', 'before', 'after', 'fingerprint',
        ]));
    }
}
