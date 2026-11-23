<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Nonprofit;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingFrameworkRepository;
use MyInvoice\Repository\Nonprofit\Membership\MembershipRateRepository;
use MyInvoice\Service\Accounting\Dimension\SystemDimensionCatalog;
use MyInvoice\Service\Accounting\Archive\ArchiveService;
use MyInvoice\Service\Accounting\Archive\ArchiveRestoreService;
use MyInvoice\Service\Anonymization\MembershipCloneScrubber;
use MyInvoice\Service\Nonprofit\Membership\MembershipException;
use MyInvoice\Service\Nonprofit\Membership\MembershipRateService;
use PDO;
use PHPUnit\Framework\TestCase;

final class MembershipRatesReviewTest extends TestCase
{
    private Connection $db;
    private PDO $pdo;
    private int $supplier;
    private int $actor;

    protected function setUp(): void
    {
        $this->db = Bootstrap::buildContainer()->get(Connection::class);
        $this->pdo = $this->db->pdo();
        self::assertStringEndsWith('_test', (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn());
        $this->pdo->beginTransaction();
        $vat = (int) $this->pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn();
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        $this->pdo->prepare("INSERT INTO supplier(company_name,street,city,zip,email,country_id,default_currency_id,default_vat_rate_id,accounting_mode) VALUES('Synthetic rate association','Synthetic street','Synthetic city','10000','rate-review@example.test',203,1,?,'double_entry')")->execute([$vat]);
        $this->supplier = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO currencies(supplier_id,code,label,symbol,name_cs,name_en,decimals,is_active,is_default,account_number,bank_code) VALUES(?,'CZK','Synthetic CZK','Kč','Koruna','Crown',2,1,1,'1000000005','0100')")->execute([$this->supplier]);
        $currency = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('UPDATE supplier SET default_currency_id=? WHERE id=?')->execute([$currency, $this->supplier]);
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        $this->pdo->prepare("INSERT INTO users(email,password_hash,name,role,locale,is_active) VALUES('rate-actor@example.test',?,'Synthetic rate actor','admin','cs',1)")
            ->execute([password_hash('Synthetic unused password', PASSWORD_BCRYPT, ['cost' => 4])]);
        $this->actor = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO supplier_accounting_frameworks(supplier_id,effective_from,accounting_framework) VALUES(?,'2020-01-01','cz_504')")->execute([$this->supplier]);
        foreach (['315.100' => 'asset', '684.100' => 'revenue', '649.100' => 'revenue'] as $code => $type) {
            $this->pdo->prepare('INSERT INTO chart_of_accounts(supplier_id,account_code,name,account_type) VALUES(?,?,?,?)')->execute([$this->supplier, $code, 'Synthetic ' . $code, $type]);
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) $this->pdo->rollBack();
    }

    public function testAllowedAccountsFollowFrameworkAndCurrentActiveChart(): void
    {
        $service = new MembershipRateService($this->db);
        self::assertSame([['account_code' => '684.100', 'name' => 'Synthetic 684.100']], $service->revenueAccounts($this->supplier, '2026-01-01'));
        $this->pdo->prepare("UPDATE chart_of_accounts SET is_active=0 WHERE supplier_id=? AND account_code='684.100'")->execute([$this->supplier]);
        self::assertSame([], $service->revenueAccounts($this->supplier, '2026-01-01'));
        $this->pdo->prepare("UPDATE supplier_accounting_frameworks SET accounting_framework='cz_500' WHERE supplier_id=?")->execute([$this->supplier]);
        self::assertSame([], $service->revenueAccounts($this->supplier, '2026-01-01'));
        $this->expectException(MembershipException::class);
        $service->save($this->supplier, null, $this->input());
    }

    public function testRetirementPreservesInvalidHistoricalAccountsButReactivationValidates(): void
    {
        $service = new MembershipRateService($this->db);
        $rate = $service->save($this->supplier, null, $this->input());
        // Historický kód může přestat existovat i po změně účtové osnovy.
        $this->pdo->prepare("UPDATE chart_of_accounts SET account_code='315.999' WHERE supplier_id=? AND account_code='315.100'")->execute([$this->supplier]);
        $input = $this->input();
        $input['is_active'] = false;
        $input['row_version'] = $rate['row_version'];
        $retired = $service->save($this->supplier, $rate['id'], $input);
        self::assertFalse($retired['is_active']);
        self::assertSame('315.100', $retired['receivable_account']);
        $input['is_active'] = true;
        $input['row_version'] = $retired['row_version'];
        $this->expectException(MembershipException::class);
        $service->save($this->supplier, $rate['id'], $input);
    }

    public function testChangedAccountingValuesAreValidatedEvenWhenRetiring(): void
    {
        $service = new MembershipRateService($this->db);
        $rate = $service->save($this->supplier, null, $this->input());
        $input = $this->input();
        $input['is_active'] = false;
        $input['row_version'] = $rate['row_version'];
        $input['revenue_account'] = '649.100';
        $this->expectException(MembershipException::class);
        $service->save($this->supplier, $rate['id'], $input);
    }

    public function testInactiveReceivableAccountCannotBeUsedForAnActiveRate(): void
    {
        $this->pdo->prepare("UPDATE chart_of_accounts SET is_active=0 WHERE supplier_id=? AND account_code='315.100'")->execute([$this->supplier]);
        $this->expectException(MembershipException::class);
        (new MembershipRateService($this->db))->save($this->supplier, null, $this->input());
    }

    public function testCalendarRateRejectsMidMonthButOneOffRateAcceptsIt(): void
    {
        $service = new MembershipRateService($this->db);
        $input = $this->input();
        $input['period_months'] = 0;
        $oneOff = $service->save($this->supplier, null, $input);
        $service->assertUsable($this->supplier, $oneOff['id'], false, '2026-02-15');
        $calendar = $service->save($this->supplier, null, $this->input());
        $service->assertUsable($this->supplier, $calendar['id'], false, '2026-02-01');
        $this->expectException(MembershipException::class);
        $this->expectExceptionMessage('Období kalendářní sazby musí začínat prvním dnem měsíce.');
        $service->assertUsable($this->supplier, $calendar['id'], false, '2026-02-15');
    }

    public function testCloneRemapsAllRateDimensionIdsAndPreservesOtherFields(): void
    {
        $catalog = new SystemDimensionCatalog($this->db);
        $axes = $catalog->ensureForFramework($this->supplier, '2026-01-01', $this->actor);
        $this->pdo->prepare("INSERT INTO dimension_values(type_id,supplier_id,code,name) VALUES(?,?,'SYNTHETIC-RATE','Synthetic rate activity')")
            ->execute([$axes['activity_kind']['id'], $this->supplier]);
        $selection = ['activity_value_id' => $axes['activity']['values']['main'], 'activity_kind_value_id' => (int) $this->pdo->lastInsertId(),
            'tax_regime_value_id' => $axes['tax_regime']['values']['subject']];
        ksort($selection);
        $input = $this->input();
        $input['classification'] = $selection;
        $rate = (new MembershipRateService($this->db))->save($this->supplier, null, $input);
        $withoutClassification = (new MembershipRateService($this->db))->save($this->supplier, null, $this->input());
        $map = [];
        foreach ($selection as $old) {
            $this->pdo->prepare("INSERT INTO dimension_values(type_id,supplier_id,code,name,semantic_key,is_active) SELECT type_id,supplier_id,CONCAT(code,'-CLONE'),'Synthetic cloned dimension',NULL,0 FROM dimension_values WHERE supplier_id=? AND id=?")
                ->execute([$this->supplier, $old]);
            $map[$old] = (int) $this->pdo->lastInsertId();
            self::assertNotSame($old, $map[$old]);
        }
        MembershipCloneScrubber::remapRateClassifications($this->db, $this->supplier, $map);
        $cloned = (new MembershipRateRepository($this->db))->find($this->supplier, $rate['id']);
        self::assertSame(array_map(static fn (int $id): int => $map[$id], $selection), $cloned['classification']);
        self::assertSame($withoutClassification, (new MembershipRateRepository($this->db))->find($this->supplier, $withoutClassification['id']));
        unset($rate['classification'], $cloned['classification'], $rate['updated_at'], $cloned['updated_at']);
        self::assertSame($rate, $cloned);
        // Neúplná mapa nesmí přepsat žádné z polí právě kontrolované sazby.
        try {
            MembershipCloneScrubber::remapRateClassifications($this->db, $this->supplier, []);
            self::fail('Neúplná mapa klonu nesmí projít.');
        } catch (\LogicException $error) {
            self::assertSame('Chybí cílová hodnota dimenze sazby.', $error->getMessage());
        }
        self::assertSame(array_map(static fn (int $id): int => $map[$id], $selection), (new MembershipRateRepository($this->db))->find($this->supplier, $cloned['id'])['classification']);
    }

    public function testArchiveCloneRemapsRateClassificationThroughExportAndRestore(): void
    {
        // Současný rámec není celá historie: archiv musí zachovat i předchozí a budoucí změnu.
        $today = date('Y-m-d');
        $future = ((int) date('Y') + 1) . '-01-01';
        $this->pdo->prepare("INSERT INTO supplier_accounting_frameworks(supplier_id,effective_from,accounting_framework,created_by) VALUES(?,'2010-01-01','cz_500',?),(?,?,'cz_500',?)")
            ->execute([$this->supplier, $this->actor, $this->supplier, $future, $this->actor]);
        $frameworks = new AccountingFrameworkRepository($this->db);
        $sourceHistory = $frameworks->listForTenant($this->supplier);
        $axes = (new SystemDimensionCatalog($this->db))->ensureForFramework($this->supplier, '2026-01-01', $this->actor);
        $this->pdo->prepare("INSERT INTO dimension_values(type_id,supplier_id,code,name) VALUES(?,?,'SYNTHETIC-ARCHIVE-RATE','Synthetic archive rate activity')")
            ->execute([$axes['activity_kind']['id'], $this->supplier]);
        $selection = ['activity_value_id' => $axes['activity']['values']['main'],
            'activity_kind_value_id' => (int) $this->pdo->lastInsertId(),
            'tax_regime_value_id' => $axes['tax_regime']['values']['subject']];
        $input = $this->input();
        $input['classification'] = $selection;
        $rates = new MembershipRateService($this->db);
        $repository = new MembershipRateRepository($this->db);
        $source = $rates->save($this->supplier, null, $input);
        $this->pdo->prepare('UPDATE nonprofit_membership_rates SET is_active=0,row_version=7 WHERE supplier_id=? AND id=?')
            ->execute([$this->supplier, $source['id']]);
        $source = $repository->find($this->supplier, $source['id']);
        self::assertNotNull($source);
        // Vyřazená sazba se musí obnovit i s později deaktivovanou dimenzí.
        $this->pdo->prepare('UPDATE dimension_values SET is_active=0 WHERE supplier_id=? AND id=?')
            ->execute([$this->supplier, $selection['activity_kind_value_id']]);
        $empty = $rates->save($this->supplier, null, $this->input());
        $container = Bootstrap::buildContainer();
        $archive = $container->get(ArchiveService::class);
        $path = null;
        try {
            // Veřejná cesta skutečně vytvoří ZIP a obnoví další firmu; helper test nevolá.
            $meta = $archive->export($this->supplier, $this->actor);
            $path = $archive->filePath($this->supplier, $meta);
            $report = $container->get(ArchiveRestoreService::class)->restore($path);
            $target = (int) $report['new_supplier_id'];
            self::assertNotSame($this->supplier, $target);
            self::assertSame(2, $report['counts']['nonprofit_membership_rates']);
            $cloned = $repository->page($target, 0, false);
            self::assertCount(2, $cloned);
            self::assertNotSame($source['id'], $cloned[0]['id']);
            self::assertSame($target, $cloned[0]['supplier_id']);
            self::assertFalse($cloned[0]['is_active']);
            self::assertSame(7, $cloned[0]['row_version']);
            $dimension = $this->pdo->prepare('SELECT v.supplier_id,t.supplier_id type_supplier,t.system_key,v.code,v.semantic_key,v.is_active FROM dimension_values v JOIN dimension_types t ON t.id=v.type_id WHERE v.supplier_id=? AND v.id=?');
            $sourceDimension = $this->pdo->prepare('SELECT code,semantic_key,is_active FROM dimension_values WHERE supplier_id=? AND id=?');
            foreach ($selection as $field => $oldId) {
                $newId = $cloned[0]['classification'][$field];
                self::assertNotSame($oldId, $newId, $field);
                $dimension->execute([$target, $newId]);
                $row = $dimension->fetch(PDO::FETCH_ASSOC);
                self::assertIsArray($row, $field);
                self::assertSame($target, (int) $row['supplier_id']);
                self::assertSame($target, (int) $row['type_supplier']);
                self::assertSame(substr($field, 0, -strlen('_value_id')), $row['system_key']);
                $sourceDimension->execute([$this->supplier, $oldId]);
                $old = $sourceDimension->fetch(PDO::FETCH_ASSOC);
                self::assertSame($old['code'], $row['code']);
                self::assertSame($old['semantic_key'], $row['semantic_key']);
                self::assertSame($old['is_active'], $row['is_active']);
                if ($field === 'activity_kind_value_id') self::assertSame(0, (int) $row['is_active']);
            }
            foreach ([[$source, $cloned[0]], [$empty, $cloned[1]]] as [$before, $after]) {
                unset($before['id'], $before['supplier_id'], $before['classification'], $after['id'], $after['supplier_id'], $after['classification']);
                self::assertSame($before, $after);
            }
            self::assertNull($cloned[1]['classification']);
            self::assertSame($source, $repository->find($this->supplier, $source['id']));
            self::assertSame($empty, $repository->find($this->supplier, $empty['id']));
            $restoredHistory = $frameworks->listForTenant($target);
            self::assertCount(3, $restoredHistory);
            foreach ($sourceHistory as $index => $oldFramework) {
                $newFramework = $restoredHistory[$index];
                self::assertNotSame($oldFramework['id'], $newFramework['id']);
                self::assertSame($target, $newFramework['supplier_id']);
                unset($oldFramework['id'], $oldFramework['supplier_id'], $newFramework['id'], $newFramework['supplier_id']);
                self::assertSame($oldFramework, $newFramework);
            }
            self::assertSame('cz_500', $frameworks->forDate($target, '2019-12-31'));
            self::assertSame('cz_504', $frameworks->forDate($target, '2020-01-01'));
            self::assertSame('cz_504', $frameworks->forDate($target, $today));
            self::assertSame('cz_500', $frameworks->forDate($target, $future));
            self::assertSame([['account_code' => '684.100', 'name' => 'Synthetic 684.100']], $rates->revenueAccounts($target, $today));
            self::assertSame([], $rates->revenueAccounts($target, '2019-12-31'));
            self::assertSame([], $rates->revenueAccounts($target, $future));
            $edit = $this->input();
            $edit['amount'] = '750.00';
            $edit['row_version'] = $cloned[1]['row_version'];
            $saved = $rates->save($target, $cloned[1]['id'], $edit);
            self::assertSame('750.00', $saved['amount']);
            self::assertSame($cloned[1]['row_version'] + 1, $saved['row_version']);
            self::assertSame($sourceHistory, $frameworks->listForTenant($this->supplier));
            self::assertSame($empty, $repository->find($this->supplier, $empty['id']));
        } finally {
            if ($path !== null && is_file($path)) {
                unlink($path);
                @rmdir(dirname($path));
            }
        }
    }

    private function input(): array
    {
        return ['name' => 'Synthetic member rate', 'amount' => '500.00', 'receivable_account' => '315.100', 'revenue_account' => '684.100',
            'title_key' => 'synthetic-rate', 'period_months' => 12, 'title_reference' => 'Synthetic resolution', 'evidence' => 'Synthetic evidence',
            'classification' => null, 'is_active' => true, 'row_version' => 0];
    }
}
