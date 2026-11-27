<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Reports;

use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\Assets\AssetException;
use MyInvoice\Service\Accounting\Accrual\AccrualAgendaGuard;
use MyInvoice\Service\Accounting\Accrual\AccrualScheduleCalculator;
use MyInvoice\Service\Accounting\Assets\DepreciationPostingService;
use MyInvoice\Service\Accounting\Closing\AccrualDeferralService;
use MyInvoice\Service\Accounting\Closing\ClosingException;
use MyInvoice\Service\Accounting\PostingException;

/**
 * Průběžné časové rozlišení ve výkazech mimo deník (volba výkazu, výchozí vypnutá).
 *
 * Doklad s obdobím od–do se zaúčtuje celý do nákladu či výnosu dne dokladu a na 381/384
 * ho odloží až krok uzávěrky. Výkaz k rozvahovému dni s touto volbou ukáže v nákladech
 * a výnosech jen poměrnou část období do toho dne včetně a zbytek jako náklady (381)
 * či výnosy (384) příštích období, jako by uzávěrka proběhla k tomu dni. Do deníku se
 * nic nezapisuje.
 *
 * Výběr řádků, účty i částky počítá {@see AccrualDeferralService} stejně jako uzávěrka:
 * upravují se jen doklady zaúčtované od začátku období do rozvahového dne, které odklad
 * nenesou samy a jejichž odklad k tomu dni uzávěrka ještě nezaúčtovala. Co už v deníku
 * na 381/384 je (odklad uzávěrky, rozpuštění v dalším roce, doklad rozúčtovaný rovnou na
 * 381/384), se znovu neupravuje.
 *
 * Stejná volba přidává průběžné účetní odpisy: odpis roku, který zaúčtuje až krok
 * uzávěrky, se k rozvahovému dni ukáže za uplynulé celé měsíce jako MD 551 / D oprávky
 * ({@see DepreciationPostingService::interimAccounting()}).
 *
 * Zápisy rozpuštění 381/384 a odpisů, které deník nese s datem PO rozvahovém dni (deník
 * převzatý z jiného programu, předem zaúčtované rozpuštění), k tomu dni v deníku nejsou
 * a výkaz z nich ukáže uplynulou část ({@see LaterDatedEntrySpread}). Stejně položka agendy
 * a karta majetku, jejíž rozpuštění či odpis roku je zaúčtovaný s pozdějším datem.
 *
 * Výsledkem jsou rozdíly zůstatků (MD − D) po účtech. Výkazy je přičtou k zůstatkům
 * deníku, takže rozvaha bilancuje: o co klesne náklad, o to vzroste 381 a výsledek
 * běžného období; o co klesne výnos, o to vzroste 384 a klesne výsledek; o co vzroste
 * odpis, o to vzrostou oprávky a klesne výsledek.
 */
final class InterimAccrualAdjustment
{
    public function __construct(
        private readonly AccrualDeferralService $accruals,
        private readonly DepreciationPostingService $depreciation,
        private readonly AccrualAgendaGuard $agenda,
        private readonly LaterDatedEntrySpread $laterDated,
        private readonly AccountingPeriodRepository $periods,
    ) {}

