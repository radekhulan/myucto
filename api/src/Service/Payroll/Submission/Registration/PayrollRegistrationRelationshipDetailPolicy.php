<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration;

final class PayrollRegistrationRelationshipDetailPolicy
{
    public const MODE_FORBIDDEN = 'forbidden';
    public const MODE_SELECT = 'select';
    public const MODE_FIXED_NONE = 'fixed_none';

    private const WITHOUT_RELATIONSHIP_DETAIL = [
        '10', '15', '16',
        'A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J',
        'T', 'U', 'V', 'W', 'X', 'Y', 'Z', 'ZA', 'ZB', 'ZC',
    ];

    public static function requireForActivity(
        string $activityCode,
        ?string $relationshipDetailCode,
    ): ?string {
        // Výjimky tu zůstávají: volající (business matice) je chytá a překládá
        // na sbíranou vadu, takže uložení rozpracovaného profilu tím nepadá.
        $detail = PayrollRegistrationFieldVocabulary::label(
            'employment.relationship_detail_code',
        );
        $where = PayrollRegistrationFieldVocabulary::describe(
            'employment.relationship_detail_code',
        );
        $mode = self::modeForActivity($activityCode);
        if ($mode === self::MODE_FORBIDDEN) {
            if ($relationshipDetailCode !== null && $relationshipDetailCode !== '') {
                /*
                 * Tady se pole MAŽE, nepřidává. Obecná věta „údaj doplňte
                 * na …" by si s tím protiřečila — hláška by v jedné větě
                 * říkala nechte prázdné a zároveň jděte to vyplnit.
                 */
                $place = PayrollRegistrationFieldVocabulary::where(
                    'employment.relationship_detail_code',
                );
                throw new \InvalidArgumentException(
                    $detail . " se u druhu činnosti „{$activityCode}“ "
                        . "nevyplňuje, teď je „{$relationshipDetailCode}“. "
                        . ($place === null
                            ? 'Vymažte ho přímo v tomhle formuláři.'
                            : "Vymažte ho na {$place}."),
                );
            }
            return null;
        }
        if ($relationshipDetailCode === null || $relationshipDetailCode === '') {
            throw new \InvalidArgumentException(
                $detail . " chybí — u druhu činnosti „{$activityCode}“ ho ČSSZ "
                    . 'vyžaduje. ' . $where,
            );
        }
        if ($mode === self::MODE_SELECT) {
            if (!in_array($relationshipDetailCode, ['1', '2', '3'], true)) {
                throw new \InvalidArgumentException(
                    $detail . ' musí být 1 (pracovní poměr), 2 (dohoda '
                        . 'o provedení práce) nebo 3 (dohoda o pracovní '
                        . "činnosti), teď je „{$relationshipDetailCode}“. "
                        . $where,
                );
            }
            return $relationshipDetailCode;
        }
        if ($relationshipDetailCode !== '1') {
            throw new \InvalidArgumentException(
                $detail . " musí být u druhu činnosti „{$activityCode}“ "
                    . 'hodnota 1 (žádné bližší určení), teď je '
                    . "„{$relationshipDetailCode}“. " . $where,
            );
        }

        return '1';
    }

    public static function modeForActivity(string $activityCode): string
    {
        if (in_array($activityCode, self::WITHOUT_RELATIONSHIP_DETAIL, true)) {
            return self::MODE_FORBIDDEN;
        }

        return preg_match('/^[1-9]$/D', $activityCode) === 1
            ? self::MODE_SELECT
            : self::MODE_FIXED_NONE;
    }
}
