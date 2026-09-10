<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Tax;

use MyInvoice\Action\Tax\Return\TaxReturnAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Epic DP (issue #18) — E2E API DPFO: GET (podklady §7 z paušálu) → PUT §6 vstupy →
 * finalize → GET XML (DPFDP7 validované proti EPO2 XSD + archivace). Bez fixtur faktur
 * (příjem §7 = 0, daň řídí §6). Izolovaný supplier (fo), rollback v tearDown.
 */
#[Group('integration')]
final class TaxReturnFoApiTest extends TestCase
{
    private const YEAR = 2048;

    private Connection $db;
    private TaxReturnAction $action;
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
            $container = Bootstrap::buildApp()->getContainer();
            $this->db = $container->get(Connection::class);
            $this->action = $container->get(TaxReturnAction::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        $currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $vatRateId = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->userId === 0 || $czId === 0 || $currencyId === 0 || $vatRateId === 0) {
            $this->markTestSkipped('Chybí základní data v DB.');
        }

        $pdo->beginTransaction();
        $constants = \MyInvoice\Service\Tax\TaxConstants::forYear(2026);
        $constants['year'] = self::YEAR;
        $pdo->prepare('INSERT INTO tax_constants (year, data) VALUES (?, ?) ON DUPLICATE KEY UPDATE data = VALUES(data)')
            ->execute([self::YEAR, json_encode($constants, JSON_UNESCAPED_UNICODE)]);
        $this->inTx = true;

        $stmt = $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id,
                                   taxpayer_type, accounting_mode, ic, dic, financial_office_code, cz_nace_code)
             VALUES (?, "Krátká 12/3", "Praha", "11000", ?, "dp-fo@example.com", ?, ?,
                     "fo", "tax_evidence", "87654321", "CZ7801011234", "451", "62020")'
        );
        $stmt->execute(['Jan Novák', $czId, $currencyId, $vatRateId]);
        $this->supplierId = (int) $pdo->lastInsertId();

        $snapshot = json_encode(['year' => self::YEAR, 'journal' => ['totals' => [], 'rows' => []]], JSON_THROW_ON_ERROR);
        $pdo->prepare(
            "INSERT INTO tax_evidence_closings
                (supplier_id, year, status, checklist, source_snapshot, source_hash, row_version, finalized_at, finalized_by)
             VALUES (?, ?, 'final', '{}', ?, ?, 1, NOW(), ?)"
        )->execute([$this->supplierId, self::YEAR, $snapshot, hash('sha256', $snapshot), $this->userId]);
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

    private function req(string $method, array $body = [], string $role = 'accountant'): array
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest($method, '/api/tax-return/fo/' . self::YEAR)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => $role]);
        if ($body !== []) {
            $req = $req->withParsedBody($body);
        }
        return [$req, new Psr7Response()];
    }

    private function json(\Psr\Http\Message\ResponseInterface $r): array
    {
        $r->getBody()->rewind();
        $decoded = json_decode((string) $r->getBody(), true);
        return is_array($decoded) ? $decoded : [];
    }

    public function testFullFoWorkflow(): void
    {
        $args = ['type' => 'fo', 'year' => (string) self::YEAR];

        // GET — draft, §7 z paušálu (0 příjmů → základ 0).
        [$req, $res] = $this->req('GET');
        $body = $this->json($this->action->get($req, $res, $args));
        self::assertSame('dpfdp7', $body['form_code']);
        self::assertSame('draft', $body['return']['status']);
        self::assertSame('pausal', $body['podklady']['expense_mode']);

        // PUT §6 příjmy 600 000.
        [$req, $res] = $this->req('PUT', [
            'row_version' => 1,
            'inputs' => ['s6_employment' => ['income' => 600000, 'withholding' => 0]],
        ]);
        $r = $this->action->putInputs($req, $res, $args);
        self::assertSame(200, $r->getStatusCode());
        $body = $this->json($r);
        // Daň 600 000 × 15 % = 90 000 − sleva 30 840 = 59 160.
        self::assertSame(59160.0, (float) $body['computed']['tax']);
        self::assertSame(59160.0, (float) $body['computed']['balance_due']);

        // finalize.
        [$req, $res] = $this->req('POST', ['row_version' => 2]);
        $r = $this->action->finalize($req, $res, $args);
        self::assertSame(200, $r->getStatusCode());
        self::assertSame('final', $this->json($r)['return']['status']);

        // XML — validace DPFDP7 + archivace.
        [$req, $res] = $this->req('GET');
        $r = $this->action->xml($req, $res, $args);
        self::assertSame(200, $r->getStatusCode());
        self::assertSame('passed', $r->getHeaderLine('X-Validation-Status'));
        $r->getBody()->rewind();
        $finalXml = (string) $r->getBody();
        self::assertStringContainsString('<DPFDP7', $finalXml);

        $this->db->pdo()->prepare('UPDATE supplier SET company_name = ? WHERE id = ?')
            ->execute(['Změněný živý profil', $this->supplierId]);
        [$req, $res] = $this->req('GET');
        $second = $this->action->xml($req, $res, $args);
        $second->getBody()->rewind();
        self::assertSame($finalXml, (string) $second->getBody(), 'Ostré XML musí být byte-for-byte ze snapshotu.');

        $snapshotRows = $this->db->pdo()->query(
            "SELECT s.id AS snapshot_id, s.tax_return_id, r.final_snapshot_id
               FROM income_tax_return_snapshots s
              JOIN income_tax_returns r ON r.id = s.tax_return_id AND r.final_snapshot_id = s.id
             WHERE r.supplier_id = {$this->supplierId} AND r.year = " . self::YEAR . "
               AND r.taxpayer_type = 'fo' AND r.variant = 'radne' AND r.variant_seq = 1"
        )->fetchAll(\PDO::FETCH_ASSOC);
        self::assertCount(1, $snapshotRows, json_encode($snapshotRows, JSON_THROW_ON_ERROR));

        $subCount = (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM tax_submissions WHERE supplier_id = {$this->supplierId} AND form_code = 'dpfdp7'"
        )->fetchColumn();
        self::assertSame(2, $subCount, 'Každý ze dvou exportů se archivuje jako samostatné stažení.');
    }

    public function testLaterEntityStatusDoesNotBlockEarlierFoFinalization(): void
    {
        $this->db->pdo()->prepare('UPDATE supplier SET tax_entity_status = ?, tax_entity_status_date = ? WHERE id = ?')
            ->execute(['insolvency', (self::YEAR + 1) . '-01-01', $this->supplierId]);
        $args = ['type' => 'fo', 'year' => (string) self::YEAR];
        [$req, $res] = $this->req('GET');
        $body = $this->json($this->action->get($req, $res, $args));
        [$req, $res] = $this->req('POST', ['row_version' => $body['return']['row_version']]);
        $response = $this->action->finalize($req, $res, $args);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('final', $this->json($response)['return']['status']);
    }

    public function testSectionEOverflowWarningSurvivesFinalization(): void
    {
        $stmt = $this->db->pdo()->prepare(
            "INSERT INTO tax_evidence_non_cash_adjustments
                 (supplier_id, closing_id, adjustment_on, kind, direction, amount, description)
             SELECT supplier_id, id, ?, 'section23_other', 'increase', 100.49, 'Syntetická úprava'
               FROM tax_evidence_closings WHERE supplier_id = ? AND year = ?"
        );
        for ($i = 0; $i < 100; ++$i) {
            $stmt->execute([self::YEAR . '-12-31', $this->supplierId, self::YEAR]);
        }
        $args = ['type' => 'fo', 'year' => (string) self::YEAR];
        [$req, $res] = $this->req('GET');
        $body = $this->json($this->action->get($req, $res, $args));
        [$req, $res] = $this->req('POST', ['row_version' => $body['return']['row_version']]);
        $response = $this->action->finalize($req, $res, $args);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $final = $this->json($response);
        self::assertNotEmpty(array_filter($final['warnings'], static fn (string $warning): bool => str_contains($warning, 'limit 99')));
        $service = Bootstrap::buildApp()->getContainer()->get(\MyInvoice\Service\Tax\Return\TaxReturnService::class);
        $export = $service->buildXml($this->supplierId, self::YEAR, 'fo');
        self::assertNotEmpty(array_filter($export['warnings'], static fn (string $warning): bool => str_contains($warning, 'limit 99')));
        self::assertSame(99, substr_count($export['xml'], '<VetaC'));
    }

    public function testInsuranceSummaryAndPdf(): void
    {
        $args = ['type' => 'fo', 'year' => (string) self::YEAR];

        // Přehledy pojistného — §7 zisk 0 → minimální vyměřovací základy z přesné
        // DB sady konstant pro rok 2048 připravené v setUp().
        [$req, $res] = $this->req('GET');
        $body = $this->json($this->action->insurance($req, $res, $args));
        self::assertArrayHasKey('social', $body);
        self::assertArrayHasKey('health', $body);
        self::assertTrue($body['social']['participates']);
        // Sociální min základ (hlavní) → pojistné > 0; zdravotní min → pojistné > 0.
        self::assertGreaterThan(0, $body['social']['insurance']);
        self::assertGreaterThan(0, $body['health']['insurance']);
        self::assertSame($body['social']['min_base'], $body['social']['assessment_base'], 'Nulový zisk → VZ = minimum.');

        // PDF Přehledů.
        [$req, $res] = $this->req('GET');
        $r = $this->action->insurancePdf($req, $res, $args);
        self::assertSame(200, $r->getStatusCode());
        self::assertStringContainsString('application/pdf', $r->getHeaderLine('Content-Type'));
        $r->getBody()->rewind();
        self::assertStringStartsWith('%PDF', (string) $r->getBody());
    }

    /**
     * REGRESE (Fáze E adversariální review, nález N2, HIGH): zadaná ztráta k uplatnění (§34)
     * PŘESAHUJÍCÍ evidovanou dostupnou ztrátu (zde 0 — žádná ztráta evidovaná) MUSÍ vygenerovat
     * výrazné varování. Fat-finger scénář: uživatel zadá 1 200 000 místo 120 000, evidence nemá
     * nic k dispozici → bez kontroly by se ztráta tiše uplatnila v kalkulátoru (kc_ztrata2)
     * beze stopy v evidenci.
     */
    public function testLossCarryforwardExceedingEvidenceWarns(): void
    {
        $args = ['type' => 'fo', 'year' => (string) self::YEAR];

        [$req, $res] = $this->req('PUT', [
            'row_version' => 0,
            'inputs' => [
                's6_employment' => ['income' => 0, 'withholding' => 0],
                'loss_carryforward' => 1200000,
            ],
        ]);
        $body = $this->json($this->action->putInputs($req, $res, $args));

        self::assertArrayHasKey('tax_losses', $body);
        self::assertSame(0.0, (float) $body['tax_losses']['available_total'], 'Žádná ztráta evidovaná.');
        $joined = implode("\n", $body['warnings']);
        self::assertStringContainsString('PŘESAHUJE', $joined);
        self::assertStringContainsString('1 200 000', $joined);
    }

    public function testInsuranceRejectedForPo(): void
    {
        // Přehledy pojistného jen pro FO — pro PO 400.
        $req = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/tax-return/po/' . self::YEAR . '/insurance')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant']);
        $r = $this->action->insurance($req, new Psr7Response(), ['type' => 'po', 'year' => (string) self::YEAR]);
        self::assertSame(400, $r->getStatusCode());
    }

    public function testInsuranceRejectsYearWithoutExactConstants(): void
    {
        $year = 2049;
        $this->db->pdo()->prepare('DELETE FROM tax_constants WHERE year = ?')->execute([$year]);
        $req = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/tax-return/fo/' . $year . '/insurance')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant']);

        $r = $this->action->insurance($req, new Psr7Response(), ['type' => 'fo', 'year' => (string) $year]);
        $body = $this->json($r);

        self::assertSame(422, $r->getStatusCode());
        self::assertSame('missing_tax_constants', $body['error']['code']);
    }
}