    /**
     * @return array{deltas: array<string,float>, prepaid_expense: float, deferred_revenue: float, documents: int,
     *   depreciation: float, depreciation_assets: int, accrual_agenda: float, accrual_agenda_items: int,
     *   later_dated: array{deferred_revenue: float, prepaid_expense: float, depreciation: float, entries: int}}
     *   deltas = účet => změna zůstatku MD − D; prepaid_expense = přírůstek 381; deferred_revenue = přírůstek 384;
     *   depreciation = průběžný účetní odpis karet, jejichž odpis roku deník ještě nenese;
     *   accrual_agenda = nezaúčtované rozpuštění položek agendy Časové rozlišení (v normálním znaménku);
     *   later_dated = uplynulá část zápisů rozpuštění a odpisů s datem po rozvahovém dni ({@see LaterDatedEntrySpread})
     */
    public function compute(int $supplierId, string $periodStartsOn, string $asOf): array
    {
        $deltas = [];
        $documents = [];
        $prepaid = 0.0;
        $revenue = 0.0;
        $add = static function (array &$deltas, string $account, float $amount): void {
            $deltas[$account] = round(($deltas[$account] ?? 0.0) + $amount, 2);
        };

        try {
            $accountsByInvoice = [];
            $costsByInvoice = [];
            foreach ($this->accruals->withoutAgendaItems($supplierId, $this->accruals->prepaidExpenseRows($supplierId, $asOf, $periodStartsOn, $asOf, $asOf)) as $row) {
                $netCzk = $this->accruals->prepaidExpenseCostCzk($supplierId, $row, $costsByInvoice);
                $deferred = AccrualScheduleCalculator::deferredAmount($netCzk, (string) $row['accrual_from'], (string) $row['accrual_to'], $asOf);
                if ((int) round($deferred * 100) === 0) {
                    continue;
                }
                $accounts = $this->accruals->prepaidExpenseAccounts($supplierId, $row, $accountsByInvoice);
                foreach (AccrualDeferralService::spreadByAccount($deferred, $accounts) as $account => $part) {
                    $add($deltas, (string) $account, -$part);
                }
                $prepaid = round($prepaid + $deferred, 2);
                $documents[AccrualDeferralService::prepaidExpenseDocumentKey($row)] = true;
            }

            $contexts = [];
            foreach ($this->accruals->withoutAgendaItems($supplierId, $this->accruals->deferredRevenueRows($supplierId, $asOf, $periodStartsOn, $asOf, null, false, $asOf)) as $row) {
                [$netCzk, $accounts] = $this->accruals->deferredRevenueItem($supplierId, $row, $contexts);
                $deferred = AccrualScheduleCalculator::deferredAmount($netCzk, (string) $row['accrual_from'], (string) $row['accrual_to'], $asOf);
                if ((int) round($deferred * 100) === 0 || $accounts === []) {
                    continue;
                }
                foreach (AccrualDeferralService::spreadByAccount($deferred, $accounts) as $account => $part) {
                    $add($deltas, (string) $account, $part);
                }
                $revenue = round($revenue + $deferred, 2);
                $documents[AccrualDeferralService::deferredRevenueDocumentKey($row)] = true;
            }

            if ((int) round($prepaid * 100) !== 0) {
                $add($deltas, $this->accruals->deferralAccount($supplierId, AccrualDeferralService::RULE_PREPAID_EXPENSE), $prepaid);
            }
            if ((int) round($revenue * 100) !== 0) {
                $add($deltas, $this->accruals->deferralAccount($supplierId, AccrualDeferralService::RULE_DEFERRED_REVENUE), -$revenue);
            }

            // Položky agendy Časové rozlišení: jen nezaúčtované rozpuštění podle plánu
            // ({@see AccrualAgendaGuard::interimDeltas()}), zaúčtované už je v deníku.
            $agenda = $this->agenda->interimDeltas($supplierId, $periodStartsOn, $asOf);
            foreach ($agenda['deltas'] as $account => $amount) {
                $add($deltas, (string) $account, $amount);
            }

            $depreciation = $this->depreciation->interimAccounting($supplierId, $periodStartsOn, $asOf);
            foreach ($depreciation['lines'] as $line) {
                $add($deltas, $line['expense'], $line['amount']);
                $add($deltas, $line['accumulated'], -$line['amount']);
            }

            $period = $this->periods->findForDate($supplierId, $periodStartsOn);
            $later = $period === null || (string) $period['starts_on'] !== $periodStartsOn
                ? ['deltas' => [], 'deferred_revenue' => 0.0, 'prepaid_expense' => 0.0, 'depreciation' => 0.0, 'entries' => 0]
                : $this->laterDated->compute($supplierId, $periodStartsOn, (string) $period['ends_on'], $asOf);
            foreach ($later['deltas'] as $account => $amount) {
                $add($deltas, (string) $account, $amount);
            }
        } catch (ClosingException $e) {
            throw new ReportException($e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (PostingException $e) {
            throw new ReportException($e->errorCode, $e->getMessage());
        } catch (AssetException $e) {
            throw new ReportException($e->errorCode, $e->getMessage(), $e->httpStatus);
        }

        return [
            'deltas' => array_filter($deltas, static fn (float $v): bool => (int) round($v * 100) !== 0),
            'prepaid_expense' => $prepaid,
            'deferred_revenue' => $revenue,
            'documents' => count($documents),
            'depreciation' => $depreciation['total'],
            'accrual_agenda' => $agenda['total'],
            'accrual_agenda_items' => $agenda['items'],
            'depreciation_assets' => $depreciation['assets'],
            'later_dated' => [
                'deferred_revenue' => $later['deferred_revenue'],
                'prepaid_expense' => $later['prepaid_expense'],
                'depreciation' => $later['depreciation'],
                'entries' => $later['entries'],
            ],
        ];
    }
}
