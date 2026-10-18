<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Bank;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Bank\EmailNoticeReconciler;
use MyInvoice\Service\Bank\GpcParser;
use MyInvoice\Service\Bank\StatementImporter;
use MyInvoice\Service\Bank\StatementMatcher;
use MyInvoice\Service\Bank\StatementReconciliationException;
use MyInvoice\Service\Invoice\FinalFromProformaCreator;
use MyInvoice\Service\Migration\Shared\BankStatementImportWriter;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Pohyby převzaté převodem z jiného programu (výpis se zdrojem `import`) mají otisk
 * převodu, ne banky. Výpis z banky za stejné období by je proto deduplikací podle otisku
 * nepoznal a založil podruhé — příjem i výdaj by se v peněžním deníku zdvojil. GPC
 * a napojená banka převzaté pohyby párují jednotlivě (AuthoritativeTransactionReconciler);
 * import výpisu v PDF pohyby účtu do dne posledního převzatého pohybu přeskočí a ohlásí to.
 *
 * Izolace: syntetické účty 99905624xx, rok 2099, vlastní currencies řádky; vše se
 * v tearDown maže.
 */
#[Group('integration')]
final class StatementImporterMigratedBoundaryTest extends TestCase
{
    private Connection $db;
    private StatementImporter $importer;
    private int $supplierId = 0;

    /** @var int[] */
    private array $currencyIds = [];

    private const ACCOUNT_PREFIX = '999056240';
    private const BANK_CODE = '2010';
    private const VS = '2099009992';

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $c = Bootstrap::buildContainer();
            $this->db = $c->get(Connection::class);
            $this->importer = new StatementImporter(
                $this->db,
                new GpcParser(),
                new StatementMatcher($this->db, $c->get(FinalFromProformaCreator::class), null),
                $c->get(EmailNoticeReconciler::class),
            );
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        $this->supplierId = (int) ($this->db->pdo()->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
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
        foreach ($this->currencyIds as $id) {
            $this->db->pdo()->prepare('DELETE FROM currencies WHERE id = ?')->execute([$id]);
        }
        $this->db->close();
    }

    /**
     * Převod převzal pohyby do 10. 1.; výpis v PDF za leden nese pohyb z 10. 1. (už
     * převzatý) a z 20. 1. (nový). Založí se jen nový, převzatý se ohlásí.
     */
    public function testMovementsUpToMigratedBoundaryAreSkipped(): void
    {
        $account = self::ACCOUNT_PREFIX . '1';
        $currencyId = $this->registerCurrency($account);
        $this->migrated($account, self::BANK_CODE, '2099-01-10');

        $r = $this->importPdf($account, $currencyId, ['2099-01-10', '2099-01-20']);

        self::assertSame(1, $r['transactions'], json_encode($r, JSON_UNESCAPED_UNICODE) ?: '');
        self::assertSame(1, $r['skipped_migrated']);
        $warning = array_column($r['warnings'], null, 'code')['transactions_skipped_as_migrated'] ?? null;
        self::assertNotNull($warning);
        self::assertSame('2099-01-10', $warning['until']);
        self::assertSame(['2099-01-10', '2099-01-20'], $this->postedDates($account), 'převzatý pohyb jednou, nový z výpisu');
    }

    /** Bez převzatých pohybů na účtu, nebo s převzetím na jiném účtu, se založí všechno. */
    public function testOtherAccountsAreNotAffected(): void
    {
        $account = self::ACCOUNT_PREFIX . '2';
        $currencyId = $this->registerCurrency($account);
        $this->migrated(self::ACCOUNT_PREFIX . '3', self::BANK_CODE, '2099-12-31');
        // Stejné číslo účtu u jiné banky je jiný účet.
        $this->migrated($account, '0100', '2099-12-31');

        $r = $this->importPdf($account, $currencyId, ['2099-01-10', '2099-01-20']);

        self::assertSame(2, $r['transactions'], json_encode($r, JSON_UNESCAPED_UNICODE) ?: '');
        self::assertSame(0, $r['skipped_migrated']);
        self::assertNotContains('transactions_skipped_as_migrated', array_column($r['warnings'], 'code'));
    }

    /**
     * Výpis GPC za převzaté období páruje pohyby s převzatými jednotlivě: shodu dne a částky
     * bez shodného protiúčtu předloží k potvrzení. Hranice převzetí ho nepřeskočí.
     */
    public function testGpcStatementIsReconciledInsteadOfSkipped(): void
    {
        $account = self::ACCOUNT_PREFIX . '4';
        $currencyId = $this->registerCurrency($account);
        $this->migrated($account, self::BANK_CODE, '2099-01-10');

        try {
            $this->importer->import('gpc-' . $account, 'TEST-HRANICE.gpc', null, $currencyId, [], $this->statement($account, ['2099-01-10', '2099-01-20']));
            self::fail('Nejistá shoda s převzatým pohybem čeká na potvrzení.');
        } catch (StatementReconciliationException) {
        }
        self::assertSame(['2099-01-10'], $this->postedDates($account), 'bez potvrzení se nic nezaložilo');
    }

