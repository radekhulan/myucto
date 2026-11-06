<?php

declare(strict_types=1);

namespace MyInvoice\Action\Mobile;

use MyInvoice\Http\GuardsDocumentLock;
use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Accounting\Cash\CashException;
use MyInvoice\Service\Accounting\DocumentLockService;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Eet\EetCommandException;
use MyInvoice\Service\Eet\EetValues;
use MyInvoice\Service\Eet\IdempotencyConflictException;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Mobile\QuickReceiptException;
use MyInvoice\Service\Mobile\QuickReceiptService;
use MyInvoice\Service\Stock\StockException;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /api/mobile/receipts — rychlá účtenka (prodej bez předchozí faktury).
 *
 * Vyžaduje hlavičku `Idempotency-Key`. Route hlídá `invoices.create` (RoutePermissionMap);
 * účtenka zároveň doklad vystaví a uhradí, takže akce ověřuje i `invoices.issue`
 * a `invoices.mark_paid` ({@see RequestAuthorization::canIssueQuickReceipt()}).
 * Právo `eet.accept_payment` se vyžaduje, když firma eviduje tržby. Datum dokladu
 * (dnes) nesmí padnout do uzavřeného období.
 */
final class QuickReceiptAction
{
    use GuardsDocumentLock;

    public function __construct(
        private readonly QuickReceiptService $receipts,
        private readonly DocumentLockService $locks,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly ClockInterface $clock,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::canIssueQuickReceipt($request)) {
            return Json::error($response, 'forbidden', 'K rychlé účtence je potřeba právo vystavit a uhradit fakturu.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $lock = $this->locks->forDate($supplierId, EetValues::local($this->clock->now())->format('Y-m-d'));
        if ($deny = $this->denyIfLocked($request, $response, $lock, 'invoice', null)) {
            return $deny;
        }
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $body = $request->getParsedBody();
        try {
            $result = $this->receipts->create(
                $supplierId,
                (int) ($user['id'] ?? 0),
                $request->getHeaderLine('Idempotency-Key'),
                is_array($body) ? $body : [],
                RequestAuthorization::allows($request, 'eet.accept_payment', AccessLevel::WRITE),
                $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
                $request->getHeaderLine('User-Agent'),
            );
        } catch (IdempotencyConflictException $e) {
            return Json::error($response, 'idempotency_conflict', $e->getMessage(), 409);
        } catch (QuickReceiptException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus, $e->extra);
        } catch (EetCommandException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus, $e->extra);
        } catch (CashException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus, $e->extra);
        } catch (PostingException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (StockException $e) {
            return Json::error($response, 'stock.error.' . $e->errorCode, $e->getMessage(), $e->httpStatus, ['items' => $e->details]);
        }
        return Json::ok($response, $result, $result['idempotent_replay'] ? 200 : 201);
    }
}
