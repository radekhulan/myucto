<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Obligations;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Report\CzechWorkingDays;
use MyInvoice\Service\Report\VatClassificationMapper;
use MyInvoice\Service\Tax\Return\TaxReturnException;
use MyInvoice\Service\Tax\Return\TaxReturnService;
use MyInvoice\Service\Vat\VatStatusService;
use PDO;

final class ExistingObligationSourceService
{
    public function __construct(
        private readonly Connection $db,
        private readonly VatClassificationMapper $vatMapper,
        private readonly TaxReturnService $taxReturns,
    ) {}

    /** @return list<array<string, mixed>> */
    public function taxAdvances(int $supplierId, string $fromDue, string $toDue): array
    {
        self::validateRange($supplierId, $fromDue, $toDue);
        $statement = $this->db->pdo()->prepare(
            'SELECT id, taxpayer_type, advance_kind, period_year, amount, paid_amount,
                    due_date, status, created_at
               FROM tax_advance_schedules
              WHERE supplier_id = ? AND due_date BETWEEN ? AND ? AND amount > 0
              ORDER BY due_date, id'
        );
        $statement->execute([$supplierId, $fromDue, $toDue]);

        $items = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $amount = round((float) $row['amount'], 2);
            $paid = round((float) $row['paid_amount'], 2);
            if ($paid < 0) {
                throw new \UnexpectedValueException('Úhrada daňové zálohy je záporná.');
            }
            $kind = (string) $row['advance_kind'];
            $type = (string) $row['taxpayer_type'];
            $items[] = [
                'id' => 'tax_advance:' . $row['id'],
                'source_kind' => 'tax_advance',
                'source_id' => (int) $row['id'],
                'side' => 'payable',
                'kind' => $kind === 'tax' && $type === 'po' ? 'dppo_advance' : $kind . '_advance',
                'title' => self::taxTitle($kind, $type),
                'partner_name' => null,
                'issued_on' => substr((string) $row['created_at'], 0, 10),
                'due_on' => (string) $row['due_date'],
                'currency' => 'CZK',
                'amount' => $amount,
                'paid_amount' => $paid,
                'remaining' => round(max(0.0, $amount - $paid), 2),
                'status' => self::status($amount, $paid, (string) $row['due_date']),
                'certainty' => 'scheduled',
                'editable' => false,
                'document_count' => 0,
                'source_url' => '/reports/income-tax?year=' . (int) $row['period_year'] . '&tab=zalohy',
            ];
        }
        return $items;
    }

    /**
     * Caller must require payroll.payments READ before invoking this method.
     * Employee and recipient details are deliberately excluded from the shared view.
     * @return list<array<string, mixed>>
     */
    public function payrollLiabilities(int $supplierId, string $fromDue, string $toDue): array
    {
        self::validateRange($supplierId, $fromDue, $toDue);
        $statement = $this->db->pdo()->prepare(
            'SELECT liability.id, liability.liability_kind, liability.direction,
                    liability.due_on, liability.currency_code, liability.amount_minor,
                    liability.created_at, run.period_start,
                    COALESCE(settlement.settled_minor, 0) AS settled_minor
               FROM payroll_payment_liabilities liability
               JOIN payroll_run_revisions revision
                 ON revision.supplier_id = liability.supplier_id
                AND revision.id = liability.revision_id
               JOIN payroll_runs run
                 ON run.supplier_id = revision.supplier_id AND run.id = revision.run_id
          LEFT JOIN (
                    SELECT supplier_id, liability_id, SUM(amount_minor) AS settled_minor
                      FROM payroll_payment_matches
                     WHERE supplier_id = ?
                     GROUP BY supplier_id, liability_id
               ) settlement
                 ON settlement.supplier_id = liability.supplier_id
                AND settlement.liability_id = liability.id
              WHERE liability.supplier_id = ?
                AND liability.due_on BETWEEN ? AND ?
              ORDER BY liability.due_on, liability.id'
        );
        $statement->execute([$supplierId, $supplierId, $fromDue, $toDue]);

        $items = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $amountMinor = (int) $row['amount_minor'];
            $settledMinor = (int) $row['settled_minor'];
            if ($amountMinor <= 0 || $settledMinor < 0 || $settledMinor > $amountMinor) {
                throw new \UnexpectedValueException('Úhrada mzdového závazku je mimo předepsanou částku.');
            }
            $amount = $amountMinor / 100;
            $paid = $settledMinor / 100;
            $direction = (string) $row['direction'];
            if (!in_array($direction, ['outgoing', 'incoming'], true)) {
                throw new \UnexpectedValueException('Neplatný směr mzdového závazku.');
            }
            $period = substr((string) $row['period_start'], 0, 7);
            $items[] = [
                'id' => 'payroll:' . $row['id'],
                'source_kind' => 'payroll',
                'source_id' => (int) $row['id'],
                'side' => $direction === 'incoming' ? 'receivable' : 'payable',
                'kind' => 'payroll_' . $row['liability_kind'],
                'title' => $direction === 'incoming' ? 'Mzdová vratka' : 'Mzdový závazek',
                'partner_name' => null,
                'issued_on' => substr((string) $row['created_at'], 0, 10),
                'due_on' => (string) $row['due_on'],
                'currency' => (string) $row['currency_code'],
                'amount' => $amount,
                'paid_amount' => $paid,
                'remaining' => ($amountMinor - $settledMinor) / 100,
                'status' => self::status($amount, $paid, (string) $row['due_on']),
                'certainty' => 'confirmed',
                'editable' => false,
                'document_count' => 0,
                'source_url' => '/payroll/payments?period=' . $period,
            ];
        }
        return $items;
    }

    /**
     * Caller must require payroll.payments READ. The estimate contains no employee data.
     * An approved liability for the same payroll period and kind replaces the estimate.
     * @return list<array<string, mixed>>
     */
    public function payrollForecasts(int $supplierId, string $fromDue, string $toDue): array
    {
        self::validateRange($supplierId, $fromDue, $toDue);
        $pdo = $this->db->pdo();
        $currentPeriod = date('Y-m-01');
        $latest = $pdo->prepare(
            'SELECT MAX(run.period_start)
               FROM payroll_payment_liabilities liability
               JOIN payroll_run_revisions revision
                 ON revision.supplier_id = liability.supplier_id AND revision.id = liability.revision_id
               JOIN payroll_runs run
                 ON run.supplier_id = revision.supplier_id AND run.id = revision.run_id
              WHERE liability.supplier_id = ? AND run.period_start <= ?
                AND run.status <> "cancelled"'
        );
        $latest->execute([$supplierId, $currentPeriod]);
        $sourcePeriod = $latest->fetchColumn();
        if (!is_string($sourcePeriod) || $sourcePeriod === '') {
            return [];
        }
        if ($sourcePeriod < (new \DateTimeImmutable($currentPeriod))->modify('-3 months')->format('Y-m-01')) {
            return [];
        }
        $source = $pdo->prepare(
            'SELECT run.id AS run_id, liability.liability_reference,
                    liability.liability_kind, liability.direction, liability.due_on,
                    liability.currency_code, liability.amount_minor
               FROM payroll_payment_liabilities liability
               JOIN payroll_run_revisions revision
                 ON revision.supplier_id = liability.supplier_id AND revision.id = liability.revision_id
               JOIN payroll_runs run
                 ON run.supplier_id = revision.supplier_id AND run.id = revision.run_id
              WHERE liability.supplier_id = ? AND run.period_start = ?
                AND run.status <> "cancelled"
                AND liability.liability_kind IN
                    ("net_wage", "social_insurance", "health_insurance",
                     "advance_tax", "withholding_tax", "statutory_insurance")
              ORDER BY revision.revision_no, liability.id'
        );
        $source->execute([$supplierId, $sourcePeriod]);
        $positions = [];
        $sourceDate = new \DateTimeImmutable($sourcePeriod);
        foreach ($source->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = implode(':', [$row['run_id'], $row['liability_kind'],
                $row['currency_code'], $row['liability_reference']]);
            $positions[$key] ??= ['kind' => (string) $row['liability_kind'],
                'currency' => (string) $row['currency_code'], 'due_on' => '', 'minor' => 0];
            $positions[$key]['due_on'] = (string) $row['due_on'];
            $positions[$key]['minor'] += $row['direction'] === 'incoming'
                ? -(int) $row['amount_minor']
                : (int) $row['amount_minor'];
        }
        $templates = [];
        foreach ($positions as $position) {
            if ($position['minor'] <= 0) {
                continue;
            }
            $due = new \DateTimeImmutable($position['due_on']);
            $offset = ((int) $due->format('Y') - (int) $sourceDate->format('Y')) * 12
                + (int) $due->format('n') - (int) $sourceDate->format('n');
            if ($offset < 0 || $offset > 3) {
                continue;
            }
            $kind = $position['kind'];
            $currency = $position['currency'];
            $day = (int) $due->format('j');
            $key = implode(':', [$kind, $currency, $offset, $day]);
            $templates[$key] ??= ['kind' => $kind, 'currency' => $currency,
                'offset' => $offset, 'day' => $day, 'minor' => 0];
            $templates[$key]['minor'] += $position['minor'];
        }
        if ($templates === []) {
            return [];
        }

        $existing = $pdo->prepare(
            'SELECT run.period_start, liability.liability_kind
               FROM payroll_payment_liabilities liability
               JOIN payroll_run_revisions revision
                 ON revision.supplier_id = liability.supplier_id AND revision.id = liability.revision_id
               JOIN payroll_runs run
                 ON run.supplier_id = revision.supplier_id AND run.id = revision.run_id
              WHERE liability.supplier_id = ? AND run.period_start > ?
                AND run.period_start <= ? AND run.status <> "cancelled"
              GROUP BY run.period_start, liability.liability_kind'
        );
        $lastPeriod = (new \DateTimeImmutable($currentPeriod))->modify('+3 months')->format('Y-m-01');
        $existing->execute([$supplierId, $sourcePeriod, $lastPeriod]);
        $confirmed = [];
        foreach ($existing->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $confirmed[(string) $row['period_start'] . ':' . $row['liability_kind']] = true;
        }

        $items = [];
        for ($months = 0; $months <= 3; ++$months) {
            $period = (new \DateTimeImmutable($currentPeriod))->modify('+' . $months . ' months');
            $periodStart = $period->format('Y-m-01');
            if ($periodStart <= $sourcePeriod) {
                continue;
            }
            foreach ($templates as $template) {
                if (isset($confirmed[$periodStart . ':' . $template['kind']])) {
                    continue;
                }
                if ($template['minor'] <= 0) {
                    continue;
                }
                $dueMonth = $period->modify('+' . $template['offset'] . ' months');
                $day = min($template['day'], (int) $dueMonth->format('t'));
                $due = $dueMonth->setDate(
                    (int) $dueMonth->format('Y'), (int) $dueMonth->format('n'), $day
                )->format('Y-m-d');
                if ($due < $fromDue || $due > $toDue) {
                    continue;
                }
                $amount = $template['minor'] / 100;
                $items[] = [
                    'id' => 'payroll_forecast:' . $period->format('Y-m') . ':'
                        . $template['kind'] . ':' . $template['offset'] . ':' . $template['day'],
                    'source_kind' => 'payroll_forecast',
                    'source_id' => $sourcePeriod,
                    'side' => 'payable',
                    'kind' => 'payroll_' . $template['kind'],
                    'title' => 'Odhad mzdové platby',
                    'partner_name' => null,
                    'issued_on' => null,
                    'due_on' => $due,
                    'currency' => $template['currency'],
                    'amount' => $amount,
                    'paid_amount' => 0.0,
                    'remaining' => $amount,
                    'status' => 'forecast',
                    'certainty' => 'estimate',
                    'editable' => false,
                    'document_count' => 0,
                    'source_url' => '/payroll/payments?period=' . $period->format('Y-m'),
                ];
            }
        }
        usort($items, static fn (array $a, array $b): int => [$a['due_on'], $a['id']] <=> [$b['due_on'], $b['id']]);
        return $items;
    }

    /** @return array<string, mixed>|null */
    public function vatForecastForPeriod(
        int $supplierId, int $year, int $month, ?string $fromDue = null, ?string $toDue = null,
    ): ?array
    {
        if ($supplierId <= 0 || $year < 2020 || $year > 2100 || $month < 1 || $month > 12) {
            throw new \InvalidArgumentException('Neplatné období predikce DPH.');
        }
        $settings = $this->db->pdo()->prepare('SELECT vat_period FROM supplier WHERE id = ?');
        $settings->execute([$supplierId]);
        $vatPeriod = $settings->fetchColumn();
        if ($vatPeriod === false) {
            return null;
        }
        $period = $vatPeriod === 'quarterly' ? 'quarterly' : 'monthly';
        $monthlyEnd = (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))
            ->modify('last day of this month')->format('Y-m-d');
        $monthlyFlags = VatStatusService::flagsAt($this->db->pdo(), $supplierId, $monthlyEnd);
        if ($monthlyFlags['is_identified'] && !$monthlyFlags['is_vat_payer']) {
            $period = 'monthly';
        }
        $lastMonth = $period === 'quarterly' ? (int) (ceil($month / 3) * 3) : $month;
        $periodEnd = (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $lastMonth)))
            ->modify('last day of this month')->format('Y-m-d');
        $flags = VatStatusService::flagsAt($this->db->pdo(), $supplierId, $periodEnd);
        if (!$flags['is_vat_payer'] && !$flags['is_identified']) {
            return null;
        }
        $next = (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $lastMonth)))
            ->modify('+1 month');
        $due = CzechWorkingDays::deadline((int) $next->format('Y'), (int) $next->format('n'));
        $refundDue = (new \DateTimeImmutable($due))->modify('+30 days')->format('Y-m-d');
        if (($fromDue !== null && $refundDue < $fromDue)
            || ($toDue !== null && $due > $toDue)) {
            return null;
        }
        $firstMonth = $period === 'quarterly' ? $lastMonth - 2 : $lastMonth;
        $posted = $this->db->pdo()->prepare(
            'SELECT 1 FROM vat_clearing_runs
              WHERE supplier_id = ? AND period_year = ? AND period_first_month = ?
                AND period_type = ? AND status = "posted" LIMIT 1'
        );
        $posted->execute([$supplierId, $year, $firstMonth, $period]);
        if ($posted->fetchColumn()) {
            return null;
        }
        $periodStart = sprintf('%04d-%02d-01', $year, $firstMonth);
        if (!$this->hasPotentialVatDocuments($supplierId, $periodStart, $next->format('Y-m-d'))) {
            return null;
        }
        $prediction = $this->vatMapper->predictDph($supplierId, $year, $lastMonth, $period);
        $net = round((float) $prediction['tax_due'], 2);
        if ($net === 0.0) {
            return null;
        }
        $receivable = $net < 0;
        $amount = abs($net);
        // Termín inkasa je pouze odhad: vratka běží až od vyměření nadměrného odpočtu.
        $cashDate = $receivable ? $refundDue : $due;
        if ($cashDate < date('Y-m-d')) {
            return null;
        }
        $periodKey = sprintf('%04d-%02d', $year, $firstMonth);
        return [
            'id' => 'vat_forecast:' . $period . ':' . $periodKey,
            'source_kind' => 'vat_forecast',
            'source_id' => $periodKey,
            'side' => $receivable ? 'receivable' : 'payable',
            'kind' => $receivable ? 'vat_refund' : 'vat',
            'title' => $receivable ? 'Odhad vratky DPH' : 'Odhad DPH k odvodu',
            'partner_name' => null,
            'issued_on' => null,
            'due_on' => $cashDate,
            'currency' => 'CZK',
            'amount' => $amount,
            'paid_amount' => 0.0,
            'remaining' => $amount,
            'status' => 'forecast',
            'certainty' => 'estimate',
            'editable' => false,
            'document_count' => 0,
            'source_url' => '/reports/dph?year=' . $year . '&month=' . $lastMonth,
        ];
    }

    private function hasPotentialVatDocuments(int $supplierId, string $start, string $endExclusive): bool
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT EXISTS (
                SELECT 1 FROM invoices i
                 WHERE i.supplier_id = ? AND i.effective_tax_date >= ? AND i.effective_tax_date < ?
             ) OR EXISTS (
                SELECT 1 FROM purchase_invoices pi
                 WHERE pi.supplier_id = ?
                   AND ((pi.tax_date >= ? AND pi.tax_date < ?)
                     OR (pi.issue_date >= ? AND pi.issue_date < ?)
                     OR (pi.received_at >= ? AND pi.received_at < ?))
             ) OR EXISTS (
                SELECT 1 FROM cash_documents cd
                 WHERE cd.supplier_id = ?
                   AND ((cd.tax_date >= ? AND cd.tax_date < ?)
                     OR (cd.issue_date >= ? AND cd.issue_date < ?))
             )'
        );
        $statement->execute([
            $supplierId, $start, $endExclusive,
            $supplierId, $start, $endExclusive, $start, $endExclusive, $start, $endExclusive,
            $supplierId, $start, $endExclusive, $start, $endExclusive,
        ]);
        return (bool) $statement->fetchColumn();
    }

    /** @return array<string, mixed>|null */
    public function dppoForecastForYear(
        int $supplierId, int $year, ?string $fromDue = null, ?string $toDue = null,
    ): ?array
    {
        if ($supplierId <= 0 || $year < 2020 || $year > 2100) {
            throw new \InvalidArgumentException('Neplatný rok predikce DPPO.');
        }
        $settings = $this->db->pdo()->prepare('SELECT taxpayer_type, accounting_mode FROM supplier WHERE id = ?');
        $settings->execute([$supplierId]);
        $supplier = $settings->fetch(PDO::FETCH_ASSOC);
        if (!is_array($supplier) || $supplier['taxpayer_type'] !== 'po'
            || $supplier['accounting_mode'] !== 'double_entry') {
            return null;
        }
        $draft = $this->db->pdo()->prepare(
            'SELECT status, JSON_VALUE(inputs, "$.filing_deadline") AS filing_deadline
               FROM income_tax_returns
              WHERE supplier_id = ? AND year = ? AND taxpayer_type = "po"
                AND variant = "radne" AND variant_seq = 1'
        );
        $draft->execute([$supplierId, $year]);
        $row = $draft->fetch(PDO::FETCH_ASSOC);
        $input = is_array($row) ? (string) ($row['filing_deadline'] ?? '') : '';
        if (!is_array($row) || $row['status'] !== 'draft' || !self::validDate($input)
            || $input < date('Y-m-d')
            || ($fromDue !== null && $input < $fromDue)
            || ($toDue !== null && $input > $toDue)) {
            return null;
        }
        try {
            $preview = $this->taxReturns->balanceDueReadOnly($supplierId, $year, 'po');
        } catch (TaxReturnException $e) {
            if (!in_array($e->errorCode, ['dppo_accounting_framework_not_supported','nonprofit_dppo_ruleset_not_supported'], true)) {
                throw $e;
            }
            return null;
        }
        if ($preview === null || $preview['status'] !== 'draft' || $preview['balance_due'] <= 0) {
            return null;
        }
        $input = (string) $preview['filing_deadline_input'];
        if (!self::validDate($input)) {
            return null;
        }
        $due = $input;
        if ($due < date('Y-m-d')) {
            return null;
        }
        $amount = round((float) $preview['balance_due'], 2);
        return [
            'id' => 'dppo_forecast:' . $year,
            'source_kind' => 'dppo_forecast',
            'source_id' => $year,
            'side' => 'payable',
            'kind' => 'dppo_balance',
            'title' => 'Odhad doplatku DPPO',
            'partner_name' => null,
            'issued_on' => null,
            'due_on' => $due,
            'currency' => 'CZK',
            'amount' => $amount,
            'paid_amount' => 0.0,
            'remaining' => $amount,
            'status' => 'forecast',
            'certainty' => 'estimate',
            'editable' => false,
            'document_count' => 0,
            'source_url' => '/reports/income-tax?year=' . $year . '&tab=nahled',
        ];
    }

    /** @return list<array<string,mixed>> */
    public function taxForecasts(int $supplierId, string $fromDue, string $toDue): array
    {
        self::validateRange($supplierId, $fromDue, $toDue);
        $start = (new \DateTimeImmutable($fromDue))->modify('first day of this month')->modify('-3 months');
        $end = new \DateTimeImmutable($toDue);
        $seen = [];
        $items = [];
        for ($month = $start; $month <= $end; $month = $month->modify('+1 month')) {
            $item = $this->vatForecastForPeriod(
                $supplierId, (int) $month->format('Y'), (int) $month->format('n'), $fromDue, $toDue,
            );
            if ($item !== null && $item['due_on'] >= $fromDue && $item['due_on'] <= $toDue
                && !isset($seen[$item['id']])) {
                $seen[$item['id']] = true;
                $items[] = $item;
            }
        }
        for ($year = (int) $start->format('Y') - 1; $year <= (int) $end->format('Y'); ++$year) {
            $item = $this->dppoForecastForYear($supplierId, $year, $fromDue, $toDue);
            if ($item !== null && $item['due_on'] >= $fromDue && $item['due_on'] <= $toDue) {
                $items[] = $item;
            }
        }
        usort($items, static fn (array $a, array $b): int => [$a['due_on'], $a['id']] <=> [$b['due_on'], $b['id']]);
        return $items;
    }

    private static function taxTitle(string $kind, string $type): string
    {
        return match ($kind) {
            'tax' => $type === 'po' ? 'Záloha na DPPO' : 'Záloha na DPFO',
            'social' => 'Záloha na sociální pojištění',
            'health' => 'Záloha na zdravotní pojištění',
            default => throw new \UnexpectedValueException('Neplatný druh daňové zálohy.'),
        };
    }

    private static function status(float $amount, float $paid, string $dueOn): string
    {
        if ($paid >= $amount) return 'paid';
        if ($paid > 0) return 'partial';
        return $dueOn < date('Y-m-d') ? 'overdue' : 'open';
    }

    private static function validateRange(int $supplierId, string $fromDue, string $toDue): void
    {
        if ($supplierId <= 0 || !self::validDate($fromDue) || !self::validDate($toDue) || $fromDue > $toDue) {
            throw new \InvalidArgumentException('Neplatná firma nebo rozsah splatností.');
        }
    }

    private static function validDate(string $date): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }
}