    /**
     * Pohyb výpisu GPC, ke kterému převod žádný pohyb nepřevzal, se založí, i když leží
     * v převzatém období: GPC je úplný seznam pohybů banky a párovač pro něj protějšek
     * nenašel. Hranice převzetí by ho tiše zahodila.
     */
    public function testGpcMovementWithoutMigratedCounterpartIsCreated(): void
    {
        $account = self::ACCOUNT_PREFIX . '5';
        $currencyId = $this->registerCurrency($account);
        $this->migrated($account, self::BANK_CODE, '2099-01-31');

        $r = $this->importer->import('gpc-novy-' . $account, 'TEST-HRANICE.gpc', null, $currencyId, [], $this->statement($account, ['2099-01-15']));

        self::assertSame(1, $r['transactions'], json_encode($r, JSON_UNESCAPED_UNICODE) ?: '');
        self::assertSame(0, $r['skipped_migrated']);
        self::assertSame(['2099-01-15', '2099-01-31'], $this->postedDates($account));
    }

    /**
     * @param list<string> $dates
     * @return array<string,mixed>
     */
    private function importPdf(string $account, int $currencyId, array $dates): array
    {
        return $this->importer->importParsedPdf($this->statement($account, $dates), '%PDF-hranice-' . $account, 'TEST-HRANICE.pdf', null, $currencyId, $this->supplierId);
    }

    /** Převzatý výpis s jedním pohybem v daný den, jak ho zapíše převod. */
    private function migrated(string $account, string $bankCode, string $date): void
    {
        $writer = new BankStatementImportWriter($this->db, 'test-hranice');
        $key = $account . '|' . $bankCode . '|' . $date;
        $statementId = $writer->createStatement($this->supplierId, $key, 'HRANICE/' . substr($date, 0, 7), $account, $bankCode, 'CZK', $date, null);
        $writer->insertTransaction($this->supplierId, $statementId, $key, [
            'source_ref' => 'BP-' . $date, 'posted_at' => $date, 'amount' => '1500.00', 'currency' => 'CZK',
            'variable_symbol' => self::VS, 'bank_ref' => null,
        ]);
        $writer->touchStatement($this->supplierId, $statementId, 1, $date);
    }

    /**
     * @param list<string> $dates
     * @return array{header:array<string,mixed>,transactions:list<array<string,mixed>>}
     */
    private function statement(string $account, array $dates): array
    {
        return [
            'header' => [
                'account_number' => $account, 'statement_date' => '2099-01-31', 'statement_number' => null,
                'prev_balance' => null, 'curr_balance' => null, 'credit_total' => null, 'debit_total' => null,
            ],
            'transactions' => array_map(static fn (string $date, int $i): array => [
                'posted_at' => $date, 'amount' => 1500.00, 'currency' => 'CZK', 'variable_symbol' => self::VS,
                'constant_symbol' => null, 'specific_symbol' => null, 'counterparty_account' => '1000000005',
                'counterparty_bank' => '0100', 'counterparty_name' => 'Odběratel Beta a.s.', 'description' => 'Platba',
                'bank_ref' => 'HR' . $i . str_replace('-', '', $date),
            ], $dates, array_keys($dates)),
        ];
    }

    /** @return list<string> */
    private function postedDates(string $account): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT DATE(bt.posted_at) FROM bank_transactions bt JOIN bank_statements bs ON bs.id = bt.statement_id
              WHERE bs.supplier_id = ? AND bs.account_number = ? ORDER BY bt.posted_at'
        );
        $stmt->execute([$this->supplierId, $account]);
        return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    private function registerCurrency(string $account): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO currencies
                (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default, account_number, bank_code)
             VALUES (?, "CZK", ?, "CZK", "CZK", "CZK", 2, 0, 0, ?, ?)'
        )->execute([$this->supplierId, 'TEST HRANICE /' . $account, $account, self::BANK_CODE]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->currencyIds[] = $id;
        return $id;
    }

    private function cleanup(): void
    {
        $pdo = $this->db->pdo();
        $ids = array_map('intval', $pdo->query(
            "SELECT id FROM bank_statements WHERE account_number LIKE '%" . self::ACCOUNT_PREFIX . "%'"
        )->fetchAll(\PDO::FETCH_COLUMN));
        foreach ($ids as $id) {
            $pdo->prepare('DELETE FROM bank_api_evidence_months WHERE evidence_statement_id = ? OR monthly_statement_id = ?')->execute([$id, $id]);
            $pdo->prepare('DELETE FROM bank_api_months WHERE statement_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM bank_transaction_imports WHERE original_statement_id = ? OR statement_id = ?')->execute([$id, $id]);
        }
        foreach ($ids as $id) {
            $pdo->prepare('DELETE FROM bank_transactions WHERE statement_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM bank_statements WHERE id = ?')->execute([$id]);
        }
        $pdo->prepare("DELETE FROM currencies WHERE label LIKE 'TEST HRANICE /%'")->execute();
    }
}
