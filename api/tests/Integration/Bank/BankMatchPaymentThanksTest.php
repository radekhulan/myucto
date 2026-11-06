<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Bank;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Bank\BankTransactionReleaseService;
use MyInvoice\Service\Bank\StatementMatcher;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * #127 — automatické spárování platby z banky (StatementMatcher::match) musí poslat
 * děkovný e-mail za úhradu stejně jako ruční mark-paid / manualMatch.
 *
 * Dřív poděkování posílala JEN MarkPaidAction a BankStatementAction::manualMatch.
 * Automatické cesty (GPC import, e-mailové bankovní avízo, cron) ale jdou přes
 * match(), který fakturu označil paid napřímo a PaymentThanksMailer nikdy nezavolal —
 * proto u uživatele přišlo avízo, faktura zpaid, ale děkovný e-mail nikde.
 *
 * Test pozoruje vyvolání maileru přes activity_log: supplier má poděkování zapnuté
 * (enabled + auto_send), faktura ale nemá příjemce → mailer deterministicky zaloguje
 * `invoice.payment_thanks_skipped` (reason no_recipient) BEZ pokusu o SMTP. Před fixem
 * žádný takový záznam nevznikne (mailer se z této cesty nevolal vůbec).
 *
 * Soft-skip bez cfg.php / DB (CI runner bez DB).
 */
#[Group('integration')]
final class BankMatchPaymentThanksTest extends TestCase
{
    private Connection $db;
    private StatementMatcher $matcher;
    private int $supplierId = 0;
    private int $countryId = 0;

    private int $currencyId = 0;
    private int $clientId = 0;
    private int $invoiceId = 0;
    private int $statementId = 0;
    private int $txId = 0;

    /** Záloha původního nastavení poděkování dodavatele (obnova v tearDown). */
    private ?int $origEnabled = null;
    private ?int $origAutoSend = null;
    private ?int $origMaxAgeDays = null;
    private BankTransactionReleaseService $release;

