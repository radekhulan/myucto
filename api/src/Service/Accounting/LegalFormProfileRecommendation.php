<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

final class LegalFormProfileRecommendation
{
    public const PROFILE_NONPROFIT = 'nonprofit';
    public const PROFILE_SVJ = 'svj';
    public const PROFILE_UNKNOWN = 'unknown';

    private const NONPROFIT_LEGAL_FORMS = [
        '117', '118', '141', '161', '706', '736',
    ];

    /**
     * @return array{
     *   entity_profile:'nonprofit'|'svj'|'unknown',
     *   accounting_framework:?string,
     *   public_benefit_taxpayer:?bool
     * }
     */
    public static function forLegalForm(?string $legalForm, ?string $accountingMode): array
    {
        $legalForm = trim((string) $legalForm);
        $profile = match (true) {
            $legalForm === '145' => self::PROFILE_SVJ,
            in_array($legalForm, self::NONPROFIT_LEGAL_FORMS, true) => self::PROFILE_NONPROFIT,
            default => self::PROFILE_UNKNOWN,
        };

        return [
            'entity_profile' => $profile,
            'accounting_framework' => $profile !== self::PROFILE_UNKNOWN && $accountingMode === 'double_entry'
                ? AccountingFrameworkService::CZ_504
                : null,
            'public_benefit_taxpayer' => $profile === self::PROFILE_SVJ ? false : null,
        ];
    }
}
