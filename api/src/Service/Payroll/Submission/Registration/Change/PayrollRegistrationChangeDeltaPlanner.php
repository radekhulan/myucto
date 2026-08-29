<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration\Change;

/**
 * Převod nalezených rozdílů na vstup existujícího schválení události A3.
 *
 * Detekce umí najít víc, než umí tenhle core podat. To není nedodělek, který
 * se má zamlčet: datová věta REGZEC A3 nese dnes v serializéru jen titul,
 * doručovací adresu, daňovou rezidenci a kód zdravotní pojišťovny, a změna
 * bližšího určení vztahu je navíc uzavřená kvůli povinné příloze s vysvětlením
 * ({@see \MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationEventService}).
 *
 * Planner proto rozdělí nález na dvě hromádky:
 * - **`changes`** — co se dá schválit jedním kliknutím do neměnné události,
 * - **`unsupported`** — co detekce našla, ale podat to musí člověk jinudy.
 *
 * Nález z druhé hromádky se NEZAHAZUJE. Povinnost i osmidenní lhůta existují
 * bez ohledu na to, jestli je aplikace umí odbavit, takže návrh zůstane
 * otevřený s termínem a dá se uzavřít až ručně. Tiše zmizet by znamenalo
 * tvrdit, že se nic nestalo.
 */
final class PayrollRegistrationChangeDeltaPlanner
{
    /** Povinná pole doručovací adresy v datové větě A3. */
    private const CONTACT_ADDRESS_REQUIRED = [
        'street', 'house_number', 'postal_code', 'city', 'country_code',
    ];

    private const CONTACT_ADDRESS_OPTIONAL = ['orientation_number', 'ruian_point'];

    /**
     * @param list<PayrollRegistrationChangeFinding> $findings
     * @return array{
     *   changes:array<string,mixed>,
     *   unsupported:list<array{path:string,reason_code:string}>
     * }
     */
    public function plan(
        array $findings,
        PayrollRegistrationReportableProfile $current,
        string $effectiveOn,
    ): array {
        $changes = [];
        $unsupported = [];
        foreach ($findings as $finding) {
            if ($finding->actionCode !== PayrollRegistrationReportableCatalog::ACTION_CHANGE) {
                // Vznik a skončení příslušnosti k cizím předpisům se podává
                // akcí A6/A7, která má vlastní vstup (nositel pojištění).
                // Do A3 ji přimíchat nelze.
                $unsupported[] = [
                    'path' => $finding->path,
                    'reason_code' => 'registration_change_requires_other_action',
                ];
                continue;
            }
            switch (true) {
                case $finding->path === 'identity.title_prefix':
                    $title = $current->get('identity.title_prefix');
                    if ($title === null) {
                        // Datová věta umí titul jen NASTAVIT, ne vymazat.
                        $unsupported[] = [
                            'path' => $finding->path,
                            'reason_code' => 'registration_change_value_removal_unsupported',
                        ];
                        break;
                    }
                    $changes['title_prefix'] = $title;
                    break;

                case $finding->path === 'health_insurance_code':
                    $code = $current->get('health_insurance_code');
                    if ($code === null) {
                        $unsupported[] = [
                            'path' => $finding->path,
                            'reason_code' => 'registration_change_value_removal_unsupported',
                        ];
                        break;
                    }
                    $changes['health_insurance_code'] = $code;
                    break;

                case $finding->path === 'tax_residency.country_code':
                    $country = $current->get('tax_residency.country_code');
                    if ($country === null) {
                        $unsupported[] = [
                            'path' => $finding->path,
                            'reason_code' => 'registration_change_value_removal_unsupported',
                        ];
                        break;
                    }
                    $changes['tax_residency'] = [
                        'country_code' => $country,
                        // Všechny údaje jednoho podání A3 musí mít stejné datum
                        // účinnosti; jiné by událost odmítla.
                        'changed_on' => $effectiveOn,
                    ];
                    break;

                case str_starts_with($finding->path, 'contact_address.'):
                    if (array_key_exists('contact_address', $changes)) {
                        break;
                    }
                    $address = $this->contactAddress($current);
                    if ($address === null) {
                        $unsupported[] = [
                            'path' => 'contact_address',
                            'reason_code' => 'registration_change_contact_address_incomplete',
                        ];
                        break;
                    }
                    $changes['contact_address'] = $address;
                    break;

                default:
                    $unsupported[] = [
                        'path' => $finding->path,
                        'reason_code' => 'registration_change_field_not_in_a3_payload',
                    ];
            }
        }
        ksort($changes, SORT_STRING);
        usort(
            $unsupported,
            static fn (array $a, array $b): int => $a['path'] <=> $b['path'],
        );

        return ['changes' => $changes, 'unsupported' => array_values($unsupported)];
    }

    /** @return array<string,string>|null */
    private function contactAddress(
        PayrollRegistrationReportableProfile $current,
    ): ?array {
        $address = [];
        foreach (self::CONTACT_ADDRESS_REQUIRED as $field) {
            $value = $current->get("contact_address.{$field}");
            if ($value === null) {
                return null;
            }
            $address[$field] = $value;
        }
        foreach (self::CONTACT_ADDRESS_OPTIONAL as $field) {
            $value = $current->get("contact_address.{$field}");
            if ($value !== null) {
                $address[$field] = $value;
            }
        }

        return $address;
    }
}
