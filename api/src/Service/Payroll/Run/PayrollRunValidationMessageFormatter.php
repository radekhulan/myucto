<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Run;

final class PayrollRunValidationMessageFormatter
{
    private const MAX_MESSAGE_LENGTH = 500;
    public const NET_PAY_ISSUE = 'income:net_pay_result_missing_or_unverified';

    /**
     * `$draftInputEmployments` jsou vztahy, u kterých revize už hlásí
     * neschválené vstupy (`draft_inputs_present`). Chybějící schválená složka je
     * u nich jen ozvěnou téhož problému: složka chybí právě proto, že vstup
     * čeká na schválení. Řádek se proto vynechá jen pro tuhle dvojici, ostatní
     * důvody zákonného posouzení zůstávají. Vynechané důvody nenahrazuje obecná
     * hláška „výpočet není úplný", protože vztah dál blokuje `draft_inputs_present`.
     *
     * @param array<int,true> $draftInputEmployments
     */
    public static function statutoryDetails(array $statutory, array $draftInputEmployments = []): array
    {
        $details = [];
        $covered = 0;
        self::collectDetails($statutory, $details, null, null, $draftInputEmployments, $covered);
        if ($details === [] && $covered > 0) {
            return [];
        }
        return array_values($details === []
            ? [PayrollRunIssueGuidance::describe('statutory_input_incomplete')]
            : $details);
    }

    public static function enforcementDetails(array $issues, int $employeeId): array
    {
        $details = [];
        foreach ($issues as $issue) {
            if (is_string($issue) && $issue !== '') {
                $detail = PayrollRunIssueGuidance::describe($issue, $employeeId);
                // Klíčem je to, co účetní uvidí: dvě příčiny téže věci (chybějící
                // sada exekučních srážek) by jinak daly u osoby dva stejné řádky.
                $key = $detail['entity_type'] . ':' . $detail['entity_id'] . ':' . $detail['remediation_path'] . ':' . $detail['message'];
                $details[$key] = $detail;
            }
        }
        return array_values($details === []
            ? [PayrollRunIssueGuidance::describe('enforcement_input_incomplete', $employeeId)]
            : $details);
    }

    private static function collectDetails(
        array $node,
        array &$details,
        ?int $employeeId = null,
        ?int $employmentId = null,
        array $draftInputEmployments = [],
        int &$covered = 0,
    ): void {
        foreach (['person_reference', 'person_id', 'employee_reference'] as $field) {
            if (is_string($node[$field] ?? null)) {
                $employeeId = PayrollRunIssueGuidance::reference($node[$field], 'employee') ?? $employeeId;
            }
        }
        foreach (['relationship_reference', 'relationship_id'] as $field) {
            if (is_string($node[$field] ?? null)) {
                $employmentId = PayrollRunIssueGuidance::reference($node[$field], 'employment') ?? $employmentId;
            }
        }
        if (is_int($node['employee_id'] ?? null) && $node['employee_id'] > 0) $employeeId = $node['employee_id'];
        if (is_int($node['employment_id'] ?? null) && $node['employment_id'] > 0) $employmentId = $node['employment_id'];
        foreach (is_array($node['issues'] ?? null) ? $node['issues'] : [] as $issue) {
            if (is_string($issue) && $issue !== '') {
                $issueEmploymentId = PayrollRunIssueGuidance::reference($issue, 'employment') ?? $employmentId;
                if ($issueEmploymentId !== null
                    && isset($draftInputEmployments[$issueEmploymentId])
                    && in_array('payroll_component_missing', explode(':', str_replace('-', '_', $issue)), true)
                ) {
                    ++$covered;
                    continue;
                }
                $detail = PayrollRunIssueGuidance::describe($issue, $employeeId, $employmentId);
                // Klíčem je to, co se uloží a co účetní uvidí, ne kód issue.
                // Assembler hlásí tentýž problém v každé ze tří domén
                // (`social_insurance:employment_term_missing:…`,
                // `health_insurance:…`, `income_tax:…`) a z nich vznikaly tři
                // řádky, které se lišily jen neuloženým prefixem domény.
                $key = $detail['entity_type'] . ':' . $detail['entity_id'] . ':' . $detail['remediation_path'] . ':' . $detail['message'];
                $details[$key] = $detail;
            }
        }
        foreach ($node as $field => $value) {
            if ($field !== 'issues' && is_array($value)) {
                self::collectDetails($value, $details, $employeeId, $employmentId, $draftInputEmployments, $covered);
            }
        }
    }

