<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Service\Accounting\AccountCodeRange;
use MyInvoice\Service\Accounting\DocumentPartnerName;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Dimension\DimensionFilter;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Accounting\Dimension\SystemDimensionWritePolicy;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\JournalDescriptionBuilder;
use MyInvoice\Service\Accounting\JournalLineAmount;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Accounting\Allocation\JointAllocationJournalProof;
use MyInvoice\Service\Accounting\Allocation\JointAllocationAudit;
use MyInvoice\Service\Accounting\Allocation\JointAllocationException;
use MyInvoice\Service\Accounting\Allocation\JointAllocationUseGuard;
use MyInvoice\Service\Property\Charges\Issuance\IssuanceUseGuard;
use MyInvoice\Service\Nonprofit\Membership\MembershipUseGuard;
use MyInvoice\Service\Property\Charges\Issuance\IssuanceReversalIntent;
use MyInvoice\Service\Accounting\Closing\ClosingSourceId;
use MyInvoice\Service\Accounting\Closing\Fx504JournalProof;
use MyInvoice\Service\Accounting\EntryForm\EntryFormService;
use MyInvoice\Service\Accounting\Pair\JournalPairInvariant;
use MyInvoice\Service\Accounting\Pair\JournalPairer;
use MyInvoice\Service\Accounting\Pair\TypedJournalPairs;
use MyInvoice\Security\FrameworkScopePolicy;
use PDO;

/**
 * Repository pro journal_entries + journal_entry_lines — účetní deník (Epic F1).
 *
 * Zápis se vkládá atomicky (hlavička + řádky v jedné transakci). Rovnováhu
 * Σ MD = Σ D vynucuje PostingService před voláním insert() — tady se drží jen
 * perzistence. Idempotence přes findBySource() (source_type + source_id).
 */
final class JournalEntryRepository
{
    public const int SECURITIES_DEBT_GUARD_VERSION = 1;

    private ?EntryFormService $entryForms = null;

    public function __construct(
        private readonly Connection $db,
        private readonly ActivityLogger $activity,
    ) {}

    /**
     * Vloží účetní zápis včetně řádků v transakci. Vrací id zápisu.
     *
     * @param array{
     *     supplier_id:int, period_id:int, entry_date:string, document_date?:?string,
     *     document_no?:?string, description?:?string, source_type?:string,
     *     source_id?:?int, posted_at?:?string, posted_by?:?int
     * } $header
     * @param list<array{account_id:int, side:'debit'|'credit', amount:float|string, is_red_storno?:bool, cost_center?:?string, project_id?:?int, line_no?:int}> $lines
     */
    public const FRAMEWORK_CONVERSION_OWNER_VERSION = 1;

    public function cancelFrameworkConversion(int $supplier,int $conversion,int $actor,string $receiptHash):void
    {
        (new DimensionPeriodGuard($this->db))->transaction(function()use($supplier,$conversion,$actor,$receiptHash):void{
            $proof=\MyInvoice\Service\Accounting\Conversion\FrameworkConversionCancellationProof::current($this->db,$supplier,$conversion);
            \MyInvoice\Service\Accounting\Conversion\FrameworkConversionException::need(hash_equals($proof->row['receipt_hash'],$receiptHash),'conversion_preview_stale');
            $entry=(int)$proof->row['opening_entry_id'];
            $q=$this->db->pdo()->prepare('UPDATE accounting_framework_conversion_cells SET opening_line_id=NULL WHERE supplier_id=? AND conversion_id=?');$q->execute([$supplier,$conversion]);
            $q=$this->db->pdo()->prepare("UPDATE accounting_framework_conversions SET status='cancelled',opening_entry_id=NULL,source_entry_id=NULL,cancelled_by=?,cancelled_at=UTC_TIMESTAMP(6) WHERE supplier_id=? AND id=? AND opening_entry_id=? AND status='applied'");$q->execute([$actor,$supplier,$conversion,$entry]);
            \MyInvoice\Service\Accounting\Conversion\FrameworkConversionException::need($q->rowCount()===1,'conversion_write_conflict');
            $this->deleteLines($entry,$supplier);$q=$this->db->pdo()->prepare('DELETE FROM journal_entries WHERE supplier_id=? AND id=?');$q->execute([$supplier,$entry]);
            \MyInvoice\Service\Accounting\Conversion\FrameworkConversionException::need($q->rowCount()===1,'conversion_write_conflict');
        });
    }

    public function insertFrameworkConversion(int $supplier,int $conversion):int
    {
        return (new DimensionPeriodGuard($this->db))->transaction(function()use($supplier,$conversion):int{
            $proof=\MyInvoice\Service\Accounting\Conversion\FrameworkConversionJournalProof::pending($this->db,$supplier,$conversion);
            (new SystemDimensionWritePolicy($this->db))->assertClosingCarry($proof);
            $entry=$this->persistJournal($proof->header,$proof->lines,typedPairs:TypedJournalPairs::CONSECUTIVE);
            $this->activity->log('accounting.posted',(int)$proof->header['posted_by'],'journal_entry',$entry,
                ['source_type'=>$proof->header['source_type'],'source_id'=>$proof->header['source_id'],'framework_conversion_id'=>$conversion],null,null,$supplier);
            return $entry;
        });
    }

