<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Migration;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\Payroll\PayrollAbsenceRepository;
use MyInvoice\Repository\Payroll\PayrollAverageEarningRepository;
use MyInvoice\Repository\Payroll\PayrollSicknessRepository;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;
use MyInvoice\Service\Payroll\Absence\PayrollSicknessInputMaterializer;
use MyInvoice\Service\Payroll\Absence\PayrollWageProrationService;
use MyInvoice\Service\Payroll\Absence\SicknessCompensationCalculator;
use MyInvoice\Service\Payroll\Absence\SicknessCompensationResult;
use PDO;

/**
 * Náhrada mzdy při DPN u převzatých nepřítomností, které zasahují do měsíců počítaných
 * MyÚčtem.
 *
 * Převod zakládá dočasnou pracovní neschopnost z dat předchozího programu rovnou
 * schválenou, ale bez výpočtu náhrady: ten vzniká jen schválením v Nepřítomnostech,
 * a to měří dobu publikovanými směnami, které měsíc ze souhrnu importu nemá. Bez
 * výpočtu běh náhradu nevyplatí a krácení měsíční mzdy skončí v ruční kontrole
 * (`sickness_calculation_missing`), protože nezná okno § 192 ZP.
 *
 * Výpočet se tu dělá toutéž mechanikou jako při schválení ({@see SicknessCompensationCalculator},
 * {@see PayrollSicknessRepository::record()}, {@see PayrollSicknessInputMaterializer}); dobu
 * měří segmenty, kterými měří nemoc i krácení mzdy
 * ({@see PayrollWageProrationService::sicknessCompensationSegments()}). Náhrada vzniká jen za
 * dny od začátku vedení mezd: dřívější dny zaplatil předchozí program. Za první den nemoci
 * se bere, že odpracovaný nebyl (okno začíná dnem vzniku DPN), a účast na pojištění i
 * vyloučení souběžné dávky dokládá to, že předchozí program za tutéž neschopnost náhradu
 * vedl. Nepřítomnost bez schváleného průměru se nepočítá a protokol ji vypíše.
 */
