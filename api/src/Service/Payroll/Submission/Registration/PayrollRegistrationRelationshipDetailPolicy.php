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
        $mode = self::modeForActivity($activityCode);
        if ($mode === self::MODE_FORBIDDEN) {
            if ($relationshipDetailCode !== null && $relationshipDetailCode !== '') {
                throw new \InvalidArgumentException(
                    "Druh činnosti {$activityCode} zakazuje bližší určení pracovněprávního vztahu.",
                );
            }
            return null;
        }
        if ($relationshipDetailCode === null || $relationshipDetailCode === '') {
            throw new \InvalidArgumentException(
                'Druh činnosti vyžaduje bližší určení pracovněprávního vztahu.',
            );
        }
        if ($mode === self::MODE_SELECT) {
            if (!in_array($relationshipDetailCode, ['1', '2', '3'], true)) {
                throw new \InvalidArgumentException(
                    'Pro druh činnosti 1 až 9 musí být bližší určení 1, 2 nebo 3.',
                );
            }
            return $relationshipDetailCode;
        }
        if ($relationshipDetailCode !== '1') {
            throw new \InvalidArgumentException(
                'Pro tento druh činnosti musí být bližší určení 1 — žádné.',
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
