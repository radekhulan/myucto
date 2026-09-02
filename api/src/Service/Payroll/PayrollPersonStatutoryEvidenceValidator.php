<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

use DateTimeImmutable;
use InvalidArgumentException;
use MyInvoice\Service\Codebook\HealthInsurers;

final class PayrollPersonStatutoryEvidenceValidator
{
    /** @param array<string,mixed> $raw
     *  @return array<string,mixed>
     */
    public function normalize(int $employeeId, string $effectiveOn, array $raw): array
    {
        if ($employeeId <= 0) {
            throw new InvalidArgumentException('employee_id musí být kladné celé číslo.');
        }
        $this->date($effectiveOn, 'effective_on');
        $monthStart = substr($effectiveOn, 0, 7) . '-01';
        $monthEnd = (new DateTimeImmutable($monthStart))
            ->modify('last day of this month')
            ->format('Y-m-d');

        $health = $this->section($raw, 'health');
        $coverages = $this->intervalRows(
            $this->rows($health, 'coverages'),
            'zdravotní krytí',
            static fn (array $row): string => 'coverage',
        );
        $minimumReductions = $this->intervalRows(
            $this->rows($health, 'minimum_reductions'),
            'snížení minima zdravotního pojištění',
            fn (array $row): string => $this->enum(
                $row,
                'reason',
                [
                    'state_insured',
                    'ztp_or_ztp_p',
                    'pension_age_without_pension',
                    'sickness_care_or_quarantine',
                    'osvc_minimum_advance',
                    'foster_reward_only',
                    'unverified',
                ],
            ),
        );
        $monthEvidence = $this->monthRows(
            $this->rows($health, 'month_evidence'),
            'měsíční evidence zdravotního minima',
            $monthStart,
            static fn (array $row): string => 'month',
        );
        $otherEmployerBases = $this->monthRows(
            $this->rows($health, 'other_employer_bases'),
            'evidence jiného zaměstnavatele',
            $monthStart,
            fn (array $row): string => $this->canonical(
                $row,
                'employer_reference',
            ),
        );

        $incomeTax = $this->section($raw, 'income_tax');
        $declarations = $this->intervalRows(
            $this->rows($incomeTax, 'declarations'),
            'daňové prohlášení',
            static fn (array $row): string => 'declaration',
        );
        $residences = $this->intervalRows(
            $this->rows($incomeTax, 'residences'),
            'daňová rezidence',
            static fn (array $row): string => 'residence',
        );
        $creditClaims = $this->intervalRows(
            $this->rows($incomeTax, 'credit_claims'),
            'daňová sleva',
            fn (array $row): string => $this->enum(
                $row,
                'credit_kind',
                ['taxpayer', 'disability-basic', 'disability-extended', 'ztp-p'],
            ),
        );
        $childClaims = $this->intervalRows(
            $this->rows($incomeTax, 'child_claims'),
            'daňové zvýhodnění na dítě',
            fn (array $row): string => $this->canonical($row, 'child_reference'),
        );

        $social = $this->section($raw, 'social');
        $jurisdictions = $this->intervalRows(
            $this->rows($social, 'jurisdictions'),
            'sociální jurisdikce',
            static fn (array $row): string => 'jurisdiction',
        );
        $discountClaims = $this->intervalRows(
            $this->rows($social, 'discount_claims'),
            'sleva pracujícího důchodce',
            static fn (array $row): string => 'discount',
        );

        $effectiveCoverages = $this->intersecting(
            $coverages,
            $monthStart,
            $monthEnd,
        );
        $effectiveReductions = $this->intersecting(
            $minimumReductions,
            $monthStart,
            $monthEnd,
        );
        $effectiveDeclarations = $this->effective($declarations, $monthStart);
        $effectiveResidences = $this->effective($residences, $monthStart);
        $effectiveCredits = $this->effective($creditClaims, $monthStart);
        $effectiveChildren = $this->effective($childClaims, $monthStart);
        $effectiveJurisdictions = $this->intersecting(
            $jurisdictions,
            $monthStart,
            $monthEnd,
        );
        $effectiveDiscounts = $this->intersecting(
            $discountClaims,
            $monthStart,
            $monthEnd,
        );

        $normalizedOtherEmployers = array_map(
            fn (array $row): array => $this->healthOtherEmployer($row),
            $otherEmployerBases,
        );
        $normalizedMonthEvidence = array_map(
            fn (array $row): array => $this->healthMonthEvidence($row),
            $monthEvidence,
        );
        if (count($normalizedMonthEvidence) > 1) {
            throw new InvalidArgumentException(
                'Měsíční evidence zdravotního minima se pro období překrývá.',
            );
        }
        $this->assertSelectedOtherEmployer(
            $normalizedMonthEvidence[0] ?? null,
            $normalizedOtherEmployers,
        );

        $normalizedChildren = array_map(
            fn (array $row): array => $this->taxChildClaim($row),
            $effectiveChildren,
        );
        $orders = array_column($normalizedChildren, 'child_order');
        if (count($orders) !== count(array_unique($orders))) {
            throw new InvalidArgumentException(
                'Pořadí současně uplatňovaných dětí musí být jedinečné.',
            );
        }

        return [
            'schema_version' => 'payroll-person-statutory-evidence.v1',
            'employee_id' => $employeeId,
            'effective_on' => $effectiveOn,
            'health' => [
                'coverage' => $this->single(
                    array_map(
                        fn (array $row): array => $this->healthCoverage($row),
                        $effectiveCoverages,
                    ),
                    'Zdravotní krytí',
                ),
                'minimum_reductions' => array_map(
                    fn (array $row): array => $this->healthMinimumReduction($row),
                    $effectiveReductions,
                ),
                'month_evidence' => $normalizedMonthEvidence[0] ?? null,
                'other_employer_bases' => $normalizedOtherEmployers,
            ],
            'income_tax' => [
                'declaration' => $this->single(
                    array_map(
                        fn (array $row): array => $this->taxDeclaration($row),
                        $effectiveDeclarations,
                    ),
                    'Daňové prohlášení',
                ),
                'residence' => $this->single(
                    array_map(
                        fn (array $row): array => $this->taxResidence($row),
                        $effectiveResidences,
                    ),
                    'Daňová rezidence',
                ),
                'credit_claims' => array_map(
                    fn (array $row): array => $this->taxCreditClaim($row),
                    $effectiveCredits,
                ),
                'child_claims' => $normalizedChildren,
            ],
            'social' => [
                'jurisdiction' => $this->single(
                    array_map(
                        fn (array $row): array => $this->socialJurisdiction(
                            $row,
                            $effectiveOn,
                        ),
                        $effectiveJurisdictions,
                    ),
                    'Sociální jurisdikce',
                ),
                'working_pensioner_discount' => $this->single(
                    array_map(
                        fn (array $row): array => $this->socialDiscount($row),
                        $effectiveDiscounts,
                    ),
                    'Sleva pracujícího důchodce',
                ),
            ],
        ];
    }

