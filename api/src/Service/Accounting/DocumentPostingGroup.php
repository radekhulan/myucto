<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use PDO;

/** Vlastní zaúčtování dokladu; informativní vazby a úhrady sem nepatří. */
final class DocumentPostingGroup
{
    public function __construct(private readonly Connection $db) {}

    /** Klíč zaúčtovaného dokladu včetně originálu protizápisu pro smír DPH. */
    public static function documentKeySql(string $alias): string
    {
        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $alias) !== 1) {
            throw new \InvalidArgumentException('Neplatný alias účetního zápisu.');
        }
        $original = $alias . '_original';
        $direct = self::directDocumentKeySql($alias);
        $parent = self::directDocumentKeySql($original);
        return "COALESCE({$direct},
            (SELECT MIN(COALESCE({$parent}, CONCAT('manual:', {$original}.id)))
               FROM journal_entries {$original} FORCE INDEX (idx_je_supplier_reversed_by)
              WHERE {$original}.supplier_id = {$alias}.supplier_id AND {$original}.reversed_by = {$alias}.id
             HAVING COUNT(*) = 1), CONCAT('manual:', {$alias}.id))";
    }

    /**
     * SQL podmínka „doklad má aktivní předpis": přímý zápis se zdrojem dokladu, nebo
     * zápis, který doklad vlastní ve skupině zaúčtování. Nativní doklad skupinu nemá,
     * takže výsledek je u něj totožný s dotazem jen na přímý zdroj. Bez $postedOnly
     * se jako dřív počítá i nezaúčtovaný koncept. Obě větve jdou po indexu
     * idx_je_supplier_source a uq_jdp_doc_entry.
     *
     * Vydanou fakturu navázanou na ostatní pohledávku (migrace 2246) účtuje živý zápis
     * pohledávky; bez $otherItemCover se počítá jen vlastní zápis faktury. Větev jde po
     * primárních klíčích faktury, pohledávky a zápisu. S ní se každý argument v SQL
     * objeví dvakrát, volající s pozičními parametry ji proto vypíná.
     *
     * Argumenty jsou SQL výrazy volajícího (sloupec vnějšího dotazu nebo literál),
     * nikdy uživatelský vstup.
     */
    public static function activePostingExistsSql(string $docTypeSql, string $docIdSql, string $supplierIdSql, bool $postedOnly = true, bool $otherItemCover = true): string
    {
        $posted = static fn (string $alias): string => $postedOnly ? " AND {$alias}.posted_at IS NOT NULL" : '';
        $cover = $otherItemCover
            ? "
             OR ({$docTypeSql} = 'invoice' AND EXISTS (SELECT 1 FROM invoices dpg_inv
                  JOIN other_items dpg_oi ON dpg_oi.supplier_id = dpg_inv.supplier_id AND dpg_oi.id = dpg_inv.other_item_id
                  JOIN journal_entries dpg_oie ON dpg_oie.supplier_id = dpg_oi.supplier_id AND dpg_oie.id = dpg_oi.journal_entry_id
                  WHERE dpg_inv.supplier_id = {$supplierIdSql} AND dpg_inv.id = {$docIdSql}
                    AND dpg_oie.reversed_by IS NULL{$posted('dpg_oie')}))"
            : '';
        return "(EXISTS (SELECT 1 FROM journal_entries dpg_direct FORCE INDEX (idx_je_supplier_source)
                  WHERE dpg_direct.supplier_id = {$supplierIdSql} AND dpg_direct.source_type = {$docTypeSql}
                    AND dpg_direct.source_id = {$docIdSql} AND dpg_direct.reversed_by IS NULL{$posted('dpg_direct')})
             OR EXISTS (SELECT 1 FROM journal_document_postings dpg_jdp FORCE INDEX (uq_jdp_doc_entry)
                  JOIN journal_entries dpg_owned ON dpg_owned.supplier_id = dpg_jdp.supplier_id AND dpg_owned.id = dpg_jdp.entry_id
                  WHERE dpg_jdp.supplier_id = {$supplierIdSql} AND dpg_jdp.doc_type = {$docTypeSql}
                    AND dpg_jdp.doc_id = {$docIdSql} AND dpg_owned.reversed_by IS NULL{$posted('dpg_owned')}){$cover})";
    }

    /**
     * Aktivní vazby předpisů celé firmy pro kontrolu integrity. UNION odstraní
     * dvojí výskyt přímého člena, který je zároveň vlastněný skupinou. Na rozdíl
     * od hasActive() jde o dávkový přehled, nikdy o dotaz pro každý doklad zvlášť.
     */
    public static function activeReferencesSql(int $supplierId): string
    {
        return "SELECT e.id AS entry_id, e.source_type AS doc_type, e.source_id AS doc_id
                  FROM journal_entries e FORCE INDEX (idx_je_supplier_source)
                 WHERE e.supplier_id = {$supplierId} AND e.source_type IN ('invoice', 'purchase_invoice')
                   AND e.source_id IS NOT NULL AND e.posted_at IS NOT NULL AND e.reversed_by IS NULL
                UNION
                SELECT e.id, jdp.doc_type, jdp.doc_id
                  FROM journal_document_postings jdp FORCE INDEX (uq_jdp_doc_entry)
                  JOIN journal_entries e ON e.supplier_id = jdp.supplier_id AND e.id = jdp.entry_id
                 WHERE jdp.supplier_id = {$supplierId} AND e.posted_at IS NOT NULL AND e.reversed_by IS NULL";
    }

    /**
     * Aktivní členové dokladů, které mají skupinu zaúčtování: vlastněné zápisy i přímý
     * zápis se zdrojem dokladu. Doklady bez skupiny ve výsledku nejsou, kontroly je dál
     * vyhodnocují po jednotlivém přímém zápisu. Dotaz vychází z vazeb skupin firmy
     * (uq_jdp_doc_entry) a přímý zápis dohledává po idx_je_supplier_source.
     */
    public static function groupedActiveMembersSql(int $supplierId): string
    {
        return "SELECT m.doc_type, m.doc_id, e.id AS entry_id
                  FROM (SELECT jdp.doc_type, jdp.doc_id, jdp.entry_id
                          FROM journal_document_postings jdp FORCE INDEX (uq_jdp_doc_entry)
                         WHERE jdp.supplier_id = {$supplierId}
                        UNION
                        SELECT g.doc_type, g.doc_id, d.id
                          FROM (SELECT DISTINCT jdp.doc_type, jdp.doc_id
                                  FROM journal_document_postings jdp FORCE INDEX (uq_jdp_doc_entry)
                                 WHERE jdp.supplier_id = {$supplierId}) g
                          JOIN journal_entries d FORCE INDEX (idx_je_supplier_source)
                            ON d.supplier_id = {$supplierId} AND d.source_type = g.doc_type AND d.source_id = g.doc_id) m
                  JOIN journal_entries e ON e.supplier_id = {$supplierId} AND e.id = m.entry_id
                 WHERE e.posted_at IS NOT NULL AND e.reversed_by IS NULL";
    }

    /**
     * Všechny zápisy, kterými firma zaúčtovala doklady daného typu: `entry_id`, `doc_id`.
     * Přímý zápis se zdrojem dokladu a k němu zápisy, které doklad vlastní ve skupině
     * zaúčtování (převod z programu, který účtuje doklad po řádcích). Vlastněný zápis
     * zdroj nemá ({@see attach()}), takže se větve nepřekrývají a přímý člen skupiny se
     * nezapočte dvakrát. Zda zápis platí k datu, rozhoduje volající nad `journal_entries`.
     *
     * Saldokonto i uzávěrková kontrola zaplacených dokladů z něj berou předpis dokladu:
     * doklad účtovaný po řádcích jinak vypadal otevřený o všechny řádky mimo první zápis.
     * Obě větve jdou po indexu (idx_je_supplier_source, uq_jdp_doc_entry).
     *
     * @param 'invoice'|'purchase_invoice' $docType
     */
    public static function entriesSql(string $docType, int $supplierId, bool $currentRead = false): string
    {
        if (!in_array($docType, ['invoice', 'purchase_invoice'], true)) {
            throw new \InvalidArgumentException('Nepodporovaný doklad skupiny zaúčtování.');
        }
        $lock = $currentRead ? ' FOR UPDATE' : '';
        return "(SELECT dpe.id AS entry_id, dpe.source_id AS doc_id
                  FROM journal_entries dpe FORCE INDEX (idx_je_supplier_source)
                 WHERE dpe.supplier_id = {$supplierId} AND dpe.source_type = '{$docType}' AND dpe.source_id IS NOT NULL{$lock})
                UNION ALL
                (SELECT dpo.entry_id, dpo.doc_id
                  FROM journal_document_postings dpo FORCE INDEX (uq_jdp_doc_entry)
                  JOIN journal_entries dpm ON dpm.supplier_id = dpo.supplier_id AND dpm.id = dpo.entry_id
                 WHERE dpo.supplier_id = {$supplierId} AND dpo.doc_type = '{$docType}' AND dpm.source_id IS NULL{$lock})";
    }

    private static function directDocumentKeySql(string $alias): string
    {
        // Historické vlastnictví přežívá smazání dokladu. Smír pak musí nabídnout
        // existující zápis deníku, nikoli odkaz na již neexistující fakturu.
        return "COALESCE((SELECT CASE WHEN
                           (jdp.doc_type = 'invoice' AND EXISTS (
                               SELECT 1 FROM invoices jdp_invoice
                                WHERE jdp_invoice.supplier_id = jdp.supplier_id AND jdp_invoice.id = jdp.doc_id))
                           OR (jdp.doc_type = 'purchase_invoice' AND EXISTS (
                               SELECT 1 FROM purchase_invoices jdp_purchase
                                WHERE jdp_purchase.supplier_id = jdp.supplier_id AND jdp_purchase.id = jdp.doc_id))
                           THEN CONCAT(jdp.doc_type, ':', jdp.doc_id) ELSE CONCAT('manual:', {$alias}.id) END
                           FROM journal_document_postings jdp
                           WHERE jdp.supplier_id = {$alias}.supplier_id AND jdp.entry_id = {$alias}.id),
                         CONCAT({$alias}.source_type, ':', {$alias}.source_id))";
    }

    /** @param list<array<string,mixed>> $entries */
    public static function hasOwnedEntries(array $entries): bool
    {
        return array_any($entries, static fn (array $entry): bool => (bool) ($entry['grouped'] ?? false));
    }

    /**
     * Skupina má jediný aktivní zápis a ten nese zdroj dokladu. Takový předpis lze
     * přepsat na místě stejně jako nativní zápis, aniž by vedle něj zůstala jiná část.
     * @param list<array<string,mixed>> $entries
     */
    public static function isSingleDirectEntry(array $entries, string $docType, int $docId): bool
    {
        return count($entries) === 1
            && (string) $entries[0]['source_type'] === $docType
            && $entries[0]['source_id'] !== null && (int) $entries[0]['source_id'] === $docId;
    }

    /** Vlastník zápisu i po stornu; měkké vazby nejsou vlastnictvím. */
    public function owner(int $supplierId, int $entryId, bool $lock = false): ?array
    {
        if ($lock && !$this->db->pdo()->inTransaction()) throw new \LogicException('Zamčení vlastníka vyžaduje transakci.');
        if ($lock) (new DimensionPeriodGuard($this->db))->lockPeriods($supplierId);
        $stmt = $this->db->pdo()->prepare("SELECT doc_type, doc_id FROM journal_document_postings
            WHERE supplier_id = ? AND entry_id = ?" . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute([$supplierId, $entryId]);
        $owner = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($owner !== false) return ['doc_type' => (string) $owner['doc_type'], 'doc_id' => (int) $owner['doc_id']];
        $stmt = $this->db->pdo()->prepare("SELECT source_type, source_id FROM journal_entries
            WHERE supplier_id = ? AND id = ? AND source_type IN ('invoice', 'purchase_invoice') AND source_id IS NOT NULL"
            . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute([$supplierId, $entryId]);
        $entry = $stmt->fetch(PDO::FETCH_ASSOC);
        return $entry === false ? null : ['doc_type' => (string) $entry['source_type'], 'doc_id' => (int) $entry['source_id']];
    }

    /** @param list<int> $entryIds */
    public function attach(int $supplierId, string $docType, int $docId, array $entryIds): void
    {
        $table = match ($docType) {
            'invoice' => 'invoices',
            'purchase_invoice' => 'purchase_invoices',
            default => throw new \InvalidArgumentException('Nepodporovaný doklad skupiny zaúčtování.'),
        };
        $pdo = $this->db->pdo();
        if (!$pdo->inTransaction()) throw new \LogicException('Skupina zaúčtování vyžaduje transakci.');
        (new DimensionPeriodGuard($this->db))->lockPeriods($supplierId);
        $document = $pdo->prepare("SELECT id FROM {$table} WHERE supplier_id = ? AND id = ? FOR UPDATE");
        $document->execute([$supplierId, $docId]);
        if ($document->fetchColumn() === false) throw new PostingException('document_not_found', 'Doklad skupiny zaúčtování neexistuje.', 404);
        $entryIds = array_values(array_unique(array_map('intval', $entryIds)));
        if ($entryIds === []) return;
        $entries = (new DimensionPeriodGuard($this->db))->lockEntries($supplierId, $entryIds, false);
        if (count($entries) !== count($entryIds)) throw new PostingException('entry_not_found', 'Zápis skupiny zaúčtování neexistuje.', 404);
        foreach ($entries as $entry) {
            if (!((in_array($entry['source_type'], ['manual', $docType], true) && $entry['source_id'] === null)
                || ($entry['source_type'] === $docType && (int) $entry['source_id'] === $docId))) {
                throw new PostingException('posting_group_conflict', 'Zápis již účtuje jiný doklad.', 409);
            }
        }
        $in = implode(',', array_fill(0, count($entryIds), '?'));
        $owners = $pdo->prepare("SELECT doc_type, doc_id FROM journal_document_postings WHERE supplier_id = ? AND entry_id IN ({$in}) FOR UPDATE");
        $owners->execute([$supplierId, ...$entryIds]);
        foreach ($owners->fetchAll(PDO::FETCH_ASSOC) as $owner) {
            if ($owner['doc_type'] !== $docType || (int) $owner['doc_id'] !== $docId) {
                throw new PostingException('posting_group_conflict', 'Zápis již patří k zaúčtování jiného dokladu.', 409);
            }
        }
        $values = implode(',', array_fill(0, count($entryIds), '(?, ?, ?, ?)'));
        $params = [];
        foreach ($entryIds as $entryId) array_push($params, $supplierId, $docType, $docId, $entryId);
        $pdo->prepare("INSERT IGNORE INTO journal_document_postings (supplier_id, doc_type, doc_id, entry_id) VALUES {$values}")->execute($params);
    }

    /**
     * Aktivní předpisy: nativní zdroj i další vlastněné zápisy. Jediný zdroj skupiny
     * pro automatiku, přeúčtování a rušení dokladu; bez dohledávání podle čísla.
     * @return list<array<string,mixed>>
     */
    public function active(int $supplierId, string $docType, int $docId, bool $lock = false): array
    {
        return $this->activeMany($supplierId, $docType, [$docId], $lock)[$docId] ?? [];
    }

    /** @param list<int> $docIds @return array<int,list<array<string,mixed>>> */
    public function activeMany(int $supplierId, string $docType, array $docIds, bool $lock = false): array
    {
        if (!in_array($docType, ['invoice', 'purchase_invoice'], true) || $docIds === []) return [];
        $docIds = array_values(array_unique(array_map('intval', $docIds)));
        $in = implode(',', array_fill(0, count($docIds), '?'));
        if ($lock && !$this->db->pdo()->inTransaction()) throw new \LogicException('Zamčení skupiny vyžaduje transakci.');
        if ($lock) (new DimensionPeriodGuard($this->db))->lockPeriods($supplierId);
        $suffix = $lock ? ' FOR UPDATE' : '';
        $fields = 'e.*, p.status AS period_status, jdp.entry_id IS NOT NULL AS grouped, jdp.doc_id AS posting_doc_id';
        $joins = ' JOIN accounting_periods p ON p.supplier_id = e.supplier_id AND p.id = e.period_id';
        $direct = $this->db->pdo()->prepare("SELECT {$fields}
            FROM journal_entries e FORCE INDEX (idx_je_supplier_source){$joins}
            LEFT JOIN journal_document_postings jdp ON jdp.supplier_id = e.supplier_id AND jdp.entry_id = e.id
            WHERE e.supplier_id = ? AND e.source_type = ? AND e.source_id IN ({$in})
              AND e.posted_at IS NOT NULL AND e.reversed_by IS NULL ORDER BY e.id{$suffix}");
        $direct->execute([$supplierId, $docType, ...$docIds]);
        $result = [];
        foreach ($direct->fetchAll(PDO::FETCH_ASSOC) as $entry) $result[(int) $entry['source_id']][(int) $entry['id']] = $entry;
        // Samostatný current read: materializovaná CTE by uvnitř starší transakce
        // mohla použít snapshot před souběžným převodem. Obě cesty jdou po indexu.
        $owned = $this->db->pdo()->prepare("SELECT {$fields}
            FROM journal_document_postings jdp FORCE INDEX (uq_jdp_doc_entry)
            JOIN journal_entries e ON e.supplier_id = jdp.supplier_id AND e.id = jdp.entry_id{$joins}
            WHERE jdp.supplier_id = ? AND jdp.doc_type = ? AND jdp.doc_id IN ({$in})
              AND e.posted_at IS NOT NULL AND e.reversed_by IS NULL ORDER BY e.entry_date, e.id{$suffix}");
        $owned->execute([$supplierId, $docType, ...$docIds]);
        foreach ($owned->fetchAll(PDO::FETCH_ASSOC) as $entry) $result[(int) $entry['posting_doc_id']][(int) $entry['id']] = $entry;
        return array_map(array_values(...), $result);
    }

    /**
     * Má doklad aktivní předpis (přímý zdroj nebo vlastněný zápis)? Bez zámku období:
     * příjem či změna stavu dokladu nesmí čekat na uzávěrku. V transakci čte aktuální
     * stav se sdíleným zámkem jen na řádcích zápisů dokladu.
     */
    public function hasActive(int $supplierId, string $docType, int $docId): bool
    {
        if (!in_array($docType, ['invoice', 'purchase_invoice'], true)) return false;
        $suffix = $this->db->pdo()->inTransaction() ? ' LOCK IN SHARE MODE' : '';
        $direct = $this->db->pdo()->prepare("SELECT e.id FROM journal_entries e FORCE INDEX (idx_je_supplier_source)
            WHERE e.supplier_id = ? AND e.source_type = ? AND e.source_id = ?
              AND e.posted_at IS NOT NULL AND e.reversed_by IS NULL LIMIT 1{$suffix}");
        $direct->execute([$supplierId, $docType, $docId]);
        if ($direct->fetchColumn() !== false) return true;
        $owned = $this->db->pdo()->prepare("SELECT e.id FROM journal_document_postings jdp FORCE INDEX (uq_jdp_doc_entry)
            JOIN journal_entries e ON e.supplier_id = jdp.supplier_id AND e.id = jdp.entry_id
            WHERE jdp.supplier_id = ? AND jdp.doc_type = ? AND jdp.doc_id = ?
              AND e.posted_at IS NOT NULL AND e.reversed_by IS NULL LIMIT 1{$suffix}");
        $owned->execute([$supplierId, $docType, $docId]);
        return $owned->fetchColumn() !== false;
    }

    /** @param list<int> $entryIds @return list<array<string,mixed>> */
    public function lines(int $supplierId, array $entryIds, bool $lock = false): array
    {
        if ($entryIds === []) return [];
        if ($lock && !$this->db->pdo()->inTransaction()) throw new \LogicException('Zamčení řádků vyžaduje transakci.');
        $in = implode(',', array_fill(0, count($entryIds), '?'));
        $stmt = $this->db->pdo()->prepare("SELECT l.*, ca.account_code, ca.name AS account_name
            FROM journal_entry_lines l JOIN chart_of_accounts ca ON ca.id = l.account_id AND ca.supplier_id = l.supplier_id
            WHERE l.supplier_id = ? AND l.entry_id IN ({$in}) ORDER BY l.entry_id, l.line_no" . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute([$supplierId, ...$entryIds]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
