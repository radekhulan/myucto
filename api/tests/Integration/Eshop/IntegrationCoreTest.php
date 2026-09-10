<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Eshop;

use MyInvoice\Repository\ExternalEntityMapRepository;
use MyInvoice\Service\Integration\IntegrationChangeFeedService;
use MyInvoice\Service\Integration\IntegrationConnectionService;
use MyInvoice\Service\Integration\IntegrationDeliveryService;
use MyInvoice\Service\Integration\IntegrationEventPublisher;
use MyInvoice\Service\Integration\IntegrationInboxService;
use MyInvoice\Service\Integration\IntegrationReconcileService;
use MyInvoice\Service\Integration\IntegrationReconcileWorker;
use MyInvoice\Service\Integration\IntegrationWebhookService;
use MyInvoice\Tests\Integration\Stock\StockTestCase;
use Symfony\Component\Process\Process;

final class IntegrationCoreTest extends StockTestCase
{
    public function testEmptyMappingRoundTripStaysAJsonObjectAndCanBeEdited(): void
    {
        $sid = $this->createSupplier();
        $connections = $this->container->get(IntegrationConnectionService::class);
        $created = $connections->create($sid, [
            'connector_key' => 'synthetic.empty',
            'name' => 'Empty mapping',
            'status' => 'draft',
        ], $this->userId);

        self::assertInstanceOf(\stdClass::class, $created['mappings']);
        self::assertInstanceOf(\stdClass::class, $created['field_ownership']);
        self::assertSame('{}', json_encode($created['mappings'], JSON_THROW_ON_ERROR));
        self::assertSame('{}', json_encode($created['field_ownership'], JSON_THROW_ON_ERROR));

        $loaded = $connections->find($sid, $created['id']);
        self::assertInstanceOf(\stdClass::class, $loaded['mappings']);
        $edited = $connections->update($sid, $created['id'], [
            'name' => 'Edited empty mapping',
            'mappings' => [],
            'field_ownership' => [],
        ]);
        self::assertSame('Edited empty mapping', $edited['name']);
        self::assertSame('{}', json_encode($edited['mappings'], JSON_THROW_ON_ERROR));
        self::assertSame('{}', json_encode($edited['field_ownership'], JSON_THROW_ON_ERROR));

        $stored = $this->db->pdo()->query('SELECT mappings_json, field_ownership_json FROM integration_connections WHERE id = ' . (int) $created['id'])
            ->fetch(\PDO::FETCH_ASSOC);
        self::assertSame(['mappings_json' => '{}', 'field_ownership_json' => '{}'], $stored);
    }

    public function testCredentialsAreEncryptedAndWebhookIsVerifiedAndDeduplicated(): void
    {
        $sid = $this->createSupplier();
        $connection = $this->connection($sid);
        $connections = $this->container->get(IntegrationConnectionService::class);
        $presented = $connections->setCredentials($sid, $connection['id'], ['token' => 'synthetic-token']);

        self::assertTrue($presented['credentials_configured']);
        self::assertArrayNotHasKey('credentials_enc', $presented);
        $ciphertext = $this->db->pdo()->query('SELECT credentials_enc FROM integration_connections WHERE id = ' . $connection['id'])->fetchColumn();
        self::assertIsString($ciphertext);
        self::assertStringNotContainsString('synthetic-token', $ciphertext);
        self::assertSame(['token' => 'synthetic-token'], $connections->credentials($sid, $connection['id']));

        $rotated = $connections->rotateWebhookSecret($sid, $connection['id']);
        $body = json_encode([
            'event_id' => 'evt-synthetic-1', 'entity_type' => 'product', 'entity_id' => 'sku-1',
            'event_type' => 'product.updated', 'aggregate_version' => 1,
        ], JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        $signature = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $rotated['secret']);
        $webhooks = $this->container->get(IntegrationWebhookService::class);
        self::assertFalse($webhooks->receive($connection['connection_uuid'], $timestamp, $signature, $body)['duplicate']);
        self::assertTrue($webhooks->receive($connection['connection_uuid'], $timestamp, $signature, $body)['duplicate']);

        $this->expectExceptionMessage('webhook_signature_invalid');
        $webhooks->receive($connection['connection_uuid'], $timestamp, 'sha256=' . str_repeat('0', 64), $body);
    }

