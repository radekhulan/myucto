<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\Reports\{CashFlowStatementService, EquityChangesStatementService, ReportException, StatementNotesService};
use MyInvoice\Service\Export\ClosingPackageService;
use PHPUnit\Framework\TestCase;

final class Statement504WorkflowTest extends TestCase
{
    private \PDO $pdo;
    private int $supplier;
    private int $period;

    protected function setUp(): void
    {
        $c = Bootstrap::buildContainer();
        $this->pdo = $c->get(Connection::class)->pdo();
        $this->pdo->beginTransaction();
        $this->pdo->exec("INSERT INTO supplier (company_name,street,city,zip,email,country_id,default_currency_id,default_vat_rate_id)
            SELECT 'Synthetic 504 workflow','Test 1','Test','10000','workflow504@example.com',
                (SELECT id FROM countries WHERE iso2='CZ' LIMIT 1),(SELECT id FROM currencies WHERE code='CZK' LIMIT 1),
                (SELECT id FROM vat_rates ORDER BY id LIMIT 1)");
        $this->supplier = (int) $this->pdo->lastInsertId();
        $this->period = $c->get(AccountingPeriodRepository::class)->create($this->supplier, 2099, '2098-07-01', '2099-06-30');
        $this->pdo->prepare("INSERT INTO supplier_accounting_frameworks (supplier_id,effective_from,accounting_framework) VALUES (?,'2098-07-01','cz_504')")
            ->execute([$this->supplier]);
    }

    protected function tearDown(): void
    {
        if ($this->pdo->inTransaction()) $this->pdo->rollBack();
    }

    public function testNotesUse504LawAndActualFiscalDates(): void
    {
        $out = Bootstrap::buildContainer()->get(StatementNotesService::class)->build($this->supplier, $this->period);
        self::assertSame('cz_504', $out['accounting_framework'] ?? null);
        self::assertSame('2098-07-01', $out['period']['starts_on']);
        self::assertSame('2099-06-30', $out['period']['ends_on']);
        $sections = array_column($out['sections'], null, 'key');
        self::assertArrayHasKey('n504_grants', $sections);
        self::assertArrayHasKey('n504_funds', $sections);
        self::assertArrayHasKey('n504_tax_reliefs', $sections);
        self::assertArrayNotHasKey('n504_fair_value', $sections);
        self::assertStringNotContainsString('§ 39', json_encode($out, JSON_UNESCAPED_UNICODE));
        self::assertFalse($out['complete']);
    }

    public function testAuditedNotesIncludeExactlyTheAdditional504Sections(): void
    {
        $this->pdo->prepare('INSERT INTO accounting_supplier_settings (supplier_id,statutory_audit) VALUES (?,1)')->execute([$this->supplier]);
        $out = Bootstrap::buildContainer()->get(StatementNotesService::class)->build($this->supplier, $this->period);
        self::assertSame('full', $out['scope'] ?? null);
        $keys = array_column($out['sections'], 'key');
        foreach (['n504_fair_value','n504_valuation_model','n504_extraordinary','n504_auditor_fees'] as $key) self::assertContains($key, $keys);
    }

    public function testDirectCashFlowCannotRender500ClassificationFor504(): void
    {
        $this->expectException(ReportException::class);
        Bootstrap::buildContainer()->get(CashFlowStatementService::class)->build($this->supplier, $this->period);
    }

    public function testDirectEquityCannotRender500AccountsFor504(): void
    {
        $this->expectException(ReportException::class);
        Bootstrap::buildContainer()->get(EquityChangesStatementService::class)->build($this->supplier, $this->period);
    }

    public function testPackageAvailabilityDoesNotOffer500OnlyStatements(): void
    {
        $counts = Bootstrap::buildContainer()->get(ClosingPackageService::class)->previewCounts($this->supplier, $this->period);
        self::assertSame(0, $counts['cash_flow']);
        self::assertSame(0, $counts['equity_changes']);
        self::assertSame(1, $counts['statement_notes']);
    }

    public function testHttpAvailabilityAndPackageMetadataUseThePeriodFramework(): void
    {
        $this->pdo->prepare("UPDATE supplier SET accounting_mode='double_entry' WHERE id=?")->execute([$this->supplier]);
        $request = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('GET', '/')
            ->withAttribute(\MyInvoice\Middleware\SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplier)
            ->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_USER, ['id'=>1,'role'=>'admin'])
            ->withQueryParams(['period_id'=>$this->period]);
        $c = Bootstrap::buildContainer();
        $response = $c->get(\MyInvoice\Action\Accounting\Reports\Section18StatementsAction::class)->get($request, new \Slim\Psr7\Response());
        self::assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($payload['available']);
        self::assertNull($payload['cash_flow']);
        self::assertNull($payload['equity']);
        $response = $c->get(\MyInvoice\Action\Report\ClosingPackageAction::class)->preview($request, new \Slim\Psr7\Response());
        self::assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('cz_504', $payload['accounting_framework']);
        self::assertSame(0, $payload['counts']['cash_flow']);
    }