    /** @param array<string,mixed> $row
     *  @return array<string,mixed>
     */
    private function healthCoverage(array $row): array
    {
        $jurisdiction = $this->enum(
            $row,
            'jurisdiction',
            ['czech_regime_verified', 'foreign_regime_verified', 'unverified'],
        );
        $country = $this->country($row, 'foreign_country_code');
        $jurisdictionEvidence = $this->nullableCanonical(
            $row,
            'jurisdiction_evidence_reference',
        );
        if ($jurisdiction === 'foreign_regime_verified') {
            if ($country === null) {
                throw new InvalidArgumentException(
                    'Ověřená zahraniční zdravotní jurisdikce vyžaduje zemi.',
                );
            }
        } elseif ($country !== null || $jurisdictionEvidence !== null) {
            throw new InvalidArgumentException(
                'Česká nebo neověřená zdravotní jurisdikce nesmí nést zahraniční důkaz.',
            );
        }

        $insurerStatus = $this->enum(
            $row,
            'insurer_status',
            ['verified', 'unverified', 'not_applicable'],
        );
        $insurerCode = $this->nullableString($row, 'insurer_code');
        $insurerEvidence = $this->nullableCanonical(
            $row,
            'insurer_evidence_reference',
        );
        // Tvar `\d{3}` nestačí — evidence jde až do zákonného podání, takže se
        // kód musí shodovat se skutečným číselníkem pojišťoven.
        if ($insurerCode !== null && !HealthInsurers::isValid($insurerCode)) {
            throw new InvalidArgumentException(HealthInsurers::invalidCodeMessage($insurerCode));
        }
        if ($insurerStatus === 'verified' && $insurerCode === null) {
            throw new InvalidArgumentException(
                'Ověřená zdravotní pojišťovna vyžaduje kód.',
            );
        }
        if ($insurerStatus === 'not_applicable'
            && ($insurerCode !== null || $insurerEvidence !== null)
        ) {
            throw new InvalidArgumentException(
                'Nepoužitelná česká zdravotní pojišťovna nesmí nést kód ani důkaz.',
            );
        }
        if ($insurerStatus === 'unverified' && $insurerEvidence !== null) {
            throw new InvalidArgumentException(
                'Neověřená zdravotní pojišťovna nesmí nést ověřený důkaz.',
            );
        }
        /*
         * Jurisdikce váže stav pojišťovny — stejně jako u sociální jurisdikce
         * a A1 ({@see self::socialJurisdiction()}). Vazba je ale OTOČENÁ, a to
         * věcně: A1 je doklad, který existuje jen u přeshraničního případu,
         * takže česká jurisdikce ho má mít `not_applicable`. Pojišťovna je
         * naopak tuzemský protějšek — kdo podléhá českému veřejnému
         * zdravotnímu pojištění (§ 2 zákona č. 48/1997 Sb.), je vždy u některé
         * z nich. „Nepoužitelná" pojišťovna proto popírá právě to, co ověřená
         * česká jurisdikce tvrdí.
         *
         * Zakazuje se JEN `not_applicable`, ne i `unverified`:
         *  - `not_applicable` je TVRZENÍ o skutečnosti, a to protichůdné;
         *  - `unverified` je přiznané „zatím nevíme", tedy legitimní mezistav,
         *    ve kterém uživatel evidenci rozepisuje. Přitvrdit i na něj by
         *    znemožnilo uložit rozdělanou kartu.
         *
         * PayrollRunStatutoryInputAssembler::healthPerson() je přísnější —
         * u české jurisdikce hlásí `health_coverage_evidence_conflict` pro
         * všechno kromě `verified`. Neodporuje si to: `unverified` tam
         * neprojde jako platný podklad, ale ohlásí se jako blokátor běhu
         * (vedle `health_insurer_evidence_unverified`), ne jako odmítnutý
         * zápis. Rozhoduje se tak až tam, kde na tom skutečně záleží.
         */
        if ($jurisdiction === 'czech_regime_verified'
            && $insurerStatus === 'not_applicable'
        ) {
            throw new InvalidArgumentException(
                'Ověřená česká zdravotní jurisdikce nemůže mít pojišťovnu'
                . ' označenou jako nepoužitelnou — vyberte pojišťovnu a doložte'
                . ' ji, nebo ji do zjištění nechte jako neověřenou.',
            );
        }

        return $this->baseInterval($row) + [
            'jurisdiction' => $jurisdiction,
            'foreign_country_code' => $country,
            'jurisdiction_evidence_reference' => $jurisdictionEvidence,
            'insurer_status' => $insurerStatus,
            'insurer_code' => $insurerCode,
            'insurer_evidence_reference' => $insurerEvidence,
        ];
    }

