<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Bank;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Bank\EmailNoticeReconciler;
use MyInvoice\Service\Invoice\InvoicePaymentService;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Cross-source dedup GPC ← e-mailové avízo (EmailNoticeReconciler).
 *
 * Scénář: platba dorazila nejdřív e-mailovým avízem a spárovala se (i sloučená úhrada
 * / split). Pak se importuje oficiální GPC výpis se stejnou platbou — reconciler musí
 * PŘEVZÍT párování na GPC transakci (přepojit platby), avízo rozpárovat a NEzaložit
 * duplicitní platbu (jinak falešný přeplatek).
 *
 * Izolace: rok 2099, vlastní statementy/transakce/faktury, vše se v tearDown smaže.
 * Soft-skip bez cfg.php / DB / vhodného supplieru.
 */
#[Group('integration')]
final class EmailNoticeReconcilerTest extends TestCase
{
    private Connection $db;
    private InvoicePaymentService $payments;
    private EmailNoticeReconciler $reconciler;
    private \MyInvoice\Service\Bank\StatementMatcher $matcher;
    private int $supplierId = 0;
    private int $clientId = 0;
    private int $currencyId = 0;
    private int $userId = 0;
    private string $account = '';
    private ?string $bankCode = null;

    private const FILE_MARKER = '__entwin__';
    private const VS_A = '2099-70001';
    private const VS_B = '2099-70002';
    private const ALL_VS = [self::VS_A, self::VS_B];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db = $c->get(Connection::class);
            $this->payments = $c->get(InvoicePaymentService::class);
            $this->reconciler = $c->get(EmailNoticeReconciler::class);
            $this->matcher = $c->get(\MyInvoice\Service\Bank\StatementMatcher::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $cur = $pdo->query(
            "SELECT id, supplier_id, account_number, bank_code FROM currencies
              WHERE code = 'CZK' AND account_number IS NOT NULL AND account_number <> ''
              ORDER BY id LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
        if (!$cur) {
            $this->markTestSkipped('Chybí CZK currency s account_number.');
        }
        $this->currencyId = (int) $cur['id'];
        $this->supplierId = (int) $cur['supplier_id'];
        $this->account = (string) $cur['account_number'];
        $this->bankCode = $cur['bank_code'] !== null ? (string) $cur['bank_code'] : null;

        $this->clientId = (int) ($pdo->query("SELECT id FROM clients WHERE supplier_id = {$this->supplierId} ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->clientId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí client/user pro supplier.');
        }

        $this->cleanup();
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->cleanup();
        }
    }

    private function cleanup(): void
    {
        $pdo = $this->db->pdo();
        $place = implode(',', array_fill(0, count(self::ALL_VS), '?'));
        $pdo->prepare(
            "DELETE p FROM invoice_payments p
               JOIN invoices i ON i.id = p.invoice_id
              WHERE i.supplier_id = ? AND i.varsymbol IN ($place)"
        )->execute([$this->supplierId, ...self::ALL_VS]);
        $monthly = $pdo->prepare('SELECT DISTINCT m.monthly_statement_id FROM bank_api_evidence_months m JOIN bank_statements bs ON bs.id = m.evidence_statement_id WHERE bs.file_name LIKE ?');
        $monthly->execute(['%' . self::FILE_MARKER . '%']);
        $monthlyIds = $monthly->fetchAll(PDO::FETCH_COLUMN);
        $pdo->prepare('DELETE link FROM bank_transaction_imports link JOIN bank_statements bs ON bs.id = link.original_statement_id WHERE bs.file_name LIKE ?')
            ->execute(['%' . self::FILE_MARKER . '%']);
        $pdo->prepare('DELETE link FROM bank_api_evidence_months link JOIN bank_statements bs ON bs.id = link.evidence_statement_id WHERE bs.file_name LIKE ?')
            ->execute(['%' . self::FILE_MARKER . '%']);
        $pdo->prepare("DELETE FROM bank_statements WHERE file_name LIKE ?")
            ->execute(['%' . self::FILE_MARKER . '%']);
        foreach ($monthlyIds as $id) {
            $pdo->prepare('DELETE FROM bank_api_months WHERE statement_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM bank_statements WHERE id = ?')->execute([$id]);
        }
        $pdo->prepare('DELETE FROM currencies WHERE supplier_id = ? AND label = "Synthetic API lifecycle"')->execute([$this->supplierId]);
        // payment_matches na ně visí přes FK ON DELETE CASCADE (tx i přijatá faktura).
        $pdo->prepare("DELETE FROM purchase_invoices WHERE supplier_id = ? AND vendor_invoice_number LIKE ?")
            ->execute([$this->supplierId, self::FILE_MARKER . '%']);
        $pdo->prepare("DELETE FROM invoices WHERE supplier_id = ? AND varsymbol IN ($place)")
            ->execute([$this->supplierId, ...self::ALL_VS]);
    }

    private function insertInvoice(string $vs, float $amount): int
    {
        $pdo = $this->db->pdo();
        $d = '2099-06-15';
        $pdo->prepare(
            "INSERT INTO invoices
                (invoice_type, varsymbol, client_id, supplier_id, issue_date, tax_date, due_date,
                 currency_id, status, total_without_vat, total_with_vat, paid_total, created_by)
             VALUES ('invoice', ?, ?, ?, ?, ?, ?, ?, 'issued', ?, ?, 0, ?)"
        )->execute([
            $vs, $this->clientId, $this->supplierId, $d, $d, $d,
            $this->currencyId, $amount, $amount, $this->userId,
        ]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * Vloží výpis + jednu transakci. $source = 'gpc'/'email_notice'/'idoklad',
     * tx source se odvodí ('statement' pro gpc, jinak stejně jako výpis).
     *
     * @return array{0:int,1:int} [statementId, txId]
     */
    private function insertStatementWithTx(string $source, float $amount, string $vs, string $tag, ?string $bankCode = null): array
    {
        $pdo = $this->db->pdo();
        $d = '2099-06-15';
        // `supplier_id` je POVINNÝ: kandidátní dotaz reconcileru ho od SEC-01 tenant
        // scopingu (5f5c7183, migrace 1136) filtruje tvrdě — `bs.supplier_id = ?`.
        // Bez něj zůstane NULL, `NULL = 1` není nikdy true, kandidátů je nula a převzetí
        // se nikdy neprovede. Fixture pocházela z doby před tou změnou a testy padaly
        // od 21. 7.; maskoval to skip kvůli prázdné testovací DB.
        $pdo->prepare(
            "INSERT INTO bank_statements
                (supplier_id, source, file_name, file_hash, account_number, bank_code, currency, statement_date)
             VALUES (?, ?, ?, ?, ?, ?, 'CZK', ?)"
        )->execute([
            $this->supplierId,
            $source,
            self::FILE_MARKER . $tag . '.gpc',
            hash('sha256', self::FILE_MARKER . $tag . $amount . $vs),
            $this->account, $bankCode ?? $this->bankCode, $d,
        ]);
        $statementId = (int) $pdo->lastInsertId();

        $txSource = $source === 'gpc' ? 'statement' : $source;
        $pdo->prepare(
            "INSERT INTO bank_transactions
                (statement_id, source, posted_at, amount, currency, variable_symbol)
             VALUES (?, ?, ?, ?, 'CZK', ?)"
        )->execute([$statementId, $txSource, $d, $amount, $vs]);

        return [$statementId, (int) $pdo->lastInsertId()];
    }

    /** Přijatá faktura pro úhrady vedené přes `payment_matches` (nemají invoice_payments). */
    private function insertPurchaseInvoice(float $total): int
    {
        $pdo = $this->db->pdo();
        $d = '2099-06-15';
        $pdo->prepare(
            "INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, issue_date, tax_date, due_date, received_at,
                 currency_id, vendor_snapshot, total_without_vat, total_vat, total_with_vat, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, '{}', ?, 0, ?, 'received', ?)"
        )->execute([
            $this->supplierId, $this->clientId, self::FILE_MARKER . uniqid('', true),
            $d, $d, $d, $d, $this->currencyId, $total, $total, $this->userId,
        ]);
        return (int) $pdo->lastInsertId();
    }

    /** Úhrada přijaté faktury z banky — `payment_matches.amount` je vždy absolutní. */
    private function insertPaymentMatch(int $txId, int $purchaseInvoiceId, float $amount): void
    {
        $this->db->pdo()->prepare(
            "INSERT INTO payment_matches (supplier_id, bank_transaction_id, purchase_invoice_id, amount, match_type)
             VALUES (?, ?, ?, ?, 'auto')"
        )->execute([$this->supplierId, $txId, $purchaseInvoiceId, $amount]);
    }

    private function payableMatchCountForTx(int $txId): int
    {
        return (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM payment_matches WHERE bank_transaction_id = $txId"
        )->fetchColumn();
    }

    private function payableMatchAmountForTx(int $txId): string
    {
        return (string) $this->db->pdo()->query(
            "SELECT amount FROM payment_matches WHERE bank_transaction_id = $txId LIMIT 1"
        )->fetchColumn();
    }

    /** Označí avízo-transakci za spárovanou (po zaevidování plateb). */
    private function markTxMatched(int $txId, ?int $matchedInvoiceId, string $status = 'manual'): void
    {
        $this->db->pdo()->prepare(
            "UPDATE bank_transactions
                SET match_status = ?, matched_invoice_id = ?, matched_at = NOW(), matched_by = ?
              WHERE id = ?"
        )->execute([$status, $matchedInvoiceId, $this->userId, $txId]);
    }

    private function invoiceStatus(int $id): string
    {
        return (string) $this->db->pdo()->query("SELECT status FROM invoices WHERE id = $id")->fetchColumn();
    }

    private function paymentCountForTx(int $txId): int
    {
        return (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM invoice_payments WHERE bank_transaction_id = $txId"
        )->fetchColumn();
    }

    // ── Převzetí jednoduchého párování ───────────────────────────────────────

    public function testTakeOverSingleMatch(): void
    {
        $invA = $this->insertInvoice(self::VS_A, 1000.00);
        [$emailStmt, $emailTx] = $this->insertStatementWithTx('email_notice', 1000.00, self::VS_A, 'email');
        $this->payments->recordPayment($invA, 1000.00, '2099-06-15', [
            'source' => 'bank', 'bank_transaction_id' => $emailTx, 'created_by' => $this->userId,
        ]);
        $this->markTxMatched($emailTx, $invA, 'auto_exact');
        // matched_count avíza = 1 (jako po jeho spárování)
        $this->db->pdo()->exec("UPDATE bank_statements SET matched_count = 1 WHERE id = $emailStmt");
        self::assertSame('paid', $this->invoiceStatus($invA));

        [, $gpcTx] = $this->insertStatementWithTx('gpc', 1000.00, self::VS_A, 'gpc');
        $result = $this->reconciler->takeOverFromEmailNotice($gpcTx);

        self::assertNotNull($result, 'Mělo dojít k převzetí.');
        self::assertSame($emailTx, $result['email_tx_id']);
        // Platba přepojena na GPC tx, avízo bez plateb.
        self::assertSame(1, $this->paymentCountForTx($gpcTx));
        self::assertSame(0, $this->paymentCountForTx($emailTx));
        // Faktura zůstává zaplacená (žádné dvojí započtení).
        self::assertSame('paid', $this->invoiceStatus($invA));
        $paid = (float) $this->db->pdo()->query("SELECT paid_total FROM invoices WHERE id = $invA")->fetchColumn();
        self::assertSame(1000.0, $paid);

        // GPC tx přebírá match status + matched_invoice_id; avízo je rozpárované.
        $gpc = $this->db->pdo()->query(
            "SELECT match_status, matched_invoice_id FROM bank_transactions WHERE id = $gpcTx"
        )->fetch(PDO::FETCH_ASSOC);
        self::assertSame('auto_exact', $gpc['match_status']);
        self::assertSame($invA, (int) $gpc['matched_invoice_id']);

        $email = $this->db->pdo()->query(
            "SELECT match_status, matched_invoice_id FROM bank_transactions WHERE id = $emailTx"
        )->fetch(PDO::FETCH_ASSOC);
        self::assertSame('unmatched', $email['match_status']);
        self::assertNull($email['matched_invoice_id']);

        // matched_count avízo-výpisu klesl na 0 → smazání výpisu se může nabídnout.
        $mc = (int) $this->db->pdo()->query("SELECT matched_count FROM bank_statements WHERE id = $emailStmt")->fetchColumn();
        self::assertSame(0, $mc);
    }

    /**
     * Stejný účet u JINÉ banky není tentýž účet — převzetí se nesmí provést.
     * Guard nesmí zabírat, když je kód banky na jedné straně neznámý (legacy avíza
     * bez parsovatelného „účet/kód" mají bank_code NULL) — viz test níže.
     */
    public function testNoTakeOverWhenBankCodeDiffers(): void
    {
        $invA = $this->insertInvoice(self::VS_A, 1000.00);
        [, $emailTx] = $this->insertStatementWithTx('email_notice', 1000.00, self::VS_A, 'email-other-bank', '0800');
        $this->payments->recordPayment($invA, 1000.00, '2099-06-15', [
            'source' => 'bank', 'bank_transaction_id' => $emailTx, 'created_by' => $this->userId,
        ]);
        $this->markTxMatched($emailTx, $invA, 'auto_exact');

        [, $gpcTx] = $this->insertStatementWithTx('gpc', 1000.00, self::VS_A, 'gpc-other-bank');

        self::assertNull($this->reconciler->takeOverFromEmailNotice($gpcTx));
        self::assertSame(1, $this->paymentCountForTx($emailTx));
        self::assertSame(0, $this->paymentCountForTx($gpcTx));
    }

    /** Legacy avízo bez bank_code (NULL) nesmí kvůli guardu přijít o převzetí. */
    public function testTakeOverWhenCandidateBankCodeUnknown(): void
    {
        $invA = $this->insertInvoice(self::VS_A, 1000.00);
        [, $emailTx] = $this->insertStatementWithTx('email_notice', 1000.00, self::VS_A, 'email-null-bank', '');
        $this->payments->recordPayment($invA, 1000.00, '2099-06-15', [
            'source' => 'bank', 'bank_transaction_id' => $emailTx, 'created_by' => $this->userId,
        ]);
        $this->markTxMatched($emailTx, $invA, 'auto_exact');

        [, $gpcTx] = $this->insertStatementWithTx('gpc', 1000.00, self::VS_A, 'gpc-null-bank');

        self::assertNotNull($this->reconciler->takeOverFromEmailNotice($gpcTx));
        self::assertSame(1, $this->paymentCountForTx($gpcTx));
        self::assertSame(0, $this->paymentCountForTx($emailTx));
    }

    public function testGpcTakesOverIdokladPaymentAndMarksSecondaryIgnored(): void
    {
        $invA = $this->insertInvoice(self::VS_A, 1000.00);
        [, $idokladTx] = $this->insertStatementWithTx('idoklad', 1000.00, self::VS_A, 'idoklad');
        $this->payments->recordPayment($invA, 1000.00, '2099-06-15', [
            'source' => 'bank', 'bank_transaction_id' => $idokladTx, 'created_by' => $this->userId,
        ]);
        $this->markTxMatched($idokladTx, $invA, 'auto_exact');

        [, $gpcTx] = $this->insertStatementWithTx('gpc', 1000.00, self::VS_A, 'gpc');
        $result = $this->reconciler->takeOverFromEmailNotice($gpcTx);

        self::assertNotNull($result);
        self::assertSame('idoklad', $result['secondary_source']);
        self::assertSame(1, $this->paymentCountForTx($gpcTx));
        self::assertSame(0, $this->paymentCountForTx($idokladTx));
        $secondary = $this->db->pdo()->query(
            "SELECT match_status, matched_invoice_id FROM bank_transactions WHERE id = $idokladTx"
        )->fetch(PDO::FETCH_ASSOC);
        self::assertSame('ignored', $secondary['match_status']);
        self::assertNull($secondary['matched_invoice_id']);
    }

    public function testRelatedBankTransactionFollowsAuthoritativeTwinWithoutPaymentRow(): void
    {
        $invoiceId = $this->insertInvoice(self::VS_A, 1000.00);
        $this->db->pdo()->prepare(
            "UPDATE invoices SET status = 'paid', paid_at = '2099-06-15' WHERE id = ?"
        )->execute([$invoiceId]);

        [, $idokladTx] = $this->insertStatementWithTx('idoklad', 1000.00, self::VS_A, 'idoklad-trace');
        $this->markTxMatched($idokladTx, $invoiceId, 'auto_exact');

        $before = $this->payments->listRelatedBankTransactions($invoiceId, $this->supplierId);
        self::assertCount(1, $before);
        self::assertSame('idoklad', $before[0]['statement_source']);
        self::assertSame($idokladTx, $before[0]['id']);
        self::assertSame(0, $this->paymentCountForTx($idokladTx));

        // Tenant kotva: cizí supplier vazbu nevidí, i když zná ID faktury.
        self::assertSame([], $this->payments->listRelatedBankTransactions($invoiceId, $this->supplierId + 1000));

        [, $gpcTx] = $this->insertStatementWithTx('gpc', 1000.00, self::VS_A, 'gpc-trace');
        self::assertNotNull($this->reconciler->takeOverFromEmailNotice($gpcTx));

        $after = $this->payments->listRelatedBankTransactions($invoiceId, $this->supplierId);
        self::assertCount(1, $after);
        self::assertSame('gpc', $after[0]['statement_source']);
        self::assertSame($gpcTx, $after[0]['id']);
        self::assertSame(self::VS_A, $after[0]['variable_symbol']);
        self::assertSame(1000.0, $after[0]['amount']);
        self::assertSame(0, $this->paymentCountForTx($gpcTx));

        $secondary = $this->db->pdo()->query(
            "SELECT match_status, matched_invoice_id FROM bank_transactions WHERE id = $idokladTx"
        )->fetch(PDO::FETCH_ASSOC);
        self::assertSame('ignored', $secondary['match_status']);
        self::assertNull($secondary['matched_invoice_id']);
    }

    #[\PHPUnit\Framework\Attributes\TestWith(['bank_api'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['gpc'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['pdf'])]
    public function testAuthoritativeMatchRepairsMissingInvoicePaymentOnlyOnce(string $source): void
    {
        $invoiceId = $this->insertInvoice(self::VS_A, 1000.00);
        [$statementId, $txId] = $this->insertStatementWithTx('gpc', 1000.00, self::VS_A, 'missing-payment');
        $this->db->pdo()->prepare('UPDATE bank_statements SET source = ? WHERE id = ?')->execute([$source, $statementId]);
        $this->markTxMatched($txId, $invoiceId, 'auto_exact');

        $this->matcher->match($txId);
        $this->matcher->match($txId);

        self::assertSame(1, $this->paymentCountForTx($txId));
        $invoice = $this->db->pdo()->query("SELECT status, paid_total FROM invoices WHERE id = $invoiceId")->fetch(PDO::FETCH_ASSOC);
        self::assertSame('paid', $invoice['status']);
        self::assertSame(1000.0, (float) $invoice['paid_total']);
    }

    public function testAuthoritativeMatchPreservesManuallyPaidInvoiceWithoutPayment(): void
    {
        $invoiceId = $this->insertInvoice(self::VS_A, 1000.00);
        $this->db->pdo()->prepare("UPDATE invoices SET status = 'paid', paid_at = '2099-06-15' WHERE id = ?")->execute([$invoiceId]);
        [, $txId] = $this->insertStatementWithTx('gpc', 1000.00, self::VS_A, 'manual-paid');
        $this->markTxMatched($txId, $invoiceId, 'auto_exact');
        $this->matcher->match($txId);
        self::assertSame(0, $this->paymentCountForTx($txId));
    }

    #[\PHPUnit\Framework\Attributes\TestWith(['partial'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['currency'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['existing-payment'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['secondary-source'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['proforma-final'])]
    public function testMissingPaymentRepairRejectsAmbiguousEvidence(string $scenario): void
    {
        $invoiceId = $this->insertInvoice(self::VS_A, 1000.00);
        [, $txId] = $this->insertStatementWithTx($scenario === 'secondary-source' ? 'idoklad' : 'gpc', $scenario === 'partial' ? 500.00 : 1000.00, self::VS_A, 'repair-guard');
        $this->markTxMatched($txId, $invoiceId, 'auto_exact');
        if ($scenario === 'currency') {
            $this->db->pdo()->prepare("UPDATE bank_transactions SET currency = 'EUR' WHERE id = ?")->execute([$txId]);
        }
        if ($scenario === 'existing-payment') {
            $this->payments->recordPayment($invoiceId, 500.00, '2099-06-15', ['source' => 'manual']);
        }
        if ($scenario === 'proforma-final') {
            $this->db->pdo()->prepare("UPDATE invoices SET invoice_type = 'proforma' WHERE id = ?")->execute([$invoiceId]);
            $finalId = $this->insertInvoice(self::VS_B, 1000.00);
            $this->db->pdo()->prepare('UPDATE invoices SET parent_invoice_id = ? WHERE id = ?')->execute([$invoiceId, $finalId]);
        }
        $this->matcher->match($txId);
        self::assertSame(0, $this->paymentCountForTx($txId));
        self::assertSame('issued', $this->db->pdo()->query("SELECT status FROM invoices WHERE id = $invoiceId")->fetchColumn());
    }

    public function testMissingPaymentRepairRejectsInvoiceOfAnotherSupplier(): void
    {
        $invoiceId = $this->insertInvoice(self::VS_A, 1000.00);
        [$statementId, $txId] = $this->insertStatementWithTx('gpc', 1000.00, self::VS_A, 'repair-tenant');
        $this->markTxMatched($txId, $invoiceId, 'auto_exact');
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id)
            SELECT 'Synthetic payment repair tenant', 'Test 1', 'Praha', '11000', country_id, 'repair@example.test', default_currency_id, default_vat_rate_id
              FROM supplier WHERE id = ?")->execute([$this->supplierId]);
        $otherSupplier = (int) $pdo->lastInsertId();
        try {
            $pdo->prepare('UPDATE bank_statements SET supplier_id = ? WHERE id = ?')->execute([$otherSupplier, $statementId]);
            $this->matcher->match($txId);
            self::assertSame(0, $this->paymentCountForTx($txId));
            self::assertSame('issued', $pdo->query("SELECT status FROM invoices WHERE id = $invoiceId")->fetchColumn());
        } finally {
            $pdo->prepare('UPDATE bank_statements SET supplier_id = ? WHERE id = ?')->execute([$this->supplierId, $statementId]);
            $pdo->prepare('DELETE FROM supplier WHERE id = ?')->execute([$otherSupplier]);
        }
    }

    #[\PHPUnit\Framework\Attributes\TestWith(['email_notice', false])]
    #[\PHPUnit\Framework\Attributes\TestWith(['email_notice', true])]
    #[\PHPUnit\Framework\Attributes\TestWith(['idoklad', false])]
    public function testBankApiImportKeepsOnePaymentAfterSecondaryEvidence(string $source, bool $unmatch): void
    {
        $pdo = $this->db->pdo();
        $oldAccount = $this->account;
        $oldBank = $this->bankCode;
        $this->account = '1000000005';
        $this->bankCode = '0100';
        $pdo->prepare('INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default, account_number, bank_code)
            VALUES (?, "CZK", "Synthetic API lifecycle", "CZK", "CZK", "CZK", 2, 1, 0, ?, ?)')
            ->execute([$this->supplierId, $this->account, $this->bankCode]);
        $currencyId = (int) $pdo->lastInsertId();
        try {
            $invoiceId = $this->insertInvoice(self::VS_A, 1000.00);
            [, $secondaryTx] = $this->insertStatementWithTx($source, 1000.00, self::VS_A, 'api-lifecycle');
            if ($source === 'email_notice') {
                $this->payments->recordPayment($invoiceId, 1000.00, '2099-06-15', ['source' => 'bank', 'bank_transaction_id' => $secondaryTx]);
            }
            $this->markTxMatched($secondaryTx, $invoiceId, 'auto_exact');
            if ($unmatch) {
                $this->payments->deleteForBankTransaction($secondaryTx);
                $pdo->prepare("UPDATE bank_transactions SET match_status = 'unmatched', matched_invoice_id = NULL, matched_at = NULL WHERE id = ?")->execute([$secondaryTx]);
            }
            $parsed = [
                'header' => ['account_number' => $this->account, 'statement_number' => null, 'statement_date' => '2099-06-15', 'prev_balance' => null, 'curr_balance' => null, 'credit_total' => 1000, 'debit_total' => 0],
                'transactions' => [['posted_at' => '2099-06-15', 'amount' => 1000, 'currency' => 'CZK', 'variable_symbol' => self::VS_A,
                    'constant_symbol' => '', 'specific_symbol' => '', 'counterparty_account' => '', 'counterparty_bank' => '',
                    'counterparty_name' => '', 'description' => 'Synthetic payment', 'bank_ref' => 'SYNTHETIC-API-1']],
            ];
            $importer = new \MyInvoice\Service\Bank\StatementImporter($this->db, new \MyInvoice\Service\Bank\GpcParser(), $this->matcher, $this->reconciler);
            $result = $importer->importConnectedParsed($parsed, 'synthetic-api-lifecycle', self::FILE_MARKER . 'api.json', null, $currencyId, $this->supplierId);
            $importer->importConnectedParsed($parsed, 'synthetic-api-lifecycle-repeat', self::FILE_MARKER . 'api-repeat.json', null, $currencyId, $this->supplierId);
            $payments = $this->payments->listFor($invoiceId);
            self::assertCount(1, $payments);
            self::assertNotSame($secondaryTx, (int) $payments[0]['bank_transaction_id']);
            self::assertSame('paid', $pdo->query("SELECT status FROM invoices WHERE id = $invoiceId")->fetchColumn());
            self::assertSame(1000.0, (float) $pdo->query("SELECT paid_total FROM invoices WHERE id = $invoiceId")->fetchColumn());
            self::assertSame(1, $result['matched']);
        } finally {
            $this->cleanup();
            $pdo->prepare('DELETE FROM currencies WHERE id = ?')->execute([$currencyId]);
            $this->account = $oldAccount;
            $this->bankCode = $oldBank;
        }
    }

    #[\PHPUnit\Framework\Attributes\TestWith([1000.0, 1])]
    #[\PHPUnit\Framework\Attributes\TestWith([500.0, 0])]
    public function testRematchRepairsPaymentAndReportsUnchangedMatchesAccurately(float $amount, int $repaired): void
    {
        $invoiceId = $this->insertInvoice(self::VS_A, 1000.00);
        [$statementId, $txId] = $this->insertStatementWithTx('gpc', $amount, self::VS_A, 'repair-rematch');
        $this->markTxMatched($txId, $invoiceId, 'auto_exact');
        $request = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST', '/api/bank-statements/' . $statementId . '/rematch')
            ->withAttribute(\MyInvoice\Middleware\SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin']);
        $action = Bootstrap::buildContainer()->get(\MyInvoice\Action\Bank\BankStatementAction::class);
        $response = $action->rematch($request, new \Slim\Psr7\Response(), ['id' => (string) $statementId]);
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($repaired, $body['newly_matched']);
        self::assertSame(0, $body['still_unmatched']);
        self::assertSame($repaired, $this->paymentCountForTx($txId));
    }

    public function testIdokladIsIgnoredWhenGpcAlreadyExists(): void
    {
        [, $gpcTx] = $this->insertStatementWithTx('gpc', 1000.00, self::VS_A, 'gpc-first');
        [, $idokladTx] = $this->insertStatementWithTx('idoklad', 1000.00, self::VS_A, 'idoklad-second');

        self::assertSame($gpcTx, $this->reconciler->ignoreSecondaryWhenAuthoritativeTwinExists($idokladTx));
        $status = $this->db->pdo()->query(
            "SELECT match_status FROM bank_transactions WHERE id = $idokladTx"
        )->fetchColumn();
        self::assertSame('ignored', $status);
        self::assertSame(0, $this->paymentCountForTx($idokladTx));
    }

    // ── Převzetí sloučené úhrady (split, migrace 0119) ───────────────────────

    public function testTakeOverSplitMatch(): void
    {
        $invA = $this->insertInvoice(self::VS_A, 1000.00);
        $invB = $this->insertInvoice(self::VS_B, 500.00);
        [, $emailTx] = $this->insertStatementWithTx('email_notice', 1500.00, self::VS_A, 'email');
        $this->payments->recordPayment($invA, 1000.00, '2099-06-15', [
            'source' => 'bank', 'bank_transaction_id' => $emailTx, 'created_by' => $this->userId,
        ]);
        $this->payments->recordPayment($invB, 500.00, '2099-06-15', [
            'source' => 'bank', 'bank_transaction_id' => $emailTx, 'created_by' => $this->userId,
        ]);
        $this->markTxMatched($emailTx, $invA, 'manual');

        [, $gpcTx] = $this->insertStatementWithTx('gpc', 1500.00, self::VS_A, 'gpc');
        $result = $this->reconciler->takeOverFromEmailNotice($gpcTx);

        self::assertNotNull($result);
        // Obě platby přepojené na GPC tx.
        self::assertSame(2, $this->paymentCountForTx($gpcTx));
        self::assertSame(0, $this->paymentCountForTx($emailTx));
        self::assertSame('paid', $this->invoiceStatus($invA));
        self::assertSame('paid', $this->invoiceStatus($invB));
    }

    // ── Bezpečnostní brzdy ───────────────────────────────────────────────────

    public function testAmbiguousCandidatesSkipped(): void
    {
        $invA = $this->insertInvoice(self::VS_A, 1000.00);
        [, $emailTx1] = $this->insertStatementWithTx('email_notice', 1000.00, self::VS_A, 'email1');
        [, $emailTx2] = $this->insertStatementWithTx('email_notice', 1000.00, self::VS_A, 'email2');
        // Dvě identické platby (dvojí avízo) — obě spárované s reálnou platbou na invA
        // (UNIQUE(bank_tx, invoice) povolí 2 řádky, různé bank_transaction_id).
        $this->payments->recordPayment($invA, 1000.00, '2099-06-15', [
            'source' => 'bank', 'bank_transaction_id' => $emailTx1, 'created_by' => $this->userId,
        ]);
        $this->payments->recordPayment($invA, 1000.00, '2099-06-15', [
            'source' => 'bank', 'bank_transaction_id' => $emailTx2, 'created_by' => $this->userId,
        ]);
        $this->markTxMatched($emailTx1, $invA, 'manual');
        $this->markTxMatched($emailTx2, $invA, 'manual');

        [, $gpcTx] = $this->insertStatementWithTx('gpc', 1000.00, self::VS_A, 'gpc');
        $result = $this->reconciler->takeOverFromEmailNotice($gpcTx);

        self::assertNull($result, 'Při >1 kandidátovi se nic nepřevezme.');
        self::assertSame(1, $this->paymentCountForTx($emailTx1), 'Platba zůstává na avízu.');
        self::assertSame(0, $this->paymentCountForTx($gpcTx));
    }

    /** Tenant brzda: dvojník, jehož platba patří JINÉMU supplierovi, se nepřevezme. */
    public function testForeignSupplierTwinNotTakenOver(): void
    {
        $otherSupplier = (int) ($this->db->pdo()->query(
            "SELECT id FROM supplier WHERE id <> {$this->supplierId} ORDER BY id LIMIT 1"
        )->fetchColumn() ?: 0);
        if ($otherSupplier === 0) {
            $this->markTestSkipped('Jen jeden supplier — tenant scope nelze ověřit.');
        }

        $invA = $this->insertInvoice(self::VS_A, 1000.00);
        [, $emailTx] = $this->insertStatementWithTx('email_notice', 1000.00, self::VS_A, 'email');
        $this->payments->recordPayment($invA, 1000.00, '2099-06-15', [
            'source' => 'bank', 'bank_transaction_id' => $emailTx, 'created_by' => $this->userId,
        ]);
        $this->markTxMatched($emailTx, $invA, 'manual');
        // Přepiš vlastnictví platby na jiného existujícího supplierа → mimo scope GPC účtu.
        $this->db->pdo()->prepare(
            "UPDATE invoice_payments SET supplier_id = ? WHERE bank_transaction_id = ?"
        )->execute([$otherSupplier, $emailTx]);

        [, $gpcTx] = $this->insertStatementWithTx('gpc', 1000.00, self::VS_A, 'gpc');
        $result = $this->reconciler->takeOverFromEmailNotice($gpcTx);

        self::assertNull($result, 'Dvojník patřící jinému supplierovi se nepřevezme.');
        self::assertSame(0, $this->paymentCountForTx($gpcTx));
    }

    /** Bez rozpoznaného supplierа (cizí účet) se nic nepřebírá. */
    public function testUnknownAccountNotTakenOver(): void
    {
        $invA = $this->insertInvoice(self::VS_A, 1000.00);
        [, $emailTx] = $this->insertStatementWithTx('email_notice', 1000.00, self::VS_A, 'email');
        $this->payments->recordPayment($invA, 1000.00, '2099-06-15', [
            'source' => 'bank', 'bank_transaction_id' => $emailTx, 'created_by' => $this->userId,
        ]);
        $this->markTxMatched($emailTx, $invA, 'manual');

        // GPC výpis na účtu, který nepatří žádnému supplierovi → resolveSupplierId = 0.
        $pdo = $this->db->pdo();
        $d = '2099-06-15';
        $pdo->prepare(
            "INSERT INTO bank_statements (source, file_name, file_hash, account_number, currency, statement_date)
             VALUES ('gpc', ?, ?, '999000999000', 'CZK', ?)"
        )->execute([self::FILE_MARKER . 'unknown.gpc', hash('sha256', self::FILE_MARKER . 'unknown'), $d]);
        $unknownStmt = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO bank_transactions (statement_id, source, posted_at, amount, currency, variable_symbol)
             VALUES (?, 'statement', ?, 1000.00, 'CZK', ?)"
        )->execute([$unknownStmt, $d, self::VS_A]);
        $gpcTx = (int) $pdo->lastInsertId();

        self::assertNull($this->reconciler->takeOverFromEmailNotice($gpcTx));
    }

    /** Daňový doklad k platbě (proforma): repoint zachová tax_document_invoice_id, nevznikne 2. */
    public function testTakeOverPreservesTaxDocumentLink(): void
    {
        $invA = $this->insertInvoice(self::VS_A, 1000.00);
        $invTaxDoc = $this->insertInvoice(self::VS_B, 1000.00); // placeholder „daňový doklad"
        [, $emailTx] = $this->insertStatementWithTx('email_notice', 1000.00, self::VS_A, 'email');
        $r = $this->payments->recordPayment($invA, 1000.00, '2099-06-15', [
            'source' => 'bank', 'bank_transaction_id' => $emailTx, 'created_by' => $this->userId,
        ]);
        // Naváž daňový doklad na platbu (jako PaymentTaxDocumentCreator).
        $this->db->pdo()->prepare(
            'UPDATE invoice_payments SET tax_document_invoice_id = ? WHERE id = ?'
        )->execute([$invTaxDoc, $r['payment_id']]);
        $this->markTxMatched($emailTx, $invA, 'auto_partial');

        [, $gpcTx] = $this->insertStatementWithTx('gpc', 1000.00, self::VS_A, 'gpc');
        self::assertNotNull($this->reconciler->takeOverFromEmailNotice($gpcTx));

        // Platba je teď na GPC tx a stále nese vazbu na daňový doklad (žádný 2. doklad).
        $link = $this->db->pdo()->query(
            "SELECT tax_document_invoice_id FROM invoice_payments WHERE bank_transaction_id = $gpcTx"
        )->fetchColumn();
        self::assertSame($invTaxDoc, (int) $link);
    }

    public function testUnmatchedTwinNotTakenOver(): void
    {
        $this->insertInvoice(self::VS_A, 1000.00);
        // Avízo dorazilo, ale nespárovalo se (match_status zůstal unmatched).
        $this->insertStatementWithTx('email_notice', 1000.00, self::VS_A, 'email');

        [, $gpcTx] = $this->insertStatementWithTx('gpc', 1000.00, self::VS_A, 'gpc');
        $result = $this->reconciler->takeOverFromEmailNotice($gpcTx);

        self::assertNull($result, 'Nespárované avízo se nepřebírá.');
    }

    public function testVsMismatchNotTakenOver(): void
    {
        $invA = $this->insertInvoice(self::VS_A, 1000.00);
        [, $emailTx] = $this->insertStatementWithTx('email_notice', 1000.00, self::VS_A, 'email');
        $this->payments->recordPayment($invA, 1000.00, '2099-06-15', [
            'source' => 'bank', 'bank_transaction_id' => $emailTx, 'created_by' => $this->userId,
        ]);
        $this->markTxMatched($emailTx, $invA, 'manual');

        // GPC tx má JINÝ VS → není to tatáž platba.
        [, $gpcTx] = $this->insertStatementWithTx('gpc', 1000.00, self::VS_B, 'gpc');
        $result = $this->reconciler->takeOverFromEmailNotice($gpcTx);

        self::assertNull($result, 'Rozdílný VS nesmí převzít cizí párování.');
        self::assertSame(1, $this->paymentCountForTx($emailTx));
    }

    // ── Karetní platby: avízo bez VS i bez protiúčtu ─────────────────────────

    private function setCounterpartyAccount(int $txId, string $account): void
    {
        $this->db->pdo()->prepare(
            'UPDATE bank_transactions SET counterparty_account = ? WHERE id = ?'
        )->execute([$account, $txId]);
    }

    /**
     * Karetní avízo („Blokace") nenese VS ani protiúčet a GPC řádek karty taky ne.
     * Dřív takové párování zůstalo viset na avízu — a avízo se nikdy neúčtuje, takže
     * se platební noha (u cizoměnové faktury včetně kurzového rozdílu) nikdy nedostala
     * do deníku. Při jednoznačném kandidátovi se převzít musí.
     */
    public function testTakeOverCardNoticeWithoutVsOrCounterparty(): void
    {
        $invA = $this->insertInvoice(self::VS_A, 212.88);
        [, $emailTx] = $this->insertStatementWithTx('email_notice', 212.88, '', 'card-email');
        $this->payments->recordPayment($invA, 212.88, '2099-06-15', [
            'source' => 'bank', 'bank_transaction_id' => $emailTx, 'created_by' => $this->userId,
        ]);
        $this->markTxMatched($emailTx, $invA, 'manual');

        [, $gpcTx] = $this->insertStatementWithTx('gpc', 212.88, '', 'card-gpc');
        $result = $this->reconciler->takeOverFromEmailNotice($gpcTx);

        self::assertNotNull($result, 'Karetní platba bez VS i protiúčtu se má převzít.');
        self::assertSame($emailTx, $result['email_tx_id']);
        self::assertSame(1, $this->paymentCountForTx($gpcTx), 'Platba patří na GPC transakci.');
        self::assertSame(0, $this->paymentCountForTx($emailTx), 'Avízo zůstane bez plateb.');
        self::assertSame('paid', $this->invoiceStatus($invA), 'Žádné dvojí započtení.');
    }

    /**
     * Asymetrie = slabá shoda: avízo je bez identity, ale GPC protistranu zná, takže
     * jde nejspíš o běžný převod, ne o tentýž karetní pohyb. Nepřebírat — jinak by
     * shodná částka ve stejný den přetáhla platbu na cizí doklad.
     */
    public function testCardNoticeNotTakenOverWhenGpcKnowsCounterparty(): void
    {
        $invA = $this->insertInvoice(self::VS_A, 212.88);
        [, $emailTx] = $this->insertStatementWithTx('email_notice', 212.88, '', 'card-email2');
        $this->payments->recordPayment($invA, 212.88, '2099-06-15', [
            'source' => 'bank', 'bank_transaction_id' => $emailTx, 'created_by' => $this->userId,
        ]);
        $this->markTxMatched($emailTx, $invA, 'manual');

        [, $gpcTx] = $this->insertStatementWithTx('gpc', 212.88, '', 'card-gpc2');
        $this->setCounterpartyAccount($gpcTx, '2601234567');

        $result = $this->reconciler->takeOverFromEmailNotice($gpcTx);

        self::assertNull($result, 'GPC se známou protistranou se s bezidentitním avízem nepáruje.');
        self::assertSame(1, $this->paymentCountForTx($emailTx), 'Platba zůstává na avízu.');
    }

    /**
     * GPC plní protiúčet u karetních pohybů samými nulami (`0000000000000000`) —
     * to není protistrana, ale „žádná". Dřív se to bralo jako známý účet, spadlo to
     * do asymetrické větve a párování zůstalo viset na avízu (reálný nález: 4 karetní
     * platby z červencového výpisu). Nuly se musí chovat jako prázdno.
     */
    public function testTakeOverCardNoticeWhenGpcCounterpartyIsAllZeros(): void
    {
        $invA = $this->insertInvoice(self::VS_A, 773.50);
        [, $emailTx] = $this->insertStatementWithTx('email_notice', 773.50, '', 'card-zero-email');
        $this->payments->recordPayment($invA, 773.50, '2099-06-15', [
            'source' => 'bank', 'bank_transaction_id' => $emailTx, 'created_by' => $this->userId,
        ]);
        $this->markTxMatched($emailTx, $invA, 'manual');

        [, $gpcTx] = $this->insertStatementWithTx('gpc', 773.50, '', 'card-zero-gpc');
        $this->setCounterpartyAccount($gpcTx, '0000000000000000');

        $result = $this->reconciler->takeOverFromEmailNotice($gpcTx);

        self::assertNotNull($result, 'Nulami vyplněný protiúčet nesmí blokovat převzetí.');
        self::assertSame(1, $this->paymentCountForTx($gpcTx));
        self::assertSame(0, $this->paymentCountForTx($emailTx));
    }

    /**
     * Karetní blokace v cizí měně se zúčtuje jiným kurzem — avízo „Blokace" 831,48
     * × GPC „GITHUB, INC." 848,35. Bez kurzové tolerance zůstala platba na avízu.
     * Převzít a přepsat evidovanou částku na skutečně zúčtovanou (GPC = zdroj pravdy).
     */
    public function testTakeOverCardNoticeWithFxSettlementDifference(): void
    {
        $purchaseId = $this->insertPurchaseInvoice(848.35);
        [, $emailTx] = $this->insertStatementWithTx('email_notice', -831.48, '', 'card-fx-email');
        $this->insertPaymentMatch($emailTx, $purchaseId, 831.48);
        $this->markTxMatched($emailTx, null, 'auto_partial');

        [, $gpcTx] = $this->insertStatementWithTx('gpc', -848.35, '', 'card-fx-gpc');
        $this->setCounterpartyAccount($gpcTx, '0000000000000000');

        $result = $this->reconciler->takeOverFromEmailNotice($gpcTx);

        self::assertNotNull($result, 'Kurzový rozdíl blokace × zúčtování nesmí bránit převzetí.');
        self::assertSame(0, $this->payableMatchCountForTx($emailTx), 'Avízo zůstane bez úhrady.');
        self::assertSame(1, $this->payableMatchCountForTx($gpcTx), 'Úhrada patří na GPC transakci.');
        self::assertSame(
            '848.35',
            $this->payableMatchAmountForTx($gpcTx),
            'Evidovaná částka se má opravit na zúčtovanou, ne zůstat na blokované.',
        );
    }

    /**
     * Kurzová tolerance je fallback, ne konkurence: když je v okně kandidát na haléř,
     * rozhoduje se jen mezi přesnými. Jinak by blízká blokace shodila jinak jednoznačné
     * převzetí na „nejednoznačné".
     */
    public function testExactCandidateWinsOverFxCandidate(): void
    {
        $exactPurchase = $this->insertPurchaseInvoice(848.35);
        $fuzzyPurchase = $this->insertPurchaseInvoice(831.48);

        [, $exactTx] = $this->insertStatementWithTx('email_notice', -848.35, '', 'card-pref-exact');
        $this->insertPaymentMatch($exactTx, $exactPurchase, 848.35);
        $this->markTxMatched($exactTx, null, 'auto_exact');

        [, $fuzzyTx] = $this->insertStatementWithTx('email_notice', -831.48, '', 'card-pref-fuzzy');
        $this->insertPaymentMatch($fuzzyTx, $fuzzyPurchase, 831.48);
        $this->markTxMatched($fuzzyTx, null, 'auto_exact');

        [, $gpcTx] = $this->insertStatementWithTx('gpc', -848.35, '', 'card-pref-gpc');
        $this->setCounterpartyAccount($gpcTx, '0000000000000000');

        $result = $this->reconciler->takeOverFromEmailNotice($gpcTx);

        self::assertNotNull($result, 'Přesný kandidát existuje → převzít ho.');
        self::assertSame($exactTx, $result['email_tx_id']);
        self::assertSame(1, $this->payableMatchCountForTx($fuzzyTx), 'Kurzový kandidát zůstane nedotčený.');
    }

    /**
     * Kurzová tolerance smí hýbat jen úhradami přijatých faktur (`payment_matches`),
     * kde je částka jediným nositelem stavu. U vystavené faktury drží stav
     * denormalizovaný `invoices.paid_total`, který umí přepočítat jen
     * InvoicePaymentService — takový pár nepřebíráme.
     */
    public function testFxToleranceNotAppliedToIssuedInvoicePayment(): void
    {
        $invA = $this->insertInvoice(self::VS_A, 831.48);
        [, $emailTx] = $this->insertStatementWithTx('email_notice', -831.48, '', 'card-fx-issued-email');
        $this->payments->recordPayment($invA, 831.48, '2099-06-15', [
            'source' => 'bank', 'bank_transaction_id' => $emailTx, 'created_by' => $this->userId,
        ]);
        $this->markTxMatched($emailTx, $invA, 'manual');

        [, $gpcTx] = $this->insertStatementWithTx('gpc', -848.35, '', 'card-fx-issued-gpc');
        $this->setCounterpartyAccount($gpcTx, '0000000000000000');

        $result = $this->reconciler->takeOverFromEmailNotice($gpcTx);

        self::assertNull($result, 'Rozdílná částka u vystavené faktury se nepřebírá.');
        self::assertSame(1, $this->paymentCountForTx($emailTx), 'Platba zůstává na avízu.');
    }

    /** Odchylka nad kurzovou toleranci je jiná platba — nepřebírat. */
    public function testCardNoticeNotTakenOverWhenAmountDiffersTooMuch(): void
    {
        $purchaseId = $this->insertPurchaseInvoice(1000.00);
        [, $emailTx] = $this->insertStatementWithTx('email_notice', -831.48, '', 'card-far-email');
        $this->insertPaymentMatch($emailTx, $purchaseId, 831.48);
        $this->markTxMatched($emailTx, null, 'auto_partial');

        [, $gpcTx] = $this->insertStatementWithTx('gpc', -1000.00, '', 'card-far-gpc');
        $this->setCounterpartyAccount($gpcTx, '0000000000000000');

        $result = $this->reconciler->takeOverFromEmailNotice($gpcTx);

        self::assertNull($result, '20% odchylka není kurzový rozdíl.');
        self::assertSame(1, $this->payableMatchCountForTx($emailTx));
    }

    /** Dvě stejné karetní blokace v okně → nejednoznačné, nechat na uživateli. */
    public function testAmbiguousCardNoticesSkipped(): void
    {
        $invA = $this->insertInvoice(self::VS_A, 212.88);
        [, $emailTx1] = $this->insertStatementWithTx('email_notice', 212.88, '', 'card-e1');
        [, $emailTx2] = $this->insertStatementWithTx('email_notice', 212.88, '', 'card-e2');
        $this->payments->recordPayment($invA, 212.88, '2099-06-15', [
            'source' => 'bank', 'bank_transaction_id' => $emailTx1, 'created_by' => $this->userId,
        ]);
        $this->payments->recordPayment($invA, 212.88, '2099-06-15', [
            'source' => 'bank', 'bank_transaction_id' => $emailTx2, 'created_by' => $this->userId,
        ]);
        $this->markTxMatched($emailTx1, $invA, 'manual');
        $this->markTxMatched($emailTx2, $invA, 'manual');

        [, $gpcTx] = $this->insertStatementWithTx('gpc', 212.88, '', 'card-g');
        $result = $this->reconciler->takeOverFromEmailNotice($gpcTx);

        self::assertNull($result, 'Dvě stejné blokace = nejednoznačné, nepřebírat.');
        self::assertSame(0, $this->paymentCountForTx($gpcTx));
    }
}
