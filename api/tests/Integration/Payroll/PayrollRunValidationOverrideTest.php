<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollRunValidationOverrideAction;
use MyInvoice\Bootstrap;
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
        $container = Bootstrap::buildContainer();
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
     * Zaměstnanec bez schválené mzdové složky vyrábí ve snapshotu varování
     * `employment_without_inputs` s `requires_override = 1` — tedy přesně tu
     * validaci, která doteď běh zablokovala natrvalo. Test tvrdí dvě věci:
     * takové varování v modulu SKUTEČNĚ vzniká, a jde odklidit.
     */
    public function testWarningRequiringOverrideCanBeCleared(): void
    {
        // Druhý pracovní vztah bez jediné schválené složky = přirozený zdroj
        // varování s požadavkem na override.
        $this->employment('SYN-NOINPUT');
        $locked = $this->lockedRun();
        $revisionId = (int) $locked['revision_id'];

        $pending = $this->requiresOverrideValidations($revisionId);
        self::assertNotSame(
            [],
            $pending,
            'Modul vyrábí varování s requires_override — musí pro ně existovat cesta ven.',
        );
        self::assertSame('employment_without_inputs', $pending[0]['code']);
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
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_run_validations
                (supplier_id, revision_id, severity, code, entity_type,
                 entity_id, message, remediation_path, requires_override)
             VALUES (?, ?, "warning", "overtime_limit_exceeded", "employment",
                     ?, ?, "/payroll/time", 1)'
        )->execute([
            $this->supplierId,
            $revisionId,
            $this->employmentId,
            'Přesčas překročil zákonný limit podle § 93 zákoníku práce.',
        ]);
        return (int) $this->db->pdo()->lastInsertId();
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
            'four_eyes_required' => true,
            'automatic_calculation_enabled' => true,
            'automatic_posting_enabled' => true,
            'automatic_payments_enabled' => true,
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
