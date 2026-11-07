<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\Nonprofit\Membership\MembershipBatchRepository;
use MyInvoice\Repository\Nonprofit\Membership\MembershipChargeRepository;
use MyInvoice\Repository\Nonprofit\Membership\MembershipRateRepository;
use MyInvoice\Repository\Nonprofit\Membership\MembershipRepository;
use MyInvoice\Service\Accounting\Dimension\SystemDimensionCatalog;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Nonprofit\Membership\MembershipBatchService;
use MyInvoice\Service\Nonprofit\Membership\MembershipChargeService;
use MyInvoice\Service\Nonprofit\Membership\MembershipException;
use MyInvoice\Service\Nonprofit\Membership\MembershipMemberService;
use MyInvoice\Service\Tax\Nonprofit\NonprofitLegalProfileService;
use PDO;
use Psr\Container\ContainerInterface;
use PHPUnit\Framework\TestCase;

/** Regrese review dávky; samostatná syntetická fixture nezasahuje do paralelních testů. */
abstract class MembershipBatchReviewFixture extends TestCase
{
    protected Connection $db;
    protected PDO $pdo;
    protected int $supplier;
    protected int $actor;
    protected array $classification;
    protected MembershipMemberService $members;
    protected MembershipChargeService $charges;
    protected MembershipBatchService $batches;
    protected array $template;

    protected function setUp(): void
    {
        $container = $this->fixtureContainer();
        $this->db = $container->get(Connection::class);
        $this->pdo = $this->db->pdo();
        self::assertStringEndsWith('_test', (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn());
        $this->pdo->beginTransaction();
        $vat = (int) $this->pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn();
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        try {
            $this->pdo->prepare("INSERT INTO supplier(company_name,street,city,zip,email,country_id,default_currency_id,default_vat_rate_id,accounting_mode) VALUES('Synthetic batch review association','Synthetic street','Synthetic city','10000','batch-review@example.test',203,1,?,'double_entry')")->execute([$vat]);
            $this->supplier = (int) $this->pdo->lastInsertId();
            $this->pdo->prepare("INSERT INTO currencies(supplier_id,code,label,symbol,name_cs,name_en,decimals,is_active,is_default,account_number,bank_code) VALUES(?,'CZK','Synthetic CZK','Kč','Koruna','Crown',2,1,1,'1000000005','0100')")->execute([$this->supplier]);
            $this->pdo->prepare('UPDATE supplier SET default_currency_id=? WHERE id=?')->execute([(int) $this->pdo->lastInsertId(), $this->supplier]);
        } finally {
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        }
        $this->pdo->prepare("INSERT INTO users(email,password_hash,name,role,locale,is_active) VALUES(?,?,'Synthetic batch actor','admin','cs',1)")
            ->execute(['batch-review-actor-' . $this->supplier . '@example.test', password_hash('Synthetic unused password', PASSWORD_BCRYPT, ['cost' => 4])]);
        $this->actor = (int) $this->pdo->lastInsertId();
        $period = $container->get(AccountingPeriodRepository::class)->create($this->supplier, 2026, '2026-01-01', '2026-12-31');
        $this->pdo->prepare("INSERT INTO supplier_accounting_frameworks(supplier_id,effective_from,accounting_framework) VALUES(?,'2026-01-01','cz_504')")->execute([$this->supplier]);
        $axes = (new SystemDimensionCatalog($this->db))->ensureForFramework($this->supplier, '2026-01-01', $this->actor);
        $this->pdo->prepare("INSERT INTO dimension_values(type_id,supplier_id,code,name) VALUES(?,?,'BATCH-REVIEW','Synthetic batch activity')")->execute([$axes['activity_kind']['id'], $this->supplier]);
        $this->classification = ['activity_value_id' => $axes['activity']['values']['main'], 'activity_kind_value_id' => (int) $this->pdo->lastInsertId(), 'tax_regime_value_id' => $axes['tax_regime']['values']['subject']];
        foreach (['315.100' => 'asset', '684.100' => 'revenue'] as $code => $type) {
            $this->pdo->prepare('INSERT INTO chart_of_accounts(supplier_id,account_code,name,account_type) VALUES(?,?,?,?)')->execute([$this->supplier, $code, 'Synthetic ' . $code, $type]);
        }
        (new NonprofitLegalProfileService($this->db))->confirm($this->supplier, $period, ['expected_revision' => 0, 'idempotency_key' => 'batch-review-legal', 'reason' => 'Synthetic reviewed statutes',
            'profile' => ['kind' => 'narrow_vpp', 'entity_type' => 'association', 'main_activity_nonbusiness' => true, 'legal_basis' => 'zdp_17a', 'evidence' => 'Synthetic signed statutes']], $this->actor);
        $repository = new MembershipRepository($this->db);
        $this->members = new MembershipMemberService($this->db, $repository);
        $this->charges = new MembershipChargeService($this->db, $repository, new MembershipChargeRepository($this->db), $container->get(PostingService::class));
        $this->batches = new MembershipBatchService($this->db, $this->charges);
        $rate = (new MembershipRateRepository($this->db))->save($this->supplier, null, ['name' => 'Synthetic annual rate', 'amount' => '500.00', 'receivable_account' => '315.100', 'revenue_account' => '684.100', 'title_key' => 'synthetic-review',
            'period_months' => 12, 'title_reference' => 'Synthetic decision', 'evidence' => 'Synthetic signed decision', 'classification' => $this->classification, 'is_active' => 1], 0);
        $this->template = ['rate_id' => $rate['id'], 'title_key' => 'synthetic-review', 'amount' => '500.00', 'receivable_account' => '315.100', 'revenue_account' => '684.100',
            'period_from' => '2026-01-01', 'period_to' => '2026-12-31', 'accounting_date' => '2026-01-01', 'due_date' => '2026-01-31', 'classification' => $this->classification,
            'title_reference' => 'Synthetic decision', 'evidence' => 'Synthetic signed decision', 'reason' => 'Synthetic batch charge', 'nonreciprocal' => true];
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) $this->pdo->rollBack();
    }

