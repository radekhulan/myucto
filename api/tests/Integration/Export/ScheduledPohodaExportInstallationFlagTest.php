<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Export;

use MyInvoice\Action\Report\PohodaScheduleAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Export\ExportPeriodResolver;
use MyInvoice\Service\Export\PohodaXmlExporter;
use MyInvoice\Service\Export\ScheduledPohodaExportFeature;
use MyInvoice\Service\Export\ScheduledPohodaExportService;
use MyInvoice\Service\Mail\Mailer;
use MyInvoice\Service\Mail\SupplierEmailContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Automatický export do Pohody je volba instalace `export.scheduled_pohoda`, výchozí vypnuto.
 * Vypnutá: API nastavení i ručního spuštění vrací 404 a cron skončí s exit 0 bez práce,
 * uložené nastavení firmy přitom zůstává. Zapnutá: chování beze změny.
 */
#[Group('integration')]
final class ScheduledPohodaExportInstallationFlagTest extends TestCase
{
    private $container;
    private Connection $db;
    private int $supplierId = 0;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 4);
        if (!is_file($root . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje.');
        }
        $this->container = Bootstrap::buildContainer();
        $this->db = $this->container->get(Connection::class);
        $pdo = $this->db->pdo();
        $cur = (int) $pdo->query("SELECT id FROM currencies WHERE code = 'CZK' LIMIT 1")->fetchColumn();
        $vat = (int) $pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn();
        $cty = (int) $pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn();
        if (!$cur || !$vat || !$cty) {
            $this->markTestSkipped('Chybí základní data.');
        }
        $pdo->prepare('INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id)
                       VALUES ("Přepínač s.r.o.", "Testovaci 1", "Praha", "11000", ?, "p@example.com", ?, ?)')->execute([$cty, $cur, $vat]);
        $this->supplierId = (int) $pdo->lastInsertId();
        $start = (new \DateTimeImmutable('first day of last month'))->format('Y-m');
        $pdo->prepare("INSERT INTO scheduled_pohoda_export (supplier_id, enabled, run_day, include_credit_notes, recipient_email, start_month)
                       VALUES (?, 1, 1, 1, 'ucetni@example.com', ?)")->execute([$this->supplierId, $start]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->supplierId > 0) {
            $pdo = $this->db->pdo();
            $pdo->prepare('DELETE d FROM scheduled_pohoda_export_doc d JOIN scheduled_pohoda_export_run r ON r.id = d.run_id WHERE r.supplier_id = ?')->execute([$this->supplierId]);
            $pdo->prepare('DELETE FROM scheduled_pohoda_export_run WHERE supplier_id = ?')->execute([$this->supplierId]);
            $pdo->prepare('DELETE FROM scheduled_pohoda_export WHERE supplier_id = ?')->execute([$this->supplierId]);
            $pdo->prepare('DELETE FROM supplier WHERE id = ?')->execute([$this->supplierId]);
        }
    }

    /** @return array<string,array{string,string}> */
    public static function endpointProvider(): array
    {
        return [
            'nastavení' => ['get', 'GET'],
            'uložení'   => ['save', 'PUT'],
            'spustit'   => ['run', 'POST'],
        ];
    }

    #[DataProvider('endpointProvider')]
    public function testVypnutaVolbaInstalaceVraci404(string $handler, string $method): void
    {
        $response = $this->call($handler, $method, false, ['month' => '2026-01', 'enabled' => false, 'run_day' => 5]);

        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('scheduled_pohoda_export_disabled', (string) $response->getBody());
        // Uložené nastavení firmy zůstává beze změny.
        $config = $this->container->get(ScheduledPohodaExportService::class)->config($this->supplierId);
        self::assertTrue($config['enabled']);
        self::assertSame('ucetni@example.com', $config['recipient_email']);
        self::assertSame(0, $this->runCount());
    }

    public function testZapnutaVolbaInstalaceVraciNastaveni(): void
    {
        $response = $this->call('get', 'GET', true);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $data = $body['data'] ?? $body;
        self::assertTrue($data['config']['enabled']);
        self::assertSame('ucetni@example.com', $data['config']['recipient_email']);
    }

    public function testCronSVypnutouVolbouSkonciBezPrace(): void
    {
        [$code, $out] = $this->cron('0', '--dry-run');

        self::assertSame(0, $code, $out);
        self::assertStringContainsString('vypnuto (export.scheduled_pohoda)', $out);
        self::assertStringNotContainsString('"due"', $out);
        self::assertSame(0, $this->runCount());
    }

    public function testCronSeZapnutouVolbouNajdeMesicKOdeslani(): void
    {
        [$code, $out] = $this->cron('1', '--dry-run');

        self::assertSame(0, $code, $out);
        $json = json_decode(substr($out, (int) strpos($out, '{')), true);
        self::assertIsArray($json, $out);
        self::assertArrayHasKey((string) $this->supplierId, $json['due']);
    }

    /** @param array<string,mixed> $body */
    private function call(string $handler, string $method, bool $enabled, array $body = []): ResponseInterface
    {
        // Bez skutečné pošty: kdyby brána propustila spuštění, e-mail neodejde.
        $mailer = $this->createMock(Mailer::class);
        $mailer->expects(self::never())->method('sendTemplateDetailed');
        $service = new ScheduledPohodaExportService(
            $this->db,
            $this->container->get(PohodaXmlExporter::class),
            $this->container->get(ExportPeriodResolver::class),
            $mailer,
            $this->container->get(SupplierEmailContext::class),
            $this->container->get(ActivityLogger::class),
        );
        $action = new PohodaScheduleAction(
            $service,
            new ScheduledPohodaExportFeature(new Config(['export' => ['scheduled_pohoda' => $enabled]])),
        );
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, '/api/reports/pohoda-schedule' . ($handler === 'run' ? '/run' : ''))
            ->withParsedBody($body)
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 0, 'role' => 'admin', 'is_superadmin' => true])
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId);
        return $action->{$handler}($request, (new ResponseFactory())->createResponse());
    }

    private function runCount(): int
    {
        $st = $this->db->pdo()->prepare('SELECT COUNT(*) FROM scheduled_pohoda_export_run WHERE supplier_id = ?');
        $st->execute([$this->supplierId]);
        return (int) $st->fetchColumn();
    }

    /** @return array{int,string} */
    private function cron(string $flag, string ...$args): array
    {
        $env = getenv();
        $env['MYINVOICE_SCHEDULED_POHODA_EXPORT'] = $flag;
        $cmd = array_merge([PHP_BINARY, dirname(__DIR__, 3) . '/bin/cron-scheduled-pohoda-export.php'], $args);
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        self::assertIsResource($proc);
        $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($proc), $out];
    }
}
