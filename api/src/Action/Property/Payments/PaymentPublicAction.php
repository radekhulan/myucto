<?php

declare(strict_types=1);

namespace MyInvoice\Action\Property\Payments;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\Json;
use MyInvoice\Repository\Property\Payments\PaymentOperationsReadRepository;
use MyInvoice\Repository\Property\Payments\PaymentWorkflowReadRepository;
use MyInvoice\Repository\Property\PropertyReadRepository;
use MyInvoice\Repository\Property\Receivables\PartyEventRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Property\Payments\PaymentException;
use MyInvoice\Service\Property\Payments\PaymentPublicProjection;
use MyInvoice\Service\Property\PropertyException;
use MyInvoice\Service\Property\PropertyInput;
use MyInvoice\Service\Property\PropertyInstant;
use MyInvoice\Service\Property\Receivables\PropertyPartyReconciliation;
use MyInvoice\Service\Property\Receivables\PropertyReconciliation;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PaymentPublicAction
{
    use AccountingActionSupport;

    public function __construct(private readonly PaymentOperationsReadRepository $read,
        private readonly PaymentWorkflowReadRepository $workflows, private readonly PropertyReadRepository $registry,
        private readonly PropertyReconciliation $reconciliation, private readonly PropertyPartyReconciliation $parties) {}

    public function card(Request $request, Response $response): Response
    {
        return $this->run($request, $response, function (int $supplier) use ($request): array {
            $q = $request->getQueryParams();
            PropertyInput::fields($q, ['unit_id', 'as_of', 'known_at', 'supplier_id']);
            $unit = $this->integer($q, 'unit_id', minimum: 1);
            $asOf = PropertyInstant::normalize($q['as_of'] ?? null);
            $knownAt = PropertyInstant::normalize($q['known_at'] ?? null);
            $this->unit($supplier, $unit, $asOf, $knownAt);
            return PaymentPublicProjection::card($this->read->card($supplier, $asOf, $knownAt, ['unit_id' => $unit]));
        });
    }

    public function operations(Request $request, Response $response): Response
    {
        return $this->run($request, $response, function (int $supplier) use ($request): array {
            $q = $request->getQueryParams();
            PropertyInput::fields($q, ['unit_id', 'after_id', 'limit', 'supplier_id']);
            $after = $this->integer($q, 'after_id', 0);
            $limit = $this->integer($q, 'limit', 25, 1, 100);
            $unit = array_key_exists('unit_id', $q) ? $this->integer($q, 'unit_id', minimum: 1) : null;
            if ($unit !== null) { $now = PartyEventRepository::now(); $this->unit($supplier, $unit, $now, $now); }
            $page = $this->read->page($supplier, 'operations', $after, $limit, $unit);
            $page['items'] = array_map(PaymentPublicProjection::operation(...), $page['items']);
            return $page;
        });
    }

    public function history(Request $request, Response $response): Response
    {
        return $this->run($request, $response, function (int $supplier) use ($request): array {
            $q = $request->getQueryParams();
            PropertyInput::fields($q, ['before_id', 'limit', 'supplier_id']);
            $before = $this->integer($q, 'before_id', 0);
            $limit = $this->integer($q, 'limit', 25, 1, 100);
            return $this->workflows->historyPage($supplier, $before, $limit);
        });
    }

    public function reconciliation(Request $request, Response $response): Response
    {
        return $this->run($request, $response, function (int $supplier) use ($request): array {
            $q = $request->getQueryParams();
            PropertyInput::fields($q, ['unit_id', 'date', 'supplier_id']);
            $unit = $this->integer($q, 'unit_id', minimum: 1);
            $date = PropertyInput::text($q['date'] ?? null, 'date', 10);
            $now = PartyEventRepository::now(); $this->unit($supplier, $unit, $now, $now);
            $result=$this->reconciliation->at($supplier, $date, $unit);unset($result['opening']['states']);return $result;
        });
    }

    public function partyReconciliation(Request $request, Response $response): Response
    {
        return $this->run($request, $response, function (int $supplier) use ($request): array {
            $q = $request->getQueryParams();
            PropertyInput::fields($q, ['date', 'limit', 'after', 'supplier_id']);
            $date = PropertyInput::text($q['date'] ?? null, 'date', 10);
            $limit = $this->integer($q, 'limit', 50, 1, 100);
            $after = array_key_exists('after', $q) ? PropertyInput::text($q['after'], 'after', 2000) : null;
            return $this->parties->at($supplier, $date, $limit, $after);
        });
    }

    private function unit(int $supplier, int $unit, string $at, string $known): void
    {
        $object = $this->registry->find($supplier, $unit, $at, $known);
        if ($object['kind'] !== 'unit') throw new PropertyException('property_not_found', 'Jednotka nebyla nalezena.', 404);
    }

    private function integer(array $data, string $key, ?int $default = null, int $minimum = 0, ?int $maximum = null): int
    {
        $value = array_key_exists($key, $data) ? $data[$key] : $default;
        if (is_bool($value) || !is_scalar($value) || filter_var($value, FILTER_VALIDATE_INT) === false) PropertyInput::invalid($key);
        $value = (int) $value;
        if ($value < $minimum || ($maximum !== null && $value > $maximum)) PropertyInput::invalid($key);
        return $value;
    }

    private function run(Request $request, Response $response, callable $work): Response
    {
        if (!RequestAuthorization::allows($request, 'property', AccessLevel::READ)) return Json::error($response, 'forbidden', 'Pro tuto operaci nemáte oprávnění.', 403);
        try { return Json::ok($response, $work($this->currentSupplierId($request))); }
        catch (PropertyException|\MyInvoice\Service\Property\Opening\OpeningException|PaymentException $e) { return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus, ['details' => $e->details]); }
    }
}
