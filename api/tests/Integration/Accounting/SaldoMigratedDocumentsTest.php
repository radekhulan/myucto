<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\ClosingRepository;
use MyInvoice\Repository\JournalEntryDocumentLinkRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\DocumentPostingGroup;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Accounting\Reports\SaldoService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Saldokonto u dat převzatých z jiného programu (commity 48dff311f a f2eaa45a0):
 *
 *  - doklady zastoupené v počátečních stavech (vazba na otevírací zápis) na 311, 321 a 314,
 *  - čerpání přijaté zálohy 1:N (`purchase_advance_deductions`) a vrácení zálohy,
 *  - záloha placená konečnou fakturou vs. přeúčtování mezi analytikami 314,
 *  - úhrada zaúčtovaná na saldokontní účet před zaúčtováním dokladu.
 *
 * Ve všech scénářích musí Σ otevřených položek sedět se zůstatkem hlavní knihy.
 * Vše běží v jedné transakci, kterou tearDown rollbackne. Soft-skip bez cfg.php.
 */
#[Group('integration')]
final class SaldoMigratedDocumentsTest extends TestCase
{
    private const YEAR = 2099;

    private Connection $db;
    private PostingService $posting;
    private SaldoService $saldo;
    private JournalEntryDocumentLinkRepository $links;

    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $userId = 0;
    private int $czId = 0;
    private int $periodId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje - test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db      = $container->get(Connection::class);
            $this->posting = $container->get(PostingService::class);
            $this->saldo   = $container->get(SaldoService::class);
            $periods       = $container->get(AccountingPeriodRepository::class);
            $seeder        = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        $this->links = new JournalEntryDocumentLinkRepository($this->db);

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId       = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->currencyId === 0 || $this->userId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier/currency/user/country) v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id)
             SELECT ?, "Testovací", "Praha", "11000", country_id, ?, default_currency_id, default_vat_rate_id
               FROM supplier WHERE id = ?'
        )->execute(['Saldo převod s.r.o.', 'saldo-prevod@example.com', $this->supplierId]);
        $this->supplierId = (int) $pdo->lastInsertId();

        $seeder->seedForSupplier($this->supplierId);
        $this->periodId = $periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
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

    // ── A1: doklady počátečních stavů ────────────────────────────────────────

    /**
     * Vydaná faktura minulého roku je jen v otevíracím zápisu (311 MD / 701 D) s vazbou
     * na doklad. Saldokonto ji musí ukázat; úhrada letos ji snižuje k datu úhrady,
     * úhrada z doby před počátečním stavem (jen v `paid_total`) se odečte vždy.
     */
    public function testOpeningIssuedInvoicesAreOpenItemsOn311(): void
    {
        $pdo = $this->db->pdo();
        $a = $this->client('Počáteční odběratel A');
        $b = $this->client('Počáteční odběratel B');
        $invA = $this->invoice($a, 1210.00, (self::YEAR - 1) . '-11-10', (self::YEAR - 1) . '-11-24');
        // B: 1 210, z toho 210 uhrazeno už v minulém roce (paid_total, bez evidované platby).
        $invB = $this->invoice($b, 1210.00, (self::YEAR - 1) . '-12-01', (self::YEAR - 1) . '-12-15');
        $pdo->prepare('UPDATE invoices SET paid_total = 210.00 WHERE id = ?')->execute([$invB]);

        $opening = $this->opening([
            self::l('311', 'debit', 1210.00),
            self::l('311', 'debit', 1000.00),
            self::l('701', 'credit', 2210.00),
        ]);
        $this->links->add($opening, $this->supplierId, 'invoice', $invA, null, $this->userId);
        $this->links->add($opening, $this->supplierId, 'invoice', $invB, null, $this->userId);

        // Letošní částečná úhrada A 400 z banky (zaúčtovaná 221/311).
        $tx = $this->bankInvoicePayment($invA, 400.00, self::YEAR . '-03-01');
        $pdo->prepare('UPDATE invoices SET paid_total = 400.00 WHERE id = ?')->execute([$invA]);
        $this->posting->postDocument($this->supplierId, 'bank', $tx, [
            self::l('221', 'debit', 400.00),
            self::l('311', 'credit', 400.00),
        ], $this->opts(self::YEAR . '-03-01'));

        foreach ([self::YEAR . '-02-28' => [1210.00, 1000.00], self::YEAR . '-06-30' => [810.00, 1000.00]] as $asOf => [$openA, $openB]) {
            $acc = $this->account('311', $asOf);
            self::assertSame(self::cents($openA + $openB), self::cents($acc['gl_balance']), "HK 311 k {$asOf}");
            self::assertSame(self::cents($openA + $openB), self::cents($acc['open_items_total']), "Σ položek 311 k {$asOf}");
            self::assertSame(0, self::cents($acc['difference']), "Rozdíl 311 k {$asOf}");
            $pa = $this->partner($acc, $a);
            self::assertNotNull($pa, "Doklad počátečních stavů A je otevřený k {$asOf}");
            self::assertSame($invA, (int) $pa['items'][0]['doc_id']);
            self::assertSame(self::cents($openA), self::cents($pa['items'][0]['remaining_czk']), "Zbývá A k {$asOf}");
            $pb = $this->partner($acc, $b);
            self::assertNotNull($pb);
            self::assertSame(self::cents($openB), self::cents($pb['items'][0]['remaining_czk']), "B: úhrada před počátečním stavem se odečte k {$asOf}");
        }
    }

    /** Přijatá faktura minulého roku v počátečních stavech (701 MD / 321 D) a její úhrada letos. */
    public function testOpeningPurchaseInvoiceIsOpenItemOn321(): void
    {
        $vendor = $this->client('Počáteční dodavatel');
        $pi = $this->purchaseInvoice($vendor, 2000.00, (self::YEAR - 1) . '-12-10', (self::YEAR - 1) . '-12-24');
        $opening = $this->opening([
            self::l('701', 'debit', 2000.00),
            self::l('321', 'credit', 2000.00),
        ]);
        $this->links->add($opening, $this->supplierId, 'purchase_invoice', $pi, null, $this->userId);

        $acc = $this->account('321', self::YEAR . '-01-31');
        self::assertSame(self::cents(2000.00), self::cents($acc['gl_balance']), 'HK 321 nese počáteční stav.');
        self::assertSame(self::cents(2000.00), self::cents($acc['open_items_total']));
        self::assertSame(0, self::cents($acc['difference']));
        $p = $this->partner($acc, $vendor);
        self::assertNotNull($p, 'Přijatá faktura počátečních stavů je otevřená položka.');
        self::assertSame('purchase_invoice', $p['items'][0]['doc_type']);
        self::assertSame($pi, (int) $p['items'][0]['doc_id']);

        $tx = $this->bankPurchasePayment($pi, 2000.00, self::YEAR . '-02-10');
        $this->posting->postDocument($this->supplierId, 'bank', $tx, [
            self::l('321', 'debit', 2000.00),
            self::l('221', 'credit', 2000.00),
        ], $this->opts(self::YEAR . '-02-10'));

        $before = $this->account('321', self::YEAR . '-02-09');
        self::assertSame(self::cents(2000.00), self::cents($before['open_items_total']), 'Před úhradou otevřená.');
        self::assertSame(0, self::cents($before['difference']));
        $after = $this->account('321', self::YEAR . '-02-28');
        self::assertSame(0, self::cents($after['gl_balance']));
        self::assertSame(0, self::cents($after['open_items_total']));
        self::assertNull($this->partner($after, $vendor), 'Uhrazená faktura počátečních stavů zmizí.');
    }

    /**
     * Přijatá záloha z počátečních stavů bez DDKP: DPH čerpal DDKP minulého roku, počáteční
     * stav 314 nese jen základ. Předpis položky = základ; čerpání konečnou fakturou přes
     * `purchase_advance_deductions` ji snižuje od zaúčtování faktury.
     */
    public function testOpeningPurchaseAdvanceWithoutTaxDocumentHasBaseAsBooked(): void
    {
        $vendor = $this->client('Zálohový dodavatel PS');
        $advance = $this->purchaseInvoice($vendor, 1210.00, (self::YEAR - 1) . '-12-01', (self::YEAR - 1) . '-12-10', 210.00);
        $this->db->pdo()->prepare("UPDATE purchase_invoices SET document_kind = 'advance', status = 'paid', paid_at = ? WHERE id = ?")
            ->execute([(self::YEAR - 1) . '-12-05', $advance]);
        $opening = $this->opening([
            self::l('314', 'debit', 1000.00),
            self::l('701', 'credit', 1000.00),
        ]);
        $this->links->add($opening, $this->supplierId, 'purchase_invoice', $advance, null, $this->userId);

        $acc = $this->account('314', self::YEAR . '-01-31');
        self::assertSame(self::cents(1000.00), self::cents($acc['gl_balance']));
        self::assertSame(self::cents(1000.00), self::cents($acc['open_items_total']), 'Předpis zálohy počátečních stavů = základ bez DPH.');
        self::assertSame(0, self::cents($acc['difference']));
        $p = $this->partner($acc, $vendor);
        self::assertNotNull($p);
        self::assertSame($advance, (int) $p['items'][0]['doc_id']);
        self::assertSame(self::cents(1000.00), self::cents($p['items'][0]['booked_czk']));

        // Konečná faktura odečte 600 ze zálohy (vazba po dvojicích, ne advance_purchase_invoice_id).
        $final = $this->purchaseInvoice($vendor, 726.00, self::YEAR . '-03-01', self::YEAR . '-03-15');
        $this->postPurchase($final, [
            self::l('518', 'debit', 1200.00),
            self::l('343', 'debit', 252.00),
            self::l('314', 'credit', 600.00),
            self::l('321', 'credit', 852.00),
        ], self::YEAR . '-03-01');
        $this->deduction($final, $advance, 600.00);

        $before = $this->account('314', self::YEAR . '-02-28');
        self::assertSame(self::cents(1000.00), self::cents($before['open_items_total']), 'Čerpání platí až od zaúčtování konečné faktury.');
        $after = $this->account('314', self::YEAR . '-03-31');
        self::assertSame(self::cents(400.00), self::cents($after['gl_balance']));
        self::assertSame(self::cents(400.00), self::cents($after['open_items_total']), 'Zbývá nečerpaných 400.');
        self::assertSame(0, self::cents($after['difference']));
    }

    // ── A2: čerpání přijaté zálohy 1:N a vrácení zálohy ──────────────────────

    /**
     * Záloha 1 000 se čerpá dvěma fakturami (300 + 400), druhá faktura odečítá zároveň
     * celou druhou zálohu 500. Zbytek první zálohy (300) dodavatel vrátí příchozí platbou
     * spárovanou se zálohou a zaúčtovanou na 314 - tím se položka uzavře.
     */
    public function testAdvanceDeductedByMultipleFinalsAndRefunded(): void
    {
        $vendor = $this->client('Pronajímatel s.r.o.');
        $adv1 = $this->paidAdvance($vendor, 1000.00, self::YEAR . '-01-10');
        $adv2 = $this->paidAdvance($vendor, 500.00, self::YEAR . '-01-20');

        $finalA = $this->purchaseInvoice($vendor, 700.00, self::YEAR . '-03-01', self::YEAR . '-03-15');
        $this->postPurchase($finalA, [
            self::l('518', 'debit', 1000.00),
            self::l('314', 'credit', 300.00),
            self::l('321', 'credit', 700.00),
        ], self::YEAR . '-03-01');
        $this->deduction($finalA, $adv1, 300.00);

        $finalB = $this->purchaseInvoice($vendor, 100.00, self::YEAR . '-04-01', self::YEAR . '-04-15');
        $this->postPurchase($finalB, [
            self::l('518', 'debit', 1000.00),
            self::l('314', 'credit', 900.00),
            self::l('321', 'credit', 100.00),
        ], self::YEAR . '-04-01');
        $this->deduction($finalB, $adv1, 400.00);
        $this->deduction($finalB, $adv2, 500.00);

        $expect = [
            self::YEAR . '-02-28' => [1000.00, 500.00],
            self::YEAR . '-03-31' => [700.00, 500.00],
            self::YEAR . '-04-30' => [300.00, 0.00],
        ];
        foreach ($expect as $asOf => [$left1, $left2]) {
            $acc = $this->account('314', $asOf);
            self::assertSame(self::cents($left1 + $left2), self::cents($acc['gl_balance']), "HK 314 k {$asOf}");
            self::assertSame(self::cents($left1 + $left2), self::cents($acc['open_items_total']), "Σ položek 314 k {$asOf}");
            self::assertSame(0, self::cents($acc['difference']), "Rozdíl 314 k {$asOf}");
            $items = $this->itemsByDoc($acc, $vendor);
            self::assertSame(self::cents($left1), self::cents($items[$adv1]['remaining_czk'] ?? 0), "Záloha 1 k {$asOf}");
            self::assertSame(self::cents($left2), self::cents($items[$adv2]['remaining_czk'] ?? 0), "Záloha 2 k {$asOf}");
            if ($left2 === 0.00) {
                self::assertArrayNotHasKey($adv2, $items, "Vyčerpaná záloha 2 není položkou k {$asOf}");
            }
        }

        // Vrácení zbytku zálohy 1.
        $refund = $this->bankTransaction(300.00, self::YEAR . '-05-10');
        $this->match($refund, $adv1, 300.00);
        $this->posting->postDocument($this->supplierId, 'bank', $refund, [
            self::l('221', 'debit', 300.00),
            self::l('314', 'credit', 300.00),
        ], $this->opts(self::YEAR . '-05-10'));

        $before = $this->account('314', self::YEAR . '-05-09');
        self::assertSame(self::cents(300.00), self::cents($before['open_items_total']), 'Před vrácením zůstává 300.');
        $after = $this->account('314', self::YEAR . '-05-31');
        self::assertSame(0, self::cents($after['gl_balance']));
        self::assertSame(0, self::cents($after['open_items_total']), 'Vrácení zálohy položku uzavře.');
        self::assertSame(0, self::cents($after['difference']));
        self::assertNull($this->partner($after, $vendor));
    }

    /**
     * DDKP z POHODY přeúčtovává základ zálohy mezi analytikami 314 (314.640 D 1 210,
     * 314.641 MD 1 000, 343 MD 210): zálohu čerpá jen čistým pohybem 210. Konečná faktura
     * pak čerpá základ z 314.641.
     */
    public function testTaxDocumentCountsNetMovementBetween314Analytics(): void
    {
        $this->analytic('314', '314.640');
        $this->analytic('314', '314.641');
        $vendor = $this->client('POHODA zálohový dodavatel');
        $advance = $this->purchaseInvoice($vendor, 1210.00, self::YEAR . '-02-01', self::YEAR . '-02-10', 210.00);
        $this->db->pdo()->prepare("UPDATE purchase_invoices SET document_kind = 'advance', status = 'paid', paid_at = ? WHERE id = ?")
            ->execute([self::YEAR . '-02-05', $advance]);
        $tx = $this->bankPurchasePayment($advance, 1210.00, self::YEAR . '-02-05');
        $this->posting->postDocument($this->supplierId, 'bank', $tx, [
            self::l('314.640', 'debit', 1210.00),
            self::l('221', 'credit', 1210.00),
        ], $this->opts(self::YEAR . '-02-05'));

        $ddkp = $this->purchaseInvoice($vendor, 1210.00, self::YEAR . '-02-06', self::YEAR . '-02-06', 210.00);
        $this->db->pdo()->prepare("UPDATE purchase_invoices SET document_kind = 'tax_document', parent_purchase_invoice_id = ? WHERE id = ?")
            ->execute([$advance, $ddkp]);
        $this->postPurchase($ddkp, [
            self::l('343', 'debit', 210.00),
            self::l('314.641', 'debit', 1000.00),
            self::l('314.640', 'credit', 1210.00),
        ], self::YEAR . '-02-06');

        $acc = $this->account('314', self::YEAR . '-02-28');
        self::assertSame(self::cents(1000.00), self::cents($acc['gl_balance']));
        self::assertSame(self::cents(1000.00), self::cents($acc['open_items_total']), 'DDKP čerpá jen DPH (čistý pohyb), ne celý kredit 1 210.');
        self::assertSame(0, self::cents($acc['difference']));

        $final = $this->purchaseInvoice($vendor, 1210.00, self::YEAR . '-03-01', self::YEAR . '-03-15', 210.00);
        $this->db->pdo()->prepare('UPDATE purchase_invoices SET advance_purchase_invoice_id = ?, advance_paid_amount = 1210.00 WHERE id = ?')
            ->execute([$advance, $final]);
        $this->postPurchase($final, [
            self::l('518', 'debit', 1000.00),
            self::l('314.641', 'credit', 1000.00),
        ], self::YEAR . '-03-01');

        $after = $this->account('314', self::YEAR . '-03-31');
        self::assertSame(0, self::cents($after['gl_balance']));
        self::assertSame(0, self::cents($after['open_items_total']));
        self::assertNull($this->partner($after, $vendor));
    }

    // ── A3: záloha placená fakturou vs. přeúčtování mezi analytikami 314 ────

    /**
     * Konečná faktura z Money S3 přeúčtovává DPH zálohy mezi analytikami (314.641 MD /
     * 314.640 D, stejná částka): to NENÍ záloha placená fakturou. Faktura nájmu s debetem
     * 314 bez protějšku („záloha na služby") zálohou je a uzavře ji až vyúčtování.
     */
    public function testInvoicePrepaymentIgnoresReclassBetween314Analytics(): void
    {
        $this->analytic('314', '314.640');
        $this->analytic('314', '314.641');
        $reclassVendor = $this->client('Money S3 dodavatel');
        $rentVendor = $this->client('Nájem se službami');

        $reclass = $this->purchaseInvoice($reclassVendor, 1210.00, self::YEAR . '-02-01', self::YEAR . '-02-15');
        $this->postPurchase($reclass, [
            self::l('518', 'debit', 1000.00),
            self::l('343', 'debit', 210.00),
            self::l('321', 'credit', 1210.00),
            self::l('314.641', 'debit', 210.00),
            self::l('314.640', 'credit', 210.00),
        ], self::YEAR . '-02-01');

        $rent = $this->purchaseInvoice($rentVendor, 1500.00, self::YEAR . '-02-01', self::YEAR . '-02-15');
        $this->postPurchase($rent, [
            self::l('518', 'debit', 1000.00),
            self::l('314.640', 'debit', 500.00),
            self::l('321', 'credit', 1500.00),
        ], self::YEAR . '-02-01');

        $acc = $this->account('314', self::YEAR . '-02-28');
        self::assertSame(self::cents(500.00), self::cents($acc['gl_balance']), 'HK 314: přeúčtování se vyruší, záloha na služby 500.');
        self::assertSame(self::cents(500.00), self::cents($acc['open_items_total']));
        self::assertSame(0, self::cents($acc['difference']));
        self::assertNull($this->partner($acc, $reclassVendor), 'Přeúčtování mezi analytikami 314 není záloha placená fakturou.');
        $p = $this->partner($acc, $rentVendor);
        self::assertNotNull($p, 'Záloha na služby v nájmu je otevřená položka 314.');
        self::assertSame($rent, (int) $p['items'][0]['doc_id']);
        self::assertSame(self::cents(500.00), self::cents($p['items'][0]['remaining_czk']));

        // Roční vyúčtování služeb zálohu čerpá (záloha = faktura nájmu).
        $settlement = $this->purchaseInvoice($rentVendor, 100.00, self::YEAR . '-12-15', self::YEAR . '-12-31');
        $this->postPurchase($settlement, [
            self::l('518', 'debit', 600.00),
            self::l('314.640', 'credit', 500.00),
            self::l('321', 'credit', 100.00),
        ], self::YEAR . '-12-15');
        $this->deduction($settlement, $rent, 500.00);

        $after = $this->account('314', self::YEAR . '-12-31');
        self::assertSame(0, self::cents($after['gl_balance']));
        self::assertSame(0, self::cents($after['open_items_total']), 'Vyúčtovaná záloha na služby zmizí.');
        self::assertSame(0, self::cents($after['difference']));
    }

    // ── A4: úhrada zaúčtovaná před zaúčtováním dokladu ───────────────────────

    /** Nájem za listopad zaplacený v říjnu (321 MD / 221 D), faktura zaúčtovaná až 1. 11. */
    public function testPurchasePaidBeforeBookingIsOverpaymentUntilBooked(): void
    {
        $vendor = $this->client('Nájem předem s.r.o.');
        $pi = $this->purchaseInvoice($vendor, 1000.00, self::YEAR . '-11-01', self::YEAR . '-11-15');
        $this->db->pdo()->prepare("UPDATE purchase_invoices SET status = 'paid', paid_at = ? WHERE id = ?")
            ->execute([self::YEAR . '-10-05', $pi]);
        $tx = $this->bankPurchasePayment($pi, 1000.00, self::YEAR . '-10-05');
        $this->posting->postDocument($this->supplierId, 'bank', $tx, [
            self::l('321', 'debit', 1000.00),
            self::l('221', 'credit', 1000.00),
        ], $this->opts(self::YEAR . '-10-05'));
        $this->postPurchase($pi, [
            self::l('518', 'debit', 1000.00),
            self::l('321', 'credit', 1000.00),
        ], self::YEAR . '-11-01');

        $early = $this->account('321', self::YEAR . '-10-04');
        self::assertSame(0, self::cents($early['gl_balance']));
        self::assertNull($this->partner($early, $vendor), 'Před úhradou nic.');

        $between = $this->account('321', self::YEAR . '-10-31');
        self::assertSame(self::cents(-1000.00), self::cents($between['gl_balance']), 'HK 321 nese úhradu bez předpisu.');
        self::assertSame(self::cents(-1000.00), self::cents($between['open_items_total']), 'Položka = úhrada se znaménkem přeplatku.');
        self::assertSame(0, self::cents($between['difference']));
        $p = $this->partner($between, $vendor);
        self::assertNotNull($p);
        self::assertCount(1, $p['items']);
        self::assertSame($pi, (int) $p['items'][0]['doc_id']);

        $after = $this->account('321', self::YEAR . '-11-30');
        self::assertSame(0, self::cents($after['gl_balance']));
        self::assertSame(0, self::cents($after['open_items_total']), 'Po zaúčtování faktury položka zmizí.');
        self::assertSame(0, self::cents($after['difference']));
        self::assertNull($this->partner($after, $vendor));
    }

    /** Vydaná faktura uhrazená (invoice_payments + banka 221/311) před svým zaúčtováním. */
    public function testIssuedInvoicePaidBeforeBookingIsOverpaymentUntilBooked(): void
    {
        $client = $this->client('Platí předem a.s.');
        $inv = $this->invoice($client, 1000.00, self::YEAR . '-11-01', self::YEAR . '-11-15');
        $this->db->pdo()->prepare("UPDATE invoices SET status = 'paid', paid_total = 1000.00, paid_at = ? WHERE id = ?")
            ->execute([self::YEAR . '-10-05', $inv]);
        $tx = $this->bankInvoicePayment($inv, 1000.00, self::YEAR . '-10-05');
        $this->posting->postDocument($this->supplierId, 'bank', $tx, [
            self::l('221', 'debit', 1000.00),
            self::l('311', 'credit', 1000.00),
        ], $this->opts(self::YEAR . '-10-05'));
        $this->posting->postDocument($this->supplierId, 'invoice', $inv, [
            self::l('311', 'debit', 1000.00),
            self::l('602', 'credit', 1000.00),
        ], $this->opts(self::YEAR . '-11-01'));

        $between = $this->account('311', self::YEAR . '-10-31');
        self::assertSame(self::cents(-1000.00), self::cents($between['gl_balance']), 'HK 311 nese inkaso bez předpisu.');
        self::assertSame(self::cents(-1000.00), self::cents($between['open_items_total']), 'Položka = přeplatek.');
        self::assertSame(0, self::cents($between['difference']));
        $p = $this->partner($between, $client);
        self::assertNotNull($p);
        self::assertSame($inv, (int) $p['items'][0]['doc_id']);
        self::assertSame('invoice', $p['items'][0]['doc_type']);

        $after = $this->account('311', self::YEAR . '-11-30');
        self::assertSame(0, self::cents($after['gl_balance']));
        self::assertSame(0, self::cents($after['open_items_total']));
        self::assertSame(0, self::cents($after['difference']));
        self::assertNull($this->partner($after, $client));
    }

    /**
     * Proforma placená na 311 není „uhrazeno před zaúčtováním" (proforma se nezaúčtovává
     * nikdy): její položku řeší zálohová větev, nesmí se objevit podruhé.
     */
    public function testProformaPaidOn311IsNotPaidBeforeBooking(): void
    {
        $client = $this->client('Proforma bez finálu');
        $proforma = $this->invoice($client, 1210.00, self::YEAR . '-05-01', self::YEAR . '-05-08');
        $this->db->pdo()->prepare("UPDATE invoices SET invoice_type = 'proforma' WHERE id = ?")->execute([$proforma]);
        $tx = $this->bankInvoicePayment($proforma, 1210.00, self::YEAR . '-05-05');
        $this->posting->postDocument($this->supplierId, 'bank', $tx, [
            self::l('221', 'debit', 1210.00),
            self::l('311', 'credit', 1210.00),
        ], $this->opts(self::YEAR . '-05-05'));

        $acc = $this->account('311', self::YEAR . '-05-31');
        self::assertSame(self::cents(-1210.00), self::cents($acc['gl_balance']));
        self::assertSame(self::cents(-1210.00), self::cents($acc['open_items_total']), 'Inkaso proformy jen jednou.');
        self::assertSame(0, self::cents($acc['difference']));
        $p = $this->partner($acc, $client);
        self::assertNotNull($p);
        self::assertCount(1, $p['items'], 'Proforma nesmí být navíc položkou „uhrazeno před zaúčtováním".');
    }

    // ── A5: doklad zaúčtovaný víc zápisy (skupina zaúčtování) ────────────────

    /**
     * Převod z programu, který účtuje doklad po řádcích (ABRA): základ nese přímý zápis se
     * zdrojem dokladu, DPH ruční zápis ve skupině zaúčtování dokladu, navázaný na doklad
     * i měkkou vazbou. Předpisem je celá částka, DPH není ruční vyrovnání: dřív doklad po
     * úplné úhradě zůstal otevřený o DPH a uzávěrková kontrola počítala DPH jako záporné
     * vyrovnání.
     */
    public function testInvoicePostedByTwoEntriesOfPostingGroup(): void
    {
        $client = $this->client('Odběratel po řádcích');
        $inv = $this->invoice($client, 1210.00, self::YEAR . '-03-01', self::YEAR . '-03-15');
        $this->postInGroup('invoice', $inv, [
            [self::l('311', 'debit', 1000.00), self::l('602', 'credit', 1000.00)],
            [self::l('311', 'debit', 210.00), self::l('343', 'credit', 210.00)],
        ], self::YEAR . '-03-01');

        $open = $this->account('311', self::YEAR . '-03-31');
        self::assertSame(self::cents(1210.00), self::cents($open['gl_balance']));
        self::assertSame(self::cents(1210.00), self::cents($open['open_items_total']), 'Předpis ze všech zápisů skupiny.');
        self::assertSame(0, self::cents($open['difference']));
        $p = $this->partner($open, $client);
        self::assertNotNull($p);
        self::assertCount(1, $p['items']);
        self::assertSame(self::cents(1210.00), self::cents($p['items'][0]['booked_czk']));

        $tx = $this->bankInvoicePayment($inv, 610.00, self::YEAR . '-04-10');
        $this->posting->postDocument($this->supplierId, 'bank', $tx, [
            self::l('221', 'debit', 610.00),
            self::l('311', 'credit', 610.00),
        ], $this->opts(self::YEAR . '-04-10'));
        $partial = $this->account('311', self::YEAR . '-04-30');
        self::assertSame(self::cents(600.00), self::cents($partial['open_items_total']), 'Částečná úhrada snižuje celý předpis.');
        self::assertSame(0, self::cents($partial['difference']));

        $tx = $this->bankInvoicePayment($inv, 600.00, self::YEAR . '-05-10');
        $this->posting->postDocument($this->supplierId, 'bank', $tx, [
            self::l('221', 'debit', 600.00),
            self::l('311', 'credit', 600.00),
        ], $this->opts(self::YEAR . '-05-10'));
        $this->db->pdo()->prepare("UPDATE invoices SET status = 'paid', paid_total = 1210.00, paid_at = ? WHERE id = ?")
            ->execute([self::YEAR . '-05-10', $inv]);

        $paid = $this->account('311', self::YEAR . '-05-31');
        self::assertSame(0, self::cents($paid['gl_balance']));
        self::assertSame(0, self::cents($paid['open_items_total']), 'Uhrazený doklad nezůstane otevřený o DPH.');
        self::assertNull($this->partner($paid, $client));
        $closing = new ClosingRepository($this->db);
        self::assertSame([], array_values(array_filter(
            $closing->paidInvoicesOpenSaldo($this->supplierId, self::YEAR . '-05-31'),
            static fn (array $r): bool => $r['id'] === $inv,
        )), 'Kontrola zaplacených dokladů bere předpis z celé skupiny.');
    }

    /**
     * Přijatá faktura po řádcích, přímý zápis bez řádku na 321 (jen přeúčtování nákladu):
     * položka saldokonta vznikne jen ze zápisů skupiny a úhradou zmizí.
     */
    public function testPurchaseWhoseDirectEntryHasNo321Line(): void
    {
        $vendor = $this->client('Dodavatel po řádcích');
        $pi = $this->purchaseInvoice($vendor, 1210.00, self::YEAR . '-03-01', self::YEAR . '-03-15', 210.00);
        $this->postInGroup('purchase_invoice', $pi, [
            [self::l('518', 'debit', 50.00), self::l('501', 'credit', 50.00)],
            [self::l('518', 'debit', 1000.00), self::l('321', 'credit', 1000.00)],
            [self::l('343', 'debit', 210.00), self::l('321', 'credit', 210.00)],
        ], self::YEAR . '-03-01');

        $open = $this->account('321', self::YEAR . '-03-31');
        self::assertSame(self::cents(1210.00), self::cents($open['gl_balance']));
        self::assertSame(self::cents(1210.00), self::cents($open['open_items_total']));
        self::assertSame(0, self::cents($open['difference']));

        $tx = $this->bankPurchasePayment($pi, 1210.00, self::YEAR . '-04-02');
        $this->posting->postDocument($this->supplierId, 'bank', $tx, [
            self::l('321', 'debit', 1210.00),
            self::l('221', 'credit', 1210.00),
        ], $this->opts(self::YEAR . '-04-02'));
        $this->db->pdo()->prepare("UPDATE purchase_invoices SET status = 'paid', paid_at = ? WHERE id = ?")
            ->execute([self::YEAR . '-04-02', $pi]);

        $paid = $this->account('321', self::YEAR . '-04-30');
        self::assertSame(0, self::cents($paid['open_items_total']));
        self::assertNull($this->partner($paid, $vendor));
        $closing = new ClosingRepository($this->db);
        self::assertSame([], array_values(array_filter(
            $closing->paidPurchasesOpenSaldo($this->supplierId, self::YEAR . '-04-30'),
            static fn (array $r): bool => $r['id'] === $pi,
        )));
    }

    /** DDKP zaúčtovaný dvěma zápisy čerpá zálohu na 314 oběma. */
    public function testTaxDocumentPostedByPostingGroupDrawsAdvance(): void
    {
        $vendor = $this->client('Záloha s DDKP po řádcích');
        $advance = $this->paidAdvance($vendor, 1210.00, self::YEAR . '-02-05');
        $ddkp = $this->purchaseInvoice($vendor, 1210.00, self::YEAR . '-02-06', self::YEAR . '-02-06', 210.00);
        $this->db->pdo()->prepare("UPDATE purchase_invoices SET document_kind = 'tax_document', parent_purchase_invoice_id = ? WHERE id = ?")
            ->execute([$advance, $ddkp]);
        $this->postInGroup('purchase_invoice', $ddkp, [
            [self::l('343', 'debit', 100.00), self::l('314', 'credit', 100.00)],
            [self::l('343', 'debit', 110.00), self::l('314', 'credit', 110.00)],
        ], self::YEAR . '-02-06');

        $acc = $this->account('314', self::YEAR . '-02-28');
        self::assertSame(self::cents(1000.00), self::cents($acc['gl_balance']));
        self::assertSame(self::cents(1000.00), self::cents($acc['open_items_total']), 'DDKP čerpá DPH ze všech svých zápisů.');
        self::assertSame(0, self::cents($acc['difference']));
    }

    // ── A6: záloha placená v cizí měně ───────────────────────────────────────

    /**
     * Zálohová faktura 1 000 EUR zaplacená z eurového účtu: `payment_matches.amount` je
     * v eurech, bankovní zápis nese na 314 Kč kurzem dne platby. Položka saldokonta je
     * v Kč shodně s hlavní knihou, ne 1 000 „korun".
     */
    public function testAdvancePaidInEurIsCzkOnAccount(): void
    {
        $eur = (int) $this->db->pdo()->query("SELECT id FROM currencies WHERE code = 'EUR' LIMIT 1")->fetchColumn();
        if ($eur === 0) {
            self::markTestSkipped('Měna EUR v číselníku chybí.');
        }
        $vendor = $this->client('Zahraniční dodavatel');
        $advance = $this->purchaseInvoice($vendor, 1000.00, self::YEAR . '-06-01', self::YEAR . '-06-10');
        $this->db->pdo()->prepare("UPDATE purchase_invoices SET document_kind = 'advance', status = 'paid', paid_at = ?, currency_id = ?, exchange_rate = 25.000 WHERE id = ?")
            ->execute([self::YEAR . '-06-05', $eur, $advance]);
        $tx = $this->bankTransaction(-1000.00, self::YEAR . '-06-05');
        $this->db->pdo()->prepare("UPDATE bank_transactions SET currency = 'EUR' WHERE id = ?")->execute([$tx]);
        $this->match($tx, $advance, 1000.00);
        $this->posting->postDocument($this->supplierId, 'bank', $tx, [
            self::l('314', 'debit', 25100.00),
            self::l('221', 'credit', 25100.00),
        ], $this->opts(self::YEAR . '-06-05'));

        $acc = $this->account('314', self::YEAR . '-06-30');
        self::assertSame(self::cents(25100.00), self::cents($acc['gl_balance']));
        self::assertSame(self::cents(25100.00), self::cents($acc['open_items_total']), 'Záloha v Kč zaúčtovanou částkou platby.');
        self::assertSame(0, self::cents($acc['difference']));
        $items = $this->itemsByDoc($acc, $vendor);
        self::assertSame(self::cents(25100.00), self::cents($items[$advance]['booked_czk'] ?? 0));
    }

    // ── A7: zápočet převzatý z jiného programu v kontrole zaplacených dokladů ─

    /**
     * Interní doklad převzatý z Money S3 vyrovnává dvě faktury najednou (321 MD / 395 D),
     * rozpad nese jen `invoice_settlements` s vazbou na ten zápis. Saldokonto doklady
     * uzavírá zápočtem; kontrola zaplacených dokladů je dřív hlásila s otevřeným saldem,
     * protože zápočet četla jen ze zápisů typu `settlement`.
     */
    public function testImportedSettlementClosesPaidDocumentsInClosingCheck(): void
    {
        $vendor = $this->client('Zápočet převzatý dodavatel');
        $piA = $this->purchaseInvoice($vendor, 300.00, self::YEAR . '-03-01', self::YEAR . '-03-15');
        $piB = $this->purchaseInvoice($vendor, 200.00, self::YEAR . '-03-02', self::YEAR . '-03-15');
        $this->postPurchase($piA, [self::l('518', 'debit', 300.00), self::l('321', 'credit', 300.00)], self::YEAR . '-03-01');
        $this->postPurchase($piB, [self::l('518', 'debit', 200.00), self::l('321', 'credit', 200.00)], self::YEAR . '-03-02');
        $client = $this->client('Zápočet převzatý odběratel');
        $inv = $this->invoice($client, 500.00, self::YEAR . '-03-03', self::YEAR . '-03-17');
        $this->posting->postDocument($this->supplierId, 'invoice', $inv, [
            self::l('311', 'debit', 500.00), self::l('602', 'credit', 500.00),
        ], $this->opts(self::YEAR . '-03-03'));
        $internal = $this->posting->postDocument($this->supplierId, 'manual', null, [
            self::l('321', 'debit', 500.00),
            self::l('311', 'credit', 500.00),
        ], $this->opts(self::YEAR . '-03-20'));

        $pdo = $this->db->pdo();
        $account = (int) $pdo->query("SELECT id FROM chart_of_accounts WHERE supplier_id = {$this->supplierId} AND account_code = '311'")->fetchColumn();
        $settle = $pdo->prepare("INSERT INTO invoice_settlements (supplier_id, doc_type, doc_id, settled_on, amount, account_id, note, status, journal_entry_id, invoice_payment_id, created_by)
                                 VALUES (?, ?, ?, ?, ?, ?, 'převzato', 'confirmed', ?, ?, ?)");
        foreach ([[$piA, 300.00], [$piB, 200.00]] as [$pi, $amount]) {
            $settle->execute([$this->supplierId, 'purchase_invoice', $pi, self::YEAR . '-03-20', $amount, $account, $internal, null, $this->userId]);
            $pdo->prepare("UPDATE purchase_invoices SET status = 'paid', paid_at = ? WHERE id = ?")->execute([self::YEAR . '-03-20', $pi]);
        }
        $pdo->prepare("INSERT INTO invoice_payments (supplier_id, invoice_id, paid_on, amount, currency, source) VALUES (?, ?, ?, 500.00, 'CZK', 'settlement')")
            ->execute([$this->supplierId, $inv, self::YEAR . '-03-20']);
        $payment = (int) $pdo->lastInsertId();
        $settle->execute([$this->supplierId, 'invoice', $inv, self::YEAR . '-03-20', 500.00, $account, $internal, $payment, $this->userId]);
        $pdo->prepare("UPDATE invoices SET status = 'paid', paid_total = 500.00, paid_at = ? WHERE id = ?")->execute([self::YEAR . '-03-20', $inv]);

        $asOf = self::YEAR . '-03-31';
        self::assertNull($this->partner($this->account('321', $asOf), $vendor), 'Saldokonto přijaté faktury uzavírá zápočtem.');
        self::assertNull($this->partner($this->account('311', $asOf), $client), 'Saldokonto vydané faktury uzavírá evidovanou platbou.');
        $closing = new ClosingRepository($this->db);
        $purchases = array_column($closing->paidPurchasesOpenSaldo($this->supplierId, $asOf), 'id');
        self::assertNotContains($piA, $purchases);
        self::assertNotContains($piB, $purchases);
        self::assertNotContains($inv, array_column($closing->paidInvoicesOpenSaldo($this->supplierId, $asOf), 'id'));

        // Před datem zápočtu jsou doklady pořád otevřené (kontrola k 19. 3. je nehlásí jen proto,
        // že ještě nejsou uhrazené): zápočet se počítá až od svého dne.
        $pdo->prepare("UPDATE purchase_invoices SET paid_at = NULL WHERE id = ?")->execute([$piA]);
        $early = array_column($closing->paidPurchasesOpenSaldo($this->supplierId, self::YEAR . '-03-19'), 'saldo', 'id');
        self::assertSame(self::cents(300.00), self::cents($early[$piA] ?? 0), 'Zápočet po rozvahovém dni nevyrovnává.');
    }

    /**
     * Ruční zápis 321/365 vyrovnává přijatou fakturu a účetní ho k ní eviduje dvakrát:
     * zápočtem i měkkou vazbou. Částka se smí započíst jednou. Dřív kontrola hlásila doklad
     * jako přeplacený a částečné vyrovnání by v saldokontu vypadalo jako dvojnásobné.
     */
    public function testManualEntryWithSettlementAndLinkCountsOnce(): void
    {
        $vendor = $this->client('Zápočet s vazbou dodavatel');
        $paid = $this->purchaseInvoice($vendor, 2799.00, self::YEAR . '-03-01', self::YEAR . '-03-15');
        $this->postPurchase($paid, [self::l('518', 'debit', 2799.00), self::l('321', 'credit', 2799.00)], self::YEAR . '-03-01');
        $this->settleByManualEntry('purchase_invoice', $paid, 2799.00, self::YEAR . '-03-20');
        $this->db->pdo()->prepare("UPDATE purchase_invoices SET status = 'paid', paid_at = ? WHERE id = ?")->execute([self::YEAR . '-03-20', $paid]);

        $closing = new ClosingRepository($this->db);
        self::assertNotContains($paid, array_column($closing->paidPurchasesOpenSaldo($this->supplierId, self::YEAR . '-03-31'), 'id'));

        $partial = $this->purchaseInvoice($vendor, 1000.00, self::YEAR . '-04-01', self::YEAR . '-04-15');
        $this->postPurchase($partial, [self::l('518', 'debit', 1000.00), self::l('321', 'credit', 1000.00)], self::YEAR . '-04-01');
        $this->settleByManualEntry('purchase_invoice', $partial, 400.00, self::YEAR . '-04-20');

        $acc = $this->account('321', self::YEAR . '-04-30');
        self::assertSame(0, self::cents($acc['difference']), 'Saldokonto musí sedět na hlavní knihu.');
        self::assertSame(self::cents(600.00), self::cents($acc['open_items_total']), 'Zbývá 600, ne 200.');
    }

    /** Vydaná faktura: zápočet s evidovanou platbou a zároveň vazba na tentýž zápis. */
    public function testManualEntryWithIssuedSettlementAndLinkCountsOnce(): void
    {
        $client = $this->client('Zápočet s vazbou odběratel');
        $inv = $this->invoice($client, 500.00, self::YEAR . '-03-03', self::YEAR . '-03-17');
        $this->posting->postDocument($this->supplierId, 'invoice', $inv, [
            self::l('311', 'debit', 500.00), self::l('602', 'credit', 500.00),
        ], $this->opts(self::YEAR . '-03-03'));
        $this->settleByManualEntry('invoice', $inv, 500.00, self::YEAR . '-03-20');
        $this->db->pdo()->prepare("UPDATE invoices SET status = 'paid', paid_total = 500.00, paid_at = ? WHERE id = ?")->execute([self::YEAR . '-03-20', $inv]);

        $closing = new ClosingRepository($this->db);
        self::assertNotContains($inv, array_column($closing->paidInvoicesOpenSaldo($this->supplierId, self::YEAR . '-03-31'), 'id'));
    }

    /**
     * Vazba bez evidovaného zápočtu vyrovnává dál: kontrola zápočtů se jí nesmí dotknout.
     */
    public function testManualEntryWithLinkOnlyStillSettles(): void
    {
        $vendor = $this->client('Jen vazba dodavatel');
        $pi = $this->purchaseInvoice($vendor, 300.00, self::YEAR . '-03-01', self::YEAR . '-03-15');
        $this->postPurchase($pi, [self::l('518', 'debit', 300.00), self::l('321', 'credit', 300.00)], self::YEAR . '-03-01');
        $entry = $this->posting->postDocument($this->supplierId, 'manual', null, [
            self::l('321', 'debit', 300.00), self::l('365', 'credit', 300.00),
        ], $this->opts(self::YEAR . '-03-20'));
        $this->links->add($entry, $this->supplierId, 'purchase_invoice', $pi, null, $this->userId);
        $this->db->pdo()->prepare("UPDATE purchase_invoices SET status = 'paid', paid_at = ? WHERE id = ?")->execute([self::YEAR . '-03-20', $pi]);

        $closing = new ClosingRepository($this->db);
        self::assertNotContains($pi, array_column($closing->paidPurchasesOpenSaldo($this->supplierId, self::YEAR . '-03-31'), 'id'));
        self::assertNull($this->partner($this->account('321', self::YEAR . '-03-31'), $vendor));
    }

    /**
     * Kontrola zaplacené proformy: inkaso na účtu záloh i na 311 je v deníku, bez inkasa
     * nebo s inkasem jinam se hlásí. Firma může z 311 přejít na 324 a staré roky nechat být.
     */
    public function testPaidProformaFindingAcceptsAdvanceAccountAnd311(): void
    {
        $client = $this->client('Proforma kontrola');
        $paidProforma = function (string $date) use ($client): int {
            $id = $this->invoice($client, 1210.00, $date, $date);
            $this->db->pdo()->prepare("UPDATE invoices SET invoice_type = 'proforma', status = 'paid', paid_at = ? WHERE id = ?")->execute([$date, $id]);
            return $id;
        };
        $collect = function (int $proforma, string $date, string $account): void {
            $tx = $this->bankInvoicePayment($proforma, 1210.00, $date);
            $this->posting->postDocument($this->supplierId, 'bank', $tx, [
                self::l('221', 'debit', 1210.00),
                self::l($account, 'credit', 1210.00),
            ], $this->opts($date));
        };

        $on324 = $paidProforma(self::YEAR . '-05-01');
        $collect($on324, self::YEAR . '-05-02', '324');
        $on311 = $paidProforma(self::YEAR . '-05-03');
        $collect($on311, self::YEAR . '-05-04', '311');
        $elsewhere = $paidProforma(self::YEAR . '-05-05');
        $collect($elsewhere, self::YEAR . '-05-06', '602');
        $missing = $paidProforma(self::YEAR . '-05-07');

        $found = array_column((new ClosingRepository($this->db))->paidProformasWithoutAdvance($this->supplierId, self::YEAR . '-05-31'), 'id');
        self::assertNotContains($on324, $found);
        self::assertNotContains($on311, $found);
        self::assertContains($elsewhere, $found);
        self::assertContains($missing, $found);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** Ruční zápis vyrovnávající doklad, navázaný na něj zápočtem i měkkou vazbou. */
    private function settleByManualEntry(string $docType, int $docId, float $amount, string $date): void
    {
        $lines = $docType === 'invoice'
            ? [self::l('365', 'debit', $amount), self::l('311', 'credit', $amount)]
            : [self::l('321', 'debit', $amount), self::l('365', 'credit', $amount)];
        $entry = $this->posting->postDocument($this->supplierId, 'manual', null, $lines, $this->opts($date));
        $pdo = $this->db->pdo();
        $payment = null;
        if ($docType === 'invoice') {
            $pdo->prepare("INSERT INTO invoice_payments (supplier_id, invoice_id, paid_on, amount, currency, source) VALUES (?, ?, ?, ?, 'CZK', 'settlement')")
                ->execute([$this->supplierId, $docId, $date, $amount]);
            $payment = (int) $pdo->lastInsertId();
        }
        $account = (int) $pdo->query("SELECT id FROM chart_of_accounts WHERE supplier_id = {$this->supplierId} AND account_code = '365'")->fetchColumn();
        $pdo->prepare("INSERT INTO invoice_settlements (supplier_id, doc_type, doc_id, settled_on, amount, account_id, note, status, journal_entry_id, invoice_payment_id, created_by)
                       VALUES (?, ?, ?, ?, ?, ?, 'zápočet', 'confirmed', ?, ?, ?)")
            ->execute([$this->supplierId, $docType, $docId, $date, $amount, $account, $entry, $payment, $this->userId]);
        $this->links->add($entry, $this->supplierId, $docType, $docId, null, $this->userId);
    }

    /**
     * Doklad zaúčtovaný víc zápisy jako po převodu: první nese zdroj dokladu, další jsou
     * ruční zápisy ve skupině zaúčtování dokladu, všechny navázané i měkkou vazbou.
     *
     * @param list<list<array{account_code:string, side:string, amount:float}>> $entries
     */
    private function postInGroup(string $docType, int $docId, array $entries, string $date): void
    {
        $ids = [];
        foreach ($entries as $i => $lines) {
            $ids[] = $this->posting->postDocument($this->supplierId, $i === 0 ? $docType : 'manual', $i === 0 ? $docId : null, $lines, $this->opts($date));
        }
        (new DocumentPostingGroup($this->db))->attach($this->supplierId, $docType, $docId, $ids);
        foreach ($ids as $id) {
            $this->links->add($id, $this->supplierId, $docType, $docId, null, $this->userId);
        }
    }

    /** @param list<array{account_code:string, side:string, amount:float}> $lines */
    private function opening(array $lines): int
    {
        return $this->posting->postDocument($this->supplierId, 'opening', $this->periodId, $lines, $this->opts(self::YEAR . '-01-01'));
    }

    /** @return array<string,mixed> */
    private function opts(string $date): array
    {
        return ['entry_date' => $date, 'posted_by' => $this->userId, 'user_id' => $this->userId];
    }

    private function analytic(string $parent, string $code): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO chart_of_accounts (supplier_id, account_code, name, account_type, normal_side, is_synthetic, parent_id, is_clearing)
             SELECT supplier_id, ?, ?, account_type, normal_side, 0, id, is_clearing FROM chart_of_accounts WHERE supplier_id = ? AND account_code = ?"
        )->execute([$code, 'Analytika ' . $code, $this->supplierId, $parent]);
    }

    private function client(string $name): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, main_email, currency_default_id)
             VALUES (?, ?, "Ulice 1", "Praha", "11000", ?, ?, ?)'
        )->execute([$this->supplierId, $name, $this->czId, 'c' . uniqid() . '@example.com', $this->currencyId]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function invoice(int $clientId, float $total, string $issue, string $due): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO invoices (supplier_id, varsymbol, client_id, issue_date, due_date, currency_id, created_by, total_with_vat, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, "issued")'
        )->execute([$this->supplierId, (string) random_int(1000000000, 1999999999), $clientId, $issue, $due, $this->currencyId, $this->userId, $total]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function purchaseInvoice(int $vendorId, float $total, string $issue, string $due, float $vat = 0.0): int
    {
        $vs = 'PF-' . random_int(1000000000, 1999999999);
        $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, varsymbol, vendor_id, vendor_invoice_number, document_kind,
                 issue_date, due_date, received_at, currency_id, vendor_snapshot, total_without_vat, total_vat, total_with_vat, status, created_by)
             VALUES (?, ?, ?, ?, "invoice", ?, ?, ?, ?, "{}", ?, ?, ?, "received", ?)'
        )->execute([$this->supplierId, $vs, $vendorId, $vs, $issue, $due, $issue, $this->currencyId, $total - $vat, $vat, $total, $this->userId]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /** Zálohová přijatá faktura zaplacená z banky na 314 (payment_matches + zápis 314/221). */
    private function paidAdvance(int $vendorId, float $amount, string $paidOn): int
    {
        $advance = $this->purchaseInvoice($vendorId, $amount, $paidOn, $paidOn);
        $this->db->pdo()->prepare("UPDATE purchase_invoices SET document_kind = 'advance', status = 'paid', paid_at = ? WHERE id = ?")
            ->execute([$paidOn, $advance]);
        $tx = $this->bankPurchasePayment($advance, $amount, $paidOn);
        $this->posting->postDocument($this->supplierId, 'bank', $tx, [
            self::l('314', 'debit', $amount),
            self::l('221', 'credit', $amount),
        ], $this->opts($paidOn));
        return $advance;
    }

    private function deduction(int $finalId, int $advanceId, float $amount): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO purchase_advance_deductions (supplier_id, final_invoice_id, advance_invoice_id, amount, created_by) VALUES (?, ?, ?, ?, ?)'
        )->execute([$this->supplierId, $finalId, $advanceId, $amount, $this->userId]);
    }

    /** Výpis + pohyb (kladná částka = příjem). Vrací id pohybu. */
    private function bankTransaction(float $signedAmount, string $postedOn): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO bank_statements (supplier_id, source, file_name, file_hash, account_number, bank_code, statement_date)
             VALUES (?, "gpc", "saldo-prevod.gpc", ?, "1000000005", "0100", ?)'
        )->execute([$this->supplierId, hash('sha256', 'saldo-prevod-' . random_int(1, PHP_INT_MAX)), $postedOn]);
        $statementId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO bank_transactions (statement_id, source, posted_at, amount, currency, counterparty_name, description, match_status)
             VALUES (?, "statement", ?, ?, "CZK", "Partner", "Platba", "manual")'
        )->execute([$statementId, $postedOn, $signedAmount]);
        return (int) $pdo->lastInsertId();
    }

    private function match(int $txId, int $purchaseInvoiceId, float $amount): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payment_matches (supplier_id, bank_transaction_id, purchase_invoice_id, amount, match_type) VALUES (?, ?, ?, ?, "manual")'
        )->execute([$this->supplierId, $txId, $purchaseInvoiceId, $amount]);
    }

    private function bankPurchasePayment(int $purchaseInvoiceId, float $amount, string $postedOn): int
    {
        $tx = $this->bankTransaction(-$amount, $postedOn);
        $this->match($tx, $purchaseInvoiceId, $amount);
        return $tx;
    }

    private function bankInvoicePayment(int $invoiceId, float $amount, string $paidOn): int
    {
        $tx = $this->bankTransaction($amount, $paidOn);
        $this->db->pdo()->prepare(
            'INSERT INTO invoice_payments (supplier_id, invoice_id, paid_on, amount, currency, source, bank_transaction_id)
             VALUES (?, ?, ?, ?, "CZK", "bank", ?)'
        )->execute([$this->supplierId, $invoiceId, $paidOn, $amount, $tx]);
        return $tx;
    }

    /** @param list<array{account_code:string, side:string, amount:float}> $lines */
    private function postPurchase(int $purchaseInvoiceId, array $lines, string $date): int
    {
        return $this->posting->postDocument($this->supplierId, 'purchase_invoice', $purchaseInvoiceId, $lines, $this->opts($date));
    }

    /** @return array{account_code:string, side:string, amount:float} */
    private static function l(string $code, string $side, float $amount): array
    {
        return ['account_code' => $code, 'side' => $side, 'amount' => $amount];
    }

    /** @return array<string,mixed> */
    private function account(string $code, string $asOf): array
    {
        foreach ($this->saldo->build($this->supplierId, $this->periodId, $asOf, $code)['accounts'] as $acc) {
            if ((string) $acc['account']['code'] === $code) {
                return $acc;
            }
        }
        self::fail("Saldokonto {$code} k {$asOf} chybí.");
    }

    /**
     * @param array<string,mixed> $acc
     * @return array<string,mixed>|null
     */
    private function partner(array $acc, int $partnerId): ?array
    {
        foreach ($acc['partners'] as $p) {
            if ((int) $p['partner_id'] === $partnerId) {
                return $p;
            }
        }
        return null;
    }

    /**
     * @param array<string,mixed> $acc
     * @return array<int,array<string,mixed>>
     */
    private function itemsByDoc(array $acc, int $partnerId): array
    {
        $out = [];
        foreach ($this->partner($acc, $partnerId)['items'] ?? [] as $item) {
            $out[(int) $item['doc_id']] = $item;
        }
        return $out;
    }

    private static function cents(float|int|string|null $amount): int
    {
        return (int) round(((float) $amount) * 100.0);
    }
}
