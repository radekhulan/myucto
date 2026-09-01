<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollComponentJmhzMappingsAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\Payroll\PayrollComponentJmhzMappingRepository;
use MyInvoice\Repository\Payroll\PayrollComponentRepository;
use MyInvoice\Repository\Payroll\PayrollTimeValue;
use MyInvoice\Service\Payroll\Component\PayrollComponentJmhzMappingDefaults;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class PayrollComponentJmhzMappingDefaultsTest extends TestCase
{
    use IsolatedSupplierTrait;

    /**
     * Složky výchozího číselníku, které do JMHZ patří, ale výchozí zařazení
     * ZÁMĚRNĚ nemají — u nich je cílový atribut úsudek účetní.
     */
    private const WITHOUT_DEFAULT = [
        'PROVIZE',
        'ODSTUPNE',
        'DOPLATEK_MZDY',
        'NEPENEZNI_PRIJEM',
        'SOUKROME_VOZIDLO',
        'CESTOVNI_NAHRADA_NADLIMIT',
    ];

    private Connection $db;
    private PayrollComponentRepository $components;
    private PayrollComponentJmhzMappingRepository $mappings;
    private PayrollComponentJmhzMappingDefaults $defaults;
    private PayrollComponentJmhzMappingsAction $action;
    private int $supplierId;
    private int $userId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $db = $container->get(Connection::class);
        $components = $container->get(PayrollComponentRepository::class);
        $mappings = $container->get(PayrollComponentJmhzMappingRepository::class);
        $defaults = $container->get(PayrollComponentJmhzMappingDefaults::class);
        $action = $container->get(PayrollComponentJmhzMappingsAction::class);
        if (!$db instanceof Connection || !$components instanceof PayrollComponentRepository
            || !$mappings instanceof PayrollComponentJmhzMappingRepository
            || !$defaults instanceof PayrollComponentJmhzMappingDefaults
            || !$action instanceof PayrollComponentJmhzMappingsAction
        ) {
            throw new \RuntimeException('Služby výchozího zařazení složek nejsou dostupné.');
        }
        $this->db = $db;
        $this->components = $components;
        $this->mappings = $mappings;
        $this->defaults = $defaults;
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
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')
            ->execute([$this->supplierId]);
        $this->components->ensureDefaults($this->supplierId);
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

    public function testFreshSupplierGetsExpectedDefaultsAndJudgementCallsStayEmpty(): void
    {
        $applied = $this->defaults->apply($this->supplierId);
        self::assertNotSame([], $applied);

        $expected = [
            'MZDA_MESICNI' => '10329',
            'MZDA_HODINOVA' => '10329',
            'MZDA_UKOLOVA' => '10329',
            'ODMENA' => '10331',
            'PREMIE_PRIPLATKY' => '10332',
            'PRIPLATEK_PRESCAS' => '10333',
            'PRIPLATEK_NOCNI' => '10334',
            'PRIPLATEK_VIKEND' => '10335',
            'PRIPLATEK_SVATEK' => '10336',
            'PRIPLATEK_ZTIZENE_PROSTREDI' => '10332',
            'NAHRADA_MZDY' => '10337',
            'NAHRADA_MZDY_DPN' => '10342',
        ];
        self::assertSame($expected, PayrollComponentJmhzMappingDefaults::all());
        foreach ($expected as $code => $target) {
            $mapping = $this->mappingByCode($code);
            self::assertIsArray($mapping, "Složka {$code} nedostala výchozí zařazení.");
            self::assertSame($target, $mapping['target_attribute_id'], "Špatný cíl u {$code}.");
            self::assertTrue($mapping['is_active']);
            self::assertSame(1, $mapping['row_version']);
        }
        foreach (self::WITHOUT_DEFAULT as $code) {
            self::assertNull(
                $this->mappingByCode($code),
                "Složka {$code} nemá mít výchozí zařazení — rozhoduje účetní.",
            );
        }

        // Detail naplní i sběrný součet nad sebou, takže mapovat na catch-all
        // by bylo hrubší než potřeba.
        $wage = $this->mappingByCode('MZDA_MESICNI');
        self::assertIsArray($wage);
        self::assertSame(['10328'], $wage['ancestor_attribute_ids']);
    }

    public function testManualChoiceIsNeverOverwrittenNorRestored(): void
    {
        $componentId = $this->componentIdByCode('MZDA_MESICNI');
        $manual = $this->mappings->put($this->supplierId, $componentId, '10330', null, $this->userId);
        self::assertSame('10330', $manual['target_attribute_id']);

        // Vědomě zrušené mapování je taky volba účetní a nesmí se obnovit.
        $removedComponentId = $this->componentIdByCode('ODMENA');
        $this->mappings->put($this->supplierId, $removedComponentId, '10331', null, $this->userId);
        $this->mappings->remove($this->supplierId, $removedComponentId, 1, $this->userId);

        $this->defaults->apply($this->supplierId);

        $kept = $this->mappingByCode('MZDA_MESICNI');
        self::assertIsArray($kept);
        self::assertSame('10330', $kept['target_attribute_id']);
        self::assertSame(1, $kept['row_version']);

        $disabled = $this->mappingByCode('ODMENA');
        self::assertIsArray($disabled);
        self::assertFalse($disabled['is_active']);
    }

    public function testRepeatedApplicationIsIdempotent(): void
    {
        $first = $this->defaults->apply($this->supplierId);
        self::assertNotSame([], $first);
        $before = $this->snapshot();

        self::assertSame([], $this->defaults->apply($this->supplierId));
        self::assertSame([], $this->defaults->apply($this->supplierId));
        self::assertSame($before, $this->snapshot());
    }

    public function testComponentOutsideJmhzIsSkippedWithoutFailingTheRest(): void
    {
        $componentId = $this->componentIdByCode('PRIPLATEK_VIKEND');
        $this->db->pdo()->prepare(
            "UPDATE payroll_component_definitions
                SET jmhz_treatment = 'excluded'
              WHERE supplier_id = ? AND id = ?",
        )->execute([$this->supplierId, $componentId]);

        $this->defaults->apply($this->supplierId);

        self::assertNull($this->mappingByCode('PRIPLATEK_VIKEND'));
        $rest = $this->mappingByCode('PRIPLATEK_NOCNI');
        self::assertIsArray($rest);
        self::assertSame('10334', $rest['target_attribute_id']);
    }

    public function testMappingScreenPrefillsDefaultsOnRead(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/payroll/components/jmhz-mappings')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session');
        $response = $this->action->list($request, new Response());
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $response->getBody()->rewind();
        $items = PayrollTimeValue::rows(
            PayrollTimeValue::row(
                json_decode((string) $response->getBody(), true),
                'response',
            )['items'] ?? null,
            'items',
        );
        $byComponent = array_column($items, null, 'component_id');

        $wage = PayrollTimeValue::row(
            $byComponent[$this->componentIdByCode('MZDA_MESICNI')] ?? null,
            'item',
        );
        self::assertSame('configured', $wage['status']);
        self::assertSame(
            '10329',
            PayrollTimeValue::row($wage['mapping'] ?? null, 'mapping')['target_attribute_id'],
        );

        $commission = PayrollTimeValue::row(
            $byComponent[$this->componentIdByCode('PROVIZE')] ?? null,
            'item',
        );
        self::assertSame('missing', $commission['status']);
        self::assertNull($commission['mapping']);
    }

    private function componentIdByCode(string $code): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM payroll_component_definitions WHERE supplier_id = ? AND code = ?',
        );
        $stmt->execute([$this->supplierId, $code]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            self::fail("Výchozí číselník neobsahuje složku {$code}.");
        }

        return (int) $id;
    }

    /** @return array<string,mixed>|null */
    private function mappingByCode(string $code): ?array
    {
        return $this->mappings->find($this->supplierId, $this->componentIdByCode($code));
    }

    /** @return list<array{int,string,int,bool}> */
    private function snapshot(): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT component_definition_id, target_attribute_id, row_version, is_active
               FROM payroll_component_jmhz_mappings
              WHERE supplier_id = ?
              ORDER BY component_definition_id',
        );
        $stmt->execute([$this->supplierId]);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_NUM) as $row) {
            $rows[] = [(int) $row[0], (string) $row[1], (int) $row[2], (bool) $row[3]];
        }

        return $rows;
    }
}
