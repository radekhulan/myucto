<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollComponentsAction;
use MyInvoice\Action\Payroll\PayrollEmployerPolicyAction;
use MyInvoice\Action\Payroll\PayrollInstitutionAccountsAction;
use MyInvoice\Action\Payroll\PayrollRecurringComponentsAction;
use MyInvoice\Repository\Payroll\PayrollComponentDeletionRepository;
use MyInvoice\Repository\Payroll\PayrollEmployerPolicyDeletionRepository;
use MyInvoice\Repository\Payroll\PayrollInstitutionAccountDeletionRepository;
use MyInvoice\Repository\Payroll\PayrollRecurringComponentDeletionRepository;
use MyInvoice\Tests\Support\JmhzSpecPackageFixtureTrait;
use MyInvoice\Tests\Support\PayrollDeletionFixturesTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Response;

/**
 * Mazání mzdového nastavení: účet instituce, zaměstnavatelské pravidlo,
 * mzdová složka a pravidelná složka.
 *
 * Vodicí princip: blokovat smí VÝHRADNĚ důkaz pohybu. Konfigurační řádek,
 * podle kterého se nikdy nic nepočítalo a na který nikdy nešly peníze, se maže.
 */
#[Group('integration')]
final class PayrollSettingsDeletionApiTest extends TestCase
{
    use JmhzSpecPackageFixtureTrait;
    use PayrollDeletionFixturesTrait;

    private PayrollInstitutionAccountsAction $accountsAction;
    private PayrollEmployerPolicyAction $policyAction;
    private PayrollComponentsAction $componentsAction;
    private PayrollRecurringComponentsAction $recurringAction;
    private PayrollInstitutionAccountDeletionRepository $accountDeletion;
    private PayrollEmployerPolicyDeletionRepository $policyDeletion;
    private PayrollComponentDeletionRepository $componentDeletion;
    private PayrollRecurringComponentDeletionRepository $recurringDeletion;

    protected function setUp(): void
    {
        $container = $this->bootPayrollFixtures();
        foreach ([
            'accountsAction' => PayrollInstitutionAccountsAction::class,
            'policyAction' => PayrollEmployerPolicyAction::class,
            'componentsAction' => PayrollComponentsAction::class,
            'recurringAction' => PayrollRecurringComponentsAction::class,
            'accountDeletion' => PayrollInstitutionAccountDeletionRepository::class,
            'policyDeletion' => PayrollEmployerPolicyDeletionRepository::class,
            'componentDeletion' => PayrollComponentDeletionRepository::class,
            'recurringDeletion' => PayrollRecurringComponentDeletionRepository::class,
        ] as $property => $class) {
            $service = $container->get($class);
            self::assertInstanceOf($class, $service);
            $this->{$property} = $service;
        }
    }

    protected function tearDown(): void
    {
        $this->shutdownPayrollFixtures();
    }

    // ── Účet instituce ───────────────────────────────────────────────────────

    public function testUnusedInstitutionAccountIsDeletable(): void
    {
        $accountId = $this->insertInstitutionAccount('FU001');

        $listed = $this->listedAccount($accountId);
        self::assertTrue($listed['can_delete']);
        self::assertNull($listed['delete_blocker']);
        $detail = $this->detailAccount($accountId);
        self::assertTrue($detail['can_delete']);

        $response = $this->deleteAccount($accountId);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(0, $this->rowCount('payroll_institution_accounts', 'id', $accountId));
    }

    public function testInstitutionAccountWithLiabilityIsBlockedEvenWithoutForeignKey(): void
    {
        $accountId = $this->insertInstitutionAccount('FU002');
        $this->insertInstitutionLiability($accountId, 'liability-blocker');

        $decision = $this->accountDeletion->canDelete($this->supplierId, $accountId);
        self::assertNotNull($decision);
        self::assertFalse($decision->canDelete);
        self::assertSame('payroll_institution_account_in_liability', $decision->blockerCode);
        self::assertStringContainsString('konec platnosti', (string) $decision->blockerMessage);

        $listed = $this->listedAccount($accountId);
        self::assertFalse($listed['can_delete']);

        $response = $this->deleteAccount($accountId);
        self::assertSame(409, $response->getStatusCode());
        $error = $this->errorOf($response);
        self::assertSame('payroll_institution_account_in_liability', $error['code']);
        $this->assertActionableMessage((string) $error['message']);
        self::assertSame(1, $this->rowCount('payroll_institution_accounts', 'id', $accountId));
    }

