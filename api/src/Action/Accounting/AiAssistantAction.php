<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Ai\AccountingAssistantException;
use MyInvoice\Service\Ai\AccountingAssistantService;
use MyInvoice\Service\Ai\AiAssistantHistoryService;
use MyInvoice\Service\Document\DocumentViewerResolver;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

/** Účetnictví → AI asistent: stav dostupnosti, dotaz s přílohami a historie dotazů. */
final class AiAssistantAction
{
    public function __construct(
        private readonly AccountingAssistantService $assistant,
        private readonly AiAssistantHistoryService $history,
    ) {}

    /** GET /api/accounting/ai-assistant/status */
    public function status(Request $request, Response $response): Response
    {
        return Json::ok($response, $this->assistant->status(SupplierGuard::currentId($request)));
    }

    /** POST /api/accounting/ai-assistant/ask (multipart/form-data) */
    public function ask(Request $request, Response $response): Response
    {
        @set_time_limit(300);
        $body = (array) ($request->getParsedBody() ?? []);
        $supplierId = SupplierGuard::currentId($request);
        $userId = self::userId($request);
        try {
            $conversationId = isset($body['conversation_id']) && $body['conversation_id'] !== ''
                ? filter_var($body['conversation_id'], FILTER_VALIDATE_INT) : null;
            if ($conversationId === false || ($conversationId !== null && ($conversationId <= 0 || $userId === null))) {
                throw AccountingAssistantException::invalid('Neplatná konverzace.');
            }
            $history = [];
            $rawHistory = $body['history'] ?? '';
            if (is_string($rawHistory) && trim($rawHistory) !== '') {
                $history = json_decode($rawHistory, true);
                if (!is_array($history) || !array_is_list($history)) {
                    throw AccountingAssistantException::invalid('Historie konverzace není platný JSON seznam.');
                }
            } elseif (is_array($rawHistory)) {
                $history = array_values($rawHistory);
            }
            $documentIds = $body['document_ids'] ?? [];
            $documentIds = is_array($documentIds) ? array_values($documentIds) : [$documentIds];
            $documentIds = array_values(array_filter($documentIds, static fn ($v): bool => $v !== '' && $v !== null));
            // Uložená konverzace je zdroj kontextu: klient nemusí historii posílat znovu.
            if ($conversationId !== null) {
                $history = $this->history->contextHistory($supplierId, $userId, $conversationId);
            }
            $question = is_string($body['question'] ?? null) ? $body['question'] : '';
            if (isset($body['model']) && !is_string($body['model'])) {
                throw AccountingAssistantException::invalid('Neplatný model.');
            }
            $files = $this->uploadedFiles($request);

            $result = $this->assistant->ask(
                $supplierId,
                DocumentViewerResolver::fromRequest($request),
                $question,
                $history,
                $files,
                $documentIds,
                in_array($body['include_chart'] ?? '0', ['1', 1, true, 'true'], true),
                $body['model'] ?? null,
                RequestAuthorization::canCreateInternalDocument($request),
            );
            if ($userId !== null) {
                $attachments = [
                    ...array_map(static fn (array $f): array => ['kind' => 'file', 'name' => $f['name']], $files),
                    ...array_map(static fn ($id): array => ['kind' => 'document', 'id' => (int) $id], $documentIds),
                ];
                $result += $this->history->record($supplierId, $userId, $conversationId, trim($question), $attachments, $result);
            }
        } catch (AccountingAssistantException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }
        return Json::ok($response, $result);
    }

    /** GET /api/accounting/ai-assistant/conversations */
    public function conversations(Request $request, Response $response): Response
    {
        $userId = self::userId($request);
        return Json::ok($response, ['items' => $userId === null ? [] : $this->history->list(SupplierGuard::currentId($request), $userId)]);
    }

    /** GET /api/accounting/ai-assistant/conversations/{id} */
    public function conversation(Request $request, Response $response, array $args): Response
    {
        return $this->guarded($response, fn () => $this->history->get(
            SupplierGuard::currentId($request), $this->requireUser($request), (int) $args['id']));
    }

    /** DELETE /api/accounting/ai-assistant/conversations/{id} */
    public function deleteConversation(Request $request, Response $response, array $args): Response
    {
        return $this->guarded($response, function () use ($request, $args) {
            $this->history->delete(SupplierGuard::currentId($request), $this->requireUser($request), (int) $args['id']);
            return ['deleted' => true];
        });
    }

    /** POST /api/accounting/ai-assistant/messages/{id}/applied {kind, index, target_id} */
    public function markApplied(Request $request, Response $response, array $args): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        return $this->guarded($response, fn () => ['applied' => $this->history->markApplied(
            SupplierGuard::currentId($request), $this->requireUser($request), (int) $args['id'],
            (string) ($body['kind'] ?? ''), (int) ($body['index'] ?? -1), (int) ($body['target_id'] ?? 0))]);
    }

    private function guarded(Response $response, callable $callback): Response
    {
        try {
            return Json::ok($response, $callback());
        } catch (AccountingAssistantException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }
    }

    private function requireUser(Request $request): int
    {
        return self::userId($request)
            ?? throw new AccountingAssistantException('ai_assistant.conversation_not_found', 'Konverzace nebyla nalezena.', 404);
    }

    private static function userId(Request $request): ?int
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $id = (int) ($user['id'] ?? 0);
        return $id > 0 ? $id : null;
    }

    /** @return list<array{name:string, bytes:string}> */
    private function uploadedFiles(Request $request): array
    {
        $uploaded = $request->getUploadedFiles()['files'] ?? [];
        if ($uploaded instanceof UploadedFileInterface) {
            $uploaded = [$uploaded];
        }
        if (!is_array($uploaded)) {
            return [];
        }
        if (count($uploaded) > AccountingAssistantService::MAX_FILES) {
            throw AccountingAssistantException::invalid('Přiložit lze nejvýše 5 souborů.');
        }
        $out = [];
        foreach ($uploaded as $file) {
            if (!$file instanceof UploadedFileInterface) {
                continue;
            }
            $name = (string) ($file->getClientFilename() ?? 'soubor');
            if ($file->getError() === UPLOAD_ERR_INI_SIZE || $file->getError() === UPLOAD_ERR_FORM_SIZE) {
                throw AccountingAssistantException::invalid('Soubor ' . $name . ' překračuje povolenou velikost.');
            }
            if ($file->getError() !== UPLOAD_ERR_OK) {
                throw AccountingAssistantException::invalid('Soubor ' . $name . ' se nepodařilo nahrát.');
            }
            if ((int) $file->getSize() > AccountingAssistantService::MAX_FILE_BYTES) {
                throw AccountingAssistantException::invalid('Soubor ' . $name . ' je větší než 15 MB.');
            }
            $out[] = ['name' => $name, 'bytes' => (string) $file->getStream()];
        }
        return $out;
    }
}
