<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use DI\Container;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Migration\Check\CheckItem;
use MyInvoice\Service\Migration\Check\Checks\AssetAccountUndeterminedCheck;
use MyInvoice\Service\Migration\Check\Checks\AssetActivationMissingCheck;
use MyInvoice\Service\Migration\Check\Checks\FinalInvoiceAdvanceVatCheck;
use MyInvoice\Service\Migration\Check\Checks\ForeignBankInCzkCheck;
use MyInvoice\Service\Migration\Check\Checks\ImportedStatementBalanceCheck;
use MyInvoice\Service\Migration\Check\Checks\PayrollLiabilitiesCheck;
use MyInvoice\Service\Migration\Check\Checks\PurchaseSettledUncoveredCheck;
use MyInvoice\Service\Migration\Check\Checks\SettledWithoutPaymentCheck;
use MyInvoice\Service\Migration\Check\Checks\SubledgerLedgerCheck;
use MyInvoice\Service\Migration\Check\Checks\TaxDocumentAdvanceLinkCheck;
use MyInvoice\Service\Migration\Check\MigrationDataCheckException;
use MyInvoice\Service\Migration\Check\MigrationDataCheckService;
use MyInvoice\Service\Migration\Pohoda\PohodaAssetAccountRepair;
use MyInvoice\Service\Migration\Pohoda\PohodaPriorYearPaymentRepair;
use MyInvoice\Service\Migration\Pohoda\PohodaPriorYearPayments;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Kontrola dat po převodu nad syntetickou firmou převedenou z POHODY (agenda 2025):
 * nález, náhled, oprava, opakovaná oprava (idempotence) a chráněné období u každé kontroly.
 * Vše v transakci, která se na konci vrátí.
 */
#[Group('integration')]
final class MigrationDataCheckTest extends TestCase
{
    private const ASSET_REVIEW_PREFIX = 'Převod z POHODY - ke kontrole: ';
    private const ASSET_REVIEW_NOTE = 'majetkový účet se z počátečních stavů nepodařilo určit';