    public function testForeignTenantSeesNeitherCanDeleteNorDeletesInstitutionAccount(): void
    {
        $accountId = $this->insertInstitutionAccount('FU003');

        self::assertNull($this->accountDeletion->canDelete($this->otherSupplierId, $accountId));

        $response = $this->accountsAction->delete(
            $this->request(
                'DELETE',
                "/api/payroll/settings/institution-accounts/{$accountId}",
                [],
                supplierId: $this->otherSupplierId,
                role: 'admin',
            ),
            new Response(),
            ['id' => (string) $accountId],
        );
        self::assertSame(404, $response->getStatusCode());
        self::assertSame(1, $this->rowCount('payroll_institution_accounts', 'id', $accountId));
    }

    public function testInstitutionAccountConcurrentLiabilityFailsOnRecheck(): void
    {
        $accountId = $this->insertInstitutionAccount('FU004');
        $decision = $this->accountDeletion->canDelete($this->supplierId, $accountId);
        self::assertNotNull($decision);
        self::assertTrue($decision->canDelete);

        $this->insertInstitutionLiability($accountId, 'concurrent-liability');

        $response = $this->deleteAccount($accountId);
        self::assertSame(409, $response->getStatusCode());
        $this->assertActionableMessage((string) $this->errorOf($response)['message']);
        self::assertSame(1, $this->rowCount('payroll_institution_accounts', 'id', $accountId));
    }

    public function testInstitutionAccountDeletionIsAuditedAndIdempotent(): void
    {
        $accountId = $this->insertInstitutionAccount('FU005');

        self::assertSame(200, $this->deleteAccount($accountId)->getStatusCode());
        $payload = $this->auditPayloadOf('payroll.institution_account.deleted', $accountId);
        self::assertSame('CZK', $payload['currency_code']);
        // Do auditu se nesmí dostat šifrované číslo účtu, jen maskovaný tvar.
        self::assertArrayNotHasKey('bank_account_ciphertext', $payload);

        $second = $this->deleteAccount($accountId);
        self::assertSame(404, $second->getStatusCode());
        self::assertSame(1, $this->auditCount('payroll.institution_account.deleted', $accountId));
    }

    // ── Zaměstnavatelské pravidlo ────────────────────────────────────────────

    public function testFuturePolicyWithoutRunIsDeletableTogetherWithItsAudit(): void
    {
        $policyId = $this->insertPolicy('2030-01-01', null);
        $this->insertPolicyAudit($policyId);

        $listed = $this->listedPolicy($policyId);
        self::assertTrue($listed['can_delete']);
        self::assertNull($listed['delete_blocker']);
        $detail = $this->detailPolicy($policyId);
        self::assertTrue($detail['can_delete']);

        $response = $this->deletePolicy($policyId);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $cascade = $this->json($response)['cascade'];
        self::assertIsArray($cascade);
        self::assertSame(1, $cascade['audit']);
        self::assertSame(0, $this->rowCount('payroll_employer_policies', 'id', $policyId));
        self::assertSame(0, $this->rowCount('payroll_employer_policy_audit', 'policy_id', $policyId));
    }

    public function testPolicyWithPayrollRunInItsIntervalIsBlocked(): void
    {
        $policyId = $this->insertPolicy('2026-01-01', '2026-12-31');
        $this->insertRun($this->supplierId, '2026-03-01');

        $decision = $this->policyDeletion->canDelete($this->supplierId, $policyId);
        self::assertNotNull($decision);
        self::assertFalse($decision->canDelete);
        self::assertSame('payroll_employer_policy_in_use', $decision->blockerCode);
        self::assertStringContainsString('novou verzi', (string) $decision->blockerMessage);

        $response = $this->deletePolicy($policyId);
        self::assertSame(409, $response->getStatusCode());
        $this->assertActionableMessage((string) $this->errorOf($response)['message']);
        self::assertSame(1, $this->rowCount('payroll_employer_policies', 'id', $policyId));
    }

