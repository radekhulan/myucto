<?php

declare(strict_types=1);

namespace MyInvoice\Service\Property\Charges\Issuance;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Accounting\Dimension\DimensionException;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Property\PropertyCatalog;
use PDO;

final class IssuanceUseGuard
{
    public function __construct(private readonly Connection $db) {}

    public function assertEntriesMutable(int $supplier,array $entryIds): void
    {
        $this->lockContext($supplier);$claims=$this->claimsForEntries($supplier,$entryIds);
        (new \MyInvoice\Service\Property\Services\Settlement\SettlementUseGuard($this->db))->assertEntriesMutable($supplier,$entryIds);
        foreach($claims as $claim) foreach(['journal_entry_id','reversal_entry_id'] as $field) if($claim[$field]!==null&&in_array((int)$claim[$field],$entryIds,true)) self::reject((int)$claim['id']);
    }

    public function assertLinesMutable(int $supplier,array $lineIds): void
    {
        $this->lockContext($supplier);
        if($lineIds===[]) return;
        foreach($lineIds as $id) \MyInvoice\Service\Property\PropertyInput::id($id,'line_id');
        $stmt=$this->db->pdo()->prepare('SELECT DISTINCT entry_id FROM journal_entry_lines WHERE supplier_id=? AND id IN ('.implode(',',array_fill(0,count($lineIds),'?')).') ORDER BY entry_id FOR UPDATE');
        $stmt->execute([$supplier,...$lineIds]); $entries=array_map('intval',$stmt->fetchAll(PDO::FETCH_COLUMN));
        $claims=$this->claimsForEntries($supplier,$entries);
        (new \MyInvoice\Service\Property\Services\Settlement\SettlementUseGuard($this->db))->assertEntriesMutable($supplier,$entries);
        foreach($claims as $claim) foreach(['journal_entry_id','reversal_entry_id'] as $field) if($claim[$field]!==null&&in_array((int)$claim[$field],$entries,true)) self::reject((int)$claim['id']);
    }

    public function lockContext(int $supplier): void
    {
        if(!$this->db->pdo()->inTransaction()) throw new \LogicException('Issued source guard requires the owner transaction before journal locks.');
        (new IssuanceLocks($this->db))->acquire($supplier);
    }

    public function lockClaims(int $supplier): array
    {
        $this->lockContext($supplier);
        return $this->currentClaims($supplier);
    }

    private function claimsForEntries(int $supplier,array $entryIds):array
    {
        if(!$this->db->pdo()->inTransaction()) throw new \LogicException('Issued claims require current owner reads.');
        foreach($entryIds as $id) \MyInvoice\Service\Property\PropertyInput::id($id,'entry_id');
        $ids=array_values(array_unique($entryIds));sort($ids,SORT_NUMERIC);$claims=[];
        foreach(array_chunk($ids,500) as $chunk)foreach(['journal_entry_id','reversal_entry_id'] as $column){
            $marks=implode(',',array_fill(0,count($chunk),'?'));
            $stmt=$this->db->pdo()->prepare('SELECT id,journal_entry_id,reversal_entry_id FROM unit_charges WHERE supplier_id=? AND '.$column.' IN ('.$marks.') ORDER BY id FOR UPDATE');$stmt->execute([$supplier,...$chunk]);
            foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row)$claims[(int)$row['id']]=$row;
        }
        ksort($claims,SORT_NUMERIC);return array_values($claims);
    }

    private function currentClaims(int $supplier): array
    {
        $stmt=$this->db->pdo()->prepare('SELECT id,journal_entry_id,reversal_entry_id FROM unit_charges WHERE supplier_id=? ORDER BY id FOR UPDATE');
        $stmt->execute([$supplier]); return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function assertPostingEntriesMutable(int $supplier, array $entryIds): void
    {
        try { $this->assertEntriesMutable($supplier, $entryIds); }
        catch (IssuanceException $e) { throw new PostingException($e->errorCode, $e->getMessage(), $e->httpStatus, $e->details); }
    }

    public function assertPostingLinesMutable(int $supplier, array $lineIds): void
    {
        try { $this->assertLinesMutable($supplier, $lineIds); }
        catch (IssuanceException $e) { throw new PostingException($e->errorCode, $e->getMessage(), $e->httpStatus, $e->details); }
    }

    public function assertAllocationSourcesMutable(int $supplier, array $lineIds): void
    {
        try { $this->assertLinesMutable($supplier, $lineIds); }
        catch (IssuanceException $e) { throw new \MyInvoice\Service\Accounting\Allocation\JointAllocationException($e->errorCode, $e->getMessage(), $e->httpStatus, $e->details); }
    }

    public function assertDimensionLinesMutable(int $supplier, array $lineIds): void
    {
        try { $this->assertLinesMutable($supplier, $lineIds); }
        catch (IssuanceException $e) { throw new DimensionException($e->errorCode, $e->getMessage(), $e->httpStatus); }
    }

    public function assertSourcesMutable(int $supplier, array $sources): void
    {
        $this->lockContext($supplier);
        $entries = (new DimensionPeriodGuard($this->db))->lockSources($supplier, $sources);
        $this->assertPostingEntriesMutable($supplier, array_map('intval', array_keys($entries)));
    }

    public function assertDocumentMutable(int $supplier, string $docType, int $docId): void
    {
        $this->lockContext($supplier);
        $sourceType = match ($docType) {
            'cash_document' => 'cash', 'bank_transaction' => 'bank',
            'journal_template', 'recurring_template' => null, default => $docType,
        };
        $sources = $sourceType === null ? [] : [[$sourceType, $docId]];
        if ($docType === 'asset') {
            $stmt = $this->db->pdo()->prepare("SELECT id FROM depreciation_entries WHERE supplier_id=? AND asset_id=? AND kind='accounting' ORDER BY id FOR UPDATE");
            $stmt->execute([$supplier, $docId]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) $sources[] = ['depreciation', (int) $id];
            $sources[] = ['asset_disposal', $docId];
        }
        try { $this->assertSourcesMutable($supplier, $sources); }
        catch (PostingException $e) { throw new DimensionException($e->errorCode, $e->getMessage(), $e->httpStatus); }
    }

    public function assertAssignmentInsertsAfterPeriodPreflight(int $supplier, array $lineIds): void
    {
        if (!$this->db->pdo()->inTransaction()) throw new \LogicException('Assignment ownership requires a transaction.');
        (new PropertyCatalog($this->db))->lockAccountingContext($supplier);
        $lineIds = array_values(array_unique(array_map('intval', $lineIds)));
        sort($lineIds, SORT_NUMERIC);
        if ($lineIds === []) return;
        try {
            $entryIds = (new \MyInvoice\Service\Property\Services\Settlement\SettlementUseGuard($this->db))->mutableAssignmentEntries($supplier,$lineIds);
            $claims=$this->claimsForEntries($supplier,$entryIds);
            foreach ($claims as $claim) foreach (['journal_entry_id', 'reversal_entry_id'] as $field) {
                if ($claim[$field] !== null && in_array((int) $claim[$field], $entryIds, true)) self::reject((int) $claim['id']);
            }
        } catch (IssuanceException $e) {
            throw new DimensionException($e->errorCode, $e->getMessage(), $e->httpStatus);
        }
    }

    private static function reject(int $charge): never
    {
        throw new IssuanceException('property_charge_issue_source_immutable','Zápis patří vydanému předpisu. Opravte jej doloženým stornem nebo rozdílovým předpisem v modulu SVJ.',409,['charge_id'=>$charge]);
    }
}
