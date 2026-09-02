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

    private const CORRECTION_STANDARD = 'standard';
    private const CORRECTION_NONSTANDARD_1 = 'nonstandard_1';
    private const CORRECTION_NONSTANDARD_2 = 'nonstandard_2';
    private const CORRECTION_SPECIAL = 'special';

    private const NONSTANDARD_1_ACTIVITY_CODES = ['K', 'N', 'O', 'P', 'Q', 'R', 'S'];
    private const SPECIAL_CORRECTION_ACTIVITY_CODES = [
        '10', '11', '12', '13', '14', '15', '16', 'M',
    ];

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
        // Výjimky tu zůstávají: tahle třída rozhoduje, KTERÁ pole jsou pro dané
        // podání povinná, takže bez druhu činnosti nemá co vracet. Do seznamu
        // vad je přeloží volající (A1 builder je chytá a sbírá), uložení
        // rozpracovaného profilu tím nepadá.
        $activityName = PayrollRegistrationFieldVocabulary::label(
            'employment.activity_code',
        );
        if ($activityCode === null || $activityCode === '') {
            throw new PayrollRegistrationXmlException(
                $actionCode === 1
                    ? 'registration_regzec_a1_activity_missing'
                    : 'registration_regzec_event_activity_missing',
                $activityName . ' chybí — podle něj se teprve pozná, které '
                    . 'další údaje ČSSZ u tohohle zaměstnance chce. '
                    . PayrollRegistrationFieldVocabulary::describe(
                        'employment.activity_code',
                    ),
            );
        }
        if (!in_array($activityCode, self::ACTIVITY_CODES, true)) {
            throw new PayrollRegistrationXmlException(
                'registration_regzec_activity_invalid',
                $activityName . " „{$activityCode}\" není v číselníku ČSSZ. "
                    . 'Vyberte ho ze seznamu, ručně psaná zkratka neprojde. '
                    . PayrollRegistrationFieldVocabulary::describe(
                        'employment.activity_code',
                    ),
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
                PayrollRegistrationFieldVocabulary::action(
                    'REGZEC25',
                    $actionCode,
                ) . ' se u tohoto druhu činnosti (' . $activityCode
                    . ') nepodává — ČSSZ ho pro něj nepřipouští. Zkontrolujte '
                    . mb_lcfirst($activityName) . ' na kartě pracovního vztahu, '
                    . 'nebo zvolte jiný druh oznámení.',
            );
        }
        if ($actionCode === 1 && !$variantDataComplete) {
            throw new PayrollRegistrationXmlException(
                'registration_regzec_a1_variant_data_incomplete',
                'Přihlášení zaměstnance (REGZEC A1) zatím nemá vyplněné všechny '
                    . 'údaje, které ČSSZ u tohoto druhu činnosti ('
                    . $activityCode . ') vyžaduje. Uložit rozpracovaný profil '
                    . 'jde, podat ho ale až po doplnění — co chybí, ukáže '
                    . 'tlačítko Kontrola.',
            );
        }

        return $variant;
    }

    public static function requireActivityCorrectionTransition(
        string $sourceActivityCode,
        ?string $sourceRelationshipDetailCode,
        string $correctedActivityCode,
        ?string $correctedRelationshipDetailCode,
    ): void {
        self::requireActionVariant(
            4,
            $sourceActivityCode,
            $sourceRelationshipDetailCode,
        );
        self::requireActionVariant(
            4,
            $correctedActivityCode,
            $correctedRelationshipDetailCode,
        );

        $sourceCategory = self::activityCorrectionCategory(
            $sourceActivityCode,
            $sourceRelationshipDetailCode,
        );
        $correctedCategory = self::activityCorrectionCategory(
            $correctedActivityCode,
            $correctedRelationshipDetailCode,
        );
        $allowed = match ($sourceCategory) {
            self::CORRECTION_STANDARD => in_array(
                $correctedCategory,
                [self::CORRECTION_STANDARD, self::CORRECTION_NONSTANDARD_2],
                true,
            ),
            self::CORRECTION_NONSTANDARD_1,
            self::CORRECTION_NONSTANDARD_2 => $sourceCategory === $correctedCategory,
            self::CORRECTION_SPECIAL => false,
            default => throw new \LogicException('Neznámá kategorie opravy druhu činnosti.'),
        };
        if (!$allowed) {
            throw new PayrollRegistrationXmlException(
                'registration_a4_activity_correction_unsupported',
                'Změnu druhu činnosti z „' . $sourceActivityCode . '" na „'
                    . $correctedActivityCode . '" nejde poslat jako opravu '
                    . '(REGZEC A4) — ČSSZ takový přechod elektronickou opravou '
                    . 'nepřipouští. Původní přihlášení stornujte (REGZEC A8) '
                    . 'a podejte nové přihlášení (REGZEC A1) se správným '
                    . 'druhem činnosti.',
            );
        }
    }

    private static function activityCorrectionCategory(
        string $activityCode,
        ?string $relationshipDetailCode,
    ): string {
        if (in_array($activityCode, self::SPECIAL_CORRECTION_ACTIVITY_CODES, true)
            || (preg_match('/^[1-9]$/D', $activityCode) === 1
                && $relationshipDetailCode === '2')
        ) {
            return self::CORRECTION_SPECIAL;
        }
        if (in_array($activityCode, self::NONSTANDARD_1_ACTIVITY_CODES, true)) {
            return self::CORRECTION_NONSTANDARD_1;
        }
        if (preg_match('/^[1-9]$/D', $activityCode) === 1
            && $relationshipDetailCode === '3'
        ) {
            return self::CORRECTION_NONSTANDARD_2;
        }

        return self::CORRECTION_STANDARD;
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
