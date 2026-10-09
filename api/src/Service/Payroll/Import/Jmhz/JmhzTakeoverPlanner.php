<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Import\Jmhz;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Import\Registration\RegistrationImportLookup;
use MyInvoice\Service\Payroll\Migration\PayrollMigrationReferenceTotals;
use MyInvoice\Service\Payroll\Migration\PayrollMigrationReferenceTotalsWriter;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverAbsenceWriter;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverEmploymentWriter;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverInvariants;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverRecord;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverRunState;

/**
 * Převzetí historie mezd z přijatých měsíčních hlášení — orchestrátor převodu
 * se zdrojem JMHZ nad společnou vrstvou převzatých mezd.
 *
 * Stejně jako převod z PAMICA a PREMIER: převodník ({@see JmhzPayrollTakeover})
 * složí kanonickou podobu a zapisují ji společné zapisovače —
 * převzaté měsíce {@see PayrollMigrationReferenceTotalsWriter} (zdroj `jmhz`),
 * sjednanou mzdu a její předpis, průměr, pracoviště a skončení vztahu
 * {@see PayrollTakeoverEmploymentWriter}, čerpání dovolené
 * {@see PayrollTakeoverAbsenceWriter}. Každý krok vztahu má vlastní savepoint:
 * údaj, který neprojde, ostatní nezastaví.
 *
 * Vztah se bere z párování formulářů ({@see JmhzReportPlanner}); plány se počítají
 * až po zápisu formulářů a vět registrací, takže vidí i vztahy, které tentýž
 * import právě založil.
 *
 * ── Kdy se měsíc nepřevezme ─────────────────────────────────────────────────
 *  - měsíc není před obdobím, od kterého mzdy počítá MyÚčto (ten počítá sám),
 *  - formulář není spárovaný s pracovním vztahem,
 *  - soubor neprošel XSD (měkký režim) — bloky s penězi čtení přejít smí,
 *  - měsíc vztahu už je převzatý z jiného zdroje (dvojí převzetí by se sečetlo).
 */
final class JmhzTakeoverPlanner
{
    public const STATUS_READY = 'ready';
    /** Měsíc se převezme, ale jen zčásti: některý formulář zůstal bez vztahu nebo zablokovaný. */
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_BLOCKED = 'blocked';
    public const STATUS_COMPUTED = 'computed';
    private const SAVEPOINT = 'jmhz_takeover_detail';

    public function __construct(
        private readonly Connection $db,
        private readonly JmhzReportLookup $jmhzLookup,
        private readonly RegistrationImportLookup $lookup,
        private readonly PayrollMigrationReferenceTotalsWriter $totalsWriter,
        private readonly PayrollTakeoverEmploymentWriter $employmentWriter,
        private readonly PayrollTakeoverAbsenceWriter $absenceWriter,
        private readonly PayrollTakeoverInvariants $invariants,
    ) {}

