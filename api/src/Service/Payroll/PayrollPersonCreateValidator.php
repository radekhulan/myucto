<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

/**
 * @phpstan-import-type EmploymentCreateInput from PayrollEmploymentValidator
 * @phpstan-type SharedEmployeeCreateInput array{
 *   full_name:string,
 *   birth_date:?string,
 *   address:null,
 *   taxpayer_type:string,
 *   employment_type:string,
 *   tax_declaration_signed:bool,
 *   tax_credit_taxpayer:bool,
 *   child_count:int,
 *   net_settlement_account_code:null,
 *   monthly_gross:?int,
 *   auto_post:bool,
 *   is_active:bool
 * }
 * @phpstan-type PayrollPersonCreateInput array{
 *   employee:SharedEmployeeCreateInput,
 *   employment:EmploymentCreateInput,
 *   first_name:?string,
 *   last_name:?string,
 *   birth_number:?string,
 *   health_insurer_code:?string
 * }
 */
final class PayrollPersonCreateValidator
{
    private const MAX_MONTHLY_GROSS = 10_000_000;

    /**
     * Délka `payroll_person_identity_history.first_name` / `last_name` —
     * shodná mez jako na osobní kartě (`PayrollPersonProfileValidator`), aby
     * jméno, které projde založením, prošlo i editací karty.
     */
    private const MAX_NAME_PART = 96;

    /**
     * Obecná stanovená týdenní pracovní doba (§ 79 odst. 1 ZP, 40 hodin)
     * v setinách hodiny — týž základ, ze kterého vychází i výchozích `40.00`
     * u týdenní doby níž.
     */
    private const STATUTORY_WEEKLY_CENTI_HOURS = 4_000;

    public function __construct(
        private readonly PayrollEmploymentValidator $employmentValidator,
    ) {}

