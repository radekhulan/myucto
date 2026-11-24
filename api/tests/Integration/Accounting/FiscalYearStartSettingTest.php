<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Action\Accounting\AccountingPeriodAction;
use MyInvoice\Action\Settings\SettingsAction;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\FiscalCalendar;
use MyInvoice\Tests\Integration\Accounting\Bank\BankPostingTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Clock\MockClock;

/**
 * Nastavení „Účetní rok začíná" (migrace 2003) se zadává PŘEDEM. Proti použitým
 * obdobím jiného tvaru se změnit nedá (změna účetního roku je samostatný úkon
 * s přechodným obdobím), nepoužitá se nahradí a fyzická osoba hospodářský rok mít nemůže.
 * Vše v transakci BankPostingTestCase → rollback.
 */
#[Group('integration')]
final class FiscalYearStartSettingTest extends BankPostingTestCase
{
    public function testChangeAgainstUsedCalendarPeriodIsRejected(): void
    {
        $periodId = $this->freshCompanyWithSetupPeriod();
        $this->container->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplierId);
        $map = $this->accounts->codeToIdMap($this->supplierId);
        $this->journal->insert([
            'supplier_id' => $this->supplierId,
            'period_id'   => $periodId,
            'entry_date'  => self::YEAR . '-06-10',
            'document_no' => 'FY-USED',
            'description' => 'Zápis v období',
            'source_type' => 'manual',
            'source_id'   => null,
            'posted_at'   => date('Y-m-d H:i:s'),
            'posted_by'   => $this->userId,
        ], [
            ['account_id' => $map['211']['id'], 'side' => 'debit', 'amount' => 100.0],
            ['account_id' => $map['411']['id'], 'side' => 'credit', 'amount' => 100.0],
        ]);

        $res = $this->update(['fiscal_year_start_month' => 8]);