    /**
     * @param list<array<string,mixed>> $plans plány formulářů hlášení (včetně interních klíčů)
     * @return array{start_period:?string,months:list<array<string,mixed>>,relations:list<array<string,mixed>>}
     */
    public function preview(int $supplierId, array $plans, JmhzBatch $batch): array
    {
        $start = $this->startPeriod($supplierId);
        $months = [];
        foreach ($this->months($supplierId, $plans, $batch, $start) as $candidate) {
            $period = $candidate['period'];
            $months[$period] ??= [
                'period' => $period,
                'status' => self::STATUS_BLOCKED,
                'reason' => null,
                'ready_count' => 0,
                'gross_minor' => 0,
                'net_minor' => 0,
                'advance_tax_minor' => 0,
                'blocked' => [],
            ];
            if ($candidate['status'] === self::STATUS_COMPUTED) {
                $months[$period]['status'] = self::STATUS_COMPUTED;
                $months[$period]['reason'] = $candidate['reason'];
                continue;
            }
            if ($candidate['status'] !== self::STATUS_READY) {
                $months[$period]['blocked'][] = ['label' => $candidate['label'], 'reason' => $candidate['reason']];
                continue;
            }
            /** @var PayrollMigrationReferenceTotals $total */
            $total = $candidate['total'];
            $months[$period]['status'] = self::STATUS_READY;
            $months[$period]['ready_count']++;
            $months[$period]['gross_minor'] += $total->grossMinor;
            $months[$period]['net_minor'] += $total->netMinor;
            $months[$period]['advance_tax_minor'] += $total->advanceTaxMinor;
        }
        foreach ($months as $period => $month) {
            // Měsíc s převzatým úhrnem, ze kterého ale část formulářů nepřešla,
            // nesmí vypadat jako hotový: úhrn by za firmu tiše chyběl.
            if ($month['status'] === self::STATUS_READY && $month['blocked'] !== []) {
                $months[$period]['status'] = self::STATUS_PARTIAL;
                $months[$period]['reason'] = 'Převezme se jen část měsíce: ' . count($month['blocked'])
                    . ' ' . (count($month['blocked']) === 1 ? 'formulář zůstal' : 'formuláře/ů zůstalo')
                    . ' bez pracovního vztahu nebo zablokovaných. Doplňte je, jinak úhrn měsíce za firmu nebude úplný.';
            }
        }
        ksort($months, SORT_STRING);

        $relations = [];
        foreach ($this->relations($supplierId, $plans, $batch, $start) as $relation) {
            /** @var PayrollTakeoverRecord $record */
            $record = $relation['record'];
            $employment = $record->employment;
            $relations[] = [
                'employee_id' => $relation['employee_id'],
                'employment_id' => $relation['employment_id'],
                'label' => $relation['label'],
                'start_on' => $employment->start,
                'end_on' => $employment->end,
                'monthly_wage_minor' => $employment->monthlyWages === []
                    ? null
                    : (int) round($employment->monthlyWages[0]['amount'] * 100),
                'monthly_wage_from' => $employment->monthlyWages[0]['from'] ?? null,
                'average_quarters' => array_map(
                    static fn (array $average): string => $average['quarter'] . '/' . $average['year'],
                    $employment->averages,
                ),
                'leave_minutes' => array_sum(array_column($employment->leaveTaken, 'minutes')),
                'deductions_recorded' => in_array(JmhzPayrollTakeover::DEDUCTIONS_FOLLOW_UP, $employment->followUps, true),
                'sickness_review' => in_array(JmhzPayrollTakeover::SICKNESS_FOLLOW_UP, $employment->followUps, true),
            ];
        }

        return ['start_period' => $start, 'months' => array_values($months), 'relations' => $relations];
    }

    /**
     * @param list<array<string,mixed>> $plans
     * @return array{saved:int,periods:list<string>,relations:int,counts:array<string,int>,skipped:list<array{period:?string,label:string,reason:string}>}
     */
    public function apply(int $supplierId, array $plans, JmhzBatch $batch, ?int $userId): array
    {
        $start = $this->startPeriod($supplierId);
        $totals = [];
        $skipped = [];
        $periods = [];
        foreach ($this->months($supplierId, $plans, $batch, $start) as $candidate) {
            if ($candidate['status'] === self::STATUS_COMPUTED) {
                continue;
            }
            if ($candidate['status'] !== self::STATUS_READY) {
                $skipped[] = ['period' => $candidate['period'], 'label' => $candidate['label'], 'reason' => (string) $candidate['reason']];
                continue;
            }
            $totals[] = $candidate['total'];
            $periods[$candidate['period']] = true;
        }
        $saved = $this->totalsWriter->store($supplierId, PayrollMigrationReferenceTotalsWriter::SOURCE_JMHZ, $totals, 'jmhz-import');

        $counts = [];
        $relations = $this->relations($supplierId, $plans, $batch, $start);
        $policy = JmhzPayrollTakeover::policy();
        foreach ($relations as $relation) {
            /** @var PayrollTakeoverRecord $record */
            $record = $relation['record'];
            $employment = $record->employment;
            $employmentId = (int) $relation['employment_id'];
            $label = (string) $relation['label'];
            $steps = [
                'Sjednaná mzda' => fn (): array => $this->employmentWriter->monthlyWage($supplierId, $employmentId, $employment, $userId, $policy),
                'Předpis měsíční mzdy' => fn (): array => $this->employmentWriter->recurringWage(
                    $supplierId, $employmentId, $employment, $userId, $policy, new PayrollTakeoverRunState(),
                ),
                'Průměrný výdělek' => fn (): array => $this->employmentWriter->averageEarnings($supplierId, $employmentId, $employment, $userId, $policy),
                'Pracoviště JMHZ' => fn (): array => $this->employmentWriter->workplace($supplierId, $employmentId, $employment, $userId, $policy),
                'Čerpání dovolené' => fn (): array => $this->absenceWriter->leaveTaken($supplierId, $employmentId, $employment, $userId, $policy),
                'Skončení vztahu' => fn (): array => $this->employmentWriter->termination(
                    $supplierId, $employmentId, $employment, date('Y-m-d'), null, $userId, $policy,
                ),
                'Úkoly na vztahu' => fn (): array => $this->employmentWriter->followUps(
                    $supplierId, $employmentId, $employment, (string) $start, $policy,
                ),
            ];
            foreach ($steps as $step => $work) {
                try {
                    foreach ($this->inSavepoint($work) as $key => $count) {
                        $counts[$key] = ($counts[$key] ?? 0) + $count;
                    }
                } catch (\InvalidArgumentException|\DomainException|\RuntimeException $e) {
                    $skipped[] = ['period' => null, 'label' => $label, 'reason' => "{$step}: {$e->getMessage()}"];
                }
            }
        }
        ksort($periods, SORT_STRING);
        // Brána G2 jako u převodů z jiných programů: porušení se ukáže v náhledu výsledku
        // i na Kontrole převzetí, nikdy jen tiše v datech.
        if ($totals !== []) {
            foreach ($this->invariants->verify($supplierId, PayrollMigrationReferenceTotalsWriter::SOURCE_JMHZ, $totals) as $violation) {
                $counts['invariant_violations'] = ($counts['invariant_violations'] ?? 0) + 1;
                $skipped[] = ['period' => $violation['context']['period'] ?? null, 'label' => 'Kontrola převzetí', 'reason' => $violation['text']];
            }
        }
        ksort($counts, SORT_STRING);

        return [
            'saved' => $saved,
            'periods' => array_keys($periods),
            'relations' => count($relations),
            'counts' => $counts,
            'skipped' => $skipped,
        ];
    }

