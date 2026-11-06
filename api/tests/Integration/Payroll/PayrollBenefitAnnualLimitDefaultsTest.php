<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollComponentsAction;
use MyInvoice\Action\Payroll\PayrollInputsAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\Payroll\PayrollComponentDeletionRepository;
use MyInvoice\Repository\Payroll\PayrollComponentRepository;
use MyInvoice\Repository\Payroll\PayrollTimeValue;
use MyInvoice\Service\Demo\DemoMode;
use MyInvoice\Service\Payroll\Component\PayrollComponentDefaults;
use MyInvoice\Service\Payroll\Component\PayrollComponentJmhzMappingDefaults;
use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * MZ-08 — zákonný koš osvobození u VÝCHOZÍCH mzdových složek.
 *
 * Předchozí podoba (MZ-08-W08 b) dosazovala zákonnou částku do složkového
 * `annual_limit_minor`. Bylo to nutné, ne postačující: limit § 6 odst. 9 ZDP
 * platí na ÚHRN za ustanovení, takže druhá složka téhož bodu ho obešla,
 * a překročení navíc BLOKOVALO schválení, ačkoli zákon plnění nad limit
 * nezakazuje — jen ho zdaňuje.
 *
 * Výchozí složka proto nově nese `exemption_basket` a žádnou částku;
 * `annual_limit_minor` zůstal jako vlastní strop zaměstnavatele a jako tvrdá
 * zábrana schválení dál funguje.
 */
#[Group('integration')]
final class PayrollBenefitAnnualLimitDefaultsTest extends TestCase
{
    use IsolatedSupplierTrait;

    /** § 6 odst. 9 písm. d) bod 2 ZDP — polovina průměrné mzdy 48 967 Kč. */
    private const LEISURE_LIMIT_MINOR = 2_448_350;

