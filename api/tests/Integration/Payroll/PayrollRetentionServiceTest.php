<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Repository\Payroll\PayrollErasureException;
use MyInvoice\Repository\Payroll\PayrollErasureProposalRepository;
use MyInvoice\Repository\Payroll\PayrollPersonAnonymizationRepository;
use MyInvoice\Repository\Payroll\PayrollRetentionPolicyException;
use MyInvoice\Repository\Payroll\PayrollRetentionPolicyRepository;
use MyInvoice\Repository\RetentionHoldRepository;
use MyInvoice\Service\Payroll\Retention\PayrollRetentionAssessment;
use MyInvoice\Service\Payroll\Retention\PayrollRetentionCatalog;
use MyInvoice\Service\Payroll\Retention\PayrollRetentionService;
use MyInvoice\Tests\Support\PayrollDeletionFixturesTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Retence mzdové agendy: co se nesmí navrhnout, co se nesmí smazat bez schválení
 * a co po výmazu musí zůstat.
 *
 * Každý test drží jedno tvrzení, které bez opravy PADÁ — ne „projde to". Zejména:
 * bez podmínky `status = 'approved'` projde {@see testExecuteWithoutApprovalIsRefused},
 * bez zápočtu holdů projde {@see testLegalHoldOverridesElapsedRetention} a bez
 * ponechání řádků projde {@see testAnonymizationKeepsAccountingRecord}.
 */
#[Group('integration')]
final class PayrollRetentionServiceTest extends TestCase
{
    use PayrollDeletionFixturesTrait;

    private PayrollRetentionService $retention;
    private PayrollRetentionPolicyRepository $policies;
    private PayrollErasureProposalRepository $proposals;
    private PayrollPersonAnonymizationRepository $anonymization;
    private RetentionHoldRepository $holds;

    /** Den posouzení — pevný, ať se test nerozbije příchodem nového roku. */
    private const AS_OF = '2026-08-15';

    protected function setUp(): void
    {
        $container = $this->bootPayrollFixtures();
        if (!$this->db->hasTable('payroll_erasure_proposals')) {
            self::markTestSkipped('Migrace 1397 neproběhla.');
        }

        $retention = $container->get(PayrollRetentionService::class);
        self::assertInstanceOf(PayrollRetentionService::class, $retention);
        $this->retention = $retention;

        $policies = $container->get(PayrollRetentionPolicyRepository::class);
        self::assertInstanceOf(PayrollRetentionPolicyRepository::class, $policies);
        $this->policies = $policies;

        $proposals = $container->get(PayrollErasureProposalRepository::class);
        self::assertInstanceOf(PayrollErasureProposalRepository::class, $proposals);
        $this->proposals = $proposals;

        $anonymization = $container->get(PayrollPersonAnonymizationRepository::class);
        self::assertInstanceOf(PayrollPersonAnonymizationRepository::class, $anonymization);
        $this->anonymization = $anonymization;

        $holds = $container->get(RetentionHoldRepository::class);
        self::assertInstanceOf(RetentionHoldRepository::class, $holds);
        $this->holds = $holds;
    }

    protected function tearDown(): void
    {
        $this->shutdownPayrollFixtures();
    }

    // ── 1. V lhůtě se nenavrhne ──────────────────────────────────────────────

    public function testRecordWithinRetentionIsNotProposed(): void
    {
        // Mzda za loňský rok: mzdový list se drží 45 let, takže lhůta zdaleka běží.
        $this->ageEmployee($this->employeeId, 2025);
        $this->insertMonthlyRecord($this->supplierId, $this->employeeId, 2025);

        $assessment = $this->assessmentFor($this->employeeId);

        self::assertSame('2070-12-31', $assessment->retainedUntil);
        self::assertFalse($assessment->expired);
        self::assertFalse($assessment->isProposable());
        self::assertSame(
            PayrollRetentionAssessment::BLOCK_WITHIN_RETENTION,
            $assessment->blockedBy,
        );
        self::assertNull(
            $this->proposals->create($this->supplierId, $this->userId, self::AS_OF, null),
            'Návrh nesmí vzniknout, když nikomu neuplynula lhůta.',
        );
    }

    // ── 2. Po lhůtě se navrhne, ale neprovede bez schválení ──────────────────

