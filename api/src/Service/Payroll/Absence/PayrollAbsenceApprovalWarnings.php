<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Absence;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Varování po schválení nepřítomnosti (C-22).
 *
 * Nemoc schválená přes dny, na které je zapsaná odpracovaná doba, prošla bez
 * jediné věty: mzdový běh pak za tytéž dny počítal mzdu i náhradu. A schválená
 * nepřítomnost v měsíci se schváleným během se do výplaty nedostane, dokud
 * nevznikne opravná revize — ani o tom obrazovka nemluvila. Obojí se neblokuje
 * (docházku i běh opraví účetní), jen se to řekne se jmény dnů a proklikem.
 */
final readonly class PayrollAbsenceApprovalWarnings
{
    private const WORK_CATEGORIES = ['regular', 'overtime'];

    public function __construct(private Connection $db) {}

    /**
     * @param array<string,mixed> $absence schválená nepřítomnost
     * @return list<array{code:string,message:string,path:string}>
     */
    public function forApproved(int $supplierId, array $absence): array
    {
        $employmentId = (int) ($absence['employment_id'] ?? 0);
        $from = (string) ($absence['date_from'] ?? '');
        $to = (string) ($absence['date_to'] ?? $from);
        if ($employmentId <= 0 || $from === '') {
            return [];
        }
        $warnings = [];

        $manual = $this->db->pdo()->prepare(
            "SELECT period_start FROM payroll_time_months FORCE INDEX (uq_payroll_time_month_period)
              WHERE supplier_id = ? AND employment_id = ? AND period_start >= ? AND period_start <= ?
                AND work_source = 'manual_summary'",
        );
        $manual->execute([$supplierId, $employmentId, substr($from, 0, 7) . '-01', substr($to, 0, 7) . '-01']);
        $manualPeriods = array_fill_keys($manual->fetchAll(PDO::FETCH_COLUMN), true);
        if ($manualPeriods !== []) {
            $warnings[] = [
                'code' => 'absence_manual_work_time_unverifiable',
                'message' => 'Ruční měsíční souhrn neuvádí odpracované dny. Souběh nepřítomnosti a práce ověřte podle podkladů.',
                'path' => '/payroll/time?employment=' . $employmentId . '&period=' . substr($from, 0, 7),
            ];
        }

        $placeholders = implode(', ', array_fill(0, count(self::WORK_CATEGORIES), '?'));
        $worked = $this->db->pdo()->prepare(
            "SELECT DISTINCT DATE(entry.starts_at_utc) AS day
               FROM payroll_time_entries entry
              WHERE entry.supplier_id = ? AND entry.employment_id = ?
                AND entry.status IN ('draft', 'approved')
                AND entry.source_kind <> 'schedule'
                AND entry.category IN ({$placeholders})
                AND DATE(entry.starts_at_utc) BETWEEN ? AND ?
              ORDER BY day",
        );
        $worked->execute([$supplierId, $employmentId, ...self::WORK_CATEGORIES, $from, $to]);
        $days = array_map(
            static fn (string $day): string => (new \DateTimeImmutable($day))->format('j. n.'),
            array_values(array_filter($worked->fetchAll(PDO::FETCH_COLUMN), static fn (string $day): bool => !isset($manualPeriods[substr($day, 0, 7) . '-01']))),
        );
        if ($days !== []) {
            $warnings[] = [
                'code' => 'absence_overlaps_worked_time',
                'message' => sprintf(
                    'Na %s je v docházce zapsaná odpracovaná doba, přestože nepřítomnost je schválená. '
                    . 'Mzdový běh by za tytéž dny počítal mzdu i náhradu. Opravte docházku (Mzdy → Pracovní doba).',
                    implode(', ', $days),
                ),
                'path' => '/payroll/time?employment=' . $employmentId . '&period=' . substr($from, 0, 7),
            ];
        }

        // DPN-04: náhrada mzdy podle § 192 ZP je povinnost zaměstnavatele v trvajícím
        // vztahu. Okno se koncem vztahu ořízne samo, účetní to ale musí vědět, protože
        // za další dny už nic nevyplácí ani nehlásí.
        if (in_array($absence['absence_type'] ?? null, ['dpn', 'quarantine'], true)) {
            $end = $this->db->pdo()->prepare(
                'SELECT end_date, employee_id FROM payroll_employments WHERE supplier_id = ? AND id = ?',
            );
            $end->execute([$supplierId, $employmentId]);
            $employment = $end->fetch(PDO::FETCH_ASSOC);
            $endDate = is_array($employment) ? $employment['end_date'] : null;
            if (is_string($endDate) && $endDate < $to) {
                $warnings[] = [
                    'code' => 'sickness_beyond_employment_end',
                    'message' => sprintf(
                        'Neschopnost trvá i po skončení pracovního vztahu (%s). Náhrada mzdy se počítá '
                        . 'nejvýš do tohoto dne; za další dny ji zaměstnavatel neposkytuje.',
                        (new \DateTimeImmutable($endDate))->format('j. n. Y'),
                    ),
                    'path' => '/payroll/people/' . (int) $employment['employee_id'],
                ];
            }
        }

        $runs = $this->db->pdo()->prepare(
            "SELECT DISTINCT DATE_FORMAT(run.period_start, '%Y-%m') AS period
               FROM payroll_runs run
              WHERE run.supplier_id = ?
                AND run.status <> 'cancelled'
                AND run.period_start BETWEEN DATE_FORMAT(?, '%Y-%m-01') AND ?
                AND EXISTS (
                    SELECT 1 FROM payroll_run_revisions revision
                     WHERE revision.supplier_id = run.supplier_id AND revision.run_id = run.id
                       AND revision.status = 'approved'
                )
                AND NOT EXISTS (
                    SELECT 1 FROM payroll_run_revisions open_revision
                     WHERE open_revision.supplier_id = run.supplier_id AND open_revision.run_id = run.id
                       AND open_revision.status NOT IN ('approved', 'superseded', 'abandoned')
                )
              ORDER BY period",
        );
        $runs->execute([$supplierId, $from, $to]);
        foreach ($runs->fetchAll(PDO::FETCH_COLUMN) as $period) {
            [$year, $month] = explode('-', (string) $period);
            $warnings[] = [
                'code' => 'absence_in_approved_run',
                'message' => sprintf(
                    'Mzdový běh za %d/%s je už schválený. Nepřítomnost se do výplaty promítne až opravnou '
                    . 'revizí: v Mzdových bězích otevřete opravu, obnovte podklady a přepočítejte.',
                    (int) $month,
                    $year,
                ),
                'path' => '/payroll/runs?period=' . $period,
            ];
        }

        return $warnings;
    }
}
