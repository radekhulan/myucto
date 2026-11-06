<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Bank;

use MyInvoice\Action\Bank\BankStatementAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Security\EffectiveRole;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Zrušení párování zaúčtovaného pohybu stornuje zápis v deníku stejně jako
 * /unpost, takže vyžaduje `bank.unpost` a zápis do účetnictví. Nezaúčtovaný
 * pohyb dál stačí zrušit s bank WRITE.
 */
#[Group('integration')]
final class BankUnmatchPostedPermissionTest extends TestCase
{
    private const ACCOUNT = '9990561188';
    private const BANK_CODE = '2010';

    private Connection $db;
    private ContainerInterface $container;
    private int $supplierId = 0;
    private int $userId = 0;
    private int $transactionId = 0;
    private int $entryId = 0;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje, test vyžaduje DB.');
        }
        try {
            $this->container = Bootstrap::buildContainer();
            $this->db = $this->container->get(Connection::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí supplier/uživatel v DB.');
        }
        $this->cleanup();

        $pdo->prepare(
            "INSERT INTO currencies
                (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default, account_number, bank_code)
             VALUES (?, 'CZK', '__TEST H14', 'Kč', 'Koruna', 'Koruna', 2, 1, 0, ?, ?)"
        )->execute([$this->supplierId, self::ACCOUNT, self::BANK_CODE]);
        $pdo->prepare(
            "INSERT INTO bank_statements
                (supplier_id, source, file_name, file_hash, file_content, account_number, bank_code, currency,
                 statement_number, statement_date, prev_balance, curr_balance, transaction_count, imported_by)
             VALUES (?, 'gpc', '__TEST-H14.gpc', ?, 'RAW', ?, ?, 'CZK', '1', '2099-03-31', 0, 10, 1, NULL)"
        )->execute([$this->supplierId, hash('sha256', 'h14-' . uniqid('', true)), self::ACCOUNT, self::BANK_CODE]);
        $statementId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO bank_transactions
                (statement_id, posted_at, amount, currency, counterparty_account, description, import_fingerprint,
                 match_status, ignore_note)
             VALUES (?, '2099-03-15', -10, 'CZK', '1000000005', 'H14 test', ?, 'ignored', 'poplatek')"
        )->execute([$statementId, hash('sha256', 'h14-tx-' . uniqid('', true))]);
        $this->transactionId = (int) $pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $this->cleanup();
        $this->db->close();
    }

    public function testUnpostedMovementStillUnmatchesWithBankWrite(): void
    {
        $status = $this->unmatch(['bank' => 2]);

        self::assertSame(200, $status);
        self::assertSame('unmatched', $this->matchStatus());
    }

    public function testPostedMovementNeedsBankUnpostAndAccountingWrite(): void
    {
        $this->postEntry();

        self::assertSame(403, $this->unmatch(['bank' => 2]));
        self::assertSame(403, $this->unmatch(['bank' => 2, 'bank.unpost' => 2]));
        self::assertSame(403, $this->unmatch(['bank' => 2, 'accounting' => 2]));
        self::assertSame('ignored', $this->matchStatus(), 'Odmítnuté zrušení párování nesmí pohyb změnit.');
        $live = $this->db->pdo()->prepare('SELECT reversed_by FROM journal_entries WHERE id = ?');
        $live->execute([$this->entryId]);
        self::assertNull($live->fetchColumn(), 'Odmítnuté zrušení párování nesmí zápis stornovat.');
    }

    /** @param array<string,int> $permissions */
    private function unmatch(array $permissions): int
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/bank-transactions/' . $this->transactionId . '/unmatch')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant'])
            ->withAttribute('auth.effective_role', new EffectiveRole(901, 'Synthetic bank staff', 'staff', true, $permissions));
        $action = $this->container->get(BankStatementAction::class);

        return $action->unmatch($request, new Psr7Response(), ['id' => (string) $this->transactionId])->getStatusCode();
    }

    private function postEntry(): void
    {
        $pdo = $this->db->pdo();
        $period = $pdo->prepare('SELECT id FROM accounting_periods WHERE supplier_id = ? ORDER BY id LIMIT 1');
        $period->execute([$this->supplierId]);
        $periodId = (int) ($period->fetchColumn() ?: 0);
        if ($periodId === 0) {
            $this->markTestSkipped('Firma nemá účetní období.');
        }
        $pdo->prepare(
            "INSERT INTO journal_entries (supplier_id, period_id, entry_date, source_type, source_id, posted_at)
             VALUES (?, ?, '2099-03-15', 'bank', ?, NOW())"
        )->execute([$this->supplierId, $periodId, $this->transactionId]);
        $this->entryId = (int) $pdo->lastInsertId();
    }

    private function matchStatus(): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT match_status FROM bank_transactions WHERE id = ?');
        $stmt->execute([$this->transactionId]);

        return (string) $stmt->fetchColumn();
    }

    private function cleanup(): void
    {
        $pdo = $this->db->pdo();
        if ($this->entryId > 0) {
            $pdo->prepare('DELETE FROM journal_entries WHERE id = ?')->execute([$this->entryId]);
        }
        $pdo->prepare('DELETE FROM bank_statements WHERE account_number = ?')->execute([self::ACCOUNT]);
        $pdo->prepare("DELETE FROM currencies WHERE label = '__TEST H14' AND account_number = ?")->execute([self::ACCOUNT]);
    }
}
