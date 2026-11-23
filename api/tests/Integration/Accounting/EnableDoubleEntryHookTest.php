<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Action\Settings\SettingsAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Ověřuje additivní hook (Epic F1): zapnutí supplier.accounting_mode = 'double_entry'
 * přes SettingsAction naseeduje směrnou účtovou osnovu (idempotentně), aby na ni
 * mohl PostingService mapovat account_code. Běží v transakci → rollback.
 */
#[Group('integration')]
final class EnableDoubleEntryHookTest extends TestCase
{
    private Connection $db;
    private SettingsAction $settings;
    private int $supplierId = 0;
    private int $userId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db       = $container->get(Connection::class);
            $this->settings = $container->get(SettingsAction::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí supplier/user v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
    }

    public function testEnablingDoubleEntrySeedsChart(): void
    {
        $pdo = $this->db->pdo();
        // Výchozí stav: firma bez osnovy (smaž případné existující účty v rámci tx).
        // Deník i alokace přijatých faktur referencují účty přes RESTRICT;
        // vše se po testu obnoví rollbackem.
        $pdo->prepare('DELETE FROM journal_entries WHERE supplier_id = ?')->execute([$this->supplierId]);
        $pdo->prepare('DELETE FROM purchase_invoice_vat_allocations WHERE supplier_id = ?')->execute([$this->supplierId]);
        $pdo->prepare('DELETE FROM chart_of_accounts WHERE supplier_id = ?')->execute([$this->supplierId]);
        $before = (int) $pdo->query("SELECT COUNT(*) FROM chart_of_accounts WHERE supplier_id = {$this->supplierId}")->fetchColumn();
        self::assertSame(0, $before);

        $req = (new ServerRequestFactory())
            ->createServerRequest('PUT', '/api/settings/supplier')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withParsedBody(['accounting_mode' => 'double_entry']);
        $resp = $this->settings->updateSupplier($req, new Psr7Response());
        self::assertSame(200, $resp->getStatusCode());

        $after = (int) $pdo->query("SELECT COUNT(*) FROM chart_of_accounts WHERE supplier_id = {$this->supplierId}")->fetchColumn();
        self::assertGreaterThan(0, $after, 'Zapnutí double_entry naseedovalo osnovu.');
    }

    public function testSwitchToTaxEvidenceBeforeFivePeriodsIsRejected(): void
    {
        $this->prepareDoubleEntryHistory('2022-01-01');

        $resp = $this->updateMode('tax_evidence', '2026-01-01');
        $resp->getBody()->rewind();
        $body = json_decode((string) $resp->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(422, $resp->getStatusCode());
        self::assertSame('accounting_minimum_periods', $body['error']['code']);
    }

    public function testSwitchToTaxEvidenceAfterFivePeriodsIsAllowed(): void
    {
        $this->prepareDoubleEntryHistory('2021-01-01');

        $resp = $this->updateMode('tax_evidence', '2026-01-01');

        self::assertSame(200, $resp->getStatusCode());
    }

    public function testSwitchToTaxEvidenceWithoutHistoryFailsClosed(): void
    {
        $this->db->pdo()->prepare("UPDATE supplier SET accounting_mode = 'double_entry', taxpayer_type = 'fo' WHERE id = ?")
            ->execute([$this->supplierId]);
        $this->db->pdo()->prepare('DELETE FROM supplier_accounting_modes WHERE supplier_id = ?')
            ->execute([$this->supplierId]);

        $resp = $this->updateMode('tax_evidence', '2026-01-01');
        $resp->getBody()->rewind();
        $body = json_decode((string) $resp->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(422, $resp->getStatusCode());
        self::assertSame('accounting_mode_history_missing', $body['error']['code']);
    }

    public function testSwitchBackToTaxEvidenceFromSameDateIsCorrection(): void
    {
        $this->prepareDoubleEntryHistory('2026-01-01');

        $resp = $this->updateMode('tax_evidence', '2026-01-01');

        self::assertSame(200, $resp->getStatusCode());
        $mode = $this->db->pdo()->prepare(
            "SELECT accounting_mode FROM supplier_accounting_modes WHERE supplier_id = ? AND effective_from = '2026-01-01'"
        );
        $mode->execute([$this->supplierId]);
        self::assertSame('tax_evidence', $mode->fetchColumn());
    }

    public function testSwitchFromActivatedDoubleEntryOnSameDateIsCorrection(): void
    {
        $this->prepareDoubleEntryHistory('2026-01-01');
        $this->db->pdo()->prepare(
            "INSERT INTO supplier_accounting_modes (supplier_id, effective_from, accounting_mode) VALUES (?, '2020-01-01', 'tax_evidence')"
        )->execute([$this->supplierId]);

        $resp = $this->updateMode('tax_evidence', '2026-01-01');

        self::assertSame(200, $resp->getStatusCode());
    }

    public function testSwitchAfterOneDoubleEntryPeriodIsStillRejected(): void
    {
        $this->prepareDoubleEntryHistory('2026-01-01');

        $resp = $this->updateMode('tax_evidence', '2027-01-01');
        $resp->getBody()->rewind();
        $body = json_decode((string) $resp->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(422, $resp->getStatusCode());
        self::assertSame('accounting_minimum_periods', $body['error']['code']);
    }

    private function prepareDoubleEntryHistory(string $effectiveFrom): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare("UPDATE supplier SET accounting_mode = 'double_entry', taxpayer_type = 'fo' WHERE id = ?")
            ->execute([$this->supplierId]);
        $pdo->prepare('DELETE FROM supplier_accounting_modes WHERE supplier_id = ?')->execute([$this->supplierId]);
        $pdo->prepare(
            "INSERT INTO supplier_accounting_modes (supplier_id, effective_from, accounting_mode) VALUES (?, ?, 'double_entry')"
        )->execute([$this->supplierId, $effectiveFrom]);
    }

    private function updateMode(string $mode, string $effectiveFrom): \Psr\Http\Message\ResponseInterface
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest('PUT', '/api/settings/supplier')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withParsedBody([
                'accounting_mode' => $mode,
                'accounting_mode_effective_from' => $effectiveFrom,
            ]);
        return $this->settings->updateSupplier($req, new Psr7Response());
    }
}
