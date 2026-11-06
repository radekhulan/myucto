<?php

declare(strict_types=1);

namespace MyInvoice\Benchmark;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Receivables\FinancialHistoryRepository;
use MyInvoice\Repository\Property\Receivables\PartyEventRepository;
use MyInvoice\Repository\Property\Receivables\PropertySaldoReadRepository;
use MyInvoice\Service\Property\Receivables\PropertyReceivableProjection;
use MyInvoice\Service\Property\Receivables\PropertySaldoSummary;
use PDO;
use RuntimeException;
use Throwable;

final class PropertyBenchmarkRead
{
    private const REFERENCE_BATCH_UNITS = 25;
    private const REFERENCE_MAX_SOURCE_ROWS = 50000;
    private const REFERENCE_MAX_BATCH_DEBTS = 3200;

    public function __construct(private readonly Connection $db)
    {
    }

    public function page(int $supplier, string $asOf, string $knownAt, int $limit = 25, ?string $cursor = null): array
    {
        $pdo = $this->db->pdo();
        $before = self::counters($pdo);
        $started = hrtime(true);
        $page = (new PropertySaldoReadRepository($this->db))->page($supplier, $asOf, $knownAt, $limit, $cursor);
        $ms = (hrtime(true) - $started) / 1e6;
        $after = self::counters($pdo);
        $delta = [];
        foreach ($after as $key => $value) {
            $delta[$key] = (int) $value - (int) $before[$key];
        }
        foreach (['Com_insert', 'Com_update', 'Com_delete'] as $key) {
            if ($delta[$key] !== 0) {
                throw new RuntimeException('Saldo read performed DML');
            }
        }
        foreach ($page['items'] as $item) {
            if (!$item['coverage']['financial_complete']) {
                throw new RuntimeException('Incomplete financial coverage is not benchmark success');
            }
        }
        if ($pdo->inTransaction()) {
            throw new RuntimeException('Saldo left an open transaction');
        }

        return [
            'ms' => $ms,
            'units' => count($page['items']),
            'delta' => $delta,
            'sha256' => hash('sha256', json_encode($page['items'], JSON_THROW_ON_ERROR)),
            'page' => $page,
        ];
    }

    public function traverse(int $supplier, string $asOf, string $knownAt, int $expectedUnits, int $limit = 100): array
    {
        if ($expectedUnits < 1 || $expectedUnits > 400 || $limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('Bounded saldo traversal requires 1..400 units and page size 1..100');
        }

        $cursor = null;
        $lastUnit = 0;
        $count = 0;
        $gross = '0';
        $claimMin = null;
        $claimMax = null;
        $pages = [];
        $digest = hash_init('sha256');
        $maxPages = (int) ceil($expectedUnits / $limit) + 1;
        do {
            if (count($pages) >= $maxPages) {
                throw new RuntimeException('Unexpected saldo cursor loop');
            }
            $sample = $this->page($supplier, $asOf, $knownAt, $limit, $cursor);
            $items = $sample['page']['items'];
            if ($items === [] && $sample['page']['next_cursor'] !== null) {
                throw new RuntimeException('Non-terminal empty saldo page');
            }
            foreach ($items as $item) {
                $unit = $item['unit_id'];
                if (!is_int($unit) || $unit <= $lastUnit) {
                    throw new RuntimeException('Saldo keyset order duplicated or regressed');
                }
                $lastUnit = $unit;
                $gross = bcadd($gross, $item['totals']['gross_receivable_cents'], 0);
                $claims = $item['claim_count'];
                if (!is_int($claims) || $claims < 0) {
                    throw new RuntimeException('Saldo claim count is not canonical');
                }
                $claimMin = $claimMin === null ? $claims : min($claimMin, $claims);
                $claimMax = $claimMax === null ? $claims : max($claimMax, $claims);
                ++$count;
            }
            $json = json_encode($items, JSON_THROW_ON_ERROR);
            hash_update($digest, pack('N', strlen($json)) . $json);
            $cursor = $sample['page']['next_cursor'];
            $pages[] = [
                'number' => count($pages) + 1,
                'ms' => $sample['ms'],
                'units' => $sample['units'],
                'delta' => $sample['delta'],
                'sha256' => $sample['sha256'],
                'read_scope_digest' => $sample['page']['read_scope_digest'],
                'has_next' => $cursor !== null,
            ];
            unset($sample, $items, $json);
        } while ($cursor !== null);

        if ($count !== $expectedUnits) {
            throw new RuntimeException('Complete saldo traversal omitted or added units');
        }
        $times = array_column($pages, 'ms');
        $worst = max($times);

        return [
            'units' => $count,
            'page_size' => $limit,
            'page_count' => count($pages),
            'gross_receivable_cents' => $gross,
            'claim_count_min' => $claimMin,
            'claim_count_max' => $claimMax,
            'worst_page_ms' => $worst,
            'within_1s' => $worst <= 1000,
            'stream_sha256' => hash_final($digest),
            'pages' => $pages,
        ];
    }

