<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollComponentJmhzMappingsAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\Payroll\PayrollComponentJmhzMappingConflictException;
use MyInvoice\Repository\Payroll\PayrollComponentJmhzMappingRepository;
use MyInvoice\Repository\Payroll\PayrollComponentRepository;
use MyInvoice\Repository\Payroll\PayrollTimeValue;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class PayrollComponentJmhzMappingRepositoryTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollComponentRepository $components;
    private PayrollComponentJmhzMappingRepository $mappings;
    private PayrollComponentJmhzMappingsAction $action;
    private int $supplierId;
    private int $otherSupplierId;
    private int $componentId;
    private int $userId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $db = $container->get(Connection::class);
        $components = $container->get(PayrollComponentRepository::class);
        $mappings = $container->get(PayrollComponentJmhzMappingRepository::class);
        $action = $container->get(PayrollComponentJmhzMappingsAction::class);
        if (!$db instanceof Connection || !$components instanceof PayrollComponentRepository
            || !$mappings instanceof PayrollComponentJmhzMappingRepository
            || !$action instanceof PayrollComponentJmhzMappingsAction
        ) {
            throw new \RuntimeException('Služby mapování mzdových složek nejsou dostupné.');
        }
        foreach (['payroll_component_jmhz_mappings', 'payroll_jmhz_spec_packages'] as $table) {
            if (!$db->hasTable($table)) {
                self::fail("Povinná migrace pro {$table} neproběhla.");
            }
        }
        $this->db = $db;
        $this->components = $components;
        $this->mappings = $mappings;
        $this->action = $action;
        $pdo = $db->pdo();
        $supplierQuery = $pdo->query('SELECT MIN(id) FROM supplier');
        $userQuery = $pdo->query('SELECT MIN(id) FROM users');
        if ($supplierQuery === false || $userQuery === false) {
            throw new \RuntimeException('Nelze načíst výchozí testovací data.');
        }
        $sourceSupplierId = (int) $supplierQuery->fetchColumn();
        $this->userId = (int) $userQuery->fetchColumn();
        if ($sourceSupplierId < 1 || $this->userId < 1) {
            $this->markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->otherSupplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id IN (?, ?)')
            ->execute([$this->supplierId, $this->otherSupplierId]);
        $rows = $components->list($this->supplierId, '2026-06-01');
        $byCode = array_column($rows, null, 'code');
        $this->componentId = PayrollTimeValue::int($byCode['ODMENA']['id'] ?? null, 'component_id');
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

    public function testMappingLifecycleIsPinnedIdempotentTenantSafeAndSnapshotted(): void
    {
        $mapping = $this->mappings->put(
            $this->supplierId,
            $this->componentId,
            '10330',
            null,
            $this->userId,
        );
        self::assertSame(1, $mapping['row_version']);
        self::assertTrue($mapping['is_active']);
        self::assertSame(['10328'], $mapping['ancestor_attribute_ids']);
        self::assertSame('detail', $mapping['aggregation_role']);
        self::assertSame('employment', $mapping['aggregation_scope']);
        self::assertNull($this->mappings->find($this->otherSupplierId, $this->componentId));

        $retry = $this->mappings->put(
            $this->supplierId,
            $this->componentId,
            '10330',
            null,
            $this->userId,
        );
        self::assertSame(1, $retry['row_version']);

        try {
            $this->mappings->put(
                $this->supplierId,
                $this->componentId,
                '10331',
                null,
                $this->userId,
            );
            self::fail('Změna cíle musí vyžadovat aktuální verzi.');
        } catch (PayrollComponentJmhzMappingConflictException $e) {
            self::assertSame(1, $e->currentVersion);
        }

        $changed = $this->mappings->put(
            $this->supplierId,
            $this->componentId,
            '10331',
            1,
            $this->userId,
        );
        self::assertSame(2, $changed['row_version']);
        $snapshot = $this->mappings->snapshot($this->supplierId, $this->componentId);
        self::assertSame($this->supplierId, $snapshot['supplier_id']);
        self::assertSame($this->componentId, $snapshot['component_definition_id']);
        self::assertSame(['10328'], $snapshot['ancestor_attribute_ids']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $snapshot['topology_hash']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $snapshot['mapping_hash']);

        $disabled = $this->mappings->remove(
            $this->supplierId,
            $this->componentId,
            2,
            $this->userId,
        );
        self::assertIsArray($disabled);
        self::assertFalse($disabled['is_active']);
        self::assertSame(3, $disabled['row_version']);
        $this->expectException(\DomainException::class);
        $this->mappings->snapshot($this->supplierId, $this->componentId);
    }

    public function testInvalidTargetsTreatmentsAndMappedTreatmentChangeFailClosed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        try {
            $this->mappings->put(
                $this->supplierId,
                $this->componentId,
                '10344',
                null,
                $this->userId,
            );
        } finally {
            $this->mappings->put(
                $this->supplierId,
                $this->componentId,
                '10330',
                null,
                $this->userId,
            );
            $component = $this->components->find($this->supplierId, $this->componentId);
            self::assertIsArray($component);
            $component['jmhz_treatment'] = 'excluded';
            try {
                $this->components->update(
                    $this->supplierId,
                    $this->componentId,
                    $component,
                    PayrollTimeValue::int($component['row_version'] ?? null, 'row_version'),
                );
                self::fail('Složka s aktivním mapováním nesmí opustit included režim.');
            } catch (\DomainException) {
                self::addToAssertionCount(1);
            }

            $excluded = $this->componentByCode('CESTOVNI_NAHRADA_LIMIT');
            try {
                $this->mappings->put(
                    $this->supplierId,
                    PayrollTimeValue::int($excluded['id'] ?? null, 'excluded_component_id'),
                    '10328',
                    null,
                    $this->userId,
                );
                self::fail('Vyloučená složka se nesmí mapovat.');
            } catch (\DomainException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testSessionApiExposesTargetsAndOptimisticMappingLifecycle(): void
    {
        $targetsResponse = $this->action->targets(
            $this->request('GET', '/api/payroll/components/jmhz-targets'),
            new Response(),
        );
        self::assertSame(200, $targetsResponse->getStatusCode());
        $targets = $this->json($targetsResponse);
        self::assertCount(17, PayrollTimeValue::rows($targets['targets'] ?? null, 'targets'));
        self::assertSame(64, strlen(PayrollTimeValue::string(
            $targets['topology_hash'] ?? null,
            'topology_hash',
        )));
        $listResponse = $this->action->list(
            $this->request('GET', '/api/payroll/components/jmhz-mappings'),
            new Response(),
        );
        self::assertSame(200, $listResponse->getStatusCode());
        $states = PayrollTimeValue::rows($this->json($listResponse)['items'] ?? null, 'items');
        self::assertGreaterThanOrEqual(20, count($states));
        self::assertContains('missing', array_column($states, 'status'));

        // ODMENA má výchozí zařazení, takže ji `list()` předvyplní; lifecycle se
        // proto zkouší na složce, která default záměrně nemá.
        $componentId = PayrollTimeValue::int(
            $this->componentByCode('PROVIZE')['id'] ?? null,
            'component_id',
        );
        $missingResponse = $this->action->get(
            $this->request('GET', "/api/payroll/components/{$componentId}/jmhz-mapping"),
            new Response(),
            ['id' => (string) $componentId],
        );
        self::assertSame('missing', $this->json($missingResponse)['status']);

        $saveResponse = $this->action->put(
            $this->request('PUT', "/api/payroll/components/{$componentId}/jmhz-mapping")
                ->withParsedBody(['target_attribute_id' => '10330']),
            new Response(),
            ['id' => (string) $componentId],
        );
        self::assertSame(200, $saveResponse->getStatusCode(), (string) $saveResponse->getBody());
        $saved = $this->json($saveResponse);
        self::assertSame('configured', $saved['status']);
        $mapping = PayrollTimeValue::row($saved['mapping'] ?? null, 'mapping');
        self::assertSame(1, $mapping['row_version']);

        $conflictResponse = $this->action->put(
            $this->request('PUT', "/api/payroll/components/{$componentId}/jmhz-mapping")
                ->withParsedBody(['target_attribute_id' => '10331']),
            new Response(),
            ['id' => (string) $componentId],
        );
        self::assertSame(409, $conflictResponse->getStatusCode());

        $removeResponse = $this->action->remove(
            $this->request('DELETE', "/api/payroll/components/{$componentId}/jmhz-mapping")
                ->withParsedBody(['row_version' => 1]),
            new Response(),
            ['id' => (string) $componentId],
        );
        self::assertSame(204, $removeResponse->getStatusCode());

        $after = $this->action->get(
            $this->request('GET', "/api/payroll/components/{$componentId}/jmhz-mapping"),
            new Response(),
            ['id' => (string) $componentId],
        );
        $afterBody = $this->json($after);
        self::assertSame('missing', $afterBody['status']);
        self::assertFalse(PayrollTimeValue::row(
            $afterBody['mapping'] ?? null,
            'mapping',
        )['is_active']);

        $bearer = $this->action->targets(
            $this->request('GET', '/api/payroll/components/jmhz-targets', 'bearer'),
            new Response(),
        );
        self::assertSame(403, $bearer->getStatusCode());
    }

    public function testDatabaseRejectsCrossTenantMappingAndDeletingMappedComponent(): void
    {
        $mapping = $this->mappings->put(
            $this->supplierId,
            $this->componentId,
            '10330',
            null,
            $this->userId,
        );
        try {
            $this->db->pdo()->prepare(
                'INSERT INTO payroll_component_jmhz_mappings
                    (supplier_id, component_definition_id, spec_package_id,
                     target_attribute_id, created_by, updated_by)
                 VALUES (?, ?, ?, ?, ?, ?)',
            )->execute([
                $this->otherSupplierId,
                $this->componentId,
                $mapping['spec_package_id'],
                '10330',
                $this->userId,
                $this->userId,
            ]);
            self::fail('Databáze nesmí přijmout vazbu na komponentu jiného tenantu.');
        } catch (\PDOException $e) {
            self::assertContains((string) $e->getCode(), ['23000', '45000']);
        }

        try {
            $this->db->pdo()->prepare(
                "UPDATE payroll_component_definitions
                    SET jmhz_treatment = 'excluded'
                  WHERE supplier_id = ? AND id = ?",
            )->execute([$this->supplierId, $this->componentId]);
            self::fail('Databáze nesmí ponechat aktivní mapování na vyloučené komponentě.');
        } catch (\PDOException $e) {
            self::assertSame('45000', (string) $e->getCode());
        }

        try {
            $this->db->pdo()->prepare(
                'DELETE FROM payroll_component_definitions WHERE supplier_id = ? AND id = ?',
            )->execute([$this->supplierId, $this->componentId]);
            self::fail('Komponentu s historií mapování JMHZ nelze smazat kaskádou.');
        } catch (\PDOException $e) {
            self::assertSame('23000', (string) $e->getCode());
        }
    }

    /** @return array<string,mixed> */
    private function componentByCode(string $code): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_component_definitions WHERE supplier_id = ? AND code = ?',
        );
        $stmt->execute([$this->supplierId, $code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return PayrollTimeValue::row($row, 'component');
    }

    private function request(string $method, string $uri, string $authMethod = 'session'): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest($method, $uri)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, $authMethod);
    }

    /** @return array<string,mixed> */
    private function json(ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);

        return PayrollTimeValue::row($decoded, 'response');
    }
}