    public function testDatabaseTriggerRefusesPolicyDeletionEvenOutsideTheApplication(): void
    {
        $policyId = $this->insertPolicy('2026-01-01', '2026-12-31');
        $this->insertRun($this->supplierId, '2026-05-01');

        // Poslední pojistka: obrana nesmí být jen v PHP. Migrace 1388 nechává
        // trigger, který mazání odmítne i při ručním SQL nebo importu.
        $this->expectException(\PDOException::class);
        $this->db->pdo()->prepare(
            'DELETE FROM payroll_employer_policies WHERE supplier_id = ? AND id = ?'
        )->execute([$this->supplierId, $policyId]);
    }

    public function testForeignTenantSeesNeitherCanDeleteNorDeletesPolicy(): void
    {
        $policyId = $this->insertPolicy('2031-01-01', null);

        self::assertNull($this->policyDeletion->canDelete($this->otherSupplierId, $policyId));

        $response = $this->policyAction->delete(
            $this->request(
                'DELETE',
                "/api/payroll/settings/policies/{$policyId}",
                [],
                supplierId: $this->otherSupplierId,
                role: 'admin',
            ),
            new Response(),
            ['id' => (string) $policyId],
        );
        self::assertSame(404, $response->getStatusCode());
        self::assertSame(1, $this->rowCount('payroll_employer_policies', 'id', $policyId));
    }

    public function testPolicyConcurrentRunFailsOnRecheck(): void
    {
        $policyId = $this->insertPolicy('2032-01-01', '2032-12-31');
        $decision = $this->policyDeletion->canDelete($this->supplierId, $policyId);
        self::assertNotNull($decision);
        self::assertTrue($decision->canDelete);

        $this->insertRun($this->supplierId, '2032-04-01');

        $response = $this->deletePolicy($policyId);
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('payroll_employer_policy_in_use', $this->errorOf($response)['code']);
        self::assertSame(1, $this->rowCount('payroll_employer_policies', 'id', $policyId));
    }

    public function testPolicyDeletionIsAuditedAndIdempotent(): void
    {
        $policyId = $this->insertPolicy('2033-01-01', null);

        self::assertSame(200, $this->deletePolicy($policyId)->getStatusCode());
        $payload = $this->auditPayloadOf('payroll.employer_policy.deleted', $policyId);
        self::assertSame('2033-01-01', $payload['valid_from']);

        $second = $this->deletePolicy($policyId);
        self::assertSame(404, $second->getStatusCode());
        self::assertSame(1, $this->auditCount('payroll.employer_policy.deleted', $policyId));
    }

    // ── Mzdová složka ────────────────────────────────────────────────────────

    public function testNeverUsedComponentIsDeletableWithItsJmhzMapping(): void
    {
        $componentId = $this->insertComponent('VLASTNI_ODMENA');
        $this->insertJmhzMapping($componentId);

        $listed = $this->listedComponent($componentId);
        self::assertTrue($listed['can_delete']);
        self::assertNull($listed['delete_blocker']);

        $response = $this->deleteComponent($componentId);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $cascade = $this->json($response)['cascade'];
        self::assertIsArray($cascade);
        self::assertSame(1, $cascade['jmhz_mapping']);
        self::assertSame(0, $this->rowCount('payroll_component_definitions', 'id', $componentId));
        self::assertSame(
            0,
            $this->rowCount('payroll_component_jmhz_mappings', 'component_definition_id', $componentId),
        );
    }

    public function testComponentUsedByRecurringPrescriptionIsBlocked(): void
    {
        $componentId = $this->insertComponent('VLASTNI_PRISPEVEK');
        $this->insertRecurring($componentId, '2026-01-01');

        $decision = $this->componentDeletion->canDelete($this->supplierId, $componentId);
        self::assertNotNull($decision);
        self::assertFalse($decision->canDelete);
        self::assertSame('payroll_component_used_in_recurring', $decision->blockerCode);

        $response = $this->deleteComponent($componentId);
        self::assertSame(409, $response->getStatusCode());
        $this->assertActionableMessage((string) $this->errorOf($response)['message']);
        self::assertSame(1, $this->rowCount('payroll_component_definitions', 'id', $componentId));
    }