    /** @param list<string> $issues */
    public static function enforcement(array $issues): string
    {
        $netPayMissing = in_array(self::NET_PAY_ISSUE, $issues, true);
        $otherIssues = array_filter(
            $issues,
            static fn (string $issue): bool => $issue !== self::NET_PAY_ISSUE,
        );
        $parts = [];
        if ($netPayMissing) {
            $parts[] = 'Exekuční srážku zatím nelze spočítat, protože chybí vypočtená'
                . ' nebo ověřená čistá mzda. Nejprve dokončete zákonný výpočet; srážka se'
                . ' potom přepočítá automaticky.';
        }
        if ($otherIssues !== [] || !$netPayMissing) {
            $parts[] = ($netPayMissing ? 'Současně v agendě Exekuce' : 'V agendě Exekuce')
                . ' doplňte nebo ověřte další podklady: příjmy, vyživované osoby,'
                . ' pořadí pohledávek a případnou insolvenci.';
        }

        return self::message($parts);
    }

    /** @param list<string> $issues */
    public static function statutory(array $issues): string
    {
        $componentEmployments = [];
        $accumulatorEmployees = [];
        $declarationEmployments = [];
        $otherWithholdingEmployments = [];
        $known = 0;

        foreach ($issues as $issue) {
            if (str_contains($issue, ':payroll_component_missing:')) {
                self::collectIdentifier($componentEmployments, $issue, 'employment');
                $known++;
                continue;
            }
            if (str_contains($issue, ':annual_accumulator_missing:')) {
                self::collectIdentifier($accumulatorEmployees, $issue, 'employee');
                $known++;
                continue;
            }
            if (str_contains($issue, ':tax_declaration_term_conflict:')) {
                self::collectIdentifier($declarationEmployments, $issue, 'employment');
                $known++;
                continue;
            }
            if (str_contains($issue, ':other-withholding-eligibility-unverified:')) {
                self::collectIdentifier($otherWithholdingEmployments, $issue, 'employment');
                $known++;
            }
        }

        $parts = [
            'Zákonný výpočet pojistného, daně nebo čisté mzdy nebyl dokončen.',
        ];
        if ($componentEmployments !== []) {
            $parts[] = sprintf(
                'Schválená mzdová složka chybí u %s.',
                self::countLabel(count($componentEmployments), 'pracovního vztahu', 'pracovních vztahů'),
            );
        }
        if ($accumulatorEmployees !== []) {
            $parts[] = sprintf(
                'Počáteční nebo průběžné roční součty chybí u %s.',
                self::countLabel(count($accumulatorEmployees), 'zaměstnance', 'zaměstnanců'),
            );
        }
        if ($declarationEmployments !== []) {
            $parts[] = sprintf(
                'Evidence daňového prohlášení neodpovídá nastavení u %s.',
                self::countLabel(count($declarationEmployments), 'pracovního vztahu', 'pracovních vztahů'),
            );
        }
        if ($otherWithholdingEmployments !== []) {
            $parts[] = sprintf(
                'U %s potvrďte účast na nemocenském pojištění z odměny.',
                self::countLabel(count($otherWithholdingEmployments), 'pracovního vztahu', 'pracovních vztahů'),
            );
        }

        $unknown = max(0, count($issues) - $known);
        if ($unknown > 0) {
            $parts[] = $unknown === 1
                ? 'Další kontrola vyžaduje doplnění zákonných údajů.'
                : sprintf('Dalších %d kontrol vyžaduje doplnění zákonných údajů.', $unknown);
        }
        $parts[] = 'Opravte uvedené podklady a v mzdovém běhu klikněte na Obnovit podklady.';

        return self::message($parts);
    }

    /** @param array<string,true> $target */
    private static function collectIdentifier(
        array &$target,
        string $issue,
        string $field,
    ): void {
        if (preg_match('/(?:^|:)' . preg_quote($field, '/') . ':(\d+)(?:$|:)/', $issue, $match) === 1) {
            $target[$match[1]] = true;
        }
    }

    private static function countLabel(int $count, string $singular, string $plural): string
    {
        return $count . ' ' . ($count === 1 ? $singular : $plural);
    }

    /** @param list<string> $parts */
    private static function message(array $parts): string
    {
        $message = implode(' ', $parts);
        if (mb_strlen($message) > self::MAX_MESSAGE_LENGTH) {
            throw new \LogicException('Uživatelská zpráva validace překročila databázový limit.');
        }

        return $message;
    }
}
