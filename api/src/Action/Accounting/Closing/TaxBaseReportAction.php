<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting\Closing;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\GuardsAccountingMode;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\Assets\DisposalResiduals;
use MyInvoice\Service\Tax\Return\BookedAccountingDepreciation;
use MyInvoice\Service\Tax\Return\SecuritiesSaleCostLimit;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

/**
 * Evidenční podklad DPPO (Epic F4, R19) — ŽÁDNÉ účtování, žádné XML; budoucí
 * vstup účetní větve IncomeTaxBuilderu.
 *
 *   GET /api/accounting/reports/tax-base-adjustments?fiscal_year=YYYY
 *
 * Vrací: (a) rozdíl daňových a účetních odpisů roku (úprava základu daně),
 * (b) vyřazený majetek roku s daňovou/účetní zůstatkovou cenou a klasifikací
 * daňové uznatelnosti ZC (§24/2/b, §25/1/o, §24/2/c+l ZDP), (b2) prodané podíly
 * a cenné papíry proti příjmům z prodeje (§24/2/w ZDP), (c) informativní
 * zůstatky dohadných účtů 388/389 a kurzové rozdíly 563/663 z přecenění.
 */
final class TaxBaseReportAction
{
    use AccountingActionSupport;
    use GuardsAccountingMode;

    public function __construct(
        private readonly Connection $db,
        private readonly AccountingPeriodRepository $periods,
        private readonly LoggerInterface $log,
    ) {}

    public function get(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $fiscalYear = (int) ($request->getQueryParams()['fiscal_year'] ?? 0);
        if ($fiscalYear < 2000 || $fiscalYear > 2200) {
            return Json::error($response, 'validation_failed', 'fiscal_year je povinný (rozumný účetní rok).', 422);
        }

        $period = $this->periods->findByYear($supplierId, $fiscalYear);
        if ($period === null) {
            return Json::error($response, 'not_found', 'Účetní období pro daný rok neexistuje.', 404);
        }

        try {
            $data = [
                'fiscal_year'  => $fiscalYear,
                'period'       => [
                    'id'        => (int) $period['id'],
                    'starts_on' => (string) $period['starts_on'],
                    'ends_on'   => (string) $period['ends_on'],
                ],
                'depreciation' => $this->depreciationDiff($supplierId, $fiscalYear, $period),
                'disposals'    => $this->disposals($supplierId, $period),
                // Stejná čísla, se kterými počítá ř. 40 přiznání DPPO (§ 24/2/w ZDP).
                'securities'   => (new SecuritiesSaleCostLimit($this->db))
                    ->forPeriod($supplierId, (string) $period['starts_on'], (string) $period['ends_on']),
                'info'         => $this->infoBalances($supplierId, $period),
                'note'         => 'Evidenční podklad pro přiznání DPPO — systém nic neúčtuje; splatná daň '
                    . '(MD 591 / D 341) se účtuje ručním zápisem.',
            ];
        } catch (\Throwable $e) {
            $this->log->error('Podklad DPPO se nepodařilo sestavit: ' . $e->getMessage(), ['exception' => $e]);
            return Json::error($response, 'operation_failed', 'Podklad se nepodařilo sestavit.', 500);
        }

        return Json::ok($response, $data);
    }

