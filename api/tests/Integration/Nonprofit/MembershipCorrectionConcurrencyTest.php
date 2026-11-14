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

    /** Rodič drží nepotvrzený předpis (a zámek člena), worker má starší snímek a prokazatelně čeká. */
    private function whileChargeIsUncommitted(int $member, string $mode, array $payload): array
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
            $request = ['member_id' => $member] + $this->template;
            $preview = $this->charges->previewIssue($this->supplier, $request);
            $this->charges->confirmIssue($this->supplier, $request, $preview['expected_case_version'], $preview['preview_hash'], 'race-issue-' . $mode, $this->actor);
            $process->start();
            $state = $this->awaitWorkerReady($process, $readyFile);
            self::assertSame(0, $state['snapshot_cases'], 'Snímek workeru nesmí nepotvrzený předpis vidět.');
            // Zrušení revize zamyká nejdřív období (jako zápis revize a předpis), oprava člena rovnou člena.
            $pattern = $mode === 'void' ? '/accounting_periods.*FOR UPDATE/is' : '/nonprofit_members.*FOR UPDATE/is';
            $this->assertWorkerWaitsOnLock($this->pdo, $process, $state['connection_id'], $pattern, 'Oprava musí čekat na zámek předpisu.');
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
}
