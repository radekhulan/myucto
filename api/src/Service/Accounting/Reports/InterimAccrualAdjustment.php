<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Reports;

use MyInvoice\Service\Accounting\Assets\AssetException;
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
    ) {}

    /**
     * @return array{deltas: array<string,float>, prepaid_expense: float, deferred_revenue: float, documents: int,
     *   depreciation: float, depreciation_assets: int}
     *   deltas = účet => změna zůstatku MD − D; prepaid_expense = přírůstek 381; deferred_revenue = přírůstek 384;
     *   depreciation = průběžný účetní odpis karet, jejichž odpis roku deník ještě nenese
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
            foreach ($this->accruals->prepaidExpenseRows($supplierId, $asOf, $periodStartsOn, $asOf, $asOf) as $row) {
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
            foreach ($this->accruals->deferredRevenueRows($supplierId, $asOf, $periodStartsOn, $asOf, null, false, $asOf) as $row) {
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

            $depreciation = $this->depreciation->interimAccounting($supplierId, $periodStartsOn, $asOf);
            foreach ($depreciation['lines'] as $line) {
                $add($deltas, $line['expense'], $line['amount']);
                $add($deltas, $line['accumulated'], -$line['amount']);
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
            'depreciation_assets' => $depreciation['assets'],
        ];
    }
}
