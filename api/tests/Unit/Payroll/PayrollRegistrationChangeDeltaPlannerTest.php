<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use MyInvoice\Service\Payroll\Submission\Registration\Change\PayrollRegistrationChangeDeltaPlanner;
use MyInvoice\Service\Payroll\Submission\Registration\Change\PayrollRegistrationChangeDetector;
use MyInvoice\Service\Payroll\Submission\Registration\Change\PayrollRegistrationReportableProfile;
use MyInvoice\Service\Payroll\Submission\Registration\Change\PayrollRegistrationReportableProfileBuilder;
use PHPUnit\Framework\TestCase;

/**
 * REG-04 a REG-06: co z rozešlých údajů plánovač změny A3 dokáže podat jedním
 * kliknutím, a co musí zůstat v nepodporovaných s důvodem.
 */
final class PayrollRegistrationChangeDeltaPlannerTest extends TestCase
{
    private const IDENTITY = [
        'first_name' => 'Jana',
        'last_name' => 'Dvořáková',
        'title_prefix' => null,
        'title_suffix' => null,
        'birth_surname' => 'Dvořáková',
        'birth_date' => '1985-03-14',
        'sex' => 'female',
        'citizenship_country_code' => 'CZ',
    ];

    private const IDENTIFIERS = [
        'birth_number' => '8553141230',
        'ecp' => null,
        'vcp' => null,
    ];

    /** @return array<string,mixed> */
    private function profile(): array
    {
        return [
            'permanent_address' => [
                'street' => 'Dlouhá',
                'house_number' => '12',
                'orientation_number' => null,
                'city' => 'Praha',
                'postal_code' => '11000',
                'country_code' => 'CZ',
                'ruian_point' => null,
            ],
            'contact_address' => null,
            'czech_residence_address' => null,
            'tax_residency' => [
                'country_code' => 'CZ',
                'identifier_type' => null,
                'identifier' => null,
                'residence_address' => null,
            ],
            'employment' => [
                'activity_code' => '01',
                'relationship_detail_code' => '1',
                'actual_start_on' => '2026-08-01',
            ],
            'pension' => null,
            'health_insurance_code' => '111',
            'facts' => [
                'highest_education_code' => 'T',
                'disability_card' => false,
                'health_restrictions' => [],
            ],
            'foreign_legislation' => ['applies' => false, 'country_code' => null],
            'proof_identity' => null,
            'foreign_worker' => null,
        ];
    }

    /**
     * @param array<string,mixed> $baselineProfile
     * @param array<string,mixed> $currentProfile
     * @param array<string,mixed> $currentIdentity
     * @return array{changes:array<string,mixed>,unsupported:list<array{path:string,reason_code:string}>}
     */
    private function plan(
        array $baselineProfile,
        array $currentProfile,
        array $currentIdentity = self::IDENTITY,
        ?string $previousSurnames = null,
    ): array {
        $builder = new PayrollRegistrationReportableProfileBuilder();
        $baseline = $builder->build(self::IDENTITY, self::IDENTIFIERS, $baselineProfile);
        $current = $builder->build($currentIdentity, self::IDENTIFIERS, $currentProfile);

        return (new PayrollRegistrationChangeDeltaPlanner())->plan(
            (new PayrollRegistrationChangeDetector())->compare($baseline, $current),
            $current,
            '2026-09-20',
            $previousSurnames,
        );
    }

    public function testSurnameChangeIsFileableWithPreviousSurname(): void
    {
        $identity = self::IDENTITY;
        $identity['last_name'] = 'Nováková';

        $plan = $this->plan($this->profile(), $this->profile(), $identity, 'Dvořáková');

        self::assertSame([], $plan['unsupported']);
        self::assertSame([
            'first_name' => 'Jana',
            'last_name' => 'Nováková',
            'previous_surnames' => 'Dvořáková',
        ], $plan['changes']['identity']);
    }

    public function testCitizenshipChangeAloneDoesNotCarryTheName(): void
    {
        $identity = self::IDENTITY;
        $identity['citizenship_country_code'] = 'SK';

        $plan = $this->plan($this->profile(), $this->profile(), $identity);

        self::assertSame(
            ['citizenship_country_code' => 'SK'],
            $plan['changes']['identity'],
        );
    }

