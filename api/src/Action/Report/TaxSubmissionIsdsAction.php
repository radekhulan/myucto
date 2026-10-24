<?php

declare(strict_types=1);

namespace MyInvoice\Action\Report;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\Submission\SubmissionInboxRepository;
use MyInvoice\Repository\TaxSubmissionEpoRepository;
use MyInvoice\Repository\TaxSubmissionRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Document\DocumentException;
use MyInvoice\Service\Epo\Isds\TaxIsdsRouting;
use MyInvoice\Service\Epo\Isds\TaxSubmissionDispatchProjection;
use MyInvoice\Service\Epo\Isds\TaxSubmissionIsdsService;
use MyInvoice\Service\License\CommercialFeatureAccess;
use MyInvoice\Service\License\TaxSubmissionAccess;
use MyInvoice\Service\Submission\Channel\SubmissionChannelException;
use MyInvoice\Service\Submission\DeliveryReceiptService;
use MyInvoice\Service\Submission\InboxMessageClassifier;
use MyInvoice\Service\Submission\SubmissionOutboxService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Daňová podání datovou schránkou ze stránky Podání (DPH, KH, SH, DPPO, DPFO).
 *
 * Tahle akce jen zařazuje do obecné fronty a dává podání do souvislosti
 * s datovou schránkou (směrování na FÚ, doručenka, zprávy od FÚ). Samotné
 * odeslání jde sdílenými cestami fronty — odesílací brána
 * ({@see \MyInvoice\Action\Submission\IsdsGatewayAction::taxStart()}), Mobilní
 * klíč, jméno a heslo nebo systémový certifikát.
 */
final class TaxSubmissionIsdsAction
{
    public function __construct(
        private readonly TaxSubmissionRepository $submissions,
        private readonly TaxSubmissionEpoRepository $epo,
        private readonly TaxIsdsRouting $routing,
        private readonly TaxSubmissionIsdsService $service,
        private readonly TaxSubmissionDispatchProjection $projection,
        private readonly DeliveryReceiptService $receipts,
        private readonly SubmissionInboxRepository $inbox,
        private readonly SubmissionOutboxService $outbox,
        private readonly CommercialFeatureAccess $commercial,
        private readonly ActivityLogger $logger,
    ) {}

