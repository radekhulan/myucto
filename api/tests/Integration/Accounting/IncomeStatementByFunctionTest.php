<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\StatementDefinitionRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Accounting\Reports\FinancialStatementService;
use MyInvoice\Service\Accounting\Reports\ReportException;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * VZZ v ÚČELOVÉM členění — vyhláška 500/2002 Sb., příloha č. 2 část II (§ 39b).
 *
 * Matice účetnictví to vedla jako CHYBÍ: systém uměl jen druhové členění, takže účetní
 * jednotka, která si zvolí účelové, výkaz sestavit nemohla vůbec.
 *
 * ── Proč je přiřazení per firma ─────────────────────────────────────────────────────
 * Druhové členění jde odvodit z čísla účtu (materiál, mzdy, odpisy), proto je jeho mapa
 * globální. Účelové člení náklady podle FUNKCE — náklady prodeje / odbytové / správní
 * režie — a tu číslo syntetického účtu nenese: tytéž Služby (518) jsou u jedné firmy
 * náklad prodeje a u druhé správní režie. Přiřazení proto pochází z per-firma mapy
 * a dělí se typicky přes analytiky, což {@see testAnalyticAccountsSplitOneSyntheticAcrossFunctions()}
 * ověřuje.
 *
 * ── Proč se neúplná mapa NESESTAVÍ ──────────────────────────────────────────────────
 * Nepřiřazený náklad by nespadl do žádného řádku, tiše z výkazu vypadl a hrubý zisk
 * i výsledek hospodaření by vyšly VYŠŠÍ, než jsou — na výkazu, který se podává.
 * Tvrdá chyba s výčtem účtů je jediná poctivá odpověď; viz
 * {@see testIncompleteFunctionMapRefusesToBuild()}.
 */
