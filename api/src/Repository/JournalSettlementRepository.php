<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Support\Sql\JournalSettlementAllocationSql;
use PDO;

/**
 * Rozpad řádků zápisu v deníku na doklady saldokonta (`journal_settlement_allocations`,
 * migrace 2192). Výpočet salda z rozpadu drží {@see JournalSettlementAllocationSql};
 * tady je jen čtení řádků, jejich volné částky a zápis rozpadu.
 *
 * Všechny dotazy začínají omezením na firmu. `doc_id` nemá FK (je polymorfní), tenanta
 * dokladu proto ověřuje {@see lockDocument()}, bez toho by šlo rozepsat zápis na cizí
 * doklad.
 */
final class JournalSettlementRepository
{
    /** Druhy zápisu, jejichž řádky smí účetní rozepsat na doklady. */
    public const ALLOCATABLE_SOURCES = ['manual'];
    /** Zápisy pohybu peněz: ostatní položku z nich zakládá převod z jiného programu, ne účetní v saldokontu. */
    public const PAYMENT_SOURCES = ['bank', 'cash'];

    public function __construct(private readonly Connection $db) {}

    /**
     * Účet a jeho analytiky (saldokonto počítá účet `ca.id = X OR ca.parent_id = X`).
     *
     * @return list<int>
     */
    public function accountFamily(int $supplierId, int $accountId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM chart_of_accounts WHERE supplier_id = ? AND (id = ? OR parent_id = ?) ORDER BY id'
        );
        $stmt->execute([$supplierId, $accountId, $accountId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Zamkne řádky deníku a doklady rozpadu (FOR UPDATE) dřív, než se cokoli přečte:
     * souběžné rozpady téhož řádku nebo dokladu se seřadí a druhý už počítá s prvním.
     * Řádky i doklady se zamykají ve vzestupném pořadí, aby souběh nevedl k uváznutí.
     *
     * @param list<int> $lineIds
     * @param list<array{0:string,1:int}> $documents [doc_type, doc_id]
     */
    public function lockForAllocation(int $supplierId, array $lineIds, array $documents): void
    {
        $pdo = $this->db->pdo();
        $ids = self::ids($lineIds);
        if ($ids !== []) {
            $pdo->prepare('SELECT id FROM journal_entry_lines WHERE supplier_id = ? AND id IN (' . implode(',', $ids) . ') ORDER BY id FOR UPDATE')
                ->execute([$supplierId]);
        }
        foreach (['invoice' => 'invoices', 'purchase_invoice' => 'purchase_invoices'] as $type => $table) {
            $docIds = self::ids(array_map(static fn (array $d): int => (int) $d[1], array_filter($documents, static fn (array $d): bool => $d[0] === $type)));
            if ($docIds !== []) {
                $pdo->prepare("SELECT id FROM {$table} WHERE supplier_id = ? AND id IN (" . implode(',', $docIds) . ') ORDER BY id FOR UPDATE')
                    ->execute([$supplierId]);
            }
        }
    }

    /**
     * Řádky zápisu s tím, co o nich potřebuje validace rozpadu. Zamyká je
     * {@see lockForAllocation()}.
     *
     * @param list<int> $lineIds
     * @return array<int,array<string,mixed>> podle id řádku
     */
    public function lines(int $supplierId, array $lineIds): array
    {
        $ids = self::ids($lineIds);
        if ($ids === []) {
            return [];
        }
        $in = implode(',', $ids);
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            "SELECT l.id AS line_id, l.entry_id, l.line_no, l.account_id, l.side, l.amount, l.is_red_storno,
                    l.currency_code, ca.account_code, ca.name AS account_name,
                    COALESCE(ca.parent_id, ca.id) AS synthetic_id,
                    COALESCE(pa.account_code, ca.account_code) AS synthetic_code,
                    e.entry_date, e.document_no, e.description, e.source_type, e.posted_at, e.reversed_by,
                    e.period_id, ap.status AS period_status,
                    EXISTS (SELECT 1 FROM journal_document_postings jdp
                             WHERE jdp.supplier_id = e.supplier_id AND jdp.entry_id = e.id) AS own_posting,
                    EXISTS (SELECT 1 FROM journal_entries o
                             WHERE o.supplier_id = e.supplier_id AND o.reversed_by = e.id) AS is_reversal,
                    NOT " . JournalSettlementAllocationSql::noImportedSettlementSql('e.supplier_id', 'e.id') . " AS imported_settlement,
                    EXISTS (SELECT 1 FROM journal_entry_lines dup
                             WHERE dup.supplier_id = l.supplier_id AND dup.entry_id = l.entry_id
                               AND dup.line_no = l.line_no AND dup.id <> l.id) AS duplicate_line_no
               FROM journal_entry_lines l
               JOIN journal_entries e ON e.id = l.entry_id AND e.supplier_id = l.supplier_id
               JOIN chart_of_accounts ca ON ca.id = l.account_id AND ca.supplier_id = l.supplier_id
               LEFT JOIN chart_of_accounts pa ON pa.id = ca.parent_id AND pa.supplier_id = ca.supplier_id
               LEFT JOIN accounting_periods ap ON ap.id = e.period_id AND ap.supplier_id = e.supplier_id
              WHERE l.supplier_id = ? AND l.id IN ({$in})"
        );
        $stmt->execute([$supplierId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['line_id']] = [
                'line_id'        => (int) $r['line_id'],
                'entry_id'       => (int) $r['entry_id'],
                'line_no'        => (int) $r['line_no'],
                'account_id'     => (int) $r['account_id'],
                'side'           => (string) $r['side'],
                'amount'         => round((float) $r['amount'], 2),
                'is_red_storno'  => (int) $r['is_red_storno'] === 1,
                'currency_code'  => $r['currency_code'] !== null ? (string) $r['currency_code'] : null,
                'account_code'   => (string) $r['account_code'],
                'account_name'   => (string) $r['account_name'],
                'synthetic_id'   => (int) $r['synthetic_id'],
                'synthetic_code' => (string) $r['synthetic_code'],
                'entry_date'     => (string) $r['entry_date'],
                'document_no'    => $r['document_no'] !== null ? (string) $r['document_no'] : null,
                'description'    => $r['description'] !== null ? (string) $r['description'] : null,
                'source_type'    => (string) $r['source_type'],
                'posted'         => $r['posted_at'] !== null,
                'reversed'       => $r['reversed_by'] !== null,
                'period_id'      => (int) $r['period_id'],
                'period_status'  => $r['period_status'] !== null ? (string) $r['period_status'] : null,
                'own_posting'    => (int) $r['own_posting'] === 1,
                'is_reversal'    => (int) $r['is_reversal'] === 1,
                'imported_settlement' => (int) $r['imported_settlement'] === 1,
                'duplicate_line_no' => (int) $r['duplicate_line_no'] === 1,
            ];
        }
        return $out;
    }

    /** Už rozepsaná částka řádku (rozpady i nezrušené předpisy ostatních položek). */
    public function usedOnLine(int $supplierId, array $line): float
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT used FROM (" . JournalSettlementAllocationSql::usedByLineSql($supplierId, [(int) $line['account_id']]) . ") u
              WHERE u.entry_id = ? AND u.line_no = ? AND u.side = ?"
        );
        $stmt->execute([$line['entry_id'], $line['line_no'], $line['side']]);
        return round((float) ($stmt->fetchColumn() ?: 0), 2);
    }

    /**
     * Vyrovnává zápis doklad druhu `$docType` už starou vazbou bez částky? Platí to, když je
     * navázaný právě na jeden doklad toho druhu a rozpad na ten druh nemá
     * ({@see \MyInvoice\Support\Sql\LinkedManualSettlementSql}). Takový zápis je celý použitý.
     */
    public function hasCountedLink(int $supplierId, int $entryId, ?string $docType = null): bool
    {
        $types = $docType === null ? ['invoice', 'purchase_invoice'] : [$docType];
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM journal_entry_document_links
              WHERE supplier_id = ? AND entry_id = ? AND doc_type = ?'
        );
        $alloc = $this->db->pdo()->prepare(
            'SELECT 1 FROM journal_settlement_allocations ja ' . JournalSettlementAllocationSql::lineJoin('ja', 'l') . "
              WHERE ja.supplier_id = ? AND ja.entry_id = ? AND ja.doc_type = ? AND ja.kind = 'settlement' LIMIT 1"
        );
        foreach ($types as $type) {
            $stmt->execute([$supplierId, $entryId, $type]);
            if ((int) $stmt->fetchColumn() !== 1) {
                continue;
            }
            $alloc->execute([$supplierId, $entryId, $type]);
            if ($alloc->fetchColumn() === false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Řádky ručních zápisů na účtech `$accountIds` a straně `$side`, které mají k `$asOf`
     * volnou (nerozepsanou) částku. Vynechá zápis, který je předpisem dokladu (skupina
     * zaúčtování), storno a stornovaný zápis, červené storno, zápis se zápočtem převzatým
     * z jiného programu, řádek se zdvojeným pořadím a zápis, který doklad druhu `$docType`
     * vyrovnává už starou vazbou.
     *
     * `$search` zúží výběr na text nebo číslo zápisu. `$score` (volitelné) přidá sloupce shody s dokladem a řadí podle nich:
     * `amount_match` (volná částka = zbývá), `doc_match` (číslo dokladu v textu zápisu nebo
     * vazba na tento doklad), `partner_match` (vazba na doklad téhož partnera nebo jeho název
     * v textu), pak podle vzdálenosti data od vystavení dokladu.
     *
     * @param list<int> $accountIds
     * @param array{remaining:float, numbers:list<string>, partner_id:int, partner_name:?string,
     *              doc_type:string, doc_id:int, issue_date:string}|null $score
     * @return list<array<string,mixed>>
     */
    public function freeLines(
        int $supplierId,
        array $accountIds,
        ?string $side,
        string $asOf,
        ?string $docType,
        int $limit,
        ?array $score = null,
        ?string $search = null,
    ): array {
        if ($accountIds === []) {
            return [];
        }
        $ids = implode(',', array_map('intval', $accountIds));
        $params = [];
        $sideSql = '';
        if ($side !== null) {
            $sideSql = ' AND l.side = ?';
            $params[] = $side;
        }
        $params[] = $asOf;
        $searchSql = '';
        $search = trim((string) $search);
        if ($search !== '') {
            $searchSql = " AND (e.description LIKE ? ESCAPE '!' OR e.document_no LIKE ? ESCAPE '!')";
            $params[] = '%' . self::likeEscape(mb_substr($search, 0, 100)) . '%';
            $params[] = '%' . self::likeEscape(mb_substr($search, 0, 100)) . '%';
        }
        $linkTypes = $docType === null ? ['invoice', 'purchase_invoice'] : [$docType];
        $countedLink = [];
        foreach ($linkTypes as $type) {
            $countedLink[] = "NOT ((SELECT COUNT(*) FROM journal_entry_document_links cdl
                                    WHERE cdl.supplier_id = e.supplier_id AND cdl.entry_id = e.id AND cdl.doc_type = '{$type}') = 1
                                   AND " . JournalSettlementAllocationSql::noAllocationSql('e.supplier_id', 'e.id', "'{$type}'") . ')';
        }
        $sources = "'" . implode("','", self::ALLOCATABLE_SOURCES) . "'";
        $free = 'ROUND(l.amount - COALESCE(u.used, 0), 2)';

        $scoreSelect = '0 AS amount_match, 0 AS doc_match, 0 AS partner_match';
        $order = 'e.entry_date DESC, l.entry_id DESC, l.line_no';
        if ($score !== null) {
            $docLike = [];
            foreach ($score['numbers'] as $number) {
                $docLike[] = "e.description LIKE ? ESCAPE '!' OR e.document_no = ?";
            }
            $docPredicate = $docLike === [] ? '0' : '(' . implode(' OR ', $docLike) . ')';
            $partnerTable = $score['doc_type'] === 'invoice' ? 'invoices' : 'purchase_invoices';
            $partnerColumn = $score['doc_type'] === 'invoice' ? 'client_id' : 'vendor_id';
            $partnerName = trim((string) ($score['partner_name'] ?? ''));
            $nameLike = mb_strlen($partnerName) >= 4 ? " OR e.description LIKE ? ESCAPE '!'" : '';
            $scoreSelect = "CASE WHEN ABS({$free} - ?) < 0.005 THEN 1 ELSE 0 END AS amount_match,
                            CASE WHEN {$docPredicate} OR EXISTS (
                                     SELECT 1 FROM journal_entry_document_links sdl
                                      WHERE sdl.supplier_id = e.supplier_id AND sdl.entry_id = e.id
                                        AND sdl.doc_type = ? AND sdl.doc_id = ?) THEN 1 ELSE 0 END AS doc_match,
                            CASE WHEN EXISTS (
                                     SELECT 1 FROM journal_entry_document_links pdl
                                       JOIN {$partnerTable} pd ON pd.id = pdl.doc_id AND pd.supplier_id = pdl.supplier_id
                                      WHERE pdl.supplier_id = e.supplier_id AND pdl.entry_id = e.id
                                        AND pdl.doc_type = ? AND pd.{$partnerColumn} = ?){$nameLike} THEN 1 ELSE 0 END AS partner_match";
            $scoreParams = [$score['remaining']];
            foreach ($score['numbers'] as $number) {
                $scoreParams[] = '%' . self::likeEscape($number) . '%';
                $scoreParams[] = $number;
            }
            array_push($scoreParams, $score['doc_type'], $score['doc_id'], $score['doc_type'], $score['partner_id']);
            if ($nameLike !== '') {
                $scoreParams[] = '%' . self::likeEscape($partnerName) . '%';
            }
            $params = array_merge($scoreParams, $params, [$score['issue_date']]);
            $order = 'amount_match DESC, doc_match DESC, partner_match DESC, ABS(DATEDIFF(e.entry_date, ?)), l.entry_id, l.line_no';
        }

        $sql = "SELECT l.id AS line_id, l.entry_id, l.line_no, l.account_id, l.side, ca.account_code,
                       e.entry_date, e.document_no, e.description, l.amount AS line_amount,
                       {$free} AS free_amount,
                       (SELECT GROUP_CONCAT(CONCAT(ldl.doc_type, ':', ldl.doc_id) ORDER BY ldl.id SEPARATOR ',')
                          FROM journal_entry_document_links ldl
                         WHERE ldl.supplier_id = e.supplier_id AND ldl.entry_id = e.id
                           AND ldl.doc_type IN ('invoice', 'purchase_invoice')) AS links,
                       {$scoreSelect}
                  FROM journal_entry_lines l
                  JOIN journal_entries e ON e.id = l.entry_id AND e.supplier_id = l.supplier_id
                  JOIN chart_of_accounts ca ON ca.id = l.account_id AND ca.supplier_id = l.supplier_id
                  LEFT JOIN (" . JournalSettlementAllocationSql::usedByLineSql($supplierId, $accountIds) . ") u
                         ON u.entry_id = l.entry_id AND u.line_no = l.line_no AND u.account_id = l.account_id AND u.side = l.side
                 WHERE l.supplier_id = {$supplierId} AND l.account_id IN ({$ids}){$sideSql}
                   AND l.is_red_storno = 0
                   AND e.supplier_id = {$supplierId} AND e.source_type IN ({$sources})
                   AND e.posted_at IS NOT NULL AND e.reversed_by IS NULL
                   AND e.entry_date <= ?{$searchSql}
                   AND NOT EXISTS (SELECT 1 FROM journal_document_postings jdp
                                    WHERE jdp.supplier_id = e.supplier_id AND jdp.entry_id = e.id)
                   AND NOT EXISTS (SELECT 1 FROM journal_entries o
                                    WHERE o.supplier_id = e.supplier_id AND o.reversed_by = e.id)
                   AND " . JournalSettlementAllocationSql::noImportedSettlementSql('e.supplier_id', 'e.id') . "
                   AND NOT EXISTS (SELECT 1 FROM journal_entry_lines dup
                                    WHERE dup.supplier_id = l.supplier_id AND dup.entry_id = l.entry_id
                                      AND dup.line_no = l.line_no AND dup.id <> l.id)
                   AND " . implode(' AND ', $countedLink) . "
                   AND {$free} >= 0.005
                 ORDER BY {$order}
                 LIMIT " . max(1, $limit);
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return array_map(static fn (array $r): array => [
            'line_id'       => (int) $r['line_id'],
            'entry_id'      => (int) $r['entry_id'],
            'line_no'       => (int) $r['line_no'],
            'account_id'    => (int) $r['account_id'],
            'account_code'  => (string) $r['account_code'],
            'side'          => (string) $r['side'],
            'entry_date'    => (string) $r['entry_date'],
            'document_no'   => $r['document_no'] !== null ? (string) $r['document_no'] : null,
            'description'   => $r['description'] !== null ? (string) $r['description'] : null,
            'line_amount'   => round((float) $r['line_amount'], 2),
            'free_amount'   => round((float) $r['free_amount'], 2),
            'links'         => self::parseLinks($r['links'] !== null ? (string) $r['links'] : ''),
            'amount_match'  => (int) $r['amount_match'] === 1,
            'doc_match'     => (int) $r['doc_match'] === 1,
            'partner_match' => (int) $r['partner_match'] === 1,
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Doklad firmy pro rozpad (zamyká ho {@see lockForAllocation()}). NULL = doklad u TÉTO
     * firmy neexistuje (cizí a neexistující se nerozlišují).
     *
     * @return array{doc_type:string, doc_id:int, partner_id:int, partner_name:string, currency_code:string,
     *               status:string, issue_date:string, numbers:list<string>, doc_no:string}|null
     */
    public function document(int $supplierId, string $docType, int $docId): ?array
    {
        $sql = match ($docType) {
            'invoice' => "SELECT d.id, d.client_id AS partner_id, cl.company_name AS partner_name, cur.code AS currency_code,
                                 d.status, d.issue_date, d.varsymbol, NULL AS vendor_invoice_number
                            FROM invoices d
                            JOIN clients cl ON cl.id = d.client_id
                            JOIN currencies cur ON cur.id = d.currency_id
                           WHERE d.id = ? AND d.supplier_id = ?",
            'purchase_invoice' => "SELECT d.id, d.vendor_id AS partner_id, cl.company_name AS partner_name, cur.code AS currency_code,
                                          d.status, d.issue_date, d.varsymbol, d.vendor_invoice_number
                                     FROM purchase_invoices d
                                     JOIN clients cl ON cl.id = d.vendor_id
                                     JOIN currencies cur ON cur.id = d.currency_id
                                    WHERE d.id = ? AND d.supplier_id = ?",
            default => null,
        };
        if ($sql === null || $docId <= 0) {
            return null;
        }
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$docId, $supplierId]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($r === false) {
            return null;
        }
        $numbers = array_values(array_unique(array_filter([
            trim((string) ($r['varsymbol'] ?? '')),
            trim((string) ($r['vendor_invoice_number'] ?? '')),
        ], [self::class, 'isDocumentNumber'])));
        return [
            'doc_type'      => $docType,
            'doc_id'        => (int) $r['id'],
            'partner_id'    => (int) $r['partner_id'],
            'partner_name'  => (string) $r['partner_name'],
            'currency_code' => strtoupper((string) $r['currency_code']),
            'status'        => (string) $r['status'],
            'issue_date'    => (string) $r['issue_date'],
            'numbers'       => $numbers,
            'doc_no'        => trim((string) ($r['varsymbol'] ?? '')) !== '' ? (string) $r['varsymbol'] : '#' . (int) $r['id'],
        ];
    }

    /**
     * Čísla dokladů pro návrh párování (variabilní symbol, u přijatých i číslo dodavatele).
     *
     * @param list<int> $ids
     * @return array<int,list<string>>
     */
    public function documentNumbers(int $supplierId, string $docType, array $ids): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique(array_map('intval', $ids))), 1000) as $chunk) {
            $in = implode(',', $chunk);
            $sql = $docType === 'invoice'
                ? "SELECT id, varsymbol, NULL AS vendor_invoice_number FROM invoices WHERE supplier_id = ? AND id IN ({$in})"
                : "SELECT id, varsymbol, vendor_invoice_number FROM purchase_invoices WHERE supplier_id = ? AND id IN ({$in})";
            $stmt = $this->db->pdo()->prepare($sql);
            $stmt->execute([$supplierId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[(int) $r['id']] = array_values(array_unique(array_filter([
                    trim((string) ($r['varsymbol'] ?? '')),
                    trim((string) ($r['vendor_invoice_number'] ?? '')),
                ], [self::class, 'isDocumentNumber'])));
            }
        }
        return $out;
    }

    /**
     * Opakovaný rozpad téhož řádku (stejné pořadí, účet i strana) na tentýž doklad se
     * přičte k původnímu. Klíč nese účet i stranu, takže se nikdy nepřičte k rozpadu,
     * kterému řádek mezitím přestal odpovídat.
     */
    public function insert(int $supplierId, array $line, string $kind, string $docType, int $docId, float $amount, ?int $userId): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO journal_settlement_allocations
                (supplier_id, entry_id, line_no, account_id, side, kind, doc_type, doc_id, amount, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), amount = amount + VALUES(amount)'
        )->execute([$supplierId, $line['entry_id'], $line['line_no'], $line['account_id'], $line['side'], $kind, $docType, $docId, round($amount, 2), $userId]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * Rozpady zápisů (zamčené), pro hlídání přeúčtování a mazání zápisu.
     *
     * @param list<int> $entryIds
     * @return list<array{id:int, entry_id:int, line_no:int, account_id:int, side:string, kind:string, amount:float, released_on:?string}>
     */
    public function forEntries(int $supplierId, array $entryIds): array
    {
        $ids = self::ids($entryIds);
        if ($ids === []) {
            return [];
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, entry_id, line_no, account_id, side, kind, amount, released_on
               FROM journal_settlement_allocations
              WHERE supplier_id = ? AND entry_id IN (' . implode(',', $ids) . ') ORDER BY id FOR UPDATE'
        );
        $stmt->execute([$supplierId]);
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'], 'entry_id' => (int) $r['entry_id'], 'line_no' => (int) $r['line_no'],
            'account_id' => (int) $r['account_id'], 'side' => (string) $r['side'], 'kind' => (string) $r['kind'],
            'amount' => round((float) $r['amount'], 2), 'released_on' => $r['released_on'] !== null ? (string) $r['released_on'] : null,
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Předpis ostatní položky ze zůstatku, který ještě platí (zamčený). */
    public function activeOrigin(int $supplierId, int $otherItemId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, entry_id FROM journal_settlement_allocations
              WHERE supplier_id = ? AND doc_type = 'other_item' AND doc_id = ? AND kind = 'origin' AND released_on IS NULL
              ORDER BY id LIMIT 1 FOR UPDATE"
        );
        $stmt->execute([$supplierId, $otherItemId]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        return $r === false ? null : ['id' => (int) $r['id'], 'entry_id' => (int) $r['entry_id']];
    }

    /** Zruší předpis ostatní položky ze zůstatku od data storna položky. */
    public function releaseOrigin(int $supplierId, int $otherItemId, string $releasedOn): void
    {
        $this->db->pdo()->prepare(
            "UPDATE journal_settlement_allocations SET released_on = ?
              WHERE supplier_id = ? AND doc_type = 'other_item' AND doc_id = ? AND kind = 'origin' AND released_on IS NULL"
        )->execute([$releasedOn, $supplierId, $otherItemId]);
    }

    /**
     * Číslo dokladu, které má smysl hledat v textu zápisu: aspoň čtyři znaky s písmenem
     * i číslicí, čistě číselné aspoň pět. Krátký variabilní symbol nebo `#5` by se našel
     * v datu či částce a dal by falešnou shodu.
     */
    public static function isDocumentNumber(string $number): bool
    {
        $n = preg_replace('/[^\p{L}\p{N}]+/u', '', $number) ?? '';
        if (str_starts_with(trim($number), '#')) {
            return false;
        }
        $len = mb_strlen($n);
        if (preg_match('/^\p{N}+$/u', $n) === 1) {
            return $len >= 5;
        }
        return $len >= 4 && preg_match('/\p{N}/u', $n) === 1 && preg_match('/\p{L}/u', $n) === 1;
    }

    /** @return array<string,mixed>|null */
    public function find(int $supplierId, int $id, bool $lock = false): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, entry_id, line_no, account_id, side, kind, doc_type, doc_id, amount, released_on, created_by, created_at
               FROM journal_settlement_allocations WHERE id = ? AND supplier_id = ?' . ($lock ? ' FOR UPDATE' : '')
        );
        $stmt->execute([$id, $supplierId]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        return $r === false ? null : self::cast($r);
    }

    public function delete(int $supplierId, int $id): bool
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM journal_settlement_allocations WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$id, $supplierId]);
        return $stmt->rowCount() > 0;
    }

    /** Stav účetního období zápisu (pro hlídání uzavřeného období). */
    public function entryPeriodStatus(int $supplierId, int $entryId): ?string
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ap.status FROM journal_entries e
               JOIN accounting_periods ap ON ap.id = e.period_id AND ap.supplier_id = e.supplier_id
              WHERE e.id = ? AND e.supplier_id = ?'
        );
        $stmt->execute([$entryId, $supplierId]);
        $status = $stmt->fetchColumn();
        return $status === false ? null : (string) $status;
    }

    /**
     * Rozpady dokladu s údaji zápisu (seznam v dialogu vyrovnání).
     *
     * @return list<array<string,mixed>>
     */
    public function listForDocument(int $supplierId, string $docType, int $docId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT ja.id, ja.entry_id, ja.line_no, ja.account_id, ja.side, ja.kind, ja.doc_type, ja.doc_id, ja.amount,
                    ja.released_on, ja.created_by, ja.created_at, ca.account_code, e.entry_date, e.document_no, e.description,
                    l.id AS line_id
               FROM journal_settlement_allocations ja
               JOIN journal_entries e ON e.id = ja.entry_id AND e.supplier_id = ja.supplier_id
               JOIN chart_of_accounts ca ON ca.id = ja.account_id AND ca.supplier_id = ja.supplier_id
               LEFT JOIN journal_entry_lines l ON l.supplier_id = ja.supplier_id AND l.entry_id = ja.entry_id
                    AND l.line_no = ja.line_no AND l.account_id = ja.account_id AND l.side = ja.side
              WHERE ja.supplier_id = ? AND ja.doc_type = ? AND ja.doc_id = ?
              ORDER BY e.entry_date, ja.id"
        );
        $stmt->execute([$supplierId, $docType, $docId]);
        return array_map(static fn (array $r): array => [
            ...self::cast($r),
            'account_code' => (string) $r['account_code'],
            'entry_date'   => (string) $r['entry_date'],
            'document_no'  => $r['document_no'] !== null ? (string) $r['document_no'] : null,
            'description'  => $r['description'] !== null ? (string) $r['description'] : null,
            'line_id'      => $r['line_id'] !== null ? (int) $r['line_id'] : null,
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string,mixed> */
    private static function cast(array $r): array
    {
        return [
            'id'         => (int) $r['id'],
            'entry_id'   => (int) $r['entry_id'],
            'line_no'    => (int) $r['line_no'],
            'account_id' => (int) $r['account_id'],
            'side'       => (string) $r['side'],
            'kind'       => (string) $r['kind'],
            'doc_type'   => (string) $r['doc_type'],
            'doc_id'     => (int) $r['doc_id'],
            'amount'     => round((float) $r['amount'], 2),
            'released_on' => $r['released_on'] !== null ? (string) $r['released_on'] : null,
            'created_by' => $r['created_by'] !== null ? (int) $r['created_by'] : null,
            'created_at' => (string) $r['created_at'],
        ];
    }

    /** @return list<array{doc_type:string, doc_id:int}> */
    private static function parseLinks(string $raw): array
    {
        $out = [];
        foreach ($raw === '' ? [] : explode(',', $raw) as $pair) {
            [$type, $id] = array_pad(explode(':', $pair, 2), 2, '0');
            if (in_array($type, ['invoice', 'purchase_invoice'], true) && (int) $id > 0) {
                $out[] = ['doc_type' => $type, 'doc_id' => (int) $id];
            }
        }
        return $out;
    }

    /** @return list<int> kladná id bez duplicit, vzestupně */
    private static function ids(array $ids): array
    {
        $out = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        sort($out);
        return $out;
    }

    private static function likeEscape(string $value): string
    {
        return strtr($value, ['!' => '!!', '%' => '!%', '_' => '!_']);
    }
}