    /** @param array<string,mixed> $row
     *  @return array<string,mixed>
     */
    private function healthMinimumReduction(array $row): array
    {
        $reason = $this->enum(
            $row,
            'reason',
            [
                'state_insured',
                'ztp_or_ztp_p',
                'pension_age_without_pension',
                'sickness_care_or_quarantine',
                'osvc_minimum_advance',
                'foster_reward_only',
                'unverified',
            ],
        );
        $evidence = $this->nullableCanonical($row, 'evidence_reference');
        $this->assertEvidenceAllowed($reason !== 'unverified', $evidence, 'redukce minima');

        return $this->baseInterval($row) + [
            'reason' => $reason,
            'evidence_reference' => $evidence,
        ];
    }

    /** @param array<string,mixed> $row
     *  @return array<string,mixed>
     */
    private function healthMonthEvidence(array $row): array
    {
        $responsibility = $this->enum(
            $row,
            'top_up_responsibility',
            ['employee', 'employer_obstacle_verified', 'unverified'],
        );
        $responsibilityEvidence = $this->nullableCanonical(
            $row,
            'top_up_responsibility_evidence_reference',
        );
        $this->assertEvidenceAllowed(
            $responsibility === 'employer_obstacle_verified',
            $responsibilityEvidence,
            'odpovědnost za doplatek minima',
        );
        $selected = $this->nullableCanonical(
            $row,
            'selected_top_up_employer_reference',
        );
        $selectedEvidence = $this->nullableCanonical(
            $row,
            'selected_top_up_employer_evidence_reference',
        );
        if ($selected === null && $selectedEvidence !== null) {
            throw new InvalidArgumentException(
                'Doklad k volbě zaměstnavatele vyžaduje zvoleného zaměstnavatele.',
            );
        }

        return $this->baseMonth($row) + [
            'top_up_responsibility' => $responsibility,
            'top_up_responsibility_evidence_reference' => $responsibilityEvidence,
            'selected_top_up_employer_reference' => $selected,
            'selected_top_up_employer_evidence_reference' => $selectedEvidence,
        ];
    }

