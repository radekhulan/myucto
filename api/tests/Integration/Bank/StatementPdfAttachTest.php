<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Bank;

use MyInvoice\Action\Bank\BankStatementAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * PDF výpis přetažený k už naimportovanému GPC se má PŘILOŽIT k němu, ne založit
 * druhý výpis téhož období. Dřív se dedup opíral výhradně o `file_hash` celého
 * souboru — GPC a PDF ho mají z definice jiný, takže vznikl prázdný duplikát
 * (transakce odfiltroval fingerprint) a párování se rozešlo mezi dva doklady.
 *
 * Testuje se rozhodovací logika `BankStatementAction::findStatementForPdfAttachment()`
 * přes reflexi: HTTP vrstva nad ní jen předává výstup PDF parseru, a syntetizovat
 * bank-specifické PDF s textovou vrstvou jen kvůli tomuhle rozhodnutí by testovalo
 * parser, ne párování výpisů.
 *
 * Izolace: vlastní měnový účet s testovacím číslem, rok 2099, vše se v tearDown maže.
 */
#[Group('integration')]
final class StatementPdfAttachTest extends TestCase
{
    private Connection $db;
    private BankStatementAction $action;
    private ReflectionMethod $find;
    private int $supplierId = 0;
    private int $currencyId = 0;
    private string $account = '9988776655';

    /** @var int[] */
    private array $statementIds = [];

