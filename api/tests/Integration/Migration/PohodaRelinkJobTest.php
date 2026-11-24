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
use MyInvoice\Service\Migration\Pohoda\PohodaExport;
use MyInvoice\Service\Migration\Pohoda\PohodaImporter;
use MyInvoice\Service\Migration\Pohoda\PohodaImportJobService;
use MyInvoice\Service\Migration\Pohoda\PohodaRelinkRunner;
use MyInvoice\Service\Migration\Pohoda\PohodaUploads;
use MyInvoice\Tests\Fixtures\Pohoda\SyntheticPohodaExport;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Opravný převod vazeb z POHODY (`relink_only`) přes průvodce i příkazovou řádku: firma
 * převedená z POHODY se dál účtuje v MyÚčtu a klient pošle novější export. Opravný převod
 * nechá vlastní zápisy, nepřidá nové doklady a doplní vazby (tady úhrady převzatých faktur).
 * Syntetická data, izolovaná firma, transakce s rollbackem v tearDown.
 */
#[Group('integration')]
final class PohodaRelinkJobTest extends TestCase
{
    private ContainerInterface $container;
    private Connection $db;
    private ImportJobRepository $jobs;
    private PohodaImporter $importer;
    private int $userId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private int $czId = 0;
    private int $supplierId = 0;
    private string $tmp = '';
    /** @var list<array{int,string}> */
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
            $this->jobs = $this->container->get(ImportJobRepository::class);
            $this->importer = $this->container->get(PohodaImporter::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        if (!$this->db->hasColumn('pohoda_import_map', 'pohoda_key')) {
            $this->markTestSkipped('Chybí migrace 1844 (pohoda_import_map).');
        }
        $pdo = $this->db->pdo();
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->userId === 0 || $this->currencyId === 0 || $this->vatRateId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data (user/currency/vat_rate/country) v DB.');
        }
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pohoda_relink_' . bin2hex(random_bytes(5));
        mkdir($this->tmp, 0755, true);
        $pdo->beginTransaction();
        $this->inTx = true;
        $this->supplierId = $this->supplier();
    }

    protected function tearDown(): void
    {
        foreach ($this->uploads as [$supplierId, $token]) {
            PohodaUploads::purge($supplierId, $token);
            @rmdir(PohodaUploads::base($supplierId));
        }
        if (isset($this->db) && $this->inTx) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
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
     * Job průvodce s `relink_only` volá importer v režimu opravného převodu: řádný převod
     * novějšího exportu do rozjetého deníku odmítne, opravný projde, nic nového nezaloží
     * a úhrady převzatých faktur dopočítá.
     */
    public function testJobWithRelinkOnlyRestoresLinksWithoutNewRecords(): void
    {
        [$entries, $purchases, $payments, $ownEntryId] = $this->convertedCompanyWithOwnEntry();
        $token = $this->upload();

        $plain = $this->runJob($token, []);
        self::assertSame('failed', $this->jobs->findById($plain)['status'], 'Řádný převod do rozjetého deníku nesmí projít.');
        self::assertContains('journal_not_empty', $this->protocolCodes($plain));

        $relink = $this->runJob($token, ['relink_only' => true]);
        $job = $this->jobs->findById($relink);
        self::assertContains($job['status'], ['completed', 'completed_with_warnings'], (string) $job['log_text'] . (string) $job['last_error'] . json_encode($this->protocols($relink), JSON_UNESCAPED_UNICODE));
        self::assertStringContainsString('opravného převodu vazeb', (string) $job['log_text']);
        $protocol = $this->protocols($relink)[0];
        self::assertTrue($protocol['agenda']['relink_only']);
        self::assertContains('relink_keeps_own_journal', array_column($protocol['preflight'], 'code'));
        self::assertNotContains('stock', array_column($protocol['steps'], 'key'), 'Opravný převod sklad nepřevádí.');
        self::assertSame($entries, $this->rows('journal_entries'), 'Opravný převod nezakládá zápisy.');
        self::assertSame($purchases, $this->rows('purchase_invoices'), 'Novější doklad z POHODY se nepřidá.');
        self::assertSame(1, $this->rows('journal_entries', "id = {$ownEntryId}"), 'Vlastní zápis uživatele zůstává.');
        self::assertSame($payments, $this->rows('invoice_payments'), 'Úhrady převzatých faktur se dopočítají.');
    }

    /** Opravný převod se týká jen účetnictví: u mezd job volbu ignoruje. */
    public function testRelinkOnlyIsIgnoredForPayrollAndNeedsTrue(): void
    {
        self::assertTrue(PohodaImportJobService::relinkOnly(['kind' => 'accounting', 'relink_only' => true]));
        self::assertTrue(PohodaImportJobService::relinkOnly(['relink_only' => true]));
        self::assertFalse(PohodaImportJobService::relinkOnly(['kind' => 'payroll', 'relink_only' => true]));
        self::assertFalse(PohodaImportJobService::relinkOnly(['kind' => 'accounting', 'relink_only' => 'yes']));
        self::assertFalse(PohodaImportJobService::relinkOnly(['kind' => 'accounting']));
    }

    /**
     * Náhled průvodce u firmy převedené z POHODY nabídne opravný převod a ukáže jeho kontrolu:
     * vlastní zápisy v MyÚčtu jsou u něj jen informace, u řádného převodu chyba.
     */
    public function testPreviewOffersRelinkOnlyForConvertedCompany(): void
    {
        $token = $this->upload();
        $before = $this->show($token);
        self::assertFalse($before['pohoda_converted']);
        self::assertSame([], $before['relink_preflight']);

        $this->convertedCompanyWithOwnEntry();
        $after = $this->show($token);
        $year = (string) SyntheticPohodaExport::YEAR;
        self::assertTrue($after['pohoda_converted']);
        self::assertContains('journal_not_empty', array_column($after['preflight'][$year], 'code'));
        $relinkCodes = array_column($after['relink_preflight'][$year], 'code');
        self::assertContains('relink_keeps_own_journal', $relinkCodes);
        self::assertNotContains('journal_not_empty', $relinkCodes);
        self::assertSame([], array_filter($after['relink_preflight'][$year], static fn (array $m): bool => $m['level'] === 'error'));
    }

    /** Spuštění průvodce: opravný převod u mezd a neplatná hodnota volby skončí 422 dřív, než vznikne job. */
    public function testStartRejectsInvalidRelinkOnly(): void
    {
        $token = $this->upload();
        $payroll = $this->start($token, ['mode' => 'dry_run', 'years' => [SyntheticPohodaExport::YEAR], 'kind' => 'payroll', 'relink_only' => true]);
        self::assertSame([422, 'invalid_relink_only'], [$payroll->getStatusCode(), self::json($payroll)['error']['code']]);
        $invalid = $this->start($token, ['mode' => 'dry_run', 'years' => [SyntheticPohodaExport::YEAR], 'relink_only' => 'snad']);
        self::assertSame([422, 'invalid_relink_only'], [$invalid->getStatusCode(), self::json($invalid)['error']['code']]);
    }

    /** CLI nanečisto z ZIP exportu: protokol vypíše, nic nezapíše; s --commit vazby doplní. */
    public function testCliDryRunWritesNothingAndCommitRestoresLinks(): void
    {
        [$entries, $purchases, $payments] = $this->convertedCompanyWithOwnEntry();
        $zip = $this->zip(SyntheticPohodaExport::write($this->tmp . '/cli', true));
        $runner = $this->container->get(PohodaRelinkRunner::class);

        $out = [];
        $exit = $runner->run($this->supplierId, $zip, false, null, $this->userId, static function (string $line) use (&$out): void { $out[] = $line; });
        $text = implode("\n", $out);
        self::assertSame(PohodaRelinkRunner::EXIT_OK, $exit, $text);
        self::assertStringContainsString('[NANEČISTO]', $text);
        self::assertStringContainsString('payments', $text);
        self::assertStringContainsString('relink_skipped=', $text);
        self::assertStringContainsString('Nic nezměněno', $text);
        self::assertSame(0, $this->rows('invoice_payments'), 'Zkouška nanečisto nic nezapíše.');
        self::assertSame($entries, $this->rows('journal_entries'));
        self::assertSame(1, $this->rows('pohoda_imports', "mode = 'dry_run'"), 'Běh je v historii převodů.');

        $out = [];
        $exit = $runner->run($this->supplierId, $this->tmp . '/cli/' . SyntheticPohodaExport::ICO . '_' . SyntheticPohodaExport::YEAR, true, null, $this->userId,
            static function (string $line) use (&$out): void { $out[] = $line; });
        self::assertSame(PohodaRelinkRunner::EXIT_OK, $exit, implode("\n", $out));
        self::assertSame($payments, $this->rows('invoice_payments'));
        self::assertSame($entries, $this->rows('journal_entries'));
        self::assertSame($purchases, $this->rows('purchase_invoices'));
    }

    /** CLI: firma bez převodu z POHODY skončí chybou kontroly, chybějící export neplatným zadáním. */
    public function testCliFailsWithoutConversionAndOnMissingExport(): void
    {
        $runner = $this->container->get(PohodaRelinkRunner::class);
        $out = [];
        $collect = static function (string $line) use (&$out): void { $out[] = $line; };
        $exit = $runner->run($this->supplierId, SyntheticPohodaExport::write($this->tmp . '/none'), false, null, $this->userId, $collect);
        self::assertSame(PohodaRelinkRunner::EXIT_FAILED, $exit);
        self::assertStringContainsString('deník převodu tu chybí', implode("\n", $out));

        self::assertSame(PohodaRelinkRunner::EXIT_INVALID, $runner->run($this->supplierId, $this->tmp . '/missing.zip', false, null, null, $collect));
        self::assertSame(PohodaRelinkRunner::EXIT_INVALID, $runner->run($this->supplierId, $this->tmp . '/none', false, SyntheticPohodaExport::YEAR + 5, null, $collect));
    }

    /** CLI skript: bez povinných parametrů skončí kódem 2 a nápovědou. */
    public function testCliScriptRejectsMissingArguments(): void
    {
        $script = dirname(__DIR__, 3) . '/bin/pohoda-relink.php';
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' --commit 2>&1', $output, $code);
        self::assertSame(2, $code);
        self::assertStringContainsString('--supplier=ID', implode("\n", $output));
    }

    /**
     * Firma převedená z POHODY, která se dál účtuje v MyÚčtu (vlastní zápis), a ztracené úhrady.
     *
     * @return array{int,int,int,int} zápisy, přijaté doklady, úhrady před ztrátou, id vlastního zápisu
     */
    private function convertedCompanyWithOwnEntry(): array
    {
        $first = $this->importer->run($this->supplierId, $this->userId, PohodaExport::open(SyntheticPohodaExport::write($this->tmp . '/a')), false);
        self::assertFalse($first->hasErrors(), (string) json_encode($first->toArray()['steps'], JSON_UNESCAPED_UNICODE));
        $payments = $this->rows('invoice_payments');
        self::assertGreaterThan(0, $payments);
        $pdo = $this->db->pdo();
        $periodId = (int) $pdo->query("SELECT id FROM accounting_periods WHERE supplier_id = {$this->supplierId} ORDER BY starts_on DESC LIMIT 1")->fetchColumn();
        $account = fn (string $code): int => (int) $pdo->query("SELECT id FROM chart_of_accounts WHERE supplier_id = {$this->supplierId} AND account_code = '{$code}'")->fetchColumn();
        $pdo->prepare("INSERT INTO journal_entries (supplier_id, period_id, entry_date, description, source_type, posted_at) VALUES (?, ?, ?, 'Vlastní zápis v MyÚčtu', 'manual', NOW())")
            ->execute([$this->supplierId, $periodId, SyntheticPohodaExport::YEAR . '-11-30']);
        $entryId = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO journal_entry_lines (entry_id, supplier_id, account_id, side, amount, line_no) VALUES (?, ?, ?, 'debit', 100, 1), (?, ?, ?, 'credit', 100, 2)")
            ->execute([$entryId, $this->supplierId, $account('518000') ?: $account('518'), $entryId, $this->supplierId, $account('321001') ?: $account('321')]);
        $entries = $this->rows('journal_entries');
        $purchases = $this->rows('purchase_invoices');
        $pdo->prepare('DELETE FROM invoice_payments WHERE supplier_id = ?')->execute([$this->supplierId]);
        return [$entries, $purchases, $payments, $entryId];
    }

    /** Novější export (s majetkem a dalším dokladem) nahraný do průvodce. */
    private function upload(): string
    {
        $token = PohodaUploads::newToken();
        $this->uploads[] = [$this->supplierId, $token];
        $root = PohodaUploads::exportDir($this->supplierId, $token);
        SyntheticPohodaExport::write($root, true);
        PohodaUploads::writeState($this->supplierId, $token, ['file_name' => 'pohoda_export.zip', 'size' => 1, 'received' => 1,
            'status' => PohodaUploads::STATUS_READY, 'uploaded_by' => $this->userId, 'job_id' => null, 'error' => null]);
        PohodaUploads::writeMeta($this->supplierId, $token, ['token' => $token, 'file_name' => 'pohoda_export.zip', 'sha256' => str_repeat('0', 64),
            'agendas' => PohodaExport::overview($root)]);
        return $token;
    }

    /** @param array<string,mixed> $extra */
    private function runJob(string $token, array $extra): int
    {
        $jobId = $this->jobs->create($this->supplierId, PohodaImportJobService::SOURCE, [
            'token' => $token, 'mode' => 'import', 'ico' => SyntheticPohodaExport::ICO, 'kind' => 'accounting',
            'years' => [SyntheticPohodaExport::YEAR],
        ] + $extra, $this->userId);
        $this->container->get(PohodaImportJobService::class)->run($jobId);
        return $jobId;
    }

    /** @return list<array<string,mixed>> */
    private function protocols(int $jobId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT protocol FROM pohoda_imports WHERE job_id = ? ORDER BY id');
        $stmt->execute([$jobId]);
        return array_map(static fn (string $p): array => (array) json_decode($p, true), $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** @return list<string> */
    private function protocolCodes(int $jobId): array
    {
        $codes = [];
        foreach ($this->protocols($jobId) as $protocol) {
            foreach ($protocol['steps'] ?? [] as $step) {
                array_push($codes, ...array_column($step['messages'], 'code'));
            }
        }
        return $codes;
    }

    /** @return array<string,mixed> */
    private function show(string $token): array
    {
        $response = $this->container->get(PohodaMigrationAction::class)->show($this->request('GET', []), (new ResponseFactory())->createResponse(), ['token' => $token]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        return self::json($response);
    }

    /** @param array<string,mixed> $body */
    private function start(string $token, array $body): ResponseInterface
    {
        return $this->container->get(PohodaMigrationAction::class)->start($this->request('POST', $body), (new ResponseFactory())->createResponse(), ['token' => $token]);
    }

    /** @param array<string,mixed> $body */
    private function request(string $method, array $body): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest($method, '/api/admin/imports/pohoda/uploads')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute('auth.effective_role', new EffectiveRole(9, 'Test', 'staff', true, ['utilities.import' => 2]))
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId])
            ->withParsedBody($body);
    }

    /** @return array<string,mixed> */
    private static function json(ResponseInterface $response): array
    {
        return (array) json_decode((string) $response->getBody(), true);
    }

    private function zip(string $agendaDir): string
    {
        $path = $this->tmp . '/export.zip';
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        foreach (glob($agendaDir . '/*') ?: [] as $file) {
            $zip->addFile($file, basename($agendaDir) . '/' . basename($file));
        }
        $zip->close();
        return $path;
    }

    private function rows(string $table, string $where = '1 = 1'): int
    {
        $stmt = $this->db->pdo()->prepare("SELECT COUNT(*) FROM {$table} WHERE supplier_id = ? AND {$where}");
        $stmt->execute([$this->supplierId]);
        return (int) $stmt->fetchColumn();
    }

    private function supplier(): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, ic, dic, is_vat_payer, vat_period, default_currency_id, default_vat_rate_id, accounting_mode)
             VALUES (?, "Účetní 7", "Brno", "60200", ?, "prevod@example.invalid", ?, ?, 1, "monthly", ?, ?, "tax_evidence")'
        )->execute([SyntheticPohodaExport::NAME, $this->czId, SyntheticPohodaExport::ICO, 'CZ' . SyntheticPohodaExport::ICO, $this->currencyId, $this->vatRateId]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
             VALUES (?, 'CZK', 'CZK', 'Kč', 'Česká koruna', 'Czech Koruna', 2, 1, 1)"
        )->execute([$id]);
        $pdo->prepare('UPDATE supplier SET default_currency_id = ? WHERE id = ?')->execute([(int) $pdo->lastInsertId(), $id]);
        return $id;
    }
}
