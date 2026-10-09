<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration;

/**
 * Které části uložené události A2 až A4 by se do věty zapsaly, ačkoli je
 * varianta zakazuje (EDV 1.4.0.6, list Slovník, kód „/"). Službu oznámení
 * hlídá {@see PayrollRegistrationEventService}; serializér se na ni ale
 * nesmí spolehnout, protože payload jde sestavit i přímo. Proto tohle
 * pravidlo volá i serializér a zakázanou část odmítne, místo aby ji zapsal.
 *
 * - A2-10 a A2-SPEC: bez „ukončeno smrtí" (10225); A2-10 bez celé části
 *   podpory v nezaměstnanosti, A2-SPEC z ní jen důvod předčasného ukončení.
 * - A3 a A4: delta podle {@see PayrollRegistrationDeltaVariantRule}; mimo OST
 *   bez cizozemského nositele (`forin`), u A4 mimo OST bez vzniku
 *   zaměstnání (`contractfro`).
 */
final class PayrollRegistrationEventVariantRule
{
    private const A2_SPEC_UNEMPLOYMENT_ALLOWED = ['early_termination_reason'];

    /**
     * @param array<string,mixed> $data `data` uložené události
     * @return list<string> tečkové cesty v `data`
     */
    public static function forbiddenParts(
        int $actionCode,
        string $activityCode,
        ?string $relationshipDetailCode,
        array $data,
    ): array {
        if (!in_array($actionCode, [2, 3, 4], true)) {
            return [];
        }
        $variant = PayrollRegistrationBusinessMatrix::requireActionVariant(
            $actionCode,
            $activityCode,
            $relationshipDetailCode,
        );
        if ($variant === PayrollRegistrationBusinessMatrix::VARIANT_OST) {
            return [];
        }
        $result = [];
        if ($actionCode === 2) {
            if (is_bool($data['ended_by_death'] ?? null)) {
                $result[] = 'ended_by_death';
            }
            $unemployment = $data['unemployment'] ?? null;
            if (is_array($unemployment)) {
                if ($variant === PayrollRegistrationBusinessMatrix::VARIANT_10) {
                    $result[] = 'unemployment';
                } else {
                    foreach (array_keys($unemployment) as $key) {
                        if (!in_array($key, self::A2_SPEC_UNEMPLOYMENT_ALLOWED, true)) {
                            $result[] = 'unemployment.' . $key;
                        }
                    }
                }
            }

            return $result;
        }
        $delta = is_array($data['delta'] ?? null) ? $data['delta'] : [];
        foreach (PayrollRegistrationDeltaVariantRule::forbiddenPaths($variant, $delta) as $path) {
            $result[] = 'delta.' . $path;
        }
        if ($actionCode === 4 && isset($delta['contract_start_on'])) {
            $result[] = 'delta.contract_start_on';
        }
        if (is_array($data['foreign_insurance'] ?? null)) {
            $result[] = 'foreign_insurance';
        }

        return $result;
    }
}
