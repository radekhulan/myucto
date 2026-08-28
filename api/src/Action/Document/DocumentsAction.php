<?php

declare(strict_types=1);

namespace MyInvoice\Action\Document;

use MyInvoice\Http\Json;
use MyInvoice\Repository\Deletion\DeletionConflict;
use MyInvoice\Repository\Deletion\DocumentDeletionGuard;
use MyInvoice\Repository\DmsMessageRepository;
use MyInvoice\Repository\DocumentFolderRepository;
use MyInvoice\Repository\DocumentLinkRepository;
use MyInvoice\Repository\DocumentRepository;
use MyInvoice\Repository\DocumentTagRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Document\DocumentStorage;
use MyInvoice\Support\Pagination;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** Hlavní CRUD + metadata + koš + hromadné akce nad dokumenty. */
final class DocumentsAction
{
    use DocumentActionTrait;

    public function __construct(
        private readonly DocumentRepository $documents,
        private readonly DocumentFolderRepository $folders,
        private readonly DocumentTagRepository $tags,
        private readonly DocumentLinkRepository $links,
        private readonly DmsMessageRepository $dms,
        private readonly DocumentStorage $storage,
        private readonly ActivityLogger $logger,
        // Registr vazeb, které trvalé smazání dokladu blokují (mzdové doklady,
        // důkazy k exekucím, artefakty podání na finanční správu).
        private readonly DocumentDeletionGuard $deletionGuard,
    ) {}

    /** GET /api/documents?folder_id=&doc_type= */
    public function list(Request $request, Response $response): Response
    {
        $sid = $this->supplierId($request);
        $viewer = $this->viewer($request);
        $q = $request->getQueryParams();
        $folderId = array_key_exists('folder_id', $q) ? $this->optInt($q['folder_id']) : null;
        $docType = isset($q['doc_type']) ? (string) $q['doc_type'] : null;
        $tag = isset($q['tag']) ? trim((string) $q['tag']) : '';
        // Aditivní scope filtr (Firemní/Osobní taby, §6) — jen platné hodnoty; owner_user_id
        // ctíme jen pro admina (non-admin scopeClause stejně omezí na vlastní user doklady).
        $scopeFilter = (isset($q['scope']) && in_array($q['scope'], ['company', 'user'], true))
            ? (string) $q['scope'] : null;
        $ownerFilter = ($viewer->isAdmin && isset($q['owner_user_id'])) ? $this->optInt($q['owner_user_id']) : null;
        $p = Pagination::fromQuery($q, 50);

        // Filtr tagem je globální (napříč složkami) — vrátíme plochý (nestránkovaný)
        // seznam; kontrakt {data,meta} zůstává jednotný, jen meta.pages je vždy 1.
        if ($tag !== '') {
            $docs = $this->documents->listByTag($sid, $tag, $viewer);
            $meta = Pagination::meta(count($docs), 1, max(count($docs), 1));
            $meta['breadcrumb']     = [];
            $meta['folders']        = [];
            $meta['tag']            = $tag;
            $meta['max_file_bytes'] = $this->storage->maxFileBytes();
            return Json::ok($response, ['data' => $docs, 'meta' => $meta]);
        }

        $total = $this->documents->countInFolder($sid, $folderId, $viewer, $docType, $scopeFilter, $ownerFilter);
        $rows = $this->documents->listInFolder(
            $sid, $folderId, $viewer, $docType, $scopeFilter, $ownerFilter, $p['per_page'], $p['offset']
        );
        $meta = Pagination::meta($total, $p['page'], $p['per_page']);
        $meta['breadcrumb']           = $this->breadcrumb($sid, $folderId);
        $meta['folders']              = $this->folders->listChildren($sid, $folderId, $viewer);
        $meta['max_file_bytes']       = $this->storage->maxFileBytes();
        $meta['php_max_upload_bytes'] = $this->phpUploadLimit();
        return Json::ok($response, ['data' => $rows, 'meta' => $meta]);
    }

    /** Efektivní per-request limit PHP = min(post_max_size, upload_max_filesize); 0 = neomezeno. */
    private function phpUploadLimit(): int
    {
        $post = $this->iniBytes((string) ini_get('post_max_size'));
        $upload = $this->iniBytes((string) ini_get('upload_max_filesize'));
        $vals = array_filter([$post, $upload], static fn(int $v): bool => $v > 0);
        return $vals === [] ? 0 : min($vals);
    }

