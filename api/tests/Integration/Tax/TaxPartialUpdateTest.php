<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Tax;

use MyInvoice\Action\Tax\Return\TaxReturnAction;
use MyInvoice\Action\Tax\TaxAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\TaxProfileRepository;
use MyInvoice\Repository\TaxReturnRepository;
use MyInvoice\Service\TaxEvidence\AnnualClosingService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Issue #113 — částečný update daňových endpointů: vynechaný klíč zachová uloženou
 * hodnotu, explicitní null/"" ji smaže. Izolovaný FO supplier, rollback v tearDown.
 */
#[Group('integration')]
final class TaxPartialUpdateTest extends TestCase
{
    private const YEAR = 2048;
    private const PROFILE_YEAR = 2025;

    private Connection $db;
    private TaxReturnAction $returnAction;
    private TaxAction $taxAction;
    private TaxProfileRepository $profiles;
    private TaxReturnRepository $returns;
    private AnnualClosingService $closing;
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
            $this->db = $container->get(Connection::class);
            $this->returnAction = $container->get(TaxReturnAction::class);
            $this->taxAction = $container->get(TaxAction::class);
            $this->profiles = $container->get(TaxProfileRepository::class);
            $this->returns = $container->get(TaxReturnRepository::class);
            $this->closing = $container->get(AnnualClosingService::class);
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
        $this->inTx = true;
        $constants = \MyInvoice\Service\Tax\TaxConstants::forYear(2026);
        $constants['year'] = self::YEAR;
        $pdo->prepare('INSERT INTO tax_constants (year, data) VALUES (?, ?) ON DUPLICATE KEY UPDATE data = VALUES(data)')
            ->execute([self::YEAR, json_encode($constants, JSON_UNESCAPED_UNICODE)]);

