<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Dimension;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingFrameworkRepository;
use MyInvoice\Repository\DimensionAssignmentRepository;
use MyInvoice\Service\Accounting\AccountingFrameworkService;
use MyInvoice\Service\Accounting\PostingException;
use PDO;

final class SystemDimensionWritePolicy
{
    public function __construct(private readonly Connection $db) {}

    public function lockPeriods(int $supplierId): void
    {
        (new DimensionPeriodGuard($this->db))->lockPeriods($supplierId);
    }

    public function lockContext(int $supplierId): void
    {
        try { (new \MyInvoice\Service\Property\PropertyCatalog($this->db))->lockContext($supplierId); }
        catch (\MyInvoice\Service\Property\PropertyException $e) { throw new PostingException($e->errorCode, $e->getMessage(), $e->httpStatus, $e->details); }
    }

    private function propertyAssignments(int $supplierId, array $lines, ?string $date, array $previousLines): void
    {
        try {
            $policy = new \MyInvoice\Service\Property\PropertyDimensionPolicy($this->db);
            $date === null ? $policy->assertStructural($supplierId, $lines, $previousLines) : $policy->assertAccountingDate($supplierId, $date, $lines, $previousLines);
        } catch (\MyInvoice\Service\Property\PropertyException $e) { throw new PostingException($e->errorCode, $e->getMessage(), $e->httpStatus, $e->details); }
    }

    public function assertJournal(array $header, array $lines, array $previousLines = []): void
    {
        $context = $header['issuance_posting_batch_context'] ?? null;
        if ($context !== null && !$context instanceof \MyInvoice\Service\Property\Charges\Issuance\IssuancePostingBatchContext) {
            throw new \LogicException('Invalid issuance posting batch capability.');
        }
        $this->assertJournalCore($header, $lines, $previousLines);
        $context?->assertJournalAfterCore($this->db, $header, $lines, $previousLines);
    }

    public function assertPropertyPaymentCarrierJournal(array $header, array $lines, \MyInvoice\Service\Property\Payments\PaymentHistoricalCarrierProof $proof): void
    {
        $this->assertJournalCore($header, $lines, [], $proof);
    }

    public function assertPropertyServiceSettlementJournal(array $header,array $lines,\MyInvoice\Service\Property\Services\Settlement\SettlementHistoricalCarrierProof $proof):void
    {
        $this->assertJournalCore($header,$lines,[],settlement:$proof);
    }

    public function assertPropertyFunding(array $header,array $lines,\MyInvoice\Service\Property\Funds\FundingProof $proof):void
    {$this->assertJournalCore($header,$lines,[],funding:$proof);}
    public function assertPropertyServiceLifecycle(array $header,array $lines,\MyInvoice\Service\Property\Services\Settlement\ServiceLifecycleProof $proof):void
    {$this->assertJournalCore($header,$lines,[],lifecycle:$proof);}

    public function assertPropertyOpening(array $header,array $lines,\MyInvoice\Service\Property\Opening\OpeningOwnerProof $proof,string $part):void
    {
        $this->assertJournalCore($header,$lines,[],null,null,null,null,$proof,$part);
    }

    public function assertClosingCarry(\MyInvoice\Service\Accounting\Closing\ClosingCarryJournalProof|\MyInvoice\Service\Accounting\Conversion\FrameworkConversionJournalProof $proof): void
    {
        $this->assertJournalCore($proof->header, $proof->lines, [], null, $proof);
    }

    public function assertLegalClosing(\MyInvoice\Service\Accounting\Closing\ClosingLegal504Proof $proof): void
    {
        $this->assertJournalCore($proof->header, $proof->lines, [], null, null, $proof);
    }

    public function assertAssetReserveCorrection(\MyInvoice\Service\Accounting\Closing\AssetReserveCorrection504Proof $proof): void
    {
        $this->assertJournalCore($proof->header, $proof->lines, [], null, null, $proof);
    }

    public function assertDeferredTax504(\MyInvoice\Service\Accounting\Closing\DeferredTax504Proof $proof): void
    {
        $this->assertJournalCore($proof->header, $proof->lines, [], null, null, $proof);
    }

    public function assertFx504(\MyInvoice\Service\Accounting\Closing\Fx504JournalProof $proof): void
    {
        $this->assertJournalCore($proof->header, $proof->lines, [], null, null, null, $proof);
    }

