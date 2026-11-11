<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Run;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollRunRepository;
use MyInvoice\Repository\Payroll\PayrollTakeoverRunRepository;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverReader;
use MyInvoice\Service\Payroll\PayrollHistoricalPeriodService;
use MyInvoice\Service\Payroll\PayrollYearCloseGuard;
use PDO;

/**
 * Převzatý mzdový běh: zrcadlo měsíce, který zpracoval předchozí program.
 *
 * ── Proč to není příkaz běžného workflow ────────────────────────────────────
 * Zadání zní „validace se PŘESKAKUJÍ, ne přebíjejí". Nejčistší způsob, jak něco
 * přeskočit, je tam vůbec nevstoupit: převzatý běh neprochází
 * {@see PayrollRunWorkflow} ani {@see PayrollRunCommandService}, takže žádný
 * blokátor spočítaného běhu se nemusel uvolnit, obejít ani zvýjimkovat. Ten kód
 * zůstal doslova nedotčený a spočítaný běh se chová přesně jako dřív.
 *
 * Opačný směr hlídá {@see PayrollRunCommandService::assertCalculatedRun()}:
 * příkaz workflow nad převzatým během skončí doménovou chybou. Obě brány jsou
 * vázané na `payroll_runs.run_kind`, ne na výjimku pro soubor.
 *
 * ── Kdy převzatý běh smí vzniknout ──────────────────────────────────────────
 *  1. období PŘEDCHÁZÍ `payroll_module_state.start_period` podle jediného
 *     výkladu té hranice ({@see PayrollHistoricalPeriodService::precedesStart()}),
 *  2. za období JSOU převzatá data,
 *  3. mzdový rok je otevřený,
 *  4. za období ještě není běh, který MyÚčto počítá.
 *
 * Za měsíc, který MyÚčto počítá, převzatý běh nevznikne nikdy — jinak by vedle
 * spočítaného výsledku stálo druhé, neověřené číslo za týž měsíc.
 *
 * ── Neměnnost ───────────────────────────────────────────────────────────────
 * Zrcadlo se neopravuje. `discard()` ho zahodí (obal běhu zůstane jako
 * `cancelled`, aby se dal znovu použít a nevznikl duplicitní měsíc) a `build()`
 * ho vezme znovu z aktuálních převzatých dat. Přepsat ho nejde ani ručně:
 * `payroll_takeover_runs` i doložení plateb mají zákaz UPDATE v databázi.
 */
