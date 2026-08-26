<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use MyInvoice\Service\Payment\CzechBankAccountValidator;
use MyInvoice\Service\Payment\IbanValidator;
use MyInvoice\Service\Payroll\PayrollPersonProfileValidator;
use PHPUnit\Framework\TestCase;

final class PayrollPersonProfileValidatorTest extends TestCase
{
    private PayrollPersonProfileValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new PayrollPersonProfileValidator(
            new IbanValidator(),
            new CzechBankAccountValidator(),
        );
    }

    public function testNormalizesCompleteSyntheticProfile(): void
    {
        $data = $this->validator->validate([
            'profile_status' => 'setup',
            'payout_method' => 'mixed',
            'cash_allocation_basis_points' => 2500,
            'payout_effective_on' => '2026-01-01',
            'secure_delivery_channel' => 'portal',
            'identity_history' => [[
                'full_name' => '  Jana Testovací  ',
                'first_name' => '  Jana  ',
                'last_name' => '  Testovací  ',
                'birth_surname' => 'Příkladová',
                'effective_from' => '2026-01-01',
                'effective_to' => null,
            ]],
            'addresses' => [[
                'address_type' => 'residence',
                'street_line' => 'Testovací 1',
                'city' => 'Praha',
                'postal_code' => '100 00',
                'country_code' => 'cz',
                'effective_from' => '2026-01-01',
            ]],
            'contacts' => [[
                'contact_type' => 'email',
                'value' => 'jana.testovaci@example.invalid',
                'is_primary' => true,
                'is_active' => true,
            ]],
            'identifiers' => [[
                'identifier_type' => 'ecp',
                'value' => '123456789',
            ]],
            'accounts' => [[
                'label' => 'Testovací účet',
                'bank_account' => '1000000005/0100',
                'allocation_basis_points' => 7500,
                'effective_from' => '2026-01-01',
                'is_active' => true,
            ]],
        ]);

        self::assertSame('Jana Testovací', $data['identity_history'][0]['full_name']);
        self::assertSame('Jana', $data['identity_history'][0]['first_name']);
        self::assertSame('Testovací', $data['identity_history'][0]['last_name']);
        self::assertSame('CZ', $data['addresses'][0]['country_code']);
        self::assertSame(2500, $data['cash_allocation_basis_points']);
        self::assertSame('123456789', $data['identifiers'][0]['value']);
    }

    public function testRejectsOverlappingIdentityAndAddressIntervals(): void
    {
        foreach ([
            [
                'identity_history' => [
                    [
                        'full_name' => 'Jana První',
                        'first_name' => 'Jana',
                        'last_name' => 'První',
                        'effective_from' => '2026-01-01',
                        'effective_to' => '2026-06-30',
                    ],
                    [
                        'full_name' => 'Jana Druhá',
                        'first_name' => 'Jana',
                        'last_name' => 'Druhá',
                        'effective_from' => '2026-06-30',
                    ],
                ],
            ],
            [
                'addresses' => [
                    [
                        'address_type' => 'residence',
                        'street_line' => 'První 1',
                        'city' => 'Praha',
                        'postal_code' => '100 00',
                        'country_code' => 'CZ',
                        'effective_from' => '2026-01-01',
                    ],
                    [
                        'address_type' => 'residence',
                        'street_line' => 'Druhá 2',
                        'city' => 'Praha',
                        'postal_code' => '100 00',
                        'country_code' => 'CZ',
                        'effective_from' => '2026-07-01',
                    ],
                ],
            ],
        ] as $payload) {
            try {
                $this->validator->validate($this->payload($payload));
                self::fail('Překryv účinnosti musí být odmítnut.');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('překrývají', $e->getMessage());
            }
        }
    }

    public function testExistingSecretsCanBePreservedButNewOnesRequirePlaintext(): void
    {
        $normalized = $this->validator->validate($this->payload([
            'identifiers' => [[
                'id' => 10,
                'identifier_type' => 'birth_number',
            ]],
            'accounts' => [[
                'id' => 20,
                'label' => 'Zachovaný účet',
                'allocation_basis_points' => 0,
                'effective_from' => '2026-01-01',
                'is_active' => false,
            ]],
        ]));

        self::assertNull($normalized['identifiers'][0]['value']);
        self::assertNull($normalized['accounts'][0]['bank_account']);

        foreach ([
            ['identifiers' => [['identifier_type' => 'birth_number']]],
            ['accounts' => [[
                'label' => 'Chybějící účet',
                'effective_from' => '2026-01-01',
            ]]],
        ] as $payload) {
            $this->expectInvalid($payload);
        }
    }

    public function testRejectsInvalidContactsAndDuplicatePrimaryContact(): void
    {
        $this->expectInvalid([
            'contacts' => [[
                'contact_type' => 'email',
                'value' => 'neni-email',
            ]],
        ]);
        $this->expectInvalid([
            'contacts' => [
                [
                    'contact_type' => 'phone',
                    'value' => '+420 111 222 333',
                    'is_primary' => true,
                ],
                [
                    'contact_type' => 'phone',
                    'value' => '+420 444 555 666',
                    'is_primary' => true,
                ],
            ],
        ]);
    }

    public function testRejectsMaskedPlaintextAndAcceptsMaskedReadModelForExistingRows(): void
    {
        $normalized = $this->validator->validate($this->payload([
            'identity_history' => [[
                'id' => 1,
                'full_name' => 'Jana Testovací',
                'first_name' => 'Jana',
                'last_name' => 'Testovací',
                'birth_surname_masked' => 'P••••••••',
                'effective_from' => '2026-01-01',
            ]],
            'addresses' => [[
                'id' => 2,
                'address_type' => 'residence',
                'address_masked' => '••••••, P••••, ••• ••, CZ',
                'effective_from' => '2026-01-01',
            ]],
            'contacts' => [[
                'id' => 3,
                'contact_type' => 'email',
                'value_masked' => 'j•••@example.invalid',
                'is_primary' => true,
                'is_active' => true,
            ]],
        ]));

        self::assertFalse($normalized['identity_history'][0]['birth_surname_present']);
        self::assertFalse($normalized['addresses'][0]['address_present']);
        self::assertNull($normalized['contacts'][0]['value']);

        foreach ([
            ['identity_history' => [[
                'id' => 1,
                'full_name' => 'Jana Testovací',
                'first_name' => 'Jana',
                'last_name' => 'Testovací',
                'birth_surname' => 'P••••••••',
                'effective_from' => '2026-01-01',
            ]]],
            ['addresses' => [[
                'id' => 2,
                'address_type' => 'residence',
                'street_line' => '••••••',
                'city' => 'Praha',
                'postal_code' => '100 00',
                'country_code' => 'CZ',
                'effective_from' => '2026-01-01',
            ]]],
            ['contacts' => [[
                'id' => 3,
                'contact_type' => 'email',
                'value' => 'j•••@example.invalid',
            ]]],
        ] as $payload) {
            $this->expectInvalid($payload);
        }
    }

    public function testNullSensitiveValuePreservesExistingBirthSurname(): void
    {
        $normalized = $this->validator->validate($this->payload([
            'identity_history' => [[
                'id' => 1,
                'full_name' => 'Jana Testovací',
                'first_name' => 'Jana',
                'last_name' => 'Testovací',
                'birth_surname' => null,
                'effective_from' => '2026-01-01',
            ]],
        ]));

        self::assertFalse($normalized['identity_history'][0]['birth_surname_present']);
        self::assertNull($normalized['identity_history'][0]['birth_surname']);
    }

    public function testRejectsIdentityWithoutStructuredFirstOrLastName(): void
    {
        foreach (['first_name', 'last_name'] as $missing) {
            $identity = [
                'full_name' => 'Jana Testovací',
                'first_name' => 'Jana',
                'last_name' => 'Testovací',
                'effective_from' => '2026-01-01',
            ];
            unset($identity[$missing]);
            $this->expectInvalid(['identity_history' => [$identity]]);
        }
    }

    public function testNormalizesRegistrationIdentityFactsAndRejectsInvalidValues(): void
    {
        $identity = [
            'full_name' => 'Jana Testovací',
            'first_name' => 'Jana',
            'last_name' => 'Testovací',
            'title_prefix' => ' Ing. ',
            'title_suffix' => '',
            'birth_date' => '1990-02-03',
            'birth_place' => ' Brno ',
            'birth_country_code' => 'cz',
            'citizenship_country_code' => 'sk',
            'sex' => 'female',
            'effective_from' => '2026-01-01',
        ];
        $normalized = $this->validator->validate($this->payload([
            'identity_history' => [$identity],
        ]))['identity_history'][0];

        self::assertSame('Ing.', $normalized['title_prefix']);
        self::assertNull($normalized['title_suffix']);
        self::assertSame('1990-02-03', $normalized['birth_date']);
        self::assertSame('Brno', $normalized['birth_place']);
        self::assertSame('CZ', $normalized['birth_country_code']);
        self::assertSame('SK', $normalized['citizenship_country_code']);
        self::assertSame('female', $normalized['sex']);

        foreach ([
            ['birth_date' => (new \DateTimeImmutable('tomorrow'))->format('Y-m-d')],
            ['birth_country_code' => 'CZE'],
            ['citizenship_country_code' => '1X'],
            ['sex' => 'F'],
        ] as $invalid) {
            $this->expectInvalid([
                'identity_history' => [array_replace($identity, $invalid)],
            ]);
        }
    }

    public function testNormalizesTypedIdentifiersAndRejectsArbitraryValues(): void
    {
        $normalized = $this->validator->validate($this->payload([
            'identifiers' => [
                ['identifier_type' => 'birth_number', 'value' => '000101 / 0009'],
                ['identifier_type' => 'ecp', 'value' => '123 456 789'],
                ['identifier_type' => 'vcp', 'value' => '654 321 987'],
                ['identifier_type' => 'foreign_tax_identifier', 'value' => 'de: ab 12 34'],
            ],
        ]));

        self::assertSame('000101/0009', $normalized['identifiers'][0]['value']);
        self::assertSame('123456789', $normalized['identifiers'][1]['value']);
        self::assertSame('654321987', $normalized['identifiers'][2]['value']);
        self::assertSame('DE:AB1234', $normalized['identifiers'][3]['value']);

        foreach ([
            ['identifier_type' => 'birth_number', 'value' => '1234567890'],
            ['identifier_type' => 'birth_number', 'value' => '0041010002'],
            ['identifier_type' => 'ecp', 'value' => '12345678'],
            ['identifier_type' => 'vcp', 'value' => '1234567890'],
            ['identifier_type' => 'foreign_tax_identifier', 'value' => 'bez-zeme'],
        ] as $identifier) {
            $this->expectInvalid(['identifiers' => [$identifier]]);
        }
    }

    public function testValidatesCzechAndIbanBankAccounts(): void
    {
        $czech = $this->validator->validate($this->payload([
            'accounts' => [[
                'label' => 'Český účet',
                'bank_account' => '1000000005 / 0100',
                'allocation_basis_points' => 0,
                'effective_from' => '2026-01-01',
                'is_active' => false,
            ]],
        ]));
        self::assertSame('1000000005/0100', $czech['accounts'][0]['bank_account']);

        $iban = $this->validator->validate($this->payload([
            'accounts' => [[
                'label' => 'IBAN',
                'bank_account' => 'CZ65 0800 0000 1920 0014 5399',
                'allocation_basis_points' => 0,
                'effective_from' => '2026-01-01',
                'is_active' => false,
            ]],
        ]));
        self::assertSame('CZ6508000000192000145399', $iban['accounts'][0]['bank_account']);

        foreach (['1000000006/0100', 'CZ6508000000192000145398'] as $account) {
            $this->expectInvalid(['accounts' => [[
                'label' => 'Neplatný účet',
                'bank_account' => $account,
                'allocation_basis_points' => 0,
                'effective_from' => '2026-01-01',
                'is_active' => false,
            ]]]);
        }
    }

    /** @param array<string,mixed> $payload */
    private function expectInvalid(array $payload): void
    {
        try {
            $this->validator->validate($this->payload($payload));
        } catch (\InvalidArgumentException $exception) {
            self::assertNotSame('', $exception->getMessage());
            return;
        }

        self::fail('Neplatný profil musí být odmítnut.');
    }

    public function testPartnerSettlementRequiresSettlementAccountCode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('kód účtu zápočtu');
        $this->validator->validate($this->payload([
            'payout_method' => 'partner_settlement',
            'cash_allocation_basis_points' => 0,
        ]));
    }

    public function testPartnerSettlementAcceptsAnalyticAccountCode(): void
    {
        $data = $this->validator->validate($this->payload([
            'payout_method' => 'partner_settlement',
            'partner_settlement_account_code' => ' 365.100 ',
            'cash_allocation_basis_points' => 0,
        ]));

        self::assertSame('partner_settlement', $data['payout_method']);
        self::assertSame('365.100', $data['partner_settlement_account_code']);
    }

    public function testSettlementAccountCodeIsRefusedForOtherPayoutMethods(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('jen u zápočtu na účet společníka');
        $this->validator->validate($this->payload([
            'payout_method' => 'cash',
            'partner_settlement_account_code' => '365.100',
        ]));
    }

    public function testOtherPayoutMethodsKeepSettlementAccountNull(): void
    {
        $data = $this->validator->validate($this->payload([]));

        self::assertNull($data['partner_settlement_account_code']);
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function payload(array $overrides): array
    {
        return array_replace([
            'profile_status' => 'setup',
            'payout_method' => 'cash',
            'cash_allocation_basis_points' => 10000,
            'payout_effective_on' => '2026-01-01',
            'secure_delivery_channel' => 'portal',
        ], $overrides);
    }
}
