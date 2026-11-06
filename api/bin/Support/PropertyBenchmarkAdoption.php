<?php

declare(strict_types=1);

namespace MyInvoice\Benchmark;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\Property\Opening\OpeningRepository;
use MyInvoice\Service\Property\Opening\OpeningReadProof;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Throwable;

final class PropertyBenchmarkAdoption
{
    private const MONTHLY_AMOUNT_CENTS = '180000';
    private const MAX_PHYSICAL_ITEMS = 4800;
    private const MAX_SOURCE_MONTHS = 120;

    public function __construct(
        private readonly Connection $db,
        private readonly ContainerInterface $container,
    ) {
    }

    public static function plan(int $units, int $months, string $firstMonth): array
    {
        if ($units < 1 || $units > 400 || $months < 1 || $months > self::MAX_SOURCE_MONTHS) {
            throw new InvalidArgumentException('Bounded adoption requires 1..400 units and 1..120 source months');
        }
        if (preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])$/D', $firstMonth) !== 1) {
            throw new InvalidArgumentException('First source month must use YYYY-MM');
        }

        $first = DateTimeImmutable::createFromFormat('!Y-m-d', $firstMonth . '-01', new DateTimeZone('Europe/Prague'));
        if (!$first instanceof DateTimeImmutable || $first->format('Y-m') !== $firstMonth) {
            throw new InvalidArgumentException('Invalid first source month');
        }

        $conceptual = $units * $months;
        if ($conceptual <= self::MAX_PHYSICAL_ITEMS) {
            $bucketMonths = 1;
            $granularity = 'monthly';
        } elseif ($months % 12 === 0 && $units * intdiv($months, 12) <= self::MAX_PHYSICAL_ITEMS) {
            $bucketMonths = 12;
            $granularity = 'annual_rollup';
        } else {
            throw new InvalidArgumentException('Source history cannot fit the bounded native adoption request');
        }

        $buckets = [];
        $cursor = $first;
        for ($covered = 0; $covered < $months; $covered += $bucketMonths) {
            $count = min($bucketMonths, $months - $covered);
            $after = $cursor->modify('+' . $count . ' months');
            $last = $after->modify('-1 day');
            $from = $cursor->format('Y-m');
            $to = $last->format('Y-m');
            $buckets[] = [
                'occurrence_ref' => $count === 1 ? $from : $from . '..' . $to,
                'period_from' => $cursor->format('Y-m-d'),
                'period_to' => $last->format('Y-m-d'),
                'source_month_count' => $count,
                'amount_cents' => bcmul(self::MONTHLY_AMOUNT_CENTS, (string) $count, 0),
            ];
            $cursor = $after;
        }

        $claims = $units * count($buckets);
        if ($claims > self::MAX_PHYSICAL_ITEMS) {
            throw new RuntimeException('Physical adoption plan exceeded its benchmark bound');
        }

        return [
            'units' => $units,
            'source_months' => $months,
            'source_first' => $firstMonth,
            'source_last' => $cursor->modify('-1 month')->format('Y-m'),
            'source_granularity' => $granularity,
            'monthly_claim_granularity' => $bucketMonths === 1,
            'claims_per_unit' => count($buckets),
            'physical_claims' => $claims,
            'conceptual_unit_months' => $conceptual,
            'total_cents' => bcmul((string) $conceptual, self::MONTHLY_AMOUNT_CENTS, 0),
            'buckets' => $buckets,
        ];
    }

    public function apply(array $fixture, int $months, string $firstMonth, string $adoptionDate = '2026-01-01'): array
    {
        $plan = self::plan(count($fixture['units']), $months, $firstMonth);
        $supplier = $fixture['supplier'];
        $actor = $fixture['actor'];
        $periods = new AccountingPeriodRepository($this->db);
        $year = (int) substr($adoptionDate, 0, 4);
        if ($periods->findByYear($supplier, $year) === null) {
            $periods->create($supplier, $year, $year . '-01-01', $year . '-12-31');
        }

        $chart = new ChartOfAccountsRepository($this->db);
        if ($chart->findByCode($supplier, '315.100') === null) {
            $parent = $chart->findByCode($supplier, '315');
            $chart->insert($supplier, [
                'account_code' => '315.100',
                'name' => 'Synthetic historical unit receivables',
                'account_type' => $parent['account_type'],
                'normal_side' => $parent['normal_side'],
                'parent_id' => $parent['id'],
            ]);
        }

        $items = [];
        foreach ($plan['buckets'] as $bucket) {
            $origin = new DateTimeImmutable($bucket['period_from'], new DateTimeZone('Europe/Prague'));
            $dueMonth = substr($bucket['period_to'], 0, 7);
            foreach ($fixture['units'] as $index => $unit) {
                $groups = [];
                foreach ($fixture['states'][$unit]['ownerships'] as $group) {
                    $groups[] = [
                        'group_key' => $group['group_key'],
                        'role' => 'debtor',
                        'mode' => $group['kind'] === 'marital_community' ? 'solidary' : 'individual',
                        'basis' => $group['kind'] === 'marital_community' ? 'sjm_713' : 'documented_title',
                        'members' => $group['members'],
                        'extent' => $group['share'],
                        'evidence_ref' => 'Synthetic assessed historical deed',
                        'legal_reason' => 'Synthetic documented historical expense reimbursement',
                        'valid_from' => $fixture['start'] . 'T00:00:00+01:00',
                        'valid_to' => null,
                    ];
                }
                $items[] = [
                    'case_ref' => sprintf('SYNTHETIC-HIST-U%03d', $index + 1),
                    'occurrence_ref' => $bucket['occurrence_ref'],
                    'kind' => 'debt',
                    'unit_id' => $unit,
                    'service_id' => null,
                    'origin_at' => $origin->format('c'),
                    'period_from' => $bucket['period_from'],
                    'period_to' => $bucket['period_to'],
                    'due_date' => $dueMonth . '-15',
                    'title_ref' => 'Synthetic historical documented reimbursement ' . $bucket['occurrence_ref'],
                    'evidence_ref' => 'Synthetic signed transfer and source-ledger rollup for ' . $bucket['source_month_count'] . ' source month(s)',
                    'liability_groups' => $groups,
                    'legal' => [
                        'basis' => 'legal_act',
                        'legal_act_date' => $bucket['period_from'],
                        'evidence' => 'Synthetic contemporary legal title',
                        'regime' => 'general',
                    ],
                    'security' => ['kind' => 'unsecured', 'evidence' => 'Synthetic documented security assessment'],
                    'reminders' => [],
                    'components' => [[
                        'key' => 'principal',
                        'role' => 'principal',
                        'account_code' => '315.100',
                        'amount_cents' => $bucket['amount_cents'],
                    ]],
                ];
            }
        }

        $effective = (new DateTimeImmutable($adoptionDate, new DateTimeZone('Europe/Prague')))->format('c');
        $request = [
            'mode' => 'post_new',
            'accounting_date' => $adoptionDate,
            'effective_at' => $effective,
            'expected_revision' => 0,
            'evidence_ref' => 'Synthetic approved native S6 transfer with explicit source periods',
            'reason' => 'Synthetic native adopted historical reimbursement balances, distinct from monthly charge generation',
            'items' => $items,
            'other_balances' => [[
                'account_code' => '932',
                'side' => 'credit',
                'amount_cents' => $plan['total_cents'],
                'dimensions' => [],
            ]],
        ];

        $owner = $this->container->get(OpeningRepository::class);
        $before = PropertyBenchmarkRead::counters($this->db->pdo());
        $started = hrtime(true);
        $preview = $owner->preview($supplier, $request);
        $previewMs = (hrtime(true) - $started) / 1e6;
        $previewHash = $preview['preview_hash'];
        unset($preview);

        $applyStarted = hrtime(true);
        $result = $owner->apply($supplier, $request, $previewHash, 'SYNTHETIC-HISTORICAL-ADOPTION', $actor);
        $applyMs = (hrtime(true) - $applyStarted) / 1e6;
        $after = PropertyBenchmarkRead::counters($this->db->pdo());
        if ($result['status'] !== 'posted' || count($result['items']) !== count($items)) {
            throw new RuntimeException('Native S6 adoption incomplete');
        }

        $delta = [];
        foreach ($after as $key => $value) {
            $delta[$key] = (int) $value - (int) $before[$key];
        }

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            (new OpeningReadProof($this->db))->batch($supplier, $result['id'], true);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $query = $pdo->prepare('SELECT COUNT(*) FROM unit_payment_debts WHERE supplier_id=? AND opening_item_id IS NOT NULL');
        $query->execute([$supplier]);
        if ((int) $query->fetchColumn() !== count($items)) {
            throw new RuntimeException('Native S6 debt cardinality mismatch');
        }

        return [
            'dataset_type' => $plan['monthly_claim_granularity']
                ? 'native_s6_adopted_historical_reimbursement_claims'
                : 'native_s6_adopted_historical_reimbursement_annual_rollups',
            'supplier_id' => $supplier,
            'units' => $plan['units'],
            'source_months' => $plan['source_months'],
            'source_first' => $plan['source_first'],
            'source_last' => $plan['source_last'],
            'source_granularity' => $plan['source_granularity'],
            'monthly_claim_granularity' => $plan['monthly_claim_granularity'],
            'claims_per_unit' => $plan['claims_per_unit'],
            'claims' => $plan['physical_claims'],
            'conceptual_unit_months' => $plan['conceptual_unit_months'],
            'total_cents' => $plan['total_cents'],
            'adoption_effective_at' => $effective,
            'batch_id' => $result['id'],
            'preview_ms' => $previewMs,
            'apply_ms' => $applyMs,
            'total_ms' => $previewMs + $applyMs,
            'sql_delta' => $delta,
            'physical_origin_proof' => true,
            'physical_monthly_origin_proof' => $plan['monthly_claim_granularity'],
            'historical_gl_before_adoption' => false,
        ];
    }
}
