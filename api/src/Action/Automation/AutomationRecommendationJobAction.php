<?php

declare(strict_types=1);

namespace MyInvoice\Action\Automation;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\AccountingModeRepository;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Automation\AutomationFeedService;
use MyInvoice\Service\Automation\AutomationRecommendationJobService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class AutomationRecommendationJobAction
{
    public function __construct(
        private readonly AutomationRecommendationJobService $jobs,
        private readonly AutomationFeedService $feed,
        private readonly AccountingModeRepository $modes,
    ) {}

    public function status(Request $request, Response $response): Response
    {
        return $this->handle($request, $response, false);
    }

    public function start(Request $request, Response $response): Response
    {
        return $this->handle($request, $response, true);
    }

    private function handle(Request $request, Response $response, bool $start): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $requested = $request->getQueryParams()['suppliers'] ?? null;
        if (!is_string($requested) || !ctype_digit($requested) || (int) $requested !== $supplierId || $supplierId <= 0) {
            return Json::error($response, 'invalid_supplier', 'Vyberte právě aktuální firmu.', 422);
        }
        $userId = (int) (((array) $request->getAttribute(AuthMiddleware::ATTR_USER, []))['id'] ?? 0);
        if (!RequestAuthorization::allows($request, 'accounting')
            || !in_array($supplierId, $this->feed->allowedSupplierIds($userId, RequestAuthorization::isSuperadmin($request)), true)) {
            return Json::error($response, 'forbidden', 'K doporučením této firmy nemáte přístup.', 403);
        }
        if (!$this->modes->hasDoubleEntry($supplierId)) {
            return Json::error($response, 'not_double_entry', 'Firma nevede podvojné účetnictví.', 409);
        }
        try {
            $job = $start ? $this->jobs->start($supplierId, $userId) : $this->jobs->latest($supplierId);
            return Json::ok($response, ['job' => $job], $start ? 202 : 200);
        } catch (\RuntimeException) {
            return Json::error($response, 'job_start_failed', 'Úlohu se nepodařilo spustit. Zkuste to znovu.', 503);
        }
    }
}