    /**
     * @param array<string,mixed> $input
     * @return PayrollPersonCreateInput
     */
    public function validate(array $input): array
    {
        $fullName = trim($this->string($input['full_name'] ?? null));
        if ($fullName === '') {
            throw new \InvalidArgumentException('Jméno a příjmení je povinné.');
        }
        if (mb_strlen($fullName) > 191) {
            throw new \InvalidArgumentException('Jméno a příjmení může mít nejvýše 191 znaků.');
        }

        /*
         * Křestní jméno a příjmení jdou ZVLÁŠŤ, protože měsíční JMHZ je hlásí
         * odděleně a nad `full_name` si je domýšlet nesmí nikdo (migrace 1272).
         * Zakládací formulář obě pole vyžaduje a `full_name` z nich skládá;
         * na úrovni API zůstávají nepovinná, aby starší klient ani API token
         * nepřestal osoby zakládat — bez nich pak osoba svítí jako „vyžaduje
         * doplnění" přesně jako dřív.
         */
        $firstName = $this->optionalNamePart(
            $input['first_name'] ?? null,
            'Křestní jméno může mít nejvýše ' . self::MAX_NAME_PART . ' znaků.',
        );
        $lastName = $this->optionalNamePart(
            $input['last_name'] ?? null,
            'Příjmení může mít nejvýše ' . self::MAX_NAME_PART . ' znaků.',
        );

        $birthDate = $this->optionalDate(
            $input['birth_date'] ?? null,
            'Datum narození musí být ve formátu YYYY-MM-DD.',
        );
        /*
         * Rodné číslo NEPATŘÍ na kartu zaměstnance — `payroll_employees.birth_number`
         * je otevřený legacy sloupec (W1/P-02, migrace 1611) a repozitář ho
         * záměrně ani nečte, ani nezapisuje. Jediné legální úložiště je šifrovaný
         * `payroll_person_identifiers`, odkud ho čtou podání i mzdový list, a ten
         * drží kanonický tvar RRMMDD/XXXX. Kontroluje ho proto tentýž
         * `CzechBirthNumber` jako osobní karta: číslo, které by podání odmítlo,
         * nemá do evidence propadnout jen proto, že se zadává při založení.
         */
        $birthNumber = trim($this->string($input['birth_number'] ?? null));
        $birthNumber = $birthNumber === ''
            ? null
            : CzechBirthNumber::normalize($birthNumber);

        $monthlyGross = $input['monthly_gross'] ?? null;
        if ($monthlyGross !== null
            && (!is_int($monthlyGross)
                || $monthlyGross < 0
                || $monthlyGross > self::MAX_MONTHLY_GROSS)
        ) {
            throw new \InvalidArgumentException(
                'Pravidelná hrubá mzda musí být celé číslo v rozsahu 0 až 10 000 000 Kč.',
            );
        }

        $relationType = $this->string($input['relation_type'] ?? null);
        $plannedStart = $this->string($input['planned_start_on'] ?? null);
        $officeId = $input['office_id'] ?? null;
        /*
         * Týdenní doba se dosazovala natvrdo na 40 hodin, takže poloviční úvazek
         * se musel po založení hned přepsat novou verzí podmínek — a do historie
         * vztahu tím spadl jednodenní interval s úvazkem, který nikdy neplatil.
         * Tvar i meze hlídá `PayrollEmploymentValidator::terms()`; tady se jen
         * předává dál, aby zůstal JEDEN validátor týdenní doby.
         */
        $weeklyHours = $input['weekly_hours'] ?? null;
        if ($weeklyHours === '') {
            $weeklyHours = null;
        }
        /*
         * Kód zdravotní pojišťovny je nepovinný a jen se převezme. Jeho platnost
         * proti číselníku (`HealthInsurers`) ověří až zákonná evidence osoby
         * — pravidlo tak zůstává na jednom místě a chybný kód shodí celé
         * založení, protože všechno běží v jedné transakci.
         */
        $insurerCode = trim($this->string($input['health_insurer_code'] ?? null));

        $employment = $this->employmentValidator->create([
            'code' => 'ZAM-PENDING',
            'relation_type' => $relationType,
            'monthly_gross_minor' => $monthlyGross === null ? null : $monthlyGross * 100,
            'terms' => [
                'office_id' => $officeId,
                'effective_from' => $plannedStart,
                'contract_signed_on' => null,
                'planned_start_on' => $plannedStart,
                'actual_start_on' => null,
                'fixed_term_end_on' => null,
                'weekly_hours' => $weeklyHours ?? '40.00',
                /*
                 * Úvazek se dosazoval natvrdo na 10 000 bazických bodů, takže
                 * dvacetihodinový úvazek platil hned po založení za plný. Na tom
                 * čísle přitom stojí zákaz nařízeného přesčasu u kratší pracovní
                 * doby podle § 78 odst. 1 písm. i) ZP — viz
                 * {@see \MyInvoice\Service\Payroll\Time\Overtime\OvertimeEmploymentProfile::partTimeOn()}.
                 */
                'workload_basis_points' => self::workloadBasisPoints($weeklyHours ?? '40.00'),
                'work_place' => null,
                'regular_workplace' => null,
                'jmhz_workplace_municipality_code' => null,
                'jmhz_workplace_country_code' => null,
                'jmhz_apz_contribution_status' => 'unverified',
                'jmhz_apz_instrument_code' => null,
                'jmhz_functional_benefits_status' => 'unverified',
                'jmhz_temporary_assignment_status' => 'unverified',
                'cz_isco_code' => null,
                'activity_code' => null,
                'jmhz_relationship_detail_code' => null,
                'social_insurance_participation' => 'automatic',
                'health_insurance_participation' => 'automatic',
                'tax_regime' => 'advance',
                'foreign_legislation_country_code' => null,
                'a1_certificate_until' => null,
                'risky_work' => false,
                'tax_declaration_signed' => false,
                'is_primary' => true,
                'change_reason' => 'Počáteční podmínky při založení zaměstnance.',
            ],
        ]);

        return [
            'employee' => [
                'full_name' => $fullName,
                'birth_date' => $birthDate,
                'address' => null,
                'taxpayer_type' => in_array(
                    $relationType,
                    ['partner_dependent', 'statutory_body'],
                    true,
                ) ? 'managing_partner' : 'employee',
                // Klíče jsou shodné s `payroll_employments.relation_type` všude, kde ta
                // hodnota v účetní větvi existuje — mapování je pak identita, ne překlad.
                // `partner_dependent` v ENUM `payroll_employees.employment_type` protějšek
                // nemá, takže spadá na `hpp`; kontaci 522/366 mu i tak zajistí
                // `taxpayer_type = managing_partner` výš.
                'employment_type' => match ($relationType) {
                    'dpp' => 'dpp',
                    'dpc' => 'dpc',
                    // Migrace 1302 — do té doby dostal člen statutárního orgánu na
                    // legacy kartě „pracovní poměr", což u odměny podle § 6/1/c ZDP
                    // není pravda.
                    'statutory_body' => 'statutory_body',
                    default => 'hpp',
                },
                'tax_declaration_signed' => false,
                'tax_credit_taxpayer' => true,
                'child_count' => 0,
                'net_settlement_account_code' => null,
                'monthly_gross' => $monthlyGross,
                'auto_post' => false,
                'is_active' => true,
            ],
            'employment' => $employment,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'birth_number' => $birthNumber,
            'health_insurer_code' => $insurerCode === '' ? null : $insurerCode,
        ];
    }