final class PayrollTakeoverRunService
{
    private readonly PayrollYearCloseGuard $yearClose;

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollTakeoverRunRepository $takeovers,
        private readonly PayrollRunRepository $runs,
        private readonly PayrollTakeoverReader $reader,
        private readonly PayrollTakeoverRunBuilder $builder,
        private readonly PayrollHistoricalPeriodService $historical,
    ) {
        $this->yearClose = new PayrollYearCloseGuard($db);
    }

    /**
     * Přehled roku přechodu: co je převzaté, co už má běh a co MyÚčto počítá.
     *
     * @return array<string,mixed>
     */
    public function overview(int $supplierId, int $year): array
    {
        $takeover = $this->reader->forSupplier($supplierId, $year);
        $built = $this->takeovers->builtRuns($supplierId, $year);
        $periods = [];
        foreach ($takeover->takeoverPeriods() as $period) {
            $periods[] = [
                'period' => $period,
                'historical' => $takeover->isHistorical($period),
                'presence' => $takeover->presence($period),
                'has_takeover_run' => isset($built[$period]),
                'run_id' => $built[$period] ?? null,
                'row_count' => count($takeover->forPeriod($period)),
            ];
        }

        return [
            'year' => $year,
            'payroll_start_period' => $takeover->payrollStartPeriod,
            'sources' => $takeover->sources(),
            'periods' => $periods,
        ];
    }

    /**
     * Postaví převzatý běh za jeden měsíc.
     *
     * @return array<string,mixed>
     */
    public function build(int $supplierId, string $period, ?int $actorUserId): array
    {
        $period = $this->normalizePeriod($period);
        $periodStart = $period . '-01';
        if ($actorUserId !== null && $actorUserId <= 0) {
            throw new \InvalidArgumentException('Uživatel převzetí běhu není platný.');
        }

        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $this->assertModuleActive($supplierId);
            $this->assertHistorical($supplierId, $period);
            $this->yearClose->assertOpenForDateRange($supplierId, $periodStart, $periodStart);

            $year = $this->reader->forSupplier(
                $supplierId,
                (int) substr($period, 0, 4),
            );
            /*
             * Měsíc, který MyÚčto počítá, se nesmí převzít ani tehdy, když by
             * hranice mzdového modulu byla nastavená nedbale. Druhé číslo za
             * týž měsíc je horší než chybějící funkce.
             */
            if ($year->hasCalculated($period)) {
                throw new \DomainException(
                    'Za tenhle měsíc už má MyÚčto vlastní výsledek. Převzatý běh '
                    . 'by vedle něj postavil druhé, neověřené číslo.',
                );
            }
            $build = $this->builder->build($year, $period);

            $existing = $this->takeovers->runForPeriod($supplierId, $periodStart);
            if ($existing !== null
                && ($existing['run_kind'] ?? 'calculated') !== 'takeover'
            ) {
                throw new \DomainException(
                    'Za tenhle měsíc už je založený běžný mzdový běh.',
                );
            }
            if ($existing !== null
                && $this->takeovers->takeover($supplierId, (int) $existing['id']) !== null
            ) {
                throw new \DomainException(
                    'Převzatý běh za tenhle měsíc už existuje. Zrcadlo se '
                    . 'neopravuje — nejdřív ho zrušte a převezměte znovu.',
                );
            }

            if ($existing === null) {
                $runId = $this->takeovers->insertRun(
                    $supplierId,
                    $periodStart,
                    $build->paymentDate,
                    $actorUserId,
                );
                $fromStatus = null;
            } else {
                $runId = (int) $existing['id'];
                $this->takeovers->reviveRun(
                    $supplierId,
                    $runId,
                    $build->paymentDate,
                    $actorUserId,
                );
                $fromStatus = (string) $existing['status'];
            }

            $this->takeovers->insertTakeover(
                $supplierId,
                $runId,
                $periodStart,
                $build->sources,
                $build->inputSnapshot,
                $build->inputSnapshotHash,
                $build->resultSnapshot,
                $build->resultSnapshotHash,
                $build->employeeCount,
                $build->relationshipCount,
                $actorUserId,
            );
            $evidenceCount = $this->takeovers->insertEvidence(
                $supplierId,
                $runId,
                $build->paymentEvidence,
                $actorUserId,
            );
            $this->runs->insertEvent(
                $supplierId,
                $runId,
                null,
                $existing === null ? 'created' : 'takeover_rebuilt',
                $fromStatus,
                'closed',
                $actorUserId,
                null,
                [
                    'run_kind' => PayrollRunKind::TAKEOVER->value,
                    'period_start' => $periodStart,
                    'payment_date' => $build->paymentDate,
                    'sources' => $build->sources,
                    'result_snapshot_hash' => $build->resultSnapshotHash,
                    'payment_evidence_count' => $evidenceCount,
                ],
            );

            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        return $this->detail($supplierId, $runId);
    }

    /**
     * Zahodí zrcadlo. Obal běhu zůstane jako `cancelled`.
     *
     * @return array<string,mixed>
     */
    public function discard(
        int $supplierId,
        int $runId,
        string $reason,
        ?int $actorUserId,
    ): array {
        $reason = trim($reason);
        if ($reason === '') {
            throw new \InvalidArgumentException('Zrušení převzatého běhu vyžaduje důvod.');
        }

        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $run = $this->runs->lock($supplierId, $runId);
            if ($run === null) {
                throw new \OutOfBoundsException('Mzdový běh nebyl nalezen.');
            }
            if (!PayrollRunKind::isTakeover($run)) {
                throw new \DomainException(
                    'Tenhle mzdový běh není převzatý — rušit se dá příkazem workflow.',
                );
            }
            $this->yearClose->assertOpenForDateRange(
                $supplierId,
                (string) $run['period_start'],
                (string) $run['period_start'],
            );
            if ($this->takeovers->takeover($supplierId, $runId) === null) {
                throw new \DomainException('Převzatý běh už je zrušený.');
            }

            $this->takeovers->deleteTakeover($supplierId, $runId);
            $this->takeovers->cancelRun($supplierId, $runId, $actorUserId);
            $this->runs->insertEvent(
                $supplierId,
                $runId,
                null,
                'takeover_discarded',
                (string) $run['status'],
                'cancelled',
                $actorUserId,
                $reason,
                ['run_kind' => PayrollRunKind::TAKEOVER->value],
            );

            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        return ['run_id' => $runId, 'discarded' => true];
    }

    /**
     * Převzatý běh i s doložením plateb.
     *
     * @return array<string,mixed>
     */
    public function detail(int $supplierId, int $runId): array
    {
        $run = $this->runs->find($supplierId, $runId);
        if ($run === null) {
            throw new \OutOfBoundsException('Mzdový běh nebyl nalezen.');
        }
        if (!PayrollRunKind::isTakeover($run)) {
            throw new \DomainException('Tenhle mzdový běh není převzatý.');
        }
        $takeover = $this->takeovers->takeover($supplierId, $runId);
        if ($takeover === null) {
            throw new \OutOfBoundsException('Převzatý běh nemá zmrazený výsledek.');
        }

        return [
            'run' => $run,
            'takeover' => $takeover,
            /*
             * Doložení plateb, NE platební ledger. Nevzniká tu závazek, dávka
             * ani úhrada, takže se to neobjeví v saldu, v hlídači termínů ani
             * v účetním deníku — a nemá se objevit, protože za historický měsíc
             * MyÚčto nikdy nic nedlužilo a jeho zaúčtování je v knihách
             * z převodu.
             */
            'payment_evidence' => $this->takeovers->evidence($supplierId, $runId),
        ];
    }

    private function normalizePeriod(string $period): string
    {
        $value = trim($period);
        if (preg_match('/^\d{4}-\d{2}$/D', $value) !== 1) {
            throw new \InvalidArgumentException('Období musí být ve tvaru RRRR-MM.');
        }
        $month = (int) substr($value, 5, 2);
        if ($month < 1 || $month > 12) {
            throw new \InvalidArgumentException('Období musí být ve tvaru RRRR-MM.');
        }

        return $value;
    }

    private function assertHistorical(int $supplierId, string $period): void
    {
        $startPeriod = $this->historical->startPeriod($supplierId);
        if ($startPeriod === null) {
            throw new \DomainException(
                'Firma nemá nastavený první mzdový měsíc, takže není podle čeho '
                . 'poznat, který měsíc je historický.',
            );
        }
        if (!PayrollHistoricalPeriodService::precedesStart($startPeriod, $period)) {
            throw new \DomainException(sprintf(
                'Měsíc %s už vede MyÚčto (mzdy počítá od %s). Převzít se dá jen '
                . 'období, které předchází aktivaci mzdového modulu.',
                $period,
                $startPeriod,
            ));
        }
    }

    /**
     * Tatáž brána jako u běžného běhu, jen BEZ pravidla o období.
     *
     * Kontrola období je u převzatého běhu obrácená (musí být historické) a dělá
     * ji {@see self::assertHistorical()}. Zbytek — zapnuté mzdy a živý modul —
     * platí stejně: bez mzdového modulu nemá převzatý měsíc kam patřit.
     */
    private function assertModuleActive(int $supplierId): void
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT supplier.payroll_enabled, state.status AS module_status
               FROM supplier
          LEFT JOIN payroll_module_state state ON state.supplier_id = supplier.id
              WHERE supplier.id = ?
              FOR UPDATE',
        );
        $statement->execute([$supplierId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new \OutOfBoundsException('Firma nebyla nalezena.');
        }
        if (!(bool) $row['payroll_enabled']) {
            throw new \DomainException(PayrollModuleAvailabilityMessage::PAYROLL_DISABLED);
        }
        if ($row['module_status'] === null
            || in_array($row['module_status'], ['disabled', 'suspended'], true)
        ) {
            throw new \DomainException(PayrollModuleAvailabilityMessage::moduleInactive(
                $row['module_status'] === null ? null : (string) $row['module_status'],
            ));
        }
    }
}