    public function assertNonprofitCapital(array $header,array $lines,\MyInvoice\Service\Nonprofit\Capital\CapitalJournalProof $proof):void
    {$this->assertJournalCore($header,$lines,[],capital:$proof);}

    private function assertJournalCore(array $header, array $lines, array $previousLines, ?\MyInvoice\Service\Property\Payments\PaymentHistoricalCarrierProof $proof = null, \MyInvoice\Service\Accounting\Closing\ClosingCarryJournalProof|\MyInvoice\Service\Accounting\Conversion\FrameworkConversionJournalProof|null $closingCarry = null, \MyInvoice\Service\Accounting\Closing\ClosingLegal504Proof|\MyInvoice\Service\Accounting\Closing\AssetReserveCorrection504Proof|\MyInvoice\Service\Accounting\Closing\DeferredTax504Proof|null $assetCarry = null, ?\MyInvoice\Service\Accounting\Closing\Fx504JournalProof $fx = null, ?\MyInvoice\Service\Property\Opening\OpeningOwnerProof $opening = null, string $openingPart = 'journal', ?\MyInvoice\Service\Property\Services\Settlement\SettlementHistoricalCarrierProof $settlement = null, ?\MyInvoice\Service\Property\Services\Settlement\ServiceLifecycleProof $lifecycle = null, ?\MyInvoice\Service\Property\Funds\FundingProof $funding = null, ?\MyInvoice\Service\Nonprofit\Capital\CapitalJournalProof $capital = null): void
    {
        if($capital===null)\MyInvoice\Service\Nonprofit\Capital\CapitalUseGuard::assertGenericInsert($header);
        $carried = $capital?->indices($this->db,$header,$lines) ?? $funding?->indices($this->db,$header,$lines) ?? $lifecycle?->indices($this->db,$header,$lines) ?? $settlement?->indices($this->db,$header,$lines) ?? $opening?->indices($this->db,$header,$lines,$openingPart) ?? $assetCarry?->indices($this->db, $header, $lines) ?? $closingCarry?->indices($this->db, $header, $lines) ?? $proof?->indices($this->db, $header, $lines) ?? [];
        $fxRetained = $fx?->assignments($this->db, $header, $lines) ?? [];
        $supplierId = (int) $header['supplier_id'];
        $this->lockJournalPeriod($header);
        (new \MyInvoice\Service\Accounting\Conversion\FrameworkConversionUseGuard($this->db))->assertGenericJournal($header);
        if($opening===null)(new \MyInvoice\Service\Property\Opening\OpeningUseGuard($this->db))->assertGenericJournal($header);
        foreach ($lines as $line) {
            if (($line['dimensions'] ?? []) !== [] || ($line['dimension_splits'] ?? []) !== []) {
                try { (new \MyInvoice\Service\Property\PropertyCatalog($this->db))->lockAccountingContext($supplierId); }
                catch (\MyInvoice\Service\Property\PropertyException $e) { throw new PostingException($e->errorCode, $e->getMessage(), $e->httpStatus, $e->details); }
                break;
            }
        }
        if($capital!==null){
            (new \MyInvoice\Service\Property\PropertyDimensionPolicy($this->db))->assertNonprofitCapitalJournal($header,$lines,$capital);
        } elseif($funding!==null){
            (new \MyInvoice\Service\Property\PropertyDimensionPolicy($this->db))->assertFundingJournal($header,$lines,$funding);
        } elseif($lifecycle!==null){
            (new \MyInvoice\Service\Property\PropertyDimensionPolicy($this->db))->assertServiceLifecycleJournal($header,$lines,$lifecycle);
        } elseif ($settlement !== null) {
            try { (new \MyInvoice\Service\Property\PropertyDimensionPolicy($this->db))->assertServiceSettlementJournal($header,$lines,$settlement); }
            catch (\MyInvoice\Service\Property\PropertyException $e) { throw new PostingException($e->errorCode,$e->getMessage(),$e->httpStatus,$e->details); }
        } elseif ($opening !== null) {
            (new \MyInvoice\Service\Property\PropertyDimensionPolicy($this->db))->assertOpeningJournal($header,$lines,$opening,$openingPart);
        } elseif ($fx !== null) {
            try { (new \MyInvoice\Service\Property\PropertyDimensionPolicy($this->db))->assertFx504Journal($header, $lines, $fx); }
            catch (\MyInvoice\Service\Property\PropertyException $e) { throw new PostingException($e->errorCode, $e->getMessage(), $e->httpStatus, $e->details); }
        } elseif ($assetCarry !== null) {
            try {
                $policy = new \MyInvoice\Service\Property\PropertyDimensionPolicy($this->db);
                $assetCarry instanceof \MyInvoice\Service\Accounting\Closing\ClosingLegal504Proof
                    ? $policy->assertLegalClosingJournal($header, $lines, $assetCarry)
                    : ($assetCarry instanceof \MyInvoice\Service\Accounting\Closing\DeferredTax504Proof
                        ? $policy->assertDeferredTax504Journal($header, $lines, $assetCarry)
                        : $policy->assertAssetReserveCorrectionJournal($header, $lines, $assetCarry));
            } catch (\MyInvoice\Service\Property\PropertyException $e) { throw new PostingException($e->errorCode, $e->getMessage(), $e->httpStatus, $e->details); }
        } elseif ($closingCarry !== null) {
            try { (new \MyInvoice\Service\Property\PropertyDimensionPolicy($this->db))->assertClosingCarryJournal($header, $lines, $closingCarry); }
            catch (\MyInvoice\Service\Property\PropertyException $e) { throw new PostingException($e->errorCode, $e->getMessage(), $e->httpStatus, $e->details); }
        } elseif ($proof === null) {
            $this->propertyAssignments($supplierId, $lines, (string) $header['entry_date'], $previousLines);
        } else {
            try { (new \MyInvoice\Service\Property\PropertyDimensionPolicy($this->db))->assertPaymentCarrierJournal($header, $lines, $proof); }
            catch (\MyInvoice\Service\Property\PropertyException $e) { throw new PostingException($e->errorCode, $e->getMessage(), $e->httpStatus, $e->details); }
        }
        $types = $this->types($supplierId, $lines);
        if ($types === [] || !$this->is504($supplierId, (string) $header['entry_date'])) {
            return;
        }
        $this->assignments($supplierId, $lines, $types, $previousLines, $carried, $fxRetained);
        $stmt = $this->db->pdo()->prepare("SELECT r.id, r.dimension_type_id, r.account_mask, r.enforcement, r.valid_from, r.valid_to
            FROM dimension_account_rules r JOIN dimension_types t ON t.id = r.dimension_type_id
            WHERE r.supplier_id = ? AND r.is_active = 1 AND t.system_key IS NOT NULL ORDER BY r.id FOR UPDATE");
        $stmt->execute([$supplierId]);
        $rules = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $rule) {
            $rules[] = $rule + ['mask' => DimensionAccountMask::parse($rule['account_mask']), 'default_value_id' => null, 'default_from_card' => false];
        }
        foreach ($types as $type) {
            if ($type['system_key'] === 'activity') {
                $rules[] = SystemDimensionSourcePolicy::activityRule((int) $type['id']);
            }
        }
        if ($rules === []) {
            return;
        }
        $stmt = $this->db->pdo()->prepare('SELECT id, account_code, account_type FROM chart_of_accounts WHERE supplier_id = ? ORDER BY id FOR UPDATE');
        $stmt->execute([$supplierId]);
        $accounts = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), null, 'id');
        foreach ($lines as $line) {
            $account = $accounts[(int) ($line['account_id'] ?? 0)] ?? null;
            if ($account === null) {
                continue;
            }
            $selected = array_values(array_filter($rules, static fn (array $rule): bool => SystemDimensionSourcePolicy::applies(
                (string) ($header['source_type'] ?? 'manual'), $account['account_type'], $rule['enforcement'],
            )));
            $result = DimensionRuleEngine::apply($selected, [$line + ['account_code' => $account['account_code']]], (string) $header['entry_date'], null, false);
            if ($result['violations'] !== []) {
                throw new PostingException('dimension_required', 'Zápis nelze zaúčtovat bez povinných systémových dimenzí.', 422, ['violations' => $result['violations']]);
            }
        }
    }

    public function assertAssignments(int $supplierId, array $lines, ?string $date = null, array $previousLines = []): void
    {
        if (!$this->db->pdo()->inTransaction()) {
            throw new \LogicException('System dimension validation requires period locks in a transaction.');
        }
        $this->lockContext($supplierId);
        $this->propertyAssignments($supplierId, $lines, $date, $previousLines);
        $types = $this->types($supplierId, $lines);
        if ($types !== [] && ($date === null || $this->is504($supplierId, $date))) {
            $this->assignments($supplierId, $lines, $types, $previousLines);
        }
    }

    public function assertDocumentAssignments(int $supplierId, array $lines, ?string $date, array $previousLines = []): void
    {
        $this->lockContext($supplierId);
        $this->propertyAssignments($supplierId, $lines, $date, $previousLines);
        $types = $this->types($supplierId, $lines);
        if ($types !== []) $this->assignments($supplierId, $lines, $types, $previousLines);
    }

    public function lockJournalPeriod(array $header): void
    {
        if (!$this->db->pdo()->inTransaction()) {
            throw new \LogicException('Journal dimension validation requires a transaction.');
        }
        $sql = 'SELECT id FROM accounting_periods WHERE supplier_id = ?';
        $params = [(int) $header['supplier_id']];
        if (!empty($header['period_id'])) {
            $sql .= ' AND id = ?';
            $params[] = (int) $header['period_id'];
        } else {
            $sql .= ' AND starts_on <= ? AND ends_on >= ?';
            array_push($params, $header['entry_date'], $header['entry_date']);
        }
        $stmt = $this->db->pdo()->prepare($sql . ' ORDER BY starts_on, id FOR UPDATE');
        $stmt->execute($params);
        $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    private function types(int $supplierId, array $lines): array
    {
        $ids = [];
        foreach ($lines as $line) {
            foreach (['dimensions', 'dimension_splits'] as $field) {
                foreach (array_keys($line[$field] ?? []) as $id) $ids[(int) $id] = (int) $id;
            }
        }
        $scope = 'supplier_id = ?';
        $params = [$supplierId];
        if ($ids !== []) {
            $scope .= ' OR id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            array_push($params, ...array_values($ids));
        }
        $stmt = $this->db->pdo()->prepare('SELECT id, supplier_id, system_key FROM dimension_types WHERE (' . $scope . ') AND system_key IS NOT NULL ORDER BY id FOR UPDATE');
        $stmt->execute($params);
        $types = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($types as $type) {
            if ((int) $type['supplier_id'] !== $supplierId) {
                throw new PostingException('invalid_dimension_value', 'Systémová dimenze nepatří firmě.', 422);
            }
        }
        return array_column($types, null, 'id');
    }

    private function is504(int $supplierId, string $date): bool
    {
        return (new AccountingFrameworkService($this->db, new AccountingFrameworkRepository($this->db)))->forDateForUpdate($supplierId, $date) === AccountingFrameworkService::CZ_504;
    }

    private function assignments(int $supplierId, array $lines, array $types, array $previousLines, array $carried = [], array $fxRetained = []): void
    {
        $retained = $fxRetained + self::retainedAssignments($lines, $previousLines);
        $requested = [];
        foreach ($lines as $index => $line) {
            $fractional = 0;
            foreach ($line['dimension_splits'] ?? [] as $typeId => $shares) {
                if (!isset($types[$typeId])) {
                    continue;
                }
                if (in_array($types[$typeId]['system_key'], ['activity', 'activity_kind', 'tax_regime'], true) && count($shares) > 1) {
                    ++$fractional;
                }
                foreach ($shares as $valueId => $share) {
                    $kept = isset($carried[$index]) || isset($retained[$index]['dimension_splits'][$typeId][$valueId]);
                    self::requestValue($requested, (int) $typeId, (int) $valueId, $kept);
                }
            }
            if ($fractional > 1) {
                throw new PostingException('system_dimension_allocation_ambiguous', 'Rozdělení více systémových dimenzí musí mít společné alokační řádky.', 422);
            }
            foreach ($line['dimensions'] ?? [] as $typeId => $valueId) {
                if (isset($types[$typeId])) {
                    $kept = isset($carried[$index]) || ($retained[$index]['dimensions'][$typeId] ?? null) === (int) $valueId;
                    self::requestValue($requested, (int) $typeId, (int) $valueId, $kept);
                }
            }
        }
        if ($requested === []) {
            return;
        }
        $ids = array_values(array_unique(array_column($requested, 'value_id')));
        sort($ids, SORT_NUMERIC);
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->pdo()->prepare("SELECT id, type_id, is_active FROM dimension_values WHERE supplier_id = ? AND id IN ({$marks}) ORDER BY id FOR UPDATE");
        $stmt->execute([$supplierId, ...$ids]);
        $values = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), null, 'id');
        foreach ($requested as $pair) {
            $value = $values[$pair['value_id']] ?? null;
            if ($value === null || (int) $value['type_id'] !== $pair['type_id']) {
                throw new PostingException('invalid_dimension_value', 'Hodnota systémové dimenze nepatří firmě nebo typu.', 422);
            }
            if (!(bool) $value['is_active'] && !$pair['retained']) {
                throw new PostingException('dimension_closed', 'Uzavřenou hodnotu systémové dimenze nelze nově přiřadit.', 422);
            }
        }
    }

    private static function requestValue(array &$requested, int $typeId, int $valueId, bool $retained): void
    {
        $key = $typeId . ':' . $valueId;
        $requested[$key] = ['type_id' => $typeId, 'value_id' => $valueId,
            'retained' => $retained && ($requested[$key]['retained'] ?? true)];
    }

    public static function retainedAssignments(array $lines, array $previousLines): array
    {
        $byId = [];
        $byNumber = [];
        foreach ($previousLines as $index => $line) {
            if (isset($line['id'])) {
                $byId[(int) $line['id']] = $line;
            }
            $byNumber[(int) ($line['line_no'] ?? $index)] = $line;
        }
        $out = [];
        $used = [];
        foreach ($lines as $index => $line) {
            $previous = isset($line['id'])
                ? ($byId[(int) $line['id']] ?? null)
                : ($byNumber[(int) ($line['line_no'] ?? $index)] ?? null);
            if ($previous === null || !self::sameLedgerLine($line, $previous)) {
                continue;
            }
            $identity = isset($previous['id']) ? 'id:' . $previous['id'] : 'number:' . ($previous['line_no'] ?? $index);
            if (isset($used[$identity])) {
                continue;
            }
            $used[$identity] = true;
            foreach ($line['dimensions'] ?? [] as $typeId => $valueId) {
                if (isset($previous['dimensions'][$typeId]) && (int) $previous['dimensions'][$typeId] === (int) $valueId) {
                    $out[$index]['dimensions'][$typeId] = (int) $valueId;
                    $out[$index]['dimension_origins'][$typeId] = DimensionAssignmentOrigin::normalize($previous['dimension_origins'][$typeId] ?? []);
                }
            }
            foreach ($line['dimension_splits'] ?? [] as $typeId => $shares) {
                foreach ($shares as $valueId => $share) {
                    if (isset($previous['dimension_splits'][$typeId][$valueId])
                        && DimensionAssignmentRepository::share($previous['dimension_splits'][$typeId][$valueId]) === DimensionAssignmentRepository::share($share)) {
                        $out[$index]['dimension_splits'][$typeId][$valueId] = $share;
                        $out[$index]['dimension_split_origins'][$typeId][$valueId] = DimensionAssignmentOrigin::normalize($previous['dimension_split_origins'][$typeId][$valueId] ?? []);
                    }
                }
            }
        }
        return $out;
    }

    public static function canRetainEntryAssignments(array $header, array $previous): bool
    {
        return (int) $header['supplier_id'] === (int) $previous['supplier_id']
            && (int) $header['period_id'] === (int) $previous['period_id']
            && $header['entry_date'] === $previous['entry_date']
            && ($header['source_type'] ?? 'manual') === ($previous['source_type'] ?? 'manual')
            && (isset($header['source_id']) ? (int) $header['source_id'] : null) === (isset($previous['source_id']) ? (int) $previous['source_id'] : null)
            && !(($previous['posted_at'] ?? null) === null && ($header['posted_at'] ?? null) !== null);
    }

    public static function sameLedgerLine(array $a, array $b): bool
    {
        return \MyInvoice\Service\Accounting\JournalLineComparison::sameLedgerLine($a, $b);
    }
}