    public function testSystemComponentIsBlockedBecauseItWouldComeBack(): void
    {
        $componentId = $this->insertComponent('ODMENA', '2027-01-01');

        $decision = $this->componentDeletion->canDelete($this->supplierId, $componentId);
        self::assertNotNull($decision);
        self::assertFalse($decision->canDelete);
        self::assertSame('payroll_component_is_system_default', $decision->blockerCode);
        self::assertStringContainsString('deaktivujte', (string) $decision->blockerMessage);
    }

    public function testForeignTenantSeesNeitherCanDeleteNorDeletesComponent(): void
    {
        $componentId = $this->insertComponent('VLASTNI_CIZI');

        self::assertNull($this->componentDeletion->canDelete($this->otherSupplierId, $componentId));

        $response = $this->componentsAction->delete(
            $this->request(
                'DELETE',
                "/api/payroll/components/{$componentId}",
                [],
                supplierId: $this->otherSupplierId,
            ),
            new Response(),
            ['id' => (string) $componentId],
        );
        self::assertSame(404, $response->getStatusCode());
        self::assertSame(1, $this->rowCount('payroll_component_definitions', 'id', $componentId));
    }

    public function testComponentConcurrentUsageFailsOnRecheck(): void
    {
        $componentId = $this->insertComponent('VLASTNI_SOUBEH');
        $decision = $this->componentDeletion->canDelete($this->supplierId, $componentId);
        self::assertNotNull($decision);
        self::assertTrue($decision->canDelete);

        $this->insertRecurring($componentId, '2026-02-01');

        $response = $this->deleteComponent($componentId);
        self::assertSame(409, $response->getStatusCode());
        $this->assertActionableMessage((string) $this->errorOf($response)['message']);
        self::assertSame(1, $this->rowCount('payroll_component_definitions', 'id', $componentId));
    }

    public function testComponentDeletionIsAuditedAndIdempotent(): void
    {
        $componentId = $this->insertComponent('VLASTNI_AUDIT');

        self::assertSame(200, $this->deleteComponent($componentId)->getStatusCode());
        $payload = $this->auditPayloadOf('payroll.component.deleted', $componentId);
        self::assertSame('VLASTNI_AUDIT', $payload['code']);

        $second = $this->deleteComponent($componentId);
        self::assertSame(404, $second->getStatusCode());
        self::assertSame(1, $this->auditCount('payroll.component.deleted', $componentId));
    }

    // ── Pravidelná složka ────────────────────────────────────────────────────

    public function testFreshRecurringPrescriptionIsDeletable(): void
    {
        $componentId = $this->insertComponent('VLASTNI_PRAVIDELNA');
        $recurringId = $this->insertRecurring($componentId, '2026-01-01');

        $listed = $this->listedRecurring($recurringId);
        self::assertTrue($listed['can_delete']);
        self::assertNull($listed['delete_blocker']);

        $response = $this->deleteRecurring($recurringId);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(0, $this->rowCount('payroll_recurring_components', 'id', $recurringId));
    }

    public function testMaterializedRecurringPrescriptionIsBlocked(): void
    {
        $componentId = $this->insertComponent('VLASTNI_MATERIALIZOVANA');
        $recurringId = $this->insertRecurring($componentId, '2026-01-01');
        $this->insertInput($componentId, $recurringId, '2026-01-01');

        $decision = $this->recurringDeletion->canDelete($this->supplierId, $recurringId);
        self::assertNotNull($decision);
        self::assertFalse($decision->canDelete);
        self::assertSame('payroll_recurring_materialized', $decision->blockerCode);
        self::assertStringContainsString('deaktivujte', (string) $decision->blockerMessage);

        $response = $this->deleteRecurring($recurringId);
        self::assertSame(409, $response->getStatusCode());
        $this->assertActionableMessage((string) $this->errorOf($response)['message']);
        self::assertSame(1, $this->rowCount('payroll_recurring_components', 'id', $recurringId));
    }

