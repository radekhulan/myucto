<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollRetentionAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\Payroll\PayrollErasureProposalRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Payroll\Retention\PayrollRetentionCatalog;
use MyInvoice\Tests\Support\PayrollDeletionFixturesTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * HTTP kontrakt retence a výmazu.
 *
 * Obrazovky nad tímhle API dělají nevratné věci, takže se tu hlídají tři
 * vlastnosti, které z UI vidět nejsou:
 *
 *  - `payroll.retention` a `payroll.erasure` jsou DVĚ RŮZNÁ práva. Kdo smí číst
 *    lhůty, nesmí tím pádem odklepnout výmaz — a naopak.
 *  - Zápis vyžaduje úroveň WRITE; čtecí role zápisové cesty nepustí.
 *  - Nic z toho nejde přes API token, jen z přihlášené relace.
 *
 * Doménové invarianty (nelze zkrátit lhůtu, zadržení blokuje výmaz) drží
 * {@see PayrollRetentionServiceTest}; tady se ověřuje jen jejich překlad
 * do HTTP odpovědi.
 */
#[Group('integration')]
final class PayrollRetentionApiTest extends TestCase
{
    use PayrollDeletionFixturesTrait;

    private PayrollRetentionAction $action;
    private PayrollErasureProposalRepository $proposals;

    protected function setUp(): void
    {
        $container = $this->bootPayrollFixtures();
        if (!$this->db->hasTable('payroll_erasure_proposals')) {
            self::markTestSkipped('Migrace 1397 neproběhla.');
        }

        $action = $container->get(PayrollRetentionAction::class);
        self::assertInstanceOf(PayrollRetentionAction::class, $action);
        $this->action = $action;

        $proposals = $container->get(PayrollErasureProposalRepository::class);
        self::assertInstanceOf(PayrollErasureProposalRepository::class, $proposals);
        $this->proposals = $proposals;
    }

    protected function tearDown(): void
    {
        $this->shutdownPayrollFixtures();
    }

    public function testRetentionReaderSeesCatalogAndNamedAssessment(): void
    {
        $overview = $this->action->overview(
            $this->roleRequest('GET', '/api/payroll/retention', $this->retentionRole()),
            new Response(),
        );
        self::assertSame(200, $overview->getStatusCode());
        $payload = $this->json($overview);
        self::assertNotEmpty($payload['categories']);

        $assessment = $this->action->assessment(
            $this->roleRequest('GET', '/api/payroll/retention/assessment', $this->retentionRole()),
            new Response(),
        );
        self::assertSame(200, $assessment->getStatusCode());
        $items = $this->json($assessment)['items'];
        self::assertIsArray($items);
        self::assertNotEmpty($items);
        // Posudek musí říct, o KOHO jde — panel k výmazu se jinak nedá zkontrolovat.
        self::assertSame('Testovací Pracovník', $items[0]['full_name']);
    }

    public function testReadOnlyRetentionRoleCannotWritePolicyOrHold(): void
    {
        $policy = $this->action->putPolicy(
            $this->roleRequest(
                'PUT',
                '/api/payroll/retention/policies/payroll_sheet',
                $this->retentionRole(AccessLevel::READ),
                ['extra_years' => 5, 'reason' => 'Vnitřní předpis'],
            ),
            new Response(),
            ['category' => PayrollRetentionCatalog::PAYROLL_SHEET],
        );
        self::assertSame(403, $policy->getStatusCode());

        $hold = $this->action->placeHold(
            $this->roleRequest(
                'POST',
                '/api/payroll/retention/holds',
                $this->retentionRole(AccessLevel::READ),
                ['employee_id' => $this->employeeId, 'reason' => 'enforcement', 'description' => 'X'],
            ),
            new Response(),
        );
        self::assertSame(403, $hold->getStatusCode());
    }