    public function testExpiredRecordIsProposedButNotExecutedWithoutApproval(): void
    {
        $this->ageEmployee($this->employeeId, 1975);
        $this->insertMonthlyRecord($this->supplierId, $this->employeeId, 1975);

        $assessment = $this->assessmentFor($this->employeeId);
        self::assertSame('2020-12-31', $assessment->retainedUntil);
        self::assertTrue($assessment->expired);
        self::assertTrue($assessment->isProposable());
        self::assertSame(PayrollRetentionCatalog::PAYROLL_SHEET, $assessment->governingCategory);

        $proposalId = $this->proposals->create(
            $this->supplierId,
            $this->userId,
            self::AS_OF,
            'Test',
        );
        self::assertNotNull($proposalId);

        // Návrh sám nesmí nic udělat — data jsou po vytvoření pořád na místě.
        self::assertSame(
            1,
            $this->rowCount('payroll_monthly_records', 'employee_id', $this->employeeId),
        );
        self::assertSame(
            'pending',
            (string) ($this->proposals->find($this->supplierId, $proposalId)['status'] ?? ''),
        );
    }

    public function testExecuteWithoutApprovalIsRefused(): void
    {
        $proposalId = $this->expiredProposal();

        $this->expectException(PayrollErasureException::class);
        $this->expectExceptionMessage('Výmaz jde provést až po schválení');
        $this->executeAfterCoolingOff($proposalId);
    }

    public function testRejectedProposalCannotBeExecuted(): void
    {
        $proposalId = $this->expiredProposal();
        $this->proposals->reject($this->supplierId, $proposalId, $this->userId);

        $this->expectException(PayrollErasureException::class);
        $this->executeAfterCoolingOff($proposalId);
    }

    // ── 3. Legal hold přebije lhůtu ──────────────────────────────────────────

    public function testLegalHoldOverridesElapsedRetention(): void
    {
        $this->ageEmployee($this->employeeId, 1975);
        $this->insertMonthlyRecord($this->supplierId, $this->employeeId, 1975);
        self::assertTrue($this->assessmentFor($this->employeeId)->isProposable());

        $this->holds->place(
            $this->supplierId,
            null,
            'enforcement',
            'Exekuce sp. zn. TEST-1',
            '2026-01-01',
            $this->userId,
            RetentionHoldRepository::SUBJECT_PAYROLL_EMPLOYEE,
            $this->employeeId,
        );

        $assessment = $this->assessmentFor($this->employeeId);
        self::assertTrue($assessment->expired, 'Lhůta uplynula — hold ji nepřepisuje, jen drží výmaz.');
        self::assertFalse($assessment->isProposable());
        self::assertSame(PayrollRetentionAssessment::BLOCK_HOLD, $assessment->blockedBy);
        self::assertNotSame([], $assessment->holds);
    }

    public function testCompanyWideHoldAlsoBlocksPayrollErasure(): void
    {
        $this->ageEmployee($this->employeeId, 1975);
        $this->insertMonthlyRecord($this->supplierId, $this->employeeId, 1975);

        // Hold zadaný na účetní straně (bez rozsahu = celá firma) musí zastavit
        // i mzdový výmaz. Bez toho by kontrola běžela a mzdové listy mizely.
        $this->holds->place(
            $this->supplierId,
            null,
            'tax_audit',
            'Kontrola č. j. TEST-2',
            '2026-01-01',
            $this->userId,
        );

        self::assertSame(
            PayrollRetentionAssessment::BLOCK_HOLD,
            $this->assessmentFor($this->employeeId)->blockedBy,
        );
    }

    public function testHoldPlacedAfterApprovalSkipsTheItem(): void
    {
        $proposalId = $this->expiredProposal();
        $this->proposals->approve($this->supplierId, $proposalId, $this->userId);

        // Mezi schválením a provedením přibude zadržení.
        $this->holds->place(
            $this->supplierId,
            null,
            'litigation',
            'Spor sp. zn. TEST-3',
            '2026-01-01',
            $this->userId,
            RetentionHoldRepository::SUBJECT_PAYROLL_EMPLOYEE,
            $this->employeeId,
        );

        $summary = $this->executeAfterCoolingOff($proposalId);

        self::assertSame(1, $summary['skipped_hold']);
        self::assertSame(0, $summary['done']);
        self::assertSame(
            1,
            $this->rowCount('payroll_monthly_records', 'employee_id', $this->employeeId),
            'Zadržený záznam se nesmí smazat ani po schválení.',
        );
    }

    // ── 4. Anonymizace zachová účetní záznam ─────────────────────────────────

