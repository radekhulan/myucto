<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Settings;

use MyInvoice\Action\Settings\TaxRepresentationAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Tax\Return\TaxRepresentationService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Integrační testy evidence zastoupení daňovým poradcem (§29/2 DŘ, migrace 1662).
 *
 * Action volaná přímo z DI kontejneru s ATTR_USER (role admin) + ATTR_CURRENT_ID —
 * vzor {@see \MyInvoice\Tests\Integration\Settings\VatStatusHistoryTest}, jen bez
 * retro-guardu (viz migrace 1662 — zastoupení nic neúčtuje). Hodnoty v testu jsou
 * VŽDY vymyšlené.
 */
#[Group('integration')]
final class TaxRepresentationTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private TaxRepresentationAction $action;
    private TaxRepresentationService $service;

    private int $userId = 0;
    private int $supplierId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container     = Bootstrap::buildApp()->getContainer();
            $this->db      = $container->get(Connection::class);
            $this->action  = $container->get(TaxRepresentationAction::class);
            $this->service = $container->get(TaxRepresentationService::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $sourceSupplier = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId   = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($sourceSupplier === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier/user) v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplier);
        $pdo->prepare('DELETE FROM supplier_tax_representation_history WHERE supplier_id = ?')->execute([$this->supplierId]);
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

    public function testFirmaBezHistorieNeniZastoupena(): void
    {
        $status = $this->service->at($this->supplierId, '2025-12-31');
        self::assertFalse($status['represented']);
        self::assertNull($status['type']);
    }

    public function testSaveAddsRepresentedRow(): void
    {
        $r = $this->save([
            'effective_from' => '2025-01-01',
            'represented' => true,
            'type' => 'F',
            'first_name' => 'Vzorový',
            'last_name' => 'Poradce',
            'ev_number' => 'EV-0001',
        ]);
        self::assertSame(200, $r['status'], (string) json_encode($r['body']));
        $rows = $r['body']['tax_representation_history'];
        self::assertCount(1, $rows);
        self::assertTrue($rows[0]['represented']);
        self::assertSame('F', $rows[0]['type']);
        self::assertSame('Vzorový', $rows[0]['first_name']);
        self::assertSame('EV-0001', $rows[0]['ev_number']);

        $status = $this->service->at($this->supplierId, '2025-06-15');
        self::assertTrue($status['represented']);
        self::assertSame('EV-0001', $status['ev_number']);
    }

    public function testSaveUpsertsExistingDate(): void
    {
        $this->save(['effective_from' => '2025-01-01', 'represented' => false]);
        $r = $this->save([
            'effective_from' => '2025-01-01', 'represented' => true, 'type' => 'P',
            'company_name' => 'Vzorová poradna s.r.o.', 'ev_number' => 'EV-0002',
        ]);
        self::assertSame(200, $r['status']);
        self::assertCount(1, $r['body']['tax_representation_history'], 'Stejné datum = upsert, ne nový řádek.');
        self::assertSame('Vzorová poradna s.r.o.', $r['body']['tax_representation_history'][0]['company_name']);
    }

    /** Stav K DATU: přiznání za starší rok nesmí vidět pozdější změnu zastoupení. */
    public function testPointInTimeLookup(): void
    {
        $this->save(['effective_from' => '2024-01-01', 'represented' => false]);
        $this->save([
            'effective_from' => '2025-06-01', 'represented' => true, 'type' => 'F',
            'first_name' => 'Nový', 'last_name' => 'Poradce', 'ev_number' => 'EV-0003',
        ]);

        self::assertFalse($this->service->at($this->supplierId, '2024-12-31')['represented']);
        self::assertFalse($this->service->at($this->supplierId, '2025-05-31')['represented']);
        self::assertTrue($this->service->at($this->supplierId, '2025-06-01')['represented']);
        self::assertTrue($this->service->at($this->supplierId, '2026-01-01')['represented']);
    }

    public function testRepresentedRequiresType(): void
    {
        $r = $this->save(['effective_from' => '2025-01-01', 'represented' => true, 'ev_number' => 'EV-0004']);
        self::assertSame(422, $r['status']);
        self::assertSame('validation_failed', $r['body']['error']['code']);
    }

    public function testRepresentedRequiresEvNumber(): void
    {
        $r = $this->save([
            'effective_from' => '2025-01-01', 'represented' => true, 'type' => 'F',
            'first_name' => 'A', 'last_name' => 'B',
        ]);
        self::assertSame(422, $r['status']);
    }

    public function testInvalidDateRejected(): void
    {
        $r = $this->save(['effective_from' => '2025-13-45', 'represented' => false]);
        self::assertSame(422, $r['status']);
    }

    public function testDeleteRow(): void
    {
        $add = $this->save(['effective_from' => '2025-01-01', 'represented' => false]);
        $id = (int) $add['body']['tax_representation_history'][0]['id'];
        $r = $this->delete($id);
        self::assertSame(200, $r['status']);
        self::assertCount(0, $r['body']['tax_representation_history']);
    }

    public function testDeleteMissingRowReturns404(): void
    {
        $r = $this->delete(999999999);
        self::assertSame(404, $r['status']);
    }

    /**
     * @param array<string,mixed> $body
     * @return array{status:int, body:array<string,mixed>}
     */
    private function save(array $body): array
    {
        return $this->call('save', 'POST', ['body' => $body]);
    }

    /** @return array{status:int, body:array<string,mixed>} */
    private function delete(int $id): array
    {
        return $this->call('delete', 'DELETE', ['args' => ['id' => (string) $id]]);
    }

    /**
     * @param array<string,mixed> $opts
     * @return array{status:int, body:array<string,mixed>}
     */
    private function call(string $method, string $httpMethod, array $opts = []): array
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest($httpMethod, '/api/settings/tax-representation-history')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin']);
        if (array_key_exists('body', $opts) && $opts['body'] !== []) {
            $req = $req->withParsedBody($opts['body']);
        }
        $args = $opts['args'] ?? [];
        $resp = $args === []
            ? $this->action->{$method}($req, new Psr7Response())
            : $this->action->{$method}($req, new Psr7Response(), $args);
        $resp->getBody()->rewind();
        $decoded = json_decode((string) $resp->getBody(), true);
        return ['status' => $resp->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }
}
