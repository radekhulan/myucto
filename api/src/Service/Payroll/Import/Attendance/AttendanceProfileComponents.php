<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Import\Attendance;

use MyInvoice\Service\Payroll\Component\PayrollComponentDefaults;

/**
 * Mzdové složky, které profil importu potřebuje: `{code, name, kind}`.
 *
 * Import je založí jen na výslovné potvrzení a vždy jako jednorázovou
 * peněžní složku se zdaněním a pojistným jako běžná mzda — přesně to, co
 * vstupní brána mzdových vstupů přijímá. Jiné zacházení (osvobození, benefit)
 * nastaví účetní v Mzdových složkách; import ho nehádá.
 *
 * Zařazení do JMHZ dostane složka při založení podle svého druhu z jediného
 * zdroje {@see \MyInvoice\Service\Payroll\Component\PayrollComponentJmhzMappingDefaults::targetFor()}
 * (hodinová a úkolová mzda → tarifní mzdy, příplatek → příplatky celkem,
 * náhrada → náhrady mzdy). Druh `other`, odměna (pravidelná, nebo nepravidelná,
 * z importu neplyne) a ostatní druhy bez jednoznačného zařazení zůstanou
 * nezařazené — rozhodne účetní.
 *
 * @phpstan-type AttendanceProfileComponent array{code:string,name:string,kind:string}
 */
final class AttendanceProfileComponents
{
    public const KINDS = ['hourly_wage', 'task_wage', 'bonus', 'premium', 'compensation', 'allowance', 'other'];
    public const MAX_COMPONENTS = 200;

    /** @return list<AttendanceProfileComponent> */
    public static function validate(mixed $components): array
    {
        if ($components === null) {
            return [];
        }
        if (!is_array($components) || !array_is_list($components)) {
            throw new \InvalidArgumentException('Mzdové složky profilu musí být seznam.');
        }
        if (count($components) > self::MAX_COMPONENTS) {
            throw new \InvalidArgumentException('Profil má příliš mnoho mzdových složek (nejvýše ' . self::MAX_COMPONENTS . ').');
        }
        $result = [];
        $seen = [];
        foreach ($components as $index => $component) {
            $position = $index + 1;
            if (!is_array($component)) {
                throw new \InvalidArgumentException("Mzdová složka profilu č. {$position} nemá platný tvar.");
            }
            $code = is_string($component['code'] ?? null) ? strtoupper(trim($component['code'])) : '';
            if (preg_match('/^[A-Z0-9][A-Z0-9._-]{0,63}$/D', $code) !== 1) {
                throw new \InvalidArgumentException(
                    "Mzdová složka profilu č. {$position} nemá platný kód (velká písmena, číslice, tečka, pomlčka, podtržítko).",
                );
            }
            $name = is_string($component['name'] ?? null) ? trim((string) preg_replace('/\s+/u', ' ', $component['name'])) : '';
            if ($name === '' || mb_strlen($name) > 190 || preg_match('/[\x00-\x1F\x7F]/u', $name) === 1) {
                throw new \InvalidArgumentException("Mzdová složka {$code} nemá platný název (nejvýše 190 znaků).");
            }
            $kind = $component['kind'] ?? null;
            if (!is_string($kind) || !in_array($kind, self::KINDS, true)) {
                throw new \InvalidArgumentException("Mzdová složka {$code} má neznámý druh.");
            }
            if (isset($seen[$code])) {
                throw new \InvalidArgumentException("Mzdová složka {$code} je v profilu dvakrát.");
            }
            $seen[$code] = true;
            $result[] = ['code' => $code, 'name' => $name, 'kind' => $kind];
        }

        return $result;
    }

    /**
     * Data pro {@see \MyInvoice\Repository\Payroll\PayrollComponentRepository::create()}.
     *
     * Kód výchozího číselníku (převzatý příspěvek na stravování, nezdaněná náhrada
     * výdajů) import nezakládá s obecným zacházením: složka tu chybí jen proto, že
     * výchozí číselník platí od pozdějšího dne (převod staršího roku), a stejný kód
     * musí mít v každém roce stejné zdanění, pojistné i zařazení do hlášení.
     *
     * @param AttendanceProfileComponent $component
     * @return array<string,mixed>
     */
    public static function definition(array $component, string $validFrom): array
    {
        $template = PayrollComponentDefaults::template($component['code']);
        if ($template !== null) {
            return array_replace(self::generic($component, $validFrom), $template);
        }

        return self::generic($component, $validFrom);
    }

    /**
     * @param AttendanceProfileComponent $component
     * @return array<string,mixed>
     */
    private static function generic(array $component, string $validFrom): array
    {
        return [
            'code' => $component['code'],
            'name' => $component['name'],
            'component_kind' => $component['kind'],
            'value_kind' => 'monetary',
            'frequency_kind' => 'one_off',
            'tax_treatment' => 'included',
            'social_participation_treatment' => 'included',
            'social_treatment' => 'included',
            'health_participation_treatment' => 'included',
            'health_treatment' => 'included',
            // Náhrada mzdy odpracovanou dobou není (§ 353 ZP), do průměru nepatří.
            'average_earning_treatment' => $component['kind'] === 'compensation' ? 'excluded' : 'included',
            'enforcement_treatment' => 'included',
            'jmhz_treatment' => 'included',
            'statistics_treatment' => 'included',
            'accounting_debit_code' => null,
            'accounting_credit_code' => null,
            'annual_limit_minor' => null,
            'exemption_basket' => null,
            'exemption_basis' => null,
            'valid_from' => $validFrom,
            'valid_to' => null,
            'is_active' => true,
        ];
    }
}
