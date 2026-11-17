<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Nonprofit;

use MyInvoice\Repository\Nonprofit\Membership\MembershipChargeRepository;
use MyInvoice\Repository\Nonprofit\Membership\MembershipErasureRepository;
use MyInvoice\Repository\Nonprofit\Membership\MembershipListRepository;
use MyInvoice\Repository\Nonprofit\Membership\MembershipRepository;
use MyInvoice\Repository\RetentionHoldRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\ActivityLogHashChain;
use MyInvoice\Service\Nonprofit\Membership\MembershipContactErasure;
use MyInvoice\Service\System\CompanyPurgeService;
use MyInvoice\Service\Nonprofit\Membership\MembershipCorrectionService;
use MyInvoice\Service\Nonprofit\Membership\MembershipErasureAnchor;
use MyInvoice\Service\Nonprofit\Membership\MembershipErasureAssessment;
use MyInvoice\Service\Nonprofit\Membership\MembershipErasureSeal;
use MyInvoice\Service\Nonprofit\Membership\MembershipErasureService;
use MyInvoice\Service\Nonprofit\Membership\MembershipException;
use MyInvoice\Service\Nonprofit\Membership\MembershipImportService;
use MyInvoice\Service\Nonprofit\Membership\MembershipListColumns;
use MyInvoice\Service\Nonprofit\Membership\MembershipListExporter;
use MyInvoice\Service\Nonprofit\Membership\MembershipListFilter;
use MyInvoice\Service\Nonprofit\Membership\MembershipStoredProof;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;
use MyInvoice\Service\Tax\Nonprofit\SourceFacts\MembershipJ6SourceFacts;
use MyInvoice\Tests\Support\MembershipBatchReviewFixture;
use PDO;

/** Výmaz osobních údajů bývalých členů (#321, krok 10). Jen syntetická data. */
final class MembershipErasureTest extends MembershipBatchReviewFixture
{
    private const PERSONAL = 'Synthetic application signed by Jana Testovaci';

