<?php

declare(strict_types=1);

namespace MyInvoice\Action\SalesQuote;

use MyInvoice\Action\Stock\GuardsStockEnabled;
use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\License\CommercialFeatureAccess;
use MyInvoice\Service\SalesQuote\QuoteDelivery;
use MyInvoice\Service\SalesQuote\QuoteException;
use MyInvoice\Service\SalesQuote\QuoteInvoiceService;
use MyInvoice\Service\SalesQuote\QuoteOrderService;
use MyInvoice\Service\SalesQuote\QuoteWorkflow;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Psr7\Stream;

final class QuoteAction
{
    use GuardsStockEnabled;

    public function __construct(
        private readonly Connection $db,
        private readonly QuoteWorkflow $quotes,
        private readonly QuoteDelivery $delivery,
        private readonly QuoteInvoiceService $invoices,
        private readonly QuoteOrderService $orders,
        private readonly CommercialFeatureAccess $commercial,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        return Json::ok($response, $this->quotes->list(
            SupplierGuard::currentId($request), $request->getQueryParams(), RequestAuthorization::allows($request, 'invoices')
        ));
    }

    public function get(Request $request, Response $response, array $args): Response
    {
        return $this->handle($response, fn (): Response => Json::ok(
            $response, $this->detail($request, (int) $args['id'], $this->versionId($request))
        ));
    }

    public function create(Request $request, Response $response): Response
    {
        return $this->handle($response, function () use ($request, $response): Response {
            $body = (array) $request->getParsedBody();
            $this->guardReferences($request, $body);
            $id = $this->quotes->create(SupplierGuard::currentId($request), $this->userId($request), $body);
            return Json::ok($response, $this->detail($request, $id), 201);
        });
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        return $this->mutation($request, $response, $args, function (int $supplier, int $id, int $user, array $body) use ($request): void {
            $this->guardReferences($request, $body);
            $this->quotes->update($supplier, $id, $body);
        });
    }

    public function revise(Request $request, Response $response, array $args): Response
    {
        return $this->mutation($request, $response, $args, $this->quotes->revise(...));
    }

    public function state(Request $request, Response $response, array $args): Response
    {
        return $this->mutation($request, $response, $args, $this->quotes->state(...));
    }

    public function history(Request $request, Response $response, array $args): Response
    {
        return $this->mutation($request, $response, $args, $this->quotes->history(...));
    }

    public function send(Request $request, Response $response, array $args): Response
    {
        return $this->mutation($request, $response, $args, $this->delivery->send(...));
    }

    public function invoice(Request $request, Response $response, array $args): Response
    {
        return $this->handle($response, function () use ($request, $response, $args): Response {
            if (!RequestAuthorization::allows($request, 'invoices.create', AccessLevel::WRITE)) {
                throw new QuoteException('forbidden_permission', 'Pro tuto akci nemáte oprávnění.', 403);
            }
            $id = $this->invoices->convert(
                SupplierGuard::currentId($request), $this->userId($request), (int) $args['id'], (array) $request->getParsedBody()
            );
            return Json::ok($response, ['invoice_id' => $id], 201);
        });
    }

    public function order(Request $request, Response $response, array $args): Response
    {
        return $this->handle($response, function () use ($request, $response, $args): Response {
            if (!RequestAuthorization::allows($request, 'stock.orders.write', AccessLevel::WRITE) || !$this->commercial->isAvailable()) {
                throw new QuoteException('forbidden_permission', 'Pro tuto akci nemáte oprávnění.', 403);
            }
            $supplierId = SupplierGuard::currentId($request);
            $error = null;
            if (!$this->guardStockEnabled($this->db, $supplierId, $response, $error)) {
                return $error;
            }
            $id = $this->orders->convert($supplierId, (int) $args['id'], $this->userId($request), (array) $request->getParsedBody());
            return Json::ok($response, ['order_id' => $id], 201);
        });
    }

    public function upload(Request $request, Response $response, array $args): Response
    {
        return $this->mutation($request, $response, $args, function (int $supplier, int $id, int $user, array $body) use ($request): void {
            $file = $request->getUploadedFiles()['file'] ?? null;
            if (!$file instanceof UploadedFileInterface) {
                throw new QuoteException('quote_file', 'Vyberte soubor.');
            }
            $mode = $body['mode'] ?? null;
            if (!in_array($mode, ['inline', 'append', 'email', 'internal'], true)) {
                throw new QuoteException('quote_file_mode', 'Vyberte použití přílohy.');
            }
            $this->quotes->upload($supplier, $id, $file, $mode);
        });
    }

