<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollEmploymentSurchargePolicyAction;
use MyInvoice\Action\Payroll\PayrollQuickInputsAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\Payroll\PayrollTimeValue;
use MyInvoice\Service\Payroll\Time\Surcharge\PayrollSurchargeInputMaterializer;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * W20 — ruční zadání zákonných příplatků § 115 až § 118 v rychlém měsíčním
 * vstupu, a sjednávání zásad, bez kterých se nedaly spočítat vůbec.
 */
#[Group('integration')]
final class PayrollQuickSurchargeInputsApiTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const PERIOD = '2026-06';
    private const PERIOD_START = '2026-06-01';

    private Connection $db;
    private PayrollQuickInputsAction $action;
    private PayrollEmploymentSurchargePolicyAction $policies;
    private PayrollSurchargeInputMaterializer $materializer;
    private int $supplierId;
    private int $userId;
    private int $employmentId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildApp()->getContainer();
        if ($container === null) {
            throw new \RuntimeException('DI kontejner není dostupný.');
        }
        $this->db = $container->get(Connection::class);
        $this->action = $container->get(PayrollQuickInputsAction::class);
        $this->policies = $container->get(PayrollEmploymentSurchargePolicyAction::class);
        $this->materializer = $container->get(PayrollSurchargeInputMaterializer::class);
        $pdo = $this->db->pdo();
        $sourceSupplierId = $this->firstId('supplier');
        $this->userId = $this->firstId('users');
        if ($sourceSupplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }

        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')
            ->execute([$this->supplierId]);
        $this->employmentId = $this->employment('Syntetická směna', 'SYN-PRIPLATKY');
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
     * § 116 — deset hodin noční práce při průměru 200 Kč/h a sazbě 10 %.
     *
     * 200,00 Kč × 0,10 × 10 h = 200,00 Kč. Kontroluje se i to, že se zapsaly
     * HODINY, ne jen částka: bez nich nejde z mzdového listu doložit rozsah.
     */
    public function testEntersNightSurchargeFromHoursAlone(): void
    {
        $this->approvedAverage(20_000);

        $response = $this->saveSurcharges(['night' => ['hours_milli' => 10_000]]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame([], $this->json($response)['failures']);

        $input = $this->quickInput('PRIPLATEK_NOCNI');
        self::assertIsArray($input, 'Noční příplatek se neuložil.');
        self::assertSame(20_000, (int) $input['amount_minor']);
        self::assertSame(10_000, (int) $input['quantity_milliunits']);

        $source = json_decode((string) $input['source_snapshot_json'], true);
        self::assertSame('night', $source['surcharge_kind']);
        self::assertSame('average_earning', $source['basis']);
        self::assertSame(1_000, $source['rate_basis_points']);
        self::assertSame('quick_manual', $source['entry_source']);

        // Nárok drží ruční zadání — docházka ho už převzít nesmí.
        self::assertSame('manual', $this->claimSource('night'));
    }

    /** Víc druhů u jedné osoby se sčítá vedle sebe, nekonkuruje si. */
    public function testEntersSeveralKindsForOnePersonAtOnce(): void
    {
        $this->approvedAverage(20_000);

        $response = $this->saveSurcharges([
            'night' => ['hours_milli' => 10_000],
            'weekend' => ['hours_milli' => 8_000],
        ]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame([], $this->json($response)['failures']);

        self::assertSame(20_000, (int) $this->quickInput('PRIPLATEK_NOCNI')['amount_minor']);
        self::assertSame(16_000, (int) $this->quickInput('PRIPLATEK_VIKEND')['amount_minor']);

        $item = $this->listItem();
        self::assertSame(36_000, $item['surcharge_amount_minor']);
        // Příplatky se musí projevit i v náhledu hrubého příjmu, jinak by
        // účetní viděla jiné číslo, než co vyjde ze mzdového běhu.
        self::assertSame(
            4_200_000 + 36_000,
            $item['gross_preview_minor'],
        );
    }

    /**
     * § 117 — příplatek náleží ZA KAŽDÝ ztěžující vliv, základ je minimální
     * mzda, ne průměrný výdělek. Tři vlivy tedy dají trojnásobek.
     */
    public function testDifficultEnvironmentMultipliesByFactorCount(): void
    {
        $one = $this->saveSurcharges([
            'difficult_environment' => ['hours_milli' => 10_000, 'factors' => 1],
        ]);
        self::assertSame(200, $one->getStatusCode(), (string) $one->getBody());
        $single = (int) $this->quickInput('PRIPLATEK_ZTIZENE_PROSTREDI')['amount_minor'];
        self::assertGreaterThan(0, $single);

        $stored = $this->quickInput('PRIPLATEK_ZTIZENE_PROSTREDI');
        $three = $this->saveSurcharges(
            ['difficult_environment' => ['hours_milli' => 10_000, 'factors' => 3]],
            ['difficult_environment' => (int) $stored['row_version']],
        );
        self::assertSame(200, $three->getStatusCode(), (string) $three->getBody());

        $updated = $this->quickInput('PRIPLATEK_ZTIZENE_PROSTREDI');
        self::assertSame($single * 3, (int) $updated['amount_minor']);
        // Oprava přepisuje TÝŽ řádek, nezakládá druhý — jinak by se příplatek
        // vyplatil dvakrát.
        self::assertSame((int) $stored['id'], (int) $updated['id']);
        self::assertSame(1, $this->countInputs('PRIPLATEK_ZTIZENE_PROSTREDI'));

        $source = json_decode((string) $updated['source_snapshot_json'], true);
        self::assertSame(3, $source['difficulty_factors']);
        self::assertSame('minimum_wage_hourly', $source['basis']);

        // Bez průměrného výdělku, protože § 117 ho nepotřebuje. Kdyby ho
        // potřeboval, test by tu spadl a bylo by to správně.
        self::assertNull($source['average_snapshot_id']);
    }

    /** § 117 bez počtu vlivů je fail-closed, ne tichá nula. */
    public function testDifficultEnvironmentWithoutFactorCountIsRejected(): void
    {
        $response = $this->saveSurcharges([
            'difficult_environment' => ['hours_milli' => 10_000],
        ]);

        self::assertSame(409, $response->getStatusCode(), (string) $response->getBody());
        $error = $this->json($response)['error'];
        self::assertStringContainsString('ztěžující', $error['message']);
        self::assertNull($this->quickInput('PRIPLATEK_ZTIZENE_PROSTREDI'));
    }

    /**
     * § 115 — bez sjednané zásady se příplatek za svátek nevyplácí (odst. 1 dává
     * náhradní volno). Pole tedy není dostupné a server hodiny odmítne.
     */
    public function testHolidayWithoutAgreedArrangementIsUnavailableAndRejected(): void
    {
        $this->approvedAverage(20_000);

        $state = $this->listItem()['surcharges']['holiday'];
        self::assertFalse($state['entry_available']);
        self::assertSame('holiday_arrangement_missing', $state['unavailable_reason']);

        $response = $this->saveSurcharges(['holiday' => ['hours_milli' => 8_000]]);
        self::assertSame(409, $response->getStatusCode(), (string) $response->getBody());
        self::assertStringContainsString('§ 115', $this->json($response)['error']['message']);
        self::assertNull($this->quickInput('PRIPLATEK_SVATEK'));
    }

    /**
     * Táž situace po sjednání zásady. Tenhle test je smyslem celé opravy:
     * dokud nešlo zásadu zadat, byl svátek slepá ulička bez východiska.
     */
    public function testHolidayBecomesAvailableOnceTheArrangementIsAgreed(): void
    {
        $this->approvedAverage(20_000);
        $this->agreeHolidaySurcharge();

        $state = $this->listItem()['surcharges']['holiday'];
        self::assertTrue($state['entry_available'], 'Sjednaná zásada svátek neodblokovala.');
        self::assertNull($state['unavailable_reason']);

        $response = $this->saveSurcharges(['holiday' => ['hours_milli' => 8_000]]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame([], $this->json($response)['failures']);

        // § 115 odst. 2 — nejméně 100 % průměrného výdělku: 200,00 × 8 h.
        self::assertSame(160_000, (int) $this->quickInput('PRIPLATEK_SVATEK')['amount_minor']);
    }

    /**
     * Bez schváleného průměrného výdělku se § 116 a § 118 spočítat nedají.
     * Nula by tvrdila, že nárok byl posouzen a nevznikl.
     */
    public function testMissingAverageEarningFailsClosedInsteadOfZero(): void
    {
        $state = $this->listItem()['surcharges']['night'];
        self::assertFalse($state['entry_available']);
        self::assertSame('basis_missing', $state['unavailable_reason']);
        self::assertNull($state['basis_hourly_minor']);

        $response = $this->saveSurcharges(['night' => ['hours_milli' => 10_000]]);
        self::assertSame(409, $response->getStatusCode(), (string) $response->getBody());
        self::assertStringContainsString('průměrný výdělek', $this->json($response)['error']['message']);
        self::assertNull($this->quickInput('PRIPLATEK_NOCNI'));
    }

    /** Vyprázdnění hodin ruší vstup A PUSTÍ nárok, ať ho může převzít docházka. */
    public function testClearingHoursCancelsTheInputAndReleasesTheClaim(): void
    {
        $this->approvedAverage(20_000);
        self::assertSame(200, $this->saveSurcharges(['night' => ['hours_milli' => 10_000]])->getStatusCode());
        $stored = $this->quickInput('PRIPLATEK_NOCNI');
        self::assertSame('manual', $this->claimSource('night'));

        $response = $this->saveSurcharges(
            ['night' => ['hours_milli' => null]],
            ['night' => (int) $stored['row_version']],
        );

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertNull($this->quickInput('PRIPLATEK_NOCNI'));
        self::assertNull($this->claimSource('night'));
    }

    /**
     * Zmizí-li podklad až po zadání (třeba se zruší schválení průměrného
     * výdělku), musí jít zadanou hodnotu aspoň VYMAZAT. Jinak by omylem zadaný
     * příplatek nešlo vzít zpátky jinak než zásahem do databáze.
     */
    public function testStoredEntryStaysClearableWhenTheBasisDisappears(): void
    {
        $averageId = $this->approvedAverage(20_000);
        self::assertSame(200, $this->saveSurcharges(['night' => ['hours_milli' => 10_000]])->getStatusCode());
        $stored = $this->quickInput('PRIPLATEK_NOCNI');

        $this->db->pdo()->prepare(
            'UPDATE payroll_average_earning_snapshots SET status = "draft" WHERE id = ?'
        )->execute([$averageId]);

        $state = $this->listItem()['surcharges']['night'];
        self::assertFalse($state['available'], 'Podklad měl zmizet.');
        self::assertTrue($state['entry_available'], 'Uložený řádek přestal být editovatelný.');
        self::assertTrue($state['clear_only']);

        $cleared = $this->saveSurcharges(
            ['night' => ['hours_milli' => null]],
            ['night' => (int) $stored['row_version']],
        );
        self::assertSame(200, $cleared->getStatusCode(), (string) $cleared->getBody());
        self::assertNull($this->quickInput('PRIPLATEK_NOCNI'));
        self::assertNull($this->claimSource('night'));
    }

    /**
     * SMĚR 1 — nárok drží docházka, ruční zadání ho nesmí přebít.
     */
    public function testAttendanceClaimBlocksManualEntry(): void
    {
        $this->approvedAverage(20_000);
        $this->claimFor('night', 'time');

        $item = $this->listItem();
        self::assertTrue($item['surcharges']['night']['from_attendance']);
        self::assertFalse($item['surcharges']['night']['entry_available']);
        self::assertSame('claimed_by_attendance', $item['surcharges']['night']['unavailable_reason']);

        $response = $this->saveSurcharges(['night' => ['hours_milli' => 10_000]]);
        self::assertSame(409, $response->getStatusCode(), (string) $response->getBody());
        self::assertStringContainsString(
            'docházk',
            $this->json($response)['error']['message'],
        );
        self::assertNull($this->quickInput('PRIPLATEK_NOCNI'));
    }

    /**
     * SMĚR 2 — nárok drží ruční zadání, materializace z docházky ho nesmí
     * přebít. Zábrana musí pojmenovat DRUH, ne jen že něco koliduje.
     */
    public function testManualEntryBlocksMaterializationFromAttendance(): void
    {
        $this->approvedAverage(20_000);
        self::assertSame(200, $this->saveSurcharges(['night' => ['hours_milli' => 10_000]])->getStatusCode());
        // Docházka musí opravdu NĚJAKÉ noční minuty obsahovat. Bez nich by
        // materializace neměla co zapsat, skončila by jako no-op a test by
        // prošel z nesprávného důvodu.
        $this->timeEntry('regular', '2026-06-09 20:00:00', '2026-06-10 04:00:00');
        $this->timeEntry('night', '2026-06-09 20:00:00', '2026-06-10 04:00:00');
        $this->approvedTimeMonth();

        try {
            $this->materializer->materialize(
                $this->supplierId,
                $this->employmentId,
                self::PERIOD_START,
                $this->userId,
            );
            self::fail('Materializace z docházky přebila ruční zadání.');
        } catch (\MyInvoice\Service\Payroll\Time\Surcharge\PayrollSurchargeException $e) {
            self::assertStringContainsString('§ 116', $e->getMessage());
            self::assertStringContainsString('noční', mb_strtolower($e->getMessage(), 'UTF-8'));
        }
    }

    /** Bez práva schvalovat zůstává příplatek konceptem, ne schváleným vstupem. */
    public function testUserWithoutApprovalRightSavesDraftOnly(): void
    {
        $this->approvedAverage(20_000);

        $response = $this->action->save(
            $this->request('PUT', approve: false)->withParsedBody(
                $this->payload(['night' => ['hours_milli' => 10_000]], []),
            ),
            new Response(),
        );

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $input = $this->quickInput('PRIPLATEK_NOCNI');
        self::assertIsArray($input);
        self::assertSame('draft', (string) $input['status']);
    }

    /** Souběžná editace téhož druhu se pozná; nevyhrává poslední zapisovatel. */
    public function testStaleRowVersionIsRejected(): void
    {
        $this->approvedAverage(20_000);
        self::assertSame(200, $this->saveSurcharges(['night' => ['hours_milli' => 10_000]])->getStatusCode());

        $response = $this->saveSurcharges(
            ['night' => ['hours_milli' => 12_000]],
            ['night' => 99],
        );

        self::assertSame(409, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('row_version_conflict', $this->json($response)['error']['code']);
        self::assertSame(10_000, (int) $this->quickInput('PRIPLATEK_NOCNI')['quantity_milliunits']);
    }

    /** Sjednaná sazba pod kogentním minimem § 115 se z API uložit nesmí. */
    public function testAgreedHolidayRateBelowStatutoryFloorIsRejected(): void
    {
        // § 115 má zákonné minimum rovných 100 % průměrného výdělku, takže
        // sjednat 50 % je podlezení kogentní podlahy a nesmí projít ani z API.
        $response = $this->policies->create(
            $this->request('POST')->withParsedBody([
                'valid_from' => '2026-01-01',
                'overtime_mode' => 'surcharge',
                'holiday_mode' => 'surcharge',
                'holiday_rate_bp' => 5_000,
            ]),
            new Response(),
            ['id' => (string) $this->employmentId],
        );

        self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        self::assertStringContainsString(
            'zákonné minimum',
            $this->json($response)['error']['message'],
        );
    }

    /** § 116 naopak nižší sjednanou sazbu dovoluje — a musí ji použít. */
    public function testAgreedLowerNightRateIsLawfulAndApplied(): void
    {
        $this->approvedAverage(20_000);
        $created = $this->policies->create(
            $this->request('POST')->withParsedBody([
                'valid_from' => '2026-01-01',
                'overtime_mode' => 'surcharge',
                'holiday_mode' => 'compensatory_time_off',
                'night_rate_bp' => 500,
                'agreement_reference' => 'KS 2026/1',
            ]),
            new Response(),
            ['id' => (string) $this->employmentId],
        );
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());

        self::assertSame(200, $this->saveSurcharges(['night' => ['hours_milli' => 10_000]])->getStatusCode());
        // 200,00 Kč × 0,05 × 10 h = 100,00 Kč — polovina zákonné sazby.
        self::assertSame(10_000, (int) $this->quickInput('PRIPLATEK_NOCNI')['amount_minor']);
    }

    // ── scaffolding ─────────────────────────────────────────────────────────

    /**
     * @param array<string,array<string,mixed>> $surcharges
     * @param array<string,int|null> $versions
     */
    private function saveSurcharges(array $surcharges, array $versions = []): ResponseInterface
    {
        return $this->action->save(
            $this->request('PUT')->withParsedBody($this->payload($surcharges, $versions)),
            new Response(),
        );
    }

    /**
     * @param array<string,array<string,mixed>> $surcharges
     * @param array<string,int|null> $versions
     * @return array<string,mixed>
     */
    private function payload(array $surcharges, array $versions): array
    {
        return [
            'period' => self::PERIOD,
            'rows' => [[
                'employment_id' => $this->employmentId,
                'employment_row_version' => 1,
                'base_amount_minor' => 4_200_000,
                'overtime_mode' => 'amount',
                'overtime_hours_milli' => null,
                'overtime_amount_minor' => 0,
                'bonus_amount_minor' => 0,
                'surcharges' => $surcharges,
                'versions' => [
                    'base' => null,
                    'overtime' => null,
                    'bonus' => null,
                    'surcharges' => $versions,
                ],
            ]],
        ];
    }

    /** @return array<string,mixed> */
    private function listItem(): array
    {
        $response = $this->action->list(
            $this->request('GET')->withQueryParams(['period' => self::PERIOD]),
            new Response(),
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $month = PayrollTimeValue::row($this->json($response)['month'] ?? null, 'month');

        return PayrollTimeValue::row($month['items'][0] ?? null, 'item');
    }

    private function agreeHolidaySurcharge(): void
    {
        $response = $this->policies->create(
            $this->request('POST')->withParsedBody([
                'valid_from' => '2026-01-01',
                'overtime_mode' => 'surcharge',
                'holiday_mode' => 'surcharge',
                'agreement_reference' => 'Pracovní smlouva čl. IV',
            ]),
            new Response(),
            ['id' => (string) $this->employmentId],
        );
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
    }

    private function claimFor(string $kind, string $source): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_surcharge_period_claims
                (supplier_id, employment_id, period_start, surcharge_kind, claim_source)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$this->supplierId, $this->employmentId, self::PERIOD_START, $kind, $source]);
    }

    private function claimSource(string $kind): ?string
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT claim_source FROM payroll_surcharge_period_claims
              WHERE supplier_id = ? AND employment_id = ? AND period_start = ?
                AND surcharge_kind = ?'
        );
        $stmt->execute([$this->supplierId, $this->employmentId, self::PERIOD_START, $kind]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (string) $value;
    }

    private function timeEntry(string $category, string $startsAtUtc, string $endsAtUtc): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_time_entries
                (supplier_id, employment_id, series_key, revision_no, category,
                 starts_at_utc, ends_at_utc, timezone_name, break_minutes,
                 source_kind, source_hash, created_by)
             VALUES (?, ?, ?, 1, ?, ?, ?, "Europe/Prague", 0, "manual", ?, ?)'
        )->execute([
            $this->supplierId,
            $this->employmentId,
            bin2hex(random_bytes(16)),
            $category,
            $startsAtUtc,
            $endsAtUtc,
            random_bytes(32),
            $this->userId,
        ]);
    }

    /** Schválený měsíc docházky, aby materializace vůbec došla ke kontrole. */
    private function approvedTimeMonth(): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_time_months
                (supplier_id, employment_id, period_start, status, revision_no,
                 approved_by, approved_at)
             VALUES (?, ?, ?, "approved", 1, ?, "2026-07-01 08:00:00")'
        )->execute([
            $this->supplierId,
            $this->employmentId,
            self::PERIOD_START,
            $this->userId,
        ]);
    }

    private function approvedAverage(int $hourlyMinor): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_average_earning_snapshots
                (supplier_id, employment_id, applicable_year, applicable_quarter,
                 revision_no, source_kind, decisive_from, decisive_to,
                 gross_earnings_minor, longer_period_allocated_minor,
                 worked_minutes, worked_days, average_hourly_minor,
                 support_status, status, ruleset_id, ruleset_hash,
                 input_hash, input_trace)
             VALUES (?, ?, 2026, 2, 1, "actual", "2026-01-01", "2026-03-31",
                     1000000, 0, 6000, 21, ?,
                     "supported", "approved", "synthetic-2026",
                     REPEAT("a", 64), UNHEX(SHA2("synthetic", 256)), "{}")'
        )->execute([$this->supplierId, $this->employmentId, $hourlyMinor]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @return array<string,mixed>|null */
    private function quickInput(string $code): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT input.*
               FROM payroll_inputs input
               JOIN payroll_component_definitions component
                 ON component.supplier_id = input.supplier_id
                AND component.id = input.component_id
              WHERE input.supplier_id = ? AND input.employment_id = ?
                AND input.period_start = ? AND input.status <> "cancelled"
                AND input.external_id = ? AND component.code = ?'
        );
        $stmt->execute([
            $this->supplierId,
            $this->employmentId,
            self::PERIOD_START,
            'quick-monthly:' . $code,
            $code,
        ]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private function countInputs(string $code): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*)
               FROM payroll_inputs input
               JOIN payroll_component_definitions component
                 ON component.supplier_id = input.supplier_id
                AND component.id = input.component_id
              WHERE input.supplier_id = ? AND input.employment_id = ?
                AND input.period_start = ? AND input.status <> "cancelled"
                AND component.code = ?'
        );
        $stmt->execute([
            $this->supplierId,
            $this->employmentId,
            self::PERIOD_START,
            $code,
        ]);

        return (int) $stmt->fetchColumn();
    }

    private function employment(string $name, string $code): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, ?, "employee", "hpp", 1, 1, 0, 42000, 0, 1)'
        )->execute([$this->supplierId, $name]);
        $employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 start_date, actual_start_date, monthly_gross_minor,
                 is_legacy_projection)
             VALUES (?, ?, ?, "employment", "active",
                     "2026-01-01", "2026-01-01", 4200000, 0)'
        )->execute([$this->supplierId, $employeeId, $code]);

        return (int) $pdo->lastInsertId();
    }

    private function request(string $method, bool $approve = true): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest($method, '/api/payroll/quick-inputs')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, [
                'id' => $this->userId,
                'role' => $approve ? 'admin' : 'accountant',
            ])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session');
    }

    private function firstId(string $table): int
    {
        if (!in_array($table, ['supplier', 'users'], true)) {
            throw new \InvalidArgumentException('Nepodporovaná tabulka.');
        }
        $stmt = $this->db->pdo()->query("SELECT id FROM {$table} ORDER BY id LIMIT 1");
        if ($stmt === false) {
            throw new \RuntimeException("Tabulku {$table} nelze načíst.");
        }

        return (int) ($stmt->fetchColumn() ?: 0);
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