    private MembershipRepository $repository;
    private MembershipChargeRepository $chargeRepository;
    private MembershipErasureRepository $erasures;
    private MembershipErasureService $erasure;
    private MembershipCorrectionService $corrections;
    private string $today = '2032-01-02';

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new MembershipRepository($this->db);
        $this->chargeRepository = new MembershipChargeRepository($this->db);
        $this->erasures = new MembershipErasureRepository($this->db);
        $this->corrections = new MembershipCorrectionService($this->db, $this->repository, new ActivityLogger($this->db));
        $assessment = new MembershipErasureAssessment($this->db, new MembershipListRepository($this->db), new RetentionHoldRepository($this->db), $this->erasures);
        $this->erasure = new MembershipErasureService($this->db, $this->repository, $this->chargeRepository, $this->erasures, $assessment,
            new MembershipContactErasure($this->db), new ActivityLogger($this->db), new ActivityLogHashChain($this->db), new \Psr\Log\NullLogger(), fn (): string => $this->today);
    }

    public function testRetentionRunsFromEndAndLastAccountingRecordWithTheLongerWinning(): void
    {
        $plain = $this->ended('RET-PLAIN');
        $charged = $this->ended('RET-CHARGED', charge: 'reverse');
        $unpaid = $this->ended('RET-UNPAID', charge: 'open');
        $active = $this->member('RET-ACTIVE');

        $this->today = '2027-06-30';
        $items = $this->assess();
        self::assertArrayNotHasKey($active, $items, 'Aktivní člen není kandidát výmazu.');
        self::assertSame(['2027-06-30', 'membership_only', 'within_retention'], [$items[$plain]['retained_until'], $items[$plain]['governing_rule'], $items[$plain]['blocked_by']]);
        self::assertSame(['2031-12-31', 'accounting_records', '2026-12-31'], [$items[$charged]['retained_until'], $items[$charged]['governing_rule'], $items[$charged]['last_record_period_end']]);
        self::assertSame('open_receivable', $items[$unpaid]['blocked_by'], 'Neuhrazený předpis drží osobní údaje bez ohledu na lhůtu.');

        $this->today = '2027-07-01';
        $items = $this->assess();
        self::assertTrue($items[$plain]['erasable'], 'Den po konci lhůty jde údaje vymazat.');
        self::assertSame('within_retention', $items[$charged]['blocked_by']);

        $this->today = '2032-01-01';
        self::assertTrue($this->assess()[$charged]['erasable'], 'Pět let od konce období posledního zápisu.');
    }

    public function testHoldAndOpenBatchBlockErasure(): void
    {
        $charged = $this->ended('HOLD-CHARGED', charge: 'reverse');
        $plain = $this->ended('HOLD-PLAIN');
        (new RetentionHoldRepository($this->db))->place($this->supplier, 2026, 'tax_audit', 'Synthetic tax audit', '2031-06-01', $this->actor);
        $items = $this->assess();
        self::assertSame('retention_hold', $items[$charged]['blocked_by'], 'Zadržení roku se záznamem člena výmaz drží.');
        self::assertTrue($items[$plain]['erasable'], 'Člen bez účetního záznamu zadržením roku dotčen není.');

        $other = $this->member('HOLD-OTHER');
        $this->batches->create($this->supplier, $this->input([$plain, $other], 'hold-batch'), $this->actor);
        self::assertSame('open_batch', $this->assess()[$plain]['blocked_by']);
    }

    public function testSeedingBatchAndOpenProposalBlockErasure(): void
    {
        $inRange = $this->ended('SEED-IN');
        $selected = $this->ended('SEED-SELECTED');
        $outside = $this->ended('SEED-OUTSIDE');
        $other = $this->member('SEED-OTHER');
        $active = $this->batches->create($this->supplier, $this->input([$other], 'seed-active'), $this->actor)['batch']['id'];
        $chosen = $this->batches->create($this->supplier, $this->input([$other], 'seed-selected'), $this->actor)['batch']['id'];
        // Plnění dávky v půli: člen v rozsahu aktivní dávky ještě není v jejích řádcích, přesto ho dávka zasáhne.
        $this->pdo->prepare("UPDATE nonprofit_membership_batches SET phase='seeding',selection_mode='active',seed_cursor=?,seed_until=? WHERE supplier_id=? AND id=?")
            ->execute([$inRange - 1, $inRange, $this->supplier, $active]);
        $this->pdo->prepare("UPDATE nonprofit_membership_batches SET phase='seeding',selection_mode='selected',selection_json=?,seed_cursor=0,seed_until=? WHERE supplier_id=? AND id=?")
            ->execute([json_encode([$selected]), $outside, $this->supplier, $chosen]);
        $items = $this->assess();
        self::assertSame('open_batch', $items[$inRange]['blocked_by']);
        self::assertSame('open_batch', $items[$selected]['blocked_by']);
        self::assertTrue($items[$outside]['erasable'], 'Mimo rozsah aktivní dávky a mimo výběr druhé dávky výmaz nic nedrží.');

        // Člen v otevřeném návrhu už do dalšího návrhu nejde.
        $this->pdo->prepare("UPDATE nonprofit_membership_batches SET phase='cancelled' WHERE supplier_id=? AND id IN (?,?)")->execute([$this->supplier, $active, $chosen]);
        $this->erasure->create($this->supplier, $this->actor, null);
        self::assertSame('already_proposed', $this->assess()[$outside]['blocked_by']);
    }

    public function testErasurePseudonymizesMemberKeepsAccountingAndProofs(): void
    {
        $member = $this->ended('FULL', charge: 'reverse', voidReason: 'Synthetic void because Jana Testovaci asked');
        $client = (int) $this->repository->member($this->supplier, $member)['client_id'];
        (new ActivityLogger($this->db))->log('client.created', $this->actor, 'client', $client, ['company_name' => 'Jana Testovaci', 'ic' => null], null, null, $this->supplier);
        $journalBefore = $this->journalSnapshot();
        $operationsBefore = $this->operationIds($member);
        $factsBefore = $this->j6Facts($operationsBefore);

        $proposal = $this->erasure->create($this->supplier, $this->actor, 'Synthetic annual erasure');
        self::assertNotNull($proposal);
        $items = $this->erasures->items($this->supplier, $proposal['id']);
        self::assertContains($member, array_column($items, 'member_id'));
        self::assertSame('SYN-FULL', $this->itemOf($items, $member)['member_number'], 'Před provedením návrh ukáže, koho vymaže.');

        $this->erasure->approve($this->supplier, $proposal['id'], $this->actor);
        $this->assertRejected('nonprofit_membership_erasure_cooling_off', fn () => $this->erasure->execute($this->supplier, $proposal['id'], $this->actor, MembershipErasureService::EXECUTE_CONFIRMATION));
        $this->endCoolingOff($proposal['id']);
        $this->assertRejected('nonprofit_membership_erasure_confirmation', fn () => $this->erasure->execute($this->supplier, $proposal['id'], $this->actor, 'smazat'));
        $result = $this->erasure->execute($this->supplier, $proposal['id'], $this->actor, MembershipErasureService::EXECUTE_CONFIRMATION);

        self::assertSame(0, $result['remaining']);
        self::assertSame('executed', $result['proposal']['status']);
        $row = $this->repository->member($this->supplier, $member);
        self::assertSame('vymazano-' . $member, $row['member_number']);
        self::assertTrue(MembershipErasureService::isErasedMember($row));

        // Historie i operace dál projdou ověřením (pečeť), osobní texty v nich nejsou.
        $history = $this->repository->history($this->supplier, $member);
        foreach ($history as $revision) {
            self::assertSame('', $revision['legal_basis']['reference']);
            self::assertSame('', $revision['legal_basis']['evidence']);
            self::assertNotNull($revision['erasure_item_id']);
        }
        $void = array_values(array_filter($history, static fn (array $r): bool => $r['voids_revision_id'] !== null))[0];
        self::assertSame(MembershipErasureService::REDACTED, $void['request']['reason']);
        $operations = array_map(fn (int $id): array => $this->chargeRepository->operation($this->supplier, $id), $operationsBefore);
        foreach ($operations as $operation) {
            self::assertSame('vymazano-' . $member, $operation['proof']['member']['member_number']);
            self::assertSame('', $operation['proof']['member_revision']['legal_basis']['reference']);
        }
        (new MembershipStoredProof($this->db, $this->repository))->assertMany($this->supplier, $operations);
        self::assertSame($factsBefore, $this->j6Facts($operationsBefore), 'Podklady DPPO drží stejné hashe a částky.');
        self::assertSame($journalBefore, $this->journalSnapshot(), 'Účetní deník zůstává beze změny.');

        $symbols = $this->pdo->prepare('SELECT variable_symbol FROM nonprofit_membership_charge_cases WHERE supplier_id=? AND member_id=?');
        $symbols->execute([$this->supplier, $member]);
        self::assertSame([null], $symbols->fetchAll(PDO::FETCH_COLUMN), 'Variabilní symbol odvozený z čísla člena zmizí.');
        $dump = $this->memberDump($member);
        self::assertStringNotContainsString('Jana', $dump, 'Žádný osobní text v datech člena.');
        self::assertStringNotContainsString('SYN-FULL', $dump);
        self::assertStringNotContainsString('12345678', $dump, 'Zadaný variabilní symbol (= číslo člena) zmizí z případu i požadavku.');

        // Veřejný katalog (API, MCP): vazbu na kontakt vymazaného člena nevydá a člena označí.
        $catalog = new \MyInvoice\Service\Nonprofit\Membership\MembershipPublicCatalog($this->db, $this->repository, $this->chargeRepository,
            new \MyInvoice\Repository\Nonprofit\Membership\MembershipBalanceRepository($this->db));
        $role = $catalog->roles($this->supplier, '2026-02-01', null, null, 25, $member)['items'][0];
        self::assertSame([null, true], [$role['client_id'], $role['erased']]);
        foreach ($catalog->receivables($this->supplier, $member, null)['items'] as $receivable) {
            self::assertSame([null, true], [$receivable['client_id'], $receivable['erased']]);
        }

        $contact = $this->pdo->query('SELECT company_name,main_email,street FROM clients WHERE id=' . $client)->fetch(PDO::FETCH_ASSOC);
        self::assertSame(['company_name' => MembershipContactErasure::placeholderName($client), 'main_email' => null, 'street' => ''], $contact);
        $done = $this->itemOf($this->erasures->items($this->supplier, $proposal['id']), $member);
        self::assertSame('done', $done['outcome']);
        self::assertNull($done['member_number'], 'Doklad o výmazu nevrací číslo ani jméno.');
        self::assertNull($done['company_name']);
        self::assertSame('anonymized', $done['counts']['contact']);
        self::assertGreaterThanOrEqual(1, $done['counts']['audit_records']);
        self::assertSame(1, $done['counts']['contact_audit_records']);
        $clientAudit = $this->pdo->prepare("SELECT payload FROM activity_log WHERE supplier_id=? AND entity_type='client' AND entity_id=? AND action='client.created'");
        $clientAudit->execute([$this->supplier, $client]);
        self::assertSame(['redacted' => MembershipErasureService::REDACTED], json_decode((string) $clientAudit->fetchColumn(), true), 'Audit anonymizovaného kontaktu jméno nenese.');

        // Audit zrušení revize nese důvod; po výmazu ho nenese, záznam zůstává.
        $audit = $this->auditPayloads($member);
        self::assertNotEmpty($audit);
        foreach ($audit as $payload) self::assertStringNotContainsString('Jana', json_encode($payload, JSON_UNESCAPED_UNICODE));
        $created = $this->pdo->query("SELECT payload FROM activity_log WHERE supplier_id={$this->supplier} AND action='" . MembershipErasureService::AUDIT_EXECUTED . "'")->fetchColumn();
        self::assertIsString($created);
        self::assertStringNotContainsString('SYN-FULL', $created);
    }

    public function testSealedRecordRejectsAnyOtherChange(): void
    {
        $member = $this->ended('SEAL', charge: 'reverse');
        $this->eraseAll();
        $revision = $this->repository->history($this->supplier, $member)[0];
        self::assertTrue(MembershipErasureSeal::revisionProven($revision));
        foreach (['status' => 'suspended', 'effective_from' => '2025-01-01'] as $field => $value) {
            self::assertFalse(MembershipErasureSeal::revisionProven([$field => $value] + $revision), 'Pečeť kryje i ' . $field);
        }
        self::assertFalse(MembershipErasureSeal::revisionProven(['legal_basis' => ['reference' => 'Restored text'] + $revision['legal_basis']] + $revision));
        self::assertFalse(MembershipErasureSeal::revisionProven(['erasure_item_id' => (int) $revision['erasure_item_id'] + 1] + $revision));
        $operation = $this->chargeRepository->operation($this->supplier, $this->operationIds($member)[0]);
        self::assertTrue(MembershipErasureSeal::operationProven($operation));
        self::assertFalse(MembershipErasureSeal::operationProven(['amount_cents' => $operation['amount_cents'] + 1] + $operation));
        // Trigger pustí jen jednu změnu osobních sloupců s pečetí; další zápis skončí chybou.
        try {
            $this->pdo->prepare('UPDATE nonprofit_member_revisions SET request_json=? WHERE id=?')->execute(['{}', $revision['id']]);
            self::fail('Zapečetěnou revizi šlo přepsat.');
        } catch (\PDOException $e) {
            self::assertStringContainsString('nonprofit_membership_immutable', $e->getMessage());
        }
        try {
            $this->pdo->prepare('UPDATE nonprofit_members SET member_number=? WHERE id=?')->execute(['SYN-SEAL', $member]);
            self::fail('Vymazané číslo šlo obnovit.');
        } catch (\PDOException $e) {
            self::assertStringContainsString('nonprofit_membership_immutable', $e->getMessage());
        }
    }

    /**
     * Pečeť je ukotvená v řetězu auditu: řádek s pozměněným obsahem a dopočítanou pečetí (zásah mimo triggery)
     * neodpovídá kotvě, a bez kotvy je historie neprůkazná.
     */
    public function testSealsAreAnchoredInTheAuditChain(): void
    {
        $member = $this->ended('ANCHOR', charge: 'reverse');
        $this->eraseAll();
        $anchor = new \MyInvoice\Service\Nonprofit\Membership\MembershipErasureAnchor($this->db);
        $revision = $this->repository->history($this->supplier, $member)[0];
        $operation = $this->chargeRepository->operation($this->supplier, $this->operationIds($member)[0]);
        $rows = [['r', $revision], ['o', $operation]];
        $anchor->assertRows($this->supplier, $rows, 'x', 'x');

        // Podvrh: změněná částka s pečetí přepočítanou nad novým obsahem (pečeť sama by ho přijala).
        $forged = ['amount_cents' => $operation['amount_cents'] + 100] + $operation;
        $forged['erased_digest'] = MembershipErasureSeal::digest((int) $forged['erasure_item_id'], $forged['record_hash'], MembershipChargeRepository::operationRecord($forged));
        self::assertTrue(MembershipErasureSeal::operationProven($forged));
        try {
            $anchor->assertRows($this->supplier, [['o', $forged]], 'forged', 'x');
            self::fail('Podvržená pečeť prošla.');
        } catch (MembershipException $e) {
            self::assertSame('forged', $e->errorCode);
        }

        $command = (string) $this->pdo->query('SELECT idempotency_key FROM nonprofit_membership_charge_commands WHERE id=' . $operation['command_id'])->fetchColumn();
        self::assertNotNull($this->chargeRepository->command($this->supplier, $command));
        $catalog = new \MyInvoice\Service\Nonprofit\Membership\MembershipPublicCatalog($this->db, $this->repository, $this->chargeRepository,
            new \MyInvoice\Repository\Nonprofit\Membership\MembershipBalanceRepository($this->db));
        $catalog->roles($this->supplier, '2026-02-01', null, null, 25, $member);
        $operationIds = $this->operationIds($member);
        $j6Rows = $this->j6Rows($operationIds);
        (new MembershipJ6SourceFacts($this->db))->capture($this->supplier, $j6Rows, false);

        // Nezapečetěná kotva pod oknem odloženého pečetění (mezera po promazání) není článkem řetězu: neuzná se.
        $deferred = $this->pdo->query('SELECT deferred_from_id FROM activity_log_chain_head WHERE id = 1')->fetchColumn();
        $this->pdo->exec('UPDATE activity_log_chain_head SET deferred_from_id = (SELECT MAX(id) + 1 FROM activity_log) WHERE id = 1');
        $this->assertRejected('nonprofit_membership_revision_corrupt', fn () => $this->repository->history($this->supplier, $member));
        $this->pdo->prepare('UPDATE activity_log_chain_head SET deferred_from_id = ? WHERE id = 1')->execute([$deferred === false ? null : $deferred]);

        // J6 kontroluje zvlášť kotvu operace i revize: chybí-li v kotvě jen jedna z nich, podklad DPPO neprojde.
        foreach (['o:' . $operation['id'], 'r:' . $operation['member_revision_id']] as $key) {
            $original = (string) $this->pdo->query("SELECT payload FROM activity_log WHERE supplier_id={$this->supplier} AND action='nonprofit_membership_erasure_sealed'")->fetchColumn();
            $this->pdo->prepare("UPDATE activity_log SET payload=JSON_REMOVE(payload, ?) WHERE supplier_id=? AND action='nonprofit_membership_erasure_sealed'")
                ->execute(['$.seals."' . $key . '"', $this->supplier]);
            $this->assertRejected('nonprofit_membership_j6_proof_invalid', fn () => (new MembershipJ6SourceFacts($this->db))->capture($this->supplier, $j6Rows, false));
            $this->pdo->prepare("UPDATE activity_log SET payload=? WHERE supplier_id=? AND action='nonprofit_membership_erasure_sealed'")->execute([$original, $this->supplier]);
        }

        // Bez kotvy v auditu neprojde žádná cesta, která čte zapečetěné záznamy vymazaného člena.
        $this->pdo->prepare("DELETE FROM activity_log WHERE supplier_id=? AND action='nonprofit_membership_erasure_sealed'")->execute([$this->supplier]);
        $this->assertRejected('nonprofit_membership_revision_corrupt', fn () => $this->repository->history($this->supplier, $member));
        $this->assertRejected('nonprofit_membership_operation_corrupt', fn () => $this->chargeRepository->operation($this->supplier, $operation['id']));
        $this->assertRejected('nonprofit_membership_operation_corrupt', fn () => $this->chargeRepository->operationsMany($this->supplier, $operationIds));
        $this->assertRejected('nonprofit_membership_command_corrupt', fn () => $this->chargeRepository->command($this->supplier, $command));
        $this->assertRejected('nonprofit_membership_revision_corrupt', fn () => $catalog->roles($this->supplier, '2026-02-01', null, null, 25, $member));
        $this->assertRejected('nonprofit_membership_j6_proof_invalid', fn () => (new MembershipJ6SourceFacts($this->db))->capture($this->supplier, $j6Rows, false));
    }

    public function testBatchAnchoringReportsOnlyTheMemberWithBrokenAnchor(): void
    {
        $broken = $this->ended('BATCH-A', charge: 'reverse');
        $fine = $this->ended('BATCH-B', charge: 'reverse');
        $active = $this->member('BATCH-C');
        $this->eraseAll();
        $revision = $this->repository->history($this->supplier, $broken)[0]['id'];
        $operation = $this->operationIds($broken)[0];
        $this->pdo->prepare("UPDATE activity_log SET payload=JSON_REMOVE(payload, ?, ?) WHERE supplier_id=? AND action=? AND JSON_EXTRACT(payload, '$.member_id')=?")
            ->execute(['$.seals."r:' . $revision . '"', '$.seals."o:' . $operation . '"', $this->supplier, MembershipErasureAnchor::ACTION, $broken]);

        $contexts = (new MembershipRepository($this->db))->batchContexts($this->supplier, [$broken, $fine, $active]);
        self::assertSame('nonprofit_membership_revision_corrupt', ($contexts[$broken]['error'] ?? null)?->errorCode);
        foreach ([$fine, $active] as $member) {
            self::assertArrayNotHasKey('error', $contexts[$member]);
            self::assertNotSame([], $contexts[$member]['history']);
        }
        self::assertNotSame([], $this->chargeRepository->operationsForMember($this->supplier, $fine));
        $this->assertRejected('nonprofit_membership_operation_corrupt', fn () => (new MembershipChargeRepository($this->db))->operationsForMember($this->supplier, $broken));
    }

    public function testJ6RejectsSealedRowWhoseContentDiffersFromItsAnchoredSeal(): void
    {
        $members = ['honest' => $this->ended('J6-H', charge: 'reverse'), 'revision' => $this->ended('J6-R', charge: 'reverse'), 'operation' => $this->ended('J6-O', charge: 'reverse')];
        $rows = array_map(fn (int $member): array => $this->j6Rows($this->operationIds($member)), $members);
        $proposal = $this->erasure->create($this->supplier, $this->actor, null);
        $this->erasure->approve($this->supplier, $proposal['id'], $this->actor);
        foreach ($members as $forge => $member) $this->sealWithAnchor($member, $forge);

        (new MembershipJ6SourceFacts($this->db))->capture($this->supplier, $rows['honest'], false);
        foreach (['revision', 'operation'] as $forge) {
            $this->assertRejected('nonprofit_membership_j6_proof_invalid', fn () => (new MembershipJ6SourceFacts($this->db))->capture($this->supplier, $rows[$forge], false));
        }
    }

    /**
     * Zapečetí revize a operace člena v rozpracované schválené položce a zapíše k nim kotvu. U $forge se
     * uloží jiný obsah, než nad jakým je pečeť spočtená: kotva s pečetí souhlasí, obsah s nimi ne.
     */
    private function sealWithAnchor(int $member, string $forge): void
    {
        $item = (int) $this->pdo->query("SELECT id FROM nonprofit_membership_erasure_items WHERE supplier_id={$this->supplier} AND member_id={$member} AND outcome='pending'")->fetchColumn();
        $seals = [];
        foreach ($this->repository->history($this->supplier, $member) as $revision) {
            $revision['legal_basis'] = MembershipErasureService::redactLegalBasis($revision['legal_basis']);
            $digest = MembershipErasureSeal::digest($item, $revision['record_hash'], MembershipRepository::revisionRecord($revision));
            if ($forge === 'revision') $revision['legal_basis']['reference'] = 'Podvrh';
            $this->pdo->prepare('UPDATE nonprofit_member_revisions SET legal_basis_json=?, erasure_item_id=?, erased_digest=? WHERE id=?')
                ->execute([NonprofitTaxRules::canonicalJson($revision['legal_basis']), $item, $digest, $revision['id']]);
            $seals[MembershipErasureAnchor::key(MembershipErasureAnchor::REVISION, $revision['id'])] = $digest;
        }
        foreach ($this->operationIds($member) as $id) {
            $operation = $this->chargeRepository->operation($this->supplier, $id);
            $operation['proof'] = MembershipErasureService::redactProof($operation['proof'], $member);
            $digest = MembershipErasureSeal::digest($item, $operation['record_hash'], MembershipChargeRepository::operationRecord($operation));
            if ($forge === 'operation') $operation['proof']['member']['member_number'] = 'PODVRH';
            $this->pdo->prepare('UPDATE nonprofit_membership_charge_operations SET proof_json=?, erasure_item_id=?, erased_digest=? WHERE id=?')
                ->execute([NonprofitTaxRules::canonicalJson($operation['proof']), $item, $digest, $id]);
            $seals[MembershipErasureAnchor::key(MembershipErasureAnchor::OPERATION, $id)] = $digest;
        }
        (new ActivityLogger($this->db))->log(MembershipErasureAnchor::ACTION, null, MembershipErasureAnchor::ENTITY, $item,
            ['member_id' => $member, 'seals' => $seals], null, null, $this->supplier);
    }

    public function testAlreadySealedCommandSkipsAsAlreadyErased(): void
    {
        $member = $this->ended('SEALED-CMD', charge: 'reverse');
        $proposal = $this->erasure->create($this->supplier, $this->actor, null);
        $this->erasure->approve($this->supplier, $proposal['id'], $this->actor);
        $this->endCoolingOff($proposal['id']);
        $item = (int) $this->pdo->query("SELECT id FROM nonprofit_membership_erasure_items WHERE supplier_id={$this->supplier} AND member_id={$member}")->fetchColumn();
        $command = $this->pdo->query('SELECT c.id, c.request_hash, c.request_json FROM nonprofit_membership_charge_commands c JOIN nonprofit_membership_charge_operations o ON o.command_id=c.id WHERE o.id=' . $this->operationIds($member)[0])->fetch(PDO::FETCH_ASSOC);
        $request = MembershipErasureService::redactCommandRequest(json_decode($command['request_json'], true));
        $digest = MembershipErasureSeal::digest($item, $command['request_hash'], ['request' => $request]);
        $this->pdo->prepare('UPDATE nonprofit_membership_charge_commands SET request_json=?, erasure_item_id=?, erased_digest=? WHERE id=?')
            ->execute([NonprofitTaxRules::canonicalJson($request), $item, $digest, $command['id']]);
        (new ActivityLogger($this->db))->log(MembershipErasureAnchor::ACTION, null, MembershipErasureAnchor::ENTITY, $item,
            ['member_id' => $member, 'seals' => [MembershipErasureAnchor::key(MembershipErasureAnchor::COMMAND, (int) $command['id']) => $digest]], null, null, $this->supplier);

        $this->erasure->execute($this->supplier, $proposal['id'], $this->actor, MembershipErasureService::EXECUTE_CONFIRMATION);
        $row = $this->itemOf($this->erasures->items($this->supplier, $proposal['id']), $member);
        self::assertSame(['skipped_changed', 'already_erased'], [$row['outcome'], $row['skip_reason']]);
    }

    public function testImportPreviewRejectsErasedMemberAndReservedNumberAndExportHidesContact(): void
    {
        $member = $this->ended('XFER');
        $other = $this->member('XFER-OTHER');
        $this->eraseAll();

        $rows = iterator_to_array((new MembershipListExporter($this->db, $this->today))->rows($this->supplier, MembershipListFilter::parse(['year' => '2031'])), false);
        $named = [];
        foreach ($rows as $cells) {
            $row = array_combine(MembershipListColumns::headers(), array_map(static fn (array $cell): mixed => $cell[1], $cells));
            $named[$row['Členské číslo']] = $row;
        }
        $erased = $named[MembershipErasureService::placeholderNumber($member)];
        // Zachovaný kontakt by soubor znovu propojil s osobou: ani jméno, ani ID kontaktu.
        self::assertSame(['', ''], [$erased['ID kontaktu'], $erased['Jméno / název']]);
        self::assertNotSame('', $named['SYN-XFER-OTHER']['ID kontaktu']);

        $import = new MembershipImportService($this->db, new ActivityLogger($this->db), $this->members);
        $csv = "Členské číslo;ID kontaktu;IČO;Jméno / název;Nový stav;Platí od;Podklad;Doložení\n"
            . MembershipErasureService::placeholderNumber($member) . ";;;;active;2032-02-01;Synthetic decision;\n"
            . "VYMAZANO-999999;;;Synthetic new;active;2032-02-01;Synthetic decision;\n"
            . "vymazáno-{$other};;;Synthetic new;active;2032-02-01;Synthetic decision;\n";
        $report = $import->run($this->supplier, $this->actor, $csv, 'a.csv', true, canCreateContacts: true);
        self::assertSame([['error', 'member_erased'], ['error', 'number_reserved'], ['error', 'number_reserved']],
            array_map(static fn (array $r): array => [$r['status'], $r['reason']], $report['rows']));

        // Řádek vymazaného člena z exportu bez změny import ostatních nezablokuje.
        $csv = "Členské číslo;ID kontaktu;IČO;Jméno / název;Nový stav;Platí od;Podklad;Doložení\n"
            . MembershipErasureService::placeholderNumber($member) . ";;;;;;;\n"
            . "SYN-XFER-OTHER;;;;suspended;2032-02-01;Synthetic decision;\n";
        $report = $import->run($this->supplier, $this->actor, $csv, 'a.csv', true);
        self::assertTrue($report['ok']);
        self::assertSame([['unchanged', 'member_erased'], ['new_revision', null]],
            array_map(static fn (array $r): array => [$r['status'], $r['reason'] ?? null], $report['rows']));
    }

    public function testReservedNumbersOfWholeImportFileTakeConstantNumberOfQueries(): void
    {
        $import = new MembershipImportService($this->db, new ActivityLogger($this->db), $this->members);
        $questions = fn (): int => (int) $this->pdo->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch(PDO::FETCH_NUM)[1];
        $cost = function (int $rows) use ($import, $questions): int {
            $csv = "Členské číslo;ID kontaktu;IČO;Jméno / název;Nový stav;Platí od;Podklad;Doložení\n";
            for ($i = 0; $i < $rows; $i++) $csv .= "NEW-{$rows}-{$i};;;Synthetic new {$i};active;2032-02-01;Synthetic decision;\n";
            $before = $questions();
            $import->run($this->supplier, $this->actor, $csv, 'a.csv', true, canCreateContacts: true);
            return $questions() - $before;
        };
        $cost(5);
        self::assertSame($cost(10), $cost(200), 'Náhled importu nesmí klást dotaz na každý řádek.');
    }

    public function testNumberCollidingWithPseudonymInDatabaseCollationIsReserved(): void
    {
        foreach (['vymazano-5', 'VYMAZANO-5', 'vymazáno-57', 'výmazano-1', "vy\u{200B}mazano-1", "vy\u{1}mazano-1", "vymazano-\u{20DD}1", 'vymazano-abc', 'ｖｙｍａｚａｎｏ-1'] as $number) {
            self::assertTrue(MembershipErasureService::isReservedNumber($this->db, $number), bin2hex($number));
        }
        foreach (['vymazan-5', 'clen-vymazano-1', 'vymazano', 'SYN-1'] as $number) {
            self::assertFalse(MembershipErasureService::isReservedNumber($this->db, $number), $number);
        }
        $other = $this->member('COLL');
        $client = $this->client('coll-free');
        $this->assertRejected('nonprofit_membership_member_number_reserved', fn () => $this->members->create($this->supplier, $client, "vymazáno-{$other}", $this->actor));
    }

    public function testLegacyNumberLikePseudonymIsNotErasedAndCollisionSkipsErasure(): void
    {
        // Čísla zapsaná před rezervací (tabulka členů je nasazená dřív než výmaz).
        $victim = $this->ended('LEGACY-VICTIM');
        $holder = $this->member('LEGACY-HOLDER');
        $own = $this->member('LEGACY-OWN');
        $this->pdo->prepare('UPDATE nonprofit_members SET member_number=? WHERE id=?')->execute(['VYMAZANO-' . $victim, $holder]);
        $this->pdo->prepare('UPDATE nonprofit_members SET member_number=? WHERE id=?')->execute(['Vymazáno-' . $own, $own]);

        // Pseudonym se pozná bajtově v PHP i v SQL; starší číslo „Vymazáno-{id}“ člena není vymazaný člen.
        $erased = fn (int $id): int => (int) $this->pdo->query('SELECT ' . MembershipListRepository::erasedSql('m') . " FROM nonprofit_members m WHERE id={$id}")->fetchColumn();
        self::assertSame(0, $erased($own));
        self::assertFalse(MembershipErasureService::isErasedMember($this->repository->member($this->supplier, $own)));
        // Oprava kontaktu s nezměněným starším číslem projde, i když by nové takové číslo bylo vyhrazené.
        $current = $this->repository->member($this->supplier, $own);
        $same = ['client_id' => $current['client_id'], 'member_number' => $current['member_number'],
            'expected_client_id' => $current['client_id'], 'expected_member_number' => $current['member_number']];
        self::assertSame($own, $this->corrections->updateIdentity($this->supplier, $own, $same, $this->actor)['id']);
        // Opakované založení téhož člena se starším číslem vrátí jeho samého (idempotence), ne „vyhrazené číslo“.
        self::assertSame($own, $this->members->create($this->supplier, $current['client_id'], $current['member_number'], $this->actor)['id']);

        $this->eraseAll();
        $item = $this->itemOf($this->erasures->items($this->supplier, $this->lastProposal()), $victim);
        self::assertSame(['skipped_changed', 'number_conflict'], [$item['outcome'], $item['skip_reason']]);
        self::assertSame('VYMAZANO-' . $victim, $this->repository->member($this->supplier, $holder)['member_number']);
    }

    private function lastProposal(): int
    {
        return (int) $this->pdo->query("SELECT MAX(id) FROM nonprofit_membership_erasure_proposals WHERE supplier_id={$this->supplier}")->fetchColumn();
    }

    public function testCompanyPurgeAfterErasureRemovesEverythingAndKeepsTheChainVerifiable(): void
    {
        $member = $this->ended('PURGE', charge: 'reverse', voidReason: 'Synthetic wrong suspension of Jana Testovaci');
        $this->eraseAll();
        $from = (int) $this->pdo->query("SELECT MIN(id) FROM activity_log WHERE supplier_id={$this->supplier}")->fetchColumn();
        self::assertGreaterThan(0, (int) $this->pdo->query("SELECT COUNT(*) FROM activity_log_redactions WHERE supplier_id={$this->supplier}")->fetchColumn());
        self::assertSame(MembershipErasureService::placeholderNumber($member), $this->repository->member($this->supplier, $member)['member_number']);
        // Smazání firmy neběží v transakci: data fixture se commitnou a smaže je až purge.
        $this->pdo->commit();
        $chain = new ActivityLogHashChain($this->db);
        $chain->sealPending();
        try {
            (new CompanyPurgeService($this->db))->purge($this->supplier, true, false);
            foreach (['nonprofit_members', 'nonprofit_member_revisions', 'nonprofit_membership_charge_operations', 'nonprofit_membership_charge_commands',
                'nonprofit_membership_erasure_items', 'nonprofit_membership_erasure_proposals'] as $table) {
                self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM {$table} WHERE supplier_id={$this->supplier}")->fetchColumn(), $table);
            }
            // Zachovaný řetěz s redakcemi a kotvou zůstává ověřitelný i bez dat firmy.
            self::assertGreaterThan(0, (int) $this->pdo->query("SELECT COUNT(*) FROM activity_log_redactions WHERE supplier_id={$this->supplier}")->fetchColumn());
            $verified = $chain->verify($from);
            self::assertTrue($verified['ok'], (string) json_encode($verified['broken']));
        } finally {
            $this->pdo->prepare('DELETE FROM users WHERE id=?')->execute([$this->actor]);
        }
    }

    public function testTriggerRejectsSealWithoutPendingItemOfThatMember(): void
    {
        $member = $this->ended('TRIG');
        $other = $this->ended('TRIG-OTHER');
        $proposal = $this->erasure->create($this->supplier, $this->actor, null);
        $otherItem = $this->itemOf($this->erasures->items($this->supplier, $proposal['id']), $other)['id'];
        $revision = $this->repository->history($this->supplier, $member)[0];
        try {
            $this->pdo->prepare('UPDATE nonprofit_member_revisions SET legal_basis_json=?,erasure_item_id=?,erased_digest=? WHERE id=?')
                ->execute(['{}', $otherItem, str_repeat('a', 64), $revision['id']]);
            self::fail('Pečeť cizí položky prošla.');
        } catch (\PDOException $e) {
            self::assertStringContainsString('nonprofit_membership_immutable', $e->getMessage());
        }
    }

    public function testTriggerAllowsOnlyPersonalPathsWithApprovedProposal(): void
    {
        $member = $this->ended('FORGE', charge: 'reverse');
        $proposal = $this->erasure->create($this->supplier, $this->actor, null);
        $item = $this->itemOf($this->erasures->items($this->supplier, $proposal['id']), $member)['id'];
        $operation = $this->operationIds($member)[0];
        $forge = fn (string $path) => $this->pdo->prepare("UPDATE nonprofit_membership_charge_operations SET proof_json=JSON_SET(proof_json,?,'forged'),erasure_item_id=?,erased_digest=? WHERE id=?")
            ->execute([$path, $item, str_repeat('a', 64), $operation]);
        // Neschválený návrh pečeť nepustí ani pro osobní cestu.
        $this->assertImmutable(fn () => $forge('$.member.member_number'));
        $this->erasure->approve($this->supplier, $proposal['id'], $this->actor);
        // Schválený návrh pustí jen osobní cesty; finanční obsah důkazu ne.
        $this->assertImmutable(fn () => $forge('$.classification'));
        $this->assertImmutable(fn () => $forge('$.accounts'));
        $revision = $this->repository->history($this->supplier, $member)[0]['id'];
        $this->assertImmutable(fn () => $this->pdo->prepare("UPDATE nonprofit_member_revisions SET legal_basis_json=JSON_SET(legal_basis_json,'$.relationship','forged'),erasure_item_id=?,erased_digest=? WHERE id=?")
            ->execute([$item, str_repeat('a', 64), $revision]));
        $forge('$.member.member_number');
        self::assertSame($item, (int) $this->pdo->query('SELECT erasure_item_id FROM nonprofit_membership_charge_operations WHERE id=' . $operation)->fetchColumn());
    }

    /**
     * Hlavní reálný případ: předpis 2026 uhrazený převodem v roce 2028. Lhůta běží od konce období úhrady,
     * výmaz nemění deník, podklady DPPO ani stav úhrady.
     */
    public function testPaidChargeRetentionRunsFromPaymentAndErasureKeepsSettlement(): void
    {
        $member = $this->member('PAID');
        $request = ['member_id' => $member] + $this->template;
        $preview = $this->charges->previewIssue($this->supplier, $request);
        $issued = $this->charges->confirmIssue($this->supplier, $request, $preview['expected_case_version'], $preview['preview_hash'], 'issue-paid', $this->actor);
        $this->members->appendRevision($this->supplier, $member, ['status' => 'ended', 'effective_from' => '2026-06-30', 'expected_revision' => 1,
            'idempotency_key' => 'end-paid', 'legal_basis' => $this->basis()], $this->actor);
        $this->payClaim($issued, '500.00', '2028-01-15');
        $statusBefore = $this->duesStatus($member);
        self::assertSame('paid', $statusBefore);

        $this->today = '2033-12-31';
        $item = $this->assess()[$member];
        self::assertSame(['2028-12-31', '2033-12-31', 'within_retention'], [$item['last_record_period_end'], $item['retained_until'], $item['blocked_by']],
            'Úhrada v roce 2028 je poslední účetní záznam člena.');
        $this->today = '2034-01-01';
        self::assertTrue($this->assess()[$member]['erasable']);
        $journalBefore = $this->journalSnapshot();
        $factsBefore = $this->j6Facts($this->operationIds($member));

        $this->eraseAll();

        self::assertSame('done', $this->doneItem($member)['outcome']);
        self::assertSame($journalBefore, $this->journalSnapshot());
        self::assertSame($factsBefore, $this->j6Facts($this->operationIds($member)));
        self::assertSame('paid', $this->duesStatus($member), 'Stav úhrady se výmazem nezmění.');
    }

    /** Předpis přes dvě revize: důkaz nese dva intervaly a trigger maskuje každý z nich. */
    public function testChargeOverSeveralMembershipIntervalsIsErased(): void
    {
        $member = $this->member('MULTI');
        $this->members->appendRevision($this->supplier, $member, ['status' => 'active', 'effective_from' => '2026-03-01', 'expected_revision' => 1,
            'idempotency_key' => 'multi-2', 'legal_basis' => ['reference' => self::PERSONAL, 'evidence' => 'Synthetic renewal by Jana Testovaci'] + $this->basis()], $this->actor);
        $request = ['member_id' => $member] + $this->template;
        $preview = $this->charges->previewIssue($this->supplier, $request);
        $issued = $this->charges->confirmIssue($this->supplier, $request, $preview['expected_case_version'], $preview['preview_hash'], 'issue-multi', $this->actor);
        self::assertCount(2, $issued['operation']['proof']['membership_intervals']);
        $reverse = ['case_id' => $issued['case']['id'], 'accounting_date' => '2026-12-30', 'reason' => 'Synthetic reversal'];
        $reversePreview = $this->charges->previewReverse($this->supplier, $reverse);
        $this->charges->confirmReverse($this->supplier, $reverse, $reversePreview['expected_case_version'], $reversePreview['preview_hash'], 'reverse-multi', $this->actor);
        $this->members->appendRevision($this->supplier, $member, ['status' => 'ended', 'effective_from' => '2026-12-31', 'expected_revision' => 2,
            'idempotency_key' => 'multi-end', 'legal_basis' => $this->basis()], $this->actor);

        $this->eraseAll();

        self::assertSame('done', $this->doneItem($member)['outcome']);
        self::assertStringNotContainsString('Jana', $this->memberDump($member));
    }

    public function testClosedProposalDoesNotReturnNamesAndSuspendedMemberIsNoCandidate(): void
    {
        $member = $this->ended('CLOSED');
        $suspended = $this->member('SUSPENDED');
        $this->members->appendRevision($this->supplier, $suspended, ['status' => 'suspended', 'effective_from' => '2026-06-30', 'expected_revision' => 1,
            'idempotency_key' => 'suspend-' . $suspended, 'legal_basis' => $this->basis()], $this->actor);
        self::assertArrayNotHasKey($suspended, $this->assess(), 'Pozastavený člen není kandidát výmazu.');
        $proposal = $this->erasure->create($this->supplier, $this->actor, null);
        self::assertSame('SYN-CLOSED', $this->itemOf($this->erasures->items($this->supplier, $proposal['id']), $member)['member_number']);
        $this->erasure->reject($this->supplier, $proposal['id'], $this->actor);
        $item = $this->itemOf($this->erasures->items($this->supplier, $proposal['id']), $member);
        self::assertSame([null, null], [$item['member_number'], $item['company_name']], 'Zamítnutý návrh osobní údaj nevrací.');
    }

    public function testMembershipRetentionWinsWhenEndedLongAfterLastRecord(): void
    {
        $item = MembershipErasureAssessment::retention('member', 1, '2031-06-30', '2026-12-31');
        self::assertSame(['2032-06-30', 'membership_only'], [$item['retained_until'], $item['governing_rule']]);
        $item = MembershipErasureAssessment::retention('member', 1, '2026-06-30', '2026-12-31');
        self::assertSame(['2031-12-31', 'accounting_records'], [$item['retained_until'], $item['governing_rule']]);
    }

    public function testContactWithAttachedDocumentIsRetained(): void
    {
        $member = $this->ended('DOCUMENT');
        $client = (int) $this->repository->member($this->supplier, $member)['client_id'];
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        $this->pdo->prepare("INSERT INTO document_links(document_id,entity_type,entity_id,supplier_id) VALUES(987654321,'client',?,?)")->execute([$client, $this->supplier]);
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        $this->eraseAll();
        $item = $this->doneItem($member);
        self::assertSame(['retained', ['document_links']], [$item['counts']['contact'], $item['counts']['contact_other_uses']], 'Připojený dokument (přihláška) kontakt drží.');
    }

    public function testWholeAccountingHoldBlocksDeletedMemberAudit(): void
    {
        $bare = $this->bareFor('DELETED-HOLD');
        $client = (int) $this->repository->member($this->supplier, $bare)['client_id'];
        $this->corrections->delete($this->supplier, $bare, $this->actor, ['client_id' => $client, 'member_number' => 'SYN-DELETED-HOLD']);
        $this->today = '2040-01-01';
        (new RetentionHoldRepository($this->db))->place($this->supplier, null, 'litigation', 'Synthetic whole hold', '2030-01-01', $this->actor);
        self::assertSame('retention_hold', $this->deleted($bare)['blocked_by']);
    }

    public function testSharedContactIsRetainedButHiddenAndUnsearchable(): void
    {
        $member = $this->ended('SHARED');
        $client = (int) $this->repository->member($this->supplier, $member)['client_id'];
        $this->pdo->prepare("INSERT INTO invoice_counters(supplier_id,client_id,revenue_category_id,invoice_type,period,last_number,floor_number) VALUES(?,?,0,'invoice','2026',1,0)")
            ->execute([$this->supplier, $client]);
        // Starší ukončená dávka s položkou člena: její detail po výmazu jméno nevrací.
        $batch = $this->batches->create($this->supplier, $this->input([$member, $this->member('SHARED-OTHER')], 'shared-batch'), $this->actor)['batch']['id'];
        $this->batches->cancel($this->supplier, $batch, $this->actor);
        $this->eraseAll();
        $batchRows = array_values(array_filter((new \MyInvoice\Repository\Nonprofit\Membership\MembershipBatchRepository($this->db))->page($this->supplier, $batch, 0),
            static fn (array $r): bool => $r['member_id'] === $member));
        self::assertSame(['vymazano-' . $member, null], [$batchRows[0]['member_number'], $batchRows[0]['company_name']]);

        $contact = $this->pdo->query('SELECT company_name FROM clients WHERE id=' . $client)->fetchColumn();
        self::assertSame('Synthetic batch member', $contact, 'Kontakt drží jiný doklad, zůstává.');
        $item = $this->doneItem($member);
        self::assertSame(['retained', ['invoice_counters']], [$item['counts']['contact'], $item['counts']['contact_other_uses']]);

        $list = new MembershipListRepository($this->db);
        $row = array_values(array_filter($list->page($this->supplier, 0, 100, ['search' => '', 'title_key' => '', 'year' => 2026, 'payment_status' => 'all']), static fn (array $r): bool => $r['id'] === $member))[0];
        self::assertTrue($row['erased']);
        self::assertNull($row['company_name'], 'Seznam vymazaného člena jméno kontaktu nevrací.');
        $found = $list->page($this->supplier, 0, 100, ['search' => 'Synthetic batch member', 'title_key' => '', 'year' => 2026, 'payment_status' => 'all']);
        self::assertNotContains($member, array_column($found, 'id'), 'Podle jména sdíleného kontaktu vymazaného člena najít nejde.');
    }

    public function testChangedMemberIsSkippedWithReason(): void
    {
        $member = $this->ended('SKIP');
        $proposal = $this->erasure->create($this->supplier, $this->actor, null);
        $this->erasure->approve($this->supplier, $proposal['id'], $this->actor);
        $this->endCoolingOff($proposal['id']);
        // Po schválení se člen vrátil: výmaz ho nesmí provést podle zastaralého rozhodnutí.
        $this->members->appendRevision($this->supplier, $member, ['status' => 'active', 'effective_from' => '2031-09-01', 'expected_revision' => 2,
            'idempotency_key' => 'skip-back', 'legal_basis' => $this->basis()], $this->actor);
        $result = $this->erasure->execute($this->supplier, $proposal['id'], $this->actor, MembershipErasureService::EXECUTE_CONFIRMATION);

        self::assertSame(['done' => 0, 'skipped_changed' => 1, 'skipped_hold' => 0], $result['summary']);
        $item = $this->itemOf($this->erasures->items($this->supplier, $proposal['id']), $member);
        self::assertSame(['skipped_changed', 'not_ended'], [$item['outcome'], $item['skip_reason']]);
        self::assertSame('SYN-SKIP', $this->repository->member($this->supplier, $member)['member_number']);
        self::assertNull($this->repository->history($this->supplier, $member)[0]['erasure_item_id']);
    }

    public function testDeletedMemberAuditIsRedactedAfterOneYear(): void
    {
        $bare = $this->bareFor('DELETED');
        $client = (int) $this->repository->member($this->supplier, $bare)['client_id'];
        $this->corrections->delete($this->supplier, $bare, $this->actor, ['client_id' => $client, 'member_number' => 'SYN-DELETED']);
        $deletedOn = (string) $this->pdo->query("SELECT DATE(created_at) FROM activity_log WHERE entity_id={$bare} AND action='nonprofit_membership_member_delete'")->fetchColumn();
        $this->today = (new \DateTimeImmutable($deletedOn))->modify('+1 year')->format('Y-m-d');
        self::assertSame('within_retention', $this->deleted($bare)['blocked_by']);
        $this->today = (new \DateTimeImmutable($deletedOn))->modify('+1 year +1 day')->format('Y-m-d');
        self::assertTrue($this->deleted($bare)['erasable']);

        $this->eraseAll();
        $payload = $this->auditPayloads($bare)[0];
        self::assertSame(['client_id' => null, 'member_number' => MembershipErasureService::REDACTED, 'rejected_batch_ids' => []], $payload);
        self::assertSame('done', $this->doneItem($bare, 'deleted_member')['outcome']);
        // Vymazaná stopa se do posouzení nevrací vůbec (jinak by vymazaní zabírali limit stránky).
        self::assertNotContains($bare, array_column($this->erasure->assessment($this->supplier, 0, 100)['deleted_members'], 'member_id'));
    }

    public function testErasedMemberRejectsFurtherChanges(): void
    {
        $member = $this->ended('LOCKED');
        $this->eraseAll();
        $this->assertRejected('nonprofit_membership_member_erased', fn () => $this->members->appendRevision($this->supplier, $member,
            ['status' => 'active', 'effective_from' => '2032-01-01', 'expected_revision' => 2, 'idempotency_key' => 'locked-back', 'legal_basis' => $this->basis()], $this->actor));
        $this->assertRejected('nonprofit_membership_member_erased', fn () => $this->charges->previewIssue($this->supplier, ['member_id' => $member] + $this->template));
        $row = $this->repository->member($this->supplier, $member);
        $this->assertRejected('nonprofit_membership_member_erased', fn () => $this->corrections->updateIdentity($this->supplier, $member,
            ['client_id' => $row['client_id'], 'member_number' => 'SYN-LOCKED-2', 'expected_client_id' => $row['client_id'], 'expected_member_number' => $row['member_number']], $this->actor));
        $availability = $this->corrections->availability($this->supplier, $row, $this->repository->history($this->supplier, $member));
        self::assertSame([false, false, null], [$availability['can_edit_identity'], $availability['can_delete'], $availability['voidable_revision_id']]);
        $this->assertRejected('nonprofit_membership_member_number_reserved', fn () => $this->members->create($this->supplier, $this->client('reserved'), 'VYMAZANO-1', $this->actor));
    }

    public function testProposalLifecycleRevokeRejectAndSingleOpenProposal(): void
    {
        $this->ended('LIFE');
        $proposal = $this->erasure->create($this->supplier, $this->actor, null);
        $this->assertRejected('nonprofit_membership_erasure_open', fn () => $this->erasure->create($this->supplier, $this->actor, null));
        $approved = $this->erasure->approve($this->supplier, $proposal['id'], $this->actor);
        self::assertNotNull($approved['executable_from']);
        $revoked = $this->erasure->revoke($this->supplier, $proposal['id'], $this->actor);
        self::assertSame(['pending', null], [$revoked['status'], $revoked['approved_by']]);
        $rejected = $this->erasure->reject($this->supplier, $proposal['id'], $this->actor);
        self::assertSame('rejected', $rejected['status']);
        $this->assertRejected('nonprofit_membership_erasure_state', fn () => $this->erasure->approve($this->supplier, $proposal['id'], $this->actor));
        $next = $this->erasure->create($this->supplier, $this->actor, null);
        self::assertNotNull($next, 'Po zamítnutí jde sestavit nový návrh.');

        // Rozběhnutý výmaz (zpracovaná položka) už vzít zpět nejde.
        $this->erasure->approve($this->supplier, $next['id'], $this->actor);
        $item = $this->erasures->items($this->supplier, $next['id'])[0];
        $this->erasures->finishItem($this->supplier, $item['id'], 'skipped_changed', 'not_ended', []);
        $this->assertRejected('nonprofit_membership_erasure_started', fn () => $this->erasure->revoke($this->supplier, $next['id'], $this->actor));
        // Přeskočený člen ve schváleném návrhu zůstává se jménem: údaje mu zůstaly a uživatel musí vědět koho.
        self::assertSame('SYN-LIFE', $this->erasures->items($this->supplier, $next['id'])[0]['member_number']);
    }

    public function testUnexpectedFailureSkipsOnlyThatMemberAndApprovedProposalCanBeClosed(): void
    {
        $broken = $this->ended('TECH');
        $fine = $this->ended('TECH-OK');
        // Neočekávaná chyba databáze uprostřed výmazu (po zapečetění, před dokončením položky).
        $real = new MembershipContactErasure($this->db);
        $contacts = $this->createStub(MembershipContactErasure::class);
        $contacts->method('erase')->willReturnCallback(static function (int $supplier, int $client, int $member) use ($real, $broken): array {
            if ($member === $broken) throw new \PDOException('Synthetic database failure');
            return $real->erase($supplier, $client, $member);
        });
        $proposal = $this->erasure->create($this->supplier, $this->actor, null);
        $this->erasure->approve($this->supplier, $proposal['id'], $this->actor);
        $this->endCoolingOff($proposal['id']);
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with('Membership erasure item failed', self::callback(static fn (array $c): bool => $c['exception'] instanceof \PDOException));
        $assessment = new MembershipErasureAssessment($this->db, new MembershipListRepository($this->db), new RetentionHoldRepository($this->db), $this->erasures);
        $logged = new MembershipErasureService($this->db, $this->repository, $this->chargeRepository, $this->erasures, $assessment,
            $contacts, new ActivityLogger($this->db), new ActivityLogHashChain($this->db), $logger, fn (): string => $this->today);
        $result = $logged->execute($this->supplier, $proposal['id'], $this->actor, MembershipErasureService::EXECUTE_CONFIRMATION);

        self::assertSame(['done' => 1, 'skipped_changed' => 1, 'skipped_hold' => 0], $result['summary']);
        $item = $this->itemOf($this->erasures->items($this->supplier, $proposal['id']), $broken);
        self::assertSame(['skipped_changed', 'technical_error'], [$item['outcome'], $item['skip_reason']]);
        self::assertSame('SYN-TECH', $this->repository->member($this->supplier, $broken)['member_number'], 'Změny přeskočeného člena se vrátily.');
        self::assertNull($this->repository->history($this->supplier, $broken)[0]['erasure_item_id']);
        self::assertSame('vymazano-' . $fine, $this->repository->member($this->supplier, $fine)['member_number']);

        // Schválený návrh, který nejde dokončit, jde ukončit; provedené položky zůstávají dokladem.
        $other = $this->ended('TECH-LATER');
        $next = $this->erasure->create($this->supplier, $this->actor, null);
        self::assertContains($other, array_column($this->erasures->items($this->supplier, $next['id']), 'member_id'));
        $this->erasure->approve($this->supplier, $next['id'], $this->actor);
        self::assertSame('rejected', $this->erasure->reject($this->supplier, $next['id'], $this->actor)['status']);
        self::assertNotNull($this->erasure->create($this->supplier, $this->actor, null), 'Ukončený návrh uvolní členy pro další návrh.');
    }

    /** Čekání na zámek při redakci auditu není „neprůkazný audit“: chyba jde vlastníkovi transakce k opakování. */
    public function testLockWaitDuringAuditRedactionIsNotTurnedIntoSkip(): void
    {
        $member = $this->ended('LOCK', voidReason: 'Synthetic void reason');
        $proposal = $this->erasure->create($this->supplier, $this->actor, null);
        $this->erasure->approve($this->supplier, $proposal['id'], $this->actor);
        $this->endCoolingOff($proposal['id']);
        // Řetěz, jehož redakce čeká na zámek déle, než server dovolí (1205 při innodb_rollback_on_timeout=OFF).
        $chain = $this->createStub(ActivityLogHashChain::class);
        $chain->method('redact')->willReturnCallback(static function (): never {
            $e = new \PDOException('SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded');
            $e->errorInfo = ['HY000', 1205, 'Lock wait timeout exceeded'];
            throw $e;
        });
        $assessment = new MembershipErasureAssessment($this->db, new MembershipListRepository($this->db), new RetentionHoldRepository($this->db), $this->erasures);
        $erasure = new MembershipErasureService($this->db, $this->repository, $this->chargeRepository, $this->erasures, $assessment,
            new MembershipContactErasure($this->db), new ActivityLogger($this->db), $chain, new \Psr\Log\NullLogger(), fn (): string => $this->today);
        try {
            $erasure->execute($this->supplier, $proposal['id'], $this->actor, MembershipErasureService::EXECUTE_CONFIRMATION);
            self::fail('Čekání na zámek se ztratilo.');
        } catch (\PDOException $e) {
            self::assertSame(1205, (int) ($e->errorInfo[1] ?? 0));
        }
        self::assertSame('pending', $this->itemOf($this->erasures->items($this->supplier, $proposal['id']), $member)['outcome'], 'Položka nezůstala trvale přeskočená.');
    }

    public function testApprovalByAnotherUserHasNoCoolingOff(): void
    {
        $this->ended('OTHER-APPROVER');
        $proposal = $this->erasure->create($this->supplier, $this->actor, null);
        $this->pdo->prepare("INSERT INTO users(email,password_hash,name,role,locale,is_active) VALUES(?,?,'Synthetic approver','admin','cs',1)")
            ->execute(['erasure-approver-' . $this->supplier . '@example.test', password_hash('Synthetic unused password', PASSWORD_BCRYPT, ['cost' => 4])]);
        $approver = (int) $this->pdo->lastInsertId();
        $this->erasure->approve($this->supplier, $proposal['id'], $approver);
        $result = $this->erasure->execute($this->supplier, $proposal['id'], $approver, MembershipErasureService::EXECUTE_CONFIRMATION);
        self::assertSame(1, $result['summary']['done']);
    }

    /** Ukončený člen: aktivní od 1. 1. 2026, ukončený 30. 6. 2026; volitelně předpis (otevřený nebo stornovaný). */
    private function ended(string $number, ?string $charge = null, ?string $voidReason = null): int
    {
        $member = $this->member($number);
        $basis = ['reference' => self::PERSONAL, 'evidence' => 'Synthetic signature of Jana Testovaci'] + $this->basis();
        if ($voidReason !== null) {
            $wrong = $this->members->appendRevision($this->supplier, $member, ['status' => 'suspended', 'effective_from' => '2026-03-01', 'expected_revision' => 1,
                'idempotency_key' => 'wrong-' . $number, 'legal_basis' => $basis], $this->actor);
            $this->corrections->voidRevision($this->supplier, $member, $wrong['id'], ['expected_revision' => 2, 'reason' => $voidReason, 'idempotency_key' => 'void-' . $number], $this->actor);
        }
        if ($charge !== null) {
            // Podklad titulu předpisu je volný text; může nést osobní údaj (přihláška člena).
            $request = ['member_id' => $member, 'title_reference' => 'Synthetic application of Jana Testovaci', 'variable_symbol' => '0012345678'] + $this->template;
            $preview = $this->charges->previewIssue($this->supplier, $request);
            $issued = $this->charges->confirmIssue($this->supplier, $request, $preview['expected_case_version'], $preview['preview_hash'], 'issue-' . $number, $this->actor);
            if ($charge === 'reverse') {
                $reverse = ['case_id' => $issued['case']['id'], 'accounting_date' => '2026-03-01', 'reason' => 'Synthetic reversal requested by Jana Testovaci'];
                $preview = $this->charges->previewReverse($this->supplier, $reverse);
                $this->charges->confirmReverse($this->supplier, $reverse, $preview['expected_case_version'], $preview['preview_hash'], 'reverse-' . $number, $this->actor);
            }
        }
        $head = $this->repository->history($this->supplier, $member);
        $this->members->appendRevision($this->supplier, $member, ['status' => 'ended', 'effective_from' => '2026-06-30', 'expected_revision' => $head[array_key_last($head)]['revision_no'],
            'idempotency_key' => 'end-' . $number, 'legal_basis' => $basis], $this->actor);
        return $member;
    }

    private function bareFor(string $number): int
    {
        return $this->members->create($this->supplier, $this->client(strtolower($number)), 'SYN-' . $number, $this->actor)['id'];
    }

    private function client(string $suffix): int
    {
        $q = $this->pdo->prepare('SELECT default_currency_id FROM supplier WHERE id=?');
        $q->execute([$this->supplier]);
        $this->pdo->prepare("INSERT INTO clients(supplier_id,company_name,street,city,zip,country_id,main_email,language,currency_default_id,is_customer,is_vendor) VALUES(?,'Synthetic erased contact','Synthetic street','Synthetic city','10000',203,?,'cs',?,1,0)")
            ->execute([$this->supplier, $suffix . '@example.test', (int) $q->fetchColumn()]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<int,array<string,mixed>> */
    private function assess(): array
    {
        $out = [];
        foreach ($this->erasure->assessment($this->supplier, 0, 500)['items'] as $item) $out[$item['member_id']] = $item;
        return $out;
    }

    private function deleted(int $member): array
    {
        foreach ($this->erasure->assessment($this->supplier, 0, 500)['deleted_members'] as $item) if ($item['member_id'] === $member) return $item;
        self::fail('Smazaný člen v posouzení chybí.');
    }

    private function eraseAll(): array
    {
        $proposal = $this->erasure->create($this->supplier, $this->actor, null);
        self::assertNotNull($proposal);
        $this->erasure->approve($this->supplier, $proposal['id'], $this->actor);
        $this->endCoolingOff($proposal['id']);
        return $this->erasure->execute($this->supplier, $proposal['id'], $this->actor, MembershipErasureService::EXECUTE_CONFIRMATION);
    }

    private function endCoolingOff(int $proposal): void
    {
        $this->pdo->prepare('UPDATE nonprofit_membership_erasure_proposals SET executable_from=UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE id=?')->execute([$proposal]);
    }

    /** Úhrada předpisu z banky v jiném období (vzor MembershipNativeTest::payClaims). */
    private function payClaim(array $issued, string $amount, string $date): void
    {
        $year = (int) substr($date, 0, 4);
        $container = $this->fixtureContainer();
        if ((int) $this->pdo->query("SELECT COUNT(*) FROM accounting_periods WHERE supplier_id={$this->supplier} AND fiscal_year={$year}")->fetchColumn() === 0) {
            $container->get(\MyInvoice\Repository\AccountingPeriodRepository::class)->create($this->supplier, $year, $year . '-01-01', $year . '-12-31');
        }
        $this->pdo->prepare("INSERT IGNORE INTO chart_of_accounts(supplier_id,account_code,name,account_type) VALUES(?,'221','Synthetic bank','asset')")->execute([$this->supplier]);
        $claim = $this->chargeRepository->claimForOperation($this->supplier, $issued['operation']['id']);
        $target = (new \MyInvoice\Service\Nonprofit\Membership\MembershipReceivablePaymentAdapter(new \MyInvoice\Repository\Nonprofit\Membership\MembershipBalanceRepository($this->db)))
            ->target($this->supplier, $claim['id']);
        $this->pdo->prepare("INSERT INTO bank_statements(supplier_id,file_name,file_hash,account_number,bank_code,currency,statement_date,imported_by) VALUES(?,'synthetic-erasure.gpc',?,'1000000005','0100','CZK',?,?)")
            ->execute([$this->supplier, hash('sha256', 'synthetic erasure payment ' . $date . $this->supplier), $date, $this->actor]);
        $statement = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO bank_transactions(statement_id,posted_at,amount,currency,description,match_status) VALUES(?,?,?,'CZK','Synthetic member payment','manual')")
            ->execute([$statement, $date, $amount]);
        $transaction = (int) $this->pdo->lastInsertId();
        $entry = $container->get(\MyInvoice\Service\Accounting\PostingService::class)->postDocument($this->supplier, 'bank', $transaction, [
            ['account_code' => '221', 'side' => 'debit', 'amount' => $amount], ['account_code' => '315.100', 'side' => 'credit', 'amount' => $amount],
        ], ['entry_date' => $date, 'description' => 'Synthetic member payment', 'posted' => true, 'posted_by' => $this->actor]);
        $q = $this->pdo->prepare('SELECT line_no FROM journal_entry_lines WHERE supplier_id=? AND entry_id=? AND account_id=?');
        $q->execute([$this->supplier, $entry, $target['journal_line']['account_id']]);
        (new \MyInvoice\Repository\JournalLinePairingRepository($this->db))->create($this->supplier, $target['journal_line']['account_id'], [
            $target['journal_line'], ['entry_id' => $entry, 'line_no' => (int) $q->fetchColumn(), 'account_id' => $target['journal_line']['account_id']],
        ], 'Synthetic member payment', 'manual', $this->actor);
    }

    private function duesStatus(int $member): string
    {
        [$sql, $params] = (new MembershipListRepository($this->db))->query($this->supplier, 0, 501, ['search' => '', 'title_key' => '', 'year' => null, 'payment_status' => 'all'], [$member]);
        $q = $this->pdo->prepare($sql);
        $q->execute($params);
        return (string) $q->fetch(PDO::FETCH_ASSOC)['dues_status'];
    }

    private function assertImmutable(callable $work): void
    {
        try {
            $work();
            self::fail('Trigger změnu pustil.');
        } catch (\PDOException $e) {
            self::assertStringContainsString('nonprofit_membership_immutable', $e->getMessage());
        }
    }

    private function itemOf(array $items, int $member): array
    {
        foreach ($items as $item) if ($item['member_id'] === $member) return $item;
        self::fail('Položka návrhu chybí.');
    }

    private function doneItem(int $member, string $subject = 'member'): array
    {
        $q = $this->pdo->prepare('SELECT proposal_id FROM nonprofit_membership_erasure_items WHERE supplier_id=? AND member_id=? AND subject=? ORDER BY id DESC LIMIT 1');
        $q->execute([$this->supplier, $member, $subject]);
        return $this->itemOf($this->erasures->items($this->supplier, (int) $q->fetchColumn()), $member);
    }

    private function assertRejected(string $code, callable $work): void
    {
        try {
            $work();
            self::fail('Očekávaná chyba ' . $code . ' nenastala.');
        } catch (MembershipException $e) {
            self::assertSame($code, $e->errorCode);
        }
    }

    private function operationIds(int $member): array
    {
        $q = $this->pdo->prepare('SELECT o.id FROM nonprofit_membership_charge_operations o JOIN nonprofit_membership_charge_cases c ON c.id=o.case_id WHERE c.supplier_id=? AND c.member_id=? ORDER BY o.id');
        $q->execute([$this->supplier, $member]);
        return array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
    }

    private function j6Facts(array $operationIds): array
    {
        return (new MembershipJ6SourceFacts($this->db))->capture($this->supplier, $this->j6Rows($operationIds), false);
    }

    /** Řádky stránky J6 (výnosové řádky předpisů a storen) pro dané operace. */
    private function j6Rows(array $operationIds): array
    {
        $rows = [];
        foreach ($operationIds as $id) {
            $operation = $this->chargeRepository->operation($this->supplier, $id);
            $axes = [];
            foreach ($operation['proof']['classification'] as $axis => $proof) $axes[$axis] = ['type_id' => $proof['type_id'], 'value_id' => $proof['value_id']];
            $rows[] = ['line_id' => (int) $operation['revenue_line_id'], 'entry_id' => (int) $operation['journal_entry_id'], 'date' => $operation['accounting_date'],
                'amount_cents' => $operation['operation'] === 'issue' ? (int) $operation['amount_cents'] : -(int) $operation['amount_cents'],
                'kind' => 'revenue', 'source_type' => 'nonprofit_membership', 'axes' => $axes];
        }
        return $rows;
    }

    private function journalSnapshot(): string
    {
        $q = $this->pdo->prepare('SELECT e.id,e.entry_date,e.description,l.account_id,l.side,l.amount FROM journal_entries e JOIN journal_entry_lines l ON l.entry_id=e.id WHERE e.supplier_id=? ORDER BY e.id,l.id');
        $q->execute([$this->supplier]);
        return json_encode($q->fetchAll(PDO::FETCH_ASSOC), JSON_THROW_ON_ERROR);
    }

    /** Všechny řádky členské evidence člena jako text (revize, případy, operace, příkazy, nároky). */
    private function memberDump(int $member): string
    {
        $parts = [];
        foreach (['SELECT * FROM nonprofit_members WHERE id=?', 'SELECT * FROM nonprofit_member_revisions WHERE member_id=?', 'SELECT * FROM nonprofit_membership_charge_cases WHERE member_id=?',
            'SELECT o.* FROM nonprofit_membership_charge_operations o JOIN nonprofit_membership_charge_cases c ON c.id=o.case_id WHERE c.member_id=?',
            'SELECT m.* FROM nonprofit_membership_charge_commands m JOIN nonprofit_membership_charge_operations o ON o.command_id=m.id JOIN nonprofit_membership_charge_cases c ON c.id=o.case_id WHERE c.member_id=?'] as $sql) {
            $q = $this->pdo->prepare($sql);
            $q->execute([$member]);
            $parts[] = json_encode($q->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }
        return implode("\n", $parts);
    }

    private function auditPayloads(int $member): array
    {
        $q = $this->pdo->prepare("SELECT payload FROM activity_log WHERE supplier_id=? AND entity_type='nonprofit_member' AND entity_id=? ORDER BY id");
        $q->execute([$this->supplier, $member]);
        return array_map(static fn (string $p): array => json_decode($p, true, flags: JSON_THROW_ON_ERROR), $q->fetchAll(PDO::FETCH_COLUMN));
    }
}