    public function testRetentionPermissionAloneDoesNotUnlockErasure(): void
    {
        $list = $this->action->listProposals(
            $this->roleRequest('GET', '/api/payroll/retention/erasure', $this->retentionRole(AccessLevel::WRITE)),
            new Response(),
        );
        self::assertSame(403, $list->getStatusCode());
        self::assertSame('forbidden', $this->errorOf($list)['code']);

        $create = $this->action->createProposal(
            $this->roleRequest('POST', '/api/payroll/retention/erasure', $this->retentionRole(AccessLevel::WRITE)),
            new Response(),
        );
        self::assertSame(403, $create->getStatusCode());
    }

    public function testErasurePermissionAloneDoesNotUnlockRetentionWrites(): void
    {
        $policy = $this->action->putPolicy(
            $this->roleRequest(
                'PUT',
                '/api/payroll/retention/policies/payroll_sheet',
                $this->erasureRole(),
                ['extra_years' => 5, 'reason' => 'Vnitřní předpis'],
            ),
            new Response(),
            ['category' => PayrollRetentionCatalog::PAYROLL_SHEET],
        );
        self::assertSame(403, $policy->getStatusCode());
    }

    public function testBearerTokenIsRefusedEverywhere(): void
    {
        $overview = $this->action->overview(
            $this->roleRequest('GET', '/api/payroll/retention', $this->retentionRole(), null, 'bearer'),
            new Response(),
        );
        self::assertSame(403, $overview->getStatusCode());
        self::assertSame('session_required', $this->errorOf($overview)['code']);

        $proposals = $this->action->listProposals(
            $this->roleRequest('GET', '/api/payroll/retention/erasure', $this->erasureRole(), null, 'bearer'),
            new Response(),
        );
        self::assertSame(403, $proposals->getStatusCode());
        self::assertSame('session_required', $this->errorOf($proposals)['code']);
    }