    public function deleteFile(Request $request, Response $response, array $args): Response
    {
        return $this->mutation($request, $response, $args, fn (int $supplier, int $id) => $this->quotes->deleteFile(
            $supplier, $id, (int) $args['fileId']
        ));
    }

    public function file(Request $request, Response $response, array $args): Response
    {
        return $this->handle($response, function () use ($request, $response, $args): Response {
            $file = $this->quotes->file(
                SupplierGuard::currentId($request), (int) $args['id'], (int) $args['fileId'], $this->versionId($request)
            );
            $inline = $file['mode'] === 'inline' && empty($request->getQueryParams()['download']);
            return $this->fileResponse($response, $file, $file['mime_type'], $inline);
        });
    }

    public function historyFile(Request $request, Response $response, array $args): Response
    {
        return $this->handle($response, function () use ($request, $response, $args): Response {
            $file = $this->delivery->historyFile(
                SupplierGuard::currentId($request), (int) $args['id'], (int) $args['eventId'], (int) $args['fileId']
            );
            return $this->fileResponse($response, $file, $file['mime_type'], false);
        });
    }

    public function pdf(Request $request, Response $response, array $args): Response
    {
        return $this->handle($response, function () use ($request, $response, $args): Response {
            $file = $this->delivery->pdf(
                SupplierGuard::currentId($request), (int) $args['id'], $this->versionId($request),
                isset($args['eventId']) ? (int) $args['eventId'] : null,
            );
            return $this->fileResponse($response, $file, 'application/pdf', empty($request->getQueryParams()['download']));
        });
    }

    private function fileResponse(Response $response, array $file, string $mime, bool $inline): Response
    {
        if (isset($file['path'])) {
            $handle = is_file($file['path']) ? fopen($file['path'], 'rb') : false;
            if ($handle === false) {
                throw new QuoteException('quote_file_not_found', 'Soubor není k dispozici.', 404);
            }
            $response = $response->withBody(new Stream($handle));
        } else {
            $response->getBody()->write($file['bytes']);
        }
        $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $file['name']);
        $disposition = ($inline ? 'inline' : 'attachment') . '; filename="' . $name . '"; filename*=UTF-8\'\'' . rawurlencode($file['name']);
        return $response->withHeader('Content-Type', $mime)
            ->withHeader('Content-Disposition', $disposition)
            ->withHeader('Content-Length', (string) $response->getBody()->getSize())
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Content-Security-Policy', "default-src 'none'; sandbox");
    }

    private function detail(Request $request, int $id, ?int $version = null): array
    {
        $supplierId = SupplierGuard::currentId($request);
        $quote = $this->quotes->detail($supplierId, $id, RequestAuthorization::allows($request, 'invoices'), $version);
        if (RequestAuthorization::allows($request, 'sales_quotes.send', AccessLevel::WRITE)
            && $quote['version_id'] === $quote['current_version_id']) {
            $quote['mail_defaults'] = $this->delivery->defaults($supplierId, $quote);
        }
        if (RequestAuthorization::allows($request, 'stock')) {
            $quote['order_id'] = $this->orders->currentOrder($supplierId, $id);
        }
        return $quote;
    }

    private function guardReferences(Request $request, array $body): void
    {
        if (!RequestAuthorization::allows($request, 'clients')
            || (!empty($body['project_id']) && !RequestAuthorization::allows($request, 'projects'))) {
            throw new QuoteException('forbidden_permission', 'Výběr odběratele a zakázky vyžaduje oprávnění ke čtení.', 403);
        }
    }

    private function mutation(Request $request, Response $response, array $args, callable $action): Response
    {
        return $this->handle($response, function () use ($request, $response, $args, $action): Response {
            $id = (int) $args['id'];
            $action(SupplierGuard::currentId($request), $id, $this->userId($request), (array) $request->getParsedBody());
            return Json::ok($response, $this->detail($request, $id));
        });
    }

    private function handle(Response $response, callable $action): Response
    {
        try {
            return $action();
        } catch (QuoteException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }
    }

    private function versionId(Request $request): ?int
    {
        $raw = $request->getQueryParams()['version_id'] ?? null;
        if ($raw === null) {
            return null;
        }
        $id = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new QuoteException('quote_version', 'Neplatná verze nabídky.');
        }
        return $id;
    }

    private function userId(Request $request): int
    {
        return (int) (((array) $request->getAttribute(AuthMiddleware::ATTR_USER, []))['id'] ?? 0);
    }
}
