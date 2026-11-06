<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Nonprofit;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Nonprofit\Membership\MembershipBalanceRepository;
use MyInvoice\Repository\Nonprofit\Membership\MembershipChargeRepository;
use MyInvoice\Repository\Nonprofit\Membership\MembershipRepository;
use MyInvoice\Service\Nonprofit\Membership\MembershipException;
use MyInvoice\Service\Nonprofit\Membership\MembershipPublicCatalog;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\TestCase;

final class MembershipRecordedTimeCompatibilityTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PDO $pdo;
    private MembershipRepository $members;
    private int $supplier;
    private int $actor;
    private int $client;
    private string $timeZone;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->pdo = $this->db->pdo();
        self::assertStringEndsWith('_test', (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn());
        $this->timeZone = (string) $this->pdo->query('SELECT @@session.time_zone')->fetchColumn();
        $this->pdo->beginTransaction();
        $seed = (int) $this->pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        $this->supplier = $this->createIsolatedSupplier($this->pdo, $seed);
        $this->pdo->prepare("INSERT INTO currencies(supplier_id,code,label,symbol,name_cs,name_en,decimals,is_active,is_default,account_number,bank_code) VALUES(?,'CZK','Synthetic CZK','Kč','Koruna','Crown',2,1,1,'1000000005','0100')")
            ->execute([$this->supplier]);
        $currency = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('UPDATE supplier SET default_currency_id=? WHERE id=?')->execute([$currency, $this->supplier]);
        $this->actor = (int) $this->pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        $this->pdo->prepare("INSERT INTO clients(supplier_id,company_name,street,city,zip,country_id,main_email,language,currency_default_id,is_customer,is_vendor) VALUES(?,'Synthetic UTC member','Synthetic street','Synthetic city','10000',203,'membership-utc@example.test','cs',?,1,0)")
            ->execute([$this->supplier, $currency]);
        $this->client = (int) $this->pdo->lastInsertId();
        $this->members = new MembershipRepository($this->db);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) $this->pdo->rollBack();
        if (isset($this->pdo, $this->timeZone)) $this->pdo->exec('SET time_zone=' . $this->pdo->quote($this->timeZone));
    }

    public function testLegacyLocalTimestampCannotBecomeVerifiedKnownAtAfterUtcOwnerRevision(): void
    {
        $this->pdo->exec("SET time_zone='+02:00'");
        $this->pdo->prepare('INSERT INTO nonprofit_members(supplier_id,client_id,member_number,created_by,created_at) VALUES(?,?,?,?,CURRENT_TIMESTAMP(6))')
            ->execute([$this->supplier, $this->client, 'M-UTC-LEGACY', $this->actor]);
        $member = (int) $this->pdo->lastInsertId();
        $first = $this->revision($member, 1, null, '2026-01-01', 'legacy-local');
        $this->insertLegacyRevision($first);

        $this->pdo->exec("SET time_zone='+00:00'");
        $second = $this->revision($member, 2, $first, '2026-02-01', 'utc-owner');
        $this->members->appendRevision($second);

        $catalog = new MembershipPublicCatalog($this->db, $this->members,
            new MembershipChargeRepository($this->db), new MembershipBalanceRepository($this->db));
        try {
            $catalog->roles($this->supplier, '2026-06-01', '2099-01-01T00:00:00.000000Z', null, 25, $member);
            self::fail('Legacy local membership time must not be presented as verified UTC knowledge.');
        } catch (MembershipException $error) {
            self::assertSame('nonprofit_membership_recorded_time_unproven', $error->errorCode);
            self::assertSame(409, $error->httpStatus);
        }
    }

    public function testOwnerWritesSameExplicitUtcUnderPragueAndPlusTwoSession(): void
    {
        $oldPhpTimeZone = date_default_timezone_get();
        date_default_timezone_set('Europe/Prague');
        try {
            $this->pdo->exec("SET time_zone='+02:00'");
            $member = $this->members->insertMember($this->supplier, $this->client, 'M-UTC-OWNER', $this->actor);
            self::assertSame($member['created_at'], $member['recorded_at_utc']);
            self::assertMatchesRegularExpression('/^\\d{4}-\\d{2}-\\d{2} \\d{2}:\\d{2}:\\d{2}\\.\\d{6}$/D', $member['recorded_at_utc']);
            self::assertLessThanOrEqual(5, abs(time() - (new \DateTimeImmutable($member['recorded_at_utc'], new \DateTimeZone('UTC')))->getTimestamp()));

            $revision = $this->members->appendRevision($this->revision($member['id'], 1, null, '2026-01-01', 'utc-owner-write'));
            self::assertSame($revision['created_at'], $revision['recorded_at_utc']);
            self::assertSame($revision['record_hash'], NonprofitTaxRules::hash(MembershipRepository::revisionRecord($revision)));
        } finally {
            date_default_timezone_set($oldPhpTimeZone);
        }
    }

    public function testKnownAtUsesExactUtcAcrossSpringAndAutumnDstTransitions(): void
    {
        $this->pdo->exec("SET time_zone='+02:00'");
        $this->pdo->prepare('INSERT INTO nonprofit_members(supplier_id,client_id,member_number,created_by,created_at,recorded_at_utc) VALUES(?,?,?,?,?,?)')
            ->execute([$this->supplier, $this->client, 'M-UTC-DST', $this->actor, '2026-03-28 23:00:00.000000', '2026-03-28 23:00:00.000000']);
        $member = (int) $this->pdo->lastInsertId();
        $first = $this->revision($member, 1, null, '2026-01-01', 'dst-first');
        $this->insertProvenRevision($first, '2026-03-29 00:30:00.123456');
        $second = $this->revision($member, 2, $first, '2026-04-01', 'dst-second');
        $second['status'] = 'suspended';
        $second['request']['status'] = 'suspended';
        $second['request_hash'] = NonprofitTaxRules::hash($second['request']);
        $this->insertProvenRevision($second, '2026-03-29 01:30:00.654321');
        $third = $this->revision($member, 3, $second, '2026-07-01', 'dst-third');
        $this->insertProvenRevision($third, '2026-10-25 00:30:00.123456');
        $fourth = $this->revision($member, 4, $third, '2026-09-01', 'dst-fourth');
        $fourth['status'] = 'ended';
        $fourth['request']['status'] = 'ended';
        $fourth['request_hash'] = NonprofitTaxRules::hash($fourth['request']);
        $this->insertProvenRevision($fourth, '2026-10-25 01:30:00.654321');

        $catalog = new MembershipPublicCatalog($this->db, $this->members,
            new MembershipChargeRepository($this->db), new MembershipBalanceRepository($this->db));
        $spring = $catalog->roles($this->supplier, '2026-12-31', '2026-03-29T01:00:00.000000Z', null, 25, $member);
        self::assertSame($first['id'], $spring['items'][0]['revision_id']);
        self::assertSame('2026-03-29T00:30:00.123456Z', $spring['items'][0]['recorded_at']);
        $autumn = $catalog->roles($this->supplier, '2026-12-31', '2026-10-25T01:00:00.000000Z', null, 25, $member);
        self::assertSame($third['id'], $autumn['items'][0]['revision_id']);
        self::assertSame('2026-10-25T00:30:00.123456Z', $autumn['items'][0]['recorded_at']);
    }

    private function revision(int $member, int $number, ?array $previous, string $effective, string $token): array
    {
        $basis = ['organisation_form' => 'association', 'relationship' => 'nonreciprocal_membership',
            'reference' => 'Synthetic statutes', 'evidence' => 'Synthetic membership decision'];
        $request = ['member_id' => $member, 'status' => 'active', 'effective_from' => $effective,
            'legal_basis' => $basis, 'expected_revision' => $number - 1, 'actor' => $this->actor];
        return ['supplier_id' => $this->supplier, 'member_id' => $member, 'revision_no' => $number,
            'previous_id' => $previous['id'] ?? null, 'effective_from' => $effective, 'status' => 'active',
            'legal_basis' => $basis, 'request' => $request, 'idempotency_key' => $token,
            'request_hash' => NonprofitTaxRules::hash($request), 'parent_hash' => $previous['record_hash'] ?? str_repeat('0', 64),
            'created_by' => $this->actor];
    }

    private function insertLegacyRevision(array &$row): void
    {
        $row['record_hash'] = NonprofitTaxRules::hash($this->members->revisionRecord($row));
        $q = $this->pdo->prepare('INSERT INTO nonprofit_member_revisions(supplier_id,member_id,revision_no,previous_id,effective_from,status,legal_basis_json,request_json,idempotency_key,request_hash,parent_hash,record_hash,created_by,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP(6))');
        $q->execute([$row['supplier_id'], $row['member_id'], $row['revision_no'], $row['previous_id'], $row['effective_from'], $row['status'],
            NonprofitTaxRules::canonicalJson($row['legal_basis']), NonprofitTaxRules::canonicalJson($row['request']), $row['idempotency_key'],
            $row['request_hash'], $row['parent_hash'], $row['record_hash'], $row['created_by']]);
        $row['id'] = (int) $this->pdo->lastInsertId();
    }

    private function insertProvenRevision(array &$row, string $recordedAtUtc): void
    {
        $row['recorded_at_utc'] = $recordedAtUtc;
        $row['record_hash'] = NonprofitTaxRules::hash(MembershipRepository::revisionRecord($row));
        $q = $this->pdo->prepare('INSERT INTO nonprofit_member_revisions(supplier_id,member_id,revision_no,previous_id,effective_from,status,legal_basis_json,request_json,idempotency_key,request_hash,parent_hash,record_hash,created_by,created_at,recorded_at_utc) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $q->execute([$row['supplier_id'], $row['member_id'], $row['revision_no'], $row['previous_id'], $row['effective_from'], $row['status'],
            NonprofitTaxRules::canonicalJson($row['legal_basis']), NonprofitTaxRules::canonicalJson($row['request']), $row['idempotency_key'],
            $row['request_hash'], $row['parent_hash'], $row['record_hash'], $row['created_by'], $recordedAtUtc, $recordedAtUtc]);
        $row['id'] = (int) $this->pdo->lastInsertId();
    }
}
