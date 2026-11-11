<?php

declare(strict_types=1);

namespace MyInvoice\Action\Automation;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Automation\RuleProposalService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class AutomationWizardAction
{
    public function __construct(private readonly RuleProposalService $proposals) {}

    public function analysis(Request $request, Response $response): Response
    {
        $supplierId = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        $months = max(1, min(60, (int) ($request->getQueryParams()['months_back'] ?? 27)));
        return Json::ok($response, $this->proposals->analyze($supplierId, $months));
    }

    public function apply(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::allows($request, 'bank.rules', AccessLevel::WRITE)) {
            return Json::error($response, 'forbidden', 'Pro tuto akci nemáte oprávnění.', 403);
        }
        $supplierId = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);
        $body = (array) ($request->getParsedBody() ?? []);
        $rules = array_values(array_filter((array) ($body['rules'] ?? []), 'is_array'));
        if ($rules === []) return Json::error($response, 'rules_required', 'Vyberte alespoň jedno pravidlo.', 422);
        if (count($rules) > 50) return Json::error($response, 'too_many_rules', 'Najednou lze vytvořit nejvýše 50 pravidel.', 422);
        return Json::ok($response, $this->proposals->apply($supplierId, $userId, $rules, (bool) ($body['backfill'] ?? true)));
    }
}