    public function testAnonymizationKeepsAccountingRecord(): void
    {
        $this->ageEmployee($this->employeeId, 1975);
        $this->insertMonthlyRecord($this->supplierId, $this->employeeId, 1975);
        $this->insertAddress($this->supplierId, $this->employeeId);
        $this->setBirthNumber($this->employeeId, '9001011234');

        $proposalId = $this->expiredProposal();
        $this->proposals->approve($this->supplierId, $proposalId, $this->userId);
        $summary = $this->executeAfterCoolingOff($proposalId);

        self::assertSame(1, $summary['done']);

        // Osoba MÁ zaúčtovanou mzdu, takže se anonymizuje, nemaže.
        $items = $this->proposals->items($this->supplierId, $proposalId);
        self::assertSame('anonymize', (string) $items[0]['action']);

        // Účetní záznam zůstal celý a beze změny částky.
        $record = $this->fetchOne(
            'SELECT gross, net_final, employee_id FROM payroll_monthly_records
              WHERE supplier_id = ? AND employee_id = ?',
            [$this->supplierId, $this->employeeId],
        );
        self::assertNotNull($record, 'Mzdový snapshot nesmí anonymizací zmizet.');
        self::assertSame(40000, (int) $record['gross']);
        self::assertSame(
            $this->employeeId,
            (int) $record['employee_id'],
            'Vazba na osobu nesmí osiřet — jinak se rozpadne mzdový list.',
        );

        // Osobní údaj je pryč.
        $person = $this->fetchOne(
            'SELECT full_name, birth_number, birth_date, address FROM payroll_employees
              WHERE supplier_id = ? AND id = ?',
            [$this->supplierId, $this->employeeId],
        );
        self::assertNotNull($person, 'Řádek osoby musí zůstat, jinak kaskáda smete mzdy.');
        self::assertSame(
            PayrollPersonAnonymizationRepository::placeholderName($this->employeeId),
            (string) $person['full_name'],
        );
        self::assertNull($person['birth_number']);
        self::assertNull($person['address']);
        self::assertSame(
            0,
            $this->rowCount('payroll_person_addresses', 'employee_id', $this->employeeId),
            'Adresa je čistě identifikační údaj a musí zmizet celá.',
        );
    }

    public function testErasureRemovesPersonWithoutAccountingFootprint(): void
    {
        // Osoba s evidencí pro důchodové pojištění, ale BEZ zaúčtované mzdy:
        // `canDelete()` ji pustí, takže jde o úplný výmaz, ne anonymizaci.
        $employeeId = $this->insertEmployee($this->supplierId, 'Syntetická Osoba');
        $this->ageEmployee($employeeId, 1975);
        $this->insertSocialJurisdiction($this->supplierId, $employeeId);

        $assessment = $this->assessmentFor($employeeId);
        self::assertTrue($assessment->isProposable());
        self::assertSame(PayrollRetentionAssessment::ACTION_ERASE, $assessment->action);

        $proposalId = $this->proposals->create($this->supplierId, $this->userId, self::AS_OF, null);
        self::assertNotNull($proposalId);
        $this->proposals->approve($this->supplierId, $proposalId, $this->userId);
        $this->executeAfterCoolingOff($proposalId);

        self::assertNull(
            $this->fetchOne(
                'SELECT id FROM payroll_employees WHERE supplier_id = ? AND id = ?',
                [$this->supplierId, $employeeId],
            ),
            'Osoba bez účetní stopy se maže celá.',
        );
    }

    // ── 5. Uzavřený rok zůstane konzistentní ─────────────────────────────────

    public function testClosedYearStaysConsistentAfterAnonymization(): void
    {
        $this->ageEmployee($this->employeeId, 1975);
        $this->insertMonthlyRecord($this->supplierId, $this->employeeId, 1975, 1);
        $this->insertMonthlyRecord($this->supplierId, $this->employeeId, 1975, 2);
        $before = $this->yearTotals(1975);

        $this->anonymization->anonymize($this->supplierId, $this->employeeId, $this->userId, null, null);

        self::assertSame(
            $before,
            $this->yearTotals(1975),
            'Anonymizace nesmí změnit počet ani součet mezd uzavřeného roku.',
        );
    }

    // ── 6. Auditní stopa po výmazu ───────────────────────────────────────────