    private function iniBytes(string $v): int
    {
        $v = trim($v);
        if ($v === '') return 0;
        $unit = strtolower($v[strlen($v) - 1]);
        $num = (int) $v;
        return match ($unit) {
            'g' => $num * 1024 * 1024 * 1024,
            'm' => $num * 1024 * 1024,
            'k' => $num * 1024,
            default => $num,
        };
    }

    /** GET /api/documents/{id} */
    public function get(Request $request, Response $response, array $args): Response
    {
        $sid = $this->supplierId($request);
        $viewer = $this->viewer($request);
        $id = (int) ($args['id'] ?? 0);
        $doc = $this->documents->find($id, $sid, $viewer, true);
        if ($doc === null) {
            return Json::error($response, 'not_found', 'Dokument nenalezen.', 404);
        }
        $doc['tags']        = $this->tags->tagsForDocument($id);
        $doc['links']       = $this->links->linksForDocument($id, $sid);
        $doc['attachments'] = $this->documents->listChildren($id, $sid, $viewer);
        $doc['breadcrumb']  = $this->breadcrumb($sid, $doc['folder_id']);
        if ($doc['doc_type'] === 'zfo') {
            $doc['dms_message'] = $this->dms->findByDocument($id);
        }
        return Json::ok($response, $doc);
    }

    /** GET /api/documents/{id}/text?offset=&max_chars= */
    public function text(Request $request, Response $response, array $args): Response
    {
        $sid = $this->supplierId($request);
        $id = (int) ($args['id'] ?? 0);
        $query = $request->getQueryParams();
        $offset = max(0, (int) ($query['offset'] ?? 0));
        $maxChars = max(1000, min(50000, (int) ($query['max_chars'] ?? 20000)));
        $row = $this->documents->findExtractedText(
            $id,
            $sid,
            $this->viewer($request),
            $offset,
            $maxChars,
        );
        if ($row === null) {
            return Json::error($response, 'not_found', 'Dokument nenalezen.', 404);
        }

        $text = $row['content_text'];
        $totalChars = $row['total_chars'];

        return Json::ok($response, [
            'id' => $id,
            'text_status' => $row['text_status'],
            'offset' => $offset,
            'max_chars' => $maxChars,
            'total_chars' => $totalChars,
            'has_more' => $text !== null && $offset + mb_strlen($text) < $totalChars,
            'content' => $text,
        ]);
    }

    /** PATCH /api/documents/{id} {title?, description?, tags?} */
    public function update(Request $request, Response $response, array $args): Response
    {
        $sid = $this->supplierId($request);
        $id = (int) ($args['id'] ?? 0);
        $doc = $this->documents->find($id, $sid, $this->viewer($request));
        if ($doc === null) {
            return Json::error($response, 'not_found', 'Dokument nenalezen.', 404);
        }
        $body = (array) $request->getParsedBody();
        $title = array_key_exists('title', $body)
            ? mb_substr(trim((string) $body['title']), 0, 255)
            : (string) $doc['title'];
        if ($title === '') {
            return Json::error($response, 'title_required', 'Název je povinný.', 400);
        }
        $description = array_key_exists('description', $body)
            ? (trim((string) $body['description']) !== '' ? (string) $body['description'] : null)
            : $doc['description'];

        $this->documents->updateMeta($id, $sid, $title, $description);

        if (array_key_exists('tags', $body) && is_array($body['tags'])) {
            $this->tags->setTags($sid, $id, $body['tags']);
            $this->tags->purgeOrphans($sid);
        }
        $this->logger->log('document.updated', $this->userId($request), 'document', $id,
            ['title' => $title], $this->clientIp($request), $request->getHeaderLine('User-Agent'), $sid);

        return $this->get($request, $response, $args);
    }

    /** POST /api/documents/{id}/move {folder_id|null} */
    public function move(Request $request, Response $response, array $args): Response
    {
        $sid = $this->supplierId($request);
        $id = (int) ($args['id'] ?? 0);
        if ($this->documents->find($id, $sid, $this->viewer($request)) === null) {
            return Json::error($response, 'not_found', 'Dokument nenalezen.', 404);
        }
        $body = (array) $request->getParsedBody();
        $folderId = $this->optInt($body['folder_id'] ?? null);
        if ($folderId !== null && $this->folders->find($folderId, $sid) === null) {
            return Json::error($response, 'folder_not_found', 'Cílová složka nenalezena.', 404);
        }
        $this->documents->move($id, $sid, $folderId);
        return Json::ok($response, ['ok' => true]);
    }

