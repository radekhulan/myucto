<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\ClosingRepository;
use MyInvoice\Repository\JournalEntryDocumentLinkRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\DocumentPostingGroup;
use MyInvoice\Service\Accounting\PostingService;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * K3 „zaplacené vydané faktury s otevřeným saldem" ({@see ClosingRepository::paidInvoicesOpenSaldo})
 * nad proformou s víc konečnými fakturami, DDKP a doklady převzatými po řádcích.
 *
 * Úhradu proformy i DDKP k ní dostává PRVNÍ nezrušená konečná faktura. Dotaz ji dřív hledal
 * závislým poddotazem za každou úhradu a každý DDKP, na produkci po indexu jen na firmu
 * (9 až 26 s). Test drží výsledek přesně a hlídá, že plán žádný takový poddotaz nemá.
 */
#[Group('integration')]
final class ClosingPaidSaldoProformaGroupTest extends TestCase
{
    private const YEAR = 2099;

    private Connection $db;
    private PostingService $posting;
    private JournalEntryDocumentLinkRepository $links;

    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $userId = 0;
    private int $czId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje - test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db      = $container->get(Connection::class);
            $this->posting = $container->get(PostingService::class);
            $periods       = $container->get(AccountingPeriodRepository::class);
            $seeder        = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        $this->links = new JournalEntryDocumentLinkRepository($this->db);

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId       = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->currencyId === 0 || $this->userId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier/currency/user/country) v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id)
             SELECT ?, "Testovací", "Praha", "11000", country_id, ?, default_currency_id, default_vat_rate_id
               FROM supplier WHERE id = ?'
        )->execute(['K3 proformy s.r.o.', 'k3-proformy@example.com', $this->supplierId]);
        $this->supplierId = (int) $pdo->lastInsertId();

        $seeder->seedForSupplier($this->supplierId);
        $periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
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

    /**
     * Proforma zaplacená předem, zrušená konečná faktura, dvě konečné faktury (milníky)
     * a DDKP. Úhrada i DDKP patří první nezrušené konečné faktuře, takže je vyrovnaná.
     * Převzatý doklad zaúčtovaný skupinou a zaplacený bankou nález nemá, převzatý doklad
     * označený jako uhrazený bez úhrady ano, s předpisem ze všech zápisů skupiny.
     */
    public function testProformaFinalsTaxDocumentAndPostingGroupsKeepFindings(): void
    {
        $client = $this->client('Odběratel proforem');
        $proforma = $this->invoice($client, 'proforma', null, 1210.00, '03-01');
        $tx = $this->bankInvoicePayment($proforma, 1210.00, '03-05');
        $this->posting->postDocument($this->supplierId, 'bank', $tx, [
            self::l('221', 'debit', 1210.00),
            self::l('311', 'credit', 1210.00),
        ], $this->opts('03-05'));

        $cancelled = $this->invoice($client, 'invoice', $proforma, 1210.00, '03-08');
        $this->db->pdo()->prepare("UPDATE invoices SET status = 'cancelled', cancelled_at = ? WHERE id = ?")
            ->execute([self::YEAR . '-03-09 10:00:00', $cancelled]);

        $first = $this->invoice($client, 'invoice', $proforma, 1110.00, '03-10');
        $this->posting->postDocument($this->supplierId, 'invoice', $first, [
            self::l('311', 'debit', 1110.00),
            self::l('602', 'credit', 1110.00),
        ], $this->opts('03-10'));
        $this->markPaid($first, '03-10');

        $second = $this->invoice($client, 'invoice', $proforma, 500.00, '03-20');
        $this->posting->postDocument($this->supplierId, 'invoice', $second, [
            self::l('311', 'debit', 500.00),
            self::l('602', 'credit', 500.00),
        ], $this->opts('03-20'));

        $taxDocument = $this->invoice($client, 'tax_document', $proforma, 100.00, '03-05');
        $this->posting->postDocument($this->supplierId, 'invoice', $taxDocument, [
            self::l('311', 'debit', 100.00),
            self::l('343', 'credit', 100.00),
        ], $this->opts('03-05'));
        $this->markPaid($taxDocument, '03-05');

        $takenOver = $this->invoice($client, 'invoice', null, 1210.00, '04-01');
        $this->postInGroup('invoice', $takenOver, [
            [self::l('311', 'debit', 1000.00), self::l('602', 'credit', 1000.00)],
            [self::l('311', 'debit', 210.00), self::l('343', 'credit', 210.00)],
        ], '04-01');
        $tx = $this->bankInvoicePayment($takenOver, 1210.00, '04-15');
        $this->posting->postDocument($this->supplierId, 'bank', $tx, [
            self::l('221', 'debit', 1210.00),
            self::l('311', 'credit', 1210.00),
        ], $this->opts('04-15'));
        $this->markPaid($takenOver, '04-15');

        $unpaid = $this->invoice($client, 'invoice', null, 968.00, '05-01');
        $this->postInGroup('invoice', $unpaid, [
            [self::l('311', 'debit', 800.00), self::l('602', 'credit', 800.00)],
            [self::l('311', 'debit', 168.00), self::l('343', 'credit', 168.00)],
        ], '05-01');
        $this->markPaid($unpaid, '05-20');

        $rows = (new ClosingRepository($this->db))->paidInvoicesOpenSaldo($this->supplierId, self::YEAR . '-12-31');

        self::assertSame([[
            'id' => $unpaid,
            'booked' => 968.0,
            'settled' => 0.0,
            'saldo' => 968.0,
            'issues' => ['marked_paid_unposted'],
        ]], array_map(static fn (array $r): array => [
            'id' => $r['id'],
            'booked' => $r['booked'],
            'settled' => $r['settled'],
            'saldo' => $r['saldo'],
            'issues' => $r['issues'],
        ], $rows));
    }

    /**
     * Plán nesmí hledat konečnou fakturu závislým poddotazem za každý řádek: s neaktuálními
     * statistikami ho optimizer vedl po indexu jen na firmu a dotaz prošel všechny faktury
     * firmy za každou úhradu.
     */
    public function testPlanHasNoDependentFinalInvoiceLookup(): void
    {
        $captured = null;
        $pdo = $this->db->pdo();
        $statementClass = $pdo->getAttribute(PDO::ATTR_STATEMENT_CLASS);
        $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [ClosingPaidSaldoCaptureStatement::class, [
            static function (string $sql, array $params) use (&$captured): void {
                if (str_contains($sql, 'WITH booked AS')) {
                    $captured = [$sql, $params];
                }
            },
        ]]);
        try {
            (new ClosingRepository($this->db))->paidInvoicesOpenSaldo($this->supplierId, self::YEAR . '-12-31');
        } finally {
            $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, $statementClass);
        }

        self::assertNotNull($captured);
        [$sql, $params] = $captured;
        $explain = $pdo->prepare(preg_replace('/\bFOR WITH booked AS\b/', 'FOR EXPLAIN WITH booked AS', $sql, 1));
        $explain->execute($params);
        $dependentInvoices = array_filter(
            $explain->fetchAll(PDO::FETCH_ASSOC),
            static fn (array $row): bool => $row['select_type'] === 'DEPENDENT SUBQUERY' && $row['table'] === 'fin',
        );
        self::assertSame([], array_values($dependentInvoices));
    }

    private function markPaid(int $invoiceId, string $date): void
    {
        $this->db->pdo()->prepare("UPDATE invoices SET status = 'paid', paid_total = total_with_vat, paid_at = ? WHERE id = ?")
            ->execute([self::YEAR . '-' . $date, $invoiceId]);
    }

    /** @param list<list<array{account_code:string, side:string, amount:float}>> $entries */
    private function postInGroup(string $docType, int $docId, array $entries, string $date): void
    {
        $ids = [];
        foreach ($entries as $i => $lines) {
            $ids[] = $this->posting->postDocument($this->supplierId, $i === 0 ? $docType : 'manual', $i === 0 ? $docId : null, $lines, $this->opts($date));
        }
        (new DocumentPostingGroup($this->db))->attach($this->supplierId, $docType, $docId, $ids);
        foreach ($ids as $id) {
            $this->links->add($id, $this->supplierId, $docType, $docId, null, $this->userId);
        }
    }

    /** @return array<string,mixed> */
    private function opts(string $date): array
    {
        return ['entry_date' => self::YEAR . '-' . $date, 'posted_by' => $this->userId, 'user_id' => $this->userId];
    }

    private function client(string $name): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, main_email, currency_default_id)
             VALUES (?, ?, "Ulice 1", "Praha", "11000", ?, ?, ?)'
        )->execute([$this->supplierId, $name, $this->czId, 'c' . uniqid() . '@example.com', $this->currencyId]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function invoice(int $clientId, string $type, ?int $parentId, float $total, string $issue): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO invoices (supplier_id, varsymbol, client_id, invoice_type, parent_invoice_id, issue_date, due_date,
                                   currency_id, created_by, total_with_vat, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "issued")'
        )->execute([
            $this->supplierId, (string) random_int(1000000000, 1999999999), $clientId, $type, $parentId,
            self::YEAR . '-' . $issue, self::YEAR . '-' . $issue, $this->currencyId, $this->userId, $total,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function bankInvoicePayment(int $invoiceId, float $amount, string $paidOn): int
    {
        $pdo = $this->db->pdo();
        $date = self::YEAR . '-' . $paidOn;
        $pdo->prepare(
            'INSERT INTO bank_statements (supplier_id, source, file_name, file_hash, account_number, bank_code, statement_date)
             VALUES (?, "gpc", "k3-proformy.gpc", ?, "1000000005", "0100", ?)'
        )->execute([$this->supplierId, hash('sha256', 'k3-proformy-' . random_int(1, PHP_INT_MAX)), $date]);
        $statementId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO bank_transactions (statement_id, source, posted_at, amount, currency, counterparty_name, description, match_status)
             VALUES (?, "statement", ?, ?, "CZK", "Partner", "Platba", "manual")'
        )->execute([$statementId, $date, $amount]);
        $tx = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO invoice_payments (supplier_id, invoice_id, paid_on, amount, currency, source, bank_transaction_id)
             VALUES (?, ?, ?, ?, "CZK", "bank", ?)'
        )->execute([$this->supplierId, $invoiceId, $date, $amount, $tx]);
        return $tx;
    }

    /** @return array{account_code:string, side:string, amount:float} */
    private static function l(string $code, string $side, float $amount): array
    {
        return ['account_code' => $code, 'side' => $side, 'amount' => $amount];
    }
}

final class ClosingPaidSaldoCaptureStatement extends \PDOStatement
{
    protected function __construct(private readonly \Closure $capture) {}

    public function execute(?array $params = null): bool
    {
        ($this->capture)($this->queryString, $params ?? []);

        return parent::execute($params);
    }
}