    public function testAuditTrailSurvivesErasureAndIsComplete(): void
    {
        $this->ageEmployee($this->employeeId, 1975);
        $this->insertMonthlyRecord($this->supplierId, $this->employeeId, 1975);

        $proposalId = $this->expiredProposal();
        $this->proposals->approve($this->supplierId, $proposalId, $this->userId);
        $this->executeAfterCoolingOff($proposalId);

        // a) Doklad v položce návrhu — přežije i úplný výmaz, protože nemá FK na osobu.
        $items = $this->proposals->items($this->supplierId, $proposalId);
        self::assertCount(1, $items);
        self::assertSame('done', (string) $items[0]['outcome']);
        self::assertSame($this->employeeId, (int) $items[0]['employee_id']);
        self::assertSame(
            PayrollRetentionCatalog::PAYROLL_SHEET,
            (string) $items[0]['governing_category'],
        );
        self::assertNotSame('', (string) $items[0]['governing_source']);
        self::assertSame('2020-12-31', (string) $items[0]['retained_until']);
        self::assertNotNull($items[0]['executed_at']);

        // b) Kdo schválil a kdy — bez toho se nedá doložit, že to nebyl automat.
        $proposal = $this->proposals->find($this->supplierId, $proposalId);
        self::assertNotNull($proposal);
        self::assertSame('executed', (string) $proposal['status']);
        self::assertSame($this->userId, (int) $proposal['approved_by']);
        self::assertNotNull($proposal['approved_at']);
        self::assertNotNull($proposal['executed_at']);

        // c) Auditní log nese návrh, schválení i provedení.
        foreach (['payroll.erasure.proposed', 'payroll.erasure.approved', 'payroll.erasure.executed'] as $action) {
            self::assertSame(
                1,
                $this->auditCount($action, $proposalId),
                "V auditním logu chybí krok {$action}.",
            );
        }
        self::assertSame(
            1,
            $this->auditCount('payroll.employee.anonymized', $this->employeeId),
        );
    }

    public function testAuditPayloadCarriesNoPersonalData(): void
    {
        $this->ageEmployee($this->employeeId, 1975);
        $this->insertMonthlyRecord($this->supplierId, $this->employeeId, 1975);
        $this->setBirthNumber($this->employeeId, '9001011234');

        $this->anonymization->anonymize($this->supplierId, $this->employeeId, $this->userId, null, null);

        $payload = json_encode(
            $this->auditPayloadOf('payroll.employee.anonymized', $this->employeeId),
            JSON_UNESCAPED_UNICODE,
        );
        self::assertIsString($payload);
        self::assertStringNotContainsString('9001011234', $payload);
        self::assertStringNotContainsString('Testovací Pracovník', $payload);
    }

    // ── 7. Cizí tenant ───────────────────────────────────────────────────────

    public function testForeignTenantNeitherSeesNorProposes(): void
    {
        $this->ageEmployee($this->employeeId, 1975);
        $this->insertMonthlyRecord($this->supplierId, $this->employeeId, 1975);

        $foreign = $this->retention->assess($this->otherSupplierId, self::AS_OF);
        foreach ($foreign as $assessment) {
            self::assertNotSame($this->employeeId, $assessment->employeeId);
        }

        self::assertNull(
            $this->proposals->create($this->otherSupplierId, $this->userId, self::AS_OF, null),
            'Cizí tenant nesmí navrhnout výmaz osoby, kterou nevlastní.',
        );
        self::assertNull($this->anonymization->preview($this->otherSupplierId, $this->employeeId));
        self::assertNull($this->proposals->find($this->otherSupplierId, $this->expiredProposal()));
    }

    // ── Politika: prodloužit ano, zkrátit ne ─────────────────────────────────

    public function testTenantPolicyMayExtendButNotShorten(): void
    {
        $this->policies->upsert(
            $this->supplierId,
            PayrollRetentionCatalog::PAYROLL_SHEET,
            5,
            null,
            'Vnitřní předpis',
            $this->userId,
        );
        self::assertSame(50, $this->policies->effectiveYears($this->supplierId)[PayrollRetentionCatalog::PAYROLL_SHEET]);

        $this->expectException(PayrollRetentionPolicyException::class);
        $this->policies->upsert(
            $this->supplierId,
            PayrollRetentionCatalog::PAYROLL_SHEET,
            0,
            10,
            'Zkrátit na 10 let',
            $this->userId,
        );
    }

    /**
     * Lhůtu dodanou aplikací (zdravotní pojištění) nesmí tabulka přebít vlastním
     * číslem — a hláška musí přiznat, že za lhůtou nestojí paragraf. Kdyby tvrdila
     * „zákonná lhůta", odkazovala by uživatele na předpis, ve kterém žádná není.
     */
    public function testHousePolicyPeriodCanBeExtendedButNotReplaced(): void
    {
        $this->policies->upsert(
            $this->supplierId,
            PayrollRetentionCatalog::HEALTH_INSURANCE,
            2,
            null,
            'Vnitřní předpis',
            $this->userId,
        );
        self::assertSame(
            12,
            $this->policies->effectiveYears($this->supplierId)[PayrollRetentionCatalog::HEALTH_INSURANCE],
        );

        try {
            $this->policies->upsert(
                $this->supplierId,
                PayrollRetentionCatalog::HEALTH_INSURANCE,
                0,
                5,
                'Zkrátit na 5 let',
                $this->userId,
            );
            self::fail('Dodanou lhůtu nejde nahradit vlastní — jen prodloužit.');
        } catch (PayrollRetentionPolicyException $e) {
            self::assertStringContainsString('dodanou aplikací', $e->getMessage());
            self::assertStringNotContainsString('zákonnou lhůtu', $e->getMessage());
        }
    }