    public function testOutboxIsTransactionalAndExternalIdentityIsConnectionScoped(): void
    {
        $sid = $this->createSupplier();
        $first = $this->connection($sid, 'First');
        $second = $this->connection($sid, 'Second');
        $firstItem = $this->item($sid, 'MAP-FIRST');
        $secondItem = $this->item($sid, 'MAP-SECOND');
        $maps = $this->container->get(ExternalEntityMapRepository::class);
        $maps->put($sid, $first['id'], 'synthetic', 'product_variant', 'same-external-id', $firstItem, null, 'variant-a', 2);
        $maps->put($sid, $second['id'], 'synthetic', 'product_variant', 'same-external-id', $secondItem, null, 'variant-b', 3);
        self::assertSame($firstItem, $maps->findExternal($sid, $first['id'], 'product_variant', 'same-external-id')['internal_id']);
        self::assertSame($secondItem, $maps->findExternal($sid, $second['id'], 'product_variant', 'same-external-id')['internal_id']);

        $publisher = $this->container->get(IntegrationEventPublisher::class);
        try {
            $publisher->publish($sid, 'sales_order', 'order-synthetic', 'sales_order.created', 1, ['order_uuid' => 'order-synthetic']);
            self::fail('Publisher accepted an event outside the domain transaction.');
        } catch (\LogicException) {
            self::assertTrue(true);
        }
        $this->db->pdo()->beginTransaction();
        self::assertSame(2, $publisher->publish($sid, 'sales_order', 'order-synthetic', 'sales_order.created', 1, ['order_uuid' => 'order-synthetic']));
        $this->db->pdo()->rollBack();
        self::assertSame(0, (int) $this->db->pdo()->query('SELECT COUNT(*) FROM integration_outbox')->fetchColumn());

        $this->db->pdo()->beginTransaction();
        self::assertSame(2, $publisher->publish($sid, 'sales_order', 'order-synthetic', 'sales_order.created', 1, ['order_uuid' => 'order-synthetic']));
        self::assertSame(0, $publisher->publish($sid, 'sales_order', 'order-synthetic', 'sales_order.created', 1, ['order_uuid' => 'order-synthetic']));
        $this->db->pdo()->commit();
        self::assertSame(2, (int) $this->db->pdo()->query('SELECT COUNT(*) FROM integration_outbox')->fetchColumn());
        $this->db->pdo()->beginTransaction();
        try {
            $publisher->publish($sid, 'sales_order', 'order-synthetic', 'sales_order.created', 1, ['order_uuid' => 'different']);
            self::fail('Conflicting idempotent event was silently accepted.');
        } catch (\RuntimeException $e) {
            self::assertSame('integration_outbox_idempotency_conflict', $e->getMessage());
        } finally {
            $this->db->pdo()->rollBack();
        }
    }

    public function testProductTriggersEmitOnlyAffectedRowsAndTotalPurgeKeepsExpirationFloor(): void
    {
        $sid = $this->createSupplier();
        $connection = $this->connection($sid);
        $this->db->pdo()->prepare('UPDATE integration_connections SET retention_days = 1 WHERE id = ?')->execute([$connection['id']]);
        $start = (int) $this->db->pdo()->query('SELECT COALESCE(MAX(cursor_id), 0) FROM integration_change_log')->fetchColumn();
        for ($i = 1; $i <= 37; $i++) {
            $this->item($sid, sprintf('CHANGE-%02d', $i));
        }
        $feed = $this->container->get(IntegrationChangeFeedService::class);
        $changes = $feed->read($sid, $start, 100);
        self::assertCount(37, $changes['items']);
        self::assertSame(['product'], array_values(array_unique(array_column($changes['items'], 'source_area'))));

        $lastCursor = $changes['items'][36]['cursor'];
        $this->db->pdo()->prepare('UPDATE integration_change_log SET occurred_at = DATE_SUB(NOW(6), INTERVAL 2 DAY) WHERE supplier_id = ?')
            ->execute([$sid]);
        self::assertSame(37, $feed->purgeExpired());
        self::assertSame(0, (int) $this->db->pdo()->query('SELECT COUNT(*) FROM integration_change_log WHERE supplier_id = ' . $sid)->fetchColumn());
        self::assertSame($lastCursor, (int) $this->db->pdo()->query('SELECT retention_floor FROM integration_change_state WHERE supplier_id = ' . $sid)->fetchColumn());
        $expired = $feed->read($sid, 0, 100);
        self::assertTrue($expired['cursor_expired']);
        self::assertTrue($expired['snapshot_required']);
        self::assertSame('/api/v1/catalog/products/batch', $expired['snapshot_path']);
    }

