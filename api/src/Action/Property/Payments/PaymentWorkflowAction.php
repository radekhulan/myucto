<?php

declare(strict_types=1);
namespace MyInvoice\Action\Property\Payments;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\Json;
use MyInvoice\Repository\Property\Payments\PaymentWorkflowReadRepository;
use MyInvoice\Service\Property\Payments\Workflow\PaymentWorkflowFacade;
use MyInvoice\Service\Property\Payments\PaymentException;
use MyInvoice\Service\Property\PropertyException;
use MyInvoice\Service\Property\PropertyInput;
use MyInvoice\Security\{AccessLevel,RequestAuthorization};
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;

final class PaymentWorkflowAction
{
    use AccountingActionSupport;
    public function __construct(private readonly PaymentWorkflowFacade $workflow, private readonly PaymentWorkflowReadRepository $read,
        private readonly \MyInvoice\Repository\Property\Payments\ClearingRevisionRepository $revisions) {}

    public function source(Request $request, Response $response): Response
    {
        return $this->run($request, $response, 'property', false, function (int $supplier) use ($request): array {
            $query = $request->getQueryParams();
            $id = filter_var($query['id'] ?? null, FILTER_VALIDATE_INT);
            $kind = (string) ($query['kind'] ?? '');
            return $this->read->source($supplier, $kind, PropertyInput::id($id, 'id'), $this->can($request, $kind === 'bank' ? 'bank.post' : 'cash.document.write'));
        });
    }

    public function revisionPreview(Request $request, Response $response): Response
    {
        return $this->run($request, $response, 'property.payments.manage', true, fn (int $supplier): array => $this->revisions->preview($supplier, $this->body($request)));
    }

    public function revisionConfirm(Request $request, Response $response): Response
    {
        return $this->run($request, $response, 'property.payments.post', true, function (int $supplier) use ($request): array {
            $body = $this->body($request); PropertyInput::fields($body, ['request', 'preview_hash', 'idempotency_key']);
            if (!is_array($body['request'] ?? null)) PropertyInput::invalid('request');
            return $this->revisions->apply($supplier, $body['request'], PropertyInput::text($body['preview_hash'] ?? null, 'preview_hash', 64),
                PropertyInput::code($body['idempotency_key'] ?? null, 'idempotency_key'), $this->userId($request));
        });
    }

    public function context(Request $request, Response $response): Response
    {
        return $this->run($request, $response, 'property', false, function (int $supplier) use ($request): array {
            $bank = $this->can($request, 'bank.post'); $cash = $this->can($request, 'cash.document.write');
            $context = $this->read->context($supplier, (string) ($request->getQueryParams()['as_of'] ?? ''), $bank, $cash);
            $context['capabilities'] = ['manage' => $this->can($request, 'property.payments.manage'), 'post' => $this->can($request, 'property.payments.post'),
                'reverse' => $this->can($request, 'property.payments.reverse'), 'bank' => $bank, 'cash' => $cash];
            return $context;
        });
    }

    public function history(Request $request, Response $response): Response
    {
        return $this->run($request, $response, 'property', false, function (int $supplier) use ($request): array {
            $query = $request->getQueryParams();
            $before = filter_var($query['before_id'] ?? 0, FILTER_VALIDATE_INT);
            $limit = filter_var($query['limit'] ?? 100, FILTER_VALIDATE_INT);
            if ($before === false || $limit === false) PropertyInput::invalid('page');
            return $this->read->historyPage($supplier, $before, $limit);
        });
    }

    public function preview(Request $request, Response $response): Response
    {
        return $this->run($request, $response, 'property.payments.manage', true, fn (int $supplier): array => $this->workflow->preview($supplier, $this->body($request)));
    }

    public function confirm(Request $request, Response $response): Response
    {
        return $this->run($request, $response, 'property.payments.post', true, function (int $supplier) use ($request): array {
            $body = $this->body($request); PropertyInput::fields($body, ['request', 'preview_hash', 'idempotency_key']);
            if (!is_array($body['request'] ?? null)) PropertyInput::invalid('request');
            return $this->workflow->confirmAuthorized($supplier, $body['request'], PropertyInput::text($body['preview_hash'] ?? null, 'preview_hash', 64),
                PropertyInput::code($body['idempotency_key'] ?? null, 'idempotency_key'), $this->userId($request),
                $this->can($request, 'bank.post'), $this->can($request, 'cash.document.write'));
        });
    }

    private function can(Request $request, string $permission): bool
    {
        return RequestAuthorization::isSessionAuth($request) && RequestAuthorization::allows($request, $permission, AccessLevel::WRITE);
    }

    private function run(Request $request, Response $response, string $permission, bool $write, callable $work): Response
    {
        if ($write && !RequestAuthorization::isSessionAuth($request)) return Json::sessionRequired($response);
        if (!RequestAuthorization::allows($request, $permission, $write ? AccessLevel::WRITE : AccessLevel::READ)) return Json::error($response, 'forbidden', 'Pro tuto operaci nemáte oprávnění.', 403);
        try { return Json::ok($response, $work($this->currentSupplierId($request))); }
        catch (PaymentException|\MyInvoice\Service\Property\Opening\OpeningException|PropertyException $e) { return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus, ['details' => $e->details]); }
        catch (\MyInvoice\Service\Accounting\PostingException|\MyInvoice\Service\Accounting\Dimension\DimensionException|\MyInvoice\Service\Accounting\Cash\CashException $e) { return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus); }
    }

    private function body(Request $request): array
    {
        $value = $request->getParsedBody(); if (!is_array($value)) PropertyInput::invalid('body'); return $value;
    }
}
