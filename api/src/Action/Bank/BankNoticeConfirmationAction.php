<?php

declare(strict_types=1);

namespace MyInvoice\Action\Bank;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Bank\BankNoticeConfirmationException;
use MyInvoice\Service\Bank\BankNoticeConfirmationService;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class BankNoticeConfirmationAction
{
    public function __construct(
        private readonly BankNoticeConfirmationService $confirmations,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function check(Request $request, Response $response, array $args): Response
    {
        return $this->run($request, $response, function (int $supplierId, int $userId) use ($request, $args): array {
            $body = (array) $request->getParsedBody();
            // Náhled je výchozí; chybějící nebo neplatný příznak nesmí provést zápis.
            $dryRun = ($body['dry_run'] ?? true) !== false;
            return $this->confirmations->reconcileStatement(
                $supplierId, (int) ($args['id'] ?? 0), $userId, $dryRun,
                max(1, min(100, (int) ($body['limit'] ?? 100))),
                max(0, (int) ($body['after_id'] ?? 0)),
            );
        });
    }

    public function confirm(Request $request, Response $response, array $args): Response
    {
        return $this->run($request, $response, function (int $supplierId, int $userId) use ($request, $args): array {
            $body = (array) $request->getParsedBody();
            $reason = is_string($body['reason'] ?? null) ? trim($body['reason']) : '';
            if ($reason === '' || mb_strlen($reason) > 500) {
                throw new \InvalidArgumentException('Vyplňte důvod potvrzení (nejvýše 500 znaků).');
            }
            return $this->confirmations->confirmManually(
                $supplierId, (int) ($args['id'] ?? 0),
                (int) ($body['authoritative_transaction_id'] ?? 0), $userId, $reason,
                fn () => $this->logger->log(
                    'bank_notice.confirmed', $userId, 'bank_transaction', (int) ($args['id'] ?? 0),
                    ['notice_transaction_id' => (int) ($args['id'] ?? 0),
                        'authoritative_transaction_id' => (int) ($body['authoritative_transaction_id'] ?? 0),
                        'reason' => $reason],
                    $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
                    $request->getHeaderLine('User-Agent'), $supplierId,
                ),
            );
        });
    }

    private function run(Request $request, Response $response, callable $operation): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        if ($supplierId <= 0) {
            return Json::error($response, 'no_supplier', 'Není zvolena firma.', 400);
        }
        if (!RequestAuthorization::allows($request, 'bank.match', AccessLevel::WRITE)) {
            return Json::error($response, 'forbidden', 'Nemáte oprávnění k párování bankovních pohybů.', 403);
        }
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        try {
            return Json::ok($response, $operation($supplierId, (int) ($user['id'] ?? 0)));
        } catch (BankNoticeConfirmationException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus, $e->details);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'invalid_confirmation', $e->getMessage(), 422);
        }
    }
}
