<?php

declare(strict_types=1);

namespace MyInvoice\Action\PurchaseInvoice\Approval;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\PurchaseInvoiceApprovalRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\PurchaseInvoice\Approval\PurchaseApprovalException;
use MyInvoice\Service\PurchaseInvoice\Approval\PurchaseInvoiceApprovalService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Schvalování přijatých dokladů manažerem střediska (F6) — interní API.
 *
 *   GET  /api/purchase-invoice-approvals?status=pending|decided&scope=mine|all&page=N
 *   GET  /api/purchase-invoice-approvals/count               — čekající schválení přihlášeného
 *   POST /api/purchase-invoice-approvals/{id}/decide         — {decision, comment?}
 *   POST /api/purchase-invoice-approvals/{id}/remind         — připomínka e-mailem hned
 *   GET  /api/purchase-invoices/{id}/approvals               — kola + co doklad vyžaduje
 *   POST /api/purchase-invoices/{id}/approvals/request       — nové kolo
 *   POST /api/purchase-invoices/{id}/approvals/cancel        — zrušit čekající kolo
 *
 * RBAC: schránka a rozhodnutí = `purchase_invoices.approve` (čtení — jde přidělit
 * i roli jen pro čtení), rozhodnout jde jen VLASTNÍ schválení. Pohled `scope=all`
 * a připomínka = účetní (`purchase_invoices` zápis); odeslání / zrušení kola =
 * `purchase_invoices.transition`. Vše v rámci aktuální firmy.
 */
final class PurchaseInvoiceApprovalAction
{
    private const PER_PAGE = 50;

    public function __construct(
        private readonly PurchaseInvoiceApprovalService $service,
        private readonly PurchaseInvoiceApprovalRepository $repo,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        $q = $request->getQueryParams();
        $status = ($q['status'] ?? 'pending') === 'decided' ? 'decided' : 'pending';
        $scope = ($q['scope'] ?? 'mine') === 'all' ? 'all' : 'mine';
        if ($scope === 'all' && !RequestAuthorization::allows($request, 'purchase_invoices', AccessLevel::WRITE)) {
            return Json::error($response, 'forbidden_permission', 'Přehled všech schválení vidí jen účetní.', 403);
        }
        $page = max(1, (int) ($q['page'] ?? 1));
        $result = $this->repo->inbox(
            SupplierGuard::currentId($request),
            $scope === 'mine' ? $this->userId($request) : null,
            $status,
            $page,
            self::PER_PAGE,
        );
        return Json::ok($response, [
            'data' => array_map([PurchaseInvoiceApprovalService::class, 'serialize'], $result['rows']),
            'meta' => [
                'total' => $result['total'],
                'page' => $page,
                'pages' => max(1, (int) ceil($result['total'] / self::PER_PAGE)),
            ],
        ]);
    }

    public function count(Request $request, Response $response): Response
    {
        return Json::ok($response, [
            'pending' => $this->repo->pendingCountForApprover(SupplierGuard::currentId($request), $this->userId($request)),
        ]);
    }

    public function decide(Request $request, Response $response, array $args): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        return $this->run($response, fn (): array => $this->service->decide(
            SupplierGuard::currentId($request),
            (int) ($args['id'] ?? 0),
            (string) ($body['decision'] ?? ''),
            isset($body['comment']) && is_scalar($body['comment']) ? (string) $body['comment'] : null,
            'app',
            $this->userId($request),
            $this->ip($request),
            $request->getHeaderLine('User-Agent'),
        ));
    }

    public function remind(Request $request, Response $response, array $args): Response
    {
        return $this->run($response, function () use ($request, $args): array {
            $id = (int) ($args['id'] ?? 0);
            $supplierId = SupplierGuard::currentId($request);
            $this->service->remind($supplierId, $id, $this->userId($request));
            return PurchaseInvoiceApprovalService::serialize((array) $this->repo->find($supplierId, $id));
        });
    }

    public function forInvoice(Request $request, Response $response, array $args): Response
    {
        if ($deny = $this->sessionOnly($request, $response)) {
            return $deny;
        }
        return $this->run($response, fn (): array => $this->service->overview(
            SupplierGuard::currentId($request),
            $this->invoiceId($request, $args),
        ));
    }

    public function request(Request $request, Response $response, array $args): Response
    {
        if ($deny = $this->sessionOnly($request, $response)) {
            return $deny;
        }
        return $this->run($response, fn (): array => $this->service->request(
            SupplierGuard::currentId($request),
            $this->invoiceId($request, $args),
            $this->userId($request),
            $this->ip($request),
            $request->getHeaderLine('User-Agent'),
        ));
    }

    public function cancel(Request $request, Response $response, array $args): Response
    {
        if ($deny = $this->sessionOnly($request, $response)) {
            return $deny;
        }
        return $this->run($response, fn (): array => $this->service->cancel(
            SupplierGuard::currentId($request),
            $this->invoiceId($request, $args),
            $this->userId($request),
            $this->ip($request),
            $request->getHeaderLine('User-Agent'),
        ));
    }

    /**
     * Interní API: cesty pod /api/purchase-invoices jsou jinak dostupné i API tokenem,
     * schvalování ale do veřejného API nepatří (odeslání ke schválení přes API řeší
     * přechod do stavu „přijato").
     */
    private function sessionOnly(Request $request, Response $response): ?Response
    {
        return RequestAuthorization::actsAsSession($request)
            ? null
            : Json::error($response, 'forbidden', 'Tato operace vyžaduje přihlášenou relaci.', 403);
    }

    /** @param callable():array<string,mixed> $fn */
    private function run(Response $response, callable $fn): Response
    {
        try {
            return Json::ok($response, $fn());
        } catch (PurchaseApprovalException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus, $e->details);
        }
    }

    /** @param array<string,string> $args */
    private function invoiceId(Request $request, array $args): int
    {
        $id = (int) ($args['id'] ?? 0);
        $stmtOk = $id > 0 && $this->repo->invoiceExists(SupplierGuard::currentId($request), $id);
        if (!$stmtOk) {
            throw new PurchaseApprovalException('not_found', 'Přijatá faktura nenalezena.', 404);
        }
        return $id;
    }

    private function userId(Request $request): int
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        return (int) ($user['id'] ?? 0);
    }

    private function ip(Request $request): string
    {
        return $this->ipMatcher->clientIpFromRequest($request->getServerParams());
    }
}
