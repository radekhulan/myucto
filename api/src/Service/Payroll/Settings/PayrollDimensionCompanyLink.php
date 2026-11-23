<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Settings;

/**
 * Který druh firemní dimenze smí nést mzdová dimenze daného typu.
 *
 * Hodnota druhu Projekt nese zakázku (`dimension_values.project_id`) a hodnota
 * druhu Středisko kód střediska. Zaúčtování mezd z nich doplní `project_id`
 * a `cost_center` řádků deníku
 * ({@see \MyInvoice\Service\Accounting\Dimension\DimensionStamper}). Mzdová
 * zakázka navázaná na středisko by tak zakázku do deníku nikdy nedostala
 * a mzdová činnost navázaná na projekt by zakázku přepsala. Proto je vazba
 * párová: zakázka jen na Projekt, středisko jen na Středisko a činnost na
 * cokoli jiného. Stejné párování dělá editor mzdových dimenzí, když podle
 * vybrané firemní hodnoty předvyplní typ.
 *
 * Jedno místo pravdy pro uložení mzdové dimenze
 * ({@see PayrollDimensionService}) i pro kontrolu před mzdovým během
 * ({@see \MyInvoice\Service\Payroll\Run\PayrollRunReadinessService}).
 */
final class PayrollDimensionCompanyLink
{
    /** Typ mzdové dimenze → jediný druh firemní dimenze, na který smí ukazovat. */
    public const KIND_BY_TYPE = [
        'cost_center' => 'cost_center',
        'project' => 'project',
    ];

    private const TYPE_LABELS = [
        'cost_center' => 'Středisko',
        'project' => 'Zakázka',
        'activity' => 'Činnost',
    ];

    private const KIND_LABELS = [
        'cost_center' => 'Středisko',
        'project' => 'Projekt',
    ];

    public static function fits(string $payrollType, string $companyKind): bool
    {
        if (isset(self::KIND_BY_TYPE[$payrollType])) {
            return self::KIND_BY_TYPE[$payrollType] === $companyKind;
        }

        return !in_array($companyKind, self::KIND_BY_TYPE, true);
    }

    /** Věta, která říká, na co se mzdová dimenze daného typu navazuje. */
    public static function requirement(string $payrollType): string
    {
        $kind = self::KIND_BY_TYPE[$payrollType] ?? null;
        if ($kind !== null) {
            return sprintf(
                'Mzdovou dimenzi typu %s lze navázat jen na hodnotu firemní dimenze druhu %s.',
                self::typeLabel($payrollType),
                self::KIND_LABELS[$kind],
            );
        }

        return sprintf(
            'Mzdovou dimenzi typu %s nelze navázat na firemní dimenzi druhu Projekt ani Středisko; '
            . 'na ně patří mzdová dimenze typu Zakázka, respektive Středisko.',
            self::typeLabel($payrollType),
        );
    }

    public static function typeLabel(string $payrollType): string
    {
        return self::TYPE_LABELS[$payrollType] ?? $payrollType;
    }
}
