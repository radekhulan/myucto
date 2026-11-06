<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Import\Jmhz;

use MyInvoice\Service\Payroll\CzechBirthNumber;
use MyInvoice\Service\Payroll\IncomeTax\ChildCreditClaimWindow;
use MyInvoice\Service\Payroll\PayrollDependantValidator;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveField;

/**
 * Vyživované děti a měsíční nároky podle § 35c z bloku `zvyhodneniDetiMesic`.
 *
 * Plán počítá náhled i zápis nad TÝMŽ přehledem
 * {@see \MyInvoice\Repository\Payroll\PayrollDependantRepository::overview()}.
 *
 * Rozhodnutí, která hlášení nenese a která se proto dovozují — všechna bez
 * vlivu na částku:
 *  - **Vztah k dítěti** hlášení neuvádí. Nové dítě se zakládá jako vlastní
 *    (`child_own`) s poznámkou; pro měsíční zvýhodnění jsou všechny dětské
 *    vztahy rovnocenné ({@see PayrollDependantValidator::CHILD_RELATIONS}).
 *  - **Pořadí 3** v hlášení znamená „třetí a každé další" (kontrola 110).
 *    Evidence drží pořadí jednoznačně, takže druhé a další dítě s „3" dostane
 *    4, 5… podle data narození; výše zvýhodnění je od třetího dítěte stejná.
 *  - **Dítě „N"** (zvýhodnění uplatňuje jiná osoba) drží v evidenci pořadí
 *    v domácnosti, které hlášení neuvádí. Dostane nejnižší volné pořadí, které
 *    nepoužívá žádné uplatňované dítě; tomuto zaměstnanci z něj žádná částka
 *    nevzniká a do hlášení jde vždy jako „N".
 */
final class JmhzChildClaims
{
    public function __construct(
        private readonly PayrollSensitiveData $sensitiveData,
        private readonly JmhzReportLookup $lookup,
        private readonly PayrollDependantValidator $validator,
    ) {}

