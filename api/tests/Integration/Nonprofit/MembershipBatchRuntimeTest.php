<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Nonprofit;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Nonprofit\Membership\MembershipBatchRepository;
use MyInvoice\Service\Nonprofit\Membership\MembershipBatchService;
use MyInvoice\Tests\Support\MembershipBatchReviewFixture;
use MyInvoice\Tests\Support\OwnedSandboxDatabase;
use MyInvoice\Tests\Support\WorkerLockWaitTrait;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Process\Process;

/** Commitující retry i souběh jsou izolované od zdrojové testovací databáze. */
final class MembershipBatchRuntimeTest extends MembershipBatchReviewFixture
{
    use OwnedSandboxDatabase;
    use WorkerLockWaitTrait;

    protected function fixtureContainer(): ContainerInterface
    {
        $container = Bootstrap::buildContainer();
        $container->set(Config::class, self::sandboxConfig('membership_batch'));
        $container->set(Connection::class, self::sandboxConnection('membership_batch'));
        return $container;
    }

    protected function tearDown(): void
    {
        MembershipBatchRuntimeFailureStatement::$member = 0;
        parent::tearDown();
        if (isset($this->db)) $this->db->close();
    }

    public static function databaseFailures(): array
    {
        return ['deadlock 1213' => [1213], 'lock timeout 1205' => [1205]];
    }

