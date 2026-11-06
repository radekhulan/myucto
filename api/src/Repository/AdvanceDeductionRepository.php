<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\DocumentPostingGroup;
use PDO;

/**
 * Čerpání zálohy konečnou fakturou po dvojicích záloha × faktura: poskytnuté zálohy na 314
 * (`purchase_advance_deductions`, migrace 2190) a přijaté zálohy na 324
 * (`invoice_advance_deductions`, migrace 2195). Saldokonto čerpání čte v
 * {@see SaldoRepository} (fetchPaidAdvances, fetchOpeningDocuments, fetchInvoicePrepayments,
 * fetchReceivedAdvances), platnost čerpání drží {@see SaldoRepository::advanceDeductionCountsSql()}.
 *
 * Konečná faktura čerpá zálohu stranou zálohového účtu, která zálohu snižuje (přijatá
 * faktura dal 314, vydaná má dáti 324). Část té strany, kterou saldokonto už počítá jiné
 * záloze, k přiřazení není:
 *   - vazba konečné faktury na zálohu (`advance_purchase_invoice_id` na zálohu nebo
 *     samostatný daňový doklad k platbě, `parent_invoice_id` na proformu): saldokonto té
 *     záloze přičte celou stranu faktury a uzavře ji nejvýš na její částku. Přiřazená je
 *     proto strana do výše částky navázané zálohy, přebytek nad ní lze čerpat jinou zálohou,
 *   - každý řádek čerpání (převzatý z převodu i zadaný v saldokontu) svou částku.
 * Přeúčtování mezi analytikami zálohového účtu v témže zápisu (stejná částka na obou
 * stranách) čerpáním není.
 *
 * Všechny dotazy začínají omezením na firmu; tenanta dokladů ověřuje {@see lockDocuments()}
 * a {@see documents()}.
 */
final class AdvanceDeductionRepository
{
    /** Druhy konečných dokladů, které čerpají zálohu. */
    private const FINAL_KINDS = [
        'purchase_invoice' => ['invoice', 'receipt'],
        'invoice'          => ['invoice'],
    ];
    /** Konečná faktura zaúčtovaná kdykoli (i dopředu). */
    public const ANY_DATE = '9999-12-31';
    private const TOLERANCE = 0.005;

    public function __construct(private readonly Connection $db) {}

    public static function table(string $docType): string
    {
        return match ($docType) {
            'purchase_invoice' => 'purchase_advance_deductions',
            'invoice'          => 'invoice_advance_deductions',
            default            => throw new \InvalidArgumentException('Neplatný druh dokladu čerpání zálohy.'),
        };
    }

    /** Strana zálohového účtu, kterou konečná faktura zálohu čerpá. */
    public static function deductionSide(string $docType): string
    {
        return $docType === 'invoice' ? 'debit' : 'credit';
    }

