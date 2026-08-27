<?php

declare(strict_types=1);

namespace MyInvoice\Action\Document;

use MyInvoice\Http\Json;
use MyInvoice\Repository\DocumentFolderRepository;
use MyInvoice\Service\ActivityLogger;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** CRUD nad stromem složek sekce Dokumenty. */
final class FoldersAction
{
    use DocumentActionTrait;

    public function __construct(
        private readonly DocumentFolderRepository $folders,
        private readonly ActivityLogger $logger,
    ) {}

    /** GET /api/document-folders?parent_id= — když parent_id chybí, vrátí celý strom. */
    public function list(Request $request, Response $response): Response
    {
        $sid = $this->supplierId($request);
        $q = $request->getQueryParams();
        if (array_key_exists('parent_id', $q)) {
            $parentId = $this->optInt($q['parent_id']);
            return Json::ok($response, ['folders' => $this->folders->listChildren($sid, $parentId, $this->viewer($request))]);
        }
        return Json::ok($response, ['folders' => $this->folders->listAll($sid)]);
    }

    /** POST /api/document-folders {name, parent_id?} */
    public function create(Request $request, Response $response): Response
    {
        $sid = $this->supplierId($request);
        $body = (array) $request->getParsedBody();
        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            return Json::error($response, 'name_required', 'Název složky je povinný.', 400);
        }
        $name = mb_substr($name, 0, 255);
        $parentId = $this->optInt($body['parent_id'] ?? null);

        if ($parentId !== null && $this->folders->find($parentId, $sid) === null) {
            return Json::error($response, 'parent_not_found', 'Nadřazená složka nenalezena.', 404);
        }
        if ($this->folders->existsByName($sid, $parentId, $name)) {
            return Json::error($response, 'duplicate', 'Složka s tímto názvem už existuje.', 409);
        }
        $id = $this->folders->create($sid, $parentId, $name, $this->userId($request));
        $this->logger->log('document.folder_created', $this->userId($request), 'document_folder', $id,
            ['name' => $name, 'parent_id' => $parentId], $this->clientIp($request), $request->getHeaderLine('User-Agent'), $sid);
        return Json::ok($response, ['id' => $id, 'folders' => $this->folders->listChildren($sid, $parentId, $this->viewer($request))]);
    }

    /** PATCH /api/document-folders/{id} {name} */
    public function rename(Request $request, Response $response, array $args): Response
    {
        $sid = $this->supplierId($request);
        $id = (int) ($args['id'] ?? 0);
        $folder = $this->folders->find($id, $sid);
        if ($folder === null) {
            return Json::error($response, 'not_found', 'Složka nenalezena.', 404);
        }
        if (!$this->folders->canMutateSubtree(
            $sid,
            $this->folders->descendantIds($id, $sid),
            $this->viewer($request),
        )) {
            return Json::error($response, 'folder_access_denied', 'Složku nelze změnit.', 403);
        }
        $body = (array) $request->getParsedBody();
        $name = mb_substr(trim((string) ($body['name'] ?? '')), 0, 255);
        if ($name === '') {
            return Json::error($response, 'name_required', 'Název složky je povinný.', 400);
        }
        if ($this->folders->existsByName($sid, $this->optInt($folder['parent_id']), $name, $id)) {
            return Json::error($response, 'duplicate', 'Složka s tímto názvem už existuje.', 409);
        }
        $this->folders->rename($id, $sid, $name);
        $this->logger->log('document.folder_renamed', $this->userId($request), 'document_folder', $id,
            ['name' => $name], $this->clientIp($request), $request->getHeaderLine('User-Agent'), $sid);
        return Json::ok($response, ['ok' => true]);
    }

    /** POST /api/document-folders/{id}/move {parent_id|null} */
    public function move(Request $request, Response $response, array $args): Response
    {
        $sid = $this->supplierId($request);
        $id = (int) ($args['id'] ?? 0);
        if ($this->folders->find($id, $sid) === null) {
            return Json::error($response, 'not_found', 'Složka nenalezena.', 404);
        }
        if (!$this->folders->canMutateSubtree(
            $sid,
            $this->folders->descendantIds($id, $sid),
            $this->viewer($request),
        )) {
            return Json::error($response, 'folder_access_denied', 'Složku nelze změnit.', 403);
        }
        $body = (array) $request->getParsedBody();
        $newParent = $this->optInt($body['parent_id'] ?? null);

        if ($newParent !== null) {
            if ($this->folders->find($newParent, $sid) === null) {
                return Json::error($response, 'parent_not_found', 'Cílová složka nenalezena.', 404);
            }
            // Zákaz přesunu do sebe sama / vlastního potomka (cyklus).
            if (in_array($newParent, $this->folders->descendantIds($id, $sid), true)) {
                return Json::error($response, 'cycle', 'Složku nelze přesunout do sebe sama.', 400);
            }
        }
        $this->folders->move($id, $sid, $newParent);
        $this->logger->log('document.folder_moved', $this->userId($request), 'document_folder', $id,
            ['parent_id' => $newParent], $this->clientIp($request), $request->getHeaderLine('User-Agent'), $sid);
        return Json::ok($response, ['ok' => true]);
    }

    /** DELETE /api/document-folders/{id} — soft-delete podstromu (do koše). */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $sid = $this->supplierId($request);
        $id = (int) ($args['id'] ?? 0);
        if ($this->folders->find($id, $sid) === null) {
            return Json::error($response, 'not_found', 'Složka nenalezena.', 404);
        }
        $viewer = $this->viewer($request);
        $subtreeIds = $this->folders->descendantIds($id, $sid);
        if (!$this->folders->canMutateSubtree($sid, $subtreeIds, $viewer)) {
            return Json::error($response, 'folder_access_denied', 'Složku nelze odstranit.', 403);
        }
        if ($this->folders->containsRetainedEvidence($sid, $subtreeIds)) {
            return Json::error(
                $response,
                'folder_retained_evidence',
                'Složka obsahuje neměnný dokument a nelze ji odstranit.',
                409,
            );
        }
        $ids = $this->folders->softDeleteSubtree($id, $sid, $this->userId($request), $viewer);
        $this->logger->log('document.folder_trashed', $this->userId($request), 'document_folder', $id,
            ['folder_count' => count($ids)], $this->clientIp($request), $request->getHeaderLine('User-Agent'), $sid);
        return Json::ok($response, ['ok' => true, 'trashed_folders' => count($ids)]);
    }

    /** POST /api/document-folders/{id}/restore */
    public function restore(Request $request, Response $response, array $args): Response
    {
        $sid = $this->supplierId($request);
        $id = (int) ($args['id'] ?? 0);
        if ($this->folders->find($id, $sid, true) === null) {
            return Json::error($response, 'not_found', 'Složka nenalezena.', 404);
        }
        $this->folders->restore($id, $sid);
        return Json::ok($response, ['ok' => true]);
    }
}
