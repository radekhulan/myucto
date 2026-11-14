<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Nonprofit;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Nonprofit\Membership\MembershipRepository;
use MyInvoice\Tests\Support\MembershipBatchReviewFixture;
use MyInvoice\Tests\Support\OwnedSandboxDatabase;
use MyInvoice\Tests\Support\WorkerLockWaitTrait;
use Psr\Container\ContainerInterface;
use Symfony\Component\Process\Process;

/**
 * Oprava člena proti souběžnému vystavení předpisu (krok 7 #321): druhé spojení čeká na zámek člena
 * a po potvrzení předpisu opravu odmítne, přestože jeho snímek vznikl před předpisem.
 */
final class MembershipCorrectionConcurrencyTest extends MembershipBatchReviewFixture
{
    use OwnedSandboxDatabase;
    use WorkerLockWaitTrait;

    protected function fixtureContainer(): ContainerInterface
    {
        $container = Bootstrap::buildContainer();
        $container->set(Config::class, self::sandboxConfig('membership_correction'));
        $container->set(Connection::class, self::sandboxConnection('membership_correction'));
        return $container;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (isset($this->db)) $this->db->close();
    }

    public function testIdentityCorrectionWaitingForMemberLockIsRejectedAfterConcurrentCharge(): void
    {
        $member = $this->member('RACE-IDENTITY');
        $client = (new MembershipRepository($this->db))->member($this->supplier, $member)['client_id'];
        $this->pdo->commit();
        $result = $this->whileChargeIsUncommitted($member, 'identity', ['body' => ['client_id' => $client, 'member_number' => 'SYN-RACE-2',
            'expected_client_id' => $client, 'expected_member_number' => 'SYN-RACE-IDENTITY']]);
        self::assertSame('rejected', $result['status'], json_encode($result, JSON_THROW_ON_ERROR));
        self::assertSame('nonprofit_membership_member_has_charges', $result['error_code']);
        self::assertSame('SYN-RACE-IDENTITY', (new MembershipRepository($this->db))->member($this->supplier, $member)['member_number']);
    }

    public function testRevisionVoidWaitingForMemberLockIsRejectedAfterConcurrentCharge(): void
    {
        $member = $this->member('RACE-VOID');
        $history = (new MembershipRepository($this->db))->history($this->supplier, $member);
        $this->pdo->commit();
        $result = $this->whileChargeIsUncommitted($member, 'void', ['revision' => $history[0]['id'],
            'body' => ['expected_revision' => 1, 'reason' => 'Synthetic race', 'idempotency_key' => 'race-void']]);
        self::assertSame('rejected', $result['status'], json_encode($result, JSON_THROW_ON_ERROR));
        self::assertSame('nonprofit_membership_revision_has_charges', $result['error_code']);
        self::assertCount(1, (new MembershipRepository($this->db))->history($this->supplier, $member));
    }

    public function testIdentityCorrectionWaitingForBatchConfirmationIsRejected(): void
    {
        $member = $this->member('RACE-BATCH');
        $client = (new MembershipRepository($this->db))->member($this->supplier, $member)['client_id'];
        $ready = $this->ready([$member], 'race-batch');
        $this->pdo->commit();
        $result = $this->whileUncommitted($member, 'identity', ['body' => ['client_id' => $client, 'member_number' => 'SYN-RACE-BATCH-2',
            'expected_client_id' => $client, 'expected_member_number' => 'SYN-RACE-BATCH']],
            fn () => $this->batches->confirm($this->supplier, $ready['batch']['id'], $ready['batch']['approval_token'], $this->actor),
            '/nonprofit_members.*FOR UPDATE/is');
        self::assertSame('rejected', $result['status'], json_encode($result, JSON_THROW_ON_ERROR));
        self::assertSame('nonprofit_membership_member_has_charges', $result['error_code']);
        self::assertSame(1, $this->operationCount());
    }

