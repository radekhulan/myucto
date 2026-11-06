<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Export;

use MyInvoice\Action\Settings\SettingsAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/** Skutečné mazání firmy (SettingsAction::deleteSupplierById) smaže plán, běhy i otisky dokladů. */
#[Group('integration')]
final class ScheduledPohodaExportSupplierDeleteTest extends TestCase
{
    private Connection $db;
    private int $supplierId = 0;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje.');
        }
        $this->db = Bootstrap::buildContainer()->get(Connection::class);
    }

    protected function tearDown(): void
    {
        if ($this->supplierId > 0) {
            $pdo = $this->db->pdo();
            $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
            foreach (['scheduled_pohoda_export_doc' => null, 'scheduled_pohoda_export_run' => 'supplier_id', 'scheduled_pohoda_export' => 'supplier_id'] as $t => $col) {
                if ($col !== null) {
                    $pdo->prepare("DELETE FROM $t WHERE $col = ?")->execute([$this->supplierId]);
                }
            }
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
            $pdo->prepare('DELETE FROM supplier WHERE id = ?')->execute([$this->supplierId]);
        }
    }

    public function testDeletingSupplierThroughActionRemovesScheduleRunsAndFingerprints(): void
    {
        $pdo = $this->db->pdo();
        $cur = (int) $pdo->query("SELECT id FROM currencies WHERE code = 'CZK' LIMIT 1")->fetchColumn();
        $vat = (int) $pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn();
        $cty = (int) $pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn();
        $actor = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        $current = (int) $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        if (!$cur || !$vat || !$cty || !$actor || !$current) {
            $this->markTestSkipped('Chybí základní data.');
        }
        $pdo->prepare('INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id)
                       VALUES ("Smazat s.r.o.", "Testovaci 1", "Praha", "11000", ?, "s@example.com", ?, ?)')->execute([$cty, $cur, $vat]);
        $this->supplierId = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO scheduled_pohoda_export (supplier_id, enabled, recipient_email, start_month) VALUES (?, 1, 'u@example.com', '2026-09')")->execute([$this->supplierId]);
        $pdo->prepare("INSERT INTO scheduled_pohoda_export_run (supplier_id, period_label, status, doc_count) VALUES (?, '2026-09', 'ok', 1)")->execute([$this->supplierId]);
        $runId = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO scheduled_pohoda_export_doc (run_id, invoice_id, fp) VALUES (?, 999999999, REPEAT('a', 32))")->execute([$runId]);

        $request = (new ServerRequestFactory())->createServerRequest('DELETE', '/api/suppliers/' . $this->supplierId)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $current)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $actor, 'role' => 'admin']);
        $response = Bootstrap::buildContainer()->get(SettingsAction::class)
            ->deleteSupplierById($request, new Response(), ['id' => (string) $this->supplierId]);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        foreach (['scheduled_pohoda_export' => 'supplier_id', 'scheduled_pohoda_export_run' => 'supplier_id', 'scheduled_pohoda_export_doc' => 'run_id'] as $t => $col) {
            $key = $col === 'run_id' ? $runId : $this->supplierId;
            $st = $pdo->prepare("SELECT COUNT(*) FROM $t WHERE $col = ?");
            $st->execute([$key]);
            $this->assertSame(0, (int) $st->fetchColumn(), $t);
        }
    }
}
