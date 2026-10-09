<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration;

/**
 * Které části změny (A3) a opravy (A4) smí nést která varianta datové věty.
 *
 * EDV 1.4.0.6 u variant 10 a SPEC většinu skupin zakazuje ("/" - podání bude
 * zamítnuto): A3-10 a A4-10 nenesou kontaktní adresu, pobyt v ČR, doklad,
 * rezidenci, zdravotní pojišťovnu, zdravotní stav, přístup na trh práce
 * ani důchod; A3-SPEC a A4-SPEC nenesou kontaktní adresu, důchod, příslušnost
 * k cizím předpisům, nejvyšší vzdělání a z pracovních údajů jen část. Klíče
 * odpovídají deltě události, kterou skládá {@see PayrollRegistrationEventService}.
 */
final class PayrollRegistrationDeltaVariantRule
{
    /** @var array<string,list<string>> */
    private const FORBIDDEN_KEYS = [
        PayrollRegistrationBusinessMatrix::VARIANT_10 => [
            'contact_address', 'czech_residence_address', 'tax_residency',
            'proof_identity', 'health_insurance_code', 'facts',
            'highest_education_code', 'foreign_worker', 'pension',
            'foreign_legislation', 'relationship_detail_code',
        ],
        PayrollRegistrationBusinessMatrix::VARIANT_SPEC => [
            'contact_address', 'pension', 'foreign_legislation',
            'highest_education_code',
        ],
    ];

    /**
     * Pracovní údaje, které varianta smí nést; ostatní skupina `employment`
     * (postavení, režim, profese, pozice…) je zakázaná.
     *
     * @var array<string,list<string>>
     */
    private const EMPLOYMENT_ALLOWED = [
        PayrollRegistrationBusinessMatrix::VARIANT_10 => ['actual_start_on'],
        PayrollRegistrationBusinessMatrix::VARIANT_SPEC => [
            'actual_start_on', 'workplace_city', 'contract_workplace',
            'workplace_municipality_code', 'expected_workplaces',
        ],
    ];

    /**
     * Cesty v deltě, které varianta nesmí nést.
     *
     * @param array<string,mixed> $delta
     * @return list<string>
     */
    public static function forbiddenPaths(string $variant, array $delta): array
    {
        $result = [];
        foreach (self::FORBIDDEN_KEYS[$variant] ?? [] as $key) {
            if (array_key_exists($key, $delta)) {
                $result[] = $key;
            }
        }
        // Serializér slévá `facts.highest_education_code` do `fact/@highedu`
        // stejně jako samostatný klíč, takže SPEC ho nesmí nést ani tudy.
        if ($variant === PayrollRegistrationBusinessMatrix::VARIANT_SPEC
            && is_array($delta['facts'] ?? null)
            && array_key_exists('highest_education_code', $delta['facts'])
        ) {
            $result[] = 'facts.highest_education_code';
        }
        $allowed = self::EMPLOYMENT_ALLOWED[$variant] ?? null;
        if ($allowed !== null && is_array($delta['employment'] ?? null)) {
            foreach (array_keys($delta['employment']) as $field) {
                if (!in_array($field, $allowed, true)) {
                    $result[] = 'employment.' . $field;
                }
            }
        }

        return $result;
    }
}
