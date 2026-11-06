<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Tax;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\Nonprofit\Membership\MembershipChargeRepository;
use MyInvoice\Repository\Nonprofit\Membership\MembershipRepository;
use MyInvoice\Service\Accounting\Dimension\SystemDimensionCatalog;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Nonprofit\Membership\MembershipChargeService;
use MyInvoice\Service\Nonprofit\Membership\MembershipException;
use MyInvoice\Service\Nonprofit\Membership\MembershipMemberService;
use MyInvoice\Service\Tax\Nonprofit\NonprofitLegalProfileService;
use MyInvoice\Service\Tax\Nonprofit\SourceFacts\MembershipJ6SourceFacts;
use PDO;
use PHPUnit\Framework\TestCase;

final class MembershipJ6SourceFactsTest extends TestCase
{
    private Connection $db;
    private PDO $pdo;
    private MembershipMemberService $members;
    private MembershipChargeService $charges;
    private int $supplier;
    private int $actor;
    private array $classification;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->pdo = $this->db->pdo();
        $configured = (string) getenv('MYINVOICE_DB_NAME');
        self::assertStringEndsWith('_test', $configured);
        self::assertSame($configured, (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn());
        $this->pdo->beginTransaction();
        $vat = (int) $this->pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn();
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        $this->pdo->prepare("INSERT INTO supplier(company_name,street,city,zip,email,country_id,default_currency_id,default_vat_rate_id,accounting_mode) VALUES('Synthetic J6 membership association','Synthetic street','Synthetic city','10000','j6-membership@example.test',203,1,?,'double_entry')")
            ->execute([$vat]);
        $this->supplier = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO currencies(supplier_id,code,label,symbol,name_cs,name_en,decimals,is_active,is_default,account_number,bank_code) VALUES(?,'CZK','Synthetic CZK','Kč','Koruna','Crown',2,1,1,'1000000005','0100')")
            ->execute([$this->supplier]);
        $currency = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('UPDATE supplier SET default_currency_id=? WHERE id=?')->execute([$currency, $this->supplier]);
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        $this->actor = (int) $this->pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        $period = (new AccountingPeriodRepository($this->db))->create($this->supplier, 2026, '2026-01-01', '2026-12-31');
        $this->pdo->prepare("INSERT INTO supplier_accounting_frameworks(supplier_id,effective_from,accounting_framework) VALUES(?,'2026-01-01','cz_504')")
            ->execute([$this->supplier]);
        $axes = (new SystemDimensionCatalog($this->db))->ensureForFramework($this->supplier, '2026-01-01', $this->actor);
        $this->pdo->prepare("INSERT INTO dimension_values(type_id,supplier_id,code,name) VALUES(?,?,'MEMBERSHIP','Synthetic membership activity')")
            ->execute([$axes['activity_kind']['id'], $this->supplier]);
        $this->classification = ['activity_value_id' => $axes['activity']['values']['main'],
            'activity_kind_value_id' => (int) $this->pdo->lastInsertId(), 'tax_regime_value_id' => $axes['tax_regime']['values']['subject']];
        foreach (['315.100' => 'asset', '684.100' => 'revenue'] as $code => $type) {
            $this->pdo->prepare('INSERT INTO chart_of_accounts(supplier_id,account_code,name,account_type) VALUES(?,?,?,?)')
                ->execute([$this->supplier, $code, 'Synthetic ' . $code, $type]);
        }
        (new NonprofitLegalProfileService($this->db))->confirm($this->supplier, $period, [
            'expected_revision' => 0, 'idempotency_key' => 'j6-membership-legal', 'reason' => 'Synthetic reviewed statutes',
            'profile' => ['kind' => 'narrow_vpp', 'entity_type' => 'association', 'main_activity_nonbusiness' => true,
                'legal_basis' => 'zdp_17a', 'evidence' => 'Synthetic association statutes'],
        ], $this->actor);
        $repository = new MembershipRepository($this->db);
        $this->members = new MembershipMemberService($this->db, $repository);
        $this->charges = new MembershipChargeService($this->db, $repository, new MembershipChargeRepository($this->db), $container->get(PostingService::class));
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) $this->pdo->rollBack();
    }

    public function testProviderBindsBatchedImmutableMembershipProofWithoutTaxQualification(): void
    {
        $member = $this->member();
        $operations = [];
        for ($index = 1; $index <= 6; ++$index) $operations[] = $this->issue($member['id'], 'annual-' . $index, 'issue-' . $index)['operation'];
        $rows = array_map($this->row(...), $operations);
        $provider = new MembershipJ6SourceFacts($this->db);
        $oneQueries = $this->selectQueries(fn () => $provider->capture($this->supplier, [$rows[0]], true));
        $manyQueries = $this->selectQueries(fn () => $provider->capture($this->supplier, $rows, true));
        self::assertSame($oneQueries, $manyQueries);
        self::assertLessThanOrEqual(8, $manyQueries);

        $facts = $provider->capture($this->supplier, $rows, true);
        self::assertCount(6, $facts);
        foreach ($operations as $operation) {
            $fact = $facts[$operation['revenue_line_id']];
            self::assertSame('unclassified_requires_explicit_j6_decision', $fact['tax_treatment']);
            self::assertArrayNotHasKey('qualifying_membership', $fact);
            self::assertSame($operation['record_hash'], $fact['operation_record_hash']);
            self::assertSame($operation['revenue_line_id'], $fact['journal_line_id']);
            self::assertSame($operation['proof']['classification'], $fact['classification']);
            self::assertSame(\MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules::hash($operation['proof']['classification']), $fact['classification_hash']);
        }

        $preview = $this->charges->previewReverse($this->supplier, ['case_id' => $operations[0]['case_id'],
            'accounting_date' => '2026-12-31', 'reason' => 'Synthetic J6 reversal']);
        $reversed = $this->charges->confirmReverse($this->supplier, ['case_id' => $operations[0]['case_id'],
            'accounting_date' => '2026-12-31', 'reason' => 'Synthetic J6 reversal'], $preview['expected_case_version'],
            $preview['preview_hash'], 'j6-reverse', $this->actor)['operation'];
        $reverseFacts = $provider->capture($this->supplier, [$this->row($reversed)], true);
        self::assertSame(-$reversed['amount_cents'], $reverseFacts[$reversed['revenue_line_id']]['amount_cents']);
        self::assertSame($operations[0]['id'], $reverseFacts[$reversed['revenue_line_id']]['reversal_of_operation_id']);

        $tampered = $rows[0];
        ++$tampered['amount_cents'];
        try {
            $provider->capture($this->supplier, [$tampered], true);
            self::fail('Membership source fact must bind the exact journal amount.');
        } catch (MembershipException $failure) {
            self::assertSame('nonprofit_membership_j6_proof_invalid', $failure->errorCode);
        }
        $tampered = $rows[1];$tampered['axes']['activity']['value_id'] = $tampered['axes']['tax_regime']['value_id'];
        try{$provider->capture($this->supplier, [$tampered], true);self::fail('Membership source fact accepted a changed 504 classification.');}
        catch(MembershipException $failure){self::assertSame('nonprofit_membership_j6_proof_invalid',$failure->errorCode);}
    }

    public function testJ6RecordHashSsotPreservesLegacyNullAndBindsUtc(): void
    {
        $claim = ['supplier_id' => 1, 'case_id' => 2, 'operation_id' => 3, 'original_claim_id' => null,
            'member_id' => 4, 'client_id' => 5, 'kind' => 'issue', 'amount_cents' => 600, 'recorded_at_utc' => null];
        self::assertArrayNotHasKey('recorded_at_utc', MembershipChargeRepository::claimRecord($claim));
        $claim['recorded_at_utc'] = '2026-10-19 10:00:00.123456';
        self::assertSame($claim['recorded_at_utc'], MembershipChargeRepository::claimRecord($claim)['recorded_at_utc']);

        $revision = ['supplier_id' => 1, 'member_id' => 4, 'revision_no' => 1, 'previous_id' => null,
            'effective_from' => '2026-01-01', 'status' => 'active', 'legal_basis' => [], 'request' => [],
            'idempotency_key' => 'synthetic', 'request_hash' => hash('sha256', 'request'),
            'parent_hash' => str_repeat('0', 64), 'created_by' => 7, 'recorded_at_utc' => null];
        self::assertArrayNotHasKey('recorded_at_utc', MembershipRepository::revisionRecord($revision));
        $revision['recorded_at_utc'] = '2026-10-19 10:00:00.654321';
        self::assertSame($revision['recorded_at_utc'], MembershipRepository::revisionRecord($revision)['recorded_at_utc']);

        $source = (string) file_get_contents((new \ReflectionClass(MembershipJ6SourceFacts::class))->getFileName());
        self::assertStringContainsString('MembershipChargeRepository::claimRecord($claimRecord)', $source);
        self::assertStringContainsString('MembershipRepository::revisionRecord($revisionRecord)', $source);
    }

    private function member(): array
    {
        $currency = (int) $this->pdo->query('SELECT id FROM currencies WHERE supplier_id=' . $this->supplier . ' ORDER BY id LIMIT 1')->fetchColumn();
        $this->pdo->prepare("INSERT INTO clients(supplier_id,company_name,street,city,zip,country_id,main_email,language,currency_default_id,is_customer,is_vendor) VALUES(?,'Synthetic J6 member','Synthetic street','Synthetic city','10000',203,'j6-member@example.test','cs',?,1,0)")
            ->execute([$this->supplier, $currency]);
        $member = $this->members->create($this->supplier, (int) $this->pdo->lastInsertId(), 'J6-001', $this->actor);
        $this->members->appendRevision($this->supplier, $member['id'], [
            'status' => 'active', 'effective_from' => '2026-01-01', 'expected_revision' => 0, 'idempotency_key' => 'j6-member-active',
            'legal_basis' => ['organisation_form' => 'association', 'relationship' => 'nonreciprocal_membership',
                'reference' => 'Synthetic statutes article 5', 'evidence' => 'Synthetic membership evidence'],
        ], $this->actor);
        return $member;
    }

    private function issue(int $member, string $title, string $key): array
    {
        $request = ['member_id' => $member, 'title_key' => $title, 'period_from' => '2026-01-01', 'period_to' => '2026-12-31',
            'accounting_date' => '2026-01-01', 'due_date' => '2026-01-31', 'amount' => '20000.00',
            'receivable_account' => '315.100', 'revenue_account' => '684.100', 'title_reference' => 'Synthetic statutes article 5',
            'evidence' => 'Synthetic approved membership resolution', 'nonreciprocal' => true, 'reason' => 'Synthetic membership charge',
            'classification' => $this->classification];
        $preview = $this->charges->previewIssue($this->supplier, $request);
        return $this->charges->confirmIssue($this->supplier, $request, $preview['expected_case_version'], $preview['preview_hash'], $key, $this->actor);
    }

    private function row(array $operation): array
    {
        $axes=[];foreach($operation['proof']['classification'] as $axis=>$proof)$axes[$axis]=['type_id'=>$proof['type_id'],'value_id'=>$proof['value_id']];
        return ['line_id' => (int) $operation['revenue_line_id'], 'entry_id' => (int) $operation['journal_entry_id'],
            'date' => $operation['accounting_date'], 'amount_cents' => $operation['operation'] === 'issue' ? (int) $operation['amount_cents'] : -(int) $operation['amount_cents'],
            'kind' => 'revenue', 'source_type' => 'nonprofit_membership', 'axes'=>$axes];
    }

    private function selectQueries(callable $work): int
    {
        $before = (int) $this->pdo->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetch(PDO::FETCH_NUM)[1];
        $work();
        return (int) $this->pdo->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetch(PDO::FETCH_NUM)[1] - $before;
    }
}
