<?php

declare(strict_types=1);

namespace MyInvoice\Action\Document;

use MyInvoice\Http\Json;
use MyInvoice\Repository\DocumentFolderRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Document\DocumentException;
use MyInvoice\Service\Document\DocumentIngestService;
use MyInvoice\Service\Document\DocumentStorage;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

/**
 * POST /api/documents — multipart upload jednoho/více souborů (field `file` /
 * `file[]`). Volitelně:
 *   - folder_id      cílová složka (NULL = root)
 *   - zip_mode       'explode' (rozbalit ZIP) | 'keep' (uložit jako ZIP)
 *   - relpaths[]     relativní cesty složek zarovnané s file[] (upload adresáře
 *                    z prohlížeče přes webkitdirectory) — rekonstruují strom
 *
 * ZFO se rozbalí automaticky (metadata zprávy + přílohy jako děti).
 */
final class UploadDocumentAction
{
    use DocumentActionTrait;

    private const MAX_FILES_PER_REQUEST = 2000;

    public function __construct(
        private readonly DocumentIngestService $ingest,
        private readonly DocumentStorage $storage,
        private readonly DocumentFolderRepository $folders,
        private readonly ActivityLogger $logger,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $sid = $this->supplierId($request);
        if ($sid <= 0) {
            return Json::error($response, 'no_supplier', 'Chybí kontext dodavatele.', 400);
        }
        $userId = $this->userId($request);
        $ip = $this->clientIp($request);
        $ua = $request->getHeaderLine('User-Agent');

        $body = (array) $request->getParsedBody();
        $baseFolderId = $this->optInt($body['folder_id'] ?? null);
        if ($baseFolderId !== null && $this->folders->find($baseFolderId, $sid) === null) {
            return Json::error($response, 'folder_not_found', 'Cílová složka nenalezena.', 404);
        }
        $zipMode = ($body['zip_mode'] ?? 'keep') === 'explode' ? 'explode' : 'keep';

        $relpaths = [];
        if (isset($body['relpaths']) && is_array($body['relpaths'])) {
            $relpaths = array_values($body['relpaths']);
        }

        $files = $request->getUploadedFiles();
        $list = [];
        if (isset($files['file'])) {
            $list = is_array($files['file']) ? array_values($files['file']) : [$files['file']];
        }
        if ($list === []) {
            return Json::error($response, 'no_file', 'Žádný soubor nebyl odeslán.', 400);
        }
        if (count($list) > self::MAX_FILES_PER_REQUEST) {
            return Json::error($response, 'too_many_files',
                'Příliš mnoho souborů najednou (max ' . self::MAX_FILES_PER_REQUEST . '). Použij ZIP.', 413);
        }

        $createdTotal = 0;
        // Dokumenty „nejvyšší úrovně" — co volající může rovnou navázat na doklad.
        // U ZFO je to obálka zprávy, ne její přílohy (ty jsou její děti).
        $rootIds = [];
        $skipped = [];
        $errors = [];

        foreach ($list as $idx => $file) {
            if (!$file instanceof UploadedFileInterface) continue;
            $originalName = trim((string) $file->getClientFilename());
            if ($file->getError() !== UPLOAD_ERR_OK) {
                $errors[] = ['name' => $originalName, 'reason' => 'upload_error_' . $file->getError()];
                continue;
            }
            if ($originalName === '') {
                $errors[] = ['name' => '?', 'reason' => 'no_filename'];
                continue;
            }

            // Cílová složka — rekonstruuj z relativní cesty (upload adresáře).
            $targetFolder = $baseFolderId;
            $rel = isset($relpaths[$idx]) ? trim(str_replace('\\', '/', (string) $relpaths[$idx]), '/') : '';
            if ($rel !== '') {
                $segments = array_values(array_filter(
                    array_map([$this->storage, 'sanitizeFilename'], explode('/', $rel)),
                    static fn(string $s): bool => $s !== '' && $s !== '.' && $s !== '..',
                ));
                $targetFolder = $this->ingest->ensureFolderPath($sid, $baseFolderId, $segments, $userId);
            }

            $tmp = $this->storage->tmpPath($sid);
            try {
                $file->moveTo($tmp);
            } catch (\Throwable $e) {
                $errors[] = ['name' => $originalName, 'reason' => 'move_failed'];
                @unlink($tmp);
                continue;
            }

            try {
                $res = $this->ingest->ingestUploadedTemp($tmp, $sid, $targetFolder, $originalName, $userId, $zipMode);
                $createdTotal += count($res['created_ids']);
                $rootIds = array_merge($rootIds, $res['container_id'] !== null ? [(int) $res['container_id']] : $res['created_ids']);
                $skipped = array_merge($skipped, $res['skipped']);
                foreach ($res['created_ids'] as $newId) {
                    $this->logger->log('document.uploaded', $userId, 'document', $newId,
                        ['original_name' => $originalName, 'kind' => $res['kind']], $ip, $ua, $sid);
                }
            } catch (DocumentException $e) {
                @unlink($tmp);
                $errors[] = ['name' => $originalName, 'reason' => $e->errorCode];
            } catch (\Throwable $e) {
                @unlink($tmp);
                $errors[] = ['name' => $originalName, 'reason' => 'ingest_failed'];
            }
        }

        if ($createdTotal === 0 && $errors !== []) {
            return Json::error($response, 'upload_failed', 'Žádný soubor se nepodařilo nahrát.', 400, ['errors' => $errors]);
        }

        return Json::ok($response, [
            'created' => $createdTotal,
            'root_ids' => $rootIds,
            'skipped' => $skipped,
            'errors'  => $errors,
        ]);
    }
}