#[Group('integration')]
final class IncomeStatementByFunctionTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const YEAR = 2090;
    private const STARTS_ON = self::YEAR . '-01-01';
    private const ENDS_ON = self::YEAR . '-12-31';

    private Connection $db;
    private FinancialStatementService $statements;
    private StatementDefinitionRepository $definitions;
    private PostingService $posting;
    private AccountingPeriodRepository $periods;

    private int $supplierId = 0;
    private int $periodId = 0;
    private int $userId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildContainer();
            $this->db          = $c->get(Connection::class);
            $this->statements  = $c->get(FinancialStatementService::class);
            $this->definitions = $c->get(StatementDefinitionRepository::class);
            $this->posting     = $c->get(PostingService::class);
            $this->periods     = $c->get(AccountingPeriodRepository::class);
            $seeder            = $c->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        if ($this->definitions->findVersion(FinancialStatementService::TYPE_PURPOSE, self::ENDS_ON) === null) {
            $this->markTestSkipped('Migrace 1161 neproběhla.');
        }

        $pdo = $this->db->pdo();
        $source = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($source === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí supplier / user.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);
        $seeder->seedForSupplier($this->supplierId);
        $this->periodId = $this->periods->create($this->supplierId, self::YEAR, self::STARTS_ON, self::ENDS_ON);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
    }

    /**
     * Základní scénář: tržby 1 000 000, náklady rozdělené mezi tři funkce.
     * Ověřuje řádky A./B./C., hrubý zisk i provozní výsledek.
     */
    public function testCostsLandOnTheirFunctionRows(): void
    {
        $this->revenue('602', 1_000_000.00);
        $this->expense('501', 400_000.00);   // náklady prodeje
        $this->expense('518', 150_000.00);   // odbytové
        $this->expense('521', 250_000.00);   // správní režie
        $this->assign(['501' => 'cost_of_sales', '518' => 'distribution', '521' => 'administration']);

        $rows = $this->rowsByCode($this->statements->incomeStatementByFunction(
            $this->supplierId,
            $this->periodId,
            self::ENDS_ON,
            'full',
        ));

        self::assertEqualsWithDelta(1_000_000.00, $rows['I.']['amount'], 0.01, 'Tržby.');
        self::assertEqualsWithDelta(400_000.00, $rows['A.']['amount'], 0.01, 'Náklady prodeje.');
        self::assertEqualsWithDelta(600_000.00, $rows['HZ']['amount'], 0.01, 'Hrubý zisk = tržby − náklady prodeje.');
        self::assertEqualsWithDelta(150_000.00, $rows['B.']['amount'], 0.01, 'Odbytové náklady.');
        self::assertEqualsWithDelta(250_000.00, $rows['C.']['amount'], 0.01, 'Správní režie.');
        self::assertEqualsWithDelta(200_000.00, $rows['PVH']['amount'], 0.01, 'Provozní VH.');
    }

    /**
     * Výsledek hospodaření musí vyjít STEJNĚ jako v druhovém členění. Členění mění jen
     * pohled na náklady, ne jejich celkovou výši — kdyby se lišil, je jedna z variant
     * špatně a nikdo by nevěděl která.
     */
    public function testProfitMatchesTheStandardIncomeStatement(): void
    {
        $this->revenue('602', 800_000.00);
        $this->expense('501', 300_000.00);
        $this->expense('518', 120_000.00);
        $this->expense('521', 180_000.00);
        $this->assign(['501' => 'cost_of_sales', '518' => 'distribution', '521' => 'administration']);

        $byNature  = $this->statements->incomeStatement($this->supplierId, $this->periodId, self::ENDS_ON, 'full');
        $byFunction = $this->statements->incomeStatementByFunction($this->supplierId, $this->periodId, self::ENDS_ON, 'full');

        self::assertEqualsWithDelta(
            $byNature['checks']['profit_current'],
            $byFunction['checks']['profit_current'],
            0.01,
            'VH se členěním měnit nesmí.',
        );
        self::assertEqualsWithDelta(200_000.00, $byFunction['checks']['profit_current'], 0.01);
        self::assertTrue($byFunction['checks']['profit_matches'], 'VH musí sedět na výsledkové účty.');
    }

    /**
     * Čistý obrat v účelovém členění = řádek I. (tržby z prodeje výrobků, zboží a služeb,
     * § 1a odst. 2 ZoÚ od 1. 1. 2024). Ostatní provozní výnos (II.) se do něj nepočítá.
     */
    public function testNetTurnoverSumsRevenueRows(): void
    {
        $this->revenue('602', 500_000.00);
        $this->revenue('648', 20_000.00);    // ostatní provozní výnos → II.
        $this->expense('501', 100_000.00);
        $this->assign(['501' => 'cost_of_sales']);

        $out = $this->statements->incomeStatementByFunction($this->supplierId, $this->periodId, self::ENDS_ON, 'full');

        self::assertEqualsWithDelta(500_000.00, $out['checks']['net_turnover'], 0.01);
    }

    /**
     * Neúplné přiřazení výkaz NESESTAVÍ. Kdyby prošlo, 250 000 Kč mezd by z výkazu tiše
     * zmizelo a zisk by vyšel o tu částku vyšší.
     */
    public function testIncompleteFunctionMapRefusesToBuild(): void
    {
        $this->revenue('602', 1_000_000.00);
        $this->expense('501', 400_000.00);
        $this->expense('521', 250_000.00);
        $this->assign(['501' => 'cost_of_sales']); // 521 zůstane nepřiřazený

        $this->expectException(ReportException::class);
        $this->expectExceptionMessageMatches('/521/');

        $this->statements->incomeStatementByFunction($this->supplierId, $this->periodId, self::ENDS_ON, 'full');
    }

    /**
     * Jeden syntetický účet rozdělený analytikami mezi dvě funkce — v praxi jediný
     * způsob, jak rozdělit např. Služby mezi odbyt a správu. Stojí na pravidle
     * nejdelšího prefixu, které mapa výkazů používá i pro globální mapování.
     */
    public function testAnalyticAccountsSplitOneSyntheticAcrossFunctions(): void
    {
        $this->revenue('602', 900_000.00);
        $this->expense('501.900', 300_000.00);
        $this->expense('511.100', 80_000.00);
        $this->expense('511.900', 120_000.00);
        $this->assign([
            '501.900' => 'cost_of_sales',
            '511.100' => 'distribution',
            '511.900' => 'administration',
        ]);

        $rows = $this->rowsByCode($this->statements->incomeStatementByFunction(
            $this->supplierId,
            $this->periodId,
            self::ENDS_ON,
            'full',
        ));

        self::assertEqualsWithDelta(80_000.00, $rows['B.']['amount'], 0.01, '511.100 → odbytové náklady.');
        self::assertEqualsWithDelta(120_000.00, $rows['C.']['amount'], 0.01, '511.900 → správní režie.');
    }

    /** Řádek „Ostatní finanční náklady" se zobrazuje jako I., ačkoli kód je I.f. */
    public function testFinancialCostRowDisplaysAsRomanOne(): void
    {
        $this->revenue('602', 100_000.00);
        $this->expense('501', 10_000.00);
        $this->assign(['501' => 'cost_of_sales']);

        $rows = $this->rowsByCode($this->statements->incomeStatementByFunction(
            $this->supplierId,
            $this->periodId,
            self::ENDS_ON,
            'full',
        ));

        self::assertSame('I.', $rows['I.f']['display_code']);
        self::assertSame('Ostatní finanční náklady', $rows['I.f']['label']);
    }

    /** Výkaz se hlásí vlastním typem, aby si ho volající nespletl s druhovým. */
    public function testStatementTypeIsReported(): void
    {
        $this->revenue('602', 50_000.00);
        $this->expense('501', 10_000.00);
        $this->assign(['501' => 'cost_of_sales']);

        $out = $this->statements->incomeStatementByFunction($this->supplierId, $this->periodId, self::ENDS_ON, 'full');

        self::assertSame(FinancialStatementService::TYPE_PURPOSE, $out['statement_type']);
        self::assertSame('income_statement', $this->statements
            ->incomeStatement($this->supplierId, $this->periodId, self::ENDS_ON, 'full')['statement_type']);
    }

    /**
     * Výčet účtů, kterým přiřazení chybí, NESMÍ obsahovat ty, které pokrývá globální mapa.
     *
     * Ostatní provozní náklady, celá finanční část, daň i převod podílu jsou v účelovém
     * členění přiřazené globálně a funkci se nepřiřazují. Kdyby se do seznamu dostaly,
     * posílal by uživatele přiřazovat účty, které přiřazovat nemá — a protože by je
     * přiřadil, výkaz by je započítal DVAKRÁT.
     */
    public function testUnassignedListSkipsGloballyMappedAccounts(): void
    {
        $this->revenue('602', 500_000.00);
        $this->expense('501', 100_000.00);   // funkční — přiřazení potřebuje
        $this->expense('541', 20_000.00);    // globálně → D. Ostatní provozní náklady
        $this->expense('562', 5_000.00);     // globálně → H. Nákladové úroky
        $this->assign(['501' => 'cost_of_sales']);

        $version = $this->definitions->findVersion(FinancialStatementService::TYPE_PURPOSE, self::ENDS_ON);
        self::assertNotNull($version);
        $globalPrefixes = array_map(
            static fn (array $m): string => (string) $m['account_prefix'],
            $this->definitions->accountMap((int) $version['id']),
        );

        self::assertContains('541', $globalPrefixes, '541 musí být v globální mapě (D.).');
        self::assertContains('562', $globalPrefixes, '562 musí být v globální mapě (H.).');
        self::assertNotContains('501', $globalPrefixes, '501 globální mapa pokrývat nesmí — patří funkci.');

        // Když je 501 přiřazený a zbytek kryje globální mapa, výkaz se sestaví.
        $out = $this->statements->incomeStatementByFunction($this->supplierId, $this->periodId, self::ENDS_ON, 'full');
        self::assertSame([], $out['checks']['unmapped_accounts']);
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    /** @param array<string,string> $map prefix => function_code */
    private function assign(array $map): void
    {
        foreach ($map as $prefix => $function) {
            // PHP z číselného klíče pole udělá int — prefix musí zůstat řetězec.
            $this->definitions->setFunctionMapping($this->supplierId, (string) $prefix, $function, $this->userId);
        }
    }

    private function revenue(string $account, float $amount): void
    {
        $this->post('311', $account, $amount);
    }

    private function expense(string $account, float $amount): void
    {
        $this->post($account, '321', $amount);
    }

    private function post(string $debit, string $credit, float $amount): void
    {
        $this->posting->postDocument($this->supplierId, 'manual', null, [
            ['account_code' => $debit, 'side' => 'debit', 'amount' => $amount],
            ['account_code' => $credit, 'side' => 'credit', 'amount' => $amount],
        ], [
            'entry_date'  => self::YEAR . '-06-30',
            'description' => 'Test účelového členění ' . $debit . '/' . $credit,
            'posted'      => true,
            'user_id'     => $this->userId,
        ]);
    }

    /**
     * Účelový výkaz se musí dát vytisknout i vyexportovat.
     *
     * Backend routu měl od začátku, ale na stránce k ní nevedlo tlačítko, takže tudy
     * nikdy nikdo neprošel — a účelové členění má jiný tvar řádků než druhové (vlastní
     * kódy „HZ" / „PVH", římské číslice). Renderer ani XLSX ho tedy nikdy neviděly.
     */
    public function testPurposeStatementRendersToPdfAndXlsx(): void
    {
        $container = Bootstrap::buildContainer();
        $pdf = $container->get(\MyInvoice\Service\Pdf\IncomeStatementPdfRenderer::class);
        $xlsx = $container->get(\MyInvoice\Service\Accounting\Reports\ReportXlsxExporter::class);

        $this->revenue('602', 1_000_000.00);
        $this->expense('501', 400_000.00);
        $this->expense('518', 150_000.00);
        $this->expense('521', 250_000.00);
        $this->assign(['501' => 'cost_of_sales', '518' => 'distribution', '521' => 'administration']);

        $data = $this->statements->incomeStatementByFunction(
            $this->supplierId,
            $this->periodId,
            self::ENDS_ON,
            'full',
        );

        $bytes = $pdf->render($data);
        self::assertStringStartsWith('%PDF', $bytes, 'Výstupem musí být PDF.');
        self::assertGreaterThan(5000, strlen($bytes), 'Prázdné PDF by znamenalo výkaz bez řádků.');

        $out = $xlsx->incomeStatement($data, 'czk');
        self::assertNotSame('', $out['bytes']);
        self::assertStringEndsWith('.xlsx', $out['filename']);
    }

    /**
     * PDF účelového výkazu nese pod výkazem blok odhadu do konce roku (stejný jako výkaz
     * po účtech, označený jako nezaúčtovaný), jen s právem k odhadu a u otevřeného období.
     */
    public function testPurposeStatementPdfCarriesYearEndEstimate(): void
    {
        $container = Bootstrap::buildContainer();
        $pdo = $this->db->pdo();
        $constants = \MyInvoice\Service\Tax\TaxConstants::forYear(2026);
        $constants['year'] = self::YEAR;
        $pdo->prepare('INSERT INTO tax_constants (year, data) VALUES (?, ?) ON DUPLICATE KEY UPDATE data = VALUES(data)')
            ->execute([self::YEAR, json_encode($constants, JSON_UNESCAPED_UNICODE)]);
        $pdo->prepare("UPDATE supplier SET taxpayer_type = 'po', accounting_mode = 'double_entry' WHERE id = ?")->execute([$this->supplierId]);
        $this->revenue('602', 1_000_000.00);
        $this->expense('501', 400_000.00);
        $this->assign(['501' => 'cost_of_sales']);

        $action = $container->get(\MyInvoice\Action\Accounting\Reports\FinancialStatementAction::class);
        $text = function (string $role) use ($action): string {
            $req = (new \Slim\Psr7\Factory\ServerRequestFactory())
                ->createServerRequest('GET', '/api/accounting/reports/income-statement-by-function/export')
                ->withQueryParams(['period_id' => (string) $this->periodId, 'as_of' => self::YEAR . '-06-30', 'scope' => 'full', 'format' => 'pdf'])
                ->withAttribute(\MyInvoice\Middleware\SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
                ->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => $role]);
            $res = $action->exportIncomeStatementByFunction($req, new \Slim\Psr7\Response());
            self::assertSame(200, $res->getStatusCode());
            $res->getBody()->rewind();
            return (new \Smalot\PdfParser\Parser())->parseContent((string) $res->getBody())->getText();
        };

        $pdf = $text('accountant');
        self::assertStringContainsString('ODHAD, NEZAÚČTOVÁNO', $pdf);
        self::assertStringContainsString('Odhad výsledku hospodaření před zdaněním', $pdf);

        $pdo->prepare("UPDATE accounting_periods SET status = 'closed' WHERE id = ?")->execute([$this->periodId]);
        self::assertStringNotContainsString('ODHAD, NEZAÚČTOVÁNO', $text('accountant'), 'Uzavřené období odhad nemá.');
    }

    /**
     * Hlavička výstupu musí říct, které členění to je.
     *
     * Šablonu i XLSX sdílí obě varianty a popisek byl natvrdo „druhové", takže účelový
     * výkaz se tiskl s hlavičkou druhového. Jsou to přitom dvě různé přílohy závěrky
     * (příloha č. 2 část I vs. část II vyhl. 500/2002 Sb.) a účetní by odevzdala jednu
     * pod hlavičkou druhé.
     */
    public function testPurposeStatementIsLabelledAsPurposeNotByNature(): void
    {
        $renderer = Bootstrap::buildContainer()
            ->get(\MyInvoice\Service\Pdf\IncomeStatementPdfRenderer::class);

        $this->revenue('602', 100_000.00);
        $this->expense('501', 40_000.00);
        $this->assign(['501' => 'cost_of_sales']);

        $purpose = $this->statements->incomeStatementByFunction($this->supplierId, $this->periodId, self::ENDS_ON, 'full');
        $byNature = $this->statements->incomeStatement($this->supplierId, $this->periodId, self::ENDS_ON, 'full');

        // Renderer nevrací HTML, jen PDF — porovnáváme proto přes vyrendrovaný text.
        $ref = new \ReflectionMethod($renderer, 'renderTemplate');
        $purposeHtml = (string) $ref->invoke($renderer, 'income_statement.twig', $purpose);
        $natureHtml = (string) $ref->invoke($renderer, 'income_statement.twig', $byNature);

        self::assertStringContainsString('účelové členění', $purposeHtml);
        self::assertStringNotContainsString('druhové členění', $purposeHtml);
        self::assertStringContainsString('druhové členění', $natureHtml, 'Druhové členění se popisovat nepřestalo.');
    }

    /**
     * @param array<string,mixed> $statement
     * @return array<string, array<string,mixed>>
     */
    private function rowsByCode(array $statement): array
    {
        $out = [];
        foreach ($statement['rows'] as $r) {
            $out[(string) $r['row_code']] = $r;
        }

        return $out;
    }
}
