<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Action\Admin\Import\PohodaMigrationAction;
use MyInvoice\Action\Admin\Import\PremierMigrationAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Migration\Pohoda\PohodaException;
use MyInvoice\Service\Migration\Pohoda\PohodaExport;
use MyInvoice\Service\Migration\Pohoda\PohodaImporter;
use MyInvoice\Service\Migration\Pohoda\PohodaImportJobService;
use MyInvoice\Service\Migration\Pohoda\PohodaUploads;
use MyInvoice\Service\Migration\Premier\PremierBackup;
use MyInvoice\Service\Migration\Premier\PremierImportJobService;
use MyInvoice\Service\Migration\Premier\PremierUploads;
use MyInvoice\Tests\Fixtures\Pohoda\SyntheticPohodaExport;
use MyInvoice\Tests\Fixtures\Premier\FiscalPremierBackup;
use MyInvoice\Tests\Fixtures\Premier\SyntheticPremierBackup;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Průvodci převodů PREMIER a POHODA u firmy s hospodářským rokem (od srpna) nabízejí roky
 * jako účetní roky FIRMY s hranicemi období a vybraný rok jde 1:1 do převodu. Kalendářní
 * firma dostane náhled beze změny. Syntetická data, izolovaná firma, transakce s rollbackem.
 */
#[Group('integration')]
final class MigrationWizardFiscalYearsTest extends TestCase
{
    /** Firma založená v únoru 2025: hospodářský rok 2024 (1. 8. 2024 – 31. 7. 2025) nemá kalendářní rok 2024. */
    private const FOUNDED_IN_FEBRUARY = ['2025-02-10', '2025-03-15', '2025-06-20', '2025-09-01', '2026-01-15'];

