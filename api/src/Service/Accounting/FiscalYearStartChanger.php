<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\ActivityLogger;

/**
 * Změna nastavení „Účetní rok začíná" u firmy, která už má účetní období.
 *
 * Tvar účetního roku se zadává předem ({@see AccountingPeriodRepository::fiscalCalendar()}),
 * jenže založení firmy hned otevře období pro rok založení
 * ({@see AccountingPeriodProvisioner::REASON_SETUP}) a převody z jiných programů při
 * nesouladu roku posílají uživatele právě do tohoto nastavení. Bez náhrady by firma
 * s hospodářským rokem uvízla na kalendářním období, které nikdy nepoužila.
 *
 * Nahradit se smí jen období, která jsou VŠECHNA nepoužitá ({@see UnusedAccountingPeriods}).
 * Jakmile je některé použité, změna účetního roku
 * je zákonný úkon s přechodným obdobím (§ 3 odst. 2 ZoÚ) a tady se neprovede.
 */
final class FiscalYearStartChanger
{
    public function __construct(
        private readonly Connection $db,
        private readonly AccountingPeriodRepository $periods,
        private readonly AccountingPeriodProvisioner $provisioner,
        private readonly UnusedAccountingPeriods $unused,
        private readonly ActivityLogger $activity,
    ) {}

    /**
     * Brání období firmy změně účetního roku? Prázdný výsledek = všechna jsou nepoužitá.
     *
     * @return list<array{fiscal_year:int, starts_on:string, ends_on:string, reasons:list<string>}>
     */
    public function blockingPeriods(int $supplierId): array
    {
        return $this->unused->blocking($supplierId);
    }

    /**
     * Smaže nepoužitá období, uloží nový začátek účetního roku a otevře období pro
     * dnešek už v novém tvaru (jako při založení firmy). Vše v jedné transakci;
     * použije-li mezitím někdo některé období, nic se nezmění.
     *
     * @return array{removed: list<array<string,mixed>>, created: ?array<string,mixed>}
     * @throws \DomainException některé období je použité
     */
    public function replaceUnusedPeriods(int $supplierId, int $startMonth, ?int $userId = null, ?string $today = null): array
    {
        $pdo = $this->db->pdo();
        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT fiscal_year_start');
        }
        try {
            $lock = $pdo->prepare('SELECT id FROM accounting_periods WHERE supplier_id = ? FOR UPDATE');
            $lock->execute([$supplierId]);
            if ($this->blockingPeriods($supplierId) !== []) {
                throw new \DomainException('Účetní období firmy jsou použitá.');
            }
            $removed = $this->periods->listForTenant($supplierId);
            $pdo->prepare('DELETE FROM accounting_periods WHERE supplier_id = ?')->execute([$supplierId]);
            $pdo->prepare('UPDATE supplier SET fiscal_year_start_month = ? WHERE id = ?')->execute([$startMonth, $supplierId]);

            $created = $removed === [] ? null : $this->provisioner->ensureOpenPeriodForDate(
                $supplierId,
                $today ?? date('Y-m-d'),
                AccountingPeriodProvisioner::REASON_SETUP,
                $userId,
            );
            $this->activity->log(
                'accounting.fiscal_year_start_changed',
                $userId,
                'supplier',
                $supplierId,
                [
                    'fiscal_year_start_month' => $startMonth,
                    'removed_periods' => array_map(
                        static fn (array $p): array => array_intersect_key($p, array_flip(['id', 'fiscal_year', 'starts_on', 'ends_on', 'created_reason'])),
                        $removed,
                    ),
                    'created_period_id' => $created['id'] ?? null,
                ],
                supplierId: $supplierId,
            );
            if ($own) {
                $pdo->commit();
            } else {
                $pdo->exec('RELEASE SAVEPOINT fiscal_year_start');
            }
        } catch (\Throwable $e) {
            if (TransactionFailure::rollbackIfAborted($pdo, $e)) {
                throw $e;
            }
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            } elseif (!$own && $pdo->inTransaction()) {
                $pdo->exec('ROLLBACK TO SAVEPOINT fiscal_year_start');
                $pdo->exec('RELEASE SAVEPOINT fiscal_year_start');
            }
            throw $e;
        }
        return ['removed' => $removed, 'created' => $created];
    }
}