    protected function fixtureContainer(): ContainerInterface
    {
        return Bootstrap::buildContainer();
    }

    protected function member(string $number): int
    {
        $q = $this->pdo->prepare('SELECT default_currency_id FROM supplier WHERE id=?'); $q->execute([$this->supplier]);
        $this->pdo->prepare("INSERT INTO clients(supplier_id,company_name,street,city,zip,country_id,main_email,language,currency_default_id,is_customer,is_vendor) VALUES(?,'Synthetic batch member','Synthetic street','Synthetic city','10000',203,?,'cs',?,1,0)")->execute([$this->supplier, strtolower($number) . '@example.test', (int) $q->fetchColumn()]);
        $member = $this->members->create($this->supplier, (int) $this->pdo->lastInsertId(), 'SYN-' . $number, $this->actor);
        $this->members->appendRevision($this->supplier, $member['id'], ['status' => 'active', 'effective_from' => '2026-01-01', 'expected_revision' => 0, 'idempotency_key' => 'review-' . $number, 'legal_basis' => $this->basis()], $this->actor);
        return $member['id'];
    }

    protected function basis(): array
    {
        return ['organisation_form' => 'association', 'relationship' => 'nonreciprocal_membership', 'reference' => 'Synthetic statutes', 'evidence' => 'Synthetic signed membership evidence'];
    }

    protected function input(array $ids, string $key = 'review-batch'): array
    {
        return ['template' => $this->template, 'selection_mode' => 'selected', 'member_ids' => $ids, 'idempotency_key' => $key];
    }

    protected function ready(array $ids, string $key = 'review-batch'): array
    {
        $created = $this->batches->create($this->supplier, $this->input($ids, $key), $this->actor);
        return $this->batches->prepare($this->supplier, $created['batch']['id']);
    }

    protected function operationCount(): int
    {
        $q = $this->pdo->prepare('SELECT COUNT(*) FROM nonprofit_membership_charge_operations WHERE supplier_id=?'); $q->execute([$this->supplier]);
        return (int) $q->fetchColumn();
    }

    protected function assertConfirmRejected(int $id, string $token): void
    {
        try { $this->batches->confirm($this->supplier, $id, $token, $this->actor); self::fail('Neplatný souhlas byl přijat.'); }
        catch (MembershipException $e) { self::assertSame(409, $e->httpStatus); }
    }

    protected function assertCounters(int $id): void
    {
        $q = $this->pdo->prepare("SELECT COUNT(*) total,COALESCE(SUM(status='pending'),0) pending,COALESCE(SUM(status='ready'),0) ready,COALESCE(SUM(status='created'),0) created,COALESCE(SUM(status='existing'),0) existing,COALESCE(SUM(status='rejected'),0) rejected,
            CAST(COALESCE(SUM(IF(status IN ('ready','created'),amount_cents,0)),0) AS CHAR) planned_cents,CAST(COALESCE(SUM(IF(status='created',amount_cents,0)),0) AS CHAR) created_cents,
            CAST(COALESCE(SUM(IF(status IN ('ready','created'),deferred_cents,0)),0) AS CHAR) deferred_cents,MIN(IF(status IN ('ready','created') AND deferred_cents>0,deferral_date,NULL)) deferral_date
            FROM nonprofit_membership_batch_members WHERE supplier_id=? AND batch_id=?");
        $q->execute([$this->supplier, $id]); $expected = $q->fetch(PDO::FETCH_ASSOC);
        foreach (['total', 'pending', 'ready', 'created', 'existing', 'rejected'] as $field) $expected[$field] = (int) $expected[$field];
        self::assertSame($expected, (new MembershipBatchRepository($this->db))->summary($this->supplier, $id));
    }
}
