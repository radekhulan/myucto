<?php

declare(strict_types=1);

namespace MyInvoice\Action\Property\Payments;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\Json;
use MyInvoice\Repository\Property\Payments\BankProposalRepository;
use MyInvoice\Security\{AccessLevel, RequestAuthorization};
use MyInvoice\Service\Property\Payments\{BankProposalService, PaymentException, UnitPaymentResolver};
use MyInvoice\Service\Property\PropertyException;
use MyInvoice\Service\Property\PropertyInput;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Párování bankovních pohybů na jednotky SVJ: fronta návrhů, vazby pro bankovní
 * seznamy a plán úhrady z návrhu. Potvrzení jde přes {@see PaymentWorkflowAction::confirm()}.
 */
final class PaymentBankProposalAction
{
    use AccountingActionSupport;

    public function __construct(
        private readonly \MyInvoice\Infrastructure\Database\Connection $db,
        private readonly BankProposalRepository $proposals,
        private readonly BankProposalService $service,
        private readonly UnitPaymentResolver $resolver,
    ) {}

    public function queue(Request $request, Response $response): Response
    {
        return $this->run($request, $response, 'property', false, function (int $supplier) use ($request): array {
            $query = $request->getQueryParams();
            $after = filter_var($query['after_id'] ?? 0, FILTER_VALIDATE_INT);
            $limit = filter_var($query['limit'] ?? 25, FILTER_VALIDATE_INT);
            if ($after === false || $limit === false) PropertyInput::invalid('page');
            $status = isset($query['status']) && $query['status'] !== '' ? (string) $query['status'] : null;
            return $this->proposals->queue($supplier, $after, $limit, $status);
        });
    }

    public function links(Request $request, Response $response): Response
    {
        return $this->run($request, $response, 'property', false, function (int $supplier) use ($request): array {
            $raw = (string) ($request->getQueryParams()['ids'] ?? '');
            if (!preg_match('/^[0-9]+(,[0-9]+){0,499}$/D', $raw)) PropertyInput::invalid('ids');
            // Banka se ptá u každé firmy; bez profilu SVJ odpoví po jediném dotazu a klient se už neptá.
            $profiles = (new \MyInvoice\Repository\Property\PropertyProfileRepository($this->db))->history($supplier);
            if (!in_array(true, array_column($profiles, 'enabled'), true)) return ['enabled' => false, 'items' => (object) []];
            return ['enabled' => true, 'items' => (object) $this->proposals->links($supplier, array_map('intval', explode(',', $raw)))];
        });
    }

    /** Uložený návrh, nebo rozpoznání spočtené teď (pohyb importovaný před zapnutím párování). */
    public function detail(Request $request, Response $response, array $args): Response
    {
        return $this->run($request, $response, 'property', false, function (int $supplier) use ($args): array {
            $id = PropertyInput::id(filter_var($args['id'] ?? null, FILTER_VALIDATE_INT), 'id');
            $link = $this->proposals->links($supplier, [$id])[$id] ?? [];
            if (isset($link['source'])) return ['bank_transaction_id' => $id, 'status' => 'used', 'link' => $link, 'resolution' => null];
            $tx = $this->proposals->freeIncoming($supplier, [$id])[0] ?? null;
            if ($tx === null) PaymentException::reject('bank_transaction_unavailable', ['bank_transaction_id' => $id]);
            $stored = $this->proposals->find($supplier, $id);
            $live = $this->resolver->resolveMany($supplier, [$tx])[$id];
            return ['bank_transaction_id' => $id, 'status' => $live['status'], 'stored' => $stored, 'resolution' => $live, 'link' => $link];
        });
    }

    public function plan(Request $request, Response $response, array $args): Response
    {
        return $this->run($request, $response, 'property.payments.manage', true, function (int $supplier) use ($request, $args): array {
            $id = PropertyInput::id(filter_var($args['id'] ?? null, FILTER_VALIDATE_INT), 'id');
            $body = $request->getParsedBody();
            if ($body !== null && !is_array($body)) PropertyInput::invalid('body');
            return $this->service->plan($supplier, $id, $body ?? []);
        });
    }

    private function run(Request $request, Response $response, string $permission, bool $write, callable $work): Response
    {
        if ($write && !RequestAuthorization::isSessionAuth($request)) return Json::sessionRequired($response);
        if (!RequestAuthorization::allows($request, $permission, $write ? AccessLevel::WRITE : AccessLevel::READ)) return Json::error($response, 'forbidden', 'Pro tuto operaci nemáte oprávnění.', 403);
        try {
            return Json::ok($response, $work($this->currentSupplierId($request)));
        } catch (PaymentException | PropertyException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus, ['details' => $e->details]);
        } catch (\MyInvoice\Service\Accounting\PostingException | \MyInvoice\Service\Accounting\Dimension\DimensionException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }
    }
}
