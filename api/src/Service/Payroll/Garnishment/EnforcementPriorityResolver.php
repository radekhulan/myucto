<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Garnishment;

final class EnforcementPriorityResolver
{
    /**
     * @param list<DeductionClaim> $claims
     * @return list<list<DeductionClaim>>
     */
    public function resolve(array $claims): array
    {
        /** @var array<string, array{priority_date:?string, claims:list<DeductionClaim>}> $orders */
        $orders = [];
        foreach ($claims as $claim) {
            if (!$claim->active || $claim->outstandingMinorUnits <= 0) {
                continue;
            }

            $orderKey = $this->orderKey($claim);
            if (!isset($orders[$orderKey])) {
                $orders[$orderKey] = [
                    'priority_date' => $claim->priorityDate,
                    'claims' => [],
                ];
            } elseif ($this->dateKey($claim->priorityDate)
                < $this->dateKey($orders[$orderKey]['priority_date'])
            ) {
                $orders[$orderKey]['priority_date'] = $claim->priorityDate;
            }
            $orders[$orderKey]['claims'][] = $claim;
        }

        uksort(
            $orders,
            function (string $left, string $right) use ($orders): int {
                $dateOrder = $this->dateKey($orders[$left]['priority_date'])
                    <=> $this->dateKey($orders[$right]['priority_date']);

                return $dateOrder !== 0 ? $dateOrder : $left <=> $right;
            },
        );

        $groups = [];
        $groupDate = null;
        foreach ($orders as $order) {
            $priorityDate = $this->dateKey($order['priority_date']);
            if ($groups === [] || $priorityDate !== $groupDate) {
                $groups[] = [];
                $groupDate = $priorityDate;
            }
            usort(
                $order['claims'],
                static fn (DeductionClaim $left, DeductionClaim $right): int =>
                    $left->id <=> $right->id,
            );
            array_push($groups[array_key_last($groups)], ...$order['claims']);
        }

        return $groups;
    }

    /**
     * @param list<DeductionClaim> $claims
     * @return list<DeductionClaim>
     */
    public function orderedActiveClaims(array $claims): array
    {
        $ordered = [];
        foreach ($this->resolve($claims) as $group) {
            array_push($ordered, ...$group);
        }

        return $ordered;
    }

    private function orderKey(DeductionClaim $claim): string
    {
        $orderId = trim((string) $claim->enforcementOrderId);

        return $orderId !== '' ? "order:\0{$orderId}" : "claim:\0{$claim->id}";
    }

    private function dateKey(?string $priorityDate): string
    {
        return $priorityDate ?? '';
    }
}
