<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration;

use MyInvoice\Repository\Payroll\PayrollEmploymentRepository;
use MyInvoice\Repository\Payroll\PayrollPersonProfileRepository;
use MyInvoice\Repository\Payroll\PayrollPersonStatutoryEvidenceRepository;
use MyInvoice\Repository\Payroll\PayrollRegistrationIdentityRepository;
use MyInvoice\Service\Payroll\PayrollEmploymentValidator;
use MyInvoice\Service\Payroll\PayrollPersonProfileValidator;

/**
 * Zápis opravené hodnoty z formuláře REGZEC A1 ZPĚT do kmenových dat.
 *
 * ## Proč to vůbec je
 *
 * Část formuláře A1 se plní z evidence osoby a pracovního vztahu. Když v něm
 * účetní hodnotu opravila, zůstala oprava jen ve snímku — kmenová data dál
 * nesla starou hodnotu a rozdíl se hlásil pořád dokola, aniž by s ním šlo něco
 * udělat. Tohle je ten chybějící směr: „ulož to i tam, odkud se to bere".
 *
 * ## Čím se to řídí
 *
 * Seznam zapsatelných polí drží {@see PayrollRegistrationA1MasterDataFields}.
 * Co v něm není, se nezapisuje a UI k tomu dostane VĚTU PROČ — mlčení by
 * vypadalo jako chybějící tlačítko.
 *
 * ## Jak se zapisuje
 *
 * Vlastní SQL tady žádné není. Každá skupina jde TOUTÉŽ cestou jako karta,
 * na které se údaj běžně edituje:
 *
 *   * adresy, občanství a zahraniční daňový identifikátor →
 *     {@see PayrollPersonProfileRepository::save()} (karta osoby),
 *   * daňová rezidence a zdravotní pojišťovna →
 *     {@see PayrollPersonStatutoryEvidenceRepository::save()} (zákonná evidence),
 *   * sjednané podmínky →
 *     {@see PayrollEmploymentRepository::correctTerms()} (karta vztahu).
 *
 * Druhá zapisovací cesta by se s tou první dřív nebo později rozešla a obešla
 * by kontroly, které na kartách platí (zmrazená období, souvislost časových
 * řad, oprávnění).
 *
 * ## Historizovaná evidence se nepřepisuje
 *
 * U adres a identity osoby vzniká NOVÁ historická verze účinná od rozhodného
 * dne profilu: verze, která ten den pokrývá, se uzavře předchozím dnem a od
 * rozhodného dne nastoupí nová. Jen když už uložená verze začíná přesně
 * rozhodným dnem, opraví se ta — jinak by vznikaly nulové intervaly. Zákonná
 * evidence si TOTÉŽ pravidlo hlídá sama vůči schváleným mzdovým obdobím
 * a sjednané podmínky se opravují jako překlep v platné verzi (`correctTerms`),
 * což je věcně přesně tenhle případ: údaj byl od začátku zapsaný špatně.
 *
 * ## Nic to neblokuje
 *
 * Zápis je akce navíc, ne podmínka uložení profilu. Co se nepovede, se vrátí
 * v `skipped` i s důvodem; zbytek se zapíše.
 */
final class PayrollRegistrationA1MasterDataWriter
{
    private const EMPLOYMENT_NOT_FOUND =
        'Pracovní vztah v téhle firmě neexistuje. Nejspíš došlo ke smazání '
        . 'nebo přesunu. Vraťte se na přehled osob a otevřete vztah znovu.';

    public function __construct(
        private readonly PayrollRegistrationIdentityService $identities,
        private readonly PayrollRegistrationIdentityRepository $registrations,
        private readonly PayrollPersonProfileRepository $profiles,
        private readonly PayrollPersonProfileValidator $profileValidator,
        private readonly PayrollPersonStatutoryEvidenceRepository $statutory,
        private readonly PayrollEmploymentRepository $employments,
        private readonly PayrollEmploymentValidator $employmentValidator,
    ) {}