    private const FILE_MARKER = '__pdfattach__';
    private const STATEMENT_DATE = '2099-07-31';

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db = $c->get(Connection::class);
            $this->action = $c->get(BankStatementAction::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $this->supplierId = (int) ($this->db->pdo()->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0) {
            $this->markTestSkipped('Chybí supplier v DB.');
        }

        $this->cleanup();
        $this->db->pdo()->prepare(
            'INSERT INTO currencies
                (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default,
                 account_number, bank_code, iban)
             VALUES (?, ?, ?, ?, ?, ?, 2, 0, 0, ?, ?, NULL)'
        )->execute([$this->supplierId, 'CZK', self::FILE_MARKER, 'CZK', 'CZK', 'CZK', $this->account, '2250']);
        $this->currencyId = (int) $this->db->pdo()->lastInsertId();

        $this->find = new ReflectionMethod($this->action, 'findStatementForPdfAttachment');
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->cleanup();
            $this->db->close();
        }
    }

    private function cleanup(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('DELETE bti FROM bank_transaction_imports bti JOIN bank_statements bs ON bs.id = bti.original_statement_id WHERE bs.file_name LIKE ?')->execute(['%' . self::FILE_MARKER . '%']);
        $pdo->prepare('DELETE FROM bank_statements WHERE file_name LIKE ?')->execute(['%' . self::FILE_MARKER . '%']);
        $pdo->prepare('DELETE FROM currencies WHERE supplier_id = ? AND label = ?')
            ->execute([$this->supplierId, self::FILE_MARKER]);
        $this->statementIds = [];
    }

    /**
     * Naimportovaný GPC výpis s pohyby.
     *
     * @param list<array{0:string,1:float}> $transactions [datum, částka]
     */
    private function insertGpcStatement(
        array $transactions,
        string $statementNumber = '007',
        ?string $account = null,
        string $date = self::STATEMENT_DATE,
        bool $withPdf = false,
    ): int {
        $pdo = $this->db->pdo();
        $tag = self::FILE_MARKER . uniqid('', true);
        $pdo->prepare(
            'INSERT INTO bank_statements
                (supplier_id, source, file_name, file_hash, account_number, bank_code, currency,
                 statement_number, statement_date, transaction_count, pdf_content, pdf_hash)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $this->supplierId, 'gpc', $tag . '.gpc', hash('sha256', $tag),
            $account ?? $this->account, '2250', 'CZK',
            $statementNumber, $date, count($transactions),
            $withPdf ? 'x' : null, $withPdf ? hash('sha256', $tag . 'pdf') : null,
        ]);
        $id = (int) $pdo->lastInsertId();
        $this->statementIds[] = $id;

        $tx = $pdo->prepare(
            "INSERT INTO bank_transactions (statement_id, source, posted_at, amount, currency)
             VALUES (?, 'statement', ?, ?, 'CZK')"
        );
        foreach ($transactions as [$postedAt, $amount]) {
            $tx->execute([$id, $postedAt, $amount]);
        }
        return $id;
    }

    /**
     * Výstup bank-specifického PDF parseru (tvar `['header'=>…, 'transactions'=>…]`).
     *
     * @param list<array{0:string,1:float}> $transactions
     * @return array{header:array<string,mixed>,transactions:list<array<string,mixed>>}
     */
    private function parsedPdf(array $transactions, string $statementNumber = '7', ?string $account = null, string $date = self::STATEMENT_DATE): array
    {
        return [
            'header' => [
                'account_number'   => $account ?? $this->account,
                'statement_number' => $statementNumber,
                'statement_date'   => $date,
            ],
            'transactions' => array_map(
                static fn (array $t): array => ['posted_at' => $t[0], 'amount' => $t[1]],
                $transactions,
            ),
        ];
    }

    /** @param array{header:array<string,mixed>,transactions:list<array<string,mixed>>} $parsed */
    private function target(array $parsed): ?int
    {
        return $this->find->invoke(
            $this->action,
            $this->supplierId,
            $parsed,
            $this->currencyId,
            (string) $parsed['header']['account_number'],
        );
    }

    private const TX = [['2099-07-02', -4446.72], ['2099-07-07', -773.50], ['2099-07-13', 27225.00]];

    public function testPdfAttachesToMatchingGpcStatement(): void
    {
        $gpcId = $this->insertGpcStatement(self::TX);

        self::assertSame($gpcId, $this->target($this->parsedPdf(self::TX)));
    }

    public function testPdfAttachesToApiStatementWithMatchingNumberAndMovements(): void
    {
        $transactions = [['2099-07-02', 100.0]];
        $id = $this->insertGpcStatement($transactions);
        $this->db->pdo()->prepare("UPDATE bank_statements SET source='bank_api' WHERE id=?")->execute([$id]);
        self::assertSame($id, $this->target($this->parsedPdf($transactions)));
    }

    public function testPdfPrefersGpcEvidenceWhenApiAndGpcShareCanonicalMovements(): void
    {
        $apiId = $this->insertGpcStatement(self::TX);
        $pdo = $this->db->pdo();
        $pdo->prepare("UPDATE bank_statements SET source = 'bank_api' WHERE id = ?")->execute([$apiId]);
        $gpcId = $this->insertGpcStatement([]);
        $pdo->prepare('INSERT INTO bank_transaction_imports (statement_id, bank_transaction_id, import_fingerprint, supplier_id, original_statement_id)
            SELECT ?, id, SHA2(CONCAT(?, id), 256), ?, statement_id FROM bank_transactions WHERE statement_id = ?')
            ->execute([$gpcId, 'synthetic-pdf-alias', $this->supplierId, $apiId]);
        self::assertSame($gpcId, $this->target($this->parsedPdf(self::TX)));
    }

    public function testCompleteGpcEvidenceWinsOverPartiallyCoveredApi(): void
    {
        $transactions = [['2099-07-02', 1.0], ['2099-07-03', 2.0], ['2099-07-04', 3.0], ['2099-07-05', 4.0], ['2099-07-06', 5.0]];
        $apiId = $this->insertGpcStatement(array_slice($transactions, 0, 4));
        $pdo = $this->db->pdo();
        $pdo->prepare("UPDATE bank_statements SET source = 'bank_api' WHERE id = ?")->execute([$apiId]);
        $gpcId = $this->insertGpcStatement(array_slice($transactions, 4));
        $pdo->prepare('INSERT INTO bank_transaction_imports (statement_id, bank_transaction_id, import_fingerprint, supplier_id, original_statement_id)
            SELECT ?, id, SHA2(CONCAT(?, id), 256), ?, statement_id FROM bank_transactions WHERE statement_id = ?')
            ->execute([$gpcId, 'synthetic-pdf-superset', $this->supplierId, $apiId]);
        self::assertSame($gpcId, $this->target($this->parsedPdf($transactions)));
    }

    /** Číslo výpisu je v GPC nulami vycpané („007"), v PDF holé („7") — musí sednout. */
    public function testStatementNumberPaddingIsIgnored(): void
    {
        $gpcId = $this->insertGpcStatement(self::TX, statementNumber: '007');

        self::assertSame($gpcId, $this->target($this->parsedPdf(self::TX, statementNumber: '7')));
    }

    public function testApiIbanMatchesDomesticPdfAccount(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE currencies SET account_number = ?, bank_code = ? WHERE id = ?')
            ->execute(['1000000005', '0800', $this->currencyId]);
        $apiId = $this->insertGpcStatement(self::TX, account: 'CZ7508000000001000000005');
        $pdo->prepare("UPDATE bank_statements SET source = 'bank_api', bank_code = '0800' WHERE id = ?")->execute([$apiId]);
        self::assertSame($apiId, $this->target($this->parsedPdf(self::TX, account: '1000000005')));
    }

    /** Prázdný měsíc (banka pošle výpis bez pohybů) — přiložit taky. */
    public function testEmptyStatementAttaches(): void
    {
        $gpcId = $this->insertGpcStatement([]);

        self::assertSame($gpcId, $this->target($this->parsedPdf([])));
    }

    /** Jiný účet = jiný výpis, i když datum i číslo sedí. */
    public function testDifferentAccountDoesNotAttach(): void
    {
        $this->insertGpcStatement(self::TX, account: '9911223344');

        self::assertNull($this->target($this->parsedPdf(self::TX)));
    }

    /** Jiné číslo výpisu → nepřilepovat. */
    public function testDifferentStatementNumberDoesNotAttach(): void
    {
        $this->insertGpcStatement(self::TX, statementNumber: '006');

        self::assertNull($this->target($this->parsedPdf(self::TX, statementNumber: '7')));
    }

    /** Existující PDF nikdy nepřepisujeme. */
    public function testStatementWithPdfIsNotReused(): void
    {
        $this->insertGpcStatement(self::TX, withPdf: true);

        self::assertNull($this->target($this->parsedPdf(self::TX)));
    }

    /**
     * Hlavička sedí, ale pohyby ne — to není tentýž výpis (přejmenovaný soubor,
     * jiný účet u banky s totožnou numerací). Radši nový výpis než špatná příloha.
     */
    public function testMismatchedTransactionsDoNotAttach(): void
    {
        $this->insertGpcStatement(self::TX);

        $other = [['2099-07-02', -1.00], ['2099-07-07', -2.00], ['2099-07-13', -3.00]];
        self::assertNull($this->target($this->parsedPdf($other)));
    }

    /**
     * Částečné pokrytí nad prahem projde — GPC mohl pohyb odfiltrovat jako duplicitní
     * vůči překrývajícímu se výpisu, PDF ho ale pořád obsahuje.
     */
    public function testPartialCoverageAboveThresholdAttaches(): void
    {
        $gpcId = $this->insertGpcStatement([
            ['2099-07-02', -4446.72], ['2099-07-07', -773.50], ['2099-07-13', 27225.00],
            ['2099-07-20', 100.00], ['2099-07-21', 200.00],
        ]);

        $pdfTx = [
            ['2099-07-02', -4446.72], ['2099-07-07', -773.50], ['2099-07-13', 27225.00],
            ['2099-07-20', 100.00], ['2099-07-29', 999.00],
        ];
        self::assertSame($gpcId, $this->target($this->parsedPdf($pdfTx)), '4 z 5 pohybů = 80 %');
    }

    /** Dva odlišní kandidáti vyžadují ruční volbu bez založení nových pohybů. */
    public function testAmbiguousCandidatesDoNotAttach(): void
    {
        $this->insertGpcStatement(self::TX);
        $this->insertGpcStatement(self::TX);

        $this->expectException(\InvalidArgumentException::class);
        $this->target($this->parsedPdf(self::TX));
    }

    /** Výpis mimo datové okno není tentýž měsíc. */
    public function testStatementOutsideDateWindowDoesNotAttach(): void
    {
        $this->insertGpcStatement(self::TX, date: '2099-06-30');

        self::assertNull($this->target($this->parsedPdf(self::TX)));
    }
}
