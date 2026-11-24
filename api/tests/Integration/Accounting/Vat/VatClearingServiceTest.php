<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Vat;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Repository\PostingRuleRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Accounting\Vat\VatClearingService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Interní doklad zúčtování DPH (VatClearingService).
 *
 * Ověřuje přesně to, co dělá účetní na konci každého zdaňovacího období:
 *   MD 343.200 / D 343.900   (daň na výstupu)
 *   MD 343.900 / D 343.100   (daň na vstupu)
 * a co z toho plyne — po dokladu jsou vstup i výstup ZA OBDOBÍ nulové a na 343.900
 * leží přesně odváděná částka. Plus idempotence (re-run přepisuje, nezdvojuje) a to,
 * že se doklad nepočítá sám ze sebe.
 */
#[Group('integration')]
final class VatClearingServiceTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const YEAR = 2099;

    private Connection $db;
    private VatClearingService $clearing;
    private PostingService $posting;
    private ChartOfAccountsRepository $accounts;
    private JournalEntryRepository $journal;

    private int $supplierId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 5);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db       = $container->get(Connection::class);
            $this->clearing = $container->get(VatClearingService::class);
            $this->posting  = $container->get(PostingService::class);
            $this->accounts = $container->get(ChartOfAccountsRepository::class);
            $this->journal  = $container->get(JournalEntryRepository::class);
            $periods        = $container->get(AccountingPeriodRepository::class);
            $seeder         = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $source = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($source === 0) {
            $this->markTestSkipped('Chybí supplier v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);
        $seeder->seedForSupplier($this->supplierId);
        $periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
        $this->setVatPeriod('monthly');
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

    // ── pomocníci ─────────────────────────────────────────────────────────────

    private function setVatPeriod(string $period): void
    {
        $this->db->pdo()
            ->prepare('UPDATE supplier SET vat_period = ?, is_vat_payer = 1, is_identified = 0 WHERE id = ?')
            ->execute([$period, $this->supplierId]);
    }

    /** Zaúčtuje ručně DPH na výstupu (311/343.200) k danému datu. */
    private function bookOutputVat(float $amount, string $date, int $sourceId, string $account = '343.200'): void
    {
        $this->posting->postDocument($this->supplierId, 'manual', $sourceId, [
            ['account_code' => '311', 'side' => 'debit', 'amount' => $amount],
            ['account_code' => $account, 'side' => 'credit', 'amount' => $amount],
        ], ['entry_date' => $date, 'description' => 'DPH na výstupu']);
    }

    /** Zaúčtuje ručně DPH na vstupu (343.100/321) k danému datu. */
    private function bookInputVat(float $amount, string $date, int $sourceId, string $account = '343.100'): void
    {
        $this->posting->postDocument($this->supplierId, 'manual', $sourceId, [
            ['account_code' => $account, 'side' => 'debit', 'amount' => $amount],
            ['account_code' => '321', 'side' => 'credit', 'amount' => $amount],
        ], ['entry_date' => $date, 'description' => 'DPH na vstupu']);
    }

    /** Obrat účtu (kredit − debet) za období, VČETNĚ zúčtovacího dokladu. */
    private function netCredit(string $code, string $from, string $to): float
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(CASE WHEN l.side = 'credit' THEN l.amount ELSE -l.amount END), 0)
               FROM journal_entry_lines l
               JOIN journal_entries e   ON e.id = l.entry_id
               JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.supplier_id = ? AND a.account_code = ? AND e.entry_date BETWEEN ? AND ?"
        );
        $stmt->execute([$this->supplierId, $code, $from, $to]);

        return round((float) $stmt->fetchColumn(), 2);
    }

    /** @return array<string, array{debit:float, credit:float}> */
    private function linesByCode(int $entryId): array
    {
        $codes = [];
        foreach ($this->accounts->listForTenant($this->supplierId, true) as $account) {
            $codes[(int) $account['id']] = (string) $account['account_code'];
        }
        $out = [];
        foreach ($this->journal->linesForEntry($entryId, $this->supplierId) as $line) {
            $code = $codes[(int) $line['account_id']] ?? (string) $line['account_id'];
            $out[$code] ??= ['debit' => 0.0, 'credit' => 0.0];
            $out[$code][(string) $line['side']] += (float) $line['amount'];
        }

        return $out;
    }

    // ── testy ─────────────────────────────────────────────────────────────────

    public function testPostsBothLegsInTheAccountantsDirections(): void
    {
        $this->bookOutputVat(10000.00, self::YEAR . '-06-10', 900001);
        $this->bookInputVat(4000.00, self::YEAR . '-06-20', 900002);

        $result = $this->clearing->postForPeriod($this->supplierId, self::YEAR, 6);

        self::assertSame(VatClearingService::STATUS_POSTED, $result['status']);
        self::assertEqualsWithDelta(10000.00, $result['output_vat'], 0.001);
        self::assertEqualsWithDelta(4000.00, $result['input_vat'], 0.001);
        self::assertEqualsWithDelta(6000.00, $result['settlement'], 0.001, 'K odvedení = výstup − vstup.');

        $entry = $this->journal->find((int) $result['entry_id'], $this->supplierId);
        self::assertSame(self::YEAR . '-06-30', (string) $entry['entry_date'], 'Datum případu = poslední den období.');
        self::assertSame('vat_clearing', (string) $entry['source_type']);
        self::assertNotNull($entry['posted_at']);

        $byCode = $this->linesByCode((int) $result['entry_id']);
        self::assertEqualsWithDelta(10000.00, $byCode['343.200']['debit'], 0.001, 'MD 343.200 — výstup se odúčtuje.');
        self::assertEqualsWithDelta(4000.00, $byCode['343.100']['credit'], 0.001, 'D 343.100 — vstup se odúčtuje.');
        self::assertEqualsWithDelta(10000.00, $byCode['343.900']['credit'], 0.001);
        self::assertEqualsWithDelta(4000.00, $byCode['343.900']['debit'], 0.001);
    }

    /**
     * Období s deníkem převzatým z jiného programu: DPH vyrovnal (nebo nevyrovnal) zdroj.
     * Cron ani obnova konceptu přiznání do něj nic nezapíšou; ruční zúčtování projde,
     * náhled ale nese `taken_over`, aby to bylo vědomé.
     */
    public function testAutomaticClearingSkipsPeriodWithTakenOverEntries(): void
    {
        $this->bookOutputVat(10000.00, self::YEAR . '-05-10', 900011);
        $entry = $this->journal->findBySource($this->supplierId, 'manual', 900011);
        $this->db->pdo()->prepare("INSERT INTO premier_import_map (supplier_id, kind, premier_key, target_id) VALUES (?, 'journal_entry', ?, ?)")
            ->execute([$this->supplierId, 'synth|ID-05', (int) $entry['id']]);

        foreach ([VatClearingService::TRIGGER_CRON, VatClearingService::TRIGGER_RETURN_DRAFT] as $trigger) {
            $auto = $this->clearing->postForPeriod($this->supplierId, self::YEAR, 5, ['trigger' => $trigger]);
            self::assertSame(VatClearingService::STATUS_TAKEN_OVER, $auto['status'], $trigger);
            self::assertNull($this->journal->findBySource($this->supplierId, VatClearingService::SOURCE_TYPE, (int) $auto['source_id']));
        }

        $manual = $this->clearing->postForPeriod($this->supplierId, self::YEAR, 5, ['trigger' => VatClearingService::TRIGGER_MANUAL]);
        self::assertTrue($manual['taken_over']);
        self::assertSame(VatClearingService::STATUS_POSTED, $manual['status']);

        $this->bookOutputVat(500.00, self::YEAR . '-07-10', 900012);
        $native = $this->clearing->postForPeriod($this->supplierId, self::YEAR, 7, ['trigger' => VatClearingService::TRIGGER_CRON]);
        self::assertFalse($native['taken_over']);
        self::assertSame(VatClearingService::STATUS_POSTED, $native['status'], 'nativní období cron vyrovná dál');
    }

    /** Smysl celé věci: po dokladu jsou vstup i výstup za období nulové. */
    public function testAfterClearingInputAndOutputAreZeroForThePeriod(): void
    {
        $this->bookOutputVat(2100.00, self::YEAR . '-06-05', 900011);
        $this->bookInputVat(840.00, self::YEAR . '-06-15', 900012);

        $this->clearing->postForPeriod($this->supplierId, self::YEAR, 6);

        $from = self::YEAR . '-06-01';
        $to   = self::YEAR . '-06-30';
        self::assertEqualsWithDelta(0.0, $this->netCredit('343.200', $from, $to), 0.001, '343.200 je po zúčtování za období nulová.');
        self::assertEqualsWithDelta(0.0, $this->netCredit('343.100', $from, $to), 0.001, '343.100 je po zúčtování za období nulová.');
        self::assertEqualsWithDelta(1260.00, $this->netCredit('343.900', $from, $to), 0.001, 'Na 343.900 zůstal celý závazek k odvodu.');
    }

    public function testClearsAllVatAccountsAndDetectsReclassificationWithoutTotalChange(): void
    {
        $rules = new PostingRuleRepository($this->db);
        $accounts = ['output.21' => '343.310', 'output.12' => '343.320', 'input.21' => '343.110', 'input.12' => '343.120'];
        $parent = $this->accounts->findByCode($this->supplierId, '343')['id'];
        foreach ($accounts as $key => $code) {
            $this->accounts->insert($this->supplierId, [
                'account_code' => $code, 'name' => 'Vzorová analytika DPH', 'account_type' => 'liability',
                'normal_side' => null, 'parent_id' => $parent,
            ]);
            $input = str_starts_with($key, 'input.');
            $rules->upsertOverride($this->supplierId, 'invoice.vat.' . $key, $input ? $code : null, $input ? null : $code, 'Vzorová DPH');
        }
        $rules->upsertOverride($this->supplierId, 'invoice.vat.output.15', null, '343.310', 'Druhá sazba na stejném účtu');
        $date = self::YEAR . '-06-15';
        $this->bookOutputVat(210, $date, 900101, '343.310');
        $this->bookOutputVat(120, $date, 900102, '343.320');
        $this->bookOutputVat(20, $date, 900103);
        $this->bookInputVat(105, $date, 900104, '343.110');
        $this->bookInputVat(60, $date, 900105, '343.120');
        $this->bookInputVat(10, $date, 900106);

        $result = $this->clearing->postForPeriod($this->supplierId, self::YEAR, 6);
        self::assertSame(VatClearingService::STATUS_POSTED, $result['status']);
        self::assertSame(350.0, $result['output_vat']);
        self::assertSame(175.0, $result['input_vat']);
        self::assertCount(6, $result['accounts_detail']);
        foreach ([...array_values($accounts), '343.100', '343.200'] as $code) {
            self::assertEqualsWithDelta(0.0, $this->netCredit($code, self::YEAR . '-06-01', self::YEAR . '-06-30'), 0.001);
        }
        self::assertSame(175.0, $this->netCredit('343.900', self::YEAR . '-06-01', self::YEAR . '-06-30'));
        $status = $this->clearing->status($this->supplierId, self::YEAR, 6);
        self::assertSame(VatClearingService::FRESHNESS_OK, $status['freshness']);
        self::assertSame(350.0, $status['run']['output_vat']);
        self::assertSame(175.0, $status['run']['input_vat']);

        $this->posting->postDocument($this->supplierId, 'manual', 900107, [
            ['account_code' => '343.310', 'side' => 'debit', 'amount' => 30.0],
            ['account_code' => '343.320', 'side' => 'credit', 'amount' => 30.0],
        ], ['entry_date' => $date, 'description' => 'Přeúčtování DPH']);
        $status = $this->clearing->status($this->supplierId, self::YEAR, 6);
        self::assertSame($status['output_vat'], $status['posted']['output_vat']);
        self::assertSame(VatClearingService::FRESHNESS_STALE, $status['freshness']);
    }

    /**
     * Idempotence přes uq_je_supplier_source: druhý běh MUSÍ přepsat týž zápis.
     * A přepočet se nesmí počítat sám ze sebe — proto se `vat_clearing` z obratu
     * vylučuje; bez toho by se částka při každém běhu zdvojnásobila.
     */
    public function testRerunRewritesInsteadOfDuplicatingAndDoesNotFeedOnItself(): void
    {
        $this->bookOutputVat(5000.00, self::YEAR . '-07-10', 900021);

        $first = $this->clearing->postForPeriod($this->supplierId, self::YEAR, 7);
        $second = $this->clearing->postForPeriod($this->supplierId, self::YEAR, 7);

        self::assertSame((int) $first['entry_id'], (int) $second['entry_id'], 'Re-run drží tentýž zápis.');
        self::assertEqualsWithDelta(5000.00, $second['output_vat'], 0.001, 'Přepočet ignoruje vlastní zúčtovací doklad.');

        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM journal_entries WHERE supplier_id = ? AND source_type = 'vat_clearing'"
        );
        $stmt->execute([$this->supplierId]);
        self::assertSame(1, (int) $stmt->fetchColumn(), 'Nikdy víc než jeden doklad na období.');

        $byCode = $this->linesByCode((int) $second['entry_id']);
        self::assertEqualsWithDelta(5000.00, $byCode['343.200']['debit'], 0.001, 'Částka se opakovaným během nezdvojila.');
    }

    /** Nový doklad v období po zúčtování → re-run částku dopočítá, nepřičte. */
    public function testRerunAfterLateDocumentRecomputesTotal(): void
    {
        $this->bookOutputVat(1000.00, self::YEAR . '-08-10', 900031);
        $this->clearing->postForPeriod($this->supplierId, self::YEAR, 8);

        $this->bookOutputVat(500.00, self::YEAR . '-08-20', 900032);
        $again = $this->clearing->postForPeriod($this->supplierId, self::YEAR, 8);

        self::assertEqualsWithDelta(1500.00, $again['output_vat'], 0.001);
        $byCode = $this->linesByCode((int) $again['entry_id']);
        self::assertEqualsWithDelta(1500.00, $byCode['343.200']['debit'], 0.001);
    }

    public function testZeroPeriodPostsNothing(): void
    {
        $result = $this->clearing->postForPeriod($this->supplierId, self::YEAR, 9);

        self::assertSame(VatClearingService::STATUS_ZERO, $result['status']);
        self::assertNull($result['entry_id']);
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM journal_entries WHERE supplier_id = ? AND source_type = 'vat_clearing'"
        );
        $stmt->execute([$this->supplierId]);
        self::assertSame(0, (int) $stmt->fetchColumn(), 'Prázdné období nezakládá doklad.');
    }

    /** Nadměrný odpočet: pohledávka za FÚ, tedy debetní zůstatek 343.900. */
    public function testExcessDeductionLeavesReceivableOnSettlementAccount(): void
    {
        $this->bookOutputVat(1000.00, self::YEAR . '-10-05', 900041);
        $this->bookInputVat(2500.00, self::YEAR . '-10-06', 900042);

        $result = $this->clearing->postForPeriod($this->supplierId, self::YEAR, 10);

        self::assertEqualsWithDelta(-1500.00, $result['settlement'], 0.001);
        self::assertEqualsWithDelta(
            -1500.00,
            $this->netCredit('343.900', self::YEAR . '-10-01', self::YEAR . '-10-31'),
            0.001,
            'Debetní zůstatek 343.900 = nadměrný odpočet (pohledávka za FÚ).',
        );
    }

    /** Čtvrtletní plátce zúčtovává CELÉ čtvrtletí jedním dokladem k jeho poslednímu dni. */
    public function testQuarterlyPayerClearsWholeQuarterAtOnce(): void
    {
        $this->setVatPeriod('quarterly');
        $this->bookOutputVat(300.00, self::YEAR . '-04-10', 900051);
        $this->bookOutputVat(700.00, self::YEAR . '-06-25', 900052);
        $this->bookOutputVat(999.00, self::YEAR . '-07-01', 900053); // už Q3 — nesmí se přimíchat

        $result = $this->clearing->postForPeriod($this->supplierId, self::YEAR, 5);

        self::assertSame('quarterly', $result['period_type']);
        self::assertSame(self::YEAR . '-06-30', $result['period_end']);
        self::assertSame('Q2/' . self::YEAR, $result['period_label']);
        self::assertEqualsWithDelta(1000.00, $result['output_vat'], 0.001, 'Do Q2 patří jen duben–červen.');
    }

    /** Uzávěrkové převody (702/701) nejsou daňová transakce a do obratu nepatří. */
    public function testClosingEntriesAreExcludedFromTurnover(): void
    {
        $this->bookOutputVat(2000.00, self::YEAR . '-11-10', 900061);
        $this->posting->postDocument($this->supplierId, 'closing', 900062, [
            ['account_code' => '343.200', 'side' => 'debit', 'amount' => 2000.00],
            ['account_code' => '702', 'side' => 'credit', 'amount' => 2000.00],
        ], ['entry_date' => self::YEAR . '-11-30', 'description' => 'Uzavření knih']);

        $result = $this->clearing->postForPeriod($this->supplierId, self::YEAR, 11);

        self::assertEqualsWithDelta(2000.00, $result['output_vat'], 0.001, 'Uzávěrkový převod obrat DPH neovlivní.');
    }

    /** Koncept (posted_at NULL) ještě není v knihách — do zúčtování nepatří. */
    public function testDraftEntriesAreExcluded(): void
    {
        $this->posting->postDocument($this->supplierId, 'manual', 900071, [
            ['account_code' => '311', 'side' => 'debit', 'amount' => 700.00],
            ['account_code' => '343.200', 'side' => 'credit', 'amount' => 700.00],
        ], ['entry_date' => self::YEAR . '-12-10', 'description' => 'Koncept', 'posted' => false]);

        $result = $this->clearing->postForPeriod($this->supplierId, self::YEAR, 12);

        self::assertSame(VatClearingService::STATUS_ZERO, $result['status'], 'Koncept se nezúčtovává.');
    }

    public function testDryRunComputesButWritesNothing(): void
    {
        $this->bookOutputVat(1234.00, self::YEAR . '-05-10', 900081);

        $result = $this->clearing->postForPeriod($this->supplierId, self::YEAR, 5, [], true);

        self::assertSame(VatClearingService::STATUS_DRY_RUN, $result['status']);
        self::assertEqualsWithDelta(1234.00, $result['output_vat'], 0.001);
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM journal_entries WHERE supplier_id = ? AND source_type = 'vat_clearing'"
        );
        $stmt->execute([$this->supplierId]);
        self::assertSame(0, (int) $stmt->fetchColumn());
    }

    /** Bez analytik (tenant si je smazal / nedoběhlá migrace) se doklad nedělá, ale nic nespadne. */
    public function testMissingAnalyticsSkipInsteadOfFailing(): void
    {
        $this->bookOutputVat(100.00, self::YEAR . '-06-10', 900091);
        $this->db->pdo()
            ->prepare("UPDATE chart_of_accounts SET is_active = 0 WHERE supplier_id = ? AND account_code = '343.900'")
            ->execute([$this->supplierId]);

        $result = $this->clearing->postForPeriod($this->supplierId, self::YEAR, 6);

        self::assertSame(VatClearingService::STATUS_MISSING_ACCOUNTS, $result['status']);
        self::assertNull($result['entry_id']);
    }

    /** Tenant s plochým 343 (override kontací) nemá co převádět — doklad se nedělá. */
    public function testFlatVatAccountTenantIsSkipped(): void
    {
        $pdo = $this->db->pdo();
        foreach ([['vat.clearing.input', '343', '343'], ['vat.clearing.output', '343', '343']] as [$key, $d, $c]) {
            $pdo->prepare(
                'INSERT INTO posting_rules (supplier_id, rule_key, description, debit_account_code, credit_account_code, priority, is_active)
                 VALUES (?, ?, ?, ?, ?, 100, 1)'
            )->execute([$this->supplierId, $key, 'ploché 343', $d, $c]);
        }

        $result = $this->clearing->postForPeriod($this->supplierId, self::YEAR, 6);

        self::assertSame(VatClearingService::STATUS_FLAT_VAT_ACCOUNT, $result['status']);
    }

    public function testCandidateSupplierIdsSkipsTaxEvidenceAndNonPayers(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare("UPDATE supplier SET accounting_mode = 'tax_evidence' WHERE id = ?")->execute([$this->supplierId]);
        self::assertNotContains($this->supplierId, $this->clearing->candidateSupplierIds());

        $pdo->prepare("UPDATE supplier SET accounting_mode = 'double_entry', is_vat_payer = 0, is_identified = 0 WHERE id = ?")
            ->execute([$this->supplierId]);
        self::assertNotContains($this->supplierId, $this->clearing->candidateSupplierIds(), 'Neplátce zúčtování DPH nedělá.');

        $pdo->prepare('UPDATE supplier SET is_vat_payer = 1 WHERE id = ?')->execute([$this->supplierId]);
        self::assertContains($this->supplierId, $this->clearing->candidateSupplierIds());
    }

    /** Identifikovaná osoba podává měsíčně bez ohledu na `vat_period`. */
    public function testIdentifiedPersonIsAlwaysMonthly(): void
    {
        $this->db->pdo()
            ->prepare("UPDATE supplier SET vat_period = 'quarterly', is_vat_payer = 0, is_identified = 1 WHERE id = ?")
            ->execute([$this->supplierId]);

        self::assertSame('monthly', $this->clearing->vatPeriodFor($this->supplierId));
    }

    /** Doklad musí jít po zúčtování vypořádat bankou proti 343.900 (kontace vat.payment). */
    public function testSettlementAccountIsWhatTheBankPaymentTargets(): void
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT debit_account_code FROM posting_rules
              WHERE rule_key = 'vat.payment' AND is_active = 1 AND (supplier_id = ? OR supplier_id IS NULL)
              ORDER BY (supplier_id IS NULL) ASC, priority DESC LIMIT 1"
        );
        $stmt->execute([$this->supplierId]);

        self::assertSame(
            PostingService::VAT_SETTLEMENT_ACCOUNT,
            (string) $stmt->fetchColumn(),
            'Úhrada DPH musí mířit na týž účet, na který zúčtovací doklad daň převedl.',
        );
    }
}
