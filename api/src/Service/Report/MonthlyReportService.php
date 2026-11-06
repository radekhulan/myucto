<?php

declare(strict_types=1);

namespace MyInvoice\Service\Report;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\Reports\FinancialStatementService;
use MyInvoice\Service\Accounting\Reports\ReportException;
use MyInvoice\Service\Accounting\Reports\SaldoService;
use MyInvoice\Service\Tax\Return\TaxAdvanceScheduleService;
use PDO;

/**
 * Měsíční přehled klientovi (Fáze F, audit 2026-07, P3 návrh) — PDF balíček nad
 * JIŽ EXISTUJÍCÍMI sestavami, žádná duplicitní účetní/daňová logika:
 *
 *   - výsledovka měsíc + YTD → FinancialStatementService::incomeStatement()
 *     (YTD = přímý výstup; „měsíc" je rozdíl dvou YTD snímků — konec měsíce
 *     minus den před jeho začátkem, viz monthOnlyRows()),
 *   - rozvaha k poslednímu dni měsíce → FinancialStatementService::balanceSheet(),
 *   - top pohledávky/závazky po splatnosti → SaldoService::build() (D6),
 *   - DPH k úhradě + termín podání → DphPriznaniBuilder::build() (jen plátci DPH,
 *     read-only náhled bez archivace do tax_submissions),
 *   - nadcházející daňové termíny → TaxAdvanceScheduleService::upcoming() (E9).
 */
final class MonthlyReportService
{
    public function __construct(
        private readonly Connection $db,
        private readonly AccountingPeriodRepository $periods,
        private readonly FinancialStatementService $statements,
        private readonly SaldoService $saldo,
        private readonly DphPriznaniBuilder $dph,
        private readonly TaxAdvanceScheduleService $advances,
    ) {}

    /** Kolik položek "top po splatnosti" zobrazit na účet (311/321). */
    private const SALDO_TOP_N = 10;

    /**
     * `$accrualSpread` = průběžné časové rozlišení mimo deník ve výsledovce i rozvaze. Měsíc je
     * dál rozdíl dvou YTD snímků, každý s rozlišením ke svému dni, takže za měsíc vyjde
     * poměrná část dokladu za ten měsíc. Rámec 504 volbu neuplatní (viz FinancialStatementService).
     *
     * @return array<string,mixed>
     * @throws ReportException 404 pokud firma nebo účetní období neexistuje
     */
    public function build(int $supplierId, int $year, int $month, ?string $comment = null, bool $accrualSpread = false): array
    {
        if ($month < 1 || $month > 12) {
            throw new ReportException('invalid_month', 'Měsíc musí být 1–12.', 422);
        }
        $monthStart = sprintf('%04d-%02d-01', $year, $month);
        $monthEndFull = (new \DateTimeImmutable($monthStart))->modify('last day of this month')->format('Y-m-d');
        $today = date('Y-m-d');
        $asOf = min($monthEndFull, $today);

        $period = $this->periods->findForDate($supplierId, $asOf);
        if ($period === null) {
            throw new ReportException('period_not_found', 'Účetní období pro ' . $asOf . ' neexistuje.', 404);
        }
        $periodId = (int) $period['id'];

        $entity = $this->loadEntity($supplierId);

        $incomeYtd = $this->statements->incomeStatement($supplierId, $periodId, $asOf, 'auto', null, $accrualSpread);
        $is504 = ($incomeYtd['accounting_framework'] ?? '') === 'cz_504';
        $prevAsOf = (new \DateTimeImmutable($monthStart))->modify('-1 day')->format('Y-m-d');
        $incomeMonth = $prevAsOf >= (string) $period['starts_on']
            ? $this->monthOnlyRows($incomeYtd, $this->statements->incomeStatement($supplierId, $periodId, $prevAsOf, 'auto', null, $accrualSpread))
            : $incomeYtd['rows']; // leden fiskálního roku = měsíc == YTD

        $balanceSheet = $is504
            ? $this->statements->accountView($supplierId, $periodId, $asOf)
            : $this->statements->balanceSheet($supplierId, $periodId, $asOf, 'auto', null, $accrualSpread);

        $saldoData = $this->saldo->build($supplierId, $periodId, $asOf);
        $receivables = $this->topOverdue($saldoData, ['311']);
        $payables = $this->topOverdue($saldoData, ['321']);

        $vat = $this->buildVatSection($supplierId, $year, $month);
        $upcomingDeadlines = $this->advances->upcoming($supplierId, 8);

        return ($is504 ? ['accounting_framework' => 'cz_504', 'view_kind' => 'management'] : []) + [
            'entity'              => $entity,
            'period'              => ['year' => $year, 'month' => $month, 'as_of' => $asOf],
            'generated_at'        => date('Y-m-d H:i'),
            'comment'             => $comment !== null && trim($comment) !== '' ? trim($comment) : null,
            'income_statement_ytd'   => $incomeYtd,
            'income_statement_month' => $incomeMonth,
            'balance_sheet'          => $balanceSheet,
            'receivables_overdue'    => $receivables,
            'payables_overdue'       => $payables,
            'vat'                    => $vat,
            'upcoming_deadlines'     => $upcomingDeadlines,
        ];
    }

