<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting;

use MyInvoice\Service\Accounting\Closing\{AssetReserveSourceDecision, ClosingException};
use MyInvoice\Service\Anonymization\{AssetReserveSourceCloneScrubber, Pseudonymizer, TextScrubber};
use PHPUnit\Framework\TestCase;

final class AssetReserveSourceCloneTest extends TestCase
{
    public function testActualAnonymizationRowPipelineKeepsReceiptValid(): void
    {
        $receipt = $this->receipt();
        $record = ['asset_source_evidence' => $receipt, 'legal_decision' => ['reserve_source' => ['source_hash' => $receipt['source_hash']]]];
        $payload = json_encode(['entries' => [$record]], JSON_THROW_ON_ERROR);
        $service = (new \ReflectionClass(\MyInvoice\Service\Anonymization\AnonymizationService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($service, 'rowUpdates');
        $updates = $method->invoke($service, new TextScrubber(new Pseudonymizer('synthetic-clone-key-12345')), 'accounting_closing_steps',
            ['payload' => 'json'], ['payload' => $payload], ['payload' => ['json' => true]]);
        $copy = json_decode($updates['payload'], true, flags: JSON_THROW_ON_ERROR);
        AssetReserveSourceDecision::verify($copy['entries'][0]['asset_source_evidence']);
        self::assertSame($copy['entries'][0]['asset_source_evidence']['source_hash'], $copy['entries'][0]['legal_decision']['reserve_source']['source_hash']);
        self::assertSame($record, json_decode($payload, true, flags: JSON_THROW_ON_ERROR)['entries'][0]);
    }

    public function testCloneVerifiesOriginalHashAndRebindsAllReferencesWithoutChangingFinancialFacts(): void
    {
        $receipt = $this->receipt();
        $body = ['amount' => '999999999.99', 'legal_decision' => ['legal_basis' => '593/1992 §7', 'reserve_source' => ['source_hash' => $receipt['source_hash'],
            'association' => $receipt['association']]]];
        $payload = ['entries' => [$body + ['asset_source_evidence' => $receipt]], 'legal_pending' => ['body' => $body]];
        $original = json_encode($payload, JSON_THROW_ON_ERROR);
        $scrubber = new TextScrubber(new Pseudonymizer('synthetic-clone-key-12345'));
        $copy = json_decode(AssetReserveSourceCloneScrubber::payload($original, $scrubber), true, flags: JSON_THROW_ON_ERROR);
        $transformed = $copy['entries'][0]['asset_source_evidence'];
        AssetReserveSourceDecision::verify($transformed);
        self::assertNotSame($receipt['source_hash'], $transformed['source_hash']);
        self::assertSame($transformed['source_hash'], $copy['legal_pending']['body']['legal_decision']['reserve_source']['source_hash']);
        self::assertSame($scrubber->scrub('synthetic@example.com'), $transformed['association']['evidence']);
        self::assertSame('999999999.99', $copy['entries'][0]['amount']);
        self::assertSame('593/1992 §7', $copy['entries'][0]['legal_decision']['legal_basis']);
        self::assertSame($receipt['contributions'], $transformed['contributions']);
        self::assertSame($payload, json_decode($original, true, flags: JSON_THROW_ON_ERROR));
    }

    public function testCorruptOriginalCannotBeRehashedIntoValidClone(): void
    {
        $receipt = $this->receipt();
        $receipt['gross_cents']++;
        $this->expectException(ClosingException::class);
        AssetReserveSourceCloneScrubber::payload(json_encode(['entries' => [['asset_source_evidence' => $receipt, 'legal_decision' => ['reserve_source' => ['source_hash' => $receipt['source_hash']]]]]], JSON_THROW_ON_ERROR), new TextScrubber(new Pseudonymizer('synthetic-clone-key-12345')));
    }

    public function testForeignPendingHashCannotBeSilentlyRetained(): void
    {
        $receipt = $this->receipt();
        $this->expectException(ClosingException::class);
        AssetReserveSourceCloneScrubber::payload(json_encode(['entries' => [['asset_source_evidence' => $receipt, 'legal_decision' => ['reserve_source' => ['source_hash' => $receipt['source_hash']]]]], 'legal_pending' => ['body' => ['legal_decision' => ['reserve_source' => ['source_hash' => str_repeat('a', 64)]]]]], JSON_THROW_ON_ERROR), new TextScrubber(new Pseudonymizer('synthetic-clone-key-12345')));
    }

    public function testLegacyPayloadUsesOriginalScrubberUnchanged(): void
    {
        $json = '{"entries":[{"description":"synthetic@example.com","amount":49.99}]}';
        $scrubber = new TextScrubber(new Pseudonymizer('synthetic-clone-key-12345'));
        self::assertSame($scrubber->scrubJson($json), AssetReserveSourceCloneScrubber::payload($json, $scrubber));
    }

    public function testRevisionClonePreservesPreviousReceiptAndRebindsBothHashes(): void
    {
        $before = $this->receipt();
        $after = $before;
        $after['association']['evidence'] = 'corrected@example.com';
        $after = AssetReserveSourceDecision::seal($after);
        $revision = AssetReserveSourceDecision::sealRevision(['version' => 'asset-reserve-revision-v1', 'period_id' => 7,
            'revision_number' => 1, 'previous_hash' => $before['source_hash'], 'previous_source_evidence' => $before,
            'source_evidence' => $after, 'reason' => 'correction@example.com', 'continued_use_evidence' => 'continued@example.com', 'effective_on' => '2097-12-31']);
        $json = json_encode(['asset_source_revisions' => [$revision]], JSON_THROW_ON_ERROR);
        $scrubber = new TextScrubber(new Pseudonymizer('synthetic-clone-key-12345'));
        $result = json_decode(AssetReserveSourceCloneScrubber::payload($json, $scrubber), true, flags: JSON_THROW_ON_ERROR)['asset_source_revisions'][0];
        AssetReserveSourceDecision::verifyRevision($result);
        self::assertNotSame($revision['revision_hash'], $result['revision_hash']);
        self::assertSame($scrubber->scrub('correction@example.com'), $result['reason']);
        self::assertSame($before['gross_cents'], $result['previous_source_evidence']['gross_cents']);
        self::assertSame($result['previous_hash'], $result['previous_source_evidence']['source_hash']);
        self::assertSame($revision, json_decode($json, true, flags: JSON_THROW_ON_ERROR)['asset_source_revisions'][0]);
        $revision['reason'] = 'corrupt';
        $this->expectException(ClosingException::class);
        AssetReserveSourceCloneScrubber::payload(json_encode(['asset_source_revisions' => [$revision]], JSON_THROW_ON_ERROR), $scrubber);
    }

    public function testClosedCorrectionCloneRebindsRevisionPendingAndPinnedJointEvidence(): void
    {
        $revision = $this->closedRevision();
        $before = $revision['previous_source_evidence'];
        $after = $revision['source_evidence'];
        $payload = ['asset_source_revisions' => [$revision], 'asset_correction_pending' => ['body' => [
            'mode' => 'prospective_closed_history', 'previous_hash' => $before['source_hash'],
            'original_purpose' => $revision['original_purpose'], 'reserve_source' => ['source_hash' => $after['source_hash'],
                'association' => $after['association']]]]];
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $scrubber = new TextScrubber(new Pseudonymizer('synthetic-clone-key-12345'));
        $copy = json_decode(AssetReserveSourceCloneScrubber::payload($json, $scrubber), true, flags: JSON_THROW_ON_ERROR);
        $result = $copy['asset_source_revisions'][0];
        AssetReserveSourceDecision::verifyRevision($result);
        self::assertNotSame($revision['revision_hash'], $result['revision_hash']);
        self::assertSame($result['previous_hash'], $copy['asset_correction_pending']['body']['previous_hash']);
        self::assertSame($result['source_evidence']['source_hash'], $copy['asset_correction_pending']['body']['reserve_source']['source_hash']);
        self::assertSame($scrubber->byStrategy('cost_center', 'source@example.com'), $result['pinned_entries'][0]['lines'][0]['cost_center']);
        self::assertSame($result['pinned_entries'][0]['lines'][0]['cost_center'], $result['transfers'][0]['before'][0]['cost_center']);
        self::assertSame('999999999.99', $result['pinned_entries'][0]['lines'][0]['amount']);
        self::assertSame('0.00', $result['pinned_entries'][0]['lines'][0]['amount_foreign']);
        self::assertNull($result['pinned_entries'][0]['lines'][0]['fx_rate']);
        self::assertSame('100.0000', $result['pinned_entries'][0]['lines'][0]['dimension_splits'][1][2]);
        self::assertSame($revision['original_purpose']['document_date'], $result['original_purpose']['document_date']);
        self::assertSame($scrubber->scrub('purpose@example.com'), $result['original_purpose']['evidence']);
        self::assertSame($payload, json_decode($json, true, flags: JSON_THROW_ON_ERROR));
    }

    public function testClosedCorrectionCloneRejectsTamperedOriginalBeforeTransform(): void
    {
        $revision = $this->closedRevision();
        $revision['pinned_entries'][0]['lines'][0]['amount'] = '1.00';
        $this->expectException(ClosingException::class);
        AssetReserveSourceCloneScrubber::payload(json_encode(['asset_source_revisions' => [$revision]], JSON_THROW_ON_ERROR),
            new TextScrubber(new Pseudonymizer('synthetic-clone-key-12345')));
    }

    private function closedRevision(): array
    {
        $before = $this->receipt();
        $before['cells'] = [['dimensions' => [1 => 2], 'cost_center' => 'source@example.com', 'project_id' => null, 'weight_cents' => 10000000]];
        $before = AssetReserveSourceDecision::seal($before);
        $after = $before;
        $after['association']['evidence'] = 'corrected@example.com';
        $after['cells'][0]['dimensions'] = [1 => 3];
        $after['cells'][0]['cost_center'] = 'corrected@example.com';
        $after = AssetReserveSourceDecision::seal($after);
        return AssetReserveSourceDecision::sealRevision(['version' => 'asset-reserve-revision-v2', 'period_id' => 7,
            'revision_number' => 1, 'previous_hash' => $before['source_hash'], 'previous_source_evidence' => $before,
            'source_evidence' => $after, 'reason' => 'correction@example.com', 'continued_use_evidence' => 'continued@example.com',
            'effective_on' => '2098-12-31', 'source_date' => '2097-12-31', 'previous_source_date' => '2097-12-31',
            'original_purpose' => ['same_original_asset_and_purpose' => true, 'document_date' => '2096-12-01', 'reference' => 'SYN-ORIGINAL', 'evidence' => 'purpose@example.com'],
            'transfers' => [['source_entry_id' => 40, 'before' => AssetReserveSourceDecision::allocations($before, 4999), 'after' => AssetReserveSourceDecision::allocations($after, 4999)]],
            'pinned_entries' => [['id' => 40, 'entry_date' => '2097-12-31', 'lines' => [['amount' => '999999999.99', 'amount_foreign' => '0.00', 'fx_rate' => null,
                'cost_center' => 'source@example.com', 'dimensions' => [1 => 2], 'dimension_splits' => [1 => [2 => '100.0000']]]]]],
            'sequence_entry_id' => 50, 'entry_id' => 50]);
    }

    private function receipt(): array
    {
        return AssetReserveSourceDecision::seal(['version' => 'asset-reserve-source-v1', 'supplier_id' => 10, 'asset_id' => 20,
            'gross_cents' => 10000000, 'association' => ['reference' => 'SYN-ASSOCIATION', 'evidence' => 'synthetic@example.com',
                'contributions' => [['line_id' => 30, 'contribution_cents' => 10000000]]],
            'contributions' => [['line_id' => 30, 'amount_cents' => 10000000, 'contribution_cents' => 10000000, 'dimensions' => [1 => 2]]]]);
    }
}
