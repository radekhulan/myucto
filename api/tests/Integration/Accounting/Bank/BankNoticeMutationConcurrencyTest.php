<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Bank;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Bank\BankNoticeConfirmationException;
use MyInvoice\Service\Bank\BankNoticeMutationGuard;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use MyInvoice\Action\Bank\BankStatementAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class BankNoticeMutationConcurrencyTest extends TestCase
{
    public static function mutationPaths(): iterable
    {
        yield 'reference writer' => [false];
        yield 'bank API action' => [true];
    }

    #[DataProvider('mutationPaths')]
    public function testOrdinaryMovementCanBeChangedWhileAnotherConnectionHoldsImportLock(bool $viaAction): void
    {
        $writer = Connection::withoutSharedTestConnection(static fn () => Bootstrap::buildContainer()->get(Connection::class));
        $importer = Connection::withoutSharedTestConnection(static fn () => Bootstrap::buildContainer()->get(Connection::class));
        $pdo = $writer->pdo();
        $other = $importer->pdo();
        $supplier = (int) $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        $statement = $transaction = 0;
        $lock = \MyInvoice\Infrastructure\Database\NamedLockName::for($writer, 'bank-import', (string) $supplier);
        try {
            $pdo->prepare("INSERT INTO bank_statements (supplier_id,file_name,file_hash,account_number,bank_code,currency,source,statement_date) VALUES (?,'synthetic-ordinary.gpc',?,'1000000005','0100','CZK','gpc','2099-06-15')")
                ->execute([$supplier, hash('sha256', random_bytes(16))]);
            $statement = (int) $pdo->lastInsertId();
            $pdo->prepare("INSERT INTO bank_transactions (statement_id,source,posted_at,amount,currency) VALUES (?,'statement','2099-06-15',200,'CZK')")->execute([$statement]);
            $transaction = (int) $pdo->lastInsertId();
            $held = $other->prepare('SELECT GET_LOCK(?, 0)');
            $held->execute([$lock]);
            self::assertSame(1, (int) $held->fetchColumn());
            // A mistaken GET_LOCK(...,30) fails in one second instead of stalling the test.
            $pdo->exec('SET SESSION max_statement_time=1');
            if ($viaAction) {
                $container = Bootstrap::buildContainer();
                $container->set(Connection::class, $writer);
                $request = (new ServerRequestFactory())->createServerRequest('POST', '/')
                    ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplier)
                    ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => (int) $other->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn(), 'role' => 'admin']);
                $response = $container->get(BankStatementAction::class)->ignore($request, new Response(), ['id' => $transaction]);
                self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
                self::assertSame('ignored', $other->query('SELECT match_status FROM bank_transactions WHERE id=' . $transaction)->fetchColumn());
            } else {
                $result = (new BankNoticeMutationGuard($writer))->mutate($supplier, $transaction, function () use ($pdo, $transaction): string {
                    $pdo->prepare('UPDATE bank_transactions SET description=? WHERE id=?')->execute(['Synthetic ordinary edit', $transaction]);
                    return 'saved';
                });
                self::assertSame('saved', $result);
                self::assertSame('Synthetic ordinary edit', $other->query('SELECT description FROM bank_transactions WHERE id=' . $transaction)->fetchColumn());
            }
            $held = $pdo->prepare('SELECT IS_USED_LOCK(?)');
            $held->execute([$lock]);
            self::assertSame((int) $other->query('SELECT CONNECTION_ID()')->fetchColumn(), (int) $held->fetchColumn());
        } finally {
            $pdo->exec('SET SESSION max_statement_time=0');
            if ($pdo->inTransaction()) $pdo->rollBack();
            $other->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);
            if ($transaction > 0) {
                $other->prepare("DELETE FROM activity_log WHERE entity_type='bank_transaction' AND entity_id=?")->execute([$transaction]);
                $other->prepare('DELETE FROM bank_transactions WHERE id=?')->execute([$transaction]);
            }
            if ($statement > 0) $other->prepare('DELETE FROM bank_statements WHERE id=?')->execute([$statement]);
            $writer->close();
            $importer->close();
        }
    }

    public function testNewTargetReservationCannotBeHiddenByAnOlderWriterSnapshot(): void
    {
        $writer = Connection::withoutSharedTestConnection(static fn () => Bootstrap::buildContainer()->get(Connection::class));
        $confirmer = Connection::withoutSharedTestConnection(static fn () => Bootstrap::buildContainer()->get(Connection::class));
        $pdo = $writer->pdo();
        $other = $confirmer->pdo();
        $supplier = (int) $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        $statement = $noticeStatement = $notice = $target = 0;
        $lock = \MyInvoice\Infrastructure\Database\NamedLockName::for($writer, 'bank-import', (string) $supplier);
        try {
            $pdo->prepare("INSERT INTO bank_statements (supplier_id,file_name,file_hash,account_number,bank_code,currency,source,statement_date) VALUES (?,'synthetic-target.gpc',?,'1000000005','0100','CZK','gpc','2099-06-15')")
                ->execute([$supplier, hash('sha256', random_bytes(16))]);
            $statement = (int) $pdo->lastInsertId();
            $pdo->prepare("INSERT INTO bank_transactions (statement_id,source,posted_at,amount,currency) VALUES (?,'statement','2099-06-15',200,'CZK')")->execute([$statement]);
            $target = (int) $pdo->lastInsertId();
            $pdo->prepare("INSERT INTO bank_statements (supplier_id,file_name,file_hash,account_number,bank_code,currency,source,statement_date) VALUES (?,'synthetic-target.eml',?,'1000000005','0100','CZK','email_notice','2099-06-15')")
                ->execute([$supplier, hash('sha256', random_bytes(16))]);
            $noticeStatement = (int) $pdo->lastInsertId();
            $ref = 'synthetic-target-' . bin2hex(random_bytes(8));
            $pdo->prepare("INSERT INTO bank_transactions (statement_id,source,source_ref,posted_at,amount,currency) VALUES (?,'email_notice',?,'2099-06-15',200,'CZK')")->execute([$noticeStatement, $ref]);
            $notice = (int) $pdo->lastInsertId();
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $pdo->beginTransaction();
            $snapshot = $pdo->prepare('SELECT COUNT(*) FROM bank_notice_confirmations WHERE authoritative_transaction_id=?');
            $snapshot->execute([$target]);
            self::assertSame(0, (int) $snapshot->fetchColumn());
            $other->prepare("INSERT INTO bank_notice_confirmations (supplier_id,notice_transaction_id,notice_source_ref,notice_identity,state,authoritative_transaction_id) VALUES (?,?,?,?,'confirmed',?)")
                ->execute([$supplier, $notice, $ref, hash('sha256', $ref), $target]);
            $called = false;
            try {
                (new BankNoticeMutationGuard($writer))->mutate($supplier, $target, static function () use (&$called): void { $called = true; });
                self::fail('A target reservation committed after the writer snapshot must require retry under the named lock.');
            } catch (BankNoticeConfirmationException $exception) {
                self::assertSame('bank_busy', $exception->errorCode);
                self::assertFalse($called);
            }
            if ($pdo->inTransaction()) $pdo->rollBack();
            // A retry with a fresh snapshot recognizes the existing reservation
            // before the row lock and must still serialize with the importer.
            $held = $other->prepare('SELECT GET_LOCK(?,0)');
            $held->execute([$lock]);
            self::assertSame(1, (int) $held->fetchColumn());
            $pdo->exec('SET SESSION max_statement_time=1');
            try {
                (new BankNoticeMutationGuard($writer))->mutate($supplier, $target, static function () use (&$called): void { $called = true; });
                self::fail('An already reserved target must retain the shared import lock.');
            } catch (BankNoticeConfirmationException $exception) {
                self::assertSame('bank_busy', $exception->errorCode);
                self::assertFalse($called);
            }
        } finally {
            $pdo->exec('SET SESSION max_statement_time=0');
            if ($pdo->inTransaction()) $pdo->rollBack();
            $other->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);
            if ($notice > 0) $other->prepare('DELETE FROM bank_notice_confirmations WHERE notice_transaction_id=?')->execute([$notice]);
            foreach ([$notice, $target] as $id) if ($id > 0) $other->prepare('DELETE FROM bank_transactions WHERE id=?')->execute([$id]);
            foreach ([$noticeStatement, $statement] as $id) if ($id > 0) $other->prepare('DELETE FROM bank_statements WHERE id=?')->execute([$id]);
            $writer->close();
            $confirmer->close();
        }
    }

    #[DataProvider('mutationPaths')]
    public function testExistingRepeatableReadSnapshotCannotHideCommittedConfirmation(bool $viaAction): void
    {
        $writer = Connection::withoutSharedTestConnection(static fn () => Bootstrap::buildContainer()->get(Connection::class));
        $confirmer = Connection::withoutSharedTestConnection(static fn () => Bootstrap::buildContainer()->get(Connection::class));
        $pdo = $writer->pdo();
        $other = $confirmer->pdo();
        $supplierId = (int) $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        self::assertGreaterThan(0, $supplierId, 'Synthetic test tenant is required.');
        $statementId = $noticeId = 0;
        try {
            $ref = 'synthetic-snapshot-' . bin2hex(random_bytes(8));
            $pdo->prepare("INSERT INTO bank_statements (supplier_id,file_name,file_hash,account_number,bank_code,currency,source,statement_date) VALUES (?,'synthetic-snapshot.eml',?,'1000000005','0100','CZK','email_notice','2099-06-15')")
                ->execute([$supplierId, hash('sha256', $ref)]);
            $statementId = (int) $pdo->lastInsertId();
            $pdo->prepare("INSERT INTO bank_transactions (statement_id,source,source_ref,posted_at,amount,currency) VALUES (?,'email_notice',?,'2099-06-15',200,'CZK')")
                ->execute([$statementId, $ref]);
            $noticeId = (int) $pdo->lastInsertId();
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $pdo->beginTransaction();
            // Čtenář začal dřív, než jiný request avízo potvrdil.
            $snapshot = $pdo->prepare('SELECT COUNT(*) FROM bank_notice_confirmations WHERE notice_transaction_id=?');
            $snapshot->execute([$noticeId]);
            self::assertSame(0, (int) $snapshot->fetchColumn());
            $other->prepare("INSERT INTO bank_notice_confirmations (supplier_id,notice_transaction_id,notice_source_ref,notice_identity,state) VALUES (?,?,?,?,'confirmed')")
                ->execute([$supplierId, $noticeId, $ref, hash('sha256', $ref)]);
            if ($viaAction) {
                $container = Bootstrap::buildContainer();
                $container->set(Connection::class, $writer);
                $request = (new ServerRequestFactory())->createServerRequest('POST', '/')
                    ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId)
                    ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => (int) $other->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn(), 'role' => 'admin']);
                $response = $container->get(BankStatementAction::class)->ignore($request, new Response(), ['id' => $noticeId]);
                self::assertSame(409, $response->getStatusCode());
                self::assertSame('unmatched', $other->query('SELECT match_status FROM bank_transactions WHERE id=' . $noticeId)->fetchColumn());
                return;
            }
            $called = false;
            try {
                (new BankNoticeMutationGuard($writer))->mutate($supplierId, $noticeId, static function () use (&$called): void { $called = true; });
                self::fail('Committed confirmation must block a writer with an older snapshot.');
            } catch (BankNoticeConfirmationException $exception) {
                self::assertContains($exception->errorCode, ['notice_read_only', 'bank_busy']);
                self::assertFalse($called);
            }
        } finally {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($noticeId > 0) {
                $other->prepare('DELETE FROM bank_notice_confirmations WHERE notice_transaction_id=?')->execute([$noticeId]);
                $other->prepare('DELETE FROM bank_transactions WHERE id=?')->execute([$noticeId]);
            }
            if ($statementId > 0) $other->prepare('DELETE FROM bank_statements WHERE id=?')->execute([$statementId]);
            $writer->close();
            $confirmer->close();
        }
    }
}
