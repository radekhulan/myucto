<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollQuickInputsAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\Payroll\PayrollTimeValue;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Rychlé zadání nesmí zakládat nulové koncepty.
 *
 * Formulář má tři pole a ukládají se všechna najednou. Dřív každé uložení volalo
 * `upsert()` třikrát bez podmínky na nenulovost, takže jedno uložení jediné částky
 * vyrobilo tři řádky v `payroll_inputs` — dva z nich nulové. Ty pak zablokovaly
 * mzdový běh (`draft_inputs_present`) a protože mazací cesta neexistovala, jediným
 * východiskem bylo je schválit — čímž se dostaly na výplatní pásku.
 *
 * Od zjednodušení schvalování (W4) se řádek uživateli s právem `payroll.approve`
 * ukládá rovnou jako `approved`. Testy proto sledují EXISTENCI a STAV řádku, ne
 * slovo „koncept": rozdíl mezi prázdným polem (ruší vstup) a zadanou nulou
 * (vstup zakládá) na tom nezávisí a platit musí dál. Že koncept vzniknout UMÍ,
 * hlídá {@see testSaverWithoutApprovalRightStillCreatesADraft()}.
 */
#[Group('integration')]
final class PayrollQuickInputZeroDraftTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const PERIOD = '2026-06';
    private const PERIOD_START = '2026-06-01';

    private Connection $db;
    private PayrollQuickInputsAction $action;
    private int $supplierId;
    private int $employmentId;
    private int $userId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildApp()->getContainer();
        if ($container === null) {
            throw new \RuntimeException('DI kontejner není dostupný.');
        }
        $db = $container->get(Connection::class);
        $action = $container->get(PayrollQuickInputsAction::class);
        if (!$db instanceof Connection || !$action instanceof PayrollQuickInputsAction) {
            throw new \RuntimeException('Payroll služby nejsou dostupné.');
        }
        $this->db = $db;
        $this->action = $action;
        if (!$this->db->hasTable('payroll_inputs')) {
            $this->markTestSkipped('Mzdové migrace neproběhly.');
        }

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query(
            'SELECT id FROM supplier ORDER BY id LIMIT 1'
        )?->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query(
            'SELECT id FROM users ORDER BY id LIMIT 1'
        )?->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }

        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')
            ->execute([$this->supplierId]);
        $this->employmentId = $this->employment('SYN-ZERO');
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

    public function testSavingEmptyFieldsCreatesNoDraftAtAll(): void
    {
        $this->save([
            'base_amount_minor' => null,
            'overtime_mode' => 'amount',
            'overtime_hours_milli' => null,
            'overtime_amount_minor' => 0,
            'bonus_amount_minor' => 0,
            'versions' => ['base' => null, 'overtime' => null, 'bonus' => null],
        ]);

        self::assertSame(
            [],
            $this->inputCodes(),
            'Prázdný formulář nesmí založit ani jeden mzdový vstup.',
        );
        self::assertSame(
            0,
            $this->draftBlockerCount(),
            'Nulový koncept už nesmí blokovat mzdový běh.',
        );
    }

    public function testSavingASingleAmountCreatesExactlyOneDraft(): void
    {
        $this->save([
            'base_amount_minor' => null,
            'overtime_mode' => 'amount',
            'overtime_hours_milli' => null,
            'overtime_amount_minor' => 0,
            'bonus_amount_minor' => 80_000,
            'versions' => ['base' => null, 'overtime' => null, 'bonus' => null],
        ]);

        self::assertSame(
            ['ODMENA'],
            $this->inputCodes(),
            'Vyplněná odměna má založit právě jeden řádek, ne tři.',
        );
        self::assertSame(
            'approved',
            $this->statusOf('ODMENA'),
            'Kdo smí schvalovat, ukládá rovnou schválený vstup — bez druhého kliku jinde.',
        );
        self::assertSame(
            1,
            $this->rowVersionOf('ODMENA'),
            'Založení je JEDEN zápis. Druhý bump verze by prohlížeči rozbil '
            . 'optimistický zámek a každá druhá editace by skončila na 409.',
        );
        self::assertSame(
            0,
            $this->draftBlockerCount(),
            'Schválený vstup mzdový běh nedrží — o to celé jde.',
        );
    }

    /**
     * Bez práva `payroll.approve` koncept vzniknout MUSÍ.
     *
     * Dvoustupňový režim pro větší účtárny nezmizel, jen přestal být povinný:
     * kdo schvalovat nesmí, ukládá dál koncept a ten drží mzdový běh, dokud ho
     * někdo neschválí.
     */
    public function testSaverWithoutApprovalRightStillCreatesADraft(): void
    {
        $this->save([
            'base_amount_minor' => null,
            'overtime_mode' => 'amount',
            'overtime_hours_milli' => null,
            'overtime_amount_minor' => 0,
            'bonus_amount_minor' => 80_000,
            'versions' => ['base' => null, 'overtime' => null, 'bonus' => null],
        ], approves: false);

        self::assertSame(['ODMENA'], $this->inputCodes());
        self::assertSame('draft', $this->statusOf('ODMENA'));
        self::assertSame(
            1,
            $this->draftBlockerCount(),
            'Koncept od neschvalujícího uživatele mzdový běh držet musí.',
        );
    }

    public function testClearingAnExistingAmountCancelsItsDraft(): void
    {
        $this->save([
            'base_amount_minor' => null,
            'overtime_mode' => 'amount',
            'overtime_hours_milli' => null,
            'overtime_amount_minor' => 0,
            'bonus_amount_minor' => 80_000,
            'versions' => ['base' => null, 'overtime' => null, 'bonus' => null],
        ]);
        self::assertSame(['ODMENA'], $this->inputCodes());

        $this->save([
            'base_amount_minor' => null,
            'overtime_mode' => 'amount',
            'overtime_hours_milli' => null,
            'overtime_amount_minor' => 0,
            'bonus_amount_minor' => 0,
            'versions' => ['base' => null, 'overtime' => null, 'bonus' => 1],
        ]);

        self::assertSame(
            [],
            $this->inputCodes(),
            'Vynulování pole má vstup zrušit, ne uložit nulu.',
        );
        self::assertSame(
            'cancelled',
            $this->statusOf('ODMENA'),
            'Zrušení jde přes status, ne přes smazání řádku — auditní stopa zůstává.',
        );
        self::assertSame(
            0,
            $this->draftBlockerCount(),
            'Zrušený vstup se do blokátoru mzdového běhu nepočítá.',
        );
    }

    /**
     * Zadaná nula na základní mzdě je údaj, ne prázdné pole.
     *
     * V částečném nebo přerušeném měsíci nese informaci „nic se nevydělalo".
     * Bez rozlišení `null` (nevyplněno) od `0` (zadáno) to nešlo říct a blokátor
     * `partial_month_base_required` neměl východisko.
     */
    public function testEnteredZeroOnTheBaseWageCreatesARowUnlikeAnEmptyField(): void
    {
        $this->save([
            'base_amount_minor' => 0,
            'overtime_mode' => 'amount',
            'overtime_hours_milli' => null,
            'overtime_amount_minor' => 0,
            'bonus_amount_minor' => 0,
            'versions' => ['base' => null, 'overtime' => null, 'bonus' => null],
        ]);

        self::assertSame(
            ['MZDA_MESICNI'],
            $this->inputCodes(),
            'Zadaná nula na základu má založit řádek — a jen ten jeden.',
        );
        self::assertSame(0, $this->amountOf('MZDA_MESICNI'));
        self::assertSame(
            'approved',
            $this->statusOf('MZDA_MESICNI'),
            'Zadaná nula je vědomý údaj — od schvalujícího uživatele rovnou schválený.',
        );
        self::assertSame(
            0,
            $this->draftBlockerCount(),
            'Nulový základ zadaný vědomě je platný podklad, ne nedodělek držící běh.',
        );
    }

    /** Zadaná nula blokátor zhasne, protože vstup na základ existovat bude. */
    public function testEnteredZeroOnTheBaseWageClearsThePartialMonthBlocker(): void
    {
        $this->partialMonthEmployment();
        self::assertContains(
            'partial_month_base_required',
            $this->blockers(),
            'Bez vstupu na základ musí blokátor svítit.',
        );

        $this->save([
            'base_amount_minor' => 0,
            'overtime_mode' => 'amount',
            'overtime_hours_milli' => null,
            'overtime_amount_minor' => 0,
            'bonus_amount_minor' => 0,
            'versions' => ['base' => null, 'overtime' => null, 'bonus' => null],
        ]);

        self::assertNotContains(
            'partial_month_base_required',
            $this->blockers(),
            'Zadaná nula je vstup na základ, blokátor tedy nemá důvod svítit dál.',
        );
    }

    /**
     * Vyprázdnění a zadání nuly jsou dvě různé operace nad týmž polem.
     *
     * Prázdné pole existující vstup ruší, zadaná nula ho zachová s nulovou
     * částkou. Kdyby se to slilo zpátky do jedné větve, spadne tenhle test.
     */
    public function testBlankAndEnteredZeroAreTwoDifferentOperationsOnTheBaseWage(): void
    {
        $this->save([
            'base_amount_minor' => 4_200_000,
            'overtime_mode' => 'amount',
            'overtime_hours_milli' => null,
            'overtime_amount_minor' => 0,
            'bonus_amount_minor' => 0,
            'versions' => ['base' => null, 'overtime' => null, 'bonus' => null],
        ]);
        self::assertSame(['MZDA_MESICNI'], $this->inputCodes());

        // Zadaná nula: řádek zůstává, jen s nulovou částkou.
        $this->save([
            'base_amount_minor' => 0,
            'overtime_mode' => 'amount',
            'overtime_hours_milli' => null,
            'overtime_amount_minor' => 0,
            'bonus_amount_minor' => 0,
            'versions' => ['base' => 1, 'overtime' => null, 'bonus' => null],
        ]);
        self::assertSame(
            ['MZDA_MESICNI'],
            $this->inputCodes(),
            'Zadaná nula vstup nesmí zrušit.',
        );
        self::assertSame(0, $this->amountOf('MZDA_MESICNI'));
        // Podstatný je STAV řádku, ne to, že je „koncept": zadaná nula vstup
        // zachovala. Kdyby se slila s vyprázdněním, řádek by tu byl zrušený.
        self::assertSame('approved', $this->statusOf('MZDA_MESICNI'));
        self::assertSame(
            2,
            $this->rowVersionOf('MZDA_MESICNI'),
            'Přepis částky je jeden zápis, takže jeden bump verze — 4 200 000 → 0.',
        );

        // Vyprázdnění: tentýž řádek se ruší.
        $this->save([
            'base_amount_minor' => null,
            'overtime_mode' => 'amount',
            'overtime_hours_milli' => null,
            'overtime_amount_minor' => 0,
            'bonus_amount_minor' => 0,
            'versions' => ['base' => 2, 'overtime' => null, 'bonus' => null],
        ]);
        self::assertSame(
            [],
            $this->inputCodes(),
            'Prázdné pole existující vstup ruší.',
        );
        self::assertSame('cancelled', $this->statusOf('MZDA_MESICNI'));
    }

    /** Chybějící klíč není totéž co výslovné null — musí zůstat chybou. */
    public function testMissingBaseAmountKeyIsRejected(): void
    {
        $response = $this->action->save(
            $this->request('PUT')->withParsedBody([
                'period' => self::PERIOD,
                'rows' => [[
                    'employment_id' => $this->employmentId,
                    'employment_row_version' => 1,
                    'overtime_mode' => 'amount',
                    'overtime_hours_milli' => null,
                    'overtime_amount_minor' => 0,
                    'bonus_amount_minor' => 0,
                    'versions' => ['base' => null, 'overtime' => null, 'bonus' => null],
                ]],
            ]),
            new Response(),
        );
        self::assertSame(422, $response->getStatusCode());
    }

    /** Zkrátí vztah tak, aby měsíc byl částečný a základ se musel zadat. */
    private function partialMonthEmployment(): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments
                SET start_date = "2026-06-10", actual_start_date = "2026-06-10"
              WHERE supplier_id = ? AND id = ?'
        )->execute([$this->supplierId, $this->employmentId]);
    }

    /** @return list<string> */
    private function blockers(): array
    {
        $response = $this->action->list(
            $this->request('GET')->withQueryParams(['period' => self::PERIOD]),
            new Response(),
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $month = PayrollTimeValue::row($this->json($response)['month'] ?? null, 'month');
        $items = $month['items'] ?? null;
        self::assertIsArray($items);
        self::assertCount(1, $items);
        $item = PayrollTimeValue::rows($items, 'items')[0];
        $blockers = $item['blockers'] ?? [];
        self::assertIsArray($blockers);

        return array_map(strval(...), $blockers);
    }

    private function amountOf(string $componentCode): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT input.amount_minor
               FROM payroll_inputs input
               JOIN payroll_component_definitions component
                 ON component.supplier_id = input.supplier_id
                AND component.id = input.component_id
              WHERE input.supplier_id = ? AND input.employment_id = ?
                AND input.period_start = ? AND component.code = ?'
        );
        $stmt->execute([
            $this->supplierId,
            $this->employmentId,
            self::PERIOD_START,
            $componentCode,
        ]);

        return (int) $stmt->fetchColumn();
    }

    /** @param array<string,mixed> $row */
    private function save(array $row, bool $approves = true): void
    {
        $request = $this->request('PUT')->withParsedBody([
            'period' => self::PERIOD,
            'rows' => [['employment_id' => $this->employmentId, 'employment_row_version' => 1] + $row],
        ]);
        if (!$approves) {
            // Účetní, která smí zadávat, ale ne schvalovat — dvoustupňový režim
            // větších účtáren. Superadmin z `request()` by právo schvalovat měl.
            $request = $request->withAttribute(
                'auth.effective_role',
                new EffectiveRole(0, 'Účetní bez schvalování', 'staff', true, [
                    'payroll' => AccessLevel::WRITE->value,
                    'payroll.inputs.write' => AccessLevel::WRITE->value,
                ]),
            );
        }
        $response = $this->action->save($request, new Response());
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
    }

    private function rowVersionOf(string $componentCode): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT input.row_version
               FROM payroll_inputs input
               JOIN payroll_component_definitions component
                 ON component.supplier_id = input.supplier_id
                AND component.id = input.component_id
              WHERE input.supplier_id = ? AND input.employment_id = ?
                AND input.period_start = ? AND component.code = ?'
        );
        $stmt->execute([
            $this->supplierId,
            $this->employmentId,
            self::PERIOD_START,
            $componentCode,
        ]);

        return (int) $stmt->fetchColumn();
    }

    /** @return list<string> */
    private function inputCodes(): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT component.code
               FROM payroll_inputs input
               JOIN payroll_component_definitions component
                 ON component.supplier_id = input.supplier_id
                AND component.id = input.component_id
              WHERE input.supplier_id = ? AND input.employment_id = ?
                AND input.period_start = ?
                AND input.status <> "cancelled"
              ORDER BY component.code'
        );
        $stmt->execute([$this->supplierId, $this->employmentId, self::PERIOD_START]);

        return array_map(strval(...), $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    private function statusOf(string $componentCode): string
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT input.status
               FROM payroll_inputs input
               JOIN payroll_component_definitions component
                 ON component.supplier_id = input.supplier_id
                AND component.id = input.component_id
              WHERE input.supplier_id = ? AND input.employment_id = ?
                AND input.period_start = ? AND component.code = ?'
        );
        $stmt->execute([
            $this->supplierId,
            $this->employmentId,
            self::PERIOD_START,
            $componentCode,
        ]);

        return (string) $stmt->fetchColumn();
    }

    private function draftBlockerCount(): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*)
               FROM payroll_inputs
              WHERE supplier_id = ? AND employment_id = ? AND period_start = ?
                AND status = "draft"'
        );
        $stmt->execute([$this->supplierId, $this->employmentId, self::PERIOD_START]);

        return (int) $stmt->fetchColumn();
    }

    private function employment(string $code): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, ?, "employee", "hpp", 1, 1, 0, 42000, 0, 1)'
        )->execute([$this->supplierId, 'Syntetická osoba ' . $code]);
        $employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employee_profiles
                (supplier_id, employee_id, profile_status)
             VALUES (?, ?, "legacy")'
        )->execute([$this->supplierId, $employeeId]);
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 start_date, actual_start_date, monthly_gross_minor,
                 is_legacy_projection)
             VALUES (?, ?, ?, "employment", "active", "2026-01-01", "2026-01-01",
                     4200000, 0)'
        )->execute([$this->supplierId, $employeeId, $code]);

        return (int) $pdo->lastInsertId();
    }

    private function request(string $method): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest($method, '/api/payroll/quick-inputs')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(
                AuthMiddleware::ATTR_USER,
                ['id' => $this->userId, 'role' => 'admin'],
            )
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session');
    }

    /** @return array<string,mixed> */
    private function json(ResponseInterface $response): array
    {
        $response->getBody()->rewind();

        return PayrollTimeValue::row(
            json_decode((string) $response->getBody(), true),
            'response',
        );
    }
}
