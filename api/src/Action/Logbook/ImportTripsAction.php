<?php

declare(strict_types=1);

namespace MyInvoice\Action\Logbook;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Logbook\TripImportService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Import knihy jízd z CSV / XLSX.
 *   POST /api/logbook/trips/import   (multipart/form-data: file)
 */
final class ImportTripsAction
{
    private const MAX_BYTES = 10 * 1024 * 1024; // 10 MiB

    public function __construct(
        private readonly TripImportService $importer,
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

        $name = $file->getClientFilename() ?? 'import.csv';
        $content = (string) $file->getStream()->getContents();

        try {
            $report = $this->importer->import($supplierId, $this->userId($request), $content, $name);
        } catch (\Throwable $e) {
            return Json::internalError($response, 'import_failed', $e->getMessage(), 500);
        }
        if (empty($report['ok'])) {
            return Json::error($response, 'import_failed', (string) ($report['error'] ?? 'Import selhal.'), 400);
        }

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('trips.imported', $this->userId($request), 'trip', null, [
            'file' => $name, 'created' => $report['created'], 'failed' => $report['failed'],
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, $report);
    }

    /**
     * @param array<string, UploadedFileInterface|array<int,UploadedFileInterface>> $uploads
     */
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