    /**
     * Úvazek v bazických bodech jako poměr sjednané a stanovené týdenní doby.
     *
     * Tvar i meze týdenní doby hlídá `PayrollEmploymentValidator::terms()` —
     * zůstává tak JEDEN validátor. Co se sem nevejde do vzoru, projde s plným
     * úvazkem a shodí to až on, se svou hláškou.
     */
    private static function workloadBasisPoints(mixed $weeklyHours): int
    {
        if ((!is_string($weeklyHours) && !is_int($weeklyHours))
            || preg_match('/^(\d{1,3})(?:\.(\d{1,2}))?$/', (string) $weeklyHours, $parts) !== 1
        ) {
            return 10_000;
        }
        $centiHours = ((int) $parts[1] * 100) + (int) str_pad($parts[2] ?? '', 2, '0');
        if ($centiHours <= 0) {
            return 10_000;
        }

        // Delší sjednaná doba než stanovená není přesčasový úvazek — sloupec
        // `payroll_employment_terms.workload_basis_points` končí na 100 %.
        return min(
            10_000,
            (int) round($centiHours * 10_000 / self::STATUTORY_WEEKLY_CENTI_HOURS),
        );
    }

    private function string(mixed $value): string
    {
        if (is_string($value) || is_int($value)) {
            return (string) $value;
        }
        if ($value === null) {
            return '';
        }
        throw new \InvalidArgumentException('Textové pole má neplatný typ.');
    }

    /**
     * Část jména tak, jak ji uživatel zadal — jen ořez a mez, žádné dělení
     * ani skládání. Řídicí znaky odmítá až karta, tady stačí táž délka.
     */
    private function optionalNamePart(mixed $value, string $error): ?string
    {
        $text = trim($this->string($value));
        if ($text === '') {
            return null;
        }
        if (mb_strlen($text) > self::MAX_NAME_PART) {
            throw new \InvalidArgumentException($error);
        }

        return $text;
    }

    private function optionalDate(mixed $value, string $error): ?string
    {
        $text = trim($this->string($value));
        if ($text === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $text);
        $errors = \DateTimeImmutable::getLastErrors();
        if ($date === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $text
        ) {
            throw new \InvalidArgumentException($error);
        }
        return $text;
    }
}