    /**
     * @param list<string> $fields cesty ve snímku, které se mají zapsat
     * @return array{
     *   written:list<array{field:string,label:string,value:?string}>,
     *   skipped:list<array{field:string,label:string,reason:string}>,
     *   view:array{profile:array<string,mixed>|null,draft:array<string,mixed>}
     * }
     */
    public function write(
        int $supplierId,
        int $employmentId,
        array $fields,
        ?int $userId,
        ?string $ip,
        ?string $userAgent,
    ): array {
        if ($supplierId <= 0 || $employmentId <= 0) {
            throw new \InvalidArgumentException(
                'Firma i pracovní vztah musí být kladné číslo.',
            );
        }
        $employment = $this->registrations->employment($supplierId, $employmentId);
        if ($employment === null) {
            throw new \OutOfBoundsException(self::EMPLOYMENT_NOT_FOUND);
        }
        $employeeId = $employment['employee_id'];

        $view = $this->identities->a1ProfileView($supplierId, $employmentId);
        $draft = $view['draft'];
        $effectiveOn = (string) $draft['effective_on'];
        /** @var array<string,mixed> $suggested */
        $suggested = is_array($draft['suggested'] ?? null)
            ? $draft['suggested']
            : [];
        $candidates = [];
        foreach ($this->list($draft['writeback'] ?? null) as $item) {
            if (is_array($item) && is_string($item['field'] ?? null)) {
                $candidates[$item['field']] = $item;
            }
        }

        $written = [];
        $skipped = [];
        /** @var array<string,list<array{path:string,value:string}>> $plan */
        $plan = [];
        foreach ($this->unique($fields) as $path) {
            $label = PayrollRegistrationFieldVocabulary::label($path);
            $reason = PayrollRegistrationA1MasterDataFields::blockedReason($path);
            if ($reason !== null) {
                $skipped[] = [
                    'field' => $path,
                    'label' => $label,
                    'reason' => $reason,
                ];
                continue;
            }
            $candidate = $candidates[$path] ?? null;
            if ($candidate === null) {
                /*
                 * Idempotence: co se od kmenových dat neliší, se nezapisuje.
                 * Jinak by druhé kliknutí zakládalo verzi bez jediné změny.
                 */
                $skipped[] = [
                    'field' => $path,
                    'label' => $label,
                    'reason' => $label . ' už má v kmenových datech stejnou '
                        . 'hodnotu jako ve formuláři, takže se nic nezapisuje.',
                ];
                continue;
            }
            $value = $candidate['stored'] ?? null;
            if (!is_string($value) || trim($value) === '') {
                $skipped[] = [
                    'field' => $path,
                    'label' => $label,
                    'reason' => $label . ' není ve formuláři vyplněný, takže '
                        . 'není co do kmenových dat zapsat. Prázdnou hodnotou '
                        . 'se evidence nemaže — vyplňte údaj, nebo ho opravte '
                        . 'přímo na kartě.',
                ];
                continue;
            }
            $target = PayrollRegistrationA1MasterDataFields::target($path);
            if ($target === null) {
                continue;
            }
            $key = $target['scope'] === null
                ? $target['target']
                : $target['target'] . ':' . $target['scope'];
            $plan[$key][] = ['path' => $path, 'value' => trim($value)];
        }

        $this->apply(
            $plan,
            $supplierId,
            $employeeId,
            $employmentId,
            $effectiveOn,
            $suggested,
            $userId,
            $ip,
            $userAgent,
            $written,
            $skipped,
        );

        usort(
            $written,
            static fn (array $a, array $b): int => strcmp($a['field'], $b['field']),
        );
        usort(
            $skipped,
            static fn (array $a, array $b): int => strcmp($a['field'], $b['field']),
        );

        return [
            'written' => $written,
            'skipped' => $skipped,
            // Seznam se musí přepočítat: bez toho by u zapsaného údaje zůstalo
            // tlačítko, které už nemá co dělat.
            'view' => $this->identities->a1ProfileView($supplierId, $employmentId),
        ];
    }

