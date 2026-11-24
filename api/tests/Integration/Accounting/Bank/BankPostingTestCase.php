<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Bank;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\BankPostingRuleRepository;
use MyInvoice\Repository\BankPostingSuggestionRepository;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\Bank\BankPostingBackfill;
use MyInvoice\Service\Accounting\Bank\BankPostingService;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use MyInvoice\Tests\Support\OwnedSandboxDatabase;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Sdílený základ integračních testů BankPostingService (mini-epic AUTOMATIZACE, §8).
 * Sdílená DB transakce + rollback v tearDown (izolace, rok 2099, demo data se nemění).
 * Soft-skip bez cfg.php / bez double_entry firmy. Testovací účet se NEskipuje —
 * doplní se v transakci, viz {@see self::ensureAccountOwnership()}.
 */
abstract class BankPostingTestCase extends TestCase
{
    use IsolatedSupplierTrait;
    use OwnedSandboxDatabase;
    protected const YEAR = 2099;
    protected const ACCOUNT = '1700000006'; // currencies řádek supplieru 1 (tenant resolution)
    protected const BANK_CODE = '2250';

    protected Connection $db;
    protected BankPostingService $service;
    protected BankPostingBackfill $backfill;
    protected PostingService $posting;
    protected JournalEntryRepository $journal;
    protected AccountingPeriodRepository $periods;
    protected ChartOfAccountsRepository $accounts;
    protected BankPostingRuleRepository $ruleRepo;
    protected BankPostingSuggestionRepository $suggestionRepo;

