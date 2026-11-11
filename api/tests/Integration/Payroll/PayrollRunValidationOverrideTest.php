<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollRunValidationOverrideAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\Payroll\PayrollEmployerPolicyRepository;
use MyInvoice\Repository\Payroll\PayrollRunConflictException;
use MyInvoice\Repository\Payroll\PayrollRunIdempotencyException;
use MyInvoice\Repository\Payroll\PayrollRunRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Payroll\PayrollPeriodOwnershipService;
use MyInvoice\Service\Payroll\Posting\PayrollApprovedRevisionPostingService;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Run\PayrollRunCalculationPipeline;
use MyInvoice\Service\Payroll\Run\PayrollRunCalculator;
use MyInvoice\Service\Payroll\Run\PayrollRunCommandService;
use MyInvoice\Service\Payroll\Run\PayrollRunGarnishmentProcessor;
use MyInvoice\Service\Payroll\Run\PayrollRunSnapshotBuilder;
use MyInvoice\Service\Payroll\Run\PayrollRunValidationOverrideService;
use MyInvoice\Service\Payroll\Run\PayrollRunWorkflow;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationSubmissionService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * MZ-01-W07 — druhá půlka override u validací mzdového běhu.
 *
 * Sloupce `override_reason` / `overridden_by` / `overridden_at` existují od
 * migrace 1210 a {@see PayrollRunWorkflow} na nich staví podmínku schválení
 * běhu. Cesta, kterou by je někdo nastavil, ale nikdy nevznikla — každé
 * varování s `requires_override = 1` proto běh zablokovalo natrvalo.
 *
 * Tenhle test hlídá obě strany: že se výjimka dá schválit i vzít zpět, a hlavně
 * REGRESNĚ, že varování vyžadující schválení jde odklidit — aby se ta chybějící
 * půlka nemohla znovu ztratit.
 */