    private Connection $db;
    private PayrollComponentsAction $components;
    private PayrollInputsAction $inputs;
    private int $supplierId;
    private int $employeeId;
    private int $employmentId;
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
        foreach ([
            'payroll_component_definitions',
            'payroll_inputs',
            'payroll_benefit_accumulators',
        ] as $table) {
            if (!$this->db->hasTable($table)) {
                $this->markTestSkipped('Migrace 1210 neproběhla.');
            }
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
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 start_date, actual_start_date, monthly_gross_minor, is_legacy_projection)
             VALUES (?, ?, "SYN-LIMIT-1", "employment", "active",
                     "2026-01-01", "2026-01-01", 4200000, 0)'
        )->execute([$this->supplierId, $this->employeeId]);
        $this->employmentId = (int) $pdo->lastInsertId();
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

    public function testDefaultBenefitComponentsAreSeededWithTheStatutoryBasket(): void
    {
        $seeded = $this->defaultComponents();

        self::assertSame('non_cash_leisure', $seeded['REKREACE_VOLNY_CAS']['exemption_basket']);
        // § 6 odst. 9 písm. d) bod 1 ZDP — celá průměrná mzda.
        self::assertSame('non_cash_health', $seeded['ZDRAVOTNI_BENEFIT']['exemption_basket']);
        // § 6 odst. 9 písm. m) ZDP — 50 000 Kč ročně.
        self::assertSame('old_age_savings', $seeded['PRISPEVEK_PENZE_ZIVOTNI']['exemption_basket']);
        // § 6 odst. 9 písm. b) ZDP — limit je za směnu, ne za rok, ale koš to
        // nese: ruleset drží částku na směnu a počet směn dodá evidence docházky.
        self::assertSame('meal_per_shift', $seeded['PRISPEVEK_STRAVOVANI']['exemption_basket']);
        // § 6 odst. 9 písm. i) ZDP — 3 500 Kč měsíčně.
        self::assertSame(
            'temporary_accommodation',
            $seeded['PRECHODNE_UBYTOVANI']['exemption_basket'],
        );

        // Zákonná částka NESMÍ skončit ve složkovém stropu: ten je tvrdá zábrana
        // schválení, kdežto zákon nadlimitní plnění zdaňuje, nezakazuje.
        foreach (['REKREACE_VOLNY_CAS', 'ZDRAVOTNI_BENEFIT', 'PRISPEVEK_PENZE_ZIVOTNI'] as $code) {
            self::assertNull(
                $seeded[$code]['annual_limit_minor'],
                "Složka {$code} znovu dosazuje zákonnou částku do vlastního stropu.",
            );
        }
    }

    /**
     * Rozdíl na 50 haléřích: 24 484,00 Kč rekreačního benefitu je o padesátník
     * nad polovinou průměrné mzdy. Bez zařazení do koše (stav před migrací 1480)
     * se o tom uživatel nedozví nic; se zařazením vidí i rozpad.
     */
    public function testTheBasketSplitsTheAmountFiftyHellersOverTheLimit(): void
    {
        $componentId = $this->defaultComponents()['REKREACE_VOLNY_CAS']['id'];
        $atLimit = self::LEISURE_LIMIT_MINOR;
        $overLimit = self::LEISURE_LIMIT_MINOR + 50;

        $before = $this->previewWithoutBasket($componentId, $overLimit);
        self::assertNull(
            $before['exemption_basket'],
            'Bez zařazení do koše se nadlimitní část nedá spočítat.',
        );

        $after = $this->preview($componentId, $overLimit);
        $basket = PayrollTimeValue::row($after['exemption_basket'] ?? null, 'exemption_basket');
        self::assertSame('non_cash_leisure', $basket['basket']);
        self::assertSame(self::LEISURE_LIMIT_MINOR, $basket['limit_minor']);
        self::assertSame(self::LEISURE_LIMIT_MINOR, $basket['exempt_minor']);
        self::assertSame(50, $basket['taxable_minor']);
        self::assertTrue($basket['limit_exceeded']);

        // Nerovnost je NEOSTRÁ: „osvobozena v úhrnu DO VÝŠE poloviny průměrné mzdy".
        $exactly = PayrollTimeValue::row(
            $this->preview($componentId, $atLimit)['exemption_basket'] ?? null,
            'exemption_basket',
        );
        self::assertSame(0, $exactly['taxable_minor']);
        self::assertFalse($exactly['limit_exceeded']);
        self::assertSame(0, $exactly['remaining_minor']);
    }

    /**
     * Vlastní strop zaměstnavatele zůstal tvrdou zábranou schválení. Je to
     * vnitřní pravidlo firmy, ne daňová hranice — proto se chová jinak než koš.
     */
    public function testEmployerOwnCapStillBlocksApproval(): void
    {
        $decided = $this->createComponent([
            'code' => 'REKREACE_STROP_FIRMY',
            'name' => 'Rekreace a volnočasový benefit',
            'component_kind' => 'benefit_recreation',
            'value_kind' => 'non_monetary',
            'frequency_kind' => 'one_off',
            'tax_treatment' => 'included',
            'social_participation_treatment' => 'included',
            'social_treatment' => 'included',
            'health_participation_treatment' => 'included',
            'health_treatment' => 'included',
            'average_earning_treatment' => 'excluded',
            'enforcement_treatment' => 'included',
            'jmhz_treatment' => 'included',
            'statistics_treatment' => 'included',
            'accounting_debit_code' => null,
            'accounting_credit_code' => null,
            'annual_limit_minor' => self::LEISURE_LIMIT_MINOR,
            'exemption_basket' => null,
            'valid_from' => '2026-07-01',
            'valid_to' => null,
            'is_active' => true,
        ]);
        $componentId = PayrollTimeValue::int($decided['id'] ?? null, 'component_id');

        // 24 000,00 Kč projde — pod polovinou průměrné mzdy.
        self::assertSame(
            200,
            $this->approve($componentId, 2_400_000, 'rekreace-1')->getStatusCode(),
        );

        // Druhý benefit 484,00 Kč posune roční úhrn na 24 484,00 Kč, tedy
        // o 50 haléřů nad zákonný limit — schválení musí spadnout.
        $overLimit = $this->approve($componentId, 48_400, 'rekreace-2');
        self::assertSame(409, $overLimit->getStatusCode());
        self::assertSame('benefit_limit_exceeded', $this->errorCode($overLimit));

        // Kontrola opačným směrem: bez limitu (stav před opravou) tatáž částka projde.
        $this->db->pdo()->prepare(
            'UPDATE payroll_component_definitions
                SET annual_limit_minor = NULL
              WHERE supplier_id = ? AND id = ?'
        )->execute([$this->supplierId, $componentId]);
        self::assertSame(
            200,
            $this->approve($componentId, 48_400, 'rekreace-3')->getStatusCode(),
        );
    }

    /**
     * Verzování klasifikace: nová verze vzniká VEDLE staré, staré se jen dopočítá
     * konec platnosti. Historii to nepřepisuje — hodnoty původní verze zůstávají,
     * na co ukazoval schválený mzdový vstup, tam ukazuje dál.
     */
    public function testANewClassificationVersionIsAddedBesideTheOldOneWithoutRewritingIt(): void
    {
        $repository = $this->repositoryWith([
            ['valid_from' => '2026-01-01', 'rows' => [self::catalogRow('Původní klasifikace')]],
        ]);
        $repository->ensureDefaults($this->supplierId);
        $original = $this->rowsForCode('SYN_KLASIFIKACE');
        self::assertCount(1, $original);
        self::assertNull($original[0]['valid_to']);
        self::assertSame('Původní klasifikace', $original[0]['name']);

        $versioned = $this->repositoryWith([
            ['valid_from' => '2026-01-01', 'rows' => [self::catalogRow('Původní klasifikace')]],
            ['valid_from' => '2026-07-01', 'rows' => [self::catalogRow('Nová klasifikace')]],
        ]);
        $versioned->ensureDefaults($this->supplierId);
        $after = $this->rowsForCode('SYN_KLASIFIKACE');

        self::assertCount(2, $after);
        self::assertSame($original[0]['id'], $after[0]['id'], 'Původní verze se nesmí založit znovu.');
        self::assertSame('Původní klasifikace', $after[0]['name'], 'Historie se nepřepisuje.');
        self::assertSame('2026-01-01', $after[0]['valid_from']);
        self::assertSame('2026-06-30', $after[0]['valid_to']);
        self::assertSame('2026-07-01', $after[1]['valid_from']);
        self::assertNull($after[1]['valid_to']);
        self::assertSame('Nová klasifikace', $after[1]['name']);
        self::assertSame('non_cash_leisure', $after[1]['exemption_basket']);

        // Opakované založení je no-op: žádná třetí verze ani další zvýšení row_version.
        $versioned->ensureDefaults($this->supplierId);
        $again = $this->rowsForCode('SYN_KLASIFIKACE');
        self::assertCount(2, $again);
        self::assertSame($after[0]['row_version'], $again[0]['row_version']);
        self::assertSame($after[1]['row_version'], $again[1]['row_version']);
    }

    /** @param list<array{valid_from:string, rows:list<array{0:string,1:string,2:string,3:string,4:string,5:string,6:string,7:string,8:string,9:string,10:string,11:string,12:?string}>}> $catalog */
    private function repositoryWith(array $catalog): PayrollComponentRepository
    {
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        if ($container === null) {
            throw new \RuntimeException('DI kontejner není dostupný.');
        }
        $deletion = $container->get(PayrollComponentDeletionRepository::class);
        $jmhzDefaults = $container->get(PayrollComponentJmhzMappingDefaults::class);
        if (!$deletion instanceof PayrollComponentDeletionRepository
            || !$jmhzDefaults instanceof PayrollComponentJmhzMappingDefaults
        ) {
            throw new \RuntimeException('Mazací repozitář složek není dostupný.');
        }

        return new PayrollComponentRepository(
            $this->db,
            $deletion,
            new PayrollComponentDefaults(CzechPayrollRulesets2026::provider(), $catalog),
            $jmhzDefaults,
            $container->get(DemoMode::class),
        );
    }

    /** @return array{0:string,1:string,2:string,3:string,4:string,5:string,6:string,7:string,8:string,9:string,10:string,11:string,12:?string} */
    private static function catalogRow(string $name): array
    {
        return [
            'SYN_KLASIFIKACE',
            $name,
            'benefit_recreation',
            'non_monetary',
            'one_off',
            'included',
            'included',
            'included',
            'excluded',
            'included',
            'included',
            'included',
            'non_cash_leisure',
        ];
    }

    /** @return list<array<string,mixed>> */
    private function rowsForCode(string $code): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, name, annual_limit_minor, exemption_basket, valid_from,
                    valid_to, row_version
               FROM payroll_component_definitions
              WHERE supplier_id = ? AND code = ?
              ORDER BY valid_from'
        );
        $stmt->execute([$this->supplierId, $code]);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[] = [
                'id' => PayrollTimeValue::int($row['id'], 'id'),
                'name' => PayrollTimeValue::string($row['name'], 'name'),
                'annual_limit_minor' => $row['annual_limit_minor'] === null
                    ? null
                    : PayrollTimeValue::int($row['annual_limit_minor'], 'annual_limit_minor'),
                'exemption_basket' => $row['exemption_basket'] === null
                    ? null
                    : PayrollTimeValue::string($row['exemption_basket'], 'exemption_basket'),
                'valid_from' => PayrollTimeValue::string($row['valid_from'], 'valid_from'),
                'valid_to' => $row['valid_to'] === null
                    ? null
                    : PayrollTimeValue::string($row['valid_to'], 'valid_to'),
                'row_version' => PayrollTimeValue::int($row['row_version'], 'row_version'),
            ];
        }

        return $rows;
    }

    /** @return array<string, array<string,mixed>> */
    private function defaultComponents(): array
    {
        $response = $this->components->list(
            $this->request('GET', '/api/payroll/components')
                ->withQueryParams(['effective_on' => '2026-06-01']),
            new Response(),
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $components = $this->json($response)['components'] ?? null;
        self::assertIsArray($components);

        $byCode = [];
        foreach ($components as $component) {
            $row = PayrollTimeValue::row($component, 'component');
            $byCode[PayrollTimeValue::string($row['code'] ?? null, 'code')] = $row;
        }

        return $byCode;
    }

    /** @return array<string,mixed> */
    private function preview(int $componentId, int $amountMinor): array
    {
        $response = $this->inputs->preview(
            $this->request('POST', '/api/payroll/inputs/preview')
                ->withParsedBody($this->inputPayload($componentId, $amountMinor, 'preview')),
            new Response(),
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return PayrollTimeValue::row($this->json($response)['preview'] ?? null, 'preview');
    }

    /**
     * Stav PŘED migrací 1480: složka bez zařazení do zákonného koše. Sloupec se
     * dočasně vynuluje, aby byl rozdíl měřený na téže složce a téže částce.
     *
     * @return array<string,mixed>
     */
    private function previewWithoutBasket(int $componentId, int $amountMinor): array
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            'SELECT exemption_basket FROM payroll_component_definitions
              WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$this->supplierId, $componentId]);
        $saved = $stmt->fetchColumn();
        $pdo->prepare(
            'UPDATE payroll_component_definitions SET exemption_basket = NULL
              WHERE supplier_id = ? AND id = ?'
        )->execute([$this->supplierId, $componentId]);
        try {
            return $this->preview($componentId, $amountMinor);
        } finally {
            $pdo->prepare(
                'UPDATE payroll_component_definitions SET exemption_basket = ?
                  WHERE supplier_id = ? AND id = ?'
            )->execute([$saved, $this->supplierId, $componentId]);
        }
    }

    private function approve(int $componentId, int $amountMinor, string $externalId): ResponseInterface
    {
        $created = $this->inputs->create(
            $this->request('POST', '/api/payroll/inputs')
                ->withParsedBody($this->inputPayload($componentId, $amountMinor, $externalId)),
            new Response(),
        );
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        $input = PayrollTimeValue::row($this->json($created)['input'] ?? null, 'input');
        $inputId = PayrollTimeValue::int($input['id'] ?? null, 'input_id');

        return $this->inputs->approve(
            $this->request('POST', "/api/payroll/inputs/{$inputId}/approve")
                ->withParsedBody([
                    'row_version' => PayrollTimeValue::int($input['row_version'] ?? null, 'row_version'),
                ]),
            new Response(),
            ['id' => (string) $inputId],
        );
    }

    /** @return array<string,mixed> */
    private function inputPayload(int $componentId, int $amountMinor, string $externalId): array
    {
        return [
            'employee_id' => $this->employeeId,
            'employment_id' => $this->employmentId,
            'component_id' => $componentId,
            'period' => '2026-07',
            'source_period' => null,
            'amount_minor' => $amountMinor,
            'quantity_milliunits' => null,
            'source_kind' => 'manual',
            'external_id' => $externalId,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function createComponent(array $payload): array
    {
        $response = $this->components->create(
            $this->request('POST', '/api/payroll/components')->withParsedBody($payload),
            new Response(),
        );
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return PayrollTimeValue::row($this->json($response)['component'] ?? null, 'component');
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

    private function errorCode(ResponseInterface $response): string
    {
        $error = PayrollTimeValue::row($this->json($response)['error'] ?? null, 'error');

        return PayrollTimeValue::string($error['code'] ?? null, 'error.code');
    }

    /** @return array<string,mixed> */
    private function json(ResponseInterface $response): array
    {
        $response->getBody()->rewind();

        return PayrollTimeValue::row(json_decode((string) $response->getBody(), true), 'response');
    }
}
