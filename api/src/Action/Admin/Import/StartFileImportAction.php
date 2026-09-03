<?php

declare(strict_types=1);

namespace MyInvoice\Action\Admin\Import;

use MyInvoice\Bootstrap;
use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\BackgroundProcess;
use MyInvoice\Service\Import\FileImportJobService;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

/**
 * POST /api/admin/import/start?kind=auto|issued|purchase
 *   multipart/form-data: files[]
 *
 * Totéž co synchronní {@see \MyInvoice\Action\Admin\ImportAction}, ale na pozadí:
 * soubory se odloží na disk, vznikne `import_jobs` řádek (source `file_import`) a běh
 * převezme worker. UI polluje stav přes {@see ImportJobStatusAction} a po doběhnutí si
 * stáhne report přes {@see ImportJobReportAction}.
 *
 * Dávka z jiného systému má běžně tisíce dokladů — synchronní cesta na ni nestačí a
 * její utnutí uprostřed je horší než selhání, protože doklady zůstanou založené, ale
 * závěrečné kroky importu (dorovnání číselných řad, přepočet statistik klientů) už
 * neproběhnou. Synchronní endpoint zůstává pro malé dávky a pro API klienty, kteří
 * chtějí report rovnou v odpovědi; importuje ho tatáž služba.
 */
final class StartFileImportAction
{
    /**
     * Limity jsou vyšší než u synchronní cesty — právě velké dávky jsou důvod, proč
     * job existuje. Strop pořád platí: nahrává se do úložiště instance.
     */
    private const MAX_FILES        = 200;
    private const MAX_PER_FILE     = 100 * 1024 * 1024;  // 100 MiB
    private const MAX_TOTAL_UPLOAD = 300 * 1024 * 1024;  // 300 MiB

    public function __construct(
        private readonly ImportJobRepository $jobs,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!RequestAuthorization::allows($request, 'utilities.import', AccessLevel::WRITE)) {
            return Json::error($response, 'forbidden', 'Pouze admin nebo účetní.', 403);
        }

        $supplierId = SupplierGuard::currentId($request);
        if ($supplierId === 0) {
            return Json::error($response, 'no_supplier', 'Chybí supplier kontext.', 400);
        }

        $kind = (string) ($request->getQueryParams()['kind'] ?? 'auto');
        if (!in_array($kind, ['auto', 'issued', 'purchase'], true)) {
            return Json::error($response, 'invalid_kind', "Neznámý kind '{$kind}', použij auto|issued|purchase.", 400);
        }

        // Viz {@see \MyInvoice\Action\Admin\ImportAction} — výchozí `received`, koncept
        // jen na výslovné přání. Obě cesty musí odpovídat, jinak by týž soubor skončil
        // jinak podle toho, jestli běžel synchronně nebo na pozadí.
        $purchaseStatus = ((string) ($request->getQueryParams()['purchase_status'] ?? 'received')) === 'draft'
            ? 'draft'
            : 'received';

        $uploads = [];
        $walk = function ($node) use (&$walk, &$uploads): void {
            if ($node instanceof UploadedFileInterface) {
                if ($node->getError() === UPLOAD_ERR_OK) $uploads[] = $node;
            } elseif (is_array($node)) {
                foreach ($node as $sub) $walk($sub);
            }
        };
        foreach ($request->getUploadedFiles() as $node) $walk($node);

        if ($uploads === []) {
            return Json::error($response, 'no_files', 'Nahrajte alespoň jeden soubor.', 400);
        }
        if (count($uploads) > self::MAX_FILES) {
            return Json::error($response, 'upload_too_large', 'Příliš mnoho souborů (max ' . self::MAX_FILES . ').', 413);
        }
        $total = 0;
        foreach ($uploads as $u) {
            $size = (int) ($u->getSize() ?? 0);
            if ($size > self::MAX_PER_FILE) {
                return Json::error($response, 'upload_too_large',
                    'Soubor "' . ($u->getClientFilename() ?? 'upload') . '" je příliš velký (max ' . self::MAX_PER_FILE . ' B).', 413);
            }
            $total += $size;
        }
        if ($total > self::MAX_TOTAL_UPLOAD) {
            return Json::error($response, 'upload_too_large',
                'Celková velikost uploadu překračuje povolený limit (max ' . self::MAX_TOTAL_UPLOAD . ' B).', 413);
        }

        // Zaseknuté joby (mrtvý worker) by jinak navždy blokovaly nový start.
        $this->jobs->reapStale($supplierId, 'file_import');
        foreach ($this->jobs->listForTenant($supplierId, 'file_import', limit: 5) as $existing) {
            if (in_array($existing['status'], ['queued', 'running'], true)) {
                return Json::error($response, 'already_running',
                    "Import už běží (job #{$existing['id']}, stav: {$existing['status']}).", 409,
                    ['existing_job_id' => $existing['id']],
                );
            }
        }

        // Soubory musí přežít konec requestu — worker je čte až potom.
        $token = bin2hex(random_bytes(8));
        $dir = FileImportJobService::stagingDir($supplierId, $token);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return Json::error($response, 'storage_not_writable', 'Úložiště jobů není zapisovatelné.', 500);
        }

        $entries = [];
        foreach ($uploads as $i => $u) {
            // Do cesty jde jen index — jméno z uploadu se ukládá zvlášť jako metadata,
            // takže se přes ně nedá ukázat mimo adresář jobu.
            try {
                $u->moveTo($dir . '/' . $i . '.bin');
            } catch (\Throwable) {
                $this->purge($dir);
                return Json::error($response, 'move_failed', 'Uložení nahraných souborů selhalo.', 500);
            }
            $entries[$i] = ['name' => basename((string) ($u->getClientFilename() ?? ('soubor-' . $i)))];
        }

        $userId = (int) ($user['id'] ?? 0);
        $params = [
            'kind' => $kind,
            'purchase_status' => $purchaseStatus,
            'staging_dir' => $dir,
            'files' => $entries,
        ];
        $jobId = $this->jobs->create($supplierId, 'file_import', $params, $userId);

        // Chybějící migrace by MySQL v ne-striktním režimu uložila jako prázdný source
        // a job by uvízl navždy — radši ho hned zrušit a říct proč.
        $stored = $this->jobs->find($jobId, $supplierId);
        if ($stored === null || ($stored['source'] ?? '') !== 'file_import') {
            $this->jobs->delete($jobId, $supplierId);
            $this->purge($dir);
            return Json::error($response, 'migration_required',
                'Chybí databázová migrace pro import na pozadí — spusťte `php api/bin/migrate.php`.', 500);
        }

        BackgroundProcess::spawnPhp(
            Bootstrap::rootDir() . '/api/bin/import-worker.php',
            ['--job-id=' . $jobId],
            RuntimePaths::log('import-worker.log'),
            Bootstrap::rootDir(),
        );

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('import.file_started', $userId, 'import_job', $jobId,
            ['files' => count($entries), 'kind' => $kind, 'purchase_status' => $purchaseStatus],
            $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, [
            'job_id' => $jobId,
            'status' => 'queued',
            'files'  => count($entries),
            'kind'   => $kind,
        ], 201);
    }

    private function purge(string $dir): void
    {
        foreach ((array) glob($dir . '/*') as $f) {
            if (is_file($f)) @unlink($f);
        }
        @rmdir($dir);
    }
}
