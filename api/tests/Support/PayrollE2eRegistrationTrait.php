<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support;

use MyInvoice\Action\Payroll\PayrollRegistrationAction;
use MyInvoice\Repository\Payroll\PayrollEmploymentRepository;
use MyInvoice\Service\Payroll\PayrollPersonCreateService;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Submission\PayrollReceiptVerifierInterface;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionService;
use MyInvoice\Service\Payroll\Submission\PayrollVerifiedReceipt;
use MyInvoice\Service\Payroll\Submission\PayrollVerifiedReceiptFormOutcome;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentityService;
use Psr\Clock\ClockInterface;
use Slim\Psr7\Response;

/**
 * Společné kroky e2e scénářů registrace (REGZEC/PREZEC) nad
 * {@see PayrollFullFlowTrait}: posuvné hodiny, osoba s vyplněnou kartou,
 * profil A1, příprava přes akci, syntetický protokol ČSSZ a kontrola věty
 * proti připnutému XSD. Síť se nevolá.
 */
trait PayrollE2eRegistrationTrait
{
    /** @var object{today:string} */
    private object $regCalendar;
    private int $regOfficeId;
    private PayrollRegistrationAction $registration;

    private function bootRegistrationFlow(string $today): void
    {
        $this->bootPayrollFullFlow();
        if (!$this->db->hasTable('payroll_registration_a1_profiles')) {
            self::markTestSkipped('Migrace registrací neproběhly.');
        }
        if (!$this->container instanceof \DI\Container) {
            self::markTestSkipped('Kontejner nejde přenastavit na zmrazené hodiny.');
        }
        $this->regCalendar = new class ($today) {
            public function __construct(public string $today) {}
        };
        $calendar = $this->regCalendar;
        $this->container->set(ClockInterface::class, new class ($calendar) implements ClockInterface {
            /** @param object{today:string} $calendar */
            public function __construct(private readonly object $calendar) {}

            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable(
                    $this->calendar->today . ' 12:00:00',
                    new \DateTimeZone('Europe/Prague'),
                );
            }
        });
        $registration = $this->container->get(PayrollRegistrationAction::class);
        self::assertInstanceOf(PayrollRegistrationAction::class, $registration);
        $this->registration = $registration;
        $this->regOfficeId = $this->createOffice('REG', 'Syntetická účtárna registrací', '1100000007');
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employer_settings
                (supplier_id, default_office_id, social_security_office_code)
             VALUES (?, ?, "110")',
        )->execute([$this->supplierId, $this->regOfficeId]);
    }

    private function regToday(string $today): void
    {
        $this->regCalendar->today = $today;
    }

    /**
     * Nová osoba s jedním vztahem a vyplněnou kartou (identita, adresa,
     * pojišťovna, pracoviště, CZ-ISCO).
     *
     * @return array{0:int,1:int} [employee_id, employment_id]
     */
    private function regPerson(string $first, string $last, int $sequence, string $relationType, string $startOn): array
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
            'planned_start_on' => $startOn,
            'office_id' => $this->regOfficeId,
            'health_insurer_code' => '111',
        ], $this->actors[0], null, null);
        $employeeId = (int) $person['id'];
        $employmentId = (int) $this->scalar(
            'SELECT id FROM payroll_employments WHERE supplier_id = ? AND employee_id = ? ORDER BY id DESC LIMIT 1',
            [$this->supplierId, $employeeId],
        );
        $this->regCompleteCard($employeeId, $employmentId, $last);

        return [$employeeId, $employmentId];
    }

    /**
     * Další vztah téže osoby (navazující DPP, souběh HPP + DPP).
     */
    private function regAdditionalEmployment(
        int $employeeId,
        string $code,
        string $relationType,
        string $startOn,
        ?string $endOn = null,
        string $status = 'planned',
    ): int {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status, is_primary,
                 start_date, actual_start_date, end_date, office_id)
             VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, ?)',
        )->execute([
            $this->supplierId, $employeeId, $code, $relationType, $status,
            $startOn, $startOn, $endOn, $this->regOfficeId,
        ]);
        $employmentId = (int) $this->db->pdo()->lastInsertId();
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employment_terms
                (supplier_id, employment_id, effective_from, planned_start_on, work_place,
                 jmhz_workplace_municipality_code, jmhz_workplace_country_code, cz_isco_code)
             SELECT supplier_id, ?, ?, ?, work_place, jmhz_workplace_municipality_code,
                    jmhz_workplace_country_code, cz_isco_code
               FROM payroll_employment_terms
              WHERE supplier_id = ? AND employment_id = (
                    SELECT MIN(id) FROM payroll_employments WHERE supplier_id = ? AND employee_id = ?)
              ORDER BY effective_from DESC LIMIT 1',
        )->execute([$employmentId, $startOn, $startOn, $this->supplierId, $this->supplierId, $employeeId]);

        return $employmentId;
    }

    private function regCompleteCard(int $employeeId, int $employmentId, string $surname): void
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

    /**
     * Úplný profil A1 občana ČR. Druh činnosti „1" = pracovní poměr,
     * „T" = DPP.
     *
     * @return array<string,mixed>
     */
    private function regA1Profile(string $startOn, string $activityCode = '1'): array
    {
        $dpp = $activityCode !== '1';

        return [
            'effective_on' => $startOn,
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
                'activity_code' => $activityCode,
                'relationship_detail_code' => $dpp ? null : '1',
                'actual_start_on' => $startOn,
                'contract_start_on' => $startOn,
                'small_scale' => false,
                'employment_status_code' => $dpp ? '1222' : '1111',
                'work_mode_code' => '1',
                'continuous_operation' => false,
                'prevailing_workplace_code' => null,
                'expected_workplaces' => null,
                'contract_workplace' => 'Praha 1, Dlouhá 1',
                'workplace_city' => 'Praha',
                'workplace_municipality_code' => '554782',
                'profession_code' => '24111',
                'required_education_code' => null,
                'position_name' => $dpp ? 'Brigádník' : 'Účetní',
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

    /**
     * Uloží profil A1 a vrátí uložený profil (stav a vady).
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function regSaveA1Profile(int $employmentId, array $payload, ?string $expectedStatus = 'verified'): array
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
        if ($expectedStatus !== null) {
            self::assertSame(
                $expectedStatus,
                $profile['status'],
                'Profil A1: ' . CanonicalJson::encode($profile['problems'] ?? []),
            );
        }

        return $profile;
    }

    /**
     * @param array<string,mixed> $body
     * @return array{status:int,body:array<string,mixed>}
     */
    private function regPrepareRaw(int $employmentId, array $body): array
    {
        $response = ($this->registration)->prepare(
            $this->request('POST', "/api/payroll/submissions/registration/{$employmentId}")
                ->withParsedBody(['environment' => 'test'] + $body),
            new Response(),
            ['employmentId' => (string) $employmentId],
        );

        return ['status' => $response->getStatusCode(), 'body' => $this->json($response)];
    }

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    private function regPrepare(int $employmentId, array $body): array
    {
        $result = $this->regPrepareRaw($employmentId, $body);
        self::assertSame(201, $result['status'], 'Zaseknutí: příprava podání. ' . CanonicalJson::encode($result['body']));

        return $result['body'];
    }

    private function regTransition(int $employmentId, string $target, string $effectiveOn): void
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
     * Syntetický protokol ČSSZ. `accepted` s identifikátory přidělí OIČ
     * a ID PPV, `rejected` přihlášku odmítne.
     *
     * @param array<string,mixed> $prepared
     */
    private function regReceipt(array $prepared, string $outcome, ?string $oic = null, ?string $idPpv = null): void
    {
        $submissions = $this->container->get(PayrollSubmissionService::class);
        self::assertInstanceOf(PayrollSubmissionService::class, $submissions);
        $submissionId = (int) $prepared['submission_id'];
        $rowVersion = (int) $this->scalar(
            'SELECT row_version FROM payroll_submissions WHERE supplier_id = ? AND id = ?',
            [$this->supplierId, $submissionId],
        );
        $correlation = "synthetic-e2e-registration:{$submissionId}";
        $submitted = $submissions->transition($this->supplierId, $submissionId, $rowVersion, 'submitted', $correlation);
        $partId = (int) $prepared['part_id'];
        $outcomes = $oic !== null ? [new PayrollVerifiedReceiptFormOutcome(
            '11111111-2222-4333-8444-' . sprintf('%012d', $submissionId),
            $partId,
            1,
            $outcome === 'accepted' ? 'Accepted' : 'Rejected',
            $outcome,
            $oic,
            $idPpv,
            [],
        )] : [];
        $verifier = new class ($partId, $outcome, $outcomes) implements PayrollReceiptVerifierInterface {
            /** @param list<PayrollVerifiedReceiptFormOutcome> $outcomes */
            public function __construct(
                private readonly int $partId,
                private readonly string $outcome,
                private readonly array $outcomes,
            ) {}

            public function verify(
                string $bytes,
                string $channel,
                string $environment,
                ?string $expectedCorrelationReference,
            ): PayrollVerifiedReceipt {
                return new PayrollVerifiedReceipt(
                    $this->outcome,
                    $expectedCorrelationReference,
                    [$this->partId => $this->outcome],
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
            "synthetic-e2e-registration-receipt:{$submissionId}",
            $correlation,
            'CSSZ_REGZEC',
            $outcome,
            'vrep_apep',
            "synthetic-e2e-registration-key:{$submissionId}",
            $this->actors[0],
            $verifier,
        );
        self::assertTrue($receipt['trusted']);
    }

    private function regArtifactXml(int $submissionId): string
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT id FROM payroll_submission_artifacts
              WHERE supplier_id = ? AND submission_id = ? AND artifact_kind = "outbound_xml"
              ORDER BY id DESC LIMIT 1',
        );
        $statement->execute([$this->supplierId, $submissionId]);
        $submissions = $this->container->get(PayrollSubmissionService::class);
        self::assertInstanceOf(PayrollSubmissionService::class, $submissions);

        $xml = $submissions->artifactBytes($this->supplierId, (int) $statement->fetchColumn());
        // Ruční kontrola vět: MYUCTO_REG_DUMP=<adresář mimo repo> uloží XML.
        $dump = getenv('MYUCTO_REG_DUMP');
        if (is_string($dump) && $dump !== '' && is_dir($dump)) {
            file_put_contents(
                $dump . '/' . (new \ReflectionClass($this))->getShortName() . '-' . $this->name() . '-' . $submissionId . '.xml',
                $xml,
            );
        }

        return $xml;
    }

    /**
     * Nezávislá kontrola věty proti připnutému XSD podle jmenného prostoru
     * kořene (REGZEC 2025 = 1.4.0.4, REGZEC 2027 = 1.5, PREZEC 2026 = 1.2).
     */
    private function regAssertXsdValid(string $xml): string
    {
        $document = new \DOMDocument();
        self::assertTrue($document->loadXML($xml));
        $namespace = (string) $document->documentElement?->namespaceURI;
        $root = dirname(__DIR__, 2) . '/xsd/jmhz/';
        $xsd = match ($namespace) {
            'http://schemas.cssz.cz/REGZEC/2025' => $root . 'regzec-1.4.0.4/REGZEC25.xsd',
            'http://schemas.cssz.cz/REGZEC/2027' => $root . 'regzec-1.5/REGZEC27_1_5.xsd',
            'http://schemas.cssz.cz/PREZEC/2026' => $root . 'prezec-1.2/PREZEC26 1.2.xsd',
            default => self::fail('Neznámý jmenný prostor věty: ' . $namespace),
        };
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $valid = $document->schemaValidate($xsd);
        $errors = array_map(static fn (\LibXMLError $error): string => trim($error->message), libxml_get_errors());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        self::assertTrue($valid, 'XSD ' . basename($xsd) . ': ' . implode(' | ', $errors));

        return $namespace;
    }

    private function regSubmissionStatus(int $submissionId): string
    {
        return (string) $this->scalar(
            'SELECT status FROM payroll_submissions WHERE supplier_id = ? AND id = ?',
            [$this->supplierId, $submissionId],
        );
    }

    private function regIdentifier(int $employmentId, string $type): ?string
    {
        $value = $this->scalar(
            'SELECT valid_from FROM payroll_employment_external_ids
              WHERE supplier_id = ? AND employment_id = ? AND identifier_type = ? AND valid_to IS NULL
              ORDER BY id DESC LIMIT 1',
            [$this->supplierId, $employmentId, $type],
        );

        return $value === null || $value === false ? null : (string) $value;
    }
}
