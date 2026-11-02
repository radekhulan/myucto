<?php

declare(strict_types=1);

namespace MyInvoice\Service\Portal;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Crm\CrmAggregationService;
use MyInvoice\Service\Report\DphPriznaniBuilder;

/** Sdílené agregáty portálu a registru widgetů, bez názvů protistran a čísel dokladů. */
final class PortalSummaryService
{
    /** KPI období dle specu §5 (subset CrmAggregationService::overview). */
    private const KPI_PERIODS = ['current_month', 'last_month', 'ytd', 'prev_year_ytd', 'last_12m'];

    /** Okno pro daňové termíny na portálu (spec §5 — 35 dní dopředu). */
    private const DEADLINE_WINDOW_DAYS = 35;

    public function __construct(
        private readonly Connection $db,
        private readonly CrmAggregationService $crm,
        private readonly DphPriznaniBuilder $dph,
        private readonly \MyInvoice\Repository\DocumentRequestRepository $documentRequests,
    ) {}

    public function summary(int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT company_name, display_name, is_vat_payer FROM supplier WHERE id = ?'
        );
        $stmt->execute([$supplierId]);
        $supplier = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        if ($supplier === []) {
            return null;
        }

        $now = new \DateTimeImmutable('today');
        $isVatPayer = (bool) ($supplier['is_vat_payer'] ?? false);

        $overview = $this->crm->overview($supplierId);
        $kpi = [];
        foreach (self::KPI_PERIODS as $period) {
            $kpi[$period] = array_map(self::kpiRow(...), (array) ($overview[$period] ?? []));
        }
        $kpi['currencies'] = $overview['currencies'] ?? [];

        $name = trim((string) ($supplier['display_name'] ?? ''));
        if ($name === '') {
            $name = (string) ($supplier['company_name'] ?? '');
        }

        return [
            'company' => [
                'name'   => $name,
                'period' => [
                    'current_month' => $now->format('Y-m'),
                    'ytd_from'      => $now->format('Y-01-01'),
                    'today'         => $now->format('Y-m-d'),
                ],
            ],
            'kpi'      => $kpi,
            'monthly'  => $this->monthly($supplierId, 12),
            'cashflow' => [
                'receivables' => $this->aging($supplierId, true),
                'payables'    => $this->aging($supplierId, false),
                'forecast'    => $this->forecast($supplierId),
            ],
            'vat' => [
                'is_vat_payer' => $isVatPayer,
                'status'       => $this->vatStatus($supplierId, $isVatPayer, $now),
                'deadlines'    => $this->deadlines($supplierId, $now),
            ],
            // Vyžádání chybějících dokladů (Fáze F, audit 2026-07) — jen agregát (počty),
            // ne obsah požadavků; drží se stejný invariant §5 jako zbytek portálu.
            'document_requests_open' => $this->documentRequests($supplierId),
        ];
    }

    public function kpiPeriod(int $supplierId, string $period, ?\DateTimeImmutable $now = null): array
    {
        return array_map(self::kpiRow(...), $this->crm->overviewPeriod($supplierId, $period, $now));
    }

    public function monthly(int $supplierId, int $months): array
    {
        return array_map(self::kpiRow(...), $this->crm->monthlyHistory($supplierId, $months));
    }

    public function aging(int $supplierId, bool $receivables): array
    {
        return $receivables ? $this->crm->agingReceivables($supplierId) : $this->crm->agingPayables($supplierId);
    }

    public function forecast(int $supplierId, string $currency = 'CZK'): array
    {
        return $this->crm->cashFlowForecast($supplierId, 4, $currency);
    }

    public function deadlines(int $supplierId, \DateTimeImmutable $now): array
    {
        return $this->crm->taxDeadlineItems($supplierId, $now, self::DEADLINE_WINDOW_DAYS);
    }

    public function documentRequests(int $supplierId): array
    {
        return $this->documentRequests->openCounts($supplierId);
    }

    /**
     * Explicitní subset KPI řádku (fakturováno / náklady / rozdíl per měna) —
     * whitelist klíčů drží invariant „jen agregáty" i kdyby CRM služba přidala pole.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function kpiRow(array $row): array
    {
        return [
            'period'         => $row['period'] ?? null,
            'currency'       => (string) ($row['currency'] ?? 'CZK'),
            'invoiced'       => (float) ($row['revenue'] ?? 0),
            'costs'          => (float) ($row['costs'] ?? 0),
            'profit'         => (float) ($row['profit'] ?? 0),
            'invoiced_czk'   => (float) ($row['revenue_czk'] ?? 0),
            'costs_czk'      => (float) ($row['costs_czk'] ?? 0),
            'profit_czk'     => (float) ($row['profit_czk'] ?? 0),
            'invoice_count'  => (int) ($row['invoice_count'] ?? 0),
            'purchase_count' => (int) ($row['purchase_count'] ?? 0),
        ];
    }

    /**
     * DPH stav běžícího období přes DphPriznaniBuilder (subset summary bez řádků).
     * Neplátce → null; build selhání (nekompletní konfigurace firmy) → null,
     * portál kvůli DPH bloku nesmí spadnout.
     *
     * @return array<string,mixed>|null
     */
    public function vatStatus(int $supplierId, bool $isVatPayer, \DateTimeImmutable $now): ?array
    {
        if (!$isVatPayer) {
            return null;
        }
        try {
            $built = $this->dph->build($supplierId, (int) $now->format('Y'), (int) $now->format('n'));
        } catch (\Throwable $failure) {
            if (\MyInvoice\Infrastructure\Database\TransactionFailure::aborted($failure)) throw $failure;
            return null;
        }
        $s = (array) ($built['summary'] ?? []);
        return [
            'period'              => (string) ($s['period'] ?? ''),
            'period_type'         => (string) ($s['period_type'] ?? 'monthly'),
            'quarter'             => $s['quarter'] ?? null,
            'vat_output'          => (float) ($s['total_vat_output'] ?? 0),
            'vat_input'           => (float) ($s['total_vat_input'] ?? 0),
            'tax_due'             => (float) ($s['tax_due'] ?? 0),
            'is_excess_deduction' => (bool) ($s['is_excess_deduction'] ?? false),
            'submission_deadline' => (string) ($s['submission_deadline'] ?? ''),
        ];
    }
}
