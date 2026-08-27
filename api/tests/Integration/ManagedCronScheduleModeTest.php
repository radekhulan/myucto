<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration;

use MyInvoice\Action\Admin\SetCronScheduleModeAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Cron\CronScheduleMode;
use MyInvoice\Service\System\ManagedModeGuard;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Režim plánovaných úloh nepatří zákazníkovi spravované instalace.
 *
 * ⚠️ Plánování drží provozovatel jedinou položkou `cron-dispatch`. Přepnutí na
 * `individual` znamená, že se dispatcher ukončí bez práce a NEBĚŽÍ NIC —
 * žádné obnovy licence, zálohy ani avíza. Heartbeat u toho tiká dál, takže se
 * na to přijde až z monitoringu provozovatele; přesně tak se to 27. 8. 2026
 * stalo třikrát za večer.
 */
#[Group('integration')]
final class ManagedCronScheduleModeTest extends TestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        if (!is_file(Bootstrap::rootDir() . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $this->db = Bootstrap::buildApp()->getContainer()->get(Connection::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
    }

    private function action(bool $managed): SetCronScheduleModeAction
    {
        return new SetCronScheduleModeAction(
            $this->db,
            Bootstrap::buildApp()->getContainer()->get(ActivityLogger::class),
            new ManagedModeGuard(new Config(['app' => ['managed' => $managed]])),
        );
    }

    private function request(string $mode): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('PUT', '/api/admin/cron-jobs/schedule-mode')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 1, 'role' => 'admin'])
            ->withParsedBody(['mode' => $mode]);
    }

    public function testManagedInstallationCannotSwitchTheMode(): void
    {
        $before = CronScheduleMode::current($this->db->pdo());

        $response = $this->action(true)->__invoke($this->request('individual'), new Psr7Response());

        self::assertSame(ManagedModeGuard::HTTP_STATUS, $response->getStatusCode());
        $body = (array) json_decode((string) $response->getBody(), true);
        self::assertSame(ManagedModeGuard::ERROR_CODE, $body['error']['code'] ?? null);
        self::assertSame(
            $before,
            CronScheduleMode::current($this->db->pdo()),
            'odmítnutí nesmí režim ani tak přepsat'
        );
    }

    public function testSelfHostedInstallationStillDecidesForItself(): void
    {
        // Vlastní provoz si plánování řídí sám — zámek platí jen u spravovaného.
        $before = CronScheduleMode::current($this->db->pdo());
        try {
            $response = $this->action(false)->__invoke($this->request('dispatcher'), new Psr7Response());

            self::assertSame(200, $response->getStatusCode());
            self::assertSame('dispatcher', CronScheduleMode::current($this->db->pdo()));
        } finally {
            CronScheduleMode::set($this->db->pdo(), $before, null);
        }
    }
}
