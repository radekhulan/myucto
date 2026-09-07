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
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * BUG 0 (tichá ztráta dat): GPC parser neplnil `bank_ref`, takže identita pohybu se
 * skládala z VS/KS/SS/protiúčtu/názvu/popisu. Tři legitimní platby téže částky, dne
 * a VS (opakované mikroplatby, poplatky) proto dostaly identický otisk a druhá i třetí
 * se tiše zahodily — v evidenci chyběly bez chyby i bez hlášení.
 *
 * Test drží OBA směry:
 *   - legitimní pohyby se nesmí ztratit (ani když banka ID pohybu nepošle),
 *   - skutečná duplicita (týž soubor, překrývající se výpis, doimport po upgradu)
 *     se nesmí založit podruhé.
 *
 * Izolace: syntetické účty 99905623xx, rok 2099, vlastní currencies řádky; vše se
 * v tearDown maže. Importér se skládá ručně BEZ BankPostingService a bez registru
 * vlastních účtů, aby test nezasahoval do účetnictví testovacího tenanta.
 */
#[Group('integration')]
final class StatementImporterDuplicateTest extends TestCase
{
    private Connection $db;
    private StatementImporter $importer;
    private int $supplierId = 0;

    /** @var int[] */
    private array $statementIds = [];
    /** @var int[] */
    private array $currencyIds = [];

