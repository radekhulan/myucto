<?php

declare(strict_types=1);

namespace MyInvoice\Action\Admin;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Import\InvoiceImportService;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Import vystavených faktur z Pohoda XML / ISDOC.
 *
 *   POST /api/admin/import
 *   multipart/form-data: files[]
 *
 * Podporuje:
 *   - .xml (Pohoda dataPack)
 *   - .isdoc (ISDOC 6.x)
 *   - .isdocx (ISDOC Package — ZIP s vnitřním .isdoc + PDF, issue #136)
 *   - .pdf (PDF/A-3 s embedded ISDOC / .isdocx přílohou)
 *   - .zip s libovolným počtem .xml / .isdoc uvnitř
 *
 * Vrací JSON s reportem (created/skipped/failed per soubor).
 */
final class ImportAction
{
    /** Limity proti DoS (souhrnně k limitům v InvoiceImportService::unzip()). */
    private const MAX_FILES        = 50;
    private const MAX_PER_FILE     = 20 * 1024 * 1024;  // 20 MiB (ZIP nebo XML)
    private const MAX_TOTAL_UPLOAD = 50 * 1024 * 1024;  // 50 MiB

    public function __construct(
        private readonly InvoiceImportService $importer,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!RequestAuthorization::allows($request, 'utilities.import', AccessLevel::WRITE)) {
            return Json::error($response, 'forbidden', 'Pouze admin nebo účetní.', 403);
        }

        $supplierId = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        if ($supplierId === 0) {
            return Json::error($response, 'no_supplier', 'Chybí supplier kontext.', 400);
        }

        $uploads = $request->getUploadedFiles();
        try {
            $files = $this->collectFiles($uploads);
        } catch (\RuntimeException $e) {
            return Json::error($response, 'upload_too_large', $e->getMessage(), 413);
        }
        if (empty($files)) {
            return Json::error($response, 'no_files', 'Nahrajte alespoň jeden soubor.', 400);
        }

        // `?kind=auto|issued|purchase` — výchozí 'auto' = per-soubor detekce dle IČO
        // (vydaná vs přijatá faktura). Backward compat: bez parametru behaviour stejný
        // jako dřív (auto fallback dispatches issued cestu pro non-purchase soubory).
        $kind = (string) ($request->getQueryParams()['kind'] ?? 'auto');
        if (!in_array($kind, ['auto', 'issued', 'purchase'], true)) {
            return Json::error($response, 'invalid_kind', "Neznámý kind '{$kind}', použij auto|issued|purchase.", 400);
        }

        // `?purchase_status=received|draft` — v jakém stavu mají vzniknout PŘIJATÉ doklady.
        // Výchozí `received`: doklad z ISDOC/Pohoda XML je úplný, kdežto koncept se
        // nezapočítává do nákladů ani závazků, takže po dávkové migraci by účetní musela
        // stovky dokladů otevřít jeden po druhém. Koncept si lze vyžádat explicitně.
        $purchaseStatus = ((string) ($request->getQueryParams()['purchase_status'] ?? 'received')) === 'draft'
            ? 'draft'
            : 'received';

        try {
            $report = $this->importer->importBundle(
                $files, $supplierId, (int) ($user['id'] ?? 0), $kind,
                null, null, $purchaseStatus,
            );
        } catch (\Throwable $e) {
            return Json::error($response, 'import_failed', $e->getMessage(), 500);
        }

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('invoices.imported', $user['id'] ?? null, null, null, [
            'files'   => count($files),
            'kind'    => $kind,
            'purchase_status' => $purchaseStatus,
            'summary' => $report['summary'],
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, $report);
    }

    /**
     * @param array<string, UploadedFileInterface|array<int,UploadedFileInterface>> $uploads
     * @return list<array{name:string, content:string}>
     */
    private function collectFiles(array $uploads): array
    {
        $out = [];
        $totalBytes = 0;
        $walk = function ($node) use (&$walk, &$out, &$totalBytes): void {
            if ($node instanceof UploadedFileInterface) {
                if ($node->getError() !== UPLOAD_ERR_OK) return;
                if (count($out) >= self::MAX_FILES) {
                    throw new \RuntimeException('Příliš mnoho souborů (max ' . self::MAX_FILES . ').');
                }
                $size = (int) ($node->getSize() ?? 0);
                if ($size > self::MAX_PER_FILE) {
                    throw new \RuntimeException('Soubor "' . ($node->getClientFilename() ?? 'upload') . '" je příliš velký (max ' . self::MAX_PER_FILE . ' B).');
                }
                $totalBytes += $size;
                if ($totalBytes > self::MAX_TOTAL_UPLOAD) {
                    throw new \RuntimeException('Celková velikost uploadu překračuje povolený limit (max ' . self::MAX_TOTAL_UPLOAD . ' B).');
                }
                $out[] = [
                    'name'    => $node->getClientFilename() ?? 'upload',
                    'content' => (string) $node->getStream()->getContents(),
                ];
            } elseif (is_array($node)) {
                foreach ($node as $sub) $walk($sub);
            }
        };
        foreach ($uploads as $node) $walk($node);
        return $out;
    }
}
