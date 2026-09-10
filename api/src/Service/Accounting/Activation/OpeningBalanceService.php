<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Activation;

use MyInvoice\Service\Accounting\AccountingPeriodStatus;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\BankStatementOwnershipResolver;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Service\Accounting\Bank\BankAnalyticAssigner;
use MyInvoice\Service\Accounting\Bank\BankAnalyticResolver;
use MyInvoice\Service\Accounting\Cash\CashRegisterService;
use MyInvoice\Service\Accounting\Closing\DocumentSeriesService;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\TaxEvidence\TransitionReportService;
use PDO;

final class OpeningBalanceService
{
    public function __construct(
        private readonly Connection $db,
        private readonly PostingService $posting,
        private readonly DocumentSeriesService $series,
        private readonly AccountingPeriodRepository $periods,
        private readonly TransitionReportService $transition,
        private readonly ChartOfAccountsRepository $accounts,
        private readonly BankAnalyticResolver $bankAnalytics,
    ) {}

    public function prefill(int $supplierId, string $asOf): array
    {
        $report = $this->transition->build($supplierId, $asOf, 'tax_to_accounting');
        $totals = (array) ($report['totals'] ?? []);
        $rows = [];
        $this->addAmount($rows, '311', (float) ($totals['receivables_czk'] ?? 0), 'debit', 'Neuhrazené pohledávky z daňové evidence — saldo bez předpisů');
        $this->addAmount($rows, '321', (float) ($totals['payables_czk'] ?? 0), 'credit', 'Neuhrazené závazky z daňové evidence — saldo bez předpisů');
        $this->addAmount($rows, '314', (float) ($totals['advances_paid_czk'] ?? 0), 'debit', 'Poskytnuté zálohy k datu přechodu');
        $this->addAmount($rows, '324', (float) ($totals['advances_received_czk'] ?? 0), 'credit', 'Přijaté zálohy k datu přechodu');
        $this->addAmount($rows, '132', (float) ($totals['inventory_czk'] ?? 0), 'debit', 'Zásoby podle přechodového můstku');

        foreach ($this->cashBalancesByRegister($supplierId, $asOf) as $cash) {
            $this->addSignedBalance($rows, $cash['account_code'], $cash['amount'], $cash['note']);
        }
        foreach ($this->bankBalancesByAccount($supplierId, $asOf) as $bank) {
            $this->addSignedBalance($rows, $bank['account_code'], $bank['amount'], $bank['note']);
        }

        // Účet mimo osnovu i účet z osnovy VYPNUTÝ shodně vypadnou: replace() by nad
        // neaktivním účtem hodil 'validation_failed' a shodil celý prefill, takže by
        // jediné vypnuté 311 zablokovalo aktivaci. Radši řádek chybí, než aby průvodce
        // přestal jít dokončit — rozvaha se doplňuje ručně a proti 701 zůstane vyrovnaná.
        $rows = array_values(array_filter($rows, fn (array $row) => $this->isPostableAccount($supplierId, $row['account_code'])));
        return $this->replace($supplierId, $rows, 'transition_report');
    }

