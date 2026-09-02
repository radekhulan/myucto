<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollPeopleAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\Payroll\PayrollPersonProfileRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveField;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class PayrollPeopleApiTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollPeopleAction $action;
    private PayrollSensitiveData $sensitiveData;
    private PayrollPersonProfileRepository $profiles;
    private int $userId;
    private int $supplierId;
    private int $otherSupplierId;
    private int $employeeId;
    private int $otherEmployeeId;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }

        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db = $container->get(Connection::class);
            $this->action = $container->get(PayrollPeopleAction::class);
            $this->sensitiveData = $container->get(PayrollSensitiveData::class);
            $this->profiles = $container->get(PayrollPersonProfileRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        if (!$this->db->hasTable('payroll_employee_profiles')
            || !$this->db->hasTable('payroll_employments')) {
            $this->markTestSkipped('Migrace 1188 neproběhla.');
        }

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query(
            'SELECT id FROM supplier ORDER BY id LIMIT 1'
        )->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query(
            'SELECT id FROM users ORDER BY id LIMIT 1'
        )->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí supplier nebo uživatel.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->otherSupplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare(
            "UPDATE supplier
                SET payroll_enabled = 1, accounting_mode = 'double_entry'
              WHERE id IN (?, ?)"
        )->execute([$this->supplierId, $this->otherSupplierId]);

        $insertEmployee = $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, ?, ?, ?, 1, 1, 0, ?, 0, 1)'
        );
        $insertEmployee->execute([
            $this->supplierId,
            'Testovací zaměstnanec',
            'managing_partner',
            'hpp',
            42_000,
        ]);
        $this->employeeId = (int) $pdo->lastInsertId();
        $insertEmployee->execute([
            $this->otherSupplierId,
            'Cizí testovací zaměstnanec',
            'employee',
            'hpp',
            35_000,
        ]);
        $this->otherEmployeeId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            "INSERT INTO payroll_offices (supplier_id, code, name, is_active)
             VALUES (?, 'HLAVNI', 'Hlavní účtárna', 1)"
        )->execute([$this->supplierId]);
        $pdo->prepare(
            'INSERT INTO payroll_employer_settings (supplier_id, default_office_id)
             VALUES (?, ?)'
        )->execute([$this->supplierId, (int) $pdo->lastInsertId()]);

        $pdo->prepare(
            "INSERT INTO payroll_employee_profiles (supplier_id, employee_id, profile_status)
             VALUES (?, ?, 'legacy')"
        )->execute([$this->supplierId, $this->employeeId]);
        $pdo->prepare(
            "INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 monthly_gross_minor, is_legacy_projection)
             VALUES (?, ?, 'legacy', 'partner_dependent', 'active', 4200000, 1)"
        )->execute([$this->supplierId, $this->employeeId]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
    }

    public function testLegacyEmployeeIsReturnedOnceInBothAccountingModesWithoutSensitiveData(): void
    {
        foreach (['double_entry', 'tax_evidence'] as $mode) {
            $this->db->pdo()->prepare(
                'UPDATE supplier SET accounting_mode = ? WHERE id = ?'
            )->execute([$mode, $this->supplierId]);

            $listResponse = $this->action->list(
                $this->request('GET', '/api/payroll/people', 'accountant'),
                new Response(),
            );
            self::assertSame(200, $listResponse->getStatusCode());
            $list = $this->json($listResponse);
            self::assertCount(1, $list['items']);
            self::assertSame($this->employeeId, $list['items'][0]['id']);
            self::assertSame(['partner_dependent'], $list['items'][0]['relation_types']);
            /*
             * Rychlé akce v řádku seznamu se zužují na `employment_id`, ne na
             * osobu. Kdyby seznam vztahy nenesl, musel by si je dotáhnout dotazem
             * na každý řádek — přehled o padesáti lidech by udělal padesát
             * požadavků navíc.
             */
            $refs = $list['items'][0]['employment_refs'];
            self::assertCount(1, $refs);
            self::assertSame('partner_dependent', $refs[0]['relation_type']);
            self::assertIsInt($refs[0]['id']);
            self::assertIsBool($refs[0]['is_primary']);
            self::assertSame('legacy', $list['items'][0]['profile_status']);
            $this->assertNoSensitiveFields($list);

            $detailResponse = $this->action->detail(
                $this->request('GET', "/api/payroll/people/{$this->employeeId}", 'accountant'),
                new Response(),
                ['id' => (string) $this->employeeId],
            );
            self::assertSame(200, $detailResponse->getStatusCode());
            $detail = $this->json($detailResponse);
            self::assertSame($this->employeeId, $detail['person']['id']);
            self::assertCount(1, $detail['person']['employments']);
            self::assertSame([
                'gross_debit' => '522',
                'gross_credit' => '366',
                'employer_insurance_debit' => '524',
                'employer_insurance_credit' => '336',
            ], $detail['person']['employments'][0]['accounting']);
            $this->assertNoSensitiveFields($detail);
        }
    }

    public function testDetailCannotCrossTenantBoundary(): void
    {
        $response = $this->action->detail(
            $this->request('GET', "/api/payroll/people/{$this->otherEmployeeId}", 'accountant'),
            new Response(),
            ['id' => (string) $this->otherEmployeeId],
        );

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('not_found', $this->json($response)['error']['code']);
    }

    public function testListUsesCurrentIdentityHistoryWithoutRewritingLegacyEmployee(): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_person_identity_history
                (supplier_id, employee_id, full_name, effective_from)
             VALUES (?, ?, ?, ?)'
        )->execute([
            $this->supplierId,
            $this->employeeId,
            'Aktuální Testovací',
            '2000-01-01',
        ]);

        $response = $this->action->list(
            $this->request('GET', '/api/payroll/people', 'accountant'),
            new Response(),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('Aktuální Testovací', $this->json($response)['items'][0]['full_name']);
        $legacy = $this->db->pdo()->prepare(
            'SELECT full_name FROM payroll_employees WHERE supplier_id = ? AND id = ?'
        );
        $legacy->execute([$this->supplierId, $this->employeeId]);
        self::assertSame('Testovací zaměstnanec', $legacy->fetchColumn());
    }

    public function testListIncludesEmployeeWithoutProfileOrEmployment(): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, ?, ?, ?, 0, 0, 0, NULL, 0, 1)'
        )->execute([
            $this->supplierId,
            'Testovací zaměstnanec bez profilu',
            'employee',
            'dpp',
        ]);
        $employeeWithoutProfileId = (int) $this->db->pdo()->lastInsertId();

        $response = $this->action->list(
            $this->request('GET', '/api/payroll/people', 'accountant'),
            new Response(),
        );
        self::assertSame(200, $response->getStatusCode());
        $items = $this->json($response)['items'];
        self::assertCount(2, $items);
        $byId = array_column($items, null, 'id');
        self::assertArrayHasKey($employeeWithoutProfileId, $byId);
        self::assertSame('missing', $byId[$employeeWithoutProfileId]['profile_status']);
        self::assertSame(0, $byId[$employeeWithoutProfileId]['employment_count']);
        self::assertSame([], $byId[$employeeWithoutProfileId]['relation_types']);
        self::assertTrue($byId[$employeeWithoutProfileId]['needs_setup']);
    }

    public function testEndpointAllowsAuthorizedBearerAndRejectsClientAndDisabledPayroll(): void
    {
        $clientResponse = $this->action->list(
            $this->request('GET', '/api/payroll/people', 'client'),
            new Response(),
        );
        self::assertSame(403, $clientResponse->getStatusCode());
        self::assertSame('forbidden', $this->json($clientResponse)['error']['code']);

        $bearerResponse = $this->action->list(
            $this->request('GET', '/api/payroll/people', 'accountant', 'bearer'),
            new Response(),
        );
        self::assertSame(200, $bearerResponse->getStatusCode());
        self::assertArrayHasKey('items', $this->json($bearerResponse));

        $this->db->pdo()->prepare(
            'UPDATE supplier SET payroll_enabled = 0 WHERE id = ?'
        )->execute([$this->supplierId]);
        $disabledResponse = $this->action->list(
            $this->request('GET', '/api/payroll/people', 'accountant'),
            new Response(),
        );
        self::assertSame(403, $disabledResponse->getStatusCode());
        self::assertSame('payroll_disabled', $this->json($disabledResponse)['error']['code']);
    }

    public function testCreateAtomicallyAddsSharedEmployeeProfileAndFirstEmployment(): void
    {
        $this->db->pdo()->prepare(
            "UPDATE supplier SET accounting_mode = 'tax_evidence' WHERE id = ?"
        )->execute([$this->supplierId]);

        $response = $this->action->create(
            $this->request(
                'POST',
                '/api/payroll/people',
                'readonly',
                'session',
                [
                    'full_name' => 'Nová Testovací',
                    'birth_date' => '1990-04-12',
                    'birth_number' => '9004121236',
                    'relation_type' => 'dpp',
                    'planned_start_on' => '2026-08-10',
                    'monthly_gross' => 12_500,
                ],
                new EffectiveRole(
                    42,
                    'Personalista',
                    'staff',
                    true,
                    ['payroll.person.write' => AccessLevel::WRITE->value],
                ),
            ),
            new Response(),
        );

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $person = $this->json($response)['person'];
        self::assertSame('Nová Testovací', $person['full_name']);
        self::assertSame('setup', $person['profile_status']);
        self::assertSame(1, $person['employment_count']);
        self::assertSame(['dpp'], $person['relation_types']);
        self::assertCount(1, $person['employments']);
        self::assertSame('ZAM-' . $person['id'], $person['employments'][0]['code']);
        self::assertSame(1_250_000, $person['employments'][0]['monthly_gross_minor']);
        self::assertSame('2026-08-10', $person['employments'][0]['terms'][0]['planned_start_on']);
        self::assertTrue($person['employments'][0]['is_primary']);
        $this->assertNoSensitiveFields(['person' => $person]);

        $employee = $this->db->pdo()->prepare(
            'SELECT taxpayer_type, employment_type, monthly_gross
               FROM payroll_employees
              WHERE supplier_id = ? AND id = ?'
        );
        $employee->execute([$this->supplierId, $person['id']]);
        self::assertSame([
            'taxpayer_type' => 'employee',
            'employment_type' => 'dpp',
            'monthly_gross' => 12_500,
        ], $employee->fetch(\PDO::FETCH_ASSOC));
    }

    /**
     * Rodné číslo zadané při založení se dřív zahodilo: validátor ho vrátil, ale
     * `PayrollEmployeeRepository::insert()` ho (správně) nezná a nikdo jiný ho
     * nezapisoval. Patří výhradně do šifrovaného `payroll_person_identifiers`,
     * odkud ho čtou podání i mzdový list — legacy sloupec na kartě zůstává prázdný.
     */
    public function testCreateSealsBirthNumberIntoEncryptedIdentifier(): void
    {
        $response = $this->action->create(
            $this->request(
                'POST',
                '/api/payroll/people',
                'accountant',
                'session',
                [
                    ...$this->validCreatePayload('Rodné Číslo'),
                    // Nekanonický tvar z formuláře musí dojít jako RRMMDD/XXXX.
                    'birth_number' => '900412 1236',
                ],
            ),
            new Response(),
        );

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $personId = $this->json($response)['person']['id'];

        $identifier = $this->db->pdo()->prepare(
            'SELECT id, identifier_type, value_ciphertext, value_hash, value_masked
               FROM payroll_person_identifiers
              WHERE supplier_id = ? AND employee_id = ?'
        );
        $identifier->execute([$this->supplierId, $personId]);
        $rows = $identifier->fetchAll(\PDO::FETCH_ASSOC);
        self::assertCount(1, $rows);
        self::assertSame('birth_number', $rows[0]['identifier_type']);
        self::assertStringStartsWith('enc:v2:', $rows[0]['value_ciphertext']);
        self::assertStringNotContainsString('1236', $rows[0]['value_masked']);
        self::assertSame(
            $this->sensitiveData->lookupHash(
                '900412/1236',
                PayrollSensitiveField::PERSONAL_IDENTIFIER,
                $this->supplierId,
            ),
            $rows[0]['value_hash'],
        );

        // Otevřený legacy sloupec se nesmí naplnit ani omylem (W1/P-02).
        $legacy = $this->db->pdo()->prepare(
            'SELECT birth_number FROM payroll_employees WHERE supplier_id = ? AND id = ?'
        );
        $legacy->execute([$this->supplierId, $personId]);
        self::assertNull($legacy->fetchColumn());
    }

    public function testCreateRejectsBirthNumberThatSubmissionsWouldRefuse(): void
    {
        $response = $this->action->create(
            $this->request(
                'POST',
                '/api/payroll/people',
                'accountant',
                'session',
                [
                    ...$this->validCreatePayload('Neplatné Číslo'),
                    'birth_number' => '9004121234',
                ],
            ),
            new Response(),
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(
            'Rodné číslo neprošlo kontrolou modulo 11.',
            $this->json($response)['error']['message'],
        );
    }

    /**
     * Nová osoba dřív neměla jedinou verzi historické identity, takže měsíční
     * hlášení i prvotní registrace končily na „K rozhodnému datu chybí historická
     * identita osoby." — účetní to musela ručně doplnit na kartě.
     */
    public function testCreateSeedsIdentityHistoryEffectiveFromTheHireDate(): void
    {
        $response = $this->action->create(
            $this->request(
                'POST',
                '/api/payroll/people',
                'accountant',
                'session',
                [
                    ...$this->validCreatePayload('Historická Identita'),
                    'birth_date' => '1988-06-01',
                    'planned_start_on' => '2026-08-10',
                ],
            ),
            new Response(),
        );

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $personId = $this->json($response)['person']['id'];

        $identity = $this->db->pdo()->prepare(
            'SELECT full_name, first_name, last_name, birth_date,
                    effective_from, effective_to
               FROM payroll_person_identity_history
              WHERE supplier_id = ? AND employee_id = ?'
        );
        $identity->execute([$this->supplierId, $personId]);
        self::assertSame([[
            'full_name' => 'Historická Identita',
            // Rozpad na křestní a příjmení se NEDOMÝŠLÍ (migrace 1272) — kdo je
            // ve svém požadavku nepošle (starší klient, API token), dostane NULL
            // a osoba svítí „vyžaduje doplnění", dokud je účetní nedoplní.
            'first_name' => null,
            'last_name' => null,
            'birth_date' => '1988-06-01',
            'effective_from' => '2026-08-10',
            'effective_to' => null,
        ]], $identity->fetchAll(\PDO::FETCH_ASSOC));

        /*
         * Nerozdělené jméno musí projít na kartu jako NULL, ne jako prázdný
         * řetězec — karta podle toho pozná, že se jméno doplňuje do TÉTO verze,
         * místo aby ji nahradila novou od dneška a nechala celé období od
         * nástupu bez jména.
         */
        $card = $this->profiles->get($this->supplierId, $personId);
        self::assertNotNull($card);
        self::assertNull($card['identity_history'][0]['first_name']);
        self::assertNull($card['identity_history'][0]['last_name']);
    }

    /**
     * Zakládací formulář vybírá křestní jméno a příjmení ZVLÁŠŤ a musí dojít až
     * do historické identity.
     *
     * Dřív sem šlo jen celé jméno, obě části zůstaly NULL a měsíční JMHZ hlásilo
     * „Historická identita nemá explicitní jméno a příjmení" — účetní je musela
     * u každé nové osoby doplňovat ručně na kartě. Rozpad `full_name` na serveru
     * je pořád zakázaný (migrace 1272), zapisuje se JEN to, co přišlo.
     */
    public function testCreateSeedsIdentityWithTheSubmittedFirstAndLastName(): void
    {
        $response = $this->action->create(
            $this->request(
                'POST',
                '/api/payroll/people',
                'accountant',
                'session',
                [
                    ...$this->validCreatePayload('Jan Novák'),
                    'first_name' => ' Jan ',
                    'last_name' => ' Novák ',
                ],
            ),
            new Response(),
        );

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $personId = $this->json($response)['person']['id'];

        $identity = $this->db->pdo()->prepare(
            'SELECT full_name, first_name, last_name
               FROM payroll_person_identity_history
              WHERE supplier_id = ? AND employee_id = ?'
        );
        $identity->execute([$this->supplierId, $personId]);
        self::assertSame([[
            'full_name' => 'Jan Novák',
            'first_name' => 'Jan',
            'last_name' => 'Novák',
        ]], $identity->fetchAll(\PDO::FETCH_ASSOC));

        // Karta osoby vidí totéž — účetní už nemá co doplňovat.
        $card = $this->profiles->get($this->supplierId, $personId);
        self::assertNotNull($card);
        self::assertSame('Jan', $card['identity_history'][0]['first_name']);
        self::assertSame('Novák', $card['identity_history'][0]['last_name']);
    }

    /** Verze identity nesmí začít v budoucnu, i když je nástup naplánovaný dopředu. */
    public function testCreateClampsSeededIdentityToTodayForAFutureHire(): void
    {
        $futureStart = (new \DateTimeImmutable('today'))->modify('+30 days')->format('Y-m-d');
        $response = $this->action->create(
            $this->request(
                'POST',
                '/api/payroll/people',
                'accountant',
                'session',
                [
                    ...$this->validCreatePayload('Budoucí Nástup'),
                    'planned_start_on' => $futureStart,
                ],
            ),
            new Response(),
        );

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $identity = $this->db->pdo()->prepare(
            'SELECT effective_from FROM payroll_person_identity_history
              WHERE supplier_id = ? AND employee_id = ?'
        );
        $identity->execute([$this->supplierId, $this->json($response)['person']['id']]);
        self::assertSame(date('Y-m-d'), $identity->fetchColumn());
    }

    /**
     * Úvazek se dosazoval natvrdo na 100 %, takže dvacetihodinový úvazek platil
     * hned po založení za plný — a na tom stojí zákaz nařízeného přesčasu
     * u kratší pracovní doby (§ 78 odst. 1 písm. i) ZP).
     */
    public function testCreateDerivesWorkloadFromTheAgreedWeeklyHours(): void
    {
        $response = $this->action->create(
            $this->request(
                'POST',
                '/api/payroll/people',
                'accountant',
                'session',
                [
                    ...$this->validCreatePayload('Zkrácený Úvazek'),
                    'weekly_hours' => '20.00',
                ],
            ),
            new Response(),
        );

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $terms = $this->db->pdo()->prepare(
            'SELECT term.weekly_hours, term.workload_basis_points
               FROM payroll_employment_terms term
               JOIN payroll_employments employment
                 ON employment.supplier_id = term.supplier_id
                AND employment.id = term.employment_id
              WHERE term.supplier_id = ? AND employment.employee_id = ?'
        );
        $terms->execute([$this->supplierId, $this->json($response)['person']['id']]);
        self::assertSame([[
            'weekly_hours' => '20.00',
            'workload_basis_points' => 5000,
        ]], array_map(
            static fn (array $row): array => [
                'weekly_hours' => (string) $row['weekly_hours'],
                'workload_basis_points' => (int) $row['workload_basis_points'],
            ],
            $terms->fetchAll(\PDO::FETCH_ASSOC),
        ));
    }

    public function testCreateRollsBackSharedEmployeeWhenEmploymentOfficeIsForeign(): void
    {
        $office = $this->db->pdo()->prepare(
            "INSERT INTO payroll_offices (supplier_id, code, name, is_active)
             VALUES (?, 'FOREIGN', 'Cizí účtárna', 1)"
        );
        $office->execute([$this->otherSupplierId]);
        $foreignOfficeId = (int) $this->db->pdo()->lastInsertId();

        $response = $this->action->create(
            $this->request(
                'POST',
                '/api/payroll/people',
                'accountant',
                'session',
                [
                    'full_name' => 'Rollback Testovací',
                    'relation_type' => 'employment',
                    'planned_start_on' => '2026-08-10',
                    'office_id' => $foreignOfficeId,
                ],
            ),
            new Response(),
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertSame([
            'code' => 'validation_failed',
            'message' => 'Mzdová účtárna neexistuje nebo není aktivní.',
        ], $this->json($response)['error']);
        $count = $this->db->pdo()->prepare(
            'SELECT COUNT(*)
               FROM payroll_employees
              WHERE supplier_id = ? AND full_name = ?'
        );
        $count->execute([$this->supplierId, 'Rollback Testovací']);
        self::assertSame(0, (int) $count->fetchColumn());
    }

    /**
     * Zdravotní pojišťovna je zákonná evidence osoby — musí vzniknout TÝMŽ
     * požadavkem jako zaměstnanec. Dřív ji dopisoval prohlížeč druhým voláním
     * a jeho selhání skončilo jen varovným toastem: osoba zůstala bez ní.
     */
    public function testCreateWritesHealthInsurerEvidenceInTheSameRequest(): void
    {
        if (!$this->db->hasTable('payroll_person_health_coverage_history')) {
            self::markTestSkipped('Migrace zákonné evidence osoby neproběhla.');
        }

        $response = $this->action->create(
            $this->request(
                'POST',
                '/api/payroll/people',
                'accountant',
                'session',
                [
                    ...$this->validCreatePayload('Pojištěná Testovací'),
                    'planned_start_on' => '2026-09-15',
                    'health_insurer_code' => '111',
                ],
            ),
            new Response(),
        );

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $personId = $this->json($response)['person']['id'];

        $coverage = $this->db->pdo()->prepare(
            'SELECT jurisdiction, insurer_status, insurer_code,
                    insurer_evidence_reference, effective_from, effective_to
               FROM payroll_person_health_coverage_history
              WHERE supplier_id = ? AND employee_id = ?'
        );
        $coverage->execute([$this->supplierId, $personId]);
        self::assertSame([
            'jurisdiction' => 'czech_regime_verified',
            'insurer_status' => 'verified',
            'insurer_code' => '111',
            'insurer_evidence_reference' => null,
            // Evidence se vede po celých měsících, nástup 15. 9. tedy začíná 1. 9.
            'effective_from' => '2026-09-01',
            'effective_to' => null,
        ], $coverage->fetch(\PDO::FETCH_ASSOC));

        // Auditní stopa musí být táž jako u panelu Zákonná evidence.
        $log = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM activity_log
              WHERE supplier_id = ? AND entity_id = ? AND action = ?'
        );
        $log->execute([
            $this->supplierId,
            $personId,
            'payroll.person_statutory_evidence.saved',
        ]);
        self::assertSame(1, (int) $log->fetchColumn());
    }

    /**
     * Neznámý kód pojišťovny nesmí nechat vzniknout ani zaměstnance. Kdyby to
     * byla dvě volání, osoba by existovala bez zákonné evidence — a kód `999`
     * je přesně ten případ, kvůli kterému číselník `HealthInsurers` vznikl.
     */
    public function testCreateRollsBackTheWholePersonWhenHealthInsurerCodeIsUnknown(): void
    {
        if (!$this->db->hasTable('payroll_person_health_coverage_history')) {
            self::markTestSkipped('Migrace zákonné evidence osoby neproběhla.');
        }

        $response = $this->action->create(
            $this->request(
                'POST',
                '/api/payroll/people',
                'accountant',
                'session',
                [
                    ...$this->validCreatePayload('Neplatná Pojišťovna'),
                    'health_insurer_code' => '999',
                ],
            ),
            new Response(),
        );

        self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(
            'validation_failed',
            $this->json($response)['error']['code'],
        );
        self::assertStringContainsString(
            '999',
            $this->json($response)['error']['message'],
        );

        $count = $this->db->pdo()->prepare(
            'SELECT COUNT(*)
               FROM payroll_employees
              WHERE supplier_id = ? AND full_name = ?'
        );
        $count->execute([$this->supplierId, 'Neplatná Pojišťovna']);
        self::assertSame(0, (int) $count->fetchColumn());
    }

    public function testCreateUsesOnlyPersonWritePermissionAndReturnsExactValidationErrors(): void
    {
        $employmentOnly = new EffectiveRole(
            43,
            'Pracovní vztahy',
            'staff',
            true,
            ['payroll.employment.write' => AccessLevel::WRITE->value],
        );
        $forbidden = $this->action->create(
            $this->request(
                'POST',
                '/api/payroll/people',
                'readonly',
                'session',
                $this->validCreatePayload('Zakázaný Testovací'),
                $employmentOnly,
            ),
            new Response(),
        );
        self::assertSame(403, $forbidden->getStatusCode());
        self::assertSame('forbidden', $this->json($forbidden)['error']['code']);

        $bearer = $this->action->create(
            $this->request(
                'POST',
                '/api/payroll/people',
                'accountant',
                'bearer',
                $this->validCreatePayload('Bearer Testovací'),
            ),
            new Response(),
        );
        self::assertSame(403, $bearer->getStatusCode());
        self::assertSame([
            'code' => 'session_required',
            'message' => 'Tento endpoint je dostupný pouze z přihlášené relace.',
        ], $this->json($bearer)['error']);

        $cases = [
            [
                ['full_name' => ''],
                'Jméno a příjmení je povinné.',
            ],
            [
                ['birth_date' => '31.02.1990'],
                'Datum narození musí být ve formátu YYYY-MM-DD.',
            ],
            [
                ['monthly_gross' => 12.50],
                'Pravidelná hrubá mzda musí být celé číslo v rozsahu 0 až 10 000 000 Kč.',
            ],
        ];
        foreach ($cases as [$change, $message]) {
            $response = $this->action->create(
                $this->request(
                    'POST',
                    '/api/payroll/people',
                    'accountant',
                    'session',
                    [...$this->validCreatePayload('Validace Testovací'), ...$change],
                ),
                new Response(),
            );
            self::assertSame(422, $response->getStatusCode());
            self::assertSame([
                'code' => 'validation_failed',
                'message' => $message,
            ], $this->json($response)['error']);
        }
    }

    public function testDatabaseAllowsOnlyOneLegacyProjectionPerEmployee(): void
    {
        $this->expectException(\PDOException::class);
        $this->db->pdo()->prepare(
            "INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 monthly_gross_minor, is_legacy_projection)
             VALUES (?, ?, 'legacy-second', 'statutory_body', 'active', 4300000, 1)"
        )->execute([$this->supplierId, $this->employeeId]);
    }

    private function request(
        string $method,
        string $path,
        string $role,
        string $authMethod = 'session',
        array $body = [],
        ?EffectiveRole $effectiveRole = null,
    ): \Psr\Http\Message\ServerRequestInterface {
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, $path)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => $role])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, $authMethod)
            ->withParsedBody($body);
        if ($effectiveRole !== null) {
            $request = $request->withAttribute('auth.effective_role', $effectiveRole);
        }
        return $request;
    }

    /** @return array<string,mixed> */
    private function validCreatePayload(string $fullName): array
    {
        return [
            'full_name' => $fullName,
            'birth_date' => null,
            'birth_number' => null,
            'relation_type' => 'employment',
            'planned_start_on' => '2026-08-10',
            'monthly_gross' => 42_000,
        ];
    }

    /** @return array<string,mixed> */
    private function json(Response $response): array
    {
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded);
        return $decoded;
    }

    /** @param array<string,mixed> $payload */
    private function assertNoSensitiveFields(array $payload): void
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('"birth_number"', $json);
        self::assertStringNotContainsString('"address"', $json);
    }
}
