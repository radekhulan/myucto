<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration\Change;

use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationTaxResidencyRule;

/**
 * Převod nalezených rozdílů na vstup existujícího schválení události A3.
 *
 * Detekce umí najít víc, než umí tenhle core podat. To není nedodělek, který
 * se má zamlčet: změnou (A3) se tu hlásí titul, trvalý pobyt, doručovací
 * adresa, daňová rezidence, zdravotní pojišťovna, nejvyšší vzdělání,
 * přístup cizince na trh práce a pracovní údaje (postavení, režim, místo
 * výkonu, profese, pozice…). Změna bližšího určení
 * vztahu je uzavřená kvůli povinné příloze s vysvětlením
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

    /** Pobyt v ČR (`fdr`) nemá stát; číslo domu, PSČ a obec jsou povinné. */
    private const CZECH_RESIDENCE_REQUIRED = ['house_number', 'postal_code', 'city'];

    /** Bydliště ve státě rezidence (`rdr`): povinný je i stát. */
    private const TAX_RESIDENCE_ADDRESS_REQUIRED = [
        'house_number', 'postal_code', 'city', 'country_code',
    ];

    private const IDENTITY_PATHS = [
        'identity.last_name',
        'identity.first_name',
        'identity.citizenship_country_code',
    ];

    private const REASON_TAX_ADDRESS =
        'registration_change_tax_residence_address_incomplete';

    private const REASON_TAX_IDENTIFIER =
        'registration_change_tax_identifier_incomplete';

    /**
     * Hodnoty v `changes`, které se do odpovědi API nesmí dostat (stejný
     * důvod jako u nálezů: daňový identifikátor a číslo dokladu).
     */
    private const SENSITIVE_CHANGE_PATHS = [
        ['tax_residency', 'identifier'],
        ['proof_identity', 'number'],
    ];

    /** Trvalý pobyt: ulice může chybět (obec bez ulic), číslo popisné ne. */
    private const PERMANENT_ADDRESS_REQUIRED = [
        'house_number', 'postal_code', 'city', 'country_code',
    ];

    /**
     * Pracovní údaje, které A3 nese (EDV 1.4.0.6, sloupec A3-OST). Druh
     * činnosti měnit nejde a bližší určení vyžaduje přílohu, proto tu nejsou.
     * Posun data nástupu u už registrovaného zaměstnance (ID 10223) se hlásí
     * jako A3 (Metodika hlášení cizinců, část C bod 14).
     */
    public const EMPLOYMENT_FIELDS = [
        'actual_start_on' => 'date',
        'contract_start_on' => 'date',
        'employment_status_code' => 'text',
        'work_mode_code' => 'text',
        'continuous_operation' => 'bool',
        'prevailing_workplace_code' => 'text',
        'expected_workplaces' => 'text',
        'contract_workplace' => 'text',
        'workplace_city' => 'text',
        'workplace_municipality_code' => 'text',
        'profession_code' => 'text',
        'required_education_code' => 'text',
        'position_name' => 'text',
        'leadership' => 'bool',
    ];

    private const EMPLOYMENT_PATHS = [
        'employment.actual_start_on',
        'employment.contract_start_on',
        'employment.employment_status_code',
        'employment.work_mode_code',
        'employment.continuous_operation',
        'employment.prevailing_workplace_code',
        'employment.expected_workplaces',
        'employment.contract_workplace',
        'employment.workplace_city',
        'employment.workplace_municipality_code',
        'employment.profession_code',
        'employment.required_education_code',
        'employment.position_name',
        'employment.leadership',
    ];

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
        ?string $previousSurnames = null,
    ): array {
        $changes = [];
        $unsupported = [];
        $handled = [];
        foreach ($findings as $finding) {
            if ($finding->actionCode !== PayrollRegistrationReportableCatalog::ACTION_CHANGE) {
                // Přechod mezi cizími a českými předpisy se podává akcí
                // A6/A7, která má vlastní vstup (nositel pojištění).
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

                case in_array($finding->path, self::IDENTITY_PATHS, true):
                    if (isset($handled['identity'])) {
                        break;
                    }
                    $handled['identity'] = true;
                    $identity = $this->identity($current, $findings, $previousSurnames);
                    if ($identity === null) {
                        $unsupported[] = [
                            'path' => 'identity',
                            'reason_code' => 'registration_change_value_removal_unsupported',
                        ];
                        break;
                    }
                    $changes['identity'] = $identity;
                    break;

                case str_starts_with($finding->path, 'tax_residency.'):
                    if (isset($handled['tax_residency'])) {
                        break;
                    }
                    $handled['tax_residency'] = true;
                    $residency = $this->taxResidency($current, $effectiveOn);
                    if (is_string($residency)) {
                        $unsupported[] = [
                            'path' => $residency === self::REASON_TAX_ADDRESS
                                ? 'tax_residency.residence_address'
                                : 'tax_residency',
                            'reason_code' => $residency,
                        ];
                        break;
                    }
                    $changes['tax_residency'] = $residency;
                    break;

                case str_starts_with($finding->path, 'pension.'):
                    if (isset($handled['pension'])) {
                        break;
                    }
                    $handled['pension'] = true;
                    $pension = $this->pension($current);
                    if ($pension === null) {
                        $unsupported[] = [
                            'path' => 'pension',
                            'reason_code' => 'registration_change_value_removal_unsupported',
                        ];
                        break;
                    }
                    $changes['pension'] = $pension;
                    break;

                case $finding->path === 'facts.disability_card'
                    || $finding->path === 'facts.health_restrictions':
                    if (isset($handled['facts'])) {
                        break;
                    }
                    $handled['facts'] = true;
                    $facts = $this->facts($current, $findings);
                    foreach ($facts['unsupported'] as $item) {
                        $unsupported[] = $item;
                    }
                    if ($facts['facts'] !== []) {
                        $changes['facts'] = $facts['facts'];
                    }
                    break;

                case str_starts_with($finding->path, 'czech_residence_address.'):
                    if (isset($handled['czech_residence_address'])) {
                        break;
                    }
                    $handled['czech_residence_address'] = true;
                    $residence = $this->address(
                        $current,
                        'czech_residence_address',
                        self::CZECH_RESIDENCE_REQUIRED,
                        ['street', 'orientation_number', 'ruian_point'],
                    );
                    if ($residence === null) {
                        $unsupported[] = [
                            'path' => 'czech_residence_address',
                            'reason_code' => 'registration_change_czech_residence_address_incomplete',
                        ];
                        break;
                    }
                    $changes['czech_residence_address'] = $residence;
                    break;

                case str_starts_with($finding->path, 'proof_identity.'):
                    if (isset($handled['proof_identity'])) {
                        break;
                    }
                    $handled['proof_identity'] = true;
                    $proof = $this->address(
                        $current,
                        'proof_identity',
                        ['type_code', 'number', 'country_code'],
                        ['foreign_issuer'],
                    );
                    if ($proof === null) {
                        $unsupported[] = [
                            'path' => 'proof_identity',
                            'reason_code' => 'registration_change_proof_identity_incomplete',
                        ];
                        break;
                    }
                    $changes['proof_identity'] = $proof;
                    break;

                case $finding->path === 'foreign_legislation.country_code':
                    $country = $current->get('foreign_legislation.country_code');
                    if ($finding->from === null
                        || $current->get('foreign_legislation.applies') !== '1'
                    ) {
                        // Stát se objevil společně se vznikem cizí
                        // příslušnosti (nebo ta netrvá): to je A7 (resp.
                        // A6), ne změna A3.
                        $unsupported[] = [
                            'path' => $finding->path,
                            'reason_code' => 'registration_change_requires_other_action',
                        ];
                        break;
                    }
                    if ($country === null) {
                        $unsupported[] = [
                            'path' => $finding->path,
                            'reason_code' => 'registration_change_value_removal_unsupported',
                        ];
                        break;
                    }
                    $changes['foreign_legislation'] = [
                        'applies' => true,
                        'country_code' => $country,
                    ];
                    break;

                case str_starts_with($finding->path, 'permanent_address.'):
                    if (array_key_exists('permanent_address', $changes)
                        || in_array('permanent_address', array_column($unsupported, 'path'), true)
                    ) {
                        break;
                    }
                    $permanent = $this->address(
                        $current,
                        'permanent_address',
                        self::PERMANENT_ADDRESS_REQUIRED,
                        ['street', 'orientation_number', 'ruian_point'],
                    );
                    if ($permanent === null) {
                        // Kmenová data vedou adresu jedním řádkem; číslo
                        // popisné zvlášť zná jen profil A1. Návrh zůstane
                        // otevřený a proklik vede do profilu.
                        $unsupported[] = [
                            'path' => 'permanent_address',
                            'reason_code' => 'registration_change_permanent_address_incomplete',
                        ];
                        break;
                    }
                    $changes['permanent_address'] = $permanent;
                    break;

                case str_starts_with($finding->path, 'foreign_worker.'):
                    if (array_key_exists('foreign_worker', $changes)
                        || in_array('foreign_worker', array_column($unsupported, 'path'), true)
                    ) {
                        break;
                    }
                    $worker = $this->foreignWorker($current, $findings);
                    if ($worker === null) {
                        $unsupported[] = [
                            'path' => 'foreign_worker',
                            'reason_code' => 'registration_change_foreign_permit_incomplete',
                        ];
                        break;
                    }
                    $changes['foreign_worker'] = $worker;
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

                case $finding->path === 'facts.highest_education_code':
                    $education = $current->get('facts.highest_education_code');
                    if ($education === null) {
                        $unsupported[] = [
                            'path' => $finding->path,
                            'reason_code' => 'registration_change_value_removal_unsupported',
                        ];
                        break;
                    }
                    $changes['highest_education_code'] = $education;
                    break;

                case in_array($finding->path, self::EMPLOYMENT_PATHS, true):
                    // Mění-li se kterýkoli pracovní údaj, pošle se celý blok
                    // v aktuální podobě — ČSSZ přijímá i částečný snímek
                    // a skupina se tak nerozpadne na polovinu.
                    if (!array_key_exists('employment', $changes)) {
                        $changes['employment'] = $this->employment($current);
                    }
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

    /**
     * Změny podávané do odpovědi API bez citlivých hodnot. Plná podoba
     * zůstává jen uvnitř podání (neměnná událost je šifrovaná).
     *
     * @param array<string,mixed> $changes
     * @return array<string,mixed>
     */
    public static function redact(array $changes): array
    {
        foreach (self::SENSITIVE_CHANGE_PATHS as [$block, $field]) {
            if (is_array($changes[$block] ?? null)
                && array_key_exists($field, $changes[$block])
            ) {
                $changes[$block][$field] = null;
            }
        }

        return $changes;
    }

    /**
     * Jméno (příjmení a jméno spolu), dřívější příjmení a občanství.
     * Změní-li se jedno z jmen, pošle se celé jméno: ČSSZ pak nedostane
     * polovinu.
     *
     * @param list<PayrollRegistrationChangeFinding> $findings
     * @return array<string,string>|null
     */
    private function identity(
        PayrollRegistrationReportableProfile $current,
        array $findings,
        ?string $previousSurnames,
    ): ?array {
        $paths = array_map(
            static fn (PayrollRegistrationChangeFinding $finding): string => $finding->path,
            $findings,
        );
        $result = [];
        if (in_array('identity.last_name', $paths, true)
            || in_array('identity.first_name', $paths, true)
        ) {
            $last = $current->get('identity.last_name');
            $first = $current->get('identity.first_name');
            if ($last === null || $first === null) {
                return null;
            }
            $result['last_name'] = $last;
            $result['first_name'] = $first;
            if (in_array('identity.last_name', $paths, true)
                && $previousSurnames !== null
            ) {
                $result['previous_surnames'] = $previousSurnames;
            }
        }
        if (in_array('identity.citizenship_country_code', $paths, true)) {
            $citizenship = $current->get('identity.citizenship_country_code');
            if ($citizenship === null) {
                return null;
            }
            $result['citizenship_country_code'] = $citizenship;
        }
        ksort($result, SORT_STRING);

        return $result;
    }

    /**
     * Daňová rezidence s identifikátorem a adresou bydliště. Vrací blok, nebo
     * kód důvodu, proč ho podat nejde (rezidence v jiném státě bez adresy je
     * vada, kterou ČSSZ odmítne).
     *
     * @return array<string,mixed>|string
     */
    private function taxResidency(
        PayrollRegistrationReportableProfile $current,
        string $effectiveOn,
    ): array|string {
        $country = $current->get('tax_residency.country_code');
        if ($country === null) {
            return 'registration_change_value_removal_unsupported';
        }
        $block = [
            'country_code' => $country,
            // Všechny údaje jednoho podání A3 musí mít stejné datum
            // účinnosti; jiné by událost odmítla.
            'changed_on' => $effectiveOn,
        ];
        $type = $current->get('tax_residency.identifier_type');
        $identifier = $current->get('tax_residency.identifier');
        // Druh identifikátoru je v schématu jednoznakový kód; delší hodnotu
        // z profilu ČSSZ odmítne, proto se podání neslibuje.
        if (($type === null) !== ($identifier === null)
            || ($type !== null && mb_strlen($type, 'UTF-8') !== 1)
        ) {
            return self::REASON_TAX_IDENTIFIER;
        }
        if ($type !== null) {
            $block['identifier_type'] = $type;
            $block['identifier'] = $identifier;
        }
        if (PayrollRegistrationTaxResidencyRule::requiresResidenceAddress($country)) {
            $address = $this->address(
                $current,
                'tax_residency.residence_address',
                self::TAX_RESIDENCE_ADDRESS_REQUIRED,
                ['street', 'orientation_number', 'ruian_point'],
            );
            if ($address === null) {
                return self::REASON_TAX_ADDRESS;
            }
            $block['residence_address'] = $address;
        }
        ksort($block, SORT_STRING);

        return $block;
    }

    /** @return array<string,string|bool>|null */
    private function pension(PayrollRegistrationReportableProfile $current): ?array
    {
        $type = $current->get('pension.type_code');
        $from = $current->get('pension.received_from');
        if ($type === null || $from === null) {
            // Důchod zanikl nebo je rozepsaný: A3 hodnotu zrušit neumí.
            return null;
        }
        $pension = ['type_code' => $type, 'received_from' => $from];
        foreach (['early_retirement', 'reduced_retirement_age'] as $flag) {
            $value = $current->get("pension.{$flag}");
            if ($value !== null) {
                $pension[$flag] = $value === '1';
            }
        }
        ksort($pension, SORT_STRING);

        return $pension;
    }

    /**
     * Průkaz ZTP a zdravotní omezení. Schéma nese nejvýš jedno omezení a A3
     * nezná zrušení hodnoty, proto zánik omezení a víc omezení najednou jdou
     * do nepodporovaných.
     *
     * @param list<PayrollRegistrationChangeFinding> $findings
     * @return array{
     *   facts:array<string,mixed>,
     *   unsupported:list<array{path:string,reason_code:string}>
     * }
     */
    private function facts(
        PayrollRegistrationReportableProfile $current,
        array $findings,
    ): array {
        $paths = array_map(
            static fn (PayrollRegistrationChangeFinding $finding): string => $finding->path,
            $findings,
        );
        $facts = [];
        $unsupported = [];
        if (in_array('facts.disability_card', $paths, true)) {
            $card = $current->get('facts.disability_card');
            if ($card === null) {
                $unsupported[] = [
                    'path' => 'facts.disability_card',
                    'reason_code' => 'registration_change_value_removal_unsupported',
                ];
            } else {
                $facts['disability_card'] = $card === '1';
            }
        }
        if (in_array('facts.health_restrictions', $paths, true)) {
            $decoded = json_decode(
                (string) $current->get('facts.health_restrictions'),
                true,
            );
            $restrictions = is_array($decoded) && array_is_list($decoded)
                ? $decoded
                : [];
            if ($restrictions === []) {
                $unsupported[] = [
                    'path' => 'facts.health_restrictions',
                    'reason_code' => 'registration_change_value_removal_unsupported',
                ];
            } elseif (count($restrictions) > 1) {
                $unsupported[] = [
                    'path' => 'facts.health_restrictions',
                    'reason_code' => 'registration_change_health_restrictions_multiple',
                ];
            } else {
                $row = is_array($restrictions[0]) ? $restrictions[0] : [];
                $item = [];
                foreach (['type_code', 'from', 'to'] as $key) {
                    if (is_string($row[$key] ?? null) && $row[$key] !== '') {
                        $item[$key] = $row[$key];
                    }
                }
                if (!isset($item['type_code'], $item['from'])) {
                    $unsupported[] = [
                        'path' => 'facts.health_restrictions',
                        'reason_code' => 'registration_change_value_removal_unsupported',
                    ];
                } else {
                    ksort($item, SORT_STRING);
                    $facts['health_restrictions'] = [$item];
                }
            }
        }
        ksort($facts, SORT_STRING);

        return ['facts' => $facts, 'unsupported' => $unsupported];
    }

    /** @return array<string,string|bool> */
    private function employment(PayrollRegistrationReportableProfile $current): array
    {
        $employment = [];
        foreach (self::EMPLOYMENT_FIELDS as $field => $kind) {
            $value = $current->get("employment.{$field}");
            if ($value === null) {
                continue;
            }
            $employment[$field] = $kind === 'bool' ? $value === '1' : $value;
        }

        return $employment;
    }

    /**
     * @param list<string> $required
     * @param list<string> $optional
     * @return array<string,string>|null
     */
    private function address(
        PayrollRegistrationReportableProfile $current,
        string $block,
        array $required,
        array $optional,
    ): ?array {
        $address = [];
        foreach ($required as $field) {
            $value = $current->get("{$block}.{$field}");
            if ($value === null) {
                return null;
            }
            $address[$field] = $value;
        }
        foreach ($optional as $field) {
            $value = $current->get("{$block}.{$field}");
            if ($value !== null) {
                $address[$field] = $value;
            }
        }
        ksort($address, SORT_STRING);

        return $address;
    }

    /**
     * Přístup cizince na trh práce: buď volný přístup s důvodem, nebo úplné
     * povolení. Prodloužení povolení (nové datum „do") bez nového čísla
     * rozhodnutí se podat nedá — to je jiné rozhodnutí a jeho číslo zná jen
     * profil A1; návrh pak zůstane otevřený s prokliknutím do profilu.
     *
     * @param list<PayrollRegistrationChangeFinding> $findings
     * @return array<string,string|bool>|null
     */
    private function foreignWorker(
        PayrollRegistrationReportableProfile $current,
        array $findings,
    ): ?array {
        $freeAccess = $current->get('foreign_worker.free_access');
        if ($freeAccess === '1') {
            $reason = $current->get('foreign_worker.free_access_reason_code');

            return $reason === null
                ? null
                : ['free_access' => true, 'free_access_reason_code' => $reason];
        }
        $paths = array_map(
            static fn (PayrollRegistrationChangeFinding $finding): string => $finding->path,
            $findings,
        );
        $datesChanged = array_intersect(
            ['foreign_worker.permit_from', 'foreign_worker.permit_to'],
            $paths,
        ) !== [];
        if ($datesChanged && !in_array('foreign_worker.permit_identifier', $paths, true)) {
            return null;
        }
        $worker = ['free_access' => false];
        foreach ([
            'permit_type_code', 'permit_identifier', 'permit_from', 'permit_to',
        ] as $field) {
            $value = $current->get("foreign_worker.{$field}");
            if ($value === null) {
                return null;
            }
            $worker[$field] = $value;
        }
        $office = $current->get('foreign_worker.issuing_labour_office_code');
        if ($office !== null) {
            $worker['issuing_labour_office_code'] = $office;
        }
        ksort($worker, SORT_STRING);

        return $worker;
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
