<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Property\Services\Settlement;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Property\Payments\{PaymentLocks, ReliefCalculation};
use MyInvoice\Service\Property\PropertyInput;
use MyInvoice\Service\Property\Services\Settlement\{SettlementAmounts, SettlementEvidence, SettlementOriginAdapter};

final class SettlementDispositionReadRepository
{
    public function __construct(private readonly Connection $db, private readonly SettlementDocumentRepository $documents, private readonly SettlementOriginAdapter $origins) {}

    public function current(int $supplier, int $id, string $date): array
    {
        PropertyInput::id($id, 'document_id');
        SettlementEvidence::date($date);
        return (new DimensionPeriodGuard($this->db))->transaction(function () use ($supplier, $id, $date): array {
            (new PaymentLocks($this->db))->acquire($supplier);
            $document = $this->documents->find($supplier, $id, true);
            $facts = $document['document'];
            $q = $this->db->pdo()->prepare('SELECT row_version,application_id FROM property_service_settlement_heads WHERE supplier_id=? AND document_id=? FOR UPDATE');
            $q->execute([$supplier, $id]);
            $head = $q->fetch(\PDO::FETCH_ASSOC) ?: ['row_version' => 0, 'application_id' => null];
            if ($head['application_id'] !== null) SettlementEvidence::reject('already_applied');
            $live = $this->origins->current($supplier, $facts['unit_id'], $facts['service_id'], $date, $document['origin_receipt']['request']['selectors']);
            if (PropertyInput::hash($live['origins']) !== PropertyInput::hash($facts['origins'])) SettlementEvidence::reject('document_origin_changed');
            if (count(array_filter($live['costs'], fn(array $cost): bool => PropertyInput::hash($cost) === PropertyInput::hash($facts['cost']))) !== 1) SettlementEvidence::reject('document_cost_changed');
            $cutoff = array_fill_keys(array_column($facts['cutoff_allocations'], 'id'), true);
            $paid = []; $allocations = []; $total = '0'; $origins = [];
            foreach ($live['allocations'] as $allocation) {
                if ($allocation['active']) {
                    $key = $allocation['origin_key'];
                    $paid[$key] = bcadd($paid[$key] ?? '0', $allocation['amount_cents'], 0);
                }
                $allocations[] = array_intersect_key($allocation, array_flip(['id', 'origin_key', 'accounting_date', 'amount_cents', 'active'])) + ['at_cutoff' => isset($cutoff[$allocation['id']])];
            }
            foreach ($live['origins'] as $origin) {
                $key = $origin['kind'].':'.$origin['id'];
                $current = ReliefCalculation::calculate($origin['receivable_cents'], '0', $paid[$key] ?? '0', '0');
                $total = bcadd($total, bcadd($origin['carried_paid_cents'], $current['paid_cents'], 0), 0);
                $origins[] = ['origin_key' => $key, 'kind' => $origin['kind'], 'prescribed_cents' => $origin['prescribed_cents'], 'carried_paid_cents' => $origin['carried_paid_cents']] + array_intersect_key($current, array_flip(['principal_cents', 'paid_cents', 'unpaid_cents']));
            }
            $accounts = array_values(array_filter((new ChartOfAccountsRepository($this->db))->listForTenant($supplier), fn(array $a): bool => ($a['account_type'] === 'asset' && str_starts_with($a['account_code'], '315')) || ($a['account_type'] === 'liability' && $a['account_code'] === '379.910')));
            return ['document_id' => $id, 'row_version' => (int)$head['row_version'], 'accounting_date' => $date, 'current_paid_cents' => $total, 'amounts' => SettlementAmounts::calculate($facts['prescribed_cents'], $total, $facts['actual_cents']), 'origins' => $origins, 'allocations' => $allocations, 'accounts' => $accounts, 'write_authorization' => false];
        });
    }
}
