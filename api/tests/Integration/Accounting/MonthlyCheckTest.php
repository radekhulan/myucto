<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\AssetRepository;
use MyInvoice\Repository\ClosingRepository;
use MyInvoice\Repository\SmallAssetRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Closing\CheckFindingNormalizer;
use MyInvoice\Service\Accounting\Closing\ClosingException;
use MyInvoice\Service\Accounting\Closing\ClosingService;
use MyInvoice\Service\Accounting\PostingService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Měsíční kontrola (audit 2026-07, D8): ClosingService::buildChecks nad
 * libovolným rozsahem uvnitř období, KDYKOLI, bez zahájení uzávěrky — a nové
 * kontroly (neobvyklá strana zůstatku, 0xx bez oprávek, 111/131, saldo 343).
 *
 * IZOLOVANÝ SUPPLIER (vzor ClosingWorkflowTest) — vlastní transakce, rollback
 * v tearDown. Soft-skip bez cfg.php / DB dat.
 */
#[Group('integration')]
final class MonthlyCheckTest extends TestCase
{
    private const YEAR = 2097;
    private const STARTS_ON = self::YEAR . '-01-01';
    private const ENDS_ON = self::YEAR . '-12-31';

    private Connection $db;
    private PostingService $posting;
    private ClosingService $closing;
    private ClosingRepository $closingRepository;
    private AccountingPeriodRepository $periods;
    private AssetRepository $assets;
    private SmallAssetRepository $smallAssets;

