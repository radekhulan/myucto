<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollBenefitBasketOverviewAction;
use MyInvoice\Action\Payroll\PayrollComponentsAction;
use MyInvoice\Action\Payroll\PayrollInputsAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\Payroll\PayrollTimeValue;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Přehled čerpání ročních košů osvobození za firmu — HTTP kontrakt.
 *
 * Náhled jednoho vstupu ukáže koš jen tomu, kdo ten vstup zrovna zadává; do
 * prosince se tedy nikdo nedozví, že se někdo blíží limitu. Tenhle endpoint
 * odpovídá na opačnou otázku a musí přitom držet tři věci, které se snadno
 * ztratí: agregaci za OSOBU (ne za vztah), agregaci napříč SLOŽKAMI koše
 * a čtení ZMRAZENÉHO rozpadu bez přepočtu.
 */
#[Group('integration')]
final class PayrollBenefitBasketOverviewApiTest extends TestCase
{
    /** § 6 odst. 9 písm. d) bod 2 ZDP pro rok 2026 — polovina průměrné mzdy. */
    private const LEISURE_LIMIT_MINOR = 2_448_350;

    /** § 6 odst. 9 písm. d) bod 1 ZDP pro rok 2026 — celá průměrná mzda. */
    private const HEALTH_LIMIT_MINOR = 4_896_700;

    /** § 6 odst. 9 písm. i) ZDP — 3 500 Kč MĚSÍČNĚ. */
    private const ACCOMMODATION_LIMIT_MINOR = 350_000;

    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollComponentsAction $components;
    private PayrollInputsAction $inputs;
    private PayrollBenefitBasketOverviewAction $overview;
    private int $supplierId;
    private int $foreignSupplierId;
    private int $employeeId;
    private int $employmentId;
    private int $secondEmploymentId;
    private int $userId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        if ($container === null) {
            throw new \RuntimeException('DI kontejner není dostupný.');
        }
        $db = $container->get(Connection::class);
        $components = $container->get(PayrollComponentsAction::class);
        $inputs = $container->get(PayrollInputsAction::class);
        $overview = $container->get(PayrollBenefitBasketOverviewAction::class);
        if (!$db instanceof Connection
            || !$components instanceof PayrollComponentsAction
            || !$inputs instanceof PayrollInputsAction
            || !$overview instanceof PayrollBenefitBasketOverviewAction
        ) {
            throw new \RuntimeException('Payroll služby nejsou dostupné.');
        }
        $this->db = $db;
        if (!$this->db->hasColumn('payroll_component_definitions', 'exemption_basket')) {
            $this->markTestSkipped('Migrace 1480 neproběhla.');
        }
        $this->components = $components;
        $this->inputs = $inputs;
        $this->overview = $overview;

        $pdo = $this->db->pdo();
        $sourceSupplierId = $this->firstId($pdo, 'supplier');
        $this->userId = $this->firstId($pdo, 'users');
        if ($sourceSupplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }

        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->foreignSupplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id IN (?, ?)')
            ->execute([$this->supplierId, $this->foreignSupplierId]);

        $this->employeeId = $this->createEmployee($this->supplierId, 'Syntetická zaměstnankyně');
        $this->employmentId = $this->createEmployment($this->supplierId, $this->employeeId, 'SYN-P-1');
        $this->secondEmploymentId = $this->createEmployment(
            $this->supplierId,
            $this->employeeId,
            'SYN-P-2',
        );
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
     * Jádro přehledu: souběžné vztahy téže osoby i různé složky téhož koše se
     * sčítají do JEDNOHO řádku. Akumulátor je klíčovaný na `employee_id`, takže
     * rozpad na vztahy by byl proti zákonu i proti tomu, co vidí náhled vstupu.
     */
    public function testOnePersonWithTwoRelationsAndTwoComponentsIsOneRow(): void
    {
        $first = $this->createBasketComponent('PREH_REKREACE_A', 'non_cash_leisure');
        $second = $this->createBasketComponent('PREH_REKREACE_B', 'non_cash_leisure');

        $this->approve($first, 900_000, $this->employmentId, 'p-a');
        $this->approve($second, 600_000, $this->secondEmploymentId, 'p-b');

        $rows = $this->rows($this->fetch(['year' => '2026']));
        self::assertCount(1, $rows);

        $row = $rows[0];
        self::assertSame($this->employeeId, $row['employee_id']);
        self::assertSame('non_cash_leisure', $row['basket']);
        self::assertSame('§ 6 odst. 9 písm. d) bod 2 ZDP', $row['statute']);
        self::assertSame(self::LEISURE_LIMIT_MINOR, $row['limit_minor']);
        self::assertSame(1_500_000, $row['used_minor']);
        self::assertSame(1_500_000, $row['exempt_minor']);
        self::assertSame(0, $row['taxable_minor']);
        self::assertSame(self::LEISURE_LIMIT_MINOR - 1_500_000, $row['remaining_minor']);
        self::assertSame(2, $row['input_count']);
        self::assertSame(0, $row['unfrozen_count']);
        self::assertSame('ok', $row['status']);
        self::assertFalse($row['split_drift']);
    }

