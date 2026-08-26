<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollEmploymentAction;
use MyInvoice\Action\Payroll\PayrollPeopleAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\Payroll\PayrollEmploymentLifecycleSql;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class PayrollEmploymentLifecycleApiTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollEmploymentAction $action;
    private PayrollPeopleAction $people;
    private int $supplierId;
    private int $otherSupplierId;
    private int $employeeId;
    private int $otherEmployeeId;
    private int $officeId;
    private int $userId;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        $container = Bootstrap::buildApp()->getContainer();
        $this->db = $container->get(Connection::class);
        if (!$this->db->hasTable('payroll_employment_terms')) {
            $this->markTestSkipped('Migrace 1195 neproběhla.');
        }
        $this->action = $container->get(PayrollEmploymentAction::class);
        $this->people = $container->get(PayrollPeopleAction::class);

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query(
            'SELECT id FROM supplier ORDER BY id LIMIT 1'
        )->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query(
            'SELECT id FROM users ORDER BY id LIMIT 1'
        )->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }

        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->otherSupplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare(
            'UPDATE supplier SET payroll_enabled = 1 WHERE id IN (?, ?)'
        )->execute([$this->supplierId, $this->otherSupplierId]);

        $this->employeeId = $this->employee($this->supplierId, 'Testovací Pracovník');
        $this->otherEmployeeId = $this->employee($this->otherSupplierId, 'Cizí Pracovník');
        $office = $pdo->prepare(
            "INSERT INTO payroll_offices (supplier_id, code, name, is_active)
             VALUES (?, 'MAIN', 'Hlavní účtárna', 1)"
        );
        $office->execute([$this->supplierId]);
        $this->officeId = (int) $pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
    }

    public function testConcurrentRelationshipsHistoryLifecycleChecklistAndTimeline(): void
    {
        $hpp = $this->create($this->employeeId, 'HPP-1', 'employment', true);
        $dpp = $this->create($this->employeeId, 'DPP-1', 'dpp', false);

        self::assertSame('planned', $hpp['status']);
        self::assertTrue($hpp['is_primary']);
        self::assertSame('planned', $dpp['status']);
        self::assertCount(4, $hpp['checklist']);
        // Nástup jde potvrdit rovnou; předregistrace zůstává volbou pro budoucí nástup.
        self::assertSame(['preregistered', 'active', 'no_show'], $hpp['allowed_transitions']);

        $hpp = $this->transition($hpp, 'preregistered', '2026-01-01');
        $hpp = $this->transition($hpp, 'active', '2026-01-02');
        self::assertSame('2026-01-02', $hpp['actual_start_date']);

        $changed = $this->terms($hpp, [
            ...$this->termsPayload(true, '2026-02-01'),
            'weekly_hours' => '30',
            'workload_basis_points' => 7500,
            'work_place' => 'Hlavní město Praha',
            'jmhz_workplace_municipality_code' => '554782',
            'jmhz_workplace_country_code' => 'CZ',
            'jmhz_apz_contribution_status' => 'yes',
            'jmhz_apz_instrument_code' => '2',
            'jmhz_functional_benefits_status' => 'no',
            'jmhz_temporary_assignment_status' => 'yes',
            'activity_code' => '2',
            'change_reason' => 'Změna úvazku',
        ]);
        self::assertSame(4, $changed['row_version']);
        self::assertSame('2026-01-02', $changed['actual_start_date']);
        self::assertCount(2, $changed['terms']);
        self::assertSame('2026-01-31', $changed['terms'][1]['effective_to']);
        self::assertArrayHasKey('weekly_hours', $changed['timeline'][0]['diff']);
        self::assertSame('554782', $changed['terms'][0]['jmhz_workplace_municipality_code']);
        self::assertSame('yes', $changed['terms'][0]['jmhz_apz_contribution_status']);
        self::assertSame('yes', $changed['terms'][0]['jmhz_temporary_assignment_status']);
        self::assertSame('2', $changed['terms'][0]['activity_code']);
        self::assertSame('1', $changed['terms'][1]['jmhz_relationship_detail_code']);
        self::assertSame(
            'unverified',
            $changed['terms'][1]['jmhz_functional_benefits_status'],
        );
        self::assertArrayHasKey(
            'jmhz_workplace_municipality_code',
            $changed['timeline'][0]['diff'],
        );
        self::assertArrayHasKey(
            'activity_code',
            $changed['timeline'][0]['diff'],
        );
        self::assertCount(7, $changed['checklist']);

        $item = $changed['checklist'][0];
        $updated = $this->checklist(
            $changed,
            (string) $item['item_key'],
            (int) $item['row_version'],
            'completed',
        );
        $updatedItem = array_values(array_filter(
            $updated['checklist'],
            static fn (array $candidate): bool => $candidate['item_key'] === $item['item_key'],
        ))[0];
        self::assertSame('completed', $updatedItem['status']);
        self::assertSame(2, $updatedItem['row_version']);

        $ended = $this->transition($updated, 'ended', '2026-12-31');
        self::assertFalse($ended['is_primary']);
        self::assertCount(12, $ended['checklist']);
        $archived = $this->transition($ended, 'archived', '2027-01-02');
        self::assertSame('archived', $archived['status']);
        self::assertNotNull($archived['archived_at']);

        /*
         * Archiv není slepá ulička: nabídne se JEDINÝ návrat, a to do stavu,
         * ze kterého se archivovalo. Datum konce přitom zůstává — vrácení
         * z archivu je oprava omylu, ne nové ukončení.
         */
        self::assertSame(['ended'], $archived['allowed_transitions']);
        $restored = $this->transition($archived, 'ended', '2027-03-01');
        self::assertSame('ended', $restored['status']);
        self::assertNull($restored['archived_at']);
        self::assertSame('2026-12-31', $restored['end_date']);
        $archivedAgain = $this->transition($restored, 'archived', '2027-03-02');
        self::assertSame('archived', $archivedAgain['status']);
        $archived = $archivedAgain;
        $archivedTerms = $this->action->addTerms(
            $this->request(
                'PUT',
                "/api/payroll/employments/{$archived['id']}/terms",
                ['row_version' => $archived['row_version'], ...$this->termsPayload(false, '2027-02-01')],
            ),
            new Response(),
            ['id' => (string) $archived['id']],
        );
        self::assertSame(409, $archivedTerms->getStatusCode());

        $detail = $this->people->detail(
            $this->request('GET', "/api/payroll/people/{$this->employeeId}"),
            new Response(),
            ['id' => (string) $this->employeeId],
        );
        self::assertSame(200, $detail->getStatusCode());
        self::assertCount(2, $this->json($detail)['person']['employments']);
    }

    public function testNoShowAndOptimisticLockRejectInvalidMutation(): void
    {
        $employment = $this->create($this->employeeId, 'ZMR-1', 'small_scale_employment', true);

        /*
         * Nástup jde z plánovaného potvrdit rovnou a rovnou zapíše skutečné
         * datum nástupu — bez něj vztah vypadne z výplatní listiny.
         */
        $started = $this->transition($employment, 'active', '2026-01-01');
        self::assertSame('active', $started['status']);
        self::assertSame('2026-01-01', $started['actual_start_date']);

        // Zpátky do plánovaného ale nic nevede.
        $reverse = $this->action->transition(
            $this->request(
                'POST',
                "/api/payroll/employments/{$started['id']}/transitions/preregistered",
                ['row_version' => $started['row_version'], 'effective_on' => '2026-01-02'],
            ),
            new Response(),
            ['id' => (string) $started['id'], 'target' => 'preregistered'],
        );
        self::assertSame(409, $reverse->getStatusCode());
        self::assertSame('invalid_transition', $this->json($reverse)['error']['code']);

        $employment = $this->create($this->employeeId, 'ZMR-2', 'small_scale_employment', false);
        $noShow = $this->transition($employment, 'no_show', '2026-01-01');
        self::assertSame('no_show', $noShow['status']);

        $stale = $this->action->transition(
            $this->request(
                'POST',
                "/api/payroll/employments/{$employment['id']}/transitions/archived",
                ['row_version' => 1, 'effective_on' => '2026-01-02'],
            ),
            new Response(),
            ['id' => (string) $employment['id'], 'target' => 'archived'],
        );
        self::assertSame(409, $stale->getStatusCode());
        self::assertSame('row_version_conflict', $this->json($stale)['error']['code']);
    }

    /**
     * Kód se generuje sám a jde přejmenovat.
     *
     * Býval povinný a uživatel ho vymýšlel jako první pole formuláře, přestože
     * ho nepotřebuje žádný zákonný výstup. Po založení byl navíc neměnný — a to
     * u párovacího klíče CSV importu docházky znamenalo založit vztah znovu.
     */
    public function testEmploymentCodeIsGeneratedAndRenamable(): void
    {
        $first = $this->create($this->employeeId, '', 'employment', true);
        $second = $this->create($this->employeeId, '', 'dpp', false);

        self::assertSame('1', $first['code'], 'První vztah osoby dostane pořadové číslo 1.');
        self::assertSame('2', $second['code'], 'Druhý vztah pokračuje v řadě.');

        // Obsazené číslo se přeskočí — u převzatých osob existují vlastní kódy.
        $manual = $this->create($this->employeeId, '3', 'dpc', false);
        self::assertSame('3', $manual['code']);
        self::assertSame('4', $this->create($this->employeeId, '', 'dpc', false)['code']);

        $renamed = $this->action->rename(
            $this->request(
                'PATCH',
                "/api/payroll/employments/{$first['id']}/code",
                ['row_version' => $first['row_version'], 'code' => 'DOCHAZKA-7'],
            ),
            new Response(),
            ['id' => (string) $first['id']],
        );
        self::assertSame(200, $renamed->getStatusCode(), (string) $renamed->getBody());
        self::assertSame('DOCHAZKA-7', $this->json($renamed)['employment']['code']);

        $invalid = $this->action->rename(
            $this->request(
                'PATCH',
                "/api/payroll/employments/{$second['id']}/code",
                ['row_version' => $second['row_version'], 'code' => 'má mezeru'],
            ),
            new Response(),
            ['id' => (string) $second['id']],
        );
        self::assertSame(422, $invalid->getStatusCode());
    }

    public function testPrimaryUniquenessTenantBoundarySessionAndPermissionFailClosed(): void
    {
        $this->create($this->employeeId, 'HPP-PRIMARY', 'employment', true);
        $duplicatePrimary = $this->createResponse(
            $this->employeeId,
            'DPC-PRIMARY',
            'dpc',
            true,
        );
        self::assertSame(409, $duplicatePrimary->getStatusCode());

        $foreign = $this->createResponse(
            $this->otherEmployeeId,
            'FOREIGN',
            'dpp',
            false,
        );
        self::assertSame(404, $foreign->getStatusCode());

        $bearer = $this->createResponse(
            $this->employeeId,
            'BEARER',
            'dpp',
            false,
            'accountant',
            'bearer',
        );
        self::assertSame(403, $bearer->getStatusCode());
        self::assertSame('session_required', $this->json($bearer)['error']['code']);

        $client = $this->createResponse(
            $this->employeeId,
            'CLIENT',
            'dpp',
            false,
            'client',
        );
        self::assertSame(403, $client->getStatusCode());
        self::assertSame('forbidden', $this->json($client)['error']['code']);
    }

    public function testJmhzEvidenceOptionsComeFromPinnedPackageAndRequireSession(): void
    {
        $response = $this->action->jmhzEvidenceOptions(
            $this->request('GET', '/api/payroll/jmhz/employment-evidence-options'),
            new Response(),
        );
        self::assertSame(200, $response->getStatusCode());
        $options = $this->json($response)['options'];
        self::assertSame(64, strlen((string) $options['manifest_sha256']));
        self::assertCount(44, $options['activity_codes']);
        self::assertSame(
            ['1', '2', '3'],
            array_column($options['relationship_detail_codes'], 'code'),
        );
        self::assertSame(['1', '2', '3', '4'], array_column($options['apz_instruments'], 'code'));
        self::assertSame(250, count($options['countries']));
        self::assertSame('2026-12-31', $options['external_codebooks']['verified_through']);

        $municipalities = $this->action->jmhzMunicipalities(
            $this->request(
                'GET',
                '/api/payroll/jmhz/municipalities?q=Nymburk&limit=20',
            ),
            new Response(),
        );
        self::assertSame(200, $municipalities->getStatusCode());
        self::assertSame(
            [['code' => '537004', 'label' => 'Nymburk']],
            $this->json($municipalities)['items'],
        );

        $bearer = $this->action->jmhzEvidenceOptions(
            $this->request(
                'GET',
                '/api/payroll/jmhz/employment-evidence-options',
                null,
                'accountant',
                'bearer',
            ),
            new Response(),
        );
        self::assertSame(403, $bearer->getStatusCode());
        self::assertSame('session_required', $this->json($bearer)['error']['code']);
    }

    /** @return array<string,mixed> */
    private function create(
        int $employeeId,
        string $code,
        string $relationType,
        bool $primary,
    ): array {
        $response = $this->createResponse($employeeId, $code, $relationType, $primary);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        return $this->json($response)['employment'];
    }

    private function createResponse(
        int $employeeId,
        string $code,
        string $relationType,
        bool $primary,
        string $role = 'accountant',
        string $authMethod = 'session',
    ): Response {
        return $this->action->create(
            $this->request(
                'POST',
                "/api/payroll/people/{$employeeId}/employments",
                [
                    'code' => $code,
                    'relation_type' => $relationType,
                    'monthly_gross_minor' => 4000000,
                    'terms' => $this->termsPayload(
                        $primary,
                        '2026-01-01',
                        $relationType,
                    ),
                ],
                $role,
                $authMethod,
            ),
            new Response(),
            ['id' => (string) $employeeId],
        );
    }

    /** @param array<string,mixed> $employment
     *  @return array<string,mixed>
     */
    private function transition(array $employment, string $target, string $effectiveOn): array
    {
        $response = $this->action->transition(
            $this->request(
                'POST',
                "/api/payroll/employments/{$employment['id']}/transitions/{$target}",
                [
                    'row_version' => $employment['row_version'],
                    'effective_on' => $effectiveOn,
                ],
            ),
            new Response(),
            ['id' => (string) $employment['id'], 'target' => $target],
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        return $this->json($response)['employment'];
    }

    /**
     * Zpětná kompatibilita CZ-ISCO na úrovni API: uložený kód mimo klasifikaci
     * nesmí zablokovat uložení, dokud na něj uživatel nesáhne — ale nová
     * hodnota mimo číselník projít nesmí. Kód, který validátor toleruje, mu
     * dodává zápisová cesta z databáze, ne požadavek klienta.
     */
    public function testCzIscoBlocksNewCodesOutsideClassificationButKeepsStoredLegacyValue(): void
    {
        $employment = $this->create($this->employeeId, 'ISCO-1', 'employment', true);

        // Simulace dat z doby, kdy pole bylo volný text s kontrolou délky.
        $this->db->pdo()->prepare(
            'UPDATE payroll_employment_terms SET cz_isco_code = ?
              WHERE supplier_id = ? AND employment_id = ?'
        )->execute(['99999', $this->supplierId, (int) $employment['id']]);

        $kept = $this->terms($employment, [
            ...$this->termsPayload(true, '2026-03-01'),
            'cz_isco_code' => '99999',
            'weekly_hours' => '30',
            'workload_basis_points' => 7500,
            'change_reason' => 'Změna úvazku, CZ-ISCO beze změny',
        ]);
        self::assertSame('99999', $kept['terms'][0]['cz_isco_code']);

        $rejected = $this->action->addTerms(
            $this->request(
                'PUT',
                "/api/payroll/employments/{$employment['id']}/terms",
                [
                    'row_version' => $kept['row_version'],
                    ...$this->termsPayload(true, '2026-04-01'),
                    'cz_isco_code' => '43110',
                ],
            ),
            new Response(),
            ['id' => (string) $employment['id']],
        );
        self::assertSame(422, $rejected->getStatusCode());
        $message = $this->json($rejected)['error']['message'];
        self::assertStringContainsString('43110', $message, 'Hláška musí kód jmenovat.');
        self::assertStringContainsString('našeptávače', $message, 'Hláška musí poradit.');

        $accepted = $this->terms($kept, [
            ...$this->termsPayload(true, '2026-05-01'),
            'cz_isco_code' => '24111',
            'change_reason' => 'Kód z číselníku',
        ]);
        self::assertSame('24111', $accepted['terms'][0]['cz_isco_code']);
    }

    /**
     * Zpětně potvrzený nástup nesmí zůstat schovaný pod událostí založení.
     *
     * Vztah převzatý z jiného zpracování se do systému zapisuje až po nástupu:
     * založení nese dnešní datum, nástup se potvrzuje zpětně. Efektivní stav
     * se přitom čte jako poslední událost podle `effective_on`, takže vztah
     * dál vycházel jako `planned` — v seznamu lidí svítil aktivní (ten čte
     * sloupec `status`), ale z rychlých vstupů, docházky i z karet zaměstnanců
     * na přehledu mezd vypadl.
     */
    public function testBackdatedStartMovesCreatedEventSoEmploymentReadsActive(): void
    {
        $response = $this->action->create(
            $this->request(
                'POST',
                "/api/payroll/people/{$this->employeeId}/employments",
                [
                    'code' => 'PREVZATY-1',
                    'relation_type' => 'statutory_body',
                    'monthly_gross_minor' => 4500000,
                    'terms' => [
                        ...$this->termsPayload(true, '2026-08-16'),
                        'planned_start_on' => '2025-04-01',
                        'fixed_term_end_on' => null,
                    ],
                ],
            ),
            new Response(),
            ['id' => (string) $this->employeeId],
        );
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $employment = $this->json($response)['employment'];
        $employmentId = (int) $employment['id'];
        self::assertSame('2026-08-16', $this->createdEventDate($employmentId));

        $active = $this->transition($employment, 'active', '2025-04-01');
        self::assertSame('active', $active['status']);
        self::assertSame('2025-04-01', $active['actual_start_date']);
        self::assertSame(
            '2025-04-01',
            $this->createdEventDate($employmentId),
            'Založení musí ustoupit před zpětně datovaný nástup.',
        );
        self::assertSame('active', $this->effectiveStatusAt($employmentId, '2026-08-31'));
        // Před nástupem vztah žádný stav nemá — oprava časové osy nesmí
        // z minulosti udělat aktivní vztah dřív, než podle evidence začal.
        self::assertNull($this->effectiveStatusAt($employmentId, '2025-03-31'));
    }

    /**
     * Předkontace na kartě musí být ta, na kterou se mzda opravdu zaúčtuje.
     *
     * Firmy si účty přenastavují (typicky celou mzdu na jednu analytiku), jenže
     * karta ukazovala obecné defaulty z číselníku — u statutárního orgánu tvrdila
     * „523/366", zatímco běh účtoval podle nastavení zaměstnavatele. Rozdíl se
     * dal zjistit až v deníku po zaúčtování.
     */
    public function testEmploymentCardShowsAccountsTheEmployerActuallyPostsTo(): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employer_settings
                (supplier_id, default_office_id, statutory_gross_debit_account,
                 statutory_gross_credit_account, employer_insurance_debit_account)
             VALUES (?, ?, "521.100", "331.100", "524.100")'
        )->execute([$this->supplierId, $this->officeId]);

        $employment = $this->create($this->employeeId, 'UCTY-1', 'statutory_body', true);

        self::assertSame(
            ['gross_debit' => '521.100', 'gross_credit' => '331.100'],
            [
                'gross_debit' => $employment['accounting']['gross_debit'],
                'gross_credit' => $employment['accounting']['gross_credit'],
            ],
            'Karta nesmí ukazovat 523/366, když firma účtuje jinam.',
        );
        self::assertSame('524.100', $employment['accounting']['employer_insurance_debit']);
    }

    /**
     * Mzdová účtárna je u vztahu povinná, ale formulář ji nenabízí. Zápisová
     * cesta ji proto doplní z výchozí účtárny zaměstnavatele — jinak by vztah
     * bez účtárny prošel až ke kontrolním součtům schválení.
     */
    public function testEmploymentWithoutOfficeTakesTheEmployerDefault(): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employer_settings
                (supplier_id, default_office_id, statutory_gross_debit_account,
                 statutory_gross_credit_account, employer_insurance_debit_account)
             VALUES (?, ?, "521.100", "331.100", "524.100")'
        )->execute([$this->supplierId, $this->officeId]);

        $employment = $this->createWithoutOffice('UCT-DEF');

        self::assertSame($this->officeId, $employment['office_id']);
        self::assertSame($this->officeId, $employment['terms'][0]['office_id']);
    }

    /**
     * Když se účtárna nemá odkud vzít, musí to být pojmenovaná překážka při
     * zakládání vztahu — ne mlčení, které se ozve až uzávěrkou.
     */
    public function testEmploymentWithoutAnyOfficeIsRefusedByName(): void
    {
        $response = $this->action->create(
            $this->request(
                'POST',
                "/api/payroll/people/{$this->employeeId}/employments",
                [
                    'code' => 'UCT-NONE',
                    'relation_type' => 'employment',
                    'monthly_gross_minor' => 4000000,
                    'terms' => ['office_id' => null]
                        + $this->termsPayload(true, '2026-01-01'),
                ],
            ),
            new Response(),
            ['id' => (string) $this->employeeId],
        );

        self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        self::assertStringContainsString(
            'mzdové účtárny',
            (string) $response->getBody(),
        );
    }

    /** @return array<string,mixed> */
    private function createWithoutOffice(string $code): array
    {
        $response = $this->action->create(
            $this->request(
                'POST',
                "/api/payroll/people/{$this->employeeId}/employments",
                [
                    'code' => $code,
                    'relation_type' => 'employment',
                    'monthly_gross_minor' => 4000000,
                    'terms' => ['office_id' => null]
                        + $this->termsPayload(true, '2026-01-01'),
                ],
            ),
            new Response(),
            ['id' => (string) $this->employeeId],
        );
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return $this->json($response)['employment'];
    }

    private function createdEventDate(int $employmentId): string
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT effective_on
               FROM payroll_employment_events
              WHERE supplier_id = ? AND employment_id = ? AND event_type = 'created'"
        );
        $stmt->execute([$this->supplierId, $employmentId]);

        return (string) $stmt->fetchColumn();
    }

    /** Stav se čte TOUTÉŽ cestou jako rychlé vstupy, docházka i karty na přehledu. */
    private function effectiveStatusAt(int $employmentId, string $date): ?string
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . PayrollEmploymentLifecycleSql::effectiveStatusAtPlaceholder() . '
               FROM payroll_employments employment
              WHERE employment.supplier_id = ? AND employment.id = ?'
        );
        $stmt->execute([$date, $this->supplierId, $employmentId]);
        $value = $stmt->fetchColumn();

        return is_string($value) ? $value : null;
    }

    /** @param array<string,mixed> $employment
     *  @param array<string,mixed> $payload
     *  @return array<string,mixed>
     */
    private function terms(array $employment, array $payload): array
    {
        $response = $this->action->addTerms(
            $this->request(
                'PUT',
                "/api/payroll/employments/{$employment['id']}/terms",
                ['row_version' => $employment['row_version'], ...$payload],
            ),
            new Response(),
            ['id' => (string) $employment['id']],
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        return $this->json($response)['employment'];
    }

    /** @param array<string,mixed> $employment
     *  @return array<string,mixed>
     */
    private function checklist(
        array $employment,
        string $itemKey,
        int $rowVersion,
        string $status,
    ): array {
        $response = $this->action->checklist(
            $this->request(
                'PUT',
                "/api/payroll/employments/{$employment['id']}/checklist/{$itemKey}",
                ['row_version' => $rowVersion, 'status' => $status],
            ),
            new Response(),
            ['id' => (string) $employment['id'], 'item_key' => $itemKey],
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        return $this->json($response)['employment'];
    }

    /** @return array<string,mixed> */
    private function termsPayload(
        bool $primary,
        string $effectiveFrom,
        string $relationType = 'employment',
    ): array
    {
        [$activityCode, $relationshipDetailCode] = match ($relationType) {
            'dpc' => ['A', '1'],
            'dpp' => ['T', '1'],
            default => ['1', '1'],
        };

        return [
            'office_id' => $this->officeId,
            'effective_from' => $effectiveFrom,
            'contract_signed_on' => '2025-12-20',
            'planned_start_on' => '2026-01-01',
            'actual_start_on' => null,
            'fixed_term_end_on' => '2026-12-31',
            'weekly_hours' => '40',
            'workload_basis_points' => 10000,
            'work_place' => 'Praha',
            'regular_workplace' => 'Praha',
            'jmhz_workplace_municipality_code' => null,
            'jmhz_workplace_country_code' => null,
            'jmhz_apz_contribution_status' => 'unverified',
            'jmhz_apz_instrument_code' => null,
            'jmhz_functional_benefits_status' => 'unverified',
            'jmhz_temporary_assignment_status' => 'unverified',
            'cz_isco_code' => '43111',
            'activity_code' => $activityCode,
            'jmhz_relationship_detail_code' => $relationshipDetailCode,
            'social_insurance_participation' => 'automatic',
            'health_insurance_participation' => 'automatic',
            'tax_regime' => 'advance',
            'foreign_legislation_country_code' => null,
            'a1_certificate_until' => null,
            'risky_work' => false,
            'tax_declaration_signed' => true,
            'is_primary' => $primary,
            'change_reason' => 'Testovací podmínky',
        ];
    }

    /** @param array<string,mixed>|null $body */
    private function request(
        string $method,
        string $path,
        ?array $body = null,
        string $role = 'accountant',
        string $authMethod = 'session',
    ): \Psr\Http\Message\ServerRequestInterface {
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, $path)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => $role])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, $authMethod);
        return $body === null ? $request : $request->withParsedBody($body);
    }

    /** @return array<string,mixed> */
    private function json(Response $response): array
    {
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded);
        return $decoded;
    }

    private function employee(int $supplierId, string $name): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, ?, ?, ?, 0, 0, 0, NULL, 0, 1)'
        );
        $stmt->execute([$supplierId, $name, 'employee', 'hpp']);
        return (int) $this->db->pdo()->lastInsertId();
    }
}