    /**
     * @param array<string,mixed> $overview
     * @param array<string,mixed>|null $childCredit
     * @return array{actions:list<array<string,mixed>>,ends:list<array<string,mixed>>,warnings:list<string>}
     */
    public function plan(
        int $supplierId,
        int $employeeId,
        array $overview,
        ?array $childCredit,
        string $monthStart,
        string $reference,
    ): array {
        $frozenThrough = is_string($overview['frozen_through'] ?? null) ? $overview['frozen_through'] : null;
        $dependants = is_array($overview['dependants'] ?? null) ? $overview['dependants'] : [];
        $warnings = [];
        $actions = [];
        $matchedDependantIds = [];

        $children = is_array($childCredit['children'] ?? null) ? $childCredit['children'] : [];
        $caregiver = $this->caregiver($childCredit, $warnings);
        if ($children !== [] && $caregiver === null) {
            $children = [];
        }
        foreach ($this->orders($children, $warnings) as $index => $order) {
            $child = $children[$index];
            $label = $child['given_name'] . ' ' . $child['family_name'];
            [$birthNumber, $birthDate] = $this->birth($child, $label, $warnings);
            if ($birthDate === null) {
                continue;
            }
            $label .= " ({$birthDate})";
            $dependant = $this->dependant($supplierId, $employeeId, $dependants, $child, $birthNumber, $birthDate, $label, $warnings);
            if ($dependant === false) {
                continue;
            }
            $dependantInput = null;
            $claims = $dependant === null ? [] : $this->liveClaims($dependant);
            $covering = JmhzEvidenceTimeline::covering($claims, $monthStart);
            // Důvod nároku: uložený u pokrývajícího nároku má přednost, jinak ho
            // dá vztah k dítěti v evidenci (osvojení, převzetí do péče). Dřív
            // import posílal vždy `null`, osvojené dítě od poloviny měsíce proto
            // skončilo jen varováním a uložený důvod přepsal.
            $claimReason = is_string($covering['claim_reason'] ?? null)
                ? $covering['claim_reason']
                : ($dependant === null ? null : ChildCreditClaimWindow::reasonForRelation((string) $dependant['relation']));
            if ($dependant === null) {
                try {
                    $dependantInput = $this->validator->validateDependant([
                        'relation' => 'child_own',
                        'full_name' => $child['given_name'] . ' ' . $child['family_name'],
                        'given_name' => $child['given_name'],
                        'family_name' => $child['family_name'],
                        'birth_date' => $birthDate,
                        'birth_number' => $birthNumber,
                        'ztp_p' => $child['ztp_p'],
                        'student' => false,
                        'existence_from' => max($birthDate, $monthStart),
                        'existence_to' => null,
                        'note' => 'Založeno importem měsíčního hlášení JMHZ. Vztah k dítěti hlášení neuvádí — '
                            . 'upravte ho podle skutečnosti.',
                    ]);
                } catch (\InvalidArgumentException $e) {
                    $warnings[] = "Dítě {$label} nejde založit: {$e->getMessage()}";
                    continue;
                }
                // Dítě narozené v průběhu měsíce hlášení se zakládá i s nárokem
                // za tento měsíc: měsíc narození do nároku patří (§ 35c odst. 10,
                // {@see ChildCreditClaimWindow}).
            } else {
                $matchedDependantIds[(int) $dependant['id']] = true;
                if ($child['ztp_p'] && !(bool) $dependant['ztp_p']) {
                    $warnings[] = "Dítě {$label} je v hlášení držitelem průkazu ZTP/P, v evidenci ne. "
                        . 'Opravte kartu dítěte a import zopakujte; nárok se zatím nepřebírá.';
                    continue;
                }
                $grantedOn = $dependant['ztp_p_granted_on'] ?? null;
                if ($child['ztp_p'] && is_string($grantedOn)
                    && $monthStart < ChildCreditClaimWindow::ztpPEarliestFrom($grantedOn)
                ) {
                    $warnings[] = "Dítě {$label} má v hlášení za {$this->month($monthStart)} dvojnásobek ZTP/P, "
                        . "průkaz je ale v evidenci přiznán až {$grantedOn} a dvojnásobek náleží od "
                        . ChildCreditClaimWindow::ztpPEarliestFrom($grantedOn) . '. Ověřte den přiznání na kartě '
                        . 'dítěte; nárok se zatím nepřebírá.';
                    continue;
                }
                if (ChildCreditClaimWindow::earliestFrom(
                    (string) $dependant['birth_date'],
                    (string) $dependant['existence_from'],
                    $claimReason,
                ) > $monthStart) {
                    $warnings[] = "Dítě {$label} je vedené jako vyživované až od {$dependant['existence_from']}; "
                        . 'nárok za dřívější měsíc zapište ručně.';
                    continue;
                }
            }

            $desired = [
                'child_order' => $order,
                'credit_status' => $child['order'] === 'N' ? 'claimed_by_other' : 'claimed',
                'claim_reason' => $claimReason,
                'evidence_status' => 'verified',
                'evidence_reference' => $reference,
                'shared_household_confirmed' => true,
                'other_claimant_excluded' => $child['order'] !== 'N',
                'ztp_p' => $child['ztp_p'],
                'effective_from' => $monthStart,
                'effective_to' => null,
            ] + $caregiver;
            if ($covering !== null && $this->sameClaim($covering, $desired)) {
                continue;
            }
            if ($frozenThrough !== null && $monthStart <= $frozenThrough) {
                $warnings[] = "Nárok na dítě {$label} za {$this->month($monthStart)} nejde změnit — "
                    . 'měsíc je uzavřený schválenou mzdou.';
                continue;
            }
            $mode = 'create';
            if ($covering !== null) {
                $mode = (string) $covering['effective_from'] === $monthStart ? 'update' : 'split';
                $desired['effective_to'] = $covering['effective_to'];
            } else {
                $next = null;
                foreach ($claims as $claim) {
                    if ((string) $claim['effective_from'] > $monthStart
                        && ($next === null || (string) $claim['effective_from'] < (string) $next['effective_from'])
                    ) {
                        $next = $claim;
                    }
                }
                if ($next !== null) {
                    $desired['effective_to'] = JmhzEvidenceTimeline::previousDay((string) $next['effective_from']);
                }
            }
            try {
                $claimInput = $this->validator->validateClaim($desired);
            } catch (\InvalidArgumentException $e) {
                $warnings[] = "Nárok na dítě {$label} nejde převzít: {$e->getMessage()}";
                continue;
            }
            $actions[] = $this->action(
                $label,
                $dependant === null ? null : (int) $dependant['id'],
                $dependantInput,
                $claimInput,
                $mode,
                $covering,
                $covering === null ? null : $this->describe($covering),
            );
        }

        $ends = [];
        foreach ($dependants as $dependant) {
            if (isset($matchedDependantIds[(int) $dependant['id']])) {
                continue;
            }
            $covering = JmhzEvidenceTimeline::covering($this->liveClaims($dependant), $monthStart);
            if ($covering === null) {
                continue;
            }
            $label = (string) $dependant['full_name'];
            if (!JmhzReportPlanner::isImported($covering['evidence_reference'] ?? null)) {
                if ($childCredit !== null) {
                    $warnings[] = "Dítě {$label} v hlášení za {$this->month($monthStart)} není, evidence na ně "
                        . 'ale nárok vede. Import ručně zapsaný nárok neukončuje.';
                }
                continue;
            }
            if ((string) $covering['effective_from'] >= $monthStart
                || ($frozenThrough !== null && $monthStart <= $frozenThrough)
            ) {
                continue;
            }
            $ends[] = [
                'label' => $label,
                'dependant_id' => (int) $dependant['id'],
                'claim' => $covering,
            ];
        }

        return ['actions' => $actions, 'ends' => $ends, 'warnings' => array_values(array_unique($warnings))];
    }

