<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollInputsAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\Payroll\PayrollTimeValue;
use MyInvoice\Service\Payroll\Component\PayrollInputImportService;
use MyInvoice\Service\Payroll\Component\PayrollNegativeAmountPolicy;
use MyInvoice\Service\Payroll\Run\PayrollRunStatutoryInputAssembler;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Záporný vstup u složky v základech se odmítne už při uložení.
 *
 * Záporná úkolová mzda a nadlimitní cestovní náhrada (oprava dřívějšího
 * zadání) prošly uložením i schválením a zastavil je až zákonný výpočet
 * blokací `negative_component_requires_revision`. Běh se pak rušil a znovu
 * otevíral. Odpočet zálohy na cestovné (složka mimo všechny základy) záporný
 * být smí a projít musí dál.
 */
#[Group('integration')]
final class PayrollNegativeInputRejectionTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const PERIOD = '2026-09';

    private Connection $db;
    private PayrollInputsAction $inputs;
    private PayrollInputImportService $imports;
    private int $supplierId;
    private int $userId;
    private int $employeeId;
    private int $employmentId;
    private int $includedId;
    private int $advanceId;

    protected function setUp(): void
    {
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        $this->db = $container->get(Connection::class);
        $this->inputs = $container->get(PayrollInputsAction::class);
        $this->imports = $container->get(PayrollInputImportService::class);
        if (!$this->db->hasTable('payroll_inputs')) {
            self::markTestSkipped('Mzdové migrace neproběhly.');
        }
        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0 || $this->userId === 0) {
            self::markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }

        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')->execute([$this->supplierId]);
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, "Syntetický Úkolář", "employee", "hpp", 1, 1, 0, 42000, 0, 1)'
        )->execute([$this->supplierId]);
        $this->employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 start_date, actual_start_date, monthly_gross_minor, is_legacy_projection)
             VALUES (?, ?, "SYN-NEG", "employment", "active", "2026-01-01", "2026-01-01", 4200000, 0)'
        )->execute([$this->supplierId, $this->employeeId]);
        $this->employmentId = (int) $pdo->lastInsertId();
        $this->includedId = $this->component('SYN_UKOL', 'task_wage', 'included', 'included', 'included');
        $this->advanceId = $this->component('SYN_ZALOHA', 'travel_reimbursement', 'exempt', 'excluded', 'excluded');
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function testNegativeAmountOnComponentInBasesIsRejectedOnCreateAndUpdate(): void
    {
        $rejected = $this->create($this->includedId, -1_181_800, 'syn-neg-1');
        self::assertSame(422, $rejected->getStatusCode(), (string) $rejected->getBody());
        $message = (string) $this->json($rejected)['error']['message'];
        self::assertStringContainsString('nesmí mít zápornou částku', $message);
        self::assertStringContainsString('opravné revize', $message);
        self::assertSame(0, $this->countInputs());

        // Odpočet zálohy na cestovné je mimo všechny základy: záporný projde.
        $advance = $this->create($this->advanceId, -700_000, 'syn-neg-2');
        self::assertSame(201, $advance->getStatusCode(), (string) $advance->getBody());

        // Úprava kladného vstupu na zápornou částku narazí na totéž pravidlo.
        $created = $this->create($this->includedId, 500_000, 'syn-neg-3');
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        $input = $this->json($created)['input'];
        $id = PayrollTimeValue::int($input['id'] ?? null, 'id');
        $updated = $this->inputs->update(
            $this->request('PUT', "/api/payroll/inputs/{$id}", [
                'row_version' => $input['row_version'],
                'amount_minor' => -296_600,
            ]),
            new Response(),
            ['id' => (string) $id],
        );
        self::assertSame(422, $updated->getStatusCode(), (string) $updated->getBody());
    }

    public function testImportRejectsNegativeRowOnComponentInBases(): void
    {
        $csv = implode("\n", [
            'employment_id;employment_code;component_code;amount_minor;external_id',
            "{$this->employmentId};SYN-NEG;SYN_UKOL;-1181800;imp-neg-1",
            "{$this->employmentId};SYN-NEG;SYN_ZALOHA;-700000;imp-neg-2",
        ]);
        $preview = $this->imports->preview($this->supplierId, self::PERIOD, 'csv', 'opravy.csv', $csv);

        self::assertSame(1, $preview['accepted_count']);
        self::assertSame(1, $preview['rejected_count']);
        self::assertStringContainsString('nesmí mít zápornou částku', json_encode($preview['errors'], JSON_UNESCAPED_UNICODE) ?: '');
    }

    /**
     * Uložení a výpočet se rozhodují týmž pravidlem: co uložení pustí, výpočet
     * nezastaví, a co by výpočet zastavil, uložení odmítne.
     */
    public function testSavePolicyMatchesCalculationRule(): void
    {
        $assembler = new \ReflectionMethod(PayrollRunStatutoryInputAssembler::class, 'entersDomainBase');
        foreach ([
            ['code' => 'A', 'tax_treatment' => 'included', 'social_treatment' => 'included', 'health_treatment' => 'included'],
            ['code' => 'B', 'tax_treatment' => 'exempt', 'social_treatment' => 'excluded', 'health_treatment' => 'excluded'],
            ['code' => 'C', 'tax_treatment' => 'exempt', 'social_treatment' => 'included', 'health_treatment' => 'excluded'],
            ['code' => 'D', 'tax_treatment' => 'manual_review', 'social_treatment' => 'excluded', 'health_treatment' => 'excluded'],
            ['code' => 'E', 'tax_treatment' => 'withholding_candidate', 'social_treatment' => 'excluded', 'health_treatment' => 'excluded'],
        ] as $component) {
            $calculationStops = false;
            foreach (PayrollNegativeAmountPolicy::DOMAINS as $domain) {
                $calculationStops = $calculationStops || $assembler->invoke(null, $component, $domain);
            }
            self::assertSame($calculationStops, PayrollNegativeAmountPolicy::rejectsNegativeInput($component), $component['code']);
        }
    }

    private function create(int $componentId, int $amount, string $externalId): ResponseInterface
    {
        return $this->inputs->create(
            $this->request('POST', '/api/payroll/inputs', [
                'employee_id' => $this->employeeId,
                'employment_id' => $this->employmentId,
                'component_id' => $componentId,
                'period' => self::PERIOD,
                'amount_minor' => $amount,
                'source_kind' => 'manual',
                'external_id' => $externalId,
            ]),
            new Response(),
        );
    }

    private function component(string $code, string $kind, string $tax, string $social, string $health): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_component_definitions
                (supplier_id, code, name, component_kind, value_kind,
                 frequency_kind, tax_treatment,
                 social_participation_treatment, social_treatment,
                 health_participation_treatment, health_treatment,
                 average_earning_treatment, enforcement_treatment,
                 jmhz_treatment, statistics_treatment,
                 accounting_debit_code, accounting_credit_code,
                 valid_from, is_active)
             VALUES (?, ?, ?, ?, "monetary", "one_off", ?,
                     ?, ?, ?, ?,
                     "excluded", "excluded", "excluded", "excluded",
                     "521", "331", "2026-01-01", 1)'
        )->execute([$this->supplierId, $code, "Syntetická {$code}", $kind, $tax, $social, $social, $health, $health]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    private function countInputs(): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM payroll_inputs WHERE supplier_id = ?');
        $stmt->execute([$this->supplierId]);

        return (int) $stmt->fetchColumn();
    }

    /** @param array<string,mixed> $body */
    private function request(string $method, string $uri, array $body): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest($method, $uri)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withParsedBody($body);
    }

    /** @return array<string,mixed> */
    private function json(ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