    /**
     * Úhrn přesně na limitu je celý osvobozený — zákon říká „v úhrnu DO VÝŠE",
     * takže nerovnost je neostrá a řádek nesmí hlásit překročení.
     */
    public function testPersonExactlyOnTheLimitIsNotReportedAsExceeded(): void
    {
        $component = $this->createBasketComponent('PREH_REKREACE_C', 'non_cash_leisure');
        $this->approve($component, self::LEISURE_LIMIT_MINOR, $this->employmentId, 'p-c');

        $row = $this->rows($this->fetch(['year' => '2026']))[0];

        self::assertSame(self::LEISURE_LIMIT_MINOR, $row['used_minor']);
        self::assertSame(self::LEISURE_LIMIT_MINOR, $row['exempt_minor']);
        self::assertSame(0, $row['taxable_minor']);
        self::assertSame(0, $row['remaining_minor']);
        self::assertSame('approaching', $row['status']);
    }

    /**
     * Nad limitem se ukazuje ZMRAZENÁ nadlimitní část, tedy přesně to, co je na
     * výplatní pásce. Dopočet z dnešního rulesetu by u dřívějšího vstupu změnil
     * daňový dopad, který je už v uzavřené revizi.
     */
    public function testPersonOverTheLimitShowsTheFrozenTaxablePart(): void
    {
        $component = $this->createBasketComponent('PREH_REKREACE_D', 'non_cash_leisure');
        $this->approve($component, self::LEISURE_LIMIT_MINOR, $this->employmentId, 'p-d1');
        $this->approve($component, 100_000, $this->secondEmploymentId, 'p-d2');

        $row = $this->rows($this->fetch(['year' => '2026']))[0];

        self::assertSame('exceeded', $row['status']);
        self::assertSame(self::LEISURE_LIMIT_MINOR + 100_000, $row['used_minor']);
        self::assertSame(self::LEISURE_LIMIT_MINOR, $row['exempt_minor']);
        self::assertSame(100_000, $row['taxable_minor']);
        self::assertSame(0, $row['remaining_minor']);
        self::assertFalse($row['split_drift']);
    }

    /** Filtr na koš zúží stránku i `total`; jinak by pager nabízel prázdné stránky. */
    public function testBasketFilterNarrowsBothPageAndTotal(): void
    {
        $leisure = $this->createBasketComponent('PREH_REKREACE_E', 'non_cash_leisure');
        $health = $this->createBasketComponent('PREH_ZDRAVI_E', 'non_cash_health');
        $this->approve($leisure, 500_000, $this->employmentId, 'p-e1');
        $this->approve($health, 700_000, $this->employmentId, 'p-e2');

        $all = $this->fetch(['year' => '2026']);
        self::assertSame(2, $all['total']);

        $only = $this->fetch(['year' => '2026', 'basket' => 'non_cash_health']);
        self::assertSame(1, $only['total']);
        self::assertSame('non_cash_health', $this->rows($only)[0]['basket']);
        self::assertSame(self::HEALTH_LIMIT_MINOR, $this->rows($only)[0]['limit_minor']);
    }

    /** Rok bez dat je prázdná stránka, ne chyba a ne tichý pád na jiný rok. */
    public function testYearWithoutDataReturnsAnEmptyPage(): void
    {
        $component = $this->createBasketComponent('PREH_REKREACE_F', 'non_cash_leisure');
        $this->approve($component, 500_000, $this->employmentId, 'p-f');

        $body = $this->fetch(['year' => '2024']);

        self::assertSame(2024, $body['year']);
        self::assertSame(0, $body['total']);
        self::assertSame([], $body['items']);
        self::assertSame([2026], $body['years']);
    }

