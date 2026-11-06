<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Nonprofit;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\Nonprofit\Membership\MembershipChargeRepository;
use MyInvoice\Repository\Nonprofit\Membership\MembershipRepository;
use MyInvoice\Service\Accounting\Dimension\SystemDimensionCatalog;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Anonymization\AccountDefinitionCloneScrubber;
use MyInvoice\Service\Anonymization\DatabaseCloner;
use MyInvoice\Service\Anonymization\FinancialCloneHashKind;
use MyInvoice\Service\Anonymization\FinancialCloneHashMap;
use MyInvoice\Service\Anonymization\MembershipCloneCapture;
use MyInvoice\Service\Anonymization\MembershipCloneScrubber;
use MyInvoice\Service\Anonymization\Nonprofit\J6CloneLegacyInputTransformer;
use MyInvoice\Service\Anonymization\Nonprofit\J6CloneTypedTransformer;
use MyInvoice\Service\Anonymization\Nonprofit\J6MembershipModuleFactRebaser;
use MyInvoice\Service\Anonymization\NonprofitDppoCloneScrubber;
use MyInvoice\Service\Anonymization\Pseudonymizer;
use MyInvoice\Service\Anonymization\TextScrubber;
use MyInvoice\Service\Nonprofit\Membership\MembershipChargeService;
use MyInvoice\Service\Nonprofit\Membership\MembershipClassification;
use MyInvoice\Service\Nonprofit\Membership\MembershipJournalProof;
use MyInvoice\Service\Nonprofit\Membership\MembershipMemberService;
use MyInvoice\Service\Tax\Nonprofit\NonprofitLegalProfileService;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;
use MyInvoice\Service\Tax\Nonprofit\SourceFacts\MembershipJ6SourceFacts;
use MyInvoice\Tests\Support\OwnedTestDatabaseCleanup;
use PDO;
use PHPUnit\Framework\TestCase;
use MyInvoice\Tests\Support\CloneSandbox;