    /**
     * @param array<string,list<array{path:string,value:string}>> $plan
     * @param array<string,mixed> $suggested
     * @param list<array{field:string,label:string,value:?string}> $written
     * @param list<array{field:string,label:string,reason:string}> $skipped
     */
    private function apply(
        array $plan,
        int $supplierId,
        int $employeeId,
        int $employmentId,
        string $effectiveOn,
        array $suggested,
        ?int $userId,
        ?string $ip,
        ?string $userAgent,
        array &$written,
        array &$skipped,
    ): void {
        $personGroups = [];
        foreach ($plan as $key => $items) {
            if (str_starts_with($key, PayrollRegistrationA1MasterDataFields::TARGET_PERSON_ADDRESS)
                || $key === PayrollRegistrationA1MasterDataFields::TARGET_PERSON_IDENTITY
                || str_starts_with($key, PayrollRegistrationA1MasterDataFields::TARGET_PERSON_IDENTIFIER)
            ) {
                $personGroups[$key] = $items;
            }
        }
        if ($personGroups !== []) {
            $this->run(
                $personGroups,
                fn () => $this->writePersonCard(
                    $supplierId,
                    $employeeId,
                    $effectiveOn,
                    $suggested,
                    $personGroups,
                    $userId,
                    $ip,
                    $userAgent,
                ),
                $written,
                $skipped,
            );
        }

        $statutoryGroups = [];
        foreach ([
            PayrollRegistrationA1MasterDataFields::TARGET_TAX_RESIDENCE,
            PayrollRegistrationA1MasterDataFields::TARGET_HEALTH_COVERAGE,
        ] as $key) {
            if (isset($plan[$key])) {
                $statutoryGroups[$key] = $plan[$key];
            }
        }
        if ($statutoryGroups !== []) {
            $this->run(
                $statutoryGroups,
                fn () => $this->writeStatutoryEvidence(
                    $supplierId,
                    $employeeId,
                    $effectiveOn,
                    $statutoryGroups,
                    $userId,
                    $ip,
                    $userAgent,
                ),
                $written,
                $skipped,
            );
        }

        $termsKey = PayrollRegistrationA1MasterDataFields::TARGET_EMPLOYMENT_TERMS;
        if (isset($plan[$termsKey])) {
            $terms = [$termsKey => $plan[$termsKey]];
            $this->run(
                $terms,
                fn () => $this->writeEmploymentTerms(
                    $supplierId,
                    $employeeId,
                    $employmentId,
                    $effectiveOn,
                    $plan[$termsKey],
                    $userId,
                    $ip,
                    $userAgent,
                ),
                $written,
                $skipped,
            );
        }
    }

    /**
     * Jedna skupina = jeden zápis. Když selže, spadne celá skupina i s důvodem,
     * ale ostatní skupiny se tím neruší — zápis nesmí být „všechno, nebo nic",
     * jinak by jedno zamrzlé období shodilo i opravu adresy.
     *
     * @param array<string,list<array{path:string,value:string}>> $groups
     * @param callable():void $work
     * @param list<array{field:string,label:string,value:?string}> $written
     * @param list<array{field:string,label:string,reason:string}> $skipped
     */
    private function run(
        array $groups,
        callable $work,
        array &$written,
        array &$skipped,
    ): void {
        try {
            $work();
        } catch (\Throwable $exception) {
            foreach ($groups as $items) {
                foreach ($items as $item) {
                    $skipped[] = [
                        'field' => $item['path'],
                        'label' => PayrollRegistrationFieldVocabulary::label(
                            $item['path'],
                        ),
                        'reason' => $exception->getMessage(),
                    ];
                }
            }

            return;
        }
        foreach ($groups as $items) {
            foreach ($items as $item) {
                $written[] = [
                    'field' => $item['path'],
                    'label' => PayrollRegistrationFieldVocabulary::label(
                        $item['path'],
                    ),
                    'value' => $item['value'],
                ];
            }
        }
    }

