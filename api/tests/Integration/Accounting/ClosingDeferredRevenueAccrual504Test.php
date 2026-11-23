<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\DimensionAssignmentRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Closing\ClosingService;
use MyInvoice\Service\Accounting\Closing\ClosingSourceId;
use MyInvoice\Service\Accounting\Dimension\DimensionService;
use MyInvoice\Service\Accounting\Dimension\SystemDimensionCatalog;
use MyInvoice\Service\Accounting\EntryForm\EntryForm;
use MyInvoice\Service\Accounting\EntryForm\EntryFormService;
use MyInvoice\Service\Accounting\OtherItemService;
use MyInvoice\Service\Accounting\PostingService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Časové rozlišení výnosů (384) u účetní jednotky podle vyhlášky 504/2002 Sb. Výsledkové
 * řádky tam musí nést systémové osy činnosti (hlavní/hospodářská, druh činnosti, daňový
 * režim) a daňové posouzení NNO je čte z řádku deníku. Odklad i rozpuštění proto musí
 * převzít osy řádku, ze kterého výnos odkládají; bez nich zápis uzávěrky nejde zaúčtovat.
 * Izolovaný supplier v transakci s rollbackem, jen syntetická data.
 */
#[Group('integration')]
final class ClosingDeferredRevenueAccrual504Test extends TestCase
{
    private const YEAR = 2091;

    private Connection $db;
    private PostingService $posting;
    private ClosingService $closing;
    private AccountingPeriodRepository $periods;
    private JournalEntryRepository $journal;

    private int $supplierId = 0;
    private int $userId = 0;
    private int $periodId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private int $clientId = 0;
    /** @var array<int,int> typ dimenze => hodnota (osy hlavní činnosti) */
    private array $axes = [];
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        $container = Bootstrap::buildContainer();
        $this->db      = $container->get(Connection::class);
        $this->posting = $container->get(PostingService::class);
        $this->closing = $container->get(ClosingService::class);
        $this->periods = $container->get(AccountingPeriodRepository::class);
        $this->journal = $container->get(JournalEntryRepository::class);
        $seeder        = $container->get(ChartOfAccountsSeeder::class);

