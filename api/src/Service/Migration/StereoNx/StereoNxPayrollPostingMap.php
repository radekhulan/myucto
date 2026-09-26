<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\StereoNx;

use MyInvoice\Service\Payroll\Migration\PayrollLegacyPostingRow;
use MyInvoice\Service\Payroll\Migration\PayrollLegacyPostingSource;

/**
 * Návrh předkontace jen z mzdových řádků deníku, jejichž význam potvrzují
 * zaměstnanecké kontace MPARUCT a řada převodu mezd MPARZPR.
 */
final class StereoNxPayrollPostingMap implements PayrollLegacyPostingSource
{
    public const SOURCE = 'stereo_nx';

    /** Text deníku => [pojmenovaná kontace MPARUCT, mzdový význam]. */
    private const JOURNAL_LABELS = [
        'Hrubá mzda (z)' => ['Hrubá mzda (z)', 'employment_gross'],
        'Zdravotní pojištění zaměstnance' => ['Zdravotní pojištění zaměstnance', 'employee_health'],
        'Zdravotní pojištění podnik (z)' => ['Zdravotní pojištění podnik (z)', 'employer_health'],
        'Sociální pojištění zaměstnance' => ['Sociální pojištění zaměstnance', 'employee_social'],
        'Sociální pojištění podnik (z)' => ['Sociální pojištění podnik (z)', 'employer_social'],
        'Záloha na daň (z)' => ['Záloha na daň (z)', 'advance_tax'],
        'Srážková daň (z)' => ['Srážková daň (z)', 'withholding_tax'],
        'Exekuce (srážky zaměstnanců)' => ['Srážky zaměstnanců', 'enforcement_deductions'],
        'Spoření (srážky zaměstnanců)' => ['Srážky zaměstnanců', 'other_deductions'],
    ];

    /** @param list<PayrollLegacyPostingRow> $rows */
    private function __construct(private readonly array $rows, public readonly ?int $year) {}

    public function sourceKey(): string
    {
        return self::SOURCE;
    }

    /** @return list<PayrollLegacyPostingRow> */
    public function postingRows(): array
    {
        return $this->rows;
    }

    /**
     * @param list<array<string,mixed>> $parameters MPARUCT
     * @param list<array<string,mixed>> $transfer MPARZPR
     * @param list<array<string,mixed>> $journal Cdenik
     */
    public static function fromTables(array $parameters, array $transfer, array $journal, ?int $year = null): self
    {
        $series = self::journalSeries($transfer);
        if ($series === null) return new self([], null);

        $templates = [];
        $ambiguous = [];
        foreach ($parameters as $row) {
            if (($row['TypPar'] ?? null) !== 1) continue;
            $label = trim((string) ($row['Text'] ?? ''));
            if (!in_array($label, array_column(self::JOURNAL_LABELS, 0), true)) continue;
            $debit = trim((string) ($row['UcetMD'] ?? ''));
            $credit = trim((string) ($row['UcetD'] ?? ''));
            if ($debit === '' || $credit === '') continue;
            $pair = [$debit, $credit];
            if (isset($templates[$label]) && $templates[$label] !== $pair) {
                $ambiguous[$label] = true;
            }
            $templates[$label] = $pair;
        }

        $entries = [];
        $years = [];
        foreach ($journal as $row) {
            if (trim((string) ($row['DoklRada'] ?? '')) !== $series) continue;
            $label = trim((string) ($row['Text'] ?? ''));
            $parameterLabel = self::JOURNAL_LABELS[$label][0] ?? null;
            if ($parameterLabel === null || !isset($templates[$parameterLabel]) || isset($ambiguous[$parameterLabel])) continue;
            $date = trim((string) ($row['KdyUcPripad'] ?? ''));
            if (preg_match('/^([0-9]{4})-(0[1-9]|1[0-2])-[0-9]{2}$/D', $date, $match) !== 1
                || !checkdate((int) $match[2], (int) substr($date, 8, 2), (int) $match[1])) continue;
            $debit = trim((string) ($row['UcetMD'] ?? ''));
            $credit = trim((string) ($row['UcetD'] ?? ''));
            if ($templates[$parameterLabel][0] !== $debit || $templates[$parameterLabel][1] !== $credit) continue;
            $amount = $row['Celkem'] ?? null;
            if ((!is_int($amount) && !is_float($amount)) || !is_finite((float) $amount)
                || abs((float) $amount) > 999999999999.99) continue;
            $postingYear = (int) $match[1];
            $entries[] = [$postingYear, $label, $debit, $credit, (int) round(abs((float) $amount) * 100)];
            $years[$postingYear] = true;
        }
        $year ??= $years === [] ? null : max(array_keys($years));
        if ($year === null) return new self([], null);

        $buckets = [];
        foreach ($entries as [$postingYear, $label, $debit, $credit, $amount]) {
            if ($postingYear !== $year) continue;
            $key = $label . "\0" . $debit . "\0" . $credit;
            $buckets[$key] ??= ['label' => $label, 'debit' => $debit, 'credit' => $credit, 'lines' => 0, 'amount' => 0];
            $buckets[$key]['lines']++;
            $buckets[$key]['amount'] += $amount;
        }
        ksort($buckets);
        $rows = [];
        foreach ($buckets as $bucket) {
            $rows[] = new PayrollLegacyPostingRow(
                self::SOURCE, 'MPARUCT/MPARZPR/Cdenik:' . $year,
                self::JOURNAL_LABELS[$bucket['label']][1], $bucket['label'],
                $bucket['debit'], $bucket['credit'], $bucket['debit'], $bucket['credit'],
                $bucket['lines'], $bucket['amount'],
            );
        }
        return new self($rows, $year);
    }

    /** @param list<array<string,mixed>> $rows */
    private static function journalSeries(array $rows): ?string
    {
        $series = [];
        foreach ($rows as $row) {
            $code = trim((string) ($row['DoklRadaU'] ?? ''));
            if ($code !== '') $series[$code] = true;
        }
        return count($series) === 1 ? (string) array_key_first($series) : null;
    }

}