    /**
     * Rozdíl dvou YTD snímků výsledovky (stejné row_code) = částka za samotný měsíc.
     * Žádná nová účetní logika — jen odečet dvou už správně spočtených kumulativních
     * sestav; row metadata (label/level/row_type) se přebírá z novějšího snímku.
     *
     * @param array<string,mixed> $ytd
     * @param array<string,mixed> $prev
     * @return list<array<string,mixed>>
     */
    private function monthOnlyRows(array $ytd, array $prev): array
    {
        if (($ytd['accounting_framework'] ?? '') === 'cz_504') {
            return \MyInvoice\Service\Accounting\Reports\Statement504PeriodComparison::difference($ytd, $prev);
        }
        $prevByCode = [];
        foreach ((array) $prev['rows'] as $r) {
            $prevByCode[(string) $r['row_code']] = (float) $r['amount'];
        }
        $out = [];
        foreach ((array) $ytd['rows'] as $r) {
            $code = (string) $r['row_code'];
            $out[] = [
                'row_code'     => $code,
                'display_code' => $r['display_code'],
                'label'        => $r['label'],
                'level'        => $r['level'],
                'row_type'     => $r['row_type'],
                'amount'       => round((float) $r['amount'] - ($prevByCode[$code] ?? 0.0), 2),
            ];
        }
        return $out;
    }

    /**
     * Top N položek po splatnosti (napříč partnery) pro dané saldokontní účty.
     *
     * @param array<string,mixed> $saldoData SaldoService::build() výstup
     * @param list<string> $accountCodes
     * @return list<array<string,mixed>>
     */
    private function topOverdue(array $saldoData, array $accountCodes): array
    {
        $items = [];
        foreach ((array) $saldoData['accounts'] as $acc) {
            if (!in_array((string) $acc['account']['code'], $accountCodes, true)) {
                continue;
            }
            foreach ((array) $acc['partners'] as $partner) {
                foreach ((array) $partner['items'] as $it) {
                    if ((int) $it['days_overdue'] <= 0) {
                        continue;
                    }
                    $items[] = $it + ['partner_name' => $partner['partner_name']];
                }
            }
        }
        usort($items, static fn (array $a, array $b): int => $b['days_overdue'] <=> $a['days_overdue']);
        return array_slice($items, 0, self::SALDO_TOP_N);
    }

    /**
     * DPH k úhradě + termín — jen pro plátce DPH. Read-only náhled (DphPriznaniBuilder::build
     * nezapisuje do DB), selhání (chybějící nastavení období apod.) se v reportu jen přeskočí,
     * ať kolaps DPH sekce neshodí zbytek přehledu.
     *
     * @return array<string,mixed>|null
     */
    private function buildVatSection(int $supplierId, int $year, int $month): ?array
    {
        // EPIC VH-04: plátcovství BĚHEM reportovaného měsíce, ne živý flag ani jen
        // poslední den — při zrušení registrace uprostřed měsíce se poslední přiznání
        // pořád podává a přehled o něm musí říct.
        $monthStart = sprintf('%04d-%02d-01', $year, $month);
        $monthEnd = (new \DateTimeImmutable($monthStart))
            ->modify('last day of this month')->format('Y-m-d');
        if (!\MyInvoice\Service\Vat\VatStatusService::payerDuring($this->db->pdo(), $supplierId, $monthStart, $monthEnd)) {
            return null;
        }
        try {
            $res = $this->dph->build($supplierId, $year, $month);
        } catch (\Throwable) {
            return null;
        }
        $summary = $res['summary'];
        return [
            'period'              => $summary['period'],
            'period_type'         => $summary['period_type'],
            'tax_due'             => $summary['tax_due'],
            'is_excess_deduction' => $summary['is_excess_deduction'],
            'submission_deadline' => $summary['submission_deadline'],
        ];
    }

    /**
     * @return array{name:string, ico:?string, address:string}
     */
    private function loadEntity(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT company_name, street, city, zip, ic FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new ReportException('supplier_not_found', 'Firma #' . $supplierId . ' neexistuje.', 404);
        }
        $address = array_filter([
            trim((string) ($row['street'] ?? '')),
            trim(trim((string) ($row['zip'] ?? '')) . ' ' . trim((string) ($row['city'] ?? ''))),
        ], static fn (string $part): bool => $part !== '');
        return [
            'name'    => (string) $row['company_name'],
            'ico'     => $row['ic'],
            'address' => implode(', ', $address),
        ];
    }
}