    /**
     * Adresy, státní občanství a zahraniční daňový identifikátor jdou jedním
     * uložením karty osoby — je to jeden optimistický zámek, takže dva zápisy
     * za sebou by si navzájem shodily verzi.
     *
     * @param array<string,mixed> $suggested
     * @param array<string,list<array{path:string,value:string}>> $groups
     */
    private function writePersonCard(
        int $supplierId,
        int $employeeId,
        string $effectiveOn,
        array $suggested,
        array $groups,
        ?int $userId,
        ?string $ip,
        ?string $userAgent,
    ): void {
        $current = $this->profiles->get($supplierId, $employeeId);
        if ($current === null) {
            throw new \DomainException(
                'Osobní karta zaměstnance nebyla nalezena, takže se do ní '
                . 'nedá zapsat. Otevřete kartu osoby a zkuste to znovu.',
            );
        }

        $addresses = [];
        $identity = [];
        $identifiers = [];
        foreach ($groups as $key => $items) {
            [$target, $scope] = array_pad(explode(':', $key, 2), 2, null);
            if ($target === PayrollRegistrationA1MasterDataFields::TARGET_PERSON_ADDRESS) {
                $addresses = array_merge($addresses, $this->addressRows(
                    $current['addresses'],
                    (string) $scope,
                    $effectiveOn,
                    $suggested,
                    $items,
                ));
                continue;
            }
            if ($target === PayrollRegistrationA1MasterDataFields::TARGET_PERSON_IDENTITY) {
                $identity = $this->identityRows(
                    $current['identity_history'],
                    $effectiveOn,
                    $items,
                );
                continue;
            }
            $identifiers[] = [
                'identifier_type' => (string) $scope,
                'value' => $items[0]['value'],
                'id' => $this->identifierId($current['identifiers'], (string) $scope),
            ];
        }

        $payload = [
            'row_version' => $current['row_version'],
            // `missing` není platný stav pro zápis; karta, do které se poprvé
            // píše, přechází na „rozpracovaná" — stejně jako v Běžných údajích.
            'profile_status' => $current['profile_status'] === 'missing'
                ? 'setup'
                : $current['profile_status'],
            'payout_method' => $current['payout_method'],
            'partner_settlement_account_code' =>
                $current['partner_settlement_account_code'],
            'cash_allocation_basis_points' =>
                $current['cash_allocation_basis_points'],
            'payout_effective_on' => $current['payout_effective_on']
                ?? $effectiveOn,
            'secure_delivery_channel' => $current['secure_delivery_channel'],
            'identity_history' => $identity,
            'addresses' => $addresses,
            // Kontakty, účty a nedotčené identifikátory se posílají BEZ hodnoty:
            // uložená šifrovaná hodnota tím zůstává, jen se nic nemění. Karta
            // nic nemaže, takže vynechané řádky zůstávají také beze změny.
            'contacts' => [],
            'identifiers' => $identifiers,
            'accounts' => [],
        ];

        $this->profiles->save(
            $supplierId,
            $employeeId,
            $this->profileValidator->validate($payload),
            $current['row_version'],
            $userId,
            $ip,
            $userAgent,
        );
    }

