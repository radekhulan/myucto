<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Nonprofit;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\DimensionAssignmentRepository;
use MyInvoice\Repository\JournalLinePairingRepository;
use MyInvoice\Repository\Nonprofit\Membership\MembershipBalanceRepository;
use MyInvoice\Repository\Nonprofit\Membership\MembershipChargeRepository;
use MyInvoice\Repository\Nonprofit\Membership\MembershipRepository;
use MyInvoice\Service\Accounting\Dimension\SystemDimensionCatalog;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Nonprofit\Membership\MembershipChargeService;
use MyInvoice\Service\Nonprofit\Membership\MembershipMemberService;
use MyInvoice\Service\Nonprofit\Membership\MembershipPublicCatalog;
use MyInvoice\Service\Nonprofit\Membership\MembershipReceivablePaymentAdapter;
use MyInvoice\Service\Nonprofit\Membership\MembershipReconciliationService;
use MyInvoice\Service\Tax\Nonprofit\NonprofitLegalProfileService;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MembershipNativeTest extends TestCase
{
    private Connection $db;
    private PDO $pdo;
    private PostingService $posting;
    private MembershipMemberService $members;
    private MembershipChargeService $charges;
    private MembershipBalanceRepository $balances;
    private int $supplier;
    private int $period;
    private int $actor;
    private array $classification;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->pdo = $this->db->pdo();
        self::assertStringEndsWith('_test', (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn());
        $this->pdo->beginTransaction();
        $vat = (int) $this->pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn();
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        $this->pdo->prepare("INSERT INTO supplier(company_name,street,city,zip,email,country_id,default_currency_id,default_vat_rate_id,accounting_mode) VALUES('Synthetic membership association','Synthetic street','Synthetic city','10000','membership-association@example.test',203,1,?,'double_entry')")->execute([$vat]);
        $this->supplier = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO currencies(supplier_id,code,label,symbol,name_cs,name_en,decimals,is_active,is_default,account_number,bank_code) VALUES(?,'CZK','Synthetic CZK','Kč','Koruna','Crown',2,1,1,'1000000005','0100')")->execute([$this->supplier]);
        $currency = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('UPDATE supplier SET default_currency_id=? WHERE id=?')->execute([$currency, $this->supplier]);
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        $this->pdo->prepare("INSERT INTO users(email,password_hash,name,role,locale,is_active) VALUES(? ,?,'Synthetic membership actor','admin','cs',1)")
            ->execute(['membership-actor@example.test', password_hash('Synthetic unused password', PASSWORD_BCRYPT, ['cost' => 4])]);
        $this->actor = (int) $this->pdo->lastInsertId();
        $this->period = $container->get(AccountingPeriodRepository::class)->create($this->supplier, 2026, '2026-01-01', '2026-12-31');
        $this->pdo->prepare("INSERT INTO supplier_accounting_frameworks(supplier_id,effective_from,accounting_framework) VALUES(?,'2026-01-01','cz_504')")->execute([$this->supplier]);
        $axes = (new SystemDimensionCatalog($this->db))->ensureForFramework($this->supplier, '2026-01-01', $this->actor);
        $this->pdo->prepare("INSERT INTO dimension_values(type_id,supplier_id,code,name) VALUES(?,?,'MEMBERSHIP','Synthetic membership activity')")
            ->execute([$axes['activity_kind']['id'], $this->supplier]);
        $this->classification = ['activity_value_id' => $axes['activity']['values']['main'],
            'activity_kind_value_id' => (int) $this->pdo->lastInsertId(),
            'tax_regime_value_id' => $axes['tax_regime']['values']['subject']];
        foreach (['221' => 'asset', '315.100' => 'asset', '684.100' => 'revenue'] as $code => $type) {
            $this->pdo->prepare('INSERT INTO chart_of_accounts(supplier_id,account_code,name,account_type) VALUES(?,?,?,?)')
                ->execute([$this->supplier, $code, 'Synthetic ' . $code, $type]);
        }
        (new NonprofitLegalProfileService($this->db))->confirm($this->supplier, $this->period, [
            'expected_revision' => 0,
            'idempotency_key' => 'membership-legal',
            'reason' => 'Synthetic reviewed association statutes',
            'profile' => ['kind' => 'narrow_vpp', 'entity_type' => 'association', 'main_activity_nonbusiness' => true,
                'legal_basis' => 'zdp_17a', 'evidence' => 'Synthetic signed association statutes'],
        ], $this->actor);
        $repository = new MembershipRepository($this->db);
        $this->members = new MembershipMemberService($this->db, $repository);
        $this->posting = $container->get(PostingService::class);
        $this->charges = new MembershipChargeService($this->db, $repository, new MembershipChargeRepository($this->db), $this->posting);
        $this->balances = new MembershipBalanceRepository($this->db);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) $this->pdo->rollBack();
    }

    public function testReal504SystemAxesAcceptExplicitMembershipClassification(): void
    {
        $member = $this->member('M-DIM-RED', 'dimension-red');
        $request = $this->request($member['id']);
        $preview = $this->charges->previewIssue($this->supplier, $request);
        $issued = $this->charges->confirmIssue($this->supplier, $request, $preview['expected_case_version'], $preview['preview_hash'], 'dimension-red', $this->actor);

        self::assertSame('issue', $issued['operation']['operation']);
        self::assertSame($this->classification, [
            'activity_value_id' => $issued['operation']['proof']['classification']['activity']['value_id'],
            'activity_kind_value_id' => $issued['operation']['proof']['classification']['activity_kind']['value_id'],
            'tax_regime_value_id' => $issued['operation']['proof']['classification']['tax_regime']['value_id'],
        ]);
        foreach ($issued['operation']['receipt']['lines'] as $line) {
            self::assertCount(3, $line['dimensions']);
            self::assertSame(array_fill_keys(array_keys($line['dimensions']), ['origin' => 'explicit', 'ref' => null]), $line['dimension_origins']);
        }
    }

    public function testRateChangesAndRetirementDoNotRewriteFinancialProofOrReplay(): void
    {
        $rates = new \MyInvoice\Service\Nonprofit\Membership\MembershipRateService($this->db);
        $input = ['name' => 'Synthetic standard', 'amount' => '500.00', 'receivable_account' => '315.100', 'revenue_account' => '684.100',
            'title_key' => 'synthetic-rate', 'period_months' => 12, 'title_reference' => 'Synthetic resolution', 'evidence' => 'Synthetic evidence',
            'classification' => $this->classification, 'is_active' => true, 'row_version' => 0];
        $rate = $rates->save($this->supplier, null, $input);
        $member = $this->member('SYN-RATE', 'synthetic-rate');
        $request = $this->request($member['id']);
        $request['rate_id'] = $rate['id'];
        // Výslovná úprava předpisu se nesmí přepsat hodnotou 500 ze sazby.
        $request['amount'] = '625.00';
        $preview = $this->charges->previewIssue($this->supplier, $request);
        $input['amount'] = '750.00'; $input['name'] = 'Synthetic updated'; $input['row_version'] = 1;
        $rates->save($this->supplier, $rate['id'], $input);
        self::assertSame($preview['preview_hash'], $this->charges->previewIssue($this->supplier, $request)['preview_hash']);
        $issued = $this->charges->confirmIssue($this->supplier, $request, 0, $preview['preview_hash'], 'synthetic-rate-issue', $this->actor);
        self::assertSame(62500, $issued['operation']['amount_cents']);
        self::assertArrayNotHasKey('rate_id', $issued['operation']['proof']);
        $command = (new MembershipChargeRepository($this->db))->command($this->supplier, 'synthetic-rate-issue');
        self::assertSame($rate['id'], $command['request']['request']['rate_id']);
        $q = $this->pdo->prepare('SELECT rate_id FROM nonprofit_membership_charge_commands WHERE supplier_id=? AND id=?');
        $q->execute([$this->supplier, $command['id']]);
        self::assertSame($rate['id'], (int) $q->fetchColumn());

        $input['row_version'] = 2; $input['is_active'] = false;
        $rates->save($this->supplier, $rate['id'], $input);
        $replayed = $this->charges->confirmIssue($this->supplier, $request, 0, $preview['preview_hash'], 'synthetic-rate-issue', $this->actor);
        self::assertTrue($replayed['idempotent_replay']);
        self::assertSame($issued['operation']['record_hash'], $replayed['operation']['record_hash']);
        self::assertSame($issued['operation']['proof'], $replayed['operation']['proof']);
        $request['title_key'] = 'synthetic-new';
        $this->expectException(\MyInvoice\Service\Nonprofit\Membership\MembershipException::class);
        $this->expectExceptionMessage('sazba není dostupná');
        $this->charges->previewIssue($this->supplier, $request);
    }

    public function testBatchPreviewIsPagedAndSharesExactlyTheSingleChargeProof(): void
    {
        $template = $this->batchTemplate();
        $ids = [];
        for ($i = 1; $i <= 4; $i++) $ids[] = $this->member('SYN-BULK-' . $i, 'bulk-' . $i)['id'];
        $oneCount = $this->selectQueries(fn (): array => $this->charges->previewIssuePage($this->supplier, $template, [$ids[0]]));
        $manyCount = $this->selectQueries(fn (): array => $this->charges->previewIssuePage($this->supplier, $template, $ids));
        self::assertSame($oneCount, $manyCount, 'Náhled nesmí přidávat dotazy po členech.');
        $page = $this->charges->previewIssuePage($this->supplier, $template, $ids);
        foreach ($ids as $id) {
            $single = $this->charges->previewIssue($this->supplier, ['member_id' => $id] + $template);
            self::assertSame($single['preview_hash'], $page[$id]['preview']['preview_hash']);
            self::assertSame($single['proof'], $page[$id]['preview']['proof']);
        }
    }

    public function testBatchDeferralUsesSinglePreviewAmountsAndExcludesRejectedMembers(): void
    {
        $template = $this->batchTemplate();
        $template['period_from'] = '2026-11-01'; $template['period_to'] = '2027-10-31';
        $template['accounting_date'] = '2026-11-01'; $template['due_date'] = '2026-11-30';
        $ids = [];
        for ($i = 1; $i <= 3; $i++) $ids[] = $this->member('SYN-DEFERRAL-' . $i, 'deferral-' . $i)['id'];
        $this->members->appendRevision($this->supplier, $ids[2], ['status' => 'suspended', 'effective_from' => '2026-07-01',
            'expected_revision' => 1, 'idempotency_key' => 'synthetic-deferral-suspension', 'legal_basis' => $this->basis('Synthetic suspension')], $this->actor);
        $single = $this->charges->previewIssue($this->supplier, ['member_id' => $ids[0]] + $template);
        $expected = (string) ((int) round((float) $single['deferral']['deferred_amount'] * 100) * 2);
        $batches = new \MyInvoice\Service\Nonprofit\Membership\MembershipBatchService($this->db, $this->charges);
        $batch = $batches->create($this->supplier, ['template' => $template, 'selection_mode' => 'selected', 'member_ids' => $ids, 'idempotency_key' => 'synthetic-deferral-batch'], $this->actor);
        $preview = $batches->prepare($this->supplier, $batch['batch']['id']);
        self::assertSame(2, $preview['summary']['ready']);
        self::assertSame(1, $preview['summary']['rejected']);
        self::assertSame($expected, $preview['summary']['deferred_cents']);
        self::assertSame($single['deferral']['balance_sheet_date'], $preview['summary']['deferral_date']);
        $done = $batches->confirm($this->supplier, $batch['batch']['id'], $preview['batch']['approval_token'], $this->actor);
        self::assertSame($expected, $done['summary']['deferred_cents']);
        self::assertSame($preview['summary']['deferral_date'], $done['summary']['deferral_date']);
    }

    public function testBatchReportsCreatedExistingAndRejectedWithoutDuplicatePosting(): void
    {
        $template = $this->batchTemplate();
        $ready = $this->member('SYN-BATCH-READY', 'batch-ready');
        $inactive = $this->member('SYN-BATCH-INACTIVE', 'batch-inactive');
        $existing = $this->member('SYN-BATCH-EXISTS', 'batch-exists');
        $this->members->appendRevision($this->supplier, $inactive['id'], ['status' => 'suspended', 'effective_from' => '2026-07-01',
            'expected_revision' => 1, 'idempotency_key' => 'batch-inactive-suspension', 'legal_basis' => $this->basis('Synthetic suspension')], $this->actor);
        $this->issueTitle($existing['id'], $template['title_key'], 'batch-existing-issue');
        $batches = new \MyInvoice\Service\Nonprofit\Membership\MembershipBatchService($this->db, $this->charges);
        $input = ['template' => $template, 'selection_mode' => 'selected', 'member_ids' => [$ready['id'], $inactive['id'], $existing['id']], 'idempotency_key' => 'synthetic-batch'];
        $created = $batches->create($this->supplier, $input, $this->actor);
        $id = $created['batch']['id'];
        self::assertSame(3, $created['summary']['pending']);
        self::assertSame($id, $batches->create($this->supplier, $input, $this->actor)['batch']['id']);
        $preview = $batches->prepare($this->supplier, $id);
        self::assertSame('ready', $preview['batch']['phase']);
        self::assertSame(1, $preview['summary']['ready']);
        self::assertSame(1, $preview['summary']['existing']);
        self::assertSame(1, $preview['summary']['rejected']);
        self::assertSame('50000', $preview['summary']['planned_cents']);
        self::assertSame(['315.100', '684.100'], array_column($preview['accounts'], 'account'));
        self::assertSame('nonprofit_membership_period_inactive', $preview['items'][1]['error_code']);
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM nonprofit_membership_charge_operations WHERE supplier_id=' . $this->supplier)->fetchColumn());
        $done = $batches->confirm($this->supplier, $id, $preview['batch']['approval_token'], $this->actor);
        self::assertSame('finished', $done['batch']['phase']);
        self::assertSame(1, $done['summary']['created']);
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM nonprofit_membership_charge_operations WHERE supplier_id=' . $this->supplier)->fetchColumn());
        $again = $batches->confirm($this->supplier, $id, $preview['batch']['approval_token'], $this->actor);
        self::assertSame($done['summary'], $again['summary']);
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM nonprofit_membership_charge_operations WHERE supplier_id=' . $this->supplier)->fetchColumn());
    }

    public function testBatchResumesAfterFinancialCommitBeforeOutcomeWasSaved(): void
    {
        $template = $this->batchTemplate();
        $member = $this->member('SYN-BATCH-REPLAY', 'batch-replay');
        $batches = new \MyInvoice\Service\Nonprofit\Membership\MembershipBatchService($this->db, $this->charges);
        $batch = $batches->create($this->supplier, ['template' => $template, 'selection_mode' => 'selected', 'member_ids' => [$member['id']], 'idempotency_key' => 'synthetic-batch-replay'], $this->actor);
        $id = $batch['batch']['id'];
        $preview = $batches->prepare($this->supplier, $id);
        $repository = new \MyInvoice\Repository\Nonprofit\Membership\MembershipBatchRepository($this->db);
        $repository->beginPosting($this->supplier, $id, $preview['batch']['approval_token'], $this->actor);
        $work = $repository->workPage($this->supplier, $id, 'ready', 10)[0];
        // Skutečná účetní operace proběhla, proces skončil před uložením výsledku řádku dávky.
        $issued = $this->charges->confirmIssue($this->supplier, ['member_id' => $member['id']] + $template,
            (int) $work['expected_case_version'], $work['preview_hash'], \MyInvoice\Service\Nonprofit\Membership\MembershipBatchService::memberKey($repository->find($this->supplier,$id),$member['id']), $this->actor);
        $done = $batches->confirm($this->supplier, $id, $preview['batch']['approval_token'], $this->actor);
        self::assertSame('finished', $done['batch']['phase']);
        self::assertSame(1, $done['summary']['created']);
        self::assertSame($issued['operation']['id'], $done['items'][0]['operation_id']);
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM nonprofit_membership_charge_operations WHERE supplier_id=' . $this->supplier)->fetchColumn());
    }

    public function testBatchActiveSelectionUsesAccountingDateAndCanBeReopenedInPages(): void
    {
        $template = $this->batchTemplate();
        for ($i = 1; $i <= 52; $i++) $this->member('SYN-PAGE-' . $i, 'batch-page-' . $i);
        $future = $this->member('SYN-PAGE-FUTURE', 'batch-future');
        $this->members->appendRevision($this->supplier, $future['id'], ['status' => 'ended', 'effective_from' => '2027-01-01', 'expected_revision' => 1,
            'idempotency_key' => 'batch-future-ended', 'legal_basis' => $this->basis('Synthetic future termination')], $this->actor);
        $batches = new \MyInvoice\Service\Nonprofit\Membership\MembershipBatchService($this->db, $this->charges);
        $batch = $batches->create($this->supplier, ['template' => $template, 'selection_mode' => 'active', 'member_ids' => [], 'idempotency_key' => 'synthetic-batch-page'], $this->actor);
        self::assertSame(53, $batch['summary']['total']);
        self::assertCount(50, $batch['items']);
        $page = $batches->detail($this->supplier, $batch['batch']['id'], $batch['next_after_member_id']);
        self::assertCount(3, $page['items']);
        self::assertSame($batch['summary'], $page['summary']);
        $one = $batches->prepare($this->supplier, $batch['batch']['id']);
        self::assertSame(3, $one['summary']['pending']);
        $two = $batches->prepare($this->supplier, $batch['batch']['id']);
        self::assertSame('ready', $two['batch']['phase']);
        self::assertSame(53, $two['summary']['ready']);
    }

    public function testBatchRejectsForeignSelectionAndChangedPreviewWithoutCreatingCharges(): void
    {
        $template = $this->batchTemplate();
        $member = $this->member('SYN-BATCH-STALE', 'batch-stale');
        $batches = new \MyInvoice\Service\Nonprofit\Membership\MembershipBatchService($this->db, $this->charges);
        try {
            $batches->create($this->supplier, ['template' => $template, 'selection_mode' => 'selected', 'member_ids' => [$member['id'] + 100000], 'idempotency_key' => 'synthetic-batch-foreign'], $this->actor);
            self::fail('Cizí výběr nesmí založit dávku.');
        } catch (\MyInvoice\Service\Nonprofit\Membership\MembershipException $e) {
            self::assertSame('nonprofit_membership_batch_selection_invalid', $e->errorCode);
        }
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM nonprofit_membership_batches WHERE supplier_id=' . $this->supplier)->fetchColumn());
        $batch = $batches->create($this->supplier, ['template' => $template, 'selection_mode' => 'selected', 'member_ids' => [$member['id']], 'idempotency_key' => 'synthetic-batch-stale'], $this->actor);
        $id = $batch['batch']['id'];
        try {
            $batches->detail($this->supplier + 100000, $id);
            self::fail('Dávka cizí firmy nesmí být dostupná.');
        } catch (\MyInvoice\Service\Nonprofit\Membership\MembershipException $e) {
            self::assertSame(404, $e->httpStatus);
        }
        $preview = $batches->prepare($this->supplier, $id);
        $this->members->appendRevision($this->supplier, $member['id'], ['status' => 'suspended', 'effective_from' => '2026-07-01', 'expected_revision' => 1,
            'idempotency_key' => 'batch-stale-suspension', 'legal_basis' => $this->basis('Synthetic suspension')], $this->actor);
        $done = $batches->confirm($this->supplier, $id, $preview['batch']['approval_token'], $this->actor);
        self::assertSame(1, $done['summary']['rejected']);
        self::assertSame(0, $done['summary']['created']);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM nonprofit_membership_charge_operations WHERE supplier_id=' . $this->supplier)->fetchColumn());
    }

    public function testMemberListFiltersAndStatusesAreComputedInOneQueryBeforePagination(): void
    {
        $none = $this->member('SYN-LIST-NONE', 'list-none');
        $unknown = $this->member('SYN-LIST-UNKNOWN', 'list-unknown');
        $partial = $this->member('SYN-LIST-PARTIAL', 'list-partial');
        $this->issue($unknown['id'], 'list-unknown');
        $issued = $this->issue($partial['id'], 'list-partial');
        $this->payClaim($issued, '10000.00', 'list-partial');
        $list = $this->listRows();
        self::assertSame(['none','unpaid','unpaid'], array_column(array_column($list, 'contribution'), 'status'));
        self::assertSame('2000000', $list[2]['contribution']['prescribed_cents']);
        self::assertSame('1000000', $list[2]['contribution']['paid_cents']);
        self::assertSame('1000000', $list[2]['contribution']['open_cents']);
        self::assertSame('2000000', $list[1]['contribution']['open_cents']);
        $filtered = $this->listRows(['payment_status' => 'unpaid'], 0, 1);
        self::assertSame([$unknown['id']], array_column($filtered, 'id'), 'Filtr musí být před LIMIT a zahrnout předpis bez úhrady.');
        self::assertSame([], array_column($this->listRows(['payment_status' => 'unproven']), 'id'));
        self::assertSame([$none['id']], array_column($this->listRows(['payment_status' => 'none']), 'id'));
        self::assertSame(1, $this->selectQueries(fn (): array => $this->listRows()));
        self::assertSame([], $this->listRows(['title_key' => 'synthetic-missing', 'payment_status' => 'unpaid']));
        self::assertSame(['none','none','none'], array_column(array_column($this->listRows(['year' => 2027]), 'contribution'), 'status'));
        $this->pdo->prepare('UPDATE clients SET company_name=? WHERE supplier_id=? AND id=?')->execute(['Synthetic Rowan', $this->supplier, $partial['client_id']]);
        self::assertSame([$partial['id']], array_column($this->listRows(['search' => 'rowan']), 'id'));
        self::assertSame([$unknown['id']], array_column($this->listRows(['search' => 'UNKNOWN']), 'id'));
        $filter = \MyInvoice\Service\Nonprofit\Membership\MembershipListFilter::parse(['year' => 2026]);
        self::assertSame([], (new \MyInvoice\Repository\Nonprofit\Membership\MembershipListRepository($this->db))->page($this->supplier + 100000, 0, 50, $filter));
        $detail = (new MembershipReconciliationService($this->balances))->memberBalance($this->supplier, $partial['id']);
        self::assertSame('partially_settled', $detail['items'][0]['settlement']['status']);
        self::assertSame((string) $detail['items'][0]['settlement']['open_cents'], $list[2]['contribution']['open_cents']);
    }

    public function testUnfilteredMemberListAggregatesOnlyTheRequestedPage(): void
    {
        for ($i=1;$i<=5;$i++) {
            $member=$this->member('SYN-LIMIT-'.$i,'list-limit-'.$i);
            $issued=$this->issue($member['id'],'list-limit-'.$i);
            $this->payClaim($issued,'10000.00','list-limit-payment-'.$i);
        }
        $filter=\MyInvoice\Service\Nonprofit\Membership\MembershipListFilter::parse(['year'=>2026]);
        [$sql,$params]=(new \MyInvoice\Repository\Nonprofit\Membership\MembershipListRepository($this->db))->query($this->supplier,0,1,$filter);
        $q=$this->pdo->prepare('ANALYZE FORMAT=JSON '.$sql);$q->execute($params);
        $plan=json_decode((string)$q->fetchColumn(),true,flags:JSON_THROW_ON_ERROR);
        $examined=[];$lineReads=[];$pairReads=[];
        $walk=function(array $node)use(&$walk,&$examined,&$lineReads,&$pairReads):void {
            if (($node['table_name']??null)==='cc' && isset($node['r_rows'],$node['r_loops'])) $examined[]=(float)$node['r_rows']*(float)$node['r_loops'];
            if (($node['table_name']??null)==='l' && isset($node['r_rows'],$node['r_loops'])) $lineReads[]=(float)$node['r_rows']*(float)$node['r_loops'];
            if (($node['table_name']??null)==='i' && isset($node['r_rows'],$node['r_loops'])) $pairReads[]=(float)$node['r_rows']*(float)$node['r_loops'];
            foreach($node as $value)if(is_array($value))$walk($value);
        };
        $walk($plan);
        self::assertNotEmpty($examined,'Měření musí skutečně číst účetní případy.');
        foreach($examined as $rows)self::assertLessThanOrEqual(1.0,$rows,'Seznam bez filtru úhrady nesmí agregovat předpisy dalších čtyř členů.');
        self::assertNotEmpty($lineReads);
        foreach($lineReads as $rows)self::assertLessThanOrEqual(4.0,$rows,'Párování stránky nesmí začínat skenem všech účetních řádků firmy.');
        self::assertNotEmpty($pairReads);
        foreach($pairReads as $rows)self::assertLessThanOrEqual(4.0,$rows,'Párování stránky nesmí načíst skupiny ostatních členů.');
    }

    private function listRows(array $query = [], int $after = 0, int $limit = 100): array
    {
        $filter = \MyInvoice\Service\Nonprofit\Membership\MembershipListFilter::parse($query + ['year' => 2026]);
        return (new \MyInvoice\Repository\Nonprofit\Membership\MembershipListRepository($this->db))->page($this->supplier, $after, $limit, $filter);
    }

    public function testRefreshValidatesSelectionOutsideTheFirstPageAndFindsPersonalNames(): void
    {
        $one=$this->member('SYN-REVIEW-ONE','review-one');
        $two=$this->member('SYN-REVIEW-TWO','review-two');
        $this->pdo->prepare('UPDATE clients SET company_name=?,first_name=?,last_name=? WHERE supplier_id=? AND id=?')
            ->execute(['','Synthetic','Rowan',$this->supplier,$two['client_id']]);
        $repo=new \MyInvoice\Repository\Nonprofit\Membership\MembershipListRepository($this->db);
        $filter=\MyInvoice\Service\Nonprofit\Membership\MembershipListFilter::parse(['year'=>2026]);
        self::assertCount(1,$repo->page($this->supplier,0,1,$filter));
        self::assertSame([$one['id'],$two['id']],$repo->matchingSelection($this->supplier,[$one['id'],$two['id']],$filter));
        $filter['search']='Rowan';
        self::assertSame([$two['id']],$repo->matchingSelection($this->supplier,[$one['id'],$two['id']],$filter));
        self::assertSame('Rowan',$repo->page($this->supplier,0,50,$filter)[0]['last_name']);
        self::assertSame([],$repo->matchingSelection($this->supplier+100000,[$one['id'],$two['id']],$filter));
    }

    public function testTitlePickerReturnsStoredCaseSensitiveKeysWithTenantAndYearScope(): void
    {
        $member=$this->member('SYN-TITLES','review-titles');
        $this->issueTitle($member['id'],'CLENSTVI','review-title-one');
        $this->issueTitle($member['id'],'VSTUP','review-title-two');
        $repo=new \MyInvoice\Repository\Nonprofit\Membership\MembershipListRepository($this->db);
        self::assertSame(['CLENSTVI','VSTUP'],$repo->titles($this->supplier,2026,'',100));
        self::assertSame(['VSTUP'],$repo->titles($this->supplier,null,'CLENSTVI',100));
        self::assertSame([],$repo->titles($this->supplier,2027,'',100));
        self::assertSame([],$repo->titles($this->supplier+100000,null,'',100));
    }

    public function testExistingMismatchedReceiptIsVisibleForReviewInListAndDetail(): void
    {
        foreach(['amount','account','side'] as $variant) {
            $member=$this->member('SYN-DAMAGED-'.$variant,'damaged-'.$variant);
            $issued=$this->issue($member['id'],'damaged-'.$variant);
            $line=$issued['operation']['receivable_line_id'];
            // Úmyslně poškozené syntetické úložiště, nikoli podporovaná cesta účetní opravy.
            if($variant==='amount')$this->pdo->prepare('UPDATE journal_entry_lines SET amount=19999 WHERE supplier_id=? AND id=?')->execute([$this->supplier,$line]);
            if($variant==='account')$this->pdo->prepare("UPDATE journal_entry_lines SET account_id=(SELECT id FROM chart_of_accounts WHERE supplier_id=? AND account_code='221') WHERE supplier_id=? AND id=?")->execute([$this->supplier,$this->supplier,$line]);
            if($variant==='side')$this->pdo->prepare("UPDATE journal_entry_lines SET side='credit' WHERE supplier_id=? AND id=?")->execute([$this->supplier,$line]);
            $detail=(new MembershipReconciliationService($this->balances))->memberBalance($this->supplier,$member['id']);
            self::assertSame('ambiguous',$detail['items'][0]['settlement']['status'],$variant);
            self::assertNull($detail['items'][0]['settlement']['open_cents']);
            $list=$this->listRows(['search'=>'SYN-DAMAGED-'.$variant]);
            self::assertSame('unproven',$list[0]['contribution']['status'],$variant);
            self::assertNull($list[0]['contribution']['open_cents']);
        }
    }

    public function testMissingJournalLineDoesNotHideClaimFromDetailOrList(): void
    {
        $member=$this->member('SYN-MISSING-LINE','missing-line');
        $issued=$this->issue($member['id'],'missing-line');
        // Simulace poškozeného syntetického úložiště v transakci testu.
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        try {$this->pdo->prepare('DELETE FROM journal_entry_lines WHERE supplier_id=? AND id=?')->execute([$this->supplier,$issued['operation']['receivable_line_id']]);}
        finally {$this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');}
        $detail=(new MembershipReconciliationService($this->balances))->memberBalance($this->supplier,$member['id']);
        self::assertCount(1,$detail['items']);
        self::assertSame('ambiguous',$detail['items'][0]['settlement']['status']);
        self::assertSame('unproven',$this->listRows()[0]['contribution']['status']);
    }

    private function payClaim(array $issued, string $amount, string $key): void
    {
        $claim = (new MembershipChargeRepository($this->db))->claimForOperation($this->supplier, $issued['operation']['id']);
        $target = (new MembershipReceivablePaymentAdapter($this->balances))->target($this->supplier, $claim['id']);
        $this->pdo->prepare("INSERT INTO bank_statements(supplier_id,file_name,file_hash,account_number,bank_code,currency,statement_date,imported_by) VALUES(?,'synthetic-list.gpc',?,'1000000005','0100','CZK','2026-02-01',?)")
            ->execute([$this->supplier, hash('sha256', 'synthetic list ' . $key . $this->supplier), $this->actor]);
        $statement = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO bank_transactions(statement_id,posted_at,amount,currency,description,match_status) VALUES(?,'2026-02-01',?,'CZK','Synthetic list payment','manual')")->execute([$statement, $amount]);
        $transaction = (int) $this->pdo->lastInsertId();
        $entry = $this->posting->postDocument($this->supplier, 'bank', $transaction, [
            ['account_code'=>'221','side'=>'debit','amount'=>$amount],['account_code'=>'315.100','side'=>'credit','amount'=>$amount],
        ], ['entry_date'=>'2026-02-01','description'=>'Synthetic list payment','posted'=>true,'posted_by'=>$this->actor]);
        $q = $this->pdo->prepare("SELECT line_no FROM journal_entry_lines WHERE supplier_id=? AND entry_id=? AND account_id=?");
        $q->execute([$this->supplier,$entry,$target['journal_line']['account_id']]);
        (new JournalLinePairingRepository($this->db))->create($this->supplier, $target['journal_line']['account_id'], [
            $target['journal_line'], ['entry_id'=>$entry,'line_no'=>(int)$q->fetchColumn(),'account_id'=>$target['journal_line']['account_id']],
        ], 'Synthetic list native payment', 'manual', $this->actor);
    }

    private function batchTemplate(): array
    {
        $template = $this->request(1);
        unset($template['member_id']);
        $template['amount'] = '500.00'; $template['title_key'] = 'synthetic-batch-standard';
        $rate = (new \MyInvoice\Service\Nonprofit\Membership\MembershipRateService($this->db))->save($this->supplier, null, [
            'name' => 'Synthetic batch standard', 'amount' => '500.00', 'receivable_account' => '315.100', 'revenue_account' => '684.100',
            'title_key' => $template['title_key'], 'period_months' => 12, 'title_reference' => 'Synthetic resolution', 'evidence' => 'Synthetic evidence',
            'classification' => $this->classification, 'is_active' => true, 'row_version' => 0,
        ]);
        return ['rate_id' => $rate['id']] + $template;
    }

    public function testRateIsTenantScopedAndRejectsStaleUpdateAndForeignDimensions(): void
    {
        $rates = new \MyInvoice\Service\Nonprofit\Membership\MembershipRateService($this->db);
        $input = ['name' => 'Synthetic reduced', 'amount' => '250.00', 'receivable_account' => '315.100', 'revenue_account' => '684.100',
            'title_key' => 'synthetic-reduced', 'period_months' => 6, 'title_reference' => 'Synthetic resolution', 'evidence' => 'Synthetic evidence',
            'classification' => null, 'is_active' => true, 'row_version' => 0];
        $rate = $rates->save($this->supplier, null, $input);
        $repository = new \MyInvoice\Repository\Nonprofit\Membership\MembershipRateRepository($this->db);
        self::assertNull($repository->find($this->supplier + 100000, $rate['id']));
        self::assertSame([], $repository->page($this->supplier + 100000, 0, true));
        try {
            $rates->assertUsable($this->supplier + 100000, $rate['id'], false);
            self::fail('Cizí sazba byla přijata.');
        } catch (\MyInvoice\Service\Nonprofit\Membership\MembershipException $e) {
            self::assertSame('nonprofit_membership_rate_unavailable', $e->errorCode);
        }
        $input['row_version'] = 1;
        $rates->save($this->supplier, $rate['id'], $input);
        try {
            $rates->save($this->supplier, $rate['id'], $input);
            self::fail('Zastaralá verze byla přijata.');
        } catch (\MyInvoice\Service\Nonprofit\Membership\MembershipException $e) {
            self::assertSame('nonprofit_membership_rate_changed', $e->errorCode);
        }
        $input['row_version'] = 2;
        $input['classification'] = ['activity_value_id' => 2147483645, 'activity_kind_value_id' => 2147483646, 'tax_regime_value_id' => 2147483647];
        $this->expectException(\MyInvoice\Service\Nonprofit\Membership\MembershipException::class);
        $rates->save($this->supplier, $rate['id'], $input);
    }
    public function testReplayUsesCapturedClassificationAfterValueRetirement(): void
    {
        $member = $this->member('M-RETIRED-DIM', 'retired-dimension');
        $issued = $this->issue($member['id'], 'retired-dimension');
        $kind = $issued['operation']['proof']['classification']['activity_kind']['value_id'];
        $this->pdo->prepare('UPDATE dimension_values SET is_active=0 WHERE supplier_id=? AND id=?')->execute([$this->supplier, $kind]);

        $replayed = $this->charges->confirmIssue($this->supplier, $this->request($member['id']), 0,
            $issued['preview_hash'], 'issue-retired-dimension', $this->actor);
        self::assertTrue($replayed['idempotent_replay']);
        self::assertSame($issued['operation']['id'], $replayed['operation']['id']);

        $new = $this->request($member['id']);
        $new['title_key'] = 'retired-dimension-new';
        try {
            $this->charges->previewIssue($this->supplier, $new);
            self::fail('A retired classification value was accepted for a new charge.');
        } catch (\MyInvoice\Service\Nonprofit\Membership\MembershipException $error) {
            self::assertSame('nonprofit_membership_classification_invalid', $error->errorCode);
        }
    }
    #[DataProvider('dimensionProofMutations')]
    public function testReplayRejectsChangedDimensionAssignmentOrOrigin(string $mutation): void
    {
        $member = $this->member('M-PROOF-' . $mutation, 'proof-' . $mutation);
        $issued = $this->issue($member['id'], 'proof-' . $mutation);
        $line = (int) $issued['operation']['receivable_line_id'];
        $activity = $issued['operation']['proof']['classification']['activity'];
        if ($mutation === 'origin') {
            $this->pdo->prepare("UPDATE journal_entry_line_dimensions SET assignment_origin='unknown' WHERE supplier_id=? AND line_id=? AND dimension_type_id=?")
                ->execute([$this->supplier, $line, $activity['type_id']]);
        } else {
            $q = $this->pdo->prepare("SELECT id FROM dimension_values WHERE supplier_id=? AND type_id=? AND semantic_key='economic'");
            $q->execute([$this->supplier, $activity['type_id']]);
            $this->pdo->prepare('UPDATE journal_entry_line_dimensions SET dimension_value_id=? WHERE supplier_id=? AND line_id=? AND dimension_type_id=?')
                ->execute([(int) $q->fetchColumn(), $this->supplier, $line, $activity['type_id']]);
        }

        try {
            $this->charges->confirmIssue($this->supplier, $this->request($member['id']), 0, $issued['preview_hash'], 'issue-proof-' . $mutation, $this->actor);
            self::fail('Changed dimension proof was accepted: ' . $mutation);
        } catch (\MyInvoice\Service\Nonprofit\Membership\MembershipException $error) {
            self::assertSame('nonprofit_membership_ledger_mismatch', $error->errorCode, $mutation);
        }
    }

    public static function dimensionProofMutations(): iterable
    {
        yield 'assignment value' => ['value'];
        yield 'assignment origin' => ['origin'];
    }
    #[DataProvider('genericDimensionMutationPaths')]
    public function testGenericDimensionMutationCannotRewriteStoredFinancialProof(string $path): void
    {
        $member = $this->member('M-GUARD-' . $path, 'guard-' . $path);
        $issued = $this->issueTitle($member['id'], 'guard-' . $path, 'guard-' . $path);
        $line = (int) $issued['operation']['receivable_line_id'];
        $this->pdo->prepare('INSERT INTO dimension_types(supplier_id,code,name,kind) VALUES(?,?,?,?)')
            ->execute([$this->supplier, 'guard_' . $path, 'Synthetic guard ' . $path, 'custom']);
        $type = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO dimension_values(type_id,supplier_id,code,name) VALUES(?,?,?,?)')
            ->execute([$type, $this->supplier, 'VALUE', 'Synthetic guard value']);
        $value = (int) $this->pdo->lastInsertId();
        $repo = new DimensionAssignmentRepository($this->db);
        $origin = [$type => ['origin' => 'explicit', 'ref' => null]];

        try {
            if ($path === 'replace') {
                $current = $repo->lineDimensions($this->supplier, [$line])[$line];
                $repo->replaceLineDimensions($this->supplier, $line, $current + [$type => $value], $origin);
            } elseif ($path === 'insert') {
                $repo->insertLineDimensions($this->supplier, $line, [$type => $value], $origin);
            } elseif ($path === 'batch') {
                $repo->insertLineAssignments($this->supplier, [$line => ['dimensions' => [$type => $value], 'dimension_origins' => $origin]]);
            } else {
                $repo->insertLineSplits($this->supplier, $line, [$type => [$value => 1]], [$type => [$value => ['origin' => 'explicit', 'ref' => null]]]);
            }
            self::fail('Generic dimension mutation changed a Membership-owned financial line: ' . $path);
        } catch (\MyInvoice\Service\Accounting\PostingException $error) {
            self::assertSame('nonprofit_membership_source_immutable', $error->errorCode, $path);
        }
    }

    public static function genericDimensionMutationPaths(): iterable
    {
        yield 'replace existing dimensions' => ['replace'];
        yield 'direct dimension insert' => ['insert'];
        yield 'batch assignment insert' => ['batch'];
        yield 'direct split insert' => ['split'];
    }
    public function testTwoMembersProduceExactNativeTotalsAndReplayUsesCapturedProof(): void
    {
        $one = $this->member('M-001', 'one');
        $two = $this->member('M-002', 'two');
        $first = $this->issue($one['id'], 'first');
        $second = $this->issue($two['id'], 'second');

        $q = $this->pdo->prepare("SELECT a.account_code,l.side,SUM(l.amount) amount,COUNT(*) rows_count
            FROM journal_entry_lines l JOIN journal_entries e ON e.supplier_id=l.supplier_id AND e.id=l.entry_id
            JOIN chart_of_accounts a ON a.supplier_id=l.supplier_id AND a.id=l.account_id
            WHERE l.supplier_id=? AND e.source_type='nonprofit_membership' AND e.source_id IS NOT NULL
            GROUP BY a.account_code,l.side ORDER BY a.account_code,l.side");
        $q->execute([$this->supplier]);
        self::assertSame([
            ['account_code' => '315.100', 'side' => 'debit', 'amount' => '40000.00', 'rows_count' => 2],
            ['account_code' => '684.100', 'side' => 'credit', 'amount' => '40000.00', 'rows_count' => 2],
        ], array_map(static function (array $row): array { $row['rows_count'] = (int) $row['rows_count']; return $row; }, $q->fetchAll(PDO::FETCH_ASSOC)));
        $reconciliation = (new MembershipReconciliationService($this->balances))->periodReconciliation($this->supplier, $this->period);
        self::assertSame('balanced', $reconciliation['status']);
        self::assertTrue($reconciliation['proof_complete']);
        self::assertSame('4000000', (string) $reconciliation['claims']['cents']);
        self::assertSame('4000000', (string) $reconciliation['ledger']['receivable_cents']);
        self::assertSame('4000000', (string) $reconciliation['ledger']['revenue_cents']);

        $this->members->appendRevision($this->supplier, $one['id'], [
            'status' => 'ended', 'effective_from' => '2027-01-01', 'expected_revision' => 1,
            'idempotency_key' => 'member-one-ended',
            'legal_basis' => $this->basis('Synthetic future membership end'),
        ], $this->actor);
        $replayed = $this->charges->confirmIssue($this->supplier, $this->request($one['id']), 0, $first['preview_hash'], 'issue-first', $this->actor);
        self::assertTrue($replayed['idempotent_replay']);
        self::assertSame($first['operation']['id'], $replayed['operation']['id']);
        self::assertSame($second['operation']['amount_cents'], 2000000);
    }

    public function testStalePreviewOverlapAndClosedPeriodAreRejected(): void
    {
        $member = $this->member('M-005', 'five');
        $request = $this->request($member['id']);
        $stale = $this->charges->previewIssue($this->supplier, $request);
        $this->members->appendRevision($this->supplier, $member['id'], [
            'status' => 'ended', 'effective_from' => '2027-01-01', 'expected_revision' => 1,
            'idempotency_key' => 'member-five-ended', 'legal_basis' => $this->basis('Synthetic future end reviewed after preview'),
        ], $this->actor);
        try {
            $this->charges->confirmIssue($this->supplier, $request, 0, $stale['preview_hash'], 'stale-five', $this->actor);
            self::fail('Changed effective timeline must invalidate a prior preview.');
        } catch (\MyInvoice\Service\Nonprofit\Membership\MembershipException $e) {
            self::assertSame('nonprofit_membership_preview_stale', $e->errorCode);
        }
        $fresh = $this->charges->previewIssue($this->supplier, $request);
        $this->charges->confirmIssue($this->supplier, $request, 0, $fresh['preview_hash'], 'fresh-five', $this->actor);
        $overlap = $request;
        $overlap['period_from'] = '2026-02-01';
        $overlap['period_to'] = '2026-11-30';
        try {
            $this->charges->previewIssue($this->supplier, $overlap);
            self::fail('An active title cannot overlap itself.');
        } catch (\MyInvoice\Service\Nonprofit\Membership\MembershipException $e) {
            self::assertSame('nonprofit_membership_claim_overlap', $e->errorCode);
        }
        $closed = $request;
        $closed['title_key'] = 'closed-period-title';
        $closedPreview = $this->charges->previewIssue($this->supplier, $closed);
        $this->pdo->prepare("UPDATE accounting_periods SET status='closed' WHERE supplier_id=? AND id=?")->execute([$this->supplier, $this->period]);
        try {
            $this->charges->confirmIssue($this->supplier, $closed, 0, $closedPreview['preview_hash'], 'closed-five', $this->actor);
            self::fail('Closed period must reject a financial write.');
        } catch (\MyInvoice\Service\Nonprofit\Membership\MembershipException $e) {
            self::assertSame('nonprofit_membership_period_locked', $e->errorCode);
        }
    }

    public function testNativeBankPairingIsTheOnlyPaymentEvidenceAndReversalConsumesClaim(): void
    {
        $member = $this->member('M-003', 'three');
        $issued = $this->issue($member['id'], 'payment');
        $claimId = (int) $this->pdo->query('SELECT id FROM nonprofit_membership_charge_claims WHERE operation_id=' . (int) $issued['operation']['id'])->fetchColumn();
        $reconciliation = new MembershipReconciliationService($this->balances);
        $before = $reconciliation->claimSettlement($this->supplier, $claimId);
        self::assertSame('unreconciled', $before['settlement']['status']);
        self::assertSame(0, $before['settlement']['paid_cents']);
        self::assertSame(2000000, $before['settlement']['open_cents']);
        self::assertSame('unpaid', $this->listRows()[0]['contribution']['status']);

        $target = (new MembershipReceivablePaymentAdapter($this->balances))->target($this->supplier, $claimId);
        $forged = $this->posting->postDocument($this->supplier, 'bank', null, [
            ['account_code' => '221', 'side' => 'debit', 'amount' => '20000.00'],
            ['account_code' => '315.100', 'side' => 'credit', 'amount' => '20000.00'],
        ], ['entry_date' => '2026-02-01', 'description' => 'Synthetic unowned bank-looking entry', 'posted' => true, 'posted_by' => $this->actor]);
        $q = $this->pdo->prepare("SELECT l.line_no,l.account_id FROM journal_entry_lines l JOIN chart_of_accounts a ON a.supplier_id=l.supplier_id AND a.id=l.account_id WHERE l.supplier_id=? AND l.entry_id=? AND a.account_code='315.100'");
        $q->execute([$this->supplier, $forged]);
        $forgedLine = $q->fetch(PDO::FETCH_ASSOC);
        $pairings = new JournalLinePairingRepository($this->db);
        $forgedPairing = $pairings->create($this->supplier, $target['journal_line']['account_id'], [
            ['entry_id' => $target['journal_line']['entry_id'], 'line_no' => $target['journal_line']['line_no'], 'account_id' => $target['journal_line']['account_id']],
            ['entry_id' => $forged, 'line_no' => (int) $forgedLine['line_no'], 'account_id' => (int) $forgedLine['account_id']],
        ], 'Synthetic forged payment evidence', 'manual', $this->actor);
        self::assertSame('ambiguous', $reconciliation->claimSettlement($this->supplier, $claimId)['settlement']['status']);
        self::assertSame('unproven', $this->listRows()[0]['contribution']['status']);
        self::assertTrue($pairings->delete($this->supplier, $forgedPairing));

        $this->pdo->prepare("INSERT INTO bank_statements(supplier_id,file_name,file_hash,account_number,bank_code,currency,statement_date,imported_by) VALUES(?,'synthetic-membership.gpc',?,'1000000005','0100','CZK','2026-02-01',?)")
            ->execute([$this->supplier, hash('sha256', 'synthetic membership payment ' . $this->supplier), $this->actor]);
        $statement = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO bank_transactions(statement_id,posted_at,amount,currency,description,match_status) VALUES(?,'2026-02-01',20000,'CZK','Synthetic native membership payment','manual')")
            ->execute([$statement]);
        $transaction = (int) $this->pdo->lastInsertId();
        $payment = $this->posting->postDocument($this->supplier, 'bank', $transaction, [
            ['account_code' => '221', 'side' => 'debit', 'amount' => '20000.00'],
            ['account_code' => '315.100', 'side' => 'credit', 'amount' => '20000.00'],
        ], ['entry_date' => '2026-02-01', 'description' => 'Synthetic native bank payment', 'posted' => true, 'posted_by' => $this->actor]);
        $q->execute([$this->supplier, $payment]);
        $paymentLine = $q->fetch(PDO::FETCH_ASSOC);
        $pairings->create($this->supplier, $target['journal_line']['account_id'], [
            ['entry_id' => $target['journal_line']['entry_id'], 'line_no' => $target['journal_line']['line_no'], 'account_id' => $target['journal_line']['account_id']],
            ['entry_id' => $payment, 'line_no' => (int) $paymentLine['line_no'], 'account_id' => (int) $paymentLine['account_id']],
        ], 'Synthetic exact membership payment', 'manual', $this->actor);
        $settled = $reconciliation->claimSettlement($this->supplier, $claimId);
        self::assertSame('settled', $settled['settlement']['status']);
        self::assertSame(2000000, $settled['settlement']['paid_cents']);
        self::assertSame(0, $settled['settlement']['open_cents']);
        self::assertSame('paid', $this->listRows()[0]['contribution']['status']);
        self::assertSame('2000000', $this->listRows()[0]['contribution']['paid_cents']);
        self::assertSame('0', $this->listRows()[0]['contribution']['open_cents']);

        $preview = $this->charges->previewReverse($this->supplier, ['case_id' => $issued['case']['id'], 'accounting_date' => '2026-12-31', 'reason' => 'Synthetic approved cancellation']);
        $reversed = $this->charges->confirmReverse($this->supplier, ['case_id' => $issued['case']['id'], 'accounting_date' => '2026-12-31', 'reason' => 'Synthetic approved cancellation'],
            $preview['expected_case_version'], $preview['preview_hash'], 'reverse-payment', $this->actor);
        self::assertSame('reverse', $reversed['operation']['operation']);
        self::assertSame('reversed', $reconciliation->claimSettlement($this->supplier, $claimId)['settlement']['status']);
        self::assertSame('none', $this->listRows()[0]['contribution']['status']);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM journal_line_pairing_items WHERE entry_id=' . $target['journal_line']['entry_id'])->fetchColumn());
    }

    public function testFinancialFactsAreAppendOnly(): void
    {
        $member = $this->member('M-004', 'four');
        $issued = $this->issue($member['id'], 'immutable');
        $this->expectException(\PDOException::class);
        $this->pdo->prepare('UPDATE nonprofit_membership_charge_operations SET amount_cents=1 WHERE supplier_id=? AND id=?')
            ->execute([$this->supplier, $issued['operation']['id']]);
    }

    #[DataProvider('genericJournalMutationPaths')]
    public function testGenericJournalMutationCannotRewriteMembershipReceipt(string $path): void
    {
        $member = $this->member('M-JOURNAL-' . $path, 'journal-' . $path);
        $issued = $this->issueTitle($member['id'], 'journal-' . $path, 'journal-' . $path);
        $entry = (int) $issued['operation']['journal_entry_id'];
        $repository = Bootstrap::buildContainer()->get(\MyInvoice\Repository\JournalEntryRepository::class);
        $before = $repository->find($entry, $this->supplier);
        $header = $before;
        $header['source_type'] = 'manual';
        $header['source_id'] = null;
        $lines = $before['lines'];
        $proof = (new \MyInvoice\Service\Nonprofit\Membership\MembershipClassification($this->db))->resolve($this->supplier, $this->classification);
        foreach ($lines as &$line) {
            $line['dimensions'] = array_column($proof, 'value_id', 'type_id');
            $line['dimension_origins'] = array_fill_keys(array_keys($line['dimensions']), ['origin' => 'explicit', 'ref' => null]);
        }
        unset($line);
        try {
            match ($path) {
                'replace' => $repository->replace($entry, $header, $lines),
                'partial' => $repository->replaceSomeLines($entry, $this->supplier, (int) $before['row_version'], [(int) $lines[0]['id']], [$lines[0]]),
                'detach' => $repository->detachSource($entry, $this->supplier),
                'link-reversal' => $repository->setReversedBy($entry, $this->supplier, $entry),
                'reverse' => $this->posting->reverse($this->supplier, $entry, ['entry_date' => '2026-02-01', 'posted_by' => $this->actor]),
            };
            self::fail('Generic journal mutation accepted a Membership-owned receipt: ' . $path);
        } catch (\MyInvoice\Service\Accounting\PostingException $error) {
            self::assertSame('nonprofit_membership_source_immutable', $error->errorCode, $path);
        }
        self::assertSame($before, $repository->find($entry, $this->supplier));
    }

    public static function genericJournalMutationPaths(): iterable
    {
        foreach (['replace', 'partial', 'detach', 'link-reversal', 'reverse'] as $path) yield $path => [$path];
    }

    public function testGenericInsertCannotForgeMembershipJournalOwner(): void
    {
        $member = $this->member('M-JOURNAL-FORGE', 'journal-forge');
        $issued = $this->issue($member['id'], 'journal-forge');
        $repository = Bootstrap::buildContainer()->get(\MyInvoice\Repository\JournalEntryRepository::class);
        $header = $repository->find((int) $issued['operation']['journal_entry_id'], $this->supplier);
        $header['source_id'] = (int) $issued['case']['id'] + 100000;
        $lines = $header['lines'];
        $proof = (new \MyInvoice\Service\Nonprofit\Membership\MembershipClassification($this->db))->resolve($this->supplier, $this->classification);
        foreach ($lines as &$line) {
            $line['dimensions'] = array_column($proof, 'value_id', 'type_id');
            $line['dimension_origins'] = array_fill_keys(array_keys($line['dimensions']), ['origin' => 'explicit', 'ref' => null]);
        }
        unset($line);
        $this->expectException(\MyInvoice\Service\Accounting\PostingException::class);
        $this->expectExceptionMessage('živý vlastnický kontext');
        $repository->insert($header, $lines);
    }

    public function testMarkPostedOnMembershipReceiptIsAnExactNoop(): void
    {
        $member = $this->member('M-JOURNAL-NOOP', 'journal-noop');
        $issued = $this->issue($member['id'], 'journal-noop');
        $repository = Bootstrap::buildContainer()->get(\MyInvoice\Repository\JournalEntryRepository::class);
        $entry = (int) $issued['operation']['journal_entry_id'];
        $before = $repository->find($entry, $this->supplier);
        self::assertFalse($repository->markPosted($entry, $this->supplier, $this->actor));
        self::assertSame($before, $repository->find($entry, $this->supplier));
    }

    public function testMemberBalanceQueryCountIsConstantWithinOnePage(): void
    {
        $member = $this->member('M-006', 'six');
        $this->issueTitle($member['id'], 'batch-1', 'balance-batch-1');
        $service = new MembershipReconciliationService($this->balances);
        $one = $this->selectQueries(fn (): array => $service->memberBalance($this->supplier, $member['id']));
        for ($index = 2; $index <= 6; ++$index) $this->issueTitle($member['id'], 'batch-' . $index, 'balance-batch-' . $index);
        $many = $this->selectQueries(fn (): array => $service->memberBalance($this->supplier, $member['id']));

        self::assertSame($one, $many);
        self::assertLessThanOrEqual(3, $many);
    }

    public function testPublicRoleUsesOnlyRevisionsRecordedByKnownAt(): void
    {
        $member = $this->member('M-PUBLIC-TIME', 'public-time');
        $q = $this->pdo->prepare('SELECT recorded_at_utc FROM nonprofit_member_revisions WHERE supplier_id=? AND member_id=? AND revision_no=1');
        $q->execute([$this->supplier, $member['id']]);
        $known = str_replace(' ', 'T', (string) $q->fetchColumn()) . 'Z';
        $this->pdo->query('SELECT SLEEP(0.01)');
        $this->members->appendRevision($this->supplier, $member['id'], [
            'status' => 'suspended', 'effective_from' => '2026-06-01', 'expected_revision' => 1,
            'idempotency_key' => 'member-public-time-suspended', 'legal_basis' => $this->basis('Synthetic later recorded suspension'),
        ], $this->actor);
        $catalog = $this->catalog();

        $historical = $catalog->roles($this->supplier, '2026-07-01', $known, null, 25, $member['id']);
        $current = $catalog->roles($this->supplier, '2026-07-01', null, null, 25, $member['id']);

        self::assertSame('active', $historical['items'][0]['status']);
        self::assertNull($historical['items'][0]['effective_to']);
        self::assertSame('suspended', $current['items'][0]['status']);
        self::assertSame('verified', $historical['items'][0]['proof_status']);
        self::assertSame('proven_utc', $historical['items'][0]['recorded_time_status']);
        self::assertArrayNotHasKey('member_number', $historical['items'][0]);
        self::assertArrayNotHasKey('legal_basis', $historical['items'][0]);
    }

    public function testPublicReceivableUsesStoredProofAndShowsUnpairedClaimAsUnpaid(): void
    {
        $member = $this->member('M-PUBLIC-CLAIM', 'public-claim');
        $issued = $this->issue($member['id'], 'public-claim');
        $claim = (int) $this->pdo->query('SELECT id FROM nonprofit_membership_charge_claims WHERE operation_id=' . (int) $issued['operation']['id'])->fetchColumn();

        $response = $this->catalog()->receivables($this->supplier, $member['id'], null, 25);
        $detail = $this->catalog()->receivables($this->supplier, null, null, 25, $claim);

        self::assertCount(1, $response['items']);
        self::assertSame($response['items'][0], $detail['items'][0]);
        self::assertSame('unreconciled', $detail['items'][0]['settlement']['status']);
        self::assertSame('0', $detail['items'][0]['settlement']['paid_cents']);
        self::assertSame('2000000', $detail['items'][0]['settlement']['open_cents']);
        self::assertSame('proven_utc', $detail['items'][0]['recorded_time_status']);
        self::assertStringEndsWith('Z', $detail['items'][0]['recorded_at']);
        self::assertSame('verified', $detail['items'][0]['proof_status']);
        foreach (['member_number', 'name', 'evidence', 'request_json', 'idempotency_key', 'record_hash'] as $forbidden) {
            self::assertArrayNotHasKey($forbidden, $detail['items'][0]);
        }
    }

    public function testPublicReceivableProofUsesConstantQueriesWithinPage(): void
    {
        $member = $this->member('M-PUBLIC-BATCH', 'public-batch');
        $this->issueTitle($member['id'], 'public-batch-1', 'public-batch-1');
        $one = $this->selectQueries(fn (): array => $this->catalog()->receivables($this->supplier, $member['id'], null, 100));
        for ($index = 2; $index <= 6; ++$index) $this->issueTitle($member['id'], 'public-batch-' . $index, 'public-batch-' . $index);
        $many = $this->selectQueries(fn (): array => $this->catalog()->receivables($this->supplier, $member['id'], null, 100));

        self::assertSame($one, $many);
        self::assertLessThanOrEqual(14, $many);
    }

    private function member(string $number, string $key): array
    {
        $currency = (int) $this->pdo->query('SELECT id FROM currencies ORDER BY id LIMIT 1')->fetchColumn();
        $this->pdo->prepare("INSERT INTO clients(supplier_id,company_name,street,city,zip,country_id,main_email,language,currency_default_id,is_customer,is_vendor) VALUES(?,'Synthetic member','Synthetic street','Synthetic city','10000',203,?,'cs',?,1,0)")
            ->execute([$this->supplier, $key . '@example.test', $currency]);
        $member = $this->members->create($this->supplier, (int) $this->pdo->lastInsertId(), $number, $this->actor);
        $this->members->appendRevision($this->supplier, $member['id'], [
            'status' => 'active', 'effective_from' => '2026-01-01', 'expected_revision' => 0,
            'idempotency_key' => 'member-' . $key, 'legal_basis' => $this->basis('Synthetic member evidence ' . $key),
        ], $this->actor);
        return $member;
    }

    private function catalog(): MembershipPublicCatalog
    {
        return new MembershipPublicCatalog($this->db, new MembershipRepository($this->db), new MembershipChargeRepository($this->db), $this->balances);
    }

    private function basis(string $evidence): array
    {
        return ['organisation_form' => 'association', 'relationship' => 'nonreciprocal_membership',
            'reference' => 'Synthetic association statutes article 5', 'evidence' => $evidence];
    }

    private function request(int $member): array
    {
        return ['member_id' => $member, 'title_key' => 'annual-2026', 'period_from' => '2026-01-01', 'period_to' => '2026-12-31',
            'accounting_date' => '2026-01-01', 'due_date' => '2026-01-31', 'amount' => '20000.00',
            'receivable_account' => '315.100', 'revenue_account' => '684.100', 'title_reference' => 'Synthetic statutes article 5',
            'evidence' => 'Synthetic approved annual membership resolution', 'nonreciprocal' => true, 'reason' => 'Synthetic annual membership charge',
            'classification' => $this->classification];
    }

    private function issue(int $member, string $key): array
    {
        $request = $this->request($member);
        $preview = $this->charges->previewIssue($this->supplier, $request);
        return $this->charges->confirmIssue($this->supplier, $request, $preview['expected_case_version'], $preview['preview_hash'], 'issue-' . $key, $this->actor)
            + ['preview_hash' => $preview['preview_hash']];
    }

    private function issueTitle(int $member, string $title, string $key): array
    {
        $request = $this->request($member);
        $request['title_key'] = $title;
        $preview = $this->charges->previewIssue($this->supplier, $request);
        return $this->charges->confirmIssue($this->supplier, $request, $preview['expected_case_version'], $preview['preview_hash'], $key, $this->actor);
    }

    private function selectQueries(callable $work): int
    {
        $before = (int) $this->pdo->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetch(PDO::FETCH_NUM)[1];
        $work();
        return (int) $this->pdo->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetch(PDO::FETCH_NUM)[1] - $before;
    }
}