    /**
     * Vstup validátoru nároku pro existující řádek s jiným koncem — pro
     * ukončení nároku posledním dnem předchozího měsíce.
     *
     * @param array<string,mixed> $claim
     * @return array<string,mixed>
     */
    public function closed(array $claim, string $effectiveTo): array
    {
        return $this->validator->validateClaim([
            'child_order' => (int) $claim['child_order'],
            'credit_status' => (string) $claim['credit_status'],
            'claim_reason' => $claim['claim_reason'] ?? null,
            'evidence_status' => (string) $claim['evidence_status'],
            'evidence_reference' => $claim['evidence_reference'] ?? null,
            'shared_household_confirmed' => (bool) $claim['shared_household_confirmed'],
            'other_claimant_excluded' => (bool) $claim['other_claimant_excluded'],
            'ztp_p' => (bool) $claim['ztp_p'],
            'effective_from' => (string) $claim['effective_from'],
            'effective_to' => $effectiveTo,
            'other_household_caregiver_status' => $claim['other_household_caregiver_status'] ?? null,
            'other_caregiver_given_name' => $claim['other_caregiver_given_name'] ?? null,
            'other_caregiver_family_name' => $claim['other_caregiver_family_name'] ?? null,
            'other_caregiver_birth_date' => $claim['other_caregiver_birth_date'] ?? null,
            'other_caregiver_birth_number' => $claim['other_caregiver_birth_number'] ?? null,
        ]);
    }

    /**
     * Pořadí v evidenci pro děti z hlášení (klíč = index dítěte v hlášení).
     *
     * @param list<array<string,mixed>> $children
     * @param list<string> $warnings
     * @return array<int,int>
     */
    private function orders(array $children, array &$warnings): array
    {
        $result = [];
        $used = [];
        $thirds = [];
        foreach ($children as $index => $child) {
            if ($child['order'] === '1' || $child['order'] === '2') {
                $order = (int) $child['order'];
                if (isset($used[$order])) {
                    $warnings[] = "Hlášení uvádí pořadí {$order} u dvou dětí; nároky na tato děti se nepřebírají.";
                    foreach ($result as $other => $assigned) {
                        if ($assigned === $order) {
                            unset($result[$other]);
                        }
                    }
                    continue;
                }
                $used[$order] = true;
                $result[$index] = $order;
            } elseif ($child['order'] === '3') {
                $thirds[] = $index;
            }
        }
        $byBirth = static fn (int $a, int $b): int => [(string) ($children[$a]['birth_date'] ?? ''), $a]
            <=> [(string) ($children[$b]['birth_date'] ?? ''), $b];
        usort($thirds, $byBirth);
        $order = 3;
        foreach ($thirds as $index) {
            while (isset($used[$order])) {
                $order++;
            }
            $used[$order] = true;
            $result[$index] = $order;
        }
        $others = array_keys(array_filter($children, static fn (array $child): bool => $child['order'] === 'N'));
        usort($others, $byBirth);
        foreach ($others as $index) {
            $order = 1;
            while (isset($used[$order])) {
                $order++;
            }
            $used[$order] = true;
            $result[$index] = $order;
        }
        ksort($result);

        return $result;
    }