    #[DataProvider('databaseFailures')]
    public function testTransientDatabaseFailureStaysReadyTwiceThenRejectsAndOtherMembersContinue(int $code): void
    {
        $ids = [$this->member('RETRY-FIRST'), $this->member('RETRY-FAULT'), $this->member('RETRY-LAST')];
        $ready = $this->ready($ids); $id = $ready['batch']['id']; $token = $ready['batch']['approval_token'];
        $this->pdo->commit();
        self::assertFalse($this->pdo->inTransaction(), 'Retry musí vlastnit finanční transakci.');
        $previous = $this->pdo->getAttribute(PDO::ATTR_STATEMENT_CLASS);
        MembershipBatchRuntimeFailureStatement::$member = $ids[1];
        MembershipBatchRuntimeFailureStatement::$code = $code;
        MembershipBatchRuntimeFailureStatement::$calls = 0;
        $this->pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [MembershipBatchRuntimeFailureStatement::class]);
        $service = new MembershipBatchService($this->db, $this->charges, logger: new NullLogger());
        try {
            for ($attempt = 1; $attempt <= 3; ++$attempt) {
                $detail = $service->confirm($this->supplier, $id, $token, $this->actor);
                self::assertFalse($this->pdo->inTransaction());
                self::assertSame(2, $detail['summary']['created']);
                self::assertSame($attempt < 3 ? 1 : 0, $detail['summary']['ready']);
                self::assertSame($attempt < 3 ? 0 : 1, $detail['summary']['rejected']);
                self::assertSame($attempt < 3 ? 'posting' : 'finished', $detail['batch']['phase']);
                $item = array_column($detail['items'], null, 'member_id')[$ids[1]];
                self::assertSame($attempt < 3 ? 'ready' : 'rejected', $item['status']);
                self::assertSame($attempt < 3 ? null : 'nonprofit_membership_batch_technical', $item['error_code']);
                $q = $this->pdo->prepare('SELECT attempts FROM nonprofit_membership_batch_members WHERE supplier_id=? AND batch_id=? AND member_id=?');
                $q->execute([$this->supplier, $id, $ids[1]]); self::assertSame($attempt, (int) $q->fetchColumn());
                self::assertSame(2, $this->operationCount()); $this->assertCounters($id);
            }
            self::assertSame(3, MembershipBatchRuntimeFailureStatement::$calls);
            self::assertSame($detail['summary'], $service->confirm($this->supplier, $id, $token, $this->actor)['summary']);
            self::assertSame(3, MembershipBatchRuntimeFailureStatement::$calls, 'Terminální chyba se už nesmí opakovat.');
            self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM nonprofit_membership_charge_cases WHERE supplier_id=' . $this->supplier)->fetchColumn());
        } finally {
            MembershipBatchRuntimeFailureStatement::$member = 0;
            $this->pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, $previous);
        }
    }

    public function testTwoConnectionsSerializeConfirmAndFinancialGapReplayWithoutCounterDrift(): void
    {
        $ids = [$this->member('LOCK-FIRST'), $this->member('LOCK-SECOND')];
        $ready = $this->ready($ids); $id = $ready['batch']['id']; $token = $ready['batch']['approval_token'];
        $repo = new MembershipBatchRepository($this->db);
        $repo->beginPosting($this->supplier, $id, $token, $this->actor);
        $item = $repo->workPage($this->supplier, $id, 'ready', 1)[0];
        $issued = $this->charges->confirmIssue($this->supplier, ['member_id' => $ids[0]] + $ready['batch']['template'], (int) $item['expected_case_version'], $item['preview_hash'], MembershipBatchService::memberKey($repo->find($this->supplier, $id), $ids[0]), $this->actor);
        $this->pdo->commit();
        $result = $this->interleave($ready, 'confirm', fn () => $this->batches->confirm($this->supplier, $id, $token, $this->actor));
        self::assertSame('completed', $result['status']);
        self::assertSame('finished', $result['detail']['batch']['phase']);
        self::assertSame(2, $result['detail']['summary']['created']);
        self::assertSame(2, $this->operationCount());
        self::assertSame($issued['operation']['id'], $result['detail']['items'][0]['operation_id']);
        $this->assertCounters($id);
    }

    public function testTwoConnectionsSerializeCancelAfterPartialConfirmAndPreserveCounters(): void
    {
        $ids = [];
        for ($i = 0; $i < 11; ++$i) $ids[] = $this->member('CANCEL-LOCK-' . $i);
        $ready = $this->ready($ids); $id = $ready['batch']['id']; $token = $ready['batch']['approval_token'];
        $this->pdo->commit();
        $result = $this->interleave($ready, 'cancel', fn () => $this->batches->confirm($this->supplier, $id, $token, $this->actor));
        self::assertSame('completed', $result['status']); self::assertSame('cancelled', $result['detail']['batch']['phase']);
        self::assertSame(10, $result['detail']['summary']['created']); self::assertSame(0, $result['detail']['summary']['ready']); self::assertSame(1, $result['detail']['summary']['rejected']);
        self::assertSame(10, $this->operationCount());
        $this->assertConfirmRejected($id, $token); $this->assertCounters($id);
    }

    public function testTwoConnectionsRejectConfirmWithSnapshotPredatingCancellation(): void
    {
        $ready = $this->ready([$this->member('CANCEL-WINS')]); $id = $ready['batch']['id'];
        $this->pdo->commit();
        $result = $this->interleave($ready, 'confirm', fn () => $this->batches->cancel($this->supplier, $id, $this->actor));
        self::assertSame('rejected', $result['status']);
        self::assertSame('nonprofit_membership_batch_not_ready', $result['error_code']);
        self::assertSame('cancelled', $result['detail']['batch']['phase']);
        self::assertSame(0, $this->operationCount()); $this->assertCounters($id);
    }

    public function testTwoConnectionsRenewWithOldReadySnapshotTerminatesAndPreservesCreatedCounters(): void
    {
        $ids = [];
        for ($i = 0; $i < 11; ++$i) $ids[] = $this->member('RENEW-SNAPSHOT-' . $i);
        $ready = $this->ready($ids); $id = $ready['batch']['id']; $token = $ready['batch']['approval_token'];
        $this->pdo->commit();
        // B vidí jedenáct ready, A pod zámkem vytvoří deset. B musí po commitu A
        // obnovit pouze zbývající řádek, nikoli stále dokola číst starých deset ready.
        $result = $this->interleave($ready, 'renew', fn () => $this->batches->confirm($this->supplier, $id, $token, $this->actor), completionTimeout: 10.0);
        self::assertSame('completed', $result['status']);
        self::assertSame('preparing', $result['detail']['batch']['phase']);
        self::assertSame(11, $result['detail']['summary']['total']);
        self::assertSame(10, $result['detail']['summary']['created']);
        self::assertSame(1, $result['detail']['summary']['pending']);
        self::assertSame(0, $result['detail']['summary']['ready']);
        self::assertSame(0, $result['detail']['summary']['existing']);
        self::assertSame(0, $result['detail']['summary']['rejected']);
        self::assertSame('500000', $result['detail']['summary']['created_cents']);
        self::assertSame('500000', $result['detail']['summary']['planned_cents']);
        self::assertSame(10, $this->operationCount());
        self::assertSame($result['detail']['summary'], $result['operation_detail']['summary'], 'WRITE detail uvnitř starého snímku musí odpovídat provedené obnově.');
        self::assertSame('preparing', $result['operation_detail']['batch']['phase']);
        self::assertNull($result['operation_detail']['batch']['approval_token']);
        $this->assertCounters($id);
        $prepared = $this->batches->prepare($this->supplier, $id);
        $done = $this->batches->confirm($this->supplier, $id, $prepared['batch']['approval_token'], $this->actor);
        self::assertSame('finished', $done['batch']['phase']); self::assertSame(11, $done['summary']['created']);
        self::assertSame(11, $this->operationCount()); $this->assertCounters($id);
    }

    public function testConfirmAuditUsesCurrentSummaryAfterOtherConnectionPostedFirstPage(): void
    {
        $ids=[];for($i=0;$i<11;$i++)$ids[]=$this->member('AUDIT-SNAPSHOT-'.$i);
        $ready=$this->ready($ids);$id=$ready['batch']['id'];$token=$ready['batch']['approval_token'];
        $this->pdo->commit();
        $result=$this->interleave($ready,'confirm',fn()=>$this->batches->confirm($this->supplier,$id,$token,$this->actor));
        self::assertSame('completed',$result['status']);
        $q=$this->pdo->prepare("SELECT payload FROM activity_log WHERE supplier_id=? AND entity_id=? AND action='nonprofit_membership_batch_confirm' ORDER BY id DESC LIMIT 1");
        $q->execute([$this->supplier,$id]);$payload=json_decode((string)$q->fetchColumn(),true,flags:JSON_THROW_ON_ERROR);
        self::assertSame(1,$payload['member_count']);
        self::assertSame(10,$payload['summary']['created']);
        self::assertSame(1,$payload['summary']['ready']);
        self::assertSame(11,$this->operationCount());$this->assertCounters($id);
    }

    /** Parent drží zámek, worker již má starý RR snímek a prokazatelně čeká v DB. */
    private function interleave(array $ready, string $mode, \Closure $parentOperation, float $completionTimeout = 15.0): array
    {
        $id = $ready['batch']['id'];
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'membership-batch-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory, 0700));
        $readyFile = $directory . DIRECTORY_SEPARATOR . 'ready.json'; $resultFile = $directory . DIRECTORY_SEPARATOR . 'result.json';
        $config = self::sandboxConfig('membership_batch');
        $process = new Process([PHP_BINARY, '-d', 'opcache.enable_cli=0', dirname(__DIR__, 2) . '/Support/MembershipBatchConcurrencyWorker.php', $readyFile, $resultFile]);
        $process->setInput(json_encode(['config' => $config->all(), 'data_dir' => $config->dataDir(), 'supplier' => $this->supplier,
            'batch' => $id, 'actor' => $this->actor, 'mode' => $mode, 'token' => $ready['batch']['approval_token']], JSON_THROW_ON_ERROR));
        $process->setTimeout(40);
        try {
            $this->pdo->beginTransaction();
            (new MembershipBatchRepository($this->db))->find($this->supplier, $id, true);
            $process->start();
            $state = $this->awaitWorkerReady($process, $readyFile);
            self::assertTrue($state['snapshot_established']); self::assertGreaterThan(0, $state['snapshot_ready']);
            self::assertNotSame((int) $this->pdo->query('SELECT CONNECTION_ID()')->fetchColumn(), $state['connection_id']);
            $this->assertWorkerWaitsOnLock($this->pdo, $process, $state['connection_id'], '/nonprofit_membership_batches.*FOR UPDATE/is', 'Potvrzení/zrušení musí čekat na zámek dávky.');
            $parentOperation(); $this->pdo->commit();
            // RED nekonečné smyčky renew nesmí pověsit sadu ani následný DROP sandboxu.
            $deadline = microtime(true) + $completionTimeout;
            while ($process->isRunning()) {
                if (microtime(true) >= $deadline) {
                    $process->stop(0.1);
                    self::fail('Worker ' . $mode . ' nedokončil operaci po uvolnění zámku do ' . $completionTimeout . ' s.');
                }
                $process->checkTimeout();
                usleep(20_000);
            }
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
}

/** PDO chyby vznikají až po zápisu deníku, aby test kontroloval skutečný rollback. */
final class MembershipBatchRuntimeFailureStatement extends PDOStatement
{
    public static int $member = 0;
    public static int $code = 0;
    public static int $calls = 0;

    public function execute(?array $params = null): bool
    {
        if (self::$member > 0 && str_contains($this->queryString, 'INSERT INTO nonprofit_membership_charge_operations')
            && (int) (json_decode($params[18], true, flags: JSON_THROW_ON_ERROR)['member']['id'] ?? 0) === self::$member) {
            ++self::$calls;
            $failure = new \PDOException('Synthetic membership runtime database conflict');
            $failure->errorInfo = [self::$code === 1213 ? '40001' : 'HY000', self::$code, 'Synthetic conflict'];
            throw $failure;
        }
        return parent::execute($params);
    }
}