    public function testRevisionVoidWaitingForBatchConfirmationIsRejected(): void
    {
        $member = $this->member('RACE-BATCH-VOID');
        $history = (new MembershipRepository($this->db))->history($this->supplier, $member);
        $ready = $this->ready([$member], 'race-batch-void');
        $this->pdo->commit();
        $result = $this->whileUncommitted($member, 'void', ['revision' => $history[0]['id'],
            'body' => ['expected_revision' => 1, 'reason' => 'Synthetic race', 'idempotency_key' => 'race-batch-void']],
            fn () => $this->batches->confirm($this->supplier, $ready['batch']['id'], $ready['batch']['approval_token'], $this->actor),
            '/accounting_periods.*FOR UPDATE/is');
        self::assertSame('rejected', $result['status'], json_encode($result, JSON_THROW_ON_ERROR));
        self::assertSame('nonprofit_membership_revision_has_charges', $result['error_code']);
    }

    public function testDeletionWaitingForRevisionWriteIsRejected(): void
    {
        $member = $this->bareMember('RACE-DELETE-REV');
        $this->pdo->commit();
        $result = $this->whileUncommitted($member, 'delete', [], fn () => $this->members->appendRevision($this->supplier, $member,
            ['status' => 'active', 'effective_from' => '2026-01-01', 'expected_revision' => 0, 'idempotency_key' => 'race-delete-rev', 'legal_basis' => $this->basis()], $this->actor),
            '/nonprofit_members.*FOR UPDATE/is');
        self::assertSame('rejected', $result['status'], json_encode($result, JSON_THROW_ON_ERROR));
        self::assertSame('nonprofit_membership_member_has_revisions', $result['error_code']);
        self::assertNotNull((new MembershipRepository($this->db))->member($this->supplier, $member));
    }

    /**
     * Plnění poslední stránky přepíná fázi dávky (mění ix_nmb_phase, na kterém smazání drží sdílený zámek) → deadlock.
     * Obětí je smazání (nic nezapsalo); opakování po potvrzení plnění vrátí 409, plnění doběhne a nic není 500.
     */
    public function testDeletionDeadlockWithLastSeedingPageIsRetriedIntoConflict(): void
    {
        $bare = $this->bareMember('RACE-SEED-LAST');
        $other = $this->member('RACE-SEED-LAST-OTHER');
        $batch = $this->batches->create($this->supplier, $this->input([$bare, $other], 'race-seed-last'), $this->actor)['batch']['id'];
        $this->pdo->prepare('DELETE FROM nonprofit_membership_batch_members WHERE supplier_id=? AND batch_id=? AND member_id=?')->execute([$this->supplier, $batch, $bare]);
        $this->pdo->prepare("UPDATE nonprofit_membership_batches SET phase='seeding',seed_cursor=0,seed_until=? WHERE supplier_id=? AND id=?")->execute([$other, $this->supplier, $batch]);
        $this->pdo->commit();
        $result = $this->whileUncommitted($bare, 'delete', ['outer' => false],
            fn () => $this->pdo->prepare('SELECT id FROM nonprofit_membership_batches WHERE supplier_id=? AND id=? FOR UPDATE')->execute([$this->supplier, $batch]),
            '/nonprofit_membership_batches.*LOCK IN SHARE MODE/is',
            function () use ($batch, $bare, $other): void {
                $this->pdo->prepare("INSERT INTO nonprofit_membership_batch_members(supplier_id,batch_id,member_id,status,amount_cents) VALUES(?,?,?,'rejected',50000)")->execute([$this->supplier, $batch, $bare]);
                $this->pdo->prepare("UPDATE nonprofit_membership_batches SET phase='preparing',seed_cursor=? WHERE supplier_id=? AND id=?")->execute([$other, $this->supplier, $batch]);
            });
        self::assertSame('rejected', $result['status'], json_encode($result, JSON_THROW_ON_ERROR));
        self::assertSame('nonprofit_membership_member_in_batch', $result['error_code']);
        $q = $this->pdo->prepare('SELECT phase FROM nonprofit_membership_batches WHERE supplier_id=? AND id=?'); $q->execute([$this->supplier, $batch]);
        self::assertSame('preparing', $q->fetchColumn());
    }

