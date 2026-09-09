<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Return;

final class DpfoEpoBusinessValidator
{
    /** @return list<string> */
    public function validate(array $result, array $podklady): array
    {
        $errors = [];
        $s7 = (array) ($result['s7'] ?? []);
        $activities = (array) ($s7['activities'] ?? []);
        if ((float) ($s7['income'] ?? 0) > 0 && $activities === []) {
            $errors[] = 'DPFO: chybí položkový seznam činností §7.';
        }
        $activityIncome = 0.0;
        $activityActualExpenses = 0.0;
        $hasActualActivity = false;
        foreach ($activities as $activity) {
            if (!is_array($activity)) {
                continue;
            }
            $nace = preg_replace('/\D/', '', (string) ($activity['nace_code'] ?? ''));
            if ($nace === '' || strlen($nace) > 6) {
                $errors[] = 'DPFO: každá činnost §7 musí mít platný CZ-NACE.';
            }
            $activityIncome += (float) ($activity['income'] ?? 0);
            if (($activity['expense_mode'] ?? 'pausal') === 'actual') {
                $hasActualActivity = true;
                $activityActualExpenses += (float) ($activity['expenses'] ?? 0);
            }
        }
        if ($activities !== [] && abs($activityIncome - (float) ($s7['income'] ?? 0)) > 0.01) {
            $errors[] = 'DPFO: součet příjmů činností §7 nesouhlasí s řádkem 101.';
        }
        if ($activities !== [] && abs($activityIncome - (float) ($podklady['s7_income'] ?? 0)) > 0.01) {
            $errors[] = 'DPFO: příjmy přiřazené činnostem §7 nesouhlasí s peněžním deníkem.';
        }
        if ($hasActualActivity && abs($activityActualExpenses - (float) ($podklady['s7_expenses'] ?? 0)) > 0.01) {
            $errors[] = 'DPFO: skutečné výdaje přiřazené činnostem §7 nesouhlasí s peněžním deníkem.';
        }

        // §10: každá položka musí nést kód druhu příjmu podle § 10 odst. 1 ZDP
        // (sloupec 1 tabulky 2. oddílu Přílohy č. 2, `kod_dr_prij10`). Bez něj úřad
        // podání vytkne — a slovní popis ho nenahradí, ten je samostatný údaj.
        // Číselník je {@see Section10Codebook}; kód `kod10` (P/S/Z/N) je opravdu
        // volitelný, ten se tady nevynucuje.
        $missingKindCode = false;
        $missingDescription = false;
        foreach ((array) ($result['s10_items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            if (!Section10Codebook::isKind(Section10Codebook::normalizeKind($item['kind_code'] ?? ''))) {
                $missingKindCode = true;
            }
            // Popis druhu příjmu — `text` je dnešní klíč, `kind` historický (rozeditované
            // koncepty a starší uložená přiznání).
            if (trim((string) ($item['text'] ?? $item['kind'] ?? '')) === '') {
                $missingDescription = true;
            }
            if ((float) ($item['allowed_expenses'] ?? 0) > (float) ($item['income'] ?? 0) + 0.01) {
                $errors[] = 'DPFO: výdaje §10 překročily příjmy stejného druhu.';
            }
        }
        if ($missingKindCode) {
            $errors[] = 'DPFO: každý příjem §10 musí mít vybraný kód druhu příjmu podle § 10 odst. 1 zákona (A–H).';
        }
        if ($missingDescription) {
            $errors[] = 'DPFO: každý příjem §10 musí mít uveden samostatný druh.';
        }

        $family = (array) ($result['family'] ?? []);
        foreach ((array) ($family['children'] ?? []) as $child) {
            if (!is_array($child)
                || trim((string) ($child['first_name'] ?? '')) === ''
                || trim((string) ($child['last_name'] ?? '')) === ''
                || (empty($child['birth_number']) && empty($child['birth_date']))
                || (array) ($child['months'] ?? []) === []) {
                $errors[] = 'DPFO: neúplné identifikační nebo měsíční údaje dítěte.';
                break;
            }
        }
        $spouse = $family['spouse'] ?? null;
        if (is_array($spouse) && (
            trim((string) ($spouse['first_name'] ?? '')) === ''
            || trim((string) ($spouse['last_name'] ?? '')) === ''
            || (empty($spouse['birth_number']) && empty($spouse['birth_date']))
            || empty($spouse['income_proved'])
            || empty($spouse['shared_household_proved'])
            || empty($spouse['child_under_three_proved'])
        )) {
            $errors[] = 'DPFO: nárok na manželskou slevu není úplně doložen.';
        }

        $closing = $s7['closing'] ?? null;
        if (($s7['accounting_mode'] ?? '') === 'tax_evidence') {
            if (!is_array($closing) || ($closing['status'] ?? '') !== 'final') {
                $errors[] = 'DPFO: chybí dokončená roční uzávěrka daňové evidence.';
            } elseif ((array) ($closing['unsupported_cases'] ?? []) !== []) {
                $errors[] = 'DPFO: uzávěrka obsahuje nepodporované situace vyžadující ruční zpracování.';
            }
        }

        $summary = (array) ($result['summary'] ?? []);
        if ((float) ($summary['child_bonus'] ?? 0) > 0
            && (float) ($summary['bonus_qualifying_income'] ?? 0) < (float) ($podklady['child_bonus_min_income'] ?? 0)) {
            $errors[] = 'DPFO: daňový bonus nesplňuje minimální příjmový test.';
        }
        return array_values(array_unique($errors));
    }
}