#[Group('integration')]
final class PayrollRunValidationOverrideTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollRunValidationOverrideAction $action;
    private PayrollRunValidationOverrideService $overrides;
    private PayrollRunCommandService $service;
    private PayrollRunRepository $runs;
    private int $supplierId;
    private int $otherSupplierId;
    private int $employeeId;
    private int $employmentId;
    /** @var list<int> */
    private array $actors;

    protected function setUp(): void
    {
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        $db = $container->get(Connection::class);
        $runs = $container->get(PayrollRunRepository::class);
        $overrides = $container->get(PayrollRunValidationOverrideService::class);
        $action = $container->get(PayrollRunValidationOverrideAction::class);
        $policies = $container->get(PayrollEmployerPolicyRepository::class);
        $approvedPosting = $this->createStub(
            PayrollApprovedRevisionPostingService::class,
        );
        $approvedPosting->method('post')->willReturn([]);
        $service = new PayrollRunCommandService(
            $db,
            $runs,
            $container->get(PayrollRunSnapshotBuilder::class),
            new PayrollRunCalculationPipeline(
                $container->get(PayrollRunCalculator::class),
                $container->get(PayrollRunGarnishmentProcessor::class),
            ),
            $container->get(PayrollRunWorkflow::class),
            $container->get(PayrollPeriodOwnershipService::class),
            $approvedPosting,
        );
        if (!$db instanceof Connection
            || !$runs instanceof PayrollRunRepository
            || !$overrides instanceof PayrollRunValidationOverrideService
            || !$action instanceof PayrollRunValidationOverrideAction
            || !$policies instanceof PayrollEmployerPolicyRepository
        ) {
            throw new \RuntimeException('Služby mzdové výjimky nejsou dostupné.');
        }
        $this->db = $db;
        $this->runs = $runs;
        $this->overrides = $overrides;
        $this->action = $action;
        $this->service = $service;
        if (!$this->db->hasTable('payroll_run_validations')) {
            $this->markTestSkipped('Migrace MZ-09 neproběhly.');
        }

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        if ($sourceSupplierId <= 0) {
            $this->markTestSkipped('Chybí zdrojová firma.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->otherSupplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id IN (?, ?)')
            ->execute([$this->supplierId, $this->otherSupplierId]);
        $this->actors = [
            $this->createActor('calculator'),
            $this->createActor('reviewer'),
            $this->createActor('approver'),
        ];
        foreach ([$this->supplierId, $this->otherSupplierId] as $supplierId) {
            $pdo->prepare(
                'INSERT INTO payroll_module_state
                    (supplier_id, status, start_period, activated_by, activated_at)
                 VALUES (?, "setup", "2026-01-01", ?, NOW())'
            )->execute([$supplierId, $this->actors[0]]);
        }
        $policies->create(
            $this->supplierId,
            $this->employerPolicyInput(),
            $this->actors[0],
        );
        [$this->employeeId, $this->employmentId] = $this->employment();
        $pdo->prepare(
            'INSERT INTO payroll_enforcement_person_month_evidence
                (supplier_id, employee_id, period_start,
                 claim_register_evidence_complete,
                 dependants_evidence_complete, spouse_evidence_complete,
                 pension_evidence, updated_by)
             VALUES (?, ?, "2026-06-01", 1, 1, 1, "none", ?)'
        )->execute([$this->supplierId, $this->employeeId, $this->actors[0]]);
        $this->approvedInput(120_000, 'BASE');
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    /**
     * REGRESNÍ TEST NA TU PAST.
     *
     * Zaměstnanec bez podané přihlášky vyrábí ve snapshotu varování
     * s `requires_override = 1` — tedy přesně ten druh validace, který doteď
     * běh zablokoval natrvalo. Test tvrdí dvě věci: takové varování v modulu
     * SKUTEČNĚ vzniká, a jde odklidit.
     */
    public function testWarningRequiringOverrideCanBeCleared(): void
    {
        [, $employmentId] = $this->employment('SYN-NOREG-CLR');
        $this->pendingOnboardingChecklist($employmentId);
        $locked = $this->lockedRun();
        $revisionId = (int) $locked['revision_id'];

        $pending = $this->requiresOverrideValidations($revisionId);
        self::assertNotSame(
            [],
            $pending,
            'Modul vyrábí varování s requires_override — musí pro ně existovat cesta ven.',
        );
        self::assertContains('employment_social_registration_missing', array_column($pending, 'code'));
        self::assertSame(
            count($pending),
            $this->runs->validationCounts($this->supplierId, $revisionId)['unresolved_overrides'],
        );

        $run = $this->runs->find($this->supplierId, (int) $locked['id']);
        foreach ($pending as $validation) {
            $result = $this->overrides->grant(
                $this->supplierId,
                (int) $locked['id'],
                (int) $validation['id'],
                (int) $run['row_version'],
                'override-' . $validation['id'],
                $this->actors[2],
                'Zaměstnanec byl celý měsíc na neplaceném volnu, mzdová složka '
                    . 'proto v období vzniknout nemá.',
            );
            $run = $result->run;
        }

        self::assertSame(
            0,
            $this->runs->validationCounts($this->supplierId, $revisionId)['unresolved_overrides'],
            'Po schválení výjimky nesmí zůstat nevyřešené varování — jinak je běh zase v pasti.',
        );
    }

    /**
     * Vztah bez schválené mzdové složky výjimku NEnabízí: zákonný výpočet ho
     * podle téhož pravidla zastaví jako blokátor, takže schválená výjimka by se
     * po přepočtu vrátila jako `statutory_calculation_manual_review` a jen
     * klamala. Varování zůstává vidět a vede na zadání mzdy za ten měsíc.
     */
    public function testEmploymentWithoutInputsDoesNotOfferUselessOverride(): void
    {
        [, $employmentId] = $this->employment('SYN-NOINPUT');
        $locked = $this->lockedRun();

        $rows = array_values(array_filter(
            $this->runs->validations($this->supplierId, (int) $locked['revision_id']),
            static fn (array $row): bool => $row['code'] === 'employment_without_inputs'
                && (int) $row['entity_id'] === $employmentId,
        ));
        self::assertCount(1, $rows);
        self::assertFalse($rows[0]['requires_override']);
        self::assertSame(
            '/payroll/quick-inputs?period=2026-06&employment=' . $employmentId,
            $rows[0]['remediation_path'],
        );
    }

    /**
     * Mzda se nesmí spočítat MLČKY za člověka, kterého nikdo nepřihlásil.
     *
     * Doteď se ve snapshotu ověřovala jen registrace ÚČTÁRNY u ČSSZ.
     * Zaměstnankyně, za kterou nikdo nepodal přihlášku k nemocenskému
     * pojištění ani oznámení zdravotní pojišťovně, tak prošla celým během —
     * pojistné se spočítalo, zaúčtovalo i zaplatilo — a aplikace mlčela až
     * do kontroly z ČSSZ.
     *
     * Je to VAROVÁNÍ s overridem, ne blokátor: přihláška se mohla podat mimo
     * aplikaci, mzda se pak spočítat musí, ale s vědomým odůvodněním.
     */
    public function testMissingPersonRegistrationWarnsWithOverride(): void
    {
        [, $employmentId] = $this->employment('SYN-NOREG');
        $this->pendingOnboardingChecklist($employmentId);
        $locked = $this->lockedRun();

        $codes = array_column(
            $this->requiresOverrideValidations((int) $locked['revision_id']),
            'code',
        );

        self::assertContains('employment_social_registration_missing', $codes);
        self::assertContains('employment_health_registration_missing', $codes);
    }

    /**
     * Odklepnutá položka checklistu varování umlčí — přihláška se mohla podat
     * mimo aplikaci a hlídač, který se ozývá i potom, se přestane číst.
     */
    public function testCompletedChecklistItemSilencesTheRegistrationWarning(): void
    {
        [, $employmentId] = $this->employment('SYN-REGOK');
        $this->pendingOnboardingChecklist($employmentId);
        $this->db->pdo()->prepare(
            'UPDATE payroll_employment_checklist_items
                SET status = "completed"
              WHERE supplier_id = ? AND employment_id = ?',
        )->execute([$this->supplierId, $employmentId]);
        $locked = $this->lockedRun();

        $codes = array_column(
            $this->requiresOverrideValidations((int) $locked['revision_id']),
            'code',
        );

        self::assertNotContains('employment_social_registration_missing', $codes);
        self::assertNotContains('employment_health_registration_missing', $codes);
    }

    /**
     * Od 2026 se do evidence zaměstnanců u ČSSZ (REGZEC) přihlašuje každá DPP,
     * i pod rozhodným příjmem bez účasti na pojištění (zákon č. 323/2025 Sb.).
     * Zdravotní pojišťovně se DPP bez účasti nehlásí, takže to varování mlčí.
     */
    public function testAgreementWithAutomaticParticipationOwesSocialRegistrationOnly(): void
    {
        [, $employmentId] = $this->employment('SYN-DPP');
        $this->pendingOnboardingChecklist($employmentId);
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments SET relation_type = "dpp"
              WHERE supplier_id = ? AND id = ?',
        )->execute([$this->supplierId, $employmentId]);
        $locked = $this->lockedRun();

        $codes = array_column(
            array_filter(
                $this->requiresOverrideValidations((int) $locked['revision_id']),
                static fn (array $row): bool => (int) $row['entity_id'] === $employmentId,
            ),
            'code',
        );

        self::assertContains('employment_social_registration_missing', $codes);
        self::assertNotContains('employment_health_registration_missing', $codes);
    }

    /** DPČ s vyloučenou účastí registraci u ČSSZ dluží také, oznámení pojišťovně ne. */
    public function testAgreementExcludedFromInsuranceOwesSocialRegistrationOnly(): void
    {
        [, $employmentId] = $this->employment('SYN-DPC-EXCL');
        $this->pendingOnboardingChecklist($employmentId);
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments SET relation_type = "dpc"
              WHERE supplier_id = ? AND id = ?',
        )->execute([$this->supplierId, $employmentId]);
        $this->db->pdo()->prepare(
            'UPDATE payroll_employment_terms
                SET social_insurance_participation = "excluded",
                    health_insurance_participation = "excluded",
                    monthly_gross_minor = 300000
              WHERE supplier_id = ? AND employment_id = ?',
        )->execute([$this->supplierId, $employmentId]);
        $locked = $this->lockedRun();

        $codes = array_column(
            array_filter(
                $this->requiresOverrideValidations((int) $locked['revision_id']),
                static fn (array $row): bool => (int) $row['entity_id'] === $employmentId,
            ),
            'code',
        );

        self::assertContains('employment_social_registration_missing', $codes);
        self::assertNotContains('employment_health_registration_missing', $codes);
    }

    /**
     * Registrace podaná v aplikaci (evidovaná povinnost REGZEC A1 k vztahu)
     * varování umlčí i bez odškrtnutého checklistu. Povinnost se pozná podle
     * téže reference, kterou zakládá podání registrace.
     */
    public function testAgreementWithRegistrationObligationIsNotWarnedAbout(): void
    {
        [, $employmentId] = $this->employment('SYN-DPP-OBL');
        $this->pendingOnboardingChecklist($employmentId);
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments SET relation_type = "dpp"
              WHERE supplier_id = ? AND id = ?',
        )->execute([$this->supplierId, $employmentId]);
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_obligations
                (supplier_id, environment, agenda_code, subject_type, subject_reference,
                 period_start, period_end, obligation_kind, preferred_channel, status,
                 source_event_type, source_event_reference, source_event_hash,
                 request_fingerprint, idempotency_key_hash)
             VALUES (?, "production", "REGZEC25", "employment", ?, "2026-06-01", "2026-06-30",
                     "regular", "vrep_apep", "open", ?, ?, ?, ?, ?)',
        )->execute([
            $this->supplierId,
            'payroll_employment:' . $employmentId,
            PayrollRegistrationSubmissionService::SOURCE_EVENT_TYPE,
            PayrollRegistrationSubmissionService::sourceEventReference($employmentId),
            str_repeat('a', 64),
            str_repeat('b', 64),
            random_bytes(32),
        ]);
        $locked = $this->lockedRun();

        $codes = array_column(
            array_filter(
                $this->requiresOverrideValidations((int) $locked['revision_id']),
                static fn (array $row): bool => (int) $row['entity_id'] === $employmentId,
            ),
            'code',
        );

        self::assertNotContains('employment_social_registration_missing', $codes);
    }

    /** DPP registrovaná mimo aplikaci (odškrtnutý checklist) zůstává bez varování. */
    public function testAgreementRegisteredOutsideTheAppIsNotWarnedAbout(): void
    {
        [, $employmentId] = $this->employment('SYN-DPP-EXT');
        $this->pendingOnboardingChecklist($employmentId);
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments SET relation_type = "dpp"
              WHERE supplier_id = ? AND id = ?',
        )->execute([$this->supplierId, $employmentId]);
        $this->db->pdo()->prepare(
            'UPDATE payroll_employment_checklist_items SET status = "completed"
              WHERE supplier_id = ? AND employment_id = ? AND item_key = "social_jmhz_registration"',
        )->execute([$this->supplierId, $employmentId]);
        $locked = $this->lockedRun();

        $codes = array_column(
            array_filter(
                $this->requiresOverrideValidations((int) $locked['revision_id']),
                static fn (array $row): bool => (int) $row['entity_id'] === $employmentId,
            ),
            'code',
        );

        self::assertNotContains('employment_social_registration_missing', $codes);
    }

    /**
     * DPČ se sjednanou odměnou nad rozhodným příjmem výpočet pojistí vždy, takže
     * chybějící přihláška se hlásí stejně jako u pracovního poměru — dřív se
     * u všech dohod mlčelo a oznámení nástupu šlo připravit až po běhu.
     */
    public function testAgreementWithAgreedIncomeAboveThresholdIsWarnedAbout(): void
    {
        [, $employmentId] = $this->employment('SYN-DPC');
        $this->pendingOnboardingChecklist($employmentId);
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments SET relation_type = "dpc"
              WHERE supplier_id = ? AND id = ?',
        )->execute([$this->supplierId, $employmentId]);
        $this->db->pdo()->prepare(
            'UPDATE payroll_employment_terms SET monthly_gross_minor = 1200000
              WHERE supplier_id = ? AND employment_id = ?',
        )->execute([$this->supplierId, $employmentId]);
        $locked = $this->lockedRun();

        $codes = array_column(
            array_filter(
                $this->requiresOverrideValidations((int) $locked['revision_id']),
                static fn (array $row): bool => (int) $row['entity_id'] === $employmentId,
            ),
            'code',
        );

        self::assertContains('employment_social_registration_missing', $codes);
        self::assertContains('employment_health_registration_missing', $codes);
    }

    private function pendingOnboardingChecklist(int $employmentId): void
    {
        $insert = $this->db->pdo()->prepare(
            'INSERT IGNORE INTO payroll_employment_checklist_items
                (supplier_id, employment_id, phase, item_key, status)
             VALUES (?, ?, "onboarding", ?, "pending")',
        );
        foreach ([
            'social_jmhz_registration',
            'health_insurance_registration',
        ] as $itemKey) {
            $insert->execute([$this->supplierId, $employmentId, $itemKey]);
        }
    }

    /** Bez výjimky běh schválit nejde; s výjimkou ano. */
    public function testOverrideUnblocksApprovalAndWithoutItApprovalIsRefused(): void
    {
        $locked = $this->lockedRun();
        $runId = (int) $locked['id'];
        $validationId = $this->seedOverridableWarning((int) $locked['revision_id']);

        $calculated = $this->service->calculate(
            $this->supplierId,
            $runId,
            (int) $this->runs->find($this->supplierId, $runId)['row_version'],
            'override-calculate',
            $this->actors[0],
        );
        $reviewed = $this->service->review(
            $this->supplierId,
            $runId,
            (int) $calculated->run['row_version'],
            'override-review',
            $this->actors[1],
        );

        try {
            $this->service->approve(
                $this->supplierId,
                $runId,
                (int) $reviewed->run['row_version'],
                'override-approve-blocked',
                $this->actors[2],
            );
            self::fail('Nevyřešené varování nesmí dovolit schválení běhu.');
        } catch (\DomainException $e) {
            self::assertStringContainsString('nevyřešená varování', $e->getMessage());
        }

        $granted = $this->overrides->grant(
            $this->supplierId,
            $runId,
            $validationId,
            (int) $this->runs->find($this->supplierId, $runId)['row_version'],
            'override-grant-key',
            $this->actors[2],
            'Překročení limitu přesčasu je doložené písemným souhlasem '
                . 'zaměstnance, mzda se vyplácí podle § 114.',
        );
        self::assertTrue($granted->granted);
        self::assertTrue($granted->fourEyesMet);
        self::assertSame($this->actors[2], $granted->validation['overridden_by']);
        self::assertNotNull($granted->validation['overridden_at']);

        $approved = $this->service->approve(
            $this->supplierId,
            $runId,
            (int) $granted->run['row_version'],
            'override-approve-ok',
            $this->actors[2],
        );
        self::assertSame('approved', $approved->to->value);
    }

    /**
     * Prázdné ani bezobsažné odůvodnění neprojde — schválení bez důvodu vypadá
     * jako rozhodnutí, ačkoli žádné nedokládá.
     */
    public function testHollowReasonIsRefused(): void
    {
        $locked = $this->lockedRun();
        $runId = (int) $locked['id'];
        $validationId = $this->seedOverridableWarning((int) $locked['revision_id']);
        $version = (int) $this->runs->find($this->supplierId, $runId)['row_version'];

        foreach ([
            ['', 'povinný'],
            ['   ', 'povinný'],
            [null, 'povinný'],
            ["\n\n\n\n\n\n\n\n\n\n\n\n\n\n\n\n\n\n\n\n\n\n", 'povinný'],
            ['ok', '20 znaků'],
            ['schvaluji', '20 znaků'],
            ['schvalujitutovyjimkuprotoze', 'nejméně 3 slovech'],
            ['aaaa aaaa aaaaaaaaaaaaaa', 'čitelná věta'],
            [str_repeat('a', 501) . ' b c', '500 znaků'],
        ] as [$reason, $expected]) {
            try {
                $this->overrides->grant(
                    $this->supplierId,
                    $runId,
                    $validationId,
                    $version,
                    'reason-' . md5(var_export($reason, true)),
                    $this->actors[2],
                    $reason,
                );
                self::fail(sprintf('Odůvodnění „%s" nesmí projít.', (string) $reason));
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString($expected, $e->getMessage());
            }
        }

        self::assertNull(
            $this->runs->validation($this->supplierId, $validationId)['overridden_at'],
            'Odmítnuté odůvodnění nesmí po sobě nechat schválenou výjimku.',
        );
        self::assertSame(
            0,
            (int) $this->scalar(
                'SELECT COUNT(*) FROM payroll_run_events
                  WHERE supplier_id = ? AND run_id = ?
                    AND event_type = "validation_override"',
                [$this->supplierId, $runId],
            ),
        );
    }

    /** Odvolání funguje před schválením běhu a neprojde po něm. */
    public function testRevokeWorksBeforeApprovalAndNotAfter(): void
    {
        $locked = $this->lockedRun();
        $runId = (int) $locked['id'];
        $validationId = $this->seedOverridableWarning((int) $locked['revision_id']);

        $granted = $this->overrides->grant(
            $this->supplierId,
            $runId,
            $validationId,
            (int) $this->runs->find($this->supplierId, $runId)['row_version'],
            'revoke-grant-1',
            $this->actors[2],
            'Doklad byl doložen dodatečně a výjimku proto přebírám na sebe.',
        );
        $revoked = $this->overrides->revoke(
            $this->supplierId,
            $runId,
            $validationId,
            (int) $granted->run['row_version'],
            'revoke-clear-1',
            $this->actors[2],
        );
        self::assertFalse($revoked->granted);
        self::assertNull($revoked->validation['overridden_at']);
        self::assertNull($revoked->validation['override_reason']);
        self::assertSame(
            1,
            $this->runs->validationCounts(
                $this->supplierId,
                (int) $locked['revision_id'],
            )['unresolved_overrides'],
            'Odvolaná výjimka musí varování vrátit mezi nevyřešená.',
        );
        // Odvolání smaže odůvodnění z validace, ale ne z historie.
        $revokedEvent = $this->lastEvent($runId, 'validation_override_revoked');
        self::assertSame(
            'Doklad byl doložen dodatečně a výjimku proto přebírám na sebe.',
            $revokedEvent['metadata']['revoked_reason'],
        );

        // Znovu schválit, protáhnout běh až do `approved` a zkusit odvolat.
        $regranted = $this->overrides->grant(
            $this->supplierId,
            $runId,
            $validationId,
            (int) $revoked->run['row_version'],
            'revoke-grant-2',
            $this->actors[2],
            'Doklad byl doložen dodatečně a výjimku proto přebírám na sebe.',
        );
        $calculated = $this->service->calculate(
            $this->supplierId,
            $runId,
            (int) $regranted->run['row_version'],
            'revoke-calculate',
            $this->actors[0],
        );
        $reviewed = $this->service->review(
            $this->supplierId,
            $runId,
            (int) $calculated->run['row_version'],
            'revoke-review',
            $this->actors[1],
        );
        $approved = $this->service->approve(
            $this->supplierId,
            $runId,
            (int) $reviewed->run['row_version'],
            'revoke-approve',
            $this->actors[2],
        );

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/přepisovalo historii/');
        $this->overrides->revoke(
            $this->supplierId,
            $runId,
            $validationId,
            (int) $approved->run['row_version'],
            'revoke-after-approval',
            $this->actors[2],
        );
    }

    /** Cizí tenant validaci nevidí ani ji neschválí. */
    public function testForeignTenantCannotSeeOrOverrideTheValidation(): void
    {
        $locked = $this->lockedRun();
        $runId = (int) $locked['id'];
        $validationId = $this->seedOverridableWarning((int) $locked['revision_id']);

        self::assertNull(
            $this->runs->validation($this->otherSupplierId, $validationId),
            'Cizí firma nesmí validaci ani přečíst.',
        );
        try {
            $this->overrides->grant(
                $this->otherSupplierId,
                $runId,
                $validationId,
                (int) $this->runs->find($this->supplierId, $runId)['row_version'],
                'foreign-tenant-key',
                $this->actors[2],
                'Cizí firma se pokouší schválit výjimku u cizí mzdové validace.',
            );
            self::fail('Cizí tenant nesmí výjimku schválit.');
        } catch (\OutOfBoundsException $e) {
            self::assertStringContainsString('nebyl nalezen', $e->getMessage());
        }
        self::assertNull(
            $this->runs->validation($this->supplierId, $validationId)['overridden_at'],
        );
    }

    /**
     * Souběh: dvě schválení téže validace se nepobijí.
     *
     * Druhý zápis se stejnou `row_version` narazí na optimistický zámek běhu
     * (409), druhý se stejným `Idempotency-Key` dostane přehrání původního
     * výsledku — v žádném případě nepřepíše cizí odůvodnění.
     */
    public function testConcurrentOverridesDoNotClash(): void
    {
        $locked = $this->lockedRun();
        $runId = (int) $locked['id'];
        $validationId = $this->seedOverridableWarning((int) $locked['revision_id']);
        $version = (int) $this->runs->find($this->supplierId, $runId)['row_version'];

        $first = $this->overrides->grant(
            $this->supplierId,
            $runId,
            $validationId,
            $version,
            'concurrent-first',
            $this->actors[2],
            'První schvalovatel převzal odpovědnost za tuto výjimku.',
        );

        try {
            $this->overrides->grant(
                $this->supplierId,
                $runId,
                $validationId,
                $version,
                'concurrent-second',
                $this->actors[1],
                'Druhý schvalovatel se pokouší tutéž výjimku přepsat.',
            );
            self::fail('Souběžné schválení se stejnou row_version musí selhat.');
        } catch (PayrollRunConflictException $e) {
            self::assertSame((int) $first->run['row_version'], $e->currentVersion);
        }

        // Ani s aktuální row_version už podruhé neprojde: výjimka je schválená.
        try {
            $this->overrides->grant(
                $this->supplierId,
                $runId,
                $validationId,
                (int) $first->run['row_version'],
                'concurrent-third',
                $this->actors[1],
                'Druhý schvalovatel se pokouší tutéž výjimku přepsat podruhé.',
            );
            self::fail('Dvakrát schválená výjimka nedává smysl.');
        } catch (\DomainException $e) {
            self::assertStringContainsString('už schválená', $e->getMessage());
        }

        $stored = $this->runs->validation($this->supplierId, $validationId);
        self::assertSame($this->actors[2], $stored['overridden_by']);
        self::assertSame(
            'První schvalovatel převzal odpovědnost za tuto výjimku.',
            $stored['override_reason'],
        );

        // Opakování TÉHOŽ požadavku (retry klienta) vrátí původní výsledek.
        $replay = $this->overrides->grant(
            $this->supplierId,
            $runId,
            $validationId,
            $version,
            'concurrent-first',
            $this->actors[2],
            'První schvalovatel převzal odpovědnost za tuto výjimku.',
        );
        self::assertTrue($replay->idempotentReplay);
        self::assertSame($this->actors[2], $replay->validation['overridden_by']);

        // Stejný klíč s jiným obsahem je chyba, ne přehrání.
        $this->expectException(PayrollRunIdempotencyException::class);
        $this->overrides->grant(
            $this->supplierId,
            $runId,
            $validationId,
            $version,
            'concurrent-first',
            $this->actors[2],
            'Úplně jiné odůvodnění pod stejným idempotency klíčem.',
        );
    }

    /** Auditní záznam vzniká a nese důvod, schvalovatele i revizi. */
    public function testAuditEventCarriesWhoWhenWhyAndWhichRevision(): void
    {
        $locked = $this->lockedRun();
        $runId = (int) $locked['id'];
        $revisionId = (int) $locked['revision_id'];
        $validationId = $this->seedOverridableWarning($revisionId);
        $reason = 'Vztah nemá v období složku, protože zaměstnanec nastoupil až '
            . 'následující měsíc.';

        $granted = $this->overrides->grant(
            $this->supplierId,
            $runId,
            $validationId,
            (int) $this->runs->find($this->supplierId, $runId)['row_version'],
            'audit-grant-key',
            $this->actors[2],
            $reason,
        );

        $event = $this->lastEvent($runId, 'validation_override');
        self::assertSame($reason, $event['reason']);
        self::assertSame($this->actors[2], (int) $event['actor_user_id']);
        self::assertSame($revisionId, (int) $event['revision_id']);
        self::assertSame($validationId, $event['metadata']['validation_id']);
        self::assertSame('overtime_limit_exceeded', $event['metadata']['validation_code']);
        self::assertTrue($event['metadata']['four_eyes_met']);
        self::assertSame(
            (int) $granted->run['row_version'],
            $event['metadata']['row_version'],
        );

        // Append-only na úrovni databáze — audit výjimky nejde přepsat.
        $this->expectException(\PDOException::class);
        $this->db->pdo()->prepare(
            'UPDATE payroll_run_events SET reason = "tamper" WHERE id = ?'
        )->execute([(int) $event['id']]);
    }

    /** Jedna účetní smí vypočítat běh, povolit výjimku i jej schválit. */
    public function testCalculatorMayOverrideButItIsRecordedAsNotFourEyed(): void
    {
        $locked = $this->lockedRun();
        $runId = (int) $locked['id'];
        $validationId = $this->seedOverridableWarning((int) $locked['revision_id']);
        $calculated = $this->service->calculate(
            $this->supplierId,
            $runId,
            (int) $this->runs->find($this->supplierId, $runId)['row_version'],
            'four-eyes-calculate',
            $this->actors[0],
        );

        $granted = $this->overrides->grant(
            $this->supplierId,
            $runId,
            $validationId,
            (int) $calculated->run['row_version'],
            'four-eyes-grant',
            $this->actors[0],
            'Firma má jediného mzdového účetního, výjimku proto schvaluje on sám.',
        );

        self::assertTrue($granted->granted);
        self::assertFalse($granted->fourEyesMet);
        self::assertFalse(
            $this->lastEvent($runId, 'validation_override')['metadata']['four_eyes_met'],
        );
    }

    /** HTTP vrstva: právo, idempotence, row_version i tvar odpovědi. */
    public function testHttpEndpointEnforcesPermissionAndReturnsTheValidation(): void
    {
        $locked = $this->lockedRun();
        $runId = (int) $locked['id'];
        $validationId = $this->seedOverridableWarning((int) $locked['revision_id']);
        $args = ['id' => (string) $runId, 'validationId' => (string) $validationId];

        $readOnly = $this->role(['payroll' => AccessLevel::READ->value]);
        $forbidden = $this->action->grant(
            $this->apiRequest('POST', $readOnly)->withParsedBody([
                'row_version' => 1,
                'reason' => 'Bez práva na schválení mzdy nesmí výjimka projít.',
            ]),
            new Response(),
            $args,
        );
        self::assertSame(403, $forbidden->getStatusCode());

        $approver = $this->role([
            'payroll' => AccessLevel::READ->value,
            'payroll.approve' => AccessLevel::WRITE->value,
        ]);
        $missingKey = $this->action->grant(
            $this->apiRequest('POST', $approver, withKey: false)->withParsedBody([
                'row_version' => 1,
                'reason' => 'Bez hlavičky Idempotency-Key se výjimka schválit nedá.',
            ]),
            new Response(),
            $args,
        );
        self::assertSame(422, $missingKey->getStatusCode());

        $version = (int) $this->runs->find($this->supplierId, $runId)['row_version'];
        $ok = $this->action->grant(
            $this->apiRequest('POST', $approver)->withParsedBody([
                'row_version' => $version,
                'reason' => 'Nález je doložený a odpovědnost za výplatu přebírám.',
            ]),
            new Response(),
            $args,
        );
        self::assertSame(200, $ok->getStatusCode());
        $payload = $this->json($ok);
        self::assertTrue($payload['granted']);
        self::assertSame(
            'Nález je doložený a odpovědnost za výplatu přebírám.',
            $payload['validation']['override_reason'],
        );
        self::assertSame(
            'Synthetic calculator',
            $payload['validation']['overridden_by_name'],
            'Karta běhu musí umět napsat JMÉNO, ne číslo uživatele.',
        );

        $stale = $this->action->grant(
            $this->apiRequest('POST', $approver)->withParsedBody([
                'row_version' => $version,
                'reason' => 'Zastaralá row_version musí skončit konfliktem, ne zápisem.',
            ]),
            new Response(),
            $args,
        );
        self::assertSame(409, $stale->getStatusCode());
        self::assertSame('row_version_conflict', $this->json($stale)['error']['code']);

        $revoked = $this->action->revoke(
            $this->apiRequest('DELETE', $approver)->withParsedBody([
                'row_version' => (int) $payload['run']['row_version'],
            ]),
            new Response(),
            $args,
        );
        self::assertSame(200, $revoked->getStatusCode());
        self::assertFalse($this->json($revoked)['granted']);
    }

    /**
     * U 225 lidí bez přihlášky je to 225 varování se stejným důvodem. Hromadné
     * schválení je jeden příkaz, ale na každou validaci zapíše tutéž auditní
     * událost jako schválení po jednom — a nic mimo skupinu se ho netkne.
     */
    public function testBulkGrantResolvesTheWholeGroupAndNothingElse(): void
    {
        $locked = $this->lockedRun();
        $runId = (int) $locked['id'];
        $revisionId = (int) $locked['revision_id'];
        $group = [
            $this->seedOverridableWarning($revisionId),
            $this->seedOverridableWarning($revisionId),
            $this->seedOverridableWarning($revisionId),
        ];
        $notRequiring = $this->seedWarning($revisionId, 'overtime_limit_exceeded', false);
        $otherCode = $this->seedWarning($revisionId, 'employment_health_registration_missing', true);
        $unresolvedBefore = $this->runs->validationCounts($this->supplierId, $revisionId)['unresolved_overrides'];
        $version = $this->rowVersion($runId);
        $reason = 'Přesčasy všech tří lidí jsou doložené písemným souhlasem, vyplácí se.';

        $result = $this->overrides->grantMany(
            $this->supplierId,
            $runId,
            'overtime_limit_exceeded',
            null,
            $version,
            'bulk-grant-key',
            $this->actors[2],
            $reason,
        );

        self::assertSame(3, $result->grantedCount);
        self::assertSame(0, $result->skippedCount);
        self::assertFalse($result->idempotentReplay);
        self::assertSame($group, array_map(
            static fn (array $row): int => (int) $row['id'],
            $result->validations,
        ));
        self::assertSame(
            $version + 1,
            (int) $result->run['row_version'],
            'Celá dávka posune row_version jednou, ne za každou validaci.',
        );
        foreach ($group as $validationId) {
            $state = $this->overrideState($validationId);
            self::assertNotNull($state['overridden_at']);
            self::assertSame($reason, $state['override_reason']);
            self::assertSame($this->actors[2], $state['overridden_by']);
        }
        self::assertNull(
            $this->overrideState($notRequiring)['overridden_at'],
            'Kontrola bez requires_override se hromadného schválení nesmí dotknout.',
        );
        self::assertNull(
            $this->overrideState($otherCode)['overridden_at'],
            'Kontrola jiného kódu do skupiny nepatří.',
        );
        self::assertSame(
            $unresolvedBefore - 3,
            $this->runs->validationCounts($this->supplierId, $revisionId)['unresolved_overrides'],
        );

        // Audit: jedna událost na validaci, se stejným tvarem jako po jednom.
        self::assertSame(3, $this->eventCount($runId, 'validation_override'));
        $event = $this->lastEvent($runId, 'validation_override');
        self::assertSame($reason, $event['reason']);
        self::assertSame($this->actors[2], (int) $event['actor_user_id']);
        self::assertSame($revisionId, (int) $event['revision_id']);
        self::assertContains($event['metadata']['validation_id'], $group);
        self::assertSame('overtime_limit_exceeded', $event['metadata']['validation_code']);
        self::assertSame((int) $result->run['row_version'], $event['metadata']['row_version']);
        self::assertSame(3, $event['metadata']['bulk_count']);
        self::assertSame(
            1,
            (int) $this->scalar(
                'SELECT COUNT(*) FROM payroll_run_commands
                  WHERE supplier_id = ? AND run_id = ? AND command_name = ?',
                [$this->supplierId, $runId, PayrollRunValidationOverrideService::COMMAND_GRANT_BULK],
            ),
        );
    }

    /**
     * Seznam z obrazovky nesmí propašovat kontrolu, která schválení nevyžaduje,
     * ani kontrolu jiného kódu — dávka pak neschválí nic.
     */
    public function testBulkGrantWithIdsRefusesForeignMembersAndLeavesEverythingUntouched(): void
    {
        $locked = $this->lockedRun();
        $runId = (int) $locked['id'];
        $revisionId = (int) $locked['revision_id'];
        $first = $this->seedOverridableWarning($revisionId);
        $second = $this->seedOverridableWarning($revisionId);
        $notRequiring = $this->seedWarning($revisionId, 'overtime_limit_exceeded', false);
        $otherCode = $this->seedWarning($revisionId, 'employment_health_registration_missing', true);
        $reason = 'Přesčasy jsou doložené písemným souhlasem zaměstnanců, vyplácí se.';

        foreach ([
            [[$first, $second, $notRequiring], 'nevyžaduje'],
            [[$first, $otherCode], 'nepatří do skupiny'],
        ] as $index => [$ids, $expected]) {
            try {
                $this->overrides->grantMany(
                    $this->supplierId,
                    $runId,
                    'overtime_limit_exceeded',
                    $ids,
                    $this->rowVersion($runId),
                    'bulk-refused-' . $index,
                    $this->actors[2],
                    $reason,
                );
                self::fail('Dávka s cizím členem nesmí projít.');
            } catch (\DomainException $e) {
                self::assertStringContainsString($expected, $e->getMessage());
            }
        }

        foreach ([$first, $second, $notRequiring, $otherCode] as $validationId) {
            self::assertNull($this->overrideState($validationId)['overridden_at']);
        }
        self::assertSame(0, $this->eventCount($runId, 'validation_override'));
    }

    /** Bez odůvodnění neprojde ani hromadně — pravidla jsou tatáž jako po jednom. */
    public function testBulkGrantRequiresAReason(): void
    {
        $locked = $this->lockedRun();
        $runId = (int) $locked['id'];
        $validationId = $this->seedOverridableWarning((int) $locked['revision_id']);

        foreach (['', null, 'ok'] as $index => $reason) {
            try {
                $this->overrides->grantMany(
                    $this->supplierId,
                    $runId,
                    'overtime_limit_exceeded',
                    null,
                    $this->rowVersion($runId),
                    'bulk-no-reason-' . $index,
                    $this->actors[2],
                    $reason,
                );
                self::fail('Hromadná výjimka bez odůvodnění nesmí projít.');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('Důvod výjimky', $e->getMessage());
            }
        }
        self::assertNull($this->overrideState($validationId)['overridden_at']);
    }

    /**
     * Opakované volání nic nezdvojí: retry s týmž klíčem přehraje výsledek,
     * nový příkaz nad už schválenou skupinou nic nezapíše.
     */
    public function testBulkGrantIsIdempotent(): void
    {
        $locked = $this->lockedRun();
        $runId = (int) $locked['id'];
        $revisionId = (int) $locked['revision_id'];
        $group = [
            $this->seedOverridableWarning($revisionId),
            $this->seedOverridableWarning($revisionId),
        ];
        $version = $this->rowVersion($runId);
        $reason = 'Přesčasy jsou doložené písemným souhlasem zaměstnanců, vyplácí se.';

        $first = $this->overrides->grantMany(
            $this->supplierId,
            $runId,
            'overtime_limit_exceeded',
            $group,
            $version,
            'bulk-idempotent-key',
            $this->actors[2],
            $reason,
        );
        self::assertSame(2, $first->grantedCount);
        $after = (int) $first->run['row_version'];

        $replay = $this->overrides->grantMany(
            $this->supplierId,
            $runId,
            'overtime_limit_exceeded',
            array_reverse($group),
            $version,
            'bulk-idempotent-key',
            $this->actors[2],
            $reason,
        );
        self::assertTrue($replay->idempotentReplay);
        self::assertSame(2, $replay->grantedCount);
        self::assertCount(2, $replay->validations);
        self::assertSame($after, $this->rowVersion($runId));

        $again = $this->overrides->grantMany(
            $this->supplierId,
            $runId,
            'overtime_limit_exceeded',
            null,
            $after,
            'bulk-idempotent-second',
            $this->actors[2],
            $reason,
        );
        self::assertFalse($again->idempotentReplay);
        self::assertSame(0, $again->grantedCount);
        self::assertSame(2, $again->skippedCount);
        self::assertSame($after, $this->rowVersion($runId), 'Prázdná dávka nesmí posunout row_version.');
        self::assertSame(2, $this->eventCount($runId, 'validation_override'));

        $this->expectException(PayrollRunIdempotencyException::class);
        $this->overrides->grantMany(
            $this->supplierId,
            $runId,
            'overtime_limit_exceeded',
            $group,
            $version,
            'bulk-idempotent-key',
            $this->actors[2],
            'Úplně jiné odůvodnění pod stejným idempotency klíčem dávky.',
        );
    }

    /**
     * Multi-tenant izolace: cizí firma běh ani jeho validace nenajde a validace
     * jednoho běhu nejdou schválit přes jiný běh téže firmy.
     */
    public function testBulkGrantRefusesForeignTenantAndForeignRun(): void
    {
        $locked = $this->lockedRun();
        $runId = (int) $locked['id'];
        $validationId = $this->seedOverridableWarning((int) $locked['revision_id']);
        $reason = 'Cizí firma se pokouší hromadně schválit cizí mzdové validace.';

        foreach ([null, [$validationId]] as $index => $ids) {
            try {
                $this->overrides->grantMany(
                    $this->otherSupplierId,
                    $runId,
                    'overtime_limit_exceeded',
                    $ids,
                    $this->rowVersion($runId),
                    'bulk-foreign-tenant-' . $index,
                    $this->actors[2],
                    $reason,
                );
                self::fail('Cizí tenant nesmí výjimku hromadně schválit.');
            } catch (\OutOfBoundsException $e) {
                self::assertStringContainsString('nebyl nalezen', $e->getMessage());
            }
        }

        $other = $this->service->createRun(
            $this->supplierId,
            '2026-07-01',
            '2026-08-15',
            null,
            $this->actors[0],
        );
        $otherLocked = $this->service->lockInputs(
            $this->supplierId,
            (int) $other['id'],
            (int) $other['row_version'],
            'bulk-foreign-run-lock',
            $this->actors[0],
        );
        try {
            $this->overrides->grantMany(
                $this->supplierId,
                (int) $otherLocked->run['id'],
                'overtime_limit_exceeded',
                [$validationId],
                (int) $otherLocked->run['row_version'],
                'bulk-foreign-run',
                $this->actors[2],
                'Validace jednoho běhu se nesmí schválit přes jiný běh téže firmy.',
            );
            self::fail('Validace cizího běhu nesmí projít.');
        } catch (\OutOfBoundsException $e) {
            self::assertStringContainsString('nebyla nalezena', $e->getMessage());
        }

        self::assertNull($this->overrideState($validationId)['overridden_at']);
    }

    /** HTTP vrstva hromadné výjimky: stejné právo, validace těla, tvar odpovědi. */
    public function testBulkHttpEndpointEnforcesPermissionAndReturnsTheGroup(): void
    {
        $locked = $this->lockedRun();
        $runId = (int) $locked['id'];
        $revisionId = (int) $locked['revision_id'];
        $group = [
            $this->seedOverridableWarning($revisionId),
            $this->seedOverridableWarning($revisionId),
        ];
        $args = ['id' => (string) $runId];
        $body = [
            'row_version' => $this->rowVersion($runId),
            'code' => 'overtime_limit_exceeded',
            'validation_ids' => $group,
            'reason' => 'Přesčasy jsou doložené písemným souhlasem zaměstnanců, vyplácí se.',
        ];

        $readOnly = $this->role(['payroll' => AccessLevel::READ->value]);
        self::assertSame(403, $this->action->grantBulk(
            $this->apiRequest('POST', $readOnly)->withParsedBody($body),
            new Response(),
            $args,
        )->getStatusCode());

        $approver = $this->role([
            'payroll' => AccessLevel::READ->value,
            'payroll.approve' => AccessLevel::WRITE->value,
        ]);
        foreach ([
            ['code' => ''],
            ['validation_ids' => 'all'],
            ['validation_ids' => [1, 'x']],
        ] as $broken) {
            self::assertSame(422, $this->action->grantBulk(
                $this->apiRequest('POST', $approver)->withParsedBody([...$body, ...$broken]),
                new Response(),
                $args,
            )->getStatusCode());
        }

        $foreign = $this->action->grantBulk(
            $this->apiRequest('POST', $approver)
                ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->otherSupplierId)
                ->withParsedBody($body),
            new Response(),
            $args,
        );
        self::assertSame(404, $foreign->getStatusCode(), 'Cizí firma běh nesmí najít.');

        $ok = $this->action->grantBulk(
            $this->apiRequest('POST', $approver)->withParsedBody($body),
            new Response(),
            $args,
        );
        self::assertSame(200, $ok->getStatusCode());
        $payload = $this->json($ok);
        self::assertSame(2, $payload['granted_count']);
        self::assertSame(0, $payload['skipped_count']);
        self::assertSame($group, array_column($payload['validations'], 'id'));
        self::assertSame('Synthetic calculator', $payload['validations'][0]['overridden_by_name']);

        $stale = $this->action->grantBulk(
            $this->apiRequest('POST', $approver)->withParsedBody($body),
            new Response(),
            $args,
        );
        self::assertSame(409, $stale->getStatusCode());
        self::assertSame('row_version_conflict', $this->json($stale)['error']['code']);
    }

    // ── podklady ────────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function lockedRun(): array
    {
        $run = $this->service->createRun(
            $this->supplierId,
            '2026-06-01',
            '2026-07-15',
            null,
            $this->actors[0],
        );
        $locked = $this->service->lockInputs(
            $this->supplierId,
            (int) $run['id'],
            (int) $run['row_version'],
            'override-lock-' . bin2hex(random_bytes(4)),
            $this->actors[0],
        );
        return [
            'id' => (int) $locked->run['id'],
            'revision_id' => (int) $locked->revision['id'],
        ];
    }

    /**
     * Varování vyžadující schválení, zapsané přímo k revizi.
     *
     * Kód je `overtime_limit_exceeded` — právě u přesčasů dává schválení výjimky
     * smysl a právě kvůli chybějící routě tam zůstalo `requires_override = 0`.
     */
    private function seedOverridableWarning(int $revisionId): int
    {
        return $this->seedWarning($revisionId, 'overtime_limit_exceeded', true);
    }

    private function seedWarning(int $revisionId, string $code, bool $requiresOverride): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_run_validations
                (supplier_id, revision_id, severity, code, entity_type,
                 entity_id, message, remediation_path, requires_override)
             VALUES (?, ?, "warning", ?, "employment", ?, ?, "/payroll/time", ?)'
        )->execute([
            $this->supplierId,
            $revisionId,
            $code,
            $this->employmentId,
            'Přesčas překročil zákonný limit podle § 93 zákoníku práce.',
            $requiresOverride ? 1 : 0,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @return array{overridden_at:mixed,override_reason:mixed,overridden_by:mixed} */
    private function overrideState(int $validationId): array
    {
        $row = $this->runs->validation($this->supplierId, $validationId);
        self::assertNotNull($row);
        return [
            'overridden_at' => $row['overridden_at'],
            'override_reason' => $row['override_reason'],
            'overridden_by' => $row['overridden_by'],
        ];
    }

    private function eventCount(int $runId, string $eventType): int
    {
        return (int) $this->scalar(
            'SELECT COUNT(*) FROM payroll_run_events
              WHERE supplier_id = ? AND run_id = ? AND event_type = ?',
            [$this->supplierId, $runId, $eventType],
        );
    }

    private function rowVersion(int $runId): int
    {
        return (int) $this->runs->find($this->supplierId, $runId)['row_version'];
    }

    /** @return list<array<string,mixed>> */
    private function requiresOverrideValidations(int $revisionId): array
    {
        return array_values(array_filter(
            $this->runs->validations($this->supplierId, $revisionId),
            static fn (array $row): bool => $row['requires_override'] === true,
        ));
    }

    /** @return array<string,mixed> */
    private function lastEvent(int $runId, string $eventType): array
    {
        foreach (array_reverse($this->runs->events($this->supplierId, $runId)) as $event) {
            if ($event['event_type'] === $eventType) {
                return $event;
            }
        }
        self::fail('Auditní událost ' . $eventType . ' nevznikla.');
    }

    /** @return array{int,int} */
    private function employment(string $code = 'SYN-MZ01W07'): array
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, "Synthetic Override Person", "employee", 1)'
        )->execute([$this->supplierId]);
        $employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employee_profiles
                (supplier_id, employee_id, profile_status)
             VALUES (?, ?, "ready")'
        )->execute([$this->supplierId, $employeeId]);
        $pdo->prepare(
            'INSERT INTO payroll_offices (supplier_id, code, name, is_active)
             VALUES (?, ?, "Syntetická účtárna", 1)'
        )->execute([$this->supplierId, substr('U' . $code, 0, 16)]);
        $officeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_office_registration_versions
                (supplier_id, office_id, effective_from,
                 social_security_variable_symbol, source_reference)
             VALUES (?, ?, "2026-01-01", "0012345678", "synthetic:validation-override")'
        )->execute([$this->supplierId, $officeId]);
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, office_id, code, relation_type, status,
                 start_date, actual_start_date, is_primary)
             VALUES (?, ?, ?, ?, "employment", "active",
                     "2026-01-01", "2026-01-01", 1)'
        )->execute([$this->supplierId, $employeeId, $officeId, $code]);
        $employmentId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employment_terms
                (supplier_id, employment_id, office_id, effective_from,
                 planned_start_on,
                 actual_start_on, weekly_hours, workload_basis_points,
                 social_insurance_participation,
                 health_insurance_participation, tax_regime,
                 tax_declaration_signed, is_primary)
             VALUES (?, ?, ?, "2026-01-01", "2026-01-01", "2026-01-01",
                     40, 10000, "automatic", "automatic", "advance", 1, 1)'
        )->execute([$this->supplierId, $employmentId, $officeId]);
        return [$employeeId, $employmentId];
    }

    private function approvedInput(int $amountMinor, string $code): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_component_definitions
                (supplier_id, code, name, component_kind, value_kind,
                 frequency_kind, tax_treatment,
                 social_participation_treatment, social_treatment,
                 health_participation_treatment, health_treatment,
                 average_earning_treatment,
                 enforcement_treatment, jmhz_treatment, statistics_treatment,
                 accounting_debit_code, accounting_credit_code, valid_from)
             VALUES (?, ?, ?, "base_wage", "monetary", "regular", "included",
                     "included", "included", "included", "included",
                     "included", "included", "included",
                     "included", "521", "331", "2026-01-01")'
        )->execute([$this->supplierId, $code, "Synthetic {$code}"]);
        $componentId = (int) $pdo->lastInsertId();
        $snapshot = [
            'code' => $code,
            'name' => "Synthetic {$code}",
            'component_kind' => 'base_wage',
            'value_kind' => 'monetary',
            'frequency_kind' => 'regular',
            'tax_treatment' => 'included',
            'social_participation_treatment' => 'included',
            'social_treatment' => 'included',
            'health_participation_treatment' => 'included',
            'health_treatment' => 'included',
            'average_earning_treatment' => 'included',
            'enforcement_treatment' => 'included',
            'jmhz_treatment' => 'included',
            'statistics_treatment' => 'included',
            'accounting_debit_code' => '521',
            'accounting_credit_code' => '331',
            'annual_limit_minor' => null,
            'component_id' => $componentId,
            'component_row_version' => 1,
            'valid_from' => '2026-01-01',
            'valid_to' => null,
        ];
        $json = CanonicalJson::encode($snapshot);
        $pdo->prepare(
            'INSERT INTO payroll_inputs
                (supplier_id, employee_id, employment_id, component_id,
                 period_start, amount_minor, source_kind, status,
                 component_snapshot_json, component_snapshot_hash,
                 approved_by, approved_at)
             VALUES (?, ?, ?, ?, "2026-06-01", ?, "manual", "approved", ?, ?, ?, NOW())'
        )->execute([
            $this->supplierId,
            $this->employeeId,
            $this->employmentId,
            $componentId,
            $amountMinor,
            $json,
            hash('sha256', $json, true),
            $this->actors[0],
        ]);
        return (int) $pdo->lastInsertId();
    }

    private function createActor(string $suffix): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO users
                (email, password_hash, name, role, locale, is_active)
             VALUES (?, ?, ?, "readonly", "cs", 1)'
        );
        $stmt->execute([
            "mz01w07-{$suffix}-" . bin2hex(random_bytes(4)) . '@invalid.example',
            '$2y$10$uses.only.synthetic.placeholder.hash00000000000000000',
            "Synthetic {$suffix}",
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @return array<string,mixed> */
    private function employerPolicyInput(): array
    {
        return [
            'valid_from' => '2026-01-01',
            'valid_to' => null,
            'payday_day' => 10,
            'payday_month_offset' => 1,
            'payday_business_day_rule' => 'previous_business_day',
            'balance_rounding_mode' => 'exact_minor_units',
            'home_office_policy' => 'not_used',
            'travel_expense_policy' => 'not_used',
            'automatic_posting_enabled' => true,
            'delivery_channel' => 'disabled',
            'delivery_verified_on' => null,
            'source_kind' => 'manual',
            'source_reference' => 'synthetic:payroll-override-policy',
        ];
    }

    /** @param array<string,int> $permissions */
    private function role(array $permissions): EffectiveRole
    {
        return new EffectiveRole(
            91,
            'Syntetická mzdová účetní',
            'staff',
            true,
            $permissions,
        );
    }

    private function apiRequest(
        string $method,
        EffectiveRole $role,
        bool $withKey = true,
    ): \Psr\Http\Message\ServerRequestInterface {
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, '/api/payroll/runs/1/validations/1/override')
            ->withAttribute(
                SupplierScopeMiddleware::ATTR_CURRENT_ID,
                $this->supplierId,
            )
            ->withAttribute(AuthMiddleware::ATTR_USER, [
                'id' => $this->actors[0],
                'role' => 'readonly',
            ])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute('auth.effective_role', $role);

        return $withKey
            ? $request->withHeader('Idempotency-Key', 'http-' . bin2hex(random_bytes(6)))
            : $request;
    }

    /** @param list<mixed> $params */
    private function scalar(string $sql, array $params): mixed
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    /** @return array<string,mixed> */
    private function json(Response $response): array
    {
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded);
        return $decoded;
    }
}