    private const FILE_NAME = 'TEST-BUG0.gpc';
    /** VS mimo jakoukoli reálnou řadu — import spouští matcher, nesmí trefit ostrý doklad. */
    private const VS = '2099009991';

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
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
        $this->cleanupLeftovers();
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $pdo = $this->db->pdo();
        foreach ($this->statementIds as $id) {
            $pdo->prepare('DELETE FROM bank_transaction_imports WHERE original_statement_id = ? OR statement_id = ?')->execute([$id, $id]);
            $pdo->prepare('DELETE FROM bank_transactions WHERE statement_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM bank_statements WHERE id = ?')->execute([$id]);
        }
        foreach ($this->currencyIds as $id) {
            $pdo->prepare('DELETE FROM currencies WHERE id = ?')->execute([$id]);
        }
        $this->db->close();
    }

    /**
     * Jádro nálezu: tři platby stejné částky, dne, VS i popisu, každá s VLASTNÍM ID
     * pohybu od banky. Před opravou se založila jediná — zbylé dvě zmizely bez hlášení.
     */
    public function testIdenticalMovementsWithDistinctBankIdsAreAllImported(): void
    {
        $account = '9990562350';
        $currencyId = $this->registerCurrency($account, '2010');

        $r = $this->import($this->gpc($account, ['26001', '26002', '26003'], stmtNo: '021'), $currencyId);

        self::assertSame(3, $r['transactions'], 'Tři legitimní pohyby se nesmí slít do jednoho.');
        self::assertSame(0, $r['skipped_duplicates']);
        self::assertSame([], $r['warnings']);
        self::assertSame(
            ['26001', '26002', '26003'],
            $this->bankRefs($r['statement_id']),
            'ID pohybu z GPC musí skončit v bank_ref (do teď tam byl NULL u všech pohybů).',
        );
    }

    /**
     * Banka ID pohybu neposlala (pole čísla dokladu je prázdné) — identita se skládá
     * z náhradních polí. Ani tady se legitimní pohyby nesmí ztratit: rozliší je pořadí
     * výskytu v souboru.
     */
    public function testIdenticalMovementsWithoutBankIdsAreAllImported(): void
    {
        $account = '9990562351';
        $currencyId = $this->registerCurrency($account, '2010');

        $r = $this->import($this->gpc($account, ['', '', ''], stmtNo: '022'), $currencyId);

        self::assertSame(3, $r['transactions'], 'Bez ID pohybu musí pohyby rozlišit pořadí v souboru.');
        self::assertSame([null, null, null], $this->bankRefs($r['statement_id']));
    }

    /**
     * Opačný směr: překrývající se výpis nese TYTÉŽ pohyby. Ty se založit podruhé nesmí,
     * jinak by z opravy tiché ztráty dat bylo tiché zdvojení. Rozdíl proti počtu řádků
     * v souboru se hlásí jako varování, ne mlčky.
     */
    public function testOverlappingStatementDoesNotDuplicateAndWarns(): void
    {
        $account = '9990562352';
        $currencyId = $this->registerCurrency($account, '2010');

        $first = $this->import($this->gpc($account, ['26011', '26012', '26013'], stmtNo: '023'), $currencyId);
        $second = $this->import($this->gpc($account, ['26011', '26012', '26013'], stmtNo: '024'), $currencyId);

        self::assertSame(3, $first['transactions']);
        self::assertSame(0, $second['transactions'], 'Týž pohyb z překrývajícího se výpisu se nesmí založit podruhé.');
        self::assertSame(3, $second['skipped_duplicates']);
        self::assertSame(3, $second['parsed_transactions']);
        self::assertSame('transactions_skipped_as_duplicate', $second['warnings'][0]['code'] ?? null,
            'Rozdíl mezi počtem řádků v souboru a počtem založených pohybů musí být hlášený.');
        self::assertSame(3, $this->transactionCount($account));
    }

    /**
     * Upgrade existující instalace: pohyb naimportovaný PŘED opravou nemá bank_ref a nese
     * otisk z náhradní identity. Když pak dorazí překrývající se výpis, kde už ID pohybu je,
     * nesmí se ten pohyb založit znovu — a druhá, dosud ztracená platba se naopak založit MUSÍ.
     */
    public function testHistoricalRowWithoutBankReferenceIsNotDuplicatedButLostMovementIsRecovered(): void
    {
        $account = '9990562353';
        $currencyId = $this->registerCurrency($account, '2010');

        // Stav „před opravou": banka ID pohybu neposlala, v evidenci je jediný pohyb.
        $legacy = $this->import($this->gpc($account, [''], stmtNo: '025'), $currencyId);
        self::assertSame(1, $legacy['transactions']);

        // Výpis po upgradu: tentýž pohyb + druhá platba, kterou předchozí import zahodil.
        $r = $this->import($this->gpc($account, ['26021', '26022'], stmtNo: '026'), $currencyId);

        self::assertSame(1, $r['transactions'], 'Historický pohyb bez bank_ref se nesmí založit podruhé.');
        self::assertSame(1, $r['skipped_duplicates']);
        self::assertSame(2, $this->transactionCount($account), 'Druhá, dosud ztracená platba se musí doplnit.');
    }

    /** Nahrání téhož souboru podruhé zůstává duplicitou na úrovni výpisu (SHA-256 file_hash). */
    public function testSameFileImportedTwiceStaysStatementLevelDuplicate(): void
    {
        $account = '9990562354';
        $currencyId = $this->registerCurrency($account, '2010');
        $content = $this->gpc($account, ['26031', '26032'], stmtNo: '027');

        $first = $this->import($content, $currencyId);
        $second = $this->importer->import($content, self::FILE_NAME, null, $currencyId);

        self::assertSame(2, $first['transactions']);
        self::assertTrue($second['duplicate']);
        self::assertSame(0, $second['transactions']);
        self::assertSame($first['statement_id'], $second['statement_id']);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    public function testImportLockExcludesSecondConnectionUntilMatchingFinishes(): void
    {
        $second = Connection::withoutSharedTestConnection(static fn (): PDO => Bootstrap::buildContainer()->get(Connection::class)->pdo());
        $name = \MyInvoice\Infrastructure\Database\NamedLockName::for($this->db, 'bank-import', $this->supplierId);
        $matcher = $this->createMock(StatementMatcher::class);
        $matcher->expects(self::once())->method('matchBatch')->willReturnCallback(function (array $ids) use ($second, $name): array {
            self::assertCount(1, $ids);
            self::assertFalse($this->db->pdo()->inTransaction());
            $attempt = $second->prepare('SELECT GET_LOCK(?, 0)');
            $attempt->execute([$name]);
            self::assertSame(0, (int) $attempt->fetchColumn(), 'Import serialization must outlive the storage transaction.');
            return [];
        });
        $reconciler = $this->createStub(EmailNoticeReconciler::class);
        $reconciler->method('takeOverFromEmailNotice')->willReturn(null);
        $this->importer = new StatementImporter($this->db, new GpcParser(), $matcher, $reconciler);
        try {
            $currencyId = $this->registerCurrency('1000000005', '2010');
            $this->import($this->gpc('1000000005', ['1001'], '097'), $currencyId);
            $attempt = $second->prepare('SELECT GET_LOCK(?, 0)');
            $attempt->execute([$name]);
            self::assertSame(1, (int) $attempt->fetchColumn(), 'Completed import must release its lock.');
        } finally {
            $second->prepare('SELECT RELEASE_LOCK(?)')->execute([$name]);
        }
    }

    /** @return array{statement_id:int, transactions:int, matched:int, duplicate:bool, parsed_transactions:int, skipped_duplicates:int, warnings:list<array<string,mixed>>} */
    private function import(string $content, ?int $currencyId): array
    {
        $r = $this->importer->import($content, self::FILE_NAME, null, $currencyId);
        self::assertFalse($r['duplicate'], 'Testovací GPC nesmí být dedupnuté na úrovni výpisu.');
        $this->statementIds[] = $r['statement_id'];
        return $r;
    }

    /** @return list<?string> */
    private function bankRefs(int $statementId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT bank_ref FROM bank_transactions WHERE statement_id = ? ORDER BY id');
        $stmt->execute([$statementId]);
        return array_map(
            static fn (mixed $v): ?string => $v === null ? null : (string) $v,
            $stmt->fetchAll(PDO::FETCH_COLUMN),
        );
    }

    private function transactionCount(string $account): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM bank_transactions bt
               JOIN bank_statements bs ON bs.id = bt.statement_id
              WHERE bs.account_number = ?'
        );
        $stmt->execute([str_pad($account, 16, '0', STR_PAD_LEFT)]);
        return (int) $stmt->fetchColumn();
    }

    private function registerCurrency(string $account, string $bankCode): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO currencies
                (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default,
                 account_number, bank_code)
             VALUES (?, "CZK", ?, "CZK", "CZK", "CZK", 2, 0, 0, ?, ?)'
        )->execute([$this->supplierId, 'TEST BUG0 /' . $bankCode . ' ' . $account, $account, $bankCode]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->currencyIds[] = $id;
        return $id;
    }

    private function cleanupLeftovers(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('DELETE bti FROM bank_transaction_imports bti JOIN bank_statements bs ON bs.id = bti.original_statement_id WHERE bs.file_name = ?')->execute([self::FILE_NAME]);
        $pdo->prepare(
            'DELETE bt FROM bank_transactions bt JOIN bank_statements bs ON bs.id = bt.statement_id
              WHERE bs.file_name = ?'
        )->execute([self::FILE_NAME]);
        $pdo->prepare('DELETE FROM bank_statements WHERE file_name = ?')->execute([self::FILE_NAME]);
        $pdo->prepare("DELETE FROM currencies WHERE label LIKE 'TEST BUG0 /%'")->execute();
    }

    /**
     * Minimální validní GPC: 074 hlavička + N × 075 pohyb, které se liší JEN číslem
     * dokladu (ID pohybu). Prázdné `$docNumbers[i]` = banka ID pohybu neposlala.
     * Layout přesně dle {@see GpcParser} (fixed-width). Data jsou smyšlená.
     *
     * @param list<string> $docNumbers
     */
    private function gpc(string $account, array $docNumbers, string $stmtNo): string
    {
        $acc16 = str_pad($account, 16, '0', STR_PAD_LEFT);
        $header = '074' . $acc16
            . str_pad('TEST UCET BUG0', 20)
            . '010199'                                          // old balance date 1.1.2099
            . str_pad('0', 14, '0', STR_PAD_LEFT) . '+'
            . str_pad('37335', 14, '0', STR_PAD_LEFT) . '+'
            . str_pad('0', 14, '0', STR_PAD_LEFT) . '+'
            . str_pad('37335', 14, '0', STR_PAD_LEFT) . '+'
            . str_pad($stmtNo, 3, '0', STR_PAD_LEFT)            // salt pro odlišení file_hash
            . '310199';                                         // statement date 31.1.2099

        $lines = [$header];
        foreach ($docNumbers as $doc) {
            $lines[] = '075' . $acc16
                . str_pad('', 16, '0')                          // protiúčet (mikroplatba bez protistrany)
                . str_pad($doc, 13, '0', STR_PAD_LEFT)          // číslo dokladu = ID pohybu
                . str_pad('12445', 12, '0', STR_PAD_LEFT)       // 124,45 — u všech pohybů shodná
                . '2'                                           // credit
                . str_pad(self::VS, 10, '0', STR_PAD_LEFT)
                . '00'
                . '0000'
                . '0000'
                . str_pad('', 10, '0')
                . '150199'
                . str_pad('OPAKOVANA PLATBA', 20)               // stejný popis u všech
                . '00203'
                . '150199';
        }

        return implode("\r\n", $lines) . "\r\n";
    }
}