    public function routes(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::canReadTaxSubmissions($request)) {
            return Json::error($response, 'forbidden', 'Nemáte oprávnění.', 403);
        }
        $routes = $this->routing->routes(SupplierGuard::currentId($request));
        if (RequestAuthorization::isClientType($request)) {
            unset($routes['groups']['income']);
            $routes['recipients'] = [];
        }
        return Json::ok($response, $routes);
    }

    public function saveRoutes(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::canManageTaxSubmissions($request)) {
            return Json::error($response, 'forbidden', 'Nemáte oprávnění.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $body = (array) ($request->getParsedBody() ?? []);
        $groups = is_array($body['groups'] ?? null) ? $body['groups'] : $body;
        try {
            $this->routing->saveRoutes($supplierId, $groups, $this->userId($request));
        } catch (SubmissionChannelException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }
        $this->logger->log('report.submission_isds_routes_saved', $this->userId($request), null, null, [
            'groups' => array_intersect_key($groups, array_flip(['vat', 'kh', 'sh', 'income'])),
        ], null, null, $supplierId);

        return Json::ok($response, $this->routing->routes($supplierId));
    }

    /** @param array<string,string> $args */
    public function enqueue(Request $request, Response $response, array $args): Response
    {
        if (!RequestAuthorization::allows($request, 'reports.submit', AccessLevel::WRITE)) {
            return Json::error($response, 'forbidden', 'Nemáte oprávnění podávat.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $id = (int) ($args['id'] ?? 0);
        if (($denied = $this->deniedSubmission($request, $supplierId, $id, $response)) !== null) {
            return $denied;
        }

        try {
            $result = $this->service->enqueue($supplierId, $id, $this->userId($request));
        } catch (SubmissionChannelException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\DomainException $e) {
            return Json::error($response, 'submission_conflict', $e->getMessage(), 409);
        }

        $this->logger->log('report.submission_isds_enqueued', $this->userId($request), 'tax_submission', $id, [
            'submission_id' => $id,
            'outbox_id' => $result['outbox_id'],
            'created' => $result['created'],
            'recipient_box_id' => $result['recipient']['box_id'],
            'recipient_source' => $result['recipient']['source'],
            'office_mismatch' => $result['office_mismatch'],
        ], null, null, $supplierId);

        return Json::ok($response, $result);
    }

    /**
     * Zrušení zprávy, která čeká ve frontě a ještě neodešla. Bez toho by uživatel
     * s oprávněním jen podávat výkazy nemohl snapshot uvolnit zpět pro EPO.
     *
     * @param array<string,string> $args
     */
    public function cancel(Request $request, Response $response, array $args): Response
    {
        if (!RequestAuthorization::allows($request, 'reports.submit', AccessLevel::WRITE)) {
            return Json::error($response, 'forbidden', 'Nemáte oprávnění.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $id = (int) ($args['id'] ?? 0);
        if (($denied = $this->deniedSubmission($request, $supplierId, $id, $response)) !== null) {
            return $denied;
        }
        $dispatch = $this->epo->activeIsdsDispatch($id, $supplierId);
        if ($dispatch === null || (string) $dispatch['dispatch_state'] !== 'ready') {
            return Json::error(
                $response,
                'isds_dispatch_not_cancellable',
                'Zrušit jde jen zpráva, která čeká ve frontě a ještě neodešla.',
                409,
            );
        }
        try {
            $this->outbox->cancel($supplierId, (int) $dispatch['id']);
        } catch (SubmissionChannelException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\DomainException $e) {
            return Json::error($response, 'submission_conflict', $e->getMessage(), 409);
        }
        $this->logger->log('report.submission_isds_cancelled', $this->userId($request), 'tax_submission', $id, [
            'submission_id' => $id,
            'outbox_id' => (int) $dispatch['id'],
        ], null, null, $supplierId);

        $row = $this->submissions->find($id, $supplierId);
        return Json::ok($response, $row !== null ? $this->epo->enrich([$row], $supplierId)[0] : null);
    }

    /**
     * Doručenka (ZFO) k podání, které odešlo datovou schránkou. Párování
     * i promítnutí dělá sdílená služba doručenek; tady se jen určí zpráva fronty.
     *
     * @param array<string,string> $args
     */
    public function uploadReceipt(Request $request, Response $response, array $args): Response
    {
        if (!RequestAuthorization::allows($request, 'reports.submit', AccessLevel::WRITE)) {
            return Json::error($response, 'forbidden', 'Nemáte oprávnění.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $userId = $this->userId($request);
        $id = (int) ($args['id'] ?? 0);
        if (($denied = $this->deniedSubmission($request, $supplierId, $id, $response)) !== null) {
            return $denied;
        }
        $dispatch = $this->epo->activeIsdsDispatch($id, $supplierId);
        if ($dispatch === null) {
            return Json::error(
                $response,
                'isds_dispatch_missing',
                'Tohle podání nemá zprávu datové schránky, ke které by doručenka patřila.',
                409,
            );
        }
        $file = $request->getUploadedFiles()['file'] ?? null;
        if (!$file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
            return Json::error($response, 'file_missing', 'Vyberte soubor doručenky (ZFO).', 400);
        }
        $filename = (string) ($file->getClientFilename() ?? 'dorucenka.zfo');
        if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'zfo') {
            return Json::error($response, 'unsupported_artifact', 'Doručenka musí být soubor ZFO.', 415);
        }

        try {
            $result = $this->receipts->upload(
                $supplierId,
                'production',
                (string) $file->getStream(),
                $filename,
                (int) $userId,
                (int) $dispatch['id'],
            );
        } catch (SubmissionChannelException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (DocumentException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\DomainException $e) {
            return Json::error($response, 'receipt_conflict', $e->getMessage(), 409);
        }

        return Json::ok($response, $result);
    }

    /**
     * Zprávy od FÚ, které k podání mohou patřit — pro ruční přiřazení těch,
     * které automat nespároval.
     *
     * @param array<string,string> $args
     */
    public function inboxCandidates(Request $request, Response $response, array $args): Response
    {
        if (!RequestAuthorization::canManageTaxSubmissionEvidence($request)) {
            return Json::error($response, 'forbidden', 'Nemáte oprávnění.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $id = (int) ($args['id'] ?? 0);
        if (($denied = $this->deniedSubmission($request, $supplierId, $id, $response)) !== null) {
            return $denied;
        }
        $dispatch = $this->epo->activeIsdsDispatch($id, $supplierId);
        $items = array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'external_message_id' => (string) $row['external_message_id'],
                'sender_box_id' => $row['sender_box_id'] ?? null,
                'sender_name' => $row['sender_name'] ?? null,
                'subject' => $row['subject'] ?? null,
                'classification' => (string) $row['classification'],
                'delivered_at' => $row['delivered_at'] ?? null,
                'matched' => $row['matched_outbox_id'] !== null,
            ],
            $this->inbox->listTaxOfficeCandidates(
                $supplierId,
                $dispatch !== null ? (int) $dispatch['id'] : null,
            ),
        );

        return Json::ok($response, ['items' => $items]);
    }

    /**
     * Ruční přiřazení zprávy od FÚ k podání: originál ZFO se přiloží k podání.
     *
     * @param array<string,string> $args
     */
    public function linkInbox(Request $request, Response $response, array $args): Response
    {
        if (!RequestAuthorization::canManageTaxSubmissionEvidence($request)) {
            return Json::error($response, 'forbidden', 'Nemáte oprávnění.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $userId = $this->userId($request);
        $id = (int) ($args['id'] ?? 0);
        $messageId = (int) ($args['messageId'] ?? 0);
        if (($denied = $this->deniedSubmission($request, $supplierId, $id, $response)) !== null) {
            return $denied;
        }
        $message = $this->inbox->findById($supplierId, $messageId);
        if ($message === null
            || (string) $message['environment'] !== 'production'
            || !in_array((string) $message['classification'], [
                InboxMessageClassifier::TAX_OFFICE_RESPONSE,
                InboxMessageClassifier::UNCLASSIFIED,
            ], true)
        ) {
            return Json::error($response, 'inbox_message_not_found', 'Zpráva v datové schránce nebyla nalezena.', 404);
        }
        $documentId = $message['document_id'] !== null ? (int) $message['document_id'] : null;
        if ($documentId === null) {
            return Json::error(
                $response,
                'inbox_message_without_content',
                'Zpráva nemá uložený originál, nelze ji k podání přiložit.',
                409,
            );
        }

        $dispatch = $this->epo->activeIsdsDispatch($id, $supplierId);
        $matched = $message['matched_outbox_id'] !== null ? (int) $message['matched_outbox_id'] : null;
        if ($dispatch !== null && $matched === null) {
            $this->inbox->reclassify(
                $supplierId,
                $messageId,
                InboxMessageClassifier::TAX_OFFICE_RESPONSE,
                (int) $dispatch['id'],
            );
        } elseif ($matched !== null && ($dispatch === null || $matched !== (int) $dispatch['id'])) {
            return Json::error(
                $response,
                'inbox_message_linked_elsewhere',
                'Zpráva už patří k jinému podání.',
                409,
            );
        }

        $this->projection->linkDocument(
            $supplierId,
            $id,
            $documentId,
            'isds_response_zfo',
            [
                'inbox_message_id' => $messageId,
                'external_message_id' => $message['external_message_id'] ?? null,
                'sender_box_id' => $message['sender_box_id'] ?? null,
                'sender_name' => $message['sender_name'] ?? null,
                'subject' => $message['subject'] ?? null,
                'delivered_at' => $message['delivered_at'] ?? null,
                'matched_by' => 'manual',
            ],
            $userId,
        );
        $this->logger->log('report.submission_isds_response_linked', $userId, 'tax_submission', $id, [
            'submission_id' => $id,
            'inbox_message_id' => $messageId,
        ], null, null, $supplierId);

        $row = $this->submissions->find($id, $supplierId);
        return Json::ok($response, $row !== null ? $this->epo->enrich([$row], $supplierId)[0] : null);
    }

    private function deniedSubmission(Request $request, int $supplierId, int $id, Response $response): ?Response
    {
        $row = $this->submissions->find($id, $supplierId);
        if ($row === null) {
            return Json::error($response, 'not_found', 'Podání nebylo nalezeno.', 404);
        }
        if (!RequestAuthorization::canAccessTaxSubmission($request, $row)) {
            return Json::error($response, 'forbidden', 'Nemáte oprávnění.', 403);
        }
        if (!TaxSubmissionAccess::isFreeForm($row['form_code'] ?? null) && !$this->commercial->isAvailable()) {
            return Json::error(
                $response,
                'license_commercial_feature_unavailable',
                'Tenhle výkaz patří do účetní nadstavby a vyžaduje aktivní licenci.',
                403,
            );
        }
        return null;
    }

    private function userId(Request $request): ?int
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        return isset($user['id']) && (int) $user['id'] > 0 ? (int) $user['id'] : null;
    }
}
