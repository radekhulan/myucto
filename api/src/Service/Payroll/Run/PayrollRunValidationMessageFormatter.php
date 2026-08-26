<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Run;

final class PayrollRunValidationMessageFormatter
{
    private const MAX_MESSAGE_LENGTH = 500;
    private const NET_PAY_ISSUE = 'income:net_pay_result_missing_or_unverified';

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
        $parts[] = 'Opravte uvedené podklady a otevřete novou revizi mzdového běhu.';

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