    /**
     * Jiná osoba vyživující tytéž děti (10453, kontrola 127). `null` = údaje
     * nejdou převzít a nároky na děti se tentokrát nepřebírají.
     *
     * @param array<string,mixed>|null $childCredit
     * @param list<string> $warnings
     * @return array<string,?string>|null
     */
    private function caregiver(?array $childCredit, array &$warnings): ?array
    {
        $none = [
            'other_household_caregiver_status' => 'none',
            'other_caregiver_given_name' => null,
            'other_caregiver_family_name' => null,
            'other_caregiver_birth_date' => null,
            'other_caregiver_birth_number' => null,
        ];
        $flag = $childCredit['other_caregiver'] ?? null;
        if ($flag === false) {
            return $none;
        }
        if ($flag === null) {
            return ['other_household_caregiver_status' => 'unknown'] + $none;
        }
        $caregivers = is_array($childCredit['caregivers'] ?? null) ? $childCredit['caregivers'] : [];
        $first = $caregivers[0] ?? null;
        if ($first === null) {
            $warnings[] = 'Hlášení tvrdí, že děti vyživuje i jiná osoba, ale neuvádí ji; nároky na děti se nepřebírají.';

            return null;
        }
        if (count($caregivers) > 1) {
            $warnings[] = 'Hlášení uvádí víc jiných vyživujících osob; evidence drží jednu, převezme se první z nich.';
        }
        $birthDate = $first['birth_date'] ?? null;
        $birthNumber = null;
        if (is_string($first['birth_number'] ?? null)) {
            try {
                $birthNumber = CzechBirthNumber::normalize($first['birth_number']);
                $birthDate ??= CzechBirthNumber::birthDate($birthNumber);
            } catch (\InvalidArgumentException) {
                $birthNumber = null;
            }
        }
        // Rodné číslo (10434), které nesedí na datum narození z hlášení, se nepřebírá.
        if ($birthNumber !== null && $birthDate !== CzechBirthNumber::birthDate($birthNumber)) {
            $birthNumber = null;
        }
        if ($birthDate === null) {
            $warnings[] = 'Jiná vyživující osoba nemá v hlášení datum narození ani platné rodné číslo; '
                . 'nároky na děti se nepřebírají.';

            return null;
        }

        return [
            'other_household_caregiver_status' => 'present',
            'other_caregiver_given_name' => (string) $first['given_name'],
            'other_caregiver_family_name' => (string) $first['family_name'],
            'other_caregiver_birth_date' => $birthDate,
            'other_caregiver_birth_number' => $birthNumber,
        ];
    }

    /**
     * @param array<string,mixed> $child
     * @param list<string> $warnings
     * @return array{0:?string,1:?string} [rodné číslo, datum narození]
     */
    private function birth(array $child, string $label, array &$warnings): array
    {
        $birthNumber = null;
        if (is_string($child['birth_number'] ?? null)) {
            try {
                $birthNumber = CzechBirthNumber::normalize($child['birth_number']);
            } catch (\InvalidArgumentException) {
                $warnings[] = "Rodné číslo dítěte {$label} v hlášení není platné, nepřebírá se.";
            }
        }
        $birthDate = $child['birth_date'] ?? null;
        if ($birthNumber !== null) {
            $fromNumber = CzechBirthNumber::birthDate($birthNumber);
            if ($birthDate === null) {
                $birthDate = $fromNumber;
            } elseif ($fromNumber !== $birthDate) {
                $warnings[] = "Rodné číslo dítěte {$label} neodpovídá datu narození, nepřebírá se.";
                $birthNumber = null;
            }
        }
        if ($birthDate === null) {
            $warnings[] = "Dítě {$label} nemá v hlášení datum narození ani rodné číslo, nejde ho spárovat ani založit.";
        }

        return [$birthNumber, $birthDate];
    }

