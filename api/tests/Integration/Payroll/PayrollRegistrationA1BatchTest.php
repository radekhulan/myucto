<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollRegistrationA1BatchAction;
use MyInvoice\Action\Payroll\PayrollRegistrationAction;
use MyInvoice\Service\Payroll\PayrollPersonCreateService;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationA1BatchService;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentityService;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Slim\Psr7\Response;

/**
 * Přihlášky k podání (A1) hromadně: seznam vztahů, které přihlášku
 * potřebují, a jejich příprava toutéž cestou jako na kartě vztahu.
 *
 * Síť se nevolá — příprava končí zmrazeným XML ve frontě podání.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollRegistrationA1BatchTest extends TestCase
{
    use PayrollFullFlowTrait;

    private const TODAY = '2026-11-11';
    private const START_ON = '2026-11-16';

    private int $officeId;
    private PayrollRegistrationAction $registration;
    private PayrollRegistrationA1BatchService $batch;

    protected function setUp(): void
    {
        $this->bootPayrollFullFlow();
        if (!$this->db->hasTable('payroll_registration_a1_profiles')) {
            self::markTestSkipped('Migrace registrací neproběhly.');
        }
        if (!$this->container instanceof \DI\Container) {
            self::markTestSkipped('Kontejner nejde přenastavit na zmrazené hodiny.');
        }
        $this->container->set(ClockInterface::class, new class () implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable(
                    PayrollRegistrationA1BatchTest::TODAY_AT_NOON,
                    new \DateTimeZone('Europe/Prague'),
                );
            }
        });
        $registration = $this->container->get(PayrollRegistrationAction::class);
        self::assertInstanceOf(PayrollRegistrationAction::class, $registration);
        $this->registration = $registration;
        $batch = $this->container->get(PayrollRegistrationA1BatchService::class);
        self::assertInstanceOf(PayrollRegistrationA1BatchService::class, $batch);
        $this->batch = $batch;

        $this->officeId = $this->createOffice('REG', 'Syntetická účtárna registrací', '1100000007');
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employer_settings
                (supplier_id, default_office_id, social_security_office_code)
             VALUES (?, ?, "110")',
        )->execute([$this->supplierId, $this->officeId]);
    }

    public const TODAY_AT_NOON = self::TODAY . ' 12:00:00';

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    /**
     * Seznam nabízí jen vztahy bez ID PPV a bez živé přihlášky, neúplné
     * ukáže s důvodem. Hromadná příprava připraví jen úplné, ostatní
     * přeskočí, a opakování nezaloží druhé podání.
     */
    public function testCandidatesAndBulkPreparationThroughThePerCardPath(): void
    {
        [, $complete] = $this->person('Úplná', 'Připravená', 3, 'employment');
        $this->saveA1Profile($complete, $this->a1Profile());
        [, $missing] = $this->person('Bez', 'Profilu', 5, 'employment');
        [$registeredEmployee, $registered] = $this->person('Už', 'Přihlášená', 7, 'employment');
        $this->assignIdPpv($registeredEmployee, $registered, 'id-ppv-registered');

        $list = $this->batch->candidates($this->supplierId, 'test', null, null);
        self::assertSame('2026-11-01', $list['from']);
        self::assertSame('2026-12-31', $list['to']);
        $rows = array_column($list['items'], null, 'employment_id');
        self::assertArrayNotHasKey($registered, $rows, 'Vztah s ID PPV přihlášku nepotřebuje.');
        self::assertArrayHasKey($complete, $rows, CanonicalJson::encode($list));
        self::assertTrue($rows[$complete]['complete']);
        self::assertSame([], $rows[$complete]['problems']);
        self::assertSame(self::START_ON, $rows[$complete]['deadline']['due_on']);
        self::assertFalse($rows[$complete]['deadline']['overdue']);
        self::assertFalse($rows[$missing]['complete']);
        self::assertSame('missing', $rows[$missing]['profile_status']);
        self::assertSame(
            'registration_regzec_a1_profile_missing',
            $rows[$missing]['problems'][0]['code'],
        );

        $first = $this->batch->prepare($this->supplierId, 'test', [$complete, $missing, $registered], $this->actors[0]);
        $results = array_column($first['results'], null, 'employment_id');
        self::assertSame('prepared', $results[$complete]['status'], CanonicalJson::encode($first));
        self::assertSame('REGZEC25', $results[$complete]['agenda_code']);
        self::assertTrue($results[$complete]['created']);
        self::assertSame('skipped', $results[$missing]['status']);
        self::assertSame('profile_incomplete', $results[$missing]['code']);
        self::assertSame('skipped', $results[$registered]['status']);
        self::assertSame('id_ppv', $results[$registered]['code']);
        $submissionId = (int) $results[$complete]['submission_id'];
        $xml = $this->artifactXml($submissionId);
        self::assertStringContainsString('act="1"', $xml, 'Připraví se plná přihláška A1, ne P1.');
        $before = $this->submissionCount();

        // Připravený vztah z nabídky zmizí a druhé kolo nic nezaloží.
        $again = array_column(
            $this->batch->candidates($this->supplierId, 'test', null, null)['items'],
            null,
            'employment_id',
        );
        self::assertArrayNotHasKey($complete, $again);
        $second = $this->batch->prepare($this->supplierId, 'test', [$complete], $this->actors[0]);
        self::assertSame('skipped', $second['results'][0]['status']);
        self::assertSame('live_registration', $second['results'][0]['code']);
        self::assertSame($submissionId, $second['results'][0]['submission_id']);
        self::assertSame($before, $this->submissionCount());
    }

    /** Rozpracovaný profil se ukáže s vadami, které by hlásila Kontrola. */
    public function testDraftProfileListsItsProblems(): void
    {
        [, $draft] = $this->person('Rozpracovaný', 'Profil', 9, 'employment');
        $profile = $this->a1Profile();
        $profile['employment']['profession_code'] = null;
        $this->saveA1Profile($draft, $profile, 'draft');

        $rows = array_column(
            $this->batch->candidates($this->supplierId, 'test', null, null)['items'],
            null,
            'employment_id',
        );

        self::assertSame('draft', $rows[$draft]['profile_status']);
        self::assertFalse($rows[$draft]['complete']);
        self::assertNotSame([], $rows[$draft]['problems']);
        self::assertContains(
            'employment.profession_code',
            array_column($rows[$draft]['problems'], 'field'),
            CanonicalJson::encode($rows[$draft]['problems']),
        );
    }

    /**
     * Druhá otevřená DPP téže osoby, která už ID PPV má, je nejspíš tentýž
     * vztah založený dvakrát. Seznam ho nabídne, ale varuje.
     */
    public function testSecondOpenDppOfTheSamePersonWarnsAboutDuplicate(): void
    {
        [$employeeId, $registered] = $this->person('Dvojitá', 'Dohoda', 11, 'dpp');
        $this->assignIdPpv($employeeId, $registered, 'id-ppv-dpp');
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status, is_primary, start_date, actual_start_date)
             VALUES (?, ?, "DPP-2", "dpp", "planned", 0, ?, ?)',
        )->execute([$this->supplierId, $employeeId, self::START_ON, self::START_ON]);
        $duplicate = (int) $this->db->pdo()->lastInsertId();

        $rows = array_column(
            $this->batch->candidates($this->supplierId, 'test', null, null)['items'],
            null,
            'employment_id',
        );

        self::assertArrayNotHasKey($registered, $rows);
        self::assertArrayHasKey($duplicate, $rows);
        self::assertSame('duplicate_dpp', $rows[$duplicate]['warnings'][0]['code']);
        self::assertSame($registered, $rows[$duplicate]['warnings'][0]['employment_id']);
    }

    /** Neplatné období je validační chyba, ne pád. */
    public function testInvalidPeriodIsAValidationError(): void
    {
        $action = $this->container->get(PayrollRegistrationA1BatchAction::class);
        self::assertInstanceOf(PayrollRegistrationA1BatchAction::class, $action);
        $response = $action->candidates(
            $this->request('GET', '/api/payroll/submissions/registration-a1')
                ->withQueryParams(['environment' => 'test', 'from' => '2026-02-31']),
            new Response(),
        );

        self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());
    }

    /** @return array{0:int,1:int} */
    private function person(string $first, string $last, int $sequence, string $relationType): array
    {
        $people = $this->container->get(PayrollPersonCreateService::class);
        self::assertInstanceOf(PayrollPersonCreateService::class, $people);
        $person = $people->create($this->supplierId, [
            'full_name' => $first . ' ' . $last,
            'first_name' => $first,
            'last_name' => $last,
            'birth_date' => '1990-04-15',
            'birth_number' => self::syntheticBirthNumber('1990-04-15', 'female', $sequence),
            'relation_type' => $relationType,
            'planned_start_on' => self::START_ON,
            'office_id' => $this->officeId,
            'health_insurer_code' => '111',
        ], $this->actors[0], null, null);
        $employeeId = (int) $person['id'];
        $employmentId = (int) $this->scalar(
            'SELECT id FROM payroll_employments WHERE supplier_id = ? AND employee_id = ?',
            [$this->supplierId, $employeeId],
        );
        $this->completePersonCard($employeeId, $employmentId, $last);

        return [$employeeId, $employmentId];
    }

    private function assignIdPpv(int $employeeId, int $employmentId, string $value): void
    {
        $this->db->pdo()->prepare(
            "INSERT INTO payroll_employment_external_ids
                (supplier_id, employee_id, employment_id, environment,
                 identifier_type, value_ciphertext, value_hash, value_masked,
                 valid_from, source_kind, source_reference_hash)
             VALUES (?, ?, ?, 'test', 'id_ppv', 'enc:v2:test', UNHEX(SHA2(?, 256)),
                     '***123', '2026-01-01', 'verified_manual_import', ?)",
        )->execute([$this->supplierId, $employeeId, $employmentId, $value, str_repeat('a', 64)]);
    }

    private function completePersonCard(int $employeeId, int $employmentId, string $surname): void
    {
        $identities = $this->container->get(PayrollRegistrationIdentityService::class);
        self::assertInstanceOf(PayrollRegistrationIdentityService::class, $identities);
        $identityId = (int) $this->scalar(
            'SELECT id FROM payroll_person_identity_history WHERE supplier_id = ? AND employee_id = ?',
            [$this->supplierId, $employeeId],
        );
        $rowVersion = (int) $this->scalar(
            'SELECT row_version FROM payroll_person_identity_history WHERE supplier_id = ? AND id = ?',
            [$this->supplierId, $identityId],
        );
        $this->db->pdo()->prepare(
            'UPDATE payroll_person_identity_history SET birth_surname = ?, effective_from = "2026-01-01"
              WHERE supplier_id = ? AND id = ?',
        )->execute([$surname, $this->supplierId, $identityId]);
        $identities->saveIdentityFacts($this->supplierId, $employeeId, $identityId, $rowVersion, [
            'title_prefix' => null,
            'title_suffix' => null,
            'birth_date' => '1990-04-15',
            'birth_place' => 'Testov',
            'birth_country_code' => 'CZ',
            'citizenship_country_code' => 'CZ',
            'sex' => 'female',
        ]);
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_person_addresses
                (supplier_id, employee_id, address_type, street_line, city,
                 postal_code, country_code, effective_from)
             VALUES (?, ?, "residence", "Dlouhá 12", "Praha", "11000", "CZ", "2026-01-01")',
        )->execute([$this->supplierId, $employeeId]);
        $this->db->pdo()->prepare(
            'UPDATE payroll_person_health_coverage_history
                SET insurer_status = "verified", effective_from = "2026-01-01",
                    insurer_evidence_reference = "karta-pojistence"
              WHERE supplier_id = ? AND employee_id = ?',
        )->execute([$this->supplierId, $employeeId]);
        $this->db->pdo()->prepare(
            'UPDATE payroll_employment_terms
                SET work_place = "Praha 1, Dlouhá 1",
                    jmhz_workplace_municipality_code = "554782",
                    jmhz_workplace_country_code = "CZ",
                    cz_isco_code = "2411"
              WHERE supplier_id = ? AND employment_id = ?',
        )->execute([$this->supplierId, $employmentId]);
    }

    /** @return array<string,mixed> */
    private function a1Profile(): array
    {
        return [
            'effective_on' => self::START_ON,
            'row_version' => 0,
            'permanent_address' => [
                'street' => 'Dlouhá',
                'house_number' => '12',
                'orientation_number' => null,
                'city' => 'Praha',
                'postal_code' => '11000',
                'country_code' => 'CZ',
                'ruian_point' => null,
            ],
            'tax_residency' => [
                'country_code' => 'CZ',
                'identifier_type' => null,
                'identifier' => null,
                'residence_address' => null,
            ],
            'employment' => [
                'activity_code' => '1',
                'relationship_detail_code' => '1',
                'actual_start_on' => self::START_ON,
                'contract_start_on' => self::START_ON,
                'small_scale' => false,
                'employment_status_code' => '1111',
                'work_mode_code' => '1',
                'continuous_operation' => false,
                'prevailing_workplace_code' => null,
                'expected_workplaces' => null,
                'contract_workplace' => 'Praha 1, Dlouhá 1',
                'workplace_city' => 'Praha',
                'workplace_municipality_code' => '554782',
                'profession_code' => '24111',
                'required_education_code' => null,
                'position_name' => 'Účetní',
                'leadership' => false,
            ],
            'pension' => [
                'type_code' => null,
                'received_from' => null,
                'early_retirement' => false,
                'reduced_retirement_age' => false,
            ],
            'health_insurance_code' => '111',
            'facts' => [
                'highest_education_code' => 'T',
                'disability_card' => false,
                'health_restrictions' => [],
            ],
            'foreign_legislation' => ['applies' => false, 'country_code' => null],
            'proof_identity' => null,
            'foreign_worker' => null,
            'czech_residence_address' => null,
            'contact_address' => null,
            'attachments' => [],
        ];
    }

    /** @param array<string,mixed> $payload */
    private function saveA1Profile(int $employmentId, array $payload, string $expectedStatus = 'verified'): void
    {
        $current = $this->json(($this->registration)->a1Profile(
            $this->request('GET', "/api/payroll/submissions/registration/{$employmentId}/a1-profile"),
            new Response(),
            ['employmentId' => (string) $employmentId],
        ));
        $payload['row_version'] = (int) $current['draft']['row_version'];
        $response = ($this->registration)->saveA1Profile(
            $this->request('PUT', "/api/payroll/submissions/registration/{$employmentId}/a1-profile")
                ->withParsedBody($payload),
            new Response(),
            ['employmentId' => (string) $employmentId],
        );
        self::assertContains($response->getStatusCode(), [200, 201], (string) $response->getBody());
        $profile = $this->json($response)['profile'];
        self::assertSame(
            $expectedStatus,
            $profile['status'],
            'Profil A1: ' . CanonicalJson::encode($profile['problems'] ?? []),
        );
    }

    private function submissionCount(): int
    {
        return (int) $this->scalar(
            'SELECT COUNT(*) FROM payroll_submissions WHERE supplier_id = ?',
            [$this->supplierId],
        );
    }

    private function artifactXml(int $submissionId): string
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT id FROM payroll_submission_artifacts
              WHERE supplier_id = ? AND submission_id = ? AND artifact_kind = "outbound_xml"
              ORDER BY id DESC LIMIT 1',
        );
        $statement->execute([$this->supplierId, $submissionId]);
        $submissions = $this->container->get(\MyInvoice\Service\Payroll\Submission\PayrollSubmissionService::class);
        self::assertInstanceOf(\MyInvoice\Service\Payroll\Submission\PayrollSubmissionService::class, $submissions);

        return $submissions->artifactBytes($this->supplierId, (int) $statement->fetchColumn());
    }
}