final class PayrollTakeoverSicknessCompensation
{
    private const SAVEPOINT = 'payroll_takeover_sickness_compensation';

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollAverageEarningRepository $averages,
        private readonly PayrollWageProrationService $proration,
        private readonly SicknessCompensationCalculator $calculator,
        private readonly PayrollSicknessRepository $sickness,
        private readonly PayrollSicknessInputMaterializer $inputs,
        private readonly PayrollAbsenceRepository $absences,
    ) {}

    /**
     * @param string $startPeriod začátek vedení mezd (`YYYY-MM` nebo `YYYY-MM-DD`)
     */
    public function compensate(int $supplierId, string $startPeriod, ?int $userId, PayrollTakeoverPolicy $policy, ImportProtocol $protocol, string $step): void
    {
        $label = $policy->label;
        // Jen nepřítomnosti, které převod sám založil (poznámka „Převzato z {zdroj}:").
        $notePrefix = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $policy->note(''));
        $start = substr($startPeriod, 0, 7) . '-01';
        $stmt = $this->db->pdo()->prepare(
            "SELECT absence.*, employment.employee_id
               FROM payroll_absences absence
               JOIN payroll_employments employment
                 ON employment.supplier_id = absence.supplier_id AND employment.id = absence.employment_id
              WHERE absence.supplier_id = ? AND absence.status = 'approved'
                AND absence.absence_type IN ('dpn', 'quarantine')
                AND absence.date_to >= ? AND absence.note LIKE ?
                AND NOT EXISTS (
                    SELECT 1 FROM payroll_sickness_events event
                     WHERE event.supplier_id = absence.supplier_id AND event.absence_id = absence.id
                )
              ORDER BY absence.date_from, absence.id"
        );
        $stmt->execute([$supplierId, $start, $notePrefix . '%']);
        $computed = 0;
        $withoutAverage = [];
        $failed = [];
        $amount = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $absence) {
            $from = (string) $absence['date_from'];
            $quarter = intdiv((int) substr($from, 5, 2) - 1, 3) + 1;
            $average = $this->averages->findApproved($supplierId, (int) $absence['employment_id'], (int) substr($from, 0, 4), $quarter);
            if ($average === null || ($average['support_status'] ?? null) !== 'supported'
                || !is_int($average['average_hourly_minor'] ?? null) || $average['average_hourly_minor'] <= 0
            ) {
                $withoutAverage[] = "{$from} ({$quarter}. čtvrtletí, vztah {$absence['employment_id']})";
                continue;
            }
            $absence['average_snapshot_id'] = (int) $average['id'];
            try {
                $amount += $this->transactional(fn (): int => $this->one($absence, (int) $average['average_hourly_minor'], $start, $userId));
                $computed++;
            } catch (\InvalidArgumentException|\DomainException $e) {
                $failed[] = "{$from}: " . $e->getMessage();
            }
        }
        if ($computed > 0) {
            $protocol->count($step, 'sickness_compensations', $computed);
            $protocol->info($step, 'sickness_compensations', sprintf(
                'Náhrada mzdy při DPN spočítána u %d převzatých nepřítomností, za dny od %s celkem %s Kč. '
                . 'Doba se měří rozvrhem pracovního kalendáře, první den nemoci se bere jako neodpracovaný; '
                . 'jiný výklad opravte v Mzdy → Nepřítomnosti.',
                $computed,
                $start,
                number_format($amount / 100, 2, ',', ' '),
            ));
        }
        if ($withoutAverage !== []) {
            $protocol->count($step, 'sickness_compensations_without_average', count($withoutAverage));
            $protocol->warn($step, 'sickness_compensations_without_average', sprintf(
                'Náhradu mzdy při DPN nejde spočítat u %d nepřítomností z %s: chybí schválený průměrný výdělek '
                . '(%s). Doplňte průměr v Mzdy → Průměry a převod spusťte znovu, nebo nepřítomnost schvalte '
                . 'v Mzdy → Nepřítomnosti.',
                count($withoutAverage),
                $label,
                implode(', ', array_slice($withoutAverage, 0, 10)) . (count($withoutAverage) > 10 ? ', …' : ''),
            ));
        }
        if ($failed !== []) {
            $protocol->count($step, 'sickness_compensations_failed', count($failed));
            $protocol->warn($step, 'sickness_compensations_failed', sprintf(
                'Náhradu mzdy při DPN se nepodařilo spočítat u %d nepřítomností: %s. Dořešte je v Mzdy → Nepřítomnosti.',
                count($failed),
                implode('; ', array_slice($failed, 0, 10)),
            ));
        }
    }

    /**
     * Výpočet jedné nepřítomnosti; vrací náhradu za dny od začátku vedení mezd.
     *
     * @param array<string,mixed> $absence
     */
    private function one(array $absence, int $averageHourlyMinor, string $start, ?int $userId): int
    {
        $segments = $this->proration->sicknessCompensationSegments($absence, false);
        if ($segments === []) {
            // Okno vyčerpané předchozími částmi nebo celé po skončení vztahu: náhrada
            // nenáleží, výpočet s nulou ale nese okno pro krácení mzdy a hlášení.
            $bounds = $this->absences->absenceBounds($absence, false);
            if ($bounds['window_to'] >= $bounds['from']) {
                throw new \DomainException('v okně § 192 ZP nemá pracovní kalendář žádnou pracovní dobu');
            }
            $this->sickness->record(
                $absence,
                false,
                true,
                true,
                $this->calculator->withoutCompensation((string) $absence['date_from'], 'window_exhausted'),
                $userId,
            );

            return 0;
        }
        $segments = $this->withQuarterAverages($absence, $segments);
        $full = $this->calculator->calculate((string) $absence['date_from'], $averageHourlyMinor, $segments);
        $kept = array_values(array_filter(
            $full->segments,
            static fn (array $segment): bool => (string) $segment['local_date'] >= $start,
        ));
        $result = new SicknessCompensationResult(
            $full->reducedHourlyMinor,
            array_sum(array_map(static fn (array $segment): int => (int) $segment['compensation_minor'], $kept)),
            $full->supportStatus,
            $full->rulesetId,
            $full->rulesetHash,
            $kept,
            $full->trace + [
                'takeover_start_period' => $start,
                'takeover_segments_paid_by_previous_payer' => count($full->segments) - count($kept),
            ],
        );
        $event = $this->sickness->record($absence, false, true, true, $result, $userId);
        if ($kept !== []) {
            $this->inputs->materialize((int) $absence['supplier_id'], (int) $event['id'], $userId);
        }

        return $result->compensationMinor;
    }

    /**
     * Směny okna § 192 ZP v dalším kalendářním čtvrtletí než začátek nemoci dostanou
     * průměr toho čtvrtletí: průměrný výdělek se zjišťuje k prvnímu dni čtvrtletí
     * a platí po celé čtvrtletí (§ 351 a § 354 odst. 1 ZP). Bez schváleného průměru
     * dalšího čtvrtletí zůstává průměr čtvrtletí, kdy nemoc začala.
     *
     * @param array<string,mixed> $absence
     * @param list<array<string,mixed>> $segments
     * @return list<array<string,mixed>>
     */
    private function withQuarterAverages(array $absence, array $segments): array
    {
        $startKey = substr((string) $absence['date_from'], 0, 4) . '-' . (intdiv((int) substr((string) $absence['date_from'], 5, 2) - 1, 3) + 1);
        $byQuarter = [];
        foreach ($segments as $i => $segment) {
            $date = (string) $segment['local_date'];
            $year = (int) substr($date, 0, 4);
            $quarter = intdiv((int) substr($date, 5, 2) - 1, 3) + 1;
            if ("{$year}-{$quarter}" === $startKey) {
                continue;
            }
            if (!array_key_exists("{$year}-{$quarter}", $byQuarter)) {
                $average = $this->averages->findApproved((int) $absence['supplier_id'], (int) $absence['employment_id'], $year, $quarter);
                $byQuarter["{$year}-{$quarter}"] = $average !== null && ($average['support_status'] ?? null) === 'supported'
                    && is_int($average['average_hourly_minor'] ?? null) && $average['average_hourly_minor'] > 0
                    ? $average['average_hourly_minor']
                    : null;
            }
            if ($byQuarter["{$year}-{$quarter}"] !== null) {
                $segments[$i]['average_hourly_minor'] = $byQuarter["{$year}-{$quarter}"];
            }
        }

        return $segments;
    }

    /**
     * @template T
     * @param callable():T $callback
     * @return T
     */
    private function transactional(callable $callback): mixed
    {
        $pdo = $this->db->pdo();
        $owns = !$pdo->inTransaction();
        if ($owns) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT ' . self::SAVEPOINT);
        }
        try {
            $result = $callback();
            if ($owns) {
                $pdo->commit();
            } else {
                $pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);
            }

            return $result;
        } catch (\Throwable $e) {
            if (TransactionFailure::rollbackIfAborted($pdo, $e)) {
                throw $e;
            }
            if ($owns) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            } elseif ($pdo->inTransaction()) {
                $pdo->exec('ROLLBACK TO SAVEPOINT ' . self::SAVEPOINT);
                $pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);
            }
            throw $e;
        }
    }
}
