<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Nonprofit;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Nonprofit\Membership\MembershipRepository;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;
use PDO;
use PHPUnit\Framework\TestCase;

final class MembershipRepositoryScaleTest extends TestCase
{
    private Connection $db;
    private PDO $pdo;
    private MembershipRepository $repository;
    private int $supplier;
    private int $actor;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->pdo = $this->db->pdo();
        self::assertStringEndsWith('_test', (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn());
        $this->pdo->beginTransaction();

        $vat = (int) $this->pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn();
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        $this->pdo->prepare("INSERT INTO supplier(company_name,street,city,zip,email,country_id,default_currency_id,default_vat_rate_id,accounting_mode) VALUES('Synthetic membership scale association','Synthetic street','Synthetic city','10000','membership-scale@example.test',203,1,?,'double_entry')")
            ->execute([$vat]);
        $this->supplier = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO currencies(supplier_id,code,label,symbol,name_cs,name_en,decimals,is_active,is_default,account_number,bank_code) VALUES(?,'CZK','Synthetic CZK','Kč','Koruna','Crown',2,1,1,'1000000005','0100')")
            ->execute([$this->supplier]);
        $currency = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('UPDATE supplier SET default_currency_id=? WHERE id=?')->execute([$currency, $this->supplier]);
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        $this->pdo->prepare("INSERT INTO users(email,password_hash,name,role,locale,is_active) VALUES(?,?,'Synthetic membership scale actor','admin','cs',1)")
            ->execute(['membership-scale-actor@example.test', password_hash('Synthetic unused password', PASSWORD_BCRYPT, ['cost' => 4])]);
        $this->actor = (int) $this->pdo->lastInsertId();
        $this->repository = new MembershipRepository($this->db);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) $this->pdo->rollBack();
    }

    public function testVerifiedRevisionScanReadsOnlySelectedMemberChains(): void
    {
        $selected = $this->member('SELECTED');
        $selectedRevision = $this->append($selected, 1, null);
        $unrelated = [];
        $expected = [];
        for ($memberIndex = 1; $memberIndex <= 100; ++$memberIndex) {
            $member = $this->member('UNRELATED-' . $memberIndex);
            $unrelated[] = $member;
            $previous = null;
            for ($revision = 1; $revision <= 5; ++$revision) $previous = $this->append($member, $revision, $previous);
            $expected[$member] = $previous['id'];
        }

        $legacy = $this->legacyAnalysis($selected);
        self::assertSame('uq_nmr_tenant', $legacy['key']);
        self::assertSame(501, $legacy['r_rows']);
        $bounded = $this->boundedAnalysis($selected);
        self::assertSame('uq_nmr_chain', $bounded['key']);
        self::assertSame(1, $bounded['r_rows']);

        $before = $this->handlerReads();
        $one = $this->repository->effectiveMany($this->supplier, [$selected], '2026-12-31', '2099-01-01 00:00:00.000000');
        $singleReads = $this->handlerReads() - $before;

        self::assertSame($selectedRevision['id'], $one[$selected]['id']);
        self::assertLessThanOrEqual(8, $singleReads, 'One selected chain must not scan 500 unrelated revisions.');

        $before = $this->handlerReads();
        $many = $this->repository->effectiveMany($this->supplier, array_reverse($unrelated), '2026-12-31', '2099-01-01 00:00:00.000000');
        $batchReads = $this->handlerReads() - $before;

        self::assertCount(100, $many);
        foreach ($expected as $member => $revision) self::assertSame($revision, $many[$member]['id']);
        self::assertLessThanOrEqual(510, $batchReads, 'A full 500-row proof batch must stay physically bounded.');
    }

    private function member(string $key): int
    {
        $currency = (int) $this->pdo->query('SELECT id FROM currencies WHERE supplier_id=' . $this->supplier . ' ORDER BY id LIMIT 1')->fetchColumn();
        $insert = $this->pdo->prepare("INSERT INTO clients(supplier_id,company_name,street,city,zip,country_id,main_email,language,currency_default_id,is_customer,is_vendor) VALUES(?,'Synthetic scale member','Synthetic street','Synthetic city','10000',203,?,'cs',?,1,0)");
        $insert->execute([$this->supplier, strtolower($key) . '@example.test', $currency]);
        $client = (int) $this->pdo->lastInsertId();
        return $this->repository->insertMember($this->supplier, $client, 'M-' . $key, $this->actor)['id'];
    }

    private function append(int $member, int $revision, ?array $previous): array
    {
        $effective = sprintf('2026-01-%02d', $revision);
        $basis = ['organisation_form' => 'association', 'relationship' => 'nonreciprocal_membership',
            'reference' => 'Synthetic association statutes', 'evidence' => 'Synthetic scale membership evidence'];
        $request = ['member_id' => $member, 'status' => 'active', 'effective_from' => $effective, 'legal_basis' => $basis,
            'expected_revision' => $revision - 1, 'actor' => $this->actor];
        return $this->repository->appendRevision([
            'supplier_id' => $this->supplier, 'member_id' => $member, 'revision_no' => $revision,
            'previous_id' => $previous['id'] ?? null, 'effective_from' => $effective, 'status' => 'active',
            'legal_basis' => $basis, 'request' => $request, 'idempotency_key' => 'scale-' . $member . '-' . $revision,
            'request_hash' => NonprofitTaxRules::hash($request), 'parent_hash' => $previous['record_hash'] ?? str_repeat('0', 64),
            'created_by' => $this->actor,
        ]);
    }

    private function handlerReads(): int
    {
        $q = $this->pdo->query("SHOW SESSION STATUS WHERE Variable_name IN ('Handler_read_next','Handler_read_rnd_next')");
        return array_sum(array_map('intval', $q->fetchAll(PDO::FETCH_KEY_PAIR)));
    }

    private function legacyAnalysis(int $member): array
    {
        $q = $this->pdo->prepare("ANALYZE FORMAT=JSON SELECT * FROM nonprofit_member_revisions FORCE INDEX(uq_nmr_tenant) WHERE supplier_id=? AND member_id IN (?) AND id>? AND created_at<=? ORDER BY supplier_id,id LIMIT 500");
        $q->execute([$this->supplier, $member, 0, '2099-01-01 00:00:00.000000']);
        $plan = json_decode((string) $q->fetchColumn(), true, flags: JSON_THROW_ON_ERROR);
        return $plan['query_block']['nested_loop'][0]['table'];
    }

    private function boundedAnalysis(int $member): array
    {
        $q = $this->pdo->prepare("ANALYZE FORMAT=JSON SELECT * FROM nonprofit_member_revisions FORCE INDEX(uq_nmr_chain) WHERE supplier_id=? AND member_id IN (?) AND (recorded_at_utc<=? OR recorded_at_utc IS NULL) ORDER BY supplier_id,member_id,id LIMIT 500");
        $q->execute([$this->supplier, $member, '2099-01-01 00:00:00.000000']);
        $plan = json_decode((string) $q->fetchColumn(), true, flags: JSON_THROW_ON_ERROR);
        return $plan['query_block']['nested_loop'][0]['table'];
    }
}
