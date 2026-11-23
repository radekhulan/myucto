<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Action\Admin\Import\PohodaMigrationAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\License\CommercialFeatureAccess;
use MyInvoice\Service\Migration\Pohoda\Payroll\PohodaPayrollImporter;
use MyInvoice\Service\Migration\Pohoda\PohodaExport;
use MyInvoice\Service\Migration\Pohoda\PohodaImportJobService;
use MyInvoice\Service\Migration\Pohoda\PohodaUploads;
use MyInvoice\Service\Migration\Pohoda\PohodaXml;
use MyInvoice\Service\Migration\Shared\PayrollMigrationNotice;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Tests\Fixtures\Pohoda\LargeSyntheticPohodaPayroll;
use MyInvoice\Tests\Fixtures\Pohoda\SyntheticPohodaPayroll;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Náhled průvodce „Přechod z PAMICA" nad nahraným exportem (`GET uploads/{token}`).
 *
 * Náhled soubor mezd nečte: kontrola před převodem vychází z přehledu, který spočítal
 * job nahrání do `meta.json`. Synchronní čtení 50MB souboru mezd drželo požadavek přes
 * timeout webserveru a průvodce visel na „Načítám export…". Přehled nahraný dřív (bez
 * úplného přehledu mezd) dopočítá job na pozadí a náhled mezitím hlásí jeho průběh.
 * Transakce s rollbackem v tearDown.
 */
#[Group('integration')]
final class PohodaPayrollPreviewTest extends TestCase
{
    use IsolatedSupplierTrait;