    public function draft(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT a.account_code, c.name AS account_name, a.side, a.amount, a.note, a.source
               FROM accounting_opening_balances a
          LEFT JOIN chart_of_accounts c ON c.supplier_id = a.supplier_id AND c.account_code = a.account_code
              WHERE a.supplier_id = ? ORDER BY a.account_code, a.side'
        );
        $stmt->execute([$supplierId]);
        $rows = array_map(static fn (array $row): array => [
            'account_code' => (string) $row['account_code'],
            'account_name' => (string) ($row['account_name'] ?? ''),
            'side' => (string) $row['side'],
            'amount' => (float) $row['amount'],
            'note' => $row['note'] === null ? null : (string) $row['note'],
            'source' => (string) $row['source'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
        return ['rows' => $rows, 'totals' => $this->totals($rows), 'hash' => $this->hashRows($rows)];
    }

    public function saveDraft(int $supplierId, array $rows): array
    {
        return $this->replace($supplierId, $rows, 'manual');
    }

    /**
     * Read-only odpověď na otázku „půjde otevírací zápis k tomuto datu vůbec založit?".
     *
     * Jediný zdroj pravdy pro tři místa, která se ptají na totéž: ostré zaúčtování
     * ({@see post()}), kontrola nanečisto (dřív hlásila `failed_total: 0` i nad
     * zavřeným obdobím a pustila uživatele do ostrého běhu, který skončil `failed`)
     * a stav průvodce (rozvahu smí doplnit, dokud je cílové období otevřené).
     *
     * Nic nezakládá: období, které ještě neexistuje, si post() vytvoří jako otevřené,
     * takže překážka to není.
     *
     * @return array{code:string, message:string, status:int}|null null = zápis projde
     */
    public function postBlocker(int $supplierId, string $startsOn): ?array
    {
        $previousDay = (new \DateTimeImmutable($startsOn))->modify('-1 day')->format('Y-m-d');
        $stmt = $this->db->pdo()->prepare(
            "SELECT 1 FROM accounting_periods
              WHERE supplier_id = ? AND ends_on = ? AND status IN ('closing', " . AccountingPeriodStatus::closedSqlList() . ") LIMIT 1"
        );
        $stmt->execute([$supplierId, $previousDay]);
        if ($stmt->fetchColumn() !== false) {
            return [
                'code' => 'opening_owned_by_closing',
                'message' => 'Otevírací zápis patří uzávěrce předchozího období.',
                'status' => 409,
            ];
        }

        $period = $this->periods->findForDate($supplierId, $startsOn);
        if ($period !== null && (string) $period['status'] !== 'open') {
            return [
                'code' => 'period_not_open',
                'message' => 'Období zahájení účetnictví není otevřené.',
                'status' => 422,
            ];
        }
        return null;
    }

    /** Má už firma otevírací zápis k datu zahájení? Rozhoduje o tom, zda ho průvodce nabídne doplnit. */
    public function isPosted(int $supplierId, string $startsOn): bool
    {
        $period = $this->periods->findForDate($supplierId, $startsOn);
        if ($period === null) {
            return false;
        }
        $stmt = $this->db->pdo()->prepare(
            "SELECT 1 FROM journal_entries
              WHERE supplier_id = ? AND source_type = 'opening' AND source_id = ? LIMIT 1"
        );
        $stmt->execute([$supplierId, (int) $period['id']]);
        return $stmt->fetchColumn() !== false;
    }

    public function post(int $supplierId, string $startsOn, array $meta): array
    {
        $draft = $this->draft($supplierId);
        if ($draft['rows'] === []) {
            throw new PostingException('opening_empty', 'Otevírací rozvaha neobsahuje žádné řádky.', 422);
        }
        if (!$draft['totals']['balanced']) {
            throw new PostingException('opening_unbalanced', 'Otevírací rozvaha není vyrovnaná.', 422);
        }

        $blocker = $this->postBlocker($supplierId, $startsOn);
        if ($blocker !== null) {
            throw new PostingException($blocker['code'], $blocker['message'], $blocker['status']);
        }

        $period = $this->periods->ensureOpenPeriodFor($supplierId, $startsOn);
        // Pojistka proti souběhu: mezi postBlocker() a tímhle řádkem mohla uzávěrka
        // období zavřít. Kontrola nad již načteným řádkem, takže stojí nula dotazů.
        if ((string) $period['status'] !== 'open') {
            throw new PostingException('period_not_open', 'Období zahájení účetnictví není otevřené.', 422);
        }

        $lines = [];
        foreach ($draft['rows'] as $row) {
            $amount = (float) $row['amount'];
            if ($row['side'] === 'debit') {
                $lines[] = ['account_code' => $row['account_code'], 'side' => 'debit', 'amount' => $amount];
                $lines[] = ['account_code' => '701', 'side' => 'credit', 'amount' => $amount];
            } else {
                $lines[] = ['account_code' => '701', 'side' => 'debit', 'amount' => $amount];
                $lines[] = ['account_code' => $row['account_code'], 'side' => 'credit', 'amount' => $amount];
            }
        }
        PostingService::assertBalanced($lines);

        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) $pdo->beginTransaction();
        try {
            $existingStmt = $pdo->prepare(
                "SELECT id, document_no FROM journal_entries
                  WHERE supplier_id = ? AND source_type = 'opening' AND source_id = ? LIMIT 1"
            );
            $existingStmt->execute([$supplierId, (int) $period['id']]);
            $existing = $existingStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            $documentNo = $existing['document_no'] ?? $this->series->next($supplierId, 'opening', (int) $period['fiscal_year']);
            $entryId = $this->posting->postDocument($supplierId, 'opening', (int) $period['id'], $lines, [
                'entry_date' => $startsOn,
                'document_date' => $startsOn,
                'document_no' => $documentNo,
                'description' => 'Otevření účetních knih k ' . $startsOn,
                'posted' => true,
                'posted_by' => $meta['posted_by'] ?? null,
                'user_id' => $meta['user_id'] ?? null,
                'ip' => $meta['ip'] ?? null,
                'user_agent' => $meta['user_agent'] ?? null,
            ]);
            if ($ownTx) $pdo->commit();
            return ['journal_entry_id' => $entryId, 'document_no' => (string) $documentNo];
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    private function replace(int $supplierId, array $rows, string $source): array
    {
        $clean = [];
        $seen = [];
        // Index řádku putuje do PostingException::$context → do JSON chyby → do editoru,
        // který dotčený řádek zvýrazní. Bez něj uživatel u dvacetiřádkové rozvahy jen
        // čte, že „něco" je špatně, a hledá to metodou pokus-omyl.
        foreach (array_values($rows) as $index => $row) {
            $at = ['row' => $index];
            if (!is_array($row)) throw new PostingException('validation_failed', 'Řádek rozvahy není platný.', 400, $at);
            $code = trim((string) ($row['account_code'] ?? ''));
            $side = (string) ($row['side'] ?? '');
            $amount = round((float) ($row['amount'] ?? 0), 2);
            if ($code === '701') throw new PostingException('opening_701_forbidden', 'Účet 701 doplní systém automaticky.', 400, $at);
            $account = $this->accounts->findByCode($supplierId, $code);
            // Kód účtu je schválně až na konci věty: ErrorCatalog umí prefix-match, takže
            // hláška s proměnnou zůstane přeložitelná (jinak by v EN zůstala česky).
            if ($account === null || !$account['is_active']) throw new PostingException('validation_failed', 'Účet není v aktivní účtové osnově: ' . $code, 400, $at);
            if (!in_array($side, ['debit', 'credit'], true) || $amount <= 0) throw new PostingException('validation_failed', 'Strana musí být MD nebo D a částka musí být kladná.', 400, $at);
            $key = $code . ':' . $side;
            if (isset($seen[$key])) throw new PostingException('validation_failed', 'Účet je na stejné straně uveden vícekrát: ' . $code, 400, $at);
            $seen[$key] = true;
            $clean[] = [
                'account_code' => $code,
                'side' => $side,
                'amount' => $amount,
                'note' => trim((string) ($row['note'] ?? '')) ?: null,
                'source' => $source,
            ];
        }

        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM accounting_opening_balances WHERE supplier_id = ?')->execute([$supplierId]);
            $insert = $pdo->prepare(
                'INSERT INTO accounting_opening_balances (supplier_id, account_code, side, amount, note, source)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            foreach ($clean as $row) {
                $insert->execute([$supplierId, $row['account_code'], $row['side'], $row['amount'], $row['note'], $row['source']]);
            }
            if ($ownTx) $pdo->commit();
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return $this->draft($supplierId);
    }

    private function totals(array $rows): array
    {
        $debit = 0.0;
        $credit = 0.0;
        foreach ($rows as $row) {
            if (($row['side'] ?? null) === 'debit') $debit += (float) $row['amount'];
            if (($row['side'] ?? null) === 'credit') $credit += (float) $row['amount'];
        }
        $debit = round($debit, 2);
        $credit = round($credit, 2);
        return ['debit' => $debit, 'credit' => $credit, 'balanced' => abs($debit - $credit) < 0.005];
    }

    private function hashRows(array $rows): string
    {
        $normalized = array_map(static fn (array $row): array => [
            (string) $row['account_code'], (string) $row['side'], number_format((float) $row['amount'], 2, '.', ''), (string) ($row['note'] ?? ''),
        ], $rows);
        usort($normalized, static fn (array $a, array $b): int => $a <=> $b);
        return hash('sha256', json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function addAmount(array &$rows, string $code, float $amount, string $side, string $note): void
    {
        $amount = round($amount, 2);
        if ($amount > 0) $rows[] = ['account_code' => $code, 'side' => $side, 'amount' => $amount, 'note' => $note];
    }

    private function addSignedBalance(array &$rows, string $code, float $amount, string $note): void
    {
        $amount = round($amount, 2);
        if (abs($amount) >= 0.005) $rows[] = ['account_code' => $code, 'side' => $amount >= 0 ? 'debit' : 'credit', 'amount' => abs($amount), 'note' => $note];
    }

    /**
     * Plochý souhrn počátečního stavu pokladen přes všechny registry firmy. Rozvaha se
     * takhle NEúčtuje (knihuje se per analytika pokladny, viz
     * {@see cashBalancesByRegister()}) — zůstává jako kontrolní součet, symetricky
     * k {@see bankBalance()}: rozpad musí dát tutéž částku jako souhrn, jinak se při
     * rozpadu ztratil nebo přibyl doklad. Tu kontrolu počítá
     * `AccountingActivationTest::testCashSplitSumsToFlatBalance()` — produkční volající
     * metoda ZÁMĚRNĚ nemá, je to nezávislý orákl rozpadu.
     *
     * `total_amount` je v DB už CZK ekvivalent i u valutové pokladny (migrace 1114 —
     * cizoměnová částka žije zvlášť v `amount_foreign`), takže se kurzem NEPŘEPOČÍTÁVÁ
     * podruhé. Tutéž konvenci má
     * {@see \MyInvoice\Service\Accounting\Cash\CashRegisterService::documentsSignedTotal()}.
     */
    private function cashBalance(int $supplierId, string $asOf): float
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(CASE WHEN doc_type = 'in' THEN total_amount ELSE -total_amount END), 0)
               FROM cash_documents WHERE supplier_id = ? AND status = 'posted' AND issue_date <= ?"
        );
        $stmt->execute([$supplierId, $asOf]);
        return round((float) $stmt->fetchColumn(), 2);
    }

    /**
     * Počáteční stav pokladen ROZPADNUTÝ na analytiky jednotlivých pokladen (211.001,
     * 211.002 …), symetricky k {@see bankBalancesByAccount()}.
     *
     * Rozpad je možný, protože zdrojem není jedno souhrnné číslo: každý pokladní doklad
     * visí na registru (`cash_documents.register_id`) a registr NESE svou analytiku
     * (`cash_registers.account_code`). Je to TÝŽ účet, na který doklad zaúčtuje běžný
     * provoz (CashDocumentService) a nad kterým se staví pokladní kniha — proto se tu
     * analytika nevymýšlí vlastním pravidlem, ale čte se z registru. Kdyby si aktivace
     * přidělovala čísla po svém, počáteční stav by skončil na jiné analytice než pohyby,
     * které po něm následují, a pokladní kniha by nesedla s hlavní knihou.
     *
     * Doklad, u kterého registr dohledat nejde (smazaná pokladna, registr cizí firmy),
     * i registr s analytikou, kterou si uživatel v osnově VYPNUL, spadne na syntetiku
     * 211: replace() by nad neaktivním účtem hodil 'validation_failed' a shodil celý
     * prefill. Aby to nebyl tichý nesoulad, nese takový řádek v poznámce výzvu k ručnímu
     * rozúčtování; poznámka je v průvodci aktivací vidět i editovatelná.
     *
     * Pokladna bez dokladů se v rozpadu neobjeví vůbec (nulový zůstatek), dvě pokladny
     * na témž účtu se sečtou do jednoho bucketu — řádek se stejným `account_code` na téže
     * straně by replace() odmítl jako duplicitu.
     *
     * @return list<array{account_code:string, amount:float, note:string}>
     */
    private function cashBalancesByRegister(int $supplierId, string $asOf): array
    {
        $synthetic = CashRegisterService::CASH_SYNTHETIC;
        $buckets = [];
        foreach ($this->cashRowsByRegister($supplierId, $asOf) as $row) {
            $code = trim((string) ($row['account_code'] ?? ''));
            if ($code !== '' && !$this->isPostableAccount($supplierId, $code)) {
                $code = '';
            }
            $key = $code !== '' ? $code : $synthetic;
            if (!isset($buckets[$key])) {
                $buckets[$key] = ['amount' => 0.0, 'registers' => [], 'unresolved' => false];
            }
            $buckets[$key]['amount'] += (float) $row['balance'];
            $buckets[$key]['unresolved'] = $buckets[$key]['unresolved'] || $code === '';
            $label = $this->cashRegisterLabel($row);
            if ($label !== '' && !in_array($label, $buckets[$key]['registers'], true)) {
                $buckets[$key]['registers'][] = $label;
            }
        }
        ksort($buckets, SORT_STRING);

        $out = [];
        foreach ($buckets as $code => $bucket) {
            $registers = implode(', ', $bucket['registers']);
            $note = $bucket['unresolved']
                ? 'Stav pokladen k datu přechodu — doklady bez pokladny s vlastní analytikou'
                    . ($registers !== '' ? ' (' . $registers . ')' : '')
                    . '; rozúčtujte ručně na analytiky ' . $code . '.xxx'
                : trim('Stav pokladny ' . $registers) . ' k datu přechodu';
            $out[] = [
                'account_code' => (string) $code,
                'amount' => round($bucket['amount'], 2),
                'note' => mb_substr($note, 0, 255),
            ];
        }
        return $out;
    }

    /**
     * Zůstatky k datu po pokladnách. LEFT JOIN schválně: doklad bez dohledatelného
     * registru se NESMÍ ztratit, jinak by rozpad nesedl na {@see cashBalance()}.
     *
     * @return list<array<string,mixed>>
     */
    private function cashRowsByRegister(int $supplierId, string $asOf): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT r.account_code AS account_code,
                    r.name         AS register_name,
                    COALESCE(SUM(CASE WHEN cd.doc_type = 'in' THEN cd.total_amount ELSE -cd.total_amount END), 0) AS balance
               FROM cash_documents cd
          LEFT JOIN cash_registers r ON r.id = cd.register_id AND r.supplier_id = cd.supplier_id
              WHERE cd.supplier_id = ? AND cd.status = 'posted' AND cd.issue_date <= ?
           GROUP BY r.account_code, r.name"
        );
        $stmt->execute([$supplierId, $asOf]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param array<string,mixed> $row */
    private function cashRegisterLabel(array $row): string
    {
        $name = trim((string) ($row['register_name'] ?? ''));
        return $name !== '' ? $name : trim((string) ($row['account_code'] ?? ''));
    }

    /**
     * Plochý souhrn počátečního stavu banky přes všechny vlastní účty. Rozvaha se
     * takhle od #35 NEúčtuje (knihuje se per analytika, viz
     * {@see bankBalancesByAccount()}) — zůstává jako kontrolní součet: rozpad musí
     * dát tutéž částku jako souhrn, jinak se při rozpadu ztratil nebo přibyl pohyb.
     */
    private function bankBalance(int $supplierId, string $asOf): float
    {
        $total = 0.0;
        foreach ($this->bankRowsByStatementAccount($supplierId, $asOf) as $row) {
            $total += (float) $row['balance'];
        }
        return round($total, 2);
    }

    /**
     * Počáteční stav banky ROZPADNUTÝ na analytiky vlastních účtů (221100, 221200 …).
     *
     * Rozpad je možný, protože zdrojem není jedno souhrnné číslo: každá transakce
     * visí na hlavičce výpisu, a ta nese číslo vlastního účtu
     * (`bank_statements.account_number` / `bank_code`). Je to TÁŽ dvojice, ze které
     * běžný provoz odvozuje bankovní nohu zápisu — BankPostingService ji do `$tx`
     * promítá jako `recipient_account` / `recipient_bank`. Proto se tu na
     * analytiku nepřekládá vlastním pravidlem, ale sdíleným
     * {@see BankAnalyticResolver::analyticCodeFor()}: kdyby aktivace přidělovala čísla
     * po svém, počáteční stav by skončil na jiné analytice než pohyby, které po něm
     * následují.
     *
     * Výpis, u kterého se vlastní účet dohledat NEDÁ (cizí/neznámé číslo, účet
     * smazaný z nastavení), zůstává na syntetice 221 — vymýšlet mu rozdělení by bylo
     * horší než souhrn. Aby to nebyl tichý nesoulad, nese takový řádek v poznámce
     * výzvu k ručnímu rozúčtování; poznámka je v průvodci aktivací vidět i editovatelná.
     *
     * @return list<array{account_code:string, amount:float, note:string}>
     */
    private function bankBalancesByAccount(int $supplierId, string $asOf): array
    {
        $synthetic = BankAnalyticAssigner::BANK_SYNTHETIC;
        // Bez syntetiky 221 v osnově se analytiky NEZAKLÁDAJÍ — analyticCodeFor je do
        // osnovy sám dohrává a u nezaseedované firmy by 221xxx vzniklo bez rodiče.
        // Zůstatek pak spadne na 221 a prefill ho stejně odfiltruje (účet není v osnově).
        $canResolve = $this->accounts->findByCode($supplierId, $synthetic) !== null;

        $buckets = [];
        foreach ($this->bankRowsByStatementAccount($supplierId, $asOf) as $row) {
            $code = $canResolve ? $this->bankAnalytics->analyticCodeFor($supplierId, $row) : null;
            if ($code !== null && !$this->isPostableAccount($supplierId, $code)) {
                // Analytiku, kterou si uživatel v osnově vypnul, by replace() odmítl
                // ('validation_failed') a shodil celý prefill. Zůstatek radši spadne do
                // souhrnu k ručnímu rozúčtování než aby aktivace přestala jít dokončit.
                $code = null;
            }
            $key = $code ?? $synthetic;
            if (!isset($buckets[$key])) {
                $buckets[$key] = ['amount' => 0.0, 'accounts' => [], 'resolved' => $code !== null];
            }
            $buckets[$key]['amount'] += (float) $row['balance'];
            // Dvě různá zapsání téhož účtu (GPC padding, předčíslí) spadnou na jeden kód —
            // proto se sčítá do bucketu podle KÓDU, ne podle hlavičky výpisu. Řádek se
            // stejným account_code na téže straně by replace() odmítl jako duplicitu.
            $label = $this->statementAccountLabel($row);
            if ($label !== '' && !in_array($label, $buckets[$key]['accounts'], true)) {
                $buckets[$key]['accounts'][] = $label;
            }
        }
        ksort($buckets, SORT_STRING);

        $out = [];
        foreach ($buckets as $code => $bucket) {
            $accounts = implode(', ', $bucket['accounts']);
            $note = $bucket['resolved']
                ? trim('Stav bankovního účtu ' . $accounts) . ' k datu přechodu'
                : 'Stav bankovních účtů k datu přechodu — výpisy bez vlastního účtu v nastavení'
                    . ($accounts !== '' ? ' (' . $accounts . ')' : '')
                    . '; rozúčtujte ručně na analytiky ' . $code . 'xxx';
            $out[] = [
                'account_code' => (string) $code,
                'amount' => round($bucket['amount'], 2),
                'note' => mb_substr($note, 0, 255),
            ];
        }
        return $out;
    }

    /**
     * Zůstatky k datu po hlavičkách výpisů. Klíče `recipient_account` /
     * `recipient_bank` jsou schválně pojmenované jako v BankPostingService, aby řádek
     * šel předat rovnou {@see BankAnalyticResolver}.
     *
     * @return list<array<string,mixed>>
     */
    private function bankRowsByStatementAccount(int $supplierId, string $asOf): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT bs.account_number AS recipient_account,
                    bs.bank_code      AS recipient_bank,
                    COALESCE(SUM(bt.amount), 0) AS balance
               FROM bank_transactions bt
               JOIN bank_statements bs ON bs.id = bt.statement_id
              WHERE bt.source = 'statement' AND bt.posted_at <= ?
                AND UPPER(COALESCE(NULLIF(bt.currency, ''), NULLIF(bs.currency, ''), 'CZK')) = 'CZK'
                AND " . BankStatementOwnershipResolver::sql() . "
              GROUP BY bs.account_number, bs.bank_code"
        );
        // SEC-01: počáteční zůstatek se nesmí počítat z cizích výpisů.
        $stmt->execute(array_merge(
            [$asOf],
            BankStatementOwnershipResolver::params($supplierId),
        ));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function isPostableAccount(int $supplierId, string $code): bool
    {
        $account = $this->accounts->findByCode($supplierId, $code);
        return $account !== null && (bool) $account['is_active'];
    }

    /** @param array<string,mixed> $row */
    private function statementAccountLabel(array $row): string
    {
        $account = trim((string) ($row['recipient_account'] ?? ''));
        if ($account === '') {
            return '';
        }
        $bank = trim((string) ($row['recipient_bank'] ?? ''));
        return $bank === '' ? $account : $account . '/' . $bank;
    }
}
