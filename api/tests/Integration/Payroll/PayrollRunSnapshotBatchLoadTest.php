<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollEmployerPolicyRepository;
use MyInvoice\Repository\Payroll\PayrollRiskySavingsRepository;
use MyInvoice\Service\Payroll\Run\PayrollRunInputSnapshot;
use MyInvoice\Service\Payroll\Run\PayrollRunSnapshotBuilder;
use MyInvoice\Tests\Fixtures\Payroll\PayrollRunScaleFixture;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Snapshot mzdového běhu sahá do databáze MNOŽINOVĚ, ne po jedné osobě.
 *
 * Dřív připadalo na osobu ~57 round-tripů, takže běh nad 300 osobami vyrobil
 * uvnitř jedné transakce přes 17 tisíc dotazů a nedoběhl. Test hlídá obojí, co
 * u té opravy může selhat: že počet dotazů zůstane na počtu osob NEZÁVISLÝ,
 * a že se přitom nezměnil ani bajt kanonického JSONu — otisk snapshotu je
 * auditní závazek a porovnává se při přehrání běhu.
 */
#[Group('integration')]
final class PayrollRunSnapshotBatchLoadTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollRunSnapshotBuilder $builder;
    private PayrollEmployerPolicyRepository $policies;
    private PayrollRiskySavingsRepository $riskySavings;
    private int $sourceSupplierId;
    private int $supplierId;
    private int $actorId;
    private int $tenantOrdinal = 0;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        $container = Bootstrap::buildContainer();
        $db = $container->get(Connection::class);
        $builder = $container->get(PayrollRunSnapshotBuilder::class);
        $policies = $container->get(PayrollEmployerPolicyRepository::class);
        $riskySavings = $container->get(PayrollRiskySavingsRepository::class);
        if (!$db instanceof Connection
            || !$builder instanceof PayrollRunSnapshotBuilder
            || !$policies instanceof PayrollEmployerPolicyRepository
            || !$riskySavings instanceof PayrollRiskySavingsRepository
        ) {
            $this->markTestSkipped('Služby mzdového běhu nejsou dostupné.');
        }
        $this->db = $db;
        $this->builder = $builder;
        $this->policies = $policies;
        $this->riskySavings = $riskySavings;
        foreach ([
            'payroll_employments',
            'payroll_statutory_accumulator_openings',
            'payroll_enforcement_claims',
        ] as $table) {
            if (!$this->db->hasTable($table)) {
                $this->markTestSkipped('Mzdové migrace neproběhly.');
            }
        }

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) $this->scalar('SELECT MIN(id) FROM supplier', []);
        if ($sourceSupplierId <= 0) {
            $this->markTestSkipped('Chybí zdrojová firma.');
        }
        $this->sourceSupplierId = $sourceSupplierId;
        $pdo->beginTransaction();
        $this->newTenant();
    }

    /**
     * Založí čerstvou izolovanou firmu (a aktéra a účinnou politiku) a přepne na ni.
     *
     * Každý počet osob potřebuje vlastní firmu: opening balance zákonných kumulací
     * je append-only, takže staré řádky nejde smazat a znovu použít.
     */
    private function newTenant(): void
    {
        $pdo = $this->db->pdo();
        ++$this->tenantOrdinal;
        $this->supplierId = $this->createIsolatedSupplier($pdo, $this->sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')
            ->execute([$this->supplierId]);
        $actor = $pdo->prepare(
            'INSERT INTO users (email, password_hash, name, role, locale, is_active)
             VALUES (?, ?, "Syntetický aktér", "readonly", "cs", 1)'
        );
        $actor->execute([
            'mz30-' . bin2hex(random_bytes(6)) . '@invalid.example',
            '$2y$10$uses.only.synthetic.placeholder.hash00000000000000000',
        ]);
        $this->actorId = (int) $pdo->lastInsertId();
        $this->policies->create($this->supplierId, [
            'valid_from' => '2026-01-01',
            'valid_to' => null,
            'payday_day' => 10,
            'payday_month_offset' => 1,
            'payday_business_day_rule' => 'previous_business_day',
            'balance_rounding_mode' => 'exact_minor_units',
            'home_office_policy' => 'not_used',
            'travel_expense_policy' => 'not_used',
            'leave_entitlement_weeks' => 4,
            'four_eyes_required' => true,
            'automatic_calculation_enabled' => true,
            'automatic_posting_enabled' => true,
            'automatic_payments_enabled' => true,
            'delivery_channel' => 'disabled',
            'delivery_verified_on' => null,
            'source_kind' => 'manual',
            'source_reference' => 'synthetic:mz30-policy',
        ], $this->actorId);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
        if (isset($this->db)) {
            $this->db->close();
        }
    }

    /**
     * Počet dotazů nesmí růst s počtem osob.
     *
     * Před dávkováním to bylo 72 / 582 / 5 682 round-tripů pro 1 / 10 / 100 osob;
     * teď musí být všechny tři počty STEJNÉ. Rovnost je tvrdší tvrzení než horní
     * mez — chytí i to, kdyby někdo přidal jediný dotaz zpátky do smyčky.
     */
    public function testSnapshotQueryCountDoesNotGrowWithHeadcount(): void
    {
        $pdo = $this->db->pdo();
        $counts = [];
        $people = [];
        foreach ([1, 10, 100, 500] as $headcount) {
            if ($headcount > 1) {
                $this->newTenant();
            }
            $this->seed($headcount);
            $before = PayrollRunScaleFixture::statementRoundTrips($pdo);
            $snapshot = $this->build();
            $counts[$headcount] = PayrollRunScaleFixture::statementRoundTrips($pdo) - $before;
            $people[$headcount] = count($snapshot->data['people']);
        }

        self::assertSame([1 => 1, 10 => 10, 100 => 100, 500 => 500], $people);
        self::assertSame(
            $counts[1],
            $counts[10],
            'Snapshot deseti osob smí stát tolik dotazů co snapshot jedné.',
        );
        self::assertSame(
            $counts[1],
            $counts[100],
            'Snapshot sta osob smí stát tolik dotazů co snapshot jedné.',
        );
        self::assertSame(
            $counts[1],
            $counts[500],
            'Snapshot pěti set osob smí stát tolik dotazů co snapshot jedné.',
        );
        // Horní mez: dávka má 1 000 ID, takže ani 500 osob s více vztahy
        // nepřidá další dotaz.
        // Číslo je vědomě těsné — má spadnout, když někdo přidá dotaz navíc.
        // Doložené záměry OZUSPOJ mají vlastní množinovou dávku; risky savings
        // evidence se veze v kořenovém dotazu pracovních vztahů.
        //
        // Měřená hodnota je 78 (76 round-tripů snapshotu + 2 za samotný
        // SHOW SESSION STATUS, kterým se měří). Rozpočet se PROTI dřívějším 82
        // SNIŽUJE, protože obě zákonné kumulace (`social_insurance`,
        // `income_tax`) se od W11 berou jednou dávkou — druh kumulace je jediná
        // hodnota ve WHERE, takže volání po jednom platilo dvakrát tytéž tři
        // dotazy (příslušnost osob k firmě, opening balance, záznamy před
        // obdobím). Viz PayrollStatutoryAccumulatorRepository::statesBeforePeriodByKind().
        //
        // V rozpočtu je vědomě i jeden dotaz navíc, který přibyl s výchozími
        // analytickými předkontacemi (W7/Ú-08): firma BEZ uloženého nastavení
        // mezd si v PayrollEmployerSettingsRepository::defaultAccounts() ověří
        // jedním SELECTem nad `chart_of_accounts`, které analytiky (336.100 /
        // 336.200) v osnově vůbec má. Sloučit ho nelze — rozhoduje se až podle
        // toho, že řádek nastavení chybí, a bez něj by snapshot zmrazil účet,
        // který firma nemá. Je to jeden dotaz na běh, ne na osobu.
        //
        // Od W14 je v rozpočtu i jedna dávka navíc: kontrola, jestli je osoba
        // přihlášená u ČSSZ a u zdravotní pojišťovny
        // (PayrollRunSnapshotBuilder::personRegistrationGaps()). Doteď se
        // hlídala jen registrace ÚČTÁRNY, takže mzda člověka bez přihlášky
        // prošla mlčky. Je to jeden množinový dotaz na běh, ne na osobu —
        // proto rozpočet roste o dva round-tripy (prepare + execute), ne
        // s počtem zaměstnanců.
        //
        // Číslo je vědomě těsné — má spadnout, když někdo přidá dotaz navíc.
        self::assertLessThanOrEqual(
            80,
            $counts[500],
            'Snapshot pěti set osob se musí vejít do 80 round-tripů.',
        );
    }

    /** Dvakrát postavený snapshot téhož vstupu musí být bajtově týž. */
    public function testSnapshotIsByteIdenticalAcrossBuilds(): void
    {
        $this->seed(24);
        $first = $this->build();
        $second = $this->build();

        self::assertSame($first->json, $second->json);
        self::assertSame($first->hash, $second->hash);
        self::assertSame($first->rulesetManifestHash, $second->rulesetManifestHash);
        self::assertSame(
            hash('sha256', $first->json),
            $first->hash,
            'Otisk snapshotu musí odpovídat jeho kanonickému JSONu.',
        );
    }

    /**
     * Dávkové načtení nesmí přiřadit řádky jiné osobě.
     *
     * Tohle je ta chyba, kterou by počet dotazů ani stabilita otisku neodhalily:
     * snapshot by byl stejně velký a stejně deterministický, jen by osoba A měla
     * srážky osoby B. Proto se každá kolekce porovnává s nezávislým dotazem
     * za jednu osobu / jeden pracovní vztah.
     */
    public function testBatchedLoadKeepsRowsWithTheirOwnPerson(): void
    {
        $this->seed(24);
        $snapshot = $this->build();
        $people = $snapshot->data['people'];
        self::assertCount(24, $people);

        $seenEmploymentIds = [];
        foreach ($people as $person) {
            $employeeId = (int) $person['employee']['id'];

            self::assertSame(
                $this->ids(
                    'SELECT id FROM payroll_employments
                      WHERE supplier_id = ? AND employee_id = ? ORDER BY id',
                    [$this->supplierId, $employeeId],
                ),
                array_map(
                    static fn (array $row): int => (int) $row['employment']['id'],
                    $person['employments'],
                ),
                "Osoba {$employeeId} má cizí pracovní vztahy.",
            );
            self::assertSame(
                $this->ids(
                    'SELECT id FROM payroll_deduction_agreements
                      WHERE supplier_id = ? AND employee_id = ? AND status = "active"
                      ORDER BY priority_no, id',
                    [$this->supplierId, $employeeId],
                ),
                array_map(
                    static fn (array $row): int => (int) $row['id'],
                    $person['deduction_agreements'],
                ),
                "Osoba {$employeeId} má cizí dohody o srážkách.",
            );
            self::assertSame(
                $this->ids(
                    'SELECT id FROM payroll_payout_rules
                      WHERE supplier_id = ? AND employee_id = ? AND is_active = 1
                      ORDER BY priority_no, id',
                    [$this->supplierId, $employeeId],
                ),
                array_map(
                    static fn (array $row): int => (int) $row['id'],
                    $person['payout_rules'],
                ),
                "Osoba {$employeeId} má cizí výplatní pravidla.",
            );
            self::assertSame(
                $this->ids(
                    'SELECT id FROM payroll_person_accounts
                      WHERE supplier_id = ? AND employee_id = ? AND is_active = 1
                      ORDER BY id',
                    [$this->supplierId, $employeeId],
                ),
                array_map(
                    static fn (array $row): int => (int) $row['id'],
                    $person['payout_accounts'],
                ),
                "Osoba {$employeeId} má cizí výplatní účty.",
            );

            $accumulators = $person['statutory_accumulators'];
            foreach (['social_insurance', 'income_tax'] as $calculationKind) {
                self::assertSame('verified', $accumulators[$calculationKind]['status']);
                self::assertSame(
                    $employeeId,
                    $accumulators[$calculationKind]['state']['employee_id'],
                    "Osoba {$employeeId} má kumulaci {$calculationKind} jiné osoby.",
                );
                self::assertSame(
                    (int) $this->scalar(
                        'SELECT id FROM payroll_statutory_accumulator_openings
                          WHERE supplier_id = ? AND employee_id = ? AND tax_year = 2026
                            AND calculation_kind = ?',
                        [$this->supplierId, $employeeId, $calculationKind],
                    ),
                    $accumulators[$calculationKind]['state']['opening_balance']['id'],
                );
            }

            self::assertSame(
                $employeeId,
                $person['statutory_evidence']['employee_id'] ?? null,
                "Osoba {$employeeId} má zákonnou evidenci jiné osoby.",
            );
            $declarationIds = $this->ids(
                'SELECT id FROM payroll_person_tax_declarations
                  WHERE supplier_id = ? AND employee_id = ? ORDER BY effective_from, id',
                [$this->supplierId, $employeeId],
            );
            self::assertSame(
                $declarationIds[0] ?? null,
                $person['statutory_evidence']['income_tax']['declaration']['id'] ?? null,
                "Osoba {$employeeId} má cizí daňové prohlášení.",
            );

            self::assertCount(
                (int) $this->scalar(
                    'SELECT COUNT(*) FROM payroll_enforcement_claims claim
                       JOIN payroll_enforcement_cases enforcement_case
                         ON enforcement_case.supplier_id = claim.supplier_id
                        AND enforcement_case.id = claim.case_id
                      WHERE claim.supplier_id = ? AND enforcement_case.employee_id = ?',
                    [$this->supplierId, $employeeId],
                ),
                $person['enforcement_evidence']['claims'],
                "Osoba {$employeeId} má cizí exekuční pohledávky.",
            );

            foreach ($person['employments'] as $employment) {
                $employmentId = (int) $employment['employment']['id'];
                self::assertArrayNotHasKey(
                    $employmentId,
                    $seenEmploymentIds,
                    'Pracovní vztah se ve snapshotu objevil dvakrát.',
                );
                $seenEmploymentIds[$employmentId] = true;
                self::assertSame($employeeId, (int) $employment['employment']['employee_id']);
                self::assertSame(
                    $this->ids(
                        'SELECT id FROM payroll_inputs
                          WHERE supplier_id = ? AND employment_id = ?
                            AND period_start = ? AND status IN ("approved", "locked")
                          ORDER BY id',
                        [
                            $this->supplierId,
                            $employmentId,
                            PayrollRunScaleFixture::PERIOD_START,
                        ],
                    ),
                    array_map(
                        static fn (array $row): int => (int) $row['id'],
                        $employment['inputs'],
                    ),
                    "Vztah {$employmentId} má cizí mzdové vstupy.",
                );
                self::assertSame(
                    $this->ids(
                        'SELECT id FROM payroll_absences
                          WHERE supplier_id = ? AND employment_id = ? AND status = "approved"
                            AND date_from <= ? AND date_to >= ?
                          ORDER BY date_from, id',
                        [
                            $this->supplierId,
                            $employmentId,
                            PayrollRunScaleFixture::PERIOD_END,
                            PayrollRunScaleFixture::PERIOD_START,
                        ],
                    ),
                    array_map(
                        static fn (array $row): int => (int) $row['id'],
                        $employment['absences'],
                    ),
                    "Vztah {$employmentId} má cizí absence.",
                );
                self::assertSame(
                    $this->scalar(
                        'SELECT id FROM payroll_time_months
                          WHERE supplier_id = ? AND employment_id = ? AND period_start = ?',
                        [
                            $this->supplierId,
                            $employmentId,
                            PayrollRunScaleFixture::PERIOD_START,
                        ],
                    ) === false ? null : (int) $this->scalar(
                        'SELECT id FROM payroll_time_months
                          WHERE supplier_id = ? AND employment_id = ? AND period_start = ?',
                        [
                            $this->supplierId,
                            $employmentId,
                            PayrollRunScaleFixture::PERIOD_START,
                        ],
                    ),
                    $employment['time_month'] === null
                        ? null
                        : (int) $employment['time_month']['id'],
                    "Vztah {$employmentId} má cizí docházkový měsíc.",
                );
                // Účinný je term od 1. 6.; ten starší skončil 31. 5. a nesmí projít.
                self::assertSame('2026-06-01', $employment['term']['effective_from']);
                self::assertSame(10000, $employment['term']['workload_basis_points']);
            }
        }
    }

    /** Naseeduje osoby do aktuální firmy; ID se posunou o pořadí firmy. */
    /**
     * Dimenze vztahu (středisko / zakázka / činnost) musí do snapshotu VSTOUPIT.
     *
     * `payroll_dimensions.default_account_code` určuje nákladový účet hrubé mzdy
     * a zaúčtování jede nad zmrazeným snapshotem. Kdyby se dimenze dohledávaly až
     * při účtování, přeúčtování starší revize by použilo dnešní přiřazení střediska
     * a vyrobilo jiné zaúčtování než původní — přesně to, čemu snapshot brání.
     *
     * Vztah bez přiřazení musí mít prázdný seznam, ne chybějící klíč: jinak by se
     * kanonický JSON lišil podle toho, jestli firma dimenze vůbec používá.
     */
    public function testEmploymentDimensionsAreFrozenIntoTheSnapshot(): void
    {
        $this->seed(2);
        $employmentIds = $this->ids(
            'SELECT id FROM payroll_employments WHERE supplier_id = ? ORDER BY id',
            [$this->supplierId],
        );
        self::assertGreaterThanOrEqual(2, count($employmentIds));

        $dimensionId = $this->seedDimension('cost_center', 'VYROBA', '521.100');
        $this->assignDimension($employmentIds[0], $dimensionId);

        $byEmployment = [];
        foreach ($this->build()->data['people'] as $person) {
            foreach ($person['employments'] as $employment) {
                $byEmployment[(int) $employment['employment']['id']] = $employment['dimensions'];
            }
        }

        self::assertSame(
            [[
                'type' => 'cost_center',
                'code' => 'VYROBA',
                'name' => 'VYROBA',
                'default_account_code' => '521.100',
            ]],
            $byEmployment[$employmentIds[0]],
        );
        self::assertSame(
            [],
            $byEmployment[$employmentIds[1]],
            'Vztah bez dimenze má prázdný seznam, ne chybějící klíč.',
        );
    }

    /** Dimenze účinná až po období do snapshotu daného měsíce nepatří. */
    public function testDimensionEffectiveAfterThePeriodIsNotFrozenIn(): void
    {
        $this->seed(1);
        $employmentId = $this->ids(
            'SELECT id FROM payroll_employments WHERE supplier_id = ? ORDER BY id',
            [$this->supplierId],
        )[0];
        $dimensionId = $this->seedDimension('cost_center', 'BUDOUCI', '521.900');
        $this->assignDimension($employmentId, $dimensionId, '2099-01-01');

        $dimensions = $this->build()->data['people'][0]['employments'][0]['dimensions'];

        self::assertSame([], $dimensions);
    }

    /** Pozdější změna číselníku nesmí změnit už sestavený historický snapshot. */
    public function testDimensionAccountChangeAffectsOnlyNewSnapshots(): void
    {
        $this->seed(1);
        $employmentId = $this->ids(
            'SELECT id FROM payroll_employments WHERE supplier_id = ? ORDER BY id',
            [$this->supplierId],
        )[0];
        $dimensionId = $this->seedDimension('cost_center', 'VYROBA', '521.100');
        $this->assignDimension($employmentId, $dimensionId);

        $frozen = $this->build();
        $this->db->pdo()->prepare(
            'UPDATE payroll_dimensions
                SET default_account_code = "521.200", row_version = row_version + 1
              WHERE supplier_id = ? AND id = ?',
        )->execute([$this->supplierId, $dimensionId]);
        $current = $this->build();

        self::assertSame(
            '521.100',
            $frozen->data['people'][0]['employments'][0]['dimensions'][0][
                'default_account_code'
            ],
        );
        self::assertSame(
            '521.200',
            $current->data['people'][0]['employments'][0]['dimensions'][0][
                'default_account_code'
            ],
        );
        self::assertNotSame($frozen->hash, $current->hash);
        self::assertSame(hash('sha256', $frozen->json), $frozen->hash);
    }

    /** Stejný kód dimenze ve dvou firmách se nikdy nesmí promíchat. */
    public function testDimensionSnapshotIsTenantScoped(): void
    {
        $this->seed(1);
        $firstEmploymentId = $this->ids(
            'SELECT id FROM payroll_employments WHERE supplier_id = ? ORDER BY id',
            [$this->supplierId],
        )[0];
        $firstDimensionId = $this->seedDimension('cost_center', 'SPOLECNY', '521.100');
        $this->assignDimension($firstEmploymentId, $firstDimensionId);
        $first = $this->build();

        $this->newTenant();
        $this->seed(1);
        $secondEmploymentId = $this->ids(
            'SELECT id FROM payroll_employments WHERE supplier_id = ? ORDER BY id',
            [$this->supplierId],
        )[0];
        $secondDimensionId = $this->seedDimension('cost_center', 'SPOLECNY', '521.900');
        $this->assignDimension($secondEmploymentId, $secondDimensionId);
        $second = $this->build();

        self::assertSame(
            '521.100',
            $first->data['people'][0]['employments'][0]['dimensions'][0][
                'default_account_code'
            ],
        );
        self::assertSame(
            '521.900',
            $second->data['people'][0]['employments'][0]['dimensions'][0][
                'default_account_code'
            ],
        );
        self::assertNotSame($first->data['supplier_id'], $second->data['supplier_id']);
    }

    public function testLatestRiskySavingsEvidenceIsFrozenWithoutTenantBleed(): void
    {
        $this->seed(2);
        $employmentIds = $this->ids(
            'SELECT id FROM payroll_employments WHERE supplier_id = ? ORDER BY id',
            [$this->supplierId],
        );
        $accountId = $this->seedInstitutionAccount();
        $target = $this->riskySavings->paymentTarget(
            $this->supplierId,
            $accountId,
            PayrollRunScaleFixture::PAYMENT_DATE,
        );
        $approved = $this->riskySavings->saveEvidence(
            $this->supplierId,
            $employmentIds[0],
            PayrollRunScaleFixture::PERIOD_START,
            $this->riskySavingsEvidence($target, 24, null, null),
            $this->actorId,
        );
        $draft = $this->riskySavings->saveEvidence(
            $this->supplierId,
            $employmentIds[0],
            PayrollRunScaleFixture::PERIOD_START,
            $this->riskySavingsEvidence(
                $target,
                32,
                (int) $approved['id'],
                (int) $approved['row_version'],
                'draft',
            ),
            $this->actorId,
        );

        $firstSupplierId = $this->supplierId;
        $byEmployment = [];
        $snapshot = $this->build();
        self::assertSame([
            'effective_from' => '2026-01-01',
            'minimum_shift_eighths' => 24,
            'payment_due_months_after_period' => 1,
            'payment_due_rule' => 'last_day_of_month',
            'rate' => '0.04',
            'schema' => 'payroll-risky-savings-rules.v1',
        ], array_diff_key(
            $snapshot->data['risky_savings_ruleset'],
            array_flip(['ruleset_id', 'ruleset_sha256']),
        ));
        self::assertSame(
            'cz-payroll-2026.social-insurance.v1',
            $snapshot->data['risky_savings_ruleset']['ruleset_id'],
        );
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{64}$/D',
            $snapshot->data['risky_savings_ruleset']['ruleset_sha256'],
        );
        foreach ($snapshot->data['people'] as $person) {
            foreach ($person['employments'] as $employment) {
                $byEmployment[(int) $employment['employment']['id']] =
                    $employment['risky_savings_evidence'];
            }
        }
        $frozen = $byEmployment[$employmentIds[0]];
        self::assertIsArray($frozen);
        self::assertSame((int) $draft['id'], $frozen['id']);
        self::assertSame(2, $frozen['revision_no']);
        self::assertSame(32, $frozen['qualifying_shift_eighths']);
        self::assertSame('draft', $frozen['status']);
        self::assertSame(
            $target['institution_account_hash'],
            $frozen['institution_account_hash'],
        );
        self::assertSame(
            $target['institution_account_hash'],
            $frozen['current_institution_account_hash'],
        );
        foreach (array_slice($employmentIds, 1) as $employmentId) {
            self::assertNull($byEmployment[$employmentId]);
        }
        self::assertContains(
            'risky_savings_evidence_not_approved',
            array_map(
                static fn ($validation): string => $validation->code,
                $snapshot->validations,
            ),
        );

        $this->newTenant();
        $this->seed(1);
        self::assertNotSame($firstSupplierId, $this->supplierId);
        self::assertNull(
            $this->build()->data['people'][0]['employments'][0][
                'risky_savings_evidence'
            ],
        );
    }

    private function seedInstitutionAccount(): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_institutions
                (supplier_id, institution_type, institution_code)
             VALUES (?, "other_recipient", "SYN-SNAPSHOT-PENSION")',
        )->execute([$this->supplierId]);
        $institutionId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_institution_accounts
                (supplier_id, institution_id, institution_name,
                 bank_account_ciphertext, bank_account_hash,
                 bank_account_masked, currency_code, variable_symbol,
                 valid_from, source_kind, source_reference, verified_on,
                 verified_by, created_by)
             VALUES (?, ?, "Syntetická penzijní společnost",
                     "enc:v2:synthetic", UNHEX(?), "******0005 / 0100",
                     "CZK", "123456", "2026-01-01", "user_verified",
                     "synthetic:snapshot", "2026-01-01", ?, ?)',
        )->execute([
            $this->supplierId,
            $institutionId,
            str_repeat('b', 64),
            $this->actorId,
            $this->actorId,
        ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * @param array<string,mixed> $target
     * @return array<string,mixed>
     */
    private function riskySavingsEvidence(
        array $target,
        int $eighths,
        ?int $sourceId,
        ?int $rowVersion,
        string $status = 'approved',
    ): array {
        return [
            'status' => $status,
            'source_evidence_id' => $sourceId,
            'row_version' => $rowVersion,
            'risk_factor' => 'vibration',
            'work_category' => 3,
            'qualifying_shift_eighths' => $eighths,
            'right_claimed_on' => '2026-05-31',
            'employee_informed_on' => '2026-05-01',
            'pension_company' => 'Syntetická penzijní společnost',
            'institution_account_id' => $target['institution_account_id'],
            'institution_account_row_version' =>
                $target['institution_account_row_version'],
            'institution_account_hash' => $target['institution_account_hash'],
            'institution_account_masked' =>
                $target['institution_account_masked'],
            'product_reference' => 'SYNTHETIC-SNAPSHOT-PRODUCT',
            'variable_symbol' => '123456',
            'specific_symbol' => null,
            'payment_message' => 'Syntetická platba',
            'evidence_reference' => 'synthetic:snapshot',
        ];
    }

    private function seedDimension(string $type, string $code, ?string $account): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO payroll_dimensions
                 (supplier_id, dimension_type, code, name, valid_from, valid_to,
                  is_active, default_account_code, created_by, updated_by)
             VALUES (?, ?, ?, ?, "2000-01-01", NULL, 1, ?, ?, ?)',
        );
        $stmt->execute([
            $this->supplierId,
            $type,
            $code,
            $code,
            $account,
            $this->actorId,
            $this->actorId,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    private function assignDimension(
        int $employmentId,
        int $dimensionId,
        string $validFrom = '2000-01-01',
    ): void {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employment_dimensions
                 (supplier_id, employment_id, dimension_id, valid_from, valid_to,
                  created_by, updated_by)
             VALUES (?, ?, ?, ?, NULL, ?, ?)',
        )->execute([
            $this->supplierId,
            $employmentId,
            $dimensionId,
            $validFrom,
            $this->actorId,
            $this->actorId,
        ]);
    }

    private function seed(int $headcount): void
    {
        (new PayrollRunScaleFixture(
            $this->db,
            $this->supplierId,
            $this->actorId,
            7_000_000_000 + ($this->tenantOrdinal * 100_000_000),
        ))->seed($headcount);
    }

    private function build(): PayrollRunInputSnapshot
    {
        return $this->builder->build(
            $this->supplierId,
            PayrollRunScaleFixture::PERIOD_START,
            PayrollRunScaleFixture::PAYMENT_DATE,
        );
    }

    /**
     * @param list<mixed> $params
     * @return list<int>
     */
    private function ids(string $sql, array $params): array
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);

        return array_values(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
    }

    /** @param list<mixed> $params */
    private function scalar(string $sql, array $params): mixed
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchColumn();
    }
}