final class MembershipCloneScrubberTest extends TestCase
{
    private array $classification;
    public function testCloneRebasesEveryProofWithoutChangingFinancialFactsOrHistoricalTitle(): void
    {
        $container = Bootstrap::buildContainer();
        $source = $container->get(Connection::class);
        $sourceSchema = (string) $source->pdo()->query('SELECT DATABASE()')->fetchColumn();
        self::assertStringEndsWith('_test', $sourceSchema);
        $sourceBefore = $this->sourceCounts($source);
        $config = $container->get(Config::class);
        self::assertContains($config->get('db.host'), ['localhost', '127.0.0.1', '::1']);
        $server = new PDO('mysql:host=' . $config->get('db.host') . ';port=' . $config->get('db.port', 3306) . ';charset=utf8mb4',
            $config->get('db.user'), $config->get('db.pass', ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $schema = CloneSandbox::acquire($server, $sourceSchema);
        $target = null;
        $hashMap = null;
        try {
            $oldName = getenv('MYINVOICE_DB_NAME');
            $oldMysqlName = getenv('MYSQL_DATABASE');
            putenv('MYINVOICE_DB_NAME=' . $schema);
            putenv('MYSQL_DATABASE=' . $schema);
            try {
                $targetContainer = Connection::withoutSharedTestConnection(fn () => Bootstrap::buildContainer());
                $target = $targetContainer->get(Connection::class);
                $posting = $targetContainer->get(PostingService::class);
            } finally {
                $oldName === false ? putenv('MYINVOICE_DB_NAME') : putenv('MYINVOICE_DB_NAME=' . $oldName);
                $oldMysqlName === false ? putenv('MYSQL_DATABASE') : putenv('MYSQL_DATABASE=' . $oldMysqlName);
            }
            self::assertSame($schema, $target->pdo()->query('SELECT DATABASE()')->fetchColumn());
            self::assertSame(0, (int) $target->pdo()->query('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()')->fetchColumn());
            $hashMap = FinancialCloneHashMap::create($target->pdo());
            [$supplier, $memberId, $period, $actor, $charges, $members] = $this->fixture($target, $posting);
            $request = $this->chargeRequest($memberId);
            $preview = $charges->previewIssue($supplier, $request);
            $issued = $charges->confirmIssue($supplier, $request, $preview['expected_case_version'], $preview['preview_hash'], 'private-issue', $actor);
            $members->appendRevision($supplier, $memberId, ['status' => 'suspended', 'effective_from' => '2027-01-01', 'expected_revision' => 1,
                'idempotency_key' => 'private-suspend', 'legal_basis' => $this->basis('private-suspend@example.test')], $actor);
            $members->appendRevision($supplier, $memberId, ['status' => 'active', 'effective_from' => '2027-06-01', 'expected_revision' => 2,
                'idempotency_key' => 'private-reactivate', 'legal_basis' => $this->basis('private-reactivate@example.test')], $actor);
            $members->appendRevision($supplier, $memberId, ['status' => 'ended', 'effective_from' => '2028-01-01', 'expected_revision' => 3,
                'idempotency_key' => 'private-ended', 'legal_basis' => $this->basis('private-ended@example.test')], $actor);
            $replay = $charges->confirmIssue($supplier, $request, $preview['expected_case_version'], $preview['preview_hash'], 'private-issue', $actor);
            self::assertTrue($replay['idempotent_replay']);
            self::assertSame($issued['operation']['id'], $replay['operation']['id']);
            self::assertNull($issued['operation']['proof']['membership_intervals'][0]['effective_to']);
            $reverseRequest = ['case_id' => $issued['case']['id'], 'accounting_date' => '2026-12-31', 'reason' => 'Private reversal private-reversal@example.test'];
            $reversePreview = $charges->previewReverse($supplier, $reverseRequest);
            $reversed = $charges->confirmReverse($supplier, $reverseRequest, $reversePreview['expected_case_version'], $reversePreview['preview_hash'], 'private-reverse', $actor);
            $oldClaim = (new MembershipChargeRepository($target))->claimForOperation($supplier, $issued['operation']['id']);
            self::assertNotNull($oldClaim);
            $oldHashes = [
                'claim' => $oldClaim['record_hash'],
                'revision' => $issued['operation']['proof']['member_revision']['record_hash'],
                'legal' => $issued['operation']['proof']['legal_profile']['record_hash'],
                'operation' => $issued['operation']['record_hash'],
                'receipt' => NonprofitTaxRules::hash($issued['operation']['receipt']),
                'classification' => NonprofitTaxRules::hash($issued['operation']['proof']['classification']),
            ];
            $target->pdo()->beginTransaction();
            $oldSourceRows = [$this->membershipSourceRow($issued['operation']), $this->membershipSourceRow($reversed['operation'])];
            $oldFacts = (new MembershipJ6SourceFacts($target))->capture($supplier, $oldSourceRows, true);
            $target->pdo()->commit();
            foreach ($oldSourceRows as &$oldSourceRow) {
                $oldSourceRow['module_facts'] = [MembershipJ6SourceFacts::SCHEMA => $oldFacts[$oldSourceRow['line_id']]];
            }
            unset($oldSourceRow);

            [$smallCapture, $smallQueries] = $this->captureWithQueryCount($target);
            if ($smallCapture instanceof MembershipCloneCapture) $smallCapture->cleanup();
            $lastExtra = null;
            for ($index = 1; $index <= 4; ++$index) {
                $extra = $this->chargeRequest($memberId);
                $extra['title_key'] = 'extra-' . $index;
                $extra['reason'] = 'Private batch charge ' . $index;
                $extraPreview = $charges->previewIssue($supplier, $extra);
                $lastExtra = $charges->confirmIssue($supplier, $extra, $extraPreview['expected_case_version'], $extraPreview['preview_hash'], 'private-extra-' . $index, $actor);
            }
            self::assertIsArray($lastExtra);
            $proofRepository = new MembershipRepository($target);
            $legacyHistory = $proofRepository->history($supplier, $memberId);
            $legacyRevision = $legacyHistory[array_key_last($legacyHistory)];
            $legacyRevision['recorded_at_utc'] = null;
            $legacyRevisionHash = NonprofitTaxRules::hash(MembershipRepository::revisionRecord($legacyRevision));
            $target->pdo()->prepare('UPDATE nonprofit_member_revisions SET recorded_at_utc=NULL,record_hash=? WHERE supplier_id=? AND id=?')
                ->execute([$legacyRevisionHash, $supplier, $legacyRevision['id']]);
            $proofCharges = new MembershipChargeRepository($target);
            $legacyClaim = $proofCharges->claimForOperation($supplier, $lastExtra['operation']['id']);
            self::assertNotNull($legacyClaim);
            $legacyClaim['recorded_at_utc'] = null;
            $legacyClaimHash = NonprofitTaxRules::hash(MembershipChargeRepository::claimRecord($legacyClaim));
            $target->pdo()->prepare('UPDATE nonprofit_membership_charge_claims SET recorded_at_utc=NULL,record_hash=? WHERE supplier_id=? AND id=?')
                ->execute([$legacyClaimHash, $supplier, $legacyClaim['id']]);
            [$membershipCapture, $manyQueries] = $this->captureWithQueryCount($target);
            self::assertLessThanOrEqual($smallQueries + 8, $manyQueries,
                'Capture acquired per-operation queries: one=' . $smallQueries . ', many=' . $manyQueries);
            self::assertInstanceOf(MembershipCloneCapture::class, $membershipCapture);
            self::assertLessThanOrEqual(500, $membershipCapture->maximumBatchRows());
            $dppoCapture = NonprofitDppoCloneScrubber::capture($target);
            $scrubber = new TextScrubber(new Pseudonymizer('synthetic-membership-clone'));
            AccountDefinitionCloneScrubber::run($target, $scrubber);
            $legalHashes = NonprofitDppoCloneScrubber::runProfiles($target, $scrubber, $dppoCapture);
            MembershipCloneScrubber::run($target, $scrubber, $membershipCapture, $legalHashes, $hashMap);

            $repository = new MembershipRepository($target);
            $history = $repository->history($supplier, $memberId);
            self::assertCount(4, $history);
            self::assertSame(['active', 'suspended', 'active', 'ended'], array_column($history, 'status'));
            self::assertNotNull($history[0]['recorded_at_utc']);
            self::assertNull($history[array_key_last($history)]['recorded_at_utc']);
            self::assertSame('clone-member-' . $memberId, $repository->member($supplier, $memberId)['member_number']);
            $chargeRepository = new MembershipChargeRepository($target);
            $operation = $chargeRepository->operation($supplier, $issued['operation']['id']);
            self::assertNotNull($operation);
            self::assertNull($operation['proof']['membership_intervals'][0]['effective_to']);
            self::assertSame($history[0]['record_hash'], $operation['proof']['member_revision']['record_hash']);
            self::assertSame('unclassified_requires_explicit_j6_decision', $operation['proof']['tax_treatment']);
            $rebasedClassification = (new MembershipClassification($target))->rebase($supplier, $operation['proof']['classification'], false);
            self::assertSame(NonprofitTaxRules::hash($rebasedClassification), NonprofitTaxRules::hash($operation['proof']['classification']));
            foreach ($operation['proof']['classification'] as $axis) self::assertSame(['origin' => 'explicit', 'ref' => null], $axis['origin']);
            $commandRequest = $target->pdo()->prepare('SELECT request_json FROM nonprofit_membership_charge_commands WHERE supplier_id=? AND id=?');
            $commandRequest->execute([$supplier, $operation['command_id']]);
            $command = json_decode((string) $commandRequest->fetchColumn(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(NonprofitTaxRules::hash([
                'activity_value_id' => $rebasedClassification['activity']['value_id'],
                'activity_kind_value_id' => $rebasedClassification['activity_kind']['value_id'],
                'tax_regime_value_id' => $rebasedClassification['tax_regime']['value_id'],
            ]), NonprofitTaxRules::hash($command['request']['classification']));
            foreach ($operation['receipt']['lines'] as $line) {
                self::assertSame(['dimensions', 'dimension_origins'], array_values(array_intersect(['dimensions', 'dimension_origins'], array_keys($line))));
                self::assertSame(array_fill_keys(array_keys($line['dimensions']), ['origin' => 'explicit', 'ref' => null]), $line['dimension_origins']);
            }
            (new MembershipJournalProof($target))->assertOperation($operation, false);
            $reversal = $chargeRepository->operation($supplier, $reversed['operation']['id']);
            self::assertSame($operation['id'], $reversal['original_operation_id']);
            self::assertSame($operation['record_hash'], $reversal['parent_hash']);
            self::assertSame($operation['id'], $reversal['proof']['original_operation_id']);
            self::assertNull($reversal['proof']['membership_intervals'][0]['effective_to']);
            (new MembershipJournalProof($target))->assertOperation($reversal, false);
            $claim = $chargeRepository->claimForOperation($supplier, $operation['id']);
            $reversalClaim = $chargeRepository->claimForOperation($supplier, $reversal['id']);
            self::assertSame(2750000, $claim['amount_cents']);
            $target->pdo()->beginTransaction();
            $mapped = $hashMap->resolveOwnedMany([
                ['kind' => FinancialCloneHashKind::MEMBERSHIP_CLAIM, 'supplier_id' => $supplier,
                    'owner_id' => $claim['id'], 'old_hash' => $oldHashes['claim']],
                ['kind' => FinancialCloneHashKind::MEMBERSHIP_REVISION, 'supplier_id' => $supplier,
                    'owner_id' => $operation['member_revision_id'], 'old_hash' => $oldHashes['revision']],
                ['kind' => FinancialCloneHashKind::MEMBERSHIP_LEGAL_PROFILE, 'supplier_id' => $supplier,
                    'owner_id' => $operation['legal_profile_id'], 'old_hash' => $oldHashes['legal']],
                ['kind' => FinancialCloneHashKind::MEMBERSHIP_OPERATION, 'supplier_id' => $supplier,
                    'owner_id' => $operation['id'], 'old_hash' => $oldHashes['operation']],
                ['kind' => FinancialCloneHashKind::MEMBERSHIP_JOURNAL_RECEIPT, 'supplier_id' => $supplier,
                    'owner_id' => $operation['id'], 'old_hash' => $oldHashes['receipt']],
                ['kind' => FinancialCloneHashKind::MEMBERSHIP_CLASSIFICATION, 'supplier_id' => $supplier,
                    'owner_id' => $operation['id'], 'old_hash' => $oldHashes['classification']],
            ]);
            self::assertSame([$claim['record_hash'], $operation['proof']['member_revision']['record_hash'],
                $operation['proof']['legal_profile']['record_hash'], $operation['record_hash'],
                NonprofitTaxRules::hash($operation['receipt']), NonprofitTaxRules::hash($operation['proof']['classification'])],
                array_values($mapped));
            $rebasedSources = (new J6MembershipModuleFactRebaser($supplier, $hashMap))->rebaseRows($oldSourceRows);
            $rebasedSource = $rebasedSources[0];
            $rebasedFact = $rebasedSource['module_facts'][MembershipJ6SourceFacts::SCHEMA];
            self::assertSame($oldSourceRows[0]['amount_cents'], $rebasedSource['amount_cents']);
            self::assertSame($oldSourceRows[0]['date'], $rebasedSource['date']);
            self::assertSame($operation['record_hash'], $rebasedFact['operation_record_hash']);
            self::assertSame($operation['proof']['member_revision']['record_hash'], $rebasedFact['member_revision_hash']);
            self::assertSame($operation['proof']['legal_profile']['record_hash'], $rebasedFact['legal_profile_hash']);
            $rebasedReversalFact = $rebasedSources[1]['module_facts'][MembershipJ6SourceFacts::SCHEMA];
            self::assertSame(-2750000, $rebasedSources[1]['amount_cents']);
            self::assertSame($reversal['record_hash'], $rebasedReversalFact['operation_record_hash']);

            $legacyRows = array_map(static function (array $row): array {
                $row['id'] = 'line:' . $row['line_id'];
                $row['activity'] = 'main';
                $row['activity_kind_id'] = $row['axes']['activity_kind']['value_id'];
                $row['tax_regime'] = 'subject';
                $row['mandatory_non_deductible'] = false;
                return $row;
            }, $oldSourceRows);
            $legacy = new J6CloneLegacyInputTransformer(new J6CloneTypedTransformer($scrubber,
                new J6MembershipModuleFactRebaser($supplier, $hashMap)));
            $selectBefore = (int) $target->pdo()->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetch(PDO::FETCH_NUM)[1];
            $legacyRebased = $legacy->transformPage('source', $legacyRows);
            $selectAfter = (int) $target->pdo()->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetch(PDO::FETCH_NUM)[1];
            self::assertLessThanOrEqual(2, $selectAfter - $selectBefore);
            self::assertSame(array_column($legacyRows, 'amount_cents'), array_column($legacyRebased, 'amount_cents'));
            self::assertSame($operation['record_hash'], $legacyRebased[0]['module_facts'][MembershipJ6SourceFacts::SCHEMA]['operation_record_hash']);
            $operationHashPath = ['module_facts', MembershipJ6SourceFacts::SCHEMA, 'operation_record_hash'];
            self::assertTrue($legacy->permitsIdentityChange('source', $operationHashPath,
                $legacyRows[0]['module_facts'][MembershipJ6SourceFacts::SCHEMA]['operation_record_hash'],
                $legacyRebased[0]['module_facts'][MembershipJ6SourceFacts::SCHEMA]['operation_record_hash']));
            self::assertFalse($legacy->permitsIdentityChange('source', $operationHashPath,
                $legacyRows[0]['module_facts'][MembershipJ6SourceFacts::SCHEMA]['operation_record_hash'],
                $legacyRebased[0]['module_facts'][MembershipJ6SourceFacts::SCHEMA]['operation_record_hash']));
            try {
                (new J6CloneLegacyInputTransformer(new J6CloneTypedTransformer($scrubber,
                    new J6MembershipModuleFactRebaser($supplier + 1, $hashMap))))->transformPage('source', $legacyRows);
                self::fail('Foreign tenant membership hash map must be rejected.');
            } catch (\LogicException $failure) {
                self::assertSame('J6 membership module fact nemá doloženou cílovou hash náhradu.', $failure->getMessage());
            }
            $target->pdo()->commit();
            self::assertSame($claim['id'], $reversalClaim['original_claim_id']);
            $clonedLegacyClaim = $chargeRepository->claimForOperation($supplier, $lastExtra['operation']['id']);
            self::assertNotNull($clonedLegacyClaim);
            self::assertNull($clonedLegacyClaim['recorded_at_utc']);
            self::assertSame($period, $operation['accounting_period_id']);
            $json = NonprofitTaxRules::canonicalJson(['history' => $history, 'operation' => $operation, 'reversal' => $reversal]);
            self::assertStringNotContainsString('private-member@example.test', $json);
            self::assertStringNotContainsString('private-title@example.test', $json);
            self::assertStringNotContainsString('private-ended@example.test', $json);
            self::assertStringNotContainsString('private-reversal@example.test', $json);
            self::assertSame($sourceBefore, $this->sourceCounts($source));
        } finally {
            if ($hashMap instanceof FinancialCloneHashMap) {
                if ($target instanceof Connection && $target->pdo()->inTransaction()) $target->pdo()->rollBack();
                $hashMap->drop();
            }
            if ($target instanceof Connection) $target->close();
            CloneSandbox::release($server, $schema);
        }
    }

    private function fixture(Connection $db, PostingService $posting): array
    {
        $pdo = $db->pdo();
        $vat = (int) $pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn();
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        $pdo->prepare("INSERT INTO supplier(company_name,street,city,zip,email,country_id,default_currency_id,default_vat_rate_id,accounting_mode) VALUES('Private membership association','Private street','Private city','10000','private-association@example.test',203,1,?,'double_entry')")->execute([$vat]);
        $supplier = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO currencies(supplier_id,code,label,symbol,name_cs,name_en,decimals,is_active,is_default,account_number,bank_code) VALUES(?,'CZK','Private CZK','Kč','Koruna','Crown',2,1,1,'1000000005','0100')")->execute([$supplier]);
        $currency = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE supplier SET default_currency_id=? WHERE id=?')->execute([$currency, $supplier]);
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        $pdo->prepare("INSERT INTO users(email,password_hash,name,role,locale,is_active) VALUES('private-actor@example.test',?,'Private actor','admin','cs',1)")
            ->execute([password_hash('Synthetic unused password', PASSWORD_BCRYPT, ['cost' => 4])]);
        $actor = (int) $pdo->lastInsertId();
        $period = (new AccountingPeriodRepository($db))->create($supplier, 2026, '2026-01-01', '2026-12-31');
        $pdo->prepare("INSERT INTO supplier_accounting_frameworks(supplier_id,effective_from,accounting_framework) VALUES(?,'2026-01-01','cz_504')")->execute([$supplier]);
        $axes = (new SystemDimensionCatalog($db))->ensureForFramework($supplier, '2026-01-01', $actor);
        $pdo->prepare("INSERT INTO dimension_values(type_id,supplier_id,code,name) VALUES(?,?,'MEMBERSHIP','Private membership activity')")
            ->execute([$axes['activity_kind']['id'], $supplier]);
        $this->classification = ['activity_value_id' => $axes['activity']['values']['main'],
            'activity_kind_value_id' => (int) $pdo->lastInsertId(),
            'tax_regime_value_id' => $axes['tax_regime']['values']['subject']];
        foreach (['315.100' => 'asset', '684.100' => 'revenue'] as $code => $type) {
            $pdo->prepare('INSERT INTO chart_of_accounts(supplier_id,account_code,name,account_type) VALUES(?,?,?,?)')
                ->execute([$supplier, $code, 'Private account private-account@example.test ' . $code, $type]);
        }
        (new NonprofitLegalProfileService($db))->confirm($supplier, $period, ['expected_revision' => 0, 'idempotency_key' => 'private-legal',
            'reason' => 'Private profile private-profile@example.test', 'profile' => ['kind' => 'narrow_vpp', 'entity_type' => 'association',
                'main_activity_nonbusiness' => true, 'legal_basis' => 'zdp_17a', 'evidence' => 'Private statutes private-statutes@example.test']], $actor);
        $pdo->prepare("INSERT INTO clients(supplier_id,company_name,street,city,zip,country_id,main_email,language,currency_default_id,is_customer,is_vendor) VALUES(?,'Private member','Private street','Private city','10000',203,'private-client@example.test','cs',?,1,0)")
            ->execute([$supplier, $currency]);
        $repository = new MembershipRepository($db);
        $members = new MembershipMemberService($db, $repository);
        $member = $members->create($supplier, (int) $pdo->lastInsertId(), 'PRIVATE-MEMBER-001', $actor);
        $members->appendRevision($supplier, $member['id'], ['status' => 'active', 'effective_from' => '2026-01-01', 'expected_revision' => 0,
            'idempotency_key' => 'private-active', 'legal_basis' => $this->basis('private-member@example.test')], $actor);
        $charges = new MembershipChargeService($db, $repository, new MembershipChargeRepository($db), $posting);
        return [$supplier, $member['id'], $period, $actor, $charges, $members];
    }

    private function basis(string $evidence): array
    {
        return ['organisation_form' => 'association', 'relationship' => 'nonreciprocal_membership',
            'reference' => 'Private statutes private-reference@example.test', 'evidence' => $evidence];
    }

    private function chargeRequest(int $member): array
    {
        return ['member_id' => $member, 'title_key' => 'annual-2026', 'period_from' => '2026-01-01', 'period_to' => '2026-12-31',
            'accounting_date' => '2026-01-01', 'due_date' => '2026-01-31', 'amount' => '27500.00',
            'receivable_account' => '315.100', 'revenue_account' => '684.100', 'title_reference' => 'Private title private-title@example.test',
            'evidence' => 'Private resolution private-resolution@example.test', 'nonreciprocal' => true, 'reason' => 'Private charge private-charge@example.test',
            'classification' => $this->classification];
    }

    private function membershipSourceRow(array $operation): array
    {
        $axes = [];
        foreach ($operation['proof']['classification'] as $axis => $proof) {
            $axes[$axis] = ['type_id' => $proof['type_id'], 'value_id' => $proof['value_id']];
        }
        return ['line_id' => $operation['revenue_line_id'], 'entry_id' => $operation['journal_entry_id'],
            'date' => $operation['accounting_date'],
            'amount_cents' => $operation['operation'] === 'issue' ? $operation['amount_cents'] : -$operation['amount_cents'],
            'kind' => 'revenue', 'source_type' => 'nonprofit_membership', 'axes' => $axes];
    }

    private function sourceCounts(Connection $source): array
    {
        $out = [];
        foreach (['nonprofit_members', 'nonprofit_member_revisions', 'nonprofit_membership_charge_cases', 'nonprofit_membership_charge_commands', 'nonprofit_membership_charge_operations', 'nonprofit_membership_charge_claims'] as $table) {
            $out[$table] = (int) $source->pdo()->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
        }
        return $out;
    }

    private function captureWithQueryCount(Connection $db): array
    {
        $pdo = $db->pdo();
        $before = (int) $pdo->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetch(PDO::FETCH_NUM)[1];
        $capture = MembershipCloneScrubber::capture($db);
        $after = (int) $pdo->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetch(PDO::FETCH_NUM)[1];
        return [$capture, $after - $before];
    }
}