    /**
     * Pojistka ruční vazby záloha → konečná faktura (`linkAdvance` vydané i přijaté strany,
     * vystavení konečné faktury z proformy). Vazba přiřadí zálohovému účtu celou stranu
     * čerpání faktury, takže se nesmí potkat s čerpáním po dvojicích: zálohu, kterou už čerpá
     * některá faktura, i fakturu, která už nějakou zálohu čerpá, vyrovnejte v saldokontu.
     * Doklady zamyká stejně jako {@see lockDocuments()} (FOR UPDATE, vzestupně), takže se
     * souběžné čerpání a vazba seřadí. Volající musí být v transakci.
     *
     * @throws \RuntimeException když se vazba s čerpáním potká
     */
    public static function assertLinkAllowed(PDO $pdo, int $supplierId, string $docType, int $finalId, ?int $advanceId): void
    {
        if (!$pdo->inTransaction()) {
            throw new \LogicException('Kontrola čerpání před vazbou zálohy vyžaduje transakci volajícího.');
        }
        $ids = array_values(array_unique(array_filter([$finalId, (int) $advanceId], static fn (int $id): bool => $id > 0)));
        sort($ids);
        if ($ids === []) {
            return;
        }
        $docTable = self::docTable($docType);
        $pdo->prepare("SELECT id FROM {$docTable} WHERE supplier_id = ? AND id IN (" . implode(',', $ids) . ') ORDER BY id FOR UPDATE')
            ->execute([$supplierId]);
        $table = self::table($docType);
        $stmt = $pdo->prepare(
            "SELECT
                (SELECT COUNT(*) FROM {$table} WHERE supplier_id = ? AND advance_invoice_id = ?) AS advance_rows,
                (SELECT COUNT(*) FROM {$table} WHERE supplier_id = ? AND final_invoice_id = ?) AS final_rows"
        );
        $stmt->execute([$supplierId, (int) $advanceId, $supplierId, $finalId]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['advance_rows' => 0, 'final_rows' => 0];
        if ((int) $r['advance_rows'] > 0) {
            throw new \RuntimeException('Záloha je už čerpaná konečnou fakturou v saldokontu. Zbytek zálohy vyrovnejte tam (Saldokonto, Vyrovnat), vazba by čerpání zdvojila.');
        }
        if ((int) $r['final_rows'] > 0) {
            throw new \RuntimeException('Faktura už čerpá zálohu v saldokontu. Další zálohu jí přiřaďte tam (Saldokonto, Vyrovnat), vazba by čerpání zdvojila.');
        }
    }

    /** Čerpá zálohu nějaká konečná faktura po dvojicích (kterýkoli zdroj)? */
    public static function advanceHasDeductions(PDO $pdo, string $docType, int $advanceId): bool
    {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM ' . self::table($docType) . ' t JOIN ' . self::docTable($docType) . ' a
                ON a.id = t.advance_invoice_id AND a.supplier_id = t.supplier_id
              WHERE t.advance_invoice_id = ? LIMIT 1'
        );
        $stmt->execute([$advanceId]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Zamkne doklady (FOR UPDATE, vzestupně), než se cokoli přečte: souběžná čerpání téže
     * zálohy nebo téže konečné faktury i ruční vazba ({@see assertLinkAllowed()}) se seřadí.
     *
     * @param list<int> $ids
     */
    public function lockDocuments(int $supplierId, string $docType, array $ids): void
    {
        $ids = self::ids($ids);
        if ($ids === []) {
            return;
        }
        $table = self::docTable($docType);
        $this->db->pdo()->prepare("SELECT id FROM {$table} WHERE supplier_id = ? AND id IN (" . implode(',', $ids) . ') ORDER BY id FOR UPDATE')
            ->execute([$supplierId]);
    }

    /**
     * Doklady firmy podle id. Chybějící (cizí i neexistující) ve výsledku nejsou.
     *
     * @param list<int> $ids
     * @return array<int,array{doc_type:string, doc_id:int, kind:string, standalone:bool, partner_id:int, partner_name:string,
     *                         currency_code:string, status:string, issue_date:string, doc_no:string, numbers:list<string>}>
     */
    public function documents(int $supplierId, string $docType, array $ids): array
    {
        $ids = self::ids($ids);
        if ($ids === []) {
            return [];
        }
        $in = implode(',', $ids);
        $sql = $docType === 'invoice'
            ? "SELECT d.id, d.invoice_type AS kind, 1 AS standalone, d.client_id AS partner_id, cl.company_name AS partner_name,
                      cur.code AS currency_code, d.status, d.issue_date, d.varsymbol, NULL AS vendor_invoice_number
                 FROM invoices d
                 JOIN clients cl ON cl.id = d.client_id
                 JOIN currencies cur ON cur.id = d.currency_id
                WHERE d.supplier_id = ? AND d.id IN ({$in})"
            : "SELECT d.id, d.document_kind AS kind, d.parent_purchase_invoice_id IS NULL AS standalone,
                      d.vendor_id AS partner_id, cl.company_name AS partner_name,
                      cur.code AS currency_code, d.status, d.issue_date, d.varsymbol, d.vendor_invoice_number
                 FROM purchase_invoices d
                 JOIN clients cl ON cl.id = d.vendor_id
                 JOIN currencies cur ON cur.id = d.currency_id
                WHERE d.supplier_id = ? AND d.id IN ({$in})";
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$supplierId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $vin = trim((string) ($r['vendor_invoice_number'] ?? ''));
            $vs = trim((string) ($r['varsymbol'] ?? ''));
            $out[(int) $r['id']] = [
                'doc_type'      => $docType,
                'doc_id'        => (int) $r['id'],
                'kind'          => (string) $r['kind'],
                'standalone'    => (int) $r['standalone'] === 1,
                'partner_id'    => (int) $r['partner_id'],
                'partner_name'  => (string) $r['partner_name'],
                'currency_code' => strtoupper((string) $r['currency_code']),
                'status'        => (string) $r['status'],
                'issue_date'    => (string) $r['issue_date'],
                'doc_no'        => $vin !== '' ? $vin : ($vs !== '' ? $vs : '#' . (int) $r['id']),
                'numbers'       => array_values(array_unique(array_filter([$vin, $vs], [JournalSettlementRepository::class, 'isDocumentNumber']))),
            ];
        }
        return $out;
    }

    /**
     * Dny vystavení VŠECH záloh partnerů (i vyrovnaných, nezaplacených a v cizí měně) pro
     * okno návrhu párování: faktura mezi zálohou a další zálohou partnera.
     *
     * @param list<int> $partnerIds
     * @return array<int,list<array{doc_id:int, issue_date:string}>> podle partnera, vzestupně
     */
    public function advanceDates(int $supplierId, string $docType, array $partnerIds): array
    {
        $partnerIds = self::ids($partnerIds);
        if ($partnerIds === []) {
            return [];
        }
        $in = implode(',', $partnerIds);
        $sql = $docType === 'invoice'
            ? "SELECT client_id AS partner_id, id, issue_date FROM invoices
                WHERE supplier_id = ? AND client_id IN ({$in}) AND invoice_type = 'proforma' AND status NOT IN ('draft', 'cancelled')"
            : "SELECT vendor_id AS partner_id, id, issue_date FROM purchase_invoices
                WHERE supplier_id = ? AND vendor_id IN ({$in}) AND status NOT IN ('draft', 'cancelled')
                  AND (document_kind = 'advance' OR (document_kind = 'tax_document' AND parent_purchase_invoice_id IS NULL))";
        $stmt = $this->db->pdo()->prepare($sql . ' ORDER BY issue_date, id');
        $stmt->execute([$supplierId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['partner_id']][] = ['doc_id' => (int) $r['id'], 'issue_date' => (string) $r['issue_date']];
        }
        return $out;
    }

    /**
     * Konečné faktury partnerů `$partnerIds`, které mají k `$asOf` zaúčtovanou stranu
     * čerpání na zálohovém účtu (`$accountIds` = účet a jeho analytiky), s částkou, kterou
     * zatím nečerpá žádná záloha (`unassigned`, záporná = přečerpaná). `posted_on` = den
     * nejstaršího živého zápisu skupiny zaúčtování faktury: od něj saldokonto čerpání
     * počítá, faktura bez něj čerpat nemůže. `texts` = popisy řádků faktury a text zápisu
     * pro hledání čísla zálohy.
     *
     * `$openOnly` vyřadí faktury bez nepřiřazeného odpočtu dřív, než se dočtou zápisy a texty.
     *
     * @param list<int> $accountIds
     * @param list<int>|null $partnerIds null = všichni partneři (jen se zúžením `$finalIds`)
     * @param list<int>|null $finalIds zúžení na konkrétní faktury
     * @return array<int,array{doc_id:int, partner_id:int, doc_no:string, issue_date:string, posted_on:?string,
     *                         posted_entry_id:?int, period_status:?string, deductible:float, linked:bool,
     *                         linked_share:float, deducted:float, unassigned:float, texts:list<string>}>
     */
    public function finals(int $supplierId, string $docType, array $accountIds, ?array $partnerIds, string $asOf, ?array $finalIds = null, bool $openOnly = false): array
    {
        $accountIds = self::ids($accountIds);
        if ($accountIds === [] || $partnerIds === [] || $finalIds === [] || ($partnerIds === null && $finalIds === null)) {
            return [];
        }
        $isInvoice = $docType === 'invoice';
        $table = self::docTable($docType);
        $partnerCol = $isInvoice ? 'client_id' : 'vendor_id';
        $kindCol = $isInvoice ? 'invoice_type' : 'document_kind';
        $kinds = "'" . implode("','", self::FINAL_KINDS[$docType]) . "'";
        $numberSql = $isInvoice
            ? "COALESCE(NULLIF(d.varsymbol, ''), CONCAT('#', d.id))"
            : "COALESCE(NULLIF(d.vendor_invoice_number, ''), NULLIF(d.varsymbol, ''), CONCAT('#', d.id))";
        // Částka navázané zálohy v Kč: do ní saldokonto stranu faktury přiřadí té záloze.
        // Bere se částka dokladu (u proformy přijatá platba), ne zaplaceno na účtu: je-li
        // záloha krytá DDKP, má na účtu méně a přebytek tu vychází nižší, nikdy vyšší.
        $home = static fn (string $amount): string =>
            "ROUND({$amount} * CASE WHEN lcur.code = 'CZK' OR la.exchange_rate IS NULL THEN 1 ELSE la.exchange_rate END, 2)";
        $linkedSql = $isInvoice
            ? "(SELECT {$home("CASE WHEN la.paid_total > 0 THEN la.paid_total ELSE la.total_with_vat END")}
                  FROM invoices la JOIN currencies lcur ON lcur.id = la.currency_id
                 WHERE la.supplier_id = d.supplier_id AND la.id = d.parent_invoice_id AND la.invoice_type = 'proforma')"
            : "(SELECT {$home('la.total_with_vat')}
                  FROM purchase_invoices la JOIN currencies lcur ON lcur.id = la.currency_id
                 WHERE la.supplier_id = d.supplier_id AND la.id = d.advance_purchase_invoice_id
                   AND (la.document_kind = 'advance' OR (la.document_kind = 'tax_document' AND la.parent_purchase_invoice_id IS NULL)))";
        $filter = '';
        if ($partnerIds !== null) {
            $filter .= " AND d.{$partnerCol} IN (" . implode(',', self::ids($partnerIds) ?: [0]) . ')';
        }
        if ($finalIds !== null) {
            $filter .= ' AND d.id IN (' . implode(',', self::ids($finalIds) ?: [0]) . ')';
        }
        $accounts = implode(',', $accountIds);
        $sql = "SELECT d.id, d.{$partnerCol} AS partner_id, {$numberSql} AS doc_no, d.issue_date,
                       {$linkedSql} AS linked_capacity,
                       e.id AS entry_id, e.description AS entry_description, l.account_id, l.side, l.signed_amount
                  FROM {$table} d
                  JOIN currencies cur ON cur.id = d.currency_id
                  JOIN (" . DocumentPostingGroup::entriesSql($docType, $supplierId) . ") de ON de.doc_id = d.id
                  JOIN journal_entries e ON e.supplier_id = d.supplier_id AND e.id = de.entry_id
                  JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
                       AND l.account_id IN ({$accounts})
                  LEFT JOIN journal_entries rev ON rev.id = e.reversed_by
                 WHERE d.supplier_id = {$supplierId}{$filter}
                   AND d.{$kindCol} IN ({$kinds}) AND d.status NOT IN ('draft', 'cancelled') AND cur.code = 'CZK'
                   AND e.posted_at IS NOT NULL AND e.entry_date <= ? AND (e.reversed_by IS NULL OR rev.entry_date > ?)
                 ORDER BY d.id, e.id, l.line_no, l.id";
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$asOf, $asOf]);
        $side = self::deductionSide($docType);
        $docs = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $id = (int) $r['id'];
            $docs[$id] ??= [
                'doc_id'          => $id,
                'partner_id'      => (int) $r['partner_id'],
                'doc_no'          => (string) $r['doc_no'],
                'issue_date'      => (string) $r['issue_date'],
                'linked_capacity' => $r['linked_capacity'] !== null ? round((float) $r['linked_capacity'], 2) : null,
                'entries'         => [],
                'texts'           => [],
            ];
            $docs[$id]['entries'][(int) $r['entry_id']][] = $r;
            if ($r['entry_description'] !== null) {
                $docs[$id]['texts'][(int) $r['entry_id']] = (string) $r['entry_description'];
            }
        }
        if ($docs === []) {
            return [];
        }
        $deducted = $this->deductedByFinal($supplierId, $docType, array_keys($docs));

        $out = [];
        foreach ($docs as $id => $doc) {
            $deductible = 0.0;
            foreach ($doc['entries'] as $lines) {
                $deductible += self::deductibleOfEntry($lines, $side);
            }
            $deductible = round($deductible, 2);
            if ($deductible < self::TOLERANCE && !isset($deducted[$id])) {
                continue;
            }
            $linkedShare = $doc['linked_capacity'] === null ? 0.0 : round(min(max(0.0, $deductible), $doc['linked_capacity']), 2);
            $unassigned = round($deductible - $linkedShare - ($deducted[$id] ?? 0.0), 2);
            if ($openOnly && $unassigned < self::TOLERANCE) {
                continue;
            }
            $out[$id] = [
                'doc_id'          => $id,
                'partner_id'      => $doc['partner_id'],
                'doc_no'          => $doc['doc_no'],
                'issue_date'      => $doc['issue_date'],
                'posted_on'       => null,
                'posted_entry_id' => null,
                'period_status'   => null,
                'deductible'      => $deductible,
                'linked'          => $doc['linked_capacity'] !== null,
                'linked_share'    => $linkedShare,
                'deducted'        => round($deducted[$id] ?? 0.0, 2),
                'unassigned'      => $unassigned,
                'texts'           => array_values($doc['texts']),
            ];
        }
        if ($out === []) {
            return [];
        }
        $posted = $this->postedEntries($supplierId, $docType, array_keys($out), $asOf);
        $items = $this->itemTexts($docType, array_keys($out));
        foreach ($out as $id => &$f) {
            $f['posted_on'] = $posted[$id]['entry_date'] ?? null;
            $f['posted_entry_id'] = $posted[$id]['entry_id'] ?? null;
            $f['period_status'] = $posted[$id]['period_status'] ?? null;
            $f['texts'] = [...$f['texts'], ...($items[$id] ?? [])];
        }
        unset($f);
        return $out;
    }

    /**
     * Čerpání zálohy (obě strany vazby se čtou stejně; `$by` = 'advance' nebo 'final').
     *
     * @return list<array<string,mixed>>
     */
    public function listFor(int $supplierId, string $docType, string $by, int $docId): array
    {
        $table = self::table($docType);
        $docTable = self::docTable($docType);
        $numberSql = $docType === 'invoice'
            ? "COALESCE(NULLIF(%s.varsymbol, ''), CONCAT('#', %s.id))"
            : "COALESCE(NULLIF(%s.vendor_invoice_number, ''), NULLIF(%s.varsymbol, ''), CONCAT('#', %s.id))";
        $no = static fn (string $a): string => str_replace('%s', $a, $numberSql);
        $column = $by === 'final' ? 'final_invoice_id' : 'advance_invoice_id';
        $stmt = $this->db->pdo()->prepare(
            "SELECT t.id, t.final_invoice_id, t.advance_invoice_id, t.amount, t.source, t.created_by, t.created_at,
                    {$no('f')} AS final_no, {$no('a')} AS advance_no
               FROM {$table} t
               JOIN {$docTable} f ON f.id = t.final_invoice_id AND f.supplier_id = t.supplier_id
               JOIN {$docTable} a ON a.id = t.advance_invoice_id AND a.supplier_id = t.supplier_id
              WHERE t.supplier_id = ? AND t.{$column} = ?
              ORDER BY t.id"
        );
        $stmt->execute([$supplierId, $docId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $posted = $this->postedEntries($supplierId, $docType, array_map(static fn (array $r): int => (int) $r['final_invoice_id'], $rows));
        return array_map(static fn (array $r): array => [
            'id'           => (int) $r['id'],
            'doc_type'     => $docType,
            'final_id'     => (int) $r['final_invoice_id'],
            'final_no'     => (string) $r['final_no'],
            'advance_id'   => (int) $r['advance_invoice_id'],
            'advance_no'   => (string) $r['advance_no'],
            'amount'       => round((float) $r['amount'], 2),
            'source'       => (string) $r['source'],
            'posted_on'    => $posted[(int) $r['final_invoice_id']]['entry_date'] ?? null,
            'created_by'   => $r['created_by'] !== null ? (int) $r['created_by'] : null,
            'created_at'   => (string) $r['created_at'],
        ], $rows);
    }

    /** Čerpání téže dvojice (zamčené), nebo null. */
    public function pair(int $supplierId, string $docType, int $finalId, int $advanceId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, source, amount FROM ' . self::table($docType) . '
              WHERE supplier_id = ? AND final_invoice_id = ? AND advance_invoice_id = ? FOR UPDATE'
        );
        $stmt->execute([$supplierId, $finalId, $advanceId]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        return $r === false ? null : ['id' => (int) $r['id'], 'source' => (string) $r['source'], 'amount' => round((float) $r['amount'], 2)];
    }

    /**
     * Zapíše čerpání. `saldo` (ruční z dialogu saldokonta) se při opakování téže dvojice
     * přičte; `import` (převod) se zapíše jen jako nový řádek, existující dvojici nemění.
     */
    public function insert(int $supplierId, string $docType, int $finalId, int $advanceId, float $amount, ?int $userId, string $source = 'saldo'): int
    {
        if (!in_array($source, ['saldo', 'import'], true)) {
            throw new \InvalidArgumentException('Neplatný zdroj čerpání zálohy.');
        }
        $pdo = $this->db->pdo();
        $onDuplicate = $source === 'saldo'
            ? 'ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), amount = amount + VALUES(amount)'
            : 'ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)';
        $pdo->prepare(
            'INSERT INTO ' . self::table($docType) . " (supplier_id, final_invoice_id, advance_invoice_id, amount, source, created_by)
             VALUES (?, ?, ?, ?, ?, ?) {$onDuplicate}"
        )->execute([$supplierId, $finalId, $advanceId, round($amount, 2), $source, $userId]);
        return (int) $pdo->lastInsertId();
    }

    /** @return array{id:int, final_id:int, advance_id:int, amount:float, source:string}|null */
    public function find(int $supplierId, string $docType, int $id, bool $lock = false): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, final_invoice_id, advance_invoice_id, amount, source FROM ' . self::table($docType)
            . ' WHERE id = ? AND supplier_id = ?' . ($lock ? ' FOR UPDATE' : '')
        );
        $stmt->execute([$id, $supplierId]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        return $r === false ? null : [
            'id'         => (int) $r['id'],
            'final_id'   => (int) $r['final_invoice_id'],
            'advance_id' => (int) $r['advance_invoice_id'],
            'amount'     => round((float) $r['amount'], 2),
            'source'     => (string) $r['source'],
        ];
    }

    public function delete(int $supplierId, string $docType, int $id): bool
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM ' . self::table($docType) . ' WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$id, $supplierId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Konečné faktury s čerpáním záloh (všech zdrojů) a součet jejich čerpání.
     *
     * @param list<int>|null $advanceIds zúžení na čerpání těchto záloh
     * @return array<int,list<array{final_id:int, amount:float}>> podle zálohy
     */
    public function deductionsOfAdvances(int $supplierId, string $docType, ?array $advanceIds = null): array
    {
        if ($advanceIds === []) {
            return [];
        }
        $only = $advanceIds === null ? '' : ' AND advance_invoice_id IN (' . implode(',', self::ids($advanceIds) ?: [0]) . ')';
        $stmt = $this->db->pdo()->prepare(
            'SELECT advance_invoice_id, final_invoice_id, amount FROM ' . self::table($docType) . " WHERE supplier_id = ?{$only} ORDER BY id"
        );
        $stmt->execute([$supplierId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_NUM) as [$advance, $final, $amount]) {
            $out[(int) $advance][] = ['final_id' => (int) $final, 'amount' => round((float) $amount, 2)];
        }
        return $out;
    }

    /**
     * Zápis konečné faktury, od kterého čerpání platí (nejstarší živý zápis skupiny
     * zaúčtování k `$asOf`), se stavem jeho účetního období. Množinu zápisů drží
     * {@see SaldoRepository::advanceDeductionEntriesSql()}, stejně jako platnost čerpání
     * v saldokontu. `$asOf = null` = všechny zaúčtované zápisy včetně stornovaných.
     *
     * @param list<int> $ids
     * @return array<int,array{entry_id:int, entry_date:string, period_status:?string}>
     */
    public function postedEntries(int $supplierId, string $docType, array $ids, ?string $asOf = self::ANY_DATE): array
    {
        return array_map(static fn (array $entries): array => $entries[0], $this->finalEntries($supplierId, $docType, $ids, $asOf));
    }

    /**
     * Všechny zápisy skupiny zaúčtování konečných faktur (vzestupně podle data).
     *
     * @param list<int> $ids
     * @return array<int,list<array{entry_id:int, entry_date:string, period_status:?string, reversed_on:?string}>>
     */
    public function finalEntries(int $supplierId, string $docType, array $ids, ?string $asOf = self::ANY_DATE): array
    {
        $ids = self::ids($ids);
        if ($ids === []) {
            return [];
        }
        $sql = SaldoRepository::advanceDeductionEntriesSql($docType, $supplierId, implode(',', $ids), $asOf === null ? null : '?');
        $stmt = $this->db->pdo()->prepare("SELECT * FROM ({$sql}) x ORDER BY entry_date, entry_id");
        $stmt->execute($asOf === null ? [] : array_fill(0, substr_count($sql, '?'), $asOf));
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['final_id']][(int) $r['entry_id']] = [
                'entry_id'      => (int) $r['entry_id'],
                'entry_date'    => (string) $r['entry_date'],
                'period_status' => $r['period_status'] !== null ? (string) $r['period_status'] : null,
                'reversed_on'   => $r['reversed_on'] !== null ? (string) $r['reversed_on'] : null,
            ];
        }
        return array_map('array_values', $out);
    }

    /**
     * Strana čerpání jednoho zápisu bez přeúčtování mezi analytikami zálohového účtu.
     *
     * @param list<array<string,mixed>> $lines řádky zápisu na zálohovém účtu
     */
    private static function deductibleOfEntry(array $lines, string $side): float
    {
        $opposite = [];
        foreach ($lines as $l) {
            if ($l['side'] !== $side) {
                $opposite[] = [(int) $l['account_id'], round((float) $l['signed_amount'], 2)];
            }
        }
        $sum = 0.0;
        foreach ($lines as $l) {
            if ($l['side'] !== $side) {
                continue;
            }
            $amount = round((float) $l['signed_amount'], 2);
            foreach ($opposite as $i => [$account, $value]) {
                if ($account !== (int) $l['account_id'] && abs($value - $amount) < self::TOLERANCE) {
                    unset($opposite[$i]);
                    continue 2;
                }
            }
            $sum += $amount;
        }
        return $sum;
    }

    /**
     * Součet čerpání konečných faktur ze všech zdrojů (převod i saldokonto).
     *
     * @param list<int> $ids
     * @return array<int,float>
     */
    public function deductedByFinal(int $supplierId, string $docType, array $ids): array
    {
        $ids = self::ids($ids);
        if ($ids === []) {
            return [];
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT final_invoice_id, SUM(amount) FROM ' . self::table($docType) . '
              WHERE supplier_id = ? AND final_invoice_id IN (' . implode(',', $ids) . ')
              GROUP BY final_invoice_id'
        );
        $stmt->execute([$supplierId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_NUM) as [$id, $sum]) {
            $out[(int) $id] = round((float) $sum, 2);
        }
        return $out;
    }

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
     * Konečné faktury, jejichž čerpání (převzaté i zadané v saldokontu) spolu s vazbou na
     * zálohu přesahuje stranu čerpání živého zaúčtování: faktura přeúčtovaná s menším
     * odpočtem, odpočet přesunutý na jiný účet nebo čerpání převodu nad rámec zápisu.
     * Saldokonto by takovou zálohu uzavřelo o čerpání, které hlavní kniha nenese.
     * Stornovaná faktura bez živého zápisu sem nepatří: její čerpání saldokonto nepočítá.
     *
     * @param list<int> $accountIds
     * @return list<array{doc_id:int, doc_no:string, deductible:float, deducted:float, linked_share:float, excess:float}>
     */
    public function overdeductedFinals(int $supplierId, string $docType, array $accountIds): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT DISTINCT final_invoice_id FROM ' . self::table($docType) . ' WHERE supplier_id = ?');
        $stmt->execute([$supplierId]);
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        if ($ids === []) {
            return [];
        }
        $out = [];
        $finals = $this->finals($supplierId, $docType, $accountIds, null, self::ANY_DATE, $ids);
        foreach ($finals as $f) {
            if ($f['unassigned'] <= -self::TOLERANCE) {
                $out[] = ['doc_id' => $f['doc_id'], 'doc_no' => $f['doc_no'], 'deductible' => $f['deductible'],
                    'deducted' => $f['deducted'], 'linked_share' => $f['linked_share'], 'excess' => round(-$f['unassigned'], 2)];
            }
        }
        // Živě zaúčtovaná faktura, které na zálohovém účtu nezbyl žádný řádek strany čerpání.
        $missing = array_values(array_diff($ids, array_keys($finals)));
        $posted = $this->postedEntries($supplierId, $docType, $missing);
        if ($posted !== []) {
            $deducted = $this->deductedByFinal($supplierId, $docType, array_keys($posted));
            $docs = $this->documents($supplierId, $docType, array_keys($posted));
            foreach (array_keys($posted) as $id) {
                $out[] = ['doc_id' => $id, 'doc_no' => $docs[$id]['doc_no'] ?? '#' . $id, 'deductible' => 0.0,
                    'deducted' => $deducted[$id] ?? 0.0, 'linked_share' => 0.0, 'excess' => $deducted[$id] ?? 0.0];
            }
        }
        usort($out, static fn (array $a, array $b): int => $a['doc_id'] <=> $b['doc_id']);
        return $out;
    }

    /**
     * @param list<int> $ids
     * @return array<int,list<string>>
     */
    private function itemTexts(string $docType, array $ids): array
    {
        $sql = $docType === 'invoice'
            ? 'SELECT invoice_id, description FROM invoice_items WHERE invoice_id IN (%s)'
            : 'SELECT purchase_invoice_id, description FROM purchase_invoice_items WHERE purchase_invoice_id IN (%s)';
        $stmt = $this->db->pdo()->query(sprintf($sql, implode(',', self::ids($ids) ?: [0])));
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_NUM) as [$id, $text]) {
            if ($text !== null && trim((string) $text) !== '') {
                $out[(int) $id][] = (string) $text;
            }
        }
        return $out;
    }

    private static function docTable(string $docType): string
    {
        return match ($docType) {
            'purchase_invoice' => 'purchase_invoices',
            'invoice'          => 'invoices',
            default            => throw new \InvalidArgumentException('Neplatný druh dokladu čerpání zálohy.'),
        };
    }

    /** @return list<int> kladná id bez duplicit, vzestupně */
    private static function ids(array $ids): array
    {
        $out = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        sort($out);
        return $out;
    }
}