    /**
     * @param list<array<string,mixed>> $stored
     * @param array<string,mixed> $suggested
     * @param list<array{path:string,value:string}> $items
     * @return list<array<string,mixed>>
     */
    private function addressRows(
        array $stored,
        string $addressType,
        string $effectiveOn,
        array $suggested,
        array $items,
    ): array {
        $this->assertNotFuture($effectiveOn);
        $covering = $this->covering($stored, $effectiveOn, $addressType);
        if ($covering === null) {
            throw new \DomainException(
                'Osoba nemá k rozhodnému dni registrace evidovanou adresu, '
                . 'takže se do ní nedá zapsat. Založte ji na '
                . PayrollRegistrationFieldVocabulary::WHERE_ADDRESSES . '.',
            );
        }
        /*
         * Adresa se v evidenci vede jako celek, ne po sloupcích. Základ je
         * proto DNEŠNÍ kmenová hodnota (to, co návrh nabízí) a přepíše se jen
         * to, co účetní zaškrtla — zbytek adresy zůstává, jak byl.
         */
        $section = $suggested[$this->addressSection($addressType)] ?? null;
        $values = [
            'street_line' => $this->text($section, 'street'),
            'city' => $this->text($section, 'city'),
            'postal_code' => $this->text($section, 'postal_code'),
            'country_code' => $this->text($section, 'country_code'),
        ];
        foreach ($items as $item) {
            $target = PayrollRegistrationA1MasterDataFields::target($item['path']);
            if ($target !== null) {
                $values[$target['column']] = $item['value'];
            }
        }
        foreach ($values as $column => $value) {
            if ($value === null) {
                throw new \DomainException(
                    'Adresa se do evidence ukládá celá, a některá její část '
                    . '(ulice, obec, PSČ nebo stát) zůstala prázdná. Doplňte '
                    . 'chybějící část ve formuláři, nebo adresu opravte na '
                    . PayrollRegistrationFieldVocabulary::WHERE_ADDRESSES
                    . '. (' . $column . ')',
                );
            }
        }

        $row = [
            'address_type' => $addressType,
            'street_line' => $values['street_line'],
            'city' => $values['city'],
            'postal_code' => $values['postal_code'],
            'country_code' => $values['country_code'],
        ];
        if ((string) $covering['effective_from'] === $effectiveOn) {
            // Verze začíná přesně rozhodným dnem — nová by měla nulový
            // interval, takže se opravuje tahle.
            return [$row + [
                'id' => $covering['id'],
                'effective_from' => $effectiveOn,
                'effective_to' => $covering['effective_to'],
            ]];
        }

        return [
            [
                'id' => $covering['id'],
                'address_type' => $addressType,
                'effective_from' => $covering['effective_from'],
                'effective_to' => $this->previousDay($effectiveOn),
            ],
            $row + [
                'id' => null,
                'effective_from' => $effectiveOn,
                'effective_to' => $covering['effective_to'],
            ],
        ];
    }

    /**
     * @param list<array<string,mixed>> $stored
     * @param list<array{path:string,value:string}> $items
     * @return list<array<string,mixed>>
     */
    private function identityRows(
        array $stored,
        string $effectiveOn,
        array $items,
    ): array {
        $this->assertNotFuture($effectiveOn);
        $covering = $this->covering($stored, $effectiveOn, null);
        if ($covering === null) {
            throw new \DomainException(
                'Osoba nemá k rozhodnému dni registrace evidovanou verzi '
                . 'identity, takže se do ní nedá zapsat. Založte ji na '
                . PayrollRegistrationFieldVocabulary::WHERE_NAMES . '.',
            );
        }
        $changes = [];
        foreach ($items as $item) {
            $target = PayrollRegistrationA1MasterDataFields::target($item['path']);
            if ($target !== null) {
                $changes[$target['column']] = $item['value'];
            }
        }
        $base = [
            'full_name' => $covering['full_name'],
            'first_name' => $covering['first_name'],
            'last_name' => $covering['last_name'],
            'title_prefix' => $covering['title_prefix'],
            'title_suffix' => $covering['title_suffix'],
            'birth_date' => $covering['birth_date'],
            'birth_place' => $covering['birth_place'],
            'birth_country_code' => $covering['birth_country_code'],
            'citizenship_country_code' => $covering['citizenship_country_code'],
            'sex' => $covering['sex'],
        ];
        $base = array_merge($base, $changes);

        if ((string) $covering['effective_from'] === $effectiveOn) {
            return [$base + [
                'id' => $covering['id'],
                'effective_from' => $effectiveOn,
                'effective_to' => $covering['effective_to'],
            ]];
        }

        return [
            [
                'id' => $covering['id'],
                'full_name' => $covering['full_name'],
                'first_name' => $covering['first_name'],
                'last_name' => $covering['last_name'],
                'effective_from' => $covering['effective_from'],
                'effective_to' => $this->previousDay($effectiveOn),
            ],
            $base + [
                // Rodné příjmení je šifrované, karta ho vrací jen zamaskované.
                // Nová verze si ho proto přebírá z té předchozí odkazem, ne
                // opisem — jinak by se z maskovaného textu stal uložený údaj.
                'birth_surname_source_id' => $covering['birth_surname_masked'] === null
                    ? null
                    : $covering['id'],
                'effective_from' => $effectiveOn,
                'effective_to' => $covering['effective_to'],
            ],
        ];
    }