    /**
     * @param list<array<string,mixed>> $dependants
     * @param array<string,mixed> $child
     * @param list<string> $warnings
     * @return array<string,mixed>|false|null false = nejednoznačné, null = založit
     */
    private function dependant(
        int $supplierId,
        int $employeeId,
        array $dependants,
        array $child,
        ?string $birthNumber,
        string $birthDate,
        string $label,
        array &$warnings,
    ): array|false|null {
        $byId = [];
        foreach ($dependants as $dependant) {
            $byId[(int) $dependant['id']] = $dependant;
        }
        if ($birthNumber !== null) {
            $ids = $this->lookup->dependantIdsByBirthNumberHash(
                $supplierId,
                $employeeId,
                $this->sensitiveData->lookupHash($birthNumber, PayrollSensitiveField::PERSONAL_IDENTIFIER, $supplierId),
            );
            if (count($ids) === 1 && isset($byId[$ids[0]])) {
                return $byId[$ids[0]];
            }
        }
        $fold = static fn (mixed $value): string => mb_strtolower(trim((string) $value));
        $fullName = $fold($child['given_name'] . ' ' . $child['family_name']);
        $matches = array_values(array_filter(
            $dependants,
            static fn (array $dependant): bool => in_array($dependant['relation'], PayrollDependantValidator::CHILD_RELATIONS, true)
                && (string) $dependant['birth_date'] === $birthDate
                && (
                    ($fold($dependant['given_name'] ?? '') === $fold($child['given_name'])
                        && $fold($dependant['family_name'] ?? '') === $fold($child['family_name']))
                    || $fold($dependant['full_name'] ?? '') === $fullName
                ),
        ));
        if (count($matches) > 1) {
            $warnings[] = "Dítě {$label} sedí na víc vyživovaných osob v evidenci; nárok zapište ručně.";

            return false;
        }

        return $matches[0] ?? null;
    }

    /**
     * @param array<string,mixed> $dependant
     * @return list<array<string,mixed>>
     */
    private function liveClaims(array $dependant): array
    {
        return array_values(array_filter(
            is_array($dependant['claims'] ?? null) ? $dependant['claims'] : [],
            static fn (array $claim): bool => ($claim['superseded_by_id'] ?? null) === null,
        ));
    }

    /**
     * @param array<string,mixed> $claim
     * @param array<string,mixed> $desired
     */
    private function sameClaim(array $claim, array $desired): bool
    {
        foreach ([
            'child_order', 'credit_status', 'ztp_p', 'evidence_status', 'other_household_caregiver_status',
            'other_caregiver_given_name', 'other_caregiver_family_name', 'other_caregiver_birth_date',
        ] as $field) {
            $left = $claim[$field] ?? null;
            $right = $desired[$field] ?? null;
            if (is_bool($right) || is_int($right)) {
                if ((int) $left !== (int) $right) {
                    return false;
                }
            } elseif (($left === null ? null : (string) $left) !== $right) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string,mixed> $claim */
    private function describe(array $claim): string
    {
        return ($claim['credit_status'] === 'claimed_by_other' ? 'N' : 'pořadí ' . $claim['child_order'])
            . ((bool) $claim['ztp_p'] ? ', ZTP/P' : '')
            . ' od ' . $claim['effective_from'];
    }

    /**
     * @param array<string,mixed>|null $dependantInput
     * @param array<string,mixed>|null $claim
     * @param array<string,mixed>|null $covering
     * @return array<string,mixed>
     */
    private function action(
        string $label,
        ?int $dependantId,
        ?array $dependantInput,
        ?array $claim,
        string $mode,
        ?array $covering,
        ?string $current,
    ): array {
        $imported = null;
        if ($claim !== null) {
            $imported = ($claim['credit_status'] === 'claimed_by_other' ? 'N' : 'pořadí ' . $claim['child_order'])
                . ($claim['ztp_p'] ? ', ZTP/P' : '') . ' od ' . $claim['effective_from'];
        }

        return [
            'label' => $label,
            'dependant_id' => $dependantId,
            'dependant' => $dependantInput,
            'claim' => $claim,
            'mode' => $mode,
            'covering' => $covering,
            'current' => $current,
            'imported' => $imported ?? 'nové vyživované dítě',
        ];
    }

    private function month(string $monthStart): string
    {
        return substr($monthStart, 0, 7);
    }
}
