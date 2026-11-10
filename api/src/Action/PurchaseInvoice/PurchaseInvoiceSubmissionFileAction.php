<?php

declare(strict_types=1);

namespace MyInvoice\Action\PurchaseInvoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Repository\PurchaseInvoiceSubmissionRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Document\DocumentStorage;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Stream;

/** Soubor staging podání se servíruje přes tenant+RBAC guard, nikdy přes obecné DMS ACL. */
final class PurchaseInvoiceSubmissionFileAction
{
    private const INLINE_MIME = [
        'application/pdf', 'image/jpeg', 'image/png', 'image/gif', 'image/webp',
    ];

    public function __construct(
        private readonly PurchaseInvoiceSubmissionRepository $submissions,
        private readonly DocumentStorage $storage,
    ) {}

    public function staffPreview(Request $request, Response $response, array $args): Response
    {
        return $this->serve($request, $response, $args, true, false);
    }

    public function staffDownload(Request $request, Response $response, array $args): Response
    {
        return $this->serve($request, $response, $args, false, false);
    }

    public function portalPreview(Request $request, Response $response, array $args): Response
    {
        return $this->serve($request, $response, $args, true, true);
    }

    public function portalDownload(Request $request, Response $response, array $args): Response
    {
        return $this->serve($request, $response, $args, false, true);
    }

    private function serve(
        Request $request,
        Response $response,
        array $args,
        bool $preview,
        bool $portal,
    ): Response {
        // Portálový soubor řídí právo předávat doklady (klient i interní role), interní
        // náhled fronty právo `documents.inbox`, které klient mít nemůže.
        $allowed = $portal
            ? RequestAuthorization::allowsClientDocumentPortal($request, AccessLevel::READ)
            : !RequestAuthorization::isClientType($request)
                && RequestAuthorization::allows($request, 'documents.inbox', AccessLevel::READ);
        if (!$allowed) return Json::error($response, 'forbidden', 'K souboru nemáte oprávnění.', 403);

        $supplierId = SupplierGuard::currentId($request);
        $submission = $this->submissions->find((int) ($args['id'] ?? 0), $supplierId);
        if ($submission === null) return Json::error($response, 'not_found', 'Podání nebylo nalezeno.', 404);
        $path = $this->storage->pathFor(
            $supplierId,
            (string) $submission['document_sha256'],
            (string) $submission['document_filename'],
        );
        if (!is_file($path)) return Json::error($response, 'not_found', 'Originální soubor nebyl nalezen.', 404);

        $mime = strtolower((string) $submission['mime_type']);
        $docType = (string) $submission['doc_type'];
        $inline = $preview
            && in_array($mime, self::INLINE_MIME, true)
            && in_array($docType, ['pdf', 'image'], true);
        $original = preg_replace('/[\r\n"\\\\]/', '_', (string) $submission['original_name']) ?: 'doklad';
        $stream = new Stream(fopen($path, 'rb'));
        return $response
            ->withStatus(200)
            ->withHeader('Content-Type', $inline ? $mime : 'application/octet-stream')
            ->withHeader('Content-Disposition', ($inline ? 'inline' : 'attachment') . '; filename="' . $original . '"')
            ->withHeader('Content-Length', (string) filesize($path))
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Content-Security-Policy', "default-src 'none'; sandbox; style-src 'unsafe-inline'")
            ->withHeader('Cache-Control', 'private, no-store')
            ->withBody($stream);
    }
}
