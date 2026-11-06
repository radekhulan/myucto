<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Accounting\DocumentPostingGroup;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Accounting\DocumentLockService;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Ruční book/unbook vydané faktury (Epic F6, §4.6) — nutné pro tax_evidence firmy
 * (bez journalu a období je booked_at jediný mechanismus zámku pro roli client).
 *
 *   POST   /api/invoices/{id}/book — booked_at = NOW(), booked_by = user; jen non-draft; idempotentní
 *   DELETE /api/invoices/{id}/book — smaže booked_at; 409 'still_posted' při aktivním posted zápisu
 *
 * RBAC (defense-in-depth): PermissionMiddleware gatuje /api/invoices/{id}/book na
 * 'accounting.journal.post' (obě metody = write); Action si totéž právo ověřuje sama.
 * SupplierGuard::owns() je jen tenant kontrola, ne oprávnění — sám o sobě by roli
 * s pouhým modulovým klíčem 'invoices' zaúčtování nezakázal.
 */
final class BookInvoiceAction
{
    private const PERMISSION = 'accounting.journal.post';

    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly Connection $db,
        private readonly JournalEntryRepository $journal,
        private readonly DocumentLockService $locks,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function book(Request $request, Response $response, array $args): Response
    {
        if ($err = $this->denied($request, $response)) return $err;

        $id = (int) ($args['id'] ?? 0);
        $invoice = $this->repo->find($id);
        if (!SupplierGuard::owns($request, $invoice)) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }
        if ($invoice['status'] === 'draft') {
            return Json::error($response, 'not_draft', 'Koncept nelze označit jako zaúčtovaný.', 409);
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);

        // Idempotentní — existující booked_at/booked_by se nepřepisuje.
        $this->db->pdo()->prepare(
            'UPDATE invoices
                SET booked_at = COALESCE(booked_at, NOW()),
                    booked_by = COALESCE(booked_by, ?)
              WHERE id = ? AND supplier_id = ?'
        )->execute([$userId > 0 ? $userId : null, $id, (int) $invoice['supplier_id']]);

        if (empty($invoice['booked_at'])) {
            $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
            $this->logger->log('invoice.booked', $userId > 0 ? $userId : null, 'invoice', $id, [
                'varsymbol' => $invoice['varsymbol'] ?? null,
            ], $ip, $request->getHeaderLine('User-Agent'));
        }

        return $this->respondWithInvoice($response, $id);
    }

    public function unbook(Request $request, Response $response, array $args): Response
    {
        if ($err = $this->denied($request, $response)) return $err;

        $id = (int) ($args['id'] ?? 0);
        $invoice = $this->repo->find($id);
        if (!SupplierGuard::owns($request, $invoice)) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }

        $supplierId = (int) $invoice['supplier_id'];

        // Kontrola i odemknutí drží stejný zámek jako souběžné účtování/import.
        $changed = TransactionFailure::retrying($this->db->pdo(), fn (): ?int =>
            (new DimensionPeriodGuard($this->db))->transaction(function () use ($supplierId, $id): ?int {
                if ((new DocumentPostingGroup($this->db))->active($supplierId, 'invoice', $id, true) !== []) return null;
                $stmt = $this->db->pdo()->prepare(
                    'UPDATE invoices SET booked_at = NULL, booked_by = NULL
                      WHERE id = ? AND supplier_id = ? AND booked_at IS NOT NULL'
                );
                $stmt->execute([$id, $supplierId]);
                return $stmt->rowCount();
            }),
        );
        if ($changed === null) {
            return Json::error($response, 'still_posted',
                'Doklad má aktivní účetní zápis — nejdřív ho stornuj v deníku.', 409);
        }

        if ($changed > 0) {
            $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
            $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
            $this->logger->log('invoice.unbooked', isset($user['id']) ? (int) $user['id'] : null, 'invoice', $id, [
                'varsymbol'          => $invoice['varsymbol'] ?? null,
                'previous_booked_at' => $invoice['booked_at'] ?? null,
            ], $ip, $request->getHeaderLine('User-Agent'));
        }

        return $this->respondWithInvoice($response, $id);
    }

    /** Dokontrola konkrétního práva (ne modulového 'invoices'). */
    private function denied(Request $request, Response $response): ?Response
    {
        if (RequestAuthorization::allows($request, self::PERMISSION, AccessLevel::WRITE)) {
            return null;
        }
        return Json::error($response, 'forbidden', 'Pro tuto akci nemáš oprávnění.', 403);
    }

    private function respondWithInvoice(Response $response, int $id): Response
    {
        $invoice = $this->repo->find($id);
        if ($invoice !== null) {
            $invoice['locked'] = $this->locks->forInvoice($invoice)->toArray();
        }
        return Json::ok($response, $invoice);
    }
}