    public function testPensionDisabilityCardAndRestrictionAreFileable(): void
    {
        $changed = $this->profile();
        $changed['pension'] = [
            'type_code' => 'S',
            'received_from' => '2026-08-01',
            'early_retirement' => false,
            'reduced_retirement_age' => null,
        ];
        $changed['facts']['disability_card'] = true;
        $changed['facts']['health_restrictions'] = [
            ['type_code' => '2', 'from' => '2026-08-15', 'to' => null],
        ];

        $plan = $this->plan($this->profile(), $changed);

        self::assertSame([], $plan['unsupported']);
        self::assertSame([
            'early_retirement' => false,
            'received_from' => '2026-08-01',
            'type_code' => 'S',
        ], $plan['changes']['pension']);
        self::assertSame([
            'disability_card' => true,
            'health_restrictions' => [['from' => '2026-08-15', 'type_code' => '2']],
        ], $plan['changes']['facts']);
    }

    /** REGZEC25-PROC.A3.scope-01: posun data nástupu se hlásí jako A3 (job/@fro). */
    public function testShiftedStartDateIsFileableAsA3(): void
    {
        $changed = $this->profile();
        $changed['employment']['actual_start_on'] = '2026-08-05';

        $plan = $this->plan($this->profile(), $changed);

        self::assertSame([], $plan['unsupported']);
        self::assertSame('2026-08-05', $plan['changes']['employment']['actual_start_on']);
    }

    public function testRemovedRestrictionAndMultipleRestrictionsStayManual(): void
    {
        $baseline = $this->profile();
        $baseline['facts']['health_restrictions'] = [
            ['type_code' => '1', 'from' => '2026-01-01', 'to' => null],
        ];
        $removed = $this->plan($baseline, $this->profile());
        self::assertSame(
            'registration_change_value_removal_unsupported',
            $removed['unsupported'][0]['reason_code'],
        );

        $many = $this->profile();
        $many['facts']['health_restrictions'] = [
            ['type_code' => '1', 'from' => '2026-01-01', 'to' => null],
            ['type_code' => '2', 'from' => '2026-02-01', 'to' => null],
        ];
        $multiple = $this->plan($this->profile(), $many);
        self::assertSame(
            'registration_change_health_restrictions_multiple',
            $multiple['unsupported'][0]['reason_code'],
        );
        self::assertArrayNotHasKey('facts', $multiple['changes']);
    }

    public function testCzechResidenceAddressAndProofOfIdentityAreFileable(): void
    {
        $changed = $this->profile();
        $changed['czech_residence_address'] = [
            'street' => 'Krátká',
            'house_number' => '3',
            'orientation_number' => null,
            'city' => 'Brno',
            'postal_code' => '60200',
            'country_code' => null,
            'ruian_point' => null,
        ];
        $changed['proof_identity'] = [
            'type_code' => 'P',
            'number' => 'SYN-12345',
            'foreign_issuer' => null,
            'country_code' => 'SK',
        ];

        $plan = $this->plan($this->profile(), $changed);

        self::assertSame([], $plan['unsupported']);
        self::assertSame('Brno', $plan['changes']['czech_residence_address']['city']);
        self::assertArrayNotHasKey('country_code', $plan['changes']['czech_residence_address']);
        self::assertSame('SYN-12345', $plan['changes']['proof_identity']['number']);
    }

    /** Kontrola 5 (EDV 1.4.0.7): úřad práce 10107 jen u povolení k zaměstnání. */
    public function testLabourOfficeTravelsOnlyWithEmploymentPermit(): void
    {
        $permit = static fn (string $type, ?string $office, string $to): array => [
            'free_access' => false,
            'free_access_reason_code' => null,
            'permit_type_code' => $type,
            'issuing_labour_office_code' => $office,
            'permit_identifier' => 'SYN-0001',
            'permit_from' => '2026-01-01',
            'permit_to' => $to,
        ];
        $base = $this->profile();
        $base['foreign_worker'] = $permit('2', 'HMP', '2026-12-31');
        $extended = $base;
        $extended['foreign_worker'] = $permit('2', 'HMP', '2027-12-31');
        $extended['foreign_worker']['permit_identifier'] = 'SYN-0002';

        $plan = $this->plan($base, $extended);
        self::assertSame([], $plan['unsupported']);
        self::assertArrayNotHasKey('issuing_labour_office_code', $plan['changes']['foreign_worker']);

        $employment = $extended;
        $employment['foreign_worker'] = $permit('1', 'HMP', '2027-12-31');
        $employment['foreign_worker']['permit_identifier'] = 'SYN-0002';
        $plan = $this->plan($base, $employment);
        self::assertSame('HMP', $plan['changes']['foreign_worker']['issuing_labour_office_code']);

        $employment['foreign_worker']['issuing_labour_office_code'] = null;
        $plan = $this->plan($base, $employment);
        self::assertArrayNotHasKey('foreign_worker', $plan['changes']);
        self::assertSame(
            'registration_change_foreign_permit_incomplete',
            $plan['unsupported'][0]['reason_code'],
        );
    }

