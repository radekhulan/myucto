<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Bank;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Bank\Match\PaymentMatchAuditChecker;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Featura I (private/REAL_data_followup_UX.md) — audit spárovaných plateb banka↔faktura.
 * Vzor {@see \MyInvoice\Tests\Integration\Currency\CnbRateDeviationCheckerTest} /
 * {@see \MyInvoice\Tests\Integration\Accounting\MonthlyCheckTest}: izolovaný supplier,
 * reálná DB v transakci s rollbackem v tearDown.
 *
 * Reálný nález, který kontrola má chytit: ruční spárování CZK platby (popis "optika")
 * jako úhrady USD faktury Navicat zmaterializovalo vymyšlenou kurzovou ztrátu na
 * transakci, která je v korunách — {@see testFlagsFxOnCzkCzkTransaction}. Testy níže
 * pokrývají všechny 4 signály + konzervativní vyjmutí legitimních případů (AVYX — CZK
 * úhrada cizoměnového dokladu — a částečné úhrady).
 */
#[Group('integration')]
final class PaymentMatchAuditCheckerTest extends TestCase
{
    private const YEAR = 2096;
    private const STARTS_ON = self::YEAR . '-01-01';
    private const ENDS_ON = self::YEAR . '-12-31';

    private Connection $db;
    private PostingService $posting;
    private PaymentMatchAuditChecker $checker;

    private int $supplierId = 0;
    private int $userId = 0;
    private int $czkId = 0;
    private int $eurId = 0;
    private int $usdId = 0;
    private int $vendorId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db = $container->get(Connection::class);
            $this->posting = $container->get(PostingService::class);
            $this->checker = $container->get(PaymentMatchAuditChecker::class);
            $seeder = $container->get(ChartOfAccountsSeeder::class);
            $periods = $container->get(AccountingPeriodRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $base = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($base === 0 || $this->userId === 0 || $czId === 0) {
            $this->markTestSkipped('Chybí základní data v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        $stmt = $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id, accounting_mode)
             SELECT ?, "Testovací", "Praha", "11000", country_id, ?, default_currency_id, default_vat_rate_id, "double_entry"
               FROM supplier WHERE id = ?'
        );
        $stmt->execute(['Audit plateb s.r.o.', 'pmadev@example.com', $base]);
        $this->supplierId = (int) $pdo->lastInsertId();

        $seeder->seedForSupplier($this->supplierId);
        $periods->create($this->supplierId, self::YEAR, self::STARTS_ON, self::ENDS_ON);

        $this->czkId = (int) ($pdo->query("SELECT id FROM currencies WHERE supplier_id = {$this->supplierId} AND code = 'CZK' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->czkId === 0) {
            $cur = $pdo->prepare('INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, is_active) VALUES (?, "CZK", "CZK", "Kč", "Koruna", "Czech koruna", 1)');
            $cur->execute([$this->supplierId]);
            $this->czkId = (int) $pdo->lastInsertId();
        }
        $cur = $pdo->prepare('INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, is_active) VALUES (?, "EUR", "EUR", "€", "Euro", "Euro", 1)');
        $cur->execute([$this->supplierId]);
        $this->eurId = (int) $pdo->lastInsertId();

        $cur = $pdo->prepare('INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, is_active) VALUES (?, "USD", "USD", "$", "Dolar", "US dollar", 1)');
        $cur->execute([$this->supplierId]);
        $this->usdId = (int) $pdo->lastInsertId();

        $vendor = $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, main_email, currency_default_id, is_customer, is_vendor)
             VALUES (?, "Navicat Premium s.r.o.", "Ulice 1", "Praha", "11000", ?, "vendor@example.com", ?, 0, 1)'
        );
        $vendor->execute([$this->supplierId, $czId, $this->czkId]);
        $this->vendorId = (int) $pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
    }

    // ── fixtures ──────────────────────────────────────────────────────────────