    private function startPeriod(int $supplierId): ?string
    {
        $start = $this->jmhzLookup->moduleStartPeriod($supplierId);

        return $start === null ? null : substr($start, 0, 7);
    }

    /**
     * Převzaté měsíce po formulářích.
     *
     * @param list<array<string,mixed>> $plans
     * @return list<array{period:string,label:string,status:string,reason:?string,total:?PayrollMigrationReferenceTotals}>
     */
    private function months(int $supplierId, array $plans, JmhzBatch $batch, ?string $start): array
    {
        $byPerson = [];
        foreach ($plans as $plan) {
            if (($plan['_effective'] ?? false) === true && $plan['_employee_id'] !== null && $plan['blocker'] === null) {
                $byPerson[$plan['period'] . '|' . $plan['_employee_id']][] = $plan['_item'];
            }
        }
        $sources = [];
        $terms = [];
        $candidates = [];
        foreach ($plans as $plan) {
            if (($plan['_effective'] ?? false) !== true) {
                continue;
            }
            /** @var JmhzBatchItem $item */
            $item = $plan['_item'];
            $period = $item->period();
            $candidate = [
                'period' => $period,
                'label' => self::label($plan),
                'status' => self::STATUS_BLOCKED,
                'reason' => null,
                'total' => null,
            ];
            $employmentId = $plan['_employment_id'];
            $employeeId = $plan['_employee_id'];
            $reason = match (true) {
                $start === null => 'Firma nemá nastavené období, od kterého vede mzdy v MyÚčtu. Bez něj nejde říct, '
                    . 'které měsíce jsou převzaté.',
                $period >= $start => null,
                $plan['blocker'] !== null || $employmentId === null || $employeeId === null
                    => 'Formulář není spárovaný s pracovním vztahem v evidenci.',
                $item->file->lenient => 'Soubor neprošel kontrolou schématu (jiná verze datové věty), částky z něj '
                    . 'nejde převzít s jistotou.',
                default => null,
            };
            if ($start !== null && $period >= $start) {
                $candidates[] = [
                    'status' => self::STATUS_COMPUTED,
                    'reason' => "Měsíce od {$start} počítá MyÚčto, převzaté mzdy se za ně nezapisují.",
                ] + $candidate;
                continue;
            }
            if ($reason !== null) {
                $candidates[] = ['reason' => $reason] + $candidate;
                continue;
            }
            $sources[$employmentId] ??= $this->jmhzLookup->takeoverSources($supplierId, (int) $employmentId);
            $other = array_values(array_diff($sources[$employmentId][$period] ?? [], [PayrollMigrationReferenceTotalsWriter::SOURCE_JMHZ]));
            if ($other !== []) {
                $candidates[] = ['reason' => "Měsíc je u vztahu už převzatý ze zdroje „{$other[0]}“; převzetí "
                    . 'z hlášení by se k němu přičetlo.'] + $candidate;
                continue;
            }
            $row = $this->lookup->employment($supplierId, (int) $employmentId);
            if ($row === null) {
                $candidates[] = ['reason' => 'Pracovní vztah v téhle firmě neexistuje.'] + $candidate;
                continue;
            }
            $key = $item->form->relationKey();
            try {
                $candidate['total'] = JmhzPayrollTakeover::totals(
                    $item,
                    $byPerson[$period . '|' . $employeeId] ?? [$item],
                    (int) $employeeId,
                    $row,
                    ($key === null ? null : $batch->history()->activityCode($key))
                        ?? $this->termsActivityCode($supplierId, (int) $employmentId, $item->file->periodStart(), $terms),
                );
            } catch (\InvalidArgumentException $e) {
                $candidates[] = ['reason' => $e->getMessage()] + $candidate;
                continue;
            }
            $candidate['status'] = self::STATUS_READY;
            $candidates[] = $candidate;
        }

        return $candidates;
    }

