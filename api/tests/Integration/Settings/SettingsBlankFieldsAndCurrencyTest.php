<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Settings;

use MyInvoice\Action\Settings\SettingsAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Chyby z provozních logů: prázdná číselná pole firmy (1366), měna bez názvu (1048)
 * a smazání měny držené cizím klíčem (1451). Vše musí skončit čitelnou odpovědí,
 * ne výjimkou z DB. Session běží se striktním sql_mode jako v CI a na instancích.
 */
#[Group('integration')]
final class SettingsBlankFieldsAndCurrencyTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private SettingsAction $action;
    private int $supplierId;
    private int $userId;
    private int $currencyId;
    private int $otherCurrencyId;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            self::markTestSkipped('Test vyžaduje nakonfigurovanou testovací DB.');
        }
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->action = $container->get(SettingsAction::class);
        $pdo = $this->db->pdo();
        $pdo->exec("SET SESSION sql_mode = CONCAT(@@SESSION.sql_mode, ',STRICT_ALL_TABLES')");
        $sourceId = (int) $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        $this->userId = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        self::assertGreaterThan(0, $sourceId, 'Test potřebuje výchozí syntetickou firmu.');
        self::assertGreaterThan(0, $this->userId, 'Test potřebuje výchozího testovacího uživatele.');
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceId);

        $cur = $pdo->prepare(
            'INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active)
             VALUES (?, ?, ?, ?, ?, ?, 2, 1)'
        );
        $cur->execute([$this->supplierId, 'EUR', '__TEST EUR účet', '€', 'euro', 'euro']);
        $this->currencyId = (int) $pdo->lastInsertId();
        $cur->execute([$this->supplierId, 'CZK', '__TEST CZK účet', 'Kč', 'koruna', 'koruna']);
        $this->otherCurrencyId = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE supplier SET default_currency_id = ?, default_hourly_rate = 800 WHERE id = ?')
            ->execute([$this->otherCurrencyId, $this->supplierId]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
    }

    public function testBlankNumericAndDateFieldsDoNotBreakSupplierSave(): void
    {
        $before = $this->supplierRow();
        $response = $this->saveSupplier([
            'display_name' => '__TEST prázdná pole',
            'default_hourly_rate' => '',
            'country_id' => '',
            'default_currency_id' => '',
            'default_vat_rate_id' => '',
            'default_payment_due_days' => ' ',
            'reminder_days_after_due' => '',
            'fiscal_year_start_month' => '',
            'oss_valid_from' => '',
            'oss_valid_to' => '',
            'tax_entity_status_date' => '',
        ]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $after = $this->supplierRow();
        self::assertSame('__TEST prázdná pole', $after['display_name']);
        self::assertSame(0.0, (float) $after['default_hourly_rate'], 'Vymazaná sazba znamená „bez sazby".');
        foreach (['country_id', 'default_currency_id', 'default_vat_rate_id', 'default_payment_due_days',
                     'reminder_days_after_due', 'fiscal_year_start_month'] as $keep) {
            self::assertSame($before[$keep], $after[$keep], "Prázdné '$keep' musí nechat uloženou hodnotu.");
        }
        self::assertNull($after['oss_valid_from']);
        self::assertNull($after['tax_entity_status_date']);
    }

    public function testNullForNotNullIntegerFieldKeepsCurrentValue(): void
    {
        $before = $this->supplierRow();
        $response = $this->saveSupplier(['default_payment_due_days' => null, 'country_id' => null]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame($before['default_payment_due_days'], $this->supplierRow()['default_payment_due_days']);
        self::assertSame($before['country_id'], $this->supplierRow()['country_id']);
    }

    public function testNonNumericIntegerFieldIsValidationError(): void
    {
        $response = $this->saveSupplier(['default_payment_due_days' => 'čtrnáct']);
        self::assertSame(400, $response->getStatusCode(), (string) $response->getBody());
        self::assertStringContainsString('validation_failed', (string) $response->getBody());
    }

    /** Nulový odkaz na číselník by prošel jako „nezáporné číslo" a spadl na cizím klíči. */
    public function testZeroReferenceIdIsValidationError(): void
    {
        $response = $this->saveSupplier(['country_id' => 0]);
        self::assertSame(400, $response->getStatusCode(), (string) $response->getBody());
        self::assertStringContainsString('validation_failed', (string) $response->getBody());
    }

    public static function missingLabels(): iterable
    {
        yield 'null' => [null];
        yield 'prázdný řetězec' => [''];
        yield 'mezery' => ['   '];
        yield 'pole' => [['x']];
    }

    #[DataProvider('missingLabels')]
    public function testCurrencyUpdateWithoutLabelIsValidationErrorAndKeepsLabel(mixed $label): void
    {
        $request = $this->request('PUT', '/api/settings/currencies/' . $this->currencyId, ['label' => $label, 'symbol' => '€']);
        $response = $this->action->updateCurrency($request, new Response(), ['id' => (string) $this->currencyId]);

        self::assertSame(400, $response->getStatusCode(), (string) $response->getBody());
        $json = $this->json($response);
        self::assertSame('validation_failed', $json['error']['code'] ?? null);
        self::assertArrayHasKey('label', $json['error']['fields'] ?? []);
        self::assertSame('__TEST EUR účet', $this->currencyLabel());
    }

    public static function foreignBankCodes(): iterable
    {
        yield 'sort code s pomlčkami' => ['60-84-64'];
        yield 'sort code bez pomlček' => ['608464'];
        yield 'routing number' => ['021000021'];
    }

    /**
     * Issue #314: britský sort code v poli Kód banky končil chybou DB (CHAR(4)) a 500.
     * Zahraniční národní kód se uloží tak, jak ho uživatel zadal, při úpravě i založení.
     */
    #[DataProvider('foreignBankCodes')]
    public function testForeignBankCodeIsSavedOnUpdateAndCreate(string $bankCode): void
    {
        $update = $this->action->updateCurrency($this->request('PUT', '/api/settings/currencies/' . $this->currencyId, [
            'label' => '__TEST GBP účet', 'account_number' => ' 12345678 ', 'bank_code' => $bankCode,
            'iban' => 'GB33BUKB20201555555555', 'bic' => 'BUKBGB22',
        ]), new Response(), ['id' => (string) $this->currencyId]);
        self::assertSame(200, $update->getStatusCode(), (string) $update->getBody());
        $saved = $this->json($update)['data'] ?? $this->json($update);
        self::assertSame('12345678', $saved['account_number']);
        self::assertSame($bankCode, $saved['bank_code']);

        $create = $this->action->createCurrency($this->request('POST', '/api/settings/currencies', [
            'code' => 'GBP', 'label' => '__TEST GBP nový', 'account_number' => '87654321', 'bank_code' => $bankCode,
            'iban' => 'GB82WEST12345698765432', 'bic' => 'WESTGB2L',
        ]), new Response());
        self::assertSame(201, $create->getStatusCode(), (string) $create->getBody());
    }

    public function testInvalidOrOverlongBankFieldIsValidationErrorAndKeepsAccount(): void
    {
        foreach ([['bank_code' => '60/84/64'], ['bank_code' => '12345678901'], ['bic' => 'BUKBGB22XXXX']] as $body) {
            $response = $this->action->updateCurrency($this->request('PUT', '/api/settings/currencies/' . $this->currencyId,
                ['label' => '__TEST změna'] + $body), new Response(), ['id' => (string) $this->currencyId]);
            self::assertSame(400, $response->getStatusCode(), (string) $response->getBody());
            self::assertArrayHasKey(array_key_first($body), $this->json($response)['error']['fields'] ?? []);
            self::assertSame('__TEST EUR účet', $this->currencyLabel(), 'Neplatný požadavek nesmí změnit účet.');
        }
    }

    public function testDeleteCurrencyUsedAsClientDefaultIsConflict(): void
    {
        $this->insertClient($this->currencyId);
        $response = $this->deleteCurrency();
        self::assertSame(409, $response->getStatusCode(), (string) $response->getBody());
        self::assertStringContainsString('klientů', $this->json($response)['error']['message'] ?? '');
        self::assertTrue($this->currencyExists());
    }

    public function testDeleteCurrencyUsedAsSupplierDefaultIsConflictBeforeDelete(): void
    {
        $this->db->pdo()->prepare('UPDATE supplier SET default_currency_id = ? WHERE id = ?')
            ->execute([$this->currencyId, $this->supplierId]);
        $response = $this->deleteCurrency();
        self::assertSame(409, $response->getStatusCode(), (string) $response->getBody());
        self::assertStringContainsString('výchozí měnou firmy', $this->json($response)['error']['message'] ?? '');
        self::assertTrue($this->currencyExists());
    }

    public function testDeleteUnusedCurrencyDropsStaleRevenueCache(): void
    {
        $clientId = $this->insertClient($this->otherCurrencyId);
        $this->db->pdo()->prepare('INSERT INTO client_revenue_cache (client_id, currency_id, revenue) VALUES (?, ?, 0)')
            ->execute([$clientId, $this->currencyId]);

        $response = $this->deleteCurrency();
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertFalse($this->currencyExists());
    }

    public function testDeleteBlockersCoverEveryRestrictForeignKeyToCurrencies(): void
    {
        $rows = $this->db->pdo()->query(
            "SELECT k.TABLE_NAME, k.COLUMN_NAME
               FROM information_schema.KEY_COLUMN_USAGE k
               JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
                 ON rc.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
                AND rc.CONSTRAINT_NAME = k.CONSTRAINT_NAME
                AND rc.TABLE_NAME = k.TABLE_NAME
              WHERE k.TABLE_SCHEMA = DATABASE()
                AND k.REFERENCED_TABLE_NAME = 'currencies'
                AND k.REFERENCED_COLUMN_NAME = 'id'
                AND rc.DELETE_RULE IN ('RESTRICT', 'NO ACTION')"
        )->fetchAll(\PDO::FETCH_ASSOC);
        self::assertNotSame([], $rows);

        $covered = [];
        foreach (SettingsAction::CURRENCY_DELETE_BLOCKERS as $tables) {
            foreach ($tables as $table => $columns) {
                foreach ($columns as $column) {
                    $covered["$table.$column"] = true;
                }
            }
        }
        foreach (SettingsAction::CURRENCY_DELETE_CACHES as $table => $column) {
            $covered["$table.$column"] = true;
        }
        foreach ($rows as $row) {
            $key = $row['TABLE_NAME'] . '.' . $row['COLUMN_NAME'];
            self::assertArrayHasKey($key, $covered, "FK $key na currencies chybí v kontrole před smazáním měny.");
        }
    }

    private function saveSupplier(array $body): ResponseInterface
    {
        return $this->action->updateSupplierById(
            $this->request('PUT', '/api/suppliers/' . $this->supplierId, $body),
            new Response(),
            ['id' => (string) $this->supplierId],
        );
    }

    private function deleteCurrency(): ResponseInterface
    {
        $request = $this->request('DELETE', '/api/settings/currencies/' . $this->currencyId, []);
        return $this->action->deleteCurrency($request, new Response(), ['id' => (string) $this->currencyId]);
    }

    private function request(string $method, string $uri, array $body): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest($method, $uri)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withParsedBody($body);
    }

    private function insertClient(int $currencyId): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id,
                                  main_email, language, currency_default_id, is_customer)
             SELECT id, '__TEST klient měny', 'Testovací 1', 'Praha', '11000', country_id,
                    'klient@example.com', 'cs', ?, 1
               FROM supplier WHERE id = ?"
        )->execute([$currencyId, $this->supplierId]);
        return (int) $pdo->lastInsertId();
    }

    /** @return array<string, mixed> */
    private function supplierRow(): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT display_name, default_hourly_rate, country_id, default_currency_id, default_vat_rate_id,
                    default_payment_due_days, reminder_days_after_due, fiscal_year_start_month,
                    oss_valid_from, tax_entity_status_date
               FROM supplier WHERE id = ?'
        );
        $stmt->execute([$this->supplierId]);
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    private function currencyLabel(): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT label FROM currencies WHERE id = ?');
        $stmt->execute([$this->currencyId]);
        return (string) $stmt->fetchColumn();
    }

    private function currencyExists(): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM currencies WHERE id = ?');
        $stmt->execute([$this->currencyId]);
        return (int) $stmt->fetchColumn() === 1;
    }

    /** @return array<string, mixed> */
    private function json(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }
}