    private function createPurchaseInvoice(string $number, int $currencyId, float $totalWithVat, float $exchangeRate, string $status, string $date, ?string $vendorName = null): int
    {
        $pdo = $this->db->pdo();
        // Vlastní dodavatel jen tam, kde test na jménu záleží (porovnání protistrany).
        $vendorId = $vendorName === null ? $this->vendorId : $this->createVendor($vendorName);
        $stmt = $pdo->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date,
                 due_date, received_at, currency_id, reverse_charge, vendor_snapshot,
                 total_without_vat, total_vat, total_with_vat, status, exchange_rate,
                 vat_classification_code, vat_deduction, created_by, paid_at)
             VALUES (?, ?, ?, "invoice", ?, ?, ?, ?, ?, 0, "{}", ?, 0, ?, ?, ?, "1", "full", ?, ?)'
        );
        $paidAt = $status === 'paid' ? $date : null;
        $stmt->execute([
            $this->supplierId, $vendorId, $number, $date, $date, $date, $date,
            $currencyId, $totalWithVat, $totalWithVat, $status, $exchangeRate, $this->userId, $paidAt,
        ]);
        return (int) $pdo->lastInsertId();
    }

    /** Dodavatel se zadaným názvem — pro testy porovnání protistrany. */
    private function createVendor(string $name): int
    {
        $pdo = $this->db->pdo();
        $czId = (int) $pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn();
        $stmt = $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, main_email, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Ulice 1", "Praha", "11000", ?, "vendor2@example.com", ?, 0, 1)'
        );
        $stmt->execute([$this->supplierId, $name, $czId, $this->czkId]);

        return (int) $pdo->lastInsertId();
    }

    private function createBankTx(string $currency, float $amount, string $date, ?string $counterpartyName = null): int
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO bank_statements (supplier_id, file_name, file_hash, account_number, currency, statement_date)
             VALUES (?, "pma-test.gpc", ?, "123456789/0100", ?, ?)'
        );
        $stmt->execute([$this->supplierId, hash('sha256', 'pma-' . microtime(true) . random_int(1, 999999)), $currency, $date]);
        $statementId = (int) $pdo->lastInsertId();

        $stmt = $pdo->prepare(
            'INSERT INTO bank_transactions (statement_id, posted_at, amount, currency, variable_symbol, match_status, counterparty_name)
             VALUES (?, ?, ?, ?, ?, "manual", ?)'
        );
        $vs = (string) random_int(1000000, 9999999);
        $stmt->execute([$statementId, $date, $amount, $currency, $vs, $counterpartyName]);
        return (int) $pdo->lastInsertId();
    }

    private function linkPaymentMatch(int $txId, int $purchaseInvoiceId, float $amount): void
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO payment_matches (supplier_id, bank_transaction_id, purchase_invoice_id, amount, match_type)
             VALUES (?, ?, ?, ?, "manual")'
        );
        $stmt->execute([$this->supplierId, $txId, $purchaseInvoiceId, $amount]);
    }

    /** @return array<string,mixed>|null první nález pro danou transakci. */
    private function findForTx(array $items, int $txId): ?array
    {
        foreach ($items as $it) {
            if ($it['bank_transaction_id'] === $txId) {
                return $it;
            }
        }
        return null;
    }

    // ── testy ─────────────────────────────────────────────────────────────────

    /** Cizí měna transakce ≠ cizí měna dokladu (USD tx na EUR fakturu) — bez konverzního základu. */
    public function testFlagsCurrencyMismatchWhenNeitherSideIsCzk(): void
    {
        $date = self::YEAR . '-03-10';
        $pfId = $this->createPurchaseInvoice('PMA-CM-001', $this->eurId, 1000.0, 25.0, 'paid', $date);
        $txId = $this->createBankTx('USD', -1080.0, $date);
        $this->linkPaymentMatch($txId, $pfId, 1080.0);

        $items = $this->checker->audit($this->supplierId, self::YEAR . '-01-01', self::YEAR . '-12-31');
        $found = $this->findForTx($items, $txId);

        self::assertNotNull($found, 'USD transakce na EUR fakturu musí být nahlášena.');
        self::assertContains('currency_mismatch', $found['issues']);
        self::assertSame('USD', $found['tx_currency']);
        self::assertSame('EUR', $found['doc_currency']);
    }

    /**
     * AVYX případ (REKONCILIACE-2026-07-15.md) — CZK transakce hradící EUR fakturu
     * přepočtem kurzem dokladu je LEGITIMNÍ, nesmí se flagovat jako currency_mismatch
     * ani amount_mismatch, pokud částka odpovídá kurzu v toleranci.
     */
    public function testDoesNotFlagLegitimateCzkSettlementOfForeignInvoice(): void
    {
        $date = self::YEAR . '-04-05';
        $pfId = $this->createPurchaseInvoice('PMA-FX-OK-001', $this->eurId, 1000.0, 25.0, 'paid', $date);
        // 1000 EUR × 25 = 25 000 Kč přesně.
        $txId = $this->createBankTx('CZK', -25000.0, $date);
        $this->linkPaymentMatch($txId, $pfId, 25000.0);

        $items = $this->checker->audit($this->supplierId, self::YEAR . '-01-01', self::YEAR . '-12-31');
        $found = $this->findForTx($items, $txId);

        self::assertNull($found, 'Legitimní CZK úhrada cizoměnové faktury (AVYX vzor) se nesmí flagovat.');
    }

    /** Částka spárování mimo toleranci 1 Kč — jednorázová plná úhrada CZK faktury. */
    public function testFlagsAmountMismatchBeyondTolerance(): void
    {
        $date = self::YEAR . '-05-12';
        $pfId = $this->createPurchaseInvoice('PMA-AM-001', $this->czkId, 1210.0, 1.0, 'paid', $date);
        $txId = $this->createBankTx('CZK', -1200.0, $date); // rozdíl 10 Kč > tolerance 1 Kč
        $this->linkPaymentMatch($txId, $pfId, 1200.0);

        $items = $this->checker->audit($this->supplierId, self::YEAR . '-01-01', self::YEAR . '-12-31');
        $found = $this->findForTx($items, $txId);

        self::assertNotNull($found, 'Částka mimo toleranci musí být nahlášena.');
        self::assertContains('amount_mismatch', $found['issues']);
        self::assertEqualsWithDelta(-10.0, $found['detail']['amount_mismatch']['diff'], 0.01);
        self::assertSame('CZK', $found['currency']);
        self::assertEqualsWithDelta(10.0, $found['impact_czk'], 0.01);
    }

    /**
     * Dopad cizoměnového nálezu je v KORUNÁCH, ne v měně dokladu.
     *
     * EUR faktura hrazená v EUR se porovnává v eurech, takže rozdíl vyjde 3,67 EUR.
     * Kdyby se tohle číslo poslalo do `impact_czk`, dělo by se dvojí: v detailu by
     * u korunového sloupce svítila zkratka EUR (táž záměna, jakou měla kontrola
     * kurzových rozdílů), a hlavně by se podle něj řadily nálezy — 100 EUR by se
     * zařadilo pod 200 Kč, protože se porovnávají holá čísla.
     */
    public function testForeignCurrencyImpactIsConvertedToCzk(): void
    {
        $date = self::YEAR . '-05-14';
        // Faktura 236,84 EUR kurzem 24,36; zaplaceno 233,17 EUR → rozdíl 3,67 EUR.
        $pfId = $this->createPurchaseInvoice('PMA-EUR-001', $this->eurId, 236.84, 24.36, 'paid', $date);
        $txId = $this->createBankTx('EUR', -233.17, $date);
        $this->linkPaymentMatch($txId, $pfId, 233.17);

        $items = $this->checker->audit($this->supplierId, self::YEAR . '-01-01', self::YEAR . '-12-31');
        $found = $this->findForTx($items, $txId);

        self::assertNotNull($found);
        self::assertContains('amount_mismatch', $found['issues']);
        // Rozdíl v detailu zůstává v měně dokladu — tam je porovnání doma.
        self::assertEqualsWithDelta(-3.67, $found['detail']['amount_mismatch']['diff'], 0.01);
        self::assertSame('EUR', $found['doc_currency']);
        // Dopad ale v korunách: 3,67 × 24,36.
        self::assertEqualsWithDelta(89.40, $found['impact_czk'], 0.05);
        self::assertSame('CZK', $found['currency']);
    }

    /** Konzervativní vyjmutí: rozdíl v rámci 1 Kč tolerance (zaokrouhlení) se NESMÍ hlásit. */
    public function testDoesNotFlagRoundingWithinTolerance(): void
    {
        $date = self::YEAR . '-05-13';
        $pfId = $this->createPurchaseInvoice('PMA-AM-OK-001', $this->czkId, 1210.0, 1.0, 'paid', $date);
        $txId = $this->createBankTx('CZK', -1209.50, $date); // rozdíl 0,50 Kč — v toleranci
        $this->linkPaymentMatch($txId, $pfId, 1209.50);

        $items = $this->checker->audit($this->supplierId, self::YEAR . '-01-01', self::YEAR . '-12-31');
        self::assertNull($this->findForTx($items, $txId), 'Haléřové zaokrouhlení do 1 Kč se nesmí flagovat.');
    }

    /**
     * Konzervativní vyjmutí: faktura vypořádaná VÍCE platbami (splátky) se pro
     * amount_mismatch NEporovnává proti celé částce faktury — legitimní částečné úhrady.
     */
    public function testDoesNotFlagAmountForMultiplePaymentSettlement(): void
    {
        $date = self::YEAR . '-05-14';
        $pfId = $this->createPurchaseInvoice('PMA-SPLIT-001', $this->czkId, 2000.0, 1.0, 'paid', $date);
        $tx1 = $this->createBankTx('CZK', -800.0, $date);
        $this->linkPaymentMatch($tx1, $pfId, 800.0);
        $tx2 = $this->createBankTx('CZK', -1200.0, $date);
        $this->linkPaymentMatch($tx2, $pfId, 1200.0);

        $items = $this->checker->audit($this->supplierId, self::YEAR . '-01-01', self::YEAR . '-12-31');

        self::assertNull($this->findForTx($items, $tx1), 'Částečná úhrada (splátka 1/2) se nesmí flagovat jako amount_mismatch.');
        self::assertNull($this->findForTx($items, $tx2), 'Částečná úhrada (splátka 2/2) se nesmí flagovat jako amount_mismatch.');
    }

    /**
     * Skutečný nález z backfillu: kurzový rozdíl (563/663) zaúčtovaný na bankovním zápisu,
     * kde JAK transakce, TAK doklad jsou v CZK — konverze tam nemá co dělat.
     */
    public function testFlagsFxOnCzkCzkTransaction(): void
    {
        $date = self::YEAR . '-06-01';
        $pfId = $this->createPurchaseInvoice('PMA-FXBUG-001', $this->czkId, 1000.0, 1.0, 'paid', $date);
        $txId = $this->createBankTx('CZK', -1000.0, $date);
        $this->linkPaymentMatch($txId, $pfId, 1000.0);

        // Vymyšlený kurzový rozdíl na bankovním zápisu CZK transakce (nikdy neměl vzniknout).
        $this->posting->postDocument($this->supplierId, 'bank', $txId, [
            ['account_code' => '321', 'side' => 'debit', 'amount' => 772.37],
            ['account_code' => '563', 'side' => 'debit', 'amount' => 227.63],
            ['account_code' => '221', 'side' => 'credit', 'amount' => 1000.0],
        ], ['entry_date' => $date, 'posted_by' => $this->userId, 'user_id' => $this->userId]);

        $items = $this->checker->audit($this->supplierId, self::YEAR . '-01-01', self::YEAR . '-12-31');
        $found = $this->findForTx($items, $txId);

        self::assertNotNull($found, 'Kurzový rozdíl na CZK↔CZK transakci musí být nahlášen.');
        self::assertContains('fx_on_czk_czk', $found['issues']);
        self::assertEqualsWithDelta(227.63, $found['detail']['fx_on_czk_czk']['amount'], 0.01);
    }

    /**
     * Bankovní zápis převzatý z jiného programu (POHODA): kurzový rozdíl i částku úhrady
     * zaúčtoval zdroj. Doklad převzatý v Kč z cizí měny nese kurzový rozdíl na CZK pohybu
     * a částka nesedí kvůli jinému kanálu úhrady. Nic z toho se nehlásí.
     */
    public function testDoesNotFlagAmountOrFxOnTakenOverBankEntry(): void
    {
        $date = self::YEAR . '-06-02';
        $pfId = $this->createPurchaseInvoice('PMA-TAKEN-001', $this->czkId, 1000.0, 1.0, 'paid', $date);
        $txId = $this->createBankTx('CZK', -1300.0, $date);
        $this->linkPaymentMatch($txId, $pfId, 1300.0);
        $entryId = $this->posting->postDocument($this->supplierId, 'bank', $txId, [
            ['account_code' => '321', 'side' => 'debit', 'amount' => 1000.0],
            ['account_code' => '563', 'side' => 'debit', 'amount' => 300.0],
            ['account_code' => '221', 'side' => 'credit', 'amount' => 1300.0],
        ], ['entry_date' => $date, 'posted_by' => $this->userId, 'user_id' => $this->userId]);
        $this->db->pdo()->prepare(
            "INSERT INTO pohoda_import_map (supplier_id, kind, pohoda_key, target_id) VALUES (?, 'journal_entry', 'test|pma', ?)"
        )->execute([$this->supplierId, $entryId]);

        $items = $this->checker->audit($this->supplierId, self::YEAR . '-01-01', self::YEAR . '-12-31');

        self::assertNull($this->findForTx($items, $txId));
    }

    /** Protistrana platby (bank výpis) zjevně neodpovídá protistraně dokladu. */
    public function testFlagsCounterpartyMismatch(): void
    {
        $date = self::YEAR . '-07-01';
        $pfId = $this->createPurchaseInvoice('PMA-CP-001', $this->czkId, 1000.0, 1.0, 'paid', $date);
        $txId = $this->createBankTx('CZK', -1000.0, $date, 'Optika Praha s.r.o.');
        $this->linkPaymentMatch($txId, $pfId, 1000.0);

        $items = $this->checker->audit($this->supplierId, self::YEAR . '-01-01', self::YEAR . '-12-31');
        $found = $this->findForTx($items, $txId);

        self::assertNotNull($found, 'Zjevně odlišná protistrana musí být nahlášena.');
        self::assertContains('counterparty_mismatch', $found['issues']);
    }

    /** Shodný název (přes právní formu/diakritiku) se NESMÍ flagovat jako neshoda. */
    public function testDoesNotFlagCounterpartyWhenNamesMatchAfterNormalization(): void
    {
        $date = self::YEAR . '-07-02';
        $pfId = $this->createPurchaseInvoice('PMA-CP-OK-001', $this->czkId, 1000.0, 1.0, 'paid', $date);
        // Vendor je "Navicat Premium s.r.o." — výpis nese jen "NAVICAT PREMIUM" (bez právní formy).
        $txId = $this->createBankTx('CZK', -1000.0, $date, 'NAVICAT PREMIUM');
        $this->linkPaymentMatch($txId, $pfId, 1000.0);

        $items = $this->checker->audit($this->supplierId, self::YEAR . '-01-01', self::YEAR . '-12-31');
        self::assertNull($this->findForTx($items, $txId), 'Stejná firma bez právní formy/diakritiky se nesmí flagovat.');
    }

    /**
     * V poli protistrany bývá TYP PLATBY, ne jméno — „Okamžitá platba" se proti jménu
     * odběratele porovnávat nesmí.
     *
     * Na ostrých datech z toho vzniklo 22 z 29 hlášení „protistrana nesedí". Kontrola,
     * která je z drtivé většiny plané, se přestane číst — a tím zmizí i to jediné pravdivé.
     */
    public function testDoesNotFlagCounterpartyWhenBankFieldHoldsPaymentTypeLabel(): void
    {
        $date = self::YEAR . '-07-03';
        $pfId = $this->createPurchaseInvoice('PMA-CP-GEN-001', $this->czkId, 1000.0, 1.0, 'paid', $date);
        $txId = $this->createBankTx('CZK', -1000.0, $date, 'Okamžitá platba');
        $this->linkPaymentMatch($txId, $pfId, 1000.0);

        $items = $this->checker->audit($this->supplierId, self::YEAR . '-01-01', self::YEAR . '-12-31');
        self::assertNull($this->findForTx($items, $txId), 'Typ platby není jméno protistrany.');
    }

    /** Popis karetní transakce (zakončený kódem země) také není jméno protistrany. */
    /** Banka u karty uvádí v protistraně sebe. Jiná než kartová platba se hlásí dál. */
    public function testDoesNotFlagCounterpartyForCardPayment(): void
    {
        $date = self::YEAR . '-03-10';
        $card = $this->createPurchaseInvoice('KARTA-1', $this->czkId, 500.00, 1.0, 'paid', $date, 'Obchod Karta s.r.o.');
        $cardTx = $this->createBankTx('CZK', -500.00, $date, 'Banka Příkladová');
        $this->db->pdo()->prepare("UPDATE bank_transactions SET card_last4 = '1234' WHERE id = ?")->execute([$cardTx]);
        $this->linkPaymentMatch($cardTx, $card, 500.00);
        $transfer = $this->createPurchaseInvoice('PREVOD-1', $this->czkId, 500.00, 1.0, 'paid', $date, 'Obchod Převod s.r.o.');
        $transferTx = $this->createBankTx('CZK', -500.00, $date, 'Banka Příkladová');
        $this->linkPaymentMatch($transferTx, $transfer, 500.00);

        $items = $this->checker->audit($this->supplierId, self::STARTS_ON, self::ENDS_ON);

        self::assertNull($this->findForTx($items, $cardTx));
        self::assertContains('counterparty_mismatch', $this->findForTx($items, $transferTx)['issues'] ?? []);
    }

    public function testDoesNotFlagCounterpartyForCardTransactionDescriptor(): void
    {
        $date = self::YEAR . '-07-04';
        $pfId = $this->createPurchaseInvoice('PMA-CP-CARD-001', $this->czkId, 1000.0, 1.0, 'paid', $date);
        $txId = $this->createBankTx('CZK', -1000.0, $date, 'DPD DEPO 2366 EJPOVICE CZE');
        $this->linkPaymentMatch($txId, $pfId, 1000.0);

        $items = $this->checker->audit($this->supplierId, self::YEAR . '-01-01', self::YEAR . '-12-31');
        self::assertNull($this->findForTx($items, $txId), 'Popis karetní transakce není jméno protistrany.');
    }

    /**
     * Normalizace nesmí rozsekat diakritiku na dvě slova.
     *
     * `iconv('ASCII//TRANSLIT')` na Windows z „á" udělá „'a", takže „Okamžitá" vyšlo jako
     * „okamzit a" a „Nováková" jako „novak a". Testuje se PŘÍMO normalizace, ne až nález:
     * přes celý nález to neprojde, protože porovnání jmen zachrání shodný token — guard
     * by pak svítil zeleně, aniž by cokoli hlídal.
     */
    public function testNormalizationDoesNotSplitDiacritics(): void
    {
        $normalize = new \ReflectionMethod(PaymentMatchAuditChecker::class, 'normalizeName');

        self::assertSame('okamzita platba', $normalize->invoke(null, 'Okamžitá platba'));
        self::assertSame('ing jana novakova', $normalize->invoke(null, 'Ing. Jana Nováková'));
    }

    /** Zbytek původního scénáře — totéž jméno s diakritikou i bez ní se nesmí flagovat. */
    public function testDiacriticsDoNotBreakNameComparison(): void
    {
        $date = self::YEAR . '-07-05';
        $pfId = $this->createPurchaseInvoice('PMA-CP-DIA-001', $this->czkId, 1000.0, 1.0, 'paid', $date, 'Ing. Jana Nováková');
        $txId = $this->createBankTx('CZK', -1000.0, $date, 'ING JANA NOVAKOVA');
        $this->linkPaymentMatch($txId, $pfId, 1000.0);

        $items = $this->checker->audit($this->supplierId, self::YEAR . '-01-01', self::YEAR . '-12-31');
        self::assertNull($this->findForTx($items, $txId), 'Totéž jméno s diakritikou i bez ní se nesmí flagovat.');
    }
}