    /**
     * Evidence pracovní doby MÁ zákonnou lhůtu (§ 96 věta druhá zákona
     * č. 187/2006 Sb., 10 kalendářních roků). Do 15. 8. 2026 ji katalog vedl jako
     * neurčenou, takže osoba s docházkou se k výmazu nenavrhla NIKDY — ani po
     * půl století. Bez opravy tenhle test padá na `BLOCK_UNDETERMINED`.
     */
    public function testAttendanceNoLongerBlocksErasureForever(): void
    {
        $this->ageEmployee($this->employeeId, 1975);
        $this->insertMonthlyRecord($this->supplierId, $this->employeeId, 1975);
        $this->insertTimeEntry($this->supplierId, $this->employmentId);

        $assessment = $this->assessmentFor($this->employeeId);
        self::assertNull($assessment->blockedBy, 'Docházka už není důvod nenavrhnout nic.');
        self::assertTrue($assessment->isProposable());
        self::assertTrue($assessment->expired);

        // Rozhoduje mzdový list (45 let), ne docházka (10) — bere se nejdelší lhůta.
        self::assertSame(PayrollRetentionCatalog::PAYROLL_SHEET, $assessment->governingCategory);
        self::assertSame('2020-12-31', $assessment->retainedUntil);

        $working = $this->categoryOf($assessment, PayrollRetentionCatalog::WORKING_TIME);
        self::assertSame(10, $working['years']);
        self::assertStringContainsString('187/2006', (string) $working['source']);
        self::assertSame('1985-12-31', $working['retained_until']);

        // A návrh z toho skutečně vznikne — dřív byl vždycky prázdný.
        self::assertNotNull(
            $this->proposals->create($this->supplierId, $this->userId, self::AS_OF, null),
        );
    }

    /**
     * Neurčená kategorie pořád existuje — spis k exekučním srážkám. Zákon pro něj
     * lhůtu nemá (ověřeno negativně v OSŘ i v exekučním řádu), takže osobu drží,
     * dokud lhůtu nedodá tenant vlastní politikou.
     */
    public function testUndeterminedCategoryBlocksUntilTenantSuppliesPeriod(): void
    {
        $this->ageEmployee($this->employeeId, 1975);
        $this->insertMonthlyRecord($this->supplierId, $this->employeeId, 1975);
        $this->insertEnforcementCase($this->supplierId, $this->employeeId);

        $assessment = $this->assessmentFor($this->employeeId);
        self::assertFalse($assessment->isProposable());
        self::assertSame(
            PayrollRetentionAssessment::BLOCK_UNDETERMINED,
            $assessment->blockedBy,
        );

        // Firma lhůtu dodá — teprve pak se dá posoudit.
        $this->policies->upsert(
            $this->supplierId,
            PayrollRetentionCatalog::GARNISHMENT,
            0,
            3,
            'Vnitřní předpis: spis k exekuci 3 roky',
            $this->userId,
        );
        self::assertTrue($this->assessmentFor($this->employeeId)->isProposable());
    }

    // ── 8. Posudek jmenuje osobu, ne číslo ───────────────────────────────────

    /**
     * Bez jména je panel k výmazu jen počet a nevratný úkon se schvaluje naslepo.
     *
     * Jméno se bere TÝMŽ výrazem jako seznam osob, tedy z historie identit. Kdyby
     * se četlo přímo z `payroll_employees.full_name`, po změně příjmení by seznam
     * osob a návrh k výmazu ukazovaly dvě různá jména téhož člověka. Proto test
     * zapisuje nové příjmení do historie a v `payroll_employees` nechává staré.
     */
    public function testAssessmentNamesThePersonByEffectiveIdentity(): void
    {
        $this->db->pdo()->prepare(
            "INSERT INTO payroll_person_identity_history
                (supplier_id, employee_id, full_name, first_name, last_name, effective_from)
             VALUES (?, ?, 'Jana Nováková', 'Jana', 'Nováková', '2000-01-01')"
        )->execute([$this->supplierId, $this->employeeId]);

        self::assertSame(
            'Jana Nováková',
            $this->assessmentFor($this->employeeId)->fullName,
        );
        self::assertSame(
            'Jana Nováková',
            $this->assessmentFor($this->employeeId)->toArray()['full_name'],
        );
    }