    private ContainerInterface $container;
    private Connection $db;
    private int $supplierId = 0;
    private int $userId = 0;
    private string $tmp = '';
    /** @var list<string> */
    private array $tokens = [];

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            self::markTestSkipped('cfg.php missing');
        }
        $this->container = Bootstrap::buildContainer();
        $this->db = $this->container->get(Connection::class);
        foreach (['payroll_employees', 'pohoda_import_map', 'import_jobs'] as $table) {
            if (!$this->db->hasTable($table)) {
                self::markTestSkipped("Chybí tabulka {$table}.");
            }
        }
        $pdo = $this->db->pdo();
        $source = (int) ($pdo->query('SELECT MIN(id) FROM supplier')?->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT MIN(id) FROM users')?->fetchColumn() ?: 0);
        if ($source === 0 || $this->userId === 0) {
            self::markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pohoda_preview_' . bin2hex(random_bytes(5));
        mkdir($this->tmp, 0755, true);
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);
        $pdo->prepare('UPDATE supplier SET ic = ?, payroll_enabled = 0 WHERE id = ?')->execute([SyntheticPohodaPayroll::ICO, $this->supplierId]);
    }

    protected function tearDown(): void
    {
        foreach ($this->tokens as $token) {
            PohodaUploads::purge($this->supplierId, $token);
        }
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
        if ($this->tmp !== '' && is_dir($this->tmp)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->tmp, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir($this->tmp);
        }
    }

    /**
     * S úplným přehledem v `meta.json` náhled soubor mezd vůbec neotevře: tady v exportu
     * není, a kontrola přesto vyjde z přehledu (dřív hlásila „Export neobsahuje mzdy").
     */
    public function testShowTakesPayrollPreflightFromMetaWithoutReadingTheFile(): void
    {
        $token = $this->upload(['ico' => SyntheticPohodaPayroll::ICO, 'employees' => 248, 'months' => 10, 'payslips' => 1644,
            'first' => '2026-01', 'last' => '2026-10', 'last_overall' => '2026-10',
            'exported_on' => '2026-11-02', 'open' => ['2026-11' => 3]], null);

        $body = $this->show($token);

        self::assertSame('ready', $body['status'], json_encode($body));
        $messages = $body['payroll_preflight'][(string) SyntheticPohodaPayroll::YEAR] ?? [];
        $codes = array_column($messages, 'code');
        self::assertNotContains('payroll_missing', $codes, 'Náhled nesmí číst soubor mezd, přehled má v meta.json.');
        self::assertContains('payroll_summary', $codes);
        self::assertContains('payroll_module_will_enable', $codes);
        // Rozpracovaný měsíc exportu kontrola pojmenuje dřív, než se převod spustí.
        $open = $messages[array_search('payroll_open_months', $codes, true)];
        self::assertSame('warning', $open['level']);
        self::assertStringContainsString('2026-11 (3 mezd)', $open['message']);
        $summary = $messages[array_search('payroll_summary', $codes, true)];
        self::assertSame('Mzdy za 10 měsíců (2026-01 až 2026-10), zaměstnanců v exportu 248.', $summary['message']);
        self::assertSame(0, $this->describeJobs(), 'S úplným přehledem se job na pozadí nezakládá.');
    }

    /** Kontrola z přehledu v meta.json dává totéž co kontrola, která si soubor přečte sama. */
    public function testPreflightFromSummaryEqualsPreflightFromFile(): void
    {
        $file = SyntheticPohodaPayroll::write($this->tmp);
        $importer = $this->container->get(PohodaPayrollImporter::class);
        $summary = PohodaExport::payrollSummary($file, SyntheticPohodaPayroll::YEAR);

        self::assertSame(
            $importer->preflight($this->supplierId, $file, SyntheticPohodaPayroll::YEAR),
            $importer->preflight($this->supplierId, $this->tmp . '/neexistuje.xml', SyntheticPohodaPayroll::YEAR, $summary),
        );
        // Přehled bez posledního měsíce přes všechny roky (starší meta.json) se nepoužije.
        unset($summary['last_overall']);
        self::assertContains('payroll_missing', array_column(
            $importer->preflight($this->supplierId, $this->tmp . '/neexistuje.xml', SyntheticPohodaPayroll::YEAR, $summary), 'code'));
    }

    /**
     * Kontrola před převodem nad exportem se stovkami osob čte soubor nejvýš jednou;
     * dřív ho převodník četl dvakrát po třinácti průchodech (reálný export 37 s).
     */
    public function testPreflightOfLargeExportIsOnePass(): void
    {
        $file = LargeSyntheticPohodaPayroll::write($this->tmp, 300);
        $pass = INF;
        for ($i = 0; $i < 3; $i++) {
            $start = hrtime(true);
            PohodaXml::count($file, 'ZAM');
            $pass = min($pass, (hrtime(true) - $start) / 1e9);
        }
        $importer = $this->container->get(PohodaPayrollImporter::class);

        $start = hrtime(true);
        $messages = $importer->preflight($this->supplierId, $file, LargeSyntheticPohodaPayroll::YEAR);
        $seconds = (hrtime(true) - $start) / 1e9;

        self::assertContains('payroll_summary', array_column($messages, 'code'));
        self::assertLessThan(4 * $pass, $seconds, sprintf('Kontrola trvala %.2f s, jeden průchod souborem %.2f s.', $seconds, $pass));
    }

    /**
     * Přehled nahraný dřív (bez `last_overall`): náhled nečte soubor synchronně, založí
     * job na pozadí a hlásí jeho průběh. Opakovaný dotaz job nezdvojí; po doběhnutí jobu
     * je náhled hotový.
     */
    public function testLegacyMetaIsCompletedByBackgroundJob(): void
    {
        $token = $this->upload(['ico' => SyntheticPohodaPayroll::ICO, 'employees' => 2, 'months' => 2, 'payslips' => 4,
            'first' => '2026-01', 'last' => '2026-02'], 'small');

        $first = $this->show($token);
        self::assertSame('processing', $first['status'], json_encode($first));
        self::assertIsInt($first['job_id']);
        self::assertIsArray($first['progress']);
        self::assertArrayNotHasKey('payroll_preflight', $first);
        $jobs = $this->container->get(ImportJobRepository::class);
        $job = $jobs->find($first['job_id'], $this->supplierId);
        self::assertSame(PohodaImportJobService::MODE_DESCRIBE, $job['params']['mode'] ?? null);

        $second = $this->show($token);
        self::assertSame([$first['job_id'], 'processing'], [$second['job_id'], $second['status']], 'Běžící job se znovu nezakládá.');
        self::assertSame(1, $this->describeJobs());

        $this->container->get(PohodaImportJobService::class)->run($first['job_id']);
        self::assertSame('completed', $jobs->find($first['job_id'], $this->supplierId)['status']);

        $ready = $this->show($token);
        self::assertSame('ready', $ready['status'], json_encode($ready));
        self::assertSame('2026-02', $ready['agendas'][0]['payroll']['last_overall']);
        self::assertSame($token, $ready['token']);
        self::assertSame('pamica_export.zip', $ready['file_name'], 'Údaje nahrání v meta.json zůstávají.');
        self::assertContains('payroll_summary', array_column($ready['payroll_preflight'][(string) SyntheticPohodaPayroll::YEAR], 'code'));
    }

    /** Job přehledu, který selhal, se hlásí jako chyba, kterou jde zopakovat. */
    public function testFailedDescribeJobIsRetryable(): void
    {
        $token = $this->upload(['ico' => SyntheticPohodaPayroll::ICO, 'employees' => 2, 'months' => 2, 'payslips' => 4,
            'first' => '2026-01', 'last' => '2026-02'], 'small');
        $jobs = $this->container->get(ImportJobRepository::class);
        $failed = $jobs->create($this->supplierId, PohodaImportJobService::SOURCE, ['token' => $token, 'mode' => PohodaImportJobService::MODE_DESCRIBE], $this->userId);
        $jobs->markRunning($failed);
        $jobs->markFailed($failed, 'Zkušební chyba přehledu.');
        PohodaUploads::updateState($this->supplierId, $token, ['describe_job_id' => $failed]);

        $body = $this->show($token);
        self::assertSame(['failed', true, 'Zkušební chyba přehledu.', $failed], [$body['status'], $body['retryable'], $body['error'], $body['job_id']]);

        $retry = $this->show($token, ['retry' => '1']);
        self::assertSame('processing', $retry['status']);
        self::assertNotSame($failed, $retry['job_id']);
    }

    /**
     * Mzdy firmy v exportu, které nejde převzít (modul Mzdy vypnutý nebo licence bez mzdového
     * doplňku), náhled výrazně ohlásí: kolik zaměstnanců a za jaké měsíce, proč a co dělat.
     * Firma se zapnutými mzdami upozornění nedostane. Náhled zároveň nese stav modulu Sklad.
     */
    public function testShowWarnsAboutPayrollThatCannotBeTakenOver(): void
    {
        $token = $this->upload(['ico' => SyntheticPohodaPayroll::ICO, 'employees' => 4, 'months' => 10, 'payslips' => 40,
            'first' => '2026-01', 'last' => '2026-10', 'last_overall' => '2026-10', 'exported_on' => '2026-11-02', 'open' => []], null);
        $licensed = $this->container->get(PayrollModuleAccess::class)->isLicensed();
        $this->db->pdo()->prepare('UPDATE supplier SET stock_enabled = 0 WHERE id = ?')->execute([$this->supplierId]);

        $body = $this->show($token);

        $notice = $body[PayrollMigrationNotice::RESPONSE_KEY] ?? null;
        self::assertIsArray($notice, json_encode($body));
        self::assertSame(PayrollMigrationNotice::CODE, $notice['code']);
        self::assertSame('warning', $notice['level']);
        self::assertSame($licensed ? PayrollMigrationNotice::REASON_MODULE_DISABLED : PayrollMigrationNotice::REASON_UNLICENSED, $notice['reason']);
        self::assertSame(['employees' => 4, 'months' => 10, 'first' => '2026-01', 'last' => '2026-10'],
            array_intersect_key($notice['context'], array_flip(['employees', 'months', 'first', 'last'])));
        self::assertStringContainsString('mzdy 4 zaměstnanců za 01/2026 až 10/2026', $notice['message']);
        self::assertSame(['enabled' => false, 'can_enable' => $this->container->get(CommercialFeatureAccess::class)->isAvailable()], $body['stock_module']);

        if (!$licensed) {
            self::markTestIncomplete('Licence bez mzdového doplňku: firmu se zapnutými mzdami nejde ověřit.');
        }
        $this->db->pdo()->prepare('UPDATE supplier SET payroll_enabled = 1, stock_enabled = 1 WHERE id = ?')->execute([$this->supplierId]);
        $enabled = $this->show($token);
        self::assertArrayNotHasKey(PayrollMigrationNotice::RESPONSE_KEY, $enabled);
        self::assertSame(['enabled' => true, 'can_enable' => true], $enabled['stock_module']);
    }

    /**
     * Nahraný export tak, jak ho nechá job nahrání: `upload.json` ve stavu `ready`,
     * `meta.json` s agendou mezd a rozbalená data (volitelně bez souboru mezd).
     *
     * @param array<string,mixed> $payroll přehled mezd agendy v meta.json
     */
    private function upload(array $payroll, ?string $file): string
    {
        $token = PohodaUploads::newToken();
        $this->tokens[] = $token;
        $dir = PohodaUploads::dir($this->supplierId, $token);
        mkdir($dir, 0755, true);
        $agendaDir = PohodaUploads::exportDir($this->supplierId, $token) . '/' . SyntheticPohodaPayroll::ICO . '_' . SyntheticPohodaPayroll::YEAR;
        mkdir($agendaDir, 0755, true);
        if ($file === 'small') {
            SyntheticPohodaPayroll::write(PohodaUploads::exportDir($this->supplierId, $token));
        }
        PohodaUploads::writeState($this->supplierId, $token, ['file_name' => 'pamica_export.zip', 'size' => 1, 'received' => 1,
            'status' => PohodaUploads::STATUS_READY, 'uploaded_by' => $this->userId, 'job_id' => null, 'error' => null]);
        PohodaUploads::writeMeta($this->supplierId, $token, [
            'token' => $token,
            'file_name' => 'pamica_export.zip',
            'sha256' => str_repeat('0', 64),
            'uploaded_at' => date('c'),
            'uploaded_by' => $this->userId,
            'agendas' => [[
                'dir' => SyntheticPohodaPayroll::ICO . '_' . SyntheticPohodaPayroll::YEAR,
                'ico' => SyntheticPohodaPayroll::ICO,
                'year' => SyntheticPohodaPayroll::YEAR,
                'company' => '',
                'program' => 'POHODA Mzdy',
                'exported_at' => null,
                'counts' => ['journal' => 0, 'opening' => 0, 'first_date' => null, 'last_date' => null, 'issued' => 0, 'purchase' => 0,
                    'internal' => 0, 'cash' => 0, 'bank' => 0, 'partners' => 0],
                'files' => [['file' => '91_mzdy.xml', 'state' => 'ok', 'note' => '']],
                'has_accounting' => false,
                'has_payroll' => true,
                'payroll' => $payroll,
            ]],
        ]);
        return $token;
    }

    /**
     * @param array<string,string> $query
     * @return array<string,mixed>
     */
    private function show(string $token, array $query = []): array
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/admin/imports/pohoda/uploads/' . $token)
            ->withQueryParams($query)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute('auth.effective_role', new EffectiveRole(9, 'Test', 'staff', true, ['utilities.import' => 2]))
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId]);
        $response = $this->container->get(PohodaMigrationAction::class)
            ->show($request, (new ResponseFactory())->createResponse(), ['token' => $token]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        return self::json($response);
    }

    private function describeJobs(): int
    {
        $stmt = $this->db->pdo()->prepare("SELECT COUNT(*) FROM import_jobs WHERE supplier_id = ? AND source = ?
            AND JSON_UNQUOTE(JSON_EXTRACT(params, '$.mode')) = ?");
        $stmt->execute([$this->supplierId, PohodaImportJobService::SOURCE, PohodaImportJobService::MODE_DESCRIBE]);
        return (int) $stmt->fetchColumn();
    }

    /** @return array<string,mixed> */
    private static function json(ResponseInterface $response): array
    {
        return (array) json_decode((string) $response->getBody(), true);
    }
}
