<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollRegistrationAction;
use MyInvoice\Repository\Payroll\PayrollEmploymentRepository;
use MyInvoice\Service\Payroll\PayrollPersonCreateService;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Submission\PayrollReceiptVerifierInterface;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionService;
use MyInvoice\Service\Payroll\Submission\PayrollVerifiedReceipt;
use MyInvoice\Service\Payroll\Submission\PayrollVerifiedReceiptFormOutcome;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentityService;
use MyInvoice\Service\Payroll\Termination\PayrollEmploymentTerminationService;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Slim\Psr7\Response;

/**
 * Celý životní cyklus registrace zaměstnance u ČSSZ cestou účetní:
 *
 *  založení osoby → plná registrace A1 PŘED nástupem → nástup → změna trvalé
 *  adresy a zdravotní pojišťovny na kartě osoby → návrh změny A3 z detekce →
 *  podání A3 → skončení vztahu → odhláška A2.
 *
 * Hodiny jsou posuvné: každý krok se odehraje v den, kdy ho účetní dělá, a
 * lhůty se počítají proti němu. Síť se nevolá — podání končí zmrazeným XML
 * a protokol ČSSZ je syntetický.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollRegistrationLifecycleFlowTest extends TestCase
{
    use PayrollFullFlowTrait;

    private const START_ON = '2026-10-12';
    private const OIC = '1000000012';
    private const ID_PPV = '200000000000000000031';

    /** @var object{today:string} */
    private object $calendar;
    private int $officeId;
    private PayrollRegistrationAction $registration;

    protected function setUp(): void
    {
        $this->bootPayrollFullFlow();
        if (!$this->db->hasTable('payroll_registration_a1_profiles')
            || !$this->db->hasTable('payroll_registration_change_proposals')
        ) {
            self::markTestSkipped('Migrace registrací neproběhly.');
        }
        $this->calendar = new class () {
            public string $today = '2026-10-06';
        };
        $calendar = $this->calendar;
        $clock = new class ($calendar) implements ClockInterface {
            /** @param object{today:string} $calendar */
            public function __construct(private readonly object $calendar) {}

            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable(
                    $this->calendar->today . ' 12:00:00',
                    new \DateTimeZone('Europe/Prague'),
                );
            }
        };
        if (!$this->container instanceof \DI\Container) {
            self::markTestSkipped('Kontejner nejde přenastavit na zmrazené hodiny.');
        }
        $this->container->set(ClockInterface::class, $clock);
        $registration = $this->container->get(PayrollRegistrationAction::class);
        self::assertInstanceOf(PayrollRegistrationAction::class, $registration);
        $this->registration = $registration;

        $this->officeId = $this->createOffice('REG', 'Syntetická účtárna registrací', '1100000007');
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employer_settings
                (supplier_id, default_office_id, social_security_office_code)
             VALUES (?, ?, "110")',
        )->execute([$this->supplierId, $this->officeId]);
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    public function testRegistrationLifecycleFromHireToDeregistration(): void
    {
        // 1) Založení osoby na kartě „Nový zaměstnanec".
        $people = $this->container->get(PayrollPersonCreateService::class);
        self::assertInstanceOf(PayrollPersonCreateService::class, $people);
        $person = $people->create($this->supplierId, [
            'full_name' => 'Petra Registrační',
            'first_name' => 'Petra',
            'last_name' => 'Registrační',
            'birth_date' => '1990-04-15',
            'birth_number' => self::syntheticBirthNumber('1990-04-15', 'female', 3),
            'relation_type' => 'employment',
            'planned_start_on' => self::START_ON,
            'office_id' => $this->officeId,
            'health_insurer_code' => '111',
        ], $this->actors[0], null, null);
        $employeeId = (int) $person['id'];
        $employmentId = (int) $this->scalar(
            'SELECT id FROM payroll_employments WHERE supplier_id = ? AND employee_id = ?',
            [$this->supplierId, $employeeId],
        );
        $this->completePersonCard($employeeId, $employmentId);

        // 2) Plná registrace A1 PŘED nástupem (6. 10., nástup 12. 10.).
        $this->saveA1Profile($employmentId, $this->a1Profile('Dlouhá', '12', 'Praha', '11000'));
        $a1 = $this->prepare($employmentId, ['registration_mode' => 'full']);
        self::assertSame('REGZEC25', $a1['agenda_code'], CanonicalJson::encode($a1));
        self::assertSame('direct_full_registration', $a1['interaction']);
        self::assertSame(self::START_ON, $a1['deadline']['due_on']);
        $a1Xml = $this->artifactXml((int) $a1['submission_id']);
        self::assertStringContainsString('act="1"', $a1Xml);
        self::assertStringContainsString(' fro="' . self::START_ON . '"', $a1Xml);
        $this->acceptRegistration($a1, true);

        // 3) Nástup.
        $this->calendar->today = self::START_ON;
        $this->transition($employmentId, 'active', self::START_ON);

        // 4) Změna trvalé adresy a pojišťovny na kartě osoby od 1. 11.
        $this->calendar->today = '2026-11-03';
        $this->db->pdo()->prepare(
            'UPDATE payroll_person_addresses SET effective_to = "2026-10-31"
              WHERE supplier_id = ? AND employee_id = ? AND address_type = "residence"',
        )->execute([$this->supplierId, $employeeId]);
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_person_addresses
                (supplier_id, employee_id, address_type, street_line, city,
                 postal_code, country_code, effective_from)
             VALUES (?, ?, "residence", "Nová 5", "Brno", "60200", "CZ", "2026-11-01")',
        )->execute([$this->supplierId, $employeeId]);
        $this->db->pdo()->prepare(
            'UPDATE payroll_person_health_coverage_history SET effective_to = "2026-10-31"
              WHERE supplier_id = ? AND employee_id = ?',
        )->execute([$this->supplierId, $employeeId]);
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_person_health_coverage_history
                (supplier_id, employee_id, jurisdiction, insurer_status,
                 insurer_code, insurer_evidence_reference, effective_from)
             VALUES (?, ?, "czech_regime_verified", "verified", "211",
                     "karta-pojistence-2", "2026-11-01")',
        )->execute([$this->supplierId, $employeeId]);

        // 5) Detekce: A3 s adresou čeká na číslo popisné, pojišťovna jde hned.
        $detected = $this->detect($employmentId);
        $a3 = $this->proposal($detected, 'regzec_change');
        self::assertSame('2026-11-11', $a3['due_on']);
        $paths = array_column($a3['findings'], 'path');
        self::assertContains('health_insurance_code', $paths, CanonicalJson::encode($a3));
        self::assertContains('permanent_address.street', $paths, CanonicalJson::encode($a3));
        self::assertFalse($a3['fileable'], 'Adresa bez čísla popisného se podat nedá.');
        self::assertContains(
            'registration_change_permanent_address_incomplete',
            array_column($a3['unsupported'], 'reason_code'),
        );
        self::assertNotNull($this->proposal($detected, 'health_insurer_change'));

        // Účetní doplní adresu v profilu A1 (proklik z návrhu) a detekce
        // teď navrhne podání, které jde odeslat.
        // Výchozí stav detekce je verze profilu platná v okamžiku přípravy
        // podání (časové razítko databáze). V provozu dělí přípravu A1 a
        // opravu adresy dny, v testu aspoň jedna sekunda.
        sleep(1);
        $this->saveA1Profile($employmentId, $this->a1Profile('Nová', '5', 'Brno', '60200'));
        $a3 = $this->proposal($this->detect($employmentId), 'regzec_change');
        self::assertTrue($a3['fileable'], CanonicalJson::encode($a3));
        self::assertSame('211', $a3['changes']['health_insurance_code']);
        self::assertSame('Nová', $a3['changes']['permanent_address']['street']);
        $filed = $this->json(($this->registration)->fileChange(
            $this->request('POST', "/api/payroll/submissions/registration/{$employmentId}/changes/{$a3['id']}/file")
                ->withParsedBody(['environment' => 'test']),
            new Response(),
            ['employmentId' => (string) $employmentId, 'proposalId' => (string) $a3['id']],
        ));
        $a3Submission = $this->prepare($employmentId, ['event_id' => $filed['event']['id']]);
        self::assertSame('change', $a3Submission['interaction']);
        $a3Xml = $this->artifactXml((int) $a3Submission['submission_id']);
        self::assertStringContainsString('act="3"', $a3Xml);
        self::assertStringContainsString('<insh cnr="211"/>', $a3Xml);
        self::assertMatchesRegularExpression('/<adr [^>]*str="Nová"[^>]*num="5"/', $a3Xml);
        $this->acceptRegistration($a3Submission, false);

        // 6) Skončení vztahu výpovědí zaměstnance a odhláška A2.
        $this->calendar->today = '2026-12-02';
        $this->transition($employmentId, 'ended', '2026-11-30');
        $termination = $this->container->get(PayrollEmploymentTerminationService::class);
        self::assertInstanceOf(PayrollEmploymentTerminationService::class, $termination);
        $termination->save($this->supplierId, $employmentId, [
            'termination_method' => 'employee_notice',
        ], $this->actors[0]);
        $event = ($this->registration)->approveEvent(
            $this->request('POST', "/api/payroll/submissions/registration/{$employmentId}/events")
                ->withParsedBody([
                    'environment' => 'test',
                    'interaction' => 'termination',
                    'effective_on' => '2026-11-30',
                    'ended_by_death' => false,
                    'unemployment' => ['mode' => 'not_provided_2'],
                ]),
            new Response(),
            ['employmentId' => (string) $employmentId],
        );
        self::assertSame(201, $event->getStatusCode(), 'Zaseknutí: schválení A2. ' . (string) $event->getBody());
        $a2 = $this->prepare($employmentId, ['event_id' => $this->json($event)['id']]);
        self::assertSame('termination', $a2['interaction']);
        self::assertSame('2026-12-08', $a2['deadline']['due_on']);
        $a2Xml = $this->artifactXml((int) $a2['submission_id']);
        self::assertStringContainsString('act="2"', $a2Xml);
        self::assertStringContainsString(' to="2026-11-30"', $a2Xml);
        self::assertStringContainsString('oid="' . self::ID_PPV . '"', $a2Xml);
    }

    /**
     * REG-04 a REG-05: změna příjmení zjištěná zpětně. Detekce ji navrhne
     * jako jedno podání A3 (dřív šlo jen o ruční položku), s platností od
     * začátku nové verze identity a se začátkem osmidenní lhůty ode dne
     * zjištění; účetní platnost při podání může přepsat.
     */
    public function testSurnameChangeIsDetectedAndFiledWithBackdatedValidity(): void
    {
        $people = $this->container->get(PayrollPersonCreateService::class);
        self::assertInstanceOf(PayrollPersonCreateService::class, $people);
        $person = $people->create($this->supplierId, [
            'full_name' => 'Petra Registrační',
            'first_name' => 'Petra',
            'last_name' => 'Registrační',
            'birth_date' => '1990-04-15',
            'birth_number' => self::syntheticBirthNumber('1990-04-15', 'female', 4),
            'relation_type' => 'employment',
            'planned_start_on' => self::START_ON,
            'office_id' => $this->officeId,
            'health_insurer_code' => '111',
        ], $this->actors[0], null, null);
        $employeeId = (int) $person['id'];
        $employmentId = (int) $this->scalar(
            'SELECT id FROM payroll_employments WHERE supplier_id = ? AND employee_id = ?',
            [$this->supplierId, $employeeId],
        );
        $this->completePersonCard($employeeId, $employmentId);
        $this->saveA1Profile($employmentId, $this->a1Profile('Dlouhá', '12', 'Praha', '11000'));
        $a1 = $this->prepare($employmentId, ['registration_mode' => 'full']);
        $this->acceptRegistration($a1, true);
        $this->calendar->today = self::START_ON;
        $this->transition($employmentId, 'active', self::START_ON);

        // Nová verze identity s novým příjmením od 1. 11., zjištěno 20. 11.
        $this->calendar->today = '2026-11-20';
        $this->db->pdo()->prepare(
            'UPDATE payroll_person_identity_history SET effective_to = "2026-10-31"
              WHERE supplier_id = ? AND employee_id = ?',
        )->execute([$this->supplierId, $employeeId]);
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_person_identity_history
                (supplier_id, employee_id, full_name, first_name, last_name,
                 title_prefix, title_suffix, birth_surname, birth_date,
                 birth_place, birth_country_code, citizenship_country_code,
                 sex, effective_from)
             SELECT supplier_id, employee_id, "Petra Nová", first_name, "Nová",
                    title_prefix, title_suffix, birth_surname, birth_date,
                    birth_place, birth_country_code, citizenship_country_code,
                    sex, "2026-11-01"
               FROM payroll_person_identity_history
              WHERE supplier_id = ? AND employee_id = ? AND effective_to = "2026-10-31"',
        )->execute([$this->supplierId, $employeeId]);

        $a3 = $this->proposal($this->detect($employmentId), 'regzec_change');
        self::assertSame(['identity.last_name'], array_column($a3['findings'], 'path'));
        self::assertTrue($a3['fileable'], CanonicalJson::encode($a3));
        self::assertSame('2026-11-28', $a3['due_on']);
        self::assertSame('2026-11-01', $a3['effective_on']);
        // Dřívější „Registrační" je zároveň rodné příjmení: to nese vlastní
        // atribut (ID 10063) a do dřívějších příjmení (ID 10064) nepatří.
        self::assertSame(
            ['first_name' => 'Petra', 'last_name' => 'Nová'],
            $a3['changes']['identity'],
        );

        // Bez přepsání platí navržený začátek nové verze identity.
        $filed = $this->json(($this->registration)->fileChange(
            $this->request('POST', "/api/payroll/submissions/registration/{$employmentId}/changes/{$a3['id']}/file")
                ->withParsedBody(['environment' => 'test']),
            new Response(),
            ['employmentId' => (string) $employmentId, 'proposalId' => (string) $a3['id']],
        ));
        self::assertSame('2026-11-01', $filed['event']['effective_on']);
        $submission = $this->prepare($employmentId, ['event_id' => $filed['event']['id']]);
        // Platnost je 1. 11., ale lhůta běží ode dne zjištění 20. 11.
        self::assertSame('2026-11-20', $submission['deadline']['earliest_registration_on']);
        self::assertSame('2026-11-28', $submission['deadline']['due_on']);
        $xml = $this->artifactXml((int) $submission['submission_id']);
        self::assertStringContainsString('act="3"', $xml);
        self::assertStringContainsString(' fro="2026-11-01"', $xml);
        self::assertMatchesRegularExpression('/<name [^>]*sur="Nová"[^>]*fir="Petra"/', $xml);
    }

    public function testFilingOverridesTheSuggestedValidityDate(): void
    {
        $people = $this->container->get(PayrollPersonCreateService::class);
        self::assertInstanceOf(PayrollPersonCreateService::class, $people);
        $person = $people->create($this->supplierId, [
            'full_name' => 'Petra Registrační',
            'first_name' => 'Petra',
            'last_name' => 'Registrační',
            'birth_date' => '1990-04-15',
            'birth_number' => self::syntheticBirthNumber('1990-04-15', 'female', 5),
            'relation_type' => 'employment',
            'planned_start_on' => self::START_ON,
            'office_id' => $this->officeId,
            'health_insurer_code' => '111',
        ], $this->actors[0], null, null);
        $employeeId = (int) $person['id'];
        $employmentId = (int) $this->scalar(
            'SELECT id FROM payroll_employments WHERE supplier_id = ? AND employee_id = ?',
            [$this->supplierId, $employeeId],
        );
        $this->completePersonCard($employeeId, $employmentId);
        $this->saveA1Profile($employmentId, $this->a1Profile('Dlouhá', '12', 'Praha', '11000'));
        $a1 = $this->prepare($employmentId, ['registration_mode' => 'full']);
        $this->acceptRegistration($a1, true);
        $this->calendar->today = self::START_ON;
        $this->transition($employmentId, 'active', self::START_ON);

        $this->calendar->today = '2026-11-20';
        $this->db->pdo()->prepare(
            'UPDATE payroll_person_addresses SET effective_to = "2026-10-31"
              WHERE supplier_id = ? AND employee_id = ? AND address_type = "residence"',
        )->execute([$this->supplierId, $employeeId]);
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_person_addresses
                (supplier_id, employee_id, address_type, street_line, city,
                 postal_code, country_code, effective_from)
             VALUES (?, ?, "residence", "Nová 5", "Brno", "602 00", "CZ", "2026-11-01")',
        )->execute([$this->supplierId, $employeeId]);
        sleep(1);
        // Adresa i s číslem popisným do profilu A1 (proklik z návrhu).
        $this->saveA1Profile($employmentId, $this->a1Profile('Nová', '5', 'Brno', '602 00'));

        $a3 = $this->proposal($this->detect($employmentId), 'regzec_change');
        self::assertTrue($a3['fileable'], CanonicalJson::encode($a3));
        // Adresa nemá historii: navrhuje se den zjištění.
        self::assertSame('2026-11-20', $a3['effective_on']);

        $filed = $this->json(($this->registration)->fileChange(
            $this->request('POST', "/api/payroll/submissions/registration/{$employmentId}/changes/{$a3['id']}/file")
                ->withParsedBody(['environment' => 'test', 'effective_on' => '2026-11-01']),
            new Response(),
            ['employmentId' => (string) $employmentId, 'proposalId' => (string) $a3['id']],
        ));
        self::assertSame('2026-11-01', $filed['event']['effective_on']);
        $submission = $this->prepare($employmentId, ['event_id' => $filed['event']['id']]);
        self::assertSame('2026-11-28', $submission['deadline']['due_on']);
        $xml = $this->artifactXml((int) $submission['submission_id']);
        self::assertStringContainsString(' fro="2026-11-01"', $xml);
        // PSČ s mezerou schéma nepřipouští, do věty jde bez mezery.
        self::assertMatchesRegularExpression('/<adr [^>]*pnu="60200"/', $xml);
    }

    private function completePersonCard(int $employeeId, int $employmentId): void
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
            'UPDATE payroll_person_identity_history SET birth_surname = "Registrační", effective_from = "2026-01-01"
              WHERE supplier_id = ? AND id = ?',
        )->execute([$this->supplierId, $identityId]);
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
        if ((int) $this->scalar(
            'SELECT COUNT(*) FROM payroll_person_health_coverage_history WHERE supplier_id = ? AND employee_id = ?',
            [$this->supplierId, $employeeId],
        ) === 0) {
            $this->db->pdo()->prepare(
                'INSERT INTO payroll_person_health_coverage_history
                    (supplier_id, employee_id, jurisdiction, insurer_status,
                     insurer_code, insurer_evidence_reference, effective_from)
                 VALUES (?, ?, "czech_regime_verified", "verified", "111",
                         "karta-pojistence", "2026-01-01")',
            )->execute([$this->supplierId, $employeeId]);
        } else {
            $this->db->pdo()->prepare(
                'UPDATE payroll_person_health_coverage_history
                    SET insurer_status = "verified", effective_from = "2026-01-01",
                        insurer_evidence_reference = "karta-pojistence"
                  WHERE supplier_id = ? AND employee_id = ?',
            )->execute([$this->supplierId, $employeeId]);
        }
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
    private function a1Profile(string $street, string $houseNumber, string $city, string $postalCode): array
    {
        return [
            'effective_on' => self::START_ON,
            'row_version' => 0,
            'permanent_address' => [
                'street' => $street,
                'house_number' => $houseNumber,
                'orientation_number' => null,
                'city' => $city,
                'postal_code' => $postalCode,
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
    private function saveA1Profile(int $employmentId, array $payload): void
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
            'verified',
            $profile['status'],
            'Zaseknutí: profil A1. ' . CanonicalJson::encode($profile['problems'] ?? []),
        );
    }

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    private function prepare(int $employmentId, array $body): array
    {
        $response = ($this->registration)->prepare(
            $this->request('POST', "/api/payroll/submissions/registration/{$employmentId}")
                ->withParsedBody(['environment' => 'test'] + $body),
            new Response(),
            ['employmentId' => (string) $employmentId],
        );
        self::assertSame(201, $response->getStatusCode(), 'Zaseknutí: příprava podání. ' . (string) $response->getBody());

        return $this->json($response);
    }

    /** @return array<string,mixed> */
    private function detect(int $employmentId): array
    {
        $response = ($this->registration)->changeDetection(
            $this->request('POST', "/api/payroll/submissions/registration/{$employmentId}/changes")
                ->withParsedBody(['environment' => 'test']),
            new Response(),
            ['employmentId' => (string) $employmentId],
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return $this->json($response);
    }

    /**
     * @param array<string,mixed> $detected
     * @return array<string,mixed>
     */
    private function proposal(array $detected, string $dutyKind): array
    {
        foreach ($detected['proposals'] as $proposal) {
            if ($proposal['duty_kind'] === $dutyKind) {
                return $proposal;
            }
        }
        self::fail("Detekce nenavrhla povinnost {$dutyKind}: " . CanonicalJson::encode($detected));
    }

    private function transition(int $employmentId, string $target, string $effectiveOn): void
    {
        $employments = $this->container->get(PayrollEmploymentRepository::class);
        self::assertInstanceOf(PayrollEmploymentRepository::class, $employments);
        $employments->transition(
            $this->supplierId,
            $employmentId,
            $target,
            (int) $this->scalar(
                'SELECT row_version FROM payroll_employments WHERE supplier_id = ? AND id = ?',
                [$this->supplierId, $employmentId],
            ),
            $effectiveOn,
            null,
            $this->actors[0],
            null,
            null,
        );
    }

    /**
     * Syntetický protokol ČSSZ: přijetí podání, u přihlášky i s OIČ a ID PPV.
     *
     * @param array<string,mixed> $prepared
     */
    private function acceptRegistration(array $prepared, bool $withIdentifiers): void
    {
        $submissions = $this->container->get(PayrollSubmissionService::class);
        self::assertInstanceOf(PayrollSubmissionService::class, $submissions);
        $submissionId = (int) $prepared['submission_id'];
        $correlation = "synthetic-registration-flow:{$submissionId}";
        $submitted = $submissions->transition(
            $this->supplierId,
            $submissionId,
            (int) $prepared['row_version'],
            'submitted',
            $correlation,
        );
        $partId = (int) $prepared['part_id'];
        $outcomes = $withIdentifiers ? [new PayrollVerifiedReceiptFormOutcome(
            '11111111-2222-4333-8444-555555555555',
            $partId,
            1,
            'Accepted',
            'accepted',
            self::OIC,
            self::ID_PPV,
            [],
        )] : [];
        $verifier = new class ($partId, $outcomes) implements PayrollReceiptVerifierInterface {
            /** @param list<PayrollVerifiedReceiptFormOutcome> $outcomes */
            public function __construct(private readonly int $partId, private readonly array $outcomes) {}

            public function verify(
                string $bytes,
                string $channel,
                string $environment,
                ?string $expectedCorrelationReference,
            ): PayrollVerifiedReceipt {
                return new PayrollVerifiedReceipt(
                    'accepted',
                    $expectedCorrelationReference,
                    [$this->partId => 'accepted'],
                    $this->outcomes,
                );
            }
        };
        $receipt = $submissions->importReceipt(
            $this->supplierId,
            $submissionId,
            (int) $submitted['row_version'],
            $partId,
            '<synthetic-registration-receipt/>',
            "synthetic-registration-flow-receipt:{$submissionId}",
            $correlation,
            'CSSZ_REGZEC',
            'accepted',
            'vrep_apep',
            "synthetic-registration-flow-key:{$submissionId}",
            $this->actors[0],
            $verifier,
        );
        self::assertTrue($receipt['trusted']);
    }

    private function artifactXml(int $submissionId): string
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT id FROM payroll_submission_artifacts
              WHERE supplier_id = ? AND submission_id = ? AND artifact_kind = "outbound_xml"
              ORDER BY id DESC LIMIT 1',
        );
        $statement->execute([$this->supplierId, $submissionId]);
        $submissions = $this->container->get(PayrollSubmissionService::class);
        self::assertInstanceOf(PayrollSubmissionService::class, $submissions);

        return $submissions->artifactBytes($this->supplierId, (int) $statement->fetchColumn());
    }
}
