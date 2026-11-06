<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank;

use PDO;

/**
 * Skládá jemnozrnné výpisy jednoho účtu do JEDNOHO měsíčního výpisu, který pak
 * uživatel vidí v přehledu. Zdrojové („evidenční") výpisy zůstávají jako doklad
 * měsíce a v seznamu se skrývají ({@see visibleSql()}).
 *
 * Mechanismus vznikl pro strojový feed (`bank_api`, doplňkově `gpc` od stejného
 * účtu), ale koncept je stejný pro DENNÍ PDF VÝPISY ({@see BankStatementSource},
 * `bank_statements.period_kind = 'day'`): KB a další banky posílají e-mailem výpis
 * po každém pohybu, takže bez složení do měsíce by přehled zaplavily jednodenní
 * položky. Proto se sem přidaly, místo aby vznikl druhý paralelní mechanismus —
 * názvy tabulek `bank_api_months` / `bank_api_evidence_months` zůstávají (interní
 * identifikátory se nepřejmenovávají).
 */
final class BankApiMonthlyStatements
{
    /** Zdroje, které se skládají do měsíce bez dalších podmínek. */
    private const FEED_SOURCES = "bs.source IN ('bank_api', 'gpc')";

    /** Denní PDF výpis: skládá se do měsíce podle druhu období, ne podle zdroje. */
    private const DAILY_PDF = "(bs.source = 'pdf' AND bs.period_kind = 'day')";

    /** Výpis, který je stavebním kamenem měsíčního výpisu. */
    private const PROJECTABLE = '(' . self::FEED_SOURCES . ' OR ' . self::DAILY_PDF . ')';

    /**
     * Část SET při odpojení PDF: zůstatky měsíce z feedu dokládalo jen ono. Výpis s vlastním
     * souborem (GPC, stažený výpis banky) má zůstatky ze souboru a ty zůstávají.
     */
    public const DROP_PDF_BALANCES = "prev_balance = CASE WHEN source = 'bank_api' AND file_content IS NULL THEN NULL ELSE prev_balance END,
                curr_balance = CASE WHEN source = 'bank_api' AND file_content IS NULL THEN NULL ELSE curr_balance END";

    public function __construct(private readonly PDO $pdo) {}

    public function pendingBackfillAccounts(): array
    {
        $query = "SELECT bs.supplier_id, bs.account_number, bs.bank_code, bs.currency, COUNT(*) AS statement_count
            FROM bank_statements bs WHERE bs.source IN ('bank_api', 'gpc') AND bs.supplier_id IS NOT NULL
            AND NOT EXISTS (SELECT 1 FROM bank_api_months m WHERE m.statement_id = bs.id)
            AND " . self::visibleSql() . '
            GROUP BY bs.supplier_id, bs.account_number, bs.bank_code, bs.currency ORDER BY bs.supplier_id';
        return array_values(array_filter($this->pdo->query($query)->fetchAll(PDO::FETCH_ASSOC), fn (array $account): bool =>
            $this->hasApiAccount((int) $account['supplier_id'], (string) $account['account_number'], (string) $account['bank_code'], (string) $account['currency'])));
    }

    public static function visibleSql(string $alias = 'bs'): string
    {
        if (preg_match('/^[a-z][a-z0-9_]*$/D', $alias) !== 1) throw new \InvalidArgumentException('Invalid statement alias.');
        return "NOT EXISTS (SELECT 1 FROM bank_api_evidence_months apiem WHERE apiem.evidence_statement_id = $alias.id)";
    }