    public function testDeletionWaitsForSeedingBatchAndIsRejectedWhenTheSeedReachesTheMember(): void
    {
        $q = $this->pdo->prepare('SELECT default_currency_id FROM supplier WHERE id=?'); $q->execute([$this->supplier]);
        $this->pdo->prepare("INSERT INTO clients(supplier_id,company_name,street,city,zip,country_id,main_email,language,currency_default_id,is_customer,is_vendor) VALUES(?,'Synthetic race member','Synthetic street','Synthetic city','10000',203,'race-seed@example.test','cs',?,1,0)")
            ->execute([$this->supplier, (int) $q->fetchColumn()]);
        $bare = $this->members->create($this->supplier, (int) $this->pdo->lastInsertId(), 'SYN-RACE-SEED', $this->actor)['id'];
        $other = $this->member('RACE-SEED-OTHER');
        $batch = $this->batches->create($this->supplier, $this->input([$bare, $other], 'race-seed'), $this->actor)['batch']['id'];
        // Stav uprostřed plnění: člen ještě nemá položku a dávka se plní.
        $this->pdo->prepare('DELETE FROM nonprofit_membership_batch_members WHERE supplier_id=? AND batch_id=? AND member_id=?')->execute([$this->supplier, $batch, $bare]);
        $this->pdo->prepare("UPDATE nonprofit_membership_batches SET phase='seeding',seed_cursor=0,seed_until=? WHERE supplier_id=? AND id=?")->execute([$other, $this->supplier, $batch]);
        $this->pdo->commit();

        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'membership-correction-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory, 0700));
        $readyFile = $directory . DIRECTORY_SEPARATOR . 'ready.json'; $resultFile = $directory . DIRECTORY_SEPARATOR . 'result.json';
        $config = self::sandboxConfig('membership_correction');
        $process = new Process([PHP_BINARY, '-d', 'opcache.enable_cli=0', dirname(__DIR__, 2) . '/Support/MembershipCorrectionConcurrencyWorker.php', $readyFile, $resultFile]);
        $process->setInput(json_encode(['config' => $config->all(), 'data_dir' => $config->dataDir(), 'supplier' => $this->supplier,
            'member' => $bare, 'actor' => $this->actor, 'mode' => 'delete'], JSON_THROW_ON_ERROR));
        $process->setTimeout(40);
        try {
            // Plnění drží dávku jako MembershipBatchRepository::seed.
            $this->pdo->beginTransaction();
            $this->pdo->prepare('SELECT id FROM nonprofit_membership_batches WHERE supplier_id=? AND id=? FOR UPDATE')->execute([$this->supplier, $batch]);
            $process->start();
            $state = $this->awaitWorkerReady($process, $readyFile);
            $this->assertWorkerWaitsOnLock($this->pdo, $process, $state['connection_id'], '/nonprofit_membership_batches.*LOCK IN SHARE MODE/is', 'Smazání musí čekat na plněnou dávku dřív, než zamkne člena.');
            $this->pdo->prepare("INSERT INTO nonprofit_membership_batch_members(supplier_id,batch_id,member_id,status,amount_cents) VALUES(?,?,?,'rejected',50000)")->execute([$this->supplier, $batch, $bare]);
            $this->pdo->commit();
            self::assertSame(0, $process->wait(), $process->getErrorOutput());
            $result = json_decode((string) file_get_contents($resultFile), true, flags: JSON_THROW_ON_ERROR);
        } finally {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            if ($process->isRunning()) $process->stop(1);
            foreach ([$readyFile, $resultFile] as $file) if (is_file($file)) unlink($file);
            if (is_dir($directory)) rmdir($directory);
        }
        self::assertSame('rejected', $result['status'], json_encode($result, JSON_THROW_ON_ERROR));
        self::assertSame('nonprofit_membership_member_in_batch', $result['error_code']);
        self::assertNotNull((new MembershipRepository($this->db))->member($this->supplier, $bare));
    }

