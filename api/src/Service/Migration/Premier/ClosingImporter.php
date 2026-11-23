<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Premier;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\AccountingPeriodStatus;
use MyInvoice\Service\Accounting\FiscalCalendar;
use MyInvoice\Service\Accounting\Closing\ClosingException;
use MyInvoice\Service\Accounting\Closing\ClosingService;
use MyInvoice\Service\Accounting\Closing\ClosingSteps;

/**
 * Uzávěrka převedeného roku, který je v PREMIER uzavřený.
 *
 * PREMIER uzávěrkové zápisy (702/710) do deníku neukládá, takže převedený rok by v MyÚčtu
 * zůstal otevřený. Rok se považuje za uzavřený, když k němu záloha nese podané přiznání
 * k DPPO (`D_PO1`), nebo když ho účetní v PREMIER zamkla celý (`PERIODY`, všech 12
 * měsíců).
 *
 * Uzávěrka jde průvodcem uzávěrky MyÚčta ({@see ClosingService}), jen s vědomím, že
 * převzatý deník už obsahuje všechno, co účetní v PREMIER k uzávěrce zaúčtovala:
 * - kurzové rozdíly a odpisy se spustí a **nesmějí nic zaúčtovat** (převedené faktury
 *   jsou v Kč, odpisy zaúčtované převzatým deníkem hromadné účtování přeskočí);
 * - dohadné položky, časové rozlišení, opravné položky a daň z příjmů se potvrdí jako
 *   provedené v PREMIER;
 * - uzavření knih zaúčtuje 702/710 a otevření dalšího roku převezme otevírací zápis
 *   z převodu (musí sedět účet po účtu), případně ho založí.
 *
 * Cokoli, co by uzávěrka musela zaúčtovat navíc, nebo nesouhlasí, vrátí celý krok zpět
 * (savepoint) a rok zůstane otevřený s upozorněním - uzávěrku pak účetní udělá ručně.
 */
final class ClosingImporter
{
    public const STEP = 'closing';

    private const NOTE = 'Zaúčtováno v PREMIER (převzatý deník)';

    public function __construct(
        private readonly Connection $db,
        private readonly ClosingService $closing,
        private readonly AccountingPeriodRepository $periods,
    ) {}

    public function run(PremierContext $ctx): void
    {
        $p = $ctx->protocol;
        if ($ctx->period === null || $ctx->period['locked']) {
            $p->finish(self::STEP);
            return;
        }
        if (!self::closedInPremier($ctx->backup, $ctx->year, $ctx->calendar)) {
            $p->info(self::STEP, 'year_open_in_premier', "Rok {$ctx->year} není v PREMIER uzavřený (chybí podané přiznání k DPPO i zamčení období), v MyÚčtu zůstává otevřený.");
            $p->finish(self::STEP);
            return;
        }
        if ($p->hasErrors()) {
            $p->warn(self::STEP, 'closing_skipped_errors', "Rok {$ctx->year} se neuzavírá, převod skončil s chybami. Po jejich opravě spusťte převod znovu, nebo rok uzavřete průvodcem uzávěrky.");
            $p->finish(self::STEP);
            return;
        }
        if ($p->hasDifferences()) {
            // Přijatý rozdíl znamená, že převedený rok nesedí na PREMIER - uzávěrka by ho zabetonovala.
            $p->warn(self::STEP, 'closing_skipped_differences', "Rok {$ctx->year} se neuzavírá, převod skončil s přijatými rozdíly. Po jejich dořešení rok uzavřete průvodcem uzávěrky.");
            $p->finish(self::STEP);
            return;
        }
        $pdo = $this->db->pdo();
        $pdo->exec('SAVEPOINT premier_closing');
        try {
            $this->close($ctx, (int) $ctx->period['id']);
            $pdo->exec('RELEASE SAVEPOINT premier_closing');
            $p->count(self::STEP, 'closed');
            $p->info(self::STEP, 'year_closed', "Rok {$ctx->year} je uzavřený (702/710) a počáteční stavy roku " . ($ctx->year + 1) . ' navazují.');
        } catch (\Throwable $e) {
            if (TransactionFailure::rollbackIfAborted($pdo, $e)) {
                throw $e;
            }
            $pdo->exec('ROLLBACK TO SAVEPOINT premier_closing');
            $known = $e instanceof PremierException || $e instanceof ClosingException;
            $message = $known ? $e->getMessage() : 'neočekávaná chyba, podrobnosti jsou v logu serveru';
            if (!$known) {
                error_log(sprintf('PREMIER: uzávěrka roku %d firmy %d selhala: %s', $ctx->year, $ctx->supplierId, (string) $e));
            }
            $p->warn(self::STEP, 'closing_not_done', "Rok {$ctx->year} se nepodařilo uzavřít ({$message}). Zůstává otevřený, uzavřete ho průvodcem uzávěrky.");
        }
        $p->finish(self::STEP);
    }

