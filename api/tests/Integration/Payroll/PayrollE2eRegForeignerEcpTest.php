<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveField;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentityService;
use MyInvoice\Tests\Support\PayrollE2eRegistrationTrait;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Přihláška A1 cizince bez rodného čísla, kterému ČSSZ přidělila evidenční
 * číslo pojištěnce (EČP): brigádník agentury na DPP. Věta nese EČP v `bno`
 * (EDV ID 10055), občanství, doklad totožnosti a přístup na trh práce. Cizinec,
 * který číslo ještě nemá, se přihlásí bez něj.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollE2eRegForeignerEcpTest extends TestCase
{
    use PayrollFullFlowTrait;
    use PayrollE2eRegistrationTrait;

    private const START_ON = '2026-11-16';
    private const ECP = '1000000005';

    protected function setUp(): void
    {
        $this->bootRegistrationFlow('2026-11-11');
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    public function testForeignerWithoutBirthNumberIsRegisteredByEcp(): void
    {
        [$employeeId, $employmentId] = $this->regPerson('Cizinka', 'Evidenční', 71, 'dpp', self::START_ON);
        $birthNumber = self::syntheticBirthNumber('1990-04-15', 'female', 71);
        $this->makeForeigner($employeeId, 'UA');
        $this->replaceBirthNumberWithEcp($employeeId, self::ECP);

        $saved = $this->regSaveA1Profile($employmentId, $this->foreignerProfile());

        self::assertSame([], $saved['problems'] ?? [], CanonicalJson::encode($saved['problems'] ?? []));
        $prepared = $this->regPrepare($employmentId, ['registration_mode' => 'full']);
        $xml = $this->regArtifactXml((int) $prepared['submission_id']);
        self::assertSame('http://schemas.cssz.cz/REGZEC/2025', $this->regAssertXsdValid($xml));
        self::assertStringContainsString('act="1"', $xml);
        self::assertMatchesRegularExpression('/<client [^>]*bno="' . self::ECP . '"/', $xml);
        self::assertStringNotContainsString($birthNumber, $xml);
        self::assertMatchesRegularExpression('/<stat [^>]*cnt="UA"/', $xml);
        self::assertMatchesRegularExpression('/<proofid [^>]*type="P"[^>]*num="SYN000071"/', $xml);
        self::assertMatchesRegularExpression('/<nocitizen [^>]*freeacc="A"/', $xml);
        self::assertStringContainsString('rel="T"', $xml);
        self::assertStringContainsString('relat="1222"', $xml);
    }

    /**
     * EDV 1.4.0.6, ID 10055: RČ/EČP je u A1 nepovinné, je-li státní občanství
     * různé od CZ. Cizinec, který v ČR ještě číslo nemá, se přihlásí bez něj
     * (EČP přidělí ČSSZ); přihláška se proto nesmí zastavit.
     */
    public function testForeignerWithoutBirthNumberAndEcpIsRegisteredWithoutNumber(): void
    {
        [$employeeId, $employmentId] = $this->regPerson('Cizinka', 'Bezčísla', 73, 'dpp', self::START_ON);
        $this->makeForeigner($employeeId, 'UA');
        $this->db->pdo()->prepare(
            'DELETE FROM payroll_person_identifiers
              WHERE supplier_id = ? AND employee_id = ? AND identifier_type = "birth_number"',
        )->execute([$this->supplierId, $employeeId]);

        $this->regSaveA1Profile($employmentId, $this->foreignerProfile());
        $prepared = $this->regPrepare($employmentId, ['registration_mode' => 'full']);

        $xml = $this->regArtifactXml((int) $prepared['submission_id']);
        self::assertSame('http://schemas.cssz.cz/REGZEC/2025', $this->regAssertXsdValid($xml));
        self::assertDoesNotMatchRegularExpression('/<client [^>]*bno=/', $xml);
        self::assertMatchesRegularExpression('/<stat [^>]*cnt="UA"/', $xml);
        self::assertMatchesRegularExpression('/<proofid [^>]*num="SYN000071"/', $xml);
    }

    private function makeForeigner(int $employeeId, string $country): void
    {
        $identities = $this->container->get(PayrollRegistrationIdentityService::class);
        self::assertInstanceOf(PayrollRegistrationIdentityService::class, $identities);
        $identityId = (int) $this->scalar(
            'SELECT id FROM payroll_person_identity_history WHERE supplier_id = ? AND employee_id = ?',
            [$this->supplierId, $employeeId],
        );
        $identities->saveIdentityFacts(
            $this->supplierId,
            $employeeId,
            $identityId,
            (int) $this->scalar(
                'SELECT row_version FROM payroll_person_identity_history WHERE supplier_id = ? AND id = ?',
                [$this->supplierId, $identityId],
            ),
            [
                'title_prefix' => null,
                'title_suffix' => null,
                'birth_date' => '1990-04-15',
                'birth_place' => 'Testov',
                'birth_country_code' => $country,
                'citizenship_country_code' => $country,
                'sex' => 'female',
            ],
        );
    }

    private function replaceBirthNumberWithEcp(int $employeeId, string $ecp): void
    {
        $sensitive = $this->container->get(PayrollSensitiveData::class);
        self::assertInstanceOf(PayrollSensitiveData::class, $sensitive);
        $id = (int) $this->scalar(
            'SELECT id FROM payroll_person_identifiers
              WHERE supplier_id = ? AND employee_id = ? AND identifier_type = "birth_number"',
            [$this->supplierId, $employeeId],
        );
        self::assertGreaterThan(0, $id);
        $sealed = $sensitive->seal($ecp, PayrollSensitiveField::PERSONAL_IDENTIFIER, $this->supplierId, $id);
        $this->db->pdo()->prepare(
            'UPDATE payroll_person_identifiers
                SET identifier_type = "ecp", value_ciphertext = ?, value_hash = ?, value_masked = ?,
                    row_version = row_version + 1
              WHERE supplier_id = ? AND id = ?',
        )->execute([$sealed->ciphertext, $sealed->lookupHash, $sealed->masked, $this->supplierId, $id]);
    }

    /** @return array<string,mixed> */
    private function foreignerProfile(): array
    {
        $profile = $this->regA1Profile(self::START_ON, 'T');
        // EDV 1.4.0.6 (ID 10071, 10526, 10248): u cizince povinné.
        $profile['employment']['expected_workplaces'] = 'Sídlo zaměstnavatele';
        $profile['employment']['required_education_code'] = 'T';
        $profile['proof_identity'] = [
            'type_code' => 'P',
            'number' => 'SYN000071',
            'foreign_issuer' => 'Municipal office, Testov',
            'country_code' => 'UA',
        ];
        $profile['foreign_worker'] = [
            'free_access' => true,
            'free_access_reason_code' => '1',
            'permit_type_code' => null,
            'issuing_labour_office_code' => null,
            'permit_identifier' => null,
            'permit_from' => null,
            'permit_to' => null,
        ];

        return $profile;
    }
}