    public function testTenantCursorSerializesConcurrentTransactionsInCommitOrder(): void
    {
        $sid = $this->createSupplier();
        $root = dirname(__DIR__, 4);
        $expectedDatabase = (string) $this->db->pdo()->query('SELECT DATABASE()')->fetchColumn();
        self::assertMatchesRegularExpression('/_test(?:_worker[A-Za-z0-9_]*)?$/D', $expectedDatabase);
        $controlFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'integration-cursor-' . bin2hex(random_bytes(8)) . '.json';
        $worker = <<<'PHP'
$root = $argv[1];
$supplierId = (int) $argv[2];
$controlFile = $argv[3];
$expectedDatabase = $argv[4];
require $root . '/api/vendor/autoload.php';
$config = \MyInvoice\Infrastructure\Config\Config::load($root);
$configuredDatabase = (string) $config->get('db.name');
if ($configuredDatabase !== $expectedDatabase || preg_match('/_test(?:_worker[A-Za-z0-9_]*)?$/D', $configuredDatabase) !== 1) { exit(10); }
$pdo = \MyInvoice\Bootstrap::buildApp()->getContainer()->get(\MyInvoice\Infrastructure\Database\Connection::class)->pdo();
if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== $expectedDatabase) { exit(11); }
$pdo->beginTransaction();
file_put_contents($controlFile, json_encode(['ready' => true, 'connection_id' => (int) $pdo->query('SELECT CONNECTION_ID()')->fetchColumn()], JSON_THROW_ON_ERROR));
$pdo->prepare("INSERT INTO integration_change_log (supplier_id, entity_id, change_type, source_area) VALUES (?, 900002, 'upsert', 'reservation')")->execute([$supplierId]);
$pdo->commit();
PHP;
        $process = new Process([PHP_BINARY, '-r', $worker, $root, (string) $sid, $controlFile, $expectedDatabase], $root);
        $process->setEnv(['MYINVOICE_DB_NAME' => $expectedDatabase]);
        try {
            $this->db->pdo()->beginTransaction();
            $this->db->pdo()->prepare("INSERT INTO integration_change_log (supplier_id, entity_id, change_type, source_area) VALUES (?, 900001, 'upsert', 'reservation')")
                ->execute([$sid]);
            $process->start();
            $deadline = microtime(true) + 5;
            $control = null;
            while ($control === null && $process->isRunning() && microtime(true) < $deadline) {
                if (is_file($controlFile)) {
                    $decoded = json_decode((string) file_get_contents($controlFile), true);
                    if (is_array($decoded) && ($decoded['ready'] ?? false) === true && (int) ($decoded['connection_id'] ?? 0) > 0) {
                        $control = $decoded;
                        break;
                    }
                }
                usleep(20000);
            }
            self::assertIsArray($control, $process->getErrorOutput());
            $wait = $this->db->pdo()->prepare('SELECT 1 FROM information_schema.INNODB_LOCK_WAITS waits
                JOIN information_schema.INNODB_TRX requesting ON requesting.trx_id = waits.requesting_trx_id
                WHERE requesting.trx_mysql_thread_id = ? LIMIT 1');
            $blocked = false;
            while (!$blocked && $process->isRunning() && microtime(true) < $deadline) {
                $wait->execute([(int) $control['connection_id']]);
                $blocked = $wait->fetchColumn() !== false;
                if (!$blocked) {
                    usleep(20000);
                }
            }
            self::assertTrue($blocked, 'Worker did not wait on the tenant cursor lock. ' . $process->getErrorOutput());
            $this->db->pdo()->commit();
            $process->wait();
            self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            $stmt = $this->db->pdo()->prepare('SELECT cursor_id, entity_id FROM integration_change_log WHERE supplier_id = ? ORDER BY cursor_id');
            $stmt->execute([$sid]);
            self::assertSame([
                ['cursor_id' => 1, 'entity_id' => 900001],
                ['cursor_id' => 2, 'entity_id' => 900002],
            ], $stmt->fetchAll(\PDO::FETCH_ASSOC));
        } finally {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            if ($process->isRunning()) {
                $process->stop(1);
            }
            if (is_file($controlFile)) {
                unlink($controlFile);
            }
        }
    }