    /** @param array<string,mixed> $row
     *  @return array<string,mixed>
     */
    private function healthOtherEmployer(array $row): array
    {
        $employmentFrom = $this->dateValue($row, 'employment_from');
        $employmentTo = $this->nullableDateValue($row, 'employment_to');
        if ($employmentTo !== null && $employmentTo < $employmentFrom) {
            throw new InvalidArgumentException(
                'Konec vztahu u jiného zaměstnavatele předchází jeho začátku.',
            );
        }

        return $this->baseMonth($row) + [
            'employer_reference' => $this->canonical($row, 'employer_reference'),
            'assessment_base_minor_units' => $this->nonNegativeInt(
                $row,
                'assessment_base_minor_units',
            ),
            'employment_from' => $employmentFrom,
            'employment_to' => $employmentTo,
            'evidence_reference' => $this->nullableCanonical($row, 'evidence_reference'),
        ];
    }

    /** @param array<string,mixed> $row
     *  @return array<string,mixed>
     */
    private function taxDeclaration(array $row): array
    {
        $status = $this->enum($row, 'status', ['signed', 'not-signed', 'unverified']);
        $evidence = $this->nullableCanonical($row, 'evidence_reference');
        $this->assertEvidenceAllowed($status !== 'unverified', $evidence, 'daňové prohlášení');

        return $this->baseInterval($row) + [
            'status' => $status,
            'evidence_reference' => $evidence,
        ];
    }

    /** @param array<string,mixed> $row
     *  @return array<string,mixed>
     */
    private function taxResidence(array $row): array
    {
        $residence = $this->enum(
            $row,
            'residence',
            ['czech-resident', 'non-resident', 'unverified'],
        );
        $country = $this->country($row, 'country_code');
        $evidence = $this->nullableCanonical($row, 'evidence_reference');
        if ($residence === 'czech-resident' && $country !== 'CZ') {
            throw new InvalidArgumentException(
                'Česká daňová rezidence vyžaduje kód země CZ.',
            );
        }
        if ($residence === 'non-resident'
            && ($country === null || $country === 'CZ')
        ) {
            throw new InvalidArgumentException(
                'Daňový nerezident vyžaduje zahraniční zemi.',
            );
        }
        if ($residence === 'unverified' && ($country !== null || $evidence !== null)) {
            throw new InvalidArgumentException(
                'Neověřená daňová rezidence nesmí nést ověřené údaje.',
            );
        }

        return $this->baseInterval($row) + [
            'residence' => $residence,
            'country_code' => $country,
            'evidence_reference' => $evidence,
        ];
    }

    /** @param array<string,mixed> $row
     *  @return array<string,mixed>
     */
    private function taxCreditClaim(array $row): array
    {
        $status = $this->enum($row, 'evidence_status', ['verified', 'unverified']);
        $evidence = $this->nullableCanonical($row, 'evidence_reference');
        $this->assertEvidenceAllowed($status === 'verified', $evidence, 'daňová sleva');

        return $this->baseInterval($row) + [
            'credit_kind' => $this->enum(
                $row,
                'credit_kind',
                ['taxpayer', 'disability-basic', 'disability-extended', 'ztp-p'],
            ),
            'evidence_status' => $status,
            'evidence_reference' => $evidence,
        ];
    }

