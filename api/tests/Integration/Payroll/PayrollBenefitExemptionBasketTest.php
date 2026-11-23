<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollComponentsAction;
use MyInvoice\Action\Payroll\PayrollInputsAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\Payroll\PayrollTimeValue;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * MZ-08 — roční limit nepeněžních benefitů jako SPOLEČNÝ KOŠ.
 *
 * § 6 odst. 9 písm. d) ZDP osvobozuje plnění „v úhrnu do výše" limitu, tedy za
 * celý bod, ne za jednu mzdovou složku. Dosavadní strop na složce
 * (`annual_limit_minor`) tuhle větu vyjádřit neuměl a dvě složky téhož bodu ho
 * obešly. Zároveň blokoval schválení, ačkoli zákon plnění nad limit nezakazuje —
 * jen z přebytku ukládá odvést daň i pojistné.
 *
 * Částky pro rok 2026 (ověřeno proti doslovnému znění účinnému 1. 1. 2026
 * i 1. 8. 2026; obě znějí stejně):
 *   bod 1 — zdravotní plnění, celá průměrná mzda        48 967,00 Kč
 *   bod 2 — rekreace, sport, kultura, vzdělávání, tisk  24 483,50 Kč
 */
#[Group('integration')]
final class PayrollBenefitExemptionBasketTest extends TestCase
{
    /** § 6 odst. 9 písm. d) bod 2 ZDP — polovina průměrné mzdy 48 967 Kč. */
    private const LEISURE_LIMIT_MINOR = 2_448_350;

    /** § 6 odst. 9 písm. d) bod 1 ZDP — celá průměrná mzda. */
    private const HEALTH_LIMIT_MINOR = 4_896_700;

    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollComponentsAction $components;
    private PayrollInputsAction $inputs;
    private int $supplierId;
    private int $employeeId;
    private int $employmentId;
    private int $secondEmploymentId;
    private int $userId;