        $pdo = $this->db->pdo();
        self::assertStringEndsWith('_test', (string) $pdo->query('SELECT DATABASE()')->fetchColumn());
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId  = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $czId             = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->userId === 0 || $this->currencyId === 0 || $this->vatRateId === 0 || $czId === 0) {
            $this->markTestSkipped('Chybí základní data (user/currency/vat_rate/country) v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id, accounting_mode)
             VALUES (?, "Syntetická 1", "Praha", "11000", ?, ?, ?, ?, "double_entry")'
        )->execute(['Syntetický spolek 504', $czId, 'deferred-revenue-504@example.test', $this->currencyId, $this->vatRateId]);
        $this->supplierId = (int) $pdo->lastInsertId();
        $seeder->seedForSupplier($this->supplierId);
        $this->periodId = $this->periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
        $pdo->prepare("INSERT INTO supplier_accounting_frameworks (supplier_id, effective_from, accounting_framework) VALUES (?, ?, 'cz_504')")
            ->execute([$this->supplierId, self::YEAR . '-01-01']);
        $catalog = (new SystemDimensionCatalog($this->db))->ensureForFramework($this->supplierId, self::YEAR . '-01-01', $this->userId);
        $pdo->prepare("INSERT INTO dimension_values (type_id, supplier_id, code, name) VALUES (?, ?, 'SUBS', 'Syntetická činnost')")
            ->execute([$catalog['activity_kind']['id'], $this->supplierId]);
        $this->axes = [
            (int) $catalog['activity']['id'] => (int) $catalog['activity']['values']['main'],
            (int) $catalog['activity_kind']['id'] => (int) $pdo->lastInsertId(),
            (int) $catalog['tax_regime']['id'] => (int) $catalog['tax_regime']['values']['subject'],
        ];
        foreach (['311' => 'asset', '384' => 'liability', '684.100' => 'revenue'] as $code => $type) {
            $pdo->prepare(
                'INSERT INTO chart_of_accounts (supplier_id, account_code, name, account_type)
                 SELECT ?, ?, ?, ? FROM DUAL
                  WHERE NOT EXISTS (SELECT 1 FROM chart_of_accounts WHERE supplier_id = ? AND account_code = ?)'
            )->execute([$this->supplierId, $code, 'Syntetický ' . $code, $type, $this->supplierId, $code]);
        }

        $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, main_email,
                                  language, currency_default_id, is_customer, is_vendor)
             VALUES (?, "Syntetický odběratel", "Test 1", "Praha", "11000", ?, "customer-504@example.test", "cs", ?, 1, 0)'
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

    public function testDeferralEntryCarriesActivityAxesOfRevenueLine(): void
    {
        // Roční příspěvek 36 600 Kč, 1. 7. 2091 – 30. 6. 2092 (366 dnů); za rozvahovým dnem
        // 182 dnů → 18 200 Kč na 384. Výnos zaúčtovaný s osami hlavní činnosti.
        $this->postedRevenue('20910501');

        $run = $this->closing->runDeferredRevenueAccrual($this->supplierId, $this->periodId, $this->rv(), $this->meta());

        self::assertEqualsWithDelta(18200.00, (float) $run['total'], 0.001);
        $entry = $this->journal->findBySource($this->supplierId, 'deferred_revenue_accrual', ClosingSourceId::deferredRevenueAccrual($this->periodId));
        self::assertNotNull($entry);
        $this->assertCarriesAxes((int) $entry['id'], '684.100', 'debit', 18200.00);
    }

    /** Ostatní pohledávka ve 504: odklad nese osy činnosti řádku výnosu jejího zápisu. */
    public function testOtherItemDeferralCarriesActivityAxes(): void
    {
        $container = Bootstrap::buildContainer();
        $items = $container->get(OtherItemService::class);
        $item = $items->create($this->supplierId, [
            'side' => 'receivable', 'kind' => 'claim', 'title' => 'Syntetický nájem spolku', 'partner_id' => $this->clientId,
            'issued_on' => self::YEAR . '-07-01', 'due_on' => self::YEAR . '-07-15', 'currency' => 'CZK', 'amount' => 36600.00,
            'account_code' => '311', 'counter_account_code' => '684.100',
            'accrual_from' => self::YEAR . '-07-01', 'accrual_to' => (self::YEAR + 1) . '-06-30',
        ], $this->userId);
        $container->get(DimensionService::class)->saveDocument($this->supplierId, 'other_item', (int) $item['id'], $this->axes, []);
        $items->post($this->supplierId, (int) $item['id'], $this->userId);
        $this->db->pdo()->prepare("UPDATE accounting_periods SET status = 'closing' WHERE id = ?")->execute([$this->periodId]);

        $run = $this->closing->runDeferredRevenueAccrual($this->supplierId, $this->periodId, $this->rv(), $this->meta());

        self::assertEqualsWithDelta(18200.00, (float) $run['total'], 0.001);
        $entry = $this->journal->findBySource($this->supplierId, 'deferred_revenue_accrual', ClosingSourceId::deferredRevenueAccrual($this->periodId));
        self::assertNotNull($entry);
        $lines = $this->linesOn((int) $entry['id'], '684.100');
        self::assertCount(1, $lines);
        self::assertSame('debit', $lines[0]['side']);
        $actual = (new DimensionAssignmentRepository($this->db))->entryLineDimensions($this->supplierId, (int) $entry['id'])[(int) $lines[0]['id']] ?? [];
        ksort($actual);
        $expected = $this->axes;
        ksort($expected);
        self::assertSame($expected, $actual, 'Odklad nese osy činnosti řádku výnosu zápisu pohledávky.');

        $this->periods->create($this->supplierId, self::YEAR + 1, (self::YEAR + 1) . '-01-01', (self::YEAR + 1) . '-12-31');
        $release = $this->invokePrivate('deferredRevenueReleaseForPeriod', self::YEAR . '-12-31', (self::YEAR + 1) . '-01-01', (self::YEAR + 1) . '-12-31');
        self::assertEqualsWithDelta(18200.00, (float) $release['total'], 0.001);
        $lines = $this->invokePrivate('deferredRevenueLines', $release['lines'], (float) $release['total'], true, (self::YEAR + 1) . '-01-01');
        $releaseId = $this->posting->postDocument($this->supplierId, 'deferred_revenue_accrual', ClosingSourceId::deferredRevenueAccrualRelease($this->periodId), $lines, [
            'entry_date' => (self::YEAR + 1) . '-01-01', 'posted' => true, 'posted_by' => $this->userId, 'user_id' => $this->userId,
        ]);
        $released = $this->linesOn($releaseId, '684.100');
        self::assertCount(1, $released);
        self::assertSame('credit', $released[0]['side']);
        $actual = (new DimensionAssignmentRepository($this->db))->entryLineDimensions($this->supplierId, $releaseId)[(int) $released[0]['id']] ?? [];
        ksort($actual);
        self::assertSame($expected, $actual, 'Rozpuštění vrací výnos pohledávky se stejnými osami a ve 504 jde zaúčtovat.');
    }

    /**
     * Zápis pohledávky z roku N stornovaný až v N+1 zůstává zdrojem odkladu N (storno je
     * samostatný záporný zdroj, jako dobropis). Rozpuštění jeho odkladu proto nese osy
     * stornovaného řádku a ve 504 jde zaúčtovat i s hodnotou uzavřenou po stornu.
     */
    public function testOtherItemReleaseFromRootReversedNextYearCarriesClosedValue(): void
    {
        $container = Bootstrap::buildContainer();
        $items = $container->get(OtherItemService::class);
        $item = $items->create($this->supplierId, [
            'side' => 'receivable', 'kind' => 'claim', 'title' => 'Syntetický nájem spolku', 'partner_id' => $this->clientId,
            'issued_on' => self::YEAR . '-07-01', 'due_on' => self::YEAR . '-07-15', 'currency' => 'CZK', 'amount' => 36600.00,
            'account_code' => '311', 'counter_account_code' => '684.100',
            'accrual_from' => self::YEAR . '-07-01', 'accrual_to' => (self::YEAR + 1) . '-06-30',
        ], $this->userId);
        $container->get(DimensionService::class)->saveDocument($this->supplierId, 'other_item', (int) $item['id'], $this->axes, []);
        $items->post($this->supplierId, (int) $item['id'], $this->userId);
        $this->db->pdo()->prepare("UPDATE accounting_periods SET status = 'closing' WHERE id = ?")->execute([$this->periodId]);
        $this->closing->runDeferredRevenueAccrual($this->supplierId, $this->periodId, $this->rv(), $this->meta());

        $this->periods->create($this->supplierId, self::YEAR + 1, (self::YEAR + 1) . '-01-01', (self::YEAR + 1) . '-12-31');
        $items->reverse($this->supplierId, (int) $item['id'], 'Syntetické storno v dalším roce', $this->userId, (self::YEAR + 1) . '-02-01');
        $this->closeActivityKind();

        $release = $this->invokePrivate('deferredRevenueReleaseForPeriod', self::YEAR . '-12-31', (self::YEAR + 1) . '-01-01', (self::YEAR + 1) . '-12-31');
        self::assertEqualsWithDelta(18200.00, (float) $release['total'], 0.001, 'Storno z N+1 rozpuštění odkladu N neruší.');
        $lines = $this->invokePrivate('deferredRevenueLines', $release['lines'], (float) $release['total'], true, (self::YEAR + 1) . '-01-01');
        $releaseId = $this->posting->postDocument($this->supplierId, 'deferred_revenue_accrual', ClosingSourceId::deferredRevenueAccrualRelease($this->periodId), $lines, [
            'entry_date' => (self::YEAR + 1) . '-01-01', 'posted' => true, 'posted_by' => $this->userId, 'user_id' => $this->userId,
        ]);
        $released = $this->linesOn($releaseId, '684.100');
        self::assertCount(1, $released);
        $actual = (new DimensionAssignmentRepository($this->db))->entryLineDimensions($this->supplierId, $releaseId)[(int) $released[0]['id']] ?? [];
        ksort($actual);
        $expected = $this->axes;
        ksort($expected);
        self::assertSame($expected, $actual);
    }

    /** Rozpuštění v novém roce vrací výnos se stejnými osami a ve 504 jde zaúčtovat. */
    public function testReleaseCarriesActivityAxesAndPostsInNextPeriod(): void
    {
        $this->postedRevenue('20910502');
        $this->closing->runDeferredRevenueAccrual($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $this->periods->create($this->supplierId, self::YEAR + 1, (self::YEAR + 1) . '-01-01', (self::YEAR + 1) . '-12-31');

        $release = $this->invokePrivate('deferredRevenueReleaseForPeriod', self::YEAR . '-12-31', (self::YEAR + 1) . '-01-01', (self::YEAR + 1) . '-12-31');
        self::assertEqualsWithDelta(18200.00, (float) $release['total'], 0.001);
        $lines = $this->invokePrivate('deferredRevenueLines', $release['lines'], (float) $release['total'], true, (self::YEAR + 1) . '-01-01');
        $entryId = $this->posting->postDocument($this->supplierId, 'deferred_revenue_accrual', ClosingSourceId::deferredRevenueAccrualRelease($this->periodId), $lines, [
            'entry_date' => (self::YEAR + 1) . '-01-01', 'posted' => true, 'posted_by' => $this->userId, 'user_id' => $this->userId,
        ]);

        $this->assertCarriesAxes($entryId, '684.100', 'credit', 18200.00);
    }

    /**
     * Druh činnosti uzavřený až po zaúčtování dokladu: odklad i rozpuštění ho jen přebírají
     * z živého řádku dokladu, takže krok uzávěrky projde. V jednoduché formě zápisu dostane
     * osy výnosového řádku i jeho protějšek 384 (párovač), a s nimi i odkaz na řádek dokladu.
     */
    #[DataProvider('entryFormProvider')]
    public function testDeferralAndReleaseCarryValueClosedAfterPosting(bool $single): void
    {
        $this->singleForm($single);
        $this->postedRevenue('20910503');
        $this->closeActivityKind();

        $run = $this->closing->runDeferredRevenueAccrual($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $this->assertCarriesAxes((int) $run['entry_id'], '684.100', 'debit', 18200.00);
        $this->assertCounterpartAxes((int) $run['entry_id'], '384', $single);

        $this->periods->create($this->supplierId, self::YEAR + 1, (self::YEAR + 1) . '-01-01', (self::YEAR + 1) . '-12-31');
        $release = $this->invokePrivate('deferredRevenueReleaseForPeriod', self::YEAR . '-12-31', (self::YEAR + 1) . '-01-01', (self::YEAR + 1) . '-12-31');
        $lines = $this->invokePrivate('deferredRevenueLines', $release['lines'], (float) $release['total'], true, (self::YEAR + 1) . '-01-01');
        $entryId = $this->posting->postDocument($this->supplierId, 'deferred_revenue_accrual', ClosingSourceId::deferredRevenueAccrualRelease($this->periodId), $lines, [
            'entry_date' => (self::YEAR + 1) . '-01-01', 'posted' => true, 'posted_by' => $this->userId, 'user_id' => $this->userId,
        ]);
        $this->assertCarriesAxes($entryId, '684.100', 'credit', 18200.00);
        $this->assertCounterpartAxes($entryId, '384', $single);
    }

    /**
     * Jednoduchá forma, dva výnosové řádky s různým druhem činnosti, oba uzavřené po zaúčtování:
     * každý řádek 684 dostane vlastní protějšek 384 a ten nese osy i odkaz právě svého páru,
     * při odkladu i rozpuštění. Varianta s dobropisem na druhém druhu činnosti má výsledkové
     * řádky na obou stranách (dobropis −18 300 odloží −9 100).
     */
    #[DataProvider('secondDocumentProvider')]
    public function testSingleFormPairsEachClosedValueWithItsOwn384(bool $creditNote): void
    {
        $this->singleForm(true);
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO dimension_values (type_id, supplier_id, code, name) SELECT type_id, supplier_id, 'OTHER', 'Jiná činnost' FROM dimension_values WHERE supplier_id = ? AND code = 'SUBS'")
            ->execute([$this->supplierId]);
        $other = (int) $pdo->lastInsertId();
        $kindType = (int) $pdo->query("SELECT type_id FROM dimension_values WHERE id = {$other}")->fetchColumn();
        $otherAxes = array_replace($this->axes, [$kindType => $other]);
        $invoiceId = $this->postRevenueDocument('20910510', 36600.00, $this->axes);
        $this->postRevenueDocument('20910511', $creditNote ? -18300.00 : 36600.00, $otherAxes, $creditNote ? $invoiceId : null);
        $pdo->prepare("UPDATE dimension_values SET is_active = 0 WHERE supplier_id = ? AND code IN ('SUBS', 'OTHER')")->execute([$this->supplierId]);
        $pdo->prepare("UPDATE accounting_periods SET status = 'closing' WHERE id = ?")->execute([$this->periodId]);
        $subs = $this->axes[$kindType];
        $second = $creditNote ? 9100.00 : 18200.00;

        $run = $this->closing->runDeferredRevenueAccrual($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $this->assertPairsByKind((int) $run['entry_id'], $kindType, [
            '684.100' => [$subs => ['debit', 18200.00], $other => [$creditNote ? 'credit' : 'debit', $second]],
            '384' => [$subs => ['credit', 18200.00], $other => [$creditNote ? 'debit' : 'credit', $second]],
        ]);

        $this->periods->create($this->supplierId, self::YEAR + 1, (self::YEAR + 1) . '-01-01', (self::YEAR + 1) . '-12-31');
        $release = $this->invokePrivate('deferredRevenueReleaseForPeriod', self::YEAR . '-12-31', (self::YEAR + 1) . '-01-01', (self::YEAR + 1) . '-12-31');
        $lines = $this->invokePrivate('deferredRevenueLines', $release['lines'], (float) $release['total'], true, (self::YEAR + 1) . '-01-01');
        $entryId = $this->posting->postDocument($this->supplierId, 'deferred_revenue_accrual', ClosingSourceId::deferredRevenueAccrualRelease($this->periodId), $lines, [
            'entry_date' => (self::YEAR + 1) . '-01-01', 'posted' => true, 'posted_by' => $this->userId, 'user_id' => $this->userId,
        ]);
        $this->assertPairsByKind($entryId, $kindType, [
            '684.100' => [$subs => ['credit', 18200.00], $other => [$creditNote ? 'debit' : 'credit', $second]],
            '384' => [$subs => ['debit', 18200.00], $other => [$creditNote ? 'credit' : 'debit', $second]],
        ]);
    }

    /** @return array<string,array{bool}> */
    public static function secondDocumentProvider(): array
    {
        return ['dvě faktury' => [false], 'faktura a dobropis' => [true]];
    }

    /**
     * Zaúčtovaná faktura (nebo dobropis k $parentId) s výnosem 684.100 na zadaných osách.
     *
     * @param array<int,int> $axes
     */
    private function postRevenueDocument(string $number, float $base, array $axes, ?int $parentId = null): int
    {
        $date = self::YEAR . '-07-01';
        $this->db->pdo()->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, parent_invoice_id, client_id, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, total_without_vat, total_vat, total_with_vat, paid_total, status,
                 vat_classification_code, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, 0, ?, 0, "sent", "1", ?)'
        )->execute([$this->supplierId, $number, $parentId === null ? 'invoice' : 'credit_note', $parentId, $this->clientId,
            $date, $date, $date, $this->currencyId, $base, $base, $this->userId]);
        $invoiceId = (int) $this->db->pdo()->lastInsertId();
        $this->item($invoiceId, $base, $date, (self::YEAR + 1) . '-06-30');
        $credit = $base < 0;
        $this->posting->postDocument($this->supplierId, 'invoice', $invoiceId, [
            ['account_code' => '311', 'side' => $credit ? 'credit' : 'debit', 'amount' => abs($base)],
            ['account_code' => '684.100', 'side' => $credit ? 'debit' : 'credit', 'amount' => abs($base), 'dimensions' => $axes, 'dimension_origins' => $this->origins()],
        ], ['entry_date' => $date, 'posted' => true, 'posted_by' => $this->userId, 'user_id' => $this->userId]);
        return $invoiceId;
    }

    /**
     * Řádky zápisu na účtech podle druhu činnosti: strana a částka každého řádku.
     *
     * @param array<string,array<int,array{0:string,1:float}>> $expected účet => druh činnosti => [strana, částka]
     */
    private function assertPairsByKind(int $entryId, int $kindType, array $expected): void
    {
        $dims = (new DimensionAssignmentRepository($this->db))->entryLineDimensions($this->supplierId, $entryId);
        foreach ($expected as $code => $byKind) {
            $lines = $this->linesOn($entryId, (string) $code);
            self::assertCount(count($byKind), $lines, "Řádky {$code}.");
            $actual = [];
            foreach ($lines as $line) {
                $actual[$dims[(int) $line['id']][$kindType] ?? 0] = [$line['side'], round((float) $line['amount'], 2)];
            }
            ksort($actual);
            ksort($byKind);
            self::assertSame($byKind, $actual, "Účet {$code}: každý řádek nese druh činnosti svého páru.");
        }
    }

    /**
     * Hodnota uzavřená až mezi odkladem a dalšími kroky: opakované spuštění kroku odkladu
     * (přepis zápisu) i rozpuštění v N+1 projdou, výnosy (384) i náklady (381).
     */
    #[DataProvider('entryFormProvider')]
    public function testRerunAndReleaseCarryValueClosedAfterDeferral(bool $single): void
    {
        $this->singleForm($single);
        $this->postedRevenue('20910512');
        $this->db->pdo()->prepare("UPDATE accounting_periods SET status = 'open' WHERE id = ?")->execute([$this->periodId]);
        $this->postedPrepaid();
        $this->closing->runDeferredRevenueAccrual($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $this->closing->runPrepaidExpenseAccrual($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $this->closeActivityKind();

        $revenue = $this->closing->runDeferredRevenueAccrual($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $prepaid = $this->closing->runPrepaidExpenseAccrual($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $this->assertCarriesAxes((int) $revenue['entry_id'], '684.100', 'debit', 18200.00);
        $this->assertCounterpartAxes((int) $revenue['entry_id'], '384', $single);
        $this->assertCarriesAxes((int) $prepaid['entry_id'], '518', 'credit', 18200.00);
        $this->assertCounterpartAxes((int) $prepaid['entry_id'], '381', $single);

        $this->periods->create($this->supplierId, self::YEAR + 1, (self::YEAR + 1) . '-01-01', (self::YEAR + 1) . '-12-31');
        foreach ([
            ['deferredRevenueReleaseForPeriod', 'deferredRevenueLines', 'deferred_revenue_accrual', ClosingSourceId::deferredRevenueAccrualRelease($this->periodId), '684.100', 'credit', '384'],
            ['prepaidExpenseReleaseForPeriod', 'prepaidExpenseLines', 'prepaid_expense_accrual', ClosingSourceId::prepaidExpenseAccrualRelease($this->periodId), '518', 'debit', '381'],
        ] as [$releaseFor, $linesFor, $sourceType, $sourceId, $account, $side, $counterpart]) {
            $release = $this->invokePrivate($releaseFor, self::YEAR . '-12-31', (self::YEAR + 1) . '-01-01', (self::YEAR + 1) . '-12-31');
            $lines = $this->invokePrivate($linesFor, $release['lines'], (float) $release['total'], true, (self::YEAR + 1) . '-01-01');
            $entryId = $this->posting->postDocument($this->supplierId, $sourceType, $sourceId, $lines, [
                'entry_date' => (self::YEAR + 1) . '-01-01', 'posted' => true, 'posted_by' => $this->userId, 'user_id' => $this->userId,
            ]);
            $this->assertCarriesAxes($entryId, $account, $side, 18200.00);
            $this->assertCounterpartAxes($entryId, $counterpart, $single);
        }
    }

    /**
     * Výnosový řádek rozpadlý mezi dva druhy činnosti, z nichž jeden se po zaúčtování uzavřel:
     * odklad převezme rozpad, v jednoduché formě i na protějšek 384.
     */
    #[DataProvider('entryFormProvider')]
    public function testDeferralCarriesSplitWithValueClosedAfterPosting(bool $single): void
    {
        $this->singleForm($single);
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO dimension_values (type_id, supplier_id, code, name) SELECT type_id, supplier_id, 'OTHER', 'Jiná činnost' FROM dimension_values WHERE supplier_id = ? AND code = 'SUBS'")
            ->execute([$this->supplierId]);
        $other = (int) $pdo->lastInsertId();
        $kindType = (int) $pdo->query("SELECT type_id FROM dimension_values WHERE id = {$other}")->fetchColumn();
        $split = [$kindType => [$this->axes[$kindType] => 0.5, $other => 0.5]];
        $origins = $this->origins();
        $splitOrigins = [$kindType => $origins[$kindType]];
        unset($origins[$kindType]);
        $invoiceId = $this->invoice('20910513', 36600.00);
        $this->item($invoiceId, 36600.00, self::YEAR . '-07-01', (self::YEAR + 1) . '-06-30');
        $this->posting->postDocument($this->supplierId, 'invoice', $invoiceId, [
            ['account_code' => '311', 'side' => 'debit', 'amount' => 36600.00],
            ['account_code' => '684.100', 'side' => 'credit', 'amount' => 36600.00,
                'dimensions' => array_diff_key($this->axes, [$kindType => true]), 'dimension_origins' => $origins,
                'dimension_splits' => $split, 'dimension_split_origins' => $splitOrigins],
        ], ['entry_date' => self::YEAR . '-07-01', 'posted' => true, 'posted_by' => $this->userId, 'user_id' => $this->userId]);
        $this->closeActivityKind();
        $pdo->prepare("UPDATE accounting_periods SET status = 'closing' WHERE id = ?")->execute([$this->periodId]);

        $run = $this->closing->runDeferredRevenueAccrual($this->supplierId, $this->periodId, $this->rv(), $this->meta());

        $entryId = (int) $run['entry_id'];
        $repo = new DimensionAssignmentRepository($this->db);
        $splits = $repo->lineSplits($this->supplierId, array_map(static fn (array $l): int => (int) $l['id'], [
            ...$this->linesOn($entryId, '684.100'), ...$this->linesOn($entryId, '384'),
        ]));
        self::assertCount(1, $this->linesOn($entryId, '684.100'));
        self::assertCount(1, $this->linesOn($entryId, '384'));
        $revenueLine = (int) $this->linesOn($entryId, '684.100')[0]['id'];
        $counterLine = (int) $this->linesOn($entryId, '384')[0]['id'];
        $expected = [$this->axes[$kindType], $other];
        sort($expected);
        $values = array_keys($splits[$revenueLine][$kindType] ?? []);
        sort($values);
        self::assertSame($expected, $values, 'Odklad nese rozpad řádku dokladu.');
        $values = array_keys($splits[$counterLine][$kindType] ?? []);
        sort($values);
        self::assertSame($single ? $expected : [], $values);

        // Rozpuštění v N+1 nese rozpad také a jde zaúčtovat.
        $this->periods->create($this->supplierId, self::YEAR + 1, (self::YEAR + 1) . '-01-01', (self::YEAR + 1) . '-12-31');
        $release = $this->invokePrivate('deferredRevenueReleaseForPeriod', self::YEAR . '-12-31', (self::YEAR + 1) . '-01-01', (self::YEAR + 1) . '-12-31');
        $lines = $this->invokePrivate('deferredRevenueLines', $release['lines'], (float) $release['total'], true, (self::YEAR + 1) . '-01-01');
        $releaseId = $this->posting->postDocument($this->supplierId, 'deferred_revenue_accrual', ClosingSourceId::deferredRevenueAccrualRelease($this->periodId), $lines, [
            'entry_date' => (self::YEAR + 1) . '-01-01', 'posted' => true, 'posted_by' => $this->userId, 'user_id' => $this->userId,
        ]);
        foreach (['684.100' => $expected, '384' => $single ? $expected : []] as $code => $want) {
            $codeLines = $this->linesOn($releaseId, (string) $code);
            self::assertCount(1, $codeLines);
            $line = (int) $codeLines[0]['id'];
            $values = array_keys($repo->lineSplits($this->supplierId, [$line])[$line][$kindType] ?? []);
            sort($values);
            self::assertSame($want, $values, "Rozpuštění {$code} nese rozpad.");
        }
    }

    /** Náklady příštích období (381) se stejně: hodnota uzavřená po zaúčtování přijaté faktury. */
    #[DataProvider('entryFormProvider')]
    public function testPrepaidDeferralAndReleaseCarryValueClosedAfterPosting(bool $single): void
    {
        $this->singleForm($single);
        $this->postedPrepaid();
        $this->closeActivityKind();

        $run = $this->closing->runPrepaidExpenseAccrual($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $this->assertCarriesAxes((int) $run['entry_id'], '518', 'credit', 18200.00);
        $this->assertCounterpartAxes((int) $run['entry_id'], '381', $single);

        $this->periods->create($this->supplierId, self::YEAR + 1, (self::YEAR + 1) . '-01-01', (self::YEAR + 1) . '-12-31');
        $release = $this->invokePrivate('prepaidExpenseReleaseForPeriod', self::YEAR . '-12-31', (self::YEAR + 1) . '-01-01', (self::YEAR + 1) . '-12-31');
        $lines = $this->invokePrivate('prepaidExpenseLines', $release['lines'], (float) $release['total'], true, (self::YEAR + 1) . '-01-01');
        $entryId = $this->posting->postDocument($this->supplierId, 'prepaid_expense_accrual', ClosingSourceId::prepaidExpenseAccrualRelease($this->periodId), $lines, [
            'entry_date' => (self::YEAR + 1) . '-01-01', 'posted' => true, 'posted_by' => $this->userId, 'user_id' => $this->userId,
        ]);
        $this->assertCarriesAxes($entryId, '518', 'debit', 18200.00);
        $this->assertCounterpartAxes($entryId, '381', $single);
    }

    /** @return array<string,array{bool}> */
    public static function entryFormProvider(): array
    {
        return ['složená forma' => [false], 'jednoduchá forma' => [true]];
    }

    /**
     * Odkaz na živý výnosový řádek, který uzavřenou hodnotu nenese (jiný druh činnosti),
     * ji nepropustí: převzít jde jen hodnotu, kterou uvedený řádek skutečně nese, v obou formách
     * zápisu. Vlastní hodnotu protějšku 384 ověřuje {@see testCounterpartOwnClosedValueIsRefusedWhenSourceLineDoesNotCarryIt()}.
     */
    #[DataProvider('entryFormProvider')]
    public function testClosedValueIsRefusedWhenReferencedLineDoesNotCarryIt(bool $single): void
    {
        $this->singleForm($single);
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO dimension_values (type_id, supplier_id, code, name) SELECT type_id, supplier_id, 'OTHER', 'Jiná činnost' FROM dimension_values WHERE supplier_id = ? AND code = 'SUBS'")
            ->execute([$this->supplierId]);
        $other = (int) $pdo->lastInsertId();
        $kindType = (int) $pdo->query("SELECT type_id FROM dimension_values WHERE id = {$other}")->fetchColumn();
        $otherAxes = array_replace($this->axes, [$kindType => $other]);
        $invoiceId = $this->invoice('20910504', 1000.00);
        $this->item($invoiceId, 1000.00, self::YEAR . '-07-01', (self::YEAR + 1) . '-06-30');
        $this->posting->postDocument($this->supplierId, 'invoice', $invoiceId, [
            ['account_code' => '311', 'side' => 'debit', 'amount' => 1000.00],
            ['account_code' => '684.100', 'side' => 'credit', 'amount' => 1000.00, 'dimensions' => $otherAxes, 'dimension_origins' => $this->origins()],
        ], ['entry_date' => self::YEAR . '-07-01', 'posted' => true, 'posted_by' => $this->userId, 'user_id' => $this->userId]);
        $otherLine = (int) $pdo->query(
            "SELECT l.id FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.supplier_id = {$this->supplierId} AND a.account_code = '684.100' ORDER BY l.id DESC LIMIT 1"
        )->fetchColumn();
        $this->closeActivityKind();
        $pdo->prepare("UPDATE accounting_periods SET status = 'closing' WHERE id = ?")->execute([$this->periodId]);

        $this->expectExceptionMessage('Uzavřenou hodnotu systémové dimenze nelze nově přiřadit.');
        $this->posting->postDocument($this->supplierId, 'deferred_revenue_accrual', ClosingSourceId::deferredRevenueAccrual($this->periodId), [
            ['account_code' => '684.100', 'side' => 'debit', 'amount' => 100.00, 'dimensions' => $this->axes, 'dimension_carry_lines' => [$otherLine]],
            ['account_code' => '384', 'side' => 'credit', 'amount' => 100.00],
        ], ['entry_date' => self::YEAR . '-12-31', 'posted' => true, 'posted_by' => $this->userId, 'allow_closing_period' => true]);
    }

    /** Uzavřenou hodnotu nepropustí odkaz na stornovaný řádek dokladu. */
    public function testClosedValueIsRefusedWhenReferencedEntryIsReversed(): void
    {
        $this->postedRevenue('20910506');
        $line = $this->lastRevenueLine();
        $entry = (int) $this->db->pdo()->query("SELECT entry_id FROM journal_entry_lines WHERE id = {$line}")->fetchColumn();
        $this->db->pdo()->prepare("UPDATE accounting_periods SET status = 'open' WHERE id = ?")->execute([$this->periodId]);
        $this->posting->reverse($this->supplierId, $entry, ['entry_date' => self::YEAR . '-08-01']);
        $this->closeActivityKind();
        $this->db->pdo()->prepare("UPDATE accounting_periods SET status = 'closing' WHERE id = ?")->execute([$this->periodId]);

        $this->expectExceptionMessage('Uzavřenou hodnotu systémové dimenze nelze nově přiřadit.');
        $this->postCarried($line);
    }

    /** Uzavřenou hodnotu nepropustí odkaz na rozvahový řádek, i když ji nese. */
    public function testClosedValueIsRefusedWhenReferencedLineIsNotResultLine(): void
    {
        $invoiceId = $this->invoice('20910509', 1000.00);
        $this->item($invoiceId, 1000.00, self::YEAR . '-07-01', (self::YEAR + 1) . '-06-30');
        $this->posting->postDocument($this->supplierId, 'invoice', $invoiceId, [
            ['account_code' => '311', 'side' => 'debit', 'amount' => 1000.00, 'dimensions' => $this->axes, 'dimension_origins' => $this->origins()],
            ['account_code' => '684.100', 'side' => 'credit', 'amount' => 1000.00, 'dimensions' => $this->axes, 'dimension_origins' => $this->origins()],
        ], ['entry_date' => self::YEAR . '-07-01', 'posted' => true, 'posted_by' => $this->userId, 'user_id' => $this->userId]);
        $receivable = (int) $this->db->pdo()->query(
            "SELECT l.id FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.supplier_id = {$this->supplierId} AND a.account_code = '311' ORDER BY l.id DESC LIMIT 1"
        )->fetchColumn();
        self::assertNotSame([], (new DimensionAssignmentRepository($this->db))->lineDimensions($this->supplierId, [$receivable]), 'Rozvahový řádek nese osy.');
        $this->closeActivityKind();
        $this->db->pdo()->prepare("UPDATE accounting_periods SET status = 'closing' WHERE id = ?")->execute([$this->periodId]);

        $this->expectExceptionMessage('Uzavřenou hodnotu systémové dimenze nelze nově přiřadit.');
        $this->postCarried($receivable);
    }

    /** Uzavřenou hodnotu nepropustí odkaz na nezaúčtovaný (rozpracovaný) zápis. */
    public function testClosedValueIsRefusedWhenReferencedEntryIsNotPosted(): void
    {
        $invoiceId = $this->invoice('20910507', 1000.00);
        $this->item($invoiceId, 1000.00, self::YEAR . '-07-01', (self::YEAR + 1) . '-06-30');
        $this->posting->postDocument($this->supplierId, 'invoice', $invoiceId, [
            ['account_code' => '311', 'side' => 'debit', 'amount' => 1000.00],
            ['account_code' => '684.100', 'side' => 'credit', 'amount' => 1000.00, 'dimensions' => $this->axes, 'dimension_origins' => $this->origins()],
        ], ['entry_date' => self::YEAR . '-07-01', 'posted' => false, 'user_id' => $this->userId]);
        $line = $this->lastRevenueLine();
        $this->closeActivityKind();
        $this->db->pdo()->prepare("UPDATE accounting_periods SET status = 'closing' WHERE id = ?")->execute([$this->periodId]);

        $this->expectExceptionMessage('Uzavřenou hodnotu systémové dimenze nelze nově přiřadit.');
        $this->postCarried($line);
    }

    /**
     * Ve 504 starý odklad bez dimenzí: rozpuštění si ponechá jen osy činnosti dokladu (bez
     * nich by nešlo zaúčtovat), ostatní dimenze (středisko) zrcadlí odklad a nenese je.
     * Rozpuštění jde zaúčtovat i s osou uzavřenou po zaúčtování dokladu, v obou formách zápisu.
     */
    #[DataProvider('entryFormProvider')]
    public function testReleaseIn504KeepsOnlyActivityAxesAfterDeferralWithoutDimensions(bool $single): void
    {
        $this->singleForm($single);
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO dimension_types (supplier_id, code, name, kind) VALUES (?, 'stredisko-504', 'Středisko', 'custom')")->execute([$this->supplierId]);
        $centerType = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO dimension_values (type_id, supplier_id, code, name) VALUES (?, ?, 'ODDIL', 'Oddíl')")->execute([$centerType, $this->supplierId]);
        $center = (int) $pdo->lastInsertId();
        $invoiceId = $this->invoice('20910508', 36600.00);
        $this->item($invoiceId, 36600.00, self::YEAR . '-07-01', (self::YEAR + 1) . '-06-30');
        $this->posting->postDocument($this->supplierId, 'invoice', $invoiceId, [
            ['account_code' => '311', 'side' => 'debit', 'amount' => 36600.00],
            ['account_code' => '684.100', 'side' => 'credit', 'amount' => 36600.00, 'dimensions' => $this->axes + [$centerType => $center], 'dimension_origins' => $this->origins()],
        ], ['entry_date' => self::YEAR . '-07-01', 'posted' => true, 'posted_by' => $this->userId, 'user_id' => $this->userId]);
        $pdo->prepare("UPDATE accounting_periods SET status = 'closing' WHERE id = ?")->execute([$this->periodId]);
        $run = $this->closing->runDeferredRevenueAccrual($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $this->db->pdo()->prepare('DELETE d FROM journal_entry_line_dimensions d JOIN journal_entry_lines l ON l.id = d.line_id WHERE l.entry_id = ?')
            ->execute([(int) $run['entry_id']]);
        $this->periods->create($this->supplierId, self::YEAR + 1, (self::YEAR + 1) . '-01-01', (self::YEAR + 1) . '-12-31');

        $this->closeActivityKind();
        $release = $this->invokePrivate('deferredRevenueReleaseForPeriod', self::YEAR . '-12-31', (self::YEAR + 1) . '-01-01', (self::YEAR + 1) . '-12-31');

        self::assertCount(1, $release['lines']);
        $dims = $release['lines'][0]['dimensions'] ?? [];
        ksort($dims);
        $expected = $this->axes;
        ksort($expected);
        self::assertSame($expected, $dims);
        $this->postLegacyRelease('deferredRevenueLines', 'deferred_revenue_accrual', ClosingSourceId::deferredRevenueAccrualRelease($this->periodId), $release, '384', $single);
    }

    /**
     * Starý odklad bez dimenzí u faktury se dvěma položkami na stejném účtu a s různou
     * činností: položka se páruje s řádkem zápisu podle všech dimenzí dokladu (středisko)
     * a na osy činnosti se zužuje až potom. Rozpuštění rozlišené položky proto nese jen
     * osy její činnosti, ne poměrem i osy činnosti druhé položky.
     */
    public function testLegacyReleaseIn504TakesAxesOfMatchedItemOnly(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO dimension_types (supplier_id, code, name, kind) VALUES (?, 'stredisko-504', 'Středisko', 'custom')")->execute([$this->supplierId]);
        $centerType = (int) $pdo->lastInsertId();
        $centers = [];
        foreach (['ODDIL-A', 'ODDIL-B'] as $code) {
            $pdo->prepare('INSERT INTO dimension_values (type_id, supplier_id, code, name) VALUES (?, ?, ?, ?)')->execute([$centerType, $this->supplierId, $code, $code]);
            $centers[] = (int) $pdo->lastInsertId();
        }
        $catalog = (new SystemDimensionCatalog($this->db))->ensureForFramework($this->supplierId, self::YEAR . '-01-01', $this->userId);
        $economic = [(int) $catalog['activity']['id'] => (int) $catalog['activity']['values']['economic']] + $this->axes;
        $invoiceId = $this->invoice('20910509', 48600.00);
        $this->item($invoiceId, 36600.00, self::YEAR . '-07-01', (self::YEAR + 1) . '-06-30');
        $this->item($invoiceId, 12000.00, self::YEAR . '-07-01', self::YEAR . '-07-31');
        (new DimensionAssignmentRepository($this->db))->replaceDocumentDimensions($this->supplierId, 'invoice', $invoiceId, [], [
            1 => [$centerType => $centers[0]],
            2 => [$centerType => $centers[1]],
        ]);
        $this->posting->postDocument($this->supplierId, 'invoice', $invoiceId, [
            ['account_code' => '311', 'side' => 'debit', 'amount' => 48600.00],
            ['account_code' => '684.100', 'side' => 'credit', 'amount' => 36600.00, 'dimensions' => $this->axes + [$centerType => $centers[0]], 'dimension_origins' => $this->origins()],
            ['account_code' => '684.100', 'side' => 'credit', 'amount' => 12000.00, 'dimensions' => $economic + [$centerType => $centers[1]], 'dimension_origins' => $this->origins()],
        ], ['entry_date' => self::YEAR . '-07-01', 'posted' => true, 'posted_by' => $this->userId, 'user_id' => $this->userId]);
        $pdo->prepare("UPDATE accounting_periods SET status = 'closing' WHERE id = ?")->execute([$this->periodId]);
        $run = $this->closing->runDeferredRevenueAccrual($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $pdo->prepare('DELETE d FROM journal_entry_line_dimensions d JOIN journal_entry_lines l ON l.id = d.line_id WHERE l.entry_id = ?')
            ->execute([(int) $run['entry_id']]);
        $this->periods->create($this->supplierId, self::YEAR + 1, (self::YEAR + 1) . '-01-01', (self::YEAR + 1) . '-12-31');

        $release = $this->invokePrivate('deferredRevenueReleaseForPeriod', self::YEAR . '-12-31', (self::YEAR + 1) . '-01-01', (self::YEAR + 1) . '-12-31');

        self::assertCount(1, $release['lines']);
        self::assertEqualsWithDelta(18200.00, (float) $release['lines'][0]['amount'], 0.001);
        $dims = $release['lines'][0]['dimensions'] ?? [];
        ksort($dims);
        $expected = $this->axes;
        ksort($expected);
        self::assertSame($expected, $dims, 'Rozpuštění nese jen osy činnosti rozlišené položky.');
    }

    /**
     * Náklady (381) ve 504 se starým odkladem bez dimenzí: rozpuštění nese osy dokladu a jde
     * zaúčtovat i s osou uzavřenou po zaúčtování dokladu, v obou formách zápisu.
     */
    #[DataProvider('entryFormProvider')]
    public function testPrepaidReleaseIn504CarriesAxesAfterDeferralWithoutDimensions(bool $single): void
    {
        $this->singleForm($single);
        $run = $this->postedPrepaidAndRun();
        $this->db->pdo()->prepare('DELETE d FROM journal_entry_line_dimensions d JOIN journal_entry_lines l ON l.id = d.line_id WHERE l.entry_id = ?')
            ->execute([(int) $run['entry_id']]);
        $this->periods->create($this->supplierId, self::YEAR + 1, (self::YEAR + 1) . '-01-01', (self::YEAR + 1) . '-12-31');

        $this->closeActivityKind();
        $release = $this->invokePrivate('prepaidExpenseReleaseForPeriod', self::YEAR . '-12-31', (self::YEAR + 1) . '-01-01', (self::YEAR + 1) . '-12-31');

        self::assertCount(1, $release['lines']);
        $dims = $release['lines'][0]['dimensions'] ?? [];
        ksort($dims);
        $expected = $this->axes;
        ksort($expected);
        self::assertSame($expected, $dims);
        $this->postLegacyRelease('prepaidExpenseLines', 'prepaid_expense_accrual', ClosingSourceId::prepaidExpenseAccrualRelease($this->periodId), $release, '381', $single);
    }

    /**
     * Zaúčtuje rozpuštění starého odkladu v N+1; protějšek 381/384 nese v jednoduché formě
     * osy činnosti svého páru, ve složené je bez dimenzí.
     *
     * @param array<string,mixed> $release
     */
    private function postLegacyRelease(string $linesFor, string $sourceType, int $sourceId, array $release, string $counterpart, bool $single): void
    {
        $lines = $this->invokePrivate($linesFor, $release['lines'], (float) $release['total'], true, (self::YEAR + 1) . '-01-01');
        $entryId = $this->posting->postDocument($this->supplierId, $sourceType, $sourceId, $lines, [
            'entry_date' => (self::YEAR + 1) . '-01-01', 'posted' => true, 'posted_by' => $this->userId, 'user_id' => $this->userId,
        ]);
        $this->assertCounterpartAxes($entryId, $counterpart, $single);
    }

    /**
     * Protějšek 384 s vlastní uzavřenou hodnotou druhu činnosti, kterou řádek dokladu nese:
     * párovač mu doplní ostatní osy i s odkazem a odkaz hodnotu propustí. Bez přenosu odkazu
     * by zápis padl. Pozitivní kontrola k {@see testCounterpartOwnClosedValueIsRefusedWhenSourceLineDoesNotCarryIt()}.
     */
    public function testCounterpartOwnClosedValueCarriedBySourceLineIsAccepted(): void
    {
        $this->singleForm(true);
        $this->postedRevenue('20910515');
        $line = $this->lastRevenueLine();
        $kindType = (int) $this->db->pdo()->query("SELECT type_id FROM dimension_values WHERE supplier_id = {$this->supplierId} AND code = 'SUBS'")->fetchColumn();
        $this->closeActivityKind();

        $entryId = $this->posting->postDocument($this->supplierId, 'deferred_revenue_accrual', ClosingSourceId::deferredRevenueAccrual($this->periodId), [
            ['account_code' => '684.100', 'side' => 'debit', 'amount' => 100.00, 'dimensions' => $this->axes, 'dimension_carry_lines' => [$line]],
            ['account_code' => '384', 'side' => 'credit', 'amount' => 100.00, 'dimensions' => [$kindType => $this->axes[$kindType]]],
        ], ['entry_date' => self::YEAR . '-12-31', 'posted' => true, 'posted_by' => $this->userId, 'allow_closing_period' => true]);

        $this->assertCounterpartAxes($entryId, '384', true);
    }

    /**
     * Protějšek 384 s vlastní uzavřenou hodnotou druhu činnosti, kterou řádek dokladu nenese:
     * odkaz převzatý od páru ji nepropustí (uzná se jen hodnota, kterou odkazovaný řádek nese).
     * Protipříklad: projde i bez přenosu odkazu, chrání zúžení v politice dimenzí.
     */
    public function testCounterpartOwnClosedValueIsRefusedWhenSourceLineDoesNotCarryIt(): void
    {
        $this->singleForm(true);
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO dimension_values (type_id, supplier_id, code, name) SELECT type_id, supplier_id, 'OTHER', 'Jiná činnost' FROM dimension_values WHERE supplier_id = ? AND code = 'SUBS'")
            ->execute([$this->supplierId]);
        $other = (int) $pdo->lastInsertId();
        $kindType = (int) $pdo->query("SELECT type_id FROM dimension_values WHERE id = {$other}")->fetchColumn();
        $this->postedRevenue('20910514');
        $line = $this->lastRevenueLine();
        $pdo->prepare("UPDATE dimension_values SET is_active = 0 WHERE id = ?")->execute([$other]);

        $this->expectExceptionMessage('Uzavřenou hodnotu systémové dimenze nelze nově přiřadit.');
        $this->posting->postDocument($this->supplierId, 'deferred_revenue_accrual', ClosingSourceId::deferredRevenueAccrual($this->periodId), [
            ['account_code' => '684.100', 'side' => 'debit', 'amount' => 100.00, 'dimensions' => $this->axes, 'dimension_carry_lines' => [$line]],
            ['account_code' => '384', 'side' => 'credit', 'amount' => 100.00, 'dimensions' => [$kindType => $other]],
        ], ['entry_date' => self::YEAR . '-12-31', 'posted' => true, 'posted_by' => $this->userId, 'allow_closing_period' => true]);
    }

    /** Převzetí uzavřené hodnoty platí jen pro zápisy časového rozlišení, ne pro ruční zápis. */
    public function testClosedValueIsRefusedForManualEntryEvenWithCarriedLine(): void
    {
        $this->postedRevenue('20910505');
        $this->closeActivityKind();
        $this->db->pdo()->prepare("UPDATE accounting_periods SET status = 'open' WHERE id = ?")->execute([$this->periodId]);
        $revenueLine = (int) $this->db->pdo()->query(
            "SELECT l.id FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.supplier_id = {$this->supplierId} AND a.account_code = '684.100' ORDER BY l.id DESC LIMIT 1"
        )->fetchColumn();

        $this->expectExceptionMessage('Uzavřenou hodnotu systémové dimenze nelze nově přiřadit.');
        $this->posting->postDocument($this->supplierId, 'manual', null, [
            ['account_code' => '684.100', 'side' => 'debit', 'amount' => 100.00, 'dimensions' => $this->axes, 'dimension_carry_lines' => [$revenueLine]],
            ['account_code' => '384', 'side' => 'credit', 'amount' => 100.00],
        ], ['entry_date' => self::YEAR . '-12-30', 'posted' => true, 'posted_by' => $this->userId]);
    }

    /** Náklady příštích období (381): odklad z 5xx nese osy řádku přijaté faktury. */
    public function testPrepaidExpenseDeferralCarriesActivityAxesOfExpenseLine(): void
    {
        $run = $this->postedPrepaidAndRun();

        self::assertNotNull($run['entry_id']);
        $this->assertCarriesAxes((int) $run['entry_id'], '518', 'credit', 18200.00);
    }

    /**
     * Převzatá přijatá faktura: náklad s osami činnosti nese ruční zápis vlastněný dokladem
     * ve skupině zaúčtování, vlastní zápis se zdrojem dokladu nemá. Odklad převezme osy
     * z tohoto zápisu; bez nich by ho politika os ve 504 nepřijala.
     */
    public function testPrepaidDeferralOfImportedDocumentCarriesActivityAxesOfOwnedEntry(): void
    {
        $run = $this->postedPrepaidAndRun(owned: true);

        self::assertNotNull($run['entry_id']);
        $this->assertCarriesAxes((int) $run['entry_id'], '518', 'credit', 18200.00);
    }

    /**
     * Ostatní závazek ve 504: odklad na 381 i rozpuštění nesou osy činnosti řádku nákladu
     * jeho zápisu (5xx), bez nich by zápis uzávěrky nešel zaúčtovat.
     */
    #[DataProvider('entryFormProvider')]
    public function testOtherItemPrepaidDeferralAndReleaseCarryActivityAxes(bool $single): void
    {
        $this->singleForm($single);
        $this->db->pdo()->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, main_email,
                                  language, currency_default_id, is_customer, is_vendor)
             SELECT supplier_id, "Syntetický pronajímatel", street, city, zip, country_id, "landlord-504@example.test",
                    language, currency_default_id, 0, 1 FROM clients WHERE id = ?'
        )->execute([$this->clientId]);
        $vendorId = (int) $this->db->pdo()->lastInsertId();
        $container = Bootstrap::buildContainer();
        $items = $container->get(OtherItemService::class);
        $item = $items->create($this->supplierId, [
            'side' => 'payable', 'kind' => 'rent', 'title' => 'Syntetické nájemné spolku', 'partner_id' => $vendorId,
            'issued_on' => self::YEAR . '-07-01', 'due_on' => self::YEAR . '-07-15', 'currency' => 'CZK', 'amount' => 36600.00,
            'counter_account_code' => '518',
            'accrual_from' => self::YEAR . '-07-01', 'accrual_to' => (self::YEAR + 1) . '-06-30',
        ], $this->userId);
        $container->get(DimensionService::class)->saveDocument($this->supplierId, 'other_item', (int) $item['id'], $this->axes, []);
        $items->post($this->supplierId, (int) $item['id'], $this->userId);
        $this->db->pdo()->prepare("UPDATE accounting_periods SET status = 'closing' WHERE id = ?")->execute([$this->periodId]);

        $run = $this->closing->runPrepaidExpenseAccrual($this->supplierId, $this->periodId, $this->rv(), $this->meta());

        self::assertEqualsWithDelta(18200.00, (float) $run['total'], 0.001);
        $entry = $this->journal->findBySource($this->supplierId, 'prepaid_expense_accrual', ClosingSourceId::prepaidExpenseAccrual($this->periodId));
        self::assertNotNull($entry);
        $this->assertLineAxes((int) $entry['id'], '518', 'credit', 18200.00);
        $this->assertCounterpartAxes((int) $entry['id'], '381', $single);

        $this->periods->create($this->supplierId, self::YEAR + 1, (self::YEAR + 1) . '-01-01', (self::YEAR + 1) . '-12-31');
        $release = $this->invokePrivate('prepaidExpenseReleaseForPeriod', self::YEAR . '-12-31', (self::YEAR + 1) . '-01-01', (self::YEAR + 1) . '-12-31');
        self::assertEqualsWithDelta(18200.00, (float) $release['total'], 0.001);
        $lines = $this->invokePrivate('prepaidExpenseLines', $release['lines'], (float) $release['total'], true, (self::YEAR + 1) . '-01-01');
        $releaseId = $this->posting->postDocument($this->supplierId, 'prepaid_expense_accrual', ClosingSourceId::prepaidExpenseAccrualRelease($this->periodId), $lines, [
            'entry_date' => (self::YEAR + 1) . '-01-01', 'posted' => true, 'posted_by' => $this->userId, 'user_id' => $this->userId,
        ]);
        $this->assertLineAxes($releaseId, '518', 'debit', 18200.00);
        $this->assertCounterpartAxes($releaseId, '381', $single);
    }

    /** Řádek zápisu na účtu nese osy činnosti hlavní činnosti (bez ohledu na původ přiřazení). */
    private function assertLineAxes(int $entryId, string $accountCode, string $side, float $amount): void
    {
        $lines = $this->linesOn($entryId, $accountCode);
        self::assertCount(1, $lines);
        self::assertSame($side, $lines[0]['side']);
        self::assertEqualsWithDelta($amount, (float) $lines[0]['amount'], 0.001);
        $actual = (new DimensionAssignmentRepository($this->db))->entryLineDimensions($this->supplierId, $entryId)[(int) $lines[0]['id']] ?? [];
        ksort($actual);
        $expected = $this->axes;
        ksort($expected);
        self::assertSame($expected, $actual, 'Zápis musí nést osy činnosti řádku nákladu zápisu závazku.');
    }

    /**
     * Zaúčtovaná přijatá faktura s nákladem na 518 (osy hlavní činnosti) a obdobím přes
     * rozvahový den; rok se uzavírá a krok „Časové rozlišení nákladů" proběhne.
     *
     * @return array<string,mixed> výsledek kroku
     */
    private function postedPrepaidAndRun(bool $owned = false): array
    {
        $this->postedPrepaid($owned);
        return $this->closing->runPrepaidExpenseAccrual($this->supplierId, $this->periodId, $this->rv(), $this->meta());
    }

    /**
     * Zaúčtovaná přijatá faktura z {@see postedPrepaidAndRun()}, rok ve stavu uzavírání.
     * `$owned`: zápis nákladu jako po převodu z jiného programu (ruční zápis ve skupině
     * zaúčtování dokladu).
     */
    private function postedPrepaid(bool $owned = false): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, main_email,
                                  language, currency_default_id, is_customer, is_vendor)
             SELECT supplier_id, "Syntetický dodavatel", street, city, zip, country_id, "vendor-504@example.test",
                    language, currency_default_id, 0, 1 FROM clients WHERE id = ?'
        )->execute([$this->clientId]);
        $vendorId = (int) $pdo->lastInsertId();
        $date = self::YEAR . '-07-01';
        $pdo->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date, due_date,
                 received_at, currency_id, reverse_charge, vendor_snapshot, total_without_vat, total_vat,
                 total_with_vat, status, vat_classification_code, vat_deduction, created_by)
             VALUES (?, ?, "P-504", "invoice", ?, ?, ?, ?, ?, 0, "{}", 36600, 0, 36600, "received", "40", "none", ?)'
        )->execute([$this->supplierId, $vendorId, $date, $date, $date, $date, $this->currencyId, $this->userId]);
        $purchaseId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO purchase_invoice_items
                (purchase_invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index,
                 expense_account_code, accrual_from, accrual_to)
             VALUES (?, "Roční pojistné", 1, "ks", 36600, ?, 0, 36600, 0, 36600, 0, "518", ?, ?)'
        )->execute([$purchaseId, $this->vatRateId, $date, (self::YEAR + 1) . '-06-30']);
        $entryId = $this->posting->postDocument($this->supplierId, $owned ? 'manual' : 'purchase_invoice', $owned ? null : $purchaseId, [
            ['account_code' => '518', 'side' => 'debit', 'amount' => 36600.00, 'dimensions' => $this->axes, 'dimension_origins' => $this->origins()],
            ['account_code' => '321', 'side' => 'credit', 'amount' => 36600.00],
        ], ['entry_date' => $date, 'posted' => true, 'posted_by' => $this->userId, 'user_id' => $this->userId]);
        if ($owned) {
            (new \MyInvoice\Service\Accounting\DocumentPostingGroup($this->db))->attach($this->supplierId, 'purchase_invoice', $purchaseId, [$entryId]);
        }
        $pdo->prepare("UPDATE accounting_periods SET status = 'closing' WHERE id = ?")->execute([$this->periodId]);
    }

    private function singleForm(bool $single): void
    {
        if ($single) {
            Bootstrap::buildContainer()->get(EntryFormService::class)
                ->schedule($this->supplierId, self::YEAR . '-01-01', EntryForm::Single, $this->userId);
        }
    }

    /**
     * Protějšek 381/384: v jednoduché formě je zápis po dvojicích a nese osy výsledkového
     * řádku (párovač), ve složené formě zůstává bez dimenzí.
     */
    private function assertCounterpartAxes(int $entryId, string $accountCode, bool $single): void
    {
        $lines = $this->linesOn($entryId, $accountCode);
        self::assertCount(1, $lines);
        $actual = (new DimensionAssignmentRepository($this->db))->entryLineDimensions($this->supplierId, $entryId)[(int) $lines[0]['id']] ?? [];
        ksort($actual);
        $expected = $single ? $this->axes : [];
        ksort($expected);
        self::assertSame($expected, $actual);
    }

    /** Zaúčtovaná faktura s výnosem na 684.100 (osy hlavní činnosti) a obdobím přes rozvahový den; rok se uzavírá. */
    private function postedRevenue(string $number): void
    {
        $invoiceId = $this->invoice($number, 36600.00);
        $this->item($invoiceId, 36600.00, self::YEAR . '-07-01', (self::YEAR + 1) . '-06-30');
        $this->posting->postDocument($this->supplierId, 'invoice', $invoiceId, [
            ['account_code' => '311', 'side' => 'debit', 'amount' => 36600.00],
            ['account_code' => '684.100', 'side' => 'credit', 'amount' => 36600.00, 'dimensions' => $this->axes, 'dimension_origins' => $this->origins()],
        ], ['entry_date' => self::YEAR . '-07-01', 'posted' => true, 'posted_by' => $this->userId, 'user_id' => $this->userId]);
        $this->db->pdo()->prepare("UPDATE accounting_periods SET status = 'closing' WHERE id = ?")->execute([$this->periodId]);
    }

    private function lastRevenueLine(): int
    {
        return (int) $this->db->pdo()->query(
            "SELECT l.id FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.supplier_id = {$this->supplierId} AND a.account_code = '684.100' ORDER BY l.id DESC LIMIT 1"
        )->fetchColumn();
    }

    /** Zápis odkladu s uzavřenou hodnotou převzatou z uvedeného řádku. */
    private function postCarried(int $line): void
    {
        $this->posting->postDocument($this->supplierId, 'deferred_revenue_accrual', ClosingSourceId::deferredRevenueAccrual($this->periodId), [
            ['account_code' => '684.100', 'side' => 'debit', 'amount' => 100.00, 'dimensions' => $this->axes, 'dimension_carry_lines' => [$line]],
            ['account_code' => '384', 'side' => 'credit', 'amount' => 100.00],
        ], ['entry_date' => self::YEAR . '-12-31', 'posted' => true, 'posted_by' => $this->userId, 'allow_closing_period' => true]);
    }

    private function closeActivityKind(): void
    {
        $this->db->pdo()->prepare("UPDATE dimension_values SET is_active = 0 WHERE supplier_id = ? AND code = 'SUBS'")->execute([$this->supplierId]);
    }

    /** Řádek zápisu na účtu nese osy i jejich původ z řádku dokladu (daňové posouzení NNO čte obojí). */
    private function assertCarriesAxes(int $entryId, string $accountCode, string $side, float $amount): void
    {
        $lines = $this->linesOn($entryId, $accountCode);
        self::assertCount(1, $lines);
        self::assertSame($side, $lines[0]['side']);
        self::assertEqualsWithDelta($amount, (float) $lines[0]['amount'], 0.001);
        $repo = new DimensionAssignmentRepository($this->db);
        $lineId = (int) $lines[0]['id'];
        $actual = $repo->entryLineDimensions($this->supplierId, $entryId)[$lineId] ?? [];
        ksort($actual);
        $expected = $this->axes;
        ksort($expected);
        self::assertSame($expected, $actual, 'Zápis musí nést osy činnosti řádku, ze kterého částku přesouvá.');
        $origins = $repo->entryLineOrigins($this->supplierId, $entryId)[$lineId] ?? [];
        ksort($origins);
        self::assertSame($this->origins(), $origins, 'Zápis musí nést i původ přiřazení os.');
    }

    /** @return array<int,array{origin:string,ref:?string}> */
    private function origins(): array
    {
        $out = [];
        foreach (array_keys($this->axes) as $typeId) {
            $out[$typeId] = ['origin' => 'explicit', 'ref' => null];
        }
        ksort($out);
        return $out;
    }

    /** @return array{user_id:int, posted_by:int} */
    private function meta(): array
    {
        return ['user_id' => $this->userId, 'posted_by' => $this->userId];
    }

    private function invokePrivate(string $method, mixed ...$args): mixed
    {
        return (new \ReflectionMethod($this->closing, $method))->invoke($this->closing, $this->supplierId, ...$args);
    }

    private function invoice(string $number, float $base): int
    {
        $date = self::YEAR . '-07-01';
        $this->db->pdo()->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, total_without_vat, total_vat, total_with_vat, paid_total, status,
                 vat_classification_code, created_by)
             VALUES (?, ?, "invoice", ?, ?, ?, ?, ?, 0, ?, 0, ?, 0, "sent", "1", ?)'
        )->execute([$this->supplierId, $number, $this->clientId, $date, $date, $date, $this->currencyId, $base, $base, $this->userId]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function item(int $invoiceId, float $base, string $from, string $to): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO invoice_items
                (invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index,
                 accrual_from, accrual_to)
             VALUES (?, "Roční příspěvek", 1, "ks", ?, ?, 0, ?, 0, ?, 0, ?, ?)'
        )->execute([$invoiceId, $base, $this->vatRateId, $base, $base, $from, $to]);
    }

    private function rv(): int
    {
        return (int) $this->periods->findById($this->supplierId, $this->periodId)['row_version'];
    }

    /** @return list<array{id:int|string, side:string, amount:string}> */
    private function linesOn(int $entryId, string $accountCode): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT l.id, l.side, l.amount
               FROM journal_entry_lines l
               JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.supplier_id = ? AND l.entry_id = ? AND a.account_code = ?'
        );
        $stmt->execute([$this->supplierId, $entryId, $accountCode]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }
}