    private \Psr\Container\ContainerInterface $container;
    private Connection $db;
    private int $userId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private int $czId = 0;
    private int $supplierId = 0;
    /** @var list<array{string,int,string}> */
    private array $uploads = [];
    private bool $inTx = false;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje - test vyžaduje DB connection.');
        }
        try {
            $this->container = Bootstrap::buildContainer();
            $this->db = $this->container->get(Connection::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        if (!$this->db->hasColumn('premier_import_map', 'premier_key') || !$this->db->hasColumn('pohoda_import_map', 'pohoda_key')
            || !$this->db->hasColumn('supplier', 'fiscal_year_start_month')) {
            $this->markTestSkipped('Chybí migrace převodů nebo začátku účetního roku.');
        }
        $pdo = $this->db->pdo();
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->userId === 0 || $this->currencyId === 0 || $this->vatRateId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data (user/currency/vat_rate/country) v DB.');
        }
        $pdo->beginTransaction();
        $this->inTx = true;
    }

    protected function tearDown(): void
    {
        foreach ($this->uploads as [$system, $supplierId, $token]) {
            if ($system === 'pohoda') {
                PohodaUploads::purge($supplierId, $token);
                @rmdir(PohodaUploads::base($supplierId));
            } else {
                PremierUploads::purge($supplierId, $token);
                @rmdir(PremierUploads::base($supplierId));
            }
        }
        if (isset($this->db) && $this->inTx) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
    }

    /**
     * PREMIER: kalendářní roky zálohy 2025 a 2026 jsou u firmy od srpna účetní roky 2024
     * (únor až červen 2025) a 2025 (září 2025 až leden 2026). Rok 2024 jde vybrat a převést.
     */
    public function testPremierFiscalCompanyGetsCompanyYearsWithPeriods(): void
    {
        $this->supplierId = $this->supplier(SyntheticPremierBackup::ICO, SyntheticPremierBackup::NAME, 8);
        $token = $this->premierUpload(self::FOUNDED_IN_FEBRUARY);
        $action = $this->container->get(PremierMigrationAction::class);

        $show = $this->json($this->call($action, 'show', [], $token));
        self::assertSame([
            [2024, 3, '2024-08-01', '2025-07-31'],
            [2025, 2, '2025-08-01', '2026-07-31'],
        ], array_map(static fn (array $a): array => [$a['year'], $a['entries'], $a['period_start'] ?? null, $a['period_end'] ?? null], $show['agendas']));
        self::assertSame(['2024', '2025'], array_map('strval', array_keys($show['preflight'])));
        self::assertNotContains('year_missing', array_column($show['preflight']['2024'], 'code'), json_encode($show['preflight'], JSON_UNESCAPED_UNICODE));
        self::assertSame(2025, $show['default_year']);
        foreach ($show['agendas'] as $agenda) {
            self::assertArrayNotHasKey('months', $agenda, 'Počty po měsících jsou jen podklad serveru.');
        }

        // Kalendářní rok zálohy, který účetním rokem firmy není, výběr odmítne.
        $start = $this->call($action, 'start', ['mode' => 'dry_run', 'years' => [2026]], $token);
        self::assertSame([422, 'invalid_year'], [$start->getStatusCode(), $this->json($start)['error']['code']]);

        // Vybraný rok firmy převod najde a převede jako období 2024.
        $jobs = $this->container->get(ImportJobRepository::class);
        $jobId = $jobs->create($this->supplierId, PremierImportJobService::SOURCE, [
            'token' => $token, 'mode' => 'dry_run', 'ico' => SyntheticPremierBackup::ICO, 'years' => [2024],
        ], $this->userId);
        $this->container->get(PremierImportJobService::class)->run($jobId);
        $job = $jobs->findById($jobId);
        self::assertStringNotContainsString('neobsahuje účetní rok', (string) $job['log_text'] . (string) $job['last_error']);
        $stmt = $this->db->pdo()->prepare('SELECT agenda_year FROM premier_imports WHERE job_id = ?');
        $stmt->execute([$jobId]);
        self::assertSame([2024], array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN)), (string) $job['log_text'] . (string) $job['last_error']);
    }

    /** PREMIER: kalendářní firma dostane náhled jako dřív - kalendářní roky, bez hranic období. */
    public function testPremierCalendarCompanyKeepsCalendarYears(): void
    {
        $this->supplierId = $this->supplier(SyntheticPremierBackup::ICO, SyntheticPremierBackup::NAME, 1);
        $token = $this->premierUpload(self::FOUNDED_IN_FEBRUARY);
        $expected = PremierBackup::withoutMonths(PremierUploads::meta($this->supplierId, $token)['agendas']);

        $show = $this->json($this->call($this->container->get(PremierMigrationAction::class), 'show', [], $token));
        self::assertSame($expected, $show['agendas']);
        self::assertSame([2025, 2026], array_column($show['agendas'], 'year'));
        self::assertSame([4, 1], array_column($show['agendas'], 'entries'));
        self::assertSame(['2025', '2026'], array_map('strval', array_keys($show['preflight'])));
    }

    /**
     * POHODA: agenda `<IČO>_2026` vede hospodářský rok 1. 8. 2025 – 31. 7. 2026 a doklad
     * ze září 2026. Firma od srpna vybírá účetní rok 2025 (agenda) a 2026 (pozdější období);
     * plán převodu je přeloží na agendu 2026.
     */
    public function testPohodaFiscalCompanyGetsCompanyYearsAndPlanMapsThemToAgenda(): void
    {
        $this->supplierId = $this->supplier(SyntheticPohodaExport::ICO, SyntheticPohodaExport::NAME, 8);
        $token = $this->pohodaUpload(true);
        $agendaYear = SyntheticPohodaExport::YEAR;

        $show = $this->json($this->call($this->container->get(PohodaMigrationAction::class), 'show', [], $token));
        $agenda = array_values(array_filter($show['agendas'], static fn (array $a): bool => $a['year'] === $agendaYear))[0];
        self::assertSame([
            'year' => 2025,
            'period_start' => '2025-08-01',
            'period_end' => '2026-07-31',
            'later_years' => [2026],
            'later_periods' => [['year' => 2026, 'period_start' => '2026-08-01', 'period_end' => '2027-07-31']],
        ], $agenda['company_year'] ?? null);
        self::assertSame(['2025'], array_map('strval', array_keys($show['preflight'])));

        $meta = PohodaUploads::meta($this->supplierId, $token);
        $calendar = $this->container->get(PohodaImporter::class)->companyCalendar($this->supplierId);
        $companyYears = PohodaImportJobService::companyYears($meta, SyntheticPohodaExport::ICO, $calendar, $this->supplierId, $token);
        self::assertSame([['year' => $agendaYear, 'skip' => [2026], 'later' => [2026]]],
            PohodaImportJobService::plan($meta, SyntheticPohodaExport::ICO, [2025], false, $this->supplierId, $token, $companyYears));
        self::assertSame([['year' => $agendaYear, 'skip' => [], 'later' => [2026]]],
            PohodaImportJobService::plan($meta, SyntheticPohodaExport::ICO, [2025, 2026], false, $this->supplierId, $token, $companyYears));
        try {
            PohodaImportJobService::plan($meta, SyntheticPohodaExport::ICO, [2024], false, $this->supplierId, $token, $companyYears);
            self::fail('Rok, který není účetním rokem firmy z exportu, plán odmítne.');
        } catch (PohodaException $e) {
            self::assertSame('invalid_year', $e->errorCode);
        }
    }

    /** POHODA: kalendářní firma - rok agendy, žádný `company_year`, kontrola pod rokem agendy. */
    public function testPohodaCalendarCompanyKeepsAgendaYears(): void
    {
        $this->supplierId = $this->supplier(SyntheticPohodaExport::ICO, SyntheticPohodaExport::NAME, 1);
        $token = $this->pohodaUpload(false);
        $expected = PohodaUploads::meta($this->supplierId, $token)['agendas'];

        $show = $this->json($this->call($this->container->get(PohodaMigrationAction::class), 'show', [], $token));
        self::assertSame($expected, $show['agendas']);
        self::assertSame([(string) SyntheticPohodaExport::YEAR], array_map('strval', array_keys($show['preflight'])));
        $meta = PohodaUploads::meta($this->supplierId, $token);
        $calendar = $this->container->get(PohodaImporter::class)->companyCalendar($this->supplierId);
        self::assertNull(PohodaImportJobService::companyYears($meta, SyntheticPohodaExport::ICO, $calendar, $this->supplierId, $token));
    }

    /**
     * Náhled převodu upozorní jen neziskovou organizaci (právní forma nebo rámec 504 kdykoli
     * v historii), že převod pro ni nebyl ověřen. Běžná firma dostane odpověď bez klíče.
     */
    public function testPreviewWarnsOnlyNonprofitTarget(): void
    {
        $this->supplierId = $this->supplier(SyntheticPremierBackup::ICO, SyntheticPremierBackup::NAME, 1);
        $token = $this->premierUpload(self::FOUNDED_IN_FEBRUARY);
        $premier = $this->container->get(PremierMigrationAction::class);
        $pdo = $this->db->pdo();

        $pdo->prepare("UPDATE supplier SET legal_form = '112' WHERE id = ?")->execute([$this->supplierId]);
        self::assertArrayNotHasKey('nonprofit_notice', $this->json($this->call($premier, 'show', [], $token)));

        $pdo->prepare("UPDATE supplier SET legal_form = '706' WHERE id = ?")->execute([$this->supplierId]);
        $notice = $this->json($this->call($premier, 'show', [], $token))['nonprofit_notice'] ?? null;
        self::assertSame(['warning', 'migration_nonprofit_untested', ['entity_profile' => 'nonprofit', 'framework_504' => false]],
            [$notice['level'] ?? null, $notice['code'] ?? null, $notice['context'] ?? null]);

        $pdo->prepare("UPDATE supplier SET legal_form = NULL WHERE id = ?")->execute([$this->supplierId]);
        $pdo->prepare("INSERT INTO supplier_accounting_frameworks (supplier_id, effective_from, accounting_framework) VALUES (?, '1900-01-01', 'cz_500'), (?, '2020-01-01', 'cz_504'), (?, '2022-01-01', 'cz_500')")
            ->execute([$this->supplierId, $this->supplierId, $this->supplierId]);
        $notice = $this->json($this->call($premier, 'show', [], $token))['nonprofit_notice'] ?? null;
        self::assertSame(['entity_profile' => 'unknown', 'framework_504' => true], $notice['context'] ?? null);

        $pohodaSupplier = $this->supplierId = $this->supplier(SyntheticPohodaExport::ICO, SyntheticPohodaExport::NAME, 1);
        $pohodaToken = $this->pohodaUpload(false);
        $pohoda = $this->container->get(PohodaMigrationAction::class);
        self::assertArrayNotHasKey('nonprofit_notice', $this->json($this->call($pohoda, 'show', [], $pohodaToken)));
        $pdo->prepare("UPDATE supplier SET legal_form = '145' WHERE id = ?")->execute([$pohodaSupplier]);
        self::assertSame('svj', $this->json($this->call($pohoda, 'show', [], $pohodaToken))['nonprofit_notice']['context']['entity_profile'] ?? null);
    }

    private function supplier(string $ico, string $name, int $startMonth): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, ic, dic, is_vat_payer, vat_period, default_currency_id, default_vat_rate_id, accounting_mode, fiscal_year_start_month)
             VALUES (?, "Účetní 1", "Brno", "60200", ?, "prevod@example.invalid", ?, ?, 1, "monthly", ?, ?, "tax_evidence", ?)'
        )->execute([$name, $this->czId, $ico, 'CZ' . $ico, $this->currencyId, $this->vatRateId, $startMonth]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
             VALUES (?, 'CZK', 'CZK', 'Kč', 'Česká koruna', 'Czech Koruna', 2, 1, 1)"
        )->execute([$id]);
        $pdo->prepare('UPDATE supplier SET default_currency_id = ? WHERE id = ?')->execute([(int) $pdo->lastInsertId(), $id]);
        return $id;
    }

    /** @param list<string> $dates */
    private function premierUpload(array $dates): string
    {
        $token = PremierUploads::newToken();
        $this->uploads[] = ['premier', $this->supplierId, $token];
        $dir = FiscalPremierBackup::writeDir(PremierUploads::backupDir($this->supplierId, $token), false, $dates);
        PremierUploads::writeMeta($this->supplierId, $token, ['token' => $token, 'sha256' => str_repeat('0', 64), 'agendas' => PremierBackup::overview($dir)]);
        return $token;
    }

    /**
     * `$fiscal`: agenda ze {@see SyntheticPohodaExport::write()} posunutá na hospodářský rok
     * od srpna (jako {@see PohodaFiscalYearTest}) s dokladem 10. 9. 2026 dalšího roku.
     */
    private function pohodaUpload(bool $fiscal): string
    {
        $token = PohodaUploads::newToken();
        $this->uploads[] = ['pohoda', $this->supplierId, $token];
        $root = PohodaUploads::exportDir($this->supplierId, $token);
        $dir = SyntheticPohodaExport::write($root, unbooked: !$fiscal);
        if ($fiscal) {
            foreach (glob($dir . '/*.xml') ?: [] as $file) {
                file_put_contents($file, str_replace(['2026-01-', '2025-12-'], ['2025-08-', '2025-07-'], (string) file_get_contents($file)));
            }
            $journal = $dir . '/01_ucetni_denik.xml';
            $entry = '<act:accountingItem><act:source>Interní doklady</act:source><act:number><typ:numberRequested>FI0002</typ:numberRequested></act:number>'
                . '<act:text>Služba vložená společníkem</act:text><act:homeCurrency><typ:priceSum>200</typ:priceSum></act:homeCurrency>'
                . '<act:accounting><act:credit>518000</act:credit><act:debit>411000</act:debit></act:accounting><act:date>2026-09-10</act:date><act:dateTax>2026-09-10</act:dateTax></act:accountingItem>';
            file_put_contents($journal, str_replace('</lst:accountancy>', (string) iconv('UTF-8', 'Windows-1250', $entry) . '</lst:accountancy>', (string) file_get_contents($journal)));
        }
        PohodaUploads::writeMeta($this->supplierId, $token, ['token' => $token, 'sha256' => str_repeat('0', 64), 'agendas' => PohodaExport::overview($root)]);
        return $token;
    }

    /** @param array<string,mixed> $body */
    private function call(object $action, string $method, array $body, string $token): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method === 'show' ? 'GET' : 'POST', '/api/admin/imports')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute('auth.effective_role', new EffectiveRole(9, 'Test', 'staff', true, ['utilities.import' => 2]))
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId])
            ->withParsedBody($body);
        return $action->{$method}($request, (new ResponseFactory())->createResponse(), ['token' => $token]);
    }

    /** @return array<string,mixed> */
    private function json(ResponseInterface $response): array
    {
        return (array) json_decode((string) $response->getBody(), true);
    }
}