    /** @param array<string,mixed> $row
     *  @return array<string,mixed>
     */
    private function taxChildClaim(array $row): array
    {
        $status = $this->enum($row, 'evidence_status', ['verified', 'unverified']);
        $evidence = $this->nullableCanonical($row, 'evidence_reference');
        $this->assertEvidenceAllowed($status === 'verified', $evidence, 'zvýhodnění na dítě');

        return $this->baseInterval($row) + [
            'child_reference' => $this->canonical($row, 'child_reference'),
            'child_order' => $this->positiveInt($row, 'child_order'),
            'ztp_p' => $this->bool($row, 'ztp_p'),
            'evidence_status' => $status,
            'shared_household_confirmed' => $this->bool(
                $row,
                'shared_household_confirmed',
            ),
            'other_claimant_excluded' => $this->bool($row, 'other_claimant_excluded'),
            'evidence_reference' => $evidence,
        ];
    }

    /** @param array<string,mixed> $row
     *  @return array<string,mixed>
     */
    private function socialJurisdiction(array $row, string $effectiveOn): array
    {
        $jurisdiction = $this->enum(
            $row,
            'jurisdiction',
            ['czech_regime_verified', 'foreign_regime_verified', 'unverified'],
        );
        $country = $this->country($row, 'foreign_country_code');
        $jurisdictionEvidence = $this->nullableCanonical(
            $row,
            'jurisdiction_evidence_reference',
        );
        if ($jurisdiction === 'foreign_regime_verified') {
            if ($country === null) {
                throw new InvalidArgumentException(
                    'Ověřená zahraniční sociální jurisdikce vyžaduje zemi.',
                );
            }
        } elseif ($country !== null || $jurisdictionEvidence !== null) {
            throw new InvalidArgumentException(
                'Česká nebo neověřená sociální jurisdikce nesmí nést zahraniční důkaz.',
            );
        }

        $a1Status = $this->enum(
            $row,
            'a1_status',
            ['verified', 'unverified', 'not_applicable'],
        );
        $a1Reference = $this->nullableCanonical($row, 'a1_certificate_reference');
        $a1Until = $this->nullableDateValue($row, 'a1_valid_until');
        if ($a1Status === 'verified' && ($a1Until === null || $a1Until < $effectiveOn)) {
            throw new InvalidArgumentException(
                'Ověřený A1 musí platit k datu snímku.',
            );
        }
        if ($a1Status !== 'verified' && ($a1Reference !== null || $a1Until !== null)) {
            throw new InvalidArgumentException(
                'Neověřený nebo nepoužitelný A1 nesmí nést ověřené údaje.',
            );
        }
        if ($jurisdiction === 'czech_regime_verified' && $a1Status !== 'not_applicable') {
            throw new InvalidArgumentException(
                'Česká sociální jurisdikce musí mít A1 označený jako nepoužitelný.',
            );
        }

        return $this->baseInterval($row) + [
            'jurisdiction' => $jurisdiction,
            'foreign_country_code' => $country,
            'jurisdiction_evidence_reference' => $jurisdictionEvidence,
            'a1_status' => $a1Status,
            'a1_certificate_reference' => $a1Reference,
            'a1_valid_until' => $a1Until,
        ];
    }

    /** @param array<string,mixed> $row
     *  @return array<string,mixed>
     */
    private function socialDiscount(array $row): array
    {
        $status = $this->enum($row, 'status', ['not_claimed', 'verified', 'unverified']);
        $evidence = $this->nullableCanonical($row, 'evidence_reference');
        $this->assertEvidenceAllowed($status === 'verified', $evidence, 'sleva důchodce');

        return $this->baseInterval($row) + [
            'status' => $status,
            'evidence_reference' => $evidence,
        ];
    }

    /** @param array<string,mixed>|null $monthEvidence
     *  @param list<array<string,mixed>> $otherEmployers
     */
    private function assertSelectedOtherEmployer(
        ?array $monthEvidence,
        array $otherEmployers,
    ): void {
        $selected = $monthEvidence['selected_top_up_employer_reference'] ?? null;
        if (!is_string($selected)) {
            return;
        }
        foreach ($otherEmployers as $otherEmployer) {
            if (($otherEmployer['employer_reference'] ?? null) === $selected) {
                return;
            }
        }
        throw new InvalidArgumentException(
            'Zvolený jiný zaměstnavatel nemá pro měsíc doložený vyměřovací základ.',
        );
    }

