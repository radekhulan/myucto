<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Allocation;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\DimensionAssignmentRepository;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Accounting\JournalLineAmount;
use PDO;

final class JointAllocationJournalProof
{
    public function __construct(private readonly Connection $db) {}

    public function apply(int $supplierId, int $batchId): array
    {
        $proof = (new JointAllocationPreviewService($this->db))->forPendingBatchLocked($supplierId, $batchId);
        $batch = $proof['batch'];
        $preview = $proof['preview'];
        $lines = self::materializedLines($preview['plan'], $batchId, $preview['type_ids']);
        self::assertBalanced($lines);
        return ['header' => self::header($supplierId, $preview['period_id'], $batch['target_date'], $batch['reason'], $batch['created_by']),
            'lines' => $lines, 'batch' => $batch];
    }

    public function cancellation(int $supplierId, int $cancellationId): array
    {
        (new \MyInvoice\Service\Accounting\Dimension\SystemDimensionWritePolicy($this->db))->lockContext($supplierId);
        (new \MyInvoice\Service\Property\Charges\Issuance\IssuanceUseGuard($this->db))->lockClaims($supplierId);
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('SELECT * FROM joint_allocation_cancellations WHERE supplier_id = ? AND id = ? FOR UPDATE');
        $stmt->execute([$supplierId, $cancellationId]);
        $cancellation = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$cancellation || $cancellation['reversal_entry_id'] !== null) {
            throw new JointAllocationException('allocation_changed', 'Zrušení již není připraveno k prvnímu zaúčtování.', 409);
        }
        $stmt = $pdo->prepare('SELECT * FROM joint_allocation_batches WHERE supplier_id = ? AND id = ? FOR UPDATE');
        $stmt->execute([$supplierId, $cancellation['batch_id']]);
        $batch = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$batch || $batch['journal_entry_id'] === null || $cancellation['target_date'] < $batch['target_date']) {
            throw new JointAllocationException('allocation_changed', 'Původní alokace není způsobilá ke zrušení.', 409);
        }
        $period = (new JointAllocationPreviewService($this->db))->targetPeriod($supplierId, $cancellation['target_date']);
        $snapshot = JointAllocationKeyVersion::decodeSnapshot($batch);
        $sourceIds = array_map('intval', array_column($snapshot['source_lines'], 'id'));
        (new JointAllocationUseGuard($this->db))->assertOwnedSources($supplierId, (int) $batch['id'], $sourceIds);
        $stmt = $pdo->prepare('SELECT * FROM journal_entries WHERE supplier_id = ? AND id = ? FOR UPDATE');
        $stmt->execute([$supplierId, $batch['journal_entry_id']]);
        $entry = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$entry || $entry['posted_at'] === null || $entry['reversed_by'] !== null || $entry['source_type'] !== 'manual'
            || $entry['source_id'] !== null || $entry['entry_date'] !== $batch['target_date'] || (int) $entry['period_id'] !== $snapshot['period_id']) {
            throw new JointAllocationException('allocation_changed', 'Účetní zápis alokace neodpovídá původnímu záznamu.', 409);
        }
        $stmt = $pdo->prepare('SELECT * FROM journal_entry_lines WHERE supplier_id = ? AND entry_id = ? ORDER BY line_no, id FOR UPDATE');
        $stmt->execute([$supplierId, $entry['id']]);
        $lines = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $expected = self::materializedLines($snapshot['plan'], (int) $batch['id'], $snapshot['type_ids']);
        if (count($lines) !== count($expected)) {
            throw new JointAllocationException('allocation_changed', 'Počet řádků alokace se změnil.', 409);
        }
        $stmt = $pdo->prepare('SELECT c.line_id FROM joint_allocation_source_claims c JOIN journal_entry_lines l ON l.id = c.line_id AND l.supplier_id = c.supplier_id WHERE c.supplier_id = ? AND l.entry_id = ? FOR UPDATE');
        $stmt->execute([$supplierId, $entry['id']]);
        if ($stmt->fetchColumn() !== false) {
            throw new JointAllocationException('allocation_source_in_use', 'Na účetní zápis navazuje další aktivní alokace.', 409);
        }
        $ids = array_map('intval', array_column($lines, 'id'));
        $assignments = new DimensionAssignmentRepository($this->db);
        $maps = [
            'dimensions' => $assignments->lineDimensions($supplierId, $ids, true),
            'dimension_splits' => $assignments->lineSplits($supplierId, $ids, true),
            'dimension_origins' => $assignments->lineOrigins($supplierId, $ids, true),
            'dimension_split_origins' => $assignments->lineSplitOrigins($supplierId, $ids, true),
        ];
        $mirror = [];
        foreach ($lines as $index => $line) {
            foreach ($maps as $field => $map) $line[$field] = $map[(int) $line['id']] ?? [];
            if (!self::sameLine($line, $expected[$index])) {
                throw new JointAllocationException('allocation_changed', 'Řádky alokace již neodpovídají neměnnému náhledu.', 409);
            }
            $reversed = JournalLineAmount::reversal($line);
            unset($reversed['id'], $reversed['entry_id'], $reversed['supplier_id']);
            $reversed['line_no'] = $index;
            $mirror[] = $reversed;
        }
        self::assertBalanced($mirror);
        return ['header' => self::header($supplierId, (int) $period['id'], $cancellation['target_date'], $cancellation['reason'], $cancellation['created_by']),
            'lines' => $mirror, 'batch' => $batch, 'cancellation' => $cancellation, 'source_line_ids' => $sourceIds];
    }

    public static function materializedLines(array $plan, int $batchId, array $typeIds): array
    {
        $out = [];
        foreach ($plan['sources'] as $source) {
            $out[] = $source['compensation'];
            foreach ($source['targets'] as $target) {
                $ownedTypes = array_merge(array_values($typeIds), $source['additional_type_ids'] ?? []);
                foreach (array_unique($ownedTypes) as $typeId) {
                    $target['dimension_origins'][$typeId] = ['origin' => 'module', 'ref' => 'allocation:' . $batchId];
                }
                $out[] = $target;
            }
        }
        foreach ($out as $index => &$line) $line['line_no'] = $index;
        unset($line);
        return $out;
    }

    public static function sameLine(array $a, array $b): bool
    {
        // Neměnný snapshot vyžaduje přesné desetinné částky i provenienci, na rozdíl od legacy finančního porovnání JournalLineComparison.
        foreach (['account_id', 'line_no'] as $field) if ((int) ($a[$field] ?? 0) !== (int) ($b[$field] ?? 0)) return false;
        foreach (['side', 'currency_code', 'cost_center'] as $field) if (($a[$field] ?? null) !== ($b[$field] ?? null)) return false;
        if (!empty($a['is_red_storno']) !== !empty($b['is_red_storno'])) return false;
        if (((int) ($a['project_id'] ?? 0)) !== ((int) ($b['project_id'] ?? 0))) return false;
        foreach (['amount', 'amount_foreign', 'fx_rate'] as $field) {
            if (($a[$field] ?? null) === null || ($b[$field] ?? null) === null) {
                if (($a[$field] ?? null) !== ($b[$field] ?? null)) return false;
            } elseif (bccomp((string) $a[$field], (string) $b[$field], 12) !== 0) return false;
        }
        if (($a['dimensions'] ?? []) != ($b['dimensions'] ?? [])) return false;
        if (!DimensionAssignmentRepository::sameSplits($a['dimension_splits'] ?? [], $b['dimension_splits'] ?? [])) return false;
        foreach (['dimension_origins', 'dimension_split_origins'] as $field) if (($a[$field] ?? []) != ($b[$field] ?? [])) return false;
        return true;
    }

    private static function assertBalanced(array $lines): void
    {
        $local = '0.00';
        $foreign = [];
        foreach ($lines as $line) {
            $negative = ($line['side'] === 'credit') !== !empty($line['is_red_storno']);
            $signed = static fn (string $amount): string => $negative ? bcsub('0', $amount, 2) : $amount;
            $local = bcadd($local, $signed($line['amount']), 2);
            if (($line['amount_foreign'] ?? null) !== null) {
                $currency = $line['currency_code'] ?? '';
                $foreign[$currency] = bcadd($foreign[$currency] ?? '0.00', $signed($line['amount_foreign']), 2);
            }
        }
        if ($local !== '0.00' || array_filter($foreign, static fn (string $amount): bool => $amount !== '0.00') !== []) {
            throw new JointAllocationException('allocation_unbalanced', 'Alokační protizápisy nejsou vyrovnané v účetní nebo cizí měně.', 409);
        }
    }

    private static function header(int $supplierId, int $periodId, string $date, string $reason, mixed $actor): array
    {
        return ['supplier_id' => $supplierId, 'period_id' => $periodId, 'entry_date' => $date,
            'description' => mb_substr($reason, 0, 255), 'source_type' => 'manual', 'source_id' => null,
            'posted_at' => date('Y-m-d H:i:s'), 'posted_by' => $actor === null ? null : (int) $actor];
    }
}
