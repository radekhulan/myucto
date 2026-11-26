<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Closing\AssetSaleDerecognitionCheck;
use MyInvoice\Service\Accounting\Closing\ClosingService;
use MyInvoice\Service\Accounting\PostingService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Kontrola `asset_sale_without_derecognition`: tržba z prodeje dlouhodobého majetku (641)
 * bez odúčtování zůstatkové ceny (MD 541 / D 0xx).
 *
 * Prodej pozemku se zaúčtuje s fakturou, odúčtování je samostatný interní doklad a na ten
 * se zapomíná. Pozemky navíc často nejsou v evidenci majetku, takže je nepohlídá ani
 * vyřazení karty. Izolovaný supplier v transakci s rollbackem.
 */
#[Group('integration')]
final class AssetSaleDerecognitionCheckTest extends TestCase
{
    private const YEAR = 2087;
    private const STARTS_ON = self::YEAR . '-01-01';
    private const ENDS_ON = self::YEAR . '-12-31';

    private Connection $db;
    private ClosingService $closing;
    private AssetSaleDerecognitionCheck $check;
    private PostingService $posting;
    private AccountingPeriodRepository $periods;

    private int $supplierId = 0;
    private int $userId = 0;
    private int $periodId = 0;
    private int $clientId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje, test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db = $container->get(Connection::class);
            $this->closing = $container->get(ClosingService::class);
            $this->check = $container->get(AssetSaleDerecognitionCheck::class);
            $this->posting = $container->get(PostingService::class);
            $this->periods = $container->get(AccountingPeriodRepository::class);
            $seeder = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $vatRateId = $this->vatRateId = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->userId === 0 || $this->currencyId === 0 || $vatRateId === 0 || $czId === 0) {
            $this->markTestSkipped('Chybí základní data (user/currency/vat_rate/country) v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id)
             VALUES (?, "Testovací 1", "Praha", "11000", ?, ?, ?, ?)'
        )->execute(['Pozemky test s.r.o.', $czId, 'pozemky-test@example.com', $this->currencyId, $vatRateId]);
        $this->supplierId = (int) $pdo->lastInsertId();
        $seeder->seedForSupplier($this->supplierId);
        $this->periodId = $this->periods->create($this->supplierId, self::YEAR, self::STARTS_ON, self::ENDS_ON);

        $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, currency_default_id)
             VALUES (?, "Kupující pozemku s.r.o.", "Odběratelská 1", "Praha", "11000", ?, ?)'
        )->execute([$this->supplierId, $czId, $this->currencyId]);
        $this->clientId = (int) $pdo->lastInsertId();
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

    public function testLandSaleWithoutDerecognitionIsReported(): void
    {
        $invoiceId = $this->landSaleInvoice('FV-POZ-1', self::YEAR . '-04-10', 250000.0);

        $check = $this->monthlyCheck(self::YEAR . '-04-01', self::YEAR . '-04-30');

        self::assertFalse($check['ok'], 'Prodej pozemku bez MD 541 / D 031 musí kontrola nahlásit.');
        self::assertSame('warning', $check['severity']);
        self::assertSame(1, $check['value']['count']);
        $finding = $check['value']['findings'][0];
        self::assertSame('invoice', $finding['doc_type']);
        self::assertSame($invoiceId, $finding['doc_id']);
        self::assertSame(self::YEAR . '-04-10', $finding['doc_date']);
        self::assertEqualsWithDelta(250000.0, $finding['amount'], 0.01);
        self::assertStringContainsString('MD 541', (string) $finding['note']);
        self::assertNotNull($finding['entry_id'], 'Nález musí odkazovat i na zápis tržby v deníku.');
    }

    public function testInternalDocumentWithResidualValueClearsTheFinding(): void
    {
        $this->landSaleInvoice('FV-POZ-2', self::YEAR . '-04-10', 250000.0);
        $this->derecognition(self::YEAR . '-04-10', [['541', 'debit', 40000.0], ['031', 'credit', 40000.0]]);

        $check = $this->monthlyCheck(self::YEAR . '-04-01', self::YEAR . '-04-30');

        self::assertTrue($check['ok'], 'Interní doklad MD 541 / D 031 prodej pokrývá.');
        self::assertSame(1, $check['value']['sales']);
        self::assertSame(1, $check['value']['derecognitions'], 'MD 541 / D 031 je jedno odúčtování, ne dvě.');
    }

    public function testSaleOfNonAssetIsNotReported(): void
    {
        $invoiceId = $this->invoice('FV-SLUZBA-1', self::YEAR . '-04-10');
        $this->post('invoice', $invoiceId, self::YEAR . '-04-10', [['311', 'debit', 12100.0], ['602', 'credit', 10000.0], ['343', 'credit', 2100.0]]);

        $check = $this->monthlyCheck(self::YEAR . '-04-01', self::YEAR . '-04-30');

        self::assertTrue($check['ok']);
        self::assertSame(0, $check['value']['sales']);
    }

    /**
     * Odúčtování se páruje postupně podle data, takže nepokrytý zůstane nejnovější prodej.
     * Odúčtování z minulého měsíce se počítá: rozsah prodejů i odúčtování běží od začátku období.
     */
    public function testDerecognitionsCoverSalesInDateOrder(): void
    {
        $this->landSaleInvoice('FV-POZ-3', self::YEAR . '-03-20', 100000.0);
        $this->derecognition(self::YEAR . '-03-20', [['541', 'debit', 5000.0], ['031', 'credit', 5000.0]]);
        $later = $this->landSaleInvoice('FV-POZ-4', self::YEAR . '-04-15', 80000.0);

        $march = $this->monthlyCheck(self::YEAR . '-03-01', self::YEAR . '-03-31');
        self::assertTrue($march['ok'], 'Březnový prodej je odúčtovaný.');

        $april = $this->monthlyCheck(self::YEAR . '-04-01', self::YEAR . '-04-30');
        self::assertFalse($april['ok']);
        self::assertSame(1, $april['value']['count']);
        self::assertSame($later, $april['value']['findings'][0]['doc_id']);
        self::assertSame(2, $april['value']['sales']);
        self::assertSame(1, $april['value']['derecognitions']);
    }

    /** Tržba zaúčtovaná ručním zápisem (faktura jen kvůli DPH) se čte z deníku stejně. */
    public function testManualRevenueEntryIsReportedAsJournalEntry(): void
    {
        $entryId = $this->post('manual', null, self::YEAR . '-05-05', [['378', 'debit', 90000.0], ['641', 'credit', 90000.0]]);

        $check = $this->monthlyCheck(self::YEAR . '-05-01', self::YEAR . '-05-31');

        self::assertFalse($check['ok']);
        self::assertSame('journal_entry', $check['value']['findings'][0]['doc_type']);
        self::assertSame($entryId, $check['value']['findings'][0]['doc_id']);
    }

    /** Doprodej plně odepsaného majetku nemá 541, odúčtování je jen MD 082 / D 022. */
    public function testFullyDepreciatedAssetDisposalCountsAsDerecognition(): void
    {
        $this->landSaleInvoice('FV-AUTO-1', self::YEAR . '-06-01', 30000.0);
        $this->derecognition(self::YEAR . '-06-01', [['082', 'debit', 400000.0], ['022', 'credit', 400000.0]]);

        self::assertTrue($this->monthlyCheck(self::YEAR . '-06-01', self::YEAR . '-06-30')['ok']);
    }

    public function testSaleLinkedToDisposedAssetCardIsNotReported(): void
    {
        $invoiceId = $this->landSaleInvoice('FV-KARTA-1', self::YEAR . '-07-01', 150000.0);
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO assets (supplier_id, inventory_number, name, kind, input_price, acquisition_date,
                                 put_into_use_date, disposal_date, disposal_type, disposal_price, status, tax_group)
             VALUES (?, "POZ-1", "Pozemek parc. 1", "tangible", 60000, ?, ?, ?, "sold", 150000, "disposed", 0)'
        )->execute([$this->supplierId, self::YEAR . '-01-01', self::YEAR . '-01-01', self::YEAR . '-07-01']);
        $assetId = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE invoice_items SET asset_id = ? WHERE invoice_id = ?')->execute([$assetId, $invoiceId]);

        self::assertTrue($this->monthlyCheck(self::YEAR . '-07-01', self::YEAR . '-07-31')['ok']);
    }

    public function testReversedSaleIsNotReported(): void
    {
        $this->landSaleInvoice('FV-POZ-5', self::YEAR . '-08-01', 70000.0);
        $this->db->pdo()->prepare(
            "UPDATE journal_entries SET reversed_by = id WHERE supplier_id = ? AND source_type = 'invoice'"
        )->execute([$this->supplierId]);

        self::assertTrue($this->monthlyCheck(self::YEAR . '-08-01', self::YEAR . '-08-31')['ok']);
    }

    /** V rámci 504 jsou 641/541 smluvní pokuty; tržba z prodeje majetku je 652. */
    public function testNonprofitFrameworkDoesNotReadPenaltyAccount641(): void
    {
        $this->landSaleInvoice('FV-POKUTA-1', self::YEAR . '-09-01', 5000.0);

        $check = $this->check->check($this->supplierId, self::STARTS_ON, self::ENDS_ON, 'cz_504');

        self::assertTrue($check['ok']);
        self::assertSame('652', $check['value']['revenue_account']);
        self::assertSame('552', $check['value']['residual_account']);
    }

    /** @return array<string,mixed> */
    private function monthlyCheck(string $from, string $to): array
    {
        $result = $this->closing->monthlyCheck($this->supplierId, $this->periodId, $from, $to);
        foreach ($result['checks'] as $check) {
            if ($check['key'] === AssetSaleDerecognitionCheck::KEY) {
                return $check;
            }
        }
        self::fail('Měsíční kontrola neobsahuje kontrolu ' . AssetSaleDerecognitionCheck::KEY . '.');
    }

    private function invoice(string $varsymbol, string $date): int
    {
        $this->db->pdo()->prepare(
            "INSERT INTO invoices (supplier_id, client_id, currency_id, varsymbol, issue_date, tax_date, due_date, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'sent', ?)"
        )->execute([$this->supplierId, $this->clientId, $this->currencyId, $varsymbol, $date, $date, $date, $this->userId]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    private function landSaleInvoice(string $varsymbol, string $date, float $amount): int
    {
        $invoiceId = $this->invoice($varsymbol, $date);
        $this->db->pdo()->prepare(
            "INSERT INTO invoice_items (invoice_id, description, quantity, unit_price_without_vat, vat_rate_id, vat_rate_snapshot, total_without_vat,
                                        total_vat, total_with_vat, vat_classification_code, revenue_account_code)
             VALUES (?, 'Prodej pozemku', 1, ?, ?, 0, ?, 0, ?, '3m', '641')"
        )->execute([$invoiceId, $amount, $this->vatRateId, $amount, $amount]);
        $this->post('invoice', $invoiceId, $date, [['311', 'debit', $amount], ['641', 'credit', $amount]]);

        return $invoiceId;
    }

    /** @param list<array{0:string,1:string,2:float}> $lines */
    private function derecognition(string $date, array $lines): int
    {
        return $this->post('manual', null, $date, $lines);
    }

    /** @param list<array{0:string,1:string,2:float}> $lines */
    private function post(string $sourceType, ?int $sourceId, string $date, array $lines): int
    {
        return $this->posting->postDocument(
            $this->supplierId,
            $sourceType,
            $sourceId,
            array_map(static fn (array $l): array => ['account_code' => $l[0], 'side' => $l[1], 'amount' => $l[2]], $lines),
            ['entry_date' => $date, 'description' => 'Syntetický zápis', 'posted' => true, 'user_id' => $this->userId],
        );
    }
}