    /** DELETE /api/documents/{id} — do koše. */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $sid = $this->supplierId($request);
        $id = (int) ($args['id'] ?? 0);
        if ($this->documents->find($id, $sid, $this->viewer($request)) === null) {
            return Json::error($response, 'not_found', 'Dokument nenalezen.', 404);
        }
        $this->documents->softDelete($id, $sid, $this->userId($request));
        $this->logger->log('document.trashed', $this->userId($request), 'document', $id,
            null, $this->clientIp($request), $request->getHeaderLine('User-Agent'), $sid);
        return Json::ok($response, ['ok' => true]);
    }

    /** POST /api/documents/{id}/restore */
    public function restore(Request $request, Response $response, array $args): Response
    {
        $sid = $this->supplierId($request);
        $id = (int) ($args['id'] ?? 0);
        if ($this->documents->find($id, $sid, $this->viewer($request), true) === null) {
            return Json::error($response, 'not_found', 'Dokument nenalezen.', 404);
        }
        $this->documents->restore($id, $sid);
        return Json::ok($response, ['ok' => true]);
    }

    /** GET /api/documents/search?q= */
    public function search(Request $request, Response $response): Response
    {
        $sid = $this->supplierId($request);
        $q = trim((string) ($request->getQueryParams()['q'] ?? ''));
        if (mb_strlen($q) < 2) {
            return Json::ok($response, ['documents' => [], 'query' => $q]);
        }
        return Json::ok($response, ['documents' => $this->documents->search($sid, $q, $this->viewer($request)), 'query' => $q]);
    }

    /** GET /api/documents/by-entity/{type}/{id} */
    public function byEntity(Request $request, Response $response, array $args): Response
    {
        $sid = $this->supplierId($request);
        $type = (string) ($args['type'] ?? '');
        $eid = (int) ($args['id'] ?? 0);
        if (!in_array($type, DocumentLinkRepository::ENTITY_TYPES, true)) {
            return Json::error($response, 'bad_entity', 'Neplatný typ entity.', 400);
        }
        return Json::ok($response, ['documents' => $this->documents->listByEntity($sid, $type, $eid, $this->viewer($request))]);
    }

    /** GET /api/documents/tags */
    public function listTags(Request $request, Response $response): Response
    {
        return Json::ok($response, ['tags' => $this->tags->listForSupplier($this->supplierId($request))]);
    }

    /** POST /api/documents/{id}/links {entity_type, entity_id} */
    public function addLink(Request $request, Response $response, array $args): Response
    {
        $sid = $this->supplierId($request);
        $id = (int) ($args['id'] ?? 0);
        if ($this->documents->find($id, $sid, $this->viewer($request)) === null) {
            return Json::error($response, 'not_found', 'Dokument nenalezen.', 404);
        }
        $body = (array) $request->getParsedBody();
        $type = (string) ($body['entity_type'] ?? '');
        $eid = (int) ($body['entity_id'] ?? 0);
        if (!in_array($type, DocumentLinkRepository::ENTITY_TYPES, true) || $eid <= 0) {
            return Json::error($response, 'bad_entity', 'Neplatná vazba.', 400);
        }
        // Ověř, že cílová entita patří aktuálnímu dodavateli — jinak by vznikla
        // dangling/cizí vazba (read-back je sice scoped, ale nezakládáme smetí).
        if (!$this->links->entityBelongsToSupplier($type, $eid, $sid)) {
            return Json::error($response, 'not_found', 'Propojená entita nenalezena.', 404);
        }
        $this->links->attach($sid, $id, $type, $eid);
        return Json::ok($response, ['links' => $this->links->linksForDocument($id, $sid)]);
    }

    /** DELETE /api/documents/{id}/links {entity_type, entity_id} */
    public function removeLink(Request $request, Response $response, array $args): Response
    {
        $sid = $this->supplierId($request);
        $id = (int) ($args['id'] ?? 0);
        if ($this->documents->find($id, $sid, $this->viewer($request)) === null) {
            return Json::error($response, 'not_found', 'Dokument nenalezen.', 404);
        }
        $body = (array) $request->getParsedBody();
        $q = $request->getQueryParams();
        $type = (string) ($body['entity_type'] ?? $q['entity_type'] ?? '');
        $eid = (int) ($body['entity_id'] ?? $q['entity_id'] ?? 0);
        $this->links->detach($sid, $id, $type, $eid);
        return Json::ok($response, ['links' => $this->links->linksForDocument($id, $sid)]);
    }

    /** GET /api/documents/trash */
    public function trash(Request $request, Response $response): Response
    {
        $sid = $this->supplierId($request);
        $viewer = $this->viewer($request);
        $p = Pagination::fromQuery($request->getQueryParams(), 50);

        $total = $this->documents->countTrash($sid, $viewer);
        $rows = $this->documents->listTrash($sid, $viewer, $p['per_page'], $p['offset']);
        $meta = Pagination::meta($total, $p['page'], $p['per_page']);
        $meta['folders'] = $this->folders->listTrashed($sid);
        return Json::ok($response, ['data' => $rows, 'meta' => $meta]);
    }

    /**
     * POST /api/documents/trash/empty — tvrdé smazání + dedup-aware mazání souborů.
     *
     * ── Proč to není jeden DELETE ─────────────────────────────────────────────
     * Býval. Mzdový modul ale přidal na `documents` cizí klíče RESTRICT (mzdové
     * doklady v DMS, důkazy k exekucím), takže jediný navázaný doklad shodil celý
     * příkaz — koš pak nešlo vysypat NIKDY, uživatel neměl jak zjistit který doklad
     * to je, a nemělo to obchvat. Dnes se blokované doklady vynechají, zbytek se
     * vysype a odpověď řekne, kolik jich zůstalo a proč.
     *
     * Součástí registru je i `tax_submission_artifacts` — ta na `documents`
     * kaskáduje, takže vysypání koše dosud mlčky mazalo důkaz o podání na finanční
     * správu. Viz {@see DocumentDeletionGuard}.
     */
    public function emptyTrash(Request $request, Response $response): Response
    {
        $sid = $this->supplierId($request);
        $viewer = $this->viewer($request);
        // Scope-aware (§4.2): non-admin vysype jen company + vlastní user doklady;
        // cizí user doklady zůstanou v koši i s bajty (listTrashedRaw i hardDelete
        // sdílejí stejný scope, aby se ref-counting nekřížil).
        $rows = $this->documents->listTrashedRaw($sid, $viewer);
        $candidateIds = array_map(static fn (array $r): int => (int) $r['id'], $rows);

        $blocked = $this->deletionGuard->blockedTrashDocuments($sid, $candidateIds);
        $targets = array_values(array_diff($candidateIds, array_keys($blocked)));

        // Vrací id, která v tabulce zůstala — kaskáda `parent_document_id` i souběžně
        // vzniklá vazba by z `rowCount()` udělaly lež.
        $survivors = $this->documents->hardDeleteTrashedByIds($sid, $viewer, $targets);
        $keptIds = array_values(array_unique(array_merge(array_keys($blocked), $survivors)));
        $deletedIds = array_values(array_diff($candidateIds, $keptIds));

        $this->folders->purgeTrashed($sid);
        $this->tags->purgeOrphans($sid); // osamocené tagy po skutečném smazání dokumentů

        // Po smazání DB řádků: fyzicky smaž soubory, na které už nikdo neukazuje.
        // Jen za skutečně smazané řádky — bajt dokladu, který v koši zůstal, se
        // odpojit nesmí, jinak by po obnovení chyběl soubor.
        $deletedLookup = array_flip($deletedIds);
        foreach ($rows as $r) {
            if (!isset($deletedLookup[(int) $r['id']])) {
                continue;
            }
            $this->storage->deleteIfOrphan(
                $sid,
                (string) $r['sha256'],
                (string) $r['filename'],
                isset($r['thumb_path']) ? (string) $r['thumb_path'] : null,
                $this->documents,
                [],
            );
        }
        $this->storage->pruneEmptyDirs($sid);

        $kept = $this->describeKept($rows, $keptIds, $blocked);
        $this->logger->log('document.trash_emptied', $this->userId($request), 'document', null,
            ['deleted' => count($deletedIds), 'kept' => count($keptIds), 'kept_ids' => $keptIds],
            $this->clientIp($request), $request->getHeaderLine('User-Agent'), $sid);

        return Json::ok($response, [
            'ok'        => true,
            'deleted'   => count($deletedIds),
            'kept'      => count($keptIds),
            'kept_documents' => $kept,
        ]);
    }

    /**
     * Výpis dokladů, které v koši zůstaly, i s důvodem — bez něj by uživatel
     * viděl jen nižší číslo a netušil proč.
     *
     * @param list<array<string,mixed>>      $rows
     * @param list<int>                      $keptIds
     * @param array<int,DeletionConflict>    $blocked
     * @return list<array{id:int,title:string,reason:string}>
     */
    private function describeKept(array $rows, array $keptIds, array $blocked): array
    {
        $titles = [];
        foreach ($rows as $r) {
            $titles[(int) $r['id']] = (string) ($r['title'] ?? '');
        }

        $kept = [];
        foreach ($keptIds as $id) {
            $kept[] = [
                'id'     => $id,
                'title'  => $titles[$id] ?? '',
                'reason' => isset($blocked[$id])
                    ? $blocked[$id]->message
                    : DocumentDeletionGuard::raceMessage(),
            ];
        }

        return $kept;
    }

    /** POST /api/documents/bulk {action, ids[], folder_ids[]?, folder_id?, tags?} */
    public function bulk(Request $request, Response $response): Response
    {
        $sid = $this->supplierId($request);
        $body = (array) $request->getParsedBody();
        $action = (string) ($body['action'] ?? '');
        $ids = array_values(array_filter(array_map('intval', (array) ($body['ids'] ?? []))));
        $folderIds = array_values(array_filter(array_map('intval', (array) ($body['folder_ids'] ?? []))));
        if ($ids === [] && $folderIds === []) {
            return Json::error($response, 'no_ids', 'Nebyly vybrány žádné položky.', 400);
        }

        $userId = $this->userId($request);
        $viewer = $this->viewer($request);
        $affected = 0;

        switch ($action) {
            case 'move':
                $folderId = $this->optInt($body['folder_id'] ?? null);
                if ($folderId !== null && $this->folders->find($folderId, $sid) === null) {
                    return Json::error($response, 'folder_not_found', 'Cílová složka nenalezena.', 404);
                }
                foreach ($ids as $id) {
                    // Scope guard: nelze hromadně přesunout cizí user doklad.
                    if ($this->documents->find($id, $sid, $viewer) === null) continue;
                    if ($this->documents->move($id, $sid, $folderId)) $affected++;
                }
                // Složky: zákaz přesunu do sebe / vlastního potomka (cyklus).
                foreach ($folderIds as $fid) {
                    $subtreeIds = $this->folders->descendantIds($fid, $sid);
                    if (!$this->folders->canMutateSubtree($sid, $subtreeIds, $viewer)) {
                        continue;
                    }
                    if ($folderId !== null && ($folderId === $fid
                        || in_array($folderId, $subtreeIds, true))) {
                        continue;
                    }
                    if ($this->folders->move($fid, $sid, $folderId)) $affected++;
                }
                break;

            case 'delete':
                foreach ($ids as $id) {
                    // Scope guard: nelze hromadně smazat cizí user doklad.
                    if ($this->documents->find($id, $sid, $viewer) === null) continue;
                    if ($this->documents->softDelete($id, $sid, $userId)) $affected++;
                }
                foreach ($folderIds as $fid) {
                    if ($this->folders->find($fid, $sid) === null) continue;
                    $subtreeIds = $this->folders->descendantIds($fid, $sid);
                    if (
                        $this->folders->containsRetainedEvidence($sid, $subtreeIds)
                        || !$this->folders->canMutateSubtree($sid, $subtreeIds, $viewer)
                    ) {
                        continue;
                    }
                    if ($this->folders->softDeleteSubtree($fid, $sid, $userId, $viewer) === []) {
                        continue;
                    }
                    $affected++;
                }
                break;

            case 'tag':
                $names = array_values(array_filter(array_map('strval', (array) ($body['tags'] ?? []))));
                foreach ($ids as $id) {
                    if ($this->documents->find($id, $sid, $viewer) === null) continue;
                    foreach ($names as $name) {
                        $name = mb_substr(trim($name), 0, 64);
                        if ($name === '') continue;
                        $this->tags->attach($id, $this->tags->upsertTag($sid, $name));
                    }
                    $affected++;
                }
                break;

            default:
                return Json::error($response, 'bad_action', 'Neznámá hromadná akce.', 400);
        }

        $this->logger->log('document.bulk_' . $action, $userId, 'document', null,
            ['count' => $affected], $this->clientIp($request), $request->getHeaderLine('User-Agent'), $sid);
        return Json::ok($response, ['ok' => true, 'affected' => $affected]);
    }

    /** @return list<array{id:int,name:string}> Breadcrumb od rootu k aktuální složce. */
    private function breadcrumb(int $sid, ?int $folderId): array
    {
        $chain = [];
        $guard = 0;
        $cur = $folderId;
        while ($cur !== null && $guard++ < 64) {
            $f = $this->folders->find($cur, $sid, true);
            if ($f === null) break;
            array_unshift($chain, ['id' => (int) $f['id'], 'name' => (string) $f['name']]);
            $cur = $f['parent_id'] !== null ? (int) $f['parent_id'] : null;
        }
        return $chain;
    }
}