    /** Rodič drží nepotvrzený předpis (a zámek člena), worker má starší snímek a prokazatelně čeká. */
    private function whileChargeIsUncommitted(int $member, string $mode, array $payload): array
    {
        // Zrušení revize zamyká nejdřív období (jako zápis revize a předpis), oprava člena rovnou člena.
        $pattern = $mode === 'void' ? '/accounting_periods.*FOR UPDATE/is' : '/nonprofit_members.*FOR UPDATE/is';
        return $this->whileUncommitted($member, $mode, $payload, function () use ($member, $mode): void {
            $request = ['member_id' => $member] + $this->template;
            $preview = $this->charges->previewIssue($this->supplier, $request);
            $this->charges->confirmIssue($this->supplier, $request, $preview['expected_case_version'], $preview['preview_hash'], 'race-issue-' . $mode, $this->actor);
        }, $pattern, assertNoCases: true);
    }

    /**
     * Rodič v otevřené transakci provede $parentWork (drží jeho zámky), worker spustí opravu a prokazatelně čeká
     * na zámek podle $pattern; $beforeCommit proběhne, když worker čeká, pak rodič potvrdí.
     */
    private function whileUncommitted(int $member, string $mode, array $payload, \Closure $parentWork, string $pattern, ?\Closure $beforeCommit = null, bool $assertNoCases = false): array
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'membership-correction-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory, 0700));
        $readyFile = $directory . DIRECTORY_SEPARATOR . 'ready.json'; $resultFile = $directory . DIRECTORY_SEPARATOR . 'result.json';
        $config = self::sandboxConfig('membership_correction');
        $process = new Process([PHP_BINARY, '-d', 'opcache.enable_cli=0', dirname(__DIR__, 2) . '/Support/MembershipCorrectionConcurrencyWorker.php', $readyFile, $resultFile]);
        $process->setInput(json_encode(['config' => $config->all(), 'data_dir' => $config->dataDir(), 'supplier' => $this->supplier,
            'member' => $member, 'actor' => $this->actor, 'mode' => $mode] + $payload, JSON_THROW_ON_ERROR));
        $process->setTimeout(40);
        try {
            $this->pdo->beginTransaction();
            $parentWork();
            $process->start();
            $state = $this->awaitWorkerReady($process, $readyFile);
            if ($assertNoCases) self::assertSame(0, $state['snapshot_cases'], 'Snímek workeru nesmí nepotvrzený předpis vidět.');
            $this->assertWorkerWaitsOnLock($this->pdo, $process, $state['connection_id'], $pattern, 'Oprava musí čekat na zámek souběžné operace.');
            if ($beforeCommit !== null) $beforeCommit();
            $this->pdo->commit();
            self::assertSame(0, $process->wait(), $process->getErrorOutput());
            self::assertFileExists($resultFile);
            return json_decode((string) file_get_contents($resultFile), true, flags: JSON_THROW_ON_ERROR);
        } finally {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            if ($process->isRunning()) $process->stop(1);
            foreach ([$readyFile, $resultFile] as $file) if (is_file($file)) unlink($file);
            if (is_dir($directory)) rmdir($directory);
        }
    }

    private function bareMember(string $number): int
    {
        $q = $this->pdo->prepare('SELECT default_currency_id FROM supplier WHERE id=?'); $q->execute([$this->supplier]);
        $this->pdo->prepare("INSERT INTO clients(supplier_id,company_name,street,city,zip,country_id,main_email,language,currency_default_id,is_customer,is_vendor) VALUES(?,'Synthetic race member','Synthetic street','Synthetic city','10000',203,?,'cs',?,1,0)")
            ->execute([$this->supplier, strtolower($number) . '@example.test', (int) $q->fetchColumn()]);
        return $this->members->create($this->supplier, (int) $this->pdo->lastInsertId(), 'SYN-' . $number, $this->actor)['id'];
    }
}