    /**
     * Rok přechodu z jiného mzdového programu: příspěvky na spoření na stáří z převzatých
     * měsíců čerpají koš § 6 odst. 9 písm. m) ZDP stejně jako v annualBasketTotal() (měsíc
     * a vztah jednou, dva vztahy téhož měsíce oba). Přehled je musí ukázat v „vyčerpáno"
     * i zvlášť, a osoba jen s převzatými měsíci v něm nesmí chybět.
     */
    public function testTakenOverMonthsCountTowardsTheOldAgeSavingsBasket(): void
    {
        $this->insertTakenOverSavings('2026-01-01', 'SYN-REL-1', $this->employmentId, 100_000);
        $this->insertTakenOverSavings('2026-02-01', 'SYN-REL-1', $this->employmentId, 100_000);
        $this->insertTakenOverSavings('2026-02-01', 'SYN-REL-2', $this->secondEmploymentId, 50_000);
        $this->insertTakenOverSavings('2025-12-01', 'SYN-REL-1', $this->employmentId, 900_000);

        $onlyTakenOver = $this->rows($this->fetch(['year' => '2026']));
        self::assertCount(1, $onlyTakenOver);
        self::assertSame(['old_age_savings', 250_000, 250_000, 2, 0], [$onlyTakenOver[0]['basket'], $onlyTakenOver[0]['used_minor'],
            $onlyTakenOver[0]['taken_over_minor'], $onlyTakenOver[0]['taken_over_months'], $onlyTakenOver[0]['input_count']]);

        $component = $this->createBasketComponent('PREH_PENZE_A', 'old_age_savings');
        $this->approve($component, 300_000, $this->employmentId, 'p-penze');

        $row = $this->rows($this->fetch(['year' => '2026', 'basket' => 'old_age_savings']))[0];
        self::assertSame(550_000, $row['used_minor']);
        self::assertSame(300_000, $row['exempt_minor']);
        self::assertSame(250_000, $row['taken_over_minor']);
        self::assertSame(1, $row['input_count']);
        self::assertSame(5_000_000 - 550_000, $row['remaining_minor']);
        self::assertFalse($row['split_drift']);
        self::assertSame([], $this->rows($this->fetch(['year' => '2026', 'basket' => 'non_cash_leisure'])));
        self::assertContains(2025, $this->fetch(['year' => '2026'])['years']);
    }

