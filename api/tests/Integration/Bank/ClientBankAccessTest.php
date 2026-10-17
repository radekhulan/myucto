<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Bank;

use MyInvoice\Action\Bank\BankStatementAction;
use MyInvoice\Action\Bank\BankTransactionListAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Security\EffectiveRole;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Slim\Psr7\UploadedFile;

#[Group('integration')]
final class ClientBankAccessTest extends TestCase
{
    use \MyInvoice\Tests\Support\PayrollPaymentEvidenceTrait;

    /** Import páruje a uhrazuje doklady, proto vyžaduje i oprávnění ke změně úhrad. */
    private const IMPORT = ['bank' => 1, 'bank.import' => 2, 'invoices.mark_paid' => 2, 'purchase_invoices.transition' => 2];

    private ContainerInterface $container;
    private PDO $pdo;
    private BankStatementAction $bank;
    private int $supplier;
    private int $otherSupplier;
    private int $currency;
    private int $user;
    private int $partner;
    private int $statement;
    private int $tx;
    private int $invoice;
    private int $purchase;
    private bool $committed = false;

    protected function setUp(): void
    {
        $this->container = Bootstrap::buildContainer();
        $this->pdo = $this->container->get(Connection::class)->pdo();
        self::assertStringEndsWith('_test', (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn());
        $this->pdo->exec('SET SESSION sql_mode = \'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION\'');
        $this->pdo->beginTransaction();
        $country = (int) $this->pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ'")->fetchColumn();
        $vat = (int) $this->pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn();
        $baselineCurrency = (int) $this->pdo->query('SELECT id FROM currencies ORDER BY id LIMIT 1')->fetchColumn();
        self::assertGreaterThan(0, $country);
        self::assertGreaterThan(0, $vat);
        self::assertGreaterThan(0, $baselineCurrency);
        $this->supplier = $this->supplier($country, $vat, $baselineCurrency);
        $this->otherSupplier = $this->supplier($country, $vat, $baselineCurrency);
        $this->pdo->prepare("INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, account_number, bank_code)
            VALUES (?, 'CZK', 'Synthetic client bank', 'Kč', 'Koruna', 'Crown', '1000000005', '0100')")->execute([$this->supplier]);
        $this->currency = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('UPDATE supplier SET default_currency_id = ? WHERE id = ?')->execute([$this->currency, $this->supplier]);
        $this->pdo->prepare("INSERT INTO users (email, password_hash, name, role) VALUES (?, ?, 'Synthetic client bank', 'client')")
            ->execute(['bank-client-' . bin2hex(random_bytes(8)) . '@example.invalid', str_repeat('x', 60)]);
        $this->user = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, main_email, currency_default_id, is_customer, is_vendor)
            VALUES (?, 'Synthetic bank partner', 'Test 1', 'Testov', '00000', ?, 'partner@example.invalid', ?, 1, 1)")
            ->execute([$this->supplier, $country, $this->currency]);
        $this->partner = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO bank_statements (supplier_id, source, file_name, file_hash, account_number, bank_code, currency, statement_date, transaction_count)
            VALUES (?, 'gpc', 'synthetic-client.gpc', ?, '1000000005', '0100', 'CZK', '2099-06-15', 1)")
            ->execute([$this->supplier, hash('sha256', random_bytes(16))]);
        $this->statement = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO bank_transactions (statement_id, posted_at, amount, currency, variable_symbol, description)
            VALUES (?, '2099-06-15', 1000, 'CZK', '1600001', 'Synthetic client bank')")->execute([$this->statement]);
        $this->tx = (int) $this->pdo->lastInsertId();
        $this->invoice = $this->invoice('1600001', 1000);
        $this->pdo->prepare("INSERT INTO purchase_invoices (supplier_id, vendor_id, vendor_invoice_number, vendor_snapshot, document_kind, vat_deduction,
            issue_date, tax_date, due_date, received_at, currency_id, total_without_vat, total_with_vat, status, created_by)
            VALUES (?, ?, 'SYNTH-160-P', '{}', 'invoice', 'full', '2099-06-15', '2099-06-15', '2099-06-15', '2099-06-15', ?, 1000, 1000, 'received', ?)")
            ->execute([$this->supplier, $this->partner, $this->currency, $this->user]);
        $this->purchase = (int) $this->pdo->lastInsertId();
        $this->bank = $this->container->get(BankStatementAction::class);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) $this->pdo->rollBack();
        if ($this->committed) {
            // Scénáře se službami s vlastní transakcí uklízejí jen své syntetické firmy.
            // Pohyby nemají supplier_id: smažeme je ještě před vypnutím FK, aby
            // zafungovaly i kaskády do jejich podřízené evidence.
            $this->pdo->prepare('DELETE bt FROM bank_transactions bt JOIN bank_statements bs ON bs.id = bt.statement_id WHERE bs.supplier_id IN (?, ?)')
                ->execute([$this->supplier, $this->otherSupplier]);
            $tables = $this->pdo->query("SELECT TABLE_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'supplier_id'")->fetchAll(PDO::FETCH_COLUMN);
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            try {
                foreach ($tables as $table) {
                    self::assertMatchesRegularExpression('/^[a-z0-9_]+$/D', $table);
                    $this->pdo->prepare('DELETE FROM `' . $table . '` WHERE supplier_id IN (?, ?)')->execute([$this->supplier, $this->otherSupplier]);
                }
            } finally { $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1'); }
            $this->pdo->prepare('DELETE FROM supplier WHERE id IN (?, ?)')->execute([$this->supplier, $this->otherSupplier]);
            $this->pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$this->user]);
        }
    }

    private function supplier(int $country, int $vat, int $currency): int
    {
        $this->pdo->prepare("INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id, auto_send_reminders)
            VALUES ('Synthetic client bank', 'Test 1', 'Testov', '00000', ?, 'bank@example.invalid', ?, ?, 0)")->execute([$country, $currency, $vat]);
        return (int) $this->pdo->lastInsertId();
    }

    private function invoice(string $vs, float $amount): int
    {
        $this->pdo->prepare("INSERT INTO invoices (supplier_id, client_id, varsymbol, issue_date, tax_date, due_date, currency_id, status, total_without_vat, total_with_vat, created_by)
            VALUES (?, ?, ?, '2099-06-15', '2099-06-15', '2099-06-15', ?, 'issued', ?, ?, ?)")
            ->execute([$this->supplier, $this->partner, $vs, $this->currency, $amount, $amount, $this->user]);
        return (int) $this->pdo->lastInsertId();
    }

    private function request(array $permissions = [], array $body = [], ?int $supplier = null): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('POST', '/api/bank-transactions/' . $this->tx)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->user, 'role' => 'client'])
            ->withAttribute('auth.effective_role', new EffectiveRole(0, 'Synthetic bank client', 'client', true, $permissions))
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplier ?? $this->supplier)->withParsedBody($body);
    }

    private function call(string $method, array $permissions, array $body = [], array $query = [], ?int $supplier = null): array
    {
        $response = $this->bank->{$method}($this->request($permissions, $body, $supplier)->withQueryParams($query), new Response(),
            ['id' => $method === 'detail' || $method === 'matchSuggestions' ? $this->statement : $this->tx]);
        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)];
    }

    private function state(): array
    {
        return $this->pdo->query('SELECT match_status, matched_invoice_id, ignore_origin, ignore_note FROM bank_transactions WHERE id = ' . $this->tx)->fetch(PDO::FETCH_ASSOC);
    }

    public function testIgnoreAndUnignoreRequireTheirOwnPermissionAndPreserveAudit(): void
    {
        foreach (['ignore', 'unignore', 'manualMatch', 'unmatch'] as $method) self::assertSame(403, $this->call($method, ['bank' => 1])[0], $method);
        $permissions = ['bank' => 1, 'bank.ignore' => 2];
        self::assertSame(200, $this->call('ignore', $permissions, ['note' => 'Synthetic reason'])[0]);
        self::assertSame(['match_status' => 'ignored', 'matched_invoice_id' => null, 'ignore_origin' => 'manual', 'ignore_note' => 'Synthetic reason'], $this->state());
        self::assertSame(409, $this->call('unmatch', ['bank' => 1, 'bank.match' => 2])[0]);
        self::assertSame(200, $this->call('unignore', $permissions)[0]);
        self::assertSame('unmatched', $this->state()['match_status']);
        self::assertNull($this->state()['ignore_origin']);
        self::assertSame(['bank.tx_ignore', 'bank.tx_unignore'], $this->pdo->query('SELECT action FROM activity_log WHERE user_id = ' . $this->user . ' ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
    }

    public function testSystemAndRuleIgnoredTransactionsCannotBeReactivatedOrRelabelled(): void
    {
        $permissions = ['bank' => 1, 'bank.ignore' => 2, 'bank.match' => 2, 'invoices' => 1, 'invoices.mark_paid' => 2];
        foreach (['rule', 'system', null] as $origin) {
            $this->pdo->prepare("UPDATE bank_transactions SET match_status = 'ignored', ignore_origin = ? WHERE id = ?")->execute([$origin, $this->tx]);
            foreach (['ignore', 'unignore', 'unmatch', 'manualMatch'] as $method) {
                self::assertSame(409, $this->call($method, $permissions, ['invoice_id' => $this->invoice])[0], $method);
                self::assertSame($origin, $this->state()['ignore_origin']);
            }
        }
    }

    public function testIssuedPairingRequiresDocumentReadAndPaymentWrite(): void
    {
        // Párování nemění obsah dokladu; změna úhrady vyžaduje samostatné oprávnění.
        $this->pdo->prepare("UPDATE invoices SET booked_at = '2099-06-15 10:00:00' WHERE id = ?")->execute([$this->invoice]);
        self::assertSame(403, $this->call('manualMatch', ['bank' => 1, 'bank.match' => 2], ['invoice_id' => $this->invoice])[0]);
        self::assertSame('unmatched', $this->state()['match_status']);
        $readOnly = ['bank' => 1, 'bank.match' => 2, 'invoices' => 1];
        $before = $this->state();
        self::assertSame(403, $this->call('manualMatch', $readOnly, ['invoice_id' => $this->invoice])[0]);
        self::assertSame($before, $this->state());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM invoice_payments WHERE bank_transaction_id = ' . $this->tx)->fetchColumn());
        [$status, $body] = $this->call('manualMatch', ['bank' => 1, 'bank.match' => 2, 'invoices' => 1, 'invoices.mark_paid' => 2], ['invoice_id' => $this->invoice]);
        self::assertSame(200, $status, json_encode($body));
        self::assertSame('manual', $this->state()['match_status']);
        self::assertSame('paid', $this->pdo->query('SELECT status FROM invoices WHERE id = ' . $this->invoice)->fetchColumn());
        self::assertSame(409, $this->call('ignore', ['bank' => 1, 'bank.ignore' => 2])[0]);
        self::assertSame(403, $this->call('unmatch', ['bank' => 1, 'bank.match' => 2])[0]);
        $before = $this->state();
        $payments = $this->pdo->query('SELECT * FROM invoice_payments WHERE bank_transaction_id = ' . $this->tx)->fetchAll(PDO::FETCH_ASSOC);
        self::assertSame(403, $this->call('unmatch', $readOnly)[0]);
        self::assertSame($before, $this->state());
        self::assertSame($payments, $this->pdo->query('SELECT * FROM invoice_payments WHERE bank_transaction_id = ' . $this->tx)->fetchAll(PDO::FETCH_ASSOC));
        self::assertSame(200, $this->call('unmatch', ['bank' => 1, 'bank.match' => 2, 'invoices' => 1, 'invoices.mark_paid' => 2])[0]);
        self::assertSame('issued', $this->pdo->query('SELECT status FROM invoices WHERE id = ' . $this->invoice)->fetchColumn());
    }

    public function testPurchasePairingAndUnmatchRequireReadAndTransitionWrite(): void
    {
        $this->pdo->prepare('UPDATE bank_transactions SET amount = -1000 WHERE id = ?')->execute([$this->tx]);
        self::assertSame(403, $this->call('manualMatch', ['bank' => 1, 'bank.match' => 2, 'invoices' => 1, 'invoices.mark_paid' => 2], ['purchase_invoice_id' => $this->purchase])[0]);
        $readOnly = ['bank' => 1, 'bank.match' => 2, 'purchase_invoices' => 1];
        self::assertSame(403, $this->call('manualMatch', $readOnly, ['purchase_invoice_id' => $this->purchase])[0]);
        self::assertSame('unmatched', $this->state()['match_status']);
        $permissions = ['bank' => 1, 'bank.match' => 2, 'purchase_invoices' => 1, 'purchase_invoices.transition' => 2];
        [$status, $body] = $this->call('manualMatch', $permissions, ['purchase_invoice_id' => $this->purchase]);
        self::assertSame(200, $status, json_encode($body));
        self::assertNull($this->state()['matched_invoice_id']);
        self::assertSame(403, $this->call('unmatch', ['bank' => 1, 'bank.match' => 2, 'invoices' => 1, 'invoices.mark_paid' => 2])[0]);
        self::assertSame('manual', $this->state()['match_status']);
        $before = $this->state();
        $payments = $this->pdo->query('SELECT * FROM payment_matches WHERE bank_transaction_id = ' . $this->tx)->fetchAll(PDO::FETCH_ASSOC);
        self::assertSame(403, $this->call('unmatch', $readOnly)[0]);
        self::assertSame($before, $this->state());
        self::assertSame($payments, $this->pdo->query('SELECT * FROM payment_matches WHERE bank_transaction_id = ' . $this->tx)->fetchAll(PDO::FETCH_ASSOC));
        self::assertSame(200, $this->call('unmatch', $permissions)[0]);
    }

    public function testSplitChecksEveryTargetBeforeChangingAnyPayment(): void
    {
        $other = $this->invoice('1600002', 500);
        $this->pdo->prepare('UPDATE invoices SET supplier_id = ? WHERE id = ?')->execute([$this->otherSupplier, $other]);
        $this->pdo->prepare('UPDATE bank_transactions SET amount = 1500 WHERE id = ?')->execute([$this->tx]);
        [$status] = $this->call('manualMatch', ['bank' => 1, 'bank.match' => 2, 'invoices' => 1, 'invoices.mark_paid' => 2], ['invoice_ids' => [$this->invoice, $other]]);
        self::assertSame(404, $status);
        self::assertSame('unmatched', $this->state()['match_status']);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM invoice_payments WHERE bank_transaction_id = ' . $this->tx)->fetchColumn());
    }

    public function testManualSplitsWithoutPaymentPermissionCannotChangeAnyDocument(): void
    {
        $other = $this->invoice('1600002', 500);
        $this->pdo->prepare('UPDATE bank_transactions SET amount = 1500 WHERE id = ?')->execute([$this->tx]);
        $before = $this->state();
        self::assertSame(403, $this->call('manualMatch', ['bank' => 1, 'bank.match' => 2, 'invoices' => 1], ['invoice_ids' => [$this->invoice, $other]])[0]);
        self::assertSame($before, $this->state());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM invoice_payments WHERE bank_transaction_id = ' . $this->tx)->fetchColumn());
        $this->pdo->prepare("INSERT INTO purchase_invoices (supplier_id, vendor_id, vendor_invoice_number, vendor_snapshot, document_kind, vat_deduction, issue_date, tax_date, due_date, received_at, currency_id, total_without_vat, total_with_vat, status, created_by)
            SELECT supplier_id, vendor_id, 'SYNTH-160-P2', vendor_snapshot, document_kind, vat_deduction, issue_date, tax_date, due_date, received_at, currency_id, total_without_vat, total_with_vat, status, created_by FROM purchase_invoices WHERE id = ?")->execute([$this->purchase]);
        $otherPurchase = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('UPDATE bank_transactions SET amount = -2000 WHERE id = ?')->execute([$this->tx]);
        self::assertSame(403, $this->call('manualMatch', ['bank' => 1, 'bank.match' => 2, 'purchase_invoices' => 1], ['purchase_invoice_ids' => [$this->purchase, $otherPurchase]])[0]);
        self::assertSame($before, $this->state());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM payment_matches WHERE bank_transaction_id = ' . $this->tx)->fetchColumn());
    }

    private function staffRequest(array $permissions, array $body = []): ServerRequestInterface
    {
        return $this->request([], $body)->withAttribute('auth.effective_role', new EffectiveRole(9, 'Synthetic custom staff', 'staff', true, $permissions));
    }

    public function testStaffCanIgnoreMatchedOccupiedPaymentAndRestoreNoticeTransfer(): void
    {
        $this->pdo->prepare("UPDATE bank_transactions SET match_status = 'manual', matched_invoice_id = ? WHERE id = ?")->execute([$this->invoice, $this->tx]);
        $this->pdo->prepare("INSERT INTO invoice_payments (supplier_id, invoice_id, bank_transaction_id, amount, paid_on, currency) VALUES (?, ?, ?, 1000, ?, 'CZK')")->execute([$this->supplier, $this->invoice, $this->tx, '2099-06-15']);
        $this->pdo->prepare('UPDATE bank_statements SET matched_count = 1 WHERE id = ?')->execute([$this->statement]);
        $response = $this->bank->ignore($this->staffRequest(['bank.ignore' => 2]), new Response(), ['id' => $this->tx]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('ignored', $this->state()['match_status']);
        self::assertSame(0, (int) $this->pdo->query('SELECT matched_count FROM bank_statements WHERE id = ' . $this->statement)->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM invoice_payments WHERE bank_transaction_id = ' . $this->tx)->fetchColumn());
        $this->pdo->prepare('DELETE FROM invoice_payments WHERE bank_transaction_id = ?')->execute([$this->tx]);
        $this->pdo->prepare("UPDATE bank_transactions SET match_status = 'ignored', matched_invoice_id = NULL, ignore_origin = 'notice_transfer' WHERE id = ?")->execute([$this->tx]);
        $response = $this->bank->unmatch($this->staffRequest(['bank' => 2]), new Response(), ['id' => $this->tx]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('unmatched', $this->state()['match_status']);
        self::assertNull($this->state()['ignore_origin']);
    }

    public function testStaffAutomaticCandidatesAndSplitsKeepAgendasWithoutDocumentPermissions(): void
    {
        $response = $this->bank->matchCandidates($this->staffRequest(['bank.match' => 2]), new Response(), ['id' => $this->tx]);
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertContains('invoice', array_column($body['candidates'], 'type'));
        self::assertContains('purchase_invoice', array_column($body['candidates'], 'type'));
        $this->invoice('1600002', 500);
        $this->pdo->prepare('UPDATE bank_transactions SET amount = 1500 WHERE id = ?')->execute([$this->tx]);
        $response = $this->bank->splitSuggestions($this->staffRequest(['bank.match' => 2]), new Response(), ['id' => $this->tx]);
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertNotEmpty($body['suggestions']);
    }

    public function testStaffFullTextCandidatesKeepDocumentReadPermissionRequirement(): void
    {
        foreach (['invoice' => 'invoices', 'purchase_invoice' => 'purchase_invoices'] as $type => $permission) {
            $this->pdo->prepare('UPDATE bank_transactions SET amount = ? WHERE id = ?')
                ->execute([$type === 'invoice' ? 1000 : -1000, $this->tx]);
            foreach ([false, true] as $canRead) {
                $permissions = ['bank.match' => 2];
                if ($canRead) $permissions[$permission] = 1;
                $response = $this->bank->matchCandidates($this->staffRequest($permissions)->withQueryParams(['search' => 'Synthetic']), new Response(), ['id' => $this->tx]);
                self::assertSame(200, $response->getStatusCode());
                $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
                if ($canRead) {
                    self::assertSame([$type], array_values(array_unique(array_column($body['candidates'], 'type'))));
                } else {
                    self::assertSame([], $body['candidates']);
                }
            }
        }
    }

    public function testForeignMovementsRemainInvisibleForEveryMutationAndDetail(): void
    {
        $permissions = ['bank' => 1, 'bank.ignore' => 2, 'bank.match' => 2, 'invoices' => 1, 'invoices.mark_paid' => 2, 'purchase_invoices' => 1];
        foreach (['ignore', 'unignore', 'unmatch', 'manualMatch', 'detail', 'matchCandidates', 'splitSuggestions'] as $method) {
            self::assertSame(404, $this->call($method, $permissions, ['invoice_id' => $this->invoice], [], $this->otherSupplier)[0], $method);
        }
        self::assertSame('unmatched', $this->state()['match_status']);
    }

    public function testCandidatesAndSplitsOnlyExposeReadableAgendas(): void
    {
        foreach ([[], ['search' => 'Synthetic']] as $query) {
            [$status, $body] = $this->call('matchCandidates', ['bank' => 1], [], $query);
            self::assertSame(200, $status);
            self::assertSame([], $body['candidates']);
            [$status, $body] = $this->call('matchCandidates', ['bank' => 1, 'invoices' => 1], [], $query);
            self::assertSame(200, $status);
            self::assertNotEmpty($body['candidates']);
            self::assertSame(['invoice'], array_values(array_unique(array_column($body['candidates'], 'type'))));
        }
        self::assertSame([], $this->call('splitSuggestions', ['bank' => 1])[1]['suggestions']);
        $this->pdo->prepare('UPDATE bank_transactions SET amount = -1000 WHERE id = ?')->execute([$this->tx]);
        self::assertSame([], $this->call('splitSuggestions', ['bank' => 1, 'invoices' => 1])[1]['suggestions']);
    }

    public function testClientNoticeDetailHidesConfirmationComparisonAndStaffKeepsIt(): void
    {
        $target = $this->tx;
        $this->pdo->prepare("INSERT INTO bank_statements (supplier_id, source, file_name, file_hash, account_number, bank_code, currency, statement_date, transaction_count)
            VALUES (?, 'email_notice', 'synthetic-notice', ?, '1000000005', '0100', 'CZK', '2099-06-01', 1)")
            ->execute([$this->supplier, hash('sha256', random_bytes(16))]);
        $this->statement = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO bank_transactions (statement_id, source, posted_at, amount, currency, variable_symbol, description)
            VALUES (?, 'email_notice', '2099-06-15', 1000, 'CZK', '1600001', 'Synthetic client notice')")
            ->execute([$this->statement]);
        $this->tx = (int) $this->pdo->lastInsertId();
        [$status, $client] = $this->call('detail', ['bank' => 2, 'bank.match' => 2]);
        self::assertSame(200, $status, json_encode($client));
        self::assertSame($this->tx, $client['transactions'][0]['id']);
        foreach (['candidates', 'blockers', 'authoritative_transaction_id', 'authoritative_statement_id',
            'authoritative_statement_ids', 'confirmation_state', 'confirmation_reason'] as $key) {
            self::assertArrayNotHasKey($key, $client['transactions'][0], $key);
        }
        foreach (['confirmation_summary', 'deletion_blockers', 'can_delete_notices', 'deleted_notice_confirmations'] as $key) {
            self::assertArrayNotHasKey($key, $client, $key);
        }
        $request = $this->request(['bank' => 1])
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->user, 'role' => 'accountant'])
            ->withAttribute('auth.effective_role', new EffectiveRole(0, 'Synthetic staff', 'staff', true, ['bank' => 1]));
        $response = $this->bank->detail($request, new Response(), ['id' => $this->statement]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $staff = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('confirmation_summary', $staff);
        self::assertSame('pending', $staff['transactions'][0]['confirmation_state']);
        self::assertContains($target, array_column($staff['transactions'][0]['candidates'], 'id'));
    }

    public function testReadResponsesRedactBothUnavailableAgendasAndInternalMetadata(): void
    {
        $this->pdo->prepare("UPDATE bank_transactions SET matched_invoice_id = ?, match_status = 'manual' WHERE id = ?")->execute([$this->invoice, $this->tx]);
        $this->pdo->prepare("INSERT INTO payment_matches (supplier_id, bank_transaction_id, purchase_invoice_id, amount, match_type) VALUES (?, ?, ?, 1000, 'manual')")
            ->execute([$this->supplier, $this->tx, $this->purchase]);
        [$status, $detail] = $this->call('detail', ['bank' => 1]);
        self::assertSame(200, $status, json_encode($detail));
        $list = ($this->container->get(BankTransactionListAction::class))($this->request(['bank' => 1]), new Response());
        self::assertSame(200, $list->getStatusCode());
        $movements = json_decode((string) $list->getBody(), true, flags: JSON_THROW_ON_ERROR);
        foreach ([$detail['transactions'][0], $movements['items'][0]] as $row) {
            foreach (['matched_invoice_id', 'matched_varsymbol', 'matched_invoice_amount', 'matched_client_name', 'matched_purchase_invoice_id', 'matched_purchase_ref', 'matched_vendor_name', 'posting'] as $key) {
                self::assertNull($row[$key], $key);
            }
            self::assertSame([], $row['matched_invoices']);
            self::assertSame([], $row['matched_purchase_invoices']);
            self::assertArrayNotHasKey('dimensions', $row);
            self::assertArrayHasKey('ignore_origin', $row);
        }
    }

    public static function ordinaryMatchingPaths(): array
    {
        return [['document'], ['suggestion'], ['release']];
    }

    #[DataProvider('ordinaryMatchingPaths')]
    public function testOrdinaryMatchingDoesNotWaitForAnotherImport(string $path): void
    {
        $suggestion = $path === 'suggestion'
            ? $this->suggestion([['type' => 'invoice', 'invoice_id' => $this->invoice, 'score' => 0.9]]) : null;
        if ($path === 'release') {
            [$status, $body] = $this->call('manualMatch', ['bank' => 1, 'bank.match' => 2, 'invoices' => 1, 'invoices.mark_paid' => 2], ['invoice_id' => $this->invoice]);
            self::assertSame(200, $status, json_encode($body));
        }
        $this->pdo->commit();
        $this->committed = true;
        $importer = Connection::withoutSharedTestConnection(static fn () => Bootstrap::buildContainer()->get(Connection::class));
        $other = $importer->pdo();
        $lock = \MyInvoice\Infrastructure\Database\NamedLockName::for($this->container->get(Connection::class), 'bank-import', (string) $this->supplier);
        try {
            $held = $other->prepare('SELECT GET_LOCK(?, 0)');
            $held->execute([$lock]);
            self::assertSame(1, (int) $held->fetchColumn());
            $this->pdo->exec('SET SESSION max_statement_time=1');
            if ($path === 'document') {
                $request = $this->request(body: ['invoice_id' => $this->invoice])
                    ->withAttribute('auth.effective_role', new EffectiveRole(0, 'Superadmin', 'superadmin', true, [], 'superadmin'));
                $response = $this->container->get(\MyInvoice\Action\Bank\BankPaymentCandidatesAction::class)
                    ->match($request, new Response(), ['id' => $this->tx]);
                self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
            } elseif ($path === 'suggestion') {
                $result = $this->container->get(\MyInvoice\Service\Bank\Match\MatchSuggestionService::class)
                    ->accept($suggestion, $this->supplier, $this->user, 0);
                self::assertSame($this->invoice, $result['invoice_id']);
                self::assertSame('accepted', $this->pdo->query('SELECT status FROM bank_match_suggestions WHERE id=' . $suggestion)->fetchColumn());
            } else {
                $result = $this->container->get(\MyInvoice\Service\Bank\BankTransactionReleaseService::class)
                    ->release($this->supplier, $this->tx, \MyInvoice\Service\Bank\BankTransactionReleaseService::MODE_UNMATCH, $this->user);
                self::assertTrue($result['deleted_payment']);
            }
            self::assertSame($path === 'release' ? 'unmatched' : 'manual', $this->state()['match_status']);
            self::assertSame($path === 'release' ? 0 : 1,
                (int) $this->pdo->query('SELECT COUNT(*) FROM invoice_payments WHERE bank_transaction_id=' . $this->tx)->fetchColumn());
            self::assertFalse($this->pdo->inTransaction());
        } finally {
            $this->pdo->exec('SET SESSION max_statement_time=0');
            $other->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);
            $importer->close();
        }
    }

    public function testAcceptingStaleSuggestionPersistsSupersededState(): void
    {
        $suggestion = $this->suggestion([['type' => 'invoice', 'invoice_id' => $this->invoice, 'score' => 0.9]]);
        $this->pdo->prepare("UPDATE bank_transactions SET match_status='manual' WHERE id=?")->execute([$this->tx]);
        $this->pdo->commit();
        $this->committed = true;
        try {
            $this->container->get(\MyInvoice\Service\Bank\Match\MatchSuggestionService::class)
                ->accept($suggestion, $this->supplier, $this->user, 0);
            self::fail('A suggestion for a matched movement must be refused.');
        } catch (\MyInvoice\Service\Bank\Match\MatchSuggestionException $exception) {
            self::assertSame('already_reviewed', $exception->errorCode);
        }
        self::assertFalse($this->pdo->inTransaction());
        self::assertSame('superseded', $this->pdo->query('SELECT status FROM bank_match_suggestions WHERE id=' . $suggestion)->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM invoice_payments WHERE bank_transaction_id=' . $this->tx)->fetchColumn());
    }

    public function testClientImportWithoutPaymentWriteCannotBypassManualPairingCheck(): void
    {
        $bytes = (string) file_get_contents(dirname(__DIR__, 2) . '/Fixtures/BankIgnoreTransfer/statement.gpc');
        $tmp = tempnam(sys_get_temp_dir(), 'bank-client-');
        file_put_contents($tmp, $bytes);
        try {
            foreach ([
                ['bank' => 1, 'bank.import' => 2],
                ['bank' => 1, 'bank.import' => 2, 'invoices.mark_paid' => 2],
                ['bank' => 1, 'bank.import' => 2, 'purchase_invoices.transition' => 2],
            ] as $permissions) {
                $request = $this->request($permissions, ['account_id' => $this->currency])
                    ->withUploadedFiles(['file' => new UploadedFile($tmp, 'synthetic.gpc', 'text/plain', strlen($bytes))]);
                $response = $this->bank->upload($request, new Response());
                self::assertSame(403, $response->getStatusCode(), (string) $response->getBody());
            }
            self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM bank_statements WHERE supplier_id = ' . $this->supplier)->fetchColumn());
        } finally { unlink($tmp); }
    }

    public function testClientUploadRejectsForeignOverrideAndUnregisteredAccountBeforeImport(): void
    {
        $content = (string) file_get_contents(dirname(__DIR__, 2) . '/Fixtures/BankIgnoreTransfer/statement.gpc');
        $tmp = tempnam(sys_get_temp_dir(), 'bank-client-');
        try {
            foreach ([['account_id' => $this->currency + 1000000], []] as $index => $body) {
                $bytes = $index === 0 ? $content : str_replace('1000000005', '0000000000', $content);
                file_put_contents($tmp, $bytes);
                $request = $this->request(self::IMPORT, $body)
                    ->withUploadedFiles(['file' => new UploadedFile($tmp, 'synthetic.gpc', 'text/plain', strlen($bytes))]);
                $response = $this->bank->upload($request, new Response());
                self::assertSame($index === 0 ? 422 : 409, $response->getStatusCode(), (string) $response->getBody());
            }
            self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM bank_statements WHERE supplier_id = ' . $this->supplier)->fetchColumn());
        } finally { unlink($tmp); }
    }
    private function suggestion(array $candidates): int
    {
        $this->pdo->prepare("INSERT INTO bank_match_suggestions (supplier_id, bank_transaction_id, kind, reason, candidates_json, top_score)
            VALUES (?, ?, 'single', 'no_vs', ?, 0.9)")->execute([$this->supplier, $this->tx, json_encode($candidates, JSON_THROW_ON_ERROR)]);
        return (int) $this->pdo->lastInsertId();
    }

    public function testSuggestionFilteringPreservesCandidateIndicesAndAcceptRechecksPermission(): void
    {
        $candidates = [
            ['type' => 'purchase_invoice', 'purchase_invoice_id' => $this->purchase, 'score' => 0.9],
            ['type' => 'invoice', 'invoice_id' => $this->invoice, 'score' => 0.8],
        ];
        $id = $this->suggestion($candidates);
        self::assertSame([], $this->call('matchSuggestions', ['bank' => 1, 'invoices' => 1])[1]['suggestions']);
        $all = ['bank' => 1, 'bank.match' => 2, 'invoices' => 1, 'invoices.mark_paid' => 2, 'purchase_invoices' => 1];
        self::assertSame($candidates, $this->call('matchSuggestions', $all)[1]['suggestions'][0]['candidates']);
        $this->pdo->commit();
        $this->committed = true;
        // Při odebrání práva po načtení návrhu nesmí potvrzení propašovat PF.
        $request = $this->request(['bank' => 1, 'bank.match' => 2, 'invoices' => 1, 'invoices.mark_paid' => 2], ['candidate_index' => 0]);
        $response = $this->bank->acceptMatchSuggestion($request, new Response(), ['id' => $id]);
        self::assertSame(403, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('unmatched', $this->state()['match_status']);
        self::assertSame('pending', $this->pdo->query('SELECT status FROM bank_match_suggestions WHERE id = ' . $id)->fetchColumn());
        $readOnlyRequest = $this->request(['bank' => 1, 'bank.match' => 2, 'invoices' => 1], ['candidate_index' => 1]);
        $response = $this->bank->acceptMatchSuggestion($readOnlyRequest, new Response(), ['id' => $id]);
        self::assertSame(403, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('unmatched', $this->state()['match_status']);
        self::assertSame('pending', $this->pdo->query('SELECT status FROM bank_match_suggestions WHERE id = ' . $id)->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM invoice_payments WHERE bank_transaction_id = ' . $this->tx)->fetchColumn());
        // Index 1 stále označuje vydanou fakturu; filtr ho nepřesunul na index 0.
        $response = $this->bank->acceptMatchSuggestion($request->withParsedBody(['candidate_index' => 1]), new Response(), ['id' => $id]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($this->invoice, $body['invoice_id']);
        self::assertNull($body['posting']);
        self::assertSame('manual', $this->state()['match_status']);
    }

    public function testSuggestionAcceptWithoutPaymentWriteCannotChangeAnyAgenda(): void
    {
        $other = $this->invoice('1600002', 500);
        $this->pdo->commit();
        $this->committed = true;
        foreach ([
            ['type' => 'invoice', 'invoice_id' => $this->invoice],
            ['type' => 'purchase_invoice', 'purchase_invoice_id' => $this->purchase],
            ['type' => 'split', 'invoice_ids' => [$this->invoice, $other]],
        ] as $candidate) {
            $id = $this->suggestion([$candidate + ['score' => 0.9]]);
            $request = $this->request(['bank' => 1, 'bank.match' => 2, 'invoices' => 1, 'purchase_invoices' => 1]);
            $response = $this->bank->acceptMatchSuggestion($request, new Response(), ['id' => $id]);
            self::assertSame(403, $response->getStatusCode(), (string) $response->getBody());
            self::assertSame('unmatched', $this->state()['match_status']);
            self::assertSame('issued', $this->pdo->query('SELECT status FROM invoices WHERE id = ' . $this->invoice)->fetchColumn());
            self::assertSame('received', $this->pdo->query('SELECT status FROM purchase_invoices WHERE id = ' . $this->purchase)->fetchColumn());
            self::assertSame('pending', $this->pdo->query('SELECT status FROM bank_match_suggestions WHERE id = ' . $id)->fetchColumn());
            self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM invoice_payments WHERE bank_transaction_id = ' . $this->tx)->fetchColumn());
            self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM payment_matches WHERE bank_transaction_id = ' . $this->tx)->fetchColumn());
            self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM bank_match_audit WHERE supplier_id = " . $this->supplier . " AND decision = 'accept'")->fetchColumn());
            $this->pdo->prepare('DELETE FROM bank_match_suggestions WHERE id = ?')->execute([$id]);
        }
    }

    public function testClientCanUploadExistingOwnAccountAndRepeatedUploadIsDuplicate(): void
    {
        $this->pdo->commit();
        $this->committed = true;
        $bytes = file_get_contents(dirname(__DIR__, 2) . '/Fixtures/BankIgnoreTransfer/statement.gpc') . '999CLIENT-' . bin2hex(random_bytes(16)) . "\r\n";
        $tmp = tempnam(sys_get_temp_dir(), 'bank-client-upload-');
        file_put_contents($tmp, $bytes);
        try {
            $request = $this->request(self::IMPORT, ['account_id' => $this->currency]);
            $upload = fn () => $this->bank->upload($request->withUploadedFiles(['file' => new UploadedFile($tmp, 'synthetic.gpc', 'text/plain', strlen($bytes))]), new Response());
            $response = $upload();
            self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
            $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
            $id = (int) $body['statement_id'];
            self::assertSame($this->supplier, (int) $this->pdo->query('SELECT supplier_id FROM bank_statements WHERE id = ' . $id)->fetchColumn());
            self::assertGreaterThan(0, (int) $body['transactions']);
            $response = $upload();
            self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
            self::assertTrue(json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)['duplicate']);
            self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM currencies WHERE supplier_id = ' . $this->supplier)->fetchColumn());
        } finally { unlink($tmp); }
    }

    public function testForeignStatementDownloadsNeverExposeBytes(): void
    {
        $this->pdo->prepare("UPDATE bank_statements SET file_content = 'SYNTHETIC PRIVATE GPC', pdf_content = '%PDF-SYNTHETIC PRIVATE', pdf_name = 'synthetic.pdf' WHERE id = ?")->execute([$this->statement]);
        foreach (['download', 'downloadPdf'] as $method) {
            $response = $this->bank->{$method}($this->request(['bank' => 1], supplier: $this->otherSupplier), new Response(), ['id' => $this->statement]);
            self::assertSame(404, $response->getStatusCode());
            self::assertStringNotContainsString('PRIVATE', (string) $response->getBody());
            $own = $this->bank->{$method}($this->request(['bank' => 1]), new Response(), ['id' => $this->statement]);
            self::assertSame(200, $own->getStatusCode());
            self::assertStringContainsString('PRIVATE', (string) $own->getBody());
        }
    }

    private function movements(array $query = []): array
    {
        $response = ($this->container->get(BankTransactionListAction::class))(
            $this->request(['bank' => 1, 'invoices' => 1, 'purchase_invoices' => 1])->withQueryParams($query), new Response());
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function testClientReadFiltersCorruptForeignStatementReferencesBeforePagination(): void
    {
        $this->pdo->prepare("INSERT INTO bank_statements (supplier_id, source, file_name, file_hash, account_number, bank_code, currency, statement_date)
            VALUES (?, 'gpc', 'synthetic-foreign.gpc', ?, '1000000005', '0100', 'CZK', '2099-07-01')")
            ->execute([$this->otherSupplier, hash('sha256', random_bytes(16))]);
        $foreignStatement = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO bank_transactions (statement_id, posted_at, amount, currency, match_status, matched_invoice_id, description)
            VALUES (?, '2099-07-01', 500, 'CZK', 'manual', ?, 'FOREIGN BANK PRIVATE')")
            ->execute([$foreignStatement, $this->invoice]);
        $foreignTx = (int) $this->pdo->lastInsertId();
        foreach ([[], ['per_page' => 1, 'page' => 1]] as $query) {
            $result = $this->movements($query);
            self::assertSame(1, $result['total']);
            self::assertSame([$this->tx], array_column($result['items'], 'id'));
            self::assertStringNotContainsString('FOREIGN BANK PRIVATE', json_encode($result));
        }
        self::assertSame([], $this->movements(['per_page' => 1, 'page' => 2])['items']);
        self::assertSame(404, $this->bank->detail($this->request(['bank' => 1, 'invoices' => 1]), new Response(), ['id' => $foreignStatement])->getStatusCode());
        self::assertNotSame($this->tx, $foreignTx);
    }

    public function testClientDoesNotOfferMatchingOrIgnoringForPayrollTransferAndAllocation(): void
    {
        $permissions = ['bank' => 1, 'bank.match' => 2, 'bank.ignore' => 2, 'invoices' => 1, 'purchase_invoices' => 1];
        $free = $this->movements()['items'][0];
        self::assertTrue($free['match_available']);
        self::assertTrue($free['ignore_available']);
        $this->pdo->prepare("UPDATE bank_transactions SET amount = -1000 WHERE id = ?")->execute([$this->tx]);
        foreach (['payroll', 'transfer', 'allocation'] as $kind) {
            $this->pdo->exec('SAVEPOINT evidence_case');
            if ($kind === 'payroll') {
                $allocation = $this->seedAllocation($this->pdo, $this->supplier, 'client-bank', 'bank');
                $this->seedBankPaymentMatch($this->pdo, $this->supplier, $allocation, $this->statement, $this->tx, 'client-bank');
            } elseif ($kind === 'transfer') {
                $this->pdo->prepare("INSERT INTO bank_transactions (statement_id, posted_at, amount, currency) VALUES (?, '2099-06-15', 1000, 'CZK')")->execute([$this->statement]);
                $pair = (int) $this->pdo->lastInsertId();
                $this->pdo->prepare("INSERT INTO bank_transfer_matches (supplier_id, out_transaction_id, in_transaction_id, amount, currency) VALUES (?, ?, ?, 1000, 'CZK')")
                    ->execute([$this->supplier, $this->tx, $pair]);
            } elseif ($kind === 'allocation') {
                $this->pdo->prepare("INSERT INTO other_items (supplier_id, side, title, issued_on, due_on, amount, status) VALUES (?, 'payable', 'Synthetic allocation', '2099-06-15', '2099-06-15', 1000, 'confirmed')")
                    ->execute([$this->supplier]);
                $item = (int) $this->pdo->lastInsertId();
                $this->pdo->prepare("INSERT INTO other_item_allocations (supplier_id, other_item_id, bank_transaction_id, amount, payment_on) VALUES (?, ?, ?, 1000, '2099-06-15')")
                    ->execute([$this->supplier, $item, $this->tx]);
            }
            $detail = $this->call('detail', $permissions)[1];
            $list = $this->movements();
            foreach ([$detail['transactions'], $list['items']] as $rows) {
                $row = array_values(array_filter($rows, fn (array $row): bool => (int) $row['id'] === $this->tx))[0];
                self::assertFalse($row['match_available'], $kind);
                self::assertFalse($row['ignore_available'], $kind);
                self::assertNull($row['posting']);
                if ($kind === 'payroll') self::assertSame('payroll', $row['resolution']);
            }
            self::assertSame(409, $this->call('ignore', $permissions)[0], $kind);
            self::assertSame(409, $this->call('manualMatch', $permissions, ['purchase_invoice_id' => $this->purchase])[0], $kind);
            self::assertSame('unmatched', $this->state()['match_status']);
            $this->pdo->exec('ROLLBACK TO SAVEPOINT evidence_case');
            $this->pdo->exec('RELEASE SAVEPOINT evidence_case');
        }
    }

    public function testClientPdfImportRequiresOwnRegisteredAccount(): void
    {
        $bytes = \MyInvoice\Tests\Support\KbDailyStatementPdfFactory::build(
            '1000000005', '0100', 'CZK', '15.06.2099', '7', '0,00', '1 000,00', '1 000,00', '0,00',
            [['description' => 'Synthetic PDF payment', 'amount' => '1 000,00']]);
        $tmp = tempnam(sys_get_temp_dir(), 'client-bank-pdf-');
        file_put_contents($tmp, $bytes);
        try {
            foreach ([['bank' => 1], ['bank' => 1, 'bank.import' => 2], self::IMPORT] as $index => $permissions) {
                $request = $this->request($permissions, ['account_id' => $this->currency + 1000000])
                    ->withUploadedFiles(['file' => new UploadedFile($tmp, 'synthetic.pdf', 'application/pdf', strlen($bytes))]);
                $response = $this->bank->importPdf($request, new Response());
                self::assertSame($index < 2 ? 403 : 422, $response->getStatusCode(), (string) $response->getBody());
            }
            $request = $this->request(self::IMPORT, supplier: $this->otherSupplier)
                ->withUploadedFiles(['file' => new UploadedFile($tmp, 'synthetic.pdf', 'application/pdf', strlen($bytes))]);
            $response = $this->bank->importPdf($request, new Response());
            self::assertSame(409, $response->getStatusCode(), (string) $response->getBody());
            self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM bank_statements WHERE supplier_id IN (' . $this->supplier . ',' . $this->otherSupplier . ')')->fetchColumn());
            self::assertNull($this->pdo->query('SELECT pdf_content FROM bank_statements WHERE id = ' . $this->statement)->fetchColumn());
        } finally { unlink($tmp); }
    }

    public function testClientUnmatchWithTaxDocumentRollsBackAllChanges(): void
    {
        $permissions = ['bank' => 1, 'bank.match' => 2, 'invoices' => 1, 'invoices.mark_paid' => 2];
        self::assertSame(200, $this->call('manualMatch', $permissions, ['invoice_id' => $this->invoice])[0]);
        $taxDocument = $this->invoice('1600009', 1000);
        $this->pdo->prepare("UPDATE invoices SET invoice_type = 'tax_document', parent_invoice_id = ? WHERE id = ?")->execute([$this->invoice, $taxDocument]);
        $this->pdo->prepare('UPDATE invoice_payments SET tax_document_invoice_id = ? WHERE bank_transaction_id = ?')->execute([$taxDocument, $this->tx]);
        $before = $this->state();
        $payments = $this->pdo->query('SELECT * FROM invoice_payments WHERE bank_transaction_id = ' . $this->tx)->fetchAll(PDO::FETCH_ASSOC);
        $suggestion = $this->suggestion([['type' => 'invoice', 'invoice_id' => $this->invoice, 'score' => 0.9]]);
        [$status, $body] = $this->call('unmatch', $permissions);
        self::assertSame(409, $status, json_encode($body));
        self::assertSame('has_tax_document', $body['error']['code']);
        self::assertTrue($this->pdo->inTransaction());
        self::assertSame($before, $this->state());
        self::assertSame($payments, $this->pdo->query('SELECT * FROM invoice_payments WHERE bank_transaction_id = ' . $this->tx)->fetchAll(PDO::FETCH_ASSOC));
        self::assertSame('paid', $this->pdo->query('SELECT status FROM invoices WHERE id = ' . $this->invoice)->fetchColumn());
        self::assertSame('pending', $this->pdo->query('SELECT status FROM bank_match_suggestions WHERE id = ' . $suggestion)->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM activity_log WHERE user_id = " . $this->user . " AND action = 'bank.tx_unmatch'")->fetchColumn());
    }

    public function testClientUnmatchInClosedPeriodRollsBackAllChanges(): void
    {
        $permissions = ['bank' => 1, 'bank.match' => 2, 'invoices' => 1, 'invoices.mark_paid' => 2];
        self::assertSame(200, $this->call('manualMatch', $permissions, ['invoice_id' => $this->invoice])[0]);
        $this->pdo->prepare("UPDATE supplier SET accounting_mode = 'double_entry' WHERE id = ?")->execute([$this->supplier]);
        $this->container->get(\MyInvoice\Service\Accounting\ChartOfAccountsSeeder::class)->seedForSupplier($this->supplier);
        $entry = $this->container->get(\MyInvoice\Service\Accounting\PostingService::class)->postDocument($this->supplier, 'bank', $this->tx,
            [['account_code' => '221', 'side' => 'debit', 'amount' => 1000], ['account_code' => '311', 'side' => 'credit', 'amount' => 1000]],
            ['entry_date' => '2099-06-15', 'description' => 'Synthetic client bank', 'user_id' => $this->user]);
        $this->pdo->prepare("UPDATE accounting_periods SET status = 'closed' WHERE supplier_id = ?")->execute([$this->supplier]);
        $before = $this->state();
        $payments = $this->pdo->query('SELECT * FROM invoice_payments WHERE bank_transaction_id = ' . $this->tx)->fetchAll(PDO::FETCH_ASSOC);
        $suggestion = $this->suggestion([['type' => 'invoice', 'invoice_id' => $this->invoice, 'score' => 0.9]]);
        [$status, $body] = $this->call('unmatch', $permissions);
        self::assertSame(409, $status, json_encode($body));
        self::assertSame('period_closed', $body['error']['code']);
        self::assertTrue($this->pdo->inTransaction());
        self::assertSame($before, $this->state());
        self::assertSame($payments, $this->pdo->query('SELECT * FROM invoice_payments WHERE bank_transaction_id = ' . $this->tx)->fetchAll(PDO::FETCH_ASSOC));
        self::assertSame('paid', $this->pdo->query('SELECT status FROM invoices WHERE id = ' . $this->invoice)->fetchColumn());
        self::assertNull($this->pdo->query('SELECT reversed_by FROM journal_entries WHERE id = ' . $entry)->fetchColumn());
        self::assertSame('pending', $this->pdo->query('SELECT status FROM bank_match_suggestions WHERE id = ' . $suggestion)->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM activity_log WHERE user_id = " . $this->user . " AND action = 'bank.tx_unmatch'")->fetchColumn());
    }

    public function testClientCannotAcceptStaleSuggestionAfterPaymentWasPostedOutsideSaldo(): void
    {
        $this->pdo->prepare("UPDATE supplier SET accounting_mode = 'double_entry' WHERE id = ?")->execute([$this->supplier]);
        $this->container->get(\MyInvoice\Service\Accounting\ChartOfAccountsSeeder::class)->seedForSupplier($this->supplier);
        $suggestion = $this->suggestion([['type' => 'invoice', 'invoice_id' => $this->invoice, 'score' => 0.9]]);
        $entry = $this->container->get(\MyInvoice\Service\Accounting\PostingService::class)->postDocument($this->supplier, 'bank', $this->tx,
            [['account_code' => '221', 'side' => 'debit', 'amount' => 1000], ['account_code' => '648', 'side' => 'credit', 'amount' => 1000]],
            ['entry_date' => '2099-06-15', 'description' => 'Synthetic non-saldo payment', 'user_id' => $this->user]);
        $permissions = ['bank' => 1, 'bank.match' => 2, 'invoices' => 1, 'invoices.mark_paid' => 2];
        self::assertSame(409, $this->call('manualMatch', $permissions, ['invoice_id' => $this->invoice])[0]);
        $this->pdo->commit();
        $this->committed = true;
        $response = $this->bank->acceptMatchSuggestion($this->request($permissions, ['candidate_index' => 0]), new Response(), ['id' => $suggestion]);
        self::assertSame(409, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('unmatched', $this->state()['match_status']);
        self::assertSame('issued', $this->pdo->query('SELECT status FROM invoices WHERE id = ' . $this->invoice)->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM invoice_payments WHERE bank_transaction_id = ' . $this->tx)->fetchColumn());
        self::assertSame('pending', $this->pdo->query('SELECT status FROM bank_match_suggestions WHERE id = ' . $suggestion)->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM bank_match_audit WHERE supplier_id = " . $this->supplier . " AND decision = 'accept'")->fetchColumn());
        self::assertNull($this->pdo->query('SELECT reversed_by FROM journal_entries WHERE id = ' . $entry)->fetchColumn());
    }

}
