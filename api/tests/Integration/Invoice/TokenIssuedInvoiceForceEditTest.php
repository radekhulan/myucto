<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Invoice;

use MyInvoice\Action\Invoice\UpdateInvoiceAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Auth\ApiTokenService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Vynucená úprava vystavené vydané faktury přes API token se schopností
 * `allow_issued_invoice_edit` (#355). Token smí méně než správce v relaci:
 * uzavřené ani podané období nepřebije, doklad nepřečísluje a fakturu s úhradou
 * nechá být. Každé odmítnutí musí přijít dřív, než se cokoli zapíše.
 *
 * Soft-skip bez cfg.php; vše v transakci s rollbackem, data jsou syntetická.
 */
#[Group('integration')]
final class TokenIssuedInvoiceForceEditTest extends TestCase
{
    private const CLOSED_YEAR = 2095;
    private const FILED_YEAR = 2096;

    private ContainerInterface $container;
    private Connection $db;
    private PostingService $posting;
    private JournalEntryRepository $journal;

    private int $supplierId = 0;
    private int $userId = 0;
    private int $vatRateId = 0;
    private int $czkId = 0;
    private int $clientId = 0;
    private string $date = '';
    private bool $inTx = false;
    /** @var array<string,mixed> */
    private array $token = [];

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje, test vyžaduje DB connection.');
        }
        try {
            $this->container = Bootstrap::buildContainer();
            $this->db      = $this->container->get(Connection::class);
            $this->posting = $this->container->get(PostingService::class);
            $this->journal = $this->container->get(JournalEntryRepository::class);
            $periods       = $this->container->get(AccountingPeriodRepository::class);
            $seeder        = $this->container->get(ChartOfAccountsSeeder::class);
            $tokens        = $this->container->get(ApiTokenService::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->vatRateId  = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $czId             = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->userId === 0 || $this->vatRateId === 0 || $czId === 0) {
            $this->markTestSkipped('Chybí základní data v DB.');
        }
        $this->czkId = (int) ($pdo->query(
            "SELECT id FROM currencies WHERE supplier_id = {$this->supplierId} AND code = 'CZK' LIMIT 1"
        )->fetchColumn() ?: 0);
        if ($this->czkId === 0) {
            $this->markTestSkipped('Dodavatel nemá měnu CZK.');
        }

        $year = (int) date('Y');
        $this->date = $year . '-03-10';

        $pdo->beginTransaction();
        $this->inTx = true;

        $seeder->seedForSupplier($this->supplierId);
        if ($periods->findByYear($this->supplierId, $year) === null) {
            $periods->create($this->supplierId, $year, $year . '-01-01', $year . '-12-31');
        }
        $pdo->prepare('UPDATE accounting_supplier_settings SET locked_until = NULL WHERE supplier_id = ?')
            ->execute([$this->supplierId]);
        $pdo->prepare("DELETE FROM accounting_periods WHERE supplier_id = ? AND fiscal_year IN (?, ?)")
            ->execute([$this->supplierId, self::CLOSED_YEAR, self::FILED_YEAR]);
        $pdo->prepare(
            "INSERT INTO accounting_periods (supplier_id, fiscal_year, starts_on, ends_on, status)
             VALUES (?, ?, ?, ?, 'closed')"
        )->execute([$this->supplierId, self::CLOSED_YEAR, self::CLOSED_YEAR . '-01-01', self::CLOSED_YEAR . '-12-31']);

        $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id,
                                  main_email, language, currency_default_id, is_vendor, is_customer, is_vat_payer)
             VALUES (?, "Odběratel API s.r.o. (PHPUnit)", "Testovaci 1", "Praha", "11000", ?,
                     "token-force@example.test", "cs", ?, 0, 1, 1)'
        )->execute([$this->supplierId, $czId, $this->czkId]);
        $this->clientId = (int) $pdo->lastInsertId();

        $created = $tokens->generateInTransaction(
            $pdo, $this->userId, $this->supplierId, 'Portál (PHPUnit)', 'read_write', null,
            seatGate: false, allowIssuedInvoiceEdit: true,
        );
        $this->token = [
            'id' => $created['id'], 'name' => 'Portál (PHPUnit)', 'prefix' => $created['prefix'],
            'scope' => 'read_write', 'supplier_id' => $this->supplierId, 'allow_issued_invoice_edit' => true,
        ];
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

    public function testTokenServiceStoresCapabilityOnlyForBoundWriteToken(): void
    {
        $pdo = $this->db->pdo();
        $row = $pdo->query('SELECT allow_issued_invoice_edit FROM api_tokens WHERE id = ' . (int) $this->token['id'])->fetchColumn();
        self::assertSame(1, (int) $row);

        $tokens = $this->container->get(ApiTokenService::class);
        foreach ([[null, 'read_write'], [$this->supplierId, 'read']] as [$supplier, $scope]) {
            try {
                $tokens->generateInTransaction($pdo, $this->userId, $supplier, 'Neplatný (PHPUnit)', $scope, null,
                    seatGate: false, allowIssuedInvoiceEdit: true);
                self::fail('Schopnost nesmí vzniknout na tokenu bez vazby nebo jen pro čtení.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testTokenWithoutCapabilityKeepsSessionOnlyGuard(): void
    {
        $id = $this->issuedInvoice($this->date);

        $res = $this->call($id, $this->noteBody(), ['allow_issued_invoice_edit' => false]);
        self::assertSame(403, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame('token_write_forbidden', $res['body']['error']['code'] ?? null);
        self::assertNull($this->note($id));
    }

    public function testTokenWithCapabilityEditsIssuedInvoiceAndAuditsToken(): void
    {
        $id = $this->issuedInvoice($this->date);
        $body = ['items' => [$this->item(2000.0)], 'note_below_items' => 'Oprava z portálu (PHPUnit)'];

        $res = $this->call($id, $body);
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame('Oprava z portálu (PHPUnit)', $this->note($id));
        $row = $this->invoiceRow($id);
        self::assertSame('issued', $row['status']);
        self::assertSame('invoice', $row['invoice_type']);
        self::assertSame($this->varsymbolOf($id), $row['varsymbol']);

        $log = $this->db->pdo()->prepare(
            "SELECT payload FROM activity_log WHERE action = 'invoice.force_updated' AND entity_id = ? ORDER BY id DESC LIMIT 1"
        );
        $log->execute([$id]);
        $payload = json_decode((string) $log->fetchColumn(), true);
        self::assertIsArray($payload);
        self::assertTrue($payload['via_api_token'] ?? false);
        self::assertSame($this->token['id'], $payload['api_token']['id'] ?? null);
        self::assertSame('Portál (PHPUnit)', $payload['api_token']['name'] ?? null);
        self::assertSame($this->token['prefix'], $payload['api_token']['prefix'] ?? null);
        self::assertArrayNotHasKey('token_hash', $payload['api_token']);
        self::assertStringNotContainsString('token_hash', json_encode($payload));
        self::assertNotEmpty($payload['changed'] ?? []);
    }

    public function testClosedPeriodIsRejectedEvenForAdminOwner(): void
    {
        $id = $this->issuedInvoice(self::CLOSED_YEAR . '-06-15');

        $res = $this->call($id, $this->noteBody());
        $this->assertRejected($res, 409, 'period_closed', $id);
        self::assertSame(0, $this->auditCount('document_lock.force_override', $id), 'Token nesmí po sobě nechat audit přebití.');

        $res = $this->call($id, $this->noteBody(), [], ['force' => '1', 'force_mode' => 'notes_only']);
        $this->assertRejected($res, 409, 'period_closed', $id);
    }

    public function testMovingDateIntoClosedPeriodIsRejected(): void
    {
        $id = $this->issuedInvoice($this->date);
        $date = self::CLOSED_YEAR . '-06-15';

        $res = $this->call($id, ['issue_date' => $date, 'tax_date' => $date, 'due_date' => $date] + $this->noteBody());
        $this->assertRejected($res, 409, 'period_closed', $id);
        self::assertSame($this->date, $this->invoiceRow($id)['issue_date']);
    }

    public function testFiledVatReturnAndControlStatementRejectFinancialChange(): void
    {
        foreach (['dphdp3' => 'B', 'dphkh1' => 'B'] as $form => $variant) {
            $this->db->pdo()->exec("DELETE FROM tax_submissions WHERE supplier_id = {$this->supplierId} AND period_year = " . self::FILED_YEAR);
            $this->filed($form, $variant, 5);

            $old = $this->issuedInvoice(self::FILED_YEAR . '-05-10');
            $res = $this->call($old, ['items' => [$this->item(3000.0)]]);
            $this->assertRejected($res, 409, 'period_filed', $old, $form . ' (staré datum)');

            $new = $this->issuedInvoice(self::FILED_YEAR . '-07-10');
            $date = self::FILED_YEAR . '-05-20';
            $res = $this->call($new, ['issue_date' => $date, 'tax_date' => $date, 'due_date' => $date]);
            $this->assertRejected($res, 409, 'period_filed', $new, $form . ' (nové datum)');
            self::assertSame(self::FILED_YEAR . '-07-10', $this->invoiceRow($new)['tax_date']);
        }

        $notes = $this->issuedInvoice(self::FILED_YEAR . '-05-10');
        $res = $this->call($notes, $this->noteBody());
        self::assertSame(200, $res['status'], 'Neúčetní změna v podaném období projde: ' . json_encode($res['body'], JSON_UNESCAPED_UNICODE));
    }

    public function testFiledSummaryReportBlocksOnlyEuSupplies(): void
    {
        $this->filed('dphshv', 'R', 5);
        $euClient = $this->euClient();

        $old = $this->issuedInvoice(self::FILED_YEAR . '-05-10', $euClient, '22');
        $res = $this->call($old, ['items' => [$this->item(3000.0) + ['vat_classification_code' => '22']]]);
        $this->assertRejected($res, 409, 'period_filed', $old, 'EU plnění, staré datum');
        self::assertStringContainsString('souhrnné hlášení', (string) ($res['body']['error']['message'] ?? ''));

        $new = $this->issuedInvoice(self::FILED_YEAR . '-07-10', $euClient, '22');
        $date = self::FILED_YEAR . '-05-20';
        $res = $this->call($new, ['issue_date' => $date, 'tax_date' => $date, 'due_date' => $date]);
        $this->assertRejected($res, 409, 'period_filed', $new, 'EU plnění, nové datum');

        $domestic = $this->issuedInvoice(self::FILED_YEAR . '-05-10');
        $res = $this->call($domestic, ['items' => [$this->item(3000.0)]] + $this->noteBody());
        self::assertSame(200, $res['status'], 'Tuzemskou fakturu podané SH neblokuje: ' . json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertEqualsWithDelta(3000.0, (float) $this->invoiceRow($domestic)['total_without_vat'], 0.001);
    }

    public function testCashRegisterChangeIsRejected(): void
    {
        $id = $this->issuedInvoice($this->date);

        $res = $this->call($id, ['payment_method' => 'cash', 'cash_register_id' => 999999] + $this->noteBody());
        $this->assertRejected($res, 422, 'token_force_cash_register_forbidden', $id);
        self::assertNull($this->invoiceRow($id)['cash_register_id'] ?? null);

        $res = $this->call($id, ['cash_register_id' => null] + $this->noteBody());
        self::assertSame(200, $res['status'], 'Nezměněná (prázdná) volba pokladny projde: ' . json_encode($res['body'], JSON_UNESCAPED_UNICODE));
    }

    public function testPostedInvoiceRepostsOnlyWithJournalPostRight(): void
    {
        $id = $this->issuedInvoice($this->date);
        $lines = $this->posting->buildFromInvoice($this->supplierId, $id);
        $entryId = $this->posting->postDocument($this->supplierId, 'invoice', $id, $lines, [
            'entry_date' => $this->date, 'posted_by' => $this->userId, 'user_id' => $this->userId,
        ]);
        $editor = new EffectiveRole(90, 'Úprava vystavených', 'staff', true, [
            'invoices' => AccessLevel::WRITE->value,
            'invoices.edit_issued' => AccessLevel::WRITE->value,
        ]);
        $financial = ['items' => [$this->item(2500.0)]];

        $res = $this->call($id, $financial, [], ['force' => '1'], $editor);
        $this->assertRejected($res, 403, 'journal_post_forbidden', $id);
        self::assertSame($entryId, (int) $this->journal->findBySource($this->supplierId, 'invoice', $id)['id']);

        $res = $this->call($id, $financial);
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        $live = $this->journal->findBySource($this->supplierId, 'invoice', $id);
        self::assertNotNull($live);
        self::assertEqualsWithDelta(2500.0, (float) $this->invoiceRow($id)['total_without_vat'], 0.001);
    }

    public function testInvoiceWithPaymentIsRejected(): void
    {
        $id = $this->issuedInvoice($this->date);
        $this->db->pdo()->prepare(
            'INSERT INTO invoice_payments (supplier_id, invoice_id, amount, currency, paid_on, source, created_by)
             VALUES (?, ?, 100, "CZK", ?, "manual", ?)'
        )->execute([$this->supplierId, $id, $this->date, $this->userId]);

        $res = $this->call($id, $this->noteBody());
        $this->assertRejected($res, 409, 'invoice_has_payments', $id);
        self::assertStringContainsString('dobropis', (string) ($res['body']['error']['message'] ?? ''));
    }

    public function testTypeOrNumberChangeIsRejectedWithoutBurningNumber(): void
    {
        $id = $this->issuedInvoice($this->date);
        $counters = $this->countersSnapshot();

        $res = $this->call($id, ['invoice_type' => 'proforma'] + $this->noteBody());
        $this->assertRejected($res, 422, 'token_force_renumber_forbidden', $id);

        $res = $this->call($id, ['varsymbol' => 'JINE' . random_int(1000, 9999)] + $this->noteBody());
        $this->assertRejected($res, 422, 'token_force_renumber_forbidden', $id);
        self::assertSame('invoice', $this->invoiceRow($id)['invoice_type']);
        self::assertSame($counters, $this->countersSnapshot(), 'Odmítnutí nesmí spálit číslo řady.');
    }

    public function testTokenOfAnotherSupplierGets404(): void
    {
        $id = $this->issuedInvoice($this->date);
        $other = $this->supplierId + 1_000_000;

        $res = $this->call($id, $this->noteBody(), ['supplier_id' => $other], ['force' => '1'], null, $other);
        self::assertSame(404, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertNull($this->note($id));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @param array{status:int, body:array<string,mixed>} $res */
    private function assertRejected(array $res, int $status, string $code, int $id, string $context = ''): void
    {
        $json = $context . ' ' . json_encode($res['body'], JSON_UNESCAPED_UNICODE);
        self::assertSame($status, $res['status'], $json);
        self::assertSame($code, $res['body']['error']['code'] ?? null, $json);
        self::assertNull($this->note($id), 'Odmítnutý token nesmí nic zapsat. ' . $context);
        self::assertSame(0, $this->auditCount('invoice.force_updated', $id), $context);
    }

    private function issuedInvoice(string $date, ?int $clientId = null, string $code = '1'): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO invoices (supplier_id, invoice_type, client_id, varsymbol, issue_date, tax_date, due_date,
                                   currency_id, language, reverse_charge, total_without_vat, total_vat, total_with_vat,
                                   status, vat_classification_code, created_by)
             VALUES (?, "invoice", ?, ?, ?, ?, DATE_ADD(?, INTERVAL 14 DAY), ?, "cs", ?, 1000, 0, 1000, "issued", ?, ?)'
        )->execute([
            $this->supplierId, $clientId ?? $this->clientId, 'TF' . random_int(10000000, 99999999),
            $date, $date, $date, $this->czkId, $code === '1' ? 0 : 1, $code, $this->userId,
        ]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO invoice_items
                (invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, 'Zboží (PHPUnit)', 1, 'ks', 1000, ?, 0, 1000, 0, 1000, 0)"
        )->execute([$id, $this->vatRateId]);

        return $id;
    }

    private function euClient(): int
    {
        $pdo = $this->db->pdo();
        $deId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'DE' LIMIT 1")->fetchColumn() ?: 0);
        if ($deId === 0) {
            $this->markTestSkipped('Chybí země DE.');
        }
        $pdo->prepare('UPDATE countries SET is_eu = 1 WHERE id = ?')->execute([$deId]);
        $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, dic,
                                  main_email, language, currency_default_id, is_vendor, is_customer, is_vat_payer)
             VALUES (?, "EU Kunde GmbH (PHPUnit)", "Teststr. 1", "Berlin", "10115", ?, "DE123456789",
                     "eu-kunde@example.test", "en", ?, 0, 1, 1)'
        )->execute([$this->supplierId, $deId, $this->czkId]);

        return (int) $pdo->lastInsertId();
    }

    /** @return array<string,mixed> */
    private function item(float $price): array
    {
        return [
            'description' => 'Zboží (PHPUnit)', 'quantity' => 1, 'unit' => 'ks',
            'unit_price_without_vat' => $price, 'vat_rate_id' => $this->vatRateId,
        ];
    }

    /** @return array<string,mixed> */
    private function noteBody(): array
    {
        return ['note_below_items' => 'Poznámka z portálu (PHPUnit)'];
    }

    private function filed(string $form, string $variant, int $month): void
    {
        $this->db->pdo()->prepare(
            "INSERT INTO tax_submissions
                (supplier_id, form_code, period_year, period_month, period_quarter, form_variant,
                 xml_content, xml_size_bytes, xml_sha256, validation_status, status, submitted_at,
                 summary_json, generated_by)
             VALUES (?, ?, ?, ?, NULL, ?, '<test/>', 7, ?, 'passed', 'submitted', ?, '{}', ?)"
        )->execute([
            $this->supplierId, $form, self::FILED_YEAR, $month, $variant, hash('sha256', $form),
            self::FILED_YEAR . '-06-20 09:00:00', $this->userId,
        ]);
    }

    private function note(int $id): ?string
    {
        $note = $this->db->pdo()->query("SELECT note_below_items FROM invoices WHERE id = $id")->fetchColumn();

        return ($note === false || $note === null || $note === '') ? null : (string) $note;
    }

    /** @return array<string,mixed> */
    private function invoiceRow(int $id): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM invoices WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$id, $this->supplierId]);

        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
    }

    private function varsymbolOf(int $id): string
    {
        return (string) $this->db->pdo()->query("SELECT varsymbol FROM invoices WHERE id = $id")->fetchColumn();
    }

    private function auditCount(string $action, int $id): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM activity_log WHERE action = ? AND entity_id = ?');
        $stmt->execute([$action, $id]);

        return (int) $stmt->fetchColumn();
    }

    private function countersSnapshot(): string
    {
        $rows = [];
        foreach ([
            'invoice_counters' => 'SELECT * FROM invoice_counters WHERE supplier_id = ? ORDER BY 1',
            'number_series_counters' => 'SELECT c.* FROM number_series_counters c
                JOIN number_series s ON s.id = c.series_id WHERE s.supplier_id = ? ORDER BY c.series_id, c.period_key',
        ] as $table => $sql) {
            $stmt = $this->db->pdo()->prepare($sql);
            $stmt->execute([$this->supplierId]);
            $rows[$table] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        }

        return (string) json_encode($rows);
    }

    /**
     * @param array<string,mixed>  $body
     * @param array<string,mixed>  $tokenOverride
     * @param array<string,string> $query
     * @return array{status:int, body:array<string,mixed>}
     */
    private function call(
        int $id,
        array $body,
        array $tokenOverride = [],
        array $query = ['force' => '1'],
        ?EffectiveRole $role = null,
        ?int $currentSupplier = null,
    ): array {
        $action = $this->container->get(UpdateInvoiceAction::class);
        $req = (new ServerRequestFactory())
            ->createServerRequest('PUT', '/api/v1/invoices/' . $id)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $currentSupplier ?? $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'bearer')
            ->withAttribute(AuthMiddleware::ATTR_API_TOKEN, $tokenOverride + $this->token)
            ->withQueryParams($query)
            ->withParsedBody($body);
        if ($role !== null) {
            $req = $req->withAttribute('auth.effective_role', $role);
        }
        $response = $action($req, new Psr7Response(), ['id' => (string) $id]);
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);

        return ['status' => $response->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }
}
