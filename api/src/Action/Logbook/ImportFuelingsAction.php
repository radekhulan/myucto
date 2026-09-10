<?php

declare(strict_types=1);

namespace MyInvoice\Action\Logbook;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Logbook\FuelingImportService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Hromadný import tankování z CSV / XLSX.
 *   POST /api/logbook/fuelings/import   (multipart/form-data: file, dry_run=1 → jen náhled)
 */
final class ImportFuelingsAction
{
    private const MAX_BYTES = 10 * 1024 * 1024;

    public function __construct(
        private readonly FuelingImportService $importer,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        if ($supplierId === 0) return Json::error($response, 'no_supplier', 'Chybí supplier kontext.', 400);

        $file = $this->firstFile($request->getUploadedFiles());
        if ($file === null) return Json::error($response, 'no_file', 'Nahrajte CSV nebo XLSX soubor.', 400);
        if ($file->getError() !== UPLOAD_ERR_OK) {
            return Json::error($response, 'upload_error', 'Chyba při nahrávání souboru.', 400);
        }
        if ((int) ($file->getSize() ?? 0) > self::MAX_BYTES) {
            return Json::error($response, 'too_large', 'Soubor je příliš velký (max 10 MB).', 413);
        }

        $fields = (array) ($request->getParsedBody() ?? []);
        $dryRun = in_array((string) ($fields['dry_run'] ?? $request->getQueryParams()['dry_run'] ?? ''), ['1', 'true'], true);
        $name = $file->getClientFilename() ?? 'import.csv';
        $content = (string) $file->getStream()->getContents();

        try {
            $report = $this->importer->import($supplierId, $this->userId($request), $content, $name, $dryRun);
        } catch (\Throwable $e) {
            return Json::error($response, 'import_failed', $e->getMessage(), 500);
        }
        if (empty($report['ok'])) {
            return Json::error($response, 'import_failed', (string) ($report['error'] ?? 'Import selhal.'), 400);
        }

        if (!$dryRun) {
            $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
            $this->logger->log('fuelings.imported', $this->userId($request), 'fueling', null, [
                'file' => $name, 'created' => $report['created'], 'updated' => $report['updated'],
                'duplicates' => $report['duplicates'], 'failed' => $report['failed'],
            ], $ip, $request->getHeaderLine('User-Agent'));
        }

        return Json::ok($response, $report);
    }

    /** @param array<string, UploadedFileInterface|array<int,UploadedFileInterface>> $uploads */
    private function firstFile(array $uploads): ?UploadedFileInterface
    {
        foreach ($uploads as $node) {
            if ($node instanceof UploadedFileInterface) return $node;
            if (is_array($node)) {
                foreach ($node as $sub) {
                    if ($sub instanceof UploadedFileInterface) return $sub;
                }
            }
        }
        return null;
    }

    private function userId(Request $request): ?int
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        return (int) ($user['id'] ?? 0) ?: null;
    }
}
