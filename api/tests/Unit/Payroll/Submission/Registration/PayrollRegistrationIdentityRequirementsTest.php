<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission\Registration;

use MyInvoice\Service\Payroll\Submission\Registration\EmployerVariableSymbolPlausibility;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentityRequirements as Requirements;
use PHPUnit\Framework\TestCase;

final class PayrollRegistrationIdentityRequirementsTest extends TestCase
{
    /** @return array<string,mixed> */
    private static function completeIdentity(): array
    {
        return [
            'first_name' => 'Jana',
            'last_name' => 'Nováková',
            'birth_surname' => 'Nováková',
            'birth_place' => 'Testov',
            'citizenship_country_code' => 'CZ',
            'sex' => 'female',
            'birth_date' => '1991-02-03',
            'birth_country_code' => 'CZ',
        ];
    }

    public function testCompletePrezecPersonHasNothingMissing(): void
    {
        self::assertSame([], Requirements::missing(
            Requirements::AGENDA_PREZEC,
            self::completeIdentity(),
            ['birth_number' => '9152031234', 'ecp' => null, 'vcp' => null],
        ));
    }

    public function testEveryMissingItemIsListedAtOnceWithItsField(): void
    {
        $identity = self::completeIdentity();
        $identity['birth_surname'] = null;
        $identity['citizenship_country_code'] = null;
        $identity['birth_place'] = '  ';

        $missing = Requirements::missing(null, $identity, []);

        self::assertSame(
            ['identity.birth_surname', 'identity.birth_place', 'identity.citizenship_country_code'],
            array_column($missing, 'field'),
        );
        foreach ($missing as $problem) {
            self::assertSame('registration_identity', $problem['panel']);
            self::assertSame('person', $problem['target']);
            self::assertStringNotContainsString('(', $problem['message']);
        }
    }

    /** EDV PREZEC 1.5: rodné příjmení je od podání 1. 1. 2027 nepovinné, REGZEC beze změny. */
    public function testPrezecBirthSurnameIsOptionalOnlyWhenFiledFrom2027(): void
    {
        $identity = self::completeIdentity();
        $identity['birth_surname'] = null;
        $identifiers = ['birth_number' => '9152031234', 'ecp' => null, 'vcp' => null];

        self::assertSame(['identity.birth_surname'], array_column(
            Requirements::missing(Requirements::AGENDA_PREZEC, $identity, $identifiers, null, null, null, '2026-12-31'),
            'field',
        ));
        self::assertSame(['identity.birth_surname'], array_column(
            Requirements::missing(Requirements::AGENDA_PREZEC, $identity, $identifiers),
            'field',
        ));
        self::assertSame([], Requirements::missing(
            Requirements::AGENDA_PREZEC, $identity, $identifiers, null, null, null, '2027-01-01',
        ));
        self::assertSame(['identity.birth_surname'], array_column(
            Requirements::missing(Requirements::AGENDA_REGZEC, $identity, $identifiers, null, null, null, '2027-01-01'),
            'field',
        ));
    }

    public function testPrezecNeedsBirthNumberOrEcp(): void
    {
        $missing = Requirements::missing(
            Requirements::AGENDA_PREZEC,
            self::completeIdentity(),
            ['birth_number' => null, 'ecp' => null, 'vcp' => '612345678'],
        );

        self::assertSame(['identifier.value'], array_column($missing, 'field'));
        self::assertSame('identifiers', $missing[0]['panel']);
    }

    /** REGZEC25-client.bno-02: u českého občanství je rodné číslo nebo EČP povinné. */
    public function testRegzecRequiresBirthNumberOrEcpForCzechCitizen(): void
    {
        $identity = self::completeIdentity();
        $identity['citizenship_country_code'] = 'CZ';

        $missing = Requirements::missing(Requirements::AGENDA_REGZEC, $identity, []);
        self::assertSame(['identifier.value'], array_column($missing, 'field'));
        self::assertStringContainsString('REGZEC A1', $missing[0]['message']);
        self::assertSame(
            [],
            Requirements::missing(
                Requirements::AGENDA_REGZEC,
                $identity,
                ['ecp' => '1234567890'],
            ),
        );
    }

