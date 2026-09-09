<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Tax;

use MyInvoice\Action\Tax\Return\TaxReturnAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Epic DP (issue #18) — E2E API DPPO přiznání: seed dat (období + deník) → GET
 * (podklady + řádky) → PUT vstupy (draft, row_version) → finalize → GET XML
 * (validované proti EPO2 XSD + archivace v tax_submissions + link last_submission_id).
 *
 * Izolovaný supplier (po), transakce s rollbackem v tearDown, soft-skip bez cfg.php.
 */
#[Group('integration')]
final class TaxReturnApiTest extends TestCase
{
    private const YEAR = 2049;

    private Connection $db;
    private TaxReturnAction $action;
    private \MyInvoice\Service\Tax\Return\TaxReturnService $returns;
    private AccountingPeriodRepository $periods;
    private int $supplierId = 0;
    private int $userId = 0;
    private int $periodId = 0;
    private bool $inTx = false;

    /** @var array<string,int> account_code => id */
    private array $accounts = [];

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
            $this->returns = $container->get(\MyInvoice\Service\Tax\Return\TaxReturnService::class);
            $this->periods = $container->get(AccountingPeriodRepository::class);
            $seeder = $container->get(ChartOfAccountsSeeder::class);
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
                                   taxpayer_type, ic, dic, financial_office_code, cz_nace_code, opr_jmeno, opr_prijmeni, opr_postaveni)
             VALUES (?, "Zkušební 123/4", "Vzorov", "10000", ?, "dp-api@example.com", ?, ?,
                     "po", "12345678", "CZ12345678", "451", "62020", "Jan", "Novák", "jednatel")'
        );
        $stmt->execute(['DP API test s.r.o.', $czId, $currencyId, $vatRateId]);
        $this->supplierId = (int) $pdo->lastInsertId();

        $seeder->seedForSupplier($this->supplierId);
        $this->periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
        $period = $this->periods->findByYear($this->supplierId, self::YEAR);
        $this->periodId = (int) $period['id'];

        foreach ($pdo->query("SELECT account_code, id FROM chart_of_accounts WHERE supplier_id = {$this->supplierId}")->fetchAll(\PDO::FETCH_KEY_PAIR) as $code => $id) {
            $this->accounts[(string) $code] = (int) $id;
        }

        // Deník: výnos 1 000 000 (602), náklad 300 000 (518), reprezentace 50 000 (513, nedaňové).
        $this->postEntry(self::YEAR . '-03-15', [['311', 'debit', 1000000], ['602', 'credit', 1000000]]);
        $this->postEntry(self::YEAR . '-04-10', [['518', 'debit', 300000], ['321', 'credit', 300000]]);
        $this->postEntry(self::YEAR . '-05-20', [['513', 'debit', 50000], ['321', 'credit', 50000]]);

        // Uzávěrkové zápisy (source_type='closing') uvnitř období — NESMÍ vynulovat VH ani ř.40
        // (regrese C1: převod výsledkových účtů na 710 při roční uzávěrce).
        $this->postEntry(self::YEAR . '-12-31', [['602', 'debit', 1000000], ['710', 'credit', 1000000]], 'closing');
        $this->postEntry(self::YEAR . '-12-31', [['710', 'debit', 350000], ['518', 'credit', 300000], ['513', 'credit', 50000]], 'closing');
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

    /** @param list<array{0:string,1:string,2:float}> $lines */
    private function postEntry(string $date, array $lines, string $sourceType = 'manual'): void
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO journal_entries (supplier_id, period_id, entry_date, posted_at, source_type)
             VALUES (?, ?, ?, NOW(), ?)'
        );
        $stmt->execute([$this->supplierId, $this->periodId, $date, $sourceType]);
        $entryId = (int) $pdo->lastInsertId();
        $ins = $pdo->prepare(
            'INSERT INTO journal_entry_lines (entry_id, supplier_id, account_id, side, amount) VALUES (?, ?, ?, ?, ?)'
        );
        foreach ($lines as [$code, $side, $amount]) {
            $ins->execute([$entryId, $this->supplierId, $this->accounts[$code], $side, $amount]);
        }
    }

    private function req(string $method, string $path, array $body = [], string $role = 'accountant'): array
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest($method, $path)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => $role]);
        if ($body !== []) {
            $req = $req->withParsedBody($body);
        }
        return [$req, new Psr7Response()];
    }

    public function testFullWorkflow(): void
    {
        $args = ['type' => 'po', 'year' => (string) self::YEAR];

        // 1) GET — založí draft, spočte podklady z deníku.
        [$req, $res] = $this->req('GET', '/api/tax-return/po/' . self::YEAR);
        $r = $this->action->get($req, $res, $args);
        self::assertSame(200, $r->getStatusCode());
        $body = $this->json($r);
        self::assertSame('draft', $body['return']['status']);
        self::assertSame(1, $body['return']['row_version']);
        self::assertSame('dppdp9', $body['form_code']);
        self::assertEqualsWithDelta(650000.0, $body['podklady']['vh'], 0.01, 'VH = 1 000 000 − 300 000 − 50 000 (513 je náklad).');
        self::assertEqualsWithDelta(50000.0, $body['podklady']['non_deductible_costs'], 0.01);
        self::assertSame(700000.0, $this->line($body['computed']['lines'], 200)); // VH 650k + ř.40 50k
        self::assertSame(147000.0, (float) $body['computed']['tax']); // 700000 × 0.21

        // 2) PUT vstupy (draft, CAS row_version=1).
        [$req, $res] = $this->req('PUT', '/api/tax-return/po/' . self::YEAR . '/inputs', [
            'row_version' => 1,
            'inputs' => [
                'donations' => 40000,
                'tax_paid_advances' => 10000,
                'filing_deadline' => (self::YEAR + 1) . '-07-01',
            ],
        ]);
        $r = $this->action->putInputs($req, $res, $args);
        self::assertSame(200, $r->getStatusCode());
        $body = $this->json($r);
        self::assertSame(2, $body['return']['row_version']);
        self::assertSame((self::YEAR + 1) . '-07-01', $body['return']['inputs']['filing_deadline']);
        self::assertSame((self::YEAR + 1) . '-07-01', $body['computed']['next_advances']['filing_deadline']);
        // Dary 40 000 ≤ cap 30 % z 700 000 = 210 000 → odečteny plně; base 700 000 − 40 000 = 660 000.
        self::assertSame(660000.0, $this->line($body['computed']['lines'], 270));
        self::assertSame(138600.0, (float) $body['computed']['tax']); // 660000 × 0.21

        // 3) Stale row_version → 409 konflikt.
        [$req, $res] = $this->req('PUT', '/api/tax-return/po/' . self::YEAR . '/inputs', [
            'row_version' => 1, 'inputs' => ['donations' => 0],
        ]);
        $r = $this->action->putInputs($req, $res, $args);
        self::assertSame(409, $r->getStatusCode());

        // 4) finalize (row_version=2).
        [$req, $res] = $this->req('POST', '/api/tax-return/po/' . self::YEAR . '/finalize', ['row_version' => 2]);
        $r = $this->action->finalize($req, $res, $args);
        self::assertSame(200, $r->getStatusCode());
        self::assertSame('final', $this->json($r)['return']['status']);
        $advanceDates = $this->db->pdo()->query(
            'SELECT due_date FROM tax_advance_schedules WHERE supplier_id = ' . $this->supplierId
            . " AND taxpayer_type = 'po' ORDER BY seq_no"
        )->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame([(self::YEAR + 1) . '-12-15', (self::YEAR + 2) . '-06-15'], $advanceDates);

        // 5) GET XML — validace proti EPO2 XSD + archivace.
        [$req, $res] = $this->req('GET', '/api/tax-return/po/' . self::YEAR . '/xml');
        $r = $this->action->xml($req, $res, $args);
        self::assertSame(200, $r->getStatusCode());
        self::assertSame('passed', $r->getHeaderLine('X-Validation-Status'), 'XML musí projít EPO2 XSD.');
        $r->getBody()->rewind();
        $xml = (string) $r->getBody();
        self::assertStringContainsString('<DPPDP9', $xml);
        self::assertStringContainsString('rod_c="12345678"', $xml);

        // Archivace + navázání last_submission_id.
        $pdo = $this->db->pdo();
        $subCount = (int) $pdo->query("SELECT COUNT(*) FROM tax_submissions WHERE supplier_id = {$this->supplierId} AND form_code = 'dppdp9'")->fetchColumn();
        self::assertSame(1, $subCount);
        $lastSub = $pdo->query("SELECT last_submission_id FROM income_tax_returns WHERE supplier_id = {$this->supplierId} AND taxpayer_type = 'po'")->fetchColumn();
        self::assertNotNull($lastSub);
    }

    /**
     * P-1 — blokující nepodporovaný případ nesmí projít až k ostrému XML. Před touhle
     * bránou se investičnímu fondu vygenerovalo a zarchivovalo přiznání s natvrdo
     * zapsaným typem poplatníka „1" (ostatní), tedy podání tvrdící o poplatníkovi
     * nepravdu — a nikdo se to nedozvěděl.
     */
    public function testBlockingUnsupportedCaseStopsXmlIssue(): void
    {
        $pdo = $this->db->pdo();
        if ($pdo->query("SHOW COLUMNS FROM supplier LIKE 'epo_taxpayer_code'")->fetch() === false) {
            self::markTestSkipped('Migrace 1785 (příznaky nepodporovaných případů) neproběhla.');
        }
        $args = ['type' => 'po', 'year' => (string) self::YEAR];

        // Bez příznaku se XML vydá (kontrolní vzorek — brána nesmí blokovat běžnou firmu).
        [$req, $res] = $this->req('GET', '/api/tax-return/po/' . self::YEAR . '/xml');
        self::assertSame(200, $this->action->xml($req, $res, $args)->getStatusCode());

        $pdo->prepare("UPDATE supplier SET epo_taxpayer_code = '4' WHERE id = ?")->execute([$this->supplierId]);

        [$req, $res] = $this->req('GET', '/api/tax-return/po/' . self::YEAR . '/xml');
        $r = $this->action->xml($req, $res, $args);
        self::assertSame(422, $r->getStatusCode());
        self::assertSame('unsupported_case_blocked', $this->json($r)['error']['code']);

        // Typ poplatníka z nastavení firmy se skutečně dostane do XML místo natvrdo
        // zapsané „1" — podklad (náhled/uzávěrkový balíček) se staví i s nálezem,
        // blokované je až vydání ostrého XML výše.
        $pdo->prepare("UPDATE supplier SET epo_taxpayer_code = '3' WHERE id = ?")->execute([$this->supplierId]);
        $built = $this->returns->buildXml($this->supplierId, self::YEAR, 'po');
        self::assertStringContainsString('typ_popldpp="3"', $built['xml']);
        self::assertContains(
            'taxpayer_type_unsupported',
            array_column($built['unsupported_cases'], 'key'),
        );

        // Finalizace stojí na téže bráně.
        [$req, $res] = $this->req('POST', '/api/tax-return/po/' . self::YEAR . '/finalize', ['row_version' => 1]);
        $r = $this->action->finalize($req, $res, $args);
        self::assertSame(422, $r->getStatusCode());
        self::assertSame('prefinalize_blocked', $this->json($r)['error']['code']);
    }

    public function testReadonlyCannotWrite(): void
    {
        $args = ['type' => 'po', 'year' => (string) self::YEAR];
        [$req, $res] = $this->req('PUT', '/api/tax-return/po/' . self::YEAR . '/inputs', ['row_version' => 0, 'inputs' => []], 'readonly');
        $r = $this->action->putInputs($req, $res, $args);
        self::assertSame(403, $r->getStatusCode());
    }

    public function testHistoricalDppoWithoutExactConstantsIsRejected(): void
    {
        $args = ['type' => 'po', 'year' => '2023'];
        [$req, $res] = $this->req('GET', '/api/tax-return/po/2023');
        $r = $this->action->get($req, $res, $args);
        self::assertSame(422, $r->getStatusCode());
        self::assertSame('missing_tax_constants', $this->json($r)['error']['code']);
    }

    private function json(\Psr\Http\Message\ResponseInterface $r): array
    {
        $r->getBody()->rewind();
        $decoded = json_decode((string) $r->getBody(), true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param list<array<string,mixed>> $lines */
    private function line(array $lines, int $n): float
    {
        foreach ($lines as $l) {
            if ((int) $l['line'] === $n) {
                return (float) $l['value'];
            }
        }
        throw new \RuntimeException("Řádek $n nenalezen");
    }
}