    /**
     * Návrh výmazu jméno UKAZUJE, ale NEUKLÁDÁ.
     *
     * Doklad o výmazu, který si osobní údaj nechá, výmaz popírá — jméno se proto
     * dopojuje až při čtení. Bez toho by buď v panelu chybělo, nebo by přežilo
     * v tabulce, kterou výmaz záměrně nemaže.
     */
    public function testProposalShowsNameButDoesNotStoreIt(): void
    {
        $proposalId = $this->expiredProposal();

        $items = $this->proposals->items($this->supplierId, $proposalId);
        self::assertSame('Testovací Pracovník', (string) $items[0]['full_name']);

        $stored = $this->fetchOne(
            'SELECT * FROM payroll_erasure_proposal_items WHERE supplier_id = ? AND proposal_id = ?',
            [$this->supplierId, $proposalId],
        );
        self::assertIsArray($stored);
        self::assertArrayNotHasKey('full_name', $stored);
        self::assertStringNotContainsString(
            'Testovací',
            json_encode($stored, JSON_THROW_ON_ERROR),
        );
    }

    // ── 9. Uvolnění zadržení výmaz zase pustí ────────────────────────────────

    /**
     * Zadržení není konečné rozhodnutí. Když důvod pomine, uvolnění musí osobu
     * do návrhu vrátit — jinak by hold fungoval jako trvalá blokace a lhůty
     * by nikdy nedoběhly.
     */
    public function testReleasingHoldLetsErasureThroughAgain(): void
    {
        $this->ageEmployee($this->employeeId, 1975);
        $this->insertMonthlyRecord($this->supplierId, $this->employeeId, 1975);

        $holdId = $this->holds->place(
            $this->supplierId,
            null,
            'litigation',
            'Spor sp. zn. TEST-2',
            '2026-01-01',
            $this->userId,
            RetentionHoldRepository::SUBJECT_PAYROLL_EMPLOYEE,
            $this->employeeId,
        );
        self::assertSame(
            PayrollRetentionAssessment::BLOCK_HOLD,
            $this->assessmentFor($this->employeeId)->blockedBy,
        );
        self::assertNull(
            $this->proposals->create($this->supplierId, $this->userId, self::AS_OF, null),
            'Zadržená osoba se nesmí dostat do návrhu.',
        );

        self::assertTrue($this->holds->release(
            $this->supplierId,
            $holdId,
            '2026-02-01',
            $this->userId,
            RetentionHoldRepository::SUBJECT_PAYROLL_EMPLOYEE,
        ));

        $assessment = $this->assessmentFor($this->employeeId);
        self::assertNull($assessment->blockedBy);
        self::assertTrue($assessment->isProposable());
        self::assertSame([], $assessment->holds);
        self::assertNotNull(
            $this->proposals->create($this->supplierId, $this->userId, self::AS_OF, null),
        );
    }

    /**
     * Mzdová cesta uvolňuje jen mzdová zadržení. Kdyby `subject_kind` v podmínce
     * chyběl, uvolnilo by se i firemní zadržení zadané na účetní straně.
     */
    public function testPayrollReleaseDoesNotTouchCompanyHold(): void
    {
        $companyHoldId = $this->holds->place(
            $this->supplierId,
            null,
            'tax_audit',
            'Kontrola sp. zn. TEST-3',
            '2026-01-01',
            $this->userId,
        );

        self::assertFalse($this->holds->release(
            $this->supplierId,
            $companyHoldId,
            '2026-02-01',
            $this->userId,
            RetentionHoldRepository::SUBJECT_PAYROLL_EMPLOYEE,
        ));
        self::assertTrue($this->holds->hasActiveHold($this->supplierId));
    }

    /** Přehled mzdových zadržení musí říct, KOHO drží — ne jen `subject_id`. */
    public function testPayrollHoldsListCarriesPersonName(): void
    {
        $this->holds->place(
            $this->supplierId,
            null,
            'enforcement',
            'Exekuce sp. zn. TEST-4',
            '2026-01-01',
            $this->userId,
            RetentionHoldRepository::SUBJECT_PAYROLL_EMPLOYEE,
            $this->employeeId,
        );

        $rows = $this->holds->payrollHolds($this->supplierId);
        self::assertCount(1, $rows);
        self::assertSame('Testovací Pracovník', (string) $rows[0]['employee_full_name']);

        // Firemní zadržení do mzdového přehledu nepatří — má vlastní obrazovku.
        $this->holds->place(
            $this->supplierId,
            null,
            'tax_audit',
            'Kontrola sp. zn. TEST-5',
            '2026-01-01',
            $this->userId,
        );
        self::assertCount(1, $this->holds->payrollHolds($this->supplierId));
    }

    // ── W30 / C-07: odkladná lhůta a odvolání schválení ──────────────────────

