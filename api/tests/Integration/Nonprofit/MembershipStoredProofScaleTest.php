<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Nonprofit;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\Nonprofit\Membership\MembershipRepository;
use MyInvoice\Service\Nonprofit\Membership\MembershipStoredProof;
use MyInvoice\Service\Tax\Nonprofit\NonprofitDppoLegalRules;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class MembershipStoredProofScaleTest extends TestCase
{
    private Connection $db;
    private PDO $pdo;
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
        $this->pdo->prepare("INSERT INTO supplier(company_name,street,city,zip,email,country_id,default_currency_id,default_vat_rate_id,accounting_mode) VALUES('Synthetic legal profile scale association','Synthetic street','Synthetic city','10000','legal-profile-scale@example.test',203,1,?,'double_entry')")
            ->execute([$vat]);
        $this->supplier = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO currencies(supplier_id,code,label,symbol,name_cs,name_en,decimals,is_active,is_default,account_number,bank_code) VALUES(?,'CZK','Synthetic CZK','Kč','Koruna','Crown',2,1,1,'1000000005','0100')")
            ->execute([$this->supplier]);
        $currency = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('UPDATE supplier SET default_currency_id=? WHERE id=?')->execute([$currency, $this->supplier]);
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        $this->pdo->prepare("INSERT INTO users(email,password_hash,name,role,locale,is_active) VALUES(?,?,'Synthetic legal profile scale actor','admin','cs',1)")
            ->execute(['legal-profile-scale-actor@example.test', password_hash('Synthetic unused password', PASSWORD_BCRYPT, ['cost' => 4])]);
        $this->actor = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) $this->pdo->rollBack();
    }

    public function testLegalProfileProofReadsOnlySelectedPeriodChains(): void
    {
        $selectedPeriod = $this->period(2099);
        $selectedProfile = $this->append($selectedPeriod, 1, null);
        $periods = [];
        $targets = [];
        for ($offset = 0; $offset < 100; ++$offset) {
            $period = $this->period(2100 + $offset);
            $periods[] = $period;
            $previous = null;
            for ($revision = 1; $revision <= 5; ++$revision) $previous = $this->append($period, $revision, $previous);
            $targets[$period] = [$previous['id'] => true];
        }

        $legacy = $this->analysis($selectedPeriod, 'uq_nlp_tenant', 'supplier_id,id', true);
        self::assertSame('uq_nlp_tenant', $legacy['key']);
        self::assertSame(501, $legacy['r_rows']);
        $bounded = $this->analysis($selectedPeriod, 'uq_nlp_period', 'supplier_id,period_id,id', false);
        self::assertSame('uq_nlp_period', $bounded['key']);
        self::assertSame(1, $bounded['r_rows']);

        $before = $this->handlerReads();
        $one = $this->legalProfiles([$selectedPeriod], [$selectedPeriod => [$selectedProfile['id'] => true]]);
        $singleReads = $this->handlerReads() - $before;
        self::assertSame($selectedProfile['record_hash'], $one[$selectedProfile['id']]['record_hash']);
        self::assertLessThanOrEqual(8, $singleReads, 'One selected legal-profile chain must not scan 500 unrelated revisions.');

        $before = $this->handlerReads();
        $many = $this->legalProfiles(array_reverse($periods), $targets);
        $batchReads = $this->handlerReads() - $before;
        self::assertCount(100, $many);
        foreach ($targets as $periodTargets) {
            $profile = array_key_first($periodTargets);
            self::assertArrayHasKey($profile, $many);
        }
        self::assertLessThanOrEqual(510, $batchReads, 'A full 500-row legal proof batch must stay physically bounded.');
    }

    private function period(int $year): int
    {
        return (new AccountingPeriodRepository($this->db))->create($this->supplier, $year, $year . '-01-01', $year . '-12-31');
    }

    private function append(int $period, int $revision, ?array $previous): array
    {
        $profile = NonprofitDppoLegalRules::profile(['kind' => 'narrow_vpp', 'entity_type' => 'association',
            'main_activity_nonbusiness' => true, 'evidence' => 'Synthetic association statutes', 'legal_basis' => 'zdp_17a']);
        $reason = 'Synthetic reviewed association statutes';
        $request = ['period_id' => $period, 'expected_revision' => $revision - 1, 'profile' => $profile,
            'reason' => $reason, 'actor' => $this->actor];
        $row = ['supplier_id' => $this->supplier, 'period_id' => $period, 'revision_no' => $revision,
            'previous_id' => $previous['id'] ?? null, 'profile' => $profile, 'module_proof' => [], 'reason' => $reason,
            'idempotency_key' => 'legal-scale-' . $period . '-' . $revision, 'request_hash' => NonprofitTaxRules::hash($request),
            'created_by' => $this->actor];
        $record = $row;
        $record['parent_hash'] = $previous['record_hash'] ?? null;
        $row['record_hash'] = NonprofitTaxRules::hash($record);
        $q = $this->pdo->prepare('INSERT INTO nonprofit_legal_profiles(supplier_id,period_id,revision_no,previous_id,profile_json,module_proof_json,reason,idempotency_key,request_hash,record_hash,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
        $q->execute([$this->supplier, $period, $revision, $row['previous_id'], NonprofitTaxRules::canonicalJson($profile), '[]',
            $reason, $row['idempotency_key'], $row['request_hash'], $row['record_hash'], $this->actor]);
        $row['id'] = (int) $this->pdo->lastInsertId();
        return $row;
    }

    private function legalProfiles(array $periods, array $targets): array
    {
        $proof = new MembershipStoredProof($this->db, new MembershipRepository($this->db));
        return (new ReflectionMethod($proof, 'legalProfiles'))->invoke($proof, $this->supplier, $periods, $targets);
    }

    private function handlerReads(): int
    {
        $q = $this->pdo->query("SHOW SESSION STATUS WHERE Variable_name IN ('Handler_read_next','Handler_read_rnd_next')");
        return array_sum(array_map('intval', $q->fetchAll(PDO::FETCH_KEY_PAIR)));
    }

    private function analysis(int $period, string $index, string $order, bool $legacy): array
    {
        $cursor = $legacy ? ' AND id>?' : '';
        $q = $this->pdo->prepare("ANALYZE FORMAT=JSON SELECT * FROM nonprofit_legal_profiles FORCE INDEX({$index}) WHERE supplier_id=? AND period_id IN (?){$cursor} ORDER BY {$order} LIMIT 500");
        $params = [$this->supplier, $period];
        if ($legacy) $params[] = 0;
        $q->execute($params);
        $plan = json_decode((string) $q->fetchColumn(), true, flags: JSON_THROW_ON_ERROR);
        return $plan['query_block']['nested_loop'][0]['table'];
    }
}