    private int $supplierId = 0;
    private int $userId = 0;
    private int $periodId = 0;
    private bool $inTx = false;
    private \MyInvoice\Service\Accounting\InvoiceSettlementService $settlements;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db      = $container->get(Connection::class);
            $this->posting = $container->get(PostingService::class);
            $this->closing = $container->get(ClosingService::class);
            $this->closingRepository = $container->get(ClosingRepository::class);
            $this->periods = $container->get(AccountingPeriodRepository::class);
            $this->assets  = $container->get(AssetRepository::class);
            $this->smallAssets = $container->get(SmallAssetRepository::class);
            $this->settlements = $container->get(\MyInvoice\Service\Accounting\InvoiceSettlementService::class);
            $seeder        = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $currencyId   = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $vatRateId    = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $czId         = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->userId === 0 || $currencyId === 0 || $vatRateId === 0 || $czId === 0) {
            $this->markTestSkipped('Chybí základní data (user/currency/vat_rate/country) v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        $stmt = $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id, accounting_mode)
             VALUES ("D8 měsíční kontrola s.r.o.", "Testovací 1", "Praha", "11000", ?, "d8-monthly-check@example.com", ?, ?, "double_entry")'
        );
        $stmt->execute([$czId, $currencyId, $vatRateId]);
        $this->supplierId = (int) $pdo->lastInsertId();

        $seeder->seedForSupplier($this->supplierId);
        $this->periodId = $this->periods->create($this->supplierId, self::YEAR, self::STARTS_ON, self::ENDS_ON);
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

    public function testRangeOutsidePeriodIsRejected(): void
    {
        $this->expectException(ClosingException::class);
        $this->closing->monthlyCheck($this->supplierId, $this->periodId, self::YEAR - 1 . '-12-01', self::YEAR . '-01-31');
    }

    public function testFromAfterToIsRejected(): void
    {
        $this->expectException(ClosingException::class);
        $this->closing->monthlyCheck($this->supplierId, $this->periodId, self::YEAR . '-03-01', self::YEAR . '-02-01');
    }

    public function testDefaultRangeIsWholePeriodAndDoesNotMutateStatus(): void
    {
        $result = $this->closing->monthlyCheck($this->supplierId, $this->periodId, null, null);

        self::assertSame(self::STARTS_ON, $result['range_from']);
        self::assertSame(self::ENDS_ON, $result['range_to']);
        self::assertArrayHasKey('checks', $result);
        self::assertNotEmpty($result['checks']);

        // Read-only: stav období se nezměnil, žádný krok 'precheck' se nezapsal.
        $period = $this->periods->findById($this->supplierId, $this->periodId);
        self::assertSame('open', $period['status']);
    }

    public function testFlagsUnusualSideAndProcurementOpenBalances(): void
    {
        $entryDate = self::YEAR . '-03-15';

        // Přeplatek na 311 (pohledávka v kreditu — neobvyklá strana pro asset/normal_side=debit).
        $this->posting->postDocument($this->supplierId, 'manual', null, [
            ['account_code' => '221', 'side' => 'debit', 'amount' => 100],
            ['account_code' => '311', 'side' => 'credit', 'amount' => 100],
        ], ['entry_date' => $entryDate, 'posted_by' => $this->userId, 'user_id' => $this->userId]);

        // Nedočerpaná záloha na pořízení materiálu (111 otevřený zůstatek).
        $this->posting->postDocument($this->supplierId, 'manual', null, [
            ['account_code' => '111', 'side' => 'debit', 'amount' => 5000],
            ['account_code' => '321', 'side' => 'credit', 'amount' => 5000],
        ], ['entry_date' => $entryDate, 'posted_by' => $this->userId, 'user_id' => $this->userId]);

        $result = $this->closing->monthlyCheck($this->supplierId, $this->periodId, self::YEAR . '-03-01', self::YEAR . '-03-31');
        $byKey = [];
        foreach ($result['checks'] as $c) {
            $byKey[$c['key']] = $c;
        }

        self::assertFalse($byKey['accounts_unusual_side']['ok']);
        $codes = array_column($byKey['accounts_unusual_side']['value']['findings'], 'account_code');
        self::assertContains('311', $codes);

        self::assertFalse($byKey['procurement_111_131_open']['ok']);
        self::assertSame(5000.0, $byKey['procurement_111_131_open']['value']['111']);
        self::assertSame(0.0, $byKey['procurement_111_131_open']['value']['131']);
    }

    public function testBatchAccountBalancesMatchIndividualBalancesIncludingAnalyticsAndDate(): void
    {
        $pdo = $this->db->pdo();
        $parent = $pdo->prepare('SELECT id FROM chart_of_accounts WHERE supplier_id = ? AND account_code = ?');
        $parent->execute([$this->supplierId, '221']);
        $parentId = (int) $parent->fetchColumn();
        self::assertGreaterThan(0, $parentId);
        $pdo->prepare(
            'INSERT INTO chart_of_accounts
                (supplier_id, account_code, name, account_type, normal_side, is_synthetic, parent_id, is_active)
             VALUES (?, ?, ?, ?, ?, 0, ?, 1)'
        )->execute([$this->supplierId, '221.731', 'Testovací analytika banky', 'asset', 'debit', $parentId]);
        $pdo->prepare(
            'INSERT INTO chart_of_accounts
                (supplier_id, account_code, name, account_type, normal_side, is_synthetic, parent_id, is_active)
             VALUES (?, ?, ?, ?, ?, 0, ?, 1)'
        )->execute([$this->supplierId, '888.731', 'Test vazby přes rodičovský účet', 'asset', 'debit', $parentId]);

        $this->posting->postDocument($this->supplierId, 'manual', null, [
            ['account_code' => '221.731', 'side' => 'debit', 'amount' => 100],
            ['account_code' => '602', 'side' => 'credit', 'amount' => 100],
        ], ['entry_date' => self::YEAR . '-03-10', 'posted_by' => $this->userId, 'user_id' => $this->userId]);
        $this->posting->postDocument($this->supplierId, 'manual', null, [
            ['account_code' => '888.731', 'side' => 'debit', 'amount' => 25],
            ['account_code' => '602', 'side' => 'credit', 'amount' => 25],
        ], ['entry_date' => self::YEAR . '-03-11', 'posted_by' => $this->userId, 'user_id' => $this->userId]);
        $this->posting->postDocument($this->supplierId, 'manual', null, [
            ['account_code' => '221.731', 'side' => 'debit', 'amount' => 20, 'is_red_storno' => true],
            ['account_code' => '602', 'side' => 'credit', 'amount' => 20, 'is_red_storno' => true],
        ], ['entry_date' => self::YEAR . '-03-12', 'posted_by' => $this->userId, 'user_id' => $this->userId]);
        $this->posting->postDocument($this->supplierId, 'manual', null, [
            ['account_code' => '381', 'side' => 'debit', 'amount' => 50],
            ['account_code' => '221.731', 'side' => 'credit', 'amount' => 50],
        ], ['entry_date' => self::YEAR . '-04-10', 'posted_by' => $this->userId, 'user_id' => $this->userId]);

        $codes = ['221', '221.731', '888.731', '381', '999', '221'];
        foreach ([
            self::YEAR . '-03-31' => [105.0, 80.0, 25.0, 0.0, 0.0],
            self::YEAR . '-04-30' => [55.0, 30.0, 25.0, 50.0, 0.0],
        ] as $asOf => $expected) {
            $batched = $this->closingRepository->accountBalances($this->supplierId, $codes, $asOf);
            self::assertSame([221, '221.731', '888.731', 381, 999], array_keys($batched));
            self::assertSame($expected, array_values($batched));
            foreach (array_unique($codes) as $code) {
                self::assertSame(
                    $this->closingRepository->accountBalance($this->supplierId, $code, $asOf),
                    $batched[$code],
                    "Neshodný zůstatek účtu {$code} k {$asOf}.",
                );
            }
        }
    }

    public function testFlagsAssetWithoutAccumulatedDepreciation(): void
    {
        $entryDate = self::YEAR . '-04-10';

        $assetId = $this->assets->insert($this->supplierId, [
            'inventory_number' => 'D8-TEST-1',
            'name' => 'Test stroj bez oprávek',
            'kind' => 'tangible',
            'asset_account_code' => '022',
            'accumulated_account_code' => '082',
            'acquisition_account_code' => '042',
            'input_price' => 60000,
            'acquisition_date' => $entryDate,
            'put_into_use_date' => $entryDate,
            'status' => 'in_use',
        ]);

        // Majetek na 022 zaúčtovaný, ale BEZ zápisu na 082 (chybějící oprávky).
        $this->posting->postDocument($this->supplierId, 'manual', null, [
            ['account_code' => '022', 'side' => 'debit', 'amount' => 60000],
            ['account_code' => '321', 'side' => 'credit', 'amount' => 60000],
        ], ['entry_date' => $entryDate, 'posted_by' => $this->userId, 'user_id' => $this->userId]);

        $result = $this->closing->monthlyCheck($this->supplierId, $this->periodId, self::YEAR . '-04-01', self::YEAR . '-04-30');
        $byKey = [];
        foreach ($result['checks'] as $c) {
            $byKey[$c['key']] = $c;
        }

        self::assertFalse($byKey['assets_without_accumulated_depreciation']['ok']);
        // Skupiny se rozbalují na jeden nález per KARTA — id karty je to jediné, co
        // uživateli umožní ji najít, takže se nesmí ztratit ve vnořené struktuře.
        $findings = $byKey['assets_without_accumulated_depreciation']['value']['findings'];
        self::assertNotEmpty($findings);
        self::assertContains($assetId, array_column($findings, 'doc_id'));
        self::assertSame('asset', $findings[0]['doc_type']);

        // Počet musí sedět s tabulkou: kontrola dřív vracela SKUPINY dvojic účtů, takže
        // hlásila „1 nález" a v detailu se rozbalilo tolik řádků, kolik měla skupina karet.
        self::assertCount(
            $byKey['assets_without_accumulated_depreciation']['value']['count'],
            $findings,
            'Nález je karta majetku — hlášený počet musí odpovídat počtu řádků.',
        );

        $card = array_column($findings, null, 'doc_id')[$assetId];
        self::assertNotNull($card['doc_date'], 'Bez data zařazení nejde karta zařadit v čase.');
        self::assertNotNull($card['amount'], 'Vstupní cena je to, o jakou částku bez oprávek jde.');
    }

    public function testVat343CheckIsInfoOnlyForNonCalendarRange(): void
    {
        $result = $this->closing->monthlyCheck($this->supplierId, $this->periodId, self::YEAR . '-05-05', self::YEAR . '-05-20');
        $byKey = [];
        foreach ($result['checks'] as $c) {
            $byKey[$c['key']] = $c;
        }

        self::assertSame('info', $byKey['vat_343_vs_return']['severity']);
        self::assertTrue($byKey['vat_343_vs_return']['ok']);
    }

    public function testVat343CheckRunsForFullCalendarMonthWithoutError(): void
    {
        // Bez DPH aktivity v měsíci — kontrola nesmí spadnout, jen nic nenajde.
        $result = $this->closing->monthlyCheck($this->supplierId, $this->periodId, self::YEAR . '-06-01', self::YEAR . '-06-30');
        $byKey = [];
        foreach ($result['checks'] as $c) {
            $byKey[$c['key']] = $c;
        }

        self::assertArrayHasKey('vat_343_vs_return', $byKey);
        self::assertTrue($byKey['vat_343_vs_return']['ok']);
    }

    /**
     * Regrese F1 (adversariální review D8): buildErrorChecks (prior_period_open,
     * pl_balance_before_period, journal_unbalanced) MUSÍ zůstat vázané na CELÝ
     * fiskální rok bez ohledu na zvolený rozsah — jinak by měsíční kontrola nad
     * úzkým rozsahem mohla tiše ukázat "OK" na celoroční chybu, kterou by roční
     * precheck odhalil. Scénář: předchozí období zůstane 'open' (neuzavřené) →
     * prior_period_open musí selhat i při kontrole úzkého jednoho měsíce.
     */
    public function testErrorChecksStayWholeYearRegardlessOfNarrowRange(): void
    {
        $prevYear = self::YEAR - 1;
        $this->periods->create($this->supplierId, $prevYear, $prevYear . '-01-01', $prevYear . '-12-31');
        // status zůstává default 'open' — prior_period_open musí selhat.

        $result = $this->closing->monthlyCheck($this->supplierId, $this->periodId, self::YEAR . '-06-01', self::YEAR . '-06-30');
        $byKey = [];
        foreach ($result['checks'] as $c) {
            $byKey[$c['key']] = $c;
        }

        self::assertArrayHasKey('prior_period_open', $byKey);
        self::assertFalse(
            $byKey['prior_period_open']['ok'],
            'Neuzavřené předchozí období musí selhat v error-checks i pro úzký měsíční rozsah — buildErrorChecks nesmí být scoped na range.',
        );
        self::assertSame('error', $byKey['prior_period_open']['severity']);
    }

    /**
     * K3: faktura se statusem 'paid', jejíž úhrada NENÍ v deníku (žádný bankovní/
     * pokladní zápis na 311) → musí se objevit v kontrole paid_invoices_open_saldo
     * s otevřeným saldem předpisu. Naopak faktura se zaúčtovanou bankovní úhradou
     * (221 MD / 311 D) se hlásit NESMÍ.
     */
    public function testFlagsPaidInvoiceWhoseSettlementIsMissingInJournal(): void
    {
        $pdo = $this->db->pdo();
        $clientId = $this->createClient();
        $entryDate = self::YEAR . '-06-10';

        // 1) FV zaplacená jen "statusem" — předpis v deníku, úhrada nikde.
        $ghostId = $this->createPaidInvoice($clientId, 'K3-GHOST-001', $entryDate);
        $this->posting->postDocument($this->supplierId, 'invoice', $ghostId, [
            ['account_code' => '311', 'side' => 'debit', 'amount' => 1210],
            ['account_code' => '602', 'side' => 'credit', 'amount' => 1000],
            ['account_code' => '343', 'side' => 'credit', 'amount' => 210],
        ], ['entry_date' => $entryDate, 'posted_by' => $this->userId, 'user_id' => $this->userId]);

        // 2) Kontrolní FV se zaúčtovanou bankovní úhradou — nesmí být hlášená.
        $settledId = $this->createPaidInvoice($clientId, 'K3-OK-002', $entryDate);
        $this->posting->postDocument($this->supplierId, 'invoice', $settledId, [
            ['account_code' => '311', 'side' => 'debit', 'amount' => 1210],
            ['account_code' => '602', 'side' => 'credit', 'amount' => 1000],
            ['account_code' => '343', 'side' => 'credit', 'amount' => 210],
        ], ['entry_date' => $entryDate, 'posted_by' => $this->userId, 'user_id' => $this->userId]);

        $stmt = $pdo->prepare(
            'INSERT INTO bank_statements (supplier_id, file_name, file_hash, account_number, statement_date)
             VALUES (?, "k3-test.gpc", ?, "123456789/0100", ?)'
        );
        $stmt->execute([$this->supplierId, hash('sha256', 'k3-monthly-check-' . microtime(true)), $entryDate]);
        $statementId = (int) $pdo->lastInsertId();

        $stmt = $pdo->prepare(
            'INSERT INTO bank_transactions (statement_id, posted_at, amount, currency, variable_symbol)
             VALUES (?, ?, 1210, "CZK", "K3OK002")'
        );
        $stmt->execute([$statementId, $entryDate]);
        $txId = (int) $pdo->lastInsertId();

        $stmt = $pdo->prepare(
            'INSERT INTO invoice_payments (supplier_id, invoice_id, paid_on, amount, currency, source, bank_transaction_id)
             VALUES (?, ?, ?, 1210, "CZK", "bank", ?)'
        );
        $stmt->execute([$this->supplierId, $settledId, $entryDate, $txId]);

        $this->posting->postDocument($this->supplierId, 'bank', $txId, [
            ['account_code' => '221', 'side' => 'debit', 'amount' => 1210],
            ['account_code' => '311', 'side' => 'credit', 'amount' => 1210],
        ], ['entry_date' => $entryDate, 'posted_by' => $this->userId, 'user_id' => $this->userId]);

        $result = $this->closing->monthlyCheck($this->supplierId, $this->periodId, self::YEAR . '-06-01', self::YEAR . '-06-30');
        $byKey = [];
        foreach ($result['checks'] as $c) {
            $byKey[$c['key']] = $c;
        }

        self::assertArrayHasKey('paid_invoices_open_saldo', $byKey);
        self::assertFalse($byKey['paid_invoices_open_saldo']['ok']);
        $items = $byKey['paid_invoices_open_saldo']['value']['findings'];
        $byId = array_column($items, null, 'doc_id');
        self::assertArrayHasKey($ghostId, $byId, 'Zaplacená FV bez úhrady v deníku musí být v nálezu.');
        self::assertSame(1210.0, $byId[$ghostId]['amount']);
        self::assertArrayNotHasKey($settledId, $byId, 'FV se zaúčtovanou bankovní úhradou (221/311) se hlásit nesmí.');
    }

    /**
     * Regrese (faktura #71 z reálných dat): úhrada spárovaná JEN přes
     * bank_transactions.matched_invoice_id (bez invoice_payments vazby — typicky
     * legacy import / ruční match cizoměnové platby, CZK faktura placená z EUR účtu).
     * 311 je v deníku vynulované, takže kontrola ji hlásit NESMÍ.
     */
    public function testDoesNotFlagInvoiceSettledOnlyViaBankMatchedInvoiceId(): void
    {
        $pdo = $this->db->pdo();
        $clientId = $this->createClient();
        $entryDate = self::YEAR . '-06-15';

        $invId = $this->createPaidInvoice($clientId, 'K3-MATCHED-003', $entryDate);
        $this->posting->postDocument($this->supplierId, 'invoice', $invId, [
            ['account_code' => '311', 'side' => 'debit', 'amount' => 1210],
            ['account_code' => '602', 'side' => 'credit', 'amount' => 1000],
            ['account_code' => '343', 'side' => 'credit', 'amount' => 210],
        ], ['entry_date' => $entryDate, 'posted_by' => $this->userId, 'user_id' => $this->userId]);

        $stmt = $pdo->prepare(
            'INSERT INTO bank_statements (supplier_id, file_name, file_hash, account_number, statement_date)
             VALUES (?, "k3-matched.gpc", ?, "123456789/0100", ?)'
        );
        $stmt->execute([$this->supplierId, hash('sha256', 'k3-matched-' . microtime(true)), $entryDate]);
        $statementId = (int) $pdo->lastInsertId();

        // Bankovní pohyb spárovaný přes matched_invoice_id, BEZ invoice_payments řádku.
        $stmt = $pdo->prepare(
            'INSERT INTO bank_transactions (statement_id, posted_at, amount, currency, variable_symbol, matched_invoice_id, match_status)
             VALUES (?, ?, 1210, "CZK", "K3M003", ?, "manual")'
        );
        $stmt->execute([$statementId, $entryDate, $invId]);
        $txId = (int) $pdo->lastInsertId();

        $this->posting->postDocument($this->supplierId, 'bank', $txId, [
            ['account_code' => '221', 'side' => 'debit', 'amount' => 1210],
            ['account_code' => '311', 'side' => 'credit', 'amount' => 1210],
        ], ['entry_date' => $entryDate, 'posted_by' => $this->userId, 'user_id' => $this->userId]);

        $result = $this->closing->monthlyCheck($this->supplierId, $this->periodId, self::YEAR . '-06-01', self::YEAR . '-06-30');
        $byKey = [];
        foreach ($result['checks'] as $c) {
            $byKey[$c['key']] = $c;
        }
        $items = $byKey['paid_invoices_open_saldo']['value']['findings'] ?? [];
        $byId = array_column($items, null, 'doc_id');
        self::assertArrayNotHasKey($invId, $byId, 'Úhrada spárovaná přes matched_invoice_id musí být uznaná — 311 vynulované.');
    }

    /**
     * K3 zrcadlově pro přijaté faktury: PF se statusem 'paid' bez úhradového
     * zápisu na 321 v deníku → paid_purchases_open_saldo ji musí nahlásit.
     */
    public function testFlagsPaidPurchaseWhoseSettlementIsMissingInJournal(): void
    {
        $vendorId = $this->createClient();
        $entryDate = self::YEAR . '-06-12';

        $pfId = $this->createPaidPurchase($vendorId, 'K3-PF-001', $entryDate);
        $this->posting->postDocument($this->supplierId, 'purchase_invoice', $pfId, [
            ['account_code' => '518', 'side' => 'debit', 'amount' => 1000],
            ['account_code' => '343', 'side' => 'debit', 'amount' => 210],
            ['account_code' => '321', 'side' => 'credit', 'amount' => 1210],
        ], ['entry_date' => $entryDate, 'posted_by' => $this->userId, 'user_id' => $this->userId]);

        $result = $this->closing->monthlyCheck($this->supplierId, $this->periodId, self::YEAR . '-06-01', self::YEAR . '-06-30');
        $byKey = [];
        foreach ($result['checks'] as $c) {
            $byKey[$c['key']] = $c;
        }

        self::assertArrayHasKey('paid_purchases_open_saldo', $byKey);
        self::assertFalse($byKey['paid_purchases_open_saldo']['ok']);
        $items = $byKey['paid_purchases_open_saldo']['value']['findings'];
        $byId = array_column($items, null, 'doc_id');
        self::assertArrayHasKey($pfId, $byId, 'Zaplacená PF bez úhrady v deníku musí být v nálezu.');
        self::assertSame(1210.0, $byId[$pfId]['amount']);
    }

    /**
     * ZÁLOHA UHRAZENÁ PŘÍMO NA SALDOKONTNÍ ÚČET (bez 314) není otevřené saldo.
     *
     * Reálný nález: dodavatel poslal zálohovou fakturu a po ní konečnou. Výpis se spároval
     * se ZÁLOHOU — variabilní symbol nesla ona — kdežto předpis na 321 má konečná faktura.
     * Kontrola hledala úhradu jen pod `payment_matches.purchase_invoice_id` konečné faktury,
     * kde nikdy nebyla, a hlásila plně předplacený doklad jako otevřené saldo v plné výši.
     * V hlavní knize se přitom obojí potkalo na 321 a vyrušilo se na nulu.
     *
     * Přepárovat výpis na konečnou fakturu by šlo proti tomu, co je na výpisu — a v uzavřeném
     * období by to ani nešlo. Kontrola tedy musí umět přečíst vazbu `advance_purchase_invoice_id`.
     */
    public function testAdvancePaidOnSaldoAccountSettlesFinalPurchaseInvoice(): void
    {
        $vendorId = $this->createClient();
        $entryDate = self::YEAR . '-06-16';

        $advanceId = $this->createAdvancePurchase($vendorId, 'K3-PF-ADV', $entryDate);
        $finalId = $this->createPaidPurchase($vendorId, 'K3-PF-FINAL', $entryDate);
        $this->db->pdo()
            ->prepare('UPDATE purchase_invoices SET advance_purchase_invoice_id = ? WHERE id = ?')
            ->execute([$advanceId, $finalId]);

        // Předpis visí na konečné faktuře, ...
        $this->posting->postDocument($this->supplierId, 'purchase_invoice', $finalId, [
            ['account_code' => '518', 'side' => 'debit', 'amount' => 1000],
            ['account_code' => '343', 'side' => 'debit', 'amount' => 210],
            ['account_code' => '321', 'side' => 'credit', 'amount' => 1210],
        ], ['entry_date' => $entryDate, 'posted_by' => $this->userId, 'user_id' => $this->userId]);

        // ... zatímco úhrada (321 MD / 221 D) se páruje se zálohou.
        $this->bookPurchaseBankSettlement($advanceId, 1210.0, $entryDate);

        $byId = $this->paidPurchaseFindingsById();
        self::assertArrayNotHasKey(
            $finalId,
            $byId,
            'Konečná faktura krytá zálohou uhrazenou na 321 není otevřené saldo.'
        );
    }

    /**
     * Zrcadlo předchozího testu na kontrole `paid_advances_no_payment`.
     *
     * Ta se ptá „je zaplacená záloha vidět v deníku?" a uznávala jedinou odpověď: debet
     * na 314. Záloha zaúčtovaná rovnou na saldokontní 321 je ale legitimní varianta —
     * deník o penězích ví, jen jinou nohou — a hlásit ji jako nezaúčtovanou je falešný
     * poplach. Zároveň nález musí nést DATUM: bez něj zůstával v detailu kontroly prázdný
     * sloupec a účetní musela každý řádek dohledávat ručně.
     */
    public function testAdvancePaidOntoSaldoAccountIsNotReportedAsUnbooked(): void
    {
        $vendorId = $this->createClient();
        $entryDate = self::YEAR . '-06-18';

        // Záloha uhrazená na 321 — do nálezů nepatří.
        $onSaldo = $this->createAdvancePurchase($vendorId, 'K3-ADV-321', $entryDate);
        $this->bookPurchaseBankSettlement($onSaldo, 1210.0, $entryDate);

        // Záloha označená jako zaplacená bez jakéhokoli zápisu — pravý nález.
        $unbooked = $this->createAdvancePurchase($vendorId, 'K3-ADV-NIC', $entryDate);

        $check = $this->checkByKey('paid_advances_no_payment');
        $byId = array_column($check['value']['findings'], null, 'doc_id');

        self::assertArrayNotHasKey(
            $onSaldo,
            $byId,
            'Záloha uhrazená na 321 je v deníku vidět — nález být nesmí.'
        );
        self::assertArrayHasKey(
            $unbooked,
            $byId,
            'Záloha bez jakéhokoli zápisu zůstává nálezem.'
        );
        self::assertSame(
            $entryDate,
            $byId[$unbooked]['doc_date'],
            'Nález musí nést datum, jinak zůstane sloupec Datum prázdný.'
        );
    }

    /**
     * Faktura vyrovnaná NAVÁZANÝM DOBROPISEM není otevřené saldo. Opravný doklad nese na
     * 321 opačné znaménko, takže dvojice účet vynuluje i bez pohybu peněz (vrácené zboží
     * prostě sníží závazek) — dřív kontrola hlásila obě strany dvojice, každou v plné výši.
     */
    public function testCreditNoteSettlesParentInvoiceWithoutMoney(): void
    {
        $vendorId = $this->createClient();
        $entryDate = self::YEAR . '-06-14';

        $pfId = $this->createPaidPurchase($vendorId, 'K3-PF-CN-PARENT', $entryDate);
        $this->posting->postDocument($this->supplierId, 'purchase_invoice', $pfId, [
            ['account_code' => '518', 'side' => 'debit', 'amount' => 1000],
            ['account_code' => '343', 'side' => 'debit', 'amount' => 210],
            ['account_code' => '321', 'side' => 'credit', 'amount' => 1210],
        ], ['entry_date' => $entryDate, 'posted_by' => $this->userId, 'user_id' => $this->userId]);

        $cnId = $this->createPaidPurchase($vendorId, 'K3-PF-CN-CHILD', $entryDate);
        $this->db->pdo()->prepare(
            'UPDATE purchase_invoices
                SET document_kind = "credit_note", parent_purchase_invoice_id = ?,
                    total_without_vat = -1000, total_vat = -210, total_with_vat = -1210
              WHERE id = ?'
        )->execute([$pfId, $cnId]);
        $this->posting->postDocument($this->supplierId, 'purchase_invoice', $cnId, [
            ['account_code' => '321', 'side' => 'debit', 'amount' => 1210],
            ['account_code' => '518', 'side' => 'credit', 'amount' => 1000],
            ['account_code' => '343', 'side' => 'credit', 'amount' => 210],
        ], ['entry_date' => $entryDate, 'posted_by' => $this->userId, 'user_id' => $this->userId]);

        $byId = $this->paidPurchaseFindingsById();

        self::assertArrayNotHasKey($pfId, $byId, 'Faktura vyrovnaná dobropisem není otevřené saldo.');
        self::assertArrayNotHasKey($cnId, $byId, 'Ani dobropis sám — jeho protistranou je právě ta faktura.');
    }

    /**
     * Haléřový rozdíl z platby (banka pošle zaokrouhlenou částku) není chybějící úhrada.
     * Tolerance je 1 Kč; rozdíl nad ni se hlásit musí dál.
     */
    public function testSubKorunaRoundingDifferenceIsTolerated(): void
    {
        $vendorId = $this->createClient();
        $entryDate = self::YEAR . '-06-16';

        $pfId = $this->createPaidPurchase($vendorId, 'K3-PF-ROUND', $entryDate);
        $this->posting->postDocument($this->supplierId, 'purchase_invoice', $pfId, [
            ['account_code' => '518', 'side' => 'debit', 'amount' => 1000],
            ['account_code' => '343', 'side' => 'debit', 'amount' => 210],
            ['account_code' => '321', 'side' => 'credit', 'amount' => 1210],
        ], ['entry_date' => $entryDate, 'posted_by' => $this->userId, 'user_id' => $this->userId]);
        // Banka poslala 1 209,20 — o 80 haléřů míň, než je předpis (stará tolerance
        // 0,50 Kč takový rozdíl ještě hlásila).
        $this->bookPurchaseBankSettlement($pfId, 1209.20, $entryDate);

        self::assertArrayNotHasKey($pfId, $this->paidPurchaseFindingsById(),
            'Rozdíl 0,80 Kč je zaokrouhlení úhrady, ne chybějící platba.');
    }

    /**
     * Doklad označený jako uhrazený ručně (typicky po zápočtu) se pořád hlásí — deník
     * o úhradě neví —, ale dostane vlastní kód nálezu, ať ho účetní odliší od dokladu,
     * kde úhrada existuje a jen nesedí částka.
     */
    public function testManuallyPaidPurchaseCarriesItsOwnIssueCode(): void
    {
        $vendorId = $this->createClient();
        $entryDate = self::YEAR . '-06-18';

        $pfId = $this->createPaidPurchase($vendorId, 'K3-PF-MANUAL', $entryDate);
        $this->posting->postDocument($this->supplierId, 'purchase_invoice', $pfId, [
            ['account_code' => '518', 'side' => 'debit', 'amount' => 1000],
            ['account_code' => '343', 'side' => 'debit', 'amount' => 210],
            ['account_code' => '321', 'side' => 'credit', 'amount' => 1210],
        ], ['entry_date' => $entryDate, 'posted_by' => $this->userId, 'user_id' => $this->userId]);

        $byId = $this->paidPurchaseFindingsById();

        self::assertArrayHasKey($pfId, $byId);
        self::assertSame(['marked_paid_unposted'], $byId[$pfId]['issues'] ?? null);
    }

    /**
     * Faktura uhrazená ZÁPOČTEM proti účtu (321 MD / zvolený účet D) není otevřené saldo.
     * Kontrola dosud znala jen banku a pokladnu, takže třetí zaúčtovaný kanál úhrady
     * hlásila jako díru v deníku — a to v plné výši dokladu.
     */
    public function testAccountSettlementCountsAsPaidOnSaldoCheck(): void
    {
        $vendorId = $this->createClient();
        $entryDate = self::YEAR . '-06-20';

        $pfId = $this->createPaidPurchase($vendorId, 'K3-PF-SETTLE', $entryDate);
        $this->db->pdo()->prepare('UPDATE purchase_invoices SET status = "received", paid_at = NULL WHERE id = ?')
            ->execute([$pfId]);
        $this->posting->postDocument($this->supplierId, 'purchase_invoice', $pfId, [
            ['account_code' => '518', 'side' => 'debit', 'amount' => 1000],
            ['account_code' => '343', 'side' => 'debit', 'amount' => 210],
            ['account_code' => '321', 'side' => 'credit', 'amount' => 1210],
        ], ['entry_date' => $entryDate, 'posted_by' => $this->userId, 'user_id' => $this->userId]);

        $account = $this->db->pdo()->prepare(
            'SELECT id FROM chart_of_accounts WHERE supplier_id = ? AND account_code LIKE "365%" ORDER BY id LIMIT 1'
        );
        $account->execute([$this->supplierId]);
        $accountId = (int) ($account->fetchColumn() ?: 0);
        if ($accountId === 0) {
            self::markTestSkipped('Účet 365 není v osnově tenanta.');
        }

        $this->settlements->create($this->supplierId, 'purchase_invoice', $pfId, [
            'settled_on' => $entryDate, 'amount' => 1210.00, 'account_id' => $accountId,
        ], $this->userId);

        self::assertSame('paid', (string) $this->purchaseStatusOf($pfId), 'Plný zápočet doklad uzavře.');
        self::assertArrayNotHasKey($pfId, $this->paidPurchaseFindingsById(),
            'Doklad uhrazený zápočtem nesmí kontrola hlásit jako otevřené saldo.');
    }

    /**
     * Inkaso platební bránou: GoPay nemá k JEDNÉ faktuře bankovní pohyb (do banky
     * přijde až souhrnná výplata za vyúčtování), takže úhrada visí jen na pohybu
     * vyúčtování a jeho zápisu `source_type='gopay'` (221.x MD / 311.x D).
     * Kontrola ji musí uznat jako vyrovnání 311 — jinak hlásí každou fakturu
     * placenou kartou jako „uhrazeno ručně, úhrada není zaúčtovaná".
     */
    public function testGoPayClearingSettlesInvoiceOnSaldoCheck(): void
    {
        $pdo = $this->db->pdo();
        $clientId = $this->createClient();
        $entryDate = self::YEAR . '-06-12';

        $invoiceId = $this->createPaidInvoice($clientId, 'K3-GOPAY-001', $entryDate);
        $this->posting->postDocument($this->supplierId, 'invoice', $invoiceId, [
            ['account_code' => '311', 'side' => 'debit', 'amount' => 1210],
            ['account_code' => '602', 'side' => 'credit', 'amount' => 1000],
            ['account_code' => '343', 'side' => 'credit', 'amount' => 210],
        ], ['entry_date' => $entryDate, 'posted_by' => $this->userId, 'user_id' => $this->userId]);

        // Úhrada evidovaná jen u dokladu (`source='mark_paid'`, bez bank_transaction_id) —
        // přesně to, co zapíše GoPay platební tlačítko.
        $pdo->prepare(
            'INSERT INTO invoice_payments (supplier_id, invoice_id, paid_on, amount, currency, source, bank_reference)
             VALUES (?, ?, ?, 1210, "CZK", "mark_paid", "GOPAY:9000000001")'
        )->execute([$this->supplierId, $invoiceId, $entryDate]);
        $paymentId = (int) $pdo->lastInsertId();

        $movementId = $this->gopayMovement($invoiceId, $paymentId, 1210.00, $entryDate);
        $this->posting->postDocument($this->supplierId, 'gopay', $movementId, [
            ['account_code' => '221', 'side' => 'debit', 'amount' => 1210],
            ['account_code' => '311', 'side' => 'credit', 'amount' => 1210],
        ], ['entry_date' => $entryDate, 'posted_by' => $this->userId, 'user_id' => $this->userId]);

        $findings = $this->checkByKey('paid_invoices_open_saldo')['value']['findings'] ?? [];
        self::assertArrayNotHasKey(
            $invoiceId,
            array_column($findings, null, 'doc_id'),
            'Faktura vyrovnaná zápisem GoPay vyúčtování nesmí být hlášená jako otevřené saldo na 311.',
        );
    }

    /** Pohyb GoPay vyúčtování navázaný na fakturu (minimální clearing + movement). */
    private function gopayMovement(int $invoiceId, int $paymentId, float $amount, string $performedOn): int
    {
        $pdo = $this->db->pdo();
        $hash = hash('sha256', 'gopay-monthly-check-' . $invoiceId . '-' . microtime(true));
        $pdo->prepare(
            'INSERT INTO gopay_clearings
                (supplier_id, clearing_id, account_name, currency, variable_symbol, cleared_from, cleared_to,
                 performed_on, amount_gross, amount_transfer, amount_sent, file_name, file_hash, file_content)
             VALUES (?, ?, "Test", "CZK", "1", ?, ?, ?, ?, ?, ?, "gopay-test.xml", ?, "")'
        )->execute([$this->supplierId, substr($hash, 0, 20), $performedOn, $performedOn, $performedOn,
            $amount, $amount, $amount, $hash]);
        $clearingId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO gopay_movements
                (supplier_id, clearing_id, external_id, movement_type, performed_on, amount,
                 invoice_id, invoice_payment_id, status)
             VALUES (?, ?, ?, "credit", ?, ?, ?, ?, "posted")'
        )->execute([$this->supplierId, $clearingId, substr($hash, 20, 20), $performedOn, $amount,
            $invoiceId, $paymentId]);

        return (int) $pdo->lastInsertId();
    }

    private function purchaseStatusOf(int $purchaseInvoiceId): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT status FROM purchase_invoices WHERE id = ?');
        $stmt->execute([$purchaseInvoiceId]);
        return (string) ($stmt->fetchColumn() ?: '');
    }

    /**
     * Jedna kontrola měsíční sestavy podle klíče.
     *
     * @return array<string,mixed>
     */
    private function checkByKey(string $key): array
    {
        $result = $this->closing->monthlyCheck($this->supplierId, $this->periodId, self::YEAR . '-06-01', self::YEAR . '-06-30');
        foreach ($result['checks'] as $c) {
            if ($c['key'] === $key) {
                return $c;
            }
        }
        self::fail('Kontrola ' . $key . ' v sestavě chybí.');
    }

    /** Nálezy kontroly `paid_purchases_open_saldo` naklíčované podle doc_id. */
    private function paidPurchaseFindingsById(): array
    {
        $result = $this->closing->monthlyCheck($this->supplierId, $this->periodId, self::YEAR . '-06-01', self::YEAR . '-06-30');
        foreach ($result['checks'] as $c) {
            if ($c['key'] === 'paid_purchases_open_saldo') {
                return array_column($c['value']['findings'], null, 'doc_id');
            }
        }
        self::fail('Kontrola paid_purchases_open_saldo v sestavě chybí.');
    }

    /**
     * Regrese F1: monthlyCheck nezapisuje žádný krok do accounting_closing_steps
     * (na rozdíl od runPrecheck, který krok 'precheck' persistuje) — je to čistě
     * READ-ONLY sestava spustitelná kdykoli bez zahájení uzávěrky.
     */
    public function testMonthlyCheckDoesNotWriteClosingStep(): void
    {
        $this->closing->monthlyCheck($this->supplierId, $this->periodId, self::YEAR . '-06-01', self::YEAR . '-06-30');

        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM accounting_closing_steps WHERE supplier_id = ? AND period_id = ?'
        );
        $stmt->execute([$this->supplierId, $this->periodId]);
        self::assertSame(0, (int) $stmt->fetchColumn(), 'monthlyCheck nesmí zapsat žádný krok do accounting_closing_steps.');
    }

    /**
     * K1: otevřený zůstatek na zálohových účtech 314 (poskytnuté) / 324 (přijaté)
     * k rozvahovému dni je jen WARNING (ne error jako u ryze průběžných účtů) —
     * legitimně jde o nevypořádanou zálohu, kterou má účetní jen ověřit.
     */
    public function testFlagsOpenAdvanceAccounts314And324AsWarning(): void
    {
        $entryDate = self::YEAR . '-07-15';

        // Poskytnutá provozní záloha (314 v debetu) — dosud nevypořádaná.
        $this->posting->postDocument($this->supplierId, 'manual', null, [
            ['account_code' => '314', 'side' => 'debit', 'amount' => 3000],
            ['account_code' => '221', 'side' => 'credit', 'amount' => 3000],
        ], ['entry_date' => $entryDate, 'posted_by' => $this->userId, 'user_id' => $this->userId]);

        // Přijatá provozní záloha (324 v kreditu) — dosud nevypořádaná.
        $this->posting->postDocument($this->supplierId, 'manual', null, [
            ['account_code' => '221', 'side' => 'debit', 'amount' => 1250],
            ['account_code' => '324', 'side' => 'credit', 'amount' => 1250],
        ], ['entry_date' => $entryDate, 'posted_by' => $this->userId, 'user_id' => $this->userId]);

        $result = $this->closing->monthlyCheck($this->supplierId, $this->periodId, self::YEAR . '-07-01', self::YEAR . '-07-31');
        $byKey = [];
        foreach ($result['checks'] as $c) {
            $byKey[$c['key']] = $c;
        }

        self::assertArrayHasKey('deposits_314_324_open', $byKey);
        self::assertSame('warning', $byKey['deposits_314_324_open']['severity'], 'Zálohové účty 314/324 nesmí být tvrdá chyba — jen warning.');
        self::assertFalse($byKey['deposits_314_324_open']['ok']);
        self::assertSame(3000.0, $byKey['deposits_314_324_open']['value']['314']);
        self::assertSame(-1250.0, $byKey['deposits_314_324_open']['value']['324']);
    }

    /**
     * Realizovaný kurzový rozdíl nezaúčtovaný na 563/663: cizoměnová (EUR) plně
     * zaplacená FV, jejíž bankovní úhrada vypořádala 311 JINÝM kurzem než doklad a
     * rozdíl nikdo nepřeúčtoval na kurzový výsledek → musí být v nálezu
     * realized_fx_unbooked. Kontrolní FV, jejíž úhrada už NESE řádek 663 (auto-B6
     * kurzový zisk), se hlásit NESMÍ.
     */
    public function testFlagsRealizedFxUnbookedOnSettledForeignInvoice(): void
    {
        $clientId = $this->createClient();
        $entryDate = self::YEAR . '-08-10';

        // 1) EUR FV: předpis 100 EUR × 25 = 2500 na 311, úhrada 2800 CZK bez kurzového řádku.
        $trigId = $this->createPaidInvoice($clientId, 'FX-TRIG-001', $entryDate);
        $this->posting->postDocument($this->supplierId, 'invoice', $trigId, [
            ['account_code' => '311', 'side' => 'debit', 'amount' => 2500, 'currency_code' => 'EUR', 'fx_rate' => 25, 'amount_foreign' => 100],
            ['account_code' => '602', 'side' => 'credit', 'amount' => 2500],
        ], ['entry_date' => $entryDate, 'posted_by' => $this->userId, 'user_id' => $this->userId]);
        $this->bookBankSettlement($trigId, 2800.0, $entryDate, [
            ['account_code' => '221', 'side' => 'debit', 'amount' => 2800],
            ['account_code' => '311', 'side' => 'credit', 'amount' => 2800],
        ]);

        // 2) EUR FV se ZAÚČTOVANÝM kurzovým ziskem na 663 (311 kreditováno nominálem 2500,
        //    rozdíl 300 na 663) — nesmí být hlášená (guard + reziduum 0).
        $okId = $this->createPaidInvoice($clientId, 'FX-OK-002', $entryDate);
        $this->posting->postDocument($this->supplierId, 'invoice', $okId, [
            ['account_code' => '311', 'side' => 'debit', 'amount' => 2500, 'currency_code' => 'EUR', 'fx_rate' => 25, 'amount_foreign' => 100],
            ['account_code' => '602', 'side' => 'credit', 'amount' => 2500],
        ], ['entry_date' => $entryDate, 'posted_by' => $this->userId, 'user_id' => $this->userId]);
        $this->bookBankSettlement($okId, 2800.0, $entryDate, [
            ['account_code' => '221', 'side' => 'debit', 'amount' => 2800],
            ['account_code' => '311', 'side' => 'credit', 'amount' => 2500],
            ['account_code' => '663', 'side' => 'credit', 'amount' => 300],
        ]);

        $result = $this->closing->monthlyCheck($this->supplierId, $this->periodId, self::YEAR . '-08-01', self::YEAR . '-08-31');
        $byKey = [];
        foreach ($result['checks'] as $c) {
            $byKey[$c['key']] = $c;
        }

        self::assertArrayHasKey('realized_fx_unbooked', $byKey);
        self::assertSame('warning', $byKey['realized_fx_unbooked']['severity']);
        self::assertFalse($byKey['realized_fx_unbooked']['ok']);
        $items = $byKey['realized_fx_unbooked']['value']['findings'];
        $byDoc = array_column($items, null, 'doc_no');
        self::assertArrayHasKey('FX-TRIG-001', $byDoc, 'Nezaúčtovaný realizovaný kurzový rozdíl musí být v nálezu.');
        // Částka je KURZOVÝ ROZDÍL, tedy rozdíl dvou korunových přepočtů — v korunách.
        // Dřív se u ní psala měna dokladu, takže −300 Kč vypadalo jako −300 EUR.
        self::assertSame(-300.0, $byDoc['FX-TRIG-001']['amount']);
        self::assertSame('CZK', $byDoc['FX-TRIG-001']['currency']);
        self::assertArrayNotHasKey('FX-OK-002', $byDoc, 'Doklad s kurzovým rozdílem už zaúčtovaným na 663 se hlásit nesmí.');
    }

    /**
     * Úplnost evidence karet drobného majetku vs obrat 501.200: přijatá faktura s
     * řádkem expense_kind='small_asset' 52 104 Kč, ke které NEEXISTUJE karta →
     * small_asset_cards_incomplete musí selhat (cards_total 0, turnover 52 104).
     */
    public function testFlagsSmallAssetCardsIncompleteVsTurnover501(): void
    {
        $vendorId = $this->createClient();
        $date = self::YEAR . '-09-05';

        $pfId = $this->createPaidPurchase($vendorId, 'DM-INC-001', $date);
        $this->addPurchaseItem($pfId, 'small_asset', 52104.0, 'Drobný majetek bez karty');

        $result = $this->closing->monthlyCheck($this->supplierId, $this->periodId, self::YEAR . '-09-01', self::YEAR . '-09-30');
        $byKey = [];
        foreach ($result['checks'] as $c) {
            $byKey[$c['key']] = $c;
        }

        self::assertArrayHasKey('small_asset_cards_incomplete', $byKey);
        self::assertSame('warning', $byKey['small_asset_cards_incomplete']['severity']);
        self::assertFalse($byKey['small_asset_cards_incomplete']['ok']);
        self::assertSame(0.0, $byKey['small_asset_cards_incomplete']['value']['cards_total']);
        self::assertSame(52104.0, $byKey['small_asset_cards_incomplete']['value']['turnover_501']);
        self::assertSame(-52104.0, $byKey['small_asset_cards_incomplete']['value']['diff']);
    }

    /**
     * Ok-větev: obrat 501.200 8 000 Kč přesně pokrytý kartou 8 000 Kč →
     * small_asset_cards_incomplete musí být ok. Karta pořízená a vrácená dobropisem ve
     * STEJNÉM období (nákup-a-vratka 3 000 Kč, v 501 se vyruší) se do součtu karet
     * NEZAPOČÍTÁ, jinak by cards_total vyskočil na 11 000 a kontrola by falešně selhala.
     */
    public function testSmallAssetCardsMatchingTurnoverIsOk(): void
    {
        $vendorId = $this->createClient();
        $date = self::YEAR . '-10-05';

        $pfId = $this->createPaidPurchase($vendorId, 'DM-OK-001', $date);
        $this->addPurchaseItem($pfId, 'small_asset', 8000.0, 'Drobný majetek s kartou');

        $this->smallAssets->insert($this->supplierId, [
            'name' => 'Evidovaná karta',
            'acquisition_date' => $date,
            'price' => 8000.0,
            'status' => 'in_use',
        ], $this->userId);

        // Nákup-a-vratka v témže období (faktura a dobropis k ní): musí se z přírůstku vyloučit.
        $returnedId = $this->createPaidPurchase($vendorId, 'DM-OK-002', self::YEAR . '-10-06');
        $this->addPurchaseItem($returnedId, 'small_asset', 3000.0, 'Vrácená věc');
        $creditNoteId = $this->createPaidPurchase($vendorId, 'DM-OK-002-D', self::YEAR . '-10-20');
        $this->db->pdo()->prepare("UPDATE purchase_invoices SET document_kind = 'credit_note', parent_purchase_invoice_id = ? WHERE id = ?")
            ->execute([$returnedId, $creditNoteId]);
        $this->addPurchaseItem($creditNoteId, 'small_asset', -3000.0, 'Vrácená věc');
        $this->smallAssets->insert($this->supplierId, [
            'name' => 'Vrácená karta',
            'purchase_invoice_id' => $returnedId,
            'acquisition_date' => self::YEAR . '-10-06',
            'price' => 3000.0,
            'status' => 'disposed',
            'disposed_at' => self::YEAR . '-10-20',
        ], $this->userId);

        $result = $this->closing->monthlyCheck($this->supplierId, $this->periodId, self::YEAR . '-10-01', self::YEAR . '-10-31');
        $byKey = [];
        foreach ($result['checks'] as $c) {
            $byKey[$c['key']] = $c;
        }

        self::assertArrayHasKey('small_asset_cards_incomplete', $byKey);
        self::assertTrue($byKey['small_asset_cards_incomplete']['ok'], 'Karty pokrývající obrat 501 nesmí kontrola hlásit.');
        self::assertSame(8000.0, $byKey['small_asset_cards_incomplete']['value']['cards_total']);
        self::assertSame(8000.0, $byKey['small_asset_cards_incomplete']['value']['turnover_501']);
        self::assertSame(0.0, $byKey['small_asset_cards_incomplete']['value']['diff']);
    }

    // ── helpers (K3) ─────────────────────────────────────────────────────────

    private function createClient(): int
    {
        $pdo = $this->db->pdo();
        $czId = (int) $pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn();
        $currencyId = (int) $pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn();
        $stmt = $pdo->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, dic, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, "K3 protistrana s.r.o.", "Test 1", "Praha", "11000", ?, "CZ12345678", "k3@example.com", "cs", ?, 1, 1)'
        );
        $stmt->execute([$this->supplierId, $czId, $currencyId]);
        return (int) $pdo->lastInsertId();
    }

    private function createPaidInvoice(int $clientId, string $varsymbol, string $date): int
    {
        $pdo = $this->db->pdo();
        $currencyId = (int) $pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn();
        $stmt = $pdo->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, total_without_vat, total_vat, total_with_vat,
                 status, paid_at, vat_classification_code, created_by)
             VALUES (?, ?, "invoice", ?, ?, ?, ?, ?, 0, 1000, 210, 1210, "paid", ?, "1", ?)'
        );
        $stmt->execute([$this->supplierId, $varsymbol, $clientId, $date, $date, $date, $currencyId, $date, $this->userId]);
        return (int) $pdo->lastInsertId();
    }

    private function createPaidPurchase(int $vendorId, string $number, string $date): int
    {
        $pdo = $this->db->pdo();
        $currencyId = (int) $pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn();
        $stmt = $pdo->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date,
                 due_date, received_at, currency_id, reverse_charge, vendor_snapshot,
                 total_without_vat, total_vat, total_with_vat, status, paid_at,
                 vat_classification_code, vat_deduction, created_by)
             VALUES (?, ?, ?, "invoice", ?, ?, ?, ?, ?, 0, "{}", 1000, 210, 1210, "paid", ?, "1", "full", ?)'
        );
        $stmt->execute([$this->supplierId, $vendorId, $number, $date, $date, $date, $date, $currencyId, $date, $this->userId]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * Zálohová přijatá faktura (`document_kind='advance'`). Vlastní předpis na 321 nedostává —
     * zálohová faktura není daňový doklad, na saldokontě visí až konečná faktura.
     */
    private function createAdvancePurchase(int $vendorId, string $number, string $date): int
    {
        $pdo = $this->db->pdo();
        $currencyId = (int) $pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn();
        $stmt = $pdo->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date,
                 due_date, received_at, currency_id, reverse_charge, vendor_snapshot,
                 total_without_vat, total_vat, total_with_vat, status, paid_at,
                 vat_classification_code, vat_deduction, created_by)
             VALUES (?, ?, ?, "advance", ?, ?, ?, ?, ?, 0, "{}", 1000, 210, 1210, "paid", ?, "1", "full", ?)'
        );
        $stmt->execute([$this->supplierId, $vendorId, $number, $date, $date, $date, $date, $currencyId, $date, $this->userId]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * Zaúčtuje bankovní úhradu faktury: bank_statement + bank_transaction +
     * invoice_payments (vazba na doklad) + deníkový zápis se zadanými řádky.
     *
     * @param list<array{account_code:string, side:'debit'|'credit', amount:float|int}> $lines
     */
    private function bookBankSettlement(int $invoiceId, float $czk, string $date, array $lines): void
    {
        $pdo = $this->db->pdo();

        $stmt = $pdo->prepare(
            'INSERT INTO bank_statements (supplier_id, file_name, file_hash, account_number, statement_date)
             VALUES (?, "fx-test.gpc", ?, "123456789/0100", ?)'
        );
        $stmt->execute([$this->supplierId, hash('sha256', 'fx-settle-' . $invoiceId . '-' . microtime(true)), $date]);
        $statementId = (int) $pdo->lastInsertId();

        $stmt = $pdo->prepare(
            'INSERT INTO bank_transactions (statement_id, posted_at, amount, currency, variable_symbol)
             VALUES (?, ?, ?, "CZK", ?)'
        );
        $stmt->execute([$statementId, $date, $czk, 'FX' . $invoiceId]);
        $txId = (int) $pdo->lastInsertId();

        $stmt = $pdo->prepare(
            'INSERT INTO invoice_payments (supplier_id, invoice_id, paid_on, amount, currency, source, bank_transaction_id)
             VALUES (?, ?, ?, ?, "CZK", "bank", ?)'
        );
        $stmt->execute([$this->supplierId, $invoiceId, $date, $czk, $txId]);

        $this->posting->postDocument($this->supplierId, 'bank', $txId, $lines, [
            'entry_date' => $date, 'posted_by' => $this->userId, 'user_id' => $this->userId,
        ]);
    }

    /**
     * Zaúčtuje bankovní úhradu PŘIJATÉ faktury. Vazbu na doklad nese `payment_matches`
     * (invoice_payments míří na vydané faktury a FK by neprošel), zápis je 321 MD / 221 D.
     */
    private function bookPurchaseBankSettlement(int $purchaseInvoiceId, float $czk, string $date): void
    {
        $pdo = $this->db->pdo();

        $stmt = $pdo->prepare(
            'INSERT INTO bank_statements (supplier_id, file_name, file_hash, account_number, statement_date)
             VALUES (?, "k3-purchase.gpc", ?, "123456789/0100", ?)'
        );
        $stmt->execute([
            $this->supplierId,
            hash('sha256', 'k3-pf-settle-' . $purchaseInvoiceId . '-' . microtime(true)),
            $date,
        ]);
        $statementId = (int) $pdo->lastInsertId();

        $stmt = $pdo->prepare(
            'INSERT INTO bank_transactions (statement_id, posted_at, amount, currency, variable_symbol)
             VALUES (?, ?, ?, "CZK", ?)'
        );
        $stmt->execute([$statementId, $date, -$czk, 'PF' . $purchaseInvoiceId]);
        $txId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO payment_matches (supplier_id, bank_transaction_id, purchase_invoice_id, amount, match_type, matched_by_user_id)
             VALUES (?, ?, ?, ?, "manual", ?)'
        )->execute([$this->supplierId, $txId, $purchaseInvoiceId, $czk, $this->userId]);

        $this->posting->postDocument($this->supplierId, 'bank', $txId, [
            ['account_code' => '321', 'side' => 'debit', 'amount' => $czk],
            ['account_code' => '221', 'side' => 'credit', 'amount' => $czk],
        ], ['entry_date' => $date, 'posted_by' => $this->userId, 'user_id' => $this->userId]);
    }

    /** Přidá řádek přijaté faktury s daným druhem výdaje (expense_kind) a netto částkou. */
    private function addPurchaseItem(int $purchaseInvoiceId, string $expenseKind, float $net, string $description): void
    {
        $pdo = $this->db->pdo();
        $vatRateId = (int) $pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn();
        $stmt = $pdo->prepare(
            'INSERT INTO purchase_invoice_items
                (purchase_invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index, expense_kind)
             VALUES (?, ?, 1, "ks", ?, ?, 21, ?, 0, ?, 0, ?)'
        );
        $stmt->execute([$purchaseInvoiceId, $description, $net, $vatRateId, $net, $net, $expenseKind]);
    }
    /**
     * Doklad s NEZAÚČTOVANOU úhradou se nesmí hlásit jako nezaúčtovaný kurzový rozdíl.
     *
     * Zbytek na saldokontu je kurzovým rozdílem jen tehdy, když úhrada zaúčtovaná JE.
     * Bez téhle podmínky se do nálezu dostal doklad, u kterého se zbytek rovnal CELÉ
     * faktuře — v produkci se tak u dokladu hlásil „kurzový rozdíl" ve výši
     * jeho plné hodnoty. Ten případ patří kontrole otevřeného salda, a ta ho
     * hlásí správně; tady by svedl k zaúčtování celé faktury na 563/663.
     */
    public function testUnpostedSettlementIsNotReportedAsFxDifference(): void
    {
        $entryDate = self::YEAR . '-08-20';
        $clientId = $this->createClient();
        $id = $this->createPaidInvoice($clientId, 'FX-NOPAY-001', $entryDate);

        // Předpis zaúčtovaný, úhrada NE — na 311 tedy zůstává celá faktura.
        $this->posting->postDocument($this->supplierId, 'invoice', $id, [
            ['account_code' => '311', 'side' => 'debit', 'amount' => 2500, 'currency_code' => 'EUR', 'fx_rate' => 25, 'amount_foreign' => 100],
            ['account_code' => '602', 'side' => 'credit', 'amount' => 2500],
        ], ['entry_date' => $entryDate, 'posted_by' => $this->userId, 'user_id' => $this->userId]);
        $this->db->pdo()->prepare("UPDATE invoices SET status = 'paid', paid_at = ? WHERE id = ?")
            ->execute([$entryDate, $id]);

        $result = $this->closing->monthlyCheck($this->supplierId, $this->periodId, self::YEAR . '-08-01', self::YEAR . '-08-31');
        $byKey = array_column($result['checks'], null, 'key');
        $byDoc = array_column($byKey['realized_fx_unbooked']['value']['findings'] ?? [], null, 'doc_no');

        self::assertArrayNotHasKey(
            'FX-NOPAY-001',
            $byDoc,
            'Nezaúčtovaná úhrada není kurzový rozdíl — hlásí ji kontrola otevřeného salda.',
        );
    }

    /**
     * Kolik kontrola hlásí, tolik musí detail ukázat.
     *
     * Popup se plnil z payloadu kroku `precheck`, což je auditní snímek useknutý na
     * {@see CheckFindingNormalizer::SNAPSHOT_CAP} položek. Kontrola s 12 nálezy proto
     * hlásila 12 a v tabulce vypsala 10 — chybějící dva nebyly nikde vidět a uživatel
     * neměl jak zjistit, které to jsou. Detail se proto načítá živě vlastním dotazem.
     */
    public function testCheckDetailShowsEveryFindingItReports(): void
    {
        $vendorId = $this->createClient();
        $entryDate = self::YEAR . '-09-10';
        $count = CheckFindingNormalizer::SNAPSHOT_CAP + 2;

        for ($i = 1; $i <= $count; $i++) {
            $pfId = $this->createPaidPurchase($vendorId, sprintf('K3-CAP-%03d', $i), $entryDate);
            $this->posting->postDocument($this->supplierId, 'purchase_invoice', $pfId, [
                ['account_code' => '518', 'side' => 'debit', 'amount' => 1000],
                ['account_code' => '343', 'side' => 'debit', 'amount' => 210],
                ['account_code' => '321', 'side' => 'credit', 'amount' => 1210],
            ], ['entry_date' => $entryDate, 'posted_by' => $this->userId, 'user_id' => $this->userId]);
        }

        $detail = $this->closing->checkFindings(
            $this->supplierId,
            $this->periodId,
            'paid_purchases_open_saldo',
            self::YEAR . '-09-01',
            self::YEAR . '-09-30',
        );

        self::assertSame($count, $detail['value']['count']);
        self::assertCount(
            $count,
            $detail['value']['findings'],
            'Detail musí vypsat všechny nálezy, které kontrola napočítala.',
        );
        self::assertFalse($detail['value']['truncated'], 'Pod stropem se nic neořezává.');

        // A pro srovnání: snímek do payloadu kroku zůstává schválně malý — je to auditní
        // stopa, ne datový sklad. Právě proto se z něj detail plnit nesmí.
        $snapshot = (new CheckFindingNormalizer())->recap(
            [$detail],
            CheckFindingNormalizer::SNAPSHOT_CAP,
        )[0];
        self::assertSame($count, $snapshot['value']['count']);
        self::assertCount(CheckFindingNormalizer::SNAPSHOT_CAP, $snapshot['value']['findings']);
        self::assertTrue($snapshot['value']['truncated']);
    }

    /**
     * Otevřené cizoměnové položky k přecenění musí být VIDĚT.
     *
     * Kontrola vracela jen `{count: N}`, takže hlásila „1 nález" a otevřela prázdnou
     * tabulku — uživatel věděl, že něco k přecenění zbývá, a neměl jak zjistit co.
     * Zároveň se počítaly ŘÁDKY deníku: doklad s cizoměnovým 311 i 343 by se počítal
     * dvakrát, takže by se počet rozešel s počtem dokladů v tabulce.
     */
    public function testOpenFxItemsListsDocumentsNotJustACount(): void
    {
        $clientId = $this->createClient();
        $entryDate = self::YEAR . '-10-05';
        $id = $this->createPaidInvoice($clientId, 'FX-OPEN-001', $entryDate);

        // Neuhrazená EUR faktura se DVĚMA cizoměnovými řádky (311 i 343) — jeden doklad.
        $this->posting->postDocument($this->supplierId, 'invoice', $id, [
            ['account_code' => '311', 'side' => 'debit', 'amount' => 2500, 'currency_code' => 'EUR', 'fx_rate' => 25, 'amount_foreign' => 100],
            ['account_code' => '343', 'side' => 'debit', 'amount' => 500, 'currency_code' => 'EUR', 'fx_rate' => 25, 'amount_foreign' => 20],
            ['account_code' => '602', 'side' => 'credit', 'amount' => 3000],
        ], ['entry_date' => $entryDate, 'posted_by' => $this->userId, 'user_id' => $this->userId]);
        $this->db->pdo()->prepare("UPDATE invoices SET status = 'sent', paid_at = NULL WHERE id = ?")
            ->execute([$id]);

        $result = $this->closing->monthlyCheck($this->supplierId, $this->periodId, self::YEAR . '-10-01', self::YEAR . '-10-31');
        $byKey = array_column($result['checks'], null, 'key');
        $value = $byKey['fx_open_items']['value'];

        self::assertSame(1, $value['count'], 'Dva cizoměnové řádky jednoho dokladu = jeden nález.');
        self::assertCount(1, $value['findings'], 'Počet bez seznamu = prázdný popup.');

        $f = $value['findings'][0];
        self::assertSame($id, $f['doc_id']);
        self::assertSame('FX-OPEN-001', $f['doc_no']);
        self::assertSame($entryDate, $f['doc_date']);
        self::assertNotNull($f['partner_name']);
    }

    /** Neznámý klíč nesmí projít jako prázdný detail — to by vypadalo jako „nic nenalezeno". */
    public function testUnknownCheckKeyIsRejected(): void
    {
        $this->expectException(ClosingException::class);
        $this->closing->checkFindings($this->supplierId, $this->periodId, 'neexistujici_kontrola');
    }
}
