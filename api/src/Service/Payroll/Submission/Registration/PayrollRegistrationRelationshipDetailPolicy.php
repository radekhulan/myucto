<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration;

final class PayrollRegistrationRelationshipDetailPolicy
{
    public static function requireForActivity(
        string $activityCode,
        ?string $relationshipDetailCode,
    ): ?string {
        if ($activityCode === '10') {
            if ($relationshipDetailCode !== null && $relationshipDetailCode !== '') {
                throw new \InvalidArgumentException(
                    'Druh činnosti 10 zakazuje bližší určení pracovněprávního vztahu.',
                );
            }
            return null;
        }
        if ($relationshipDetailCode === null || $relationshipDetailCode === '') {
            throw new \InvalidArgumentException(
                'Druh činnosti vyžaduje bližší určení pracovněprávního vztahu.',
            );
        }
        if (preg_match('/^[1-9]$/D', $activityCode) === 1) {
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
}