    /**
     * Schválí-li návrh týž člověk, který ho sestavil, otevře se odkladná
     * lhůta. Nevratný výmaz tak má okno, ve kterém jde omyl napravit bez
     * zálohy — a to bez pravidla čtyř očí, které tenhle produkt zavádět nesmí
     * (řada firem má jedinou účetní).
     */
    public function testSelfApprovalOpensCoolingOffWindow(): void
    {
        $proposalId = $this->expiredProposal();
        $this->proposals->approve($this->supplierId, $proposalId, $this->userId);

        $proposal = $this->proposals->find($this->supplierId, $proposalId);
        self::assertIsArray($proposal);
        self::assertNotNull(
            $proposal['executable_from'] ?? null,
            'Sólo schválení musí otevřít odkladnou lhůtu.',
        );

        try {
            $this->proposals->execute(
                $this->supplierId,
                $proposalId,
                $this->userId,
                null,
                null,
                PayrollErasureProposalRepository::EXECUTE_CONFIRMATION,
            );
            self::fail('Výmaz v odkladné lhůtě se nesmí provést.');
        } catch (PayrollErasureException $exception) {
            self::assertSame('payroll_erasure_cooling_off', $exception->errorCode);
        }

        self::assertSame(
            1,
            $this->rowCount('payroll_monthly_records', 'employee_id', $this->employeeId),
        );
    }

    /** Odklad bez odvolatelnosti by byl jen zdržení. */
    public function testApprovalCanBeRevokedDuringCoolingOff(): void
    {
        $proposalId = $this->expiredProposal();
        $this->proposals->approve($this->supplierId, $proposalId, $this->userId);
        $this->proposals->revoke($this->supplierId, $proposalId, $this->userId);

        $proposal = $this->proposals->find($this->supplierId, $proposalId);
        self::assertIsArray($proposal);
        self::assertSame(
            PayrollErasureProposalRepository::STATUS_PENDING,
            (string) $proposal['status'],
        );
        self::assertNull($proposal['executable_from'] ?? null);
        self::assertNotNull($proposal['revoked_at'] ?? null);
    }

    /** Nevratné smazání nesmí jít odklepnout stejným pohybem jako uložení. */
    public function testExecuteWithoutTypedConfirmationIsRefused(): void
    {
        $proposalId = $this->expiredProposal();
        $this->proposals->approve($this->supplierId, $proposalId, $this->userId);
        $this->elapseCoolingOff($proposalId);

        try {
            $this->proposals->execute($this->supplierId, $proposalId, $this->userId);
            self::fail('Výmaz bez potvrzovací fráze se nesmí provést.');
        } catch (PayrollErasureException $exception) {
            self::assertSame(
                'payroll_erasure_confirmation_required',
                $exception->errorCode,
            );
        }
    }

    // ── Pomocné ──────────────────────────────────────────────────────────────

