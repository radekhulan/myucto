<?php

declare(strict_types=1);

namespace MyInvoice\Action\Stock;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Action\Invoice\CreateInvoiceAction;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\StockDocumentRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Stock\ReceiptInvoiceService;
use MyInvoice\Service\Stock\StockException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /api/stock/documents/{id}/invoice — koncept vydané faktury z příjemky.
 * Faktura vznikne stejnou cestou jako POST /api/invoices (validace, DPH, OSS, kurz,
 * uzavřené období), vždy jako koncept a jen ze zaúčtované příjemky. Vystavuje ji až člověk.
 */
final class ReceiptInvoiceAction
{
    use AccountingActionSupport;
    use GuardsStockEnabled;

    public function __construct(
        private readonly Connection $db,
        private readonly ReceiptInvoiceService $service,
        private readonly CreateInvoiceAction $createInvoice,
        private readonly StockDocumentRepository $documents,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function create(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        // Trasa sama chce právo psát skladové doklady (a klientské role tím neotevírá), stejně
        // jako faktura z prodejní objednávky ({@see SalesOrderAction::invoice()}).
        if (!RequestAuthorization::allows($request, 'invoices.create', AccessLevel::WRITE)) {
            return Json::error($response, 'forbidden', 'Nemáte oprávnění vytvářet faktury.', 403);
        }
        $documentId = (int) $args['id'];
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $prepared = $this->service->prepare(
                $supplierId,
                $documentId,
                (array) ($request->getParsedBody() ?? []),
                RequestAuthorization::allows($request, 'invoices', AccessLevel::READ),
            );
            $created = ($this->createInvoice)($request->withParsedBody($prepared['invoice']), $response);
            if ($created->getStatusCode() !== 201) {
                $pdo->rollBack();
                return $created;
            }
            $invoice = json_decode((string) $created->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $this->documents->linkSalesInvoice($supplierId, $documentId, $prepared['line_nos'], (int) $invoice['id']);
            if ($prepared['duplicate_line_nos'] !== []) {
                $this->logger->log('stock.receipt_invoiced_again', $this->userId($request), 'stock_document', $documentId, [
                    'line_nos' => $prepared['duplicate_line_nos'],
                    'invoice_id' => (int) $invoice['id'],
                ], $this->ipMatcher->clientIpFromRequest($request->getServerParams()), $request->getHeaderLine('User-Agent'), $supplierId);
            }
            $pdo->commit();
            return $created;
        } catch (StockException $e) {
            $pdo->rollBack();
            return Json::error($response, 'stock.error.' . $e->errorCode, $e->getMessage(), $e->httpStatus, ['items' => $e->details]);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