    public function testForeignLegislationCountryChangeNeedsRunningLegislation(): void
    {
        $running = $this->profile();
        $running['foreign_legislation'] = ['applies' => true, 'country_code' => 'SK'];
        $moved = $running;
        $moved['foreign_legislation']['country_code'] = 'DE';

        $plan = $this->plan($running, $moved);
        self::assertSame(
            ['applies' => true, 'country_code' => 'DE'],
            $plan['changes']['foreign_legislation'],
        );

        // Stát se objevil spolu se vznikem příslušnosti: to je A6, ne A3.
        $started = $this->plan($this->profile(), $running);
        self::assertArrayNotHasKey('foreign_legislation', $started['changes']);
        self::assertSame(
            'registration_change_requires_other_action',
            $started['unsupported'][0]['reason_code'],
        );
    }

    /**
     * REG-06: rezidence v jiném státě než ČR bez adresy bydliště ČSSZ odmítne
     * (zásady REGZEC 10519 až 10524). Položka musí skončit v nepodporovaných,
     * ne jako podání, které ČSSZ vrátí.
     */
    public function testForeignTaxResidencyWithoutAddressIsUnsupported(): void
    {
        $changed = $this->profile();
        $changed['tax_residency']['country_code'] = 'US';

        $plan = $this->plan($this->profile(), $changed);

        self::assertArrayNotHasKey('tax_residency', $plan['changes']);
        self::assertSame([[
            'path' => 'tax_residency.residence_address',
            'reason_code' => 'registration_change_tax_residence_address_incomplete',
        ]], $plan['unsupported']);
    }

    public function testForeignTaxResidencyCarriesAddressAndIdentifier(): void
    {
        $changed = $this->profile();
        $changed['tax_residency'] = [
            'country_code' => 'US',
            'identifier_type' => 'T',
            'identifier' => 'SYN-TIN-1',
            'residence_address' => [
                'street' => 'Main',
                'house_number' => '1',
                'orientation_number' => null,
                'city' => 'Springfield',
                'postal_code' => '12345',
                'country_code' => 'US',
                'ruian_point' => null,
            ],
        ];

        $plan = $this->plan($this->profile(), $changed);

        self::assertSame([], $plan['unsupported']);
        $residency = $plan['changes']['tax_residency'];
        self::assertSame('US', $residency['country_code']);
        self::assertSame('2026-09-20', $residency['changed_on']);
        self::assertSame('T', $residency['identifier_type']);
        self::assertSame('Springfield', $residency['residence_address']['city']);

        // Citlivá hodnota se do odpovědi API nedostane.
        $redacted = PayrollRegistrationChangeDeltaPlanner::redact($plan['changes']);
        self::assertNull($redacted['tax_residency']['identifier']);
        self::assertSame('SYN-TIN-1', $plan['changes']['tax_residency']['identifier']);
    }

    public function testTaxIdentifierTypeLongerThanOneCharacterIsNotPromised(): void
    {
        $changed = $this->profile();
        $changed['tax_residency']['identifier_type'] = 'TIN';
        $changed['tax_residency']['identifier'] = 'SYN-TIN-3';

        $plan = $this->plan($this->profile(), $changed);

        self::assertArrayNotHasKey('tax_residency', $plan['changes']);
        self::assertSame(
            'registration_change_tax_identifier_incomplete',
            $plan['unsupported'][0]['reason_code'],
        );
    }

    public function testDomesticTaxResidencyNeverCarriesAnAddress(): void
    {
        $changed = $this->profile();
        $changed['tax_residency']['identifier_type'] = 'T';
        $changed['tax_residency']['identifier'] = 'SYN-TIN-2';

        $plan = $this->plan($this->profile(), $changed);

        self::assertArrayNotHasKey('residence_address', $plan['changes']['tax_residency']);
    }

    public function testProfileBuilderKeepsTypeForPlannerInputs(): void
    {
        // Pojistka proti tichému rozbití vstupu plánovače: profil je tvořen
        // jen řetězci, bool se mění na '1' / '0'.
        $profile = (new PayrollRegistrationReportableProfileBuilder())
            ->build(self::IDENTITY, self::IDENTIFIERS, $this->profile());

        self::assertInstanceOf(PayrollRegistrationReportableProfile::class, $profile);
        self::assertSame('0', $profile->get('facts.disability_card'));
    }
}
