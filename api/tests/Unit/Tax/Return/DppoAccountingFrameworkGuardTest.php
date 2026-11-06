<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tax\Return;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\TaxSubmissionEpoRepository;
use MyInvoice\Service\Epo\EpoClient;
use MyInvoice\Service\Epo\EpoSubmissionException;
use MyInvoice\Service\Epo\EpoSubmissionService;
use MyInvoice\Service\Tax\Return\AccountingDecreeService;
use MyInvoice\Service\Tax\Return\DppoReturnCalculator;
use MyInvoice\Service\Tax\Return\DppoXmlBuilder;
use MyInvoice\Service\Tax\Return\TaxReturnException;
use MyInvoice\Service\Tax\Return\UnsupportedCaseDetector;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class DppoAccountingFrameworkGuardTest extends TestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE supplier_accounting_modes (supplier_id INTEGER, effective_from TEXT, accounting_mode TEXT)');
        $pdo->exec("INSERT INTO supplier_accounting_modes VALUES (1, '1900-01-01', 'double_entry')");
        $pdo->exec('CREATE TABLE supplier_accounting_frameworks (id INTEGER, supplier_id INTEGER, effective_from TEXT, accounting_framework TEXT, created_at TEXT, created_by INTEGER)');
        $pdo->exec("INSERT INTO supplier_accounting_frameworks (id, supplier_id, effective_from, accounting_framework) VALUES (1, 1, '1900-01-01', 'cz_500'), (2, 1, '2026-07-01', 'cz_504')");
        $pdo->exec('CREATE TABLE accounting_periods (id INTEGER, supplier_id INTEGER, fiscal_year INTEGER, starts_on TEXT, ends_on TEXT, status TEXT, closed_at TEXT, created_at TEXT, row_version INTEGER, closed_by INTEGER, approved_at TEXT, approved_by INTEGER, reviewed_at TEXT, reviewed_by INTEGER, approval_body TEXT, approval_decision_ref TEXT, approval_document_hash TEXT, created_reason TEXT)');
        $pdo->exec("INSERT INTO accounting_periods (id, supplier_id, fiscal_year, starts_on, ends_on) VALUES (1, 1, 2026, '2025-07-01', '2026-06-30')");
        $this->db = new Connection($this->createStub(Config::class));
        (new \ReflectionProperty(Connection::class, 'pdo'))->setValue($this->db, $pdo);
    }

    public function testHandoffRejectsFalse500XmlForActual504Period(): void
    {
        $repo = $this->createMock(TaxSubmissionEpoRepository::class);
        $repo->expects(self::once())->method('lockSubmission')->willReturn($this->submission('01.07.2026', '30.06.2027'));
        $service = (new \ReflectionClass(EpoSubmissionService::class))->newInstanceWithoutConstructor();
        foreach ([
            'db' => $this->db,
            'repo' => $repo,
            'client' => new EpoClient(new Client(['handler' => HandlerStack::create(new MockHandler([]))])),
        ] as $name => $value) {
            (new \ReflectionProperty($service, $name))->setValue($service, $value);
        }

        try {
            $service->createHandoff(1, 1, null);
            self::fail('Předání DPPO 504 nesmí vzniknout.');
        } catch (EpoSubmissionException $e) {
            self::assertSame('dppo_accounting_framework_not_supported', $e->errorCode);
            self::assertSame(422, $e->httpStatus);
        }
        self::assertFalse($this->db->pdo()->inTransaction());
    }

    public function testSubmissionUsesXmlPeriodInsteadOfCurrentFrameworkOrYearLabel(): void
    {
        $guard = new AccountingDecreeService($this->db);
        $guard->assertDppoSubmissionSupported(1, $this->submission('01.07.2025', '30.06.2026'));
        self::assertTrue(true);
        $this->expectException(TaxReturnException::class);
        $guard->assertDppoSubmissionSupported(1, $this->submission('01.07.2026', '30.06.2027'));
    }

    public function testXmlExplicit504CannotBeSubmittedUnderHistorical500(): void
    {
        $this->expectException(TaxReturnException::class);
        (new AccountingDecreeService($this->db))->assertDppoSubmissionSupported(1, $this->submission('01.07.2025', '30.06.2026', '504'));
    }

    public function testEpoDatesWithoutLeadingZeroesCannotBypassActualPeriodGuard(): void
    {
        $guard = new AccountingDecreeService($this->db);
        $guard->assertDppoSubmissionSupported(1, $this->submission('1.7.2025', '30.6.2026'));
        $this->expectException(TaxReturnException::class);
        $guard->assertDppoSubmissionSupported(1, $this->submission('1.7.2026', '30.6.2027'));
    }

    /**
     * Přiznání 500 bez přílohy účetní závěrky (chybí účetní období, výkazy nešly sestavit,
     * starší uložené podání) atribut `uv_vyhl` nemá. Před větví 504 takové podání prošlo
     * a nesmí ho zablokovat ani kontrola rámce. U skutečného období 504 se blokuje dál.
     */
    public function testSubmissionWithoutDeclaredDecreePassesFor500AndStaysBlockedFor504(): void
    {
        $guard = new AccountingDecreeService($this->db);
        $guard->assertDppoSubmissionSupported(1, $this->submission('01.07.2025', '30.06.2026', null));
        self::assertTrue(true);
        $this->expectException(TaxReturnException::class);
        $guard->assertDppoSubmissionSupported(1, $this->submission('01.07.2026', '30.06.2027', null));
    }

    public function testPureCalculatorRejectsExplicit504Data(): void
    {
        $this->expectException(TaxReturnException::class);
        (new DppoReturnCalculator())->compute(['accounting_decree' => '504', 'vh' => 100000], [], []);
    }

    public function testPureXmlBuilderRejectsExplicit504(): void
    {
        $this->expectException(TaxReturnException::class);
        (new DppoXmlBuilder())->build(['financial_office_code' => '451'], 2026, [], ['accounting_decree' => '504']);
    }

    public static function unsupportedAppendices(): iterable
    {
        $balance = ['assets' => [['row_code' => 'UR.85', 'start' => 10000, 'end' => 12345.67]],
            'liabilities' => [['row_code' => 'US.49', 'start' => 10000, 'end' => 12345.67]]];
        $income = ['rows' => [['row_code' => 'UT.63', 'main' => 2345.67, 'economic' => 0, 'total' => 2345.67]]];
        yield 'balance framework' => ['balance_sheet', ['accounting_framework' => 'cz_504'] + $balance];
        yield 'income framework' => ['income_statement', ['accounting_framework' => 'cz_504'] + $income];
        yield 'balance version' => ['balance_sheet', ['version_code' => 'vyhl504-2002/2016'] + $balance];
        yield 'income version' => ['income_statement', ['version_code' => 'vyhl504-2002/2016'] + $income];
    }

    #[DataProvider('unsupportedAppendices')]
    public function test500MetadataCannotHide504Appendix(string $key, array $statement): void
    {
        try {
            (new DppoXmlBuilder())->build(['financial_office_code' => '451', 'tax_accounting_decree' => '500'], 2026, [],
                ['accounting_decree' => '500'], [$key => $statement]);
            self::fail('Výkaz 504 nesmí projít serializací přílohy 500.');
        } catch (TaxReturnException $e) {
            self::assertSame('dppo_accounting_framework_not_supported', $e->errorCode);
            self::assertSame(422, $e->httpStatus);
        }
    }

    public function testDetectorUsesEffectiveDecreeInsteadOfCurrentManualValue(): void
    {
        $findings = UnsupportedCaseDetector::detectForSupplier(
            ['tax_accounting_decree' => '504'], 'po', ['accounting_decree' => '500'],
        );
        self::assertNotContains('accounting_decree_unsupported', array_column($findings, 'key'));
        $findings = UnsupportedCaseDetector::detectForSupplier(
            ['tax_accounting_decree' => '500'], 'po', ['accounting_decree' => '501'],
        );
        self::assertContains('accounting_decree_unsupported', array_column($findings, 'key'));
    }

    /** Rámec podvojného zná jen 500/504; ručně nastavená vyhláška 501–503 musí dál varovat. */
    #[DataProvider('unsupportedManualDecrees')]
    public function testDetectorKeepsWarningForManualDecreeOutsideFramework(string $decree): void
    {
        $findings = UnsupportedCaseDetector::detectForSupplier(
            ['tax_accounting_decree' => $decree], 'po', ['accounting_decree' => '500'],
        );
        self::assertContains('accounting_decree_unsupported', array_column($findings, 'key'));
    }

    public static function unsupportedManualDecrees(): iterable
    {
        yield 'banky' => ['501'];
        yield 'pojišťovny' => ['502'];
        yield 'zdravotní pojišťovny' => ['503'];
    }

    private function submission(string $startsOn, string $endsOn, ?string $decree = '500'): array
    {
        $decreeAttribute = $decree === null ? '' : ' uv_vyhl="' . $decree . '"';
        return [
            'form_code' => 'dppdp9',
            'period_year' => 2026,
            'status' => 'generated',
            'validation_status' => 'failed',
            'validation_errors' => null,
            'xml_content' => '<Pisemnost><DPPDP9><VetaD zdobd_od="' . $startsOn . '" zdobd_do="' . $endsOn . '"' . $decreeAttribute . '/></DPPDP9></Pisemnost>',
        ];
    }
}