    public function projectAccount(int $supplierId, string $account, string $bank, string $currency, ?int $userId = null): array
    {
        if (!$this->pdo->inTransaction()) throw new \LogicException('Monthly projection requires an account import transaction.');
        $key = AuthoritativeTransactionReconciler::account($account, $bank);
        if ($key === null) throw new \InvalidArgumentException('Invalid monthly statement account.');
        if (!$this->aggregatesMonthly($supplierId, $account, $bank, $currency)) return [];
        $query = $this->pdo->prepare('SELECT bs.id, bs.account_number, bs.bank_code, bs.statement_date
            FROM bank_statements bs WHERE bs.supplier_id = ? AND bs.currency = ? AND ' . self::PROJECTABLE . '
            AND NOT EXISTS (SELECT 1 FROM bank_api_months m WHERE m.statement_id = bs.id)
            AND ' . self::visibleSql() . ' ORDER BY bs.id');
        $query->execute([$supplierId, $currency]);
        $evidence = array_filter($query->fetchAll(PDO::FETCH_ASSOC), static fn (array $row): bool =>
            AuthoritativeTransactionReconciler::account((string) $row['account_number'], (string) $row['bank_code']) === $key);
        $link = $this->pdo->prepare('INSERT INTO bank_api_evidence_months (evidence_statement_id, monthly_statement_id, supplier_id) VALUES (?, ?, ?)');
        $hasTx = $this->pdo->prepare('SELECT 1 FROM bank_transaction_imports WHERE statement_id = ? AND bank_transaction_id = ?');
        $linkTx = $this->pdo->prepare('INSERT INTO bank_transaction_imports (statement_id, bank_transaction_id, import_fingerprint, supplier_id, original_statement_id) VALUES (?, ?, ?, ?, ?)');
        $result = [];
        $affected = [];
        foreach ($evidence as $row) {
            $id = (int) $row['id'];
            $result[$id] = [];
            $transactions = $this->pdo->query('SELECT bt.id, bt.statement_id, bt.posted_at, bt.import_fingerprint FROM bank_transactions bt WHERE ' . StatementTransactionScope::sql($id))->fetchAll(PDO::FETCH_ASSOC);
            $groups = [];
            foreach ($transactions as $tx) $groups[substr((string) $tx['posted_at'], 0, 7) . '-01'][] = $tx;
            if ($groups === []) $groups[substr((string) $row['statement_date'], 0, 7) . '-01'] = [];
            ksort($groups);
            foreach ($groups as $month => $rows) {
                $monthId = $this->month($supplierId, $key, $account, $bank, $currency, $month, $userId);
                foreach ($rows as $tx) {
                    $hasTx->execute([$monthId, $tx['id']]);
                    if ($hasTx->fetchColumn() === false) {
                        $linkTx->execute([$monthId, $tx['id'], $tx['import_fingerprint'] ?? hash('sha256', 'bank-api-transaction:' . $tx['id']), $supplierId, $tx['statement_id']]);
                    }
                }
                $link->execute([$id, $monthId, $supplierId]);
                $result[$id][] = $monthId;
                $affected[$monthId] = true;
                $end = (new \DateTimeImmutable($month))->format('Y-m-t');
                $covered = max($month, min($end, (string) $row['statement_date']));
                foreach ($rows as $tx) $covered = max($covered, (string) $tx['posted_at']);
                $update = $this->pdo->prepare('UPDATE bank_statements SET statement_date = CASE WHEN statement_date < ? THEN ? ELSE statement_date END WHERE id = ?');
                $update->execute([$covered, $covered, $monthId]);
            }
        }
        $this->refreshMonths(array_keys($affected), $supplierId);
        return $result;
    }

    /** Přepočte souhrny měsíčních výpisů a jejich doklad banky po změně zdrojových výpisů. */
    public function refreshMonths(array $monthIds, int $supplierId): void
    {
        foreach (array_unique(array_map('intval', $monthIds)) as $id) {
            $totals = $this->pdo->query("SELECT COUNT(*) AS total,
                COALESCE(SUM(CASE WHEN bt.amount > 0 THEN bt.amount ELSE 0 END), 0) AS credit,
                COALESCE(SUM(CASE WHEN bt.amount < 0 THEN -bt.amount ELSE 0 END), 0) AS debit,
                COALESCE(SUM(CASE WHEN bt.match_status IN ('auto_exact', 'auto_partial', 'manual') THEN 1 ELSE 0 END), 0) AS matched
                FROM bank_transactions bt WHERE " . StatementTransactionScope::sql($id))->fetch(PDO::FETCH_ASSOC);
            $update = $this->pdo->prepare('UPDATE bank_statements SET transaction_count = ?, matched_count = ?, credit_total = ?, debit_total = ? WHERE id = ?');
            $update->execute([$totals['total'], $totals['matched'], $totals['credit'], $totals['debit'], $id]);
            $this->useBankDocument($id, $supplierId);
            $this->preservePdf($id, $supplierId);
        }
    }

    /** Zdrojový výpis, který zobrazuje měsíční evidence API (sám je v seznamu skrytý). */
    public function isEvidence(int $statementId): bool
    {
        $query = $this->pdo->prepare('SELECT 1 FROM bank_api_evidence_months WHERE evidence_statement_id = ? LIMIT 1');
        $query->execute([$statementId]);
        return $query->fetchColumn() !== false;
    }

    /**
     * Zdrojové výpisy měsíce: jen tak se k nim uživatel dostane, protože seznam
     * výpisů je skrývá za měsíčním výpisem.
     *
     * @return list<array{id:int,file_name:?string,source:string,statement_date:?string,transaction_count:int}>
     */
    public function evidenceStatements(int $monthId, int $supplierId): array
    {
        $query = $this->pdo->prepare('SELECT bs.id, bs.file_name, bs.source, bs.statement_date,
                (SELECT COUNT(*) FROM bank_transactions bt WHERE bt.statement_id = bs.id) AS transaction_count
            FROM bank_api_evidence_months e
            JOIN bank_statements bs ON bs.id = e.evidence_statement_id
            WHERE e.monthly_statement_id = ? AND e.supplier_id = ? AND bs.supplier_id = ?
            ORDER BY bs.statement_date, bs.id');
        $query->execute([$monthId, $supplierId, $supplierId]);
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'file_name' => $row['file_name'],
            'source' => (string) $row['source'],
            'statement_date' => $row['statement_date'],
            'transaction_count' => (int) $row['transaction_count'],
        ], $query->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Odpojí zdrojový výpis od měsíční evidence před jeho smazáním: zruší vazbu
     * výpis → měsíc a vazby měsíce na pohyby, které výpis sám vlastní. Vazby
     * z jiných importů zůstanou a smazání dál blokují. Vrací měsíce k přepočtu.
     *
     * @return list<int>
     */
    public function detachEvidence(int $evidenceId, int $supplierId): array
    {
        if (!$this->pdo->inTransaction()) throw new \LogicException('Detaching monthly evidence requires a transaction.');
        $query = $this->pdo->prepare('SELECT monthly_statement_id FROM bank_api_evidence_months WHERE evidence_statement_id = ? AND supplier_id = ?');
        $query->execute([$evidenceId, $supplierId]);
        $months = array_map('intval', $query->fetchAll(PDO::FETCH_COLUMN));
        if ($months === []) return [];
        $this->pdo->prepare('DELETE FROM bank_transaction_imports
            WHERE statement_id IN (' . implode(',', $months) . ')
              AND bank_transaction_id IN (SELECT bt.id FROM bank_transactions bt WHERE bt.statement_id = ?)')
            ->execute([$evidenceId]);
        $this->pdo->prepare('DELETE FROM bank_api_evidence_months WHERE evidence_statement_id = ? AND supplier_id = ?')
            ->execute([$evidenceId, $supplierId]);
        return $months;
    }

    /**
     * Skládá se tenhle účet do měsíčních výpisů? Buď proto, že má strojový feed, nebo
     * proto, že do něj chodí denní PDF výpisy. Jakmile je účet jednou složený, platí to
     * pro VŠECHNY jeho skládatelné výpisy — i pro ručně nahrané GPC za měsíc, aby účet
     * neměl půlku pohybů v měsíčním výpisu a půlku vedle něj.
     */
    public function aggregatesMonthly(int $supplierId, string $account, string $bank, string $currency): bool
    {
        return $this->hasApiAccount($supplierId, $account, $bank, $currency)
            || $this->hasDailyStatements($supplierId, $account, $bank, $currency);
    }

    /** Přišel na tenhle účet aspoň jeden denní výpis (PDF „výpis při pohybu")? */
    public function hasDailyStatements(int $supplierId, string $account, string $bank, string $currency): bool
    {
        $key = AuthoritativeTransactionReconciler::account($account, $bank);
        if ($key === null) return false;
        $query = $this->pdo->prepare('SELECT bs.account_number, bs.bank_code FROM bank_statements bs
            WHERE bs.supplier_id = ? AND bs.currency = ? AND ' . self::DAILY_PDF);
        $query->execute([$supplierId, $currency]);
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (AuthoritativeTransactionReconciler::account((string) $row['account_number'], (string) $row['bank_code']) === $key) return true;
        }
        return false;
    }

    /**
     * Má účet strojový feed? Rozhoduje napojení na banku, ne formát, ve kterém feed
     * chodí: Fio posílá GPC za okno od posledního stažení, takže bez napojení by se
     * každé noční stažení (i prázdné) ukázalo jako samostatný výpis a pohyby z překryvu
     * oken by se opakovaly v několika výpisech po sobě.
     */
    public function hasApiAccount(int $supplierId, string $account, string $bank, string $currency): bool
    {
        $key = AuthoritativeTransactionReconciler::account($account, $bank);
        if ($key === null) return false;
        $monthly = $this->pdo->prepare('SELECT 1 FROM bank_api_months WHERE supplier_id = ? AND account_key = ? AND currency = ? LIMIT 1');
        $monthly->execute([$supplierId, $key, $currency]);
        if ($monthly->fetchColumn() !== false) return true;
        $query = $this->pdo->prepare("SELECT account_number, bank_code FROM bank_statements WHERE supplier_id = ? AND currency = ? AND source = 'bank_api'");
        $query->execute([$supplierId, $currency]);
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (AuthoritativeTransactionReconciler::account((string) $row['account_number'], (string) $row['bank_code']) === $key) return true;
        }
        return $this->hasBankConnection($supplierId, $key, $currency);
    }

    /** Ověřené napojení na banku pro tento účet (i odpojené: jeho výpisy už feed jsou). */
    private function hasBankConnection(int $supplierId, string $key, string $currency): bool
    {
        $query = $this->pdo->prepare('SELECT bc.verified_account_number, bc.verified_bank_code, c.account_number, c.bank_code
            FROM bank_connections bc
            JOIN currencies c ON c.id = bc.currency_id AND c.supplier_id = bc.supplier_id
            WHERE bc.supplier_id = ? AND c.code = ? AND bc.verified_account_number IS NOT NULL');
        $query->execute([$supplierId, $currency]);
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $bank = (string) ($row['verified_bank_code'] ?? $row['bank_code']);
            if (AuthoritativeTransactionReconciler::account((string) $row['verified_account_number'], $bank) === $key
                || AuthoritativeTransactionReconciler::account((string) $row['account_number'], (string) $row['bank_code']) === $key) {
                return true;
            }
        }
        return false;
    }

    private function useBankDocument(int $monthId, int $supplierId): void
    {
        $ids = $this->pdo->query('SELECT bt.id FROM bank_transactions bt WHERE ' . StatementTransactionScope::sql($monthId) . ' ORDER BY bt.id')->fetchAll(PDO::FETCH_COLUMN);
        $query = $this->pdo->prepare("SELECT bs.* FROM bank_statements bs JOIN bank_api_evidence_months e ON e.evidence_statement_id = bs.id
            WHERE e.monthly_statement_id = ? AND bs.supplier_id = ? AND bs.source = 'gpc'
            AND NOT EXISTS (SELECT 1 FROM bank_api_evidence_months other WHERE other.evidence_statement_id = bs.id AND other.monthly_statement_id <> ?)
            ORDER BY bs.statement_date DESC, bs.id DESC");
        $query->execute([$monthId, $supplierId, $monthId]);
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $document) {
            $documentIds = $this->pdo->query('SELECT bt.id FROM bank_transactions bt WHERE ' . StatementTransactionScope::sql((int) $document['id']) . ' ORDER BY bt.id')->fetchAll(PDO::FETCH_COLUMN);
            if (array_map('intval', $ids) !== array_map('intval', $documentIds)) continue;
            $update = $this->pdo->prepare("UPDATE bank_statements SET source = 'gpc', file_name = ?, file_content = ?, statement_number = ?, prev_balance = ?, curr_balance = ? WHERE id = ?");
            $update->execute([$document['file_name'], $document['file_content'], $document['statement_number'], $document['prev_balance'], $document['curr_balance'], $monthId]);
            return;
        }
        $month = substr((string) $this->pdo->query('SELECT month_start FROM bank_api_months WHERE statement_id = ' . $monthId)->fetchColumn(), 0, 7);
        if ($this->useDailyDocuments($monthId, $supplierId, $month)) return;
        // Zůstatky z přiloženého PDF přežijí přepočet z nočního stažení feedu, jiné ne.
        // MariaDB vyhodnocuje SET zleva, proto se source přepisuje až za nimi.
        $this->pdo->prepare("UPDATE bank_statements SET
                prev_balance = CASE WHEN source = 'bank_api' AND OCTET_LENGTH(pdf_content) > 0 THEN prev_balance END,
                curr_balance = CASE WHEN source = 'bank_api' AND OCTET_LENGTH(pdf_content) > 0 THEN curr_balance END,
                source = 'bank_api', file_name = ?, file_content = NULL, statement_number = NULL
              WHERE id = ?")
            ->execute(['API-' . $month, $monthId]);
    }

    /**
     * Měsíc složený VÝHRADNĚ z denních výpisů: doklad měsíce jsou ty denní výpisy
     * (stáhnout jdou přes {@see evidencePdfs()}), a zůstatky měsíce jsou počáteční
     * zůstatek prvního dne a konečný zůstatek posledního — obojí je údaj banky, ne
     * náš dopočet, takže přehled stavů na účtech nemusí měsíc přeskakovat.
     *
     * Jakmile je mezi podklady i strojový feed, platí dosavadní chování: zůstatky se
     * nedají složit z dvou různě granulárních zdrojů, aniž by se mlčky zvolil jeden.
     */
    private function useDailyDocuments(int $monthId, int $supplierId, string $month): bool
    {
        $query = $this->pdo->prepare("SELECT bs.source, bs.period_kind, bs.prev_balance, bs.curr_balance
            FROM bank_api_evidence_months e JOIN bank_statements bs ON bs.id = e.evidence_statement_id
            WHERE e.monthly_statement_id = ? AND bs.supplier_id = ?
            ORDER BY bs.statement_date, bs.id");
        $query->execute([$monthId, $supplierId]);
        $evidence = $query->fetchAll(PDO::FETCH_ASSOC);
        if ($evidence === []) return false;
        foreach ($evidence as $row) {
            if ((string) $row['source'] !== 'pdf' || (string) $row['period_kind'] !== 'day') return false;
        }
        $this->pdo->prepare("UPDATE bank_statements SET source = 'pdf', file_name = ?, file_content = NULL,
                statement_number = NULL, prev_balance = ?, curr_balance = ? WHERE id = ?")
            ->execute(['PDF-' . $month, $evidence[0]['prev_balance'], $evidence[array_key_last($evidence)]['curr_balance'], $monthId]);
        return true;
    }

    private function preservePdf(int $monthId, int $supplierId): void
    {
        $ids = $this->pdo->query('SELECT bt.id FROM bank_transactions bt WHERE ' . StatementTransactionScope::sql($monthId) . ' ORDER BY bt.id')->fetchAll(PDO::FETCH_COLUMN);
        $query = $this->pdo->prepare("SELECT bs.* FROM bank_statements bs JOIN bank_api_evidence_months e ON e.evidence_statement_id = bs.id
            WHERE e.monthly_statement_id = ? AND bs.supplier_id = ? AND OCTET_LENGTH(bs.pdf_content) > 0
            AND NOT EXISTS (SELECT 1 FROM bank_api_evidence_months other WHERE other.evidence_statement_id = bs.id AND other.monthly_statement_id <> ?)
            ORDER BY bs.statement_date DESC, bs.id DESC");
        $query->execute([$monthId, $supplierId, $monthId]);
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $document) {
            $documentIds = $this->pdo->query('SELECT bt.id FROM bank_transactions bt WHERE ' . StatementTransactionScope::sql((int) $document['id']) . ' ORDER BY bt.id')->fetchAll(PDO::FETCH_COLUMN);
            if (array_map('intval', $ids) !== array_map('intval', $documentIds)) continue;
            $this->pdo->prepare('UPDATE bank_statements SET pdf_content = ?, pdf_name = ?, pdf_hash = ?, pdf_size_bytes = ?, pdf_uploaded_at = ? WHERE id = ? AND (pdf_content IS NULL OR OCTET_LENGTH(pdf_content) = 0)')
                ->execute([$document['pdf_content'], $document['pdf_name'], $document['pdf_hash'], $document['pdf_size_bytes'], $document['pdf_uploaded_at'], $monthId]);
            return;
        }
        // Žádný podklad už měsíc nepokrývá celý — typicky proto, že k prvnímu dennímu
        // výpisu přibyl druhý den. Převzaté PDF prvního dne by pak pod tlačítkem
        // „Stáhnout PDF" vydávalo jeden den za celý měsíc, takže ho odpojíme; jednotlivé
        // dny zůstávají ke stažení přes {@see evidencePdfs()}. Ručně nahrané PDF se
        // nemaže — poznáme ho podle toho, že jeho otisk nepatří žádnému podkladu.
        $current = $this->pdo->prepare('SELECT pdf_hash FROM bank_statements WHERE id = ? AND supplier_id = ?');
        $current->execute([$monthId, $supplierId]);
        $monthHash = $current->fetchColumn();
        if ($monthHash === false || $monthHash === null || $monthHash === '') return;
        $copied = $this->pdo->prepare('SELECT 1 FROM bank_api_evidence_months e
            JOIN bank_statements src ON src.id = e.evidence_statement_id
            WHERE e.monthly_statement_id = ? AND src.pdf_hash = ? LIMIT 1');
        $copied->execute([$monthId, $monthHash]);
        if ($copied->fetchColumn() === false) return;
        $this->pdo->prepare('UPDATE bank_statements
                SET pdf_content = NULL, pdf_name = NULL, pdf_hash = NULL, pdf_size_bytes = NULL, pdf_uploaded_at = NULL, ' . self::DROP_PDF_BALANCES . '
              WHERE id = ? AND supplier_id = ?')
            ->execute([$monthId, $supplierId]);
    }

    public function monthIds(int $evidenceId, int $supplierId): array
    {
        $query = $this->pdo->prepare('SELECT e.monthly_statement_id FROM bank_api_evidence_months e JOIN bank_api_months m ON m.statement_id = e.monthly_statement_id WHERE e.evidence_statement_id = ? AND m.supplier_id = ? ORDER BY m.month_start');
        $query->execute([$evidenceId, $supplierId]);
        return array_map('intval', $query->fetchAll(PDO::FETCH_COLUMN));
    }

    public function evidencePdfs(int $monthId, int $supplierId): array
    {
        $query = $this->pdo->prepare('SELECT bs.id, bs.pdf_name FROM bank_api_evidence_months e
            JOIN bank_statements bs ON bs.id = e.evidence_statement_id
            JOIN bank_statements monthly ON monthly.id = e.monthly_statement_id
            WHERE monthly.id = ? AND monthly.supplier_id = ? AND bs.supplier_id = ?
            AND OCTET_LENGTH(bs.pdf_content) > 0
            AND (COALESCE(OCTET_LENGTH(monthly.pdf_content), 0) = 0 OR NOT (bs.pdf_hash <=> monthly.pdf_hash))
            ORDER BY bs.statement_date, bs.id');
        $query->execute([$monthId, $supplierId, $supplierId]);
        return array_map(static fn (array $row): array => ['id' => (int) $row['id'], 'pdf_name' => $row['pdf_name']], $query->fetchAll(PDO::FETCH_ASSOC));
    }

    private function month(int $supplierId, string $key, string $account, string $bank, string $currency, string $month, ?int $userId): int
    {
        $query = $this->pdo->prepare('SELECT statement_id FROM bank_api_months WHERE supplier_id = ? AND account_key = ? AND currency = ? AND month_start = ?');
        $query->execute([$supplierId, $key, $currency, $month]);
        $id = $query->fetchColumn();
        if ($id !== false) return (int) $id;
        $hash = hash('sha256', json_encode(['bank-api-month', $supplierId, $key, $currency, $month], JSON_THROW_ON_ERROR));
        $number = 'API-' . substr($month, 0, 7);
        $insert = $this->pdo->prepare("INSERT INTO bank_statements
            (source, file_name, file_hash, supplier_id, account_number, bank_code, currency, statement_number, statement_date, transaction_count, matched_count, imported_by)
            VALUES ('bank_api', ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, ?)");
        $insert->execute([$number, $hash, $supplierId, $account, $bank, $currency, null, $month, $userId]);
        $id = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO bank_api_months (supplier_id, account_key, currency, month_start, statement_id) VALUES (?, ?, ?, ?, ?)')->execute([$supplierId, $key, $currency, $month, $id]);
        return $id;
    }
}