    public function testForeignTenantSeesNeitherCanDeleteNorDeletesRecurring(): void
    {
        $componentId = $this->insertComponent('VLASTNI_CIZI_PRAVIDELNA');
        $recurringId = $this->insertRecurring($componentId, '2026-01-01');

        self::assertNull($this->recurringDeletion->canDelete($this->otherSupplierId, $recurringId));

        $response = $this->recurringAction->delete(
            $this->request(
                'DELETE',
                "/api/payroll/recurring-components/{$recurringId}",
                [],
                supplierId: $this->otherSupplierId,
            ),
            new Response(),
            ['id' => (string) $recurringId],
        );
        self::assertSame(404, $response->getStatusCode());
        self::assertSame(1, $this->rowCount('payroll_recurring_components', 'id', $recurringId));
    }

    public function testRecurringConcurrentMaterializationFailsOnRecheck(): void
    {
        $componentId = $this->insertComponent('VLASTNI_SOUBEH_PRAVIDELNA');
        $recurringId = $this->insertRecurring($componentId, '2026-01-01');
        $decision = $this->recurringDeletion->canDelete($this->supplierId, $recurringId);
        self::assertNotNull($decision);
        self::assertTrue($decision->canDelete);

        $this->insertInput($componentId, $recurringId, '2026-02-01');

        $response = $this->deleteRecurring($recurringId);
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('payroll_recurring_materialized', $this->errorOf($response)['code']);
        self::assertSame(1, $this->rowCount('payroll_recurring_components', 'id', $recurringId));
    }

    public function testRecurringDeletionIsAuditedAndIdempotent(): void
    {
        $componentId = $this->insertComponent('VLASTNI_AUDIT_PRAVIDELNA');
        $recurringId = $this->insertRecurring($componentId, '2026-01-01');

        self::assertSame(200, $this->deleteRecurring($recurringId)->getStatusCode());
        $payload = $this->auditPayloadOf('payroll.recurring_component.deleted', $recurringId);
        self::assertSame($this->employmentId, $payload['employment_id']);

        $second = $this->deleteRecurring($recurringId);
        self::assertSame(404, $second->getStatusCode());
        self::assertSame(1, $this->auditCount('payroll.recurring_component.deleted', $recurringId));
    }

    // ── Pomocné: účet instituce ──────────────────────────────────────────────