    public function testInboxAppliesNewestVersionAndIgnoresLateDelivery(): void
    {
        $sid = $this->createSupplier();
        $connection = $this->connection($sid);
        $inbox = $this->container->get(IntegrationInboxService::class);
        $newerBody = $this->eventBody('newer-event', 2);
        $olderBody = $this->eventBody('older-event', 1);
        $newer = $inbox->accept($sid, $connection['id'], json_decode($newerBody, true), $newerBody);
        $older = $inbox->accept($sid, $connection['id'], json_decode($olderBody, true), $olderBody);
        $handled = 0;
        self::assertSame('processed', $inbox->process($sid, $connection['id'], $newer['id'], function () use (&$handled): void { $handled++; }));
        self::assertSame('ignored', $inbox->process($sid, $connection['id'], $older['id'], function () use (&$handled): void { $handled++; }));
        self::assertSame(1, $handled);
        self::assertTrue($inbox->accept($sid, $connection['id'], json_decode($olderBody, true), $olderBody)['duplicate']);
    }

    public function testOutboxClaimsVersionsInOrderAndRetriesDeadLetterWithinTenant(): void
    {
        $sid = $this->createSupplier();
        $other = $this->createSupplier();
        $connection = $this->connection($sid);
        $publisher = $this->container->get(IntegrationEventPublisher::class);
        $this->db->pdo()->beginTransaction();
        $publisher->publish($sid, 'product', 'ordered-product', 'product.updated', 1, ['version' => 1]);
        $publisher->publish($sid, 'product', 'ordered-product', 'product.updated', 2, ['version' => 2]);
        $this->db->pdo()->commit();

        $delivery = $this->container->get(IntegrationDeliveryService::class);
        $first = $delivery->claimOutbox($sid, $connection['id']);
        self::assertSame(1, (int) $first['aggregate_version']);
        self::assertNull($delivery->claimOutbox($sid, $connection['id']));
        self::assertTrue($delivery->delivered($sid, (int) $first['id'], $first['lease_token']));
        $second = $delivery->claimOutbox($sid, $connection['id']);
        self::assertSame(2, (int) $second['aggregate_version']);

        for ($attempt = 1; $attempt <= 8; $attempt++) {
            self::assertTrue($delivery->failed($sid, (int) $second['id'], $second['lease_token'], 'synthetic_failure'));
            if ($attempt === 8) {
                break;
            }
            $this->db->pdo()->prepare('UPDATE integration_outbox SET available_at = DATE_SUB(NOW(6), INTERVAL 1 SECOND) WHERE id = ?')
                ->execute([$second['id']]);
            $second = $delivery->claimOutbox($sid, $connection['id']);
        }
        self::assertSame('dead_letter', $this->db->pdo()->query('SELECT status FROM integration_outbox WHERE id = ' . (int) $second['id'])->fetchColumn());
        self::assertFalse($delivery->retryDeadLetter($other, $connection['id'], (int) $second['id']));
        self::assertTrue($delivery->retryDeadLetter($sid, $connection['id'], (int) $second['id']));
    }

