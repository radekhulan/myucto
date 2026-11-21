<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\OtherItemInstallmentCoverage;
use MyInvoice\Service\Accounting\OtherItemSort;
use PDO;

final class OtherItemRepository
{
    public function __construct(private readonly Connection $db) {}

    public function find(int $supplierId, int $id, bool $lock = false): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT oi.*, ' . self::PAID_SQL . ' + oi.settled_outside_amount AS paid_amount,
                    oi.amount - oi.settled_outside_amount - ' . self::PAID_SQL . ' AS remaining_amount
               FROM other_items oi
              WHERE oi.supplier_id = ? AND oi.id = ? AND oi.deleted_at IS NULL' . ($lock ? ' FOR UPDATE' : '')
        );
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) return null;
        // Název druhu zvlášť a bez zámku: JOIN v zamykacím čtení by zamkl i řádek druhu
        // a serializoval všechny položky stejného druhu (i s úpravou druhu v Nástrojích).
        $row['kind_name'] = null;
        if ($row['kind_id'] !== null) {
            $kind = $this->db->pdo()->prepare('SELECT name FROM other_item_kinds WHERE id = ? AND supplier_id = ?');
            $kind->execute([(int) $row['kind_id'], $supplierId]);
            $row['kind_name'] = $kind->fetchColumn() ?: null;
        }
        $row['posting_lines'] = $this->postingLines($supplierId, $id);
        if ($row['posting_lines'] === [] && $row['counter_account_code'] !== null) {
            $row['posting_lines'] = [[
                'account_code' => (string) $row['counter_account_code'],
                'amount' => (float) $row['amount_czk'],
            ]];
        }
        return $row;
    }

    public function postingLines(int $supplierId, int $id): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT account_code, amount FROM other_item_posting_lines
            WHERE supplier_id = ? AND other_item_id = ? ORDER BY position');
        $stmt->execute([$supplierId, $id]);
        return array_map(static fn (array $row): array => [
            'account_code' => (string) $row['account_code'], 'amount' => (float) $row['amount'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function replacePostingLines(int $supplierId, int $id, array $lines): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('DELETE FROM other_item_posting_lines WHERE supplier_id = ? AND other_item_id = ?')
            ->execute([$supplierId, $id]);
        $stmt = $pdo->prepare('INSERT INTO other_item_posting_lines
            (supplier_id, other_item_id, position, account_code, amount) VALUES (?,?,?,?,?)');
        foreach ($lines as $position => $line) {
            $stmt->execute([$supplierId, $id, $position + 1, $line['account_code'], $line['amount']]);
        }
    }

    /**
     * Spárované úhrady jedné položky (bez storen) po indexu uq_other_item_bank; sdílí je detail
     * (find) i výpis, takže stav položky je v obou stejný. Úhrady se čtou jen pro položky ve výběru
     * (okno splatnosti), ne jedním souhrnem přes všechny úhrady firmy: ten by s historií rostl,
     * i když výběr zůstává stejný (měření v PR).
     */
    private const PAID_SQL = '(SELECT COALESCE(SUM(a.amount), 0) FROM other_item_allocations a
        WHERE a.supplier_id = oi.supplier_id AND a.other_item_id = oi.id AND a.reversed_on IS NULL)';

    /**
     * Otevřená = ještě je co zaplatit: zbytek jistiny, nebo u splátek rozdělených na jistinu, úrok
     * a poplatek nezaplacený úrok či poplatek (stejně jako stav ve výpisu a v detailu podle
     * OtherItemService::payableOf). Úhrada nese úrok a poplatek nejvýš do výše plánovaných,
     * takže rozdíl součtů je přesně zbývající úrok a poplatek splátek.
     */
    private const OPEN_PAYMENT_SQL = '(oi.amount - oi.settled_outside_amount > ' . self::PAID_SQL . '
        OR (SELECT COALESCE(SUM(COALESCE(ins.interest_amount, 0) + COALESCE(ins.fee_amount, 0)), 0) FROM other_item_installments ins
             WHERE ins.supplier_id = oi.supplier_id AND ins.other_item_id = oi.id)
         > (SELECT COALESCE(SUM(a.interest_amount + a.fee_amount), 0) FROM other_item_allocations a
             WHERE a.supplier_id = oi.supplier_id AND a.other_item_id = oi.id AND a.reversed_on IS NULL))';

    /**
     * Výběr ručních položek výpisu: tabulka (s indexem okna splatnosti), WHERE a parametry.
     * Jediné místo podmínek filtru; sdílí ho výpis, jeho součty a kniha ({@see bookBatch()}).
     *
     * @param array<string,mixed> $filters výstup {@see \MyInvoice\Http\OtherItemListFilters}
     * @return array{0:string, 1:string, 2:list<mixed>}
     */
    private function listWhere(int $supplierId, array $filters): array
    {
        $where = ['oi.supplier_id = ?', 'oi.deleted_at IS NULL'];
        $params = [$supplierId];
        if (!empty($filters['side'])) {
            $where[] = 'oi.side = ?';
            $params[] = $filters['side'];
        }
        // Vlastní druh se filtruje podle kind_id; vestavěný podle kind bez vlastního druhu,
        // jinak by „other“ vracel i položky vlastních druhů (ty nesou kind = other).
        if (!empty($filters['kind_id'])) {
            $where[] = 'oi.kind_id = ?';
            $params[] = (int) $filters['kind_id'];
        } elseif (!empty($filters['kind'])) {
            $where[] = 'oi.kind = ? AND oi.kind_id IS NULL';
            $params[] = $filters['kind'];
        }
        if (!empty($filters['status']) && $filters['status'] !== 'all') {
            if ($filters['status'] === 'open') {
                $where[] = "oi.status IN ('draft','confirmed','posted')";
                $where[] = self::OPEN_PAYMENT_SQL;
            } else {
                $where[] = 'oi.status = ?';
                $params[] = $filters['status'];
            }
        }
        if (!empty($filters['q'])) {
            $where[] = '(oi.title LIKE ? OR oi.partner_name LIKE ? OR oi.document_no LIKE ? OR oi.variable_symbol LIKE ?)';
            $term = '%' . $filters['q'] . '%';
            array_push($params, $term, $term, $term, $term);
        }
        if (!empty($filters['from'])) {
            $where[] = 'oi.due_on >= ?';
            $params[] = $filters['from'];
        }
        if (!empty($filters['to'])) {
            $where[] = 'oi.due_on <= ?';
            $params[] = $filters['to'];
        }
        $whereSql = implode(' AND ', $where);
        // S oknem splatnosti optimalizátor podíl okna přeceňuje a volí sken celé tabulky (všech firem),
        // i když index splatnosti čte jen výběr (měření v PR). Otevřené bez dolní meze čtou po indexu
        // historii firmy do konce okna: uhrazenost se neukládá, index ji přeskočit nemůže.
        $table = 'other_items oi' . (!empty($filters['from']) || !empty($filters['to']) ? ' FORCE INDEX (idx_other_item_due)' : '');
        return [$table, $whereSql, $params];
    }

    public function list(int $supplierId, array $filters, int $page, int $perPage): array
    {
        [$table, $whereSql, $params] = $this->listWhere($supplierId, $filters);
        $count = $this->db->pdo()->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $whereSql);
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $offset = ($page - 1) * $perPage;
        $stmt = $this->db->pdo()->prepare(
            'SELECT oi.*, ' . self::PAID_SQL . ' + oi.settled_outside_amount paid_amount,
                    oi.amount - oi.settled_outside_amount - ' . self::PAID_SQL . ' remaining_amount,
                    k.name kind_name
               FROM ' . $table . '
               LEFT JOIN other_item_kinds k ON k.id = oi.kind_id AND k.supplier_id = oi.supplier_id
              WHERE ' . $whereSql . '
              ORDER BY ' . OtherItemSort::orderBy($filters) . ' LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset
        );
        $stmt->execute($params);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $counts = $this->documentCounts($supplierId, array_map(static fn (array $row): int => (int) $row['id'], $items));
        foreach ($items as &$item) {
            $item['document_count'] = $counts[(int) $item['id']] ?? 0;
        }
        unset($item);
        $result = ['items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
        if ($page === 1) {
            $result['totals'] = $this->remainingTotals($table, $whereSql, $params);
        }
        return $result;
    }

    /**
     * Počet připojených dokumentů pro řádky stránky jedním dotazem (index document_links
     * supplier_id, entity_type, entity_id), vzor InternalDocumentRepository.
     *
     * @param list<int> $ids
     * @return array<int,int>
     */
    private function documentCounts(int $supplierId, array $ids): array
    {
        if ($ids === []) return [];
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT dl.entity_id, COUNT(*)
               FROM document_links dl
              WHERE dl.supplier_id = ? AND dl.entity_type = 'other_item' AND dl.entity_id IN ({$in})
              GROUP BY dl.entity_id"
        );
        $stmt->execute([$supplierId, ...$ids]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
    }

    /**
     * Zbývá uhradit za celý výběr (ne jen načtenou stránku), po straně a měně: pohledávky
     * a závazky ani dvě měny se nesčítají. Jen potvrzené a zaúčtované položky — koncept
     * ještě dluhem není, storno už ne. Přeplatek jedné položky nesnižuje dluh jiné (záporný zůstatek se počítá jako nula).
     *
     * @param list<mixed> $params
     * @return list<array{side:string,currency:string,remaining_amount:float}>
     */
    private function remainingTotals(string $table, string $whereSql, array $params): array
    {
        return array_map(static fn (array $row): array => [
            'side' => $row['side'],
            'currency' => $row['currency'],
            'remaining_amount' => $row['remaining_amount'],
        ], $this->selectionTotals($table, $whereSql, $params));
    }

    /**
     * Součty knihy za celý výběr výpisu: stejné pravidlo jako součty výpisu ({@see remainingTotals()}),
     * navíc počet, částka a uhrazeno (včetně vyrovnaného mimo úhrady).
     *
     * @param array<string,mixed> $filters výstup {@see \MyInvoice\Http\OtherItemListFilters}
     * @return list<array{side:string,currency:string,count:int,amount:float,paid_amount:float,remaining_amount:float}>
     */
    public function bookTotals(int $supplierId, array $filters): array
    {
        [$table, $whereSql, $params] = $this->listWhere($supplierId, $filters);
        return $this->selectionTotals($table, $whereSql, $params);
    }

    /**
     * @param list<mixed> $params
     * @return list<array{side:string,currency:string,count:int,amount:float,paid_amount:float,remaining_amount:float}>
     */
    private function selectionTotals(string $table, string $whereSql, array $params): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT oi.side, oi.currency, COUNT(*) item_count, SUM(oi.amount) amount,
                    SUM(oi.settled_outside_amount + " . self::PAID_SQL . ") paid_amount,
                    SUM(GREATEST(oi.amount - oi.settled_outside_amount - " . self::PAID_SQL . ", 0)) remaining_amount
               FROM " . $table . "
              WHERE " . $whereSql . " AND oi.status IN ('confirmed','posted')
              GROUP BY oi.side, oi.currency
              ORDER BY oi.side, oi.currency"
        );
        $stmt->execute($params);
        return array_map(static fn (array $row): array => [
            'side' => (string) $row['side'],
            'currency' => (string) $row['currency'],
            'count' => (int) $row['item_count'],
            'amount' => round((float) $row['amount'], 2),
            'paid_amount' => round((float) $row['paid_amount'], 2),
            'remaining_amount' => round((float) $row['remaining_amount'], 2),
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Dávka řádků knihy nad výběrem výpisu ({@see listWhere()}), bez stránkování: klíč
     * (splatnost, id) místo OFFSET, takže každá dávka čte jen svůj úsek indexu splatnosti
     * (InnoDB nese id v sekundárním indexu, řazení tedy obejde filesort).
     *
     * @param array<string,mixed> $filters výstup {@see \MyInvoice\Http\OtherItemListFilters}
     * @param array{0:string,1:int}|null $after splatnost a id posledního řádku předchozí dávky
     * @return list<array<string,mixed>>
     */
    public function bookBatch(int $supplierId, array $filters, ?array $after, int $limit): array
    {
        [, $whereSql, $params] = $this->listWhere($supplierId, $filters);
        if ($after !== null) {
            $whereSql .= ' AND (oi.due_on > ? OR (oi.due_on = ? AND oi.id > ?))';
            array_push($params, $after[0], $after[0], $after[1]);
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT oi.id, oi.side, oi.kind, oi.kind_id, k.name kind_name, oi.title, oi.partner_name,
                    oi.document_no, oi.variable_symbol, oi.issued_on, oi.accounting_on, oi.due_on,
                    oi.currency, oi.amount, oi.status, oi.journal_entry_id, oi.posted_at,
                    ' . self::PAID_SQL . ' + oi.settled_outside_amount paid_amount,
                    oi.amount - oi.settled_outside_amount - ' . self::PAID_SQL . ' remaining_amount
               FROM other_items oi FORCE INDEX (idx_other_item_due)
               LEFT JOIN other_item_kinds k ON k.id = oi.kind_id AND k.supplier_id = oi.supplier_id
              WHERE ' . $whereSql . '
              ORDER BY oi.due_on, oi.id
              LIMIT ' . max(1, $limit)
        );
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function insert(int $supplierId, array $data, ?int $userId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO other_items
              (supplier_id, side, kind, kind_id, title, partner_id, partner_name, issued_on, accounting_on,
               due_on, currency, amount, exchange_rate, amount_czk, variable_symbol,
               counterparty_account, counterparty_bank_code,
               account_code, counter_account_code, note, created_by, updated_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            $supplierId, $data['side'], $data['kind'], $data['kind_id'] ?? null, $data['title'], $data['partner_id'],
            $data['partner_name'], $data['issued_on'], $data['accounting_on'], $data['due_on'],
            $data['currency'], $data['amount'], $data['exchange_rate'], $data['amount_czk'],
            $data['variable_symbol'], $data['counterparty_account'] ?? null, $data['counterparty_bank_code'] ?? null,
            $data['account_code'], $data['counter_account_code'],
            $data['note'], $userId, $userId,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    public function updateDraft(int $supplierId, int $id, array $data, ?int $userId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE other_items SET side = ?, kind = ?, kind_id = ?, title = ?, partner_id = ?, partner_name = ?,
                    issued_on = ?, accounting_on = ?, due_on = ?, currency = ?, amount = ?,
                    exchange_rate = ?, amount_czk = ?, variable_symbol = ?, counterparty_account = ?,
                    counterparty_bank_code = ?, account_code = ?,
                    counter_account_code = ?, note = ?, updated_by = ?, row_version = row_version + 1
              WHERE id = ? AND supplier_id = ? AND status = \'draft\' AND deleted_at IS NULL'
        );
        $stmt->execute([
            $data['side'], $data['kind'], $data['kind_id'] ?? null, $data['title'], $data['partner_id'], $data['partner_name'],
            $data['issued_on'], $data['accounting_on'], $data['due_on'], $data['currency'], $data['amount'],
            $data['exchange_rate'], $data['amount_czk'], $data['variable_symbol'],
            $data['counterparty_account'] ?? null, $data['counterparty_bank_code'] ?? null, $data['account_code'],
            $data['counter_account_code'], $data['note'], $userId, $id, $supplierId,
        ]);
    }

    public function softDeleteDraft(int $supplierId, int $id): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE other_items SET deleted_at = NOW(), row_version = row_version + 1
              WHERE id = ? AND supplier_id = ? AND status = \'draft\' AND deleted_at IS NULL'
        );
        $stmt->execute([$id, $supplierId]);
    }

    public function cancelDraft(int $supplierId, int $id): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE other_items SET status = \'cancelled\', row_version = row_version + 1
              WHERE id = ? AND supplier_id = ? AND status = \'draft\' AND deleted_at IS NULL'
        );
        $stmt->execute([$id, $supplierId]);
    }

    public function setPosted(int $supplierId, int $id, string $status, string $number, ?int $entryId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE other_items SET status = ?, document_no = ?, journal_entry_id = ?, posted_at = NOW(),
                    row_version = row_version + 1 WHERE id = ? AND supplier_id = ? AND status = \'draft\''
        );
        $stmt->execute([$status, $number, $entryId, $id, $supplierId]);
    }

    /** Účet a protiúčet doplněné při zaúčtování z účetního profilu druhu. */
    public function storePostedAccounts(int $supplierId, int $id, string $accountCode, ?string $counterCode): void
    {
        $this->db->pdo()->prepare(
            'UPDATE other_items SET account_code = ?, counter_account_code = ? WHERE id = ? AND supplier_id = ?'
        )->execute([$accountCode, $counterCode, $id, $supplierId]);
    }

    public function setUnposted(int $supplierId, int $id, ?int $userId): void
    {
        $this->db->pdo()->prepare(
            "UPDATE other_items SET status = 'draft', journal_entry_id = NULL, reversal_entry_id = NULL,
                    posted_at = NULL, updated_by = ?, row_version = row_version + 1
              WHERE id = ? AND supplier_id = ? AND status = 'posted' AND deleted_at IS NULL"
        )->execute([$userId, $id, $supplierId]);
    }

    public function setReversed(int $supplierId, int $id, string $status, ?int $entryId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE other_items SET status = ?, reversal_entry_id = ?, row_version = row_version + 1
              WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$status, $entryId, $id, $supplierId]);
    }

    public function setReposted(int $supplierId, int $id, string $accountCode, ?string $counterCode,
        string $accountingOn, int $entryId, int $reversalId, ?int $userId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE other_items SET account_code = ?, counter_account_code = ?, accounting_on = ?,
                    journal_entry_id = ?, reversal_entry_id = ?, updated_by = ?, row_version = row_version + 1
              WHERE id = ? AND supplier_id = ? AND status = \'posted\' AND deleted_at IS NULL'
        );
        $stmt->execute([$accountCode, $counterCode, $accountingOn, $entryId, $reversalId,
            $userId, $id, $supplierId]);
    }

    /**
     * Stav splátek položek ({@see OtherItemInstallmentCoverage::compute}) třemi dotazy bez
     * ohledu na počet položek. Položka bez splátek v mapě chybí.
     *
     * @param list<int> $itemIds
     * @param bool $lock zamknout aktivní úhrady (přiřazení úhrady pod zámkem položky)
     * @return array<int, list<array<string,mixed>>>
     */
    public function installmentCoverage(int $supplierId, array $itemIds, ?string $today = null, bool $lock = false): array
    {
        $itemIds = array_values(array_unique(array_map('intval', $itemIds)));
        if ($itemIds === []) return [];
        $in = implode(',', array_fill(0, count($itemIds), '?'));
        $pdo = $this->db->pdo();
        $installments = $pdo->prepare("SELECT id, other_item_id, position, due_on, amount,
                principal_amount, interest_amount, fee_amount FROM other_item_installments
            WHERE supplier_id = ? AND other_item_id IN ({$in}) ORDER BY other_item_id, position");
        $installments->execute([$supplierId, ...$itemIds]);
        $plan = [];
        foreach ($installments->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $plan[(int) $row['other_item_id']][] = $row;
        }
        if ($plan === []) return [];
        $planned = array_keys($plan);
        $in = implode(',', array_fill(0, count($planned), '?'));
        $allocations = $pdo->prepare("SELECT other_item_id, installment_id, amount, interest_amount, fee_amount
            FROM other_item_allocations
            WHERE supplier_id = ? AND other_item_id IN ({$in}) AND reversed_on IS NULL
            ORDER BY other_item_id, payment_on, id" . ($lock ? ' FOR UPDATE' : ''));
        $allocations->execute([$supplierId, ...$planned]);
        $paid = [];
        foreach ($allocations->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $paid[(int) $row['other_item_id']][] = $row;
        }
        $settled = $pdo->prepare("SELECT id, settled_outside_amount FROM other_items WHERE supplier_id = ? AND id IN ({$in})");
        $settled->execute([$supplierId, ...$planned]);
        $outside = $settled->fetchAll(PDO::FETCH_KEY_PAIR);
        $today ??= (new \DateTimeImmutable('today'))->format('Y-m-d');
        $result = [];
        foreach ($plan as $itemId => $rows) {
            $result[$itemId] = OtherItemInstallmentCoverage::compute($rows, $paid[$itemId] ?? [],
                (float) ($outside[$itemId] ?? 0), $today);
        }
        return $result;
    }
}