    /** @param list<array<string,mixed>> $rows
     *  @param callable(array<string,mixed>):string $scope
     *  @return list<array<string,mixed>>
     */
    private function intervalRows(array $rows, string $label, callable $scope): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $from = $this->dateValue($row, 'effective_from');
            $to = $this->nullableDateValue($row, 'effective_to');
            if ($to !== null && $to < $from) {
                throw new InvalidArgumentException("Interval {$label} není seřazen.");
            }
            $grouped[$scope($row)][] = $row;
        }
        foreach ($grouped as $items) {
            usort(
                $items,
                fn (array $left, array $right): int =>
                    $this->dateValue($left, 'effective_from')
                    <=> $this->dateValue($right, 'effective_from'),
            );
            $previousTo = null;
            $first = true;
            foreach ($items as $item) {
                $from = $this->dateValue($item, 'effective_from');
                if (!$first && ($previousTo === null || $from <= $previousTo)) {
                    throw new InvalidArgumentException("Interval {$label} se překrývá.");
                }
                $first = false;
                $previousTo = $this->nullableDateValue($item, 'effective_to');
            }
        }

        return $rows;
    }

    /** @param list<array<string,mixed>> $rows
     *  @param callable(array<string,mixed>):string $scope
     *  @return list<array<string,mixed>>
     */
    private function monthRows(
        array $rows,
        string $label,
        string $monthStart,
        callable $scope,
    ): array {
        $selected = [];
        $seen = [];
        foreach ($rows as $row) {
            $periodStart = $this->dateValue($row, 'period_start');
            if (substr($periodStart, 8, 2) !== '01') {
                throw new InvalidArgumentException("{$label} nemá první den měsíce.");
            }
            if ($periodStart !== $monthStart) {
                continue;
            }
            $key = $scope($row);
            if (isset($seen[$key])) {
                throw new InvalidArgumentException("{$label} se pro období překrývá.");
            }
            $seen[$key] = true;
            $selected[] = $row;
        }

        return $selected;
    }

    /** @param list<array<string,mixed>> $rows
     *  @return list<array<string,mixed>>
     */
    private function effective(array $rows, string $effectiveOn): array
    {
        return array_values(array_filter(
            $rows,
            fn (array $row): bool =>
                $this->dateValue($row, 'effective_from') <= $effectiveOn
                && (
                    $this->nullableDateValue($row, 'effective_to') === null
                    || $this->nullableDateValue($row, 'effective_to') >= $effectiveOn
                ),
        ));
    }

    /** @param list<array<string,mixed>> $rows
     *  @return list<array<string,mixed>>
     */
    private function intersecting(array $rows, string $from, string $to): array
    {
        return array_values(array_filter(
            $rows,
            fn (array $row): bool =>
                $this->dateValue($row, 'effective_from') <= $to
                && (
                    $this->nullableDateValue($row, 'effective_to') === null
                    || $this->nullableDateValue($row, 'effective_to') >= $from
                ),
        ));
    }

    /** @param list<array<string,mixed>> $rows
     *  @return array<string,mixed>|null
     */
    private function single(array $rows, string $label): ?array
    {
        if (count($rows) > 1) {
            throw new InvalidArgumentException("{$label} se k datu snímku překrývá.");
        }

        return $rows[0] ?? null;
    }

    /** @param array<string,mixed> $row
     *  @return array{id:int,effective_from:string,effective_to:?string,row_version:int}
     */
    private function baseInterval(array $row): array
    {
        return [
            'id' => $this->positiveInt($row, 'id'),
            'effective_from' => $this->dateValue($row, 'effective_from'),
            'effective_to' => $this->nullableDateValue($row, 'effective_to'),
            'row_version' => $this->positiveInt($row, 'row_version'),
        ];
    }

    /** @param array<string,mixed> $row
     *  @return array{id:int,period_start:string,row_version:int}
     */
    private function baseMonth(array $row): array
    {
        return [
            'id' => $this->positiveInt($row, 'id'),
            'period_start' => $this->dateValue($row, 'period_start'),
            'row_version' => $this->positiveInt($row, 'row_version'),
        ];
    }

    /** @param array<string,mixed> $raw
     *  @return array<string,mixed>
     */
    private function section(array $raw, string $key): array
    {
        $value = $raw[$key] ?? null;
        if (!is_array($value)) {
            throw new InvalidArgumentException("Sekce {$key} chybí nebo není objekt.");
        }

        return $value;
    }

    /** @param array<string,mixed> $section
     *  @return list<array<string,mixed>>
     */
    private function rows(array $section, string $key): array
    {
        $value = $section[$key] ?? null;
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidArgumentException("Kolekce {$key} chybí nebo není seznam.");
        }
        foreach ($value as $row) {
            if (!is_array($row)) {
                throw new InvalidArgumentException("Kolekce {$key} obsahuje neplatný řádek.");
            }
        }

        /** @var list<array<string,mixed>> $value */
        return $value;
    }

    /** @param array<string,mixed> $row
     *  @param list<string> $allowed
     */
    private function enum(array $row, string $key, array $allowed): string
    {
        $value = $this->string($row, $key);
        if (!in_array($value, $allowed, true)) {
            // Hláška vypisuje, co JDE poslat. Bez toho zbývá hádání, což je
            // u evidence s deseti výčty ta nejdražší forma zdržení.
            throw new InvalidArgumentException(
                "Pole {$key} má nepovolenou hodnotu „{$value}“. Přípustné hodnoty: "
                . implode(', ', $allowed) . '.',
            );
        }

        return $value;
    }

    /** @param array<string,mixed> $row */
    private function canonical(array $row, string $key): string
    {
        $value = $this->string($row, $key);
        if (strlen($value) > 500
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:\/-]*$/D', $value) !== 1
        ) {
            // „Není kanonická reference" nikomu neřekne, co s tím. Tahle věta
            // je shodná s tou, kterou ukazuje formulář (`reference_invalid`).
            throw new InvalidArgumentException(
                "Označení dokladu u pole {$key} smí obsahovat jen písmena bez diakritiky,"
                . ' číslice a znaky . : / _ - , musí začínat písmenem nebo číslicí'
                . ' a být nejvýše 500 znaků dlouhé (např. „declaration:38k-signed“).',
            );
        }

        return $value;
    }

    /** @param array<string,mixed> $row */
    private function nullableCanonical(array $row, string $key): ?string
    {
        return ($row[$key] ?? null) === null ? null : $this->canonical($row, $key);
    }

    /** @param array<string,mixed> $row */
    private function country(array $row, string $key): ?string
    {
        $value = $this->nullableString($row, $key);
        if ($value === null) {
            return null;
        }
        $value = strtoupper($value);
        if (preg_match('/^[A-Z]{2}$/D', $value) !== 1) {
            throw new InvalidArgumentException("Pole {$key} není ISO kód země.");
        }

        return $value;
    }

    private function assertEvidenceAllowed(
        bool $allowed,
        ?string $reference,
        string $label,
    ): void {
        if (!$allowed && $reference !== null) {
            throw new InvalidArgumentException("Neověřená evidence {$label} nesmí nést důkaz.");
        }
    }

    /** @param array<string,mixed> $row */
    private function dateValue(array $row, string $key): string
    {
        $value = $this->string($row, $key);
        $this->date($value, $key);

        return $value;
    }

    /** @param array<string,mixed> $row */
    private function nullableDateValue(array $row, string $key): ?string
    {
        return ($row[$key] ?? null) === null ? null : $this->dateValue($row, $key);
    }

    private function date(string $value, string $key): void
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException("Pole {$key} musí být datum YYYY-MM-DD.");
        }
    }

    /** @param array<string,mixed> $row */
    private function string(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException("Pole {$key} musí být neprázdný text.");
        }

        return trim($value);
    }

    /** @param array<string,mixed> $row */
    private function nullableString(array $row, string $key): ?string
    {
        return ($row[$key] ?? null) === null ? null : $this->string($row, $key);
    }

    /** @param array<string,mixed> $row */
    private function positiveInt(array $row, string $key): int
    {
        $value = $this->int($row, $key);
        if ($value <= 0) {
            throw new InvalidArgumentException("Pole {$key} musí být kladné celé číslo.");
        }

        return $value;
    }

    /** @param array<string,mixed> $row */
    private function nonNegativeInt(array $row, string $key): int
    {
        $value = $this->int($row, $key);
        if ($value < 0) {
            throw new InvalidArgumentException("Pole {$key} nesmí být záporné.");
        }

        return $value;
    }

    /** @param array<string,mixed> $row */
    private function int(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/D', $value) === 1) {
            return (int) $value;
        }
        throw new InvalidArgumentException("Pole {$key} musí být celé číslo.");
    }

    /** @param array<string,mixed> $row */
    private function bool(array $row, string $key): bool
    {
        $value = $row[$key] ?? null;
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 0 || $value === '0') {
            return false;
        }
        if ($value === 1 || $value === '1') {
            return true;
        }
        throw new InvalidArgumentException("Pole {$key} musí být boolean.");
    }
}