    public function testRegzecNeedsSexAndWithoutIdentifierTheForeignIdentity(): void
    {
        $identity = self::completeIdentity();
        $identity['citizenship_country_code'] = 'SK';
        $identity['sex'] = 'unspecified';
        $identity['birth_date'] = null;

        self::assertSame(
            ['identity.sex', 'identity.birth_date'],
            array_column(Requirements::missing(Requirements::AGENDA_REGZEC, $identity, []), 'field'),
        );
        self::assertSame(
            ['identity.sex'],
            array_column(Requirements::missing(
                Requirements::AGENDA_REGZEC,
                $identity,
                ['ecp' => '1234567890'],
            ), 'field'),
        );
    }

    /**
     * Odhláška A2 nese jen `client/@ikmpsv`, dohlášení A3 nenese rodné příjmení,
     * místo ani stát narození (EDV je u akcí 2 a 3 zakazuje). Evidence osoby je
     * proto u těchto akcí nesmí vyžadovat.
     */
    public function testFollowUpEventsDoNotRequireWhatTheSentenceDoesNotCarry(): void
    {
        $identity = self::completeIdentity();
        $identity['birth_surname'] = null;
        $identity['birth_place'] = null;
        $identity['birth_country_code'] = null;
        $identifiers = ['birth_number' => null, 'ecp' => null, 'vcp' => null];

        // A1 je dál vyžaduje.
        self::assertSame(
            ['identity.birth_surname', 'identity.birth_place'],
            array_column(Requirements::missing(Requirements::AGENDA_REGZEC, $identity, ['ecp' => '1234567890']), 'field'),
        );
        foreach ([
            'A2' => [2, null],
            'A3 bez dohlášení' => [3, null],
            'A3 jen údaje, které ONZ nevedla' => [3, 'minimal'],
            'A3 celý profil' => [3, 'full'],
            'A4' => [4, null],
            'A8' => [8, null],
        ] as $label => [$action, $completion]) {
            self::assertSame([], Requirements::missing(
                Requirements::AGENDA_REGZEC,
                $identity,
                $identifiers,
                null,
                $action,
                $completion,
            ), $label);
        }
    }

    public function testFullCompletionStillNeedsWhatItCarries(): void
    {
        $identity = self::completeIdentity();
        $identity['birth_date'] = null;
        $identity['sex'] = 'unspecified';
        $identity['citizenship_country_code'] = null;

        self::assertSame(
            ['identity.birth_date', 'identity.sex', 'identity.citizenship_country_code'],
            array_column(Requirements::missing(
                Requirements::AGENDA_REGZEC,
                $identity,
                [],
                null,
                3,
                'full',
            ), 'field'),
        );
        self::assertSame([], Requirements::missing(
            Requirements::AGENDA_REGZEC,
            $identity,
            [],
            null,
            3,
            'minimal',
        ));
    }

    /**
     * Štítek sekce na kartě osoby: „Doplněno" svítilo i bez občanství
     * a rodného příjmení, protože stačil jediný vyplněný titul.
     */
    public function testProfileStatusUsesTheSameRequirements(): void
    {
        $identity = self::completeIdentity();
        $identity['citizenship_country_code'] = null;

        self::assertSame(
            ['birth_surname', 'citizenship_country_code'],
            array_map(
                static fn (string $field): string => substr($field, strlen('identity.')),
                Requirements::missingForProfile($identity, false),
            ),
        );
        self::assertSame([], Requirements::missingForProfile(self::completeIdentity(), true));
    }

    public function testSummaryNamesItemsWithoutTechnicalCodes(): void
    {
        $summary = Requirements::summary(Requirements::missing(null, [], []));

        self::assertStringContainsString('rodné příjmení', $summary);
        self::assertStringContainsString('státní občanství', $summary);
        self::assertDoesNotMatchRegularExpression('/[a-z]+_[a-z]+/', $summary);
    }

    /** @return iterable<string,array{0:string,1:bool}> */
    public static function variableSymbols(): iterable
    {
        yield 'sestupná řada' => ['9876543210', true];
        yield 'vzestupná řada' => ['1234567890', true];
        yield 'samé nuly' => ['0000000000', true];
        yield 'krátká řada' => ['123456', true];
        yield 'běžný symbol' => ['9990001234', false];
        yield 'jiný běžný symbol' => ['4815162342', false];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('variableSymbols')]
    public function testPlaceholderVariableSymbolIsRecognised(string $value, bool $placeholder): void
    {
        self::assertSame(
            $placeholder,
            EmployerVariableSymbolPlausibility::placeholderReason($value) !== null,
        );
        $warning = EmployerVariableSymbolPlausibility::warning($value);
        self::assertSame($placeholder, $warning !== null);
        if ($warning !== null) {
            self::assertSame('employer_settings', $warning['target']);
        }
    }
}
