<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Service\Codebook\HealthInsurers;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationCodebooks;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentityService;
use MyInvoice\Tests\Support\PayrollE2eRegistrationTrait;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Číselníky v přihlášce A1 a na kartě osoby: hodnota mimo číselník se
 * nesmí dostat do věty REGZEC ani tiše uložit, účetní ji uvidí jako vadu
 * s kotvou na pole.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollE2eRegCodebookTest extends TestCase
{
    use PayrollFullFlowTrait;
    use PayrollE2eRegistrationTrait;

    private const START_ON = '2026-11-16';

    protected function setUp(): void
    {
        $this->bootRegistrationFlow('2026-11-11');
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    /**
     * Kód obce pracoviště (municode, EDV číselník CIS_OBCE = CISOB) a kód
     * CZ-ISCO (clas) mimo číselník nebo vyřazený: profil A1 zůstane
     * rozpracovaný s vadou na poli a přihláška se nepřipraví.
     *
     * @param array<string,string> $employment
     */
    #[DataProvider('invalidEmploymentCodes')]
    public function testA1ProfileRejectsCodesOutsideTheCodebook(array $employment, string $field): void
    {
        [, $employmentId] = $this->regPerson('Číselník', 'Vadný', 41, 'employment', self::START_ON);
        $profile = $this->regA1Profile(self::START_ON);
        $profile['employment'] = $employment + $profile['employment'];

        $saved = $this->regSaveA1Profile($employmentId, $profile, 'draft');

        $problem = $this->problem($saved['problems'] ?? [], $field);
        self::assertNotNull($problem, CanonicalJson::encode($saved['problems'] ?? []));
        self::assertSame('codebook', $problem['message_key'] ?? null, CanonicalJson::encode($problem));
        $result = $this->regPrepareRaw($employmentId, ['registration_mode' => 'full']);
        self::assertSame(422, $result['status'], CanonicalJson::encode($result['body']));
        self::assertSame(0, (int) $this->scalar(
            'SELECT COUNT(*) FROM payroll_submissions WHERE supplier_id = ?',
            [$this->supplierId],
        ));
    }

    /** @return iterable<string,array{0:array<string,string>,1:string}> */
    public static function invalidEmploymentCodes(): iterable
    {
        yield 'obec mimo CISOB' => [['workplace_municipality_code' => '999999'], 'employment.workplace_municipality_code'];
        yield 'CZ-ISCO mimo klasifikaci' => [['profession_code' => '99999'], 'employment.profession_code'];
        yield 'CZ-ISCO vyřazený' => [['profession_code' => '32114'], 'employment.profession_code'];
    }

    /**
     * Nástup od 1. 1. 2027: číselník obcí 2027 ještě není vyhlášený. Platný
     * kód z posledního vyhlášeného (k 31. 12. 2026) projde a A1 se připraví
     * jako REGZEC27; kód, který nebyl platný ani tehdy, je vada.
     */
    public function testA1For2027StartChecksMunicipalityAgainstLastPublishedCodebook(): void
    {
        $this->regToday('2026-12-28');
        [, $valid] = $this->regPerson('Leden', 'Platná', 43, 'employment', '2027-01-04');
        $this->regSaveA1Profile($valid, $this->regA1Profile('2027-01-04'));
        $prepared = $this->regPrepare($valid, ['registration_mode' => 'full']);
        $xml = $this->regArtifactXml((int) $prepared['submission_id']);
        self::assertSame('http://schemas.cssz.cz/REGZEC/2027', $this->regAssertXsdValid($xml));
        self::assertStringContainsString('municode="554782"', $xml);

        [, $invalid] = $this->regPerson('Leden', 'Neplatná', 45, 'employment', '2027-01-04');
        $profile = $this->regA1Profile('2027-01-04');
        $profile['employment']['workplace_municipality_code'] = '999999';
        $saved = $this->regSaveA1Profile($invalid, $profile, 'draft');
        $problem = $this->problem($saved['problems'] ?? [], 'employment.workplace_municipality_code');
        self::assertNotNull($problem, CanonicalJson::encode($saved['problems'] ?? []));
        self::assertStringContainsString('2027', (string) $problem['message']);
    }

    /**
     * Občanství a stát narození na kartě osoby: kód mimo číselník států
     * (EDV C_STAT, CZEM) je validační chyba, ne tiše uložená hodnota.
     */
    public function testIdentityFactsRejectUnknownCountry(): void
    {
        [$employeeId] = $this->regPerson('Stát', 'Neznámý', 47, 'employment', self::START_ON);
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
        try {
            $identities->saveIdentityFacts($this->supplierId, $employeeId, $identityId, $rowVersion, [
                'title_prefix' => null,
                'title_suffix' => null,
                'birth_date' => '1990-04-15',
                'birth_place' => 'Testov',
                'birth_country_code' => 'CZ',
                'citizenship_country_code' => 'ZZ',
                'sex' => 'female',
            ]);
            self::fail('Občanství ZZ se uložilo.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('ZZ', $exception->getMessage());
        }
        self::assertSame('CZ', (string) $this->scalar(
            'SELECT citizenship_country_code FROM payroll_person_identity_history WHERE supplier_id = ? AND id = ?',
            [$this->supplierId, $identityId],
        ));
    }

    /**
     * Karta osoby zná 7 zdravotních pojišťoven (plátci pojistného), přihláška
     * A1 navíc 300 (Samoplátce) a 999 (Ostatní) z EDV C_ZPOJ. Rozdíl je
     * vědomý a jen tenhle.
     */
    public function testHealthInsurerCodebooksDifferOnlyByRegistrationOnlyCodes(): void
    {
        self::assertSame(
            ['300', '999'],
            array_values(array_diff(PayrollRegistrationCodebooks::HEALTH_INSURER, HealthInsurers::codes())),
        );
        self::assertSame([], array_values(array_diff(HealthInsurers::codes(), PayrollRegistrationCodebooks::HEALTH_INSURER)));
    }

    /**
     * @param list<array<string,mixed>> $problems
     * @return array<string,mixed>|null
     */
    private function problem(array $problems, string $field): ?array
    {
        foreach ($problems as $problem) {
            if (($problem['field'] ?? null) === $field) {
                return $problem;
            }
        }

        return null;
    }
}
