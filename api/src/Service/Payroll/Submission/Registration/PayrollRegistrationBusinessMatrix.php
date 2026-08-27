<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration;

final class PayrollRegistrationBusinessMatrix
{
    public const VARIANT_OST = 'OST';
    public const VARIANT_10 = '10';
    public const VARIANT_SPEC = 'SPEC';

    private const ACTIVITY_CODES = [
        '1', '2', '3', '4', '5', '6', '7', '8', '9',
        '10', '11', '12', '13', '14', '15', '16',
        'A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K',
        'M', 'N', 'O', 'P', 'Q', 'R', 'S',
        'T', 'U', 'V', 'W', 'X', 'Y', 'Z', 'ZA', 'ZB', 'ZC',
    ];

    private const SPEC_ACTIVITY_CODES = ['11', '12', '13', '14', 'M'];

    private const ALLOWED_VARIANTS = [
        1 => [self::VARIANT_OST, self::VARIANT_10, self::VARIANT_SPEC],
        2 => [self::VARIANT_OST, self::VARIANT_10, self::VARIANT_SPEC],
        3 => [self::VARIANT_OST, self::VARIANT_10, self::VARIANT_SPEC],
        4 => [self::VARIANT_OST, self::VARIANT_10, self::VARIANT_SPEC],
        5 => [self::VARIANT_OST],
        6 => [self::VARIANT_OST],
        7 => [self::VARIANT_OST],
        8 => [self::VARIANT_OST],
    ];

    public static function requireActionVariant(
        int $actionCode,
        ?string $activityCode,
        ?string $relationshipDetailCode,
        bool $variantDataComplete = true,
    ): string {
        if ($activityCode === null || $activityCode === '') {
            $message = $actionCode === 1
                ? 'REGZEC A1 nelze připravit: chybí povinný druh činnosti (atribut 10239).'
                : "REGZEC A{$actionCode} nelze připravit: chybí druh činnosti v neměnném podkladu události.";
            throw new PayrollRegistrationXmlException(
                $actionCode === 1
                    ? 'registration_regzec_a1_activity_missing'
                    : 'registration_regzec_event_activity_missing',
                $message,
            );
        }
        if (!in_array($activityCode, self::ACTIVITY_CODES, true)) {
            throw new PayrollRegistrationXmlException(
                'registration_regzec_activity_invalid',
                'Druh činnosti neodpovídá autoritativní matici REGZEC.',
            );
        }
        try {
            $relationshipDetailCode =
                PayrollRegistrationRelationshipDetailPolicy::requireForActivity(
                    $activityCode,
                    $relationshipDetailCode,
                );
        } catch (\InvalidArgumentException $exception) {
            throw new PayrollRegistrationXmlException(
                'registration_regzec_relationship_detail_invalid',
                $exception->getMessage(),
            );
        }

        $variant = self::variantFor(
            $activityCode,
            $relationshipDetailCode,
        );
        $allowed = self::ALLOWED_VARIANTS[$actionCode] ?? null;
        if ($allowed === null || !in_array($variant, $allowed, true)) {
            throw new PayrollRegistrationXmlException(
                'registration_regzec_action_variant_unsupported',
                "REGZEC A{$actionCode} není pro variantu {$variant} podle autoritativní business matice povolena.",
            );
        }
        if ($actionCode === 1 && !$variantDataComplete) {
            throw new PayrollRegistrationXmlException(
                'registration_regzec_a1_variant_data_incomplete',
                "REGZEC A1-{$variant} nelze připravit, dokud není zmrazená úplná povinná datová sada této varianty.",
            );
        }

        return $variant;
    }

    private static function variantFor(
        string $activityCode,
        ?string $relationshipDetailCode,
    ): string {
        if ($activityCode === '10') {
            return self::VARIANT_10;
        }
        if (in_array($activityCode, self::SPEC_ACTIVITY_CODES, true)
            || (preg_match('/^[1-9]$/D', $activityCode) === 1
                && $relationshipDetailCode === '2')
        ) {
            return self::VARIANT_SPEC;
        }

        return self::VARIANT_OST;
    }

    /** @return list<string> */
    public static function variantsForAction(int $actionCode): array
    {
        return self::ALLOWED_VARIANTS[$actionCode] ?? [];
    }
}
