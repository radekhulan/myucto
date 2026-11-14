<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Nonprofit;

use MyInvoice\Repository\Nonprofit\Membership\MembershipBalanceRepository;
use MyInvoice\Repository\Nonprofit\Membership\MembershipChargeRepository;
use MyInvoice\Repository\Nonprofit\Membership\MembershipListRepository;
use MyInvoice\Repository\Nonprofit\Membership\MembershipRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Nonprofit\Membership\MembershipCorrectionService;
use MyInvoice\Service\Nonprofit\Membership\MembershipException;
use MyInvoice\Service\Nonprofit\Membership\MembershipListFilter;
use MyInvoice\Service\Nonprofit\Membership\MembershipPublicCatalog;
use MyInvoice\Service\Nonprofit\Membership\MembershipRevisionTimeline;
use MyInvoice\Tests\Support\MembershipBatchReviewFixture;
use PDO;

/** Opravitelnost členské evidence do prvního účetního zápisu (#321, krok 7). */
final class MembershipCorrectionTest extends MembershipBatchReviewFixture
{
    private MembershipCorrectionService $corrections;
    private MembershipRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new MembershipRepository($this->db);
        $this->corrections = new MembershipCorrectionService($this->db, $this->repository, new ActivityLogger($this->db));
    }

    public function testCorrectionAuditSurvivesActivityLogRetention(): void
    {
        foreach ([MembershipCorrectionService::AUDIT_MEMBER_UPDATE, MembershipCorrectionService::AUDIT_MEMBER_DELETE, MembershipCorrectionService::AUDIT_REVISION_VOID] as $action) {
            self::assertContains($action, \MyInvoice\Service\ActivityLogHashChain::RETAINED_ACTIONS, 'Jediná stopa opravy nesmí zmizet retencí.');
        }
    }

    public function testMemberWithoutRevisionIsDeletedAndAudited(): void
    {
        [$member, $client] = $this->bareMember('DEL-OK');
        $result = $this->corrections->delete($this->supplier, $member, $this->actor);

        self::assertSame(['deleted' => true, 'member_id' => $member], $result);
        self::assertNull($this->repository->member($this->supplier, $member));
        $audit = $this->audit(MembershipCorrectionService::AUDIT_MEMBER_DELETE, $member);
        self::assertSame(['client_id' => $client, 'member_number' => 'SYN-DEL-OK', 'rejected_batch_ids' => []], $audit);
        // Uvolněný kontakt jde dál smazat (dřív ho držel FK člena).
        $this->pdo->prepare('DELETE FROM clients WHERE supplier_id=? AND id=?')->execute([$this->supplier, $client]);
    }

    public function testMemberWithRevisionOrVoidedRevisionOrChargeCannotBeDeleted(): void
    {
        $withRevision = $this->member('DEL-REV');
        $this->assertCorrectionRejected('nonprofit_membership_member_has_revisions', fn () => $this->corrections->delete($this->supplier, $withRevision, $this->actor));

        $voided = $this->member('DEL-VOID');
        $this->void($voided, $this->lastRevision($voided), 1, 'del-void');
        $this->assertCorrectionRejected('nonprofit_membership_member_has_revisions', fn () => $this->corrections->delete($this->supplier, $voided, $this->actor));

        $charged = $this->member('DEL-CHARGE');
        $this->issue($charged, 'del-charge');
        $this->assertCorrectionRejected('nonprofit_membership_member_has_revisions', fn () => $this->corrections->delete($this->supplier, $charged, $this->actor));
        foreach ([$withRevision, $voided, $charged] as $id) self::assertNotNull($this->repository->member($this->supplier, $id));
        self::assertSame(0, $this->auditCount(MembershipCorrectionService::AUDIT_MEMBER_DELETE));
    }

    public function testOpenBatchBlocksDeletionAndFinishedBatchRejectedRowIsRemoved(): void
    {
        [$bare] = $this->bareMember('DEL-BATCH');
        $other = $this->member('DEL-BATCH-OTHER');
        $created = $this->batches->create($this->supplier, $this->input([$bare, $other], 'del-batch'), $this->actor);
        $batch = $created['batch']['id'];
        self::assertSame('rejected', $this->batchRowStatus($batch, $bare));

        $this->assertCorrectionRejected('nonprofit_membership_member_in_batch', fn () => $this->corrections->delete($this->supplier, $bare, $this->actor));
        $this->batches->cancel($this->supplier, $batch, $this->actor);
        $this->corrections->delete($this->supplier, $bare, $this->actor);

        self::assertNull($this->batchRowStatus($batch, $bare));
        self::assertNotNull($this->batchRowStatus($batch, $other));
        self::assertSame([$batch], $this->audit(MembershipCorrectionService::AUDIT_MEMBER_DELETE, $bare)['rejected_batch_ids']);
    }

    public function testSeedingBatchThatCanStillReachMemberBlocksDeletion(): void
    {
        [$bare] = $this->bareMember('DEL-SEED');
        [$selected] = $this->bareMember('DEL-SEED-SELECTED');
        [$outside] = $this->bareMember('DEL-SEED-OUTSIDE');
        $other = $this->member('DEL-SEED-OTHER');
        $active = $this->batches->create($this->supplier, $this->input([$other], 'del-seed-active'), $this->actor)['batch']['id'];
        $chosen = $this->batches->create($this->supplier, $this->input([$other], 'del-seed-selected'), $this->actor)['batch']['id'];
        // Plnění rozpracované v půli: kurzor před členy, horní mez za nimi.
        $this->pdo->prepare("UPDATE nonprofit_membership_batches SET phase='seeding',selection_mode='active',seed_cursor=0,seed_until=? WHERE supplier_id=? AND id=?")
            ->execute([$selected, $this->supplier, $active]);
        $this->pdo->prepare("UPDATE nonprofit_membership_batches SET phase='seeding',selection_mode='selected',selection_json=?,seed_cursor=0,seed_until=? WHERE supplier_id=? AND id=?")
            ->execute([json_encode([$selected, $outside + 1000]), $outside, $this->supplier, $chosen]);

        $this->assertCorrectionRejected('nonprofit_membership_member_in_batch', fn () => $this->corrections->delete($this->supplier, $bare, $this->actor));
        $this->assertCorrectionRejected('nonprofit_membership_member_in_batch', fn () => $this->corrections->delete($this->supplier, $selected, $this->actor));
        // Mimo horní mez aktivní dávky a mimo výběr druhé dávky smazání projde.
        $this->corrections->delete($this->supplier, $outside, $this->actor);
        self::assertNull($this->repository->member($this->supplier, $outside));
    }

    public function testUnexpectedReferenceIsReportedAsMemberInUse(): void
    {
        [$bare] = $this->bareMember('DEL-IN-USE');
        $other = $this->member('DEL-IN-USE-OTHER');
        $batch = $this->batches->create($this->supplier, $this->input([$other], 'del-in-use'), $this->actor)['batch']['id'];
        $this->batches->cancel($this->supplier, $batch, $this->actor);
        // Neodmítnutá položka bez operace v ukončené dávce: kontroly ji neznají, drží ji jen cizí klíč.
        $this->pdo->prepare("INSERT INTO nonprofit_membership_batch_members(supplier_id,batch_id,member_id,status,amount_cents) VALUES(?,?,?,'pending',50000)")
            ->execute([$this->supplier, $batch, $bare]);
        $this->assertCorrectionRejected('nonprofit_membership_member_in_use', fn () => $this->corrections->delete($this->supplier, $bare, $this->actor));
        self::assertNotNull($this->repository->member($this->supplier, $bare));
    }

    public function testRepeatedIdentityCorrectionAfterUncertainResponseIsANoop(): void
    {
        $member = $this->member('FIX-REPLAY');
        $client = $this->repository->member($this->supplier, $member)['client_id'];
        $body = $this->identity($client, 'SYN-FIX-REPLAY-2', $client, 'SYN-FIX-REPLAY');
        $this->corrections->updateIdentity($this->supplier, $member, $body, $this->actor);
        self::assertSame('SYN-FIX-REPLAY-2', $this->corrections->updateIdentity($this->supplier, $member, $body, $this->actor)['member_number']);
        self::assertSame(1, $this->auditCount(MembershipCorrectionService::AUDIT_MEMBER_UPDATE));
    }

    public function testIdentityIsCorrectedBeforeFirstChargeAndAudited(): void
    {
        $member = $this->member('FIX');
        $current = $this->repository->member($this->supplier, $member);
        $newClient = $this->client('fix-new');

        $updated = $this->corrections->updateIdentity($this->supplier, $member, [
            'client_id' => $newClient, 'member_number' => ' SYN-FIX-2 ',
            'expected_client_id' => $current['client_id'], 'expected_member_number' => 'SYN-FIX',
        ], $this->actor);

        self::assertSame($newClient, $updated['client_id']);
        self::assertSame('SYN-FIX-2', $updated['member_number']);
        self::assertSame([
            'before' => ['client_id' => $current['client_id'], 'member_number' => 'SYN-FIX'],
            'after' => ['client_id' => $newClient, 'member_number' => 'SYN-FIX-2'],
        ], $this->audit(MembershipCorrectionService::AUDIT_MEMBER_UPDATE, $member));
        // Revize (s člen. číslem v request_json nejsou) zůstávají průkazné.
        self::assertCount(1, $this->repository->history($this->supplier, $member));
    }

    public function testIdentityCorrectionAllowsCaseOnlyChangeAndRejectsConflictsAndStaleInput(): void
    {
        $member = $this->member('CASE');
        $other = $this->member('TAKEN');
        $client = $this->repository->member($this->supplier, $member)['client_id'];
        $otherClient = $this->repository->member($this->supplier, $other)['client_id'];

        $updated = $this->corrections->updateIdentity($this->supplier, $member, $this->identity($client, 'syn-case', $client, 'SYN-CASE'), $this->actor);
        self::assertSame('syn-case', $updated['member_number']);

        $this->assertCorrectionRejected('nonprofit_membership_identity_conflict', fn () => $this->corrections->updateIdentity($this->supplier, $member, $this->identity($client, 'syn-taken', $client, 'syn-case'), $this->actor));
        $this->assertCorrectionRejected('nonprofit_membership_identity_conflict', fn () => $this->corrections->updateIdentity($this->supplier, $member, $this->identity($otherClient, 'syn-case', $client, 'syn-case'), $this->actor));
        $this->assertCorrectionRejected('nonprofit_membership_member_changed', fn () => $this->corrections->updateIdentity($this->supplier, $member, $this->identity($client, 'SYN-NEW', $client, 'SYN-CASE'), $this->actor));
        $this->assertCorrectionRejected('nonprofit_membership_client_not_found', fn () => $this->corrections->updateIdentity($this->supplier, $member, $this->identity(PHP_INT_MAX, 'SYN-NEW', $client, 'syn-case'), $this->actor));
        $this->assertCorrectionRejected('nonprofit_membership_invalid', fn () => $this->corrections->updateIdentity($this->supplier, $member, $this->identity($client, str_repeat('X', 81), $client, 'syn-case'), $this->actor));
        self::assertSame(1, $this->auditCount(MembershipCorrectionService::AUDIT_MEMBER_UPDATE));
    }

    public function testIdentityIsLockedByChargeIncludingReversedCharge(): void
    {
        $member = $this->member('LOCKED');
        $client = $this->repository->member($this->supplier, $member)['client_id'];
        $issued = $this->issue($member, 'locked');
        $this->assertCorrectionRejected('nonprofit_membership_member_has_charges', fn () => $this->corrections->updateIdentity($this->supplier, $member, $this->identity($client, 'SYN-LOCKED-2', $client, 'SYN-LOCKED'), $this->actor));

        $reverse = ['case_id' => $issued['case']['id'], 'accounting_date' => '2026-12-31', 'reason' => 'Synthetic approved cancellation'];
        $preview = $this->charges->previewReverse($this->supplier, $reverse);
        $this->charges->confirmReverse($this->supplier, $reverse, $preview['expected_case_version'], $preview['preview_hash'], 'locked-reverse', $this->actor);
        $this->assertCorrectionRejected('nonprofit_membership_member_has_charges', fn () => $this->corrections->updateIdentity($this->supplier, $member, $this->identity($client, 'SYN-LOCKED-2', $client, 'SYN-LOCKED'), $this->actor));
    }

    public function testDatabaseRejectsDirectMemberChangeAfterChargeAndChangeOfOtherColumns(): void
    {
        $free = $this->member('RAW-FREE');
        $this->pdo->prepare("UPDATE nonprofit_members SET member_number='SYN-RAW-FREE-2' WHERE supplier_id=? AND id=?")->execute([$this->supplier, $free]);
        $this->assertTriggerRejects("UPDATE nonprofit_members SET created_by=created_by+1 WHERE supplier_id=? AND id=?", $free);
        $this->assertTriggerRejects("UPDATE nonprofit_members SET recorded_at_utc=NULL WHERE supplier_id=? AND id=?", $free);

        $charged = $this->member('RAW-CHARGED');
        $this->issue($charged, 'raw-charged');
        $this->assertTriggerRejects("UPDATE nonprofit_members SET member_number='SYN-RAW-X' WHERE supplier_id=? AND id=?", $charged);
        // Revize zůstávají v databázi neměnné.
        $this->assertTriggerRejects("UPDATE nonprofit_member_revisions SET status='ended' WHERE supplier_id=? AND member_id=?", $free);
        $this->assertTriggerRejects("DELETE FROM nonprofit_member_revisions WHERE supplier_id=? AND member_id=?", $free);
    }

    public function testVoidingLastRevisionKeepsChainAndAllowsCorrectedRevisionOnTheSameDay(): void
    {
        $member = $this->member('VOID');
        $suspended = $this->revision($member, 'suspended', '2026-07-01', 1, 'void-suspended');

        $voiding = $this->void($member, $suspended['id'], 2, 'void-suspended-cancel', 'Synthetic wrong status');
        self::assertSame($suspended['id'], $voiding['voids_revision_id']);
        self::assertSame(3, $voiding['revision_no']);
        self::assertSame($suspended['record_hash'], $voiding['parent_hash']);
        self::assertSame($voiding, $this->void($member, $suspended['id'], 2, 'void-suspended-cancel', 'Synthetic wrong status'), 'Opakovaný požadavek vrací stejný článek.');

        $history = $this->repository->history($this->supplier, $member);
        self::assertCount(3, $history);
        self::assertSame('active', MembershipRevisionTimeline::at($history, '2026-08-01')['status']);
        self::assertSame([$suspended['id'] => $voiding['id']], MembershipRevisionTimeline::voided($history));

        $ended = $this->revision($member, 'ended', '2026-07-01', 3, 'void-ended');
        self::assertSame(4, $ended['revision_no']);
        self::assertSame('ended', MembershipRevisionTimeline::at($this->repository->history($this->supplier, $member), '2026-08-01')['status']);
        $audit = $this->audit(MembershipCorrectionService::AUDIT_REVISION_VOID, $member);
        self::assertSame(['revision_id' => $suspended['id'], 'revision_no' => 2, 'voiding_revision_id' => $voiding['id'], 'reason' => 'Synthetic wrong status'], $audit);
    }

    public function testRevisionEffectiveDateMustFollowLastValidRevisionAfterVoid(): void
    {
        $member = $this->member('VOID-DATE');
        $second = $this->revision($member, 'suspended', '2026-07-01', 1, 'void-date-2');
        $this->void($member, $second['id'], 2, 'void-date-cancel');
        // Po zrušení jde zapsat revizi dříve, než byla zrušená, ale ne před poslední platnou.
        $this->revision($member, 'suspended', '2026-03-01', 3, 'void-date-earlier');
        $this->assertCorrectionRejected('nonprofit_membership_effective_conflict', fn () => $this->revision($member, 'ended', '2026-02-01', 4, 'void-date-before'));
    }

    public function testOnlyLastValidRevisionCanBeVoidedAndStaleOrInvalidRequestIsRejected(): void
    {
        $member = $this->member('VOID-LAST');
        $first = $this->lastRevision($member);
        $second = $this->revision($member, 'suspended', '2026-07-01', 1, 'void-last-2');
        $this->assertCorrectionRejected('nonprofit_membership_revision_not_last', fn () => $this->void($member, $first, 2, 'void-last-first'));
        $this->assertCorrectionRejected('nonprofit_membership_revision_changed', fn () => $this->void($member, $second['id'], 1, 'void-last-stale'));
        $voiding = $this->void($member, $second['id'], 2, 'void-last-ok');
        $this->assertCorrectionRejected('nonprofit_membership_revision_not_last', fn () => $this->void($member, $voiding['id'], 3, 'void-voiding'));
        $this->assertCorrectionRejected('nonprofit_membership_revision_not_last', fn () => $this->void($member, $second['id'], 3, 'void-again'));
        $this->assertCorrectionRejected('nonprofit_membership_invalid', fn () => $this->corrections->voidRevision($this->supplier, $member, $first, ['expected_revision' => 3, 'reason' => ' ', 'idempotency_key' => 'void-no-reason'], $this->actor));
        $this->assertCorrectionRejected('nonprofit_membership_idempotency_conflict', fn () => $this->void($member, $first, 3, 'void-last-ok', 'Synthetic other reason'));
        // Postupně jde zrušit i předchozí platnou revizi.
        $this->void($member, $first, 3, 'void-last-first-now');
        self::assertSame([], MembershipRevisionTimeline::effective($this->repository->history($this->supplier, $member)));
        $this->assertCorrectionRejected('nonprofit_membership_initial_status_invalid', fn () => $this->revision($member, 'suspended', '2026-09-01', 4, 'void-last-restart'));
    }

    public function testRevisionCarryingChargeCannotBeVoidedEvenAfterReversal(): void
    {
        $member = $this->member('VOID-CHARGED');
        $revision = $this->lastRevision($member);
        $issued = $this->issue($member, 'void-charged');
        $this->assertCorrectionRejected('nonprofit_membership_revision_has_charges', fn () => $this->void($member, $revision, 1, 'void-charged-1'));

        $reverse = ['case_id' => $issued['case']['id'], 'accounting_date' => '2026-12-31', 'reason' => 'Synthetic approved cancellation'];
        $preview = $this->charges->previewReverse($this->supplier, $reverse);
        $this->charges->confirmReverse($this->supplier, $reverse, $preview['expected_case_version'], $preview['preview_hash'], 'void-charged-reverse', $this->actor);
        $this->assertCorrectionRejected('nonprofit_membership_revision_has_charges', fn () => $this->void($member, $revision, 1, 'void-charged-2'));
        self::assertSame(0, $this->auditCount(MembershipCorrectionService::AUDIT_REVISION_VOID));
    }

    public function testRevisionCoveredByChargeIntervalCannotBeVoidedButLaterRevisionCan(): void
    {
        $member = $this->member('VOID-INTERVAL');
        // Druhá aktivní revize uprostřed roku: předpis stojí na první (k účetnímu datu) a pokrývá i druhou.
        $covered = $this->revision($member, 'active', '2026-06-01', 1, 'void-interval-2');
        $this->issue($member, 'void-interval');
        $this->assertCorrectionRejected('nonprofit_membership_revision_has_charges', fn () => $this->void($member, $covered['id'], 2, 'void-interval-cancel'));

        // Revize zapsaná až po předpisu v jeho důkazu není, zrušit ji jde.
        $later = $this->revision($member, 'suspended', '2026-09-01', 2, 'void-interval-later');
        $this->void($member, $later['id'], 3, 'void-interval-later-cancel');
        self::assertSame('active', MembershipRevisionTimeline::at($this->repository->history($this->supplier, $member), '2026-10-01')['status']);
    }

    public function testRevisionValidOnLaterAccountingDateCarriesChargeOutsideItsPeriod(): void
    {
        $member = $this->member('VOID-ACCOUNTING-DATE');
        $later = $this->revision($member, 'active', '2026-07-01', 1, 'void-accounting-later');
        // Předpis za první pololetí zaúčtovaný v prosinci: stojí na revizi k účetnímu datu, ne na revizi období.
        $request = ['member_id' => $member, 'period_from' => '2026-01-01', 'period_to' => '2026-06-30', 'accounting_date' => '2026-12-01', 'due_date' => '2026-12-31'] + $this->template;
        unset($request['rate_id']);
        $preview = $this->charges->previewIssue($this->supplier, $request);
        $issued = $this->charges->confirmIssue($this->supplier, $request, $preview['expected_case_version'], $preview['preview_hash'], 'void-accounting-date', $this->actor);
        self::assertSame($later['id'], $issued['operation']['member_revision_id']);
        self::assertNotContains($later['id'], array_column($issued['operation']['proof']['membership_intervals'], 'id'));
        $this->assertCorrectionRejected('nonprofit_membership_revision_has_charges', fn () => $this->void($member, $later['id'], 2, 'void-accounting-cancel'));
    }

    public function testPublicMemberPageSkipsVoidedRevisionOnlyFromItsRecordedTime(): void
    {
        $member = $this->member('VOID-PAGE');
        $q = $this->pdo->prepare('SELECT recorded_at_utc FROM nonprofit_member_revisions WHERE supplier_id=? AND member_id=?');
        $q->execute([$this->supplier, $member]);
        $before = (string) $q->fetchColumn();
        $this->pdo->query('SELECT SLEEP(0.01)');
        $this->void($member, $this->lastRevision($member), 1, 'void-page');
        $q = $this->pdo->prepare('SELECT MAX(recorded_at_utc) FROM nonprofit_member_revisions WHERE supplier_id=? AND member_id=?');
        $q->execute([$this->supplier, $member]);
        $after = (string) $q->fetchColumn();
        $ids = fn (string $known): array => array_column($this->repository->publicMembersPage($this->supplier, '2026-03-01', $known, 0, 101), 'id');
        // Rušící článek ani zrušená revize člena do stránky nepřivedou; před zrušením člen platil.
        self::assertNotContains($member, $ids($after));
        self::assertContains($member, $ids($before));
    }

    public function testVoidedRevisionIsIgnoredByListBatchAndCatalogFromItsRecordedTime(): void
    {
        $member = $this->member('VOID-READERS');
        $suspended = $this->revision($member, 'suspended', '2026-01-02', 1, 'void-readers-2');
        $q = $this->pdo->prepare('SELECT recorded_at_utc FROM nonprofit_member_revisions WHERE supplier_id=? AND id=?');
        $q->execute([$this->supplier, $suspended['id']]);
        $known = str_replace(' ', 'T', (string) $q->fetchColumn()) . 'Z';
        $this->pdo->query('SELECT SLEEP(0.01)');
        $this->void($member, $suspended['id'], 2, 'void-readers-cancel');

        $list = (new MembershipListRepository($this->db))->page($this->supplier, 0, 100, MembershipListFilter::parse(['year' => 2026]));
        $row = array_values(array_filter($list['items'] ?? $list, static fn (array $item): bool => (int) $item['id'] === $member))[0];
        self::assertSame('active', $row['status']);
        self::assertSame(1, (int) $row['revision_no']);

        $catalog = new MembershipPublicCatalog($this->db, $this->repository, new MembershipChargeRepository($this->db), new MembershipBalanceRepository($this->db));
        self::assertSame('active', $catalog->roles($this->supplier, '2026-03-01', null, null, 25, $member)['items'][0]['status']);
        self::assertSame('suspended', $catalog->roles($this->supplier, '2026-03-01', $known, null, 25, $member)['items'][0]['status']);
        $page = $catalog->roles($this->supplier, '2026-03-01', null, null, 100, null);
        self::assertContains($member, array_column($page['items'], 'member_id'));

        // Hromadný předpis i jednotlivý předpis vidí člena aktivního po celý rok.
        $ready = $this->ready([$member], 'void-readers-batch');
        self::assertSame(1, $ready['summary']['ready']);
    }

    public function testCatalogSkipsMemberWhoseOnlyRevisionWasVoided(): void
    {
        $member = $this->member('VOID-ONLY');
        $this->void($member, $this->lastRevision($member), 1, 'void-only');
        $catalog = new MembershipPublicCatalog($this->db, $this->repository, new MembershipChargeRepository($this->db), new MembershipBalanceRepository($this->db));
        self::assertNotContains($member, array_column($catalog->roles($this->supplier, '2026-03-01', null, null, 100, null)['items'], 'member_id'));
    }

    public function testAvailabilityMatchesWhatServerAllows(): void
    {
        [$bare] = $this->bareMember('AV-BARE');
        $free = $this->member('AV-FREE');
        $charged = $this->member('AV-CHARGED');
        $this->issue($charged, 'av-charged');

        $bareState = $this->availability($bare);
        self::assertTrue($bareState['can_delete']);
        self::assertTrue($bareState['can_edit_identity']);
        self::assertNull($bareState['voidable_revision_id']);

        $freeState = $this->availability($free);
        self::assertSame('nonprofit_membership_member_has_revisions', $freeState['delete_block']);
        self::assertTrue($freeState['can_edit_identity']);
        self::assertSame($this->lastRevision($free), $freeState['voidable_revision_id']);
        self::assertSame(1, $freeState['head_revision_no']);

        $chargedState = $this->availability($charged);
        self::assertFalse($chargedState['can_edit_identity']);
        self::assertNull($chargedState['voidable_revision_id']);
        self::assertSame('nonprofit_membership_revision_has_charges', $chargedState['void_block']);
    }

    private function availability(int $member): array
    {
        return $this->corrections->availability($this->supplier, $this->repository->member($this->supplier, $member), $this->repository->history($this->supplier, $member));
    }

    private function client(string $key): int
    {
        $q = $this->pdo->prepare('SELECT default_currency_id FROM supplier WHERE id=?'); $q->execute([$this->supplier]);
        $this->pdo->prepare("INSERT INTO clients(supplier_id,company_name,street,city,zip,country_id,main_email,language,currency_default_id,is_customer,is_vendor) VALUES(?,'Synthetic corrected member','Synthetic street','Synthetic city','10000',203,?,'cs',?,1,0)")
            ->execute([$this->supplier, $key . '@example.test', (int) $q->fetchColumn()]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @return array{0:int,1:int} */
    private function bareMember(string $number): array
    {
        $client = $this->client(strtolower($number));
        return [$this->members->create($this->supplier, $client, 'SYN-' . $number, $this->actor)['id'], $client];
    }

    private function identity(int $client, string $number, int $expectedClient, string $expectedNumber): array
    {
        return ['client_id' => $client, 'member_number' => $number, 'expected_client_id' => $expectedClient, 'expected_member_number' => $expectedNumber];
    }

    private function revision(int $member, string $status, string $from, int $expected, string $key): array
    {
        return $this->members->appendRevision($this->supplier, $member, ['status' => $status, 'effective_from' => $from, 'expected_revision' => $expected,
            'idempotency_key' => $key, 'legal_basis' => $this->basis()], $this->actor);
    }

    private function void(int $member, int $revision, int $expected, string $key, string $reason = 'Synthetic data entry mistake'): array
    {
        return $this->corrections->voidRevision($this->supplier, $member, $revision, ['expected_revision' => $expected, 'reason' => $reason, 'idempotency_key' => $key], $this->actor);
    }

    private function lastRevision(int $member): int
    {
        $history = $this->repository->history($this->supplier, $member);
        return $history[array_key_last($history)]['id'];
    }

    private function issue(int $member, string $key): array
    {
        $request = ['member_id' => $member] + $this->template;
        $preview = $this->charges->previewIssue($this->supplier, $request);
        return $this->charges->confirmIssue($this->supplier, $request, $preview['expected_case_version'], $preview['preview_hash'], 'issue-' . $key, $this->actor);
    }

    private function batchRowStatus(int $batch, int $member): ?string
    {
        $q = $this->pdo->prepare('SELECT status FROM nonprofit_membership_batch_members WHERE supplier_id=? AND batch_id=? AND member_id=?');
        $q->execute([$this->supplier, $batch, $member]);
        $status = $q->fetchColumn();
        return $status === false ? null : (string) $status;
    }

    private function audit(string $action, int $member): array
    {
        $q = $this->pdo->prepare("SELECT payload FROM activity_log WHERE supplier_id=? AND action=? AND entity_type='nonprofit_member' AND entity_id=? ORDER BY id DESC LIMIT 1");
        $q->execute([$this->supplier, $action, $member]);
        $payload = $q->fetchColumn();
        self::assertIsString($payload, 'Oprava musí být v auditní stopě.');
        return json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
    }

    private function auditCount(string $action): int
    {
        $q = $this->pdo->prepare('SELECT COUNT(*) FROM activity_log WHERE supplier_id=? AND action=?');
        $q->execute([$this->supplier, $action]);
        return (int) $q->fetchColumn();
    }

    private function assertCorrectionRejected(string $code, callable $work): void
    {
        try {
            $work();
            self::fail('Oprava měla být odmítnuta: ' . $code);
        } catch (MembershipException $e) {
            self::assertSame($code, $e->errorCode, $e->getMessage());
        }
    }

    private function assertTriggerRejects(string $sql, int $id): void
    {
        $this->pdo->exec('SAVEPOINT trigger_probe');
        try {
            $this->pdo->prepare($sql)->execute([$this->supplier, $id]);
            self::fail('Databáze měla změnu odmítnout: ' . $sql);
        } catch (\PDOException $e) {
            self::assertStringContainsString('nonprofit_membership_immutable', $e->getMessage());
        } finally {
            $this->pdo->exec('ROLLBACK TO SAVEPOINT trigger_probe');
        }
    }
}