    /**
     * Vztahy, které dávka dokládá, s kanonickou podobou převzatých mezd.
     *
     * @param list<array<string,mixed>> $plans
     * @return list<array{employee_id:int,employment_id:int,label:string,record:PayrollTakeoverRecord}>
     */
    private function relations(int $supplierId, array $plans, JmhzBatch $batch, ?string $start): array
    {
        if ($start === null) {
            return [];
        }
        $history = $batch->history();
        $byKey = [];
        foreach ($plans as $plan) {
            /** @var JmhzBatchItem $item */
            $item = $plan['_item'];
            $key = $item->form->relationKey();
            if (($plan['_effective'] ?? false) !== true || $plan['blocker'] !== null || $plan['_employment_id'] === null
                || $key === null || $item->period() >= $start
            ) {
                continue;
            }
            $byKey[$key] = $plan;
        }
        ksort($byKey, SORT_STRING);
        $relations = [];
        foreach ($byKey as $key => $plan) {
            $row = $this->lookup->employment($supplierId, (int) $plan['_employment_id']);
            if ($row === null) {
                continue;
            }
            $relations[] = [
                'employee_id' => (int) $plan['_employee_id'],
                'employment_id' => (int) $plan['_employment_id'],
                'label' => self::label($plan),
                'record' => JmhzPayrollTakeover::record($history, (string) $key, (int) $plan['_employee_id'], $row, $start),
            ];
        }

        return $relations;
    }

    /**
     * Druh činnosti ze sjednaných podmínek vztahu k měsíci, když ho hlášení
     * nenese (vztah bez ELDP a bez 10239, typicky pracující důchodce). Bez něj
     * by převzatý měsíc neměl kód sekce evidenčního listu.
     *
     * @param array<int,list<array<string,mixed>>> $cache
     */
    private function termsActivityCode(int $supplierId, int $employmentId, string $monthStart, array &$cache): ?string
    {
        $cache[$employmentId] ??= $this->jmhzLookup->termVersions($supplierId, $employmentId);
        $covering = JmhzEvidenceTimeline::covering($cache[$employmentId], $monthStart) ?? ($cache[$employmentId][0] ?? null);
        $code = is_array($covering) ? trim((string) ($covering['activity_code'] ?? '')) : '';

        return $code === '' ? null : $code;
    }

    /** @param array<string,mixed> $plan */
    private static function label(array $plan): string
    {
        $name = (string) ($plan['person']['full_name'] ?? 'Neuvedené jméno');
        $code = $plan['match']['employment_code'] ?? null;

        return is_string($code) && $code !== '' ? $name . ' · ' . $code : $name;
    }

    /**
     * @param callable():array<string,int> $work
     * @return array<string,int>
     */
    private function inSavepoint(callable $work): array
    {
        $pdo = $this->db->pdo();
        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT ' . self::SAVEPOINT);
        }
        try {
            $result = $work();
            $own ? $pdo->commit() : $pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);

            return $result;
        } catch (\Throwable $e) {
            if ($own) {
                $pdo->rollBack();
            } else {
                $pdo->exec('ROLLBACK TO SAVEPOINT ' . self::SAVEPOINT);
                $pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);
            }
            throw $e;
        }
    }
}