    protected function setUp(): void
    {
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        if ($container === null) {
            throw new \RuntimeException('DI kontejner není dostupný.');
        }
        $db = $container->get(Connection::class);
        $components = $container->get(PayrollComponentsAction::class);
        $inputs = $container->get(PayrollInputsAction::class);
        if (!$db instanceof Connection
            || !$components instanceof PayrollComponentsAction
            || !$inputs instanceof PayrollInputsAction
        ) {
            throw new \RuntimeException('Payroll služby nejsou dostupné.');
        }
        $this->db = $db;
        if (!$this->db->hasColumn('payroll_component_definitions', 'exemption_basket')) {
            $this->markTestSkipped('Migrace 1480 neproběhla.');
        }
        $this->components = $components;
        $this->inputs = $inputs;

        $pdo = $this->db->pdo();
        $sourceSupplierId = $this->firstId($pdo, 'supplier');
        $this->userId = $this->firstId($pdo, 'users');
        if ($sourceSupplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }

        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')
            ->execute([$this->supplierId]);

        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, "Syntetická zaměstnankyně", "employee", "hpp", 1, 1, 0, 42000, 0, 1)'
        )->execute([$this->supplierId]);
        $this->employeeId = (int) $pdo->lastInsertId();
        $this->employmentId = $this->createEmployment('SYN-KOS-1');
        $this->secondEmploymentId = $this->createEmployment('SYN-KOS-2');
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
     * Jádro nálezu: dvě RŮZNÉ složky téhož bodu se sčítají. Každá sama je pod
     * limitem, v úhrnu ho překročí — a přebytek se zdaní.
     */
    public function testTwoDifferentComponentsShareOneBasket(): void
    {
        $first = $this->createBasketComponent('KOS_REKREACE_A', 'non_cash_leisure');
        $second = $this->createBasketComponent('KOS_REKREACE_B', 'non_cash_leisure');

        self::assertSame(200, $this->approve($first, 2_000_000, $this->employmentId, 'a')->getStatusCode());

        // Sama o sobě je druhá složka hluboko pod limitem…
        $alone = $this->basket($this->preview($second, 1_000_000, $this->employmentId));
        self::assertSame(2_000_000, $alone['used_before_minor']);
        self::assertSame(448_350, $alone['exempt_minor']);
        self::assertSame(551_650, $alone['taxable_minor']);
        self::assertTrue($alone['limit_exceeded']);

        // …a přesto se schválí. Zákon plnění nad limit nezakazuje, jen ho zdaňuje.
        self::assertSame(
            200,
            $this->approve($second, 1_000_000, $this->employmentId, 'b')->getStatusCode(),
        );
        self::assertSame(
            ['non_cash_leisure', 448_350, 551_650],
            $this->frozenSplit('b'),
        );
    }

    /**
     * Koš je za OSOBU u zaměstnavatele, ne za pracovní vztah — zákon mluví
     * o plnění poskytovaném zaměstnavatelem zaměstnanci. Souběžné vztahy téže
     * osoby proto sdílí jeden koš.
     */
    public function testConcurrentRelationshipsOfTheSamePersonShareTheBasket(): void
    {
        $component = $this->createBasketComponent('KOS_REKREACE_C', 'non_cash_leisure');

        $this->approve($component, 2_000_000, $this->employmentId, 'c1');
        $usage = $this->basket(
            $this->preview($component, 1_000_000, $this->secondEmploymentId),
        );

        self::assertSame(2_000_000, $usage['used_before_minor']);
        self::assertSame(551_650, $usage['taxable_minor']);
    }

    /** Zdravotní a volnočasový koš jsou samostatné; vyčerpání jednoho druhý nesníží. */
    public function testHealthAndLeisureBasketsAreIndependent(): void
    {
        $leisure = $this->createBasketComponent('KOS_REKREACE_D', 'non_cash_leisure');
        $health = $this->createBasketComponent('KOS_ZDRAVI_D', 'non_cash_health');

        $this->approve($leisure, self::LEISURE_LIMIT_MINOR, $this->employmentId, 'd1');
        $usage = $this->basket($this->preview($health, self::HEALTH_LIMIT_MINOR, $this->employmentId));

        self::assertSame('non_cash_health', $usage['basket']);
        self::assertSame(self::HEALTH_LIMIT_MINOR, $usage['limit_minor']);
        self::assertSame(0, $usage['used_before_minor']);
        self::assertSame(0, $usage['taxable_minor']);
    }

    /**
     * Hranice: přesně na limitu se ještě nezdaňuje, o korunu nad ním ano.
     * Nerovnost je NEOSTRÁ — zákon říká „osvobozena v úhrnu DO VÝŠE".
     */
    public function testExactlyOnTheLimitIsStillExemptAndOneCrownOverIsNot(): void
    {
        $component = $this->createBasketComponent('KOS_REKREACE_E', 'non_cash_leisure');

        self::assertSame(
            200,
            $this->approve($component, self::LEISURE_LIMIT_MINOR, $this->employmentId, 'e1')
                ->getStatusCode(),
        );
        self::assertSame(
            ['non_cash_leisure', self::LEISURE_LIMIT_MINOR, 0],
            $this->frozenSplit('e1'),
        );

        self::assertSame(
            200,
            $this->approve($component, 100, $this->employmentId, 'e2')->getStatusCode(),
        );
        self::assertSame(['non_cash_leisure', 0, 100], $this->frozenSplit('e2'));
    }

    /** Vstup mimo koš rozpad nedostane — starší revize se nesmí přepočítat jinak. */
    public function testInputOutsideAnyBasketKeepsNoSplit(): void
    {
        $component = $this->createBasketComponent('KOS_MIMO', null);

        self::assertSame(
            200,
            $this->approve($component, 1_000_000, $this->employmentId, 'f1')->getStatusCode(),
        );
        self::assertSame([null, null, null], $this->frozenSplit('f1'));
        self::assertNull($this->preview($component, 1_000_000, $this->employmentId)['exemption_basket']);
    }

    private function createEmployment(string $code): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 start_date, actual_start_date, monthly_gross_minor, is_legacy_projection)
             VALUES (?, ?, ?, "employment", "active",
                     "2026-01-01", "2026-01-01", 4200000, 0)'
        )->execute([$this->supplierId, $this->employeeId, $code]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * Složka klasifikovaná JEDNOZNAČNĚ — výchozí benefitní složky mají všude
     * „vyžaduje ruční posouzení" a schválení by spadlo dřív, než se ke koši
     * vůbec dostane.
     */
    private function createBasketComponent(string $code, ?string $basket): int
    {
        $response = $this->components->create(
            $this->request('POST', '/api/payroll/components')->withParsedBody([
                'code' => $code,
                'name' => 'Syntetický benefit ' . $code,
                'component_kind' => 'benefit_recreation',
                'value_kind' => 'non_monetary',
                'frequency_kind' => 'one_off',
                'tax_treatment' => 'exempt',
                'social_participation_treatment' => 'excluded',
                'social_treatment' => 'excluded',
                'health_participation_treatment' => 'excluded',
                'health_treatment' => 'excluded',
                'average_earning_treatment' => 'excluded',
                'enforcement_treatment' => 'excluded',
                'jmhz_treatment' => 'excluded',
                'statistics_treatment' => 'included',
                'accounting_debit_code' => null,
                'accounting_credit_code' => null,
                'annual_limit_minor' => null,
                'exemption_basket' => $basket,
                // Osvobození bez podkladu se neuloží — u složky v koši je jím
                // zmrazený rozpad, mimo koš zákonné osvobození bez limitu.
                'exemption_basis' => $basket === null
                    ? 'statutory_exempt'
                    : 'benefit_basket',
                'valid_from' => '2026-01-01',
                'valid_to' => null,
                'is_active' => true,
            ]),
            new Response(),
        );
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $component = PayrollTimeValue::row($this->json($response)['component'] ?? null, 'component');
        self::assertSame($basket, $component['exemption_basket']);

        return PayrollTimeValue::int($component['id'] ?? null, 'component_id');
    }

    /**
     * @param array<string,mixed> $preview
     * @return array<string,mixed>
     */
    private function basket(array $preview): array
    {
        return PayrollTimeValue::row($preview['exemption_basket'] ?? null, 'exemption_basket');
    }

    /** @return array{0:?string,1:?int,2:?int} */
    private function frozenSplit(string $externalId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT benefit_basket, benefit_exempt_minor, benefit_taxable_minor
               FROM payroll_inputs
              WHERE supplier_id = ? AND external_id = ?'
        );
        $stmt->execute([$this->supplierId, $externalId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row, "Vstup {$externalId} v databázi není.");

        return [
            $row['benefit_basket'] === null ? null : (string) $row['benefit_basket'],
            $row['benefit_exempt_minor'] === null ? null : (int) $row['benefit_exempt_minor'],
            $row['benefit_taxable_minor'] === null ? null : (int) $row['benefit_taxable_minor'],
        ];
    }

    /** @return array<string,mixed> */
    private function preview(int $componentId, int $amountMinor, int $employmentId): array
    {
        $response = $this->inputs->preview(
            $this->request('POST', '/api/payroll/inputs/preview')->withParsedBody(
                $this->inputPayload($componentId, $amountMinor, $employmentId, 'preview'),
            ),
            new Response(),
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return PayrollTimeValue::row($this->json($response)['preview'] ?? null, 'preview');
    }

    private function approve(
        int $componentId,
        int $amountMinor,
        int $employmentId,
        string $externalId,
    ): ResponseInterface {
        $created = $this->inputs->create(
            $this->request('POST', '/api/payroll/inputs')->withParsedBody(
                $this->inputPayload($componentId, $amountMinor, $employmentId, $externalId),
            ),
            new Response(),
        );
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        $input = PayrollTimeValue::row($this->json($created)['input'] ?? null, 'input');
        $inputId = PayrollTimeValue::int($input['id'] ?? null, 'input_id');

        return $this->inputs->approve(
            $this->request('POST', "/api/payroll/inputs/{$inputId}/approve")
                ->withParsedBody([
                    'row_version' => PayrollTimeValue::int(
                        $input['row_version'] ?? null,
                        'row_version',
                    ),
                ]),
            new Response(),
            ['id' => (string) $inputId],
        );
    }

    /** @return array<string,mixed> */
    private function inputPayload(
        int $componentId,
        int $amountMinor,
        int $employmentId,
        string $externalId,
    ): array {
        return [
            'employee_id' => $this->employeeId,
            'employment_id' => $employmentId,
            'component_id' => $componentId,
            'period' => '2026-07',
            'source_period' => null,
            'amount_minor' => $amountMinor,
            'quantity_milliunits' => null,
            'source_kind' => 'manual',
            'external_id' => $externalId,
        ];
    }

    private function firstId(PDO $pdo, string $table): int
    {
        if (!in_array($table, ['supplier', 'users'], true)) {
            throw new \InvalidArgumentException('Nepodporovaná testovací tabulka.');
        }
        $stmt = $pdo->query("SELECT id FROM {$table} ORDER BY id LIMIT 1");
        if ($stmt === false) {
            throw new \RuntimeException("Tabulku {$table} nelze načíst.");
        }
        $value = $stmt->fetchColumn();

        return $value === false ? 0 : PayrollTimeValue::int($value, "{$table}.id");
    }

    private function request(string $method, string $uri): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest($method, $uri)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session');
    }

    /** @return array<string,mixed> */
    private function json(ResponseInterface $response): array
    {
        $response->getBody()->rewind();

        return PayrollTimeValue::row(json_decode((string) $response->getBody(), true), 'response');
    }
}