        $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id,
                                   taxpayer_type, accounting_mode, ic, dic, financial_office_code, cz_nace_code)
             VALUES (?, "Krátká 12/3", "Praha", "11000", ?, "partial@example.com", ?, ?,
                     "fo", "tax_evidence", "87654321", "CZ7801011234", "451", "62020")'
        )->execute(['Jan Částečný', $czId, $currencyId, $vatRateId]);
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

    /** @return array{0:\Psr\Http\Message\ServerRequestInterface,1:Psr7Response} */
    private function req(string $method, array $body = [], string $role = 'accountant'): array
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest($method, '/api/tax')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => $role])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withParsedBody($body);
        return [$req, new Psr7Response()];
    }

    private function json(ResponseInterface $r): array
    {
        $r->getBody()->rewind();
        $decoded = json_decode((string) $r->getBody(), true);
        return is_array($decoded) ? $decoded : [];
    }

    private function putInputs(array $inputs, int $rowVersion): ResponseInterface
    {
        [$req, $res] = $this->req('PUT', ['row_version' => $rowVersion, 'inputs' => $inputs]);
        return $this->returnAction->putInputs($req, $res, ['type' => 'fo', 'year' => (string) self::YEAR]);
    }

    public function testPutInputsKeepsOmittedKeysAndClearsExplicitNull(): void
    {
        [$req, $res] = $this->req('GET');
        $this->returnAction->get($req, $res, ['type' => 'fo', 'year' => (string) self::YEAR]);

        $r = $this->putInputs([
            's6_employment' => ['income' => 300000, 'withholding' => 12000],
            's9_rental' => ['income' => 100000, 'expenses' => 20000, 'expense_mode' => 'pausal'],
            's7_payroll_gross' => 5000,
            'loss_carryforward' => 7000,
            'manual_increase_items' => [['text' => 'Syntetická položka', 'amount' => 1500]],
            'notes' => 'první',
        ], 1);
        self::assertSame(200, $r->getStatusCode(), (string) $r->getBody());

        $r = $this->putInputs(['notes' => 'druhá'], 2);
        self::assertSame(200, $r->getStatusCode(), (string) $r->getBody());
        $stored = $this->returns->find($this->supplierId, self::YEAR, 'fo')['inputs'];
        self::assertSame('druhá', $stored['notes']);
        self::assertEqualsWithDelta(300000.0, (float) $stored['s6_employment']['income'], 0.001);
        self::assertEqualsWithDelta(12000.0, (float) $stored['s6_employment']['withholding'], 0.001);
        self::assertSame('pausal', $stored['s9_rental']['expense_mode'], 'Vynechaný s9_rental se nesmí vrátit na skutečné výdaje.');
        self::assertEqualsWithDelta(20000.0, (float) $stored['s9_rental']['expenses'], 0.001);
        self::assertEqualsWithDelta(5000.0, (float) $stored['s7_payroll_gross'], 0.001);
        self::assertEqualsWithDelta(7000.0, (float) $stored['loss_carryforward'], 0.001);
        self::assertCount(1, $stored['manual_increase_items']);

        // Vnořený objekt se slučuje po klíčích, explicitní null maže (= převzít ze mezd).
        $r = $this->putInputs(['s9_rental' => ['income' => 90000], 's7_payroll_gross' => null, 'manual_increase_items' => []], 3);
        self::assertSame(200, $r->getStatusCode(), (string) $r->getBody());
        $stored = $this->returns->find($this->supplierId, self::YEAR, 'fo')['inputs'];
        self::assertEqualsWithDelta(90000.0, (float) $stored['s9_rental']['income'], 0.001);
        self::assertEqualsWithDelta(20000.0, (float) $stored['s9_rental']['expenses'], 0.001);
        self::assertSame('pausal', $stored['s9_rental']['expense_mode']);
        self::assertNull($stored['s7_payroll_gross']);
        self::assertSame([], $stored['manual_increase_items']);
        self::assertSame('druhá', $stored['notes']);
    }

    public function testPutInputsLegacySection10AggregateReplacesSection(): void
    {
        $r = $this->putInputs(['s10_items' => [['text' => 'Syntetický příležitostný příjem', 'income' => 10000, 'expenses' => 0]]], 0);
        self::assertSame(200, $r->getStatusCode(), (string) $r->getBody());

        // Starší klient posílá jen souhrn § 10 — opakované PUT ho nesmí přičítat k položkám.
        $r = $this->putInputs(['s10_other' => ['income' => 5000, 'expenses' => 1000]], 1);
        self::assertSame(200, $r->getStatusCode(), (string) $r->getBody());
        $r = $this->putInputs(['s10_other' => ['income' => 5000, 'expenses' => 1000]], 2);
        self::assertSame(200, $r->getStatusCode(), (string) $r->getBody());

        $stored = $this->returns->find($this->supplierId, self::YEAR, 'fo')['inputs'];
        self::assertCount(1, $stored['s10_items'], json_encode($stored['s10_items']));
        self::assertEqualsWithDelta(5000.0, (float) $stored['s10_items'][0]['income'], 0.001);
        self::assertEqualsWithDelta(1000.0, (float) $stored['s10_items'][0]['expenses'], 0.001);

        $r = $this->putInputs(['notes' => 'jen poznámka'], 3);
        self::assertSame(200, $r->getStatusCode(), (string) $r->getBody());
        $stored = $this->returns->find($this->supplierId, self::YEAR, 'fo')['inputs'];
        self::assertCount(1, $stored['s10_items'], 'Vynechaný § 10 zůstává.');
    }

    public function testUpdateAdvanceOverrideKeepsOmittedFields(): void
    {
        $args = ['type' => 'fo', 'year' => (string) self::YEAR];
        [$req, $res] = $this->req('POST', [
            'effective_from' => self::YEAR . '-01-01',
            'effective_to' => self::YEAR . '-12-31',
            'amount' => 12000,
            'periodicity' => 'semiannual',
            'note' => 'Rozhodnutí FÚ',
            'source' => 'manual',
        ]);
        $created = $this->json($this->returnAction->createAdvanceOverride($req, $res, $args));
        $id = (int) ($created['override']['id'] ?? 0);
        self::assertGreaterThan(0, $id, json_encode($created));

        [$req, $res] = $this->req('PUT', ['note' => 'Upravená poznámka']);
        $r = $this->returnAction->updateAdvanceOverride($req, $res, $args + ['overrideId' => (string) $id]);
        self::assertSame(200, $r->getStatusCode(), (string) $r->getBody());
        $saved = $this->json($r)['override'];
        self::assertSame('Upravená poznámka', $saved['note']);
        self::assertSame(self::YEAR . '-01-01', $saved['effective_from']);
        self::assertSame(self::YEAR . '-12-31', $saved['effective_to']);
        self::assertEqualsWithDelta(12000.0, (float) $saved['amount'], 0.001);
        self::assertSame('semiannual', $saved['periodicity']);
        self::assertSame('manual', $saved['source']);

        [$req, $res] = $this->req('PUT', ['effective_to' => null, 'note' => null]);
        $saved = $this->json($this->returnAction->updateAdvanceOverride($req, $res, $args + ['overrideId' => (string) $id]))['override'];
        self::assertNull($saved['effective_to'], 'Explicitní null = otevřený konec.');
        self::assertNull($saved['note']);
        self::assertEqualsWithDelta(12000.0, (float) $saved['amount'], 0.001);
    }

    public function testUpdateAdvanceAmountRequiresAmount(): void
    {
        $args = ['type' => 'fo', 'year' => (string) self::YEAR, 'scheduleId' => '999999999'];
        [$req, $res] = $this->req('POST', []);
        $r = $this->returnAction->updateAdvanceAmount($req, $res, $args);
        self::assertSame(400, $r->getStatusCode(), (string) $r->getBody());
        self::assertSame('validation_failed', $this->json($r)['error']['code'] ?? null);
    }

    public function testTaxProfileKeepsOmittedScalarColumns(): void
    {
        $this->profiles->upsert($this->supplierId, self::PROFILE_YEAR, [
            'activity_rate' => 40,
            'flat_tax_band' => 'band1',
            'mortgage_months' => 6,
            'pension_contrib' => 24000,
            'sickness_insured' => true,
            'sickness_monthly_base' => 8000,
        ]);

        // Tvar požadavku z TaxOptimizer.vue — profil bez sickness_* a s jen částí polí.
        [$req, $res] = $this->req('PUT', ['year' => self::PROFILE_YEAR, 'donations' => 1500]);
        $r = $this->taxAction->updateProfile($req, $res);
        self::assertSame(200, $r->getStatusCode(), (string) $r->getBody());

        $p = $this->profiles->find($this->supplierId, self::PROFILE_YEAR);
        self::assertSame(40, $p['activity_rate']);
        self::assertSame('band1', $p['flat_tax_band']);
        self::assertSame(6, $p['mortgage_months']);
        self::assertEqualsWithDelta(24000.0, $p['pension_contrib'], 0.001);
        self::assertTrue($p['sickness_insured'], 'Uložení z optimalizátoru nesmí vypnout nemocenské pojištění.');
        self::assertSame(8000, $p['sickness_monthly_base']);
        self::assertEqualsWithDelta(1500.0, $p['donations'], 0.001);

        [$req, $res] = $this->req('PUT', ['year' => self::PROFILE_YEAR, 'sickness_monthly_base' => null]);
        $this->taxAction->updateProfile($req, $res);
        $p = $this->profiles->find($this->supplierId, self::PROFILE_YEAR);
        self::assertNull($p['sickness_monthly_base']);
        self::assertTrue($p['sickness_insured']);
    }

    public function testAnnualClosingSaveKeepsOmittedSections(): void
    {
        $row = $this->closing->get($this->supplierId, self::PROFILE_YEAR);
        $row = $this->closing->save($this->supplierId, self::PROFILE_YEAR, [
            'checklist' => ['cash_journal_reviewed' => true, 'non_cash_reviewed' => true],
            'opening_balances' => ['cash' => 100, 'bank' => 200],
            'closing_balances' => ['bank' => 50],
            'unsupported_cases' => ['Syntetický případ'],
        ], (int) $row['row_version'], $this->userId);

        $row = $this->closing->save($this->supplierId, self::PROFILE_YEAR, [], (int) $row['row_version'], $this->userId);
        self::assertTrue($row['checklist']['cash_journal_reviewed']);
        self::assertEqualsWithDelta(100.0, (float) $row['opening_balances']['cash'], 0.001);
        self::assertEqualsWithDelta(50.0, (float) $row['closing_balances']['bank'], 0.001);
        self::assertSame(['Syntetický případ'], $row['unsupported_cases']);

        $row = $this->closing->save($this->supplierId, self::PROFILE_YEAR, [
            'checklist' => ['non_cash_reviewed' => false],
            'opening_balances' => ['bank' => 7],
            'unsupported_cases' => null,
        ], (int) $row['row_version'], $this->userId);
        self::assertTrue($row['checklist']['cash_journal_reviewed'], 'Klíč checklistu mimo tělo zůstává.');
        self::assertFalse($row['checklist']['non_cash_reviewed']);
        self::assertEqualsWithDelta(100.0, (float) $row['opening_balances']['cash'], 0.001);
        self::assertEqualsWithDelta(7.0, (float) $row['opening_balances']['bank'], 0.001);
        self::assertSame([], $row['unsupported_cases'], 'Explicitní null maže.');
    }
}
