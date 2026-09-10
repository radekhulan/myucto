<?php

declare(strict_types=1);

namespace MyInvoice\Action\Bank;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Http\TenantReferenceGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\PaymentCardRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Bank\Card\PaymentCardInput;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Platební karty firmy (Firma → Platební karty):
 *   GET  /api/payment-cards                 — seznam (?include_archived=1)
 *   GET  /api/payment-cards/holders         — nabídka držitelů (zaměstnanci, uživatelé)
 *   GET  /api/payment-cards/{id}            — detail
 *   POST /api/payment-cards                 — založení
 *   PUT  /api/payment-cards/{id}            — úprava
 *   POST /api/payment-cards/{id}/archive    — archivace (karta se nemaže, drží historii plateb)
 *   POST /api/payment-cards/{id}/restore    — obnovení z archivu
 *
 * Evidujeme jen koncovku karty. RBAC řeší RoutePermissionMap (settings.bank_accounts).
 */
final class PaymentCardAction
{
    public function __construct(
        private readonly PaymentCardRepository $cards,
        private readonly TenantReferenceGuard $tenantRefs,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $includeArchived = !empty($request->getQueryParams()['include_archived']);
        return Json::ok($response, ['cards' => $this->cards->listForSupplier($supplierId, $includeArchived)]);
    }

    public function holders(Request $request, Response $response): Response
    {
        return Json::ok($response, $this->cards->holderCandidates(SupplierGuard::currentId($request)));
    }

    public function get(Request $request, Response $response, array $args): Response
    {
        $card = $this->cards->find(SupplierGuard::currentId($request), (int) ($args['id'] ?? 0));
        if ($card === null) {
            return Json::error($response, 'not_found', 'Platební karta nenalezena.', 404);
        }
        return Json::ok($response, $card);
    }

    public function create(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $body = (array) ($request->getParsedBody() ?? []);
        $input = PaymentCardInput::normalize($body);
        $error = $this->validate($response, $supplierId, $input, null);
        if ($error !== null) {
            return $error;
        }
        $id = $this->cards->create($supplierId, $input['data'], $this->userId($request));
        $this->log($request, 'payment_card.created', $id, $input['data']);
        return Json::ok($response, [
            'card' => $this->cards->find($supplierId, $id),
            'last4_truncated' => $input['last4_truncated'],
        ], 201);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $id = (int) ($args['id'] ?? 0);
        if ($this->cards->find($supplierId, $id) === null) {
            return Json::error($response, 'not_found', 'Platební karta nenalezena.', 404);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $input = PaymentCardInput::normalize($body);
        $error = $this->validate($response, $supplierId, $input, $id);
        if ($error !== null) {
            return $error;
        }
        $this->cards->update($supplierId, $id, $input['data']);
        $this->log($request, 'payment_card.updated', $id, $input['data']);
        return Json::ok($response, [
            'card' => $this->cards->find($supplierId, $id),
            'last4_truncated' => $input['last4_truncated'],
        ]);
    }

    public function archive(Request $request, Response $response, array $args): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $id = (int) ($args['id'] ?? 0);
        if ($this->cards->find($supplierId, $id) === null) {
            return Json::error($response, 'not_found', 'Platební karta nenalezena.', 404);
        }
        $this->cards->archive($supplierId, $id);
        $this->log($request, 'payment_card.archived', $id, []);
        return Json::ok($response, ['card' => $this->cards->find($supplierId, $id)]);
    }

    public function restore(Request $request, Response $response, array $args): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $id = (int) ($args['id'] ?? 0);
        $card = $this->cards->find($supplierId, $id);
        if ($card === null) {
            return Json::error($response, 'not_found', 'Platební karta nenalezena.', 404);
        }
        $conflict = $this->cards->overlapping($supplierId, (string) $card['last4'], $card['valid_from'], $card['valid_to'], $id);
        if ($conflict !== null) {
            return Json::error($response, 'card_overlap',
                'Kartu nelze obnovit — ve stejném období platí jiná karta se stejnou koncovkou (' . $conflict['label'] . ').', 409);
        }
        $this->cards->restore($supplierId, $id);
        $this->log($request, 'payment_card.restored', $id, []);
        return Json::ok($response, ['card' => $this->cards->find($supplierId, $id)]);
    }

    /**
     * @param array{data: array<string,mixed>, errors: array<string,string>, last4_truncated: bool} $input
     */
    private function validate(Response $response, int $supplierId, array $input, ?int $exceptId): ?Response
    {
        $errors = $input['errors'];
        $data = $input['data'];
        // Měnový (bankovní) účet musí patřit firmě — cizí id se odmítne, ne tiše uloží.
        foreach ($this->tenantRefs->violations($supplierId, $data, ['currency_id']) as $column) {
            $errors[$column] = 'Bankovní účet nepatří této firmě.';
        }
        if ($data['employee_id'] !== null && !$this->cards->employeeBelongs($supplierId, (int) $data['employee_id'])) {
            $errors['employee_id'] = 'Zaměstnanec nepatří této firmě.';
        }
        if ($data['user_id'] !== null && !$this->cards->userBelongs($supplierId, (int) $data['user_id'])) {
            $errors['user_id'] = 'Uživatel nemá přístup k této firmě.';
        }
        if ($errors !== []) {
            return Json::error($response, 'validation_failed', (string) reset($errors), 422, ['errors' => $errors]);
        }
        $conflict = $this->cards->overlapping($supplierId, (string) $data['last4'], $data['valid_from'], $data['valid_to'], $exceptId);
        if ($conflict !== null) {
            return Json::error($response, 'card_overlap',
                'Ve stejném období už platí karta se stejnou koncovkou (' . $conflict['label'] . '). Upravte platnost od–do.', 409);
        }
        return null;
    }

    private function userId(Request $request): ?int
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $id = (int) ($user['id'] ?? 0);
        return $id > 0 ? $id : null;
    }

    /** @param array<string,mixed> $payload */
    private function log(Request $request, string $action, int $id, array $payload): void
    {
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $safe = array_intersect_key($payload, array_flip(['label', 'last4', 'card_type', 'valid_from', 'valid_to', 'is_active']));
        $this->logger->log($action, $this->userId($request), 'payment_card', $id, $safe, $ip, $request->getHeaderLine('User-Agent'));
    }
}