    private function insertInstitutionAccount(string $code): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO payroll_institutions (supplier_id, institution_type, institution_code)
             VALUES (?, 'tax_office', ?)"
        )->execute([$this->supplierId, $code]);
        $institutionId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            "INSERT INTO payroll_institution_accounts
                (supplier_id, institution_id, institution_name, bank_account_ciphertext,
                 bank_account_hash, bank_account_masked, currency_code, variable_symbol,
                 valid_from, source_kind, source_reference, verified_on, verified_by)
             VALUES (?, ?, ?, 'enc:v2:test', UNHEX(SHA2(?, 256)), '••••1234', 'CZK',
                     '1234567890', '2026-01-01', 'official_registry', 'test', '2026-01-01', ?)"
        )->execute([$this->supplierId, $institutionId, "Úřad {$code}", $code, $this->userId]);

        return (int) $pdo->lastInsertId();
    }

    private function insertInstitutionLiability(int $accountId, string $seed): void
    {
        $runId = $this->insertRun($this->supplierId, '2026-06-01');
        $revisionId = $this->insertApprovedRevision($this->supplierId, $runId, $seed);
        $this->db->pdo()->prepare(
            "INSERT INTO payroll_payment_liabilities
                (supplier_id, revision_id, employee_id, liability_reference, liability_kind,
                 direction, recipient_reference, due_on, currency_code, amount_minor,
                 source_snapshot_json, source_snapshot_hash, idempotency_key_hash)
             VALUES (?, ?, NULL, ?, 'advance_tax', 'outgoing', ?, '2026-07-20', 'CZK',
                     100000, '{}', ?, UNHEX(SHA2(?, 256)))"
        )->execute([
            $this->supplierId,
            $revisionId,
            'liability-' . $seed,
            "institution:tax_office:FU:account:{$accountId}",
            str_repeat('e', 64),
            $seed,
        ]);
    }

    private function deleteAccount(int $accountId): ResponseInterface
    {
        return $this->accountsAction->delete(
            $this->request(
                'DELETE',
                "/api/payroll/settings/institution-accounts/{$accountId}",
                [],
                role: 'admin',
            ),
            new Response(),
            ['id' => (string) $accountId],
        );
    }

    /** @return array<string,mixed> */
    private function listedAccount(int $accountId): array
    {
        $response = $this->accountsAction->list(
            $this->request('GET', '/api/payroll/settings/institution-accounts', role: 'admin'),
            new Response(),
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $accounts = $this->json($response)['accounts'];
        self::assertIsArray($accounts);
        foreach ($accounts as $account) {
            if (is_array($account) && (int) $account['id'] === $accountId) {
                return $account;
            }
        }
        self::fail('Účet instituce v seznamu chybí.');
    }

    /** @return array<string,mixed> */
    private function detailAccount(int $accountId): array
    {
        $response = $this->accountsAction->detail(
            $this->request(
                'GET',
                "/api/payroll/settings/institution-accounts/{$accountId}",
                role: 'admin',
            ),
            new Response(),
            ['id' => (string) $accountId],
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $account = $this->json($response)['account'];
        self::assertIsArray($account);

        return $account;
    }

    // ── Pomocné: pravidlo zaměstnavatele ─────────────────────────────────────

    private function insertPolicy(string $validFrom, ?string $validTo): int
    {
        $this->db->pdo()->prepare(
            "INSERT INTO payroll_employer_policies
                (supplier_id, valid_from, valid_to, payday_day, payday_month_offset,
                 payday_business_day_rule, balance_rounding_mode, home_office_policy,
                 travel_expense_policy, delivery_channel,
                 delivery_verified_on, source_kind)
             VALUES (?, ?, ?, 15, 1, 'previous_business_day', 'nearest_crown', 'not_used',
                     'not_used', 'disabled', NULL, 'manual')"
        )->execute([$this->supplierId, $validFrom, $validTo]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    private function insertPolicyAudit(int $policyId): void
    {
        $this->db->pdo()->prepare(
            "INSERT INTO payroll_employer_policy_audit
                (supplier_id, policy_id, action, snapshot_json, snapshot_hash, actor_user_id)
             VALUES (?, ?, 'created', '{}', ?, ?)"
        )->execute([$this->supplierId, $policyId, str_repeat('f', 64), $this->userId]);
    }

    private function deletePolicy(int $policyId): ResponseInterface
    {
        return $this->policyAction->delete(
            $this->request(
                'DELETE',
                "/api/payroll/settings/policies/{$policyId}",
                [],
                role: 'admin',
            ),
            new Response(),
            ['id' => (string) $policyId],
        );
    }

    /** @return array<string,mixed> */
    private function listedPolicy(int $policyId): array
    {
        $response = $this->policyAction->list(
            $this->request('GET', '/api/payroll/settings/policies', role: 'admin'),
            new Response(),
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $policies = $this->json($response)['policies'];
        self::assertIsArray($policies);
        foreach ($policies as $policy) {
            if (is_array($policy) && (int) $policy['id'] === $policyId) {
                return $policy;
            }
        }
        self::fail('Politika v seznamu chybí.');
    }

    /** @return array<string,mixed> */
    private function detailPolicy(int $policyId): array
    {
        $response = $this->policyAction->detail(
            $this->request('GET', "/api/payroll/settings/policies/{$policyId}", role: 'admin'),
            new Response(),
            ['id' => (string) $policyId],
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $policy = $this->json($response)['policy'];
        self::assertIsArray($policy);

        return $policy;
    }

    // ── Pomocné: mzdová a pravidelná složka ──────────────────────────────────

    private function insertComponent(string $code, string $validFrom = '2026-01-01'): int
    {
        $this->db->pdo()->prepare(
            "INSERT INTO payroll_component_definitions
                (supplier_id, code, name, component_kind, value_kind, frequency_kind,
                 tax_treatment, social_participation_treatment, social_treatment,
                 health_participation_treatment, health_treatment,
                 average_earning_treatment, enforcement_treatment, jmhz_treatment,
                 statistics_treatment, valid_from, is_active)
             VALUES (?, ?, ?, 'bonus', 'monetary', 'regular', 'included', 'included',
                     'included', 'included', 'included', 'included', 'included',
                     'included', 'included', ?, 1)"
        )->execute([$this->supplierId, $code, "Složka {$code}", $validFrom]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    private function insertJmhzMapping(int $componentId): void
    {
        $this->installDefaultJmhzSpecPackage($this->db);
        $attribute = $this->fetchRow(
            "SELECT package_id, attribute_id
               FROM payroll_jmhz_dictionary_attributes
              WHERE attribute_id = '10328'
              ORDER BY package_id LIMIT 1"
        );
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_component_jmhz_mappings
                (supplier_id, component_definition_id, spec_package_id,
                 target_attribute_id, is_active)
             VALUES (?, ?, ?, ?, 1)'
        )->execute([
            $this->supplierId,
            $componentId,
            $attribute['package_id'],
            $attribute['attribute_id'],
        ]);
    }

    private function insertRecurring(int $componentId, string $validFrom): int
    {
        $this->db->pdo()->prepare(
            "INSERT INTO payroll_recurring_components
                (supplier_id, employment_id, component_id, calculation_kind, amount_minor,
                 valid_from, allocation_rule, is_active)
             VALUES (?, ?, ?, 'fixed_amount', 100000, ?, 'full_month', 1)"
        )->execute([$this->supplierId, $this->employmentId, $componentId, $validFrom]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    private function insertInput(int $componentId, ?int $recurringId, string $periodStart): int
    {
        $this->db->pdo()->prepare(
            "INSERT INTO payroll_inputs
                (supplier_id, employee_id, employment_id, component_id, period_start,
                 amount_minor, source_kind, external_id, recurring_component_id,
                 source_snapshot_json, source_snapshot_hash, status)
             VALUES (?, ?, ?, ?, ?, 100000, 'recurring', ?, ?, '{}',
                     UNHEX(SHA2(?, 256)), 'draft')"
        )->execute([
            $this->supplierId,
            $this->employeeId,
            $this->employmentId,
            $componentId,
            $periodStart,
            "recurring:{$recurringId}",
            $recurringId,
            "input-{$recurringId}-{$periodStart}",
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    private function deleteComponent(int $componentId): ResponseInterface
    {
        return $this->componentsAction->delete(
            $this->request('DELETE', "/api/payroll/components/{$componentId}", []),
            new Response(),
            ['id' => (string) $componentId],
        );
    }

    /** @return array<string,mixed> */
    private function listedComponent(int $componentId): array
    {
        $response = $this->componentsAction->list(
            $this->request('GET', '/api/payroll/components'),
            new Response(),
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $components = $this->json($response)['components'];
        self::assertIsArray($components);
        foreach ($components as $component) {
            if (is_array($component) && (int) $component['id'] === $componentId) {
                return $component;
            }
        }
        self::fail('Mzdová složka v seznamu chybí.');
    }

    private function deleteRecurring(int $recurringId): ResponseInterface
    {
        return $this->recurringAction->delete(
            $this->request('DELETE', "/api/payroll/recurring-components/{$recurringId}", []),
            new Response(),
            ['id' => (string) $recurringId],
        );
    }

    /** @return array<string,mixed> */
    private function listedRecurring(int $recurringId): array
    {
        $response = $this->recurringAction->list(
            $this->request('GET', '/api/payroll/recurring-components'),
            new Response(),
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $items = $this->json($response)['recurring_components'];
        self::assertIsArray($items);
        foreach ($items as $item) {
            if (is_array($item) && (int) $item['id'] === $recurringId) {
                return $item;
            }
        }
        self::fail('Předpis v seznamu chybí.');
    }
}
