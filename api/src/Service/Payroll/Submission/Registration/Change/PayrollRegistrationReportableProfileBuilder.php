<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration\Change;

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;

/**
 * Průmět hlásitelných údajů ze zdrojů, které aplikace opravdu má.
 *
 * Staví se ze tří kusů, protože ani jeden sám nestačí:
 * - **identita osoby** (`payroll_person_identity_history` + sealed
 *   identifikátory) — jméno, datum narození, občanství, rodné číslo, EČP, VČP,
 * - **profil REGZEC A1** (`payroll_registration_a1_profiles`) — adresy, daňová
 *   rezidence, důchod, zdravotní stav, vzdělání, pojišťovna, cizinci,
 *   příslušnost k cizím předpisům a celý blok zaměstnání,
 * - **živý překryv** — údaje, které se po přihlášení mění JINDE než v profilu.
 *   Dnes je to druh výdělečné činnosti a bližší určení vztahu: obojí žije
 *   v `payroll_employment_terms`, takže profil zmrazený při nástupu by se
 *   o jejich změně nikdy nedozvěděl.
 *
 * Stejná třída staví obě strany porovnání. To je záměr: kdyby výchozí stav
 * vznikal jinou cestou než aktuální, každá odchylka mezi oběma cestami by se
 * tvářila jako změna údaje a hlásila by se do osmi dnů na ČSSZ.
 */
final class PayrollRegistrationReportableProfileBuilder
{
    /**
     * @param array<string,mixed> $identity řádek historické identity osoby
     * @param array{birth_number?:?string,ecp?:?string,vcp?:?string} $identifiers
     * @param array<string,mixed>|null $a1Profile dekódovaný profil REGZEC A1
     * @param array<string,?string> $overlay živý překryv (cesta → hodnota)
     */
    public function build(
        array $identity,
        array $identifiers,
        ?array $a1Profile,
        array $overlay = [],
    ): PayrollRegistrationReportableProfile {
        $values = [];
        foreach ([
            'first_name', 'last_name', 'title_prefix', 'title_suffix',
            'birth_surname', 'birth_date', 'sex', 'citizenship_country_code',
        ] as $field) {
            if (array_key_exists($field, $identity)) {
                $values["identity.{$field}"] = $this->text($identity[$field]);
            }
        }
        foreach (['birth_number', 'ecp', 'vcp'] as $field) {
            if (array_key_exists($field, $identifiers)) {
                $values["identifiers.{$field}"] = $this->text($identifiers[$field]);
            }
        }
        if ($a1Profile !== null) {
            $values += $this->fromA1Profile($a1Profile);
        }
        foreach ($overlay as $path => $value) {
            // Překryv je jediné místo, kudy se do průmětu dá dostat údaj mimo
            // profil, takže právě tady musí stát brána proti měsíčním
            // atributům. Bez ní by stačilo přidat do překryvu úvazek a
            // aplikace by začala hlásit jeho změnu jako registrační událost.
            if (PayrollRegistrationReportableCatalog::isMonthlyReportOnly($path)) {
                throw new \InvalidArgumentException(
                    "Údaj {$path} je měsíční atribut hlášení; jeho změna "
                    . 'registrační akci A3 nespouští a do průmětu nepatří.',
                );
            }
            $values[$path] = $this->text($value);
        }

        return PayrollRegistrationReportableProfile::fromValues($values);
    }

