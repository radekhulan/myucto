<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Payment;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Insurance\EmployerSocialInsuranceAllocation;
use MyInvoice\Service\Payroll\Posting\PayrollPostingLineBuilder;
use PDO;

/**
 * Rozpad nákladu zákonného pojištění odpovědnosti (548) na pracovní vztahy,
 * jejich střediska a firemní dimenze.
 *
 * Pojistné je jedna firemní částka za čtvrtletí. Na vztahy se dělí poměrem
 * jejich vyměřovacích základů za jednotlivé měsíce čtvrtletí (metoda
 * největšího zbytku, součet na haléř) a podíl vztahu se dál dělí mezi části
 * vztahu podle jeho mzdových dimenzí ze zmrazeného snapshotu revize toho
 * měsíce. Druhý krok je tentýž, kterým se dělí pojistné zaměstnavatele na 524
 * ({@see PayrollPostingLineBuilder::employmentCostShares()}).
 *
 * Fail-soft stejně jako rozpad 524: co rozdělit nejde (nulové základy, revize
 * bez snapshotu, neplatný rozpad dimenzí), zůstane jednou částí bez dimenzí.
 * Analytika je navíc, zaúčtování předpisu kvůli ní nesmí spadnout.
 */
final class PayrollAccidentInsuranceCostAllocation
{
    public function __construct(
        private readonly PayrollPostingLineBuilder $lineBuilder,
        private readonly Connection $db,
    ) {}

    /**
     * @param int $amountMinor nezáporná částka k rozdělení
     * @return list<array{employment_id:?int,cost_center:?string,dimensions:array<int,int>,amount_minor:int}>
     *         jen nenulové části; firma bez dimenzí dostane jedinou část bez dimenzí
     */
    public function allocate(int $supplierId, PayrollAccidentInsuranceCostKey $key, int $amountMinor): array
    {
        if ($amountMinor < 0) {
            throw new \InvalidArgumentException('Rozdělované pojistné nesmí být záporné.');
        }
        if ($amountMinor === 0) {
            return [];
        }
        $plain = [['employment_id' => null, 'cost_center' => null, 'dimensions' => [], 'amount_minor' => $amountMinor]];

        /** @var list<array{month:int,employment_id:?int}> $units */
        $units = [];
        $weights = [];
        foreach ($key->months as $month => $data) {
            foreach ($data['employment_bases'] as $employmentId => $base) {
                if ($base > 0) {
                    $units[] = ['month' => $month, 'employment_id' => $employmentId];
                    $weights[] = $base;
                }
            }
            if ($data['unattributed_base'] > 0) {
                $units[] = ['month' => $month, 'employment_id' => null];
                $weights[] = $data['unattributed_base'];
            }
        }
        if ($weights === []) {
            return $plain;
        }
        try {
            $shares = EmployerSocialInsuranceAllocation::allocateByWeights(
                $weights,
                $amountMinor,
                'zákonné pojištění odpovědnosti',
            );
        } catch (\DomainException|\InvalidArgumentException) {
            return $plain;
        }

        /** @var array<int,array<int,int>> $byMonth měsíc → employment_id → částka */
        $byMonth = [];
        $parts = [];
        foreach ($units as $index => $unit) {
            $share = $shares[$index] ?? 0;
            if ($share === 0) {
                continue;
            }
            if ($unit['employment_id'] === null) {
                $parts[] = ['employment_id' => null, 'cost_center' => null, 'dimensions' => [], 'amount_minor' => $share];
                continue;
            }
            $byMonth[$unit['month']][$unit['employment_id']] = $share;
        }

        $snapshots = $this->snapshots($supplierId, array_values(array_filter(
            array_map(static fn (array $month): ?int => $month['revision_id'], $key->months),
            static fn (?int $id): bool => $id !== null,
        )));
        foreach ($byMonth as $month => $monthShares) {
            $revisionId = $key->months[$month]['revision_id'];
            $snapshot = $revisionId === null ? null : ($snapshots[$revisionId] ?? null);
            $debits = null;
            if ($snapshot !== null) {
                try {
                    $debits = $this->lineBuilder->employmentCostShares(
                        $snapshot,
                        'accident-insurance',
                        $monthShares,
                    );
                } catch (\DomainException|\InvalidArgumentException) {
                    $debits = null;
                }
            }
            if ($debits === null) {
                foreach ($monthShares as $employmentId => $share) {
                    $parts[] = ['employment_id' => $employmentId, 'cost_center' => null, 'dimensions' => [], 'amount_minor' => $share];
                }
                continue;
            }
            foreach ($debits as $debit) {
                if ($debit['signed_minor'] === 0) {
                    continue;
                }
                $parts[] = [
                    'employment_id' => $debit['employment_id'],
                    'cost_center' => $debit['cost_center'],
                    'dimensions' => $debit['dimensions'],
                    'amount_minor' => $debit['signed_minor'],
                ];
            }
        }

        return array_sum(array_column($parts, 'amount_minor')) === $amountMinor ? $parts : $plain;
    }

    /**
     * Části sečtené podle střediska a firemních dimenzí — tvar řádků deníku.
     * Pořadí je stabilní (podle klíče analytiky), aby opakované zaúčtování dalo
     * tytéž řádky.
     *
     * @param list<array{employment_id:?int,cost_center:?string,dimensions:array<int,int>,amount_minor:int}> $parts
     * @return list<array{cost_center:?string,dimensions:array<int,int>,amount_minor:int}>
     */
    public static function byAnalytics(array $parts): array
    {
        $grouped = [];
        foreach ($parts as $part) {
            $dimensions = $part['dimensions'];
            ksort($dimensions, SORT_NUMERIC);
            $key = ($part['cost_center'] ?? "\0") . "\x01" . json_encode($dimensions, JSON_THROW_ON_ERROR);
            $grouped[$key] ??= ['cost_center' => $part['cost_center'], 'dimensions' => $dimensions, 'amount_minor' => 0];
            $grouped[$key]['amount_minor'] += $part['amount_minor'];
        }
        ksort($grouped, SORT_STRING);

        return array_values(array_filter(
            $grouped,
            static fn (array $group): bool => $group['amount_minor'] !== 0,
        ));
    }

    /**
     * Vstupní snapshoty revizí čtvrtletí jedním dotazem.
     *
     * @param list<int> $revisionIds
     * @return array<int,array<string,mixed>>
     */
    private function snapshots(int $supplierId, array $revisionIds): array
    {
        $revisionIds = array_values(array_unique($revisionIds));
        if ($revisionIds === []) {
            return [];
        }
        $statement = $this->db->pdo()->prepare(
            'SELECT id, input_snapshot_json
               FROM payroll_run_revisions
              WHERE supplier_id = ?
                AND id IN (' . implode(',', array_fill(0, count($revisionIds), '?')) . ')'
        );
        $statement->execute([$supplierId, ...$revisionIds]);
        $snapshots = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $decoded = is_string($row['input_snapshot_json'])
                ? json_decode($row['input_snapshot_json'], true)
                : null;
            if (is_array($decoded) && !array_is_list($decoded)) {
                $snapshots[(int) $row['id']] = $decoded;
            }
        }

        return $snapshots;
    }
}
