<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

use PDO;

/**
 * Za které měsíce už vztah nese zmrazené nebo podané hlášení JMHZ.
 *
 * Hlášení = ostrá zmrazená příprava JMHZ nebo podání měsíční agendy JMHZ,
 * které odešlo úřadu, z kterékoli revize běhu, ve které vztah byl — i když je
 * běh mezitím otevřený k opravě. Pravidlo dřív žilo jen uvnitř hromadného
 * pravděpodobného výdělku. Opravy údajů pro JMHZ u ukončeného vztahu (karta,
 * hromadné doplnění pracoviště, hromadná úprava podmínek) se ptají na totéž,
 * proto je tady jednou a volatelné.
 */
final class JmhzReportedEmploymentPeriods
{
    /** Stavy podání, ve kterých hlášení ještě neodešlo úřadu. */
    public const NOT_SUBMITTED_STATUSES = ['draft', 'validated', 'prepared', 'ready', 'cancelled_in_time'];

    /**
     * Podmínka „revize `$revisionAlias` už je vykázaná v JMHZ" do SQL.
     * Parametry v pořadí otazníků vrací {@see self::revisionReportedParams()}.
     */
    public static function revisionReportedSql(string $revisionAlias): string
    {
        return '(
            EXISTS (
                SELECT 1
                  FROM payroll_jmhz_preparation_snapshots preparation
                 WHERE preparation.supplier_id = ' . $revisionAlias . '.supplier_id
                   AND preparation.run_id = ' . $revisionAlias . '.run_id
                   AND preparation.source_revision_id = ' . $revisionAlias . '.id
                   AND preparation.environment = "production"
            )
            OR EXISTS (
                SELECT 1
                  FROM payroll_submissions submission
                  JOIN payroll_obligations obligation
                    ON obligation.supplier_id = submission.supplier_id
                   AND obligation.environment = submission.environment
                   AND obligation.id = submission.obligation_id
                 WHERE submission.supplier_id = ' . $revisionAlias . '.supplier_id
                   AND submission.source_revision_id = ' . $revisionAlias . '.id
                   AND submission.environment = "production"
                   AND submission.status NOT IN ('
            . implode(', ', array_fill(0, count(self::NOT_SUBMITTED_STATUSES), '?')) . ')
                   AND obligation.agenda_code IN ('
            . implode(', ', array_fill(0, count(self::agendas()), '?')) . ')
            )
        )';
    }

    /** @return list<string> */
    public static function revisionReportedParams(): array
    {
        return [...self::NOT_SUBMITTED_STATUSES, ...self::agendas()];
    }

    /**
     * Vykázané měsíce (první den měsíce, vzestupně) po vztazích, jedním dotazem.
     *
     * @param list<int> $employmentIds
     * @return array<int,list<string>>
     */
    public static function forEmployments(PDO $pdo, int $supplierId, array $employmentIds): array
    {
        if ($employmentIds === []) {
            return [];
        }
        $in = implode(', ', array_fill(0, count($employmentIds), '?'));
        $stmt = $pdo->prepare(
            'SELECT DISTINCT run_employment.employment_id, run.period_start
               FROM payroll_run_employments run_employment
               JOIN payroll_run_revisions revision
                 ON revision.supplier_id = run_employment.supplier_id
                AND revision.id = run_employment.revision_id
               JOIN payroll_runs run
                 ON run.supplier_id = revision.supplier_id
                AND run.id = revision.run_id
              WHERE run_employment.supplier_id = ?
                AND run_employment.employment_id IN (' . $in . ')
                AND ' . self::revisionReportedSql('revision') . '
              ORDER BY run_employment.employment_id, run.period_start',
        );
        $stmt->execute([$supplierId, ...$employmentIds, ...self::revisionReportedParams()]);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(int) $row['employment_id']][] = substr((string) $row['period_start'], 0, 7) . '-01';
        }

        return $result;
    }

    /**
     * První vykázaný měsíc v rozsahu dnů `[$from, $to]` (`$to` null = bez konce).
     *
     * @param list<string> $periods výstup {@see self::forEmployments()} pro jeden vztah
     */
    public static function firstWithin(array $periods, string $from, ?string $to): ?string
    {
        $fromMonth = substr($from, 0, 7) . '-01';
        foreach ($periods as $period) {
            if ($period >= $fromMonth && ($to === null || $period <= $to)) {
                return $period;
            }
        }

        return null;
    }

    /** Měsíc pro věty („2/2026"). */
    public static function label(string $period): string
    {
        return (int) substr($period, 5, 2) . '/' . substr($period, 0, 4);
    }

    /** @return list<string> */
    private static function agendas(): array
    {
        return ['JMHZ', ...JmhzSpecVersion::MONTHLY_AGENDA_CODES];
    }
}
