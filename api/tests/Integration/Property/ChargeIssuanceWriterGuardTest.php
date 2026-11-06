<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Property;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\DimensionAssignmentRepository;
use MyInvoice\Repository\DimensionRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Repository\Property\Charges\Issuance\IssuanceRepository;
use MyInvoice\Service\Accounting\Dimension\DimensionException;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Property\Charges\Issuance\IssuanceException;
use MyInvoice\Service\Property\Charges\Issuance\IssuancePreviewService;
use MyInvoice\Tests\Support\ChargeIssuanceFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class ChargeIssuanceWriterGuardTest extends TestCase
{
    use ChargeIssuanceFixture;

    private Connection $db;
    private array $fixture;
    private array $charge;

    protected function setUp(): void
    {
        $this->db = Bootstrap::buildContainer()->get(Connection::class);
        self::assertStringEndsWith('_test', (string) $this->db->pdo()->query('SELECT DATABASE()')->fetchColumn());
        $this->db->pdo()->beginTransaction();
        $this->fixture = $this->issuanceFixture($this->db);
        $f = $this->fixture;
        $request = $f['issue_request'];
        $request['units'] = [$request['units'][0]];
        $preview = (new IssuancePreviewService($this->db))->preview($f['supplier'], $request);
        $repository = Bootstrap::buildContainer()->get(IssuanceRepository::class);
        $batch = $repository->issue($f['supplier'], ['request' => $request, 'preview_hash' => $preview['preview_hash'], 'idempotency_key' => 'writer-guard'], $f['actor']);
        $posted = $repository->post($f['supplier'], $batch['id'], [['id' => $batch['charges'][0]['id'], 'row_version' => 1]], $f['actor']);
        $this->charge = $posted['charges'][0];
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
    }

    public static function mutations(): array
    {
        return [['detach', false], ['mark', false], ['reverse', false], ['axis', false], ['detach', true], ['reverse', true], ['axis', true],
            ['replace', false], ['partial', false], ['link', false], ['journal_reverse', false], ['cash_delete', false], ['split', false]];
    }

    #[DataProvider('mutations')]
    public function testGenericMutationCannotChangeOwnedOriginalOrMirror(string $operation, bool $mirror): void
    {
        $f = $this->fixture;
        $entryId = $this->charge['journal_entry_id'];
        if ($mirror) {
            $reversed = Bootstrap::buildContainer()->get(IssuanceRepository::class)->reverse($f['supplier'], $this->charge['id'], [
                'row_version' => 2, 'accounting_date' => '2026-02-01', 'effective_at' => '2026-02-01T10:00:00+01:00',
                'reason' => 'Synthetic owner reversal', 'evidence_ref' => 'Synthetic deed', 'idempotency_key' => 'owner-reverse',
            ], $f['actor']);
            $entryId = $reversed['reversal_entry_id'];
        }
        $journal = Bootstrap::buildContainer()->get(JournalEntryRepository::class);
        $before = $journal->findForUpdate($entryId, $f['supplier']);
        $rejected = false;
        try {
            if ($operation === 'detach') $journal->detachSource($entryId, $f['supplier']);
            elseif ($operation === 'mark') $journal->markPosted($entryId, $f['supplier'], $f['actor']);
            elseif ($operation === 'reverse') Bootstrap::buildContainer()->get(PostingService::class)->reverse($f['supplier'], $entryId, ['entry_date' => '2026-03-01']);
            elseif ($operation === 'replace') $journal->replace($entryId, $before, $before['lines']);
            elseif ($operation === 'partial') $journal->replaceSomeLines($entryId, $f['supplier'], (int) $before['row_version'], [], []);
            elseif ($operation === 'link') $journal->setReversedBy($entryId, $f['supplier'], $entryId);
            elseif ($operation === 'journal_reverse') $journal->insertReversal($before, $before['lines'], $entryId);
            elseif ($operation === 'cash_delete') Bootstrap::buildContainer()->get(\MyInvoice\Repository\CashDocumentRepository::class)->deleteJournalEntry($f['supplier'], $entryId);
            else {
                $dimensions = Bootstrap::buildContainer()->get(DimensionRepository::class);
                $type = $dimensions->createType($f['supplier'], false, ['code' => 'new_axis', 'name' => 'Synthetic axis', 'kind' => 'custom']);
                $value = $dimensions->createValue(['id' => $type, 'supplier_id' => $f['supplier'], 'supplier_group_id' => null, 'system_key' => null], ['code' => 'V', 'name' => 'Synthetic value']);
                $assignments = new DimensionAssignmentRepository($this->db);
                if ($operation === 'split') $assignments->insertLineSplits($f['supplier'], (int) $before['lines'][0]['id'], [$type => [$value => '1.000000']]);
                else $assignments->insertLineDimensions($f['supplier'], (int) $before['lines'][0]['id'], [$type => $value]);
            }
        } catch (PostingException|DimensionException $e) {
            self::assertSame('property_charge_issue_source_immutable', $e->errorCode);
            self::assertSame(409, $e->httpStatus);
            $rejected = true;
        }
        self::assertTrue($rejected, 'Generic ' . $operation . ' accepted an issued ledger source.');
        self::assertSame($before, $journal->findForUpdate($entryId, $f['supplier']));
    }

    public static function deletionEndpoints(): array { return [['delete'], ['deleteReversalPair']]; }

    #[DataProvider('deletionEndpoints')]
    public function testDeleteEndpointsReturnDomain409WithoutChangingOwnedLedger(string $method): void
    {
        $f = $this->fixture;
        $entry = $this->charge['journal_entry_id'];
        if ($method === 'deleteReversalPair') {
            $result = Bootstrap::buildContainer()->get(IssuanceRepository::class)->reverse($f['supplier'], $this->charge['id'], $this->reverseInput(), $f['actor']);
            $entry = $result['reversal_entry_id'];
        }
        $before = $this->state();
        $request = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('DELETE', '/api/accounting/journal')
            ->withAttribute(\MyInvoice\Middleware\SupplierScopeMiddleware::ATTR_CURRENT_ID, $f['supplier'])
            ->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_USER, ['id' => $f['actor'], 'role' => 'admin']);
        $response = Bootstrap::buildContainer()->get(\MyInvoice\Action\Accounting\JournalAction::class)->$method($request, new \Slim\Psr7\Response(), ['id' => $entry]);
        self::assertSame(409, $response->getStatusCode());
        self::assertStringContainsString('property_charge_issue_source_immutable', (string) $response->getBody());
        self::assertSame($before, $this->state());
    }

    public function testMissingTenantCashDeleteKeepsItsExistingDomain404(): void
    {
        try {
            Bootstrap::buildContainer()->get(\MyInvoice\Service\Accounting\Cash\CashDocumentService::class)->deleteDocument($this->fixture['supplier'] + 10000000, 2147483647);
            self::fail('Missing cash document accepted.');
        } catch (\MyInvoice\Service\Accounting\Cash\CashException $e) {
            self::assertSame('validation', $e->errorCode);
            self::assertSame(404, $e->httpStatus);
        }
    }

    public function testUnownedEntryCannotLinkAnIssuedEntryAsItsMirror(): void
    {
        $f = $this->fixture;
        $journal = Bootstrap::buildContainer()->get(JournalEntryRepository::class);
        $owned = $journal->findForUpdate($this->charge['journal_entry_id'], $f['supplier']);
        $account = (int) $owned['lines'][0]['account_id'];
        $entry = $journal->insert(['supplier_id' => $f['supplier'], 'period_id' => $owned['period_id'], 'entry_date' => '2026-03-01', 'source_type' => 'manual', 'posted_at' => '2026-03-01 12:00:00'], [
            ['account_id' => $account, 'side' => 'debit', 'amount' => '0.01'],
            ['account_id' => $account, 'side' => 'credit', 'amount' => '0.01'],
        ]);
        $before = $this->state();
        try {
            $journal->setReversedBy($entry, $f['supplier'], $this->charge['journal_entry_id']);
            self::fail('An issued entry became a generic reversal target.');
        } catch (PostingException $e) {
            self::assertSame('property_charge_issue_source_immutable', $e->errorCode);
        }
        self::assertSame($before, $this->state());
    }

    public function testTypedWriterRejectsMissingStoredIntentBeforeAnyMirror(): void
    {
        $journal = Bootstrap::buildContainer()->get(JournalEntryRepository::class);
        $before = $journal->findForUpdate($this->charge['journal_entry_id'], $this->fixture['supplier']);
        try {
            $journal->insertPropertyChargeReversal($this->fixture['supplier'], 2147483647);
            self::fail('Missing stored intent accepted.');
        } catch (IssuanceException $e) {
            self::assertSame('property_charge_issue_invalid_reversal_intent', $e->errorCode);
        }
        self::assertSame($before, $journal->findForUpdate($this->charge['journal_entry_id'], $this->fixture['supplier']));
    }

    public function testLateAuditFailureRollsBackMirrorChargeAndIntentInsideCallerTransaction(): void
    {
        $f = $this->fixture;
        $logger = new class($this->db) extends \MyInvoice\Service\ActivityLogger {
            public function log(string $action, ?int $userId = null, ?string $entityType = null, ?int $entityId = null, ?array $payload = null, ?string $ip = null, ?string $userAgent = null, ?int $supplierId = null): void
            {
                if ($action === 'accounting.reversed') throw new \RuntimeException('Synthetic late audit failure');
                parent::log($action, $userId, $entityType, $entityId, $payload, $ip, $userAgent, $supplierId);
            }
        };
        $writer = new IssuanceRepository($this->db, Bootstrap::buildContainer()->get(PostingService::class), new JournalEntryRepository($this->db, $logger));
        $before = $this->state();
        try {
            $writer->reverse($f['supplier'], $this->charge['id'], $this->reverseInput(), $f['actor']);
            self::fail('Late audit error ignored.');
        } catch (\RuntimeException $e) {
            self::assertSame('Synthetic late audit failure', $e->getMessage());
        }
        self::assertTrue($this->db->pdo()->inTransaction());
        self::assertSame($before, $this->state());
        $result = Bootstrap::buildContainer()->get(IssuanceRepository::class)->reverse($f['supplier'], $this->charge['id'], $this->reverseInput(), $f['actor']);
        self::assertSame('reversed', $result['status']);
        self::assertSame($result, Bootstrap::buildContainer()->get(IssuanceRepository::class)->reverse($f['supplier'], $this->charge['id'], $this->reverseInput(), $f['actor']));
    }

    public function testExactAssignmentRetryPreservesPhysicalEvidence(): void
    {
        $f = $this->fixture;
        $repository = new DimensionAssignmentRepository($this->db);
        $entry = Bootstrap::buildContainer()->get(JournalEntryRepository::class)->findForUpdate($this->charge['journal_entry_id'], $f['supplier']);
        $line = (int) $entry['lines'][0]['id'];
        $dims = $repository->lineDimensions($f['supplier'], [$line], true)[$line];
        $before = $this->state();
        self::assertFalse($repository->replaceLineDimensions($f['supplier'], $line, $dims));
        self::assertFalse($repository->replaceLineSplits($f['supplier'], $line, []));
        self::assertSame($before, $this->state());
    }

    public function testDirectTypedWriterLateFailureKeepsPendingIntentAndCallerTransaction(): void
    {
        $intent = $this->pendingIntent();
        $logger = new class($this->db) extends \MyInvoice\Service\ActivityLogger {
            public function log(string $action, ?int $userId = null, ?string $entityType = null, ?int $entityId = null, ?array $payload = null, ?string $ip = null, ?string $userAgent = null, ?int $supplierId = null): void
            {
                if ($action === 'accounting.reversed') throw new \RuntimeException('Synthetic direct writer failure');
                parent::log($action, $userId, $entityType, $entityId, $payload, $ip, $userAgent, $supplierId);
            }
        };
        $before = $this->state();
        try {
            (new JournalEntryRepository($this->db, $logger))->insertPropertyChargeReversal($this->fixture['supplier'], $intent);
            self::fail('Direct writer ignored audit failure.');
        } catch (\RuntimeException $e) {
            self::assertSame('Synthetic direct writer failure', $e->getMessage());
        }
        self::assertTrue($this->db->pdo()->inTransaction());
        self::assertSame($before, $this->state());
        self::assertGreaterThan(0, Bootstrap::buildContainer()->get(JournalEntryRepository::class)->insertPropertyChargeReversal($this->fixture['supplier'], $intent));
    }

    public function testDirectTypedWriterCompletesOwnerCasAndAuditAtomically(): void
    {
        $f = $this->fixture;
        $intent = $this->pendingIntent();
        $journal = Bootstrap::buildContainer()->get(JournalEntryRepository::class);
        $id = $journal->insertPropertyChargeReversal($f['supplier'], $intent);
        $reader = new \MyInvoice\Repository\Property\Charges\Issuance\IssuanceReadRepository($this->db);
        $charge = $reader->find($f['supplier'], $this->charge['id'], true);
        self::assertSame('reversed', $charge['status']);
        self::assertSame(3, $charge['row_version']);
        self::assertSame($id, $charge['reversal_entry_id']);
        self::assertSame($id, (int) $journal->findForUpdate($this->charge['journal_entry_id'], $f['supplier'])['reversed_by']);
        $q = $this->db->pdo()->prepare("SELECT payload_json FROM unit_charge_issue_audit WHERE supplier_id=? AND charge_id=? AND action='reversed'");
        $q->execute([$f['supplier'], $this->charge['id']]);
        $audits = $q->fetchAll(\PDO::FETCH_COLUMN);
        self::assertCount(1, $audits);
        self::assertSame($intent, json_decode($audits[0], true, flags: JSON_THROW_ON_ERROR)['adjustment_id']);
        $before = $this->state();
        try {
            $journal->insertPropertyChargeReversal($f['supplier'], $intent);
            self::fail('Completed intent created another mirror.');
        } catch (IssuanceException $e) {
            self::assertSame('property_charge_issue_invalid_reversal_intent', $e->errorCode);
        }
        self::assertSame($before, $this->state());
    }

    public static function badIntents(): array { return [['hash'], ['version'], ['source']]; }

    #[DataProvider('badIntents')]
    public function testTypedWriterReprovesStoredRequestAndActualSource(string $damage): void
    {
        $intent = $this->pendingIntent($damage);
        if ($damage === 'source') {
            $q = $this->db->pdo()->prepare('UPDATE journal_entry_lines SET amount=amount+0.01 WHERE supplier_id=? AND entry_id=?');
            $q->execute([$this->fixture['supplier'], $this->charge['journal_entry_id']]);
        }
        $before = $this->state();
        $rejected = false;
        try {
            Bootstrap::buildContainer()->get(JournalEntryRepository::class)->insertPropertyChargeReversal($this->fixture['supplier'], $intent);
        } catch (IssuanceException $e) {
            self::assertContains($e->errorCode, ['property_charge_issue_history_corrupt', 'property_charge_issue_invalid_reversal_intent', 'property_charge_issue_ledger_mismatch']);
            $rejected = true;
        }
        self::assertTrue($rejected, 'Typed writer trusted a corrupt ' . $damage . ' receipt.');
        self::assertTrue($this->db->pdo()->inTransaction());
        self::assertSame($before, $this->state());
    }

    private function pendingIntent(?string $damage = null): int
    {
        $f = $this->fixture;
        $request = $this->reverseInput();
        $token = $request['idempotency_key']; unset($request['idempotency_key']);
        $request['effective_at'] = \MyInvoice\Service\Property\PropertyInstant::normalize($request['effective_at']);
        if ($damage === 'version') $request['row_version'] = 1;
        $hash = \MyInvoice\Service\Property\PropertyInput::hash(['charge_id' => $this->charge['id'], 'request' => $request, 'idempotency_key' => $token, 'actor' => $f['actor']]);
        if ($damage === 'hash') $hash = str_repeat('0', 64);
        $q = $this->db->pdo()->prepare("INSERT INTO unit_charge_adjustments (supplier_id,charge_id,kind,effective_at,request_json,request_hash,idempotency_key,created_by,recorded_at) VALUES (?,?,'reversal',?,?,?,?,?,UTC_TIMESTAMP(6))");
        $q->execute([$f['supplier'], $this->charge['id'], \MyInvoice\Service\Property\PropertyInstant::sql($request['effective_at']),
            \MyInvoice\Service\Property\Charges\Issuance\IssuanceRequest::json($request), $hash, $token, $f['actor']]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function reverseInput(): array
    {
        return ['row_version' => 2, 'accounting_date' => '2026-02-01', 'effective_at' => '2026-02-01T10:00:00+01:00',
            'reason' => 'Synthetic owner correction', 'evidence_ref' => 'Synthetic deed', 'idempotency_key' => 'atomic-reverse'];
    }

    private function state(): array
    {
        $out = [];
        foreach (['journal_entries', 'journal_entry_lines', 'journal_entry_line_dimensions', 'journal_entry_line_dimension_splits', 'unit_charges', 'unit_charge_adjustments', 'unit_charge_issue_audit'] as $table) {
            $query = $this->db->pdo()->prepare('SELECT * FROM ' . $table . ' WHERE supplier_id=?');
            $query->execute([$this->fixture['supplier']]);
            $out[$table] = $query->fetchAll(\PDO::FETCH_ASSOC);
        }
        return $out;
    }
}
