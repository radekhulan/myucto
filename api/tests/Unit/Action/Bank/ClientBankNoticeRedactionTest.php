<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Action\Bank;

use MyInvoice\Action\Bank\ClientBankAccess;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Infrastructure\Database\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

final class ClientBankNoticeRedactionTest extends TestCase
{
    private function metadata(): array
    {
        return [
            'id' => 7,
            'source' => 'email_notice',
            'amount' => 100,
            'candidates' => [['id' => 8, 'description' => 'Internal comparison']],
            'blockers' => ['dependency_invoice_payments'],
            'authoritative_transaction_id' => 8,
            'authoritative_statement_id' => 9,
            'authoritative_statement_ids' => [9, 10],
            'confirmation_state' => 'review',
            'confirmation_record_exists' => true,
            'confirmation_reason' => 'ambiguous',
            'confirmation_internal_note' => 'Future confirmation metadata',
        ];
    }

    public function testClientDoesNotReceiveInternalNoticeComparisonEvenWithBankWrite(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/bank-transactions')
            ->withAttribute(\MyInvoice\Middleware\SupplierScopeMiddleware::ATTR_CURRENT_ID, 7)
            ->withAttribute('auth.effective_role', new EffectiveRole(8, 'Synthetic client', 'client', true, ['bank' => 2, 'bank.match' => 2]));
        $row = ClientBankAccess::redactTransaction($request, $this->metadata());
        foreach (array_keys($this->metadata()) as $key) {
            if (in_array($key, ['id', 'source', 'amount'], true)) continue;
            self::assertArrayNotHasKey($key, $row, $key);
        }
        self::assertSame(7, $row['id']);
        self::assertSame(100, $row['amount']);
    }

    public static function readOnlyNotices(): array
    {
        return [
            'confirmed history' => ['confirmed', 'replaced'],
            'removed authoritative transaction' => ['review', 'authoritative_removed'],
        ];
    }

    #[DataProvider('readOnlyNotices')]
    public function testClientCannotMutateNoticeHistoryAfterComparisonMetadataIsRedacted(string $state, string $reason): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/bank-transactions')
            ->withAttribute(\MyInvoice\Middleware\SupplierScopeMiddleware::ATTR_CURRENT_ID, 7)
            ->withAttribute('auth.effective_role', new EffectiveRole(8, 'Synthetic client', 'client', true, ['bank' => 2, 'bank.match' => 2]));
        $statement = $this->createStub(\PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        // Even a corrupt/manual match flag cannot re-enable a confirmed historical notice.
        $statement->method('fetchAll')->willReturn([7 => 1]);
        $pdo = $this->createMock(\PDO::class);
        $pdo->expects(self::once())->method('prepare')->willReturn($statement);
        $db = $this->createStub(Connection::class);
        $db->method('pdo')->willReturn($pdo);
        $rows = (new ClientBankAccess($db))->redactTransactions($request, [
            array_replace($this->metadata(), ['match_status' => 'manual', 'ignore_origin' => 'manual',
                'confirmation_state' => $state, 'confirmation_reason' => $reason]),
        ]);
        self::assertFalse($rows[0]['match_available']);
        self::assertFalse($rows[0]['ignore_available']);
        self::assertArrayNotHasKey('confirmation_state', $rows[0]);
        self::assertArrayNotHasKey('candidates', $rows[0]);
    }

    public function testStatementConfirmationMetadataIsInternalForClientsOnly(): void
    {
        $metadata = ['id' => 9, 'confirmation_summary' => ['pending' => 1], 'deletion_blockers' => [['code' => 'unconfirmed']],
            'can_delete_notices' => false, 'deleted_notice_confirmations' => 1];
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/bank-statements/9');
        $client = $request->withAttribute('auth.effective_role', new EffectiveRole(8, 'Synthetic client', 'client', true, ['bank' => 2]));
        self::assertSame(['id' => 9], ClientBankAccess::redactStatement($client, $metadata));
        $staff = $request->withAttribute('auth.effective_role', new EffectiveRole(8, 'Synthetic staff', 'staff', true, ['bank' => 1]));
        self::assertSame($metadata, ClientBankAccess::redactStatement($staff, $metadata));
    }

    public function testStaffStillReceivesAllNoticeComparisonMetadata(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/bank-transactions')
            ->withAttribute(\MyInvoice\Middleware\SupplierScopeMiddleware::ATTR_CURRENT_ID, 7)
            ->withAttribute('auth.effective_role', new EffectiveRole(8, 'Synthetic staff', 'staff', true, ['bank' => 1]));
        self::assertSame($this->metadata(), ClientBankAccess::redactTransaction($request, $this->metadata()));
    }
}