    private function insertTakenOverSavings(string $periodStart, string $relationRef, int $employmentId, int $contributionMinor): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_migration_reference_totals
                (supplier_id, source, period_start, external_person_ref, external_relationship_ref,
                 employee_id, employment_id, old_age_savings_contribution_minor)
             VALUES (?, "pamica", ?, "SYN-PERSON", ?, ?, ?, ?)'
        )->execute([$this->supplierId, $periodStart, $relationRef, $this->employeeId, $employmentId, $contributionMinor]);
    }

    /** Neznámý koš ani nesmyslný rok se netiší na výchozí hodnotu. */
    public function testInvalidFilterIsRejectedInsteadOfSilentlyIgnored(): void
    {
        self::assertSame(422, $this->response(['basket' => 'non_cash_nesmysl'])->getStatusCode());
        self::assertSame(422, $this->response(['year' => 'letos'])->getStatusCode());
    }

    /** Cizí firma se do přehledu nedostane ani řádkem, ani součtem. */
    public function testForeignTenantDataIsInvisible(): void
    {
        $component = $this->createBasketComponent('PREH_REKREACE_G', 'non_cash_leisure');
        $this->approve($component, 500_000, $this->employmentId, 'p-g');

        $foreignEmployeeId = $this->createEmployee($this->foreignSupplierId, 'Cizí osoba');
        $foreignEmploymentId = $this->createEmployment(
            $this->foreignSupplierId,
            $foreignEmployeeId,
            'CIZ-P-1',
        );
        $foreignComponent = $this->cloneComponentForForeignTenant($component);
        $this->insertForeignApprovedBenefit(
            $foreignEmployeeId,
            $foreignEmploymentId,
            $foreignComponent,
            2_000_000,
        );

        $rows = $this->rows($this->fetch(['year' => '2026']));
        self::assertCount(1, $rows);
        self::assertSame($this->employeeId, $rows[0]['employee_id']);
        self::assertSame(500_000, $rows[0]['used_minor']);
    }

    /** Bearer token na mzdový modul nesmí; endpoint je jen pro webovou relaci. */
    public function testBearerTokenIsRejected(): void
    {
        $response = $this->overview->list(
            $this->request(['year' => '2026'])
                ->withAttribute(AuthMiddleware::ATTR_METHOD, 'bearer'),
            new Response(),
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(
            'session_required',
            PayrollTimeValue::row($this->json($response)['error'] ?? null, 'error')['code'] ?? null,
        );
    }

    /**
     * Vstup schválený dřív, než koše existovaly, rozpad nemá. Nedopočítává se —
     * řádek jen přizná, kolik podkladů chybí.
     */
    public function testInputWithoutFrozenSplitIsReportedAsIncomplete(): void
    {
        $component = $this->createBasketComponent('PREH_REKREACE_H', 'non_cash_leisure');
        $this->approve($component, 500_000, $this->employmentId, 'p-h');
        $this->db->pdo()->prepare(
            'UPDATE payroll_inputs
                SET benefit_basket = NULL,
                    benefit_exempt_minor = NULL,
                    benefit_taxable_minor = NULL
              WHERE supplier_id = ? AND external_id = ?'
        )->execute([$this->supplierId, 'p-h']);

        $row = $this->rows($this->fetch(['year' => '2026']))[0];

        self::assertSame('incomplete', $row['status']);
        self::assertSame(1, $row['unfrozen_count']);
        self::assertSame(500_000, $row['used_minor']);
        self::assertSame(0, $row['exempt_minor']);
        self::assertFalse($row['split_drift']);
    }

    // ── Měsíční přehled (§ 6 odst. 9 písm. b) a i) ZDP) ─────────────────────

    /**
     * Měsíční přehled sčítá za MĚSÍC MZDOVÉHO VSTUPU, ne za rok akumulátoru.
     *
     * Akumulátor nese jen `tax_year`, takže dotaz vedený jím by slil celý rok do
     * jednoho čísla a měsíční limit by proti němu neznamenal nic. Zároveň se do
     * měsíčního přehledu nesmí připlést roční koš — dvanáctina ročního čerpání
     * proti ročnímu limitu je číslo bez významu.
     */
    public function testMonthlyOverviewSumsTheInputMonthAndKeepsAnnualBasketsOut(): void
    {
        $meal = $this->createBasketComponent('PREH_STRAVA_A', 'meal_per_shift');
        $leisure = $this->createBasketComponent('PREH_REKREACE_M', 'non_cash_leisure');
        $this->insertApprovedBenefit($meal, 'meal_per_shift', '2026-07-01', 180_000, 'm-a1');
        $this->insertApprovedBenefit($meal, 'meal_per_shift', '2026-07-01', 60_000, 'm-a2');
        $this->insertApprovedBenefit($meal, 'meal_per_shift', '2026-08-01', 999_000, 'm-a3');
        $this->approve($leisure, 500_000, $this->employmentId, 'm-a4');

        $body = $this->fetch(['period' => '2026-07']);
        $rows = $this->rows($body);

        self::assertSame('2026-07', $body['period']);
        self::assertCount(1, $rows, 'Roční koš do měsíčního přehledu nepatří.');
        self::assertSame('meal_per_shift', $rows[0]['basket']);
        self::assertSame(240_000, $rows[0]['used_minor'], 'Sčítá se jen červenec.');
        self::assertSame(2, $rows[0]['input_count']);
        self::assertSame(['2026-08', '2026-07'], $body['periods']);

        // A obráceně: roční přehled se měsíčními koši ředit nesmí.
        self::assertSame(1, $this->fetch(['year' => '2026'])['total']);
    }

    /**
     * PAST PÍSM. B): měsíční součet se proti limitu ZA SMĚNU poměřit nedá.
     *
     * Limit je „za jednu směnu", ale mzdový vstup je měsíční. Kdyby přehled
     * dosadil jakýkoli měsíční strop, tvrdil by o dodržení limitu něco, co
     * z dat neplyne. Řádek proto limit netvrdí vůbec a řekne to základem
     * `per_shift` — mlčky zelený stav „v limitu" by byl přesně ta lež, kvůli
     * které se tenhle údaj do odpovědi přidával.
     */
    public function testMealRowNeverAssertsALimitFromAMonthlyTotal(): void
    {
        $meal = $this->createBasketComponent('PREH_STRAVA_B', 'meal_per_shift');
        $this->insertApprovedBenefit($meal, 'meal_per_shift', '2026-07-01', 240_000, 'm-b1');

        $row = $this->rows($this->fetch(['period' => '2026-07']))[0];

        self::assertSame('per_shift', $row['limit_basis']);
        self::assertNull($row['limit_minor']);
        self::assertNull($row['remaining_minor']);
        self::assertSame('limit_unavailable', $row['status']);
        self::assertNotSame('ok', $row['status']);
        self::assertSame(240_000, $row['used_minor']);
        self::assertSame('§ 6 odst. 9 písm. b) ZDP', $row['statute']);
    }

    /**
     * Přechodné ubytování měsíční limit MÁ („maximálně do výše 3 500 Kč
     * měsíčně"), takže se u něj „zbývá" tvrdit smí — a musí, jinak by přehled
     * zamlčel jediný koš, u kterého měsíční srovnání dává smysl.
     */
    public function testTemporaryAccommodationIsMeasuredAgainstItsRealMonthlyLimit(): void
    {
        $stay = $this->createBasketComponent('PREH_UBYT_A', 'temporary_accommodation');
        $this->insertApprovedBenefit($stay, 'temporary_accommodation', '2026-07-01', 200_000, 'm-c1');

        $row = $this->rows($this->fetch(['period' => '2026-07', 'basket' => 'temporary_accommodation']))[0];

        self::assertSame('calendar_month', $row['limit_basis']);
        self::assertSame(self::ACCOMMODATION_LIMIT_MINOR, $row['limit_minor']);
        self::assertSame(self::ACCOMMODATION_LIMIT_MINOR - 200_000, $row['remaining_minor']);
        self::assertSame('ok', $row['status']);
    }

    /**
     * Koš z druhého režimu se odmítne, ne vrátí prázdno.
     *
     * Prázdná stránka by tvrdila „nikdo nečerpal" — přesně ta věta, kvůli které
     * obrazovka vznikla a která nesmí zaznít omylem.
     */
    public function testBasketFromTheOtherModeIsRejectedInsteadOfReturningNothing(): void
    {
        self::assertSame(
            422,
            $this->response(['period' => '2026-07', 'basket' => 'non_cash_leisure'])->getStatusCode(),
        );
        self::assertSame(
            422,
            $this->response(['year' => '2026', 'basket' => 'meal_per_shift'])->getStatusCode(),
        );
    }

    /** Nesmyslné období se netiší na aktuální měsíc — filtr, který ukáže jiný měsíc, je horší než chyba. */
    public function testInvalidPeriodIsRejected(): void
    {
        self::assertSame(422, $this->response(['period' => '2026-13'])->getStatusCode());
        self::assertSame(422, $this->response(['period' => '2026-7'])->getStatusCode());
        self::assertSame(422, $this->response(['period' => 'letos'])->getStatusCode());
    }

    /** Bez `period` se nic nemění: roční přehled odpovídá přesně jako dřív. */
    public function testAnnualModeIsUnchangedWhenPeriodIsAbsent(): void
    {
        $component = $this->createBasketComponent('PREH_REKREACE_BC', 'non_cash_leisure');
        $this->approve($component, 500_000, $this->employmentId, 'm-d1');

        $body = $this->fetch(['year' => '2026']);

        self::assertSame(2026, $body['year']);
        self::assertSame([2026], $body['years']);
        self::assertArrayNotHasKey('period', $body);
        self::assertSame('tax_year', $this->rows($body)[0]['limit_basis']);
    }

    /**
     * Schválený benefit se pro MĚSÍČNÍ koše zapisuje přímo.
     *
     * Přes akci to nejde: schválení stravného skládá strop z DOLOŽENÉHO počtu
     * směn ({@see \MyInvoice\Service\Payroll\Component\PayrollMealShiftEntitlement}),
     * takže by bez publikované docházky spadlo dřív, než by koš vůbec vznikl.
     * Testovaný endpoint je čtecí a zajímá ho jen zmrazený výsledek.
     */
    private function insertApprovedBenefit(
        int $componentId,
        string $basket,
        string $periodStart,
        int $amountMinor,
        string $externalId,
    ): void {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_inputs
                (supplier_id, employee_id, employment_id, component_id, period_start,
                 amount_minor, source_kind, external_id, status,
                 component_snapshot_json, component_snapshot_hash,
                 benefit_basket, benefit_exempt_minor, benefit_taxable_minor,
                 created_by, approved_by, approved_at)
             VALUES (?, ?, ?, ?, ?, ?, "manual", ?, "approved",
                     "{}", ?, ?, ?, 0, ?, ?, NOW())'
        )->execute([
            $this->supplierId,
            $this->employeeId,
            $this->employmentId,
            $componentId,
            $periodStart,
            $amountMinor,
            $externalId,
            hash('sha256', $externalId, true),
            $basket,
            $amountMinor,
            $this->userId,
            $this->userId,
        ]);
        $inputId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO payroll_benefit_accumulators
                (supplier_id, employee_id, component_id, input_id, tax_year, amount_minor)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $this->supplierId,
            $this->employeeId,
            $componentId,
            $inputId,
            (int) substr($periodStart, 0, 4),
            $amountMinor,
        ]);
    }

    /** @param array<string,string> $query */
    private function fetch(array $query): array
    {
        $response = $this->response($query);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return $this->json($response);
    }

    /** @param array<string,string> $query */
    private function response(array $query): ResponseInterface
    {
        return $this->overview->list($this->request($query), new Response());
    }

    /**
     * @param array<string,mixed> $body
     * @return list<array<string,mixed>>
     */
    private function rows(array $body): array
    {
        $items = $body['items'] ?? null;
        self::assertIsArray($items);

        return array_values(array_map(
            static fn (mixed $row): array => PayrollTimeValue::row($row, 'item'),
            $items,
        ));
    }

    private function createEmployee(int $supplierId, string $name): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, ?, "employee", "hpp", 1, 1, 0, 42000, 0, 1)'
        )->execute([$supplierId, $name]);

        return (int) $pdo->lastInsertId();
    }

    private function createEmployment(int $supplierId, int $employeeId, string $code): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 start_date, actual_start_date, monthly_gross_minor, is_legacy_projection)
             VALUES (?, ?, ?, "employment", "active",
                     "2026-01-01", "2026-01-01", 4200000, 0)'
        )->execute([$supplierId, $employeeId, $code]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * Cizí firma dostane vlastní složku kopií té naší — přehled se ptá přes
     * `supplier_id` na obou stranách joinu a test to musí umět rozbít.
     */
    private function cloneComponentForForeignTenant(int $componentId): int
    {
        $pdo = $this->db->pdo();
        $columns = $pdo->query(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'payroll_component_definitions'
                AND COLUMN_NAME NOT IN ('id', 'supplier_id')
                AND EXTRA NOT LIKE '%auto_increment%'
                AND (GENERATION_EXPRESSION IS NULL OR GENERATION_EXPRESSION = '')
              ORDER BY ORDINAL_POSITION"
        )->fetchAll(PDO::FETCH_COLUMN);
        $list = implode(', ', array_map(static fn (string $c): string => "`{$c}`", $columns));

        $pdo->prepare(
            "INSERT INTO payroll_component_definitions (supplier_id, {$list})
             SELECT ?, {$list} FROM payroll_component_definitions
              WHERE supplier_id = ? AND id = ?"
        )->execute([$this->foreignSupplierId, $this->supplierId, $componentId]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * Schválený benefit cizí firmy se zapisuje přímo, ne přes akci — akce jede
     * proti aktuální firmě v požadavku a druhého tenanta by nezaložila.
     */
    private function insertForeignApprovedBenefit(
        int $employeeId,
        int $employmentId,
        int $componentId,
        int $amountMinor,
    ): void {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_inputs
                (supplier_id, employee_id, employment_id, component_id, period_start,
                 amount_minor, source_kind, external_id, status,
                 component_snapshot_json, component_snapshot_hash,
                 benefit_basket, benefit_exempt_minor, benefit_taxable_minor,
                 created_by, approved_by, approved_at)
             VALUES (?, ?, ?, ?, "2026-07-01", ?, "manual", "ciz-1", "approved",
                     "{}", ?, "non_cash_leisure", ?, 0, ?, ?, NOW())'
        )->execute([
            $this->foreignSupplierId,
            $employeeId,
            $employmentId,
            $componentId,
            $amountMinor,
            hash('sha256', 'foreign', true),
            $amountMinor,
            $this->userId,
            $this->userId,
        ]);
        $inputId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO payroll_benefit_accumulators
                (supplier_id, employee_id, component_id, input_id, tax_year, amount_minor)
             VALUES (?, ?, ?, ?, 2026, ?)'
        )->execute([
            $this->foreignSupplierId,
            $employeeId,
            $componentId,
            $inputId,
            $amountMinor,
        ]);
    }

    /**
     * Složka klasifikovaná JEDNOZNAČNĚ — výchozí benefitní složky mají všude
     * „vyžaduje ruční posouzení" a schválení by spadlo dřív, než se ke koši
     * vůbec dostane.
     */
    private function createBasketComponent(string $code, ?string $basket): int
    {
        $response = $this->components->create(
            $this->request([], 'POST', '/api/payroll/components')->withParsedBody([
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

        return PayrollTimeValue::int($component['id'] ?? null, 'component_id');
    }

    /**
     * Storno akumulátoru — hodnota `reversed` byla v enumu od migrace 1210, ale
     * nikdo ji nenastavoval: schválený vstup se zrušit nedal a jiná cesta ke
     * stornu neexistovala. Koš tak zůstal vyčerpaný napořád i tehdy, když
     * plnění vůbec nebylo.
     */
    public function testReversingABenefitReleasesTheAnnualBasket(): void
    {
        $component = $this->createBasketComponent('PREH_STORNO_A', 'non_cash_leisure');
        $input = $this->approve($component, 900_000, $this->employmentId, 'p-rev-a');
        $inputId = PayrollTimeValue::int($input['id'] ?? null, 'input_id');

        self::assertSame(900_000, $this->rows($this->fetch(['year' => '2026']))[0]['used_minor']);

        $response = $this->reverse($inputId, $this->rowVersion($inputId), 'plnění nebylo poskytnuto');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $reversed = PayrollTimeValue::row($this->json($response)['input'] ?? null, 'input');
        self::assertSame('cancelled', $reversed['status']);

        // Storno koš uvolní, ale z přehledu vypadnout nesmí: uvolněný koš by
        // se jinak nedal odlišit od koše, který se nikdy nečerpal.
        $released = $this->rows($this->fetch(['year' => '2026']));
        self::assertCount(1, $released);
        self::assertSame(0, $released[0]['used_minor'], 'Stornovaný akumulátor už nesmí koš čerpat.');
        self::assertSame(0, $released[0]['input_count']);
        self::assertSame(1, $released[0]['reversed_count']);
        self::assertSame(900_000, $released[0]['reversed_minor']);
        self::assertSame('reversed', $this->accumulatorStatus($inputId));
        self::assertSame(
            900_000,
            $this->accumulatorAmount($inputId),
            'Zmrazená částka se stornem nepřepočítává — mění se jen stav.',
        );

        // Uvolněný koš musí jít znovu vyčerpat celý, jinak by storno nebylo storno.
        $this->approve($component, self::LEISURE_LIMIT_MINOR, $this->secondEmploymentId, 'p-rev-b');
        $row = $this->rows($this->fetch(['year' => '2026']))[0];
        self::assertSame(self::LEISURE_LIMIT_MINOR, $row['used_minor']);
        self::assertSame(0, $row['taxable_minor']);
        self::assertSame(1, $row['reversed_count']);
    }

    /** Druhé storno už jen vrátí stav — akumulátor se nesmí stornovat dvakrát. */
    public function testBenefitReversalIsIdempotent(): void
    {
        $component = $this->createBasketComponent('PREH_STORNO_B', 'non_cash_leisure');
        $input = $this->approve($component, 100_000, $this->employmentId, 'p-rev-c');
        $inputId = PayrollTimeValue::int($input['id'] ?? null, 'input_id');

        $first = $this->reverse($inputId, $this->rowVersion($inputId), 'omyl');
        self::assertSame(200, $first->getStatusCode(), (string) $first->getBody());
        $version = $this->rowVersion($inputId);

        $again = $this->reverse($inputId, $version, 'omyl');
        self::assertSame(200, $again->getStatusCode(), (string) $again->getBody());
        self::assertSame($version, $this->rowVersion($inputId));
    }

    /**
     * Vstup zmrazený ve schválené revizi je už na pásce, v zákonných výsledcích
     * i v účetní dávce. Uvolnit jeho koš by rozhodilo zdanění hotového měsíce —
     * oprava patří do opravné revize běhu.
     */
    public function testReversalRefusesAnInputFrozenInARunRevision(): void
    {
        $component = $this->createBasketComponent('PREH_STORNO_C', 'non_cash_leisure');
        $input = $this->approve($component, 100_000, $this->employmentId, 'p-rev-d');
        $inputId = PayrollTimeValue::int($input['id'] ?? null, 'input_id');
        $this->freezeInRunRevision($inputId);

        $response = $this->reverse($inputId, $this->rowVersion($inputId), 'omyl');
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('input_has_movement', $this->errorCode($response));
        self::assertSame('active', $this->accumulatorStatus($inputId));
    }

    /** Bez důvodu by z auditní stopy nešlo poznat, proč se koš uvolnil. */
    public function testReversalRequiresAReason(): void
    {
        $component = $this->createBasketComponent('PREH_STORNO_D', 'non_cash_leisure');
        $input = $this->approve($component, 100_000, $this->employmentId, 'p-rev-e');
        $inputId = PayrollTimeValue::int($input['id'] ?? null, 'input_id');

        $response = $this->reverse($inputId, $this->rowVersion($inputId), '   ');
        self::assertSame(422, $response->getStatusCode());
        self::assertSame('validation_failed', $this->errorCode($response));
        self::assertSame('active', $this->accumulatorStatus($inputId));
    }

    /** Nebenefitní vstup žádný koš nečerpá — není co stornovat. */
    public function testReversalRefusesAnInputWithoutABenefitAccumulator(): void
    {
        $component = $this->createBasketComponent('PREH_STORNO_E', null);
        $input = $this->approve($component, 100_000, $this->employmentId, 'p-rev-f');
        $inputId = PayrollTimeValue::int($input['id'] ?? null, 'input_id');
        $this->db->pdo()
            ->prepare('DELETE FROM payroll_benefit_accumulators WHERE supplier_id = ? AND input_id = ?')
            ->execute([$this->supplierId, $inputId]);

        $response = $this->reverse($inputId, $this->rowVersion($inputId), 'omyl');
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('input_state_conflict', $this->errorCode($response));
    }

    private function reverse(int $inputId, int $rowVersion, string $reason): ResponseInterface
    {
        return $this->inputs->reverseBenefit(
            $this->request([], 'POST', "/api/payroll/inputs/{$inputId}/reverse-benefit")
                ->withParsedBody(['row_version' => $rowVersion, 'reason' => $reason]),
            new Response(),
            ['id' => (string) $inputId],
        );
    }

    private function rowVersion(int $inputId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT row_version FROM payroll_inputs WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$this->supplierId, $inputId]);

        return PayrollTimeValue::int($stmt->fetchColumn(), 'row_version');
    }

    private function accumulatorStatus(int $inputId): string
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT status FROM payroll_benefit_accumulators
              WHERE supplier_id = ? AND input_id = ?'
        );
        $stmt->execute([$this->supplierId, $inputId]);

        return PayrollTimeValue::string($stmt->fetchColumn(), 'accumulator.status');
    }

    private function accumulatorAmount(int $inputId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT amount_minor FROM payroll_benefit_accumulators
              WHERE supplier_id = ? AND input_id = ?'
        );
        $stmt->execute([$this->supplierId, $inputId]);

        return PayrollTimeValue::int($stmt->fetchColumn(), 'accumulator.amount_minor');
    }

    private function errorCode(ResponseInterface $response): string
    {
        $error = PayrollTimeValue::row($this->json($response)['error'] ?? null, 'error');

        return PayrollTimeValue::string($error['code'] ?? null, 'error.code');
    }

    private function freezeInRunRevision(int $inputId): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_runs
                (supplier_id, period_start, payment_date, status, current_revision_no)
             VALUES (?, "2026-07-01", "2026-08-10", "calculated", 1)'
        )->execute([$this->supplierId]);
        $runId = (int) $pdo->lastInsertId();
        $snapshot = \MyInvoice\Service\Payroll\Ruleset\CanonicalJson::encode([
            'schema_version' => 'payroll-run-input.v2',
            'people' => [[
                'employments' => [[
                    'inputs' => [['id' => $inputId]],
                ]],
            ]],
        ]);
        $pdo->prepare(
            'INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, revision_kind, status,
                 schema_version, ruleset_manifest_hash, input_snapshot_json,
                 input_snapshot_hash, idempotency_key_hash)
             VALUES (?, ?, 1, "regular", "calculated", "payroll-run-input.v2",
                     SHA2(?, 256), ?, SHA2(?, 256), UNHEX(SHA2(?, 256)))'
        )->execute([
            $this->supplierId,
            $runId,
            'manifest-' . $inputId,
            $snapshot,
            $snapshot,
            'idempotency-' . $inputId,
        ]);
    }

    /** @return array<string,mixed> */
    private function approve(
        int $componentId,
        int $amountMinor,
        int $employmentId,
        string $externalId,
    ): array {
        $payload = [
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
        $created = $this->inputs->create(
            $this->request([], 'POST', '/api/payroll/inputs')->withParsedBody($payload),
            new Response(),
        );
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        $input = PayrollTimeValue::row($this->json($created)['input'] ?? null, 'input');
        $inputId = PayrollTimeValue::int($input['id'] ?? null, 'input_id');

        $approved = $this->inputs->approve(
            $this->request([], 'POST', "/api/payroll/inputs/{$inputId}/approve")
                ->withParsedBody([
                    'row_version' => PayrollTimeValue::int(
                        $input['row_version'] ?? null,
                        'row_version',
                    ),
                ]),
            new Response(),
            ['id' => (string) $inputId],
        );
        self::assertSame(200, $approved->getStatusCode(), (string) $approved->getBody());

        return PayrollTimeValue::row($this->json($approved)['input'] ?? null, 'input');
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

    /** @param array<string,string> $query */
    private function request(
        array $query,
        string $method = 'GET',
        string $uri = '/api/payroll/benefit-baskets',
    ): ServerRequestInterface {
        return (new ServerRequestFactory())
            ->createServerRequest($method, $uri)
            ->withQueryParams($query)
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