    private Container $c;
    private PDO $pdo;
    private MigrationDataCheckService $service;
    private int $supplierId = 0;
    private int $czk = 0;
    private int $eur = 0;
    private int $clientId = 0;
    private int $userId = 0;
    private int $vatRateId = 0;
    private int $periodId = 0;
    private int $seq = 0;
    /** @var array<string,int> */
    private array $accounts = [];

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php missing');
        }
        $this->c = Bootstrap::buildContainer();
        $this->pdo = $this->c->get(Connection::class)->pdo();
        $this->userId = (int) ($this->pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->userId === 0) {
            $this->markTestSkipped('Chybí uživatel (ci-seed).');
        }
        $this->pdo->beginTransaction();
        $this->pdo->exec("INSERT INTO supplier (company_name, street, city, zip, email, country_id, default_currency_id, default_vat_rate_id, accounting_mode)
            SELECT 'Synteticka migrace s.r.o.', 'Testovaci 1', 'Test', '10000', 'migrace@example.com',
                   (SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1), (SELECT id FROM currencies WHERE code = 'CZK' LIMIT 1),
                   (SELECT id FROM vat_rates ORDER BY id LIMIT 1), 'double_entry'");
        $this->supplierId = (int) $this->pdo->lastInsertId();
        $this->vatRateId = (int) $this->pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn();
        foreach (['CZK' => 'Kč', 'EUR' => '€'] as $code => $symbol) {
            $this->pdo->prepare("INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
                VALUES (?, ?, ?, ?, ?, ?, 2, 1, ?)")->execute([$this->supplierId, $code, $code, $symbol, $code, $code, $code === 'CZK' ? 1 : 0]);
            $code === 'CZK' ? $this->czk = (int) $this->pdo->lastInsertId() : $this->eur = (int) $this->pdo->lastInsertId();
        }
        $this->pdo->prepare("INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, currency_default_id)
            VALUES (?, 'Odberatel Alfa s.r.o.', 'Ulice 1', 'Mesto', '11000', (SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1), ?)")->execute([$this->supplierId, $this->czk]);
        $this->clientId = (int) $this->pdo->lastInsertId();
        $this->periodId = $this->c->get(AccountingPeriodRepository::class)->create($this->supplierId, 2025, '2025-01-01', '2025-12-31');
        $this->pdo->prepare("INSERT INTO pohoda_imports (supplier_id, mode, status, agenda_year) VALUES (?, 'import', 'completed', 2025)")->execute([$this->supplierId]);
        $this->service = $this->c->get(MigrationDataCheckService::class);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function testOnlyChecksForMigrationSourceApply(): void
    {
        $keys = array_column($this->service->summary($this->supplierId)['checks'], 'key');
        self::assertContains(SettledWithoutPaymentCheck::KEY, $keys);
        self::assertContains(AssetAccountUndeterminedCheck::KEY, $keys);
        self::assertContains(AssetActivationMissingCheck::KEY, $keys);
        self::assertContains(TaxDocumentAdvanceLinkCheck::KEY, $keys);
        self::assertSame(['pohoda'], $this->service->summary($this->supplierId)['sources']);

        $this->pdo->prepare("DELETE FROM pohoda_imports WHERE supplier_id = ?")->execute([$this->supplierId]);
        $service = Bootstrap::buildContainer()->get(MigrationDataCheckService::class);
        self::assertSame(['sources' => [], 'checks' => []], $service->summary($this->supplierId));
        $this->expectException(MigrationDataCheckException::class);
        $service->detail($this->supplierId, SettledWithoutPaymentCheck::KEY);
    }

    public function testPohodaPriorYearPaymentIsAddedOnceWithAudit(): void
    {
        $prior = $this->invoice('invoice', 'FV24901', '2024-12-10', 1210.0, 210.0, paidTotal: 1210.0, status: 'paid');
        $agenda = $this->invoice('invoice', 'FV25901', '2025-02-01', 1000.0, 0.0, paidTotal: 500.0, status: 'sent');

        $detail = $this->service->detail($this->supplierId, SettledWithoutPaymentCheck::KEY);
        self::assertSame('fixable', $detail['status']);
        self::assertSame([$prior => 'fixable', $agenda => 'review'], array_column($detail['items'], 'state', 'entity_id'));
        self::assertSame(1210.0, $detail['fixable_amount_czk']);

        $preview = $this->service->preview($this->supplierId, SettledWithoutPaymentCheck::KEY);
        self::assertTrue($preview['can_fix']);
        self::assertSame(1, $preview['count']);

        $result = $this->service->fix($this->supplierId, SettledWithoutPaymentCheck::KEY, $this->userId, [], $preview['fingerprint']);
        self::assertSame(1, $result['fixed_count']);
        self::assertSame('review', $result['after']['status']);
        $payment = $this->pdo->query("SELECT paid_on, amount, source, note FROM invoice_payments WHERE invoice_id = {$prior}")->fetch(PDO::FETCH_ASSOC);
        self::assertSame(['paid_on' => '2024-12-31', 'amount' => '1210.00', 'source' => 'settlement', 'note' => PohodaPriorYearPayments::NOTE], $payment);
        self::assertSame(0, $this->rows('invoice_payments', "invoice_id = {$agenda}"));
        self::assertSame(1, $this->rows('activity_log', "action = '" . PohodaPriorYearPaymentRepair::AUDIT_ACTION . "' AND entity_id = {$prior}"));
        self::assertSame(1, $this->rows('activity_log', "action = '" . MigrationDataCheckService::AUDIT_ACTION . "'"));

        // Opakovaná oprava nic nezdvojí.
        $this->assertNothingToFix(SettledWithoutPaymentCheck::KEY);
        self::assertSame(1, $this->rows('invoice_payments', "invoice_id = {$prior}"));
    }

    public function testPohodaPriorYearPaymentInLockedPeriodIsOnlyReported(): void
    {
        $prior = $this->invoice('invoice', 'FV24902', '2024-11-10', 605.0, 105.0, paidTotal: 605.0, status: 'paid');
        $this->lockUntil('2025-01-31');

        $detail = $this->service->detail($this->supplierId, SettledWithoutPaymentCheck::KEY);
        self::assertSame([$prior => 'locked'], array_column($detail['items'], 'state', 'entity_id'));
        self::assertFalse($this->service->preview($this->supplierId, SettledWithoutPaymentCheck::KEY)['can_fix']);
        $this->assertNothingToFix(SettledWithoutPaymentCheck::KEY);
        self::assertSame(0, $this->rows('invoice_payments', "invoice_id = {$prior}"));
    }

    public function testPohodaAssetAccountRepairRespectsOpeningBalanceAndCardState(): void
    {
        $this->account('021.000', 'asset');
        $this->account('022.000', 'asset');
        $this->account('701.000', 'equity');
        $opening = $this->post('2025-01-01', '021.000', '701.000', 1200.0, 'opening');
        $this->pdo->prepare('UPDATE journal_entries SET source_id = period_id WHERE supplier_id = ? AND id = ?')
            ->execute([$this->supplierId, $opening]);
        $lines = $this->pdo->prepare('INSERT INTO journal_entry_lines (supplier_id, entry_id, account_id, side, amount, line_no)
            VALUES (?, ?, ?, ?, ?, ?)');
        foreach ([['021.000', 'credit', 199.0], ['701.000', 'debit', 199.0], ['022.000', 'debit', 2500.0], ['701.000', 'credit', 2500.0]] as $i => [$account, $side, $amount]) {
            $lines->execute([$this->supplierId, $opening, $this->accounts[$account], $side, $amount, $i + 10]);
        }
        $this->map('journal_entry', '2025|PS', $opening);
        $this->post('2025-01-01', '021.000', '701.000', 50000.0, 'opening');

        $existing = $this->assetCard(550.0, '081.000', account: '021.000');
        $note = self::ASSET_REVIEW_PREFIX . 'ověřte odpisy; ' . self::ASSET_REVIEW_NOTE . '; ověřte zůstatek.';
        $building = $this->assetCard(300.0, '081.000', $note);
        $capacity = $this->assetCard(1.01, '081.000', self::ASSET_REVIEW_PREFIX . self::ASSET_REVIEW_NOTE . '.');
        $machine = $this->assetCard(900.0, '082', self::ASSET_REVIEW_PREFIX . self::ASSET_REVIEW_NOTE . '.', 'in_use');
        $missing = $this->assetCard(333.0, '081.777', self::ASSET_REVIEW_PREFIX . self::ASSET_REVIEW_NOTE . '.');
        $depreciated = $this->assetCard(800.0, '082', self::ASSET_REVIEW_PREFIX . self::ASSET_REVIEW_NOTE . '.', 'in_use');
        $booked = $this->assetCard(800.0, '082', self::ASSET_REVIEW_PREFIX . self::ASSET_REVIEW_NOTE . '.', 'in_use');
        $disposed = $this->assetCard(800.0, '082', self::ASSET_REVIEW_PREFIX . self::ASSET_REVIEW_NOTE . '.', 'disposed');
        $improvement = $this->pdo->prepare('INSERT INTO asset_improvements (supplier_id, asset_id, completed_on, amount) VALUES (?, ?, ?, ?)');
        $improvement->execute([$this->supplierId, $existing, '2024-12-30', 50.0]);
        $improvement->execute([$this->supplierId, $building, '2024-12-31', 102.0]);
        $improvement->execute([$this->supplierId, $building, '2025-01-01', 7000.0]);
        $this->pdo->prepare("INSERT INTO depreciation_entries (supplier_id, asset_id, kind, fiscal_year, amount, full_amount, residual_value_end)
            VALUES (?, ?, 'tax', 2025, 100, 100, 700)")->execute([$this->supplierId, $depreciated]);
        $this->pdo->prepare("INSERT INTO journal_entries (supplier_id, period_id, entry_date, source_type, source_id)
            VALUES (?, ?, '2025-02-01', 'asset', ?)")->execute([$this->supplierId, $this->periodId, $booked]);

        $repair = $this->c->get(PohodaAssetAccountRepair::class);
        self::assertContains($this->supplierId, $repair->convertedSuppliers());
        $dryRun = $repair->run([$this->supplierId], false)[0]['cards'];
        self::assertSame([$building => '021.000', $capacity => null, $machine => '022.000', $missing => null, $booked => null], array_column($dryRun, 'new_account', 'id'));
        self::assertStringContainsString('počáteční stav', $dryRun[1]['reason']);
        self::assertStringContainsString('1 001,00 Kč', $dryRun[1]['reason']);
        self::assertSame(1, $this->rows('assets', "id = {$building} AND asset_account_code = '022'"));
        self::assertSame(0, $this->rows('activity_log', "action = '" . PohodaAssetAccountRepair::AUDIT_ACTION . "'"));

        $key = AssetAccountUndeterminedCheck::KEY;
        $preview = $this->service->preview($this->supplierId, $key);
        self::assertSame([$building, $machine], array_column($preview['changes'], 'entity_id'));
        $this->pdo->prepare('UPDATE assets SET description = ? WHERE supplier_id = ? AND id = ?')
            ->execute([$note . "\nPoznámka účetní.", $this->supplierId, $building]);
        try {
            $this->service->fix($this->supplierId, $key, $this->userId, [], $preview['fingerprint']);
            self::fail('Oprava musí odmítnout zastaralý náhled.');
        } catch (MigrationDataCheckException $e) {
            self::assertSame(['stale_preview', 409], [$e->errorCode, $e->status]);
        }
        self::assertSame(0, $this->rows('activity_log', "action = '" . PohodaAssetAccountRepair::AUDIT_ACTION . "'"));
        $preview = $this->service->preview($this->supplierId, $key);
        $result = $this->service->fix($this->supplierId, $key, $this->userId, [], $preview['fingerprint']);
        self::assertSame(2, $result['fixed_count']);
        self::assertSame('review', $result['after']['status']);
        $cards = $this->pdo->prepare('SELECT id, asset_account_code, description, status FROM assets WHERE supplier_id = ? ORDER BY id');
        $cards->execute([$this->supplierId]);
        $stored = $cards->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
        self::assertSame(['asset_account_code' => '021.000',
            'description' => self::ASSET_REVIEW_PREFIX . "ověřte odpisy; ověřte zůstatek.\nPoznámka účetní.",
            'status' => 'draft'], $stored[$building]);
        self::assertSame(['asset_account_code' => '022.000', 'description' => null, 'status' => 'in_use'], $stored[$machine]);
        foreach ([$capacity, $missing, $depreciated, $booked, $disposed] as $untouched) {
            self::assertSame('022', $stored[$untouched]['asset_account_code']);
            self::assertSame(self::ASSET_REVIEW_PREFIX . self::ASSET_REVIEW_NOTE . '.', $stored[$untouched]['description']);
        }
        self::assertSame(3, $this->rows('journal_entries', '1=1'));
        $this->assertNothingToFix($key);
        self::assertSame([$capacity => null, $missing => null, $booked => null], array_column($repair->run([$this->supplierId], true)[0]['cards'], 'new_account', 'id'));
        self::assertSame(2, $this->rows('activity_log', "action = '" . PohodaAssetAccountRepair::AUDIT_ACTION . "'"));
    }

    /**
     * Starší období bez počátečních stavů z POHODY (např. prázdné období založené zápisem
     * s překlepem v roce) opravu neblokuje: platí zápis počátečních stavů převodu.
     */
    public function testPohodaAssetAccountRepairUsesMappedOpeningAfterEarlierPeriod(): void
    {
        $this->account('021.000', 'asset');
        $this->account('701.000', 'equity');
        $this->post('2025-01-01', '021.000', '701.000', 1200.0, 'opening');
        $laterPeriod = $this->c->get(AccountingPeriodRepository::class)->create($this->supplierId, 2026, '2026-01-01', '2026-12-31');
        $laterOpening = $this->post('2025-01-01', '021.000', '701.000', 1200.0, 'opening');
        $this->pdo->prepare("UPDATE journal_entries SET source_id = ?, period_id = ?, entry_date = '2026-01-01' WHERE supplier_id = ? AND id = ?")
            ->execute([$laterPeriod, $laterPeriod, $this->supplierId, $laterOpening]);
        $this->map('journal_entry', '2026|PS', $laterOpening);
        $asset = $this->assetCard(300.0, '081.000', self::ASSET_REVIEW_PREFIX . self::ASSET_REVIEW_NOTE . '.');

        $plan = $this->c->get(PohodaAssetAccountRepair::class)->run([$this->supplierId], true)[0];
        self::assertSame([$asset => '021.000'], array_column($plan['cards'], 'new_account', 'id'));
        self::assertSame(1, $this->rows('assets', "id = {$asset} AND asset_account_code = '021.000'"));
    }

    public function testPohodaAssetAccountRepairRefinesBothAccountsWhenJournalAgrees(): void
    {
        foreach (['021.011', '022', '022.007', '042', '081.001', '081.002', '082', '082.007', '082.008'] as $account) {
            $this->account($account, 'asset');
        }
        $this->account('551', 'expense');
        $this->account('701.000', 'equity');
        $opening = $this->post('2025-01-01', '021.011', '701.000', 500.0, 'opening', $this->periodId);
        $lines = $this->pdo->prepare('INSERT INTO journal_entry_lines (supplier_id, entry_id, account_id, side, amount, line_no)
            VALUES (?, ?, ?, ?, ?, ?)');
        foreach (['081.001' => 40.0, '081.002' => 30.0, '082.007' => 20.0] as $code => $amount) {
            $lines->execute([$this->supplierId, $opening, $this->accounts[$code], 'credit', $amount, ++$this->seq]);
            $lines->execute([$this->supplierId, $opening, $this->accounts['701.000'], 'debit', $amount, ++$this->seq]);
        }
        $this->map('journal_entry', '2025|PS', $opening);

        $unique = $this->assetCard(100.0, '082', account: '022.007', imported: true);
        $openingAsset = $this->assetCard(200.0, '081.001', account: '021', imported: true);
        $ambiguous = $this->assetCard(100.0, '081', account: '021.011', imported: true);
        $byNumber = $this->assetCard(100.0, '081', account: '021.011', imported: true);
        $number = 'MAJ-' . $this->seq;
        $imported = $this->post('2025-03-01', '551', '081.002', 25.0, documentNo: $number);
        $this->map('journal_entry', '2025|Dlouhodobý majetek|' . $number . '|2025-03-01', $imported);
        $this->pdo->prepare("UPDATE assets SET inventory_number = 'MAJ-PREJMENOVANA' WHERE supplier_id = ? AND id = ?")
            ->execute([$this->supplierId, $byNumber]);

        $booked = $this->assetCard(500.0, '082', status: 'in_use', date: '2025-02-10', imported: true);
        $this->post('2025-02-20', '022.007', '042', 500.0, 'asset', $booked);
        $wrongAsset = $this->assetCard(600.0, '082', status: 'in_use', date: '2025-02-10', imported: true);
        $this->post('2025-02-20', '022', '042', 600.0, 'asset', $wrongAsset);
        $wrongDepreciation = $this->assetCard(700.0, '082', status: 'in_use', date: '2025-02-10', imported: true);
        $this->post('2025-02-20', '022.007', '042', 700.0, 'asset', $wrongDepreciation);
        $this->assetCard(400.0, '082', account: '022.007');
        $depreciation = $this->pdo->prepare("INSERT INTO depreciation_entries
            (supplier_id, asset_id, kind, fiscal_year, amount, full_amount, residual_value_end, status)
            VALUES (?, ?, 'accounting', 2025, 10, 10, 490, 'posted')");
        foreach ([$booked => '082.007', $wrongAsset => '082.007', $wrongDepreciation => '082'] as $id => $code) {
            $depreciation->execute([$this->supplierId, $id]);
            $this->post('2025-03-31', '551', $code, 10.0, 'depreciation', (int) $this->pdo->lastInsertId());
        }

        $ledger = "SELECT entry_id, account_id, side, signed_amount FROM journal_entry_lines
                    WHERE supplier_id = {$this->supplierId} ORDER BY id";
        $ledgerBefore = $this->pdo->query($ledger)->fetchAll(PDO::FETCH_ASSOC);
        $key = AssetAccountUndeterminedCheck::KEY;
        $preview = $this->service->preview($this->supplierId, $key);
        self::assertSame([$unique, $openingAsset, $byNumber, $booked], array_column($preview['changes'], 'entity_id'));
        self::assertSame([$ambiguous, $wrongAsset, $wrongDepreciation], array_column($preview['skipped'], 'entity_id'));
        $result = $this->service->fix($this->supplierId, $key, $this->userId, [], $preview['fingerprint']);
        self::assertSame(4, $result['fixed_count']);
        self::assertSame(1, $this->rows('assets', "id = {$unique} AND accumulated_account_code = '082.007'"));
        self::assertSame(1, $this->rows('assets', "id = {$openingAsset} AND asset_account_code = '021.011'"));
        self::assertSame(1, $this->rows('assets', "id = {$ambiguous} AND accumulated_account_code = '081'"));
        self::assertSame(1, $this->rows('assets', "id = {$byNumber} AND accumulated_account_code = '081.002'"));
        self::assertSame(1, $this->rows('assets', "id = {$booked} AND asset_account_code = '022.007' AND accumulated_account_code = '082.007' AND status = 'in_use'"));
        self::assertSame(2, $this->rows('assets', "id IN ({$wrongAsset}, {$wrongDepreciation}) AND asset_account_code = '022' AND accumulated_account_code = '082'"));
        self::assertSame($ledgerBefore, $this->pdo->query($ledger)->fetchAll(PDO::FETCH_ASSOC));
        $this->assertNothingToFix($key);
    }

    public function testMissingAssetActivationReportsOpenAcquisitionInFirstAccountingPeriod(): void
    {
        foreach (['022.007', '041', '042.007'] as $account) {
            $this->account($account, 'asset');
        }
        $this->account('321', 'liability');
        $this->c->get(AccountingPeriodRepository::class)->create($this->supplierId, 2024, '2024-01-01', '2024-12-31');
        $this->pdo->prepare("UPDATE supplier SET accounting_starts_on = '2025-02-01' WHERE id = ?")->execute([$this->supplierId]);
        $open = $this->assetCard(500.0, '082', status: 'in_use', date: '2025-02-01');
        $draft = $this->assetCard(500.0, '082', date: '2025-02-01');
        $activated = $this->assetCard(500.0, '082', status: 'in_use', date: '2025-02-01');
        $this->post('2025-02-03', '022.007', '042.007', 500.0, 'asset', $activated);
        $improved = $this->assetCard(200.0, '082', status: 'in_use', date: '2025-02-01');
        $this->pdo->prepare("INSERT INTO asset_improvements (supplier_id, asset_id, completed_on, amount) VALUES (?, ?, '2025-02-03', 50)")
            ->execute([$this->supplierId, $improved]);
        $this->post('2025-02-03', '022.007', '042.007', 250.0, 'asset', $improved);
        $prior = $this->assetCard(500.0, '082', status: 'in_use', date: '2025-01-31');
        $later = $this->assetCard(500.0, '082', date: '2026-01-01');
        $imported = $this->assetCard(600.0, '082', status: 'in_use', date: '2025-02-01');
        $this->post('2025-02-03', '022.007', '042.007', 600.0, documentNo: 'MAJ-' . $this->seq);
        $noBalance = $this->assetCard(100.0, '073', account: '013', date: '2025-02-01');
        $this->pdo->prepare("UPDATE assets SET acquisition_account_code = '041' WHERE supplier_id = ? AND id = ?")
            ->execute([$this->supplierId, $noBalance]);
        $this->post('2025-02-01', '042.007', '321', 2350.0);
        $this->post('2025-02-01', '041', '321', 100.0);
        $this->post('2025-02-03', '321', '041', 100.0);

        $result = $this->service->detail($this->supplierId, AssetActivationMissingCheck::KEY);
        self::assertFalse($result['fixable']);
        $ids = array_column($result['items'], 'entity_id');
        self::assertContains($open, $ids);
        self::assertContains($draft, $ids);
        self::assertNotContains($activated, $ids);
        self::assertNotContains($improved, $ids);
        self::assertNotContains($prior, $ids);
        self::assertNotContains($later, $ids);
        self::assertNotContains($imported, $ids);
        self::assertNotContains($noBalance, $ids);
        self::assertSame([$open, $draft], $ids);
        self::assertSame(CheckItem::REVIEW, $result['items'][0]['state']);
        self::assertSame(['acquisition_account_code' => '042', 'acquisition_balance' => 1000.0], $result['items'][0]['details']);
    }

    /**
     * Doplnění evidence úhrad (kontroly 1 a 6) nemění deník ani DPH, uzavřené účetní období
     * ho neblokuje. Zámek účtování k datu a podané období DPH ano. Vazba DDKP na zálohu
     * (kontrola 7) v uzavřeném období dál jen hlásí.
     */
    public function testClosedPeriodBlocksOnlyLedgerRelevantFixes(): void
    {
        $prior = $this->c->get(AccountingPeriodRepository::class)->create($this->supplierId, 2024, '2024-01-01', '2024-12-31');
        $this->pdo->prepare("UPDATE accounting_periods SET status = 'closed' WHERE id = ?")->execute([$prior]);
        $invoice = $this->invoice('invoice', 'FV24930', '2024-10-10', 1210.0, 210.0, paidTotal: 1210.0, status: 'paid');
        $closedPurchase = $this->purchase('DF-930', '2024-11-05', 400.0, $this->czk);
        $filedPurchase = $this->purchase('DF-931', '2025-03-10', 500.0, $this->czk);
        $this->filedVat(2025, 3);
        $this->invoice('proforma', 'ZF24930', '2024-06-01', 1210.0, 0.0);
        $ddkp = $this->invoice('tax_document', 'DD24930', '2024-06-05', 1210.0, 210.0, note: 'Daňový doklad k přijaté platbě (zálohová faktura č. ZF24930)');

        self::assertSame([$invoice => 'fixable'], array_column($this->service->detail($this->supplierId, SettledWithoutPaymentCheck::KEY)['items'], 'state', 'entity_id'));
        self::assertSame([$closedPurchase => 'fixable', $filedPurchase => 'locked'],
            array_column($this->service->detail($this->supplierId, PurchaseSettledUncoveredCheck::KEY)['items'], 'state', 'entity_id'));
        self::assertSame([$ddkp => 'locked'], array_column($this->service->detail($this->supplierId, TaxDocumentAdvanceLinkCheck::KEY)['items'], 'state', 'entity_id'));

        foreach ([SettledWithoutPaymentCheck::KEY, PurchaseSettledUncoveredCheck::KEY] as $key) {
            $result = $this->service->fix($this->supplierId, $key, $this->userId, [], $this->service->preview($this->supplierId, $key)['fingerprint']);
            self::assertSame(1, $result['fixed_count'], $key);
        }
        self::assertSame(1, $this->rows('invoice_payments', "invoice_id = {$invoice} AND paid_on = '2024-12-31'"));
        self::assertSame(['400.00', '0.00'], array_map(fn (int $id): string => (string) $this->pdo->query("SELECT settled_outside_amount FROM purchase_invoices WHERE id = {$id}")->fetchColumn(), [$closedPurchase, $filedPurchase]));
        $this->assertNothingToFix(TaxDocumentAdvanceLinkCheck::KEY);
        self::assertNull($this->pdo->query("SELECT parent_invoice_id FROM invoices WHERE id = {$ddkp}")->fetchColumn());
    }

    public function testPaidPurchaseWithoutCoverageGetsSettledOutside(): void
    {
        $covered = $this->purchase('DF-901', '2025-05-10', 1000.0, $this->czk);
        $foreign = $this->purchase('DF-902', '2025-05-12', 100.0, $this->eur, 25.0);
        $late = $this->purchase('DF-903', '2025-09-15', 300.0, $this->czk);
        $this->lockUntil('2025-06-30');

        $detail = $this->service->detail($this->supplierId, PurchaseSettledUncoveredCheck::KEY);
        self::assertSame([$covered => 'locked', $foreign => 'review', $late => 'fixable'], array_column($detail['items'], 'state', 'entity_id'));
        self::assertSame(2500.0, array_column($detail['items'], 'amount_czk', 'entity_id')[$foreign]);

        $preview = $this->service->preview($this->supplierId, PurchaseSettledUncoveredCheck::KEY);
        $result = $this->service->fix($this->supplierId, PurchaseSettledUncoveredCheck::KEY, $this->userId, [], $preview['fingerprint']);
        self::assertSame(1, $result['fixed_count']);
        self::assertSame(['0.00', '0.00', '300.00'], array_map(fn (int $id): string => (string) $this->pdo->query("SELECT settled_outside_amount FROM purchase_invoices WHERE id = {$id}")->fetchColumn(), [$covered, $foreign, $late]));
        self::assertSame(1, $this->rows('activity_log', "action = '" . PurchaseSettledUncoveredCheck::AUDIT_ACTION_POHODA . "' AND entity_id = {$late}"));
        $this->assertNothingToFix(PurchaseSettledUncoveredCheck::KEY);
    }

    public function testStalePreviewIsRefused(): void
    {
        $this->purchase('DF-911', '2025-05-10', 1000.0, $this->czk);
        $preview = $this->service->preview($this->supplierId, PurchaseSettledUncoveredCheck::KEY);
        $this->purchase('DF-912', '2025-05-11', 200.0, $this->czk);
        try {
            $this->service->fix($this->supplierId, PurchaseSettledUncoveredCheck::KEY, $this->userId, [], $preview['fingerprint']);
            self::fail('Změněná data od náhledu musí opravu zastavit.');
        } catch (MigrationDataCheckException $e) {
            self::assertSame(['stale_preview', 409], [$e->errorCode, $e->status]);
        }
        self::assertSame(0, $this->rows('purchase_invoices', 'settled_outside_amount > 0'));
    }

    public function testRequestValidation(): void
    {
        foreach ([
            fn () => $this->service->detail($this->supplierId, 'unknown_check'),
            fn () => $this->service->preview($this->supplierId, PayrollLiabilitiesCheck::KEY),
            fn () => $this->service->preview($this->supplierId, ForeignBankInCzkCheck::KEY, ['delete_everything' => true]),
            fn () => $this->service->preview($this->supplierId, ForeignBankInCzkCheck::KEY, [ForeignBankInCzkCheck::OPTION_UPLOADED => 'yes']),
            fn () => $this->service->detail($this->supplierId, SettledWithoutPaymentCheck::KEY, 1, 50, 'deleted'),
        ] as $i => $call) {
            try {
                $call();
                self::fail("Požadavek {$i} musí skončit odmítnutím.");
            } catch (MigrationDataCheckException $e) {
                self::assertContains($e->status, [404, 409, 422], (string) $i);
            }
        }
    }

    public function testTaxDocumentLinksToAdvanceOutsideFiledPeriod(): void
    {
        $advance = $this->invoice('proforma', 'ZF25901', '2025-03-01', 1210.0, 0.0);
        $this->pdo->prepare("INSERT INTO invoice_payments (supplier_id, invoice_id, paid_on, amount, currency, source, note) VALUES (?, ?, '2025-03-04', 1210.50, 'CZK', 'manual', 'Platba zalohy')")
            ->execute([$this->supplierId, $advance]);
        $payment = (int) $this->pdo->lastInsertId();
        $this->invoice('proforma', 'ZF25902', '2025-03-01', 5000.0, 0.0);
        $this->invoice('proforma', 'ZF25903', '2025-04-01', 2420.0, 0.0);
        $linkable = $this->invoice('tax_document', 'DD25901', '2025-03-05', 1210.5, 210.0, note: 'Daňový doklad k přijaté platbě (zálohová faktura č. ZF25901)');
        $amount = $this->invoice('tax_document', 'DD25902', '2025-03-06', 1210.0, 210.0, note: 'Daňový doklad k přijaté platbě (zálohová faktura č. ZF25902)');
        $filed = $this->invoice('tax_document', 'DD25903', '2025-04-10', 2420.0, 420.0, note: 'Daňový doklad k uhrazené zálohové faktuře č. ZF25903');
        $noRef = $this->invoice('tax_document', 'DD25904', '2025-04-11', 100.0, 17.36, note: 'Daňový doklad k přijaté platbě');
        $over = $this->invoice('tax_document', 'DD25905', '2025-03-07', 1210.0, 210.0, note: 'Daňový doklad k přijaté platbě (zálohová faktura č. ZF25901)');
        $this->filedVat(2025, 4);

        $detail = $this->service->detail($this->supplierId, TaxDocumentAdvanceLinkCheck::KEY);
        self::assertSame([$linkable => 'fixable', $amount => 'review', $over => 'review', $filed => 'locked', $noRef => 'review'], array_column($detail['items'], 'state', 'entity_id'));
        self::assertStringContainsString('překročil', array_column($detail['items'], 'reason', 'entity_id')[$over]);

        $preview = $this->service->preview($this->supplierId, TaxDocumentAdvanceLinkCheck::KEY);
        $result = $this->service->fix($this->supplierId, TaxDocumentAdvanceLinkCheck::KEY, $this->userId, [], $preview['fingerprint']);
        self::assertSame(1, $result['fixed_count']);
        self::assertSame($this->invoiceId('ZF25901'), (int) $this->pdo->query("SELECT parent_invoice_id FROM invoices WHERE id = {$linkable}")->fetchColumn());
        // Ruční navázání naváže i jedinou platbu zálohy se stejnou částkou.
        self::assertSame($linkable, (int) $this->pdo->query("SELECT tax_document_invoice_id FROM invoice_payments WHERE id = {$payment}")->fetchColumn());
        foreach ([$amount, $filed, $noRef, $over] as $id) {
            self::assertNull($this->pdo->query("SELECT parent_invoice_id FROM invoices WHERE id = {$id}")->fetchColumn(), (string) $id);
        }
        self::assertSame(1, $this->rows('activity_log', "action = 'invoice.tax_document_advance_linked' AND entity_id = {$linkable}"));
        $this->assertNothingToFix(TaxDocumentAdvanceLinkCheck::KEY);
    }

    public function testFinalInvoiceDeductingAdvanceOnlyInHeaderIsReported(): void
    {
        $advance = $this->invoice('proforma', 'ZF25910', '2025-05-01', 1210.0, 0.0);
        $this->invoice('tax_document', 'DD25910', '2025-05-03', 1210.0, 210.0, parent: $advance);
        $double = $this->invoice('invoice', 'FV25910', '2025-06-01', 2420.0, 420.0, parent: $advance, advancePaid: 1210.0, items: [[2000.0, 420.0]]);
        $advance2 = $this->invoice('proforma', 'ZF25911', '2025-05-01', 1210.0, 0.0);
        $this->invoice('tax_document', 'DD25911', '2025-05-03', 1210.0, 210.0, parent: $advance2);
        $this->invoice('invoice', 'FV25911', '2025-06-02', 2420.0, 210.0, parent: $advance2, advancePaid: 1210.0, items: [[2000.0, 420.0], [-1000.0, -210.0]]);

        $detail = $this->service->detail($this->supplierId, FinalInvoiceAdvanceVatCheck::KEY);
        self::assertSame([$double], array_column($detail['items'], 'entity_id'));
        self::assertSame(210.0, $detail['items'][0]['amount_czk']);
        self::assertSame('review', $detail['items'][0]['state']);
        self::assertFalse($detail['fixable']);
    }

    public function testImportedStatementsAreRechainedFromJournal(): void
    {
        $this->account('221.001', 'asset');
        $this->account('701', 'equity');
        $this->account('518', 'expense');
        $this->post('2025-01-01', '221.001', '701', 1000.0, 'opening');
        $jan = $this->statement('RB', '2025-01-31', 0.0, -100.0, [['2025-01-15', -100.0]]);
        $feb = $this->statement('RB', '2025-02-28', -100.0, -150.0, [['2025-02-15', -50.0]]);
        $this->pdo->prepare('INSERT INTO bank_statement_rebases (supplier_id, anchor_statement_id, shift, statement_ids) VALUES (?, ?, ?, ?)')
            ->execute([$this->supplierId, $feb, '-1100.00', json_encode([$jan, $feb])]);

        $detail = $this->service->detail($this->supplierId, ImportedStatementBalanceCheck::KEY);
        self::assertSame('fixable', $detail['status']);
        self::assertSame(-1000.0, $detail['items'][0]['amount_czk']);

        $preview = $this->service->preview($this->supplierId, ImportedStatementBalanceCheck::KEY);
        $this->service->fix($this->supplierId, ImportedStatementBalanceCheck::KEY, $this->userId, [], $preview['fingerprint']);
        self::assertSame(['1000.00', '900.00'], $this->balances($jan));
        self::assertSame(['900.00', '850.00'], $this->balances($feb));
        self::assertSame(0, $this->rows('bank_statement_rebases', '1 = 1'), 'Přepočtené výpisy už smazání výpisu banky neposune.');
        self::assertSame(1, $this->rows('activity_log', "action = '" . ImportedStatementBalanceCheck::AUDIT_ACTION . "'"));
        $this->assertNothingToFix(ImportedStatementBalanceCheck::KEY);
    }

    public function testImportedStatementsOfTwoSourceAccountsOrLockedPeriodAreOnlyReported(): void
    {
        $this->account('221.001', 'asset');
        $this->account('701', 'equity');
        $this->account('518', 'expense');
        $this->post('2025-01-01', '221.001', '701', 1000.0, 'opening');
        $jan = $this->statement('RB', '2025-01-31', 0.0, -100.0, [['2025-01-15', -100.0]]);
        $this->statement('RBEU', '2025-02-28', -100.0, -150.0, [['2025-02-15', -50.0]]);
        self::assertSame('review', $this->service->detail($this->supplierId, ImportedStatementBalanceCheck::KEY)['items'][0]['state']);

        $this->pdo->prepare("UPDATE pohoda_import_map SET pohoda_key = REPLACE(pohoda_key, 'RBEU', 'RB') WHERE supplier_id = ?")->execute([$this->supplierId]);
        $this->lockUntil('2025-01-31');
        self::assertSame('locked', $this->service->detail($this->supplierId, ImportedStatementBalanceCheck::KEY)['items'][0]['state']);
        $this->assertNothingToFix(ImportedStatementBalanceCheck::KEY);
        self::assertSame(['0.00', '-100.00'], $this->balances($jan));
    }

    public function testPayrollLiabilitiesMissingInOtherItemsAreReported(): void
    {
        $this->account('331', 'liability');
        $this->account('336', 'liability');
        $this->account('521', 'expense');
        $this->post('2025-01-31', '521', '331', 5000.0);
        $this->post('2025-01-31', '521', '336', 1700.0);
        $this->otherItem('331', 3000.0, '2025-01-31');
        $this->otherItem('336', 1700.0, '2025-01-31');

        $detail = $this->service->detail($this->supplierId, PayrollLiabilitiesCheck::KEY);
        self::assertSame(['331'], array_map(static fn (array $i): string => (string) $i['details']['account'], $detail['items']));
        self::assertSame(2000.0, $detail['items'][0]['amount_czk']);
        self::assertSame('review', $detail['status']);
        self::assertFalse($detail['fixable']);
    }

    public function testSubledgerDifferenceAgainstGeneralLedgerIsReported(): void
    {
        $this->account('311', 'asset');
        $this->account('602', 'revenue');
        $this->post('2025-03-01', '311', '602', 500.0);

        $items = $this->service->detail($this->supplierId, SubledgerLedgerCheck::KEY)['items'];
        self::assertCount(1, $items);
        self::assertSame(['311', 2025, $this->periodId], [$items[0]['details']['account'], $items[0]['details']['fiscal_year'], $items[0]['details']['period_id']]);
        self::assertSame(500.0, $items[0]['amount_czk']);
    }

    /** Počáteční stav poskytnutých záloh a ostatních pohledávek bez dokladu je rozdíl saldokonta. */
    public function testSubledgerDifferenceOnAdvancesAndOtherReceivablesIsReported(): void
    {
        $this->account('314', 'asset');
        $this->account('315', 'asset');
        $this->account('602', 'revenue');
        $this->post('2025-01-01', '314', '602', 400.0);
        $this->post('2025-01-01', '315', '602', 300.0);

        $items = $this->service->detail($this->supplierId, SubledgerLedgerCheck::KEY)['items'];
        $byAccount = array_column(array_map(static fn (array $i): array => [$i['details']['account'], $i['amount_czk']], $items), 1, 0);
        self::assertSame(['314' => 400.0, '315' => 300.0], $byAccount);
        self::assertStringContainsString('Zůstatek hlavní knihy 300,00', $items[1]['reason'] ?? '');
    }

    /** Čtení kontroly kurz ČNB nestahuje: chybějící kurz je nález, kurzy se neukládají. */
    public function testForeignAccountWithMissingRateIsReportedWithoutFetching(): void
    {
        $this->foreignAccount('2025-06-15');
        $count = (int) $this->pdo->query("SELECT COUNT(*) FROM exchange_rates WHERE currency_code = 'EUR'")->fetchColumn();
        $detail = $this->service->detail($this->supplierId, ForeignBankInCzkCheck::KEY);
        self::assertSame('review', $detail['items'][0]['state'], json_encode($detail['items'], JSON_UNESCAPED_UNICODE));
        self::assertStringContainsString('kurz', $detail['items'][0]['reason']);
        self::assertFalse($this->service->preview($this->supplierId, ForeignBankInCzkCheck::KEY)['can_fix']);
        self::assertSame($count, (int) $this->pdo->query("SELECT COUNT(*) FROM exchange_rates WHERE currency_code = 'EUR'")->fetchColumn());
    }

    /** Převod účtu doplní měnu i do otevíracího zápisu: zámek k datu před prvním pohybem ho chrání. */
    public function testForeignAccountWithLockedOpeningEntryIsOnlyReported(): void
    {
        $this->foreignAccount(null, 2500.0);
        self::assertSame('fixable', $this->service->detail($this->supplierId, ForeignBankInCzkCheck::KEY)['status']);
        $this->lockUntil('2025-01-10');
        $detail = $this->service->detail($this->supplierId, ForeignBankInCzkCheck::KEY);
        self::assertSame('locked', $detail['items'][0]['state'], json_encode($detail['items'], JSON_UNESCAPED_UNICODE));
        $this->assertNothingToFix(ForeignBankInCzkCheck::KEY);
    }

    /** Posun stavů doložený výpisem banky se automaticky nevrací. */
    public function testImportedStatementsShiftedByBankStatementAreOnlyReported(): void
    {
        $this->account('221.001', 'asset');
        $this->account('701', 'equity');
        $this->account('518', 'expense');
        $this->post('2025-01-01', '221.001', '701', 1000.0, 'opening');
        $jan = $this->statement('RB', '2025-01-31', 1100.0, 1000.0, [['2025-01-15', -100.0]]);
        $this->pdo->prepare("INSERT INTO bank_statements (supplier_id, source, file_name, file_hash, file_content, account_number, bank_code, currency, statement_number,
                statement_date, prev_balance, curr_balance, credit_total, debit_total, transaction_count)
            VALUES (?, 'gpc', 'banka.gpc', ?, 'x', '1000000005', '0100', 'CZK', '2', '2025-02-28', 1000.00, 1000.00, 0, 0, 0)")
            ->execute([$this->supplierId, hash('sha256', 'mchk-gpc-' . $this->supplierId)]);
        $anchor = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO bank_statement_rebases (supplier_id, anchor_statement_id, shift, statement_ids) VALUES (?, ?, ?, ?)')
            ->execute([$this->supplierId, $anchor, '1100.00', json_encode([$jan])]);

        $item = $this->service->detail($this->supplierId, ImportedStatementBalanceCheck::KEY)['items'][0];
        self::assertSame('review', $item['state']);
        self::assertStringContainsString('dorovnal podle banky', $item['reason']);
        $this->assertNothingToFix(ImportedStatementBalanceCheck::KEY);
        self::assertSame(['1100.00', '1000.00'], $this->balances($jan));
        self::assertSame(1, $this->rows('bank_statement_rebases', '1 = 1'));
    }

    public function testImportedStatementsInClosedPeriodAreLocked(): void
    {
        $this->account('221.001', 'asset');
        $this->account('701', 'equity');
        $this->account('518', 'expense');
        $this->post('2025-01-01', '221.001', '701', 1000.0, 'opening');
        $this->statement('RB', '2025-01-31', 0.0, -100.0, [['2025-01-15', -100.0]]);
        $this->pdo->prepare("UPDATE accounting_periods SET status = 'closed' WHERE id = ?")->execute([$this->periodId]);
        self::assertSame('locked', $this->service->detail($this->supplierId, ImportedStatementBalanceCheck::KEY)['items'][0]['state']);
    }

    /** Otisk náhledu nese vstupy plánu: nový pohyb v řetězu opravu zastaví, i když první výpis a rozdíl zůstávají. */
    public function testImportedStatementFixRefusesChangedMovements(): void
    {
        $this->account('221.001', 'asset');
        $this->account('701', 'equity');
        $this->account('518', 'expense');
        $this->post('2025-01-01', '221.001', '701', 1000.0, 'opening');
        $jan = $this->statement('RB', '2025-01-31', 0.0, -100.0, [['2025-01-15', -100.0]]);
        $feb = $this->statement('RB', '2025-02-28', -100.0, -150.0, [['2025-02-15', -50.0]]);
        $preview = $this->service->preview($this->supplierId, ImportedStatementBalanceCheck::KEY);
        $this->pdo->prepare("INSERT INTO bank_transactions (source, statement_id, posted_at, amount, currency, import_fingerprint) VALUES ('statement', ?, '2025-02-20', -30.00, 'CZK', ?)")
            ->execute([$feb, hash('sha256', 'mchk-extra-' . $feb)]);
        try {
            $this->service->fix($this->supplierId, ImportedStatementBalanceCheck::KEY, $this->userId, [], $preview['fingerprint']);
            self::fail('Změněné pohyby od náhledu musí opravu zastavit.');
        } catch (MigrationDataCheckException $e) {
            self::assertSame('stale_preview', $e->errorCode);
        }
        self::assertSame(['0.00', '-100.00'], $this->balances($jan));
    }

    /** Záznam posunu, který přibude k řetězu po náhledu, otisk změní: oprava ho neodebere naslepo. */
    public function testImportedStatementFixRefusesNewRebaseRecord(): void
    {
        $this->account('221.001', 'asset');
        $this->account('701', 'equity');
        $this->account('518', 'expense');
        $this->post('2025-01-01', '221.001', '701', 1000.0, 'opening');
        $jan = $this->statement('RB', '2025-01-31', 0.0, -100.0, [['2025-01-15', -100.0]]);
        $preview = $this->service->preview($this->supplierId, ImportedStatementBalanceCheck::KEY);
        $this->pdo->prepare('INSERT INTO bank_statement_rebases (supplier_id, anchor_statement_id, shift, statement_ids) VALUES (?, 999999999, ?, ?)')
            ->execute([$this->supplierId, '0.00', json_encode([$jan])]);
        try {
            $this->service->fix($this->supplierId, ImportedStatementBalanceCheck::KEY, $this->userId, [], $preview['fingerprint']);
            self::fail('Nový záznam posunu od náhledu musí opravu zastavit.');
        } catch (MigrationDataCheckException $e) {
            self::assertSame('stale_preview', $e->errorCode);
        }
        self::assertSame(1, $this->rows('bank_statement_rebases', '1 = 1'));
    }

    /** Platba zálohy, která přibude po náhledu, by se navázala na DDKP: oprava ji nenaváže naslepo. */
    public function testTaxDocumentFixRefusesPaymentAddedAfterPreview(): void
    {
        $advance = $this->invoice('proforma', 'ZF25930', '2025-03-01', 1210.0, 0.0);
        $ddkp = $this->invoice('tax_document', 'DD25930', '2025-03-05', 1210.0, 210.0, note: 'Daňový doklad k přijaté platbě (zálohová faktura č. ZF25930)');
        $preview = $this->service->preview($this->supplierId, TaxDocumentAdvanceLinkCheck::KEY);
        self::assertNull($preview['changes'][0]['details']['payment_id']);
        $this->pdo->prepare("INSERT INTO invoice_payments (supplier_id, invoice_id, paid_on, amount, currency, source, note) VALUES (?, ?, '2025-03-04', 1210.00, 'CZK', 'manual', 'Platba zalohy')")
            ->execute([$this->supplierId, $advance]);
        try {
            $this->service->fix($this->supplierId, TaxDocumentAdvanceLinkCheck::KEY, $this->userId, [], $preview['fingerprint']);
            self::fail('Nová platba zálohy od náhledu musí opravu zastavit.');
        } catch (MigrationDataCheckException $e) {
            self::assertSame('stale_preview', $e->errorCode);
        }
        self::assertNull($this->pdo->query("SELECT parent_invoice_id FROM invoices WHERE id = {$ddkp}")->fetchColumn());
    }

    /** Automatické navázání DDKP jen na zálohu z převodu, která nevznikla po DDKP. */
    public function testTaxDocumentLinkNeedsMigratedAdvanceIssuedBeforeIt(): void
    {
        $this->invoice('proforma', 'ZF25920', '2025-03-01', 1210.0, 0.0, mapped: false);
        $this->invoice('proforma', 'ZF25921', '2025-05-01', 1210.0, 0.0);
        $native = $this->invoice('tax_document', 'DD25920', '2025-03-05', 1210.0, 210.0, note: 'Daňový doklad k přijaté platbě (zálohová faktura č. ZF25920)');
        $later = $this->invoice('tax_document', 'DD25921', '2025-03-06', 1210.0, 210.0, note: 'Daňový doklad k přijaté platbě (zálohová faktura č. ZF25921)');

        $items = array_column($this->service->detail($this->supplierId, TaxDocumentAdvanceLinkCheck::KEY)['items'], null, 'entity_id');
        self::assertSame('review', $items[$native]['state']);
        self::assertStringContainsString('nepochází z převodu', $items[$native]['reason']);
        self::assertSame('review', $items[$later]['state']);
        self::assertStringContainsString('po DDKP', $items[$later]['reason']);
    }

    /** Podané období DPH se zjišťuje jednou za měsíc, ne za každý nález (žádné N+1 nad podáními). */
    public function testFiledVatPeriodIsLookedUpOncePerMonth(): void
    {
        for ($i = 1; $i <= 40; $i++) {
            $this->invoice('proforma', sprintf('ZF259%02d', 50 + $i), '2025-04-01', 100.0, 0.0);
            $this->invoice('tax_document', sprintf('DD259%02d', 50 + $i), sprintf('2025-04-%02d', 2 + intdiv($i, 3)), 100.0, 17.36,
                note: sprintf('Daňový doklad k přijaté platbě (zálohová faktura č. ZF259%02d)', 50 + $i));
        }
        $this->filedVat(2025, 4);
        $questions = fn (): int => (int) $this->pdo->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch(PDO::FETCH_NUM)[1];
        $before = $questions();
        $detail = $this->service->detail($this->supplierId, TaxDocumentAdvanceLinkCheck::KEY);
        $used = $questions() - $before;
        self::assertSame(40, $detail['locked_count']);
        self::assertLessThan(40, $used, "Kontrola 40 DDKP v jednom podaném měsíci položila {$used} dotazů.");
    }

    public function testForeignCurrencyAccountMigratedInCzkIsConverted(): void
    {
        $ids = $this->foreignAccount();

        $detail = $this->service->detail($this->supplierId, ForeignBankInCzkCheck::KEY);
        self::assertSame('fixable', $detail['status'], json_encode($detail['items'], JSON_UNESCAPED_UNICODE));
        self::assertSame('EUR', $detail['items'][0]['currency']);

        $preview = $this->service->preview($this->supplierId, ForeignBankInCzkCheck::KEY);
        $result = $this->service->fix($this->supplierId, ForeignBankInCzkCheck::KEY, $this->userId, [], $preview['fingerprint']);
        self::assertSame(1, $result['fixed_count'], json_encode($result, JSON_UNESCAPED_UNICODE));
        self::assertSame(['EUR'], array_values(array_unique($this->pdo->query('SELECT currency FROM bank_statements WHERE id IN (' . implode(',', $ids) . ')')->fetchAll(PDO::FETCH_COLUMN))));
        self::assertSame(107.01, (float) $this->pdo->query("SELECT amount FROM bank_transactions WHERE statement_id = {$ids[0]}")->fetchColumn());
        $this->assertNothingToFix(ForeignBankInCzkCheck::KEY);
    }

    /**
     * Účet FIOE v EUR převedený v Kč: 12 výpisů po jednom pohybu přepočteném kurzem ČNB.
     *
     * @return list<int> výpisy
     */
    private function foreignAccount(?string $withoutRate = null, float $opening = 0.0): array
    {
        $this->account('221.001', 'asset');
        $this->account('518', 'expense');
        $this->account('701', 'equity');
        if ($opening !== 0.0) {
            $this->post('2025-01-01', '221.001', '701', $opening, 'opening');
        }
        $moves = [];
        for ($i = 1; $i <= 12; $i++) {
            $date = sprintf('2025-%02d-15', $i);
            $rate = 24.5 + $i / 100;
            if ($date !== $withoutRate) {
                $this->pdo->prepare('INSERT INTO exchange_rates (currency_code, rate_date, rate) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE rate = VALUES(rate)')->execute(['EUR', $date, $rate]);
            } else {
                $this->pdo->prepare('DELETE FROM exchange_rates WHERE currency_code = ? AND rate_date BETWEEN ? AND ?')
                    ->execute(['EUR', (new \DateTimeImmutable($date))->modify('-6 days')->format('Y-m-d'), (new \DateTimeImmutable($date))->modify('+6 days')->format('Y-m-d')]);
            }
            $moves[$i] = [$date, round(round(100 + 7 * $i + $i / 100, 2) * $rate, 2) * ($i % 2 === 0 ? -1 : 1)];
        }
        $this->pdo->prepare('INSERT INTO exchange_rates (currency_code, rate_date, rate) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE rate = VALUES(rate)')->execute(['EUR', '2024-12-31', 25.0]);
        $running = $opening;
        $ids = [];
        foreach ($moves as $i => [$date, $czk]) {
            $ids[] = $this->statement('FIOE', sprintf('2025-%02d-28', $i), $running, round($running + $czk, 2), [[$date, $czk]], '2000000017', '2010');
            $running = round($running + $czk, 2);
        }
        return $ids;
    }

    private function assertNothingToFix(string $key): void
    {
        self::assertSame(0, $this->service->detail($this->supplierId, $key)['fixable_count']);
        try {
            $this->service->fix($this->supplierId, $key, $this->userId, [], $this->service->preview($this->supplierId, $key)['fingerprint']);
            self::fail('Oprava bez nálezů k opravě musí skončit odmítnutím.');
        } catch (MigrationDataCheckException $e) {
            self::assertSame(['nothing_to_fix', 409], [$e->errorCode, $e->status]);
        }
    }

    /** @param list<array{0:float,1:float}> $items [základ, DPH] */
    private function invoice(string $type, string $number, string $date, float $total, float $vat, float $paidTotal = 0.0, string $status = 'sent',
        ?int $parent = null, ?string $note = null, float $advancePaid = 0.0, array $items = [], bool $mapped = true): int
    {
        $this->pdo->prepare("INSERT INTO invoices (supplier_id, client_id, varsymbol, invoice_type, issue_date, tax_date, due_date, currency_id, exchange_rate,
                client_snapshot, supplier_snapshot, total_without_vat, total_vat, total_with_vat, advance_paid_amount, paid_total, paid_at, status,
                parent_invoice_id, note_above_items, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, '{}', '{}', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$this->supplierId, $this->clientId, $number, $type, $date, $date, $date, $this->czk, $total - $vat, $vat, $total, $advancePaid,
                $paidTotal, $paidTotal > 0 ? $date : null, $status, $parent, $note, $this->userId]);
        $id = (int) $this->pdo->lastInsertId();
        foreach ($items as $n => [$base, $itemVat]) {
            $this->pdo->prepare("INSERT INTO invoice_items (invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id, vat_rate_snapshot,
                    total_without_vat, total_vat, total_with_vat, order_index) VALUES (?, 'Polozka', 1, 'ks', ?, ?, 21, ?, ?, ?, ?)")
                ->execute([$id, $base, $this->vatRateId, $base, $itemVat, $base + $itemVat, $n]);
        }
        if ($mapped) {
            $this->map('invoice', $number . '|' . $date, $id);
        }
        return $id;
    }

    private function purchase(string $number, string $paidAt, float $total, int $currencyId, float $rate = 1.0): int
    {
        $this->pdo->prepare("INSERT INTO purchase_invoices (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date, due_date,
                received_at, currency_id, exchange_rate, vendor_snapshot, total_without_vat, total_vat, total_with_vat, status, paid_at, created_by)
            VALUES (?, ?, ?, 'invoice', ?, ?, ?, ?, ?, ?, '{}', ?, 0, ?, 'paid', ?, ?)")
            ->execute([$this->supplierId, $this->clientId, $number, $paidAt, $paidAt, $paidAt, $paidAt, $currencyId, $rate, $total, $total, $paidAt, $this->userId]);
        $id = (int) $this->pdo->lastInsertId();
        $this->map('purchase_invoice', $number, $id);
        return $id;
    }

    /** @param list<array{0:string,1:float}> $moves */
    private function statement(string $code, string $date, float $prev, float $curr, array $moves, string $account = '1000000005', string $bank = '0100'): int
    {
        $n = ++$this->seq;
        $this->pdo->prepare("INSERT INTO bank_statements (supplier_id, source, file_name, file_hash, account_number, bank_code, currency, statement_number,
                statement_date, prev_balance, curr_balance, credit_total, debit_total, transaction_count)
            VALUES (?, 'import', ?, ?, ?, ?, 'CZK', ?, ?, ?, ?, 0, 0, ?)")
            ->execute([$this->supplierId, "pohoda-{$code}-{$n}", hash('sha256', "mchk-{$this->supplierId}-{$n}"), $account, $bank, (string) $n, $date,
                number_format($prev, 2, '.', ''), number_format($curr, 2, '.', ''), count($moves)]);
        $id = (int) $this->pdo->lastInsertId();
        $this->map('bank_statement', "stmt|{$code}|2025|{$n}", $id);
        foreach ($moves as $m => [$posted, $amount]) {
            $this->pdo->prepare("INSERT INTO bank_transactions (source, statement_id, posted_at, amount, currency, import_fingerprint)
                VALUES ('statement', ?, ?, ?, 'CZK', ?)")->execute([$id, $posted, number_format($amount, 2, '.', ''), hash('sha256', "{$id}|{$m}|{$amount}")]);
            $tx = (int) $this->pdo->lastInsertId();
            $this->map('bank_transaction', "tx|{$code}|{$n}|{$m}", $tx);
            if (isset($this->accounts['221.001'])) {
                $entry = $amount < 0 ? $this->post($posted, '518', '221.001', -$amount) : $this->post($posted, '221.001', '518', $amount);
                $this->pdo->prepare("INSERT INTO journal_entry_document_links (supplier_id, entry_id, doc_type, doc_id) VALUES (?, ?, 'bank', ?)")
                    ->execute([$this->supplierId, $entry, $tx]);
            }
        }
        return $id;
    }

    private function assetCard(float $price, string $accumulated, ?string $description = null, string $status = 'draft',
        string $account = '022', string $date = '2024-10-01', bool $imported = false): int
    {
        $number = 'MAJ-' . (++$this->seq);
        $this->pdo->prepare("INSERT INTO assets (supplier_id, inventory_number, name, asset_account_code, accumulated_account_code,
                   description, status, input_price, acquisition_date, put_into_use_date, tax_method)
            VALUES (?, ?, 'Syntetická karta', ?, ?, ?, ?, ?, ?, ?, 'none')")
            ->execute([$this->supplierId, $number, $account, $accumulated, $description, $status, $price, $date, $date]);
        $id = (int) $this->pdo->lastInsertId();
        if ($imported) {
            $this->map('asset', 'asset|' . $number, $id);
        }
        return $id;
    }

    private function account(string $code, string $type): void
    {
        $this->pdo->prepare('INSERT INTO chart_of_accounts (supplier_id, account_code, name, account_type) VALUES (?, ?, ?, ?)')
            ->execute([$this->supplierId, $code, 'Synteticky ' . $code, $type]);
        $this->accounts[$code] = (int) $this->pdo->lastInsertId();
    }

    private function post(string $date, string $debit, string $credit, float $amount, string $source = 'manual',
        ?int $sourceId = null, ?string $documentNo = null): int
    {
        $this->pdo->prepare('INSERT INTO journal_entries (supplier_id, period_id, entry_date, source_type, source_id, document_no) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$this->supplierId, $this->periodId, $date, $source, $sourceId, $documentNo]);
        $entry = (int) $this->pdo->lastInsertId();
        foreach (['debit' => $debit, 'credit' => $credit] as $side => $code) {
            $this->pdo->prepare('INSERT INTO journal_entry_lines (supplier_id, entry_id, account_id, side, amount) VALUES (?, ?, ?, ?, ?)')
                ->execute([$this->supplierId, $entry, $this->accounts[$code], $side, number_format($amount, 2, '.', '')]);
        }
        $this->pdo->prepare('UPDATE journal_entries SET posted_at = NOW() WHERE id = ?')->execute([$entry]);
        return $entry;
    }

    private function otherItem(string $account, float $amount, string $date): void
    {
        $this->pdo->prepare("INSERT INTO other_items (supplier_id, side, status, title, amount, currency, issued_on, due_on, account_code, document_no)
            VALUES (?, 'payable', 'confirmed', 'Mzdy', ?, 'CZK', ?, ?, ?, ?)")
            ->execute([$this->supplierId, $amount, $date, $date, $account, 'OZ' . (++$this->seq)]);
    }

    private function map(string $kind, string $key, int $id): void
    {
        $this->pdo->prepare('INSERT INTO pohoda_import_map (supplier_id, kind, pohoda_key, target_id) VALUES (?, ?, ?, ?)')->execute([$this->supplierId, $kind, $key, $id]);
    }

    private function lockUntil(string $date): void
    {
        $this->pdo->prepare('INSERT INTO accounting_supplier_settings (supplier_id, locked_until) VALUES (?, ?) ON DUPLICATE KEY UPDATE locked_until = VALUES(locked_until)')
            ->execute([$this->supplierId, $date]);
    }

    private function filedVat(int $year, int $month): void
    {
        $this->pdo->prepare("INSERT INTO tax_submissions (supplier_id, form_code, period_year, period_month, xml_content, xml_size_bytes, xml_sha256, status, submitted_at)
            VALUES (?, 'dphdp3', ?, ?, '<x/>', 4, ?, 'submitted', NOW())")->execute([$this->supplierId, $year, $month, hash('sha256', "x{$year}{$month}")]);
    }

    private function invoiceId(string $number): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM invoices WHERE supplier_id = ? AND varsymbol = ?');
        $stmt->execute([$this->supplierId, $number]);
        return (int) $stmt->fetchColumn();
    }

    /** @return array{0:string,1:string} */
    private function balances(int $id): array
    {
        $row = $this->pdo->query("SELECT prev_balance, curr_balance FROM bank_statements WHERE id = {$id}")->fetch(PDO::FETCH_NUM);
        return [(string) $row[0], (string) $row[1]];
    }

    private function rows(string $table, string $where): int
    {
        $column = 'supplier_id';
        return (int) $this->pdo->query("SELECT COUNT(*) FROM {$table} WHERE {$column} = {$this->supplierId} AND {$where}")->fetchColumn();
    }
}
