<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

final class PayrollEmploymentJmhzActivityFamily
{
    private const DPP_ACTIVITY_CODES = ['T', 'U', 'V', 'W', 'X', 'Y', 'Z', 'ZA', 'ZB', 'ZC'];

    public static function appliesTo(string $relationType): bool
    {
        return in_array(
            $relationType,
            ['employment', 'small_scale_employment', 'dpc', 'dpp', 'partner_dependent', 'statutory_body'],
            true,
        );
    }

    /**
     * Druh činnosti pro ČSSZ u PRVNÍHO vztahu daného druhu u zaměstnavatele.
     *
     * Zakládaný zaměstnanec u firmy žádný jiný vztah nemá, takže „první
     * pracovní poměr" (1), „dohoda o pracovní činnosti" (A), „dohoda o
     * provedení práce" (T) i „člen statutárního orgánu" (S) jsou jednoznačné.
     * Dřív pole zůstalo prázdné a chybějící kód se poznal až na obrazovce
     * registrace nebo při sestavování měsíčního hlášení — tedy ve chvíli, kdy
     * už běží lhůta. Je to NÁVRH při založení, účetní ho může přepsat.
     *
     * @return array{0:?string,1:?string} [activity_code, relationship_detail_code]
     */
    public static function firstRelationDefaults(string $relationType): array
    {
        return match ($relationType) {
            'employment', 'small_scale_employment' => ['1', '1'],
            'dpc' => ['A', null],
            'dpp' => ['T', null],
            'partner_dependent', 'statutory_body' => ['S', '1'],
            default => [null, null],
        };
    }

    public static function matches(
        string $relationType,
        string $activityCode,
        ?string $relationshipDetailCode,
    ): bool {
        return match ($relationType) {
            'employment', 'small_scale_employment' => preg_match('/^[1-9]$/D', $activityCode) === 1
                && $relationshipDetailCode === '1',
            'dpc' => preg_match('/^[A-J]$/D', $activityCode) === 1
                && $relationshipDetailCode === null,
            'dpp' => in_array($activityCode, self::DPP_ACTIVITY_CODES, true)
                && $relationshipDetailCode === null,
            'partner_dependent', 'statutory_body' => $activityCode === 'S'
                && $relationshipDetailCode === '1',
            default => false,
        };
    }
}