    public function testRedactedDeadLetterCannotBeRetriedAsEmptyPayload(): void
    {
        $sid = $this->createSupplier();
        $connection = $this->connection($sid);
        $this->db->pdo()->prepare('UPDATE integration_connections SET retention_days = 1 WHERE id = ?')->execute([$connection['id']]);
        $publisher = $this->container->get(IntegrationEventPublisher::class);
        $this->db->pdo()->beginTransaction();
        $publisher->publish($sid, 'product', 'redacted-product', 'product.updated', 1, ['name' => 'Synthetic']);
        $this->db->pdo()->commit();
        $delivery = $this->container->get(IntegrationDeliveryService::class);
        $claimed = $delivery->claimOutbox($sid, $connection['id']);
        $this->db->pdo()->prepare('UPDATE integration_outbox SET attempts = 8 WHERE id = ?')->execute([$claimed['id']]);
        self::assertTrue($delivery->failed($sid, (int) $claimed['id'], $claimed['lease_token'], 'synthetic_failure'));
        $this->db->pdo()->prepare('UPDATE integration_outbox SET created_at = DATE_SUB(NOW(6), INTERVAL 2 DAY) WHERE id = ?')->execute([$claimed['id']]);

        $diagnostics = $this->container->get(\MyInvoice\Service\Integration\IntegrationDiagnosticsService::class);
        self::assertSame(1, $diagnostics->purgeExpired()['redacted']);
        $row = $this->db->pdo()->query('SELECT payload_json, payload_redacted_at FROM integration_outbox WHERE id = ' . (int) $claimed['id'])->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('{}', $row['payload_json']);
        self::assertNotNull($row['payload_redacted_at']);
        self::assertFalse($delivery->retryDeadLetter($sid, $connection['id'], (int) $claimed['id']));
        self::assertTrue($diagnostics->overview($sid, $connection['id'])['errors'][0]['payload_redacted']);
    }

    public function testReconciliationRunsAsDurableCatalogJobWithProgress(): void
    {
        $sid = $this->createSupplier();
        $connection = $this->connection($sid);
        $itemId = $this->item($sid, 'RECONCILE-ONE');
        $this->container->get(ExternalEntityMapRepository::class)
            ->put($sid, $connection['id'], 'synthetic', 'stock_item', 'reconcile-one', $itemId);
        $jobId = $this->container->get(IntegrationReconcileService::class)->enqueue($sid, $connection['id'], $this->userId);
        $result = $this->container->get(IntegrationReconcileWorker::class)->tick($sid);

        self::assertSame($jobId, $result['id']);
        self::assertSame('completed', $result['status']);
        self::assertSame(1, $result['checkpoint']);
        self::assertSame(1, $result['total']);
        self::assertSame(1, $result['report']['processed']);
        self::assertSame(0, $result['report']['missing_internal']);
    }

    public function testIntegrationPermissionIsSeededOnlyForStoredAdminRoles(): void
    {
        $rows = $this->db->pdo()->query("SELECT r.system_key, p.access_level FROM role_permissions p
            JOIN roles r ON r.id = p.role_id WHERE p.permission_key = 'eshop.integrations' ORDER BY r.system_key")
            ->fetchAll(\PDO::FETCH_KEY_PAIR);
        self::assertSame(['admin' => 2, 'admin_plus' => 2], $rows);
        self::assertArrayNotHasKey('superadmin', $rows);
    }

    private function connection(int $supplierId, string $name = 'Synthetic'): array
    {
        return $this->container->get(IntegrationConnectionService::class)->create($supplierId, [
            'connector_key' => 'synthetic.adapter', 'name' => $name, 'status' => 'active',
            'mappings' => ['warehouse' => 'MAIN', 'currency' => 'CZK', 'language' => 'cs'],
            'field_ownership' => ['product.name' => 'local'],
        ], $this->userId);
    }

    private function eventBody(string $eventId, int $version): string
    {
        return json_encode([
            'event_id' => $eventId, 'entity_type' => 'product', 'entity_id' => 'ordered-product',
            'event_type' => 'product.updated', 'aggregate_version' => $version,
        ], JSON_THROW_ON_ERROR);
    }
}
