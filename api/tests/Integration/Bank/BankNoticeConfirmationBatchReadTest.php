<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Bank;

use MyInvoice\Action\Bank\BankStatementAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Bank\BankMovementListService;
use MyInvoice\Service\Bank\BankNoticeConfirmationReader;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class BankNoticeConfirmationBatchReadTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private \Psr\Container\ContainerInterface $container;
    private int $supplier;

    protected function setUp(): void
    {
        $this->container = Bootstrap::buildContainer();
        $this->db = $this->container->get(Connection::class);
        $pdo = $this->db->pdo();
        self::assertStringEndsWith('_test', (string) $pdo->query('SELECT DATABASE()')->fetchColumn());
        $pdo->beginTransaction();
        $source = (int) $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        self::assertGreaterThan(0, $source, 'Vyžadován syntetický seed testovací databáze.');
        $this->supplier = $this->createIsolatedSupplier($pdo, $source);
        $pdo->prepare("UPDATE supplier SET accounting_mode='tax_evidence',accounting_enabled=0 WHERE id=?")->execute([$this->supplier]);
        $pdo->prepare("INSERT INTO currencies (supplier_id,code,label,symbol,name_cs,name_en,account_number,bank_code) VALUES (?,'CZK','Synthetic batch','Kč','Koruna','Koruna','1000000005','0100')")->execute([$this->supplier]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
    }

    private function movement(string $source = 'email_notice', string $vs = '157'): array
    {
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO bank_statements (supplier_id,source,file_name,file_hash,account_number,bank_code,currency,statement_date) VALUES (?,?,'Synthetic batch',?,'1000000005','0100','CZK','2099-06-15')")->execute([$this->supplier, $source, hash('sha256', random_bytes(20))]);
        $statement = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO bank_transactions (statement_id,source,source_ref,posted_at,amount,currency,variable_symbol) VALUES (?,?,?,'2099-06-15',100,'CZK',?)")->execute([$statement, $source === 'email_notice' ? 'email_notice' : 'statement', 'synthetic-batch:' . bin2hex(random_bytes(10)), $vs]);
        return [(int) $pdo->lastInsertId(), $statement];
    }

    private function selects(callable $operation): array
    {
        $pdo = $this->db->pdo();
        $before = (int) $pdo->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetch(PDO::FETCH_NUM)[1];
        $result = $operation();
        $after = (int) $pdo->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetch(PDO::FETCH_NUM)[1];
        return [$after - $before, $result];
    }

    private function statementList(): array
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplier)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 1, 'role' => 'admin'])
            ->withQueryParams(['show_confirmed' => true, 'filter' => ['year' => 2099]]);
        $response = $this->container->get(BankStatementAction::class)->list($request, new Response());
        self::assertSame(200, $response->getStatusCode());
        return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function testStatementListQueryCountDoesNotGrowWithNoticeStatements(): void
    {
        $this->movement();
        $this->statementList(); // Lazy inicializace kontejneru a metadat nepatří do měření.
        [$one, $first] = $this->selects(fn () => $this->statementList());
        for ($i = 0; $i < 24; $i++) $this->movement();
        [$many, $last] = $this->selects(fn () => $this->statementList());
        self::assertSame(1, $first['total']);
        self::assertSame(25, $last['total']);
        self::assertSame($one, $many, 'Počet SQL SELECT nesmí růst s počtem přehledů avíz.');
        foreach ($last['items'] as $item) {
            self::assertSame(['pending' => 1, 'review' => 0, 'confirmed' => 0, 'total' => 1], $item['confirmation_summary']);
            self::assertTrue($item['can_delete_notices']);
            self::assertSame([], $item['deletion_blockers']);
        }
    }

    public function testAllMovementListExcludesNoticesAndKeepsConstantReadQueries(): void
    {
        $this->movement();
        [$target] = $this->movement('gpc');
        $list = $this->container->get(BankMovementListService::class);
        $query = ['show_confirmed' => true, 'year' => 2099, 'per_page' => 100];
        $list->list($this->supplier, $query, 'all');
        [$one, $first] = $this->selects(fn () => $list->list($this->supplier, $query, 'all'));
        for ($i = 0; $i < 24; $i++) $this->movement();
        [$many, $last] = $this->selects(fn () => $list->list($this->supplier, $query, 'all'));
        self::assertSame(1, $first['total']);
        self::assertSame(1, $last['total']);
        self::assertSame($one, $many, 'Počet SQL SELECT nesmí růst s počtem řádků avíz.');
        self::assertSame([$target], array_column($first['items'], 'id'));
        self::assertSame([$target], array_column($last['items'], 'id'));
        self::assertSame(['statement'], array_column($last['items'], 'source'));
        self::assertSame(0, (int) $this->db->pdo()->query('SELECT COUNT(*) FROM bank_notice_confirmations WHERE supplier_id=' . $this->supplier)->fetchColumn(), 'GET nesmí zapisovat ani automaticky potvrzovat.');
    }

    public function testBatchSummaryLinksDependenciesAndInvalidation(): void
    {
        [$notice, $noticeStatement] = $this->movement();
        [$target, $targetStatement] = $this->movement('gpc');
        $pdo = $this->db->pdo();
        $ref = 'synthetic-batch-confirmed:' . $notice;
        $pdo->prepare("INSERT INTO bank_notice_confirmations (supplier_id,notice_transaction_id,notice_source_ref,notice_identity,authoritative_transaction_id,state) VALUES (?,?,?,?,?,'confirmed')")->execute([$this->supplier, $notice, $ref, hash('sha256', $ref), $target]);
        $reader = new BankNoticeConfirmationReader($this->db);
        self::assertTrue($reader->statements($this->supplier, [$noticeStatement])[$noticeStatement]['can_delete_notices']);
        $item = $reader->notices($this->supplier, [$notice])[$notice];
        self::assertSame($target, $item['authoritative_transaction_id']);
        self::assertSame([$targetStatement], $item['authoritative_statement_ids']);
        [, $importedStatement] = $this->movement('gpc', '158');
        $pdo->prepare('INSERT INTO bank_transaction_imports (statement_id,bank_transaction_id,import_fingerprint,supplier_id,original_statement_id) VALUES (?,?,?,?,?)')->execute([$importedStatement, $target, hash('sha256', 'synthetic-batch-import:' . $target), $this->supplier, $targetStatement]);
        self::assertSame([$targetStatement, $importedStatement], $reader->notices($this->supplier, [$notice])[$notice]['authoritative_statement_ids']);
        $pdo->prepare('UPDATE bank_notice_confirmations SET deleted_at=NOW() WHERE notice_transaction_id=?')->execute([$notice]);
        self::assertSame([$targetStatement => 1], $reader->deletedCounts($this->supplier, [$targetStatement, $noticeStatement]));
        self::assertSame([], $reader->deletedCounts($this->supplier + 100000, [$targetStatement]));
        $pdo->prepare("INSERT INTO cash_journal_notes (supplier_id,source_type,source_id,body) VALUES (?,'bank',?,'Synthetic dependency')")->execute([$this->supplier, $notice]);
        $summary = $reader->statements($this->supplier, [$noticeStatement])[$noticeStatement];
        self::assertFalse($summary['can_delete_notices']);
        self::assertContains(['code' => 'dependency_cash_journal_notes', 'count' => 1], $summary['deletion_blockers']);
        $pdo->prepare("UPDATE bank_notice_confirmations SET state='review',reason='authoritative_removed',authoritative_transaction_id=NULL WHERE notice_transaction_id=?")->execute([$notice]);
        $item = $reader->notices($this->supplier, [$notice])[$notice];
        self::assertSame('review', $item['confirmation_state']);
        self::assertSame('authoritative_removed', $item['confirmation_reason']);
        self::assertNull($item['authoritative_transaction_id']);
        self::assertSame([], $reader->notices($this->supplier + 100000, [$notice]), 'Dávka respektuje vlastnictví firmy.');
    }
}
