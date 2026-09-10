<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Logbook;

use MyInvoice\Action\Logbook\CarsAction;
use MyInvoice\Action\Logbook\FuelingsAction;
use MyInvoice\Http\TenantReferenceGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Vazby tankování na pokladní doklad / bankovní pohyb a řidič vozidla nesmí ukázat do
 * cizí firmy — ani přes Action (400 invalid_reference), ani přímým zápisem (trigger 1802/1803).
 * Ke každému útoku je pozitivní kontrola s vlastním záznamem.
 */
#[Group('integration')]
final class FuelingLinkTenantTest extends TestCase
{
    use LogbookFixtures;

    private int $carA = 0;

    protected function setUp(): void
    {
        $this->bootLogbook();
        $this->carA = $this->car($this->supplierA, '1AB 2345');
    }

    protected function tearDown(): void
    {
        $this->shutdownLogbook();
    }

    public function testCashDocumentLinkIsBoundToSupplier(): void
    {
        $own = $this->cashDocument($this->supplierA, 'Nafta', 1000.00);
        $foreign = $this->cashDocument($this->supplierB, 'Nafta', 1000.00);
        $action = $this->container->get(FuelingsAction::class);
        $body = ['fueled_date' => '2099-03-05', 'amount_with_vat' => 1000, 'car_id' => $this->carA];

        $bad = $this->call($action, 'create', 'POST', $body + ['source_cash_document_id' => $foreign]);
        self::assertSame(400, $bad['status']);
        self::assertSame('invalid_reference', $bad['body']['error']['code'] ?? null);

        $ok = $this->call($action, 'create', 'POST', $body + ['source_cash_document_id' => $own]);
        self::assertSame(201, $ok['status']);
        self::assertSame($own, $ok['body']['source_cash_document_id']);
        self::assertNotNull($ok['body']['source_cash_document_number']);
    }

    public function testBankTransactionLinkIsBoundToSupplier(): void
    {
        $own = $this->bankTransaction($this->supplierA, -1000.00);
        $foreign = $this->bankTransaction($this->supplierB, -1000.00);
        $action = $this->container->get(FuelingsAction::class);
        $body = ['fueled_date' => '2099-03-05', 'amount_with_vat' => 1000, 'car_id' => $this->carA];

        $bad = $this->call($action, 'create', 'POST', $body + ['source_bank_transaction_id' => $foreign]);
        self::assertSame(400, $bad['status']);
        self::assertStringContainsString('source_bank_transaction_id', (string) ($bad['body']['error']['message'] ?? ''));

        $ok = $this->call($action, 'create', 'POST', $body + ['source_bank_transaction_id' => $own]);
        self::assertSame(201, $ok['status']);
        self::assertSame($own, $ok['body']['source_bank_transaction_id']);
        self::assertNotNull($ok['body']['source_bank_statement_id']);
    }

    /**
     * Pohyb z legacy výpisu (bez supplier_id), jehož účet jednoznačně patří firmě,
     * vidí přehled plateb kartou (resolver). Vazba k tankování ho dřív odmítla
     * s 400, protože kniha jízd brala vlastníka jen z bs.supplier_id.
     */
    public function testBankTransactionFromLegacyStatementCanBeLinked(): void
    {
        $account = $this->unusedAccountNumber();
        $this->pdo->prepare(
            "INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default, account_number)
             VALUES (?, 'CZK', 'Legacy účet', 'Kč', 'Koruna', 'Koruna', 2, 1, 0, ?)"
        )->execute([$this->supplierA, $account]);
        $this->pdo->prepare(
            "INSERT INTO bank_statements (supplier_id, file_name, file_hash, account_number, currency, statement_date)
             VALUES (NULL, 'legacy.gpc', ?, ?, 'CZK', '2099-03-05')"
        )->execute([hash('sha256', uniqid('legacy', true)), $account]);
        $statement = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            "INSERT INTO bank_transactions (statement_id, posted_at, amount, currency, description)
             VALUES (?, '2099-03-05', -800, 'CZK', 'Platba kartou')"
        )->execute([$statement]);
        $tx = (int) $this->pdo->lastInsertId();
        $action = $this->container->get(FuelingsAction::class);
        $body = ['fueled_date' => '2099-03-05', 'amount_with_vat' => 800, 'car_id' => $this->carA];

        $ok = $this->call($action, 'create', 'POST', $body + ['source_bank_transaction_id' => $tx]);

        self::assertSame(201, $ok['status'], json_encode($ok['body']) ?: '');
        self::assertSame($tx, $ok['body']['source_bank_transaction_id']);
        self::assertSame($this->supplierA, (int) $this->pdo->query("SELECT supplier_id FROM bank_statements WHERE id = {$statement}")->fetchColumn());

