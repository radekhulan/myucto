<?php

declare(strict_types=1);

namespace MyInvoice\Action\PurchaseInvoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SubmitsDocumentOriginals;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\DocumentRepository;
use MyInvoice\Repository\PurchaseInvoiceSubmissionRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\PurchaseInvoice\PurchaseInvoiceSubmissionException;
use MyInvoice\Service\PurchaseInvoice\PurchaseInvoiceSubmissionProcessingService;
use MyInvoice\Service\PurchaseInvoice\PurchaseInvoiceSubmissionUploadService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** Účetní fronta příchozích originálů. */
final class PurchaseInvoiceSubmissionAction
{
    use SubmitsDocumentOriginals;

    private const STATUSES = ['submitted', 'processing', 'needs_information', 'processed', 'rejected'];

    public function __construct(
        private readonly PurchaseInvoiceSubmissionRepository $submissions,
        private readonly PurchaseInvoiceSubmissionProcessingService $processing,
        private readonly PurchaseInvoiceSubmissionUploadService $upload,
        private readonly DocumentRepository $documents,
        private readonly Connection $db,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    /**
     * Doklad, který přišel mimo portál — e-mailem, papírově, nebo si ho účetní
     * dává do fronty sama za sebe. Chová se stejně jako klientské podání: originál
     * jde do DMS a zůstane účetně neutrální, dokud ho někdo nezpracuje.
     */
    public function upload(Request $request, Response $response): Response
    {
        if ($denied = $this->deny($request, $response, true)) return $denied;
        $supplierId = SupplierGuard::currentId($request);
        if ($supplierId <= 0) return Json::error($response, 'no_supplier', 'Žádná firma není dostupná.', 403);

        $files = $this->uploadedOriginals($request);
        if ($files === []) return Json::error($response, 'no_file', 'Vyberte alespoň jeden soubor.', 400);
        if (count($files) > self::MAX_ORIGINALS_PER_BATCH) {
            return Json::error($response, 'too_many_files', 'Najednou lze nahrát nejvýše 20 souborů.', 413);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $userId = $this->userId($request);
        $batch = $this->submitOriginals(
            $files,
            $supplierId,
            $userId > 0 ? $userId : null,
            'staff',
            isset($body['note']) ? (string) $body['note'] : null,
            isset($body['document_kind_hint']) ? (string) $body['document_kind_hint'] : null,
        );
        $items = $batch['items'];
        $errors = $batch['errors'];
        if ($items === [] && $batch['first_error'] instanceof PurchaseInvoiceSubmissionException) {
            $firstError = $batch['first_error'];
            return Json::error(
                $response,
                $firstError->errorCode,
                $firstError->getMessage(),
                $firstError->httpStatus,
                ['files' => $errors],
            );
        }

        foreach ($items as $item) {
            $submission = $item['submission'];
            $this->audit(
                $request,
                !empty($item['duplicate'])
                    ? 'purchase_invoice_submission.duplicate'
                    : 'purchase_invoice_submission.created',
                (int) $submission['id'],
                [
                    'document_id' => (int) $submission['document_id'],
                    'filename' => (string) $submission['original_name'],
                    'via' => 'staff',
                ],
            );
        }

        $duplicates = $this->duplicateCount($items);
        return Json::ok($response, [
            'items' => array_map(static fn(array $item): array => $item['submission'] + [
                'duplicate' => (bool) $item['duplicate'],
            ], $items),
            'created' => count($items) - $duplicates,
            'duplicates' => $duplicates,
            'errors' => $errors,
        ], $this->batchStatus($items, $errors, $duplicates));
    }

    public function list(Request $request, Response $response): Response
    {
        if ($denied = $this->deny($request, $response)) return $denied;
        $q = $request->getQueryParams();
        $status = isset($q['status']) && in_array((string) $q['status'], self::STATUSES, true)
            ? (string) $q['status'] : null;
        return Json::ok($response, $this->submissions->paginate(
            SupplierGuard::currentId($request),
            $status,
            (int) ($q['limit'] ?? 50),
            (int) ($q['offset'] ?? 0),
        ));
    }

    public function get(Request $request, Response $response, array $args): Response
    {
        if ($denied = $this->deny($request, $response)) return $denied;
        $item = $this->submissions->find((int) ($args['id'] ?? 0), SupplierGuard::currentId($request));
        return $item !== null
            ? Json::ok($response, $item)
            : Json::error($response, 'not_found', 'Podání nebylo nalezeno.', 404);
    }

    public function extract(Request $request, Response $response, array $args): Response
    {
        if ($denied = $this->deny($request, $response, true)) return $denied;
        if (!RequestAuthorization::allows($request, 'purchase_invoices.create', AccessLevel::WRITE)) {
            return Json::error($response, 'forbidden', 'Nemáte oprávnění vytvořit přijatou fakturu.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $id = (int) ($args['id'] ?? 0);
        $userId = $this->userId($request);
        try {
            $result = $this->processing->extract(
                $id,
                $supplierId,
                $userId,
                RequestAuthorization::allows($request, 'purchase_invoices.scan', AccessLevel::WRITE),
            );
        } catch (PurchaseInvoiceSubmissionException $e) {
            $this->audit($request, 'purchase_invoice_submission.extraction_failed', $id, [
                'code' => $e->errorCode,
                'error' => $e->getMessage(),
            ]);
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }
        $this->audit($request, 'purchase_invoice_submission.processed', $id, $result);
        return Json::ok($response, $this->submissions->find($id, $supplierId));
    }

    public function needsInformation(Request $request, Response $response, array $args): Response
    {
        return $this->review($request, $response, $args, 'needs_information');
    }

    public function reject(Request $request, Response $response, array $args): Response
    {
        return $this->review($request, $response, $args, 'rejected');
    }

    /**
     * Vyřadí podání z fronty i s originálem.
     *
     * Odmítnutí je jen stav — originál zůstane dohledatelný, protože u dokladu od
     * klienta je součástí auditní stopy. Tím ale ve frontě zůstává i to, co tam
     * nemá co dělat (omylem nahraná fotka, spam). Trvalý úklid je proto samostatné
     * právo `documents.inbox.delete`: podání zmizí a jeho originál se přesune do koše
     * Dokumentů, odkud ho vysypání koše smaže i z disku. Zpracované podání se
     * nemaže — drží vazbu na existující fakturu.
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        if ($denied = $this->deny($request, $response, true)) return $denied;
        if (!RequestAuthorization::allows($request, 'documents.inbox.delete', AccessLevel::WRITE)) {
            return Json::error(
                $response,
                'forbidden',
                'K trvalému vyřazení dokladu z fronty nemáte oprávnění.',
                403,
            );
        }
        $supplierId = SupplierGuard::currentId($request);
        $id = (int) ($args['id'] ?? 0);
        $userId = $this->userId($request);

        $pdo = $this->db->pdo();
        $ownTransaction = !$pdo->inTransaction();
        if ($ownTransaction) $pdo->beginTransaction();
        try {
            $deleted = $this->submissions->deleteFromQueue($id, $supplierId);
            if ($deleted === null) {
                if ($ownTransaction && $pdo->inTransaction()) $pdo->rollBack();
                $current = $this->submissions->find($id, $supplierId);
                return $current === null
                    ? Json::error($response, 'not_found', 'Podání nebylo nalezeno.', 404)
                    : Json::error(
                        $response,
                        'invalid_status',
                        'Zpracované ani právě zpracovávané podání smazat nelze. Nejdřív smažte výslednou fakturu.',
                        409,
                    );
            }
            $this->documents->softDelete((int) $deleted['document_id'], $supplierId, $userId ?: null);
            if ($ownTransaction) $pdo->commit();
        } catch (\Throwable $e) {
            if ($ownTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        $this->audit($request, 'purchase_invoice_submission.deleted', $id, [
            'document_id' => (int) $deleted['document_id'],
            'filename' => (string) $deleted['original_name'],
            'status' => (string) $deleted['status'],
            'via' => (string) $deleted['submitted_via'],
        ]);
        return Json::ok($response, ['ok' => true, 'document_id' => (int) $deleted['document_id']]);
    }

    private function review(Request $request, Response $response, array $args, string $status): Response
    {
        if ($denied = $this->deny($request, $response, true)) return $denied;
        $supplierId = SupplierGuard::currentId($request);
        $id = (int) ($args['id'] ?? 0);
        $body = (array) ($request->getParsedBody() ?? []);
        $reason = trim((string) ($body['reason'] ?? ''));
        // Zprávu vyžadujeme jen tam, kde ji má kdo číst. U dokladu, který si účetní
        // nahrála sama, ani u přílohy vytažené z e-mailu není komu psát „co doplnit" —
        // odmítnutí je tam jen úklid fronty.
        $existing = $this->submissions->find($id, $supplierId);
        $hasClientToInform = $existing !== null
            && !in_array((string) $existing['submitted_via'], ['staff', 'email'], true);
        if ($reason === '' && ($hasClientToInform || $status === 'needs_information')) {
            return Json::error($response, 'reason_required', 'Doplňte důvod pro klienta.', 400);
        }
        $changed = $status === 'needs_information'
            ? $this->submissions->needsInformation($id, $supplierId, $reason)
            : $this->submissions->reject($id, $supplierId, $reason);
        if (!$changed) return Json::error($response, 'invalid_status', 'Stav podání se mezitím změnil.', 409);
        $this->audit($request, 'purchase_invoice_submission.' . $status, $id, ['reason' => $reason]);
        return Json::ok($response, $this->submissions->find($id, $supplierId));
    }

    private function deny(Request $request, Response $response, bool $write = false): ?Response
    {
        $level = $write ? AccessLevel::WRITE : AccessLevel::READ;
        if (RequestAuthorization::isClientType($request)
            || !RequestAuthorization::allows($request, 'documents.inbox', $level)) {
            return Json::error($response, 'forbidden', 'K příchozím dokladům nemáte oprávnění.', 403);
        }
        return null;
    }

    private function userId(Request $request): int
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        return max(0, (int) ($user['id'] ?? 0));
    }

    /** @param array<string,mixed> $context */
    private function audit(Request $request, string $event, int $id, array $context): void
    {
        $this->logger->log(
            $event,
            $this->userId($request) ?: null,
            'purchase_invoice_submission',
            $id,
            $context,
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
            SupplierGuard::currentId($request),
        );
    }
}
