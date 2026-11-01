<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Action\Accounting\JournalAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\AccountingSupplierSettingsRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Closing\ClosingException;
use MyInvoice\Service\Accounting\Closing\DocumentSeriesService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Integrační testy číselných řad deníku (Epic F4, §6.2 I16, R13): sekvence
 * výdeje čísel, nezávislost per rok, editace prefixu a opt-in řada `manual`
 * pro ruční zápisy (accounting_supplier_settings.manual_doc_series).
 *
 * Izolovaný supplier, transakce s rollbackem v tearDown (výdej čísla FOR UPDATE
 * vyžaduje transakci — drží ji test). Soft-skip bez cfg.php.
 */
#[Group('integration')]
final class DocumentSeriesTest extends TestCase
{
    private const YEAR = 2098;

    private Connection $db;
    private DocumentSeriesService $series;
    private AccountingSupplierSettingsRepository $settings;
    private JournalAction $journalAction;
    private AccountingPeriodRepository $periods;

    private int $supplierId = 0;
    private int $userId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db            = $container->get(Connection::class);
            $this->series        = $container->get(DocumentSeriesService::class);
            $this->settings      = $container->get(AccountingSupplierSettingsRepository::class);
            $this->journalAction = $container->get(JournalAction::class);
            $this->periods       = $container->get(AccountingPeriodRepository::class);
            $seeder              = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $currencyId   = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $vatRateId    = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $czId         = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->userId === 0 || $currencyId === 0 || $vatRateId === 0 || $czId === 0) {
            $this->markTestSkipped('Chybí základní data (user/currency/vat_rate/country) v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        $stmt = $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id, accounting_mode)
             VALUES (?, "Testovací 1", "Praha", "11000", ?, "f4-series@example.com", ?, ?, "double_entry")'
        );
        $stmt->execute(['F4 řady test s.r.o.', $czId, $currencyId, $vatRateId]);
        $this->supplierId = (int) $pdo->lastInsertId();

        $seeder->seedForSupplier($this->supplierId);
        $this->periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
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

    // ── I16: sekvence, per rok, prefix ───────────────────────────────────────

    public function testI16SequenceAndPerYearIndependence(): void
    {
        self::assertSame('UZ-2098-0001', $this->series->next($this->supplierId, 'closing', self::YEAR));
        self::assertSame('UZ-2098-0002', $this->series->next($this->supplierId, 'closing', self::YEAR));
        self::assertSame('UZ-2098-0003', $this->series->next($this->supplierId, 'closing', self::YEAR));

        // Per rok nezávislé čítače
        self::assertSame('UZ-2099-0001', $this->series->next($this->supplierId, 'closing', self::YEAR + 1));
        // Jiná řada = jiný čítač i prefix
        self::assertSame('OT-2098-0001', $this->series->next($this->supplierId, 'opening', self::YEAR));
    }

    public function testI16UpdatePrefixAffectsNextNumber(): void
    {
        self::assertSame('UZ-2098-0001', $this->series->next($this->supplierId, 'closing', self::YEAR));

        self::assertTrue($this->series->updatePrefix($this->supplierId, 'closing', self::YEAR, 'UZAV'));
        self::assertSame('UZAV-2098-0002', $this->series->next($this->supplierId, 'closing', self::YEAR), 'Nový prefix, čítač pokračuje.');

        // Validace prefixu ^[A-Z0-9]{1,10}$
        try {
            $this->series->updatePrefix($this->supplierId, 'closing', self::YEAR, 'uz!');
            self::fail('Očekávána ClosingException invalid_prefix.');
        } catch (ClosingException $e) {
            self::assertSame('invalid_prefix', $e->errorCode);
        }
    }

    // ── #22: převzetí řady z jiného systému (čítač + tvar čísla) ─────────────

    public function testSeriesCounterCanBeSetForwardBeforeFirstIssue(): void
    {
        // Řádek řady ještě neexistuje — nastavení musí fungovat i tak.
        self::assertTrue($this->series->updateSeries($this->supplierId, 'cash_in', self::YEAR, ['next_number' => 11]));
        self::assertSame('PPD-2098-0011', $this->series->next($this->supplierId, 'cash_in', self::YEAR));
        self::assertSame('PPD-2098-0012', $this->series->next($this->supplierId, 'cash_in', self::YEAR));
    }

    public function testSeriesCustomFormatContinuesExternalNumbering(): void
    {
        // Přesně případ z #22: poslední doklad z jiného systému byl 26HP00010.
        self::assertTrue($this->series->updateSeries($this->supplierId, 'cash_in', self::YEAR, [
            'prefix'        => '26HP',
            'number_format' => '{PREFIX}{CCCCC}',
            'next_number'   => 11,
        ]));
        self::assertSame('26HP00011', $this->series->next($this->supplierId, 'cash_in', self::YEAR));
        self::assertSame('26HP00012', $this->series->next($this->supplierId, 'cash_in', self::YEAR));

        // Návrat na vestavěnou šablonu; čítač i prefix zůstávají.
        self::assertTrue($this->series->updateSeries($this->supplierId, 'cash_in', self::YEAR, ['number_format' => null]));
        self::assertSame('26HP-2098-0013', $this->series->next($this->supplierId, 'cash_in', self::YEAR));
    }

    public function testSeriesCounterAndFormatAreValidated(): void
    {
        try {
            $this->series->updateSeries($this->supplierId, 'cash_in', self::YEAR, ['next_number' => 0]);
            self::fail('Očekávána ClosingException invalid_next_number.');
        } catch (ClosingException $e) {
            self::assertSame('invalid_next_number', $e->errorCode);
        }

        try {
            $this->series->updateSeries($this->supplierId, 'cash_in', self::YEAR, ['number_format' => '{PREFIX}-{YYYY}']);
            self::fail('Očekávána ClosingException invalid_number_format.');
        } catch (ClosingException $e) {
            self::assertSame('invalid_number_format', $e->errorCode);
        }

        try {
            $this->series->updateSeries($this->supplierId, 'nesmysl', self::YEAR, ['next_number' => 5]);
            self::fail('Očekávána ClosingException unknown_series.');
        } catch (ClosingException $e) {
            self::assertSame('unknown_series', $e->errorCode);
        }
    }

    /**
     * Pohledávky (OP) i závazky (OZ) číslují do jedné tabulky s jednou unikátností čísla
     * (`uq_other_item_doc_no`). Stejný prefix obou řad by druhý potvrzený doklad shodil
     * na chybě databáze, proto ho ruční úprava řady odmítne stejně jako u pokladny.
     */
    public function testOtherItemSeriesMayNotShareAPrefix(): void
    {
        $this->series->ensureSeriesRow($this->supplierId, 'other_receivable', self::YEAR, 'OP');
        $this->series->ensureSeriesRow($this->supplierId, 'other_payable', self::YEAR, 'OZ');

        foreach ([['other_payable', 'OP'], ['other_receivable', 'OZ']] as [$code, $prefix]) {
            try {
                $this->series->updateSeries($this->supplierId, $code, self::YEAR, ['prefix' => $prefix]);
                self::fail('Prefix ' . $prefix . ' už drží druhá řada ostatních položek.');
            } catch (ClosingException $e) {
                self::assertSame('series_prefix_taken', $e->errorCode);
            }
        }

        // Jiný prefix projde a pokladní skupina je na ostatních položkách nezávislá.
        $this->series->updateSeries($this->supplierId, 'other_payable', self::YEAR, ['prefix' => 'OZA']);
        $this->series->updateSeries($this->supplierId, 'cash_in', self::YEAR, ['prefix' => 'OP']);
        self::assertSame('OZA-2098-0001', $this->series->next($this->supplierId, 'other_payable', self::YEAR));
    }

    public function testI16SeriesAreTenantScoped(): void
    {
        self::assertSame('UZ-2098-0001', $this->series->next($this->supplierId, 'closing', self::YEAR));
        $rows = $this->series->list($this->supplierId);
        self::assertCount(1, $rows);
        self::assertSame($this->supplierId, (int) $rows[0]['supplier_id']);
        self::assertSame(2, (int) $rows[0]['next_number']);
    }

    // ── I16: manual opt-in řada ID (R13) ─────────────────────────────────────

    public function testI16ManualSeriesOptInAssignsDocumentNo(): void
    {
        $this->settings->upsert($this->supplierId, null, null, null, true, null);

        $res = $this->createManualEntry([
            'entry_date' => self::YEAR . '-06-15',
            'description' => 'Ruční zápis s auto číslem',
            'lines' => [
                ['account_code' => '211', 'side' => 'debit', 'amount' => 100.00],
                ['account_code' => '602', 'side' => 'credit', 'amount' => 100.00],
            ],
        ]);
        self::assertSame(201, $res['status']);
        self::assertSame('ID-2098-0001', $res['body']['document_no'], 'Opt-in flag → číslo z řady ID.');
    }

    public function testI16ManualSeriesDisabledLeavesDocumentNoNull(): void
    {
        $this->settings->upsert($this->supplierId, null, null, null, false, null);

        $res = $this->createManualEntry([
            'entry_date' => self::YEAR . '-06-15',
            'lines' => [
                ['account_code' => '211', 'side' => 'debit', 'amount' => 100.00],
                ['account_code' => '602', 'side' => 'credit', 'amount' => 100.00],
            ],
        ]);
        self::assertSame(201, $res['status']);
        self::assertNull($res['body']['document_no'], 'Bez flagu se document_no nedoplňuje (default chování F1).');
    }

    public function testI16ExplicitDocumentNoIsNeverOverwritten(): void
    {
        $this->settings->upsert($this->supplierId, null, null, null, true, null);

        $res = $this->createManualEntry([
            'entry_date' => self::YEAR . '-06-15',
            'document_no' => 'RUCNI-77',
            'lines' => [
                ['account_code' => '211', 'side' => 'debit', 'amount' => 100.00],
                ['account_code' => '602', 'side' => 'credit', 'amount' => 100.00],
            ],
        ]);
        self::assertSame(201, $res['status']);
        self::assertSame('RUCNI-77', $res['body']['document_no'], 'Explicitní document_no má vždy přednost.');

        // Čítač řady ID se nespotřeboval
        $rows = array_filter(
            $this->series->list($this->supplierId),
            static fn (array $r): bool => $r['series_code'] === 'manual',
        );
        self::assertSame([], $rows, 'Řádek řady manual nevznikl — číslo se nevydávalo.');
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $body
     * @return array{status:int, body:array<string,mixed>}
     */
    private function createManualEntry(array $body): array
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/accounting/journal')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant'])
            ->withParsedBody($body);
        $resp = $this->journalAction->create($req, new Psr7Response());
        $resp->getBody()->rewind();
        $decoded = json_decode((string) $resp->getBody(), true);
        return ['status' => $resp->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }
}