    /**
     * Řádek historizované evidence účinný k rozhodnému dni.
     *
     * @param list<array<string,mixed>> $rows
     * @return array<string,mixed>|null
     */
    private function covering(
        array $rows,
        string $effectiveOn,
        ?string $addressType,
    ): ?array {
        $best = null;
        foreach ($rows as $row) {
            if ($addressType !== null
                && ($row['address_type'] ?? null) !== $addressType
            ) {
                continue;
            }
            $from = (string) $row['effective_from'];
            $to = $row['effective_to'] === null ? null : (string) $row['effective_to'];
            if ($from > $effectiveOn || ($to !== null && $to < $effectiveOn)) {
                continue;
            }
            if ($best === null || $from > (string) $best['effective_from']) {
                $best = $row;
            }
        }

        return $best;
    }

    /**
     * Daňová rezidence a zdravotní pojišťovna jdou jedním uložením zákonné
     * evidence: ta bere CÍLOVÝ stav všech kolekcí najednou, takže dva zápisy
     * za sebou by si přepsaly plán.
     *
     * @param array<string,list<array{path:string,value:string}>> $groups
     */
    private function writeStatutoryEvidence(
        int $supplierId,
        int $employeeId,
        string $effectiveOn,
        array $groups,
        ?int $userId,
        ?string $ip,
        ?string $userAgent,
    ): void {
        $view = $this->statutory->editorView($supplierId, $employeeId, $effectiveOn);
        if ($view === null) {
            throw new \DomainException(
                'Zákonná evidence zaměstnance nebyla nalezena, takže se do ní '
                . 'nedá zapsat. Otevřete kartu osoby a zkuste to znovu.',
            );
        }
        /** @var array<string,list<array<string,mixed>>> $sections */
        $sections = $view['sections'];

        foreach ($groups as $key => $items) {
            if ($key === PayrollRegistrationA1MasterDataFields::TARGET_TAX_RESIDENCE) {
                $sections['tax_residences'] = $this->patchStatutoryRow(
                    $sections['tax_residences'],
                    $effectiveOn,
                    'Daňová rezidence',
                    PayrollRegistrationFieldVocabulary::WHERE_TAX_RESIDENCY,
                    function (array $row) use ($items): array {
                        $country = strtoupper($items[0]['value']);
                        $row['residence'] = $country === 'CZ'
                            ? 'czech-resident'
                            : 'non-resident';
                        $row['country_code'] = $country;

                        return $row;
                    },
                );
                continue;
            }
            $sections['health_coverages'] = $this->patchStatutoryRow(
                $sections['health_coverages'],
                $effectiveOn,
                'Zdravotní pojištění',
                PayrollRegistrationFieldVocabulary::WHERE_HEALTH_INSURANCE,
                static function (array $row) use ($items): array {
                    $row['insurer_code'] = $items[0]['value'];

                    return $row;
                },
            );
        }

        $this->statutory->save(
            $supplierId,
            $employeeId,
            ['sections' => $sections],
            $effectiveOn,
            $userId,
            $ip,
            $userAgent,
        );
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param callable(array<string,mixed>):array<string,mixed> $patch
     * @return list<array<string,mixed>>
     */
    private function patchStatutoryRow(
        array $rows,
        string $effectiveOn,
        string $what,
        string $where,
        callable $patch,
    ): array {
        $covering = $this->covering($rows, $effectiveOn, null);
        if ($covering === null) {
            throw new \DomainException(
                $what . ' není k rozhodnému dni registrace v evidenci vedená, '
                . 'takže se do ní nedá zapsat. Založte ji na ' . $where . '.',
            );
        }
        $result = [];
        foreach ($rows as $row) {
            $result[] = (int) $row['id'] === (int) $covering['id']
                ? $patch($row)
                : $row;
        }

        return $result;
    }

    /**
     * Sjednané podmínky se OPRAVUJÍ, nezakládá se jejich nová verze.
     *
     * Nová verze podmínek tvrdí „od tohohle dne se sjednalo něco jiného"
     * a zakládá oznamovací povinnost. Tady jde ale o údaj, který byl od začátku
     * zapsaný špatně — a přesně na to je oprava platné verze.
     *
     * @param list<array{path:string,value:string}> $items
     */
    private function writeEmploymentTerms(
        int $supplierId,
        int $employeeId,
        int $employmentId,
        string $effectiveOn,
        array $items,
        ?int $userId,
        ?string $ip,
        ?string $userAgent,
    ): void {
        $current = $this->employments->currentTerms($supplierId, $employmentId);
        if ($current === null) {
            throw new \DomainException(
                'Pracovní vztah nemá žádnou verzi sjednaných podmínek, do '
                . 'které by šlo zapsat. Doplňte je na '
                . PayrollRegistrationFieldVocabulary::WHERE_RELATIONSHIP_TERMS
                . '.',
            );
        }
        $from = (string) $current['effective_from'];
        if ($from > $effectiveOn) {
            /*
             * Rozhodný den pokrývá STARŠÍ verze podmínek, ne ta platná. Opravou
             * platné verze by se změnil jiný údaj, než který registrace nese,
             * a účetní by o tom nevěděla.
             */
            throw new \DomainException(
                'Ke dni registrace platila starší verze sjednaných podmínek '
                . 'než ta dnešní, takže se oprava nedá bezpečně zapsat — '
                . 'změnila by jiné období. Opravte údaj na '
                . PayrollRegistrationFieldVocabulary::WHERE_RELATIONSHIP_TERMS
                . '.',
            );
        }

        $body = $this->termsBody($current);
        foreach ($items as $item) {
            $target = PayrollRegistrationA1MasterDataFields::target($item['path']);
            if ($target !== null) {
                $body[$target['column']] = $item['value'];
            }
        }
        $body['effective_from'] = $from;

        $employmentVersion = null;
        foreach ($this->employments->listForEmployee($supplierId, $employeeId) as $row) {
            if ((int) $row['id'] === $employmentId) {
                $employmentVersion = (int) $row['row_version'];
                break;
            }
        }
        if ($employmentVersion === null) {
            throw new \OutOfBoundsException(self::EMPLOYMENT_NOT_FOUND);
        }

        $this->employments->correctTerms(
            $supplierId,
            $employmentId,
            $this->employmentValidator->terms(
                $body,
                $this->employments->currentCzIscoCode($supplierId, $employmentId),
                $this->employments->currentOtherWithholdingEligibility(
                    $supplierId,
                    $employmentId,
                ),
                $this->employments->currentRelationType($supplierId, $employmentId),
            ),
            $employmentVersion,
            $userId,
            $ip,
            $userAgent,
        );
    }

    /**
     * Uložená verze podmínek jako vstup validátoru. Posílá se CELÁ, protože
     * `correctTerms()` bere cílový stav — vynechané pole by se vyprázdnilo.
     *
     * @param array<string,mixed> $current
     * @return array<string,mixed>
     */
    private function termsBody(array $current): array
    {
        $keys = [
            'office_id',
            'contract_signed_on',
            'planned_start_on',
            'actual_start_on',
            'fixed_term_end_on',
            'weekly_hours',
            'workload_basis_points',
            'work_place',
            'regular_workplace',
            'jmhz_workplace_municipality_code',
            'jmhz_workplace_country_code',
            'jmhz_external_codebook_overlay_key',
            'jmhz_external_codebook_manifest_sha256',
            'jmhz_apz_contribution_status',
            'jmhz_apz_instrument_code',
            'jmhz_functional_benefits_status',
            'jmhz_temporary_assignment_status',
            'jmhz_orchard_discount_eligible',
            'jmhz_specific_legal_fact_applies',
            'jmhz_ozp_employment_support_applies',
            'jmhz_deep_mining_work_applies',
            'cz_isco_code',
            'activity_code',
            'jmhz_relationship_detail_code',
            'social_insurance_participation',
            'health_insurance_participation',
            'tax_regime',
            'other_withholding_eligibility',
            'foreign_legislation_country_code',
            'a1_certificate_until',
            'risky_work',
            'social_employer_rate_category',
            'social_employer_rate_category_evidence',
            'social_part_time_discount_reason',
            'social_part_time_discount_evidence',
            'social_part_time_discount_notified_on',
            'is_primary',
        ];
        $body = [];
        foreach ($keys as $key) {
            $body[$key] = $current[$key] ?? null;
        }
        $override = $current['leave_entitlement_weeks_override'] ?? null;
        $body['leave_entitlement_weeks_override'] = $override === null
            ? null
            : (int) $override;
        $body['change_reason'] = 'Oprava údaje z formuláře registrace REGZEC A1.';

        return $body;
    }

    /** @param list<array<string,mixed>> $identifiers */
    private function identifierId(array $identifiers, string $type): ?int
    {
        foreach ($identifiers as $row) {
            if (($row['identifier_type'] ?? null) === $type) {
                return (int) $row['id'];
            }
        }

        return null;
    }

    private function addressSection(string $addressType): string
    {
        return $addressType === 'mailing'
            ? 'contact_address'
            : 'permanent_address';
    }

    private function text(mixed $section, string $key): ?string
    {
        if (!is_array($section)) {
            return null;
        }
        $value = $section[$key] ?? null;
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Historizovaná evidence osoby nikdy nezačíná v budoucnu — karta osoby to
     * odmítá stejně. U vztahu s dopředu zadaným nástupem to tedy nejde, a je
     * lepší to říct větou než nechat spadnout kontrolu na název sloupce.
     */
    private function assertNotFuture(string $effectiveOn): void
    {
        if ($effectiveOn <= date('Y-m-d')) {
            return;
        }
        throw new \DomainException(
            'Nástup je zadaný dopředu, takže se historie osoby k tomu dni '
            . 'ještě založit nedá — evidence nikdy nezačíná v budoucnu. '
            . 'Zapište údaj po nástupu, nebo ho opravte přímo na kartě osoby.',
        );
    }

    private function previousDay(string $date): string
    {
        return (new \DateTimeImmutable($date))
            ->modify('-1 day')
            ->format('Y-m-d');
    }

    /** @return list<mixed> */
    private function list(mixed $value): array
    {
        return is_array($value) && array_is_list($value) ? $value : [];
    }

    /**
     * @param list<string>|array<mixed> $fields
     * @return list<string>
     */
    private function unique(array $fields): array
    {
        $result = [];
        foreach ($fields as $field) {
            if (is_string($field) && $field !== '' && !in_array($field, $result, true)) {
                $result[] = $field;
            }
        }
        if ($result === []) {
            throw new \InvalidArgumentException(
                'Není vybraný žádný údaj, který se má do kmenových dat zapsat.',
            );
        }

        return $result;
    }
}
