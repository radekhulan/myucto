<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Bank;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Bank\EmailNoticeReconciler;
use MyInvoice\Service\Bank\GpcParser;
use MyInvoice\Service\Bank\StatementImporter;
use MyInvoice\Service\Bank\StatementMatcher;
use MyInvoice\Service\Invoice\FinalFromProformaCreator;
use MyInvoice\Service\Property\Payments\BankProposalService;
use MyInvoice\Tests\Support\BankProposalFixture;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Firma bez profilu SVJ musí po zapojení návrhů úhrad jednotek importovat výpis
 * stejně: stejný výsledek, stejné pohyby a stejný počet dotazů i zápisů.
 *
 * Import výpisu potvrzuje vlastní transakci, takže test běží nad potvrzenými daty
 * syntetických účtů 99905624xx (platných podle mod 11) a po sobě je uklidí (vzor StatementImporterDuplicateTest).
 */
#[Group('integration')]
final class StatementImportPropertyProposalRegressionTest extends TestCase
{
    use BankProposalFixture;

    private const LABEL = 'TEST SVJ PROPOSAL /';
    private Connection $db;
    private int $supplierId = 0;
    /** @var list<int> */
    private array $statementIds = [];

    protected function setUp(): void
    {
        $this->db = Bootstrap::buildContainer()->get(Connection::class);
        self::assertStringEndsWith('_test', (string) $this->db->pdo()->query('SELECT DATABASE()')->fetchColumn());
        $this->supplierId = (int) $this->db->pdo()->query('SELECT s.id FROM supplier s WHERE NOT EXISTS (SELECT 1 FROM property_module_profiles p WHERE p.supplier_id = s.id) ORDER BY s.id LIMIT 1')->fetchColumn();
        if ($this->supplierId === 0) self::markTestSkipped('Chybí firma bez profilu SVJ.');
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
    }

    public function testNonPropertySupplierImportIsIdenticalWithProposalHook(): void
    {
        $plain = $this->importer(false);
        $hooked = $this->importer(true);
        // Zahřátí: první import v procesu plní mezipaměti schématu a nastavení; měří se až další.
        $warmup = $this->importer(false)->import(self::gpc('9990562424', '001', $this->payments('63')), 'svj-proposal-w.gpc', null, $this->currency('9990562424'));
        $this->statementIds[] = $warmup['statement_id'];
        $plainCurrency = $this->currency('9990562408');
        $hookedCurrency = $this->currency('9990562416');
        [$plainResult, $plainCounts] = $this->measure(fn () => $plain->import(self::gpc('9990562408', '001', $this->payments('61')), 'svj-proposal-a.gpc', null, $plainCurrency));
        [$hookedResult, $hookedCounts] = $this->measure(fn () => $hooked->import(self::gpc('9990562416', '001', $this->payments('62')), 'svj-proposal-b.gpc', null, $hookedCurrency));
        $this->statementIds = [$plainResult['statement_id'], $hookedResult['statement_id']];
        unset($plainResult['statement_id'], $hookedResult['statement_id']);
        self::assertSame($plainResult, $hookedResult);
        self::assertSame(3, $hookedResult['transactions']);
        self::assertSame($plainCounts, $hookedCounts, 'Import of a supplier without SVJ must not run any extra query.');
        self::assertSame($this->states($this->statementIds[0]), $this->states($this->statementIds[1]));
        $q = $this->db->pdo()->prepare('SELECT COUNT(*) FROM unit_payment_bank_proposals WHERE supplier_id = ?');
        $q->execute([$this->supplierId]);
        self::assertSame(0, (int) $q->fetchColumn());
    }

    private function importer(bool $withProposals): StatementImporter
    {
        $container = Bootstrap::buildContainer();
        $container->set(Connection::class, $this->db);
        $matcher = new StatementMatcher($this->db, $container->get(FinalFromProformaCreator::class), null);
        return new StatementImporter($this->db, new GpcParser(), $matcher, $container->get(EmailNoticeReconciler::class),
            propertyProposals: $withProposals ? $container->get(BankProposalService::class) : null);
    }

    /** @return list<array{amount_cents:string,vs:string,ref:string}> */
    private function payments(string $prefix): array
    {
        return [['amount_cents' => '12500', 'vs' => '2099009981', 'ref' => $prefix . '001'],
            ['amount_cents' => '30000', 'vs' => '2099009982', 'ref' => $prefix . '002'],
            ['amount_cents' => '4500', 'vs' => '', 'ref' => $prefix . '003']];
    }

    /** @return array{0:array,1:array<string,int>} */
    private function measure(callable $work): array
    {
        $names = ['Com_select', 'Com_insert', 'Com_update', 'Com_delete'];
        $read = function () use ($names): array {
            $out = [];
            foreach ($names as $name) $out[$name] = (int) $this->db->pdo()->query("SHOW SESSION STATUS LIKE '$name'")->fetch(PDO::FETCH_NUM)[1];
            return $out;
        };
        $before = $read();
        $result = $work();
        $after = $read();
        return [$result, array_map(static fn (int $a, int $b): int => $a - $b, $after, $before)];
    }

    private function states(int $statementId): array
    {
        $q = $this->db->pdo()->prepare('SELECT amount, variable_symbol, match_status, match_reason FROM bank_transactions WHERE statement_id = ? ORDER BY id');
        $q->execute([$statementId]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    private function currency(string $account): int
    {
        $this->db->pdo()->prepare('INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default, account_number, bank_code)
            VALUES (?, "CZK", ?, "CZK", "CZK", "CZK", 2, 0, 0, ?, "2010")')->execute([$this->supplierId, self::LABEL . $account, $account]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function cleanup(): void
    {
        $pdo = $this->db->pdo();
        $ids = array_map('intval', $pdo->query("SELECT id FROM bank_statements WHERE account_number LIKE '%99905624%'")->fetchAll(PDO::FETCH_COLUMN));
        foreach ($ids as $id) {
            $pdo->prepare('DELETE FROM bank_api_evidence_months WHERE evidence_statement_id = ? OR monthly_statement_id = ?')->execute([$id, $id]);
            $pdo->prepare('DELETE FROM bank_api_months WHERE statement_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM bank_transaction_imports WHERE original_statement_id = ? OR statement_id = ?')->execute([$id, $id]);
        }
        foreach ($ids as $id) {
            $pdo->prepare('DELETE FROM bank_transactions WHERE statement_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM bank_statements WHERE id = ?')->execute([$id]);
        }
        $pdo->prepare('DELETE FROM currencies WHERE label LIKE ?')->execute([self::LABEL . '%']);
    }
}