    /** Zkrácení je doménová invarianta — HTTP ji musí vrátit jako 422, ne 500. */
    public function testShorteningStatutoryPeriodIsRefusedWith422(): void
    {
        $response = $this->action->putPolicy(
            $this->roleRequest(
                'PUT',
                '/api/payroll/retention/policies/payroll_sheet',
                $this->retentionRole(AccessLevel::WRITE),
                ['extra_years' => 0, 'override_years' => 3, 'reason' => 'Zkrátit'],
            ),
            new Response(),
            ['category' => PayrollRetentionCatalog::PAYROLL_SHEET],
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('payroll_retention_statutory_override', $this->errorOf($response)['code']);
        $this->assertActionableMessage((string) $this->errorOf($response)['message']);
    }

    public function testHoldNeedsBothReasonAndDescription(): void
    {
        $badReason = $this->action->placeHold(
            $this->roleRequest(
                'POST',
                '/api/payroll/retention/holds',
                $this->retentionRole(AccessLevel::WRITE),
                ['employee_id' => $this->employeeId, 'reason' => 'vymyslene', 'description' => 'X'],
            ),
            new Response(),
        );
        self::assertSame(422, $badReason->getStatusCode());

        $noDescription = $this->action->placeHold(
            $this->roleRequest(
                'POST',
                '/api/payroll/retention/holds',
                $this->retentionRole(AccessLevel::WRITE),
                ['employee_id' => $this->employeeId, 'reason' => 'enforcement', 'description' => '  '],
            ),
            new Response(),
        );
        self::assertSame(422, $noDescription->getStatusCode());

        $ok = $this->action->placeHold(
            $this->roleRequest(
                'POST',
                '/api/payroll/retention/holds',
                $this->retentionRole(AccessLevel::WRITE),
                [
                    'employee_id' => $this->employeeId,
                    'reason' => 'enforcement',
                    'description' => 'Exekuce sp. zn. TEST-API',
                    'placed_on' => '2026-01-01',
                ],
            ),
            new Response(),
        );
        self::assertSame(200, $ok->getStatusCode());

        $listed = $this->action->listHolds(
            $this->roleRequest('GET', '/api/payroll/retention/holds', $this->retentionRole()),
            new Response(),
        );
        $holds = $this->json($listed)['holds'];
        self::assertIsArray($holds);
        self::assertCount(1, $holds);
        self::assertSame('Testovací Pracovník', $holds[0]['employee_full_name']);
    }

    /** Cizí osoba nesmí prozradit ani to, že v jiné firmě existuje. */
    public function testHoldOnForeignEmployeeIs404(): void
    {
        $foreign = $this->insertEmployee($this->otherSupplierId, 'Cizí Osoba');

        $response = $this->action->placeHold(
            $this->roleRequest(
                'POST',
                '/api/payroll/retention/holds',
                $this->retentionRole(AccessLevel::WRITE),
                ['employee_id' => $foreign, 'reason' => 'enforcement', 'description' => 'X'],
            ),
            new Response(),
        );
        self::assertSame(404, $response->getStatusCode());
    }

    public function testNothingToProposeIsAConflictNotAnEmptyProposal(): void
    {
        $response = $this->action->createProposal(
            $this->roleRequest('POST', '/api/payroll/retention/erasure', $this->erasureRole(AccessLevel::WRITE)),
            new Response(),
        );

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('payroll_erasure_nothing_to_propose', $this->errorOf($response)['code']);
    }

    /**
     * Detail návrhu musí jmenovat osobu a rozepsat dopad ROZEBRANÝ, ne jako
     * řetězec JSON — jinak by ho musel rozebírat prohlížeč.
     */
    public function testProposalDetailNamesPeopleAndDecodesImpact(): void
    {
        $proposalId = $this->expiredProposal();

        $response = $this->action->showProposal(
            $this->roleRequest('GET', '/api/payroll/retention/erasure/' . $proposalId, $this->erasureRole()),
            new Response(),
            ['id' => (string) $proposalId],
        );

        self::assertSame(200, $response->getStatusCode());
        $payload = $this->json($response);
        self::assertSame('pending', $payload['proposal']['status']);
        $items = $payload['items'];
        self::assertIsArray($items);
        self::assertCount(1, $items);
        self::assertSame('Testovací Pracovník', $items[0]['full_name']);
        self::assertIsArray($items[0]['cascade_counts']);
        self::assertArrayHasKey('identity', $items[0]['cascade_counts']);
    }

    /** Provedení bez schválení je 409, ne tiché smazání. */
    public function testExecuteWithoutApprovalIsConflict(): void
    {
        $proposalId = $this->expiredProposal();

        $response = $this->action->executeProposal(
            $this->roleRequest(
                'POST',
                '/api/payroll/retention/erasure/' . $proposalId . '/execute',
                $this->erasureRole(AccessLevel::WRITE),
                ['confirmation' => PayrollErasureProposalRepository::EXECUTE_CONFIRMATION],
            ),
            new Response(),
            ['id' => (string) $proposalId],
        );

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('payroll_erasure_not_approved', $this->errorOf($response)['code']);
        self::assertSame(
            1,
            $this->rowCount('payroll_monthly_records', 'employee_id', $this->employeeId),
        );
    }

    /** Schválit a provést jsou dva požadavky. Jeden klik výmaz nespustí. */
    public function testApproveThenExecuteIsTwoSeparateCalls(): void
    {
        $proposalId = $this->expiredProposal();

        $approve = $this->action->approveProposal(
            $this->roleRequest(
                'POST',
                '/api/payroll/retention/erasure/' . $proposalId . '/approve',
                $this->erasureRole(AccessLevel::WRITE),
            ),
            new Response(),
            ['id' => (string) $proposalId],
        );
        self::assertSame(200, $approve->getStatusCode());
        // Schválení samo nic nesmaže.
        self::assertSame(
            1,
            $this->rowCount('payroll_monthly_records', 'employee_id', $this->employeeId),
        );

        // Sólo schválení otevře odkladnou lhůtu (W30 / C-07) — provést jde
        // až po ní, a jen s opsanou potvrzovací frází.
        $tooSoon = $this->action->executeProposal(
            $this->roleRequest(
                'POST',
                '/api/payroll/retention/erasure/' . $proposalId . '/execute',
                $this->erasureRole(AccessLevel::WRITE),
                ['confirmation' => PayrollErasureProposalRepository::EXECUTE_CONFIRMATION],
            ),
            new Response(),
            ['id' => (string) $proposalId],
        );
        self::assertSame(409, $tooSoon->getStatusCode());
        self::assertSame('payroll_erasure_cooling_off', $this->errorOf($tooSoon)['code']);
        $this->elapseCoolingOff($proposalId);

        $execute = $this->action->executeProposal(
            $this->roleRequest(
                'POST',
                '/api/payroll/retention/erasure/' . $proposalId . '/execute',
                $this->erasureRole(AccessLevel::WRITE),
                ['confirmation' => PayrollErasureProposalRepository::EXECUTE_CONFIRMATION],
            ),
            new Response(),
            ['id' => (string) $proposalId],
        );
        self::assertSame(200, $execute->getStatusCode());
        self::assertSame(1, $this->json($execute)['summary']['done']);
        self::assertSame(
            PayrollErasureProposalRepository::STATUS_EXECUTED,
            (string) ($this->proposals->find($this->supplierId, $proposalId)['status'] ?? ''),
        );
    }

    // ── Pomocné ──────────────────────────────────────────────────────────────

    /** Testovací ekvivalent uplynutí odkladné lhůty (W30 / C-07). */
    private function elapseCoolingOff(int $proposalId): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_erasure_proposals
                SET executable_from = NOW() - INTERVAL 1 HOUR
              WHERE supplier_id = ? AND id = ?',
        )->execute([$this->supplierId, $proposalId]);
    }

    private function expiredProposal(): int
    {
        $this->db->pdo()->prepare(
            "INSERT INTO payroll_monthly_records
                (supplier_id, employee_id, year, month, gross, breakdown,
                 advance_tax_final, net_final)
             VALUES (?, ?, 1975, 1, 40000, '{}', 4000, 34000)"
        )->execute([$this->supplierId, $this->employeeId]);

        $this->db->pdo()->prepare(
            "UPDATE payroll_employments
                SET status = 'ended', start_date = '1975-01-01', end_date = '1975-12-31'
              WHERE supplier_id = ? AND employee_id = ?"
        )->execute([$this->supplierId, $this->employeeId]);
        $this->db->pdo()->prepare(
            "UPDATE payroll_employees SET updated_at = '1975-12-31 00:00:00'
              WHERE supplier_id = ? AND id = ?"
        )->execute([$this->supplierId, $this->employeeId]);

        $id = $this->proposals->create($this->supplierId, $this->userId, '2026-08-15', null);
        self::assertNotNull($id, 'Fixture nevyrobila návrh k výmazu.');

        return $id;
    }

    private function retentionRole(AccessLevel $level = AccessLevel::READ): EffectiveRole
    {
        return new EffectiveRole(
            2,
            'Mzdová účetní',
            'staff',
            true,
            ['payroll.retention' => $level->value],
        );
    }

    private function erasureRole(AccessLevel $level = AccessLevel::READ): EffectiveRole
    {
        return new EffectiveRole(
            3,
            'Správce osobních údajů',
            'staff',
            true,
            ['payroll.erasure' => $level->value],
        );
    }

    /** @param array<string,mixed>|null $body */
    private function roleRequest(
        string $method,
        string $path,
        EffectiveRole $role,
        ?array $body = null,
        string $authMethod = 'session',
    ): ServerRequestInterface {
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, $path)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'readonly'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, $authMethod)
            ->withAttribute('auth.effective_role', $role);

        return $body === null ? $request : $request->withParsedBody($body);
    }
}
