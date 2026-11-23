<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Bank;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\BankEmailNoticeRepository;
use MyInvoice\Service\Bank\EmailNotice\ForeignCurrencyNoticeException;
use MyInvoice\Service\Bank\EmailNotice\ParsedBankEmailNotice;
use MyInvoice\Service\Bank\StatementMatcher;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Avízo o platbě kartou v cizí měně na korunovém účtu (#390). Částka nákupu se nesmí
 * zapsat jako koruny; pohyb vznikne jen s částkou v měně účtu.
 */
#[Group('integration')]
final class BankEmailNoticeForeignCurrencyTest extends TestCase
{
    private const ACCOUNT = '9990562388';

    private Connection $db;
    private BankEmailNoticeRepository $repository;
    private StatementMatcher $matcher;
    private int $supplierId = 0;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db = $container->get(Connection::class);
            $this->repository = $container->get(BankEmailNoticeRepository::class);
            $this->matcher = $container->get(StatementMatcher::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $this->supplierId = (int) ($this->db->pdo()->query(
            'SELECT id FROM supplier ORDER BY id LIMIT 1'
        )->fetchColumn() ?: 0);
        if ($this->supplierId === 0) {
            $this->markTestSkipped('Chybí supplier v DB.');
        }
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $this->cleanup();
        $this->db->close();
    }

    public function testForeignAmountOnlyNoticeCreatesNoCzkTransaction(): void
    {
        $notice = $this->cardNotice(amount: -18.00, currency: 'EUR');

        try {
            $this->repository->createTransactionFromNotice(
                $this->supplierId,
                $notice,
                'imap-1:<card-eur@example.test>',
                0.05,
                $this->matcher,
                'CZK',
            );
            self::fail('Avízo jen s částkou v EUR nesmělo na korunovém účtu založit pohyb.');
        } catch (ForeignCurrencyNoticeException $e) {
            self::assertStringContainsString('EUR', $e->getMessage());
        }

        self::assertSame([], $this->transactions());
        self::assertSame(0, (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM bank_statements WHERE source = 'email_notice' AND account_number = '" . self::ACCOUNT . "'"
        )->fetchColumn());
    }

    public function testNoticeWithAccountAmountKeepsOriginalPurchase(): void
    {
        $notice = $this->cardNotice(amount: -400.25, currency: 'CZK', originalAmount: 17.50, originalCurrency: 'USD');

        $this->repository->createTransactionFromNotice(
            $this->supplierId,
            $notice,
            'imap-1:<card-usd@example.test>',
            0.05,
            $this->matcher,
            'CZK',
        );

        $rows = $this->transactions();
        self::assertCount(1, $rows);
        self::assertSame('-400.25', (string) $rows[0]['amount']);
        self::assertSame('CZK', $rows[0]['currency']);
        self::assertSame('-17.50', (string) $rows[0]['original_amount']);
        self::assertSame('USD', $rows[0]['original_currency']);
    }

    public function testNoticeWithoutStatedCurrencyStillTakesAccountCurrency(): void
    {
        $notice = new ParsedBankEmailNotice(
            variableSymbol: '',
            amount: 250.00,
            currency: 'CZK',
            postedAt: '2099-07-14',
            recipientAccount: self::ACCOUNT . '/2010',
            currencyStated: false,
        );

        $this->repository->createTransactionFromNotice(
            $this->supplierId,
            $notice,
            'imap-1:<fio-eur@example.test>',
            0.05,
            $this->matcher,
            'EUR',
        );

        $rows = $this->transactions();
        self::assertCount(1, $rows);
        self::assertSame('250.00', (string) $rows[0]['amount']);
        self::assertSame('EUR', $rows[0]['currency']);
        self::assertNull($rows[0]['original_amount']);
    }

    private function cardNotice(float $amount, string $currency, ?float $originalAmount = null, ?string $originalCurrency = null): ParsedBankEmailNotice
    {
        return new ParsedBankEmailNotice(
            variableSymbol: '',
            amount: $amount,
            currency: $currency,
            postedAt: '2099-07-14',
            recipientAccount: self::ACCOUNT . '/0800',
            counterpartyName: 'EXAMPLE SERVICE',
            balance: 10000.00,
            originalAmount: $originalAmount,
            originalCurrency: $originalCurrency,
        );
    }

    /** @return list<array<string,mixed>> */
    private function transactions(): array
    {
        return $this->db->pdo()->query(
            "SELECT bt.amount, bt.currency, bt.original_amount, bt.original_currency
               FROM bank_transactions bt
               JOIN bank_statements bs ON bs.id = bt.statement_id
              WHERE bt.source = 'email_notice' AND bs.account_number = '" . self::ACCOUNT . "'
              ORDER BY bt.id"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    private function cleanup(): void
    {
        $this->db->pdo()->prepare(
            "DELETE FROM bank_statements WHERE source = 'email_notice' AND account_number = ?"
        )->execute([self::ACCOUNT]);
    }
}
