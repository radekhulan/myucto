<?php

declare(strict_types=1);

namespace MyUctoRegression;

final class CaseProjection
{
    public static function closing(object $container, array $case): array
    {
        [$supplier, $period, $from, $to] = $case['args'];
        $service = $container->get('MyInvoice\\Service\\Accounting\\Closing\\ClosingService');
        $repository = $container->get('MyInvoice\\Repository\\ClosingRepository');
        if ($case['kind'] === 'closing_entry_projection') {
            $statement = $container->get('MyInvoice\\Service\\Accounting\\Reports\\FinancialStatementService')->balanceSheet($supplier, $period, $to, 'full');
            $proof = ['accounting_framework' => $statement['accounting_framework'] ?? null, 'version_code' => $statement['version_code'] ?? null, 'statement_type' => $statement['statement_type'] ?? null];
            if (($statement['accounting_framework'] ?? null) === 'cz_504' || str_starts_with((string) ($statement['version_code'] ?? ''), 'vyhl504-') || !isset($statement['checks']['assets_net'], $statement['checks']['liabilities_total'])) throw new ProjectionUnavailable($proof);
            $pl = $repository->plBalances($supplier, $period, $from, $to);
            $bs = $repository->bsBalances($supplier, $period, $to);
            $builder = $container->get('MyInvoice\\Service\\Accounting\\Closing\\ClosingEntryBuilder');
            $closing = $builder->closingLines($pl, $bs);
            return ['scope' => 'native_500', 'framework_proof' => $proof, 'pl_balances' => $pl, 'bs_balances' => $bs, 'closing' => $closing,
                'opening' => $builder->openingLines($bs, $closing['profit'])];
        }
        if ($case['kind'] === 'closing_provisions') {
            $pages = [];
            $page = 1;
            do {
                $data = $service->provisionsPreview($supplier, $period, $page, 200);
                if (($data['pagination']['page'] ?? null) !== $page || !is_int($data['pagination']['total'] ?? null)) throw new \RuntimeException('invalid_provisions_pagination');
                $pages[] = $data;
                ++$page;
            } while (($page - 1) * 200 < $data['pagination']['total']);
            return ['pages' => $pages];
        }
        if ($case['kind'] === 'closing_fx') return $service->fxPreview($supplier, $period, $repository->bankProposals($supplier, $to));
        if ($case['kind'] === 'closing_stock') return [
            'projection' => $service->stockValuationProjection($supplier, $period),
            'valuation' => $container->get('MyInvoice\\Service\\Accounting\\Closing\\StockClosingValuation')->totals($supplier, $from, $to),
        ];
        $method = match ($case['kind']) {
            'closing_inventory' => 'inventoryPreview',
            'closing_small_asset_accrual' => 'smallAssetAccrualPreview',
            'closing_prepaid_accrual' => 'prepaidExpenseAccrualPreview',
            'closing_deferred_revenue' => 'deferredRevenueAccrualPreview',
            'closing_deferred_tax' => 'deferredTaxPreview',
            default => throw new \RuntimeException('unknown_closing_projection'),
        };
        return $service->$method($supplier, $period);
    }
}

final class ProjectionUnavailable extends \RuntimeException
{
    public readonly string $errorCode;

    public function __construct(public readonly array $frameworkProof)
    {
        $this->errorCode = 'regression_closing_framework_unsupported';
        parent::__construct($this->errorCode);
    }
}