    /**
     * Posune konec odkladné lhůty do minulosti — testovací ekvivalent toho, že
     * uplynuly tři dny. Sahá se výhradně na `executable_from`, aby zbytek
     * stavu zůstal takový, jaký ho zapsalo schválení.
     */
    private function elapseCoolingOff(int $proposalId): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_erasure_proposals
                SET executable_from = NOW() - INTERVAL 1 HOUR
              WHERE supplier_id = ? AND id = ?',
        )->execute([$this->supplierId, $proposalId]);
    }

    /** @return array<string,int> */
    private function executeAfterCoolingOff(int $proposalId): array
    {
        $this->elapseCoolingOff($proposalId);

        return $this->proposals->execute(
            $this->supplierId,
            $proposalId,
            $this->userId,
            null,
            null,
            PayrollErasureProposalRepository::EXECUTE_CONFIRMATION,
        );
    }

    private function assessmentFor(int $employeeId): PayrollRetentionAssessment
    {
        foreach ($this->retention->assess($this->supplierId, self::AS_OF) as $assessment) {
            if ($assessment->employeeId === $employeeId) {
                return $assessment;
            }
        }

        self::fail('Posudek pro osobu #' . $employeeId . ' nevznikl.');
    }

    private function expiredProposal(): int
    {
        if ($this->rowCount('payroll_monthly_records', 'employee_id', $this->employeeId) === 0) {
            $this->insertMonthlyRecord($this->supplierId, $this->employeeId, 1975);
        }
        // Zestárnutí se dělá VŽDY a až tady: `payroll_employees.updated_at` má
        // ON UPDATE CURRENT_TIMESTAMP, takže jakýkoli dřívější UPDATE osoby (třeba
        // doplnění rodného čísla) posune poslední rok stopy na letošek a lhůta by
        // pak nikdy neuplynula.
        $this->ageEmployee($this->employeeId, 1975);

        $id = $this->proposals->create($this->supplierId, $this->userId, self::AS_OF, null);
        self::assertNotNull($id);

        return $id;
    }

    /**
     * Posune osobu do minulosti: ukončí vztah a přepíše `updated_at`. Bez obojího
     * by poslední rok stopy vyšel na letošek a lhůta by nikdy neuplynula.
     */
    private function ageEmployee(int $employeeId, int $year): void
    {
        $this->db->pdo()->prepare(
            "UPDATE payroll_employments
                SET status = 'ended', start_date = ?, end_date = ?
              WHERE supplier_id = ? AND employee_id = ?"
        )->execute(["{$year}-01-01", "{$year}-12-31", $this->supplierId, $employeeId]);

        $this->db->pdo()->prepare(
            'UPDATE payroll_employees SET updated_at = ? WHERE supplier_id = ? AND id = ?'
        )->execute(["{$year}-12-31 00:00:00", $this->supplierId, $employeeId]);
    }

    private function insertMonthlyRecord(
        int $supplierId,
        int $employeeId,
        int $year,
        int $month = 1,
    ): void {
        $this->db->pdo()->prepare(
            "INSERT INTO payroll_monthly_records
                (supplier_id, employee_id, year, month, gross, breakdown,
                 advance_tax_final, net_final)
             VALUES (?, ?, ?, ?, 40000, '{}', 4000, 34000)"
        )->execute([$supplierId, $employeeId, $year, $month]);
    }

    private function insertAddress(int $supplierId, int $employeeId): void
    {
        $this->db->pdo()->prepare(
            "INSERT INTO payroll_person_addresses
                (supplier_id, employee_id, address_type, street_line, city,
                 postal_code, country_code, effective_from)
             VALUES (?, ?, 'residence', 'Zkušební 1', 'Testov', '10000', 'CZ', '1975-01-01')"
        )->execute([$supplierId, $employeeId]);
    }

    private function insertSocialJurisdiction(int $supplierId, int $employeeId): void
    {
        $this->db->pdo()->prepare(
            "INSERT INTO payroll_person_social_jurisdictions
                (supplier_id, employee_id, jurisdiction, a1_status, effective_from)
             VALUES (?, ?, 'czech_regime_verified', 'not_applicable', '1975-01-01')"
        )->execute([$supplierId, $employeeId]);
    }

    /**
     * Rozpis lhůt jedné kategorie z posudku.
     *
     * @return array<string,mixed>
     */
    private function categoryOf(PayrollRetentionAssessment $assessment, string $category): array
    {
        foreach ($assessment->categories as $row) {
            if (($row['category'] ?? null) === $category) {
                return $row;
            }
        }

        self::fail("Posudek neuvádí kategorii {$category}.");
    }

    private function insertEnforcementCase(int $supplierId, int $employeeId): void
    {
        $this->db->pdo()->prepare(
            "INSERT INTO payroll_enforcement_cases
                (supplier_id, employee_id, case_key, case_kind, status, effective_from)
             VALUES (?, ?, ?, 'enforcement', 'received', '1975-01-01')"
        )->execute([$supplierId, $employeeId, 'EX-' . $employeeId]);
    }

    private function insertTimeEntry(int $supplierId, int $employmentId): void
    {
        $this->db->pdo()->prepare(
            "INSERT INTO payroll_time_entries
                (supplier_id, employment_id, series_key, revision_no, category,
                 starts_at_utc, ends_at_utc, timezone_name, break_minutes,
                 source_kind, source_hash, status, approved_at)
             VALUES (?, ?, ?, 1, 'regular', '1975-01-02 07:00:00', '1975-01-02 15:00:00',
                     'Europe/Prague', 0, 'manual', UNHEX(SHA2(?, 256)), 'approved',
                     '1975-01-31 00:00:00')"
        )->execute([
            $supplierId,
            $employmentId,
            'series-' . $employmentId,
            'time-' . $employmentId,
        ]);
    }

    private function setBirthNumber(int $employeeId, string $birthNumber): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_employees SET birth_number = ? WHERE supplier_id = ? AND id = ?'
        )->execute([$birthNumber, $this->supplierId, $employeeId]);
    }

    /** @return array{count:int,gross:int} */
    private function yearTotals(int $year): array
    {
        $row = $this->fetchOne(
            'SELECT COUNT(*) AS c, COALESCE(SUM(gross), 0) AS g
               FROM payroll_monthly_records
              WHERE supplier_id = ? AND year = ?',
            [$this->supplierId, $year],
        );

        return ['count' => (int) ($row['c'] ?? 0), 'gross' => (int) ($row['g'] ?? 0)];
    }

    /**
     * @param  list<mixed> $params
     * @return array<string,mixed>|null
     */
    private function fetchOne(string $sql, array $params): ?array
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }
}
