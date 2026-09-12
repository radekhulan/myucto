<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollHealthInsuranceOverviewAction;
use MyInvoice\Action\Payroll\PayrollJmhzPreparationAction;
use MyInvoice\Action\Payroll\PayrollJmhzSubmissionFreezeAction;
use MyInvoice\Action\Payroll\PayrollJmhzXmlDryRunAction;
use MyInvoice\Repository\AccountingModeRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Repository\Payroll\PayrollComponentJmhzMappingRepository;
use MyInvoice\Service\Payroll\Net\PayrollNetResultQueryService;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Run\PayrollRunCommandOutcome;
use MyInvoice\Service\Payroll\Run\PayrollRunCommandResult;
use MyInvoice\Service\Payroll\Run\PayrollRunCommandService;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzPvpojPreviewService;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Response;

/**
 * Jeden izolovaný měsíční řez: HPP, DPČ a DPP, dvě složky, dovolená,
 * výpočet a schválení jednou účetní, zdravotní přehled a JMHZ preview.
 * Vše běží v transakci nad myucto_test a tearDown ji vrátí zpět.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollSyntheticFullFlowTest extends TestCase
{
    use PayrollFullFlowTrait;

    private PayrollHealthInsuranceOverviewAction $healthOverview;
    /** @var list<array{employee_id:int,employment_id:int,name:string}> */
    private array $people = [];

    protected function setUp(): void
    {
        $this->bootPayrollFullFlow();
        $healthOverview = $this->container->get(PayrollHealthInsuranceOverviewAction::class);
        if (!$healthOverview instanceof PayrollHealthInsuranceOverviewAction) {
            throw new \RuntimeException('Zdravotní přehled není dostupný.');
        }
        $this->healthOverview = $healthOverview;

        $officeId = $this->createOffice();
        $this->configureSocialInsuranceOutput($officeId);
        $this->configureHealthInsuranceOutput();
        $baseComponentId = $this->createComponent('MZDA_MESICNI_FLOW', 'base_wage', 'regular');
        $bonusComponentId = $this->createComponent('ODMENA_FLOW', 'bonus', 'one_off');
        $definitions = [
            [
                'name' => 'Alice Syntetická',
                'gross' => 4_200_000,
                'employment_type' => 'hpp',
                'relation_type' => 'employment',
                'weekly_hours' => 40,
                'workload_basis_points' => 10_000,
            ],
            [
                'name' => 'Boris Syntetický',
                'gross' => 3_600_000,
                'employment_type' => 'dpc',
                'relation_type' => 'dpc',
                'weekly_hours' => 20,
                'workload_basis_points' => 5_000,
            ],
            [
                'name' => 'Cyril Syntetický',
                'gross' => 1_500_000,
                'employment_type' => 'dpp',
                'relation_type' => 'dpp',
                'weekly_hours' => 10,
                'workload_basis_points' => 2_500,
            ],
        ];
        foreach ($definitions as $index => $definition) {
            $person = $this->createEmployment(
                $officeId,
                $definition['name'],
                $index + 1,
                $definition['employment_type'],
                $definition['relation_type'],
                $definition['weekly_hours'],
                $definition['workload_basis_points'],
            );
            $this->people[] = $person;
            $this->createApprovedInput($person, $baseComponentId, $definition['gross'], 'base-' . ($index + 1));
        }
        $this->createApprovedInput($this->people[0], $bonusComponentId, 25_000, 'bonus-1');
        $this->createApprovedVacation($this->people[1]['employment_id']);
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    public function testMixedEmploymentMonthReachesApprovedRunAndStatutoryOutputs(): void
    {
        self::assertCount(3, $this->people);
        self::assertSame(1, $this->countScenarioRows('payroll_absences'));
        // Pátým vstupem je náhrada mzdy za dovolenou (§ 222 odst. 1 ZP), kterou
        // schválení absence materializuje vedle tří základů a jedné odměny.
        self::assertSame(5, $this->countScenarioRows('payroll_inputs'));
        self::assertSame(
            [
                ['employment_type' => 'hpp', 'relation_type' => 'employment'],
                ['employment_type' => 'dpc', 'relation_type' => 'dpc'],
                ['employment_type' => 'dpp', 'relation_type' => 'dpp'],
            ],
            $this->employmentTypes(),
        );

        $run = $this->runs->createRun(
            $this->supplierId,
            '2026-06-01',
            '2026-07-15',
            null,
            $this->actors[0],
        );
        $locked = $this->runs->lockInputs(
            $this->supplierId,
            (int) $run['id'],
            (int) $run['row_version'],
            'full-flow-lock',
            $this->actors[0],
        );
        $calculated = $this->runs->calculate(
            $this->supplierId,
            (int) $run['id'],
            (int) $locked->run['row_version'],
            'full-flow-calculate',
            $this->actors[0],
        );
        self::assertSame('calculated', $calculated->run['status']);
        self::assertCount(3, $calculated->revision['result_snapshot']['people']);
        self::assertSame(
            1,
            $this->frozenAbsenceCount($calculated->revision['input_snapshot']),
        );
        // 9 325 000 základů a odměn + 600 000 náhrady za dovolenou
        // (8 h x 750 Kč průměrného výdělku).
        self::assertSame(
            9_925_000,
            $calculated->revision['result_snapshot']['totals']['source_amount_minor'],
        );
        $blockers = $this->blockingValidations((int) $calculated->revision['id']);
        self::assertSame([], $blockers, CanonicalJson::encode($blockers));

        $reviewed = $this->runs->review(
            $this->supplierId,
            (int) $run['id'],
            (int) $calculated->run['row_version'],
            'full-flow-review',
            $this->actors[0],
        );
        $approved = $this->runs->approve(
            $this->supplierId,
            (int) $run['id'],
            (int) $reviewed->run['row_version'],
            'full-flow-approve',
            $this->actors[0],
        );
        self::assertSame('approved', $approved->run['status']);
        self::assertSame($approved->revision['calculated_by'], $approved->revision['reviewed_by']);
        self::assertSame($approved->revision['reviewed_by'], $approved->revision['approved_by']);

        $revisionId = (int) $approved->revision['id'];

        /*
         * Rozklad čisté mzdy po osobách MUSÍ jít přečíst hned po schválení běhu,
         * bez jakéhokoliv dalšího zápisu. Je to jediná cesta, kterou čte
         * `PayrollNetResultAction` i součinnost exekutorům (XMLZAM), a stojí
         * VÝHRADNĚ na zmrazené revizi — ne na `payroll_net_results`, do kterých
         * se nikdy nezapisovalo (viz {@see \MyInvoice\Repository\Payroll\PayrollNetRepository}).
         * Kdyby ta cesta ležela na nějaké další perzistenci, tenhle test spadne
         * dřív, než se to projeví prázdnou obrazovkou v ostrém provozu.
         */
        $netResults = $this->container->get(PayrollNetResultQueryService::class);
        if (!$netResults instanceof PayrollNetResultQueryService) {
            throw new \RuntimeException('Výsledkové API čisté mzdy není dostupné.');
        }
        $netTotal = 0;
        foreach ($this->people as $person) {
            $breakdown = $netResults->breakdown(
                $this->supplierId,
                $revisionId,
                $person['employee_id'],
            );
            self::assertSame($person['employee_id'], $breakdown['person']['employee_id']);
            self::assertGreaterThan(0, $breakdown['income']['gross_minor']);
            self::assertGreaterThan(0, $breakdown['net_payable_minor']);
            self::assertSame(
                $breakdown['net_payable_minor'] - $breakdown['enforcement_withheld_minor'],
                $breakdown['payable_after_enforcement_minor'],
            );
            $netTotal += $breakdown['net_payable_minor'];
        }
        self::assertGreaterThan(0, $netTotal);
        self::assertSame(
            [0, 0],
            $this->deadNetTableCounts($revisionId),
            'Mrtvé tabulky čisté mzdy musí zůstat prázdné — zdroj pravdy je zmrazená revize.',
        );

        $response = $this->healthOverview->index(
            $this->request('GET', "/api/payroll/submissions/health-overviews/{$revisionId}"),
            new Response(),
            ['revisionId' => (string) $revisionId],
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $overview = $this->json($response);
        self::assertFalse(
            $overview['electronic_submission']['direct_portal']['supported'],
        );
        self::assertSame(
            'health_insurance_portal_transport_undocumented',
            $overview['electronic_submission']['direct_portal']['reason_code'],
        );
        self::assertTrue($overview['electronic_submission']['isds']['supported']);
        self::assertTrue($overview['electronic_submission']['isds']['requires_ready']);
        self::assertTrue(
            $overview['electronic_submission']['isds']['requires_production_gate'],
        );
        self::assertNotEmpty($overview['items']);
        self::assertSame('111', $overview['items'][0]['insurer']['code']);
        self::assertCount(3, $overview['items'][0]['people']);

        $download = $this->healthOverview->download(
            $this->request('GET', "/api/payroll/submissions/health-overviews/{$revisionId}/111/download"),
            new Response(),
            ['revisionId' => (string) $revisionId, 'insurerCode' => '111'],
        );
        self::assertSame(200, $download->getStatusCode(), (string) $download->getBody());
        self::assertSame(hash('sha256', (string) $download->getBody()), $download->getHeaderLine('Content-SHA256'));

        $jmhz = $this->container->get(JmhzPvpojPreviewService::class);
        if (!$jmhz instanceof JmhzPvpojPreviewService) {
            throw new \RuntimeException('JMHZ PVPOJ preview není dostupné.');
        }
        $preview = $jmhz->preview($this->supplierId, $revisionId);
        self::assertSame('2026-06', $preview->period);
        self::assertSame('internal_jmhz_pvpoj_preview', $preview->toArray()['document_kind']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $preview->sha256());
    }

    /**
     * C-17 — jeden běh nad jedním datovým řezem až do `closed`.
     *
     * Dosud končil syntetický tok u `approve` a zbytek řetězce
     * (`post` → `prepare_payments` → `mark_paid` → `close`) se testoval jinde,
     * nad jinými daty a s nastrčenými materializéry. Právě ve švech mezi těmi
     * bloky ale žije akceptační kritérium „mzda = závazky = platby =
     * účetnictví": čísla si musí odpovídat napříč čtyřmi různými úložišti
     * (zmrazená revize, deník, platební závazky, reconciliation ledger).
     * Nesoulad, který vznikne jen na přechodu mezi dvěma příkazy, nemá jinou
     * šanci vyplavat — proto tenhle test jede VÝHRADNĚ přes služby z kontejneru
     * (žádné stuby), aby švy byly ty produkční.
     */
    public function testMixedEmploymentRunReachesClosedWithLedgerLiabilitiesAndPaymentsInAgreement(): void
    {
        $commands = $this->containerCommandService();
        // Účetní můstek se rozhoduje podle historie režimu, ne podle sloupce na
        // firmě — bez záznamu by `post` skončil jako „daňová evidence".
        $accountingModes = $this->container->get(AccountingModeRepository::class);
        if (!$accountingModes instanceof AccountingModeRepository) {
            throw new \RuntimeException('Evidence účetního režimu není dostupná.');
        }
        $accountingModes->record($this->supplierId, '2026-01-01', 'double_entry');
        $this->db->pdo()->prepare(
            'INSERT INTO accounting_periods
                (supplier_id, fiscal_year, starts_on, ends_on, status)
             VALUES (?, 2026, "2026-01-01", "2026-12-31", "open")',
        )->execute([$this->supplierId]);
        $chart = $this->container->get(ChartOfAccountsSeeder::class);
        if (!$chart instanceof ChartOfAccountsSeeder) {
            throw new \RuntimeException('Seed účtové osnovy není dostupný.');
        }
        $chart->seedForSupplier($this->supplierId);
        $this->configureIncomeTaxOutput();
        foreach ($this->people as $index => $person) {
            $this->enableBankPayout($person['employee_id'], $index + 1);
        }

        $approved = $this->approveMixedEmploymentRun($commands);
        $runId = (int) $approved->run['id'];
        $revisionId = (int) $approved->revision['id'];
        self::assertSame('approved', $approved->run['status']);

        $netTotal = $this->netPayableTotal($revisionId);
        self::assertGreaterThan(0, $netTotal);

        /*
         * Šev 1 — `post`: běh se posunul a v deníku VZNIKL vyrovnaný zápis,
         * jehož osobní náklad sedí na součet zmrazené revize. Kdyby se sem
         * dostal běh bez účetního zápisu (nebo se zápisem o jiné částce),
         * „mzda = účetnictví" už neplatí a nikdo by si toho nevšiml.
         */
        $posted = $commands->post(
            $this->supplierId,
            $runId,
            (int) $approved->run['row_version'],
            'full-flow-post',
            $this->actors[0],
        );
        self::assertSame('posted', $posted->run['status']);
        self::assertSame(
            PayrollRunCommandOutcome::POSTED,
            $posted->outcome?->outcome,
            CanonicalJson::encode($posted->outcome?->details ?? []),
        );
        $batch = $this->postingBatch($revisionId);
        self::assertSame('posted', $batch['status']);
        self::assertSame(
            $batch['journal_entry_id'],
            $posted->outcome?->details['journal_entry_id'] ?? null,
        );
        $journalEntryId = (int) $batch['journal_entry_id'];
        [$debitTotal, $creditTotal] = $this->journalTotals($journalEntryId);
        self::assertSame($debitTotal, $creditTotal);
        self::assertSame(
            9_925_000,
            $this->journalBalanceMinor($journalEntryId, ['521']),
            'Osobní náklad v deníku musí sedět na hrubý objem zmrazené revize.',
        );
        // Závazkové účty (331/366 vůči lidem, 336 vůči pojišťovnám, 342 finančnímu
        // úřadu) drží přesně to, co se má následně vyplatit a odvést.
        $ledgerPayables = $this->journalBalanceMinor(
            $journalEntryId,
            ['331', '336', '342', '366'],
        );
        self::assertSame(
            $netTotal,
            $this->journalBalanceMinor($journalEntryId, ['331', '366']),
            'Závazek vůči lidem v deníku musí sedět na čistou mzdu z revize.',
        );

        /*
         * Šev 2 — `prepare_payments`: zmaterializované závazky musí být tentýž
         * objem peněz, jaký deník předepsal. Rozejít se to může tiše: jedna
         * strana zaokrouhlí, druhá vynechá druh závazku, a rozdíl se ukáže až
         * na výpisu z banky.
         */
        $this->activateModuleForProduction();
        $prepared = $commands->preparePayments(
            $this->supplierId,
            $runId,
            (int) $posted->run['row_version'],
            'full-flow-prepare-payments',
            $this->actors[0],
        );
        self::assertSame('payment_ready', $prepared->run['status']);
        self::assertSame(
            PayrollRunCommandOutcome::PAYMENTS_PREPARED,
            $prepared->outcome?->outcome,
        );
        $liabilities = $this->liabilities($revisionId);
        self::assertNotSame([], $liabilities);
        self::assertSame(
            [],
            array_values(array_filter(
                $liabilities,
                static fn (array $liability): bool
                    => $liability['direction'] !== 'outgoing',
            )),
            'Mzdový běh nesmí vyrobit pohledávku — všechno jsou výdaje.',
        );
        $liabilityTotal = array_sum(
            array_column($liabilities, 'amount_minor'),
        );
        self::assertSame(
            $netTotal,
            array_sum(array_column(array_values(array_filter(
                $liabilities,
                static fn (array $liability): bool
                    => $liability['liability_kind'] === 'net_wage',
            )), 'amount_minor')),
            'Závazky čisté mzdy musí sedět na rozklad čisté mzdy z revize.',
        );
        self::assertSame(
            $ledgerPayables,
            $liabilityTotal,
            'Platební závazky se musí rovnat závazkovým účtům účetního zápisu.',
        );
        // Pojistka proti prázdné shodě: kdyby se institucionální závazky
        // nezmaterializovaly vůbec, rovnost výš by pořád „platila".
        self::assertGreaterThan(
            $netTotal,
            $liabilityTotal,
            'Kromě čisté mzdy musí vzniknout i odvody institucím.',
        );

        /*
         * Šev 3 — `mark_paid`: brána pouští běh dál jen tehdy, když
         * reconciliation ledger pokrývá KAŽDÝ závazek do haléře.
         */
        foreach ($liabilities as $liability) {
            $this->settleLiability($liability['id'], $liability['amount_minor']);
        }
        $paid = $commands->markPaid(
            $this->supplierId,
            $runId,
            (int) $prepared->run['row_version'],
            'full-flow-mark-paid',
            $this->actors[0],
        );
        self::assertSame('paid', $paid->run['status']);
        self::assertSame(
            PayrollRunCommandOutcome::PAYMENTS_SETTLED,
            $paid->outcome?->outcome,
        );
        self::assertSame(
            $liabilityTotal,
            $paid->outcome?->details['settled_minor'] ?? null,
        );
        self::assertSame(
            $liabilityTotal,
            $this->settledTotal($revisionId),
            'Spárované úhrady musí sedět na závazky do haléře.',
        );

        // Šev 4 — `close`: teprve uzavřený běh je hotová mzda.
        $closed = $commands->close(
            $this->supplierId,
            $runId,
            (int) $paid->run['row_version'],
            'full-flow-close',
            $this->actors[0],
        );
        self::assertSame('closed', $closed->run['status']);
        self::assertNull($closed->outcome);
        self::assertSame(
            'closed',
            (string) $this->scalar(
                'SELECT status FROM payroll_runs WHERE supplier_id = ? AND id = ?',
                [$this->supplierId, $runId],
            ),
        );
    }

    public function testLowIncomeHppWithoutDeclarationReachesValidJmhzTestSubmissionWithoutTransport(): void
    {
        $this->assertLowIncomeEmploymentWithoutDeclarationReachesValidJmhzTestSubmissionWithoutTransport(
            'hpp',
            'employment',
            'employee',
            4,
            'jmhz-hpp-4500',
        );
    }

    public function testStatutoryBodyAt4500WithoutDeclarationOrTaxpayerCreditReachesValidScenarioThreeSubmissionWithoutTransport(): void
    {
        $this->assertLowIncomeEmploymentWithoutDeclarationReachesValidJmhzTestSubmissionWithoutTransport(
            'statutory_body',
            'statutory_body',
            'managing_partner',
            5,
            'jmhz-statutory-body-4500',
            false,
            'ineligible',
            true,
            false,
        );
    }

    public function testPartnerDependentAt4500WithoutDeclarationOrTaxpayerCreditReachesValidScenarioThreeSubmissionWithoutTransport(): void
    {
        $this->assertLowIncomeEmploymentWithoutDeclarationReachesValidJmhzTestSubmissionWithoutTransport(
            'hpp',
            'partner_dependent',
            'managing_partner',
            6,
            'jmhz-partner-dependent-4500',
            false,
            'ineligible',
            true,
            false,
        );
    }

    private function assertLowIncomeEmploymentWithoutDeclarationReachesValidJmhzTestSubmissionWithoutTransport(
        string $employmentType,
        string $relationType,
        string $taxpayerType,
        int $sequence,
        string $scenario,
        ?bool $taxpayerCreditTaxpayer = null,
        ?string $otherWithholdingEligibility = null,
        bool $scenarioThree = false,
        bool $withAverageEarning = true,
    ): void {
        $officeId = $this->createOffice('JMHZ', 'Syntetická registrace JMHZ', '9990001234');
        $person = $this->createEmployment(
            $officeId,
            'Dana Testovací',
            $sequence,
            $employmentType,
            $relationType,
            40,
            10_000,
            false,
            '2026-07-01',
            false,
            $taxpayerType,
            $taxpayerCreditTaxpayer,
            $otherWithholdingEligibility,
        );
        $this->assertEmployeeTaxConfiguration($person['employee_id'], false, $taxpayerCreditTaxpayer ?? false);
        if ($otherWithholdingEligibility !== null) {
            $this->assertOtherWithholdingEligibility($person['employment_id'], $otherWithholdingEligibility);
        }
        $this->completeJmhzEmployment($person, $scenarioThree ? 'S' : '1');
        $this->createApprovedTimeMonth($person['employment_id'], '2026-07');
        if ($withAverageEarning) {
            $this->createApprovedAverage($person['employment_id'], 3);
        }
        $this->assignJmhzIdentity($person);

        $baseComponentId = $this->componentId('MZDA_MESICNI_FLOW');
        $mappings = $this->container->get(PayrollComponentJmhzMappingRepository::class);
        if (!$mappings instanceof PayrollComponentJmhzMappingRepository) {
            throw new \RuntimeException('Mapování mzdových složek JMHZ není dostupné.');
        }
        $mappings->put($this->supplierId, $baseComponentId, '10329', null, $this->actors[0]);
        $this->createApprovedInput($person, $baseComponentId, 450_000, $scenario . '-base', '2026-07-01');

        $run = $this->runs->createRun(
            $this->supplierId,
            '2026-07-01',
            '2026-08-15',
            $officeId,
            $this->actors[0],
        );
        $locked = $this->runs->lockInputs(
            $this->supplierId,
            (int) $run['id'],
            (int) $run['row_version'],
            'jmhz-flow-lock',
            $this->actors[0],
        );
        $calculated = $this->runs->calculate(
            $this->supplierId,
            (int) $run['id'],
            (int) $locked->run['row_version'],
            'jmhz-flow-calculate',
            $this->actors[0],
        );
        self::assertSame([], $this->blockingValidations((int) $calculated->revision['id']));
        $health = $this->healthResultSnapshot((int) $calculated->revision['id']);
        self::assertSame(20_300, $health['employee_standard_contribution_minor_units']);
        self::assertSame(241_600, $health['employee_minimum_top_up_minor_units']);
        self::assertSame(261_900, $health['employee_contribution_minor_units']);
        self::assertSame(40_500, $health['employer_contribution_minor_units']);
        if ($scenarioThree) {
            $statutory = $calculated->revision['result_snapshot']['statutory']['people'][0];
            self::assertSame('advance', $statutory['income_tax']['relationships'][0]['regime']);
            self::assertSame(450_000, $statutory['income_tax']['advance_tax']['taxable_income_minor_units']);
            self::assertSame(67_500, $statutory['income_tax']['advance_tax']['tax_after_credits_minor_units']);
            self::assertSame(450_000, $statutory['social_insurance']['relationships'][0]['assessment_base_minor_units']);
        }
        $reviewed = $this->runs->review(
            $this->supplierId,
            (int) $run['id'],
            (int) $calculated->run['row_version'],
            'jmhz-flow-review',
            $this->actors[0],
        );
        $approved = $this->runs->approve(
            $this->supplierId,
            (int) $run['id'],
            (int) $reviewed->run['row_version'],
            'jmhz-flow-approve',
            $this->actors[0],
        );
        $revisionId = (int) $approved->revision['id'];

        $prepare = $this->container->get(PayrollJmhzPreparationAction::class);
        $dryRun = $this->container->get(PayrollJmhzXmlDryRunAction::class);
        $freeze = $this->container->get(PayrollJmhzSubmissionFreezeAction::class);
        if (!$prepare instanceof PayrollJmhzPreparationAction
            || !$dryRun instanceof PayrollJmhzXmlDryRunAction
            || !$freeze instanceof PayrollJmhzSubmissionFreezeAction
        ) {
            throw new \RuntimeException('Akce měsíčního hlášení JMHZ nejsou dostupné.');
        }
        $preparationResponse = $prepare(
            $this->request('POST', "/api/payroll/jmhz/preparations/{$revisionId}")
                ->withHeader('Idempotency-Key', $scenario)
                ->withParsedBody(['environment' => 'test']),
            new Response(),
            ['revisionId' => (string) $revisionId],
        );
        self::assertSame(201, $preparationResponse->getStatusCode(), (string) $preparationResponse->getBody());
        $preparation = $this->json($preparationResponse);
        self::assertSame('source_ready', $preparation['readiness_status'], CanonicalJson::encode($preparation['issues']));
        self::assertSame(0, $preparation['issue_count']);

        $preparationId = (int) $preparation['id'];
        $dryRunResponse = $dryRun(
            $this->request('GET', "/api/payroll/jmhz/preparations/{$preparationId}/test")
                ->withQueryParams(['environment' => 'test', 'office' => (string) $officeId]),
            new Response(),
            ['preparationId' => (string) $preparationId],
        );
        self::assertSame(200, $dryRunResponse->getStatusCode(), (string) $dryRunResponse->getBody());
        $tested = $this->json($dryRunResponse);
        self::assertSame('dry_run_valid', $tested['status'], CanonicalJson::encode($tested));
        self::assertTrue($tested['controls']['submittable'], CanonicalJson::encode($tested['controls']));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $tested['xml_sha256']);
        self::assertSame(hash('sha256', $tested['xml']), $tested['xml_sha256']);
        self::assertStringContainsString('<form:zuctovanoCelkem>4500</form:zuctovanoCelkem>', $tested['xml']);
        self::assertStringContainsString('<form:vypoctenaZaloha>675</form:vypoctenaZaloha>', $tested['xml']);
        self::assertStringContainsString('<form:prohlaseniPoplatnika>false</form:prohlaseniPoplatnika>', $tested['xml']);
        self::assertMatchesRegularExpression('/<form:zdravPojZamestnanec>\s*<form:zdravotniPojisteni>2619<\/form:zdravotniPojisteni>\s*<\/form:zdravPojZamestnanec>/', $tested['xml']);
        if ($scenarioThree) {
            self::assertStringContainsString('<form:cinnostKS ', $tested['xml']);
            self::assertStringNotContainsString('<form:bezPriznaku>', $tested['xml']);
            self::assertStringNotContainsString('<form:zdravPojZamestnavatel>', $tested['xml']);
            self::assertStringNotContainsString('<form:vymerovaciZakladParagraf5>', $tested['xml']);
            self::assertStringContainsString('<form:kod>S++</form:kod>', $tested['xml']);
            self::assertStringContainsString('<form:vymerovaciZaklad>4500</form:vymerovaciZaklad>', $tested['xml']);
        } else {
            self::assertMatchesRegularExpression('/<form:zdravPojZamestnavatel>\s*<form:zdravotniPojisteni>405<\/form:zdravotniPojisteni>\s*<\/form:zdravPojZamestnavatel>/', $tested['xml']);
        }
        self::assertStringContainsString('<form:socialniPojisteni>320</form:socialniPojisteni>', $tested['xml']);
        self::assertStringContainsString('<form:socialniPojisteni>1116</form:socialniPojisteni>', $tested['xml']);

        $freezeResponse = $freeze(
            $this->request('POST', "/api/payroll/jmhz/preparations/{$preparationId}/submission")
                ->withParsedBody(['environment' => 'test', 'office' => $officeId]),
            new Response(),
            ['preparationId' => (string) $preparationId],
        );
        self::assertSame(201, $freezeResponse->getStatusCode(), (string) $freezeResponse->getBody());
        $submission = $this->json($freezeResponse);
        self::assertSame('test', $submission['environment']);
        self::assertSame('ready', $submission['status']);
        self::assertTrue($submission['created']);
        self::assertSame(0, $this->transportAttemptCount((int) $submission['submission_id']));
    }

    private function assertEmployeeTaxConfiguration(
        int $employeeId,
        bool $taxDeclarationSigned,
        bool $taxpayerCreditTaxpayer,
    ): void {
        $statement = $this->db->pdo()->prepare(
            'SELECT tax_declaration_signed, tax_credit_taxpayer
               FROM payroll_employees
              WHERE supplier_id = ? AND id = ?',
        );
        $statement->execute([$this->supplierId, $employeeId]);
        $configuration = $statement->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($configuration);
        self::assertSame($taxDeclarationSigned ? 1 : 0, (int) $configuration['tax_declaration_signed']);
        self::assertSame($taxpayerCreditTaxpayer ? 1 : 0, (int) $configuration['tax_credit_taxpayer']);
    }

    private function assertOtherWithholdingEligibility(int $employmentId, string $eligibility): void
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT other_withholding_eligibility
               FROM payroll_employment_terms
              WHERE supplier_id = ? AND employment_id = ?
              ORDER BY effective_from DESC, id DESC
              LIMIT 1',
        );
        $statement->execute([$this->supplierId, $employmentId]);
        self::assertSame($eligibility, $statement->fetchColumn());
    }

    private function createApprovedVacation(int $employmentId): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_shifts
                (supplier_id, employment_id, series_key, starts_at_utc,
                 ends_at_utc, timezone_name, break_minutes, status,
                 published_by, published_at)
             VALUES (?, ?, "flow-vacation", "2026-06-15 06:00:00",
                     "2026-06-15 14:30:00", "Europe/Prague", 30,
                     "published", ?, NOW())',
        )->execute([$this->supplierId, $employmentId, $this->actors[0]]);
        $average = $this->createApprovedAverage($employmentId);
        $absenceResponse = $this->absences->create(
            $this->request('POST', '/api/payroll/absences')->withParsedBody([
                'employment_id' => $employmentId,
                'absence_type' => 'vacation',
                'date_from' => '2026-06-15',
                'date_to' => '2026-06-15',
                'timezone_name' => 'Europe/Prague',
                'partial_first_minutes' => null,
                'partial_last_minutes' => null,
                'average_snapshot_id' => $average['id'],
                'note' => 'Syntetická dovolená full-flow.',
            ]),
            new Response(),
        );
        self::assertSame(201, $absenceResponse->getStatusCode(), (string) $absenceResponse->getBody());
        $absence = $this->json($absenceResponse)['absence'];
        $decision = $this->absences->decision(
            $this->request('POST', '/api/payroll/absences/decision')->withParsedBody([
                'row_version' => $absence['row_version'],
                'decision' => 'approved',
            ]),
            new Response(),
            ['id' => (string) $absence['id']],
        );
        self::assertSame(200, $decision->getStatusCode(), (string) $decision->getBody());
    }

    /** @return list<array{employment_type:string,relation_type:string}> */
    private function employmentTypes(): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT employee.employment_type, employment.relation_type
               FROM payroll_employments employment
               JOIN payroll_employees employee
                 ON employee.supplier_id = employment.supplier_id
                AND employee.id = employment.employee_id
              WHERE employment.supplier_id = ?
              ORDER BY employment.code',
        );
        $statement->execute([$this->supplierId]);
        return array_values($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @param array<string,mixed> $snapshot */
    private function frozenAbsenceCount(array $snapshot): int
    {
        $count = 0;
        foreach ($snapshot['people'] ?? [] as $person) {
            foreach ($person['employments'] ?? [] as $employment) {
                $count += count($employment['absences'] ?? []);
            }
        }
        return $count;
    }

    private function countScenarioRows(string $table): int
    {
        if (!in_array($table, ['payroll_absences', 'payroll_inputs'], true)) {
            throw new \InvalidArgumentException('Nepodporovaná tabulka scénáře.');
        }
        $statement = $this->db->pdo()->prepare("SELECT COUNT(*) FROM {$table} WHERE supplier_id = ?");
        $statement->execute([$this->supplierId]);
        return (int) $statement->fetchColumn();
    }

    /**
     * Počty řádků v `payroll_net_results` a `payroll_payout_allocations`.
     *
     * Obě tabulky jsou mrtvé: nikdy neměly produkčního zapisovatele a rozpis
     * výplaty dnes drží `payroll_payment_liabilities`. Kdyby je někdo znovu
     * zapojil, vznikl by druhý — a s tím prvním rozporný — rozpis týchž peněz,
     * navíc neměnný (migrace 1631). Nenulový počet je tedy regrese, ne pokrok.
     *
     * @return array{0:int,1:int}
     */
    private function deadNetTableCounts(int $revisionId): array
    {
        $counts = [];
        foreach (['payroll_net_results', 'payroll_payout_allocations'] as $table) {
            $statement = $this->db->pdo()->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE supplier_id = ? AND revision_id = ?"
            );
            $statement->execute([$this->supplierId, $revisionId]);
            $counts[] = (int) $statement->fetchColumn();
        }

        return [$counts[0], $counts[1]];
    }

    /**
     * Příkazová služba běhu SESTAVENÁ KONTEJNEREM.
     *
     * `setUp()` si ji skládá ručně jen se sedmi závislostmi, takže se s ní běh
     * nedostane za `approved`. Tady jde právě o zbytek řetězce, takže se bere
     * produkční drát — včetně skutečných materializérů závazků, kontroly úhrad
     * a produkční brány.
     */
    private function containerCommandService(): PayrollRunCommandService
    {
        $commands = $this->container->get(PayrollRunCommandService::class);
        if (!$commands instanceof PayrollRunCommandService) {
            throw new \RuntimeException('Příkazová služba mzdového běhu není dostupná.');
        }

        return $commands;
    }

    private function approveMixedEmploymentRun(
        PayrollRunCommandService $commands,
    ): PayrollRunCommandResult {
        $run = $commands->createRun(
            $this->supplierId,
            '2026-06-01',
            '2026-07-15',
            null,
            $this->actors[0],
        );
        $locked = $commands->lockInputs(
            $this->supplierId,
            (int) $run['id'],
            (int) $run['row_version'],
            'full-flow-closed-lock',
            $this->actors[0],
        );
        $calculated = $commands->calculate(
            $this->supplierId,
            (int) $run['id'],
            (int) $locked->run['row_version'],
            'full-flow-closed-calculate',
            $this->actors[0],
        );
        self::assertSame(
            [],
            $this->blockingValidations((int) $calculated->revision['id']),
        );
        $reviewed = $commands->review(
            $this->supplierId,
            (int) $run['id'],
            (int) $calculated->run['row_version'],
            'full-flow-closed-review',
            $this->actors[0],
        );

        return $commands->approve(
            $this->supplierId,
            (int) $run['id'],
            (int) $reviewed->run['row_version'],
            'full-flow-closed-approve',
            $this->actors[0],
        );
    }

    /**
     * Bez ověřeného účtu a výplatního pravidla se `prepare_payments` nehne —
     * není kam poslat čistou mzdu.
     */
    private function enableBankPayout(int $employeeId, int $sequence): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_person_accounts
                (supplier_id, employee_id, label, bank_account_ciphertext,
                 bank_account_hash, bank_account_masked,
                 allocation_basis_points, effective_from, is_active,
                 row_version, verification_source, verified_on, verified_by)
             VALUES (?, ?, "Syntetický účet", "enc:v2:synthetic-account",
                     UNHEX(?), "••••0005", 10000, "2026-01-01", 1, 1,
                     "user_verified", "2026-05-01", ?)',
        )->execute([
            $this->supplierId,
            $employeeId,
            hash('sha256', "synthetic-full-flow-account:{$this->supplierId}:{$sequence}"),
            $this->actors[0],
        ]);
        $accountId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_payout_rules
                (supplier_id, employee_id, allocation_reference,
                 destination_kind, destination_reference, allocation_kind,
                 priority_no, is_active)
             VALUES (?, ?, ?, "bank", ?, "remainder", 100, 1)',
        )->execute([
            $this->supplierId,
            $employeeId,
            "FULL-FLOW-REMAINDER-{$sequence}",
            "account:{$accountId}",
        ]);
    }

    /**
     * Produkční brána materializace závazků chce modul v `active`. Schválení
     * běhu ho tam překlopí jen při hotovém setupu, který tenhle syntetický řez
     * nesplňuje — a předmětem testu je platební řetězec, ne aktivace.
     */
    private function activateModuleForProduction(): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_module_state
                SET status = "active", activated_by = ?, activated_at = NOW()
              WHERE supplier_id = ?',
        )->execute([$this->actors[0], $this->supplierId]);
    }

    /** @return array<string,mixed> */
    private function postingBatch(int $revisionId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT status, journal_entry_id
               FROM payroll_posting_batches
              WHERE supplier_id = ? AND revision_id = ?',
        );
        $statement->execute([$this->supplierId, $revisionId]);
        $batch = $statement->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($batch);
        self::assertNotNull($batch['journal_entry_id']);

        return [
            'status' => (string) $batch['status'],
            'journal_entry_id' => (int) $batch['journal_entry_id'],
        ];
    }

    /** @return array{0:int,1:int} strana MD a strana D v haléřích */
    private function journalTotals(int $journalEntryId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT ROUND(SUM(CASE WHEN side = "debit" THEN amount ELSE 0 END) * 100) AS debit_minor,
                    ROUND(SUM(CASE WHEN side = "credit" THEN amount ELSE 0 END) * 100) AS credit_minor
               FROM journal_entry_lines
              WHERE supplier_id = ? AND entry_id = ?',
        );
        $statement->execute([$this->supplierId, $journalEntryId]);
        $totals = $statement->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($totals);

        return [(int) $totals['debit_minor'], (int) $totals['credit_minor']];
    }

    /**
     * Zůstatek účtových skupin v jednom účetním zápisu, v haléřích a se
     * znaménkem podle přirozené strany: u nákladů (5xx) MD − D, u závazků
     * (3xx) D − MD. Tím se dá porovnat účetní předpis s tím, co drží mzdová
     * evidence.
     *
     * @param list<string> $groupPrefixes
     */
    private function journalBalanceMinor(int $journalEntryId, array $groupPrefixes): int
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT account.account_code, line.side, line.amount
               FROM journal_entry_lines line
               JOIN chart_of_accounts account
                 ON account.id = line.account_id
              WHERE line.supplier_id = ? AND line.entry_id = ?',
        );
        $statement->execute([$this->supplierId, $journalEntryId]);
        $balance = 0;
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $line) {
            $code = (string) $line['account_code'];
            $matches = false;
            foreach ($groupPrefixes as $prefix) {
                if (str_starts_with($code, $prefix)) {
                    $matches = true;
                    break;
                }
            }
            if (!$matches) {
                continue;
            }
            $amount = (int) round(((float) $line['amount']) * 100);
            $expense = str_starts_with($code, '5');
            $debit = $line['side'] === 'debit';
            $balance += ($expense === $debit) ? $amount : -$amount;
        }

        return $balance;
    }

    /**
     * Součet čisté mzdy k výplatě přes výsledkové API — tedy tou cestou, kterou
     * čte i účetní na obrazovce, ne přes tabulku závazků, kterou právě ověřujeme.
     */
    private function netPayableTotal(int $revisionId): int
    {
        $netResults = $this->container->get(PayrollNetResultQueryService::class);
        if (!$netResults instanceof PayrollNetResultQueryService) {
            throw new \RuntimeException('Výsledkové API čisté mzdy není dostupné.');
        }
        $total = 0;
        foreach ($this->people as $person) {
            $breakdown = $netResults->breakdown(
                $this->supplierId,
                $revisionId,
                $person['employee_id'],
            );
            $total += (int) $breakdown['payable_after_enforcement_minor'];
        }

        return $total;
    }

    /** @return list<array{id:int,amount_minor:int,liability_kind:string,direction:string}> */
    private function liabilities(int $revisionId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, amount_minor, liability_kind, direction
               FROM payroll_payment_liabilities
              WHERE supplier_id = ? AND revision_id = ?
              ORDER BY id',
        );
        $statement->execute([$this->supplierId, $revisionId]);
        $liabilities = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $liabilities[] = [
                'id' => (int) $row['id'],
                'amount_minor' => (int) $row['amount_minor'],
                'liability_kind' => (string) $row['liability_kind'],
                'direction' => (string) $row['direction'],
            ];
        }

        return $liabilities;
    }

    /**
     * Platební dávku, položku, alokaci a bankovní důkaz zakládáme přímo —
     * předmětem téhle sady je brána `mark_paid` nad reconciliation ledgerem,
     * ne generování platebního souboru (to má vlastní testy).
     */
    private function settleLiability(int $liabilityId, int $amountMinor): void
    {
        $pdo = $this->db->pdo();
        $reference = 'full-flow-' . bin2hex(random_bytes(6));
        $pdo->prepare(
            'INSERT INTO payroll_payment_batches
                (supplier_id, batch_reference, channel, export_format,
                 planned_payment_date, payer_reference, declared_total_minor,
                 declared_item_count, snapshot_ciphertext, snapshot_hash,
                 idempotency_key_hash, created_by)
             VALUES (?, ?, "bank", "manual", "2026-07-15", "synthetic-payer",
                     ?, 1, ?, ?, UNHEX(?), ?)',
        )->execute([
            $this->supplierId,
            $reference,
            $amountMinor,
            'enc:v2:synthetic-batch',
            hash('sha256', "full-flow-batch:{$reference}"),
            hash('sha256', "full-flow-batch-key:{$reference}"),
            $this->actors[0],
        ]);
        $batchId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_payment_items
                (supplier_id, batch_id, item_reference, recipient_reference,
                 amount_minor, instruction_ciphertext, instruction_hash,
                 idempotency_key_hash)
             VALUES (?, ?, ?, "synthetic-recipient", ?, ?, ?, UNHEX(?))',
        )->execute([
            $this->supplierId,
            $batchId,
            $reference,
            $amountMinor,
            'enc:v2:synthetic-item',
            hash('sha256', "full-flow-item:{$reference}"),
            hash('sha256', "full-flow-item-key:{$reference}"),
        ]);
        $itemId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_payment_allocations
                (supplier_id, item_id, liability_id, amount_minor,
                 idempotency_key_hash)
             VALUES (?, ?, ?, ?, UNHEX(?))',
        )->execute([
            $this->supplierId,
            $itemId,
            $liabilityId,
            $amountMinor,
            hash('sha256', "full-flow-allocation-key:{$reference}"),
        ]);
        $allocationId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO bank_statements
                (supplier_id, file_name, file_hash, account_number, bank_code,
                 currency, statement_date, source)
             VALUES (?, ?, ?, "1000000005", "0100", "CZK", "2026-07-31", "gpc")',
        )->execute([
            $this->supplierId,
            "{$reference}.gpc",
            hash('sha256', "full-flow-statement:{$reference}"),
        ]);
        $statementId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO bank_transactions
                (statement_id, posted_at, amount, currency, description,
                 import_fingerprint)
             VALUES (?, "2026-07-15", ?, "CZK", ?, ?)',
        )->execute([
            $statementId,
            sprintf('-%d.%02d', intdiv($amountMinor, 100), $amountMinor % 100),
            "Syntetická úhrada {$reference}",
            hash('sha256', "full-flow-transaction:{$reference}"),
        ]);
        $transactionId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_payment_matches
                (supplier_id, allocation_id, event_kind, amount_minor,
                 bank_statement_id, bank_transaction_id,
                 idempotency_key_hash, matched_by)
             VALUES (?, ?, "matched", ?, ?, ?, UNHEX(?), ?)',
        )->execute([
            $this->supplierId,
            $allocationId,
            $amountMinor,
            $statementId,
            $transactionId,
            hash('sha256', "full-flow-match:{$reference}"),
            $this->actors[0],
        ]);
    }

    private function settledTotal(int $revisionId): int
    {
        return (int) $this->scalar(
            'SELECT COALESCE(SUM(payment_match.amount_minor), 0)
               FROM payroll_payment_matches payment_match
               JOIN payroll_payment_liabilities liability
                 ON liability.supplier_id = payment_match.supplier_id
                AND liability.id = payment_match.liability_id
              WHERE liability.supplier_id = ? AND liability.revision_id = ?',
            [$this->supplierId, $revisionId],
        );
    }
}