    private string $account = '8880000127';
    private string $bankCode = '0100';
    private string $varsymbol = '8880000127';

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $c = Bootstrap::buildContainer();
            $this->db = $c->get(Connection::class);
            $this->matcher = $c->get(StatementMatcher::class);
            $this->release = $c->get(BankTransactionReleaseService::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->countryId = (int) ($pdo->query('SELECT id FROM countries ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->countryId === 0) {
            $this->markTestSkipped('Chybí supplier / country v DB.');
        }

        // Zapni poděkování za úhradu (enabled + auto_send) — uložen původní stav.
        $row = $pdo->query(
            "SELECT payment_thanks_enabled, payment_thanks_auto_send FROM supplier WHERE id = {$this->supplierId}"
        )->fetch(PDO::FETCH_ASSOC) ?: [];
        $this->origEnabled = (int) ($row['payment_thanks_enabled'] ?? 0);
        $this->origAutoSend = (int) ($row['payment_thanks_auto_send'] ?? 0);
        $pdo->prepare(
            'UPDATE supplier SET payment_thanks_enabled = 1, payment_thanks_auto_send = 1 WHERE id = ?'
        )->execute([$this->supplierId]);
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $pdo = $this->db->pdo();
        if ($this->invoiceId > 0) {
            $pdo->prepare("DELETE FROM activity_log WHERE entity_type = 'invoice' AND entity_id = ?")->execute([$this->invoiceId]);
            $pdo->prepare('DELETE FROM invoices WHERE id = ?')->execute([$this->invoiceId]);
        }
        if ($this->statementId > 0) {
            // bank_transactions padají přes ON DELETE CASCADE.
            $pdo->prepare('DELETE FROM bank_statements WHERE id = ?')->execute([$this->statementId]);
        }
        if ($this->clientId > 0) {
            $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$this->clientId]);
        }
        if ($this->currencyId > 0) {
            $pdo->prepare('DELETE FROM currencies WHERE id = ?')->execute([$this->currencyId]);
        }
        if ($this->origEnabled !== null) {
            $pdo->prepare('UPDATE supplier SET payment_thanks_enabled = ?, payment_thanks_auto_send = ? WHERE id = ?')
                ->execute([$this->origEnabled, $this->origAutoSend, $this->supplierId]);
        }
        if ($this->origMaxAgeDays !== null) {
            $pdo->prepare('UPDATE supplier SET payment_thanks_max_age_days = ? WHERE id = ?')
                ->execute([$this->origMaxAgeDays, $this->supplierId]);
        }
        $this->db->close();
    }

    public function testAutoExactMatchTriggersPaymentThanksMailer(): void
    {
        $pdo = $this->db->pdo();

        // CZK měna s testovacím účtem — matcher z ní určí supplier_id výpisu.
        $pdo->prepare(
            'INSERT INTO currencies
                (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default, account_number, bank_code)
             VALUES (?, ?, ?, ?, ?, ?, 2, 0, 0, ?, ?)'
        )->execute([$this->supplierId, 'CZK', 'TEST CZK #127', 'Kč', 'koruna', 'koruna', $this->account, $this->bankCode]);
        $this->currencyId = (int) $pdo->lastInsertId();

        // Klient BEZ e-mailu (main_email = '') → mailer skončí na no_recipient (žádné SMTP).
        $pdo->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, main_email, currency_default_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$this->supplierId, 'TEST Klient #127', 'Ulice 1', 'Praha', '11000', $this->countryId, '', $this->currencyId]);
        $this->clientId = (int) $pdo->lastInsertId();

        $userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0) ?: null;
        $amount = 1210.00;
        $pdo->prepare(
            "INSERT INTO invoices
                (invoice_type, varsymbol, client_id, supplier_id, issue_date, tax_date, due_date,
                 currency_id, status, total_without_vat, total_with_vat, created_by)
             VALUES ('invoice', ?, ?, ?, CURDATE(), CURDATE(), CURDATE(), ?, 'issued', 1000, ?, ?)"
        )->execute([$this->varsymbol, $this->clientId, $this->supplierId, $this->currencyId, $amount, $userId]);
        $this->invoiceId = (int) $pdo->lastInsertId();

        // Bankovní výpis + příchozí transakce přesně na VS + částku faktury.
        $pdo->prepare(
            'INSERT INTO bank_statements
                (file_name, file_hash, account_number, bank_code, currency, statement_date)
             VALUES (?, ?, ?, ?, ?, CURDATE())'
        )->execute(['TEST-127.gpc', hash('sha256', 'test-127:' . $this->varsymbol), $this->account, $this->bankCode, 'CZK']);
        $this->statementId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO bank_transactions
                (statement_id, posted_at, amount, currency, variable_symbol, match_status)
             VALUES (?, CURDATE(), ?, ?, ?, ?)'
        )->execute([$this->statementId, $amount, 'CZK', $this->varsymbol, 'unmatched']);
        $this->txId = (int) $pdo->lastInsertId();

        // ── act ────────────────────────────────────────────────────────────────
        $result = $this->matcher->match($this->txId);

        // ── assert ──────────────────────────────────────────────────────────────
        self::assertSame('auto_exact', $result['status'] ?? null, 'transakce se musí napárovat přesně');
        self::assertSame($this->invoiceId, (int) ($result['invoice_id'] ?? 0));

        $paidStatus = $pdo->query("SELECT status FROM invoices WHERE id = {$this->invoiceId}")->fetchColumn();
        self::assertSame('paid', $paidStatus, 'faktura musí přejít do paid');

        // Klíčové: mailer byl z auto cesty skutečně vyvolán (před fixem žádný takový log).
        $logStmt = $pdo->prepare(
            "SELECT action, payload FROM activity_log
              WHERE entity_type = 'invoice' AND entity_id = ?
                AND action LIKE 'invoice.payment_thanks%'
              ORDER BY id DESC LIMIT 1"
        );
        $logStmt->execute([$this->invoiceId]);
        $log = $logStmt->fetch(PDO::FETCH_ASSOC);

        self::assertNotFalse($log, 'auto-match musí vyvolat PaymentThanksMailer (activity_log invoice.payment_thanks_*)');
        self::assertSame('invoice.payment_thanks_skipped', $log['action'],
            'bez příjemce mailer loguje skip (ne failed/sent) — potvrzuje, že se mailer rozběhl bez SMTP');
        $payload = json_decode((string) $log['payload'], true);
        self::assertSame('no_recipient', $payload['reason'] ?? null);
        self::assertSame('bank_match', $payload['trigger'] ?? null,
            'trigger musí být bank_match (respektuje supplier auto_send přepínač)');
    }

    /**
     * Platba starší než `payment_thanks_max_age_days` (pozdě nahraný výpis) už
     * automatické poděkování nevyvolá — zákazník by v říjnu dostal díky za červenec.
     */
    public function testAutoMatchSkipsThanksForPaymentOlderThanSupplierLimit(): void
    {
        $this->setMaxAgeDays(30);
        $this->createFixture('CURDATE() - INTERVAL 31 DAY');

        $result = $this->matcher->match($this->txId);

        self::assertSame('auto_exact', $result['status'] ?? null);
        self::assertSame('stale_payment', $this->lastThanksReason());
    }

    /** Limit 0 = bez omezení stáří, stará platba projde až na kontrolu příjemce. */
    public function testZeroMaxAgeDisablesStalePaymentGuard(): void
    {
        $this->setMaxAgeDays(0);
        $this->createFixture('CURDATE() - INTERVAL 400 DAY');

        $this->matcher->match($this->txId);

        self::assertSame('no_recipient', $this->lastThanksReason());
    }

    /**
     * Smazání výpisu platbu zruší a faktura je zase neuhrazená. Po opětovném nahrání
     * výpisu se táž platba spáruje znovu — automatické poděkování se nesmí poslat,
     * i když je platba čerstvá a poděkování dřív neodešlo.
     */
    public function testStatementDeleteSuppressesThanksAfterReimport(): void
    {
        $this->setMaxAgeDays(0);
        $this->createFixture('CURDATE()');
        $pdo = $this->db->pdo();

        $this->matcher->match($this->txId);
        self::assertSame('paid', $pdo->query("SELECT status FROM invoices WHERE id = {$this->invoiceId}")->fetchColumn());

        $this->release->release($this->supplierId, $this->txId, BankTransactionReleaseService::MODE_DELETE);
        $row = $pdo->query("SELECT status, payment_thanks_suppressed_at FROM invoices WHERE id = {$this->invoiceId}")
            ->fetch(PDO::FETCH_ASSOC);
        self::assertNotSame('paid', $row['status'], 'smazání výpisu vrací fakturu mezi neuhrazené');
        self::assertNotNull($row['payment_thanks_suppressed_at']);
        $released = $pdo->query(
            "SELECT payload FROM activity_log
              WHERE entity_type = 'invoice' AND entity_id = {$this->invoiceId} AND action = 'invoice.bank_payment_released'"
        )->fetchColumn();
        self::assertNotFalse($released, 'zrušení platby smazáním výpisu musí být v aktivitě faktury');
        $released = json_decode((string) $released, true);
        self::assertSame(BankTransactionReleaseService::MODE_DELETE, $released['mode'] ?? null);
        self::assertTrue($released['payment_deleted'] ?? null);
        self::assertTrue($released['thanks_suppressed'] ?? null);

        $this->matcher->match($this->txId);

        self::assertSame('paid', $pdo->query("SELECT status FROM invoices WHERE id = {$this->invoiceId}")->fetchColumn());
        self::assertSame('suppressed_after_release', $this->lastThanksReason());
    }

    /** Zrušení chybného párování neznamená, že platba už jednou proběhla — značka nevzniká. */
    public function testUnmatchDoesNotSuppressThanks(): void
    {
        $this->setMaxAgeDays(0);
        $this->createFixture('CURDATE()');

        $this->matcher->match($this->txId);
        $this->release->release($this->supplierId, $this->txId, BankTransactionReleaseService::MODE_UNMATCH);

        self::assertNull($this->db->pdo()
            ->query("SELECT payment_thanks_suppressed_at FROM invoices WHERE id = {$this->invoiceId}")->fetchColumn());
    }

    /** Vypnuté automatické poděkování dřív skončilo bez stopy v aktivitě faktury. */
    public function testAutoDisabledSkipIsLogged(): void
    {
        $this->db->pdo()->prepare('UPDATE supplier SET payment_thanks_auto_send = 0 WHERE id = ?')->execute([$this->supplierId]);
        $this->createFixture('CURDATE()');

        $this->matcher->match($this->txId);

        self::assertSame('auto_disabled', $this->lastThanksReason());
    }

    /** Firma bez poděkování nesmí mít přeskočení v aktivitě u každého párování z banky. */
    public function testDisabledFeatureLeavesNoSkipOnBankMatch(): void
    {
        $this->db->pdo()->prepare('UPDATE supplier SET payment_thanks_enabled = 0 WHERE id = ?')->execute([$this->supplierId]);
        $this->createFixture('CURDATE()');

        $this->matcher->match($this->txId);

        self::assertSame('paid', $this->db->pdo()
            ->query("SELECT status FROM invoices WHERE id = {$this->invoiceId}")->fetchColumn());
        self::assertNull($this->lastThanksReason());
    }

    private function setMaxAgeDays(int $days): void
    {
        $pdo = $this->db->pdo();
        $this->origMaxAgeDays ??= (int) $pdo->query(
            "SELECT payment_thanks_max_age_days FROM supplier WHERE id = {$this->supplierId}"
        )->fetchColumn();
        $pdo->prepare('UPDATE supplier SET payment_thanks_max_age_days = ? WHERE id = ?')->execute([$days, $this->supplierId]);
    }

    /** Faktura bez příjemce + výpis s příchozí platbou na její VS a částku. */
    private function createFixture(string $postedAtSql): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO currencies
                (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default, account_number, bank_code)
             VALUES (?, ?, ?, ?, ?, ?, 2, 0, 0, ?, ?)'
        )->execute([$this->supplierId, 'CZK', 'TEST CZK thanks', 'Kč', 'koruna', 'koruna', $this->account, $this->bankCode]);
        $this->currencyId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, main_email, currency_default_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$this->supplierId, 'TEST Klient thanks', 'Ulice 1', 'Praha', '11000', $this->countryId, '', $this->currencyId]);
        $this->clientId = (int) $pdo->lastInsertId();

        $amount = 1210.00;
        $pdo->prepare(
            "INSERT INTO invoices
                (invoice_type, varsymbol, client_id, supplier_id, issue_date, tax_date, due_date,
                 currency_id, status, total_without_vat, total_with_vat)
             VALUES ('invoice', ?, ?, ?, {$postedAtSql}, {$postedAtSql}, {$postedAtSql}, ?, 'issued', 1000, ?)"
        )->execute([$this->varsymbol, $this->clientId, $this->supplierId, $this->currencyId, $amount]);
        $this->invoiceId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            "INSERT INTO bank_statements
                (supplier_id, file_name, file_hash, account_number, bank_code, currency, statement_date)
             VALUES (?, ?, ?, ?, ?, ?, {$postedAtSql})"
        )->execute([$this->supplierId, 'TEST-thanks.gpc', hash('sha256', 'test-thanks:' . $this->varsymbol), $this->account, $this->bankCode, 'CZK']);
        $this->statementId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            "INSERT INTO bank_transactions
                (statement_id, posted_at, amount, currency, variable_symbol, match_status)
             VALUES (?, {$postedAtSql}, ?, ?, ?, 'unmatched')"
        )->execute([$this->statementId, $amount, 'CZK', $this->varsymbol]);
        $this->txId = (int) $pdo->lastInsertId();
    }

    private function lastThanksReason(): ?string
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT payload FROM activity_log
              WHERE entity_type = 'invoice' AND entity_id = ? AND action = 'invoice.payment_thanks_skipped'
              ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$this->invoiceId]);
        $payload = json_decode((string) $stmt->fetchColumn(), true);
        return is_array($payload) ? ($payload['reason'] ?? null) : null;
    }
}