    /**
     * (a) Σ daňových vs. účetních odpisů roku. Kladný rozdíl (daňové > účetní)
     * = snížení základu daně, záporný = zvýšení (§24/2/a, §23/3 ZDP). Účetní odpisy
     * jsou ty v knihách, stejně jako v přiznání DPPO ({@see BookedAccountingDepreciation}).
     *
     * @param array<string,mixed> $period
     * @return array{tax_total:float, accounting_total:float, difference:float, warnings:list<string>, note:string}
     */
    private function depreciationDiff(int $supplierId, int $fiscalYear, array $period): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(amount), 0)
               FROM depreciation_entries
              WHERE supplier_id = ? AND fiscal_year = ? AND kind = 'tax'"
        );
        $stmt->execute([$supplierId, $fiscalYear]);
        $booked = (new BookedAccountingDepreciation($this->db))
            ->forPeriod($supplierId, $fiscalYear, (string) $period['starts_on'], (string) $period['ends_on']);
        $tax = round((float) $stmt->fetchColumn() + $booked['tax_taken_over'], 2);
        $diff = round($tax - $booked['total'], 2);

        return [
            'tax_total'        => $tax,
            'accounting_total' => $booked['total'],
            'difference'       => $diff,
            'warnings'         => BookedAccountingDepreciation::warnings($booked),
            'note'             => $diff >= 0
                ? 'Daňové odpisy převyšují účetní o ' . number_format($diff, 2, ',', ' ') . ' Kč — základ daně se snižuje.'
                : 'Účetní odpisy převyšují daňové o ' . number_format(-$diff, 2, ',', ' ') . ' Kč — základ daně se zvyšuje.',
        ];
    }

    /**
     * (b) Majetek vyřazený v období: účetní a daňová ZC ze {@see DisposalResiduals}
     * (stejné hodnoty, se kterými počítá přiznání DPPO; daňová ZC může být neznámá = null)
     * a klasifikace daňové uznatelnosti ZC dle ZDP.
     *
     * @return list<array<string,mixed>>
     */
    private function disposals(int $supplierId, array $period): array
    {
        $residuals = (new DisposalResiduals($this->db))
            ->forPeriod($supplierId, (string) $period['starts_on'], (string) $period['ends_on']);

        $out = [];
        foreach ($residuals['rows'] as $row) {
            [$deductibility, $note] = $this->classifyDisposal($row['disposal_type']);

            $out[] = [
                'asset_id'                  => $row['asset_id'],
                'inventory_number'          => $row['inventory_number'],
                'name'                      => $row['name'],
                'disposal_date'             => $row['disposal_date'],
                'disposal_type'             => $row['disposal_type'],
                'disposal_price'            => $row['disposal_price'],
                'tax_residual_value'        => $row['tax_residual_value'],
                'tax_residual_source'       => $row['tax_residual_source'],
                'accounting_residual_value' => $row['book_residual_value'],
                'accounting_residual_source' => $row['book_residual_source'],
                'deductibility'             => $deductibility,
                'note'                      => $note,
            ];
        }
        return $out;
    }

    /**
     * Klasifikace daňové uznatelnosti daňové ZC při vyřazení (R19).
     *
     * @return array{0:'full'|'none'|'limited', 1:string}
     */
    private function classifyDisposal(string $type): array
    {
        return match ($type) {
            'sold'       => ['full', 'Prodej — daňová ZC plně uznatelná (§24 odst. 2 písm. b) ZDP).'],
            'liquidated' => ['full', 'Likvidace — daňová ZC plně uznatelná (§24 odst. 2 písm. b) ZDP; výjimka: likvidace stavebního díla v souvislosti s novou výstavbou — ZC vstupuje do ceny nové stavby, bod 2).'],
            'donated'    => ['none', 'Dar — daňová ZC neuznatelná (§25 odst. 1 písm. o) ZDP).'],
            'damaged'    => ['limited', 'Škoda — daňová ZC uznatelná jen do výše přijatých náhrad '
                . '(§24 odst. 2 písm. c) ZDP); plně u živelní pohromy nebo škody způsobené dle potvrzení policie neznámým pachatelem (§24 odst. 2 písm. l) ZDP).'],
            default      => ['limited', 'Neznámý typ vyřazení — posuďte individuálně.'],
        };
    }

    /**
     * (c) Informativní zůstatky: dohadné účty 388/389 ke konci období a Σ 563/663
     * ze zápisů přecenění (source_type fx_revaluation) daného období.
     *
     * @return array<string,float>
     */
    private function infoBalances(int $supplierId, array $period): array
    {
        $endsOn = (string) $period['ends_on'];
        $periodId = (int) $period['id'];

        return [
            'estimates_388_balance' => $this->accountBalance($supplierId, '388', $endsOn),
            'estimates_389_balance' => $this->accountBalance($supplierId, '389', $endsOn),
            'fx_revaluation_loss_563' => $this->fxTotal($supplierId, $periodId, '563', 'debit'),
            'fx_revaluation_gain_663' => $this->fxTotal($supplierId, $periodId, '663', 'credit'),
        ];
    }

    /** Netto zůstatek účtu dle prefixu kódu (vč. analytik) k datu. */
    private function accountBalance(int $supplierId, string $codePrefix, string $asOf): float
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COALESCE(SUM(CASE WHEN l.side = \'debit\' THEN l.signed_amount ELSE -l.signed_amount END), 0)
               FROM journal_entry_lines l
               JOIN journal_entries e ON e.id = l.entry_id
               JOIN chart_of_accounts ca ON ca.id = l.account_id
              WHERE l.supplier_id = ? AND e.supplier_id = ?
                AND e.posted_at IS NOT NULL AND e.entry_date <= ?
                AND ca.account_code LIKE ?'
        );
        $stmt->execute([$supplierId, $supplierId, $asOf, $codePrefix . '%']);
        return round((float) $stmt->fetchColumn(), 2);
    }

    /** Σ částek na straně účtu (prefix) ze zápisů fx_revaluation období. */
    private function fxTotal(int $supplierId, int $periodId, string $codePrefix, string $side): float
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COALESCE(SUM(l.signed_amount), 0)
               FROM journal_entry_lines l
               JOIN journal_entries e ON e.id = l.entry_id
               JOIN chart_of_accounts ca ON ca.id = l.account_id
              WHERE l.supplier_id = ? AND e.supplier_id = ?
                AND e.source_type = \'fx_revaluation\' AND e.period_id = ?
                AND e.posted_at IS NOT NULL
                AND l.side = ? AND ca.account_code LIKE ?'
        );
        $stmt->execute([$supplierId, $supplierId, $periodId, $side, $codePrefix . '%']);
        return round((float) $stmt->fetchColumn(), 2);
    }
}