    public function reference(int $supplier, string $asOf, string $knownAt, array $units): array
    {
        $batches = self::referenceBatchSizes(count($units));
        $pdo = $this->db->pdo();
        $this->assertSyntheticReferenceDatabase($pdo);
        $before = self::counters($pdo);
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->exec(count($units) === 12 ? 'START TRANSACTION READ ONLY' : 'START TRANSACTION');
        try {
            $this->assertReferenceUnits($pdo, $supplier, $units);
            $out = count($units) === 12
                ? $this->pilotReference($supplier, $asOf, $knownAt, $units)
                : $this->boundedReference($pdo, $supplier, $asOf, $knownAt, $units, $batches);
            $pdo->rollBack();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        $after = self::counters($pdo);
        foreach (['Com_insert', 'Com_update', 'Com_delete'] as $key) {
            if ((int) $after[$key] !== (int) $before[$key]) {
                throw new RuntimeException('Reference projection performed DML');
            }
        }

        return $out;
    }

    public static function referenceBatchSizes(int $units): array
    {
        if ($units === 12) {
            return [12];
        }
        if ($units === 400) {
            return array_fill(0, 400 / self::REFERENCE_BATCH_UNITS, self::REFERENCE_BATCH_UNITS);
        }
        throw new \InvalidArgumentException('Reference projection requires exactly 12 or 400 units');
    }

    private function pilotReference(int $supplier, string $asOf, string $knownAt, array $units): array
    {
        $history = (new FinancialHistoryRepository($this->db))->readProjection($supplier);
        $events = (new PartyEventRepository($this->db))->history($supplier);
        $query = $this->db->pdo()->prepare('SELECT id FROM unit_payment_allocations WHERE supplier_id=? AND accessory_id IS NOT NULL');
        $query->execute([$supplier]);
        $accessories = array_fill_keys($query->fetchAll(PDO::FETCH_COLUMN), true);
        $out = [];
        foreach ($units as $unit) {
            $card = PropertyReceivableProjection::reduceReadHistory($supplier, $asOf, $knownAt, ['unit_id' => (int) $unit['id']], $history, $events);
            $out[] = PropertySaldoSummary::fromCard($card, $accessories) + ['unit_code' => $unit['code']];
        }
        return $out;
    }

    private function boundedReference(PDO $pdo, int $supplier, string $asOf, string $knownAt, array $units, array $batchSizes): array
    {
        $this->assertNativeSourceBound($pdo, $supplier);
        $financialReader = new FinancialHistoryRepository($this->db);
        $partyReader = new PartyEventRepository($this->db);
        $out = [];
        $offset = 0;
        foreach ($batchSizes as $batchSize) {
            $batch = array_slice($units, $offset, $batchSize);
            $offset += $batchSize;
            $unitIds = array_map(static fn (array $unit): int => (int) $unit['id'], $batch);
            $chargeIds = $this->nativeChargeIds($pdo, $supplier, $unitIds);
            $selected = [];
            $history = null;
            $party = null;
            $stable = false;
            for ($iteration = 0; $iteration < 8; ++$iteration) {
                $history = $financialReader->readForCharges($supplier, $chargeIds, $selected);
                $financialIds = array_map('intval', array_keys($history['debts']));
                $party = $partyReader->readHistoryForDebts($supplier, $financialIds, $unitIds);
                $next = array_values(array_unique([...$financialIds, ...array_map('intval', $party['debt_ids'])]));
                sort($next, SORT_NUMERIC);
                if (count($next) > self::REFERENCE_MAX_BATCH_DEBTS || count($party['events']) > self::REFERENCE_MAX_BATCH_DEBTS) {
                    throw new RuntimeException('Reference financial closure exceeded the bounded batch');
                }
                if ($next === $selected) {
                    $stable = true;
                    break;
                }
                $selected = $next;
            }
            if ($history === null || $party === null || !$stable) {
                throw new RuntimeException('Reference financial closure did not stabilize');
            }
            $accessories = $this->accessoryAllocations($pdo, $supplier, $selected);
            foreach ($batch as $unit) {
                $card = PropertyReceivableProjection::reduceReadHistory($supplier, $asOf, $knownAt,
                    ['unit_id' => (int) $unit['id']], $history, $party['events']);
                $out[] = PropertySaldoSummary::fromCard($card, $accessories) + ['unit_code' => $unit['code']];
            }
            unset($history, $party, $accessories, $selected, $next, $batch, $chargeIds);
        }
        if ($offset !== 400 || count($out) !== 400) {
            throw new RuntimeException('Reference fixture traversal was incomplete');
        }
        return $out;
    }

    private function assertSyntheticReferenceDatabase(PDO $pdo): void
    {
        $database = $pdo->query('SELECT DATABASE()')->fetchColumn();
        $status = strtolower((string) $pdo->getAttribute(PDO::ATTR_CONNECTION_STATUS));
        if (!is_string($database)
            || preg_match('/^myucto_nno_property_benchmark_[a-z0-9_]+_test$/D', $database) !== 1
            || preg_match('/(?:localhost|127\.0\.0\.1|::1)/', $status) !== 1) {
            throw new RuntimeException('Reference projection requires an owned local synthetic benchmark database');
        }
    }

    private function assertReferenceUnits(PDO $pdo, int $supplier, array $units): void
    {
        $expected = array_map(static fn (array $unit): array => ['id' => (int) ($unit['id'] ?? 0), 'code' => (string) ($unit['code'] ?? '')], $units);
        $query = $pdo->prepare("SELECT id,code FROM property_objects WHERE supplier_id=? AND kind='unit' ORDER BY id LIMIT 401");
        $query->execute([$supplier]);
        $actual = array_map(static fn (array $unit): array => ['id' => (int) $unit['id'], 'code' => $unit['code']], $query->fetchAll(PDO::FETCH_ASSOC));
        if ($actual !== $expected) {
            throw new RuntimeException('Reference unit manifest is not the complete canonical fixture');
        }
    }

    private function assertNativeSourceBound(PDO $pdo, int $supplier): void
    {
        $charges = $pdo->prepare('SELECT id FROM unit_charges FORCE INDEX(uq_issue_charge_tenant) WHERE supplier_id=? ORDER BY id LIMIT 50001');
        $charges->execute([$supplier]);
        $chargeCount = 0;
        while ($charges->fetchColumn() !== false) {
            if (++$chargeCount > self::REFERENCE_MAX_SOURCE_ROWS) {
                throw new RuntimeException('Reference native charge source exceeded 50000 rows');
            }
        }
        $debts = $pdo->prepare('SELECT id,charge_id,opening_item_id,settlement_effect_id FROM unit_payment_debts FORCE INDEX(uq_unit_payment_debt_tenant) WHERE supplier_id=? ORDER BY id LIMIT 50001');
        $debts->execute([$supplier]);
        $debtCount = 0;
        while (($debt = $debts->fetch(PDO::FETCH_ASSOC)) !== false) {
            if (++$debtCount > self::REFERENCE_MAX_SOURCE_ROWS) {
                throw new RuntimeException('Reference native debt source exceeded 50000 rows');
            }
            if ($debt['charge_id'] === null || $debt['opening_item_id'] !== null || $debt['settlement_effect_id'] !== null) {
                throw new RuntimeException('Reference 400-unit source contains a non-native debt origin');
            }
        }
        if ($debtCount > $chargeCount) {
            throw new RuntimeException('Reference native debts exceed the bounded native charge source');
        }
    }

    private function nativeChargeIds(PDO $pdo, int $supplier, array $unitIds): array
    {
        $marks = implode(',', array_fill(0, count($unitIds), '?'));
        $charges = $pdo->prepare("SELECT id FROM unit_charges FORCE INDEX(uq_issue_charge_revision) WHERE supplier_id=? AND unit_id IN ({$marks}) ORDER BY unit_id,source_key,revision_no,id LIMIT 3201");
        $charges->execute([$supplier, ...$unitIds]);
        $chargeIds = array_map('intval', $charges->fetchAll(PDO::FETCH_COLUMN));
        if (count($chargeIds) > self::REFERENCE_MAX_BATCH_DEBTS) {
            throw new RuntimeException('Reference native charge batch exceeded 3200 rows');
        }
        return $chargeIds;
    }

    private function accessoryAllocations(PDO $pdo, int $supplier, array $debtIds): array
    {
        if ($debtIds === []) {
            return [];
        }
        $encoded = json_encode($debtIds, JSON_THROW_ON_ERROR);
        $query = $pdo->prepare("SELECT a.id FROM JSON_TABLE(?,'$[*]' COLUMNS(id BIGINT PATH '$')) wanted JOIN unit_payment_allocations a ON a.supplier_id=? AND a.debt_id=wanted.id WHERE a.accessory_id IS NOT NULL ORDER BY a.id LIMIT 3201");
        $query->execute([$encoded, $supplier]);
        $ids = array_map('intval', $query->fetchAll(PDO::FETCH_COLUMN));
        if (count($ids) > self::REFERENCE_MAX_BATCH_DEBTS) {
            throw new RuntimeException('Reference accessory closure exceeded the bounded batch');
        }
        return array_fill_keys($ids, true);
    }

    public static function counters(PDO $pdo): array
    {
        return $pdo->query("SHOW SESSION STATUS WHERE Variable_name IN ('Com_select','Com_insert','Com_update','Com_delete','Rows_read','Rows_sent')")->fetchAll(PDO::FETCH_KEY_PAIR);
    }
}
