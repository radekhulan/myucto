<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollInputsAction;
use MyInvoice\Action\Payroll\PayrollTravelAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\Payroll\PayrollTimeValue;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Payroll\Travel\BusinessTripMaterializer;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class PayrollTravelApiTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollTravelAction $travel;
    private PayrollInputsAction $inputs;
    private int $supplierId;
    private int $otherSupplierId;
    private int $employeeId;
    private int $employmentId;
    private int $otherEmployeeId;
    private int $otherEmploymentId;
    private int $userId;

    protected function setUp(): void
    {
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        if ($container === null) {
            throw new \RuntimeException('DI kontejner není dostupný.');
        }
        $db = $container->get(Connection::class);
        $travel = $container->get(PayrollTravelAction::class);
        $inputs = $container->get(PayrollInputsAction::class);
        if (!$db instanceof Connection
            || !$travel instanceof PayrollTravelAction
            || !$inputs instanceof PayrollInputsAction
        ) {
            throw new \RuntimeException('Payroll služby nejsou dostupné.');
        }
        $this->db = $db;
        foreach ([
            'payroll_business_trips',
            'payroll_business_trip_items',
            'payroll_business_trip_free_meals',
            'payroll_travel_compensation_links',
        ] as $table) {
            if (!$this->db->hasTable($table)) {
                $this->markTestSkipped('Migrace 1308 neproběhla.');
            }
        }
        $this->travel = $travel;
        $this->inputs = $inputs;

        $pdo = $this->db->pdo();
        $sourceSupplierId = $this->firstId($pdo, 'supplier');
        $this->userId = $this->firstId($pdo, 'users');
        if ($sourceSupplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }

        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->otherSupplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id IN (?, ?)')
            ->execute([$this->supplierId, $this->otherSupplierId]);

        [$this->employeeId, $this->employmentId] =
            $this->employment($this->supplierId, 'Syntetická cestující', 'SYN-TRV-1');
        [$this->otherEmployeeId, $this->otherEmploymentId] =
            $this->employment($this->otherSupplierId, 'Cizí syntetický cestující', 'SYN-TRV-2');
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

    public function testPreviewSplitsEntitlementIntoExemptAndTaxablePart(): void
    {
        $response = $this->travel->preview(
            $this->request('POST', '/api/payroll/travel/preview')
                ->withParsedBody($this->tripPayload(mealRateBand1: '200')),
            new Response(),
        );

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $calculation = PayrollTimeValue::row(
            $this->json($response)['calculation'] ?? null,
            'calculation',
        );
        self::assertSame('supported', $calculation['status']);
        self::assertSame(20_000, $calculation['entitlement_total_minor']);
        self::assertSame(18_500, $calculation['exempt_total_minor']);
        self::assertSame(1_500, $calculation['taxable_total_minor']);
    }

    /**
     * Schválené vyúčtování se do mzdových vstupů promítne jednou; opakované
     * volání jen přehraje existující vstupy.
     */
    public function testApprovalMaterializesClassifiedInputsIdempotently(): void
    {
        $trip = $this->createTrip($this->tripPayload(mealRateBand1: '200'));
        $tripId = PayrollTimeValue::int($trip['id'] ?? null, 'trip_id');
        $approved = $this->approveTrip($tripId, PayrollTimeValue::int(
            $trip['row_version'] ?? null,
            'row_version',
        ));
        self::assertSame('approved', $approved['status']);
        self::assertSame(20_000, $approved['entitlement_total_minor']);
        self::assertSame(18_500, $approved['exempt_total_minor']);
        self::assertSame(1_500, $approved['taxable_total_minor']);

        $first = $this->materialize($tripId);
        self::assertSame(2, $first['created_count']);
        self::assertSame(0, $first['replayed_count']);

        $second = $this->materialize($tripId);
        self::assertSame(0, $second['created_count']);
        self::assertSame(2, $second['replayed_count']);

        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM payroll_inputs
              WHERE supplier_id = ? AND source_kind = "travel"'
        );
        $stmt->execute([$this->supplierId]);
        self::assertSame(2, PayrollTimeValue::int($stmt->fetchColumn(), 'input_count'));

        $links = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM payroll_travel_compensation_links
              WHERE supplier_id = ? AND trip_id = ? AND classification_status = "classified"'
        );
        $links->execute([$this->supplierId, $tripId]);
        self::assertSame(2, PayrollTimeValue::int($links->fetchColumn(), 'link_count'));
    }

    /**
     * Podlimitní část nesmí nikdy vstoupit do daně, pojistného ani exekučního
     * základu; nadlimitní část do nich naopak vstoupit musí.
     */
    public function testExemptPartStaysOutOfBasesAndTaxablePartEntersThem(): void
    {
        $trip = $this->createTrip($this->tripPayload(mealRateBand1: '200'));
        $tripId = PayrollTimeValue::int($trip['id'] ?? null, 'trip_id');
        $this->approveTrip($tripId, PayrollTimeValue::int(
            $trip['row_version'] ?? null,
            'row_version',
        ));
        $this->materialize($tripId);

        $classifications = [];
        foreach ($this->travelInputs() as $input) {
            $approved = $this->approveInput(
                PayrollTimeValue::int($input['id'] ?? null, 'input_id'),
                PayrollTimeValue::int($input['row_version'] ?? null, 'row_version'),
            );
            $snapshot = json_decode(
                (string) $approved['component_snapshot_json'],
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            self::assertIsArray($snapshot);
            $classifications[(string) $snapshot['code']] = $snapshot;
        }

        $exempt = $classifications[BusinessTripMaterializer::COMPONENT_EXEMPT];
        self::assertSame('exempt', $exempt['tax_treatment']);
        foreach ([
            'social_treatment',
            'health_treatment',
            'average_earning_treatment',
            'enforcement_treatment',
            'jmhz_treatment',
        ] as $key) {
            self::assertSame('excluded', $exempt[$key], "podlimitní část v {$key}");
        }

        $taxable = $classifications[BusinessTripMaterializer::COMPONENT_TAXABLE];
        self::assertSame('included', $taxable['tax_treatment']);
        foreach ([
            'social_treatment',
            'health_treatment',
            'enforcement_treatment',
            'jmhz_treatment',
        ] as $key) {
            self::assertSame('included', $taxable[$key], "nadlimitní část v {$key}");
        }
        self::assertSame('excluded', $taxable['average_earning_treatment']);
    }

    /**
     * § 183 ZP — vyúčtování je nárok MINUS záloha. Záloha vyplacená předem se
     * při vypořádání mzdou odečte záporným vstupem, daňové zařazení obou částí
     * nároku zůstává celé.
     */
    public function testPayrollSettlementDeductsTheAdvanceFromThePayout(): void
    {
        $payload = $this->tripPayload(mealRateBand1: '200');
        $payload['advance'] = '150';
        $tripId = $this->approvedTripId($payload);

        $result = $this->materialize($tripId);

        self::assertSame(3, $result['created_count']);
        self::assertSame([
            BusinessTripMaterializer::COMPONENT_EXEMPT => 18_500,
            BusinessTripMaterializer::COMPONENT_TAXABLE => 1_500,
            BusinessTripMaterializer::COMPONENT_ADVANCE => -15_000,
        ], $this->travelAmountsByComponent());
        $settlement = PayrollTimeValue::row($result['settlement'] ?? null, 'settlement');
        self::assertSame(5_000, $settlement['payroll_net_minor']);
        self::assertSame(0, $settlement['employee_refund_minor']);

        $replay = $this->materialize($tripId);
        self::assertSame(0, $replay['created_count']);
        self::assertSame(3, $replay['replayed_count']);
    }

    /**
     * Přeplatek zálohy se ze mzdy nesráží — odečte se nejvýš celý nárok a zbytek
     * zaměstnanec vrací.
     */
    public function testAdvanceAboveEntitlementIsRefundedNotWithheld(): void
    {
        $payload = $this->tripPayload(mealRateBand1: '200');
        $payload['advance'] = '300';
        $tripId = $this->approvedTripId($payload);

        $settlement = PayrollTimeValue::row(
            $this->materialize($tripId)['settlement'] ?? null,
            'settlement',
        );

        self::assertSame(-20_000, $this->travelAmountsByComponent()[BusinessTripMaterializer::COMPONENT_ADVANCE]);
        self::assertSame(0, $settlement['payroll_net_minor']);
        self::assertSame(10_000, $settlement['employee_refund_minor']);
    }

    /**
     * Vypořádání pokladnou: nezdaněná část do mzdy nejde (vyplácí ji pokladna),
     * zdanitelná část ano — do základu daně se dostane jen mzdou. Nezdaněnou
     * část zaúčtuje vyúčtování MD 512 / D 335 proti záloze.
     */
    public function testCashSettlementPostsTheExemptPartAgainstTheAdvance(): void
    {
        $this->makeDoubleEntry();
        $payload = $this->tripPayload(mealRateBand1: '200');
        $payload['advance'] = '100';
        $payload['advance_settlement'] = 'cash';
        $tripId = $this->approvedTripId($payload);

        $result = $this->materialize($tripId);

        self::assertSame(
            [BusinessTripMaterializer::COMPONENT_TAXABLE => 1_500],
            $this->travelAmountsByComponent(),
        );
        $settlement = PayrollTimeValue::row($result['settlement'] ?? null, 'settlement');
        self::assertSame(8_500, $settlement['cash_payout_minor']);
        $posting = PayrollTimeValue::row($result['posting'] ?? null, 'posting');
        self::assertSame('posted', $posting['status'], (string) json_encode($posting));
        self::assertSame(
            ['335|credit' => '185.00', '512|debit' => '185.00'],
            $this->journalLines(PayrollTimeValue::int($posting['journal_entry_id'] ?? null, 'entry')),
        );

        $replay = PayrollTimeValue::row(
            $this->materialize($tripId)['posting'] ?? null,
            'posting',
        );
        self::assertSame('already_posted', $replay['status']);
    }

    public function testUnknownAdvanceSettlementIsRejected(): void
    {
        $payload = $this->tripPayload();
        $payload['advance_settlement'] = 'bank';

        $response = $this->travel->create(
            $this->request('POST', '/api/payroll/travel/trips')->withParsedBody($payload),
            new Response(),
        );

        self::assertSame(422, $response->getStatusCode());
    }

    /**
     * § 183 odst. 1 ZP: cesta končící ve středu 10. 6. má doklady do 24. 6.
     * (10 pracovních dnů); předložené 12. 6. se vyúčtují do 26. 6. Den
     * předložení jde zapsat na cestě a před koncem cesty být nemůže.
     */
    public function testDocumentsSubmissionDateDrivesTheSettlementDeadline(): void
    {
        $trip = $this->createTrip($this->tripPayload());
        self::assertNull($trip['documents_submitted_on']);
        self::assertSame('2026-06-24', $trip['documents_due_on']);
        self::assertNull($trip['settlement_due_on']);

        $payload = $this->tripPayload();
        $payload['documents_submitted_on'] = '2026-06-12';
        $payload['row_version'] = $trip['row_version'];
        $response = $this->travel->update(
            $this->request('PUT', "/api/payroll/travel/trips/{$trip['id']}")->withParsedBody($payload),
            new Response(),
            ['id' => (string) $trip['id']],
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $updated = PayrollTimeValue::row($this->json($response)['trip'] ?? null, 'trip');
        self::assertSame('2026-06-12', $updated['documents_submitted_on']);
        self::assertNull($updated['documents_due_on']);
        self::assertSame('2026-06-26', $updated['settlement_due_on']);

        $early = $this->tripPayload();
        $early['documents_submitted_on'] = '2026-06-09';
        $rejected = $this->travel->create(
            $this->request('POST', '/api/payroll/travel/trips')->withParsedBody($early),
            new Response(),
        );
        self::assertSame(422, $rejected->getStatusCode());
    }

    public function testPreviewShowsTheSettlementAgainstTheAdvance(): void
    {
        $payload = $this->tripPayload(mealRateBand1: '200');
        $payload['advance'] = '50';
        $response = $this->travel->preview(
            $this->request('POST', '/api/payroll/travel/preview')->withParsedBody($payload),
            new Response(),
        );

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $settlement = PayrollTimeValue::row($this->json($response)['settlement'] ?? null, 'settlement');
        self::assertSame('payroll', $settlement['mode']);
        self::assertSame(5_000, $settlement['payroll_advance_offset_minor']);
        self::assertSame(15_000, $settlement['payroll_net_minor']);
    }

    public function testForeignBusinessTripCannotBeApproved(): void
    {
        $payload = $this->tripPayload();
        $payload['country_code'] = 'DE';
        $trip = $this->createTrip($payload);
        $tripId = PayrollTimeValue::int($trip['id'] ?? null, 'trip_id');

        $response = $this->travel->approve(
            $this->approverRequest('POST', "/api/payroll/travel/trips/{$tripId}/approve")
                ->withParsedBody(['row_version' => $trip['row_version']]),
            new Response(),
            ['id' => (string) $tripId],
        );

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('travel_requires_manual_review', $this->errorCode($response));
    }

    public function testMaterializationRejectsUnapprovedTrip(): void
    {
        $trip = $this->createTrip($this->tripPayload());
        $tripId = PayrollTimeValue::int($trip['id'] ?? null, 'trip_id');

        $response = $this->travel->materialize(
            $this->approverRequest('POST', "/api/payroll/travel/trips/{$tripId}/materialize"),
            new Response(),
            ['id' => (string) $tripId],
        );

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('trip_state_conflict', $this->errorCode($response));
    }

    /** Vyúčtování do měsíce s uzavřenou mzdou by vytvořilo vstupy, které se nevyplatí. */
    public function testMaterializationIntoClosedPayrollMonthIsRefused(): void
    {
        $trip = $this->createTrip($this->tripPayload(mealRateBand1: '200'));
        $tripId = PayrollTimeValue::int($trip['id'] ?? null, 'trip_id');
        $approved = $this->approveTrip($tripId, PayrollTimeValue::int(
            $trip['row_version'] ?? null,
            'row_version',
        ));
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_runs
                 (supplier_id, period_start, payment_date, status, current_revision_no, row_version)
             VALUES (?, ?, ?, "closed", 1, 1)'
        )->execute([
            $this->supplierId,
            $approved['settlement_period_start'],
            $approved['settlement_period_start'],
        ]);
        $runId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO payroll_run_revisions
                 (supplier_id, run_id, revision_no, status, schema_version,
                  ruleset_manifest_hash, input_snapshot_json, input_snapshot_hash,
                  idempotency_key_hash)
             VALUES (?, ?, 1, 'approved', 'test', ?, '{}', ?, ?)"
        )->execute([$this->supplierId, $runId, str_repeat('a', 64), str_repeat('b', 64), random_bytes(32)]);
        $pdo->prepare(
            "INSERT INTO payroll_run_employments
                 (supplier_id, revision_id, employee_id, employment_id, input_json, input_hash)
             VALUES (?, ?, ?, ?, '{}', ?)"
        )->execute([
            $this->supplierId,
            (int) $pdo->lastInsertId(),
            $this->employeeId,
            $this->employmentId,
            str_repeat('c', 64),
        ]);

        $response = $this->travel->materialize(
            $this->approverRequest('POST', "/api/payroll/travel/trips/{$tripId}/materialize"),
            new Response(),
            ['id' => (string) $tripId],
        );

        self::assertSame(409, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('payroll_run_closed', $this->errorCode($response));
        self::assertSame([], $this->travelInputs());
    }

    public function testTenantIsolationHidesForeignTripsFromListAndDetail(): void
    {
        $trip = $this->createTrip($this->tripPayload());
        $tripId = PayrollTimeValue::int($trip['id'] ?? null, 'trip_id');

        $ownList = $this->travel->list(
            $this->request('GET', '/api/payroll/travel/trips'),
            new Response(),
        );
        self::assertSame(200, $ownList->getStatusCode());
        self::assertCount(1, (array) ($this->json($ownList)['trips'] ?? []));

        $foreignList = $this->travel->list(
            $this->request('GET', '/api/payroll/travel/trips', supplierId: $this->otherSupplierId),
            new Response(),
        );
        self::assertSame(200, $foreignList->getStatusCode());
        self::assertSame([], (array) ($this->json($foreignList)['trips'] ?? []));

        $foreignDetail = $this->travel->recalculate(
            $this->request(
                'GET',
                "/api/payroll/travel/trips/{$tripId}/calculation",
                supplierId: $this->otherSupplierId,
            ),
            new Response(),
            ['id' => (string) $tripId],
        );
        self::assertSame(404, $foreignDetail->getStatusCode());

        $foreignUpdate = $this->travel->update(
            $this->request(
                'PUT',
                "/api/payroll/travel/trips/{$tripId}",
                supplierId: $this->otherSupplierId,
            )->withParsedBody([
                ...$this->tripPayload(),
                'employee_id' => $this->otherEmployeeId,
                'employment_id' => $this->otherEmploymentId,
                'row_version' => 1,
            ]),
            new Response(),
            ['id' => (string) $tripId],
        );
        self::assertSame(404, $foreignUpdate->getStatusCode());
    }

    public function testCrossTenantEmploymentReferenceIsRejected(): void
    {
        $payload = $this->tripPayload();
        $payload['employee_id'] = $this->otherEmployeeId;
        $payload['employment_id'] = $this->otherEmploymentId;

        $response = $this->travel->create(
            $this->request('POST', '/api/payroll/travel/trips')->withParsedBody($payload),
            new Response(),
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('validation_failed', $this->errorCode($response));
    }

    public function testTravelEndpointsAreSessionOnly(): void
    {
        $response = $this->travel->list(
            $this->request('GET', '/api/payroll/travel/trips', authMethod: 'bearer'),
            new Response(),
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('session_required', $this->errorCode($response));
    }

    public function testOptimisticLockRejectsStaleUpdate(): void
    {
        $trip = $this->createTrip($this->tripPayload());
        $tripId = PayrollTimeValue::int($trip['id'] ?? null, 'trip_id');

        $response = $this->travel->update(
            $this->request('PUT', "/api/payroll/travel/trips/{$tripId}")
                ->withParsedBody([...$this->tripPayload(), 'row_version' => 99]),
            new Response(),
            ['id' => (string) $tripId],
        );

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('row_version_conflict', $this->errorCode($response));
    }

    public function testApprovalRequiresApprovePermission(): void
    {
        $trip = $this->createTrip($this->tripPayload());
        $tripId = PayrollTimeValue::int($trip['id'] ?? null, 'trip_id');

        $request = $this->request('POST', "/api/payroll/travel/trips/{$tripId}/approve")
            ->withAttribute('auth.effective_role', new EffectiveRole(
                43,
                'Zadavatel vstupů',
                'staff',
                true,
                ['payroll.inputs.write' => AccessLevel::WRITE->value],
            ))
            ->withParsedBody(['row_version' => $trip['row_version']]);

        $response = $this->travel->approve($request, new Response(), ['id' => (string) $tripId]);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('forbidden', $this->errorCode($response));
    }

    public function testTripWithPrivateVehicleAndFreeMealPersistsItemsAndMeals(): void
    {
        $payload = $this->tripPayload();
        $payload['items'] = [[
            'item_kind' => 'private_vehicle',
            'spent_on' => '2026-06-10',
            'description' => 'Jízda soukromým vozidlem',
            'vehicle_kind' => 'car',
            'fuel_kind' => 'petrol_95',
            'distance_km' => '150',
            'consumption_per_100km' => '6.5',
        ], [
            'item_kind' => 'accommodation',
            'spent_on' => '2026-06-10',
            'description' => 'Ubytování',
            'amount' => '1250.50',
        ]];
        $payload['free_meals'] = [['meal_date' => '2026-06-10', 'meal_count' => 1]];

        $trip = $this->createTrip($payload);
        $items = (array) ($trip['items'] ?? []);
        self::assertCount(2, $items);
        self::assertSame(150_000, $items[0]['distance_m']);
        self::assertSame(6_500, $items[0]['consumption_ml_per_100km']);
        self::assertSame(125_050, $items[1]['amount_minor']);
        self::assertSame(['2026-06-10' => 1], $trip['free_meals']);

        $calculation = PayrollTimeValue::row(
            $this->json($this->travel->recalculate(
                $this->request('GET', '/api/payroll/travel/trips/x/calculation'),
                new Response(),
                ['id' => (string) PayrollTimeValue::int($trip['id'] ?? null, 'trip_id')],
            ))['calculation'] ?? null,
            'calculation',
        );
        self::assertSame('supported', $calculation['status']);
        // Stravné 155 − 70 % = 46,50 + 885,00 základní náhrada + 338,33 PHM
        // + 1 250,50 ubytování = 2 520,33 → nahoru 2 521,00 Kč.
        self::assertSame(252_100, $calculation['entitlement_total_minor']);
        self::assertSame(252_100, $calculation['exempt_total_minor']);
        self::assertSame(0, $calculation['taxable_total_minor']);
    }

    /** @param array<string,mixed> $payload */
    private function approvedTripId(array $payload): int
    {
        $trip = $this->createTrip($payload);
        $tripId = PayrollTimeValue::int($trip['id'] ?? null, 'trip_id');
        $this->approveTrip($tripId, PayrollTimeValue::int($trip['row_version'] ?? null, 'row_version'));

        return $tripId;
    }

    /** @return array<string,int> kód složky → částka */
    private function travelAmountsByComponent(): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT component.code, input.amount_minor
               FROM payroll_inputs input
               JOIN payroll_component_definitions component
                 ON component.supplier_id = input.supplier_id
                AND component.id = input.component_id
              WHERE input.supplier_id = ? AND input.source_kind = "travel"
              ORDER BY component.code'
        );
        $stmt->execute([$this->supplierId]);
        $amounts = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $amounts[(string) $row['code']] = (int) $row['amount_minor'];
        }

        return $amounts;
    }

    private function makeDoubleEntry(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO supplier_accounting_modes
                (supplier_id, effective_from, accounting_mode)
             VALUES (?, "2000-01-01", "double_entry")
             ON DUPLICATE KEY UPDATE accounting_mode = "double_entry"',
        )->execute([$this->supplierId]);
        foreach ([['512', 'expense'], ['335', 'asset']] as [$code, $type]) {
            $pdo->prepare(
                'INSERT IGNORE INTO chart_of_accounts
                    (supplier_id, account_code, name, account_type, is_active)
                 VALUES (?, ?, ?, ?, 1)',
            )->execute([$this->supplierId, $code, "Účet {$code}", $type]);
        }
    }

    /** @return array<string,string> `účet|strana` → částka */
    private function journalLines(int $entryId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT account.account_code, line.side, CAST(line.amount AS CHAR) AS amount
               FROM journal_entry_lines line
               JOIN chart_of_accounts account
                 ON account.supplier_id = line.supplier_id
                AND account.id = line.account_id
              WHERE line.supplier_id = ? AND line.entry_id = ?
              ORDER BY account.account_code',
        );
        $statement->execute([$this->supplierId, $entryId]);
        $lines = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $lines["{$row['account_code']}|{$row['side']}"] = (string) $row['amount'];
        }

        return $lines;
    }

    /** @return list<array<string,mixed>> */
    private function travelInputs(): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, row_version
               FROM payroll_inputs
              WHERE supplier_id = ? AND source_kind = "travel"
              ORDER BY id'
        );
        $stmt->execute([$this->supplierId]);

        return PayrollTimeValue::rows($stmt->fetchAll(PDO::FETCH_ASSOC), 'travel_inputs');
    }

    /** @return array<string,mixed> */
    private function approveInput(int $inputId, int $rowVersion): array
    {
        $response = $this->inputs->approve(
            $this->approverRequest('POST', "/api/payroll/inputs/{$inputId}/approve")
                ->withParsedBody(['row_version' => $rowVersion]),
            new Response(),
            ['id' => (string) $inputId],
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return PayrollTimeValue::row($this->json($response)['input'] ?? null, 'input');
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function createTrip(array $payload): array
    {
        $response = $this->travel->create(
            $this->request('POST', '/api/payroll/travel/trips')->withParsedBody($payload),
            new Response(),
        );
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return PayrollTimeValue::row($this->json($response)['trip'] ?? null, 'trip');
    }

    /** @return array<string,mixed> */
    private function approveTrip(int $tripId, int $rowVersion): array
    {
        $response = $this->travel->approve(
            $this->approverRequest('POST', "/api/payroll/travel/trips/{$tripId}/approve")
                ->withParsedBody(['row_version' => $rowVersion]),
            new Response(),
            ['id' => (string) $tripId],
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return PayrollTimeValue::row($this->json($response)['trip'] ?? null, 'trip');
    }

    /** @return array<string,mixed> */
    private function materialize(int $tripId): array
    {
        $response = $this->travel->materialize(
            $this->approverRequest('POST', "/api/payroll/travel/trips/{$tripId}/materialize"),
            new Response(),
            ['id' => (string) $tripId],
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return PayrollTimeValue::row(
            $this->json($response)['materialization'] ?? null,
            'materialization',
        );
    }

    /** @return array<string,mixed> */
    private function tripPayload(?string $mealRateBand1 = null): array
    {
        return [
            'employee_id' => $this->employeeId,
            'employment_id' => $this->employmentId,
            'country_code' => 'CZ',
            // ISO 8601 s offsetem + IANA zóna, stejně jako u směn.
            'departure_at' => '2026-06-10T08:00:00+02:00',
            'arrival_at' => '2026-06-10T16:00:00+02:00',
            'timezone' => 'Europe/Prague',
            'origin_place' => 'Praha',
            'destination_place' => 'Brno',
            'purpose' => 'Syntetické jednání',
            'transport_mode' => 'public_transport',
            'meal_rate_band_1' => $mealRateBand1,
            'advance' => null,
            'settlement_period' => '2026-06',
            'items' => [],
            'free_meals' => [],
        ];
    }

    /** @return array{0:int,1:int} */
    private function employment(int $supplierId, string $name, string $code): array
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, ?, "employee", "hpp", 1, 1, 0, 42000, 0, 1)'
        )->execute([$supplierId, $name]);
        $employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 start_date, actual_start_date, monthly_gross_minor,
                 is_legacy_projection)
             VALUES (?, ?, ?, "employment", "active",
                     "2026-01-01", "2026-01-01", 4200000, 0)'
        )->execute([$supplierId, $employeeId, $code]);

        return [$employeeId, (int) $pdo->lastInsertId()];
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

    private function approverRequest(string $method, string $uri): ServerRequestInterface
    {
        return $this->request($method, $uri)
            ->withAttribute('auth.effective_role', new EffectiveRole(
                42,
                'Schvalovatel mezd',
                'staff',
                true,
                [
                    'payroll' => AccessLevel::WRITE->value,
                    'payroll.approve' => AccessLevel::WRITE->value,
                    'payroll.inputs.write' => AccessLevel::WRITE->value,
                ],
            ));
    }

    private function request(
        string $method,
        string $uri,
        string $authMethod = 'session',
        ?int $supplierId = null,
    ): ServerRequestInterface {
        return (new ServerRequestFactory())
            ->createServerRequest($method, $uri)
            ->withAttribute(
                SupplierScopeMiddleware::ATTR_CURRENT_ID,
                $supplierId ?? $this->supplierId,
            )
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, $authMethod);
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

        return PayrollTimeValue::row(
            json_decode((string) $response->getBody(), true),
            'response',
        );
    }
}
