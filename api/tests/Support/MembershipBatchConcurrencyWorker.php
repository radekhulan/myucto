<?php

declare(strict_types=1);

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Nonprofit\Membership\MembershipChargeRepository;
use MyInvoice\Repository\Nonprofit\Membership\MembershipRepository;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Nonprofit\Membership\MembershipBatchService;
use MyInvoice\Service\Nonprofit\Membership\MembershipChargeService;
use MyInvoice\Service\Nonprofit\Membership\MembershipException;
use Psr\Log\NullLogger;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

// Konfigurace jde rourou, nikoli argumenty procesu ani souborem s přihlašovacími údaji.
$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$config = new Config($input['config'], $input['data_dir']);
if (preg_match('/^myucto_membership_batch_[a-f0-9]+_test$/D', (string) $config->get('db.name')) !== 1) {
    throw new RuntimeException('Worker vyžaduje vlastní členský testovací sandbox.');
}
$container = Bootstrap::buildContainer();
$db = Connection::withoutSharedTestConnection(static fn () => new Connection($config));
$container->set(Config::class, $config);
$container->set(Connection::class, $db);
$pdo = $db->pdo();
$pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
$pdo->exec('SET SESSION innodb_snapshot_isolation=0,innodb_lock_wait_timeout=20');
$charges = new MembershipChargeService($db, new MembershipRepository($db), new MembershipChargeRepository($db), $container->get(PostingService::class));
$service = new MembershipBatchService($db, $charges, logger: new NullLogger());
$supplier = (int) $input['supplier']; $batch = (int) $input['batch']; $actor = (int) $input['actor'];
$result = [];
try {
    $pdo->beginTransaction();
    $q = $pdo->prepare("SELECT COUNT(*) FROM nonprofit_membership_batch_members WHERE supplier_id=? AND batch_id=? AND status='ready'");
    $q->execute([$supplier, $batch]);
    $snapshotReady = (int) $q->fetchColumn();
    file_put_contents($argv[1], json_encode(['ready' => true, 'snapshot_ready' => $snapshotReady,
        'connection_id' => (int) $pdo->query('SELECT CONNECTION_ID()')->fetchColumn(), 'snapshot_established' => true], JSON_THROW_ON_ERROR));
    if ($input['mode'] === 'confirm') $operationDetail = $service->confirm($supplier, $batch, $input['token'], $actor);
    elseif ($input['mode'] === 'cancel') $operationDetail = $service->cancel($supplier, $batch, $actor);
    elseif ($input['mode'] === 'renew') $operationDetail = $service->renewPreview($supplier, $batch, $actor);
    else throw new RuntimeException('Neplatná operace workeru.');
    $pdo->commit();
    $result = ['status' => 'completed', 'operation_detail' => $operationDetail];
} catch (Throwable $failure) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $result = ['status' => $failure instanceof MembershipException ? 'rejected' : 'error',
        'error_code' => $failure instanceof MembershipException ? $failure->errorCode : null,
        'exception' => $failure::class, 'message' => $failure->getMessage()];
}
// Návratové DTO čteme až po ukončení úmyslně zastaralého snímku.
$result['detail'] = $service->detail($supplier, $batch);
file_put_contents($argv[2], json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
$db->close();