        // Cizí firma ten pohyb dál nenaváže.
        $this->expectException(\PDOException::class);
        $this->pdo->prepare('INSERT INTO fuelings (supplier_id, fueled_date, amount_with_vat, source_bank_transaction_id) VALUES (?, ?, 1, ?)')
            ->execute([$this->supplierB, '2099-03-05', $tx]);
    }

    /** Syntetické číslo účtu, které projde mod-11 a v DB ho nemá žádná firma. */
    private function unusedAccountNumber(): string
    {
        $weights = [6, 3, 7, 9, 10, 5, 8, 4, 2, 1];
        $exists = $this->pdo->prepare(
            "SELECT 1 FROM currencies WHERE TRIM(LEADING '0' FROM REGEXP_REPLACE(IFNULL(account_number, ''), '[^0-9]', '')) = ?
             UNION SELECT 1 FROM bank_statements WHERE TRIM(LEADING '0' FROM REGEXP_REPLACE(IFNULL(account_number, ''), '[^0-9]', '')) = ?
             LIMIT 1"
        );
        for ($n = 1000000013; $n < 1000100000; $n++) {
            $digits = str_split((string) $n);
            $sum = 0;
            foreach ($digits as $i => $d) {
                $sum += (int) $d * $weights[$i];
            }
            if ($sum % 11 !== 0) {
                continue;
            }
            $exists->execute([(string) $n, (string) $n]);
            if ($exists->fetchColumn() === false) {
                return (string) $n;
            }
        }
        self::fail('Nenašlo se volné testovací číslo účtu.');
    }

    public function testUpdateChangesOnlySentLinks(): void
    {
        $doc = $this->cashDocument($this->supplierA, 'Nafta', 1000.00);
        $foreign = $this->cashDocument($this->supplierB, 'Nafta', 1000.00);
        $action = $this->container->get(FuelingsAction::class);
        $body = ['fueled_date' => '2099-03-05', 'amount_with_vat' => 1000, 'car_id' => $this->carA];
        $id = (int) $this->call($action, 'create', 'POST', $body + ['source_cash_document_id' => $doc])['body']['id'];

        $kept = $this->call($action, 'update', 'PUT', $body + ['odometer' => 5000], ['id' => $id]);
        self::assertSame($doc, $kept['body']['source_cash_document_id'], 'Úprava bez vazeb v těle vazbu nemaže.');

        $bad = $this->call($action, 'update', 'PUT', $body + ['source_cash_document_id' => $foreign], ['id' => $id]);
        self::assertSame(400, $bad['status']);

        $cleared = $this->call($action, 'update', 'PUT', $body + ['source_cash_document_id' => null], ['id' => $id]);
        self::assertNull($cleared['body']['source_cash_document_id']);
    }

    public function testDatabaseRejectsForeignLinksWrittenDirectly(): void
    {
        $foreignDoc = $this->cashDocument($this->supplierB, 'Nafta', 1000.00);
        $foreignTx = $this->bankTransaction($this->supplierB, -500.00);
        $carB = $this->car($this->supplierB, '9ZZ 9999');

        foreach ([
            'source_cash_document_id' => $foreignDoc,
            'source_bank_transaction_id' => $foreignTx,
            'car_id' => $carB,
        ] as $column => $value) {
            try {
                $this->pdo->prepare("INSERT INTO fuelings (supplier_id, fueled_date, amount_with_vat, {$column}) VALUES (?, '2099-03-05', 100, ?)")
                    ->execute([$this->supplierA, $value]);
                self::fail("Trigger musel odmítnout cizí {$column}.");
            } catch (\PDOException $e) {
                self::assertStringContainsString('another supplier', $e->getMessage());
            }
        }
    }

    public function testDriverMustBelongToSupplier(): void
    {
        $own = $this->employee($this->supplierA, 'Testovací Řidič');
        $foreign = $this->employee($this->supplierB, 'Cizí Řidič');
        $guard = $this->container->get(TenantReferenceGuard::class);

        self::assertSame([], $guard->violations($this->supplierA, ['driver_employee_id' => $own], ['driver_employee_id']));

        $res = $this->call($this->container->get(CarsAction::class), 'update', 'PUT',
            ['registration' => '1AB 2345', 'driver_employee_id' => $foreign], ['id' => $this->carA]);
        self::assertSame(400, $res['status']);
        self::assertSame('invalid_reference', $res['body']['error']['code'] ?? null);

        $this->expectException(\PDOException::class);
        $this->pdo->prepare('UPDATE cars SET driver_employee_id = ? WHERE id = ?')->execute([$foreign, $this->carA]);
    }

    public function testInvalidUsageCombinationIsRejected(): void
    {
        $res = $this->call($this->container->get(CarsAction::class), 'update', 'PUT',
            ['registration' => '1AB 2345', 'usage_mode' => 'private', 'vat_deduction_mode' => 'full'], ['id' => $this->carA]);

        self::assertSame(400, $res['status']);
        self::assertSame('validation_failed', $res['body']['error']['code'] ?? null);
    }

    /**
     * @param array<string,mixed> $body
     * @param array<string,mixed> $args
     * @return array{status:int, body:array<string,mixed>}
     */
    private function call(object $action, string $method, string $httpMethod, array $body, array $args = []): array
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest($httpMethod, '/api/test')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierA)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant'])
            ->withParsedBody($body);

        /** @var ResponseInterface $response */
        $response = $args === []
            ? $action->{$method}($request, new Psr7Response())
            : $action->{$method}($request, new Psr7Response(), $args);
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);
        return ['status' => $response->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }
}