    public function testHistorical500SupplementsRemainAvailableWithLater504AndManual504Field(): void
    {
        $this->pdo->prepare("UPDATE supplier_accounting_frameworks SET accounting_framework='cz_500' WHERE supplier_id=?")->execute([$this->supplier]);
        $this->pdo->prepare("INSERT INTO supplier_accounting_frameworks (supplier_id,effective_from,accounting_framework) VALUES (?,'2099-07-01','cz_504')")->execute([$this->supplier]);
        $this->pdo->prepare("UPDATE supplier SET tax_accounting_decree='504' WHERE id=?")->execute([$this->supplier]);
        $c = Bootstrap::buildContainer();
        self::assertArrayHasKey('operating', $c->get(CashFlowStatementService::class)->build($this->supplier, $this->period));
        self::assertArrayHasKey('totals', $c->get(EquityChangesStatementService::class)->build($this->supplier, $this->period));
        self::assertSame([], $c->get(ClosingPackageService::class)->previewMetadata($this->supplier, $this->period));
        $notes = $c->get(StatementNotesService::class)->build($this->supplier, $this->period);
        self::assertArrayNotHasKey('accounting_framework', $notes);
        self::assertContains('entity_identification', array_column($notes['sections'], 'key'));
    }

    public function testNotesStoreFreezeAndCarryOverOnlyTheirOwnFrameworkKeys(): void
    {
        $c = Bootstrap::buildContainer();
        $notes = $c->get(StatementNotesService::class);
        $notes->saveSection($this->supplier, 2099, 'n504_grants', 'Synthetic grant disclosure', null);
        $notes->freezeAutoValues($this->supplier, 2099, $this->period);
        $this->pdo->prepare("UPDATE supplier SET company_name='Changed synthetic entity' WHERE id=?")->execute([$this->supplier]);
        $out = $notes->build($this->supplier, $this->period);
        $sections = array_column($out['sections'], null, 'key');
        self::assertSame('Synthetic grant disclosure', $sections['n504_grants']['content']);
        self::assertStringContainsString('Synthetic 504 workflow', $sections['n504_identification']['content']);
        $next = $c->get(AccountingPeriodRepository::class)->create($this->supplier, 2100, '2099-07-01', '2100-06-30');
        $notes->saveSection($this->supplier, 2100, 'n504_funds', 'Keep my text', null);
        self::assertSame(['n504_grants'], $notes->carryOverFromPreviousYear($this->supplier, 2100, null)['copied']);
        $nextSections = array_column($notes->build($this->supplier, $next)['sections'], null, 'key');
        self::assertSame(2099, $nextSections['n504_grants']['carried_over_from_year']);
        self::assertSame('Keep my text', $nextSections['n504_funds']['content']);
        $notes->saveSection($this->supplier, 2100, 'n504_grants', 'Reviewed', null);
        self::assertNull(array_column($notes->build($this->supplier, $next)['sections'], null, 'key')['n504_grants']['carried_over_from_year']);
    }

