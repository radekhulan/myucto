<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\PurchaseInvoice;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingModeRepository;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Repository\TaxConstantsRepository;
use MyInvoice\Service\Invoice\NumberSeriesGapGuard;
use MyInvoice\Service\Invoice\OverduePolicy;
use MyInvoice\Service\License\CommercialFeatureAccess;
use MyInvoice\Service\License\LicenseClient;
use MyInvoice\Service\License\LicenseService;
use MyInvoice\Service\License\LicenseTokenVerifier;
use MyInvoice\Tests\Support\LicenseTokenTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Varování „uhrazeno ručně, úhrada NENÍ zaúčtovaná (321 zůstává otevřený)" patří jen do
 * podvojného účetnictví. V daňové evidenci / paušálu žádný deník ani účet 321 neexistuje
 * a ruční „Uhrazeno" je jediný způsob, jak úhradu zaznamenat — banner by tam hlásil
 * závadu, která nemůže nastat (issue #43).
 *
 * Totéž platí pro podvojnou firmu bez licence (issue #146): právnická osoba dostane
 * podvojný režim už při založení, ale bez licence se doklady neúčtují a deník
 * neotevře. Varování by ji posílalo do modulu, který nemá.
 *
 * Režim se bere k ROKU DOKLADU, ne k dnešku: po přechodu na podvojné účetnictví musí
 * starší doklady zůstat tiché a novější dál hlásit.
 *
 * Izolováno v roce 2097; historie režimu se vrací na původní hodnotu už od 2098-01-01,
 * aby testy pracující s pozdějšími roky nic nepocítily. Řádek licence se vrací v tearDown.
 * Soft-skip pokud chybí cfg.php (CI runner bez DB).
 */
#[Group('integration')]
final class MarkPaidUnpostedByAccountingModeTest extends TestCase
{
    use LicenseTokenTrait;

    private const YEAR = 2097;

    private Connection $db;
    private \Psr\Container\ContainerInterface $container;

    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $userId = 0;
    private int $czId = 0;
    private string $originalMode = 'tax_evidence';
    /** @var array<string,mixed>|null */
    private ?array $originalLicense = null;

    /** @var int[] */
    private array $vendorIds = [];
    /** @var int[] */
    private array $piIds = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $this->container = Bootstrap::buildContainer();
            $this->db        = $this->container->get(Connection::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        if (!$this->db->hasTable('license')) {
            $this->markTestSkipped('Migrace 1139 (license) neproběhla.');
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code='CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId       = (int) ($pdo->query("SELECT id FROM countries WHERE iso2='CZ' LIMIT 1")->fetchColumn() ?: 0);

        if ($this->supplierId === 0 || $this->currencyId === 0 || $this->userId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data v DB.');
        }

        $stmt = $pdo->prepare('SELECT accounting_mode FROM supplier WHERE id = ?');
        $stmt->execute([$this->supplierId]);
        $this->originalMode = (string) ($stmt->fetchColumn() ?: 'tax_evidence');

        $row = $pdo->query('SELECT license_key, token, token_payload, trial_started_at FROM license WHERE id = 1')
            ->fetch(\PDO::FETCH_ASSOC);
        $this->originalLicense = is_array($row) ? $row : null;
        if ($this->originalLicense === null) {
            $this->markTestSkipped('Chybí řádek licence.');
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) return;
        $pdo = $this->db->pdo();
        foreach ($this->piIds as $id) {
            $pdo->prepare('DELETE FROM purchase_invoice_items WHERE purchase_invoice_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM purchase_invoices WHERE id = ?')->execute([$id]);
        }
        foreach ($this->vendorIds as $id) {
            $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$id]);
        }
        $pdo->prepare(
            'DELETE FROM supplier_accounting_modes WHERE supplier_id = ? AND effective_from IN (?, ?)'
        )->execute([$this->supplierId, self::YEAR . '-01-01', (self::YEAR + 1) . '-01-01']);
        if ($this->originalLicense !== null) {
            $pdo->prepare(
                'UPDATE license SET license_key = ?, token = ?, token_payload = ?, trial_started_at = ? WHERE id = 1'
            )->execute([
                $this->originalLicense['license_key'],
                $this->originalLicense['token'],
                $this->originalLicense['token_payload'],
                $this->originalLicense['trial_started_at'],
            ]);
        }
        $this->db->close();
    }

    public function testDoubleEntryYearWarns(): void
    {
        $this->modeForTestYear('double_entry');
        $id = $this->paidPurchase('Dodavatel PU', 'CZ10970001', 'FAK-PU');

        self::assertTrue(
            $this->repo(true)->find($id, $this->supplierId)['mark_paid_unposted'],
            'V podvojném účetnictví ruční úhrada bez zápisu v deníku hlásí otevřený 321.',
        );
    }

    public function testTaxEvidenceYearStaysSilent(): void
    {
        $this->modeForTestYear('tax_evidence');
        $id = $this->paidPurchase('Dodavatel DE', 'CZ10970002', 'FAK-DE');

        self::assertFalse(
            $this->repo(true)->find($id, $this->supplierId)['mark_paid_unposted'],
            'V daňové evidenci / paušálu žádný účet 321 neexistuje — varování nemá co hlásit.',
        );
    }

    public function testDoubleEntryWithoutLicenseStaysSilent(): void
    {
        $this->modeForTestYear('double_entry');
        $id = $this->paidPurchase('Dodavatel BL', 'CZ10970003', 'FAK-BL');

        self::assertFalse(
            $this->repo(false)->find($id, $this->supplierId)['mark_paid_unposted'],
            'Bez licence se deník nevede, podvojná firma nemá kde mít otevřený 321.',
        );
    }

    public static function bankVisibilityCases(): iterable
    {
        yield 'free licence' => [false, false];
        yield 'licensed, posting still missing' => [true, false];
        yield 'foreign bank statement' => [false, true];
    }

    #[DataProvider('bankVisibilityCases')]
    public function testBankPaymentVisibilityDoesNotDependOnPosting(bool $commercial, bool $foreign): void
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $this->modeForTestYear('double_entry');
            $id = $this->paidPurchase('Syntetický dodavatel bankovní úhrady', 'CZ10970004', 'SYN-BANK-VISIBILITY');
            $owner = $this->supplierId;
            if ($foreign) {
                $s = $pdo->prepare('SELECT id FROM supplier WHERE id <> ? ORDER BY id LIMIT 1');
                $s->execute([$owner]);
                $owner = (int) $s->fetchColumn();
                self::assertGreaterThan(0, $owner);
            }
            $pdo->prepare('INSERT INTO bank_statements (supplier_id,file_name,file_hash,account_number,bank_code,currency,statement_date,imported_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)')->execute([$owner, 'synthetic.gpc', hash('sha256',random_bytes(20)), '1000000005','0100','CZK',self::YEAR.'-06-10',$this->userId]);
            $statement = (int) $pdo->lastInsertId();
            $pdo->prepare("INSERT INTO bank_transactions (statement_id,source,posted_at,amount,currency,description,match_status)
                VALUES (?, 'statement', ?, -1000, 'CZK', 'Syntetická úhrada', 'manual')")->execute([$statement,self::YEAR.'-06-10']);
            $transaction = (int) $pdo->lastInsertId();
            $pdo->prepare("INSERT INTO payment_matches (supplier_id,bank_transaction_id,purchase_invoice_id,amount,match_type)
                VALUES (?, ?, ?, 1000, 'manual')")->execute([$this->supplierId,$transaction,$id]);
            $repo = $this->repo($commercial);
            $row = $repo->find($id, $this->supplierId);
            self::assertCount($foreign ? 0 : 1, $row['bank_payments']);
            if (!$foreign) {
                self::assertSame($transaction, $row['bank_payments'][0]['bank_transaction_id']);
                self::assertSame($statement, $row['bank_payments'][0]['statement_id']);
                self::assertSame(1000.0, $row['bank_payments'][0]['amount']);
                self::assertNull($row['bank_payments'][0]['journal_entry_id']);
                self::assertCount(1, $repo->find($id, $this->supplierId)['bank_payments']);
            }
            // Zobrazení vazby nesmí zaměnit bankovní spárování za zaúčtování:
            // placené podvojné účetnictví dál hlásí chybějící zápis úhrady.
            self::assertSame($commercial, $row['mark_paid_unposted']);
        } finally {
            $pdo->rollBack();
        }
    }

    /**
     * Repozitář s licencí v požadovaném stavu: bez klíče rozhoduje jen trial,
     * čerstvý trial má komerční moduly, trial starší 60 dní ne.
     */
    private function repo(bool $commercial): PurchaseInvoiceRepository
    {
        $this->db->pdo()->prepare(
            'UPDATE license SET license_key = NULL, token = NULL, token_payload = NULL, trial_started_at = ? WHERE id = 1'
        )->execute([date('Y-m-d H:i:s', $commercial ? time() : time() - 90 * 86400)]);

        $license = new LicenseService(
            $this->db,
            new Config(['license' => ['public_key' => $this->licensePublicKeyBase64()]]),
            new LicenseTokenVerifier(),
            $this->createStub(LicenseClient::class),
        );
        $access = new CommercialFeatureAccess($license);
        self::assertSame($commercial, $access->isAvailable(), 'Stav licence se nepodařilo nastavit.');

        return new PurchaseInvoiceRepository(
            $this->db,
            $this->container->get(TaxConstantsRepository::class),
            $this->container->get(AccountingModeRepository::class),
            $this->container->get(OverduePolicy::class),
            $this->container->get(NumberSeriesGapGuard::class),
            $access,
        );
    }

    /**
     * Režim platný pro rok testovacích dokladů; od dalšího roku se historie vrací
     * na skutečný režim firmy, ať tenhle test neovlivní doklady jiných testů.
     */
    private function modeForTestYear(string $mode): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO supplier_accounting_modes (supplier_id, effective_from, accounting_mode)
             VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE accounting_mode = VALUES(accounting_mode)'
        );
        $stmt->execute([$this->supplierId, self::YEAR . '-01-01', $mode]);
        $stmt->execute([$this->supplierId, (self::YEAR + 1) . '-01-01', $this->originalMode]);
    }

    private function paidPurchase(string $vendorName, string $dic, string $number): int
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, dic,
                                  main_email, language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Test 1", "Praha", "11000", ?, ?, "v@example.com", "cs", ?, 0, 1)'
        );
        $stmt->execute([$this->supplierId, $vendorName, $this->czId, $dic, $this->currencyId]);
        $vendorId = (int) $pdo->lastInsertId();
        $this->vendorIds[] = $vendorId;

        $date = self::YEAR . '-06-10';
        $stmt = $pdo->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date,
                 due_date, received_at, paid_at, currency_id, reverse_charge, vendor_snapshot,
                 total_without_vat, total_vat, total_with_vat, status, is_fixed_asset,
                 vat_deduction, vat_deduction_percent, tax_deductible, created_by)
             VALUES (?, ?, ?, "invoice", ?, ?, ?, ?, ?, ?, 0, "{}", ?, 0, ?, "paid", 0, "full", 100, 1, ?)'
        );
        $stmt->execute([
            $this->supplierId, $vendorId, $number, $date, $date, $date, $date, $date,
            $this->currencyId, 1000.0, 1000.0, $this->userId,
        ]);
        $id = (int) $pdo->lastInsertId();
        $this->piIds[] = $id;
        return $id;
    }
}
