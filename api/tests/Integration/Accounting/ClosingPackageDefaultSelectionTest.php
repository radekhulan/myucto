<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\{AccountingPeriodRepository, ImportJobRepository};
use MyInvoice\Service\Export\ClosingPackageService;
use PHPUnit\Framework\TestCase;

final class ClosingPackageDefaultSelectionTest extends TestCase
{
    private \PDO $pdo;
    private int $supplier;
    private int $period;

    protected function setUp(): void
    {
        $c=Bootstrap::buildContainer();
        $this->pdo=$c->get(Connection::class)->pdo();
        $this->pdo->beginTransaction();
        $this->pdo->exec("INSERT INTO supplier (company_name,street,city,zip,email,country_id,default_currency_id,default_vat_rate_id,accounting_mode)
            SELECT 'Synthetic package defaults','Test 1','Test','10000','package@example.com',
                (SELECT id FROM countries WHERE iso2='CZ' LIMIT 1),(SELECT id FROM currencies WHERE code='CZK' LIMIT 1),
                (SELECT id FROM vat_rates ORDER BY id LIMIT 1),'double_entry'");
        $this->supplier=(int)$this->pdo->lastInsertId();
        $this->period=$c->get(AccountingPeriodRepository::class)->create($this->supplier,2025,'2024-07-01','2025-06-30');
        $this->pdo->prepare("INSERT INTO supplier_accounting_frameworks(supplier_id,effective_from,accounting_framework) VALUES (?,'2024-07-01','cz_504')")->execute([$this->supplier]);
    }

    protected function tearDown(): void
    {
        if($this->pdo->inTransaction()) $this->pdo->rollBack();
    }

    public function testImplicitDefaultZipDoesNotAttemptUnsupported504Supplements(): void
    {
        $manifest=$this->package([]);
        self::assertSame('cz_504',$manifest['accounting_framework']);
        self::assertNotEmpty($manifest['files']);
        self::assertStringNotContainsString('Přehled o peněžních tocích:',implode("\n",$manifest['warnings']));
        self::assertStringNotContainsString('Přehled o změnách vlastního kapitálu:',implode("\n",$manifest['warnings']));
    }

    public function testExplicitUnsupportedRequestStillProducesVisibleWarning(): void
    {
        $manifest=$this->package(['parts'=>['balance_sheet','cash_flow']]);
        self::assertSame('completed_with_warnings',$manifest['status']);
        self::assertStringContainsString('Přehled o peněžních tocích:',implode("\n",$manifest['warnings']));
    }

    public function testDatedDefaultKeeps500PartsAndPreservesExplicitSelection(): void
    {
        $service=Bootstrap::buildContainer()->get(ClosingPackageService::class);
        self::assertSame(['cash_flow'],$service->partsForPeriod($this->supplier,$this->period,['cash_flow']));
        self::assertNotContains('cash_flow',$service->partsForPeriod($this->supplier,$this->period,[]));
        self::assertNotContains('equity_changes',$service->partsForPeriod($this->supplier,$this->period,['invalid']));
        $this->pdo->prepare("UPDATE supplier_accounting_frameworks SET accounting_framework='cz_500' WHERE supplier_id=?")->execute([$this->supplier]);
        $this->pdo->prepare("INSERT INTO supplier_accounting_frameworks(supplier_id,effective_from,accounting_framework) VALUES (?,'2025-07-01','cz_504')")->execute([$this->supplier]);
        self::assertSame(ClosingPackageService::ALL_PARTS,$service->partsForPeriod($this->supplier,$this->period,[]));
    }

    public function testStartRejectsConflictingHistoryBeforeCreatingJob(): void
    {
        $this->pdo->prepare("INSERT INTO supplier_accounting_frameworks(supplier_id,effective_from,accounting_framework) VALUES (?,'2025-01-01','cz_500')")->execute([$this->supplier]);
        $request=(new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST','/')
            ->withAttribute(\MyInvoice\Middleware\SupplierScopeMiddleware::ATTR_CURRENT_ID,$this->supplier)
            ->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_USER,['id'=>1,'role'=>'admin'])
            ->withParsedBody(['period_id'=>$this->period]);
        $response=Bootstrap::buildContainer()->get(\MyInvoice\Action\Report\ClosingPackageAction::class)
            ->start($request,new \Slim\Psr7\Response());
        self::assertSame(422,$response->getStatusCode());
        self::assertStringContainsString('statement_framework_period_conflict',(string)$response->getBody());
        self::assertSame([],Bootstrap::buildContainer()->get(ImportJobRepository::class)
            ->listForTenant($this->supplier,'closing_package'));
    }

    private function package(array $options): array
    {
        $c=Bootstrap::buildContainer();
        $jobs=$c->get(ImportJobRepository::class);
        $user=(int)$this->pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        $id=$jobs->create($this->supplier,'closing_package',['period_id'=>$this->period]+$options,$user);
        $service=$c->get(ClosingPackageService::class);
        $service->run($id);
        $job=$jobs->findById($id);
        self::assertNotEmpty($job['result_path'],(string)($job['last_error']??''));
        $path=$service->resolveResultPath($job['result_path']);
        $zip=new \ZipArchive();
        try {
            self::assertTrue($zip->open($path));
            return json_decode($zip->getFromName('manifest.json'),true,flags:JSON_THROW_ON_ERROR);
        } finally { $zip->close(); if(is_file($path)) unlink($path); }
    }
}
