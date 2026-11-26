<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Reports;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\AccountingPeriodStatus;
use MyInvoice\Service\Accounting\Closing\ClosingSourceId;
use MyInvoice\Service\Tax\Return\TaxReturnException;
use MyInvoice\Service\Tax\Return\TaxReturnService;

/**
 * Odhad výsledku a daně z příjmů do konce otevřeného roku pro pohled výsledovky po účtech.
 *
 * Nic nepočítá sám, jen skládá čísla existujících služeb:
 *   - VH, nezaúčtované uzávěrkové operace, základ a daň: náhled DPPO
 *     ({@see TaxReturnService::previewReadOnly()} → DppoReturnDataProvider +
 *     ClosingProjectionCalculator + DppoReturnCalculator), tedy stejná čísla jako stránka
 *     náhledu přiznání včetně ručních úprav, ztráty, darů a slev;
 *   - zaplacené zálohy: tatáž hodnota, se kterou počítá náhled (ruční vstup přiznání,
 *     jinak jisté spárované zálohy z evidence §38a);
 *   - nezaúčtované odpisy roku jsou položkou téže projekce uzávěrky (účetní snižují VH,
 *     rozdíl proti daňovým jde do ř. 50/150), takže je blok i náhled DPPO mají stejně.
 *
 * Blok má smysl jen pro právnickou osobu v roce, který ještě nemá zaúčtovanou daň
 * z příjmů (591) a není uzavřený. Daň fyzické osoby není nákladem podniku, takže
 * „VH po zdanění" u ní nedává smysl a blok se nevrací.
 */
final class YearEndTaxEstimateService
{
    public function __construct(
        private readonly Connection $db,
        private readonly AccountingPeriodRepository $periods,
        private readonly TaxReturnService $taxReturns,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function estimate(int $supplierId, int $periodId): array
    {
        $period = $this->periods->findById($supplierId, $periodId);
        if ($period === null) {
            throw new ReportException('period_not_found', 'Účetní období #' . $periodId . ' neexistuje.', 404);
        }
        $fiscalYear = (int) $period['fiscal_year'];
        $out = [
            'applicable' => false,
            'reason' => null,
            'period' => [
                'id' => (int) $period['id'],
                'fiscal_year' => $fiscalYear,
                'starts_on' => (string) $period['starts_on'],
                'ends_on' => (string) $period['ends_on'],
                'status' => (string) $period['status'],
            ],
        ];

        if (AccountingPeriodStatus::isClosed((string) $period['status'])) {
            return ['reason' => 'period_closed'] + $out;
        }
        if ($this->taxpayerType($supplierId) === 'fo') {
            return ['reason' => 'taxpayer_fo'] + $out;
        }
        if ($this->incomeTaxPosted($supplierId, (string) $period['starts_on'], (string) $period['ends_on'])) {
            return ['reason' => 'income_tax_posted'] + $out;
        }

        try {
            $preview = $this->taxReturns->previewReadOnly($supplierId, $fiscalYear, 'po');
        } catch (TaxReturnException $e) {
            return ['reason' => 'tax_unavailable', 'message' => $e->getMessage()] + $out;
        }

        $result = $preview['result'];
        $summary = (array) ($result['summary'] ?? []);
        $projection = is_array($result['projection'] ?? null) ? $result['projection'] : null;
        $closing = is_array($preview['podklady']['closing_projection'] ?? null) ? $preview['podklady']['closing_projection'] : null;
        $isProjection = $projection !== null && ($projection['is_projection'] ?? false) === true;

        $vhPosted = round((float) ($preview['podklady']['vh'] ?? 0), 2);
        $vhBeforeTax = $isProjection ? round((float) $projection['vh_projected'], 2) : $vhPosted;
        $tax = round((float) ($isProjection ? $projection['projected_tax'] : ($result['tax'] ?? 0)), 2);
        $base = round((float) ($isProjection ? $projection['projected_base'] : ($summary['base'] ?? 0)), 2);
        $advances = round((float) ($result['advances_paid'] ?? 0), 2);

        return [
            'applicable' => true,
            'reason' => null,
            'period' => $out['period'],
            'return_status' => $preview['status'],
            'vh_posted' => $vhPosted,
            'closing_items' => array_values((array) ($closing['items'] ?? [])),
            // Kroky uzávěrky, jejichž náhled selhal: projekce je bez nich neúplná.
            'projection_warnings' => array_values((array) ($closing['warnings'] ?? [])),
            // Rozdíl účetních a daňových odpisů: karty v konceptu, odpisy na 551 mimo karty apod.
            'depreciation_warnings' => array_values(array_map('strval', (array) ($preview['podklady']['depreciation_warnings'] ?? []))),
            'is_projection' => $isProjection,
            'vh_before_tax' => $vhBeforeTax,
            'increases' => round((float) ($isProjection ? ($projection['projected_increases'] ?? $this->lineValue($result, 70)) : $this->lineValue($result, 70)), 2),
            'decreases' => round((float) ($isProjection ? ($projection['projected_decreases'] ?? $this->lineValue($result, 170)) : $this->lineValue($result, 170)), 2),
            'tax_base' => $base,
            'tax' => $tax,
            'advances_paid' => $advances,
            'advances_source' => $preview['advances_source'],
            'balance_due' => round($tax - $advances, 2),
            'vh_after_tax' => round($vhBeforeTax - $tax, 2),
        ];
    }

    /** @param array<string,mixed> $result */
    private function lineValue(array $result, int $line): float
    {
        foreach ((array) ($result['lines'] ?? []) as $l) {
            if ((int) ($l['line'] ?? 0) === $line) {
                return round((float) $l['value'], 2);
            }
        }

        return 0.0;
    }

    private function taxpayerType(int $supplierId): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT taxpayer_type FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);

        return strtolower(trim((string) $stmt->fetchColumn()));
    }

    /**
     * Leží v období zaúčtovaná daň z příjmů (591)? Uzávěrkový zápis se nepočítá — převádí
     * zůstatek 591 na 710, takže by jinak zaúčtovanou daň vynuloval.
     */
    private function incomeTaxPosted(int $supplierId, string $startsOn, string $endsOn): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(CASE WHEN l.side = 'debit' THEN l.signed_amount ELSE -l.signed_amount END), 0)
               FROM journal_entry_lines l
               JOIN journal_entries e   ON e.id = l.entry_id
               JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.supplier_id = ? AND e.posted_at IS NOT NULL
                AND e.entry_date BETWEEN ? AND ?
                AND a.account_code LIKE '591%'
                AND (e.source_type IS NULL OR e.source_type <> 'closing' OR e.source_id >= ?)"
        );
        $stmt->execute([$supplierId, $startsOn, $endsOn, ClosingSourceId::STOCK_SLOT_BASE]);

        return (int) round((float) $stmt->fetchColumn() * 100) !== 0;
    }
}