        self::assertSame(409, $res['status']);
        self::assertSame('fiscal_year_conflicts_periods', $res['body']['error']['code']);
        self::assertSame([[self::YEAR . '-01-01', self::YEAR . '-12-31']], $this->periodBounds());
        self::assertSame(1, $this->periods->fiscalYearStartMonth($this->supplierId));
    }

    public function testPeriodCreatedByConversionIsNotReplaced(): void
    {
        $periodId = $this->freshCompanyWithSetupPeriod();
        $this->db->pdo()->prepare(
            "INSERT INTO pohoda_import_map (supplier_id, kind, pohoda_key, target_id) VALUES (?, 'period', ?, ?)"
        )->execute([$this->supplierId, (string) self::YEAR, $periodId]);

        $res = $this->update(['fiscal_year_start_month' => 8]);

        self::assertSame(409, $res['status']);
        self::assertSame([[self::YEAR . '-01-01', self::YEAR . '-12-31']], $this->periodBounds());
    }

    public function testUnusedSetupPeriodIsReplacedByFiscalYearShape(): void
    {
        // Založení firmy otevře kalendářní rok dřív, než uživatel stihne nastavit
        // hospodářský rok. Prázdné období nesmí změně bránit: nahradí se novým tvarem.
        $this->freshCompanyWithSetupPeriod();

        $res = $this->update(['fiscal_year_start_month' => 8]);

        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        $calendar = new FiscalCalendar(8);
        $year = $calendar->fiscalYearOfDate(date('Y-m-d'));
        self::assertSame([[$calendar->periodStart($year), $calendar->periodEnd($year)]], $this->periodBounds());
        self::assertSame(8, $this->periods->fiscalCalendar($this->supplierId)->startMonth());
    }

    public function testUnchangedValueSavesEvenWithPeriods(): void
    {
        $this->ensurePeriod();

        self::assertSame(200, $this->update(['fiscal_year_start_month' => 1])['status']);
    }

    public function testCompanyWithoutPeriodsGetsFiscalYearAndFirstPeriodFollowsIt(): void
    {
        $this->db->pdo()->prepare("UPDATE supplier SET taxpayer_type = 'po' WHERE id = ?")->execute([$this->supplierId]);
        $this->db->pdo()->prepare('DELETE FROM accounting_periods WHERE supplier_id = ?')->execute([$this->supplierId]);

        $res = $this->update(['fiscal_year_start_month' => 8]);

        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        $period = (new AccountingPeriodRepository($this->db, new MockClock('2099-03-10')))
            ->ensureOpenPeriodFor($this->supplierId, '2099-03-10');
        self::assertSame(['2098-08-01', '2099-07-31'], [(string) $period['starts_on'], (string) $period['ends_on']]);
    }

    public function testShortFirstFiscalPeriodKeepsCompanyFiscalCalendar(): void
    {
        // Firma vzniklá 1. 3. s hospodářským rokem od srpna má první období jen 1. 3.–31. 7.
        // Podle tvaru (~5 měsíců) by vypadalo kalendářně; rozhodne nastavení firmy, ze kterého
        // berou kalendář i odpisy a karta majetku.
        $this->db->pdo()->prepare('DELETE FROM accounting_periods WHERE supplier_id = ?')->execute([$this->supplierId]);
        $this->db->pdo()->prepare('UPDATE supplier SET fiscal_year_start_month = 8 WHERE id = ?')->execute([$this->supplierId]);
        $repo = new AccountingPeriodRepository($this->db, new MockClock('2099-03-10'));
        $repo->create($this->supplierId, 2098, '2099-03-01', '2099-07-31');

        self::assertSame(8, $repo->fiscalCalendar($this->supplierId)->startMonth());
        $next = $repo->ensureOpenPeriodFor($this->supplierId, '2099-09-15');
        self::assertSame(['2099-08-01', '2100-07-31'], [(string) $next['starts_on'], (string) $next['ends_on']]);
    }

    public function testShortFirstFiscalPeriodIsLabelledByFiscalYearOfItsStart(): void
    {
        // Zkrácené první období 1. 3.–31. 7. 2099 patří do účetního roku 2098 (srpen 2098 až
        // červenec 2099); s labelem 2099 by kolidovalo s obdobím od 1. 8. 2099.
        $this->db->pdo()->prepare('DELETE FROM accounting_periods WHERE supplier_id = ?')->execute([$this->supplierId]);
        $this->db->pdo()->prepare('UPDATE supplier SET fiscal_year_start_month = 8 WHERE id = ?')->execute([$this->supplierId]);
        $action = $this->container->get(AccountingPeriodAction::class);

        $wrong = $this->callAction($action, 'create', 'POST', 'admin', ['fiscal_year' => 2099, 'starts_on' => '2099-03-01', 'ends_on' => '2099-07-31']);
        self::assertSame(422, $wrong['status']);

        $short = $this->callAction($action, 'create', 'POST', 'admin', ['fiscal_year' => 2098, 'starts_on' => '2099-03-01', 'ends_on' => '2099-07-31']);
        self::assertSame(201, $short['status'], json_encode($short['body'], JSON_UNESCAPED_UNICODE));

        $next = $this->callAction($action, 'create', 'POST', 'admin', ['fiscal_year' => 2099, 'starts_on' => '2099-08-01', 'ends_on' => '2100-07-31']);
        self::assertSame(201, $next['status'], json_encode($next['body'], JSON_UNESCAPED_UNICODE));
    }

    public function testCalendarCompanyLabelStaysYearOfStart(): void
    {
        $this->db->pdo()->prepare('DELETE FROM accounting_periods WHERE supplier_id = ?')->execute([$this->supplierId]);
        $this->db->pdo()->prepare('UPDATE supplier SET fiscal_year_start_month = 1 WHERE id = ?')->execute([$this->supplierId]);
        $action = $this->container->get(AccountingPeriodAction::class);

        self::assertSame(422, $this->callAction($action, 'create', 'POST', 'admin', ['fiscal_year' => 2098, 'starts_on' => '2099-03-01', 'ends_on' => '2099-12-31'])['status']);
        self::assertSame(201, $this->callAction($action, 'create', 'POST', 'admin', ['fiscal_year' => 2099, 'starts_on' => '2099-03-01', 'ends_on' => '2099-12-31'])['status']);
    }

    public function testNaturalPersonCannotHaveFiscalYear(): void
    {
        $this->db->pdo()->prepare("UPDATE supplier SET taxpayer_type = 'fo' WHERE id = ?")->execute([$this->supplierId]);
        $this->db->pdo()->prepare('DELETE FROM accounting_periods WHERE supplier_id = ?')->execute([$this->supplierId]);

        $res = $this->update(['fiscal_year_start_month' => 8]);

        self::assertSame(400, $res['status']);
        self::assertSame('fiscal_year_not_for_fo', $res['body']['error']['code']);
    }

    public function testOutOfRangeMonthIsRejected(): void
    {
        self::assertSame(400, $this->update(['fiscal_year_start_month' => 13])['status']);
    }

    /** @return array{status:int, body:array<string,mixed>} */
    private function update(array $body): array
    {
        return $this->callAction($this->container->get(SettingsAction::class), 'updateSupplier', 'PUT', 'admin', $body);
    }

    private function ensurePeriod(): void
    {
        (new AccountingPeriodRepository($this->db))->ensureOpenPeriodFor($this->supplierId, self::YEAR . '-06-01', forPosting: false);
    }

    /** Nová právnická osoba v podvojném účetnictví s kalendářním obdobím z průvodce. */
    private function freshCompanyWithSetupPeriod(): int
    {
        $this->supplierId = $this->cloneSupplier('double_entry');
        $this->db->pdo()->prepare(
            "UPDATE supplier SET taxpayer_type = 'po', accounting_enabled = 1, fiscal_year_start_month = 1 WHERE id = ?"
        )->execute([$this->supplierId]);
        return $this->periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31', 'setup');
    }

    /** @return list<array{0:string,1:string}> */
    private function periodBounds(): array
    {
        return array_map(
            static fn (array $p): array => [(string) $p['starts_on'], (string) $p['ends_on']],
            $this->periods->listForTenant($this->supplierId),
        );
    }
}