    public function insert(array $header, array $lines): int
    {
        \MyInvoice\Service\Property\Services\Settlement\SettlementUseGuard::assertGenericInsert($header);
        \MyInvoice\Service\Property\Funds\FundingUseGuard::assertGenericInsert($header);
        \MyInvoice\Service\Nonprofit\Capital\CapitalUseGuard::assertGenericInsert($header);
        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
            (new SystemDimensionWritePolicy($this->db))->assertJournal($header, $lines);
            $lines = $this->pairGenericLines($header, $lines);
            $entryId = $this->persistJournal($header, $lines, false);

            if ($ownTx) {
                $pdo->commit();
            }
            return $entryId;
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Obecný zápis mimo PostingService (opravné a převzaté zápisy, testovací předpisy):
     * ve firmě s jednoduchou formou k datu se řádky bez dvojic a bez posouzení rozloží
     * stejným párovačem jako zaúčtování. Co rozložit nejde, zastaví invariant dvojic.
     * Složená firma řádky beze změny.
     *
     * @param array<string,mixed> $header
     * @param list<array<string,mixed>> $lines
     * @return list<array<string,mixed>>
     */
    private function pairGenericLines(array $header, array $lines): array
    {
        if (isset($header['pair_status']) || array_filter($lines, static fn (array $l): bool => isset($l['pair_no'])) !== []) {
            return $lines;
        }
        $form = $this->entryForms()->formAt((int) $header['supplier_id'], (string) $header['entry_date'], currentRead: true);
        if (!$form->isSingle()) {
            return $lines;
        }
        $result = JournalPairer::pair($lines);
        return $result['status'] === JournalPairer::STATUS_PAIRED ? $result['lines'] : $lines;
    }

    private function entryForms(): EntryFormService
    {
        return $this->entryForms ??= new EntryFormService(
            $this->db,
            $this->activity,
            new AccountingModeRepository($this->db),
            new FrameworkScopePolicy($this->db),
        );
    }

    public function insertPropertyOpening(\MyInvoice\Service\Property\Opening\OpeningOwnerProof $proof):array
    {
            $original=$proof->original($this->db);$journals=[];
            foreach($proof->parts($this->db) as $part){
                $journal=$proof->journal($this->db,$part);
                (new SystemDimensionWritePolicy($this->db))->assertPropertyOpening($journal['header'],$journal['lines'],$proof,$part);
                $journals[$part]=$journal;
            }
            $result=['journal_entry_id'=>null,'reversal_entry_id'=>null];
            foreach($journals as $part=>$journal){
                $entry=$this->persistJournal($journal['header'],$journal['lines'],typedPairs:TypedJournalPairs::SPLIT);
                if($part==='reversal'){
                    if($original===null||!$this->setReversedByLocked($original['entry']['id'],$proof->supplier,$entry))\MyInvoice\Service\Property\Opening\OpeningException::reject('reversal_conflict');
                    $result['reversal_entry_id']=$entry;
                }else $result['journal_entry_id']=$entry;
                $this->auditTypedPosting($journal['header'],$entry,$part==='reversal'?(int)$original['entry']['id']:null,['property_opening'=>$part]);
            }
            return $result;
    }

    public function insertPropertyClearing(\MyInvoice\Service\Property\Payments\Workflow\PaymentClearingIntent $intent, ?string $documentNumber = null): int
    {
        $intent->assertConnection($this->db);
        $proof = $intent->journal();
        if ($documentNumber !== null) {
            if ($proof['header']['source_type'] !== 'cash' || trim($documentNumber) === '' || mb_strlen($documentNumber) > 100) throw new \LogicException('A cash number requires the current cash owner.');
            $proof['header']['document_no'] = $documentNumber;
        }
        $entry = $this->persistJournal($proof['header'], $proof['lines'], typedPairs: TypedJournalPairs::SPLIT);
        $this->auditTypedPosting($proof['header'], $entry, null, ['property_clearing' => true]);
        $intent->bindJournal($entry);
        return $entry;
    }

    public function insertPropertySourceRevision(\MyInvoice\Service\Property\Payments\Workflow\ClearingRevisionIntent $intent): int
    {
        return (new DimensionPeriodGuard($this->db))->transaction(function () use ($intent): int {
            $proof = $intent->journal($this->db);
            $entry = $this->persistJournal($proof['header'], $proof['lines'], typedPairs: TypedJournalPairs::SPLIT);
            $this->auditTypedPosting($proof['header'], $entry, null, ['property_source_revision' => true]);
            $intent->complete($this->db, $entry);
            return $entry;
        });
    }

    public function insertPropertySettlement(\MyInvoice\Service\Property\Payments\Workflow\PaymentSettlementIntent $intent): int
    {
        $proof = $intent->journal($this->db);
        $entry = $this->persistJournal($proof['header'], $proof['lines'], typedPairs: TypedJournalPairs::SPLIT);
        $this->auditTypedPosting($proof['header'], $entry, null, ['property_settlement' => true]);
        $stmt = $this->db->pdo()->prepare("UPDATE unit_payment_operations SET journal_entry_id=? WHERE supplier_id=? AND id=? AND status='pending' AND journal_entry_id IS NULL");
        $stmt->execute([$entry, $intent->supplier, $intent->operationId]);
        if ($stmt->rowCount() !== 1) \MyInvoice\Service\Property\Payments\PaymentException::reject('settlement_intent');
        return $entry;
    }

    public function insertNonprofitCapital(\MyInvoice\Service\Nonprofit\Capital\CapitalJournalProof $proof):int
    {
        return(new DimensionPeriodGuard($this->db))->transaction(function()use($proof):int{
            $journal=$proof->journal($this->db);
            (new SystemDimensionWritePolicy($this->db))->assertNonprofitCapital($journal['header'],$journal['lines'],$proof);
            \MyInvoice\Service\Accounting\PostingService::assertBalanced($journal['lines']);
            $entry=$journal['source_entry_id']===null?$this->persistJournal($journal['header'],$journal['lines']):$this->insertReversalLocked($journal['header'],$journal['lines'],$journal['source_entry_id']);
            $this->auditTypedPosting($journal['header'],$entry,$journal['source_entry_id'],['typed_owner'=>$proof::class]);
            $proof->complete($this->db,$entry);return $entry;
        });
    }

    public function insertPropertyFunding(\MyInvoice\Service\Property\Funds\FundingProof $proof):int
    {
        return(new DimensionPeriodGuard($this->db))->transaction(function()use($proof):int{
            $journal=$proof->journal($this->db);
            (new SystemDimensionWritePolicy($this->db))->assertPropertyFunding($journal['header'],$journal['lines'],$proof);
            \MyInvoice\Service\Accounting\PostingService::assertBalanced($journal['lines']);
            $entry=$journal['source_entry_id']===null?$this->persistJournal($journal['header'],$journal['lines'],typedPairs:TypedJournalPairs::SPLIT):$this->insertReversalLocked($journal['header'],$journal['lines'],$journal['source_entry_id']);
            $this->auditTypedPosting($journal['header'],$entry,$journal['source_entry_id'],['typed_owner'=>$proof::class]);
            $proof->complete($this->db,$entry);return $entry;
        });
    }
    public function insertPropertyServiceLifecycle(\MyInvoice\Service\Property\Services\Settlement\ServiceLifecycleProof $proof):int
    {
        return(new DimensionPeriodGuard($this->db))->transaction(function()use($proof):int{
            $journal=$proof->journal($this->db);
            (new SystemDimensionWritePolicy($this->db))->assertPropertyServiceLifecycle($journal['header'],$journal['lines'],$proof);
            \MyInvoice\Service\Accounting\PostingService::assertBalanced($journal['lines']);
            $entry=$journal['source_entry_id']===null?$this->persistJournal($journal['header'],$journal['lines'],typedPairs:TypedJournalPairs::SPLIT):$this->insertReversalLocked($journal['header'],$journal['lines'],$journal['source_entry_id']);
            $this->auditTypedPosting($journal['header'],$entry,$journal['source_entry_id'],['typed_owner'=>$proof::class]);
            $proof->complete($this->db,$entry);return $entry;
        });
    }

    public function insertPropertyServiceSettlement(\MyInvoice\Service\Property\Services\Settlement\SettlementApplicationIntent $intent): int
    {
        $intent->claim($this->db);
        return (new DimensionPeriodGuard($this->db))->transaction(function () use ($intent): int {
            $proof = $intent->journal($this->db);
            if ($proof['source_entry_id'] !== null) throw new \LogicException('An application cannot reverse a source.');
            (new \MyInvoice\Service\Accounting\Dimension\SystemDimensionWritePolicy($this->db))->assertPropertyServiceSettlementJournal($proof['header'], $proof['lines'], $intent->carrier($this->db));
            \MyInvoice\Service\Accounting\PostingService::assertBalanced($proof['lines']);
            $entry = $this->persistJournal($proof['header'], $proof['lines'], typedPairs: TypedJournalPairs::SPLIT);
            $this->auditTypedPosting($proof['header'], $entry, null, ['property_service_settlement' => true]);
            $intent->complete($this->db, $entry);
            return $entry;
        });
    }

    public function insertPropertyServiceSettlementReversal(\MyInvoice\Service\Property\Services\Settlement\SettlementReversalIntent $intent): int
    {
        $intent->claim($this->db);
        return (new DimensionPeriodGuard($this->db))->transaction(function () use ($intent): int {
            $proof = $intent->journal($this->db);
            if ($proof['source_entry_id'] === null) throw new \LogicException('A reversal requires its current source.');
            $entry = $this->insertReversalLocked($proof['header'], $proof['lines'], $proof['source_entry_id']);
            $this->auditTypedPosting($proof['header'], $entry, $proof['source_entry_id'], ['property_service_settlement' => true]);
            $intent->complete($this->db, $entry);
            return $entry;
        });
    }

    public function insertPropertySetoff(\MyInvoice\Service\Property\Payments\Workflow\PaymentSetoffIntent $intent): int
    {
        $intent->claim($this->db);
        return (new DimensionPeriodGuard($this->db))->transaction(function () use ($intent): int {
            $proof = $intent->journal($this->db);
            $entry = $this->persistJournal($proof['header'], $proof['lines'], typedPairs: TypedJournalPairs::SPLIT);
            $this->auditTypedPosting($proof['header'], $entry, null, ['property_setoff' => true]);
            $intent->complete($this->db, $entry);
            return $entry;
        });
    }

    public function insertPropertyPaymentReversal(\MyInvoice\Service\Property\Payments\PaymentReversalIntent $intent): int
    {
        return (new DimensionPeriodGuard($this->db))->transaction(function () use ($intent): int {
        $intent = $intent->current($this->db);
        $source = $this->currentEntryForDimensionWrite($intent->sourceEntryId, $intent->supplierId);
        if ($source === null) \MyInvoice\Service\Property\Payments\PaymentException::reject('reversal_intent');
        $period = (new AccountingPeriodRepository($this->db))->findForDateForUpdate($intent->supplierId, $intent->accountingDate);
        if ($period === null || $period['status'] !== 'open') \MyInvoice\Service\Property\Payments\PaymentException::reject('target_period_not_open');
        $lines = array_map(static fn (array $line): array => array_replace($line, JournalLineAmount::reversal($line)), $source['lines']);
        $header = [
            'supplier_id' => $intent->supplierId, 'period_id' => (int) $period['id'],
            'entry_date' => $intent->accountingDate, 'document_date' => $source['document_date'],
            'document_no' => $source['document_no'] === null ? null : mb_substr('STORNO ' . $source['document_no'], 0, 50),
            'description' => $intent->description, 'source_type' => $source['source_type'], 'source_id' => null,
            'posted_at' => date('Y-m-d H:i:s'), 'posted_by' => $intent->actor,
        ];
        $entry = $this->insertReversalLocked($header, $lines, $intent->sourceEntryId);
        $intent->complete($this->db, $entry);
        $this->activity->log('accounting.reversed', $intent->actor, 'journal_entry', $entry,
            ['original_entry_id' => $intent->sourceEntryId, 'reversal_entry_id' => $entry, 'lines' => $lines], null, null, $intent->supplierId);
        return $entry;
        });
    }

    public function insertCurrentAllocation(int $supplierId, int $batchId): int
    {
        return (new DimensionPeriodGuard($this->db))->transaction(function () use ($supplierId, $batchId): int {
            $proof = (new JointAllocationJournalProof($this->db))->apply($supplierId, $batchId);
            $entryId = $this->persistJournal($proof['header'], $proof['lines']);
            $this->auditTypedPosting($proof['header'], $entryId, null, ['joint_allocation_batch_id' => $batchId]);
            $stmt = $this->db->pdo()->prepare('UPDATE joint_allocation_batches SET journal_entry_id = ? WHERE supplier_id = ? AND id = ? AND journal_entry_id IS NULL');
            $stmt->execute([$entryId, $supplierId, $batchId]);
            if ($stmt->rowCount() !== 1) {
                throw new JointAllocationException('allocation_changed', 'Alokace se během zápisu změnila.', 409);
            }
            $batch = $proof['batch'];
            (new JointAllocationAudit($this->db))->record($supplierId, 'batch', $batchId, 'apply', [
                'journal_entry_id' => $entryId, 'key_version_id' => (int) $batch['key_version_id'],
                'preview_hash' => $batch['preview_hash'], 'reason' => $batch['reason'],
            ], $batch['created_by'] === null ? null : (int) $batch['created_by']);
            return $entryId;
        });
    }

    public function reverseCurrentAllocation(int $supplierId, int $cancellationId): int
    {
        return (new DimensionPeriodGuard($this->db))->transaction(function () use ($supplierId, $cancellationId): int {
            $proof = (new JointAllocationJournalProof($this->db))->cancellation($supplierId, $cancellationId);
            $entryId = $this->persistJournal($proof['header'], $proof['lines']);
            $this->auditTypedPosting($proof['header'], $entryId, null, ['joint_allocation_cancellation_id' => $cancellationId]);
            $pdo = $this->db->pdo();
            $stmt = $pdo->prepare('UPDATE joint_allocation_cancellations SET reversal_entry_id = ? WHERE supplier_id = ? AND id = ? AND reversal_entry_id IS NULL');
            $stmt->execute([$entryId, $supplierId, $cancellationId]);
            if ($stmt->rowCount() !== 1) {
                throw new JointAllocationException('allocation_changed', 'Zrušení alokace se během zápisu změnilo.', 409);
            }
            $batch = $proof['batch'];
            $release = $pdo->prepare('DELETE FROM joint_allocation_source_claims WHERE supplier_id = ? AND batch_id = ?');
            $release->execute([$supplierId, $batch['id']]);
            if ($release->rowCount() !== count($proof['source_line_ids'])) {
                throw new JointAllocationException('allocation_changed', 'Vazby zdrojových řádků se změnily.', 409);
            }
            $cancellation = $proof['cancellation'];
            (new JointAllocationAudit($this->db))->record($supplierId, 'cancellation', $cancellationId, 'cancel', [
                'batch_id' => (int) $batch['id'], 'reversal_entry_id' => $entryId, 'reason' => $cancellation['reason'],
            ], $cancellation['created_by'] === null ? null : (int) $cancellation['created_by']);
            return $entryId;
        });
    }

    public function insertCurrentLegalClosing(int $supplierId, int $periodId, int $expectedVersion): int
    {
        return (new DimensionPeriodGuard($this->db))->transaction(function () use ($supplierId, $periodId, $expectedVersion): int {
            $proof = \MyInvoice\Service\Accounting\Closing\ClosingLegal504Proof::forPending($this->db, $this, $supplierId, $periodId, $expectedVersion);
            (new SystemDimensionWritePolicy($this->db))->assertLegalClosing($proof);
            if ($proof->pending['status'] === 'written') return (int) $proof->pending['entry_id'];
            $entryId = $this->persistJournal($proof->header, $proof->lines, typedPairs: TypedJournalPairs::CONSECUTIVE);
            $payload = $proof->stepRow['decoded_payload'];
            $payload['entries'][] = ['entry_id' => $entryId] + $proof->record;
            $payload['legal_pending'] = array_replace($proof->pending, ['status' => 'written', 'entry_id' => $entryId,
                'entry_version' => 1, 'source_line_ids' => $proof->sourceLineIds]);
            $stmt = $this->db->pdo()->prepare('UPDATE accounting_closing_steps SET payload=? WHERE id=? AND supplier_id=? AND payload <=> ?');
            $stmt->execute([json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $proof->stepRow['id'], $supplierId, $proof->stepRow['payload']]);
            if ($stmt->rowCount() !== 1) throw $proof::changed();
            $stmt = $this->db->pdo()->prepare("UPDATE accounting_periods SET row_version=row_version+1 WHERE supplier_id=? AND id=? AND status='closing' AND row_version=?");
            $stmt->execute([$supplierId, $periodId, $expectedVersion]);
            if ($stmt->rowCount() !== 1) throw $proof::changed();
            $meta = $proof->pending['meta'] ?? [];
            $this->activity->log('accounting.posted', $meta['user_id'] ?? null, 'journal_entry', $entryId,
                ['source_type' => 'manual', 'source_id' => null, 'legal_source_line_ids' => $proof->sourceLineIds],
                $meta['ip'] ?? null, $meta['user_agent'] ?? null, $supplierId);
            return $entryId;
        });
    }

    public function insertCurrentAssetReserveCorrection(int $supplierId, int $periodId, int $expectedVersion): array
    {
        return (new DimensionPeriodGuard($this->db))->transaction(function () use ($supplierId, $periodId, $expectedVersion): array {
            $proof = \MyInvoice\Service\Accounting\Closing\AssetReserveCorrection504Proof::forPending($this->db, $this, $supplierId, $periodId, $expectedVersion);
            if ($proof->pending['status'] === 'written') return $proof->revision;
            $entryId = null;
            if ($proof->lines !== []) {
                (new SystemDimensionWritePolicy($this->db))->assertAssetReserveCorrection($proof);
                $entryId = $this->persistJournal($proof->header, $proof->lines, typedPairs: TypedJournalPairs::CONSECUTIVE);
                $meta = $proof->pending['meta'] ?? [];
                $this->activity->log('accounting.posted', $meta['user_id'] ?? null, 'journal_entry', $entryId,
                    ['source_type' => $proof->header['source_type'] ?? 'manual', 'source_id' => $proof->header['source_id'] ?? null, 'asset_reserve_correction' => true],
                    $meta['ip'] ?? null, $meta['user_agent'] ?? null, $supplierId);
            }
            $stmt = $this->db->pdo()->prepare('SELECT id FROM journal_entries WHERE supplier_id=? ORDER BY id DESC LIMIT 1 FOR UPDATE');
            $stmt->execute([$supplierId]);
            $lastId = (int) $stmt->fetchColumn();
            $revision = \MyInvoice\Service\Accounting\Closing\AssetReserveSourceDecision::sealRevision($proof->revision + ['entry_id' => $entryId, 'sequence_entry_id' => $lastId]);
            $payload = $proof->stepRow['decoded_payload'];
            $payload['asset_source_revisions'][] = $revision;
            $payload['asset_correction_pending'] = array_replace($proof->pending, ['status' => 'written', 'revision_index' => array_key_last($payload['asset_source_revisions'])]);
            $stmt = $this->db->pdo()->prepare('UPDATE accounting_closing_steps SET payload=? WHERE id=? AND supplier_id=? AND payload <=> ?');
            $stmt->execute([\MyInvoice\Service\Accounting\Allocation\JointAllocationKeyVersion::encodeJson($payload), $proof->stepRow['id'], $supplierId, $proof->stepRow['payload']]);
            if ($stmt->rowCount() !== 1) throw \MyInvoice\Service\Accounting\Closing\AssetReserveSourceDecision::changed();
            $stmt = $this->db->pdo()->prepare("UPDATE accounting_periods SET row_version=row_version+1 WHERE supplier_id=? AND id=? AND status='closing' AND row_version=?");
            $stmt->execute([$supplierId, $periodId, $expectedVersion]);
            if ($stmt->rowCount() !== 1) throw \MyInvoice\Service\Accounting\Closing\AssetReserveSourceDecision::changed();
            return $revision;
        });
    }

    public function insertCurrentDeferredTax504(int $supplierId, int $periodId, int $expectedVersion): array
    {
        return (new DimensionPeriodGuard($this->db))->transaction(function () use ($supplierId, $periodId, $expectedVersion): array {
            $proof = \MyInvoice\Service\Accounting\Closing\DeferredTax504Proof::forPending($this->db, $this, $supplierId, $periodId, $expectedVersion);
            if ($proof->pending['status'] === 'written') return ['entry_ids' => array_column($proof->batches, 'entry_id'), 'assessment' => $proof->assessment];
            $policy = new SystemDimensionWritePolicy($this->db);
            foreach (array_keys($proof->batches) as $index) $policy->assertDeferredTax504($proof->batch($index));
            $batches = [];
            foreach ($proof->batches as $batch) {
                $entryId = $this->persistJournal($batch['header'], $batch['lines'], typedPairs: TypedJournalPairs::CONSECUTIVE);
                $batches[] = ['phase' => $batch['phase'], 'entry_id' => $entryId, 'entry_version' => 1];
                $meta = $proof->pending['meta'] ?? [];
                $this->activity->log('accounting.posted', $meta['user_id'] ?? null, 'journal_entry', $entryId,
                    ['source_type' => 'manual', 'source_id' => null, 'deferred_tax_method' => $proof->assessment['method']['version']],
                    $meta['ip'] ?? null, $meta['user_agent'] ?? null, $supplierId);
            }
            $payload = $proof->stepRow['decoded_payload'];
            $payload['deferred504_decisions'][] = ['body' => $proof->pending['body'], 'assessment' => $proof->assessment, 'batches' => $batches];
            $payload['deferred504_pending'] = array_replace($proof->pending, ['status' => 'written', 'record_index' => array_key_last($payload['deferred504_decisions']), 'source_line_ids' => $proof->sourceLineIds]);
            $stmt = $this->db->pdo()->prepare("UPDATE accounting_closing_steps SET payload=?,status='done',done_at=NOW(),done_by=? WHERE id=? AND supplier_id=? AND payload <=> ?");
            $stmt->execute([json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $proof->pending['meta']['user_id'] ?? null, $proof->stepRow['id'], $supplierId, $proof->stepRow['payload']]);
            if ($stmt->rowCount() !== 1) throw $proof::changed();
            $stmt = $this->db->pdo()->prepare("UPDATE accounting_periods SET row_version=row_version+1 WHERE supplier_id=? AND id=? AND status='closing' AND row_version=?");
            $stmt->execute([$supplierId, $periodId, $expectedVersion]);
            if ($stmt->rowCount() !== 1) throw $proof::changed();
            return ['entry_ids' => array_column($batches, 'entry_id'), 'assessment' => $proof->assessment];
        });
    }

    public function upsertCurrentFx504(int $supplierId, int $stepId, string $slot, int $expectedVersion): ?int
    {
        return (new DimensionPeriodGuard($this->db))->transaction(function () use ($supplierId, $stepId, $slot, $expectedVersion): ?int {
            $proof = Fx504JournalProof::forPending($this->db, $supplierId, $stepId, $slot, $expectedVersion);
            (new SystemDimensionWritePolicy($this->db))->assertFx504($proof);
            $header = $proof->header;
            $previous = $this->findActiveBySourceForUpdate($supplierId, 'fx_revaluation', $header['source_id']);
            $record = $proof->record['slots'][$slot];
            if (($previous === null ? null : (int) $previous['id']) !== ($record['previous_entry_id'] ?? null)
                || ($previous === null ? null : (int) $previous['row_version']) !== ($record['previous_entry_version'] ?? null)) throw Fx504JournalProof::changed();
            $entryId = null;
            if ($proof->lines === []) {
                if ($previous !== null) throw Fx504JournalProof::changed();
            } elseif ($previous === null) {
                $entryId = $this->persistJournal($header, $proof->lines, typedPairs: TypedJournalPairs::CONSECUTIVE);
            } else {
                $entryId = (int) $previous['id'];
                (new \MyInvoice\Service\Accounting\Closing\SecuritiesDebt504UseGuard($this->db))->assertEntriesMutable($supplierId, [$entryId]);
                (new JointAllocationUseGuard($this->db))->assertEntriesMutable($supplierId, [$entryId]);
                $stmt = $this->db->pdo()->prepare('UPDATE journal_entries SET document_no = ?, description = ?, posted_at = ?, posted_by = ?, row_version = row_version + 1
                    WHERE supplier_id = ? AND id = ? AND period_id = ? AND entry_date = ? AND row_version = ? AND reversed_by IS NULL');
                $stmt->execute([$header['document_no'], $header['description'], $header['posted_at'], $header['posted_by'], $supplierId, $entryId,
                    $header['period_id'], $header['entry_date'], $previous['row_version']]);
                if ($stmt->rowCount() !== 1) throw Fx504JournalProof::changed();
                $this->deleteLines($entryId, $supplierId);
                [$pairHeader, $lines] = $this->pairTypedLines($header + ['supplier_id' => $supplierId], $proof->lines, TypedJournalPairs::CONSECUTIVE);
                $this->insertLines($this->db->pdo(), $entryId, $pairHeader + ['pair_typed_owner' => true], $lines);
                $this->logReleasedPairings($supplierId, $entryId, (new JournalLinePairingRepository($this->db))->releaseStale($supplierId, $entryId), 'repost');
            }
            if ($entryId !== null) {
                $postedBy = $record['posted_by'] ?? $proof->record['posted_by'] ?? null;
                $this->activity->log($previous === null ? 'accounting.posted' : 'accounting.reposted', $postedBy === null ? null : (int) $postedBy, 'journal_entry', $entryId,
                    ['source_type' => 'fx_revaluation', 'source_id' => $header['source_id'], 'fx504_slot' => $slot], null, null, $supplierId);
            }
            $payload = $proof->stepRow['decoded_payload'];
            $payload['fx504']['slots'][$slot] = array_replace($record, ['status' => 'written', 'entry_id' => $entryId]);
            $stmt = $this->db->pdo()->prepare('UPDATE accounting_closing_steps SET payload = ? WHERE supplier_id = ? AND id = ? AND payload <=> ?');
            $stmt->execute([json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $supplierId, $stepId, $proof->stepRow['payload']]);
            if ($stmt->rowCount() !== 1) throw Fx504JournalProof::changed();
            return $entryId;
        });
    }

    public function upsertCurrentClosingCarry(int $supplierId, int $periodId, string $step, int $expectedVersion): int
    {
        return (new DimensionPeriodGuard($this->db))->transaction(function () use ($supplierId, $periodId, $step, $expectedVersion): int {
            $proof = \MyInvoice\Service\Accounting\Closing\ClosingCarryJournalProof::forPending($this->db, $supplierId, $periodId, $step, $expectedVersion);
            (new SystemDimensionWritePolicy($this->db))->assertClosingCarry($proof);
            $header = $proof->header;
            $existing = $this->findActiveBySourceForUpdate($supplierId, $header['source_type'], $header['source_id']);
            if ($proof->record['status'] === 'written') {
                if ($existing === null || (int) $existing['id'] !== ($proof->record['entry_id'] ?? 0)
                    || (int) $existing['row_version'] !== ($proof->record['entry_version'] ?? 0)) throw \MyInvoice\Repository\ClosingCarryReadRepository::changed();
                foreach ($header as $field => $value) {
                    if ((string) ($existing[$field] ?? '') !== (string) ($value ?? '')) throw \MyInvoice\Repository\ClosingCarryReadRepository::changed();
                }
                $current = $this->currentEntryForDimensionWrite((int) $existing['id'], $supplierId);
                if (count($current['lines']) !== count($proof->lines)) throw \MyInvoice\Repository\ClosingCarryReadRepository::changed();
                foreach ($proof->lines as $index => $line) {
                    $old = $current['lines'][$index];
                    if (!SystemDimensionWritePolicy::sameLedgerLine($line, $old)
                        || ($line['dimensions'] ?? []) !== $old['dimensions']
                        || !DimensionAssignmentRepository::sameSplits($line['dimension_splits'] ?? [], $old['dimension_splits'])) throw \MyInvoice\Repository\ClosingCarryReadRepository::changed();
                }
                return (int) $existing['id'];
            }
            if (($existing === null ? null : (int) $existing['id']) !== ($proof->record['previous_entry_id'] ?? null)
                || ($existing === null ? null : (int) $existing['row_version']) !== ($proof->record['previous_entry_version'] ?? null)) throw \MyInvoice\Repository\ClosingCarryReadRepository::changed();
            if ($existing === null) {
                $entryId = $this->persistJournal($header, $proof->lines, typedPairs: TypedJournalPairs::CONSECUTIVE);
                $entryVersion = 1;
            } else {
                $entryId = (int) $existing['id'];
                (new \MyInvoice\Service\Property\Charges\Issuance\IssuanceUseGuard($this->db))->assertPostingEntriesMutable($supplierId, [$entryId]);
                (new \MyInvoice\Service\Property\Payments\Workflow\PaymentMutationContext($this->db))->assertEntriesMutable($supplierId, [$entryId]);
                (new JointAllocationUseGuard($this->db))->assertEntriesMutable($supplierId, [$entryId]);
                (new \MyInvoice\Service\Accounting\Closing\AssetReserveSourceUseGuard($this->db, $this))->assertEntriesMutable($supplierId, [$entryId]);
                $locked = $this->db->pdo()->prepare('SELECT locked_until FROM accounting_supplier_settings WHERE supplier_id = ? FOR UPDATE');
                $locked->execute([$supplierId]);
                $lockedUntil = $locked->fetchColumn();
                if ($lockedUntil && $existing['entry_date'] <= $lockedUntil) throw new PostingException('date_locked', 'Datum původního zápisu leží v uzamčené části účetnictví.', 422);
                $this->replaceVerifiedClosingCarry($entryId, (int) $existing['row_version'], $proof);
                $entryVersion = (int) $existing['row_version'] + 1;
            }
            $payload = $proof->stepRow['decoded_payload'];
            $payload['carry'][$step] = array_replace($proof->record, ['status' => 'written', 'entry_id' => $entryId,
                'entry_version' => $entryVersion, 'source_line_ids' => $proof->sourceLineIds]);
            $cas = $this->db->pdo()->prepare('UPDATE accounting_closing_steps SET payload = ? WHERE id = ? AND supplier_id = ? AND payload <=> ?');
            $cas->execute([json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $proof->stepRow['id'], $supplierId, $proof->stepRow['payload']]);
            if ($cas->rowCount() !== 1) throw \MyInvoice\Repository\ClosingCarryReadRepository::changed();
            $meta = $proof->record['meta'] ?? [];
            $this->activity->log($existing === null ? 'accounting.posted' : 'accounting.reposted', $meta['user_id'] ?? null, 'journal_entry', $entryId,
                ['source_type' => $header['source_type'], 'source_id' => $header['source_id'], 'closing_carry_source_hash' => $proof->record['source_hash']],
                $meta['ip'] ?? null, $meta['user_agent'] ?? null, $supplierId);
            return $entryId;
        });
    }

    private function replaceVerifiedClosingCarry(int $entryId, int $entryVersion, \MyInvoice\Service\Accounting\Closing\ClosingCarryJournalProof $proof): void
    {
        $proof->assertConnection($this->db);
        $header = $proof->header;
        $stmt = $this->db->pdo()->prepare('UPDATE journal_entries SET entry_date = ?, document_date = ?, document_no = ?, description = ?,
            posted_at = ?, posted_by = ?, row_version = row_version + 1 WHERE supplier_id = ? AND id = ? AND period_id = ? AND row_version = ? AND reversed_by IS NULL');
        $stmt->execute([$header['entry_date'], $header['document_date'], $header['document_no'], $header['description'], $header['posted_at'], $header['posted_by'],
            $header['supplier_id'], $entryId, $header['period_id'], $entryVersion]);
        if ($stmt->rowCount() !== 1) throw \MyInvoice\Repository\ClosingCarryReadRepository::changed();
        $this->deleteLines($entryId, $header['supplier_id']);
        [$pairHeader, $lines] = $this->pairTypedLines($header, $proof->lines, TypedJournalPairs::CONSECUTIVE);
        $this->insertLines($this->db->pdo(), $entryId, $pairHeader + ['pair_typed_owner' => true], $lines);
        $this->logReleasedPairings($header['supplier_id'], $entryId,
            (new JournalLinePairingRepository($this->db))->releaseStale($header['supplier_id'], $entryId), 'repost');
    }

    /** § 33a: typovaný zapisovač nejde přes PostingService, auditní stopu zápisu proto zapisuje sám (invariant I28). */
    private function auditTypedPosting(array $header, int $entryId, ?int $sourceEntryId, array $context): void
    {
        $actor = isset($header['posted_by']) ? (int) $header['posted_by'] : null;
        $payload = $sourceEntryId === null
            ? ['source_type' => $header['source_type'] ?? 'manual', 'source_id' => $header['source_id'] ?? null]
            : ['original_entry_id' => $sourceEntryId, 'reversal_entry_id' => $entryId];
        $this->activity->log($sourceEntryId === null ? 'accounting.posted' : 'accounting.reversed', $actor, 'journal_entry', $entryId,
            $payload + $context, null, null, (int) $header['supplier_id']);
    }

    /**
     * @param bool $typedOwner zápis typovaného zapisovače (SVJ, NNO, uzávěrka 504, převod
     *        rámce): bez `$typedPairs` dvojice netvoří, v jednoduché formě se vede jako `ineligible`
     * @param ?string $typedPairs režim {@see TypedJournalPairs} typovaného zápisu, jehož důkazy
     *        přijmou zápis po dvojicích: v jednoduché formě se rozdělí, složená forma beze změny
     */
    private function persistJournal(array $header, array $lines, bool $typedOwner = true, ?string $typedPairs = null): int
    {
        $pdo = $this->db->pdo();
        if (!$pdo->inTransaction()) {
            throw new \LogicException('Journal persistence requires a transaction.');
        }
        if ($typedOwner && $typedPairs !== null) {
            [$header, $lines] = $this->pairTypedLines($header, $lines, $typedPairs);
        }
        (new MembershipUseGuard($this->db))->assertHeaderOwner($header);
        if (($header['posted_at'] ?? null) !== null) (new \MyInvoice\Service\Accounting\Funding\NativeCostCoverage($this->db))->assertCreditNotePosting($header);
        $pdo->prepare(
            'INSERT INTO journal_entries
                (supplier_id, period_id, entry_date, document_date, document_no, description,
                 source_type, source_id, posted_at, posted_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $header['supplier_id'],
            $header['period_id'],
            $header['entry_date'],
            $header['document_date'] ?? null,
            $header['document_no'] ?? null,
            $header['description'] ?? null,
            $header['source_type'] ?? 'manual',
            $header['source_id'] ?? null,
            $header['posted_at'] ?? null,
            $header['posted_by'] ?? null,
        ]);
        $entryId = (int) $pdo->lastInsertId();
        $this->insertLines($pdo, $entryId, $header + ['pair_typed_owner' => $typedOwner], $lines);
        return $entryId;
    }

    /**
     * Řádky typovaného zápisu (modul nemovitostí SVJ, kroky uzávěrky 504) ve firmě
     * s jednoduchou formou k datu zápisu: rozdělí je na dvojice jediným pravidlem
     * {@see TypedJournalPairs::pair()}, se kterým porovnávají i jejich důkazy. Co rozdělit
     * nejde, zůstane složené s posouzením `ineligible` a důvodem párovače.
     *
     * Číslo dvojice převzaté ze zdrojového zápisu (protiřádek banky, řádek předpisu)
     * do nového zápisu nepatří a zahodí se v obou formách; firma, která jednoduchou
     * formu nikdy neměla, takové řádky nemá a zapisuje bajtově stejně.
     *
     * @param array<string,mixed> $header
     * @param list<array<string,mixed>> $lines
     * @param TypedJournalPairs::SPLIT|TypedJournalPairs::CONSECUTIVE $mode
     * @return array{array<string,mixed>, list<array<string,mixed>>}
     */
    private function pairTypedLines(array $header, array $lines, string $mode = TypedJournalPairs::SPLIT): array
    {
        if (isset($header['pair_status'])) {
            return [$header, $lines];
        }
        if (TypedJournalPairs::hasPairs($lines)) {
            $lines = TypedJournalPairs::withoutCarriedPairs($lines);
        }
        if (!$this->entryForms()->formAt((int) $header['supplier_id'], (string) $header['entry_date'], currentRead: true)->isSingle()) {
            return [$header, $lines];
        }
        $result = TypedJournalPairs::pair($lines, $mode);
        if ($result['status'] === JournalPairer::STATUS_PAIRED) {
            return [$header, $result['lines']];
        }
        $header['pair_status'] = 'ineligible';
        $header['pair_status_reason'] = TypedJournalPairs::REASON_PREFIX . ($result['reason'] ?? JournalPairer::REASON_MANY_TO_MANY);
        return [$header, $lines];
    }

    public function insertReversal(array $header, array $lines, int $sourceEntryId): int
    {
        return (new DimensionPeriodGuard($this->db))->transaction(function () use ($header, $lines, $sourceEntryId): int {
            (new IssuanceUseGuard($this->db))->assertPostingEntriesMutable((int) $header['supplier_id'], [$sourceEntryId]);
            (new \MyInvoice\Service\Property\Payments\Workflow\PaymentMutationContext($this->db))->assertEntriesMutable((int) $header['supplier_id'], [$sourceEntryId]);
            return $this->insertReversalLocked($header, $lines, $sourceEntryId);
        });
    }

    public function insertPropertyChargeReversal(int $supplierId, int $intentId): int
    {
        return (new DimensionPeriodGuard($this->db))->transaction(function () use ($supplierId, $intentId): int {
            return $this->persistPropertyChargeReversal(IssuanceReversalIntent::fromCurrentRequest($this->db,$supplierId,$intentId));
        });
    }

    public function insertPropertyCorrectionChargeReversal(\MyInvoice\Service\Property\Payments\PaymentCorrectionReversalIntent $correction):array
    {
        $correction->claim($this->db);
        return (new DimensionPeriodGuard($this->db))->transaction(function()use($correction):array{
            $correction->prepareWrite($this->db);
            $intent=IssuanceReversalIntent::fromPaymentCorrection($this->db,$correction);
            $this->persistPropertyChargeReversal($intent);
            return $correction->complete($this->db);
        });
    }

    private function persistPropertyChargeReversal(IssuanceReversalIntent $intent):int
    {
        $supplierId=$intent->supplierId;
        $source = $this->currentEntryForDimensionWrite($intent->sourceEntryId, $supplierId);
        if ($source === null) throw new PostingException('entry_not_found', 'Účetní zápis nebyl nalezen.', 404);
        $lines = array_map(static fn (array $line): array => array_replace($line, JournalLineAmount::reversal($line)), $source['lines']);
        $header = [
            'supplier_id' => $supplierId, 'period_id' => $intent->targetPeriodId,
            'entry_date' => $intent->accountingDate, 'document_date' => $source['document_date'],
            'document_no' => $source['document_no'] === null ? null : mb_substr('STORNO ' . $source['document_no'], 0, 50),
            'description' => $intent->description, 'source_type' => $source['source_type'], 'source_id' => null,
            'posted_at' => date('Y-m-d H:i:s'), 'posted_by' => $intent->actor,
        ];
        $reversalId = $this->insertReversalLocked($header, $lines, $intent->sourceEntryId);
        $intent->complete($this->db, $reversalId);
        $this->activity->log('accounting.reversed', $intent->actor, 'journal_entry', $reversalId,
            ['original_entry_id' => $intent->sourceEntryId, 'reversal_entry_id' => $reversalId, 'lines' => $lines], null, null, $supplierId);
        return $reversalId;
    }

    private function insertReversalLocked(array $header, array $lines, int $sourceEntryId): int
    {
        $supplierId = (int) $header['supplier_id'];
        (new MembershipUseGuard($this->db))->assertEntriesMutable($supplierId, [$sourceEntryId]);
        (new \MyInvoice\Service\Accounting\Closing\SecuritiesDebt504UseGuard($this->db))->assertEntriesMutable($supplierId, [$sourceEntryId]);
        $source = $this->currentEntryForDimensionWrite($sourceEntryId, $supplierId);
        (new JointAllocationUseGuard($this->db))->assertEntriesMutable($supplierId, [$sourceEntryId]);
        (new \MyInvoice\Service\Accounting\Closing\AssetReserveSourceUseGuard($this->db, $this))->assertReversal($supplierId, $sourceEntryId);
        $invalid = static fn (): PostingException => new PostingException('invalid_reversal_source', 'Protizápis neodpovídá živému zaúčtovanému zdroji.', 422);
        if ($source !== null) {
            // Dvojice už stornované nebo přeúčtované opravným zápisem dvojice se nezrcadlí znovu.
            $reversedPairs = array_flip($this->reversedPairNos($supplierId, $sourceEntryId));
            if ($reversedPairs !== []) {
                $source['lines'] = array_values(array_filter(
                    $source['lines'],
                    static fn (array $line): bool => !isset($line['pair_no']) || !isset($reversedPairs[(int) $line['pair_no']]),
                ));
            }
            // Typovaný vlastník (SVJ) skládá protizápis z plánu v tvaru složeného zápisu, zdroj
            // po dvojicích ale leží rozdělený. Plán se vezme v tom tvaru dvojic, ve kterém zdroj
            // leží (totéž deterministické pravidlo jako zápis zdroje); kontrola řádek po řádku
            // proti živému zdroji níže platí beze změny.
            if (TypedJournalPairs::hasPairs($source['lines']) && !TypedJournalPairs::hasPairs($lines)
                && count($source['lines']) !== count($lines)) {
                $lines = TypedJournalPairs::expectedFor($lines, array_map(
                    static fn (array $line): array => array_replace($line, JournalLineAmount::reversal($line)),
                    $source['lines'],
                ));
            }
        }
        if ($source === null || $source['posted_at'] === null || $source['reversed_by'] !== null || $source['source_type'] === 'payroll'
            || ($header['source_type'] ?? 'manual') !== $source['source_type']
            || ($header['source_id'] ?? null) !== null || ($header['posted_at'] ?? null) === null
            || count($source['lines']) !== count($lines) || $lines === []) {
            throw $invalid();
        }
        $period = $this->db->pdo()->prepare('SELECT status FROM accounting_periods WHERE id = ? AND supplier_id = ? AND starts_on <= ? AND ends_on >= ? FOR UPDATE');
        $period->execute([(int) $header['period_id'], $supplierId, $header['entry_date'], $header['entry_date']]);
        if (!in_array($period->fetchColumn(), ['open', 'closing'], true)) {
            throw $invalid();
        }
        $dimensions = static function (array $values): array {
            $values = array_map('intval', $values);
            ksort($values);
            return $values;
        };
        $mirror = [];
        foreach (array_values($lines) as $index => $line) {
            $original = $source['lines'][$index];
            $expected = array_replace($original, JournalLineAmount::reversal($original));
            if (!SystemDimensionWritePolicy::sameLedgerLine($line, $expected)
                || $dimensions($line['dimensions'] ?? []) !== $dimensions($original['dimensions'])
                || !DimensionAssignmentRepository::sameSplits($line['dimension_splits'] ?? [], $original['dimension_splits'])) {
                throw $invalid();
            }
            unset($expected['id'], $expected['entry_id'], $expected['supplier_id']);
            $expected['line_no'] = $index;
            $mirror[] = $expected;
        }
        // Storno zápisu po dvojicích je zase po dvojicích (čísla 1..k bez stornovaných
        // dvojic), i když spadne do období se složenou formou. Storno složeného zápisu
        // zůstává složené; v jednoduché formě se vede jako nezpůsobilé k rozdělení.
        $pairMap = [];
        foreach ($mirror as $i => $line) {
            if (isset($line['pair_no'])) {
                $pairMap[(int) $line['pair_no']] ??= count($pairMap) + 1;
                $mirror[$i]['pair_no'] = $pairMap[(int) $line['pair_no']];
            }
        }
        if ($pairMap !== []) {
            $header['pairs_inherited'] = true;
        } else {
            $header['pair_status'] ??= 'ineligible';
            $header['pair_status_reason'] ??= 'composite_reversal';
        }
        // Přesné storno ověřeného aktuálního zdroje zachová i chybějící historické
        // dimenze. Nové povinnosti ani alokační pravidla jeho význam nedoplňují.
        $reversalId = $this->persistJournal($header, $mirror, false);
        if (!$this->setReversedByLocked($sourceEntryId, $supplierId, $reversalId)) {
            throw new PostingException('already_reversed', 'Zápis #' . $sourceEntryId . ' byl mezitím stornován.');
        }
        return $reversalId;
    }

    /**
     * Přepíše existující zápis in-place (idempotentní re-post v otevřeném období):
     * aktualizuje hlavičku, smaže staré řádky a zapíše nové, zvýší row_version.
     * PŘEDPOKLAD: volající drží transakci (PostingService) — celé to musí být atomické.
     *
     * @param array{
     *     supplier_id:int, period_id:int, entry_date:string, document_date?:?string,
     *     document_no?:?string, description?:?string, source_type?:string,
     *     source_id?:?int, posted_at?:?string, posted_by?:?int
     * } $header
     * @param list<array{account_id:int, side:'debit'|'credit', amount:float|string, is_red_storno?:bool, cost_center?:?string, project_id?:?int, line_no?:int}> $lines
     */
    public function replace(int $id, array $header, array $lines): void
    {
        (new MembershipUseGuard($this->db))->assertHeaderOwner($header);
        (new MembershipUseGuard($this->db))->assertEntriesMutable((int) $header['supplier_id'], [$id]);
        (new IssuanceUseGuard($this->db))->assertPostingEntriesMutable((int) $header['supplier_id'], [$id]);
        $previous = $this->currentEntryForDimensionWrite($id, (int) $header['supplier_id']);
        (new JointAllocationUseGuard($this->db))->assertEntriesMutable((int) $header['supplier_id'], [$id]);
        if ($previous === null || $previous['reversed_by'] !== null) {
            throw new PostingException('entry_reversed', 'Zápis #' . $id . ' byl mezitím stornován — přepis není možný (§35).');
        }
        if ((new \MyInvoice\Service\Accounting\Closing\AssetReserveSourceUseGuard($this->db, $this))->assertEntryChange(
            (int) $header['supplier_id'], $id, $lines, $header['entry_date'], $header,
        )) return;
        if ((new \MyInvoice\Service\Tax\Nonprofit\NonprofitTaxSavingUseGuard($this->db, $this))->assertEntryChange((int) $header['supplier_id'], $id, $lines, $header['entry_date'], $header)) return;
        if ((new \MyInvoice\Service\Nonprofit\Capital\CapitalUseGuard($this->db, $this))->assertEntryChange((int) $header['supplier_id'], $id, $lines, $header['entry_date'], $header)) return;
        (new \MyInvoice\Service\Property\Payments\Workflow\PaymentMutationContext($this->db))->assertEntriesMutable((int) $header['supplier_id'], [$id]);
        if (($header['posted_at'] ?? null) !== null) (new \MyInvoice\Service\Accounting\Funding\NativeCostCoverage($this->db))->assertCreditNotePosting($header);
        (new \MyInvoice\Service\Accounting\JournalSettlementUseGuard($this->db))->assertEntryChange((int) $header['supplier_id'], $id, $lines);
        $validationPrevious = SystemDimensionWritePolicy::canRetainEntryAssignments($header, $previous) ? $previous['lines'] : [];
        (new SystemDimensionWritePolicy($this->db))->assertJournal($header, $lines, $validationPrevious);
        $retained = SystemDimensionWritePolicy::retainedAssignments($lines, $previous['lines']);
        foreach ($retained as $index => $assignment) {
            $lines[$index]['dimension_origins'] = ($assignment['dimension_origins'] ?? []) + ($lines[$index]['dimension_origins'] ?? []);
            foreach ($assignment['dimension_split_origins'] ?? [] as $typeId => $origins) {
                $lines[$index]['dimension_split_origins'][$typeId] = $origins + ($lines[$index]['dimension_split_origins'][$typeId] ?? []);
            }
        }
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            'UPDATE journal_entries
                SET period_id = ?, entry_date = ?, document_date = ?, document_no = ?,
                    description = ?, source_type = ?, source_id = ?, posted_at = ?,
                    posted_by = ?, row_version = row_version + 1
              WHERE id = ? AND supplier_id = ? AND reversed_by IS NULL'
        );
        $stmt->execute([
            $header['period_id'],
            $header['entry_date'],
            $header['document_date'] ?? null,
            $header['document_no'] ?? null,
            $header['description'] ?? null,
            $header['source_type'] ?? 'manual',
            $header['source_id'] ?? null,
            $header['posted_at'] ?? null,
            $header['posted_by'] ?? null,
            $id,
            $header['supplier_id'],
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new PostingException(
                'entry_reversed',
                'Zápis #' . $id . ' byl mezitím stornován — přepis není možný (§35).',
            );
        }

        $this->deleteLines($id, (int) $header['supplier_id']);
        $this->insertLines($pdo, $id, $header, $lines);

        // Okruh drží řádek přes (zápis, pořadí), takže přeúčtování ho přežije. Pokud
        // na stejném pořadí teď sedí jiný účet, položka z okruhu odejde se stopou v auditu.
        $this->logReleasedPairings(
            (int) $header['supplier_id'],
            $id,
            (new JournalLinePairingRepository($this->db))->releaseStale((int) $header['supplier_id'], $id),
            'repost',
        );
    }

    /**
     * Vymění v ŽIVÉM zápisu jen vybrané řádky; hlavička (datum, posted_at/posted_by) i
     * ostatní řádky včetně jejich dimenzí a rozpadů zůstávají, jak jsou. Nové řádky dostanou
     * pořadí za posledním stávajícím. Optimistický zámek přes row_version — souběžná změna
     * zápisu skončí `version_conflict`. Kontroly období, zámku a podvojnosti dělá volající
     * ({@see \MyInvoice\Service\Accounting\PostingService::replaceEntryLines()}).
     * PŘEDPOKLAD: volající drží transakci.
     *
     * Invariant dvojic se ověřuje nad VÝSLEDNÝM zápisem (zachované + nové řádky): ve
     * firmě s jednoduchou formou musí volající odebírat celé dvojice a nové řádky nést
     * `pair_no` za poslední dvojicí ({@see PostingService::replaceEntryLines()}).
     *
     * @param list<int> $removeLineIds
     * @param list<array<string,mixed>> $addLines resolved řádky (account_id, side, amount, …)
     * @param array{pair_status?:?string, pair_status_reason?:?string} $pairHeader posouzení
     *        zápisu bez dvojic v jednoduché formě (zápis zůstává složený)
     * @param array<int,int> $renumberPairs přečíslování zachovaných dvojic (staré => nové číslo)
     */
    public function replaceSomeLines(int $entryId, int $supplierId, int $expectedRowVersion, array $removeLineIds, array $addLines, array $pairHeader = [], array $renumberPairs = []): void
    {
        (new MembershipUseGuard($this->db))->assertEntriesMutable($supplierId, [$entryId]);
        (new IssuanceUseGuard($this->db))->assertPostingEntriesMutable($supplierId, [$entryId]);
        $entry = $this->currentEntryForDimensionWrite($entryId, $supplierId);
        (new JointAllocationUseGuard($this->db))->assertEntriesMutable($supplierId, [$entryId]);
        if ($entry === null || $entry['reversed_by'] !== null || (int) $entry['row_version'] !== $expectedRowVersion) {
            throw new PostingException('version_conflict', 'Zápis #' . $entryId . ' se mezitím změnil nebo byl stornován — načtěte aktuální stav.', 409);
        }
        if ($entry['posted_at'] !== null) (new \MyInvoice\Service\Accounting\Funding\NativeCostCoverage($this->db))->assertCreditNotePosting($entry);
        (new \MyInvoice\Service\Accounting\JournalSettlementUseGuard($this->db))->assertLinesRemovable($supplierId, $entryId, $removeLineIds);
        $remove = array_fill_keys(array_map('intval', $removeLineIds), true);
        $retained = array_values(array_filter($entry['lines'], static fn (array $line): bool => !isset($remove[(int) $line['id']])));
        foreach ($retained as $i => $line) {
            if (isset($line['pair_no'], $renumberPairs[(int) $line['pair_no']])) {
                $retained[$i]['pair_no'] = $renumberPairs[(int) $line['pair_no']];
            }
        }
        $nextLineNo = $retained === [] ? 0 : max(array_column($retained, 'line_no')) + 1;
        foreach ($addLines as $i => $line) {
            unset($addLines[$i]['id']);
            $addLines[$i]['line_no'] = $nextLineNo + $i;
        }
        if ((new \MyInvoice\Service\Accounting\Closing\AssetReserveSourceUseGuard($this->db, $this))->assertEntryChange(
            $supplierId, $entryId, [...$retained, ...$addLines], $entry['entry_date'], $entry)) return;
        if ((new \MyInvoice\Service\Tax\Nonprofit\NonprofitTaxSavingUseGuard($this->db, $this))->assertEntryChange($supplierId, $entryId, [...$retained, ...$addLines], $entry['entry_date'], $entry)) return;
        if ((new \MyInvoice\Service\Nonprofit\Capital\CapitalUseGuard($this->db, $this))->assertEntryChange($supplierId, $entryId, [...$retained, ...$addLines], $entry['entry_date'], $entry)) return;
        (new \MyInvoice\Service\Property\Payments\Workflow\PaymentMutationContext($this->db))->assertEntriesMutable($supplierId, [$entryId]);
        (new SystemDimensionWritePolicy($this->db))->assertJournal($entry, [...$retained, ...$addLines], $retained);
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            'UPDATE journal_entries SET row_version = row_version + 1
              WHERE id = ? AND supplier_id = ? AND reversed_by IS NULL AND row_version = ?'
        );
        $stmt->execute([$entryId, $supplierId, $expectedRowVersion]);
        if ($stmt->rowCount() !== 1) {
            throw new PostingException(
                'version_conflict',
                'Zápis #' . $entryId . ' se mezitím změnil nebo byl stornován — načtěte aktuální stav.',
                409,
            );
        }
        if ($removeLineIds !== []) {
            $in = implode(',', array_fill(0, count($removeLineIds), '?'));
            $pdo->prepare("DELETE FROM journal_entry_lines WHERE entry_id = ? AND supplier_id = ? AND id IN ({$in})")
                ->execute([$entryId, $supplierId, ...array_map('intval', $removeLineIds)]);
        }
        $max = $pdo->prepare('SELECT COALESCE(MAX(line_no), -1) FROM journal_entry_lines WHERE entry_id = ?');
        $max->execute([$entryId]);
        $next = (int) $max->fetchColumn() + 1;
        foreach ($addLines as $i => $line) {
            $addLines[$i]['line_no'] = $next + $i;
        }
        if ($renumberPairs !== []) {
            $renumber = $pdo->prepare('UPDATE journal_entry_lines SET pair_no = ? WHERE entry_id = ? AND supplier_id = ? AND pair_no = ?');
            foreach ($renumberPairs as $from => $to) {
                $renumber->execute([$to, $entryId, $supplierId, $from]);
            }
        }
        $result = [...$retained, ...array_values($addLines)];
        // Zápis, který už je po dvojicích, zůstává po dvojicích i ve složené formě
        // (storno dvojice datované do složeného období, převedený zápis).
        if (array_filter($entry['lines'], static fn (array $l): bool => isset($l['pair_no'])) !== []) {
            $pairHeader['pairs_inherited'] = true;
        }
        usort($result, static fn (array $a, array $b): int => (int) ($a['line_no'] ?? 0) <=> (int) ($b['line_no'] ?? 0));
        $this->insertLines($pdo, $entryId, $pairHeader + $entry, array_values($addLines), $result);
        $this->logReleasedPairings(
            $supplierId,
            $entryId,
            (new JournalLinePairingRepository($this->db))->releaseStale($supplierId, $entryId),
            'repost',
        );
    }

    /**
     * Přeúčtuje vybrané řádky ŽIVÉHO zápisu na jiný účet: řádek zůstává týž (id, částka,
     * strana, dvojice, text, měna, dimenze i rozpady), mění se jen `account_id`. Pro
     * hromadné přeúčtování ručních zápisů, interních a pokladních dokladů, kde zápis
     * nestaví žádný zdrojový doklad znovu a výměna celých řádků by zbytečně přišla
     * o dimenze a vazby řádků.
     *
     * Pojistky stejné jako {@see replaceSomeLines()}: optimistický zámek `row_version`,
     * použití zápisu jinými agendami (členské příspěvky, předpisy SVJ, společné náklady,
     * zúčtování, rezerva majetku, kapitál a daňová úspora neziskovky, platby SVJ) a politika
     * systémových dimenzí. Vázaná párování saldokonta na změněných řádcích se uvolní.
     * Kontroly období, zámku k datu a daňové neutrality dělá volající.
     * PŘEDPOKLAD: volající drží transakci.
     *
     * @param list<int> $lineIds
     */
    public function reassignLineAccounts(int $entryId, int $supplierId, int $expectedRowVersion, array $lineIds, int $accountId): void
    {
        $lineIds = array_values(array_unique(array_map('intval', $lineIds)));
        (new MembershipUseGuard($this->db))->assertEntriesMutable($supplierId, [$entryId]);
        (new IssuanceUseGuard($this->db))->assertPostingEntriesMutable($supplierId, [$entryId]);
        $entry = $this->currentEntryForDimensionWrite($entryId, $supplierId);
        (new JointAllocationUseGuard($this->db))->assertEntriesMutable($supplierId, [$entryId]);
        if ($entry === null || $entry['reversed_by'] !== null || (int) $entry['row_version'] !== $expectedRowVersion) {
            throw new PostingException('version_conflict', 'Zápis #' . $entryId . ' se mezitím změnil nebo byl stornován - načtěte aktuální stav.', 409);
        }
        $ids = array_flip($lineIds);
        $known = array_flip(array_map(static fn (array $l): int => (int) $l['id'], $entry['lines']));
        if ($lineIds === [] || array_diff_key($ids, $known) !== []) {
            throw new PostingException('entry_not_found', 'Řádek do zápisu #' . $entryId . ' nepatří.', 404);
        }
        (new \MyInvoice\Service\Accounting\JournalSettlementUseGuard($this->db))->assertLinesRemovable($supplierId, $entryId, $lineIds);
        $result = array_map(static fn (array $l): array => isset($ids[(int) $l['id']]) ? ['account_id' => $accountId] + $l : $l, $entry['lines']);
        foreach ([
            new \MyInvoice\Service\Accounting\Closing\AssetReserveSourceUseGuard($this->db, $this),
            new \MyInvoice\Service\Tax\Nonprofit\NonprofitTaxSavingUseGuard($this->db, $this),
            new \MyInvoice\Service\Nonprofit\Capital\CapitalUseGuard($this->db, $this),
        ] as $guard) {
            // `true` = zápis je použitý jinde a změna je jen beze změny řádků; jiná změna
            // použitého zápisu v guardu skončí výjimkou. Výměna účtu tedy nikdy `true` nedá.
            if ($guard->assertEntryChange($supplierId, $entryId, $result, $entry['entry_date'], $entry)) {
                return;
            }
        }
        (new \MyInvoice\Service\Property\Payments\Workflow\PaymentMutationContext($this->db))->assertEntriesMutable($supplierId, [$entryId]);
        (new SystemDimensionWritePolicy($this->db))->assertJournal($entry, $result, $entry['lines']);

        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            'UPDATE journal_entries SET row_version = row_version + 1
              WHERE id = ? AND supplier_id = ? AND reversed_by IS NULL AND row_version = ?'
        );
        $stmt->execute([$entryId, $supplierId, $expectedRowVersion]);
        if ($stmt->rowCount() !== 1) {
            throw new PostingException('version_conflict', 'Zápis #' . $entryId . ' se mezitím změnil nebo byl stornován - načtěte aktuální stav.', 409);
        }
        $in = implode(',', array_fill(0, count($lineIds), '?'));
        $pdo->prepare("UPDATE journal_entry_lines SET account_id = ? WHERE entry_id = ? AND supplier_id = ? AND id IN ({$in})")
            ->execute([$accountId, $entryId, $supplierId, ...$lineIds]);
        $this->logReleasedPairings(
            $supplierId,
            $entryId,
            (new JournalLinePairingRepository($this->db))->releaseStale($supplierId, $entryId),
            'repost',
        );
    }

    /**
     * @param list<array{pairing_id:int, entry_id:int, line_no:int}> $released
     */
    private function logReleasedPairings(int $supplierId, int $entryId, array $released, string $reason): void
    {
        if ($released === []) {
            return;
        }
        $this->activity->log('accounting.pairing_released', null, 'journal_entry', $entryId, [
            'reason' => $reason,
            'items'  => $released,
        ], null, null, $supplierId);
    }

    private function deleteLines(int $entryId, int $supplierId): void
    {
        (new MembershipUseGuard($this->db))->assertEntriesMutable($supplierId, [$entryId]);
        (new IssuanceUseGuard($this->db))->assertPostingEntriesMutable($supplierId, [$entryId]);
        $this->db->pdo()->prepare('DELETE FROM journal_entry_lines WHERE entry_id = ? AND supplier_id = ?')->execute([$entryId, $supplierId]);
    }

    /**
     * §35 — aditivní editace narativního `description` na EXISTUJÍCÍM (i zaúčtovaném)
     * zápisu, MIMO PostingService/replace()/postDocument (ty přepisují řádky, vynucují
     * Σ MD = Σ D a zařazují do období). Nemění účet/stranu/částku/období — jen popisek.
     *
     * §35 guardy (POVINNÉ) — atomicky pod SELECT ... FOR UPDATE, zrcadlí
     * {@see PostingService::rewriteExisting}:
     *   - 409 `description_managed_by_source` — §5.4 re-post clobber gate: povoleno JEN pro
     *     source_type IN ('manual','closing','opening') (zápisy, které se nikdy nere-postují);
     *     u zápisů odvozených ze zdrojového dokladu by inline popis PostingService při
     *     příštím re-postu tiše přepsal.
     *   - 409 `entry_reversed` — stornovaný zápis (reversed_by != NULL) je neměnný.
     *   - 409 `period_not_open` — období zápisu zavřené/uzamčené (mirror rewriteExisting:
     *     povolené stavy závisí na source_type — 'closing' zápis smí i v období 'closing'
     *     [závěrkový krok], manual/opening jen v 'open'; jinak odmítnuto).
     * Při úspěchu zapíše description, **bumpne row_version** (optimistická konkurence) a
     * ATOMICKY (pod touž transakcí, před commitem — zrcadlí PostingService::postDocument)
     * zapíše §35 audit `accounting.description_edited`. LOW-7: no-op editace (stejný text)
     * nebumpuje row_version ani nepíše audit (§35 trail zůstává smysluplný).
     *
     * Optimistická konkurence (Issue #15, část B): pokud volající předá `$expectedVersion`
     * (z hlavičky If-Match / body row_version), porovná se pod FOR UPDATE zámkem s aktuální
     * `row_version`; při neshodě 409 `version_conflict` (mezitím editoval jiný uživatel).
     * `$expectedVersion === null` = bez CAS (zpětně kompatibilní — chování jako dřív).
     *
     * @return array{before:?string, after:?string, posted:bool, changed:bool} výsledek pro Action
     * @throws PostingException 404 not_found | 409 version_conflict|description_managed_by_source|entry_reversed|period_not_open
     */
    public function updateDescription(
        int $id,
        int $supplierId,
        ?string $text,
        ?int $userId = null,
        ?string $ip = null,
        ?string $userAgent = null,
        ?int $expectedVersion = null,
    ): array {
        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
            (new \MyInvoice\Service\Accounting\Closing\SecuritiesDebt504UseGuard($this->db))->assertEntriesMutable($supplierId, [$id]);
            (new \MyInvoice\Service\Property\Opening\OpeningUseGuard($this->db))->assertDescriptionMutable($supplierId, $id);
            (new \MyInvoice\Service\Property\Services\Settlement\SettlementUseGuard($this->db))->assertDescriptionMutable($supplierId,$id);
            try { \MyInvoice\Service\Property\Funds\FundingUseGuard::assertEntriesMutable($this->db,$supplierId,[$id]); }
            catch (\MyInvoice\Service\Property\Funds\FundingException $e) { throw new PostingException($e->errorCode,$e->getMessage(),$e->httpStatus,$e->details); }
            // Zamkni řádek zápisu + načti guard stav (vč. stavu jeho období) a row_version.
            $stmt = $pdo->prepare(
                'SELECT je.description, je.source_type, je.reversed_by, je.posted_at,
                        je.row_version, p.status AS period_status
                   FROM journal_entries je
              LEFT JOIN accounting_periods p ON p.id = je.period_id AND p.supplier_id = je.supplier_id
                  WHERE je.id = ? AND je.supplier_id = ?
                  FOR UPDATE'
            );
            $stmt->execute([$id, $supplierId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                throw new PostingException('not_found', 'Účetní zápis nenalezen.', 404);
            }

            // Optimistická konkurence: pod FOR UPDATE je přečtená row_version autoritativní,
            // proto stačí porovnat (žádný zvláštní CAS UPDATE není potřeba).
            if ($expectedVersion !== null && (int) $row['row_version'] !== $expectedVersion) {
                throw new PostingException(
                    'version_conflict',
                    'Zápis mezitím změnil jiný uživatel — načtěte aktuální stav.',
                    409,
                );
            }

            // §5.4 re-post clobber gate — jen zápisy, které PostingService nikdy nepřepisuje.
            if (!in_array((string) $row['source_type'], ['manual', 'closing', 'opening'], true)) {
                throw new PostingException(
                    'description_managed_by_source',
                    'Popis se edituje na zdrojovém dokladu.',
                    409,
                );
            }
            // §35 — stornovaný zápis je neměnný.
            if ($row['reversed_by'] !== null) {
                throw new PostingException(
                    'entry_reversed',
                    'Zápis #' . $id . ' je stornovaný — popis nelze měnit (§35).',
                    409,
                );
            }
            // §35 — do zavřeného/uzamčeného období nelze zasahovat (mirror rewriteExisting):
            // povolené stavy závisí na source_type — jen 'closing' zápis smí být v období
            // 'closing' (závěrkový krok, allow_closing_period flag), manual/opening jen 'open'.
            $sourceType = (string) $row['source_type'];
            $allowed = $sourceType === 'closing' ? ['open', 'closing'] : ['open'];
            $periodStatus = $row['period_status'] !== null ? (string) $row['period_status'] : null;
            if ($periodStatus !== null && !in_array($periodStatus, $allowed, true)) {
                throw new PostingException(
                    'period_not_open',
                    'Zápis #' . $id . ' je v období "' . $periodStatus
                        . '" — do uzavřeného období nelze zasahovat (§35 ZoÚ).',
                    409,
                );
            }

            $before = $row['description'] !== null ? (string) $row['description'] : null;
            $posted = $row['posted_at'] !== null;

            // LOW-7 — no-op editace (stejný text): nebumpuj row_version ani nepiš audit.
            if ($before === $text) {
                if ($ownTx) {
                    $pdo->commit();
                }
                return ['before' => $before, 'after' => $text, 'posted' => $posted, 'changed' => false];
            }

            (new \MyInvoice\Service\Tax\Nonprofit\NonprofitTaxSavingUseGuard($this->db, $this))->assertEntriesMutable($supplierId, [$id]);
            (new \MyInvoice\Service\Nonprofit\Capital\CapitalUseGuard($this->db, $this))->assertEntriesMutable($supplierId, [$id]);
            $pdo->prepare(
                'UPDATE journal_entries SET description = ?, row_version = row_version + 1
                  WHERE id = ? AND supplier_id = ?'
            )->execute([$text, $id, $supplierId]);

            // §35 audit ATOMICKY uvnitř téže transakce, PŘED commitem (zrcadlí
            // PostingService::postDocument) — neauditovaná editace zaúčtovaného zápisu
            // nesmí přetrvat, pokud audit INSERT selže.
            $this->activity->log(
                'accounting.description_edited',
                $userId,
                'journal_entry',
                $id,
                ['before' => $before, 'after' => $text, 'posted' => $posted],
                $ip,
                $userAgent,
                $supplierId,
            );

            if ($ownTx) {
                $pdo->commit();
            }

            return [
                'before'  => $before,
                'after'   => $text,
                'posted'  => $posted,
                'changed' => true,
            ];
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Doplní číslo dokladu ručnímu zápisu, který žádné nemá (hlavička interního dokladu
     * nad existujícím zápisem). Stejné pojistky jako {@see updateDescription()}: jen ruční,
     * nestornovaný zápis v otevřeném období, guardy navazujících agend. §35 audit
     * `accounting.document_no_assigned` se zapisuje v téže transakci před commitem.
     *
     * @throws PostingException 404 not_found | 409 entry_not_manual|entry_reversed|period_not_open|document_no_present
     */
    public function assignDocumentNo(
        int $id,
        int $supplierId,
        string $documentNo,
        ?int $userId = null,
        ?string $ip = null,
        ?string $userAgent = null,
    ): void {
        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
            (new \MyInvoice\Service\Accounting\Closing\SecuritiesDebt504UseGuard($this->db))->assertEntriesMutable($supplierId, [$id]);
            (new \MyInvoice\Service\Property\Opening\OpeningUseGuard($this->db))->assertDescriptionMutable($supplierId, $id);
            (new \MyInvoice\Service\Property\Services\Settlement\SettlementUseGuard($this->db))->assertDescriptionMutable($supplierId, $id);
            try { \MyInvoice\Service\Property\Funds\FundingUseGuard::assertEntriesMutable($this->db, $supplierId, [$id]); }
            catch (\MyInvoice\Service\Property\Funds\FundingException $e) { throw new PostingException($e->errorCode, $e->getMessage(), $e->httpStatus, $e->details); }
            $stmt = $pdo->prepare(
                'SELECT je.document_no, je.source_type, je.source_id, je.reversed_by, p.status AS period_status
                   FROM journal_entries je
              LEFT JOIN accounting_periods p ON p.id = je.period_id AND p.supplier_id = je.supplier_id
                  WHERE je.id = ? AND je.supplier_id = ?
                  FOR UPDATE'
            );
            $stmt->execute([$id, $supplierId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                throw new PostingException('not_found', 'Účetní zápis nenalezen.', 404);
            }
            if ((string) $row['source_type'] !== 'manual' || $row['source_id'] !== null) {
                throw new PostingException('entry_not_manual', 'Číslo dokladu lze doplnit jen ručnímu zápisu.', 409);
            }
            if ($row['reversed_by'] !== null) {
                throw new PostingException('entry_reversed', 'Zápis #' . $id . ' je stornovaný, číslo dokladu nelze měnit (§35).', 409);
            }
            if ($row['period_status'] !== null && (string) $row['period_status'] !== 'open') {
                throw new PostingException('period_not_open', 'Zápis #' . $id . ' je v období "' . $row['period_status']
                    . '", do uzavřeného období nelze zasahovat (§35 ZoÚ).', 409);
            }
            if ($row['document_no'] !== null && trim((string) $row['document_no']) !== '') {
                throw new PostingException('document_no_present', 'Zápis už číslo dokladu má.', 409);
            }
            (new \MyInvoice\Service\Tax\Nonprofit\NonprofitTaxSavingUseGuard($this->db, $this))->assertEntriesMutable($supplierId, [$id]);
            (new \MyInvoice\Service\Nonprofit\Capital\CapitalUseGuard($this->db, $this))->assertEntriesMutable($supplierId, [$id]);
            $pdo->prepare(
                'UPDATE journal_entries SET document_no = ?, row_version = row_version + 1
                  WHERE id = ? AND supplier_id = ?'
            )->execute([$documentNo, $id, $supplierId]);
            $this->activity->log(
                'accounting.document_no_assigned',
                $userId,
                'journal_entry',
                $id,
                ['before' => $row['document_no'], 'after' => $documentNo],
                $ip,
                $userAgent,
                $supplierId,
            );
            if ($ownTx) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Označí zápis za zaúčtovaný (koncept → zaúčtováno, §12 průkaznost).
     */
    public function markPosted(int $id, int $supplierId, ?int $postedBy): bool
    {
        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
            (new IssuanceUseGuard($this->db))->assertPostingEntriesMutable($supplierId, [$id]);
            $entry = $this->currentEntryForDimensionWrite($id, $supplierId);
            if ($entry !== null && $entry['source_type'] === 'nonprofit_membership' && $entry['posted_at'] !== null) {
                if ($ownTx) $pdo->commit();
                return false;
            }
            (new MembershipUseGuard($this->db))->assertEntriesMutable($supplierId, [$id]);
            (new JointAllocationUseGuard($this->db))->assertEntriesMutable($supplierId, [$id]);
            if ($entry !== null && $entry['posted_at'] !== null
                && (new \MyInvoice\Service\Accounting\Closing\AssetReserveSourceUseGuard($this->db, $this))->assertEntryChange(
                    $supplierId, $id, $entry['lines'], $entry['entry_date'], $entry)) {
                if ($ownTx) $pdo->commit();
                return false;
            }
            if ($entry !== null && $entry['posted_at'] !== null
                && (new \MyInvoice\Service\Tax\Nonprofit\NonprofitTaxSavingUseGuard($this->db, $this))->assertEntryChange(
                    $supplierId, $id, $entry['lines'], $entry['entry_date'], $entry)) {
                if ($ownTx) $pdo->commit();
                return false;
            }
            if ($entry !== null && $entry['posted_at'] !== null
                && (new \MyInvoice\Service\Nonprofit\Capital\CapitalUseGuard($this->db, $this))->assertEntryChange(
                    $supplierId, $id, $entry['lines'], $entry['entry_date'], $entry)) {
                if ($ownTx) $pdo->commit();
                return false;
            }
            (new \MyInvoice\Service\Property\Payments\Workflow\PaymentMutationContext($this->db))->assertEntriesMutable($supplierId, [$id]);
            if ($entry !== null) (new \MyInvoice\Service\Accounting\Funding\NativeCostCoverage($this->db))->assertCreditNotePosting($entry);
            if ($entry !== null && $entry['posted_at'] === null) {
                (new SystemDimensionWritePolicy($this->db))->assertJournal($entry, $entry['lines']);
            }
            $stmt = $pdo->prepare(
                'UPDATE journal_entries SET posted_at = NOW(), posted_by = ? WHERE id = ? AND supplier_id = ?'
            );
            $stmt->execute([$postedBy, $id, $supplierId]);
            $changed = $stmt->rowCount() > 0;
            if ($ownTx) {
                $pdo->commit();
            }
            return $changed;
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private function currentEntryForDimensionWrite(int $entryId, int $supplierId): ?array
    {
        (new SystemDimensionWritePolicy($this->db))->lockContext($supplierId);
        (new IssuanceUseGuard($this->db))->lockClaims($supplierId);
        (new \MyInvoice\Service\Property\Payments\Workflow\PaymentMutationContext($this->db))->acquire($supplierId);
        $entry = $this->findForUpdate($entryId, $supplierId);
        if ($entry === null) {
            return null;
        }
        $assignments = new DimensionAssignmentRepository($this->db);
        $dimensions = $assignments->entryLineDimensions($supplierId, $entryId, true);
        $splits = $assignments->entryLineSplits($supplierId, $entryId, true);
        $origins = $assignments->entryLineOrigins($supplierId, $entryId, true);
        $splitOrigins = $assignments->entryLineSplitOrigins($supplierId, $entryId, true);
        foreach ($entry['lines'] as $i => $line) {
            $lineId = (int) $line['id'];
            $entry['lines'][$i]['dimensions'] = $dimensions[$lineId] ?? [];
            $entry['lines'][$i]['dimension_splits'] = $splits[$lineId] ?? [];
            $entry['lines'][$i]['dimension_origins'] = $origins[$lineId] ?? [];
            $entry['lines'][$i]['dimension_split_origins'] = $splitOrigins[$lineId] ?? [];
        }
        return $entry;
    }

    /**
     * Naváže na původní zápis jeho storno (§35 — po zaúčtování jen opravný zápis).
     * Podmínka `reversed_by IS NULL` je ATOMICKÁ pojistka proti dvojímu stornu při
     * souběhu: dva paralelní reverse oba vloží zrcadlo, ale tenhle UPDATE vyhraje
     * jen první (DB serializuje zámek řádku) — druhý dostane rowCount 0 a volající
     * transakci rollbackne (audit F-1). Storno má source_id NULL, takže unique
     * uq_je_supplier_source ho nechrání — chrání ho tenhle podmíněný UPDATE.
     */
    public function setReversedBy(int $id, int $supplierId, int $reversalEntryId): bool
    {
        return (new DimensionPeriodGuard($this->db))->transaction(function () use ($id, $supplierId, $reversalEntryId): bool {
            (new SystemDimensionWritePolicy($this->db))->lockContext($supplierId);
            (new IssuanceUseGuard($this->db))->assertPostingEntriesMutable($supplierId, [$id, $reversalEntryId]);
            (new \MyInvoice\Service\Property\Payments\Workflow\PaymentMutationContext($this->db))->assertEntriesMutable($supplierId, [$id, $reversalEntryId]);
            (new JointAllocationUseGuard($this->db))->assertEntriesMutable($supplierId, [$id]);
            (new \MyInvoice\Service\Accounting\Closing\AssetReserveSourceUseGuard($this->db, $this))->assertReversal($supplierId, $id);
            return $this->setReversedByLocked($id, $supplierId, $reversalEntryId);
        });
    }

    private function setReversedByLocked(int $id, int $supplierId, int $reversalEntryId): bool
    {
        (new MembershipUseGuard($this->db))->assertEntriesMutable($supplierId, [$id, $reversalEntryId]);
        $stmt = $this->db->pdo()->prepare(
            'UPDATE journal_entries SET reversed_by = ?
              WHERE id = ? AND supplier_id = ? AND reversed_by IS NULL'
        );
        $stmt->execute([$reversalEntryId, $id, $supplierId]);
        if ($stmt->rowCount() === 0) {
            return false;
        }
        // Originál se se svým protizápisem vyruší; jeho dřívější spárování s úhradou
        // by jinak drželo úhradu uzavřenou a protizápis visel otevřený.
        $this->logReleasedPairings(
            $supplierId,
            $id,
            (new JournalLinePairingRepository($this->db))->releaseEntry($supplierId, $id),
            'reversal',
        );
        return true;
    }

    /**
     * Odpojí zdrojový doklad od zápisu — uvolní unique slot (source_type, source_id)
     * pro nový zápis (mini-epic AUTOMATIZACE: unpost = reverse + detach, R10). Audit
     * pár originál+storno zůstává (storno má source_id=NULL už dnes). Vrací true při
     * zásahu do řádku tenanta.
     */
    public function detachSource(int $entryId, int $supplierId): bool
    {
        return (new DimensionPeriodGuard($this->db))->transaction(function () use ($entryId, $supplierId): bool {
            (new SystemDimensionWritePolicy($this->db))->lockContext($supplierId);
            (new IssuanceUseGuard($this->db))->assertPostingEntriesMutable($supplierId, [$entryId]);
            (new \MyInvoice\Service\Property\Payments\Workflow\PaymentMutationContext($this->db))->assertEntriesMutable($supplierId, [$entryId]);
            (new JointAllocationUseGuard($this->db))->assertEntriesMutable($supplierId, [$entryId]);
            (new \MyInvoice\Service\Accounting\Closing\AssetReserveSourceUseGuard($this->db, $this))->assertEntriesMutable($supplierId, [$entryId]);
            return $this->detachSourceLocked($entryId, $supplierId);
        });
    }

    private function detachSourceLocked(int $entryId, int $supplierId): bool
    {
        (new MembershipUseGuard($this->db))->assertEntriesMutable($supplierId, [$entryId]);
        $stmt = $this->db->pdo()->prepare(
            'UPDATE journal_entries SET source_id = NULL WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$entryId, $supplierId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Fyzicky rozdělí řádky zaúčtovaného zápisu na dvojice (převod starších zápisů).
     *
     * Kontrakt (implementace navazuje): `$pairedLines` je výstup JournalPairer::pair()
     * nad řádky zápisu, kde každý díl nese `id` původního řádku. První díl řádku zůstane
     * na původním řádku (stejné `id` a `line_no`, UPDATE `amount`, `amount_foreign`,
     * `pair_no`, `line_text`), další díly se vloží s novými `line_no` za maximem a se
     * zkopírovanými dimenzemi a rozpady; řádky se nikdy nemažou. Optimistická
     * konkurence přes `row_version` (zvýší se), výsledek projde invariantem dvojic.
     * Volající drží transakci a zámek období a zapisuje audit.
     *
     * Každý díl v `$pairedLines` nese `id` původního řádku (díly téhož řádku se stejným
     * `id`), `pair_no`, `line_text` a částky dílu. Součty po řádcích musí sedět na
     * původní řádky a výsledek musí projít invariantem dvojic.
     *
     * @param list<array<string,mixed>> $pairedLines
     * @throws PostingException version_conflict | invalid_pair_split
     * @throws \MyInvoice\Service\Accounting\Pair\JournalPairException
     */
    public function splitIntoPairs(int $entryId, int $supplierId, int $expectedRowVersion, array $pairedLines): void
    {
        $pdo = $this->db->pdo();
        if (!$pdo->inTransaction()) {
            throw new \LogicException('Rozdělení zápisu na dvojice vyžaduje transakci volajícího.');
        }
        $entry = $this->currentEntryForDimensionWrite($entryId, $supplierId);
        if ($entry === null || $entry['reversed_by'] !== null || (int) $entry['row_version'] !== $expectedRowVersion) {
            throw new PostingException('version_conflict', 'Zápis #' . $entryId . ' se mezitím změnil nebo byl stornován — načtěte aktuální stav.', 409);
        }
        $invalid = static fn (): PostingException => new PostingException('invalid_pair_split', 'Rozdělení na dvojice neodpovídá řádkům zápisu #' . $entryId . '.', 422);
        $original = [];
        foreach ($entry['lines'] as $line) {
            if (isset($line['pair_no'])) {
                throw $invalid();
            }
            $original[(int) $line['id']] = $line;
        }
        $pieces = [];
        foreach ($pairedLines as $piece) {
            $lineId = (int) ($piece['id'] ?? 0);
            if (!isset($original[$lineId])) {
                throw $invalid();
            }
            $pieces[$lineId][] = $piece;
        }
        if (count($pieces) !== count($original)) {
            throw $invalid();
        }
        foreach ($pieces as $lineId => $parts) {
            $source = $original[$lineId];
            $cents = 0;
            $foreign = 0;
            foreach ($parts as $part) {
                if ((int) $part['account_id'] !== (int) $source['account_id'] || $part['side'] !== $source['side']
                    || !empty($part['is_red_storno']) !== !empty($source['is_red_storno'])) {
                    throw $invalid();
                }
                $cents += JournalLineAmount::cents($part['amount']);
                $foreign += isset($part['amount_foreign']) ? JournalLineAmount::cents($part['amount_foreign']) : 0;
            }
            $sourceForeign = isset($source['amount_foreign']) ? JournalLineAmount::cents($source['amount_foreign']) : 0;
            if ($cents !== JournalLineAmount::cents($source['amount']) || $foreign !== $sourceForeign) {
                throw $invalid();
            }
        }

        $result = [];
        foreach ($pairedLines as $piece) {
            $source = $original[(int) $piece['id']];
            $result[] = array_replace($source, [
                'amount'         => $piece['amount'],
                'amount_foreign' => $piece['amount_foreign'] ?? null,
                'pair_no'        => $piece['pair_no'] ?? null,
                'line_text'      => $piece['line_text'] ?? null,
            ]);
        }
        $header = $entry + ['pairs_inherited' => false];
        $this->assertPairs($header, $result);

        $stmt = $pdo->prepare(
            'UPDATE journal_entries SET row_version = row_version + 1
              WHERE id = ? AND supplier_id = ? AND reversed_by IS NULL AND row_version = ?'
        );
        $stmt->execute([$entryId, $supplierId, $expectedRowVersion]);
        if ($stmt->rowCount() !== 1) {
            throw new PostingException('version_conflict', 'Zápis #' . $entryId . ' se mezitím změnil nebo byl stornován — načtěte aktuální stav.', 409);
        }

        $update = $pdo->prepare(
            'UPDATE journal_entry_lines SET amount = ?, amount_foreign = ?, pair_no = ?, line_text = ?
              WHERE id = ? AND entry_id = ? AND supplier_id = ?'
        );
        $max = $pdo->prepare('SELECT COALESCE(MAX(line_no), -1) FROM journal_entry_lines WHERE entry_id = ? AND supplier_id = ?');
        $max->execute([$entryId, $supplierId]);
        $nextLineNo = (int) $max->fetchColumn() + 1;
        $kept = [];
        $added = [];
        foreach ($result as $line) {
            $lineId = (int) $line['id'];
            if (!isset($kept[$lineId])) {
                $kept[$lineId] = true;
                $update->execute([
                    JournalLineAmount::decimal($line['amount']),
                    isset($line['amount_foreign']) ? JournalLineAmount::decimal($line['amount_foreign']) : null,
                    $line['pair_no'],
                    self::lineText($line),
                    $lineId,
                    $entryId,
                    $supplierId,
                ]);
                continue;
            }
            unset($line['id'], $line['entry_id']);
            $line['line_no'] = $nextLineNo++;
            $added[] = $line;
        }
        if ($added !== []) {
            $this->writeLines($pdo, $entryId, $supplierId, $added);
        }
        $pdo->prepare('DELETE FROM journal_entry_pair_status WHERE supplier_id = ? AND entry_id = ?')
            ->execute([$supplierId, $entryId]);
    }

    /**
     * Pohled na dvojice zápisu pro API detailu: `pairs` (jeden prvek na dvojici, MD a Dal
     * řádek, částka, text, opravný zápis dvojice) a `pair_status` u zápisu bez dvojic
     * v jednoduché formě. Složený zápis bez posouzení vrací prázdné pole.
     *
     * @param list<array<string,mixed>> $lines řádky zápisu (s `id`, volitelně `account_code`)
     * @return array{pairs?: list<array<string,mixed>>, pair_status?: array{status:string, reason:?string}}
     */
    public function pairView(int $supplierId, int $entryId, array $lines): array
    {
        $byPair = [];
        foreach ($lines as $line) {
            if (isset($line['pair_no'])) {
                $byPair[(int) $line['pair_no']][] = $line;
            }
        }
        if ($byPair === []) {
            $stmt = $this->db->pdo()->prepare('SELECT status, reason FROM journal_entry_pair_status WHERE supplier_id = ? AND entry_id = ?');
            $stmt->execute([$supplierId, $entryId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row === false ? [] : ['pair_status' => ['status' => (string) $row['status'], 'reason' => $row['reason']]];
        }
        $stmt = $this->db->pdo()->prepare('SELECT pair_no, reversal_entry_id FROM journal_pair_reversals WHERE supplier_id = ? AND entry_id = ?');
        $stmt->execute([$supplierId, $entryId]);
        $reversals = array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'reversal_entry_id', 'pair_no'));
        ksort($byPair);
        $pairs = [];
        foreach ($byPair as $pairNo => $pairLines) {
            $debit = null;
            $credit = null;
            foreach ($pairLines as $line) {
                if (JournalPairInvariant::effectiveSide($line) === 'debit') {
                    $debit = $line;
                } else {
                    $credit = $line;
                }
            }
            $pairs[] = [
                'pair_no'              => $pairNo,
                'debit_line_id'        => isset($debit['id']) ? (int) $debit['id'] : null,
                'credit_line_id'       => isset($credit['id']) ? (int) $credit['id'] : null,
                'debit_account_code'   => $debit['account_code'] ?? null,
                'credit_account_code'  => $credit['account_code'] ?? null,
                'amount'               => (float) ($debit['amount'] ?? $credit['amount'] ?? 0),
                'is_red_storno'        => !empty(($debit ?? $credit)['is_red_storno']),
                'line_text'            => $debit['line_text'] ?? $credit['line_text'] ?? null,
                'reversed_by_entry_id' => $reversals[$pairNo] ?? null,
            ];
        }
        return ['pairs' => $pairs];
    }

    /**
     * Posouzení zápisů bez dvojic (`composite_mn` / `ineligible`) pro stránku seznamu
     * deníku, jedním dotazem po primárním klíči. Zápis bez posouzení v mapě chybí.
     *
     * @param list<int> $entryIds
     * @return array<int,string> entry_id => status
     */
    public function pairStatusesForEntries(int $supplierId, array $entryIds): array
    {
        if ($entryIds === []) {
            return [];
        }
        $place = implode(',', array_fill(0, count($entryIds), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT entry_id, status FROM journal_entry_pair_status WHERE supplier_id = ? AND entry_id IN ({$place})"
        );
        $stmt->execute([$supplierId, ...$entryIds]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int) $row['entry_id']] = (string) $row['status'];
        }
        return $out;
    }

    /**
     * Čísla dvojic zápisu, které už storno nebo přeúčtování dvojice odebralo
     * (`journal_pair_reversals`). Storno celého zápisu je nezrcadlí podruhé.
     *
     * @return list<int>
     */
    public function reversedPairNos(int $supplierId, int $entryId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT pair_no FROM journal_pair_reversals WHERE supplier_id = ? AND entry_id = ? ORDER BY pair_no'
        );
        $stmt->execute([$supplierId, $entryId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Opravný zápis vybraných dvojic: zrcadlo dvojic (+ volitelně nové dvojice přeúčtování)
     * a vazby v `journal_pair_reversals`. Originál zůstává nestornovaný (`reversed_by` NULL).
     * Zrcadlo se ověří proti živým řádkům dvojic originálu stejně jako storno celého zápisu.
     *
     * @param array<string,mixed> $header hlavička opravného zápisu
     * @param list<array<string,mixed>> $mirror zrcadlo vybraných dvojic (pair_no originálu)
     * @param list<int> $pairNos stornované dvojice originálu
     * @param list<array<string,mixed>> $replacement rozřešené řádky nové dvojice (jen u přeúčtování jedné dvojice)
     * @return int id opravného zápisu
     */
    public function insertPairReversal(array $header, array $mirror, int $sourceEntryId, array $pairNos, array $replacement = [], ?int $createdBy = null): int
    {
        return (new DimensionPeriodGuard($this->db))->transaction(function () use ($header, $mirror, $sourceEntryId, $pairNos, $replacement, $createdBy): int {
            $supplierId = (int) $header['supplier_id'];
            (new IssuanceUseGuard($this->db))->assertPostingEntriesMutable($supplierId, [$sourceEntryId]);
            (new \MyInvoice\Service\Property\Payments\Workflow\PaymentMutationContext($this->db))->assertEntriesMutable($supplierId, [$sourceEntryId]);
            (new MembershipUseGuard($this->db))->assertEntriesMutable($supplierId, [$sourceEntryId]);
            $source = $this->currentEntryForDimensionWrite($sourceEntryId, $supplierId);
            $invalid = static fn (): PostingException => new PostingException('invalid_reversal_source', 'Protizápis neodpovídá živému zaúčtovanému zdroji.', 422);
            if ($source === null || $source['posted_at'] === null || $source['reversed_by'] !== null || $source['source_type'] === 'payroll'
                || ($header['source_type'] ?? 'manual') !== $source['source_type'] || ($header['source_id'] ?? null) !== null
                || $pairNos === [] || $mirror === [] || ($replacement !== [] && count($pairNos) !== 1)) {
                throw $invalid();
            }
            $already = array_flip($this->reversedPairNos($supplierId, $sourceEntryId));
            $selected = array_flip($pairNos);
            $sourcePairs = [];
            foreach ($source['lines'] as $line) {
                if (isset($line['pair_no'], $selected[(int) $line['pair_no']])) {
                    if (isset($already[(int) $line['pair_no']])) {
                        throw new PostingException('pair_already_reversed', 'Dvojice #' . (int) $line['pair_no'] . ' už byla stornována nebo přeúčtována.', 409);
                    }
                    $sourcePairs[] = $line;
                }
            }
            if (count($sourcePairs) !== 2 * count($pairNos) || count($mirror) !== count($sourcePairs)) {
                throw $invalid();
            }
            $lines = [];
            $map = [];
            $next = 0;
            foreach (array_values($mirror) as $index => $line) {
                $original = $sourcePairs[$index];
                $expected = array_replace($original, JournalLineAmount::reversal($original));
                if (!SystemDimensionWritePolicy::sameLedgerLine($line, $expected)
                    || (int) ($line['pair_no'] ?? 0) !== (int) $original['pair_no']) {
                    throw $invalid();
                }
                $originalPair = (int) $original['pair_no'];
                $map[$originalPair] ??= ++$next;
                $line['pair_no'] = $map[$originalPair];
                $line['line_no'] = $index;
                $lines[] = $line;
            }
            $replacementPairNo = null;
            if ($replacement !== []) {
                $replacementPairNo = ++$next;
                foreach (array_values($replacement) as $i => $line) {
                    $line['pair_no'] = $replacementPairNo;
                    $line['line_no'] = count($lines);
                    $lines[] = $line;
                }
            }
            $header['pairs_inherited'] = true;
            $reversalId = $this->persistJournal($header, $lines, false);
            $link = $this->db->pdo()->prepare(
                'INSERT INTO journal_pair_reversals
                    (supplier_id, entry_id, pair_no, reversal_entry_id, reversal_pair_no, replacement_pair_no, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($map as $originalPair => $reversalPair) {
                $link->execute([$supplierId, $sourceEntryId, $originalPair, $reversalId, $reversalPair, $replacementPairNo, $createdBy]);
            }
            return $reversalId;
        });
    }

    /**
     * Pojistka invariantů dvojic nad VÝSLEDNÝM stavem zápisu (jediné místo, kudy jdou
     * řádky do deníku). Vrací stav, který se má zapsat do `journal_entry_pair_status`
     * (zápis bez dvojic ve firmě s jednoduchou formou), nebo null.
     *
     * Firma bez řádku historie formy zaplatí jediný dotaz po indexu a dál jde dnešní cestou.
     * Typované zapisovače (SVJ, NNO, kroky uzávěrky 504, převod rámce) dvojice zatím netvoří:
     * bez výslovného stavu se jejich zápis v jednoduché formě vede jako `ineligible`
     * s důvodem `typed_owner` a převod ani noční kontrola ho nerozdělí.
     *
     * @param array<string,mixed> $header supplier_id, entry_date, source_type; volitelně
     *        pair_status, pair_status_reason, pairs_inherited, pair_typed_owner
     * @param list<array<string,mixed>> $lines
     * @return array{status:string, reason:?string}|null
     */
    private function assertPairs(array $header, array $lines): ?array
    {
        $paired = false;
        foreach ($lines as $line) {
            if (isset($line['pair_no'])) {
                $paired = true;
                break;
            }
        }
        $form = $this->entryForms()->formAt((int) $header['supplier_id'], (string) $header['entry_date'], currentRead: true);
        if (!$paired && !$form->isSingle()) {
            return null;
        }
        $status = $header['pair_status'] ?? null;
        $reason = $header['pair_status_reason'] ?? null;
        if (!$paired && $status === null && !empty($header['pair_typed_owner'])) {
            $status = 'ineligible';
            $reason = 'typed_owner';
        }
        JournalPairInvariant::assert([
            'entry_date'      => $header['entry_date'],
            'pair_status'     => $status,
            'pairs_inherited' => !empty($header['pairs_inherited']),
        ], $lines, $form);

        return $paired ? null : ['status' => (string) $status, 'reason' => $reason !== null ? mb_substr((string) $reason, 0, 64) : null];
    }

    /** Zapíše (nebo po rozdělení na dvojice smaže) posouzení zápisu bez dvojic. */
    private function recordPairStatus(int $supplierId, int $entryId, ?array $status, bool $paired): void
    {
        if ($status !== null) {
            $this->db->pdo()->prepare(
                'INSERT INTO journal_entry_pair_status (supplier_id, entry_id, status, reason, run_id, assessed_at)
                 VALUES (?, ?, ?, ?, NULL, NOW())
                 ON DUPLICATE KEY UPDATE status = VALUES(status), reason = VALUES(reason), run_id = NULL, assessed_at = VALUES(assessed_at)'
            )->execute([$supplierId, $entryId, $status['status'], $status['reason']]);
            return;
        }
        if ($paired) {
            $this->db->pdo()->prepare('DELETE FROM journal_entry_pair_status WHERE supplier_id = ? AND entry_id = ?')
                ->execute([$supplierId, $entryId]);
        }
    }

    /**
     * Zapíše řádky zápisu. Jediný zapisovač řádků deníku: před zápisem ověří invarianty
     * dvojic nad výsledným stavem zápisu (`$resultLines`, jinak `$lines`) a po zápisu
     * zaznamená posouzení zápisu bez dvojic.
     *
     * @param array<string,mixed> $header hlavička zápisu (supplier_id, entry_date, source_type, …)
     * @param list<array{account_id:int, side:'debit'|'credit', amount:float|string, is_red_storno?:bool, cost_center?:?string, project_id?:?int, line_no?:int, pair_no?:?int, line_text?:?string}> $lines
     * @param list<array<string,mixed>>|null $resultLines celý zápis po zápisu (výměna části řádků)
     */
    private function insertLines(\PDO $pdo, int $entryId, array $header, array $lines, ?array $resultLines = null): void
    {
        $supplierId = (int) $header['supplier_id'];
        $result = $resultLines ?? $lines;
        $status = $this->assertPairs($header, $result);
        $this->writeLines($pdo, $entryId, $supplierId, $lines);
        $paired = false;
        foreach ($result as $line) {
            if (isset($line['pair_no'])) {
                $paired = true;
                break;
            }
        }
        $this->recordPairStatus($supplierId, $entryId, $status, $paired);
    }

    /** @param list<array<string,mixed>> $lines */
    private function writeLines(\PDO $pdo, int $entryId, int $supplierId, array $lines): void
    {
        $lineStmt = $pdo->prepare(
            'INSERT INTO journal_entry_lines
                (entry_id, supplier_id, account_id, side, amount, is_red_storno, currency_code, fx_rate,
                 amount_foreign, cost_center, project_id, line_no, pair_no, line_text)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $n = 0;
        $assignmentLines = [];
        foreach ($lines as $line) {
            $lineStmt->execute([
                $entryId,
                $supplierId,
                $line['account_id'],
                $line['side'],
                JournalLineAmount::decimal($line['amount']),
                !empty($line['is_red_storno']) ? 1 : 0,
                $line['currency_code'] ?? null,
                $line['fx_rate'] ?? null,
                isset($line['amount_foreign']) ? JournalLineAmount::decimal($line['amount_foreign']) : null,
                $line['cost_center'] ?? null,
                isset($line['project_id']) && (int) $line['project_id'] > 0 ? (int) $line['project_id'] : null,
                $line['line_no'] ?? $n,
                isset($line['pair_no']) ? (int) $line['pair_no'] : null,
                self::lineText($line),
            ]);
            // Dimenze řádku (migrace 1860) — typ => hodnota, zvalidované PostingService
            // resp. volajícím. Řádek se při přepisu maže celý, vazba odejde kaskádou.
            $hasDims = !empty($line['dimensions']) && is_array($line['dimensions']);
            $hasSplits = !empty($line['dimension_splits']) && is_array($line['dimension_splits']);
            if ($hasDims || $hasSplits) {
                $lineId = (int) $pdo->lastInsertId();
                $assignmentLines[$lineId] = $line;
            }
            $n++;
        }
        if ($assignmentLines !== []) {
            (new DimensionAssignmentRepository($this->db))->insertLineAssignments($supplierId, $assignmentLines);
        }
    }

    /**
     * VŠECHNY zápisy zdrojového dokladu, i s řádky — podklad pro sekci „Zaúčtování"
     * na detailu dokladu. Na rozdíl od {@see findBySource()} nevrací jen poslední
     * zápis: doklad se dá přeúčtovat i stornovat a účetní musí vidět celý případ.
     *
     * Protizápis se dohledá přes `reversed_by`, ne přes zdroj: storno má source_id
     * ZÁMĚRNĚ NULL (jinak by ho findBySource() vydával za zaúčtování dokladu), takže
     * by jinak ze sekce vypadlo právě to, co doklad odúčtovalo.
     *
     * @return list<array<string,mixed>>
     */
    public function listBySourceWithLines(int $supplierId, string $sourceType, int $sourceId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, supplier_id, period_id, entry_date, document_date, document_no, description,
                    source_type, source_id, posted_at, posted_by, reversed_by, row_version,
                    created_at, updated_at
               FROM journal_entries
              WHERE supplier_id = ? AND source_type = ? AND source_id = ?
              ORDER BY id ASC'
        );
        $stmt->execute([$supplierId, $sourceType, $sourceId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            return [];
        }

        $reversalIds = array_values(array_filter(array_map(
            static fn (array $r): ?int => $r['reversed_by'] === null ? null : (int) $r['reversed_by'],
            $rows,
        )));
        if ($reversalIds !== []) {
            $place = implode(',', array_fill(0, count($reversalIds), '?'));
            $stmt = $this->db->pdo()->prepare(
                "SELECT id, supplier_id, period_id, entry_date, document_date, document_no, description,
                        source_type, source_id, posted_at, posted_by, reversed_by, row_version,
                        created_at, updated_at
                   FROM journal_entries
                  WHERE supplier_id = ? AND id IN ({$place})"
            );
            $stmt->execute([$supplierId, ...$reversalIds]);
            $rows = [...$rows, ...$stmt->fetchAll(PDO::FETCH_ASSOC)];
            usort($rows, static fn (array $a, array $b): int => (int) $a['id'] <=> (int) $b['id']);
        }

        $entries = [];
        foreach ($rows as $row) {
            $entry = $this->cast($row);
            $entry['lines'] = $this->linesForEntry((int) $entry['id'], $supplierId);
            $entries[] = $entry;
        }

        return $entries;
    }

    /**
     * Poslední zápisy pro dávku zdrojů bez dotazu pro každý rok odpisů.
     */
    public function latestBySourceIds(int $supplierId, string $sourceType, array $sourceIds): array
    {
        if ($sourceIds === []) return [];
        $placeholders = implode(',', array_fill(0, count($sourceIds), '?'));
        $stmt = $this->db->pdo()->prepare(
            "WITH ranked AS (
                SELECT id, source_id, reversed_by,
                       ROW_NUMBER() OVER (PARTITION BY source_id ORDER BY id DESC) AS position
                  FROM journal_entries
                 WHERE supplier_id = ? AND source_type = ? AND source_id IN ($placeholders)
            ) SELECT id, source_id, reversed_by FROM ranked WHERE position = 1"
        );
        $stmt->execute([$supplierId, $sourceType, ...$sourceIds]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $out[(int) $row['source_id']] = $row;
        return $out;
    }

    /** Existující zápis pro zdrojový doklad (idempotence zaúčtování). */
    public function findBySource(int $supplierId, string $sourceType, int $sourceId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, supplier_id, period_id, entry_date, document_date, document_no, description,
                    source_type, source_id, posted_at, posted_by, reversed_by, row_version,
                    created_at, updated_at
               FROM journal_entries
              WHERE supplier_id = ? AND source_type = ? AND source_id = ?
              ORDER BY id DESC
              LIMIT 1'
        );
        $stmt->execute([$supplierId, $sourceType, $sourceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->cast($row);
    }

    /**
     * Jako findBySource, ale ZAMYKAJÍCÍM čtením (FOR UPDATE). Po unique-violation
     * (souběžný zápis pro týž zdroj) nesmí volající dohledat vítěze prostým SELECTem —
     * ten by v REPEATABLE READ vracel konzistentní snapshot z počátku transakce, kde
     * čerstvě commitnutý vítězný řádek ještě NENÍ (stale → null). Zamykající čtení
     * obchází snapshot: přečte poslední commitnutou verzi a počká na dokončení souběžné
     * transakce, takže retry vždy dostane AKTUÁLNÍ vítězný zápis. Volající drží transakci.
     */
    public function findBySourceForUpdate(int $supplierId, string $sourceType, int $sourceId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, supplier_id, period_id, entry_date, document_date, document_no, description,
                    source_type, source_id, posted_at, posted_by, reversed_by, row_version,
                    created_at, updated_at
               FROM journal_entries
              WHERE supplier_id = ? AND source_type = ? AND source_id = ?
              ORDER BY id DESC
              LIMIT 1
              FOR UPDATE'
        );
        $stmt->execute([$supplierId, $sourceType, $sourceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->cast($row);
    }

    /**
     * AKTIVNÍ (nestornovaný) zápis zdrojového dokladu, zamykajícím čtením — nosič
     * idempotence zaúčtování od migrace 1752.
     *
     * `findBySourceForUpdate()` vrací i STORNOVANÝ zápis, což pro idempotenci není
     * to, co se hledá: §35 ZoÚ (a docblock {@see PostingService::rewriteExisting()})
     * říká, že oprava po stornu se dělá NOVÝM zápisem, ne mutací stornovaného. Dokud
     * se ptalo na „poslední zápis dokladu", skončil re-post po stornu chybou
     * `entry_reversed` a doklad se už nedal zaúčtovat vůbec. Unikát
     * `uq_je_supplier_active_source` hlídá přesně tuhle množinu.
     */
    public function findActiveBySourceForUpdate(int $supplierId, string $sourceType, int $sourceId): ?array
    {
        return $this->findActiveBySource($supplierId, $sourceType, $sourceId, true);
    }

    public function findActiveBySource(int $supplierId, string $sourceType, int $sourceId, bool $forUpdate = false): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, supplier_id, period_id, entry_date, document_date, document_no, description,
                    source_type, source_id, posted_at, posted_by, reversed_by, row_version,
                    created_at, updated_at
               FROM journal_entries
              WHERE supplier_id = ? AND source_type = ? AND source_id = ? AND reversed_by IS NULL
              ORDER BY id DESC
              LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $stmt->execute([$supplierId, $sourceType, $sourceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->cast($row);
    }

    public function find(int $id, int $supplierId): ?array
    {
        // `reverses_entry_id`: co tenhle zápis stornuje. Protizápis nese zdroj jen jako typ,
        // bez ID — bez téhle vazby z něj v detailu nevede cesta zpět na stornovaný zápis.
        $stmt = $this->db->pdo()->prepare(
            'SELECT je.id, je.supplier_id, je.period_id, je.entry_date, je.document_date,
                    je.document_no, je.description, je.source_type, je.source_id, je.posted_at,
                    je.posted_by, je.reversed_by, je.row_version, je.created_at, je.updated_at,
                    (SELECT r.id FROM journal_entries r
                      WHERE r.supplier_id = je.supplier_id AND r.reversed_by = je.id LIMIT 1) AS reverses_entry_id
               FROM journal_entries je
              WHERE je.id = ? AND je.supplier_id = ?'
        );
        $stmt->execute([$id, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $entry = $this->cast($row);
        $entry['lines'] = $this->linesForEntry($id, $supplierId);
        return $entry;
    }

    /**
     * Načte zápis a uzamkne jeho hlavičku pro souběžné reverse()/replace().
     * Volající musí držet transakci; řádky se čtou až po získání zámku, takže
     * protizápis vždy zrcadlí poslední atomicky uloženou verzi zápisu.
     */
    public function findForUpdate(int $id, int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, supplier_id, period_id, entry_date, document_date, document_no, description,
                    source_type, source_id, posted_at, posted_by, reversed_by, row_version,
                    created_at, updated_at
               FROM journal_entries
              WHERE id = ? AND supplier_id = ?
              FOR UPDATE'
        );
        $stmt->execute([$id, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $entry = $this->cast($row);
        $entry['lines'] = $this->linesForEntry($id, $supplierId, true);
        return $entry;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function linesForEntry(int $entryId, int $supplierId, bool $forUpdate = false): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, entry_id, supplier_id, account_id, side, amount, is_red_storno, currency_code, fx_rate,
                    amount_foreign, cost_center, project_id, line_no, pair_no, line_text
               FROM journal_entry_lines
              WHERE entry_id = ? AND supplier_id = ?
              ORDER BY line_no ASC, id ASC' . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $stmt->execute([$entryId, $supplierId]);
        return array_map(static function (array $r): array {
            $r = self::castPairColumns($r);
            $r['id'] = (int) $r['id'];
            $r['entry_id'] = (int) $r['entry_id'];
            $r['supplier_id'] = (int) $r['supplier_id'];
            $r['account_id'] = (int) $r['account_id'];
            $r['amount'] = (float) $r['amount'];
            $r['is_red_storno'] = (bool) $r['is_red_storno'];
            $r['fx_rate'] = $r['fx_rate'] === null ? null : (float) $r['fx_rate'];
            $r['amount_foreign'] = $r['amount_foreign'] === null ? null : (float) $r['amount_foreign'];
            $r['project_id'] = $r['project_id'] === null ? null : (int) $r['project_id'];
            $r['line_no'] = (int) $r['line_no'];
            return $r;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Id zápisů, které vybírá filtr seznamu deníku ({@see paginate()}), pro hromadné akce:
     * nejvýš `$limit` id a celkový počet, ať jde odmítnout výběr nad limitem.
     *
     * @param array<string,mixed> $filters
     * @return array{total:int, ids:list<int>}
     */
    public function filteredIds(int $supplierId, array $filters, int $limit): array
    {
        [$whereSql, $params] = $this->buildWhere($supplierId, $filters);
        $stmt = $this->db->pdo()->prepare(
            "SELECT je.id, COUNT(*) OVER() AS total FROM journal_entries je WHERE {$whereSql} ORDER BY je.entry_date, je.id LIMIT " . max(1, $limit)
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return [
            'total' => $rows === [] ? 0 : (int) $rows[0]['total'],
            'ids'   => array_map(static fn (array $r): int => (int) $r['id'], $rows),
        ];
    }

    /**
     * Stránkovaný seznam zápisů pro firmu s filtry (období / rozsah dat / typ zdroje /
     * stav zaúčtování). Vrací hlavičky (bez řádků) + celkový počet pro paginaci.
     *
     * @param array{document_no?:string, period_id?:int, date_from?:string, date_to?:string, source_type?:string, source_id?:int, entry_id?:int, entry_ids?:list<int>, posted?:bool, automation?:string, q?:string, account_from?:string, account_to?:string, amount_from?:float, amount_to?:float, dimension?:DimensionFilter} $filters
     * @return array{items:list<array<string,mixed>>, total:int}
     */
    public function paginate(int $supplierId, array $filters, int $limit, int $offset): array
    {
        [$whereSql, $params] = $this->buildWhere($supplierId, $filters);

        $pdo = $this->db->pdo();
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM journal_entries je WHERE {$whereSql}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        // Filtr na účet (Featura D) mění i sémantiku sloupce Částka: bez filtru je to
        // Σ MD celého zápisu (AMOUNT_SUBQUERY — nález „ČÁSTKA u filtru na účet"), S
        // filtrem musí jít o částku PŘIPADAJÍCÍ na vybraný účet/rozsah a jeho stranu,
        // jinak účetní vidí (typicky vyšší) číslo, které s tím, podle čeho filtroval,
        // vůbec nesouvisí — u zápisu s víc nohama na různých účtech to bylo klidně
        // několikanásobně nadhodnocené. Select-param(y) MUSÍ jít PŘED $params z
        // buildWhere(), protože SELECT v SQL textu předchází WHERE — i po vnoření
        // WHERE do poddotazu `pick` níž, ten stojí ve FROM, tedy pořád až za SELECTem.
        $accountFiltered = self::hasAccountRangeFilter($filters);
        if ($accountFiltered) {
            $amountSelect = self::FILTERED_NET_AMOUNT_SUBQUERY . ' AS amount,';
            [$from, $to] = self::accountRangeBounds($filters);
            $selectParams = [$from, $to];
        } else {
            $amountSelect = self::AMOUNT_SUBQUERY . ' AS amount,';
            $selectParams = [];
        }

        $sortColumns = [
            'date' => 'je.entry_date', 'document_date' => 'je.document_date',
            'description' => 'je.description', 'source' => 'je.source_type',
            'status' => "CASE WHEN je.reversed_by IS NOT NULL THEN 'reversed' WHEN je.posted_at IS NULL THEN 'draft' ELSE 'posted' END",
            'posted_at' => 'je.posted_at',
            'posted_by' => '(SELECT u.name FROM users u WHERE u.id = je.posted_by)',
            'entry_id' => 'je.id', 'created_at' => 'je.created_at', 'updated_at' => 'je.updated_at',
            'document_no' => "COALESCE(je.document_no,
                (SELECT i.varsymbol FROM invoices i WHERE je.source_type = 'invoice' AND i.id = je.source_id AND i.supplier_id = je.supplier_id),
                (SELECT pi.vendor_invoice_number FROM purchase_invoices pi WHERE je.source_type = 'purchase_invoice' AND pi.id = je.source_id AND pi.supplier_id = je.supplier_id),
                (SELECT pi.varsymbol FROM purchase_invoices pi WHERE je.source_type = 'purchase_invoice' AND pi.id = je.source_id AND pi.supplier_id = je.supplier_id))",
            'amount' => $accountFiltered ? 'ABS(' . self::FILTERED_NET_AMOUNT_SUBQUERY . ')' : self::AMOUNT_SUBQUERY,
            'debit_accounts' => "(SELECT MIN(ca.account_code) FROM journal_entry_lines jel JOIN chart_of_accounts ca ON ca.id = jel.account_id WHERE jel.entry_id = je.id AND jel.supplier_id = je.supplier_id AND jel.side = 'debit')",
            'credit_accounts' => "(SELECT MIN(ca.account_code) FROM journal_entry_lines jel JOIN chart_of_accounts ca ON ca.id = jel.account_id WHERE jel.entry_id = je.id AND jel.supplier_id = je.supplier_id AND jel.side = 'credit')",
            'vat_breakdown' => "CASE WHEN je.source_type = 'invoice' THEN (SELECT i.total_vat FROM invoices i WHERE i.id = je.source_id AND i.supplier_id = je.supplier_id) WHEN je.source_type = 'purchase_invoice' THEN (SELECT pi.total_vat FROM purchase_invoices pi WHERE pi.id = je.source_id AND pi.supplier_id = je.supplier_id) ELSE NULL END",
        ];
        $sortKey = (string) ($filters['sort_key'] ?? '');
        $sortDir = strtolower((string) ($filters['sort_dir'] ?? '')) === 'asc' ? 'ASC' : 'DESC';
        $sortExpression = $sortColumns[$sortKey] ?? 'je.entry_date';
        $sortDir = isset($sortColumns[$sortKey]) ? $sortDir : 'DESC';
        $sortParams = $sortKey === 'amount' && $accountFiltered ? $selectParams : [];

        // Majetek — čitelný label u source_type 'asset'/'asset_disposal' (source_id = ID
        // karty majetku) i 'depreciation' (source_id = ID řádku depreciation_entries, proto
        // se ID karty dohledává přes mezi-JOIN na dep — viz FEATURA C, audit 2026-07 follow-up).
        //
        // Stránka se VYBÍRÁ DŘÍV, než se připojí dekorační JOINy (poddotaz `pick`).
        // Dokud filtr, řazení i LIMIT visely až nad joinem přes jedenáct tabulek,
        // musela databáze celý ten join sestavit pro VŠECHNY zápisy firmy a teprve
        // z něj vzít padesát řádků. Na produkci to znamenalo `Rows_examined: 69786`
        // a 10,6 s na jedno otevření deníku, protože si optimalizátor na self-join
        // `rev_src` nevzal index a jel přes něj BNL scan (viz migrace 1856, která
        // ten index doplňuje). S předvýběrem se dekorace počítá jen pro `$limit`
        // řádků, takže cena přestane záviset na velikosti tabulky — a zůstane nízká
        // i u instalace, kde zápisů přibývají statisíce.
        //
        // Poddotaz `pick` si drží alias `je`, protože `buildWhere()` prefixuje
        // podmínky `je.` — vnější `je` je jiný rozsah platnosti a je to schválně.
        // ORDER BY musí být v OBOU úrovních: vnitřní určuje, KTERÝCH padesát řádků
        // se vybere, vnější jejich pořadí ve výsledku (JOIN ho jinak nezaručuje).
        $sql = "SELECT je.id, je.supplier_id, je.period_id, je.entry_date, je.document_date,
                       COALESCE(je.document_no, src_i.varsymbol, src_pi.vendor_invoice_number, src_pi.varsymbol) AS document_no,
                       je.description, je.source_type, je.source_id, je.posted_at,
                       je.posted_by, je.reversed_by, je.row_version, je.created_at, je.updated_at,
                       u.name AS posted_by_name,
                       {$amountSelect}
                       COALESCE(bt.statement_id, rev_bt.statement_id) AS source_statement_id,
                       COALESCE(bt.bank_ref, rev_bt.bank_ref) AS source_bank_ref,
                       COALESCE(cd.doc_number, rev_cd.doc_number) AS source_doc_number,
                       COALESCE(cd.register_id, rev_cd.register_id) AS source_register_id,
                       ast.id AS source_asset_id,
                       ast.name AS source_asset_name,
                       stl.doc_type AS source_settlement_doc_type,
                       stl.doc_id AS source_settlement_doc_id,
                       acs.accrual_item_id AS source_accrual_item_id,
                       -- Protizápis nese zdroj jen jako typ, ne jako ID: doklad patří tomu,
                       -- co stornuje. Bez tohohle na něm chybí proklik na prvotní doklad
                       -- i cesta zpět na stornovaný zápis, takže ze storna nevede nikam nic.
                       rev_src.id AS reverses_entry_id,
                       COALESCE(je.source_id, rev_src.source_id) AS source_link_id,
                       " . self::DOCUMENT_FACT_SELECT . "
                  FROM (SELECT je.id, {$sortExpression} AS sort_value
                          FROM journal_entries je
                         WHERE {$whereSql}
                         ORDER BY sort_value {$sortDir}, je.id DESC
                         LIMIT {$limit} OFFSET {$offset}) AS pick
                  JOIN journal_entries je ON je.id = pick.id
             LEFT JOIN users u ON u.id = je.posted_by
             " . self::DOCUMENT_SOURCE_JOINS . "
             LEFT JOIN invoices src_i ON je.source_type = 'invoice'
                    AND src_i.id = je.source_id AND src_i.supplier_id = je.supplier_id
             LEFT JOIN purchase_invoices src_pi ON je.source_type = 'purchase_invoice'
                    AND src_pi.id = je.source_id AND src_pi.supplier_id = je.supplier_id
             LEFT JOIN depreciation_entries dep ON je.source_type = 'depreciation'
                    AND dep.id = je.source_id AND dep.supplier_id = je.supplier_id
             LEFT JOIN assets ast ON ast.supplier_id = je.supplier_id
                    AND ast.id = CASE
                        WHEN je.source_type IN ('asset', 'asset_disposal') THEN je.source_id
                        WHEN je.source_type = 'depreciation' THEN dep.asset_id
                        ELSE NULL
                    END
             LEFT JOIN accrual_schedule acs ON je.source_type IN ('accrual_release', 'accrual_termination')
                    AND acs.supplier_id = je.supplier_id AND acs.id = COALESCE(je.source_id, rev_src.source_id)
             " . self::DOCUMENT_FACT_JOINS . "
                 ORDER BY pick.sort_value {$sortDir}, je.id DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([...$selectParams, ...$sortParams, ...$params]);
        $items = array_map(fn (array $r): array => $this->castListRow($r, $accountFiltered), $stmt->fetchAll(PDO::FETCH_ASSOC));

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Rozklad popisu zápisů ({@see JournalDescriptionBuilder::parts()}) a číslo dokladu nad
     * týmiž údaji dokladu jako seznam deníku, pro sestavy, které ukazují číslo dokladu ve
     * vlastním sloupci (rozpis hlavní knihy). Číslo dokladu doplní zápisy bez `document_no`
     * (starší zápisy faktur) z dokladu, aby po zkrácení popisu nezmizelo.
     * Jeden dotaz na dávku, každý JOIN po klíči.
     *
     * @param list<int> $entryIds
     * @return array<int, array{document_no:?string, parts:array{kind:?string, partner:?string, vs:?string, text:?string}}>
     */
    public function descriptionParts(int $supplierId, array $entryIds): array
    {
        $entryIds = array_values(array_unique(array_map('intval', $entryIds)));
        $out = [];
        // Kus = strop dávky rozpisu ({@see LedgerReportRepository::generalLedgerLineBatches()}),
        // takže jedna dávka je vždy jeden dotaz.
        foreach (array_chunk($entryIds, 1000) as $chunk) {
            [$sql, $params] = $this->descriptionPartsQuery($supplierId, $chunk);
            $stmt = $this->db->pdo()->prepare($sql);
            $stmt->execute($params);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[(int) $r['id']] = [
                    'document_no' => JournalDescriptionBuilder::clean(
                        $r['document_no'] ?? $r['source_doc_number'] ?? $r['source_bank_ref'] ?? null,
                    ),
                    'parts' => JournalDescriptionBuilder::parts(
                        $r['description'] ?? null,
                        (string) $r['source_type'],
                        $r,
                    ),
                ];
            }
        }
        return $out;
    }

    /**
     * SQL a parametry dotazu {@see descriptionParts()} pro jeden kus zápisů (veřejné kvůli EXPLAIN testu).
     *
     * @param list<int> $entryIds neprázdný seznam
     * @return array{0:string, 1:list<int>}
     */
    public function descriptionPartsQuery(int $supplierId, array $entryIds): array
    {
        $marks = implode(',', array_fill(0, count($entryIds), '?'));
        return ["SELECT je.id, je.description, je.source_type,
                        COALESCE(je.document_no, src_i.varsymbol, src_pi.vendor_invoice_number, src_pi.varsymbol) AS document_no,
                        COALESCE(bt.bank_ref, rev_bt.bank_ref) AS source_bank_ref,
                        COALESCE(cd.doc_number, rev_cd.doc_number) AS source_doc_number,
                        " . self::DOCUMENT_FACT_SELECT . "
                   FROM journal_entries je
                   " . self::DOCUMENT_SOURCE_JOINS . "
              LEFT JOIN invoices src_i ON je.source_type = 'invoice'
                     AND src_i.id = je.source_id AND src_i.supplier_id = je.supplier_id
              LEFT JOIN purchase_invoices src_pi ON je.source_type = 'purchase_invoice'
                     AND src_pi.id = je.source_id AND src_pi.supplier_id = je.supplier_id
              " . self::DOCUMENT_FACT_JOINS . "
                  WHERE je.supplier_id = ? AND je.id IN ({$marks})", [$supplierId, ...array_map('intval', $entryIds)]];
    }

    /**
     * Bankovní zápis nese číslo v řadě účtu (BCR-08), ID pohybu z banky zůstává na pohybu.
     * Vyhledávání podle čísla dokladu ho proto hledá i tam. Jeden placeholder (LIKE).
     */
    private const BANK_REF_MATCH = "(je.source_type = 'bank' AND EXISTS(
                    SELECT 1 FROM bank_transactions bt_ref
                     WHERE bt_ref.id = je.source_id AND bt_ref.bank_ref LIKE ? ESCAPE '='
                ))";

    /**
     * Údaje prvotního dokladu do volitelných sloupců deníku (Firma, IČ, VS, číslo dokladu
     * dodavatele, DUZP, datum přijetí, splatnost, úhrada, účet protistrany) a čitelný
     * štítek zdroje (`source_ref`). Doklad se hledá stejně jako proklik: přímý zdroj, zdroj
     * stornovaného zápisu, doklad vyrovnaný zápočtem a faktura uhrazená pokladním dokladem.
     * Převzaté zápisy z převodů mají tytéž vazby, takže sloupce plní i ony.
     *
     * Počítá se jen pro vybranou stránku (poddotaz `pick` v paginate()), každý JOIN jde po
     * primárním nebo unikátním klíči. Podle těchto sloupců se proto neřadí ani nefiltruje.
     */
    private const DOCUMENT_FACT_SELECT = "
                       CASE je.source_type
                           WHEN 'invoice' THEN fi.varsymbol
                           WHEN 'purchase_invoice' THEN fpi.varsymbol
                           WHEN 'other_item' THEN foi.document_no
                           WHEN 'cash' THEN COALESCE(cd.doc_number, rev_cd.doc_number)
                           ELSE NULL
                       END AS source_ref,
                       COALESCE(foi.partner_name, fid.partner_name, cd.partner_name, rev_cd.partner_name,
                                NULLIF(fc.company_name, ''), NULLIF(TRIM(CONCAT_WS(' ', fc.first_name, fc.last_name)), ''),
                                bt.counterparty_name, rev_bt.counterparty_name) AS partner_name,
                       COALESCE(NULLIF(cd.partner_ic, ''), NULLIF(rev_cd.partner_ic, ''), NULLIF(fc.ic, '')) AS partner_ic,
                       COALESCE(NULLIF(fi.payment_variable_symbol, ''), fi.varsymbol,
                                NULLIF(fpi.payment_variable_symbol, ''), fpi.vendor_invoice_number,
                                NULLIF(foi.variable_symbol, ''),
                                NULLIF(bt.variable_symbol, ''), NULLIF(rev_bt.variable_symbol, '')) AS variable_symbol,
                       fpi.vendor_invoice_number AS vendor_document_no,
                       COALESCE(fi.tax_date, fpi.tax_date, cd.tax_date, rev_cd.tax_date) AS tax_date,
                       fpi.received_at AS received_date,
                       COALESCE(fi.due_date, fpi.due_date, foi.due_on) AS due_date,
                       COALESCE(fi.paid_at, fpi.paid_at) AS paid_date,
                       COALESCE(
                           NULLIF(CONCAT_WS('/', NULLIF(COALESCE(bt.counterparty_account, rev_bt.counterparty_account), ''),
                                                 NULLIF(COALESCE(bt.counterparty_bank, rev_bt.counterparty_bank), '')), ''),
                           NULLIF(CONCAT_WS('/', NULLIF(foi.counterparty_account, ''), NULLIF(foi.counterparty_bank_code, '')), ''),
                           NULLIF(CONCAT_WS('/', NULLIF(fpi.payment_account_number, ''), NULLIF(fpi.payment_bank_code, '')), ''),
                           NULLIF(fpi.payment_iban, '')
                       ) AS counterparty_account";

    /**
     * Cesta od zápisu k prvotnímu dokladu, kterou sdílí seznam deníku ({@see paginate()})
     * a soupiska dokladů ({@see documentSelection()}): stornovaný zápis (`rev_src`),
     * bankovní pohyb, pokladní doklad a zápočet. Předpokládá alias zápisu `je`.
     */
    private const DOCUMENT_SOURCE_JOINS = "
             -- FORCE INDEX: `reversed_by` je skoro všude NULL, optimizér pak odhaduje tisíce
             -- řádků na lookup a volí BNL přes celou tabulku (0,2 s na stránku u 100 tis. zápisů, roste
             -- s počtem zápisů). S indexem 1 ms.
             LEFT JOIN journal_entries rev_src FORCE INDEX (idx_je_supplier_reversed_by)
                    ON rev_src.supplier_id = je.supplier_id
                    AND rev_src.reversed_by = je.id
             LEFT JOIN bank_transactions rev_bt ON rev_src.source_type = 'bank' AND rev_bt.id = rev_src.source_id
             LEFT JOIN cash_documents rev_cd ON rev_src.source_type = 'cash' AND rev_cd.id = rev_src.source_id
             LEFT JOIN bank_transactions bt ON je.source_type = 'bank' AND bt.id = je.source_id
             LEFT JOIN cash_documents cd ON je.source_type = 'cash' AND cd.id = je.source_id
             -- Zápočet: source_id je ID ZÁPOČTU, ne dokladu. Bez tohohle JOINu nemá deník
             -- kam prokliknout — uživatel vidí číslo faktury, ale odkaz nikam nevede.
             LEFT JOIN invoice_settlements stl ON je.source_type = 'settlement'
                    AND stl.id = je.source_id AND stl.supplier_id = je.supplier_id";

    /** JOINy k DOCUMENT_FACT_SELECT, předpokládají aliasy z paginate() (je, rev_src, cd, rev_cd, stl). */
    private const DOCUMENT_FACT_JOINS = "
             LEFT JOIN invoices fi ON fi.supplier_id = je.supplier_id
                    AND fi.id = CASE
                        WHEN je.source_type = 'invoice' THEN COALESCE(je.source_id, rev_src.source_id)
                        WHEN je.source_type = 'settlement' AND stl.doc_type = 'invoice' THEN stl.doc_id
                        WHEN je.source_type = 'cash' THEN COALESCE(cd.invoice_id, rev_cd.invoice_id)
                        ELSE NULL
                    END
             LEFT JOIN purchase_invoices fpi ON fpi.supplier_id = je.supplier_id
                    AND fpi.id = CASE
                        WHEN je.source_type = 'purchase_invoice' THEN COALESCE(je.source_id, rev_src.source_id)
                        WHEN je.source_type = 'settlement' AND stl.doc_type = 'purchase_invoice' THEN stl.doc_id
                        WHEN je.source_type = 'cash' THEN COALESCE(cd.purchase_invoice_id, rev_cd.purchase_invoice_id)
                        ELSE NULL
                    END
             LEFT JOIN other_items foi ON je.source_type = 'other_item'
                    AND foi.id = COALESCE(je.source_id, rev_src.source_id) AND foi.supplier_id = je.supplier_id
             LEFT JOIN internal_documents fid ON je.source_type = 'manual'
                    AND fid.supplier_id = je.supplier_id AND fid.journal_entry_id = je.id
             LEFT JOIN clients fc ON fc.id = COALESCE(fi.client_id, fpi.vendor_id, foi.partner_id, fid.partner_id)";

    /** Korelovaný subselect s celkovou částkou zápisu (Σ MD = Σ Dal u vyváženého zápisu). */
    private const AMOUNT_SUBQUERY =
        '(SELECT COALESCE(SUM(jel.signed_amount), 0) FROM journal_entry_lines jel WHERE jel.entry_id = je.id AND jel.side = \'debit\')';

    /**
     * Korelovaný subselect s ČISTOU částkou zápisu na filtrovaném rozsahu účtů
     * (Σ MD − Σ D jen na řádcích, které padnou do account_from/account_to daného
     * zápisu). Znaménko nese stranu (>=0 → MD, <0 → D) — viz castListRow(). Používá
     * se MÍSTO AMOUNT_SUBQUERY, když je aktivní filtr na účet (viz paginate()); bez
     * něj zápis s nohou nákladu i nohou zúčtování zálohy na jiných účtech ukazoval
     * ve sloupci Částka Σ MD celého zápisu, ne částku vybraného účtu.
     *
     * Placeholdery (account_from, account_to) MUSÍ nést stejný rozsah jako EXISTS
     * filtr v buildWhere() — jinak by se řádek v seznamu objevil podle jednoho
     * rozsahu, ale částka by se počítala z jiného (viz accountRangeBounds()).
     */
    private const FILTERED_NET_AMOUNT_SUBQUERY =
        '(SELECT COALESCE(SUM(CASE WHEN jel.side = \'debit\' THEN jel.signed_amount ELSE -jel.signed_amount END), 0)
            FROM journal_entry_lines jel
            JOIN chart_of_accounts c ON c.id = jel.account_id
           WHERE jel.entry_id = je.id AND jel.supplier_id = je.supplier_id
             AND c.account_code BETWEEN ? AND ?)';

    /**
     * Soupiska dokladů (#356): řádky deníku vybrané filtrem seznamu, sečtené po prvotním
     * dokladu. Zápisy vybírá tentýž {@see buildWhere()} jako seznam (včetně jeho rozsahu
     * účtů a hledání), takže soupiska nikdy nevezme zápis, který uživatel na obrazovce
     * nevidí. Z nich se berou jen ŘÁDKY v rozsahu účtů soupisky a při filtru dimenze jen
     * řádky, které ho splní, dílem rozpadu ({@see DimensionFilter::lineSource()}, stejný
     * výklad jako sestavy). Úhrada nese dimenzi na saldokontu, mimo nákladové účty, proto
     * se v nákladovém rozsahu nezapočte. Uzavření a otevření knih nejsou doklady, jen
     * převádějí zůstatky, a vynulovaly by součet; soupiska je proto vynechává (skladové
     * sloty uzávěrky náklady mění a zůstávají).
     *
     * Bere jen zaúčtované zápisy (jako výsledovka po dimenzi). Částka je Σ MD − Σ D se
     * znaménkem červeného storna, u výnosových účtů Σ D − Σ MD, aby výnos byl kladný a vratka
     * záporná; pravidlo je totéž jako ve výsledovce po dimenzi (DimensionProfitService).
     * Doklad s účty různých druhů v rozsahu (náklady, výnosy, rozvaha) nese `mixed_sides`
     * a zůstává i s nulovou částkou, aby se vyrovnaný zápis neztratil bez upozornění. Protizápis a opravný zápis dvojice
     * nesou jen typ zdroje, doklad patří zápisu na začátku řetězu oprav (`reversed_by`,
     * `journal_pair_reversals`), takže úplné storno dá nulu a doklad s nulou se vynechá.
     * Doklad zápisu určuje skupina zaúčtování ({@see \MyInvoice\Service\Accounting\DocumentPostingGroup}), zápočet vede na
     * vyrovnanou fakturu, ruční zápis na interní doklad, úhrada (banka, pokladna) je vlastní
     * doklad a zápis bez dokladu tvoří vlastní skupinu. `corrected_outside` označí doklad,
     * jehož storno nebo oprava leží mimo filtr (typicky v jiném období). Seskupení
     * i součet počítá databáze, vrátí nejvýš `$limit + 1` dokladů, aby volající poznal
     * překročení.
     *
     * @param array<string,mixed> $filters filtr seznamu Deníku
     * @return list<array<string,mixed>>
     */
    public function documentSelection(int $supplierId, array $filters, string $lineAccountFrom, string $lineAccountTo, int $limit): array
    {
        if ($lineAccountFrom === '' || $lineAccountTo === '') {
            throw new \InvalidArgumentException('Soupiska dokladů vyžaduje rozsah účtů.');
        }
        [$whereSql, $whereParams] = $this->buildWhere($supplierId, $filters);
        [$accountFrom, $accountTo] = AccountCodeRange::sqlBoundsFor($lineAccountFrom, $lineAccountTo);
        [$lineSource, $lineParams] = $this->documentLineSource($supplierId, $filters);

        // Kořen řetězu oprav: storno (`reversed_by`, i storno storna) a opravný zápis dvojice
        // (`journal_pair_reversals`, i jeho storno nebo další oprava) nesou jen typ zdroje,
        // doklad patří zápisu na začátku řetězu. CTE nese jen zápisy v řetězcích, ostatní
        // zápisy jsou kořenem samy sobě (vzor JournalTaxOrigin, který opravy dvojic nezná).
        $s = (int) $supplierId;
        $origins = "doc_origins (id, root_id, reversed_by) AS (
                SELECT e.id, e.id, e.reversed_by FROM journal_entries e FORCE INDEX (idx_je_supplier_reversed_by)
                 WHERE e.supplier_id = {$s} AND e.reversed_by IS NOT NULL
                   AND NOT EXISTS (SELECT 1 FROM journal_entries p WHERE p.supplier_id = {$s} AND p.reversed_by = e.id)
                   AND NOT EXISTS (SELECT 1 FROM journal_pair_reversals pr WHERE pr.supplier_id = {$s} AND pr.reversal_entry_id = e.id)
                UNION
                SELECT e.id, e.id, e.reversed_by FROM journal_pair_reversals r
                  JOIN journal_entries e ON e.supplier_id = r.supplier_id AND e.id = r.entry_id
                 WHERE r.supplier_id = {$s}
                   AND NOT EXISTS (SELECT 1 FROM journal_entries p WHERE p.supplier_id = {$s} AND p.reversed_by = e.id)
                   AND NOT EXISTS (SELECT 1 FROM journal_pair_reversals pr WHERE pr.supplier_id = {$s} AND pr.reversal_entry_id = e.id)
                UNION
                SELECT c.id, o.root_id, c.reversed_by FROM doc_origins o
                  JOIN journal_entries c ON c.supplier_id = {$s} AND c.id = o.reversed_by
                UNION
                SELECT c.id, o.root_id, c.reversed_by FROM doc_origins o
                  JOIN journal_pair_reversals r ON r.supplier_id = {$s} AND r.entry_id = o.id
                  JOIN journal_entries c ON c.supplier_id = {$s} AND c.id = r.reversal_entry_id
            )";
        // Doklad kořene: vlastnictví skupinou zaúčtování (DocumentPostingGroup, převzaté
        // doklady účtované více zápisy), zápočet → vyrovnaná faktura, ruční zápis → interní
        // doklad, jinak zdroj zápisu. Uzavření knih (source_id = období) a otevření knih jen
        // převádějí zůstatky a vynulovaly by součet; skladové sloty uzávěrky náklady mění, zůstávají.
        $settled = "kstl.doc_type IN ('invoice', 'purchase_invoice')";
        $keys = "SELECT je.id AS entry_id, je.entry_date,
                        COALESCE(NULLIF(je.document_no, ''), CONCAT('#', je.id)) AS entry_label,
                        CASE
                            WHEN jdp.entry_id IS NOT NULL THEN jdp.doc_type
                            WHEN {$settled} THEN kstl.doc_type
                            WHEN root.source_type = 'manual' AND kid.id IS NOT NULL THEN 'internal_document'
                            WHEN root.source_type <> 'manual' AND root.source_id IS NOT NULL THEN root.source_type
                            ELSE 'entry'
                        END AS doc_kind,
                        CASE
                            WHEN jdp.entry_id IS NOT NULL THEN jdp.doc_id
                            WHEN {$settled} THEN kstl.doc_id
                            WHEN root.source_type = 'manual' AND kid.id IS NOT NULL THEN kid.id
                            WHEN root.source_type <> 'manual' AND root.source_id IS NOT NULL THEN root.source_id
                            ELSE root.id
                        END AS doc_id,
                        (origin.root_id IS NULL OR origin.root_id = je.id) AS is_root,
                        (origin.root_id IS NOT NULL AND EXISTS (
                            SELECT 1 FROM doc_origins chain WHERE chain.root_id = origin.root_id AND chain.id NOT IN (SELECT id FROM sel)
                        )) AS corrected_outside
                   FROM sel
                   JOIN journal_entries je ON je.id = sel.id
              LEFT JOIN doc_origins origin ON origin.id = je.id
                   JOIN journal_entries root ON root.supplier_id = je.supplier_id AND root.id = COALESCE(origin.root_id, je.id)
              LEFT JOIN journal_document_postings jdp ON jdp.supplier_id = root.supplier_id AND jdp.entry_id = root.id
              LEFT JOIN invoice_settlements kstl ON root.source_type = 'settlement'
                        AND kstl.supplier_id = root.supplier_id AND kstl.id = root.source_id
              LEFT JOIN internal_documents kid ON root.source_type = 'manual'
                        AND kid.supplier_id = root.supplier_id AND kid.journal_entry_id = root.id
                  WHERE root.source_type <> 'opening' AND NOT (" . ClosingSourceId::closeBooksSql('root') . ')';

        $sql = "SELECT g.doc_kind, g.doc_id, g.amount, g.first_entry_date, g.corrected_outside, g.mixed_sides, g.entry_ids, g.entry_labels, g.accounts, g.rep_id,
                       je.description,
                       -- Doklad smazaný po převzetí zůstává vlastníkem zápisů; číslo pak nese zápis.
                       COALESCE(CASE g.doc_kind
                           WHEN 'invoice' THEN di.varsymbol
                           WHEN 'purchase_invoice' THEN dpi.varsymbol
                           WHEN 'cash' THEN dcd.doc_number
                           WHEN 'other_item' THEN doi.document_no
                           WHEN 'internal_document' THEN did.document_no
                       END, je.document_no) AS our_no,
                       IF(g.doc_kind = 'purchase_invoice', dpi.vendor_invoice_number, NULL) AS counterparty_no,
                       -- Přenesená daňová povinnost: celkem dokladu je bez DPH, kterou si odběratel
                       -- přiznává sám (u neplátce či identifikované osoby jde do nákladu).
                       COALESCE(g.doc_kind = 'purchase_invoice' AND dpi.reverse_charge = 1, 0) AS reverse_charge,
                       dpi.total_vat AS supplier_vat,
                       COALESCE(dpi.vendor_snapshot, di.client_snapshot) AS partner_snapshot,
                       CASE g.doc_kind
                           WHEN 'invoice' THEN di.total_with_vat
                           WHEN 'purchase_invoice' THEN dpi.total_with_vat
                           WHEN 'cash' THEN dcd.total_amount
                           WHEN 'other_item' THEN doi.amount
                           WHEN 'internal_document' THEN did.amount
                           WHEN 'bank' THEN ABS(dbt.amount)
                           ELSE NULL
                       END AS document_total,
                       CASE g.doc_kind
                           WHEN 'invoice' THEN dccy.code
                           WHEN 'purchase_invoice' THEN dccy.code
                           WHEN 'cash' THEN 'CZK'
                           WHEN 'other_item' THEN COALESCE(NULLIF(doi.currency, ''), 'CZK')
                           WHEN 'internal_document' THEN 'CZK'
                           WHEN 'bank' THEN COALESCE(NULLIF(dbt.currency, ''), 'CZK')
                           ELSE NULL
                       END AS document_currency,
                       CASE g.doc_kind
                           WHEN 'invoice' THEN COALESCE(di.tax_date, di.issue_date)
                           WHEN 'purchase_invoice' THEN COALESCE(dpi.tax_date, dpi.issue_date)
                           WHEN 'cash' THEN COALESCE(dcd.tax_date, dcd.issue_date)
                           WHEN 'other_item' THEN doi.issued_on
                           WHEN 'internal_document' THEN did.document_date
                           ELSE NULL
                       END AS document_date,
                       COALESCE(did.partner_name, doi.partner_name, dcd.partner_name,
                                NULLIF(dc.company_name, ''), NULLIF(TRIM(CONCAT_WS(' ', dc.first_name, dc.last_name)), ''),
                                dbt.counterparty_name, cd.partner_name, rev_cd.partner_name,
                                bt.counterparty_name, rev_bt.counterparty_name) AS partner_name,
                       COALESCE(NULLIF(dcd.partner_ic, ''), NULLIF(dc.ic, ''), NULLIF(cd.partner_ic, ''), NULLIF(rev_cd.partner_ic, '')) AS partner_ic
                  FROM (SELECT k.doc_kind, k.doc_id,
                               SUM((CASE WHEN fl.side = 'debit' THEN fl.signed_amount ELSE -fl.signed_amount END)
                                   * (CASE WHEN ca.account_type = 'revenue' THEN -1 ELSE 1 END)) AS amount,
                               COUNT(DISTINCT CASE ca.account_type WHEN 'revenue' THEN 1 WHEN 'expense' THEN 2 ELSE 3 END) > 1 AS mixed_sides,
                               COALESCE(MIN(CASE WHEN k.is_root THEN k.entry_id END), MIN(k.entry_id)) AS rep_id,
                               MIN(k.entry_date) AS first_entry_date, MAX(k.corrected_outside) AS corrected_outside,
                               GROUP_CONCAT(DISTINCT k.entry_id ORDER BY k.entry_id) AS entry_ids,
                               GROUP_CONCAT(DISTINCT k.entry_label ORDER BY k.entry_label SEPARATOR ', ') AS entry_labels,
                               GROUP_CONCAT(DISTINCT ca.account_code ORDER BY ca.account_code SEPARATOR ', ') AS accounts
                          FROM {$lineSource}
                          JOIN chart_of_accounts ca ON ca.supplier_id = fl.supplier_id AND ca.id = fl.account_id AND ca.account_code BETWEEN ? AND ?
                          JOIN ({$keys}) k ON k.entry_id = fl.entry_id
                         GROUP BY k.doc_kind, k.doc_id
                        HAVING amount <> 0 OR mixed_sides) g
                  -- Nejdřív skupiny dokladů, pak hlavička zápisu po primárním klíči. Bez
                  -- STRAIGHT_JOIN optimalizátor četl všechny zápisy firmy přes index období
                  -- a skupiny k nim dohledával (2 s místo desetin u 300 tis. zápisů firmy).
                  STRAIGHT_JOIN journal_entries je ON je.id = g.rep_id AND je.supplier_id = ?
                  " . self::DOCUMENT_SOURCE_JOINS . "
             LEFT JOIN invoices di ON g.doc_kind = 'invoice' AND di.id = g.doc_id AND di.supplier_id = je.supplier_id
             LEFT JOIN purchase_invoices dpi ON g.doc_kind = 'purchase_invoice' AND dpi.id = g.doc_id AND dpi.supplier_id = je.supplier_id
             LEFT JOIN cash_documents dcd ON g.doc_kind = 'cash' AND dcd.id = g.doc_id AND dcd.supplier_id = je.supplier_id
             LEFT JOIN other_items doi ON g.doc_kind = 'other_item' AND doi.id = g.doc_id AND doi.supplier_id = je.supplier_id
             LEFT JOIN internal_documents did ON g.doc_kind = 'internal_document' AND did.id = g.doc_id AND did.supplier_id = je.supplier_id
             LEFT JOIN bank_transactions dbt ON g.doc_kind = 'bank' AND dbt.id = g.doc_id
             LEFT JOIN currencies dccy ON dccy.id = COALESCE(di.currency_id, dpi.currency_id)
             LEFT JOIN clients dc ON dc.supplier_id = je.supplier_id AND dc.id = COALESCE(di.client_id, dpi.vendor_id, doi.partner_id, did.partner_id)
                 LIMIT " . (max(1, $limit) + 1);
        // Jen zaúčtované zápisy, stejně jako výsledovka po dimenzi; koncept v řetězu oprav
        // je pro soupisku mimo výběr a doklad se označí (corrected_outside).
        $sql = "WITH RECURSIVE sel AS (SELECT je.id FROM journal_entries je WHERE {$whereSql} AND je.posted_at IS NOT NULL), {$origins}
                SELECT d.* FROM ({$sql}) d ORDER BY COALESCE(d.document_date, d.first_entry_date), d.rep_id";
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([...$whereParams, ...$lineParams, $accountFrom, $accountTo, $supplierId]);
        return array_map(static function (array $r): array {
            $r['doc_id'] = (int) $r['doc_id'];
            $r['entry_ids'] = array_map('intval', explode(',', (string) $r['entry_ids']));
            $r['amount'] = round((float) $r['amount'], 2);
            $r['document_total'] = $r['document_total'] === null ? null : round((float) $r['document_total'], 2);
            $r['corrected_outside'] = (bool) $r['corrected_outside'];
            $r['mixed_sides'] = (bool) $r['mixed_sides'];
            $r['reverse_charge'] = (bool) $r['reverse_charge'];
            $snapshot = json_decode((string) $r['partner_snapshot'], true);
            if (is_array($snapshot)) {
                $r['partner_name'] = DocumentPartnerName::fromSnapshot($snapshot) ?? $r['partner_name'];
                if (array_key_exists('ic', $snapshot)) {
                    $ic = trim((string) $snapshot['ic']);
                    $r['partner_ic'] = $ic !== '' ? $ic : null;
                }
            }
            unset($r['partner_snapshot']);
            return $r;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Zaúčtovaná daň i obrat ostatních řádků kromě DPH a závazku jsou v Kč.
     * Absolutní obrat zachová i řádky, které se navzájem vyruší.
     *
     * @param list<int> $entryIds
     * @param array<string,mixed> $filters
     * @return array<int,array{vat:string,has_vat:int,cost_turnover:string,selected_cost:string}>
     */
    public function reverseChargePostings(int $supplierId, array $entryIds, array $filters, string $accountFrom, string $accountTo): array
    {
        if ($entryIds === []) {
            return [];
        }
        $join = '';
        $lineParams = [];
        $selected = 'l.signed_amount';
        if (($filters['dimension'] ?? null) instanceof DimensionFilter) {
            [$lineSource, $lineParams] = $this->documentLineSource($supplierId, $filters);
            $join = "LEFT JOIN {$lineSource} ON fl.id = l.id";
            $selected = 'COALESCE(fl.signed_amount, 0)';
        }

        $marks = implode(',', array_fill(0, count($entryIds), '?'));
        $isVat = "a.account_code LIKE '343%'";
        $isCostCandidate = "a.account_code NOT LIKE '321%' AND a.account_code NOT LIKE '343%'";
        $stmt = $this->db->pdo()->prepare("SELECT l.entry_id,
                    SUM(CASE WHEN {$isVat} THEN IF(l.side = 'credit', l.signed_amount, -l.signed_amount) ELSE 0 END) AS vat,
                    MAX({$isVat}) AS has_vat,
                    SUM(CASE WHEN {$isCostCandidate} THEN ABS(l.signed_amount) ELSE 0 END) AS cost_turnover,
                    SUM(CASE WHEN {$isCostCandidate} AND a.account_type IN ('expense', 'asset') AND a.account_code BETWEEN ? AND ?
                             THEN ABS({$selected}) ELSE 0 END) AS selected_cost
              FROM journal_entry_lines l
              JOIN chart_of_accounts a ON a.supplier_id = l.supplier_id AND a.id = l.account_id
              {$join}
             WHERE l.supplier_id = ? AND l.entry_id IN ({$marks})
             GROUP BY l.entry_id");
        $stmt->execute([...AccountCodeRange::sqlBoundsFor($accountFrom, $accountTo), ...$lineParams, $supplierId, ...$entryIds]);
        return $stmt->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
    }

    /**
     * Počet konceptů, které by filtr seznamu a rozsah účtů soupisky vybraly, ale soupiska je
     * vynechává ({@see documentSelection()} bere jen zaúčtované zápisy). Do hlavičky soupisky,
     * aby vynechání nebylo bez stopy.
     *
     * @param array<string,mixed> $filters filtr seznamu Deníku
     */
    public function documentSelectionDraftCount(int $supplierId, array $filters, string $lineAccountFrom, string $lineAccountTo): int
    {
        [$whereSql, $whereParams] = $this->buildWhere($supplierId, $filters);
        [$accountFrom, $accountTo] = AccountCodeRange::sqlBoundsFor($lineAccountFrom, $lineAccountTo);
        [$lineSource, $lineParams] = $this->documentLineSource($supplierId, $filters);
        // Tentýž výběr řádků jako documentSelection() (rozsah i dimenze na témže řádku) a bez
        // uzavření a otevření knih, které soupiska nevezme ani po zaúčtování.
        $stmt = $this->db->pdo()->prepare("SELECT COUNT(DISTINCT je.id)
              FROM {$lineSource}
              JOIN chart_of_accounts ca ON ca.supplier_id = fl.supplier_id AND ca.id = fl.account_id AND ca.account_code BETWEEN ? AND ?
              JOIN journal_entries je ON je.id = fl.entry_id
             WHERE {$whereSql} AND je.posted_at IS NULL
               AND je.source_type <> 'opening' AND NOT (" . ClosingSourceId::closeBooksSql('je') . ')');
        $stmt->execute([...$lineParams, $accountFrom, $accountTo, ...$whereParams]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Řádky deníku pro soupisku jako odvozená tabulka `fl` (entry_id, supplier_id, account_id,
     * side, signed_amount): s filtrem dimenze jen řádky s její hodnotou dílem rozpadu
     * ({@see DimensionFilter::lineSource()}), jinak všechny řádky firmy.
     *
     * @param array<string,mixed> $filters
     * @return array{0:string, 1:list<int|string>}
     */
    private function documentLineSource(int $supplierId, array $filters): array
    {
        $dimension = $filters['dimension'] ?? null;
        if (!$dimension instanceof DimensionFilter) {
            return ['(SELECT fl.entry_id, fl.supplier_id, fl.account_id, fl.side, fl.signed_amount FROM journal_entry_lines fl WHERE fl.supplier_id = ?) fl', [$supplierId]];
        }
        // Meze data jen zužují poddotaz dimenze, zápisy vybírá buildWhere(). Filtr jen na
        // období dostane jeho hranice, jinak by poddotaz četl celou historii firmy.
        $from = $filters['date_from'] ?? null;
        $to = $filters['date_to'] ?? null;
        if (!empty($filters['period_id']) && ($from === null || $to === null)) {
            $period = $this->db->pdo()->prepare('SELECT starts_on, ends_on FROM accounting_periods WHERE supplier_id = ? AND id = ?');
            $period->execute([$supplierId, (int) $filters['period_id']]);
            $bounds = $period->fetch(PDO::FETCH_ASSOC) ?: [];
            $from ??= $bounds['starts_on'] ?? null;
            $to ??= $bounds['ends_on'] ?? null;
        }
        return $dimension->lineSource($supplierId, 'fl', $from, (string) ($to ?? '9999-12-31'));
    }

    /**
     * Export deníku (audit 2026-07, nález „Export a tisk účetního deníku"): VŠECHNY
     * zápisy odpovídající filtrům (bez stránkování), chronologicky (entry_date ASC),
     * s řádky (MD/Dal, kód a název účtu) — jak to zákonná kniha (§13 ZoÚ) vyžaduje.
     * `$limit` je tvrdý strop (viz JournalExportService) — vrátí max `$limit + 1`
     * hlaviček, aby volající poznal překročení bez druhého COUNT dotazu.
     *
     * Sloupec Částka řeší STEJNÝ nález jako paginate() („ČÁSTKA u filtru na účet"):
     * bez filtru na účet je to Σ MD celého zápisu (AMOUNT_SUBQUERY), s filtrem
     * FILTERED_NET_AMOUNT_SUBQUERY + amount_side — jinak export s filtrem na účet
     * ukazoval v hlavičkovém řádku (ReportXlsxExporter::journal(), journal.twig)
     * cizí číslo (Σ MD celého zápisu), ne částku vybraného účtu.
     *
     * @param array{document_no?:string, period_id?:int, date_from?:string, date_to?:string, source_type?:string, source_id?:int, entry_id?:int, entry_ids?:list<int>, posted?:bool, automation?:string, q?:string, account_from?:string, account_to?:string, amount_from?:float, amount_to?:float, dimension?:DimensionFilter} $filters
     * @return list<array<string,mixed>> hlavičky BEZ lines (viz linesForEntries)
     */
    public function forExport(int $supplierId, array $filters, int $limit): array
    {
        [$whereSql, $params] = $this->buildWhere($supplierId, $filters);

        // Select-param(y) MUSÍ jít PŘED $params z buildWhere() — viz stejná poznámka
        // v paginate().
        $accountFiltered = self::hasAccountRangeFilter($filters);
        if ($accountFiltered) {
            $amountSelect = self::FILTERED_NET_AMOUNT_SUBQUERY . ' AS amount';
            [$from, $to] = self::accountRangeBounds($filters);
            $selectParams = [$from, $to];
        } else {
            $amountSelect = self::AMOUNT_SUBQUERY . ' AS amount';
            $selectParams = [];
        }

        $sql = "SELECT je.id, je.entry_date, je.document_date,
                       COALESCE(je.document_no, src_i.varsymbol, src_pi.vendor_invoice_number, src_pi.varsymbol) AS document_no,
                       je.description,
                       je.source_type, je.source_id, je.posted_at, je.reversed_by,
                       u.name AS posted_by_name,
                       {$amountSelect}
                  FROM journal_entries je
             LEFT JOIN users u ON u.id = je.posted_by
             LEFT JOIN invoices src_i ON je.source_type = 'invoice'
                    AND src_i.id = je.source_id AND src_i.supplier_id = je.supplier_id
             LEFT JOIN purchase_invoices src_pi ON je.source_type = 'purchase_invoice'
                    AND src_pi.id = je.source_id AND src_pi.supplier_id = je.supplier_id
                 WHERE {$whereSql}
                 ORDER BY je.entry_date ASC, je.id ASC
                 LIMIT " . ($limit + 1);
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([...$selectParams, ...$params]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static function (array $r) use ($accountFiltered): array {
            $r['id'] = (int) $r['id'];
            $r['source_id'] = $r['source_id'] === null ? null : (int) $r['source_id'];
            $r['reversed_by'] = $r['reversed_by'] === null ? null : (int) $r['reversed_by'];
            if ($accountFiltered) {
                $net = (float) $r['amount'];
                $r['amount'] = round(abs($net), 2);
                // Nula (viz castListRow) se bere jako MD — neutrální volba, aby strana
                // nikdy nechyběla.
                $r['amount_side'] = $net < 0 ? 'credit' : 'debit';
            } else {
                $r['amount'] = (float) $r['amount'];
                $r['amount_side'] = null;
            }
            return $r;
        }, $rows);
    }

    /**
     * Řádky pro seznam zápisů (export) obohacené o kód/název účtu — jeden dotaz
     * pro celou dávku (žádné N+1), seskupené podle entry_id. `pair_no` a `line_text`
     * nese jen řádek, který je má (složený zápis klíče nedostane).
     *
     * `$withLineIds` (dvojice exportu deníku): navíc `line_id`, `account_id` a
     * `amount_foreign`, ze kterých se skládají dvojice i starší zápisy rozložené za běhu.
     *
     * @param list<int> $entryIds
     * @return array<int, list<array<string,mixed>>> keyed entry_id
     */
    public function linesForEntries(array $entryIds, int $supplierId, bool $withLineIds = false): array
    {
        if ($entryIds === []) {
            return [];
        }
        $place = implode(',', array_fill(0, count($entryIds), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT jel.id, jel.entry_id, jel.account_id, jel.side, jel.amount, jel.amount_foreign, jel.is_red_storno,
                    jel.cost_center, jel.line_no, jel.pair_no, jel.line_text, c.account_code, c.name AS account_name
               FROM journal_entry_lines jel
               JOIN chart_of_accounts c ON c.id = jel.account_id
              WHERE jel.entry_id IN ({$place}) AND jel.supplier_id = ?
              ORDER BY jel.entry_id ASC, jel.line_no ASC, jel.id ASC"
        );
        $stmt->execute([...$entryIds, $supplierId]);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $entryId = (int) $r['entry_id'];
            $line = [
                'side'         => $r['side'],
                'amount'       => (float) $r['amount'],
                'is_red_storno' => (bool) $r['is_red_storno'],
                'cost_center'  => $r['cost_center'],
                'account_code' => $r['account_code'],
                'account_name' => $r['account_name'],
                'pair_no'      => $r['pair_no'],
                'line_text'    => $r['line_text'],
            ];
            if ($withLineIds) {
                $line += [
                    'line_id'        => (int) $r['id'],
                    'account_id'     => (int) $r['account_id'],
                    'amount_decimal' => (string) $r['amount'],
                    'amount_foreign' => $r['amount_foreign'] === null ? null : (string) $r['amount_foreign'],
                ];
            }
            $out[$entryId][] = self::castPairColumns($line);
        }
        return $out;
    }

    /**
     * Auditní historie zápisu (audit 2026-07, nález „Historie účetního zápisu v UI"):
     * VŠECHNY historické i aktuální verze hlavičky a řádků přes `FOR SYSTEM_TIME ALL`
     * (migrace 1029). Vrací NULL, pokud zápis (ani historicky) neexistuje pro tenanta —
     * supplier_id se u zápisu nikdy neupravuje, takže filtr je tenant-bezpečný i nad
     * historickými řádky.
     *
     * @return array{headers:list<array<string,mixed>>, lines:list<array<string,mixed>>}|null
     */
    public function history(int $id, int $supplierId): ?array
    {
        $pdo = $this->db->pdo();

        $stmt = $pdo->prepare(
            'SELECT je.id, je.period_id, je.entry_date, je.document_date, je.document_no,
                    je.description, je.source_type, je.source_id, je.posted_at, je.posted_by,
                    u.name AS posted_by_name, je.reversed_by, je.row_version,
                    ROW_START AS valid_from, ROW_END AS valid_to
               FROM journal_entries FOR SYSTEM_TIME ALL je
          LEFT JOIN users u ON u.id = je.posted_by
              WHERE je.id = ? AND je.supplier_id = ?
              ORDER BY ROW_START ASC'
        );
        $stmt->execute([$id, $supplierId]);
        $headers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($headers === []) {
            return null;
        }

        $lineStmt = $pdo->prepare(
            'SELECT jel.id, jel.account_id, jel.side, jel.amount, jel.is_red_storno, jel.cost_center, jel.line_no,
                    jel.pair_no, c.account_code, c.name AS account_name,
                    ROW_START AS valid_from, ROW_END AS valid_to
               FROM journal_entry_lines FOR SYSTEM_TIME ALL jel
               JOIN chart_of_accounts c ON c.id = jel.account_id
              WHERE jel.entry_id = ? AND jel.supplier_id = ?
              ORDER BY ROW_START ASC, jel.line_no ASC, jel.id ASC'
        );
        $lineStmt->execute([$id, $supplierId]);
        $lines = $lineStmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'headers' => array_map(static function (array $r): array {
                $r['id'] = (int) $r['id'];
                $r['period_id'] = (int) $r['period_id'];
                $r['source_id'] = $r['source_id'] === null ? null : (int) $r['source_id'];
                $r['posted_by'] = $r['posted_by'] === null ? null : (int) $r['posted_by'];
                $r['reversed_by'] = $r['reversed_by'] === null ? null : (int) $r['reversed_by'];
                $r['row_version'] = (int) $r['row_version'];
                return $r;
            }, $headers),
            'lines' => array_map(static function (array $r): array {
                $r['id'] = (int) $r['id'];
                $r['account_id'] = (int) $r['account_id'];
                $r['amount'] = (float) $r['amount'];
                $r['is_red_storno'] = (bool) $r['is_red_storno'];
                $r['line_no'] = (int) $r['line_no'];
                $r['pair_no'] = $r['pair_no'] === null ? null : (int) $r['pair_no'];
                return $r;
            }, $lines),
        ];
    }

    /** Je aktivní filtr na rozsah účtu (account_from/account_to)? SSOT pro buildWhere() i paginate(). */
    private static function hasAccountRangeFilter(array $filters): bool
    {
        // Kód „0“ (třída 0) je platná mez, proto porovnání s prázdným řetězcem, ne empty().
        return (string) ($filters['account_from'] ?? '') !== '' || (string) ($filters['account_to'] ?? '') !== '';
    }

    /**
     * Meze rozsahu účtu z filtru pro `BETWEEN ? AND ?`. Rozsah je prefixový včetně krajů
     * (5 až 5 = třída 5, 501 až 518 včetně 518.100), pravidlo drží
     * AccountCodeRange. SSOT pro EXISTS filtr v buildWhere() i pro FILTERED_NET_AMOUNT_SUBQUERY
     * v paginate() (obojí musí vidět STEJNÝ rozsah, jinak by se řádek objevil v seznamu,
     * ale sloupec Částka by počítal jiný rozsah účtů, než podle kterého se filtrovalo).
     *
     * @return array{0:string, 1:string}
     */
    private static function accountRangeBounds(array $filters): array
    {
        // Vstup od uživatele už ověřil JournalAction (parse); kód z hledání účtu přichází
        // z databáze a nemusí abecedou projít, proto se tu jen sestaví meze.
        return AccountCodeRange::sqlBoundsFor((string) ($filters['account_from'] ?? ''), (string) ($filters['account_to'] ?? ''));
    }

    /**
     * @param array{document_no?:string, period_id?:int, date_from?:string, date_to?:string, source_type?:string, source_id?:int, entry_id?:int, entry_ids?:list<int>, posted?:bool, automation?:string, q?:string, account_from?:string, account_to?:string, amount_from?:float, amount_to?:float, dimension?:DimensionFilter} $filters
     * @return array{0:string, 1:list<mixed>}
     */
    private function buildWhere(int $supplierId, array $filters): array
    {
        $where = ['je.supplier_id = ?'];
        $params = [$supplierId];
        if (!empty($filters['document_no'])) {
            $needle = '%' . self::escapeLike((string) $filters['document_no']) . '%';
            $where[] = "(je.document_no LIKE ? ESCAPE '='
                OR (je.source_type = 'invoice' AND EXISTS(
                    SELECT 1 FROM invoices i
                     WHERE i.id = je.source_id AND i.supplier_id = je.supplier_id
                       AND i.varsymbol LIKE ? ESCAPE '='
                ))
                OR (je.source_type = 'purchase_invoice' AND EXISTS(
                    SELECT 1 FROM purchase_invoices pi
                     WHERE pi.id = je.source_id AND pi.supplier_id = je.supplier_id
                       AND (pi.vendor_invoice_number LIKE ? ESCAPE '=' OR pi.varsymbol LIKE ? ESCAPE '=')
                ))
                OR " . self::BANK_REF_MATCH . ")";
            array_push($params, $needle, $needle, $needle, $needle, $needle);
        }
        // Fulltext `q` — jedno vyhledávací pole napříč description + čísly dokladů
        // (Featura D, audit 2026-07 follow-up). ORuje se přes stejné zdroje jako
        // document_no výše, jen navíc description.
        if (!empty($filters['q'])) {
            $needle = '%' . self::escapeLike((string) $filters['q']) . '%';
            $where[] = "(je.description LIKE ? ESCAPE '='
                OR je.document_no LIKE ? ESCAPE '='
                OR (je.source_type = 'invoice' AND EXISTS(
                    SELECT 1 FROM invoices i
                     WHERE i.id = je.source_id AND i.supplier_id = je.supplier_id
                       AND i.varsymbol LIKE ? ESCAPE '='
                ))
                OR (je.source_type = 'purchase_invoice' AND EXISTS(
                    SELECT 1 FROM purchase_invoices pi
                     WHERE pi.id = je.source_id AND pi.supplier_id = je.supplier_id
                       AND (pi.vendor_invoice_number LIKE ? ESCAPE '=' OR pi.varsymbol LIKE ? ESCAPE '=')
                ))
                OR " . self::BANK_REF_MATCH . ")";
            array_push($params, $needle, $needle, $needle, $needle, $needle, $needle);
        }
        // Rozsah účtů (Featura D) — EXISTS na journal_entry_lines, indexováno přes
        // idx_jel_supplier_account. Chybějící mez se doplní neutrální hranicí.
        if (self::hasAccountRangeFilter($filters)) {
            $where[] = "EXISTS (
                SELECT 1 FROM journal_entry_lines jel
                JOIN chart_of_accounts c ON c.id = jel.account_id
                 WHERE jel.entry_id = je.id AND jel.supplier_id = je.supplier_id
                   AND c.account_code BETWEEN ? AND ?
            )";
            [$from, $to] = self::accountRangeBounds($filters);
            $params[] = $from;
            $params[] = $to;
        }
        // Rozsah částky (Featura D) — Σ MD zápisu (zrcadlí AMOUNT_SUBQUERY výše).
        if (isset($filters['amount_from']) || isset($filters['amount_to'])) {
            $having = match (true) {
                isset($filters['amount_from'], $filters['amount_to']) => 'SUM(jel.signed_amount) BETWEEN ? AND ?',
                isset($filters['amount_from']) => 'SUM(jel.signed_amount) >= ?',
                default => 'SUM(jel.signed_amount) <= ?',
            };
            $where[] = "EXISTS (
                SELECT 1 FROM journal_entry_lines jel
                 WHERE jel.entry_id = je.id AND jel.side = 'debit'
                 GROUP BY jel.entry_id
                HAVING {$having}
            )";
            if (isset($filters['amount_from'])) {
                $params[] = (float) $filters['amount_from'];
            }
            if (isset($filters['amount_to'])) {
                $params[] = (float) $filters['amount_to'];
            }
        }
        // Hodnota dimenze (včetně podřízených): zápis projde, nese-li ji aspoň jeden
        // jeho řádek. Sémantiku řádku sdílí se sestavami přes DimensionFilter.
        if (($filters['dimension'] ?? null) instanceof DimensionFilter) {
            [$dimSql, $dimParams] = $filters['dimension']->entrySql('je', $supplierId);
            $where[] = $dimSql;
            array_push($params, ...$dimParams);
        }
        if (!empty($filters['period_id'])) {
            $where[] = 'je.period_id = ?';
            $params[] = (int) $filters['period_id'];
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'je.entry_date >= ?';
            $params[] = (string) $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'je.entry_date <= ?';
            $params[] = (string) $filters['date_to'];
        }
        if (!empty($filters['source_type'])) {
            $where[] = 'je.source_type = ?';
            $params[] = (string) $filters['source_type'];
        }
        if (!empty($filters['source_id'])) {
            $where[] = 'je.source_id = ?';
            $params[] = (int) $filters['source_id'];
        }
        // Přesný odskok na jeden zápis (deep-link ?entry_id=). Dřív se místo filtru
        // jen zúžilo datum na entry_date, takže se vedle hledaného zápisu vypsal celý
        // ten den — u nálezu integrity deníku to mate, protože nesouvisející zápisy
        // vypadají jako součást nálezu.
        if (!empty($filters['entry_id'])) {
            $where[] = 'je.id = ?';
            $params[] = (int) $filters['entry_id'];
        }
        // Výčet konkrétních zápisů (filtr „jen nálezy kontroly integrity" —
        // JournalAction::parseFilters si seznam vytáhne z JournalIntegrityService).
        // PRÁZDNÝ seznam znamená „žádné nálezy", ne „bez filtru" — proto isset(),
        // ne !empty(), a nemožná podmínka místo vynechání filtru.
        if (isset($filters['entry_ids'])) {
            $ids = array_values(array_unique(array_map('intval', (array) $filters['entry_ids'])));
            if ($ids === []) {
                $where[] = '1 = 0';
            } else {
                $where[] = 'je.id IN (' . implode(',', $ids) . ')';
            }
        }
        if (array_key_exists('posted', $filters) && $filters['posted'] !== null) {
            $where[] = $filters['posted'] ? 'je.posted_at IS NOT NULL' : 'je.posted_at IS NULL';
        }
        if (!empty($filters['reversal'])) {
            $isReversal = "EXISTS (
                SELECT 1 FROM journal_entries rev
                 WHERE rev.supplier_id = je.supplier_id AND rev.reversed_by = je.id
            )";
            $where[] = match ((string) $filters['reversal']) {
                'reversed' => 'je.reversed_by IS NOT NULL',
                'reversal' => $isReversal,
                'any'      => "(je.reversed_by IS NOT NULL OR {$isReversal})",
                default    => "(je.reversed_by IS NULL AND NOT {$isReversal})",
            };
        }
        // Posouzení zápisu bez dvojic (jednoduchý zápis). Podmínka vzniká jen s filtrem,
        // dotaz firmy bez něj zůstává beze změny; jde po idx_jeps_status.
        if (in_array($filters['pair_status'] ?? null, ['composite_mn', 'ineligible'], true)) {
            $where[] = 'je.id IN (SELECT jeps.entry_id FROM journal_entry_pair_status jeps
                                   WHERE jeps.supplier_id = ? AND jeps.status = ?)';
            array_push($params, $supplierId, (string) $filters['pair_status']);
        }
        if (!empty($filters['automation'])) {
            $bankStatus = $filters['automation'] === 'auto' ? 'auto_posted' : 'approved';
            $bankExists = "EXISTS (
                SELECT 1 FROM bank_posting_suggestions aps
                 WHERE aps.supplier_id = je.supplier_id AND aps.journal_entry_id = je.id
                   AND aps.status = '{$bankStatus}'
            )";
            $documentAutoExists = "EXISTS (
                SELECT 1 FROM activity_log aal
                 WHERE aal.supplier_id = je.supplier_id
                   AND aal.action IN ('accounting.auto_posted', 'bank_match.auto_posted')
                   AND CAST(JSON_UNQUOTE(JSON_EXTRACT(aal.payload, '$.journal_entry_id')) AS UNSIGNED) = je.id
            )";
            if ($filters['automation'] === 'auto') {
                $where[] = "({$bankExists} OR {$documentAutoExists})";
            } elseif ($filters['automation'] === 'approved') {
                $where[] = $bankExists;
            } else {
                $where[] = "NOT EXISTS (
                    SELECT 1 FROM bank_posting_suggestions aps
                     WHERE aps.supplier_id = je.supplier_id AND aps.journal_entry_id = je.id
                       AND aps.status IN ('auto_posted', 'approved')
                ) AND NOT {$documentAutoExists}";
            }
        }
        return [implode(' AND ', $where), $params];
    }

    private static function escapeLike(string $value): string
    {
        return strtr($value, ['=' => '==', '%' => '=%', '_' => '=_']);
    }

    /**
     * `pair_no` / `line_text` řádku: u řádku dvojice jako int / text, u složeného zápisu
     * se klíče vynechají, takže firma bez jednoduchého zápisu dostává řádky ve stejném
     * tvaru jako dřív.
     *
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    private static function castPairColumns(array $r): array
    {
        if (($r['pair_no'] ?? null) === null) {
            unset($r['pair_no']);
        } else {
            $r['pair_no'] = (int) $r['pair_no'];
        }
        if (($r['line_text'] ?? null) === null) {
            unset($r['line_text']);
        }
        return $r;
    }

    /** Text řádku k zápisu: prázdný text = NULL, delší oříznout na šířku sloupce. */
    private static function lineText(array $line): ?string
    {
        $text = isset($line['line_text']) ? trim((string) $line['line_text']) : '';
        return $text === '' ? null : mb_substr($text, 0, 255);
    }

    private function cast(array $r): array
    {
        $r['id'] = (int) $r['id'];
        $r['supplier_id'] = (int) $r['supplier_id'];
        $r['period_id'] = (int) $r['period_id'];
        $r['source_id'] = $r['source_id'] === null ? null : (int) $r['source_id'];
        $r['posted_by'] = $r['posted_by'] === null ? null : (int) $r['posted_by'];
        $r['reversed_by'] = $r['reversed_by'] === null ? null : (int) $r['reversed_by'];
        if (array_key_exists('reverses_entry_id', $r)) {
            $r['reverses_entry_id'] = $r['reverses_entry_id'] === null ? null : (int) $r['reverses_entry_id'];
        }
        $r['row_version'] = (int) $r['row_version'];
        return $r;
    }

    /**
     * {@see cast()} + list-only obohacení (Σ MD zápisu nebo — při filtru na účet —
     * čistá částka a strana toho účtu, drill-down na banku/pokladnu/majetek).
     *
     * @param bool $accountFiltered true, pokud SQL vybíralo FILTERED_NET_AMOUNT_SUBQUERY
     *             (podepsané MD−D na filtrovaném rozsahu účtů) místo AMOUNT_SUBQUERY.
     */
    private function castListRow(array $r, bool $accountFiltered = false): array
    {
        $r = $this->cast($r);
        if ($accountFiltered) {
            $net = (float) $r['amount'];
            $r['amount'] = round(abs($net), 2);
            // Nula (např. účet je v zápisu debetní i kreditní ve stejné výši) se bere
            // jako MD — neutrální volba, aby strana nikdy nechyběla.
            $r['amount_side'] = $net < 0 ? 'credit' : 'debit';
        } else {
            $r['amount'] = (float) $r['amount'];
            $r['amount_side'] = null;
        }
        $r['source_statement_id'] = $r['source_statement_id'] === null ? null : (int) $r['source_statement_id'];
        $r['reverses_entry_id'] = ($r['reverses_entry_id'] ?? null) === null ? null : (int) $r['reverses_entry_id'];
        $r['source_link_id'] = ($r['source_link_id'] ?? null) === null ? null : (int) $r['source_link_id'];
        $r['source_register_id'] = $r['source_register_id'] === null ? null : (int) $r['source_register_id'];
        $r['source_asset_id'] = $r['source_asset_id'] === null ? null : (int) $r['source_asset_id'];
        $r['source_accrual_item_id'] = ($r['source_accrual_item_id'] ?? null) === null ? null : (int) $r['source_accrual_item_id'];
        // Zápočet ukazuje na fakturu, kterou vyrovnal (doc_type + doc_id).
        $r['source_settlement_doc_type'] = $r['source_settlement_doc_type'] !== null
            ? (string) $r['source_settlement_doc_type'] : null;
        $r['source_settlement_doc_id'] = $r['source_settlement_doc_id'] !== null
            ? (int) $r['source_settlement_doc_id'] : null;
        $r['description_parts'] = JournalDescriptionBuilder::parts(
            $r['description'] ?? null,
            (string) $r['source_type'],
            $r,
        );
        return $r;
    }
}