    protected ContainerInterface $container;
    protected int $supplierId = 0;
    protected int $currencyId = 0;
    protected int $userId = 0;
    protected int $czId = 0;
    protected int $periodId = 0;
    protected bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 5);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        $ownsTransactions = in_array('fulltext-transactions', $this->groups(), true);
        try {
            $container            = Bootstrap::buildContainer();
            if ($ownsTransactions) {
                $container->set(Config::class, self::sandboxConfig('accounting_fts'));
                $container->set(Connection::class, self::sandboxConnection('accounting_fts'));
            }
            $this->container      = $container;
            $this->db             = $container->get(Connection::class);
            $this->service        = $container->get(BankPostingService::class);
            $this->backfill       = $container->get(BankPostingBackfill::class);
            $this->posting        = $container->get(PostingService::class);
            $this->journal        = $container->get(JournalEntryRepository::class);
            $this->periods        = $container->get(AccountingPeriodRepository::class);
            $this->accounts       = $container->get(ChartOfAccountsRepository::class);
            $this->ruleRepo       = $container->get(BankPostingRuleRepository::class);
            $this->suggestionRepo = $container->get(BankPostingSuggestionRepository::class);
            $seeder               = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query("SELECT id FROM supplier WHERE accounting_mode='double_entry' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code='CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId       = (int) ($pdo->query("SELECT id FROM countries WHERE iso2='CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->currencyId === 0 || $this->userId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier double_entry/currency/user/country).');
        }
        if ($ownsTransactions) {
            $this->supplierId = $this->createIsolatedSupplier($pdo, $this->supplierId);
            $pdo->prepare(
                'INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
                 SELECT ?, code, label, symbol, name_cs, name_en, decimals, 1, 1 FROM currencies WHERE id = ?'
            )->execute([$this->supplierId, $this->currencyId]);
            $this->currencyId = (int) $pdo->lastInsertId();
            $pdo->prepare('UPDATE supplier SET default_currency_id = ? WHERE id = ?')
                ->execute([$this->currencyId, $this->supplierId]);
        } else {
            $pdo->beginTransaction();
            $this->inTx = true;
        }
        $this->ensureAccountOwnership($pdo);
        $seeder->seedForSupplier($this->supplierId);
        $this->periodId = $this->periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
        $pdo->prepare(
            "INSERT INTO auto_posting_policy (supplier_id, operation_type, level, updated_by)
             VALUES (?, 'bank.payment.matched', 'auto', ?)
             ON DUPLICATE KEY UPDATE level = 'auto', updated_by = VALUES(updated_by)"
        )->execute([$this->supplierId, $this->userId]);
    }

    /**
     * Tenant resolution váže výpis na firmu přes číslo účtu v `currencies`, takže
     * {@see self::ACCOUNT} musí supplierovi patřit. Dřív se test v opačném případě
     * přeskočil — jenže na sanitizovaném klonu produkce (`*_test`) jsou čísla účtů
     * anonymizovaná, takže se ta podmínka nesplnila NIKDY a celá rodina bankovních
     * testů se tiše vypnula. Zelená suita pak nic netvrdila.
     *
     * Účet si proto doplníme sami, uvnitř transakce, kterou tearDown roluje zpět —
     * v DB po testu nezůstane nic a předpoklad je splněný na jakémkoli klonu.
     * Přednostně obsadíme řádek supplierovy měny bez čísla účtu, ať firmě
     * nepřibývá měna navíc, na kterou by se mohly dívat jiné testy.
     */
    private function ensureAccountOwnership(PDO $pdo): void
    {
        $owns = (int) $pdo->query(
            "SELECT COUNT(*) FROM currencies WHERE supplier_id={$this->supplierId} AND account_number='" . self::ACCOUNT . "'"
        )->fetchColumn();
        if ($owns > 0) {
            return;
        }

        $spare = (int) ($pdo->query(
            "SELECT id FROM currencies
              WHERE supplier_id={$this->supplierId} AND (account_number IS NULL OR account_number = '')
              ORDER BY id LIMIT 1"
        )->fetchColumn() ?: 0);
        if ($spare > 0) {
            $pdo->prepare('UPDATE currencies SET account_number = ?, bank_code = ? WHERE id = ?')
                ->execute([self::ACCOUNT, self::BANK_CODE, $spare]);
            return;
        }

        $pdo->prepare(
            'INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, account_number, bank_code)
             VALUES (?, "CZK", "Kč", "Kč", "koruna česká", "Czech koruna", ?, ?)'
        )->execute([$this->supplierId, self::ACCOUNT, self::BANK_CODE]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    /**
     * `$supplierId` je autoritativní vlastník výpisu — od zavedení
     * {@see \MyInvoice\Repository\BankStatementOwnershipResolver} rozhoduje
     * o přístupu ON, ne shoda čísla účtu. Výpis cizího tenanta se proto musí
     * zakládat s jeho supplier_id; kombinace „supplier_id vlastní + účet cizí"
     * je nekonzistentní řádek, jaký v DB nemá vzniknout.
     */
    protected function statement(?string $account = null, ?string $bankCode = null, ?int $supplierId = null): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO bank_statements (supplier_id, file_name, file_hash, account_number, bank_code, currency, statement_date, imported_by)
             VALUES (?, ?, ?, ?, ?, "CZK", ?, ?)'
        )->execute([
            $supplierId ?? $this->supplierId, 'test.gpc', hash('sha256', uniqid('bpst', true)), $account ?? self::ACCOUNT, $bankCode ?? self::BANK_CODE,
            self::YEAR . '-06-15', $this->userId,
        ]);
        return (int) $pdo->lastInsertId();
    }

    /** @param array<string,mixed> $over */
    protected function transaction(int $statementId, float $amount, array $over = []): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO bank_transactions
                (statement_id, source, posted_at, amount, currency, variable_symbol,
                 counterparty_account, counterparty_bank, counterparty_name, description, match_status, matched_invoice_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $statementId,
            $over['source'] ?? 'statement',
            $over['posted_at'] ?? (self::YEAR . '-06-15'),
            $amount,
            $over['currency'] ?? 'CZK',
            $over['variable_symbol'] ?? null,
            $over['counterparty_account'] ?? null,
            $over['counterparty_bank'] ?? null,
            $over['counterparty_name'] ?? 'Protistrana',
            $over['description'] ?? 'Platba',
            $over['match_status'] ?? 'unmatched',
            $over['matched_invoice_id'] ?? null,
        ]);
        return (int) $pdo->lastInsertId();
    }

    protected function invoicePayment(int $invoiceId, int $txId, float $amount): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO invoice_payments (supplier_id, invoice_id, paid_on, amount, currency, source, bank_transaction_id)
             VALUES (?, ?, ?, ?, "CZK", "bank", ?)'
        )->execute([$this->supplierId, $invoiceId, self::YEAR . '-06-15', $amount, $txId]);
    }

    protected function paymentMatch(int $txId, int $purchaseInvoiceId, float $amount): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payment_matches (supplier_id, bank_transaction_id, purchase_invoice_id, amount, match_type)
             VALUES (?, ?, ?, ?, "manual")'
        )->execute([$this->supplierId, $txId, $purchaseInvoiceId, $amount]);
    }

    /** Vloží zaúčtovaný nestornovaný předpis dokladu (guard H1). Vrací entry id. */
    protected function postPredpis(string $sourceType, int $sourceId, string $debit, string $credit, float $amount): int
    {
        $map = $this->accounts->codeToIdMap($this->supplierId);
        return $this->journal->insert([
            'supplier_id' => $this->supplierId,
            'period_id'   => $this->periodId,
            'entry_date'  => self::YEAR . '-06-10',
            'document_no' => 'PREDPIS-' . $sourceType . '-' . $sourceId,
            'description' => 'Předpis',
            'source_type' => $sourceType,
            'source_id'   => $sourceId,
            'posted_at'   => date('Y-m-d H:i:s'),
            'posted_by'   => $this->userId,
        ], [
            ['account_id' => $map[$debit]['id'], 'side' => 'debit', 'amount' => $amount],
            ['account_id' => $map[$credit]['id'], 'side' => 'credit', 'amount' => $amount],
        ]);
    }

    /** @param array<string,mixed> $data */
    protected function rule(array $data): int
    {
        return $this->ruleRepo->insert($this->supplierId, [
            'name'                 => $data['name'] ?? 'Pravidlo',
            'direction'            => $data['direction'],
            'counterparty_account' => $data['counterparty_account'] ?? null,
            'counterparty_bank'    => $data['counterparty_bank'] ?? null,
            'counterparty_prefix'  => $data['counterparty_prefix'] ?? null,
            'variable_symbol'      => $data['variable_symbol'] ?? null,
            'message_contains'     => $data['message_contains'] ?? null,
            'amount_min'           => $data['amount_min'] ?? null,
            'amount_max'           => $data['amount_max'] ?? null,
            'debit_account_code'   => $data['debit_account_code'],
            'credit_account_code'  => $data['credit_account_code'],
            'description'          => $data['description'] ?? null,
            'mode'                 => $data['mode'] ?? 'suggest',
            'is_active'            => $data['is_active'] ?? 1,
            'priority'             => $data['priority'] ?? 100,
            'operation_type'       => $data['operation_type'] ?? null,
            'auto_amount_cap'      => $data['auto_amount_cap'] ?? null,
            'applies_currency'     => $data['applies_currency'] ?? 'CZK',
        ], $this->userId);
    }

    protected function client(string $name): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, dic, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Test 1", "Praha", "11000", ?, "CZ12345678", "t@example.com", "cs", ?, 1, 1)'
        );
        $stmt->execute([$this->supplierId, $name, $this->czId, $this->currencyId]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    protected function saleInvoice(string $varsymbol, int $clientId, float $total, string $type = 'invoice', string $status = 'issued'): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, total_without_vat, total_vat, total_with_vat,
                 paid_total, status, vat_classification_code, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?, 0, ?, 0, ?, "1", ?)'
        );
        $issue = self::YEAR . '-06-10';
        $stmt->execute([$this->supplierId, $varsymbol, $type, $clientId, $issue, $issue, $issue, $this->currencyId, $total, $total, $status, $this->userId]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    protected function purchaseInvoice(string $number, int $vendorId, float $total, string $documentKind = 'invoice'): int
    {
        $issue = self::YEAR . '-06-10';
        $snapshot = json_encode(['company_name' => 'Dodavatel s.r.o.'], JSON_UNESCAPED_UNICODE);
        $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, vendor_snapshot, document_kind, vat_deduction,
                 issue_date, tax_date, due_date, received_at, currency_id, reverse_charge, is_fixed_asset,
                 total_without_vat, total_vat, total_with_vat, status, created_by)
             VALUES (?, ?, ?, ?, ?, "full", ?, ?, ?, ?, ?, 0, 0, ?, 0, ?, "received", ?)'
        )->execute([$this->supplierId, $vendorId, $number, $snapshot, $documentKind, $issue, $issue, $issue, $issue, $this->currencyId, $total, $total, $this->userId]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    // ── asserts / lookups ────────────────────────────────────────────────────

    /**
     * Řádky zápisu podle kódu účtu.
     *
     * Bankovní noha od migrace 1318 nepadá na plochou syntetiku 221, ale na analytiku
     * vlastního účtu (221100, 221200 …). Testy okolo mluví o „bankovní noze", ne o
     * konkrétním čísle, takže jedinou analytiku 221xxx v zápisu zpřístupníme i pod
     * klíčem '221'. Alias se ZÁMĚRNĚ nepoužije, když v zápisu leží víc účtů skupiny 221
     * (převod mezi vlastními účty, termínovaný vklad) — tam by sloučení dvou různých
     * účtů do jednoho klíče zamlčelo, na kterém z nich částka reálně je.
     *
     * @return array<string,array{debit:float,credit:float}>
     */
    protected function linesByAccountCode(int $entryId): array
    {
        $entry = $this->journal->find($entryId, $this->supplierId);
        $out = [];
        foreach ($entry['lines'] as $l) {
            $code = $this->accountCode((int) $l['account_id']);
            $out[$code] ??= ['debit' => 0.0, 'credit' => 0.0];
            $out[$code][$l['side']] += (float) $l['amount'];
        }
        // Pozor: array_keys() vrací číselné kódy účtů jako INT — porovnávat se musí
        // přes explicitní přetypování, jinak filtr nic nenajde.
        if (!isset($out['221'])) {
            $bankCodes = array_values(array_filter(
                array_keys($out),
                static fn (int|string $code): bool => str_starts_with((string) $code, '221'),
            ));
            if (count($bankCodes) === 1) {
                $out['221'] = $out[$bankCodes[0]];
            }
        }
        return $out;
    }

    protected function accountCode(int $accountId): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT account_code FROM chart_of_accounts WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$accountId, $this->supplierId]);
        $v = $stmt->fetchColumn();
        return $v === false ? '?' : (string) $v;
    }

    protected function entryCountForTx(int $txId): int
    {
        return (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM journal_entries WHERE supplier_id={$this->supplierId}
              AND source_type='bank' AND source_id={$txId}"
        )->fetchColumn();
    }

    protected function suggestionCountForTx(int $txId): int
    {
        return (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM bank_posting_suggestions WHERE bank_transaction_id={$txId}"
        )->fetchColumn();
    }

    /** @return array<string,mixed> */
    protected function suggestionRow(int $id): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM bank_posting_suggestions WHERE id = ?');
        $stmt->execute([$id]);
        return (array) $stmt->fetch(PDO::FETCH_ASSOC);
    }

    protected function ruleRow(int $id): array
    {
        return (array) $this->ruleRepo->find($this->supplierId, $id);
    }

    protected function meta(): array
    {
        return ['user_id' => $this->userId, 'posted_by' => $this->userId];
    }

    /**
     * Zavolá Action metodu s Requestem nesoucím tenanta + uživatele (action-level RBAC).
     *
     * @param array<string,mixed> $body
     * @param array<string,string> $args
     * @return array{status:int, body:array<string,mixed>}
     */
    protected function callAction(object $action, string $method, string $httpMethod, string $role, array $body = [], array $args = []): array
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest($httpMethod, '/api/accounting')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => $role]);
        if ($body !== []) {
            $req = $req->withParsedBody($body);
        }
        $resp = $args === []
            ? $action->{$method}($req, new Psr7Response())
            : $action->{$method}($req, new Psr7Response(), $args);
        $resp->getBody()->rewind();
        $decoded = json_decode((string) $resp->getBody(), true);
        return ['status' => $resp->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }

    /** Vloží currencies řádek (per-tenant měna / bankovní účet). Vrací id. */
    protected function currencyRow(int $supplierId, string $code, ?string $account = null, ?string $bankCode = null): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default, account_number, bank_code)
             VALUES (?, ?, ?, ?, ?, ?, 2, 1, 0, ?, ?)'
        )->execute([$supplierId, $code, $code, $code, $code, $code, $account, $bankCode]);
        return (int) $pdo->lastInsertId();
    }

    protected function otherSupplierId(string $mode = 'any'): int
    {
        $sql = $mode === 'any'
            ? "SELECT id FROM supplier WHERE id <> {$this->supplierId} ORDER BY id LIMIT 1"
            : "SELECT id FROM supplier WHERE id <> {$this->supplierId} AND accounting_mode <> 'double_entry' ORDER BY id LIMIT 1";
        $id = (int) ($this->db->pdo()->query($sql)->fetchColumn() ?: 0);
        return $id !== 0 ? $id : $this->cloneSupplier($mode === 'non' ? 'tax_evidence' : 'double_entry');
    }

    /**
     * Naklonuje aktuální firmu do nové (jen v rámci rollbackované transakce) — jediný
     * unikátní klíč je PRIMARY, takže klon nekoliduje. Umožní multi-tenant testy i na
     * demo DB s jedinou firmou.
     */
    protected function cloneSupplier(string $mode): int
    {
        $pdo = $this->db->pdo();
        $cols = $pdo->query(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'supplier'
                AND COLUMN_NAME <> 'id' AND EXTRA NOT LIKE '%auto_increment%'
                AND (GENERATION_EXPRESSION IS NULL OR GENERATION_EXPRESSION = '')
              ORDER BY ORDINAL_POSITION"
        )->fetchAll(PDO::FETCH_COLUMN);
        $colList = implode(', ', array_map(static fn (string $c): string => "`{$c}`", $cols));
        $pdo->prepare(
            "INSERT INTO supplier ({$colList}) SELECT {$colList} FROM supplier WHERE id = ?"
        )->execute([$this->supplierId]);
        $newId = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE supplier SET accounting_mode = ? WHERE id = ?')->execute([$mode, $newId]);
        return $newId;
    }
}
