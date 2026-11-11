<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\Payroll\Document\PayrollMonthlyOverviewPdfRenderer;
use MyInvoice\Service\Payroll\Document\PayrollMonthlyOverviewService;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** Přehled mezd za měsíc (všichni zaměstnanci) jako PDF ke stažení. */
final class PayrollMonthlyOverviewAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly PayrollMonthlyOverviewService $overviews,
        private readonly PayrollMonthlyOverviewPdfRenderer $renderer,
        private readonly PayrollModuleAccess $moduleAccess,
    ) {}

    /** @param array<string,string> $args */
    public function pdf(Request $request, Response $response, array $args): Response
    {
        if (!$this->requirePermission(
            $request,
            $response,
            'payroll.documents',
            AccessLevel::READ,
            $error,
        ) || !$this->requirePayrollEnabled($request, $response, $this->moduleAccess, $error)) {
            return $error ?? Json::error($response, 'forbidden', 'Pro tuto akci nemáte oprávnění.', 403);
        }
        $period = trim((string) ($request->getQueryParams()['period'] ?? ''));
        try {
            $data = $this->overviews->build($this->currentSupplierId($request), $period);
        } catch (\InvalidArgumentException $exception) {
            return Json::error($response, 'validation_failed', $exception->getMessage(), 422);
        } catch (\DomainException $exception) {
            return Json::error($response, 'not_found', $exception->getMessage(), 404);
        }
        $pdf = $this->renderer->render($data);
        $response->getBody()->write($pdf['bytes']);

        return $response
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $pdf['filename'] . '"')
            ->withHeader('Cache-Control', 'no-store');
    }
}
