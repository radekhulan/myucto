<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Eshop;

use MyInvoice\Action\Eshop\IntegrationAction;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Demo\DemoMode;
use MyInvoice\Service\Integration\IntegrationConnectionService;
use MyInvoice\Service\Integration\IntegrationSampleProvisioner;
use MyInvoice\Tests\Integration\Stock\StockTestCase;
use MyInvoice\Tests\Support\WorkerLockWaitTrait;
use PHPUnit\Framework\Attributes\Group;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Symfony\Component\Process\Process;

#[Group('integration')]
final class IntegrationSampleProvisionerTest extends StockTestCase
{
    use WorkerLockWaitTrait;

    private const SAMPLE = 'Ukázkové napojení (vzor Shoptet)';

    private IntegrationAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = $this->container->get(IntegrationAction::class);
    }

    public function testFirstListCreatesOneDraftSampleFromCompanyCodebooks(): void
    {
        $sid = $this->createSupplier();
        $this->warehouse($sid, 'VEDLEJSI', false);
        $this->warehouse($sid, 'HLAVNI', true);
        $this->locale($sid, 'cs', false);
        $this->locale($sid, 'de', true);

        $list = $this->call('list', $sid);
        self::assertSame(200, $list['status']);
        self::assertCount(1, $list['body']);
        $sample = $list['body'][0];
        self::assertSame(self::SAMPLE, $sample['name']);
        self::assertSame('custom.webhook', $sample['connector_key']);
        self::assertSame('draft', $sample['status']);
        self::assertFalse($sample['webhook_configured']);
        self::assertFalse($sample['credentials_configured']);
        self::assertSame(60, $sample['rate_limit_per_minute']);
        self::assertSame(30, $sample['retention_days']);
        self::assertSame([
            'warehouses' => ['HLAVNI' => '<stockId skladu v Shoptetu>'],
            'currencies' => ['CZK' => 'CZK'],
            'languages' => ['de' => '<kód jazyka v Shoptetu>'],
        ], $sample['mappings']);
        self::assertSame('remote', $sample['field_ownership']['order.status']);
        self::assertSame('local', $sample['field_ownership']['stock.quantity']);
        self::assertSame('local', $sample['field_ownership']['order.payment_status']);
        self::assertNotNull($this->seededAt($sid));

        // Formulář nového připojení dostává tytéž hodnoty z jediného místa.
        $defaults = $this->call('connectors', $sid)['body']['defaults']['custom.webhook'];
        self::assertSame($sample['mappings'], $defaults['mappings']);
        self::assertSame($sample['field_ownership'], $defaults['field_ownership']);
        self::assertSame('draft', $defaults['status']);
    }

    public function testRepeatedListKeepsOneSampleAndDeletedSampleStaysDeleted(): void
    {
        $sid = $this->createSupplier();
        $this->call('list', $sid);
        $second = $this->call('list', $sid);
        self::assertCount(1, $second['body']);

        $deleted = $this->call('delete', $sid, id: (int) $second['body'][0]['id']);
        self::assertSame(200, $deleted['status']);
        self::assertSame([], $this->call('list', $sid)['body']);
        self::assertNotNull($this->seededAt($sid));

        $manual = $this->call('sample', $sid);
        self::assertSame(201, $manual['status']);
        self::assertSame(self::SAMPLE, $manual['body']['name']);
        self::assertSame('draft', $manual['body']['status']);
        $again = $this->call('sample', $sid);
        self::assertSame(self::SAMPLE . ' 2', $again['body']['name']);
    }

    /**
     * Instalace, kde firma napojení už má (založené ručně nebo před touto funkcí),
     * nesmí po aktualizaci dostat mezi skutečná napojení ještě ukázku.
     */
    public function testCompanyWithExistingConnectionGetsNoSampleButIsMarked(): void
    {
        $sid = $this->createSupplier();
        $existing = $this->container->get(IntegrationConnectionService::class)->createSample($sid, $this->userId);
        $this->db->pdo()->prepare('UPDATE integration_connections SET name = ? WHERE supplier_id = ? AND id = ?')
            ->execute(['Vlastní e-shop', $sid, (int) $existing['id']]);

        $list = $this->call('list', $sid);

        self::assertSame(200, $list['status']);
        self::assertCount(1, $list['body']);
        self::assertSame('Vlastní e-shop', $list['body'][0]['name']);
        self::assertNotNull($this->seededAt($sid), 'Značka se nastaví, ať se kontrola neopakuje při každém otevření.');
        $this->call('list', $sid);
        self::assertSame(1, $this->countConnections($sid), 'Ani další otevření seznamu ukázku nepřidá.');
    }

    /** Veřejná ukázka je jen ke čtení: ani uživatel s právem úprav ukázku nezaloží. */
    public function testDemoCreatesNoSampleAndNoMarker(): void
    {
        $sid = $this->createSupplier();
        self::assertInstanceOf(\DI\Container::class, $this->container);
        $provisioner = $this->container->make(IntegrationSampleProvisioner::class, [
            'demo' => new DemoMode(new Config(['demo' => ['enabled' => true]])),
        ]);

        self::assertFalse($provisioner->ensure($sid, $this->userId));
        self::assertNull($this->seededAt($sid));
        self::assertSame(0, $this->countConnections($sid));
    }

    public function testCompaniesAreIsolatedAndReadersCreateNothing(): void
    {
        $plain = $this->createSupplier();
        $rich = $this->createSupplier();
        $this->warehouse($rich, 'CIZI', true);
        $this->locale($rich, 'hu', true);

        $reader = new EffectiveRole(9, 'Čtenář integrací', 'staff', true, ['eshop.integrations' => 1]);
        $readOnly = $this->call('list', $plain, role: $reader);
        self::assertSame(200, $readOnly['status']);
        self::assertSame([], $readOnly['body']);
        self::assertNull($this->seededAt($plain));
        self::assertSame(403, $this->call('sample', $plain, role: $reader)['status']);

        $sample = $this->call('list', $plain)['body'][0];
        self::assertSame(['currencies' => ['CZK' => 'CZK']], $sample['mappings'],
            'Sklad ani jazyk jiné firmy se do ukázky nesmí dostat.');
        self::assertNull($this->seededAt($rich), 'Otevření seznamu jedné firmy nezakládá ukázku jiné.');
        self::assertSame(0, $this->countConnections($rich));
    }

    public function testConcurrentRequestWaitsForLockAndDoesNotCreateSecondSample(): void
    {
        $sid = $this->createSupplier();
        $root = dirname(__DIR__, 4);
        $expectedDatabase = (string) $this->db->pdo()->query('SELECT DATABASE()')->fetchColumn();
        self::assertMatchesRegularExpression('/_test(?:_worker[A-Za-z0-9_]*)?$/D', $expectedDatabase);
        $controlFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'integration-sample-' . bin2hex(random_bytes(8)) . '.json';
        $worker = <<<'PHP'
$root = $argv[1];
$supplierId = (int) $argv[2];
$controlFile = $argv[3];
$expectedDatabase = $argv[4];
require $root . '/api/vendor/autoload.php';
$config = \MyInvoice\Infrastructure\Config\Config::load($root);
if ((string) $config->get('db.name') !== $expectedDatabase) { exit(10); }
$container = \MyInvoice\Bootstrap::buildContainer();
$pdo = $container->get(\MyInvoice\Infrastructure\Database\Connection::class)->pdo();
if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== $expectedDatabase) { exit(11); }
file_put_contents($controlFile, json_encode(['ready' => true, 'connection_id' => (int) $pdo->query('SELECT CONNECTION_ID()')->fetchColumn()], JSON_THROW_ON_ERROR));
echo $container->get(\MyInvoice\Service\Integration\IntegrationSampleProvisioner::class)->ensure($supplierId) ? 'created' : 'skipped';
PHP;
        $process = new Process([PHP_BINARY, '-r', $worker, $root, (string) $sid, $controlFile, $expectedDatabase], $root);
        $process->setEnv(['MYINVOICE_DB_NAME' => $expectedDatabase]);
        $pdo = $this->db->pdo();
        try {
            // Tento požadavek drží zámek řádku firmy; značka je zatím prázdná, takže
            // souběžný požadavek projde rychlou kontrolou a musí čekat na zámek.
            $pdo->beginTransaction();
            $pdo->prepare('SELECT integration_sample_seeded_at FROM supplier WHERE id = ? FOR UPDATE')->execute([$sid]);

            $process->start();
            $control = $this->awaitWorkerReady($process, $controlFile);
            $this->assertWorkerWaitsOnLock(
                $pdo,
                $process,
                (int) $control['connection_id'],
                '/FROM\s+supplier\s+WHERE\s+id\s*=\s*\S+\s+FOR\s+UPDATE/i',
                'Souběžný požadavek nečekal na zámek firmy.',
            );
            // Až teď první požadavek ukázku dokončí; čekající po odemčení musí značku uvidět.
            $this->container->get(IntegrationConnectionService::class)->createSample($sid, $this->userId);
            $pdo->prepare('UPDATE supplier SET integration_sample_seeded_at = NOW() WHERE id = ?')->execute([$sid]);
            $pdo->commit();
            $process->wait();
            self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            self::assertSame('skipped', trim($process->getOutput()));
            self::assertSame(1, $this->countConnections($sid));
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($process->isRunning()) {
                $process->stop(1);
            }
            if (is_file($controlFile)) {
                unlink($controlFile);
            }
        }
    }

    private function locale(int $supplierId, string $code, bool $default): void
    {
        $this->db->pdo()->prepare('INSERT INTO stock_locales (supplier_id, code, name, is_default) VALUES (?, ?, ?, ?)')
            ->execute([$supplierId, $code, strtoupper($code), $default ? 1 : 0]);
    }

    private function seededAt(int $supplierId): ?string
    {
        $stmt = $this->db->pdo()->prepare('SELECT integration_sample_seeded_at FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $value = $stmt->fetchColumn();
        return $value === false || $value === null ? null : (string) $value;
    }

    private function countConnections(int $supplierId): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM integration_connections WHERE supplier_id = ?');
        $stmt->execute([$supplierId]);
        return (int) $stmt->fetchColumn();
    }

    /** @return array{status:int,body:array} */
    private function call(string $method, int $supplierId, array $body = [], ?int $id = null, ?EffectiveRole $role = null): array
    {
        $http = match ($method) {
            'list', 'connectors' => 'GET',
            'delete' => 'DELETE',
            default => 'POST',
        };
        $request = (new ServerRequestFactory())->createServerRequest($http, '/api/eshop/integrations')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withParsedBody($body);
        if ($role !== null) {
            $request = $request->withAttribute('auth.effective_role', $role);
        }
        $response = $id === null
            ? $this->action->{$method}($request, new Response())
            : $this->action->{$method}($request, new Response(), ['id' => (string) $id]);
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);
        return ['status' => $response->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }
}