    /**
     * Uzavřený rok podle zálohy: podané přiznání k DPPO, nebo celý rok zamčený
     * v „Zamykání period". `PERIODY` nesou jen ROK a MESIC PREMIER bez dat, párují se
     * proto na rok jako dosud (u hospodářského roku rozhoduje podané přiznání).
     */
    public static function closedInPremier(PremierBackup $backup, int $year, ?FiscalCalendar $calendar = null): bool
    {
        if (PremierSourceYears::taxReturnHead($backup, $year, $calendar) !== null) {
            return true;
        }
        $locked = [];
        foreach ($backup->rows('PERIODY') as $r) {
            if ((int) ($r['ROK'] ?? 0) === $year && ((bool) ($r['KOMPLET'] ?? false) || (bool) ($r['PU'] ?? false))) {
                $locked[(int) ($r['MESIC'] ?? 0)] = true;
            }
        }
        return count(array_intersect_key($locked, array_flip(range(1, 12)))) === 12;
    }

    private function close(PremierContext $ctx, int $periodId): void
    {
        $meta = ['user_id' => $ctx->userOrNull(), 'posted_by' => $ctx->userOrNull()];
        $version = fn (): int => (int) $this->closing->state($ctx->supplierId, $periodId)['row_version'];
        $state = $this->closing->state($ctx->supplierId, $periodId);
        $previous = $this->periods->findByYear($ctx->supplierId, $ctx->year - 1);
        if ($previous !== null && !AccountingPeriodStatus::isClosed((string) $previous['status'])) {
            throw new PremierException('previous_open', 'předchozí rok ' . ($ctx->year - 1) . ' není uzavřený');
        }
        if ((string) $state['period']['status'] === 'open') {
            $this->closing->start($ctx->supplierId, $periodId, $version(), $meta);
        }
        $this->closing->runPrecheck($ctx->supplierId, $periodId, $version(), $meta);

        $fx = $this->closing->runFxRevaluation($ctx->supplierId, $periodId, [], $version(), $meta);
        if (($fx['entry_ids'] ?? []) !== []) {
            throw new PremierException('fx_needed', 'přecenění cizoměnových zůstatků by účtovalo kurzové rozdíly, které v PREMIER nejsou');
        }
        $depreciation = $this->closing->bookDepreciation($ctx->supplierId, $periodId, $meta);
        if (abs((float) $depreciation['total_accounting']) >= 0.005 || $depreciation['errors'] !== []) {
            throw new PremierException('depreciation_needed', 'hromadné účtování odpisů by zaúčtovalo odpisy, které v deníku PREMIER nejsou');
        }
        // Kroky v pořadí průvodce: u neziskové organizace jdou Zásoby před daní.
        $state = $this->closing->state($ctx->supplierId, $periodId);
        foreach (ClosingSteps::order($state['accounting_framework'] ?? null) as $step) {
            if (in_array($step, ['depreciation', 'estimates', 'deferrals', 'provisions', 'income_tax'], true)) {
                $this->closing->confirmStep($ctx->supplierId, $periodId, $step, 'done', self::NOTE, $version(), $meta);
            } elseif ($step === 'stock') {
                foreach ($this->closing->state($ctx->supplierId, $periodId)['steps'] as $s) {
                    if ($s['step_key'] === 'stock' && $s['status'] === 'pending') {
                        $this->closing->runStockValuation($ctx->supplierId, $periodId, $version(), $meta);
                    }
                }
            }
        }
        if (!$this->closing->state($ctx->supplierId, $periodId)['can_close']) {
            throw new PremierException('cannot_close', 'průvodce uzávěrky knihy uzavřít nedovolí');
        }
        $this->closing->closeBooks($ctx->supplierId, $periodId, $version(), $meta);
        $this->closing->openNext($ctx->supplierId, $periodId, $version(), $meta);
    }
}