    public function testActualPackageUsesPeriodEndAnd504FilesWithNonzeroData(): void
    {
        $c = Bootstrap::buildContainer();
        $catalog = $c->get(\MyInvoice\Service\Accounting\Dimension\SystemDimensionCatalog::class)->ensureForFramework($this->supplier, '2098-07-01');
        $ids = [];
        foreach (['221'=>'asset','602'=>'revenue'] as $code=>$type) {
            $this->pdo->prepare('INSERT INTO chart_of_accounts (supplier_id,account_code,name,account_type) VALUES (?,?,?,?)')
                ->execute([$this->supplier, (string) $code, 'Synthetic account', $type]);
            $ids[$code] = (int) $this->pdo->lastInsertId();
        }
        $this->pdo->prepare("INSERT INTO journal_entries (supplier_id,period_id,entry_date,source_type) VALUES (?,?,'2099-06-30','manual')")->execute([$this->supplier,$this->period]);
        $entry = (int) $this->pdo->lastInsertId();
        foreach (['debit'=>'221','credit'=>'602'] as $side=>$code) {
            $this->pdo->prepare('INSERT INTO journal_entry_lines (supplier_id,entry_id,account_id,side,amount) VALUES (?,?,?,?,1250.75)')->execute([$this->supplier,$entry,$ids[$code],$side]);
            if ($side === 'credit') {
                $this->pdo->prepare('INSERT INTO journal_entry_line_dimensions (supplier_id,line_id,dimension_type_id,dimension_value_id) VALUES (?,?,?,?)')
                    ->execute([$this->supplier,(int)$this->pdo->lastInsertId(),$catalog['activity']['id'],$catalog['activity']['values']['economic']]);
            }
        }
        $this->pdo->prepare('UPDATE journal_entries SET posted_at=NOW() WHERE id=?')->execute([$entry]);
        $notes = $c->get(StatementNotesService::class);
        foreach ($notes->build($this->supplier, $this->period)['sections'] as $section) {
            if (!$section['auto']) $notes->saveSection($this->supplier, 2099, $section['key'], 'Synthetic disclosure: not applicable.', null);
        }
        $jobs = $c->get(\MyInvoice\Repository\ImportJobRepository::class);
        $user = (int) $this->pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        $jobId = $jobs->create($this->supplier, 'closing_package', ['period_id'=>$this->period,
            'parts'=>['balance_sheet','income_statement','statement_notes'],'include_xlsx'=>true], $user);
        $package = $c->get(ClosingPackageService::class);
        $package->run($jobId);
        $job = $jobs->findById($jobId);
        self::assertSame('completed', $job['status'], (string) ($job['last_error'] ?? ''));
        $path = $package->resolveResultPath($job['result_path']);
        $zip = new \ZipArchive();
        try {
            self::assertTrue($zip->open($path));
            $manifest = json_decode($zip->getFromName('manifest.json'), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('cz_504', $manifest['accounting_framework'] ?? null);
            self::assertSame('2099-06-30', $manifest['statement_as_of']);
            self::assertStringContainsString('504/2002', $zip->getFromName('README.txt'));
            self::assertStringNotContainsString('500/2002', $zip->getFromName('Priloha-k-zaverce/priloha-2099.txt'));
            self::assertStringStartsWith('%PDF-', $zip->getFromName('Rozvaha/rozvaha-2099.pdf'));
            $xlsx = tempnam(sys_get_temp_dir(), 'workflow504_');
            try {
                file_put_contents($xlsx, $zip->getFromName('Vysledovka/vysledovka-2099.xlsx'));
                $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($xlsx);
                $result = array_values(array_filter($book->getActiveSheet()->toArray(null,false,false), static fn(array $r):bool=>($r[0]??'')==='D.'))[0];
                self::assertSame(1250.75, $result[3]);
                self::assertSame(1250.75, $result[4]);
            } finally { if(isset($book)) $book->disconnectWorksheets(); unlink($xlsx); }
        } finally { $zip->close(); if(is_file($path)) unlink($path); }
    }
}