    /**
     * @param array<string,mixed> $profile
     * @return array<string,?string>
     */
    private function fromA1Profile(array $profile): array
    {
        $values = [];
        foreach (PayrollRegistrationReportableCatalog::addressBlocks() as $block) {
            $values += $this->address($block, $this->at($profile, $block));
        }
        $taxResidency = $this->section($profile, 'tax_residency');
        foreach (['country_code', 'identifier_type', 'identifier'] as $field) {
            $values["tax_residency.{$field}"] = $this->text($taxResidency[$field] ?? null);
        }

        $facts = $this->section($profile, 'facts');
        $values['facts.disability_card'] = $this->bool($facts['disability_card'] ?? null);
        $values['facts.highest_education_code'] =
            $this->text($facts['highest_education_code'] ?? null);
        // Omezení je seznam objektů; porovnává se jako jeden kanonický řetězec.
        // Kdyby se porovnávalo po prvcích, vloženo-li omezení doprostřed,
        // „posunuly" by se všechny následující a jedna změna by se nahlásila
        // několikrát.
        $restrictions = $facts['health_restrictions'] ?? null;
        $values['facts.health_restrictions'] = is_array($restrictions)
            ? CanonicalJson::encode(array_values($restrictions))
            : null;

        $values['health_insurance_code'] =
            $this->text($profile['health_insurance_code'] ?? null);

        $foreignWorker = $this->section($profile, 'foreign_worker');
        $values['foreign_worker.free_access'] =
            $this->bool($foreignWorker['free_access'] ?? null);
        foreach ([
            'free_access_reason_code', 'permit_type_code',
            'issuing_labour_office_code', 'permit_identifier',
            'permit_from', 'permit_to',
        ] as $field) {
            $values["foreign_worker.{$field}"] = $this->text($foreignWorker[$field] ?? null);
        }

        $foreignLegislation = $this->section($profile, 'foreign_legislation');
        $values['foreign_legislation.applies'] =
            $this->bool($foreignLegislation['applies'] ?? null);
        $values['foreign_legislation.country_code'] =
            $this->text($foreignLegislation['country_code'] ?? null);

        $pension = $this->section($profile, 'pension');
        $values['pension.type_code'] = $this->text($pension['type_code'] ?? null);
        $values['pension.received_from'] = $this->text($pension['received_from'] ?? null);
        $values['pension.early_retirement'] = $this->bool($pension['early_retirement'] ?? null);
        $values['pension.reduced_retirement_age'] =
            $this->bool($pension['reduced_retirement_age'] ?? null);

        $proof = $this->section($profile, 'proof_identity');
        foreach (['type_code', 'number', 'foreign_issuer', 'country_code'] as $field) {
            $values["proof_identity.{$field}"] = $this->text($proof[$field] ?? null);
        }

        $employment = $this->section($profile, 'employment');
        foreach ([
            'activity_code', 'relationship_detail_code', 'actual_start_on',
            'contract_start_on', 'employment_status_code', 'work_mode_code',
            'prevailing_workplace_code', 'expected_workplaces',
            'contract_workplace', 'workplace_city',
            'workplace_municipality_code', 'profession_code',
            'required_education_code', 'position_name',
        ] as $field) {
            $values["employment.{$field}"] = $this->text($employment[$field] ?? null);
        }
        foreach (['small_scale', 'continuous_operation', 'leadership'] as $field) {
            $values["employment.{$field}"] = $this->bool($employment[$field] ?? null);
        }

        return $values;
    }

    /**
     * @param array<string,mixed>|null $address
     * @return array<string,?string>
     */
    private function address(string $block, ?array $address): array
    {
        $values = [];
        foreach (PayrollRegistrationReportableCatalog::addressPaths($block) as $path) {
            $field = substr($path, strrpos($path, '.') + 1);
            $values[$path] = $this->text($address[$field] ?? null);
        }

        return $values;
    }

    /**
     * Vnořená sekce podle tečkové cesty; chybějící sekce je prázdný objekt,
     * ne chybějící klíče — profil bez důchodu a profil s prázdným důchodem
     * jsou pro registr totéž.
     *
     * @param array<string,mixed> $profile
     * @return array<string,mixed>|null
     */
    private function at(array $profile, string $path): ?array
    {
        $node = $profile;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return null;
            }
            $node = $node[$segment];
        }

        return is_array($node) ? $node : null;
    }

    /**
     * @param array<string,mixed> $profile
     * @return array<string,mixed>
     */
    private function section(array $profile, string $path): array
    {
        return $this->at($profile, $path) ?? [];
    }

    private function text(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (!is_string($value)) {
            throw new \InvalidArgumentException(
                'Hlásitelný údaj REGZEC musí být řetězec, číslo nebo logická hodnota.',
            );
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function bool(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_bool($value)) {
            throw new \InvalidArgumentException(
                'Logický hlásitelný údaj REGZEC musí být true nebo false.',
            );
        }

        return $value ? '1' : '0';
    }
}
